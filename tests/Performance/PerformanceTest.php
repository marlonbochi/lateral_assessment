<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use App\Models\User;
use Database\Seeders\PerformanceSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

/*
 * SC-005 / SC-006 evidence. Excluded from the default run; executed by CI
 * (PERF_SEED_ROWS=20000) and by `make perf` (100000). The data is seeded once
 * and committed, so this file deliberately uses no database reset trait.
 */
uses()->group('performance');

beforeEach(function (): void {
    config([
        'cms.api.rate_limit_per_minute' => 1_000_000,
        'cms.webhook.rate_limit_per_minute' => 1_000_000,
    ]);

    $rows = (int) config('cms.performance.seed_rows');

    if (Content::query()->where('cms_id', 'like', 'perf-%')->count() < $rows) {
        Artisan::call('db:seed', ['--class' => PerformanceSeeder::class, '--force' => true]);
    }
});

/**
 * @param  array<string, mixed>  $values
 */
function recordPerformance(array $values): void
{
    $file = storage_path('logs/performance.json');
    $current = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];

    file_put_contents($file, (string) json_encode(
        [...$current, ...$values, 'rows' => (int) config('cms.performance.seed_rows')],
        JSON_PRETTY_PRINT,
    ));
}

/**
 * @param  Builder<Content>  $query
 * @return array<string, mixed>
 */
function explainPlan(Builder $query): array
{
    $rows = DB::select('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings());

    return (array) json_decode((string) (((array) $rows[0])['QUERY PLAN'] ?? '[]'), true);
}

/**
 * @param  list<float>  $samples
 */
function p95(array $samples): float
{
    sort($samples);

    return $samples[(int) ceil(0.95 * count($samples)) - 1];
}

it('serves every listing from its partial index without a sort step', function (Role $role, string $index): void {
    $user = User::factory()->create(['role' => $role]);
    $base = fn (): Builder => Content::query()->visibleTo($user)->feedOrder()->limit(16);

    $deep = Content::query()->visibleTo($user)->feedOrder()->offset(5000)->first();
    expect($deep)->not->toBeNull();

    $plans = [
        'first page' => explainPlan($base()),
        'deep cursor page' => explainPlan($base()->where(fn ($q) => $q
            ->where('synced_at', '<', $deep?->synced_at)
            ->orWhere(fn ($q) => $q->where('synced_at', $deep?->synced_at)->where('cms_id', '<', $deep?->cms_id)))),
    ];

    foreach ($plans as $plan) {
        $json = (string) json_encode($plan);

        expect($json)->toContain($index)->and($json)->not->toContain('"Node Type":"Sort"');
    }
})->with([
    'user feed' => [Role::User, 'contents_user_feed_idx'],
    'admin feed' => [Role::Admin, 'contents_admin_feed_idx'],
]);

it('answers listing and detail requests under 300 ms at p95', function (Role $role): void {
    actingAsRole($role);
    $ids = Content::query()->where('status', 'published')->where('is_disabled', false)->inRandomOrder()->limit(100)->pluck('cms_id')->all();

    $samples = [];
    foreach (range(1, 100) as $i) {
        $start = hrtime(true);
        getJson('/api/v1/contents?per_page=50')->assertOk();
        $samples[] = (hrtime(true) - $start) / 1e6;

        $start = hrtime(true);
        getJson('/api/v1/contents/'.$ids[$i - 1])->assertOk();
        $samples[] = (hrtime(true) - $start) / 1e6;
    }

    $p95 = round(p95($samples), 2);
    recordPerformance(["{$role->value}_p95_ms" => $p95]);

    expect($p95)->toBeLessThan(300.0);
})->with([Role::User, Role::Admin]);

it('acknowledges a 100-event batch under 300 ms and processes it within 5 s', function (): void {
    $prefix = 'perf-ingest-'.Str::lower(Str::random(8));
    $events = array_map(fn (int $i): array => cmsEvent('publish', "{$prefix}-{$i}", 1, ['title' => "Item {$i}"]), range(1, 100));

    $start = hrtime(true);
    postBatch($events)->assertAccepted();
    $ackMs = (hrtime(true) - $start) / 1e6;

    // Worker pick-up + processing. In production an idle worker polls every
    // second (--sleep=1), so add up to ~1 s of pick-up latency to this.
    $start = hrtime(true);
    drainQueue();
    $processMs = (hrtime(true) - $start) / 1e6;

    recordPerformance([
        'ack_100_events_ms' => round($ackMs, 2),
        'process_100_events_ms' => round($processMs, 2),
        'worker_poll_interval_ms' => 1000,
    ]);

    expect($ackMs)->toBeLessThan(300.0)
        ->and($processMs)->toBeLessThan(5000.0)
        ->and(Content::query()->where('cms_id', 'like', "{$prefix}-%")->count())->toBe(100);
});
