<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\withHeaders;

use Symfony\Component\HttpFoundation\Response;

/**
 * @return TestResponse<Response>
 */
function getWithToken(string $token): TestResponse
{
    return withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/v1/contents');
}

it('accepts a valid token', function (): void {
    $token = User::factory()->create()->createToken('t')->plainTextToken;

    getWithToken($token)->assertOk();
});

it('rejects tokens that are no longer or never were valid', function (string $token): void {
    getWithToken($token)->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer');
})->with([
    'revoked token' => function (): string {
        $user = User::factory()->create();
        $plain = $user->createToken('t')->plainTextToken;
        $user->tokens()->delete();

        return $plain;
    },
    'token past its own expiry' => fn (): string => User::factory()->create()
        ->createToken('t', ['*'], now()->subMinute())->plainTextToken,
    'token older than the global expiration' => function (): string {
        $token = User::factory()->create()->createToken('t');
        PersonalAccessToken::query()->whereKey($token->accessToken->getKey())
            ->update(['created_at' => now()->subMinutes((int) config('sanctum.expiration') + 1)]);

        return $token->plainTextToken;
    },
    'token of a deleted consumer' => function (): string {
        $user = User::factory()->create();
        $plain = $user->createToken('t')->plainTextToken;
        $user->delete();

        return $plain;
    },
    'malformed token' => fn (): string => 'not-a-real-token',
    'unknown token id' => fn (): string => '999999|cms_'.str_repeat('a', 40),
    'right id, wrong secret' => function (): string {
        $token = User::factory()->create()->createToken('t');

        return $token->accessToken->getKey().'|cms_'.str_repeat('b', 40);
    },
]);
