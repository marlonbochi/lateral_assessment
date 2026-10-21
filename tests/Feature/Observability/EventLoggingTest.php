<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;

use function Pest\Laravel\putJson;

function captureCmsLogs(): TestHandler
{
    $handler = new TestHandler;
    $channel = Log::channel('cms');

    if (! $channel instanceof Illuminate\Log\Logger || ! $channel->getLogger() instanceof Logger) {
        throw new RuntimeException('The cms channel is expected to be a Monolog logger.');
    }

    $channel->getLogger()->pushHandler($handler);

    return $handler;
}

/**
 * @return list<LogRecord>
 */
function recordsNamed(TestHandler $handler, string $message): array
{
    return array_values(array_filter($handler->getRecords(), fn (LogRecord $r): bool => $r->message === $message));
}

it('logs received, applied, ignored and processed with their context', function (): void {
    $logs = captureCmsLogs();

    $batchId = (string) postBatch([
        cmsEvent('publish', 'content-1', 2, ['title' => 'v2']),
        cmsEvent('publish', 'content-1', 2, ['title' => 'dup']),
        cmsEvent('publish', 'content-1', 1, ['title' => 'stale']),
    ])->assertAccepted()->json('data.batch_id');
    drainQueue();

    $received = recordsNamed($logs, 'cms.batch.received')[0]->context;
    $applied = recordsNamed($logs, 'cms.event.applied');
    $ignored = recordsNamed($logs, 'cms.event.ignored');
    $processed = recordsNamed($logs, 'cms.batch.processed')[0]->context;

    expect($received)->toMatchArray(['batch_id' => $batchId, 'event_count' => 3])
        ->and($received)->toHaveKey('request_id')
        ->and($applied)->toHaveCount(1)
        ->and($applied[0]->context)->toMatchArray([
            'batch_id' => $batchId, 'attempt' => 1, 'position' => 0, 'content_id' => 'content-1',
            'type' => 'publish', 'incoming_version' => 2, 'stored_version' => null,
            'previous_status' => null, 'new_status' => 'published',
        ])
        ->and(array_map(fn (LogRecord $r): mixed => $r->context['reason'], $ignored))->toBe(['duplicate', 'stale'])
        ->and($ignored[1]->context)->toMatchArray(['incoming_version' => 1, 'stored_version' => 2, 'previous_status' => 'published'])
        ->and($processed)->toMatchArray(['batch_id' => $batchId, 'attempt' => 1, 'applied' => 1, 'ignored' => 2])
        ->and($processed['duration_ms'])->toBeNumeric();
});

it('logs rejected batches with field paths only', function (): void {
    $logs = captureCmsLogs();

    postBatch([cmsEvent('publish', 'content-1', 0, ['secret' => 'SECRET-PAYLOAD-SENTINEL'])])->assertUnprocessable();

    $rejected = recordsNamed($logs, 'cms.batch.rejected');

    expect($rejected)->toHaveCount(1)
        ->and($rejected[0]->context['fields'])->toBe(['events.0.version'])
        ->and((string) json_encode($rejected[0]->context))->not->toContain('SECRET-PAYLOAD-SENTINEL')
        ->and(recordsNamed($logs, 'cms.event.applied'))->toBe([]);
});

it('logs failed attempts and the final failure, never logging events of a rolled-back batch as applied', function (): void {
    config(['cms.processing.backoff' => [0]]);
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION test_fail_all_writes() RETURNS trigger AS $$
        BEGIN
            RAISE EXCEPTION 'simulated failure';
        END
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER test_fail_all_writes BEFORE INSERT OR UPDATE ON contents
            FOR EACH ROW EXECUTE FUNCTION test_fail_all_writes();
        SQL);
    $logs = captureCmsLogs();

    $batchId = (string) postBatch([cmsEvent('publish', 'content-1', 1, ['title' => 'x'])])->json('data.batch_id');
    drainQueue();

    $attempts = recordsNamed($logs, 'cms.batch.attempt_failed');
    $failed = recordsNamed($logs, 'cms.batch.failed');

    expect($attempts)->toHaveCount(5)
        ->and(array_map(fn (LogRecord $r): mixed => $r->context['attempt'], $attempts))->toBe([1, 2, 3, 4, 5])
        ->and($attempts[0]->context['batch_id'])->toBe($batchId)
        ->and($attempts[0]->context['error'])->toContain('simulated failure')
        ->and($failed)->toHaveCount(1)
        ->and($failed[0]->context)->toMatchArray(['batch_id' => $batchId, 'attempts' => 5])
        ->and(recordsNamed($logs, 'cms.event.applied'))->toBe([])
        ->and(recordsNamed($logs, 'cms.batch.processed'))->toBe([]);
});

it('logs administrator override changes with the actor', function (): void {
    Content::factory()->published()->create(['cms_id' => 'content-1']);
    $admin = actingAsRole(Role::Admin);
    $logs = captureCmsLogs();

    putJson('/api/v1/contents/content-1/disabled')->assertOk();
    putJson('/api/v1/contents/content-1/disabled')->assertOk(); // idempotent: no second entry

    $changes = recordsNamed($logs, 'content.override.changed');

    expect($changes)->toHaveCount(1)
        ->and($changes[0]->context)->toMatchArray(['actor_id' => $admin->id, 'content_id' => 'content-1', 'disabled' => true]);
});

it('correlates logs and responses with a request id', function (): void {
    $logs = captureCmsLogs();

    $response = postBatch([cmsEvent('delete', 'content-1', 1)])->assertAccepted();
    $requestId = $response->headers->get('X-Request-Id');

    expect($requestId)->toMatch('/^[A-Za-z0-9-]{1,64}$/')
        ->and(recordsNamed($logs, 'cms.batch.received')[0]->context['request_id'])->toBe($requestId);
});

it('reuses a well-formed incoming request id and replaces a malformed one', function (): void {
    $kept = postBatch([cmsEvent('delete', 'a', 1)]);
    expect($kept->headers->get('X-Request-Id'))->not->toBeEmpty();

    Pest\Laravel\withHeaders(['X-Request-Id' => 'cms-delivery-42'])->postJson('/cms/events', [cmsEvent('delete', 'b', 1)], cmsAuthHeaders())
        ->assertHeader('X-Request-Id', 'cms-delivery-42');

    $replaced = Pest\Laravel\withHeaders(['X-Request-Id' => str_repeat('x', 65).'<script>'])
        ->postJson('/cms/events', [cmsEvent('delete', 'c', 1)], cmsAuthHeaders());

    expect($replaced->headers->get('X-Request-Id'))->not->toContain('<script>')
        ->and(strlen((string) $replaced->headers->get('X-Request-Id')))->toBeLessThanOrEqual(64);
});
