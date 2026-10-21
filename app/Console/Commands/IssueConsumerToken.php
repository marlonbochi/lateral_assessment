<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

final class IssueConsumerToken extends Command
{
    protected $signature = 'consumer:token
        {email : E-mail of an existing consumer}
        {--name=default : Label stored with the token}
        {--expires-days= : Expire the token after this many days (defaults to the Sanctum expiration)}';

    protected $description = 'Issue a bearer token for a consumer (the token is shown only once)';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No consumer exists with that e-mail.');

            return self::FAILURE;
        }

        $days = $this->option('expires-days');
        $expiresAt = is_numeric($days) && (int) $days > 0 ? now()->addDays((int) $days) : null;

        $name = $this->option('name');
        $token = $user->createToken(is_string($name) && $name !== '' ? $name : 'default', ['*'], $expiresAt);

        $this->components->warn('Store this token now: it cannot be shown again.');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
