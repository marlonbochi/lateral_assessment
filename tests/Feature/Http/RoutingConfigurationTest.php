<?php

declare(strict_types=1);

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

it('defines the named rate limiters from configuration', function (): void {
    config(['cms.webhook.rate_limit_per_minute' => 7, 'cms.api.rate_limit_per_minute' => 9]);

    $webhook = RateLimiter::limiter('cms-webhook') ?? throw new RuntimeException('cms-webhook limiter is not defined');
    $api = RateLimiter::limiter('api') ?? throw new RuntimeException('api limiter is not defined');

    expect($webhook(Request::create('/cms/events', 'POST'))->maxAttempts)->toBe(7)
        ->and($api(Request::create('/api/v1/contents'))->maxAttempts)->toBe(9);
});

it('lets controllers authorize through policies', function (): void {
    expect(class_uses(Controller::class))->toContain(AuthorizesRequests::class);
});

it('configures Sanctum as stateless prefixed bearer tokens with an expiry', function (): void {
    expect(config('sanctum.stateful'))->toBe([])
        ->and(config('sanctum.guard'))->toBe([])
        ->and(config('sanctum.token_prefix'))->toBe('cms_')
        ->and(config('sanctum.expiration'))->toBe(129600);
});

it('does not expose the scaffolded /api/user route', function (): void {
    expect(Route::has('user') || collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => $r->uri() === 'api/user'))->toBeFalse();
});
