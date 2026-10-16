<?php

declare(strict_types=1);

use Tests\TestCase;

it('accepts the test database and its per-process parallel copies', function (string $name): void {
    TestCase::assertTestDatabase($name);

    expect(true)->toBeTrue();
})->with([
    'test database' => 'cms_sync_test',
    'parallel worker database' => 'cms_sync_test_test_3',
]);

it('refuses any database that is not a test database', function (string $name): void {
    TestCase::assertTestDatabase($name);
})->with([
    'development database' => 'cms_sync',
    'test-looking suffix' => 'cms_sync_test_prod',
    'empty name' => '',
])->throws(RuntimeException::class);
