<?php

declare(strict_types=1);

use App\Enums\Role;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

/*
 * The brief requires every endpoint to be protected, including the health
 * check: it answers only to authenticated consumers.
 */
it('reports the application as up to an authenticated consumer', function (Role $role): void {
    actingAsRole($role);

    getJson('/up')->assertOk()->assertExactJson(['status' => 'up']);
})->with([Role::User, Role::Admin]);

it('refuses anonymous health checks with problem details', function (array $server): void {
    call('GET', '/up', [], [], [], $server)
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertHeader('WWW-Authenticate', 'Bearer');
})->with([
    'JSON client' => [['HTTP_ACCEPT' => 'application/json']],
    'no Accept header' => [[]],
    'browser' => [['HTTP_ACCEPT' => 'text/html']],
]);

it('does not accept CMS credentials on the health check', function (): void {
    withHeaders(cmsAuthHeaders())->getJson('/up')->assertUnauthorized();
});
