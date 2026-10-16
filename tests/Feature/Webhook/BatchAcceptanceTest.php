<?php

declare(strict_types=1);

use App\Enums\BatchStatus;
use App\Models\EventBatch;
use Illuminate\Support\Facades\DB;

/**
 * @return list<array<string, mixed>>
 */
function briefBatch(): array
{
    return [
        cmsEvent('publish', 'content-123', 2, ['title' => 'Example content', 'description' => 'Some content'], '2026-01-01T10:00:00Z'),
        cmsEvent('unpublish', 'content-456', 4, ['title' => 'Updated content', 'description' => 'New information'], '2026-01-01T10:05:00Z'),
        cmsEvent('delete', 'content-789', 5, null, '2026-01-01T10:10:00Z'),
    ];
}

it('acknowledges a valid batch with 202 and a batch identifier', function (): void {
    $response = postBatch(briefBatch())
        ->assertAccepted()
        ->assertJsonStructure(['data' => ['batch_id', 'status', 'event_count', 'received_at']])
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.event_count', 3);

    $batchId = (string) $response->json('data.batch_id');

    expect($batchId)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($response->json('data.received_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/');
});

it('records the batch in the inbox exactly as received, in order', function (): void {
    $batchId = (string) postBatch(briefBatch())->json('data.batch_id');

    $batch = EventBatch::query()->findOrFail($batchId);
    $stored = DB::table('event_batches')->where('id', $batchId)->value('events');

    expect($batch->status)->toBe(BatchStatus::Pending)
        ->and($batch->event_count)->toBe(3)
        ->and($batch->attempts)->toBe(0)
        // jsonb normalizes key order inside objects; values and event order are preserved.
        ->and(json_decode((string) $stored, true))->toEqual(json_decode((string) json_encode(briefBatch()), true))
        ->and(array_column((array) json_decode((string) $stored, true), 'id'))->toBe(['content-123', 'content-456', 'content-789']);
});

it('queues exactly one processing job on the CMS queue', function (): void {
    postBatch(briefBatch())->assertAccepted();

    $jobs = DB::table('jobs')->get();

    expect($jobs)->toHaveCount(1)
        ->and($jobs->first()?->queue)->toBe('cms-events');
});

it('stores neither the batch nor the job when queuing fails (atomic hand-off)', function (): void {
    // Make the jobs INSERT fail after the inbox row was written (trigger lives
    // inside the test transaction and is rolled back afterwards).
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION test_fail_job_insert() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'simulated failure while queuing';
        END
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER test_fail_job_insert BEFORE INSERT ON jobs
            FOR EACH ROW EXECUTE FUNCTION test_fail_job_insert();
        SQL);

    postBatch(briefBatch())
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json');

    expect(EventBatch::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('answers 503 so the CMS retries when the batch cannot be recorded', function (): void {
    // Make the inbox unavailable inside the test transaction (rolled back afterwards).
    DB::statement('ALTER TABLE event_batches RENAME TO event_batches_unavailable');

    postBatch(briefBatch())
        ->assertStatus(503)
        ->assertHeader('Retry-After', '5')
        ->assertJsonPath('title', 'Service Unavailable');

    expect(DB::table('jobs')->count())->toBe(0);
});
