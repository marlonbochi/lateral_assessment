<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $overrides
 */
function insertContent(array $overrides = []): void
{
    DB::table('contents')->insert(array_merge([
        'cms_id' => 'content-'.Str::random(8),
        'version' => 1,
        'status' => 'published',
        'payload' => '{"title":"x"}',
        'cms_timestamp' => now(),
        'synced_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function insertBatch(array $overrides = []): void
{
    DB::table('event_batches')->insert(array_merge([
        'id' => (string) Str::uuid7(),
        'status' => 'pending',
        'events' => '[{"id":"a"}]',
        'event_count' => 1,
        'received_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

it('accepts valid rows', function (): void {
    insertContent();
    insertContent(['status' => 'deleted', 'payload' => null]);
    insertBatch();

    expect(DB::table('contents')->count())->toBe(2)
        ->and(DB::table('event_batches')->count())->toBe(1);
});

it('rejects rows that violate database invariants', function (string $table, array $overrides, string $constraint): void {
    $insert = fn () => match ($table) {
        'contents' => insertContent($overrides),
        'event_batches' => insertBatch($overrides),
        'users' => DB::table('users')->insert(array_merge([
            'name' => 'x', 'email' => 'x@example.test', 'role' => 'user',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides)),
        default => throw new InvalidArgumentException("Unknown table [{$table}]."),
    };

    expect($insert)->toThrow(QueryException::class, $constraint);
})->with([
    'content version 0' => ['contents', ['version' => 0], 'contents_version_positive'],
    'deleted content with payload' => ['contents', ['status' => 'deleted'], 'contents_payload_matches_status'],
    'published content without payload' => ['contents', ['payload' => null], 'contents_payload_matches_status'],
    'payload that is a JSON array' => ['contents', ['payload' => '[1,2]'], 'contents_payload_is_object'],
    'unknown content status' => ['contents', ['status' => 'archived'], 'contents_status_valid'],
    'batch with 101 events' => ['event_batches', ['event_count' => 101], 'event_batches_event_count_range'],
    'batch events that are a JSON object' => ['event_batches', ['events' => '{"a":1}'], 'event_batches_events_is_array'],
    'unknown batch status' => ['event_batches', ['status' => 'archived'], 'event_batches_status_valid'],
    'user role root' => ['users', ['role' => 'root'], 'users_role_valid'],
]);

it('rejects a duplicate cms_id', function (): void {
    insertContent(['cms_id' => 'dup']);
    insertContent(['cms_id' => 'dup']);
})->throws(QueryException::class, 'contents_cms_id_unique');

it('creates the partial indexes that serve the consumer listings', function (): void {
    $indexes = collect(DB::select("select indexname, indexdef from pg_indexes where tablename in ('contents', 'event_batches')"))
        ->pluck('indexdef', 'indexname');

    expect($indexes)->toHaveKeys([
        'contents_cms_id_unique',
        'contents_user_feed_idx',
        'contents_admin_feed_idx',
        'event_batches_status_received_idx',
    ])
        ->and($indexes['contents_user_feed_idx'])->toContain("WHERE (((status)::text = 'published'::text) AND (is_disabled = false))")
        ->and($indexes['contents_admin_feed_idx'])->toContain("WHERE ((status)::text <> 'deleted'::text)");
});
