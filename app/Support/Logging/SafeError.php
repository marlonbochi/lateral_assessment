<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Illuminate\Database\QueryException;
use Throwable;

/**
 * A short, log-safe description of a failure.
 *
 * QueryException messages embed the SQL with its bindings, and the inbox
 * INSERT binds the whole batch body; only the driver message is kept, so no
 * payload or other bound value can reach logs or `event_batches.last_error`.
 */
final class SafeError
{
    public const int MAX_LENGTH = 1000;

    public static function describe(Throwable $e): string
    {
        $message = $e instanceof QueryException
            ? sprintf('SQLSTATE %s: %s', (string) $e->getCode(), $e->getPrevious()?->getMessage() ?? 'query failed')
            : $e->getMessage();

        // The driver message can append the full SQL; never keep it.
        $message = (string) preg_replace('/\s*\(Connection: .*$/s', '', $message);

        return mb_substr($e::class.': '.$message, 0, self::MAX_LENGTH);
    }
}
