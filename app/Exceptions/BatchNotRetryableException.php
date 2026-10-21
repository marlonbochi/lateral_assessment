<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Only failed batches can be re-processed (rendered as 409 Conflict).
 */
final class BatchNotRetryableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Only failed batches can be retried.');
    }
}
