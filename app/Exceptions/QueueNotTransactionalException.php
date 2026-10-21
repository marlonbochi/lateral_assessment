<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The CMS queue cannot join the inbox transaction (rendered as 503 on the
 * webhook, so the CMS keeps retrying until the configuration is fixed).
 */
final class QueueNotTransactionalException extends RuntimeException
{
    public function __construct(string $connection)
    {
        parent::__construct(
            "The CMS queue connection [{$connection}] must be a database queue on the application database, "
            .'so each batch and its job are committed together (see research R2).',
        );
    }
}
