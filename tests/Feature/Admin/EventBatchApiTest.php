<?php

declare(strict_types=1);

use App\Enums\BatchStatus;
use App\Enums\Role;
use App\Models\EventBatch;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/**
 * @param  array<array-key, mixed>  $data
 */
function containsKeyRecursively(array $data, string $key): bool
{
    foreach ($data as $k => $value) {
        if ($k === $key || (is_array($value) && containsKeyRecursively($value, $key))) {
            return true;
        }
    }

    return false;
}

it('lists batches newest first, with a status filter and cursor pagination', function (): void {
    $old = EventBatch::factory()->processed()->create(['received_at' => now()->subHours(3)]);
    $failed = EventBatch::factory()->failed()->create(['received_at' => now()->subHours(2)]);
    $pending = EventBatch::factory()->pending()->create(['received_at' => now()->subHour()]);
    actingAsRole(Role::Admin);

    $all = getJson('/api/v1/admin/event-batches')->assertOk()
        ->assertJsonPath('meta.pagination', 'cursor')
        ->json('data');

    expect(array_column((array) $all, 'id'))->toBe([$pending->id, $failed->id, $old->id]);

    getJson('/api/v1/admin/event-batches?status=failed')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $failed->id)
        ->assertJsonPath('data.0.status', 'failed')
        ->assertJsonPath('data.0.attempts', 5)
        ->assertJsonStructure(['data' => [['id', 'status', 'event_count', 'attempts', 'applied_count', 'ignored_count', 'last_error', 'received_at', 'processed_at', 'failed_at', 'retried_at']]]);

    $page = getJson('/api/v1/admin/event-batches?per_page=2')->assertOk()->assertJsonCount(2, 'data');
    getJson('/api/v1/admin/event-batches?per_page=2&cursor='.$page->json('meta.next_cursor'))->assertOk()
        ->assertJsonPath('data.0.id', $old->id);
});

it('rejects an unknown status filter', function (): void {
    actingAsRole(Role::Admin);

    getJson('/api/v1/admin/event-batches?status=archived')->assertUnprocessable()->assertJsonStructure(['errors' => ['status']]);
});

it('shows a batch with an event summary and never its payloads', function (): void {
    $batchId = (string) postBatch([
        cmsEvent('publish', 'content-1', 2, ['secret' => 'SECRET-PAYLOAD-SENTINEL']),
        cmsEvent('delete', 'content-2', 5, null, '2026-01-01T10:10:00Z'),
    ])->json('data.batch_id');
    actingAsRole(Role::Admin);

    $response = getJson("/api/v1/admin/event-batches/{$batchId}")->assertOk()
        ->assertJsonPath('data.id', $batchId)
        ->assertJsonPath('data.events.0', [
            'position' => 0, 'id' => 'content-1', 'type' => 'publish', 'version' => 2, 'timestamp' => '2026-01-01T10:00:00Z',
        ])
        ->assertJsonPath('data.events.1.type', 'delete');

    expect(containsKeyRecursively((array) $response->json(), 'payload'))->toBeFalse()
        ->and((string) $response->getContent())->not->toContain('SECRET-PAYLOAD-SENTINEL');
});

it('re-processes a failed batch on request', function (): void {
    $batch = EventBatch::factory()->failed()->create();
    $admin = actingAsRole(Role::Admin);

    postJson("/api/v1/admin/event-batches/{$batch->id}/retry")
        ->assertAccepted()
        ->assertJsonPath('data.id', $batch->id)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.attempts', 0);

    $batch->refresh();
    expect($batch->status)->toBe(BatchStatus::Pending)
        ->and($batch->retried_by)->toBe($admin->id)
        ->and($batch->retried_at)->not->toBeNull()
        ->and($batch->failed_at)->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('refuses to retry batches that are not failed', function (string $state): void {
    $batch = EventBatch::factory()->{$state}()->create();
    actingAsRole(Role::Admin);

    postJson("/api/v1/admin/event-batches/{$batch->id}/retry")
        ->assertConflict()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('detail', 'Only failed batches can be retried.');

    expect(DB::table('jobs')->count())->toBe(0);
})->with(['pending', 'processed']);

it('answers 404 for unknown or malformed batch ids', function (string $id): void {
    actingAsRole(Role::Admin);

    getJson("/api/v1/admin/event-batches/{$id}")->assertNotFound();
    postJson("/api/v1/admin/event-batches/{$id}/retry")->assertNotFound();
})->with(['0199f0a2-6c1e-7b3a-9d1e-000000000000', 'not-a-uuid']);

it('forbids regular users on every batch endpoint, even for unknown ids', function (): void {
    $batch = EventBatch::factory()->failed()->create();
    actingAsRole(Role::User);

    getJson('/api/v1/admin/event-batches')->assertForbidden();
    getJson("/api/v1/admin/event-batches/{$batch->id}")->assertForbidden();
    postJson("/api/v1/admin/event-batches/{$batch->id}/retry")->assertForbidden();
    getJson('/api/v1/admin/event-batches/0199f0a2-6c1e-7b3a-9d1e-000000000000')->assertForbidden();

    expect($batch->refresh()->status)->toBe(BatchStatus::Failed);
});
