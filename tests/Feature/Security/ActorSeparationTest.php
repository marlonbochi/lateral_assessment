<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use App\Models\EventBatch;
use App\Models\User;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

it('does not let CMS credentials read consumer resources', function (string $uri): void {
    Content::factory()->published()->create(['cms_id' => 'x']);

    withHeaders(cmsAuthHeaders())->getJson($uri)
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer')
        ->assertHeader('Content-Type', 'application/problem+json');
})->with(['/api/v1/contents', '/api/v1/contents/x']);

it('does not let consumer tokens deliver CMS events', function (Role $role): void {
    $token = User::factory()->create(['role' => $role])->createToken('t')->plainTextToken;

    withHeaders(['Authorization' => 'Bearer '.$token])
        ->postJson('/cms/events', [cmsEvent('publish', 'a', 1, ['title' => 'x'])])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="cms-webhook"');

    expect(EventBatch::query()->count())->toBe(0);
})->with([Role::User, Role::Admin]);

it('rejects anonymous requests on every route with problem details', function (string $method, string $uri, string $challenge): void {
    $response = $method === 'GET' ? getJson($uri) : postJson($uri, []);

    $response->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('WWW-Authenticate', $challenge);
})->with([
    ['GET', '/api/v1/contents', 'Bearer'],
    ['GET', '/api/v1/contents/any', 'Bearer'],
    ['POST', '/cms/events', 'Basic realm="cms-webhook"'],
]);

it('rate limits consumer requests per token', function (): void {
    config(['cms.api.rate_limit_per_minute' => 2]);
    actingAsRole(Role::User);

    getJson('/api/v1/contents')->assertOk();
    getJson('/api/v1/contents')->assertOk();
    getJson('/api/v1/contents')
        ->assertTooManyRequests()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('Retry-After');
});

it('rate limits webhook deliveries', function (): void {
    config(['cms.webhook.rate_limit_per_minute' => 2]);

    postBatch([cmsEvent('delete', 'a', 1)])->assertAccepted();
    postBatch([cmsEvent('delete', 'b', 1)])->assertAccepted();
    postBatch([cmsEvent('delete', 'c', 1)])
        ->assertTooManyRequests()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('Retry-After');

    expect(EventBatch::query()->count())->toBe(2);
});
