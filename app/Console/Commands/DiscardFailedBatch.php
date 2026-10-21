<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BatchStatus;
use App\Models\EventBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Permanently drops a failed batch. Command line only (never exposed through
 * the API), confirmed interactively unless --force, and always logged.
 */
final class DiscardFailedBatch extends Command
{
    protected $signature = 'cms:batches:discard
        {id : Id of the failed batch to discard}
        {--force : Do not ask for confirmation}';

    protected $description = 'Discard a failed event batch (its events will never be applied)';

    public function handle(): int
    {
        $id = $this->argument('id');
        $batch = is_string($id) ? EventBatch::query()->whereKey($id)->first() : null;

        if ($batch === null || $batch->status !== BatchStatus::Failed) {
            $this->components->error('Only an existing failed batch can be discarded.');

            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm("Discard failed batch {$batch->id}? Its events will never be applied.")) {
            $this->components->warn('Nothing discarded.');

            return self::FAILURE;
        }

        $batch->delete();

        Log::channel('cms')->warning('cms.batch.discarded', [
            'batch_id' => $batch->id,
            'event_count' => $batch->event_count,
            'origin' => 'cli',
        ]);

        $this->components->info("Batch {$batch->id} discarded.");

        return self::SUCCESS;
    }
}
