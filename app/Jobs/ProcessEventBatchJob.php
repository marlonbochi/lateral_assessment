<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Cms\ProcessEventBatch;
use App\Enums\BatchStatus;
use App\Models\EventBatch;
use App\Support\Logging\SafeError;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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

        try {
            $process->handle($this->batchId);
        } catch (Throwable $e) {
            Log::channel('cms')->warning('cms.batch.attempt_failed', [
                'batch_id' => $this->batchId,
                'attempt' => $this->currentAttempt(),
                'error' => SafeError::describe($e),
            ]);

            throw $e;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $error = $exception === null ? 'Processing failed.' : SafeError::describe($exception);

        EventBatch::query()->whereKey($this->batchId)->update([
            'status' => BatchStatus::Failed,
            'failed_at' => now(),
            'last_error' => $error,
        ]);

        Log::channel('cms')->error('cms.batch.failed', [
            'batch_id' => $this->batchId,
            'attempts' => $this->currentAttempt(),
            'error' => $error,
        ]);
    }

    private function currentAttempt(): int
    {
        return (int) EventBatch::query()->whereKey($this->batchId)->value('attempts');
    }
}
