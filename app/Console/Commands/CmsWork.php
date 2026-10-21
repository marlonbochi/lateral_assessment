<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\QueueNotTransactionalException;
use App\Support\Queue\CmsQueue;
use Illuminate\Console\Command;

/**
 * The batch worker. Connection and queue name come from config/cms.php, so
 * the worker always consumes the queue the webhook writes to. Retries and
 * backoff are defined by the job itself.
 */
final class CmsWork extends Command
{
    protected $signature = 'cms:work
        {--sleep=1 : Seconds to wait before polling an empty queue again}
        {--max-time=3600 : Stop after this many seconds (the container restarts the worker)}
        {--stop-when-empty : Stop once the queue is empty}';

    protected $description = 'Process CMS event batches from the configured queue';

    public function handle(CmsQueue $queue): int
    {
        try {
            $queue->ensureTransactional();
        } catch (QueueNotTransactionalException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->call('queue:work', [
            'connection' => $queue->connection(),
            '--queue' => $queue->name(),
            '--sleep' => $this->option('sleep'),
            '--max-time' => $this->option('max-time'),
            '--stop-when-empty' => (bool) $this->option('stop-when-empty'),
        ]);
    }
}
