<?php

declare(strict_types=1);

namespace App\Actions\Cms;

use App\Enums\BatchStatus;
use App\Jobs\ProcessEventBatchJob;
use App\Models\EventBatch;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Durably records a validated batch in the inbox and schedules its processing
 * as one atomic step (research R2).
 */
final class AcceptEventBatch
{
    public function __construct(private readonly Dispatcher $dispatcher) {}

    /**
     * @param  string  $rawEvents  The validated request body (a JSON list), stored
     *                             without being re-encoded by PHP so payloads keep
     *                             their exact values.
     */
    public function handle(string $rawEvents, int $eventCount, ?string $requestId = null): EventBatch
    {
        return DB::transaction(function () use ($rawEvents, $eventCount, $requestId): EventBatch {
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
                    ->onConnection((string) config('cms.queue.connection'))
                    ->onQueue((string) config('cms.queue.name')),
            );

            return $batch;
        });
    }
}
