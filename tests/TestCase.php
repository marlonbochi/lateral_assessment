<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Matches the dedicated test database and the per-process copies Laravel
     * creates for parallel runs (e.g. cms_sync_test_test_3).
     */
    private const string TEST_DATABASE_PATTERN = '/_test(_test_\d+)?$/';

    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.pgsql.database');

        self::assertTestDatabase(is_string($database) ? $database : '');
    }

    /**
     * Abort the run unless the configured database is a test database, so the
     * suite can never touch the development (or any other) database.
     */
    public static function assertTestDatabase(string $name): void
    {
        if (preg_match(self::TEST_DATABASE_PATTERN, $name) !== 1) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against database [%s]: expected a *_test database.',
                $name,
            ));
        }
    }
}
