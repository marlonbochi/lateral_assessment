<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BatchStatus;
use App\Models\EventBatch;
use Illuminate\Console\Command;

final class ListFailedBatches extends Command
{
    protected $signature = 'cms:batches:failed';

    protected $description = 'List event batches that exhausted their processing retries';

    public function handle(): int
    {
        $batches = EventBatch::query()
            ->where('status', BatchStatus::Failed->value)
            ->orderByDesc('failed_at')
            ->get(['id', 'event_count', 'attempts', 'received_at', 'failed_at', 'last_error']);

        if ($batches->isEmpty()) {
            $this->components->info('No failed batches.');

            return self::SUCCESS;
        }

        $this->table(
            ['Batch', 'Events', 'Attempts', 'Received', 'Failed', 'Last error'],
            $batches->map(fn (EventBatch $b): array => [
                $b->id,
                $b->event_count,
                $b->attempts,
                $b->received_at->toDateTimeString(),
                $b->failed_at?->toDateTimeString(),
                mb_strimwidth((string) $b->last_error, 0, 80, '…'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
