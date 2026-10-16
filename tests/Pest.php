<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withToken;

use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Database reset strategy (per directory)
|--------------------------------------------------------------------------
|
| Feature tests run inside a transaction that is rolled back after each test.
| Concurrency, Performance and Database tests need committed data or real
| migration runs, so they choose DatabaseTruncation / DatabaseMigrations (or
| nothing) themselves.
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Concurrency', 'Performance', 'Database');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * HTTP Basic header for the CMS webhook (test credentials from .env.testing by default).
 *
 * @return array<string, string>
 */
function cmsAuthHeaders(?string $user = null, ?string $pass = null): array
{
    $user ??= (string) config('cms.webhook.username');
    $pass ??= (string) config('cms.webhook.password');

    return ['Authorization' => 'Basic '.base64_encode($user.':'.$pass)];
}

/**
 * Create a consumer with the given role and authenticate the next requests
 * with a real Sanctum bearer token.
 */
function actingAsRole(Role $role): User
{
    $user = User::factory()->create(['role' => $role]);
    $token = $user->createToken('test')->plainTextToken;

    withToken($token);

    return $user;
}

/**
 * POST a batch to the CMS webhook with valid CMS credentials.
 *
 * @param  list<array<string, mixed>>  $events
 * @return TestResponse<Response>
 */
function postBatch(array $events): TestResponse
{
    return withHeaders(cmsAuthHeaders())->postJson('/cms/events', $events);
}

/**
 * Run the real database queue worker until the CMS queue is empty.
 */
function drainQueue(): void
{
    Artisan::call('queue:work', [
        'connection' => 'database',
        '--queue' => (string) config('cms.queue.name'),
        '--stop-when-empty' => true,
    ]);
}

/**
 * Build a CMS event. Named cmsEvent (not event) to avoid redeclaring Laravel's
 * global event() helper. Pass (object) [] for an empty JSON object payload:
 * an empty PHP array is encoded as the JSON list [].
 *
 * @param  array<array-key, mixed>|stdClass|null  $payload
 * @return array<string, mixed>
 */
function cmsEvent(
    string $type,
    string $id,
    int $version,
    array|stdClass|null $payload = null,
    string $ts = '2026-01-01T10:00:00Z',
): array {
    $event = ['type' => $type, 'id' => $id, 'version' => $version, 'timestamp' => $ts];

    if ($payload !== null) {
        $event['payload'] = $payload;
    }

    return $event;
}
