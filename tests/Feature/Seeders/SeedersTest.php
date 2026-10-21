<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\PerformanceSeeder;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\seed;

it('seeds demo consumers and content in every state, without tokens or secrets', function (): void {
    seed(DemoSeeder::class);

    expect(User::query()->where('role', Role::Admin->value)->count())->toBe(1)
        ->and(User::query()->where('role', Role::User->value)->count())->toBe(1)
        ->and(Content::query()->count())->toBeGreaterThanOrEqual(30)
        ->and(Content::query()->distinct()->pluck('status')->map(fn ($s) => $s->value)->sort()->values()->all())
        ->toBe(['deleted', 'published', 'unpublished'])
        ->and(Content::query()->where('is_disabled', true)->exists())->toBeTrue()
        ->and(PersonalAccessToken::query()->count())->toBe(0);
});

it('is idempotent', function (): void {
    seed(DemoSeeder::class);
    seed(DemoSeeder::class);

    expect(User::query()->count())->toBe(2);
});

it('seeds the requested number of rows for performance checks with a realistic mix', function (): void {
    config(['cms.performance.seed_rows' => 2000]);

    Artisan::call('db:seed', ['--class' => PerformanceSeeder::class, '--force' => true]);

    $total = Content::query()->count();
    $published = Content::query()->where('status', 'published')->count();
    $disabled = Content::query()->where('is_disabled', true)->count();

    expect($total)->toBe(2000)
        ->and($published)->toBeGreaterThan(1400)->toBeLessThan(1800)
        ->and($disabled)->toBeGreaterThan(0)->toBeLessThan(150);
});
