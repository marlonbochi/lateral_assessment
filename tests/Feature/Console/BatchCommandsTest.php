<?php

declare(strict_types=1);

use App\Enums\BatchStatus;
use App\Models\EventBatch;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\PendingCommand;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;

use function Pest\Laravel\artisan;

/**
 * @param  array<string, mixed>  $parameters
 */
function pendingCommand(string $command, array $parameters = []): PendingCommand
{
    $pending = artisan($command, $parameters);

    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('Console output mocking is disabled.');
    }

    return $pending;
}

it('lists failed batches', function (): void {
    $failed = EventBatch::factory()->failed()->create();
    EventBatch::factory()->processed()->create();

    Artisan::call('cms:batches:failed');

    expect(Artisan::output())->toContain($failed->id)->toContain('simulated failure');
});

it('reports when there are no failed batches', function (): void {
    expect(Artisan::call('cms:batches:failed'))->toBe(0)
        ->and(Artisan::output())->toContain('No failed batches');
});

it('re-processes one failed batch from the command line', function (): void {
    $batch = EventBatch::factory()->failed()->create();

    expect(Artisan::call('cms:batches:retry', ['id' => $batch->id]))->toBe(0);

    $batch->refresh();
    expect($batch->status)->toBe(BatchStatus::Pending)
        ->and($batch->retried_by)->toBeNull()
        ->and($batch->retried_at)->not->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('re-processes every failed batch with --all', function (): void {
    EventBatch::factory()->failed()->count(3)->create();
    EventBatch::factory()->processed()->create();

    expect(Artisan::call('cms:batches:retry', ['--all' => true]))->toBe(0)
        ->and(EventBatch::query()->where('status', 'pending')->count())->toBe(3)
        ->and(DB::table('jobs')->count())->toBe(3);
});

it('refuses to retry a batch that is not failed', function (): void {
    $batch = EventBatch::factory()->processed()->create();

    expect(Artisan::call('cms:batches:retry', ['id' => $batch->id]))->toBe(1)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('discards a failed batch after confirmation and logs it', function (): void {
    $batch = EventBatch::factory()->failed()->create();
    $logs = new TestHandler;
    $channel = Log::channel('cms');
    if ($channel instanceof Illuminate\Log\Logger && $channel->getLogger() instanceof Logger) {
        $channel->getLogger()->pushHandler($logs);
    }

    pendingCommand('cms:batches:discard', ['id' => $batch->id])
        ->expectsConfirmation('Discard failed batch '.$batch->id.'? Its events will never be applied.', 'no')
        ->assertFailed();
    expect(EventBatch::query()->whereKey($batch->id)->exists())->toBeTrue();

    expect(Artisan::call('cms:batches:discard', ['id' => $batch->id, '--force' => true]))->toBe(0)
        ->and(EventBatch::query()->whereKey($batch->id)->exists())->toBeFalse()
        ->and($logs->hasRecordThatContains('cms.batch.discarded', Level::Warning))->toBeTrue();
});

it('refuses to discard a batch that is not failed', function (): void {
    $batch = EventBatch::factory()->pending()->create();

    expect(Artisan::call('cms:batches:discard', ['id' => $batch->id, '--force' => true]))->toBe(1)
        ->and(EventBatch::query()->whereKey($batch->id)->exists())->toBeTrue();
});
