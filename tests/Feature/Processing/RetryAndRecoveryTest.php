<?php

declare(strict_types=1);

use App\Actions\Cms\RetryEventBatch;
use App\Enums\BatchStatus;
use App\Models\EventBatch;
use Illuminate\Support\Facades\DB;

function failEveryContentWrite(): void
{
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION test_fail_recovery() RETURNS trigger AS $$
        BEGIN RAISE EXCEPTION 'simulated outage'; END
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER test_fail_recovery BEFORE INSERT OR UPDATE ON contents
            FOR EACH ROW EXECUTE FUNCTION test_fail_recovery();
        SQL);
}

function restoreContentWrites(): void
{
    DB::unprepared('DROP TRIGGER test_fail_recovery ON contents; DROP FUNCTION test_fail_recovery();');
}

beforeEach(fn () => config(['cms.processing.backoff' => [0]]));

it('keeps an acknowledged batch as failed after all retries, then recovers it (SC-005a)', function (): void {
    failEveryContentWrite();
    $batchId = (string) postBatch([cmsEvent('publish', 'content-1', 1, ['title' => 'v1'])])->json('data.batch_id');

    drainQueue();

    $batch = EventBatch::query()->findOrFail($batchId);
    expect($batch->status)->toBe(BatchStatus::Failed)
        ->and($batch->attempts)->toBe(5)
        ->and($batch->failed_at)->not->toBeNull()
        ->and($batch->last_error)->toContain('simulated outage')
        ->and($batch->last_error)->not->toContain('insert into')
        ->and(DB::table('contents')->count())->toBe(0);

    restoreContentWrites();
    app(RetryEventBatch::class)->handle($batch, null);
    drainQueue();

    $batch->refresh();
    expect($batch->status)->toBe(BatchStatus::Processed)
        ->and($batch->applied_count)->toBe(1)
        ->and(DB::table('contents')->where('cms_id', 'content-1')->value('version'))->toBe(1);
});

it('applies version precedence against the current state when re-processing', function (): void {
    failEveryContentWrite();
    $oldBatchId = (string) postBatch([cmsEvent('publish', 'content-1', 2, ['title' => 'v2'])])->json('data.batch_id');
    drainQueue();
    restoreContentWrites();

    // Meanwhile the CMS delivers a newer version that is processed normally.
    postBatch([cmsEvent('publish', 'content-1', 3, ['title' => 'v3'])])->assertAccepted();
    drainQueue();

    app(RetryEventBatch::class)->handle(EventBatch::query()->findOrFail($oldBatchId), null);
    drainQueue();

    $old = EventBatch::query()->findOrFail($oldBatchId);
    expect($old->status)->toBe(BatchStatus::Processed)
        ->and($old->applied_count)->toBe(0)
        ->and($old->ignored_count)->toBe(1)
        ->and(DB::table('contents')->where('cms_id', 'content-1')->value('version'))->toBe(3);
});
