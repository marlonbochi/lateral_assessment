<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bulk content for performance checks (SC-005/SC-006): `cms.performance.seed_rows`
 * rows (PERF_SEED_ROWS, default 100000) with a deterministic, realistic mix:
 * 80% published, 15% unpublished, 5% deleted, 2% locally disabled.
 */
final class PerformanceSeeder extends Seeder
{
    private const int CHUNK = 1000;

    public function run(): void
    {
        $total = (int) config('cms.performance.seed_rows');
        $now = now();
        $payload = json_encode([
            'title' => 'Performance content',
            'description' => str_repeat('Lorem ipsum dolor sit amet. ', 8),
            'tags' => ['perf', 'seed'],
        ], JSON_THROW_ON_ERROR);

        for ($start = 1; $start <= $total; $start += self::CHUNK) {
            $rows = [];

            foreach (range($start, min($start + self::CHUNK - 1, $total)) as $i) {
                $status = match (true) {
                    $i % 20 === 0 => 'deleted',
                    $i % 20 <= 3 => 'unpublished',
                    default => 'published',
                };
                $disabled = $status !== 'deleted' && $i % 50 === 1;

                $rows[] = [
                    'cms_id' => "perf-{$i}",
                    'version' => 1 + ($i % 7),
                    'status' => $status,
                    'payload' => $status === 'deleted' ? null : $payload,
                    'cms_timestamp' => $now->copy()->subSeconds($i * 3),
                    'synced_at' => $now->copy()->subSeconds($i),
                    'is_disabled' => $disabled,
                    'disabled_at' => $disabled ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('contents')->insert($rows);
        }

        DB::statement('ANALYZE contents');
    }
}
