<?php

declare(strict_types=1);

use App\Models\EventBatch;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

it('prunes processed batches past the retention period and keeps the rest', function (): void {
    config(['cms.batches.retention_days' => 30]);
    $expired = EventBatch::factory()->processed()->create(['processed_at' => now()->subDays(31)]);
    $recent = EventBatch::factory()->processed()->create(['processed_at' => now()->subDays(29)]);
    $oldFailed = EventBatch::factory()->failed()->create(['failed_at' => now()->subDays(90), 'received_at' => now()->subDays(90)]);
    $oldPending = EventBatch::factory()->pending()->create(['received_at' => now()->subDays(90)]);

    Artisan::call('model:prune', ['--model' => [EventBatch::class]]);

    expect(EventBatch::query()->pluck('id')->all())
        ->toEqualCanonicalizing([$recent->id, $oldFailed->id, $oldPending->id])
        ->and(EventBatch::query()->whereKey($expired->id)->exists())->toBeFalse();
});

it('schedules inbox pruning and expired token cleanup daily', function (): void {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn (Event $event): string => (string) $event->command)
        ->implode("\n");

    expect($commands)->toContain('model:prune')
        ->toContain('EventBatch')
        ->toContain('sanctum:prune-expired');
});
