<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\PersonalAccessToken;

it('creates a consumer with the given role', function (): void {
    expect(Artisan::call('consumer:create', ['name' => 'Editor', 'email' => 'admin@example.test', '--role' => 'admin']))->toBe(0);

    $user = User::query()->where('email', 'admin@example.test')->sole();

    expect($user->name)->toBe('Editor')->and($user->role)->toBe(Role::Admin);
});

it('defaults new consumers to the user role', function (): void {
    expect(Artisan::call('consumer:create', ['name' => 'Reader', 'email' => 'reader@example.test']))->toBe(0);

    expect(User::query()->where('email', 'reader@example.test')->sole()->role)->toBe(Role::User);
});

it('rejects a duplicate email and an unknown role', function (array $arguments): void {
    User::factory()->create(['email' => 'taken@example.test']);

    expect(Artisan::call('consumer:create', $arguments))->toBe(1);

    expect(User::query()->count())->toBe(1);
})->with([
    'duplicate email' => [['name' => 'Dup', 'email' => 'taken@example.test']],
    'unknown role' => [['name' => 'Root', 'email' => 'root@example.test', '--role' => 'root']],
    'invalid email' => [['name' => 'Bad', 'email' => 'not-an-email']],
]);

it('issues a prefixed token once and stores only its hash', function (): void {
    $user = User::factory()->create(['email' => 'reader@example.test']);

    Artisan::call('consumer:token', ['email' => 'reader@example.test', '--name' => 'ci', '--expires-days' => 7]);
    $output = Artisan::output();

    // Sanctum plain-text tokens look like "{id}|cms_{secret}"; only the
    // SHA-256 of the part after "|" is stored.
    preg_match('/(\d+)\|(cms_\S+)/', $output, $matches);
    $secret = $matches[2] ?? '';
    $stored = PersonalAccessToken::query()->sole();

    expect($secret)->toStartWith('cms_')
        ->and(substr_count($output, $secret))->toBe(1)
        ->and((int) ($matches[1] ?? 0))->toBe($stored->id)
        ->and($stored->tokenable_id)->toBe($user->id)
        ->and($stored->name)->toBe('ci')
        ->and($stored->token)->not->toContain($secret)
        ->and($stored->token)->toBe(hash('sha256', $secret))
        ->and($stored->expires_at?->isBetween(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()))->toBeTrue();
});

it('fails to issue a token for an unknown consumer', function (): void {
    expect(Artisan::call('consumer:token', ['email' => 'nobody@example.test']))->toBe(1);

    expect(PersonalAccessToken::query()->count())->toBe(0);
});

it('revokes one token or all tokens of a consumer', function (): void {
    $user = User::factory()->create(['email' => 'reader@example.test']);
    $first = $user->createToken('one')->accessToken;
    $user->createToken('two');
    $user->createToken('three');

    expect(Artisan::call('consumer:revoke', ['email' => 'reader@example.test', '--token-id' => $first->id]))->toBe(0);
    expect($user->tokens()->count())->toBe(2);

    expect(Artisan::call('consumer:revoke', ['email' => 'reader@example.test', '--all' => true]))->toBe(0);
    expect($user->tokens()->count())->toBe(0);
});

it('requires choosing which tokens to revoke', function (): void {
    User::factory()->create(['email' => 'reader@example.test'])->createToken('one');

    expect(Artisan::call('consumer:revoke', ['email' => 'reader@example.test']))->toBe(1);

    expect(PersonalAccessToken::query()->count())->toBe(1);
});
