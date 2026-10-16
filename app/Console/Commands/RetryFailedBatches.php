<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Cms\RetryEventBatch;
use App\Enums\BatchStatus;
use App\Exceptions\BatchNotRetryableException;
use App\Models\EventBatch;
use Illuminate\Console\Command;

final class RetryFailedBatches extends Command
{
    protected $signature = 'cms:batches:retry
        {id? : Id of the failed batch to re-process}
        {--all : Re-process every failed batch}';

    protected $description = 'Re-process failed event batches';

    public function handle(RetryEventBatch $retry): int
    {
        $id = $this->argument('id');

        if (! $this->option('all') && ! is_string($id)) {
            $this->components->error('Pass a batch id or --all.');

            return self::FAILURE;
        }

        $batches = $this->option('all')
            ? EventBatch::query()->where('status', BatchStatus::Failed->value)->get()
            : EventBatch::query()->whereKey($id)->get();

        if ($batches->isEmpty()) {
            $this->components->error('No matching batch.');

            return self::FAILURE;
        }

        foreach ($batches as $batch) {
            try {
                $retry->handle($batch, null);
                $this->components->info("Batch {$batch->id} re-scheduled.");
            } catch (BatchNotRetryableException $e) {
                $this->components->error("Batch {$batch->id}: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
