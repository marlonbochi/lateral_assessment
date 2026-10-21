<?php

declare(strict_types=1);

use App\Support\Logging\SafeError;
use Illuminate\Database\QueryException;

it('keeps only the driver message of a query exception, never the SQL bindings', function (): void {
    $e = new QueryException(
        'pgsql',
        'insert into "event_batches" ("events") values (?)',
        ['[{"payload":{"secret":"SECRET-PAYLOAD-SENTINEL"}}]'],
        new PDOException('SQLSTATE[08006]: connection failure'),
    );

    $described = SafeError::describe($e);

    expect($described)->toStartWith(QueryException::class.': SQLSTATE')->toContain('connection failure');
    expect(str_contains($described, 'SECRET-PAYLOAD-SENTINEL'))->toBeFalse();
    expect(str_contains($described, 'insert into'))->toBeFalse();
});

it('describes other failures with their class and message, truncated', function (): void {
    $described = SafeError::describe(new RuntimeException(str_repeat('x', 5000)));

    expect($described)->toStartWith('RuntimeException: xxx')
        ->and(mb_strlen($described))->toBe(SafeError::MAX_LENGTH);
});
