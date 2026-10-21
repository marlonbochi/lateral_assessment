<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ContentStatus;
use App\Enums\Role;
use App\Models\Content;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo data for local exploration: one administrator, one regular user and
 * 32 content items in every state. Idempotent. Issues no tokens (use
 * `php artisan consumer:token <email>`), so it prints nothing secret.
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        User::unguarded(function (): void {
            User::query()->updateOrCreate(['email' => 'admin@example.test'], ['name' => 'Demo Admin', 'role' => Role::Admin]);
            User::query()->updateOrCreate(['email' => 'reader@example.test'], ['name' => 'Demo Reader', 'role' => Role::User]);
        });

        Content::unguarded(function (): void {
            foreach (range(1, 32) as $i) {
                $status = match (true) {
                    $i % 8 === 0 => ContentStatus::Deleted,
                    $i % 4 === 0 => ContentStatus::Unpublished,
                    default => ContentStatus::Published,
                };

                Content::query()->updateOrCreate(['cms_id' => sprintf('demo-%02d', $i)], [
                    'version' => $i,
                    'status' => $status,
                    'payload' => $status === ContentStatus::Deleted ? null : (object) [
                        'title' => "Demo content {$i}",
                        'description' => 'Seeded for local exploration.',
                        'tags' => $i % 2 === 0 ? ['demo', 'even'] : ['demo'],
                    ],
                    'cms_timestamp' => now()->subHours(33 - $i),
                    'synced_at' => now()->subMinutes(33 - $i),
                    'is_disabled' => $status !== ContentStatus::Deleted && $i % 7 === 0,
                    'disabled_at' => $status !== ContentStatus::Deleted && $i % 7 === 0 ? now() : null,
                ]);
            }
        });
    }
}
