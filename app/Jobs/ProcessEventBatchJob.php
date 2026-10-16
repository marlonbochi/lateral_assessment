<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Cms\ProcessEventBatch;
use App\Enums\BatchStatus;
use App\Models\EventBatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processes one inbox batch. Retried with backoff on failure; once retries
 * are exhausted the batch is kept as `failed` for operator re-processing, so
 * an acknowledged batch is never silently lost.
 */
final class ProcessEventBatchJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $batchId) {}

    public function tries(): int
    {
        return (int) config('cms.processing.tries');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        /** @var list<int> $backoff */
        $backoff = config('cms.processing.backoff');

        return $backoff;
    }

    public function handle(ProcessEventBatch $process): void
    {
        // Counted in its own statement so it survives the rollback of a failed attempt.
        EventBatch::query()->whereKey($this->batchId)->increment('attempts');

        $process->handle($this->batchId);
    }

    public function failed(?Throwable $exception): void
    {
        EventBatch::query()->whereKey($this->batchId)->update([
            'status' => BatchStatus::Failed,
            'failed_at' => now(),
            'last_error' => $exception === null
                ? 'Processing failed.'
                : mb_substr($exception::class.': '.$exception->getMessage(), 0, 1000),
        ]);
    }
}
