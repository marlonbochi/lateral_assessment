<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use App\Models\EventBatch;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('lets only administrators pass the admin gate, with the contract message', function (): void {
    $user = User::factory()->create(['role' => Role::User]);
    $admin = User::factory()->create(['role' => Role::Admin]);

    $denied = Gate::forUser($user)->inspect('admin');

    expect(Gate::forUser($admin)->allows('admin'))->toBeTrue()
        ->and($denied->denied())->toBeTrue()
        ->and($denied->message())->toBe('This action requires the admin role.');
});

it('authorizes each ability per role', function (string $ability, object|string|null $target, bool $user, bool $admin): void {
    $regular = User::factory()->create(['role' => Role::User]);
    $administrator = User::factory()->create(['role' => Role::Admin]);

    expect(Gate::forUser($regular)->allows($ability, $target))->toBe($user)
        ->and(Gate::forUser($administrator)->allows($ability, $target))->toBe($admin);
})->with([
    'view any content' => ['viewAny', fn () => Content::class, true, true],
    'view content' => ['view', fn () => Content::factory()->create(), true, true],
    'manage the local override' => ['manageOverride', fn () => Content::factory()->create(), false, true],
    'filter contents by state' => ['filter-contents', fn () => null, false, true],
    'view any event batch' => ['viewAny', fn () => EventBatch::class, false, true],
    'view an event batch' => ['view', fn () => EventBatch::factory()->create(), false, true],
    'retry an event batch' => ['retry', fn () => EventBatch::factory()->create(), false, true],
]);
