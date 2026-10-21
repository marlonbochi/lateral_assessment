<?php

declare(strict_types=1);

namespace App\Actions\Cms;

use App\Enums\BatchStatus;
use App\Jobs\ProcessEventBatchJob;
use App\Models\EventBatch;
use App\Support\Queue\CmsQueue;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Durably records a validated batch in the inbox and schedules its processing
 * as one atomic step (research R2).
 */
final class AcceptEventBatch
{
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly CmsQueue $queue,
    ) {}

    /**
     * @param  string  $rawEvents  The validated request body (a JSON list), stored
     *                             without being re-encoded by PHP so payloads keep
     *                             their exact values.
     */
    public function handle(string $rawEvents, int $eventCount, ?string $requestId = null): EventBatch
    {
        // Never acknowledge a batch whose job could not share its transaction.
        $this->queue->ensureTransactional();

        $batch = DB::transaction(function () use ($rawEvents, $eventCount, $requestId): EventBatch {
            $batch = EventBatch::query()->forceCreate([
                'status' => BatchStatus::Pending,
                'events' => $rawEvents,
                'event_count' => $eventCount,
                'attempts' => 0,
                'request_id' => $requestId,
                'received_at' => now(),
            ]);

            // Dispatched INSIDE the transaction on purpose (not afterCommit): the
            // queue uses the database driver on this same PostgreSQL connection,
            // so the inbox row and its job commit or roll back together. There
            // is no window where a batch is acknowledged but never queued.
            $this->dispatcher->dispatch(
                (new ProcessEventBatchJob($batch->id))
                    ->onConnection($this->queue->connection())
                    ->onQueue($this->queue->name()),
            );

            return $batch;
        });

        Log::channel('cms')->info('cms.batch.received', [
            'batch_id' => $batch->id,
            'event_count' => $eventCount,
            'request_id' => $requestId,
        ]);

        return $batch;
    }
}
