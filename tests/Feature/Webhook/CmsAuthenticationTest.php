<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\EventBatch;
use App\Models\User;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * @return list<array<string, mixed>>
 */
function validBatch(): array
{
    return [cmsEvent('publish', 'content-123', 2, ['title' => 'Example content'])];
}

it('accepts a batch with valid CMS credentials', function (): void {
    postBatch(validBatch())->assertAccepted();

    expect(EventBatch::query()->count())->toBe(1);
});

it('rejects the webhook without valid CMS credentials', function (array $headers): void {
    withHeaders($headers)->postJson('/cms/events', validBatch())
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="cms-webhook"')
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', 401);

    expect(EventBatch::query()->count())->toBe(0);
})->with([
    'no Authorization header' => fn (): array => [],
    'wrong username' => fn (): array => cmsAuthHeaders('intruder', (string) config('cms.webhook.password')),
    'wrong password' => fn (): array => cmsAuthHeaders((string) config('cms.webhook.username'), 'wrong-password'),
    'malformed Base64' => fn (): array => ['Authorization' => 'Basic !!!not-base64!!!'],
    'Basic without a colon' => fn (): array => ['Authorization' => 'Basic '.base64_encode('just-a-username')],
    'bearer token of a valid admin' => function (): array {
        $token = User::factory()->create(['role' => Role::Admin])->createToken('t')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    },
]);

it('fails closed when the server has no CMS credentials configured', function (): void {
    config(['cms.webhook.username' => '', 'cms.webhook.password' => '']);

    withHeaders(cmsAuthHeaders('', ''))->postJson('/cms/events', validBatch())
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Basic realm="cms-webhook"');

    expect(EventBatch::query()->count())->toBe(0);
});

it('does not accept unauthenticated requests even with an empty body', function (): void {
    postJson('/cms/events', [])->assertUnauthorized();
});
