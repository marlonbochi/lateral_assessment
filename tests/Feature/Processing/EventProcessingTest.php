<?php

declare(strict_types=1);

use App\Enums\BatchStatus;
use App\Models\Content;
use App\Models\EventBatch;
use Illuminate\Support\Facades\DB;

/**
 * Deliver batches through the webhook, then run the real queue worker.
 *
 * @param  list<array<string, mixed>>  ...$batches
 */
function deliver(array ...$batches): void
{
    foreach ($batches as $batch) {
        postBatch($batch)->assertAccepted();
    }

    drainQueue();
}

/**
 * @return array{status: string, version: int, payload: mixed, is_disabled: bool}|null
 */
function contentState(string $cmsId): ?array
{
    $row = DB::table('contents')->where('cms_id', $cmsId)->first();

    if ($row === null) {
        return null;
    }

    return [
        'status' => (string) $row->status,
        'version' => (int) $row->version,
        'payload' => $row->payload === null ? null : json_decode((string) $row->payload, true),
        'is_disabled' => (bool) $row->is_disabled,
    ];
}

it('1. stores a published entity from its first publish event', function (): void {
    deliver([cmsEvent('publish', 'content-123', 2, ['title' => 'Example content'])]);

    expect(contentState('content-123'))->toMatchArray([
        'status' => 'published', 'version' => 2, 'payload' => ['title' => 'Example content'],
    ]);
});

it('2. ignores a duplicate event even when its content differs', function (): void {
    deliver(
        [cmsEvent('publish', 'content-123', 2, ['title' => 'Original'])],
        [cmsEvent('publish', 'content-123', 2, ['title' => 'Replayed with other content'])],
    );

    expect(contentState('content-123'))->toMatchArray(['version' => 2, 'payload' => ['title' => 'Original']]);
});

it('3. ignores a stale event delivered out of order', function (): void {
    deliver(
        [cmsEvent('publish', 'content-123', 5, ['title' => 'v5'])],
        [cmsEvent('publish', 'content-123', 3, ['title' => 'v3'])],
    );

    expect(contentState('content-123'))->toMatchArray(['version' => 5, 'payload' => ['title' => 'v5']]);
});

it('4. applies an unpublish for a version that was never published or received (edge case)', function (): void {
    deliver(
        [cmsEvent('publish', 'content-123', 3, ['title' => 'v3 published'])],
        [cmsEvent('unpublish', 'content-123', 4, ['title' => 'v4 never published'])],
    );

    expect(contentState('content-123'))->toMatchArray([
        'status' => 'unpublished', 'version' => 4, 'payload' => ['title' => 'v4 never published'],
    ]);
});

it('5. accepts an unpublish as the first event for an entity', function (): void {
    deliver([cmsEvent('unpublish', 'content-999', 7, ['title' => 'Hidden'])]);

    expect(contentState('content-999'))->toMatchArray(['status' => 'unpublished', 'version' => 7, 'payload' => ['title' => 'Hidden']]);
});

it('6. deletes content leaving a tombstone without payload', function (): void {
    deliver(
        [cmsEvent('publish', 'content-789', 4, ['title' => 'Soon gone'])],
        [cmsEvent('delete', 'content-789', 5)],
    );

    expect(contentState('content-789'))->toMatchArray(['status' => 'deleted', 'version' => 5, 'payload' => null]);
});

it('7. never restores deleted content from an older event delivered late', function (): void {
    deliver(
        [cmsEvent('publish', 'content-789', 4, ['title' => 'v4'])],
        [cmsEvent('delete', 'content-789', 5)],
        [cmsEvent('publish', 'content-789', 4, ['title' => 'v4 again'])],
        [cmsEvent('unpublish', 'content-789', 3, ['title' => 'v3'])],
    );

    expect(contentState('content-789'))->toMatchArray(['status' => 'deleted', 'version' => 5, 'payload' => null]);
});

it('8. records a deletion received first, so older events stay ignored', function (): void {
    deliver(
        [cmsEvent('delete', 'content-555', 3)],
        [cmsEvent('publish', 'content-555', 2, ['title' => 'older'])],
    );

    expect(contentState('content-555'))->toMatchArray(['status' => 'deleted', 'version' => 3, 'payload' => null]);
});

it('9. applies events of one batch in the order received', function (): void {
    deliver([
        cmsEvent('publish', 'content-1', 2, ['title' => 'v2']),
        cmsEvent('unpublish', 'content-1', 3, ['title' => 'v3']),
    ]);

    expect(contentState('content-1'))->toMatchArray(['status' => 'unpublished', 'version' => 3, 'payload' => ['title' => 'v3']]);
});

it('10. ignores a lower version that comes later in the same batch', function (): void {
    deliver([
        cmsEvent('unpublish', 'content-1', 3, ['title' => 'v3']),
        cmsEvent('publish', 'content-1', 2, ['title' => 'v2']),
    ]);

    expect(contentState('content-1'))->toMatchArray(['status' => 'unpublished', 'version' => 3, 'payload' => ['title' => 'v3']]);
});

it('11. re-creates deleted content from a newer event and keeps the local override', function (): void {
    Content::factory()->published()->disabled()->version(4)->create(['cms_id' => 'content-789']);

    deliver(
        [cmsEvent('delete', 'content-789', 5)],
        [cmsEvent('publish', 'content-789', 6, ['title' => 'Back'])],
    );

    expect(contentState('content-789'))->toMatchArray([
        'status' => 'published', 'version' => 6, 'payload' => ['title' => 'Back'], 'is_disabled' => true,
    ]);
});

it('12. accepts version gaps', function (): void {
    deliver(
        [cmsEvent('publish', 'content-1', 3, ['title' => 'v3'])],
        [cmsEvent('publish', 'content-1', 9, ['title' => 'v9'])],
    );

    expect(contentState('content-1'))->toMatchArray(['version' => 9, 'payload' => ['title' => 'v9']]);
});

it('never changes the local override columns when applying CMS events', function (): void {
    $content = Content::factory()->published()->disabled()->version(1)->create(['cms_id' => 'content-1']);
    $before = DB::table('contents')->where('id', $content->id)->first(['is_disabled', 'disabled_at', 'disabled_by']);

    deliver(
        [cmsEvent('unpublish', 'content-1', 2, ['title' => 'v2'])],
        [cmsEvent('publish', 'content-1', 3, ['title' => 'v3'])],
    );

    expect(DB::table('contents')->where('id', $content->id)->first(['is_disabled', 'disabled_at', 'disabled_by']))->toEqual($before);
});

it('marks processed batches with applied and ignored counts', function (): void {
    deliver([
        cmsEvent('publish', 'content-1', 2, ['title' => 'v2']),
        cmsEvent('publish', 'content-1', 2, ['title' => 'dup']),
        cmsEvent('publish', 'content-1', 1, ['title' => 'stale']),
        cmsEvent('delete', 'content-2', 1),
    ]);

    $batch = EventBatch::query()->sole();

    expect($batch->status)->toBe(BatchStatus::Processed)
        ->and($batch->applied_count)->toBe(2)
        ->and($batch->ignored_count)->toBe(2)
        ->and($batch->attempts)->toBe(1)
        ->and($batch->processed_at)->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0);
});
