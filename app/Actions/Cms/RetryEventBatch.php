<?php

declare(strict_types=1);

namespace App\Actions\Cms;

use App\Enums\BatchStatus;
use App\Exceptions\BatchNotRetryableException;
use App\Jobs\ProcessEventBatchJob;
use App\Models\EventBatch;
use App\Models\User;
use App\Support\Queue\CmsQueue;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Re-schedules a failed batch (from the admin API or the command line). The
 * status change and the new job are committed together, like the original
 * hand-off, and processing applies version precedence against the current state.
 */
final class RetryEventBatch
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly CmsQueue $queue,
    ) {}

    public function handle(EventBatch $batch, ?User $actor): EventBatch
    {
        $this->queue->ensureTransactional();

        $retried = DB::transaction(function () use ($batch, $actor): EventBatch {
            /** @var EventBatch $locked */
            $locked = EventBatch::query()->lockForUpdate()->findOrFail($batch->id);

            if ($locked->status !== BatchStatus::Failed) {
                throw new BatchNotRetryableException;
            }

            $locked->forceFill([
                'status' => BatchStatus::Pending,
                'attempts' => 0,
                'failed_at' => null,
                'retried_at' => now(),
                'retried_by' => $actor?->id,
            ])->save();

            $this->dispatcher->dispatch(
                (new ProcessEventBatchJob($locked->id))
                    ->onConnection($this->queue->connection())
                    ->onQueue($this->queue->name()),
            );

            return $locked;
        });

        Log::channel('cms')->info('cms.batch.retry_requested', [
            'batch_id' => $retried->id,
            'actor_id' => $actor?->id,
            'origin' => $actor === null ? 'cli' : 'api',
        ]);

        return $retried;
    }
}
