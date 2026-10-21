<?php

declare(strict_types=1);

namespace App\Support\Queue;

use App\Exceptions\QueueNotTransactionalException;
use Illuminate\Contracts\Config\Repository;

/**
 * The one place that reads the CMS queue configuration (config/cms.php), used
 * by the webhook, the retry action and the `cms:work` worker alike.
 *
 * The queue must be a database queue on the application database: only then
 * does dispatching a job inside the inbox transaction commit or roll back
 * with the batch row (research R2).
 */
final readonly class CmsQueue
{
    public function __construct(private Repository $config) {}

    public function connection(): string
    {
        return (string) $this->config->get('cms.queue.connection');
    }

    public function name(): string
    {
        return (string) $this->config->get('cms.queue.name');
    }

    /**
     * @throws QueueNotTransactionalException
     */
    public function ensureTransactional(): void
    {
        $connection = $this->connection();
        $default = $this->config->get('database.default');
        $driver = $this->config->get("queue.connections.{$connection}.driver");
        $database = $this->config->get("queue.connections.{$connection}.connection") ?? $default;

        if ($driver !== 'database' || $database !== $default) {
            throw new QueueNotTransactionalException($connection);
        }
    }
}
