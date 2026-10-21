<?php

declare(strict_types=1);

use App\Models\EventBatch;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/*
 * Child processes use their own database connections and only see committed
 * data, so this file uses DatabaseTruncation instead of RefreshDatabase.
 */
uses(DatabaseTruncation::class)->group('concurrency');

/**
 * @param  list<array<string, mixed>>  $events
 */
function committedBatch(array $events): string
{
    return EventBatch::query()->forceCreate([
        'status' => 'pending',
        'events' => json_encode($events, JSON_THROW_ON_ERROR),
        'event_count' => count($events),
        'received_at' => now(),
    ])->id;
}

function processInChild(string $batchId, string $database): Process
{
    $process = new Process(
        ['php', 'artisan', 'cms:batches:process', $batchId, '-v'],
        base_path(),
        // Under --parallel the test database is cms_sync_test_test_N, not the
        // cms_sync_test from .env.testing: pass the current one explicitly.
        ['APP_ENV' => 'testing', 'DB_DATABASE' => $database],
    );
    $process->start();

    return $process;
}

it('processes conflicting batches in parallel without deadlocks or lost updates', function (): void {
    $database = (string) config('database.connections.pgsql.database');

    foreach (range(1, 50) as $i) {
        // Opposite entity order in each batch: unsorted locks would deadlock.
        $first = committedBatch([
            cmsEvent('publish', "x-{$i}", 2, ['title' => 'x2']),
            cmsEvent('publish', "y-{$i}", 2, ['title' => 'y2']),
        ]);
        $second = committedBatch([
            cmsEvent('publish', "y-{$i}", 3, ['title' => 'y3']),
            cmsEvent('publish', "x-{$i}", 3, ['title' => 'x3']),
        ]);

        $processes = [processInChild($first, $database), processInChild($second, $database)];

        foreach ($processes as $process) {
            $process->wait();

            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput().$process->getOutput())
                ->and($process->getOutput())->toContain("database: {$database}");
        }

        $versions = DB::table('contents')->whereIn('cms_id', ["x-{$i}", "y-{$i}"])->pluck('version', 'cms_id')->all();

        expect($versions)->toEqual(["x-{$i}" => 3, "y-{$i}" => 3]);
    }

    expect(DB::table('event_batches')->where('status', 'processed')->count())->toBe(100);
});
