<?php

declare(strict_types=1);

use App\Enums\BatchStatus;
use App\Models\EventBatch;
use Illuminate\Support\Facades\DB;

/**
 * Inject a database failure when content-3 is written. Implemented as a
 * PostgreSQL trigger inside the test transaction: no hook in production code.
 */
function failWritesOfContent3(): void
{
    DB::unprepared(<<<'SQL'
        CREATE OR REPLACE FUNCTION test_fail_content_write() RETURNS trigger AS $$
        BEGIN
            IF NEW.cms_id = 'content-3' THEN
                RAISE EXCEPTION 'simulated failure while writing %', NEW.cms_id;
            END IF;
            RETURN NEW;
        END
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER test_fail_content_write BEFORE INSERT OR UPDATE ON contents
            FOR EACH ROW EXECUTE FUNCTION test_fail_content_write();
        SQL);
}

function stopFailingWrites(): void
{
    DB::unprepared('DROP TRIGGER test_fail_content_write ON contents; DROP FUNCTION test_fail_content_write();');
}

it('applies nothing from a batch when one of its events fails, then applies all of it on retry', function (): void {
    config(['cms.processing.backoff' => [60]]);
    failWritesOfContent3();

    postBatch([
        cmsEvent('publish', 'content-1', 1, ['title' => '1']),
        cmsEvent('publish', 'content-2', 1, ['title' => '2']),
        cmsEvent('publish', 'content-3', 1, ['title' => '3']),
        cmsEvent('publish', 'content-4', 1, ['title' => '4']),
        cmsEvent('delete', 'content-5', 1),
    ])->assertAccepted();

    drainQueue();

    $batch = EventBatch::query()->sole();

    expect(DB::table('contents')->count())->toBe(0)
        ->and($batch->status)->toBe(BatchStatus::Pending)
        ->and($batch->attempts)->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(1);

    // Remove the fault and make the released job available immediately.
    stopFailingWrites();
    DB::table('jobs')->update(['available_at' => now()->subSecond()->getTimestamp()]);

    drainQueue();

    expect(DB::table('contents')->pluck('cms_id')->sort()->values()->all())
        ->toBe(['content-1', 'content-2', 'content-3', 'content-4', 'content-5'])
        ->and($batch->fresh()?->status)->toBe(BatchStatus::Processed)
        ->and($batch->fresh()?->attempts)->toBe(2);
});
