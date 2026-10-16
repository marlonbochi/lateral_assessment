<?php

declare(strict_types=1);

use App\Models\Content;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

function throwingRoute(string $uri, Closure $throw): void
{
    Route::get($uri, $throw);
}

it('renders errors on api routes as RFC 9457 problem details', function (Closure $throw, int $status, string $title): void {
    throwingRoute('api/__test/problem', $throw);

    getJson('/api/__test/problem')
        ->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson([
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'instance' => '/api/__test/problem',
        ])
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'instance']);
})->with([
    'unauthenticated' => [fn () => throw new AuthenticationException, 401, 'Unauthorized'],
    'forbidden' => [fn () => throw new AuthorizationException('This action requires the admin role.'), 403, 'Forbidden'],
    'model not found' => [fn () => throw (new ModelNotFoundException)->setModel(Content::class), 404, 'Not Found'],
    'throttled' => [fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 30]), 429, 'Too Many Requests'],
    'unexpected' => [fn () => throw new RuntimeException('secret internal detail'), 500, 'Internal Server Error'],
]);

it('uses resource-specific not-found details', function (): void {
    throwingRoute('api/__test/missing-content', fn () => throw (new ModelNotFoundException)->setModel(Content::class));

    getJson('/api/__test/missing-content')->assertNotFound()->assertJsonPath('detail', 'Content not found.');
});

it('returns 404 problem details for unknown api routes', function (): void {
    getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('adds field errors to validation problems', function (): void {
    throwingRoute('api/__test/invalid', fn () => throw ValidationException::withMessages(['events.2.version' => 'The version must be a positive integer.']));

    getJson('/api/__test/invalid')
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 422)
        ->assertJson(['errors' => ['events.2.version' => ['The version must be a positive integer.']]]);
});

it('keeps the challenge header of HTTP exceptions', function (): void {
    throwingRoute('cms/__test/basic', fn () => throw new UnauthorizedHttpException('Basic realm="cms-webhook"', 'Authentication is required.'));

    getJson('/cms/__test/basic')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="cms-webhook"')
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('detail', 'Authentication is required.');
});

it('keeps the Retry-After header on 429', function (): void {
    throwingRoute('api/__test/throttle', fn () => throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => 30]));

    getJson('/api/__test/throttle')->assertTooManyRequests()->assertHeader('Retry-After', '30');
});

it('maps database failures on the webhook to 503 so the CMS retries', function (): void {
    Route::post('cms/__test/db-down', fn () => throw new QueryException('pgsql', 'insert into event_batches', [], new PDOException('connection refused')));

    postJson('/cms/__test/db-down')
        ->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('title', 'Service Unavailable');
});

it('never leaks internals of unexpected errors when debug is off', function (): void {
    config(['app.debug' => false]);
    throwingRoute('api/__test/boom', fn () => throw new RuntimeException('secret internal detail'));

    $response = getJson('/api/__test/boom')->assertStatus(500);

    expect($response->getContent())->not->toContain('secret internal detail')
        ->and($response->json())->not->toHaveKey('trace');
});
