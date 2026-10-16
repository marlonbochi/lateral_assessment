<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/*
 * Migrations must be reversible (constitution, Principle V).
 *
 * This file deliberately does not use DatabaseMigrations: its tear-down rolls
 * the schema back, which would break RefreshDatabase tests that run later in
 * the same parallel worker. The test resets and re-migrates explicitly and
 * always leaves the schema migrated.
 */
it('rolls every migration back and re-applies them cleanly', function (): void {
    Artisan::call('migrate', ['--force' => true]);

    expect(Artisan::call('migrate:reset', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable('contents'))->toBeFalse()
        ->and(Schema::hasTable('event_batches'))->toBeFalse()
        ->and(Schema::hasTable('users'))->toBeFalse();

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0)
        ->and(Schema::hasTable('contents'))->toBeTrue()
        ->and(Schema::hasTable('event_batches'))->toBeTrue()
        ->and(Schema::hasTable('users'))->toBeTrue();
});
