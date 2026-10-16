<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Cms\ProcessEventBatch;
use Illuminate\Console\Command;

/**
 * Test-support command: processes one batch in its own process, so the
 * concurrency test can run several of them in parallel against the database.
 */
final class ProcessBatchOnce extends Command
{
    protected $signature = 'cms:batches:process {id : Event batch id}';

    protected $description = 'Process one event batch synchronously (test support)';

    protected $hidden = true;

    public function handle(ProcessEventBatch $process): int
    {
        if ($this->output->isVerbose()) {
            $this->line('database: '.(string) config('database.connections.pgsql.database'));
        }

        $id = $this->argument('id');

        if (! is_string($id) || $id === '') {
            $this->components->error('A batch id is required.');

            return self::FAILURE;
        }

        $result = $process->handle($id);

        $this->line(sprintf('applied: %d, ignored: %d', $result->applied(), $result->ignored()));

        return self::SUCCESS;
    }
}
