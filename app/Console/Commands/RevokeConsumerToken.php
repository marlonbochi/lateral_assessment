<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

final class RevokeConsumerToken extends Command
{
    protected $signature = 'consumer:revoke
        {email : E-mail of an existing consumer}
        {--token-id= : Revoke only this token}
        {--all : Revoke every token of the consumer}';

    protected $description = 'Revoke one or all bearer tokens of a consumer';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->components->error('No consumer exists with that e-mail.');

            return self::FAILURE;
        }

        $tokenId = $this->option('token-id');

        if (! $this->option('all') && ! is_numeric($tokenId)) {
            $this->components->error('Pass --token-id=<id> or --all.');

            return self::FAILURE;
        }

        $query = $user->tokens();

        if (! $this->option('all')) {
            $query->whereKey((int) $tokenId);
        }

        $revoked = $query->delete();

        $this->components->info(sprintf('%d token(s) revoked.', $revoked));

        return self::SUCCESS;
    }
}
