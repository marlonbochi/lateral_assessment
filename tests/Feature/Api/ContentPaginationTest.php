<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/**
 * 40 visible items with distinct sync times (content-01 newest), plus hidden ones.
 */
function seedFeed(): void
{
    foreach (range(1, 40) as $i) {
        Content::factory()->published()->create([
            'cms_id' => sprintf('content-%02d', $i),
            'synced_at' => now()->subMinutes($i),
        ]);
    }
    Content::factory()->unpublished()->count(3)->create();
    Content::factory()->deleted()->count(2)->create();
}

/**
 * @return list<string>
 */
function idsOf(mixed $data): array
{
    return array_values(array_map(fn (array $item): string => (string) $item['id'], (array) $data));
}

beforeEach(fn () => actingAsRole(Role::User));

it('paginates with cursors by default: 15/15/10, newest first, no gaps or duplicates', function (): void {
    seedFeed();

    $first = getJson('/api/v1/contents?per_page=15')->assertOk()
        ->assertJsonPath('meta.pagination', 'cursor')
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.prev_cursor', null);
    $second = getJson('/api/v1/contents?per_page=15&cursor='.$first->json('meta.next_cursor'))->assertOk();
    $third = getJson('/api/v1/contents?per_page=15&cursor='.$second->json('meta.next_cursor'))->assertOk()
        ->assertJsonPath('meta.next_cursor', null);

    $all = [...idsOf($first->json('data')), ...idsOf($second->json('data')), ...idsOf($third->json('data'))];

    expect(count(idsOf($first->json('data'))))->toBe(15)
        ->and(count(idsOf($second->json('data'))))->toBe(15)
        ->and(count(idsOf($third->json('data'))))->toBe(10)
        ->and($all)->toBe(array_map(fn (int $i): string => sprintf('content-%02d', $i), range(1, 40)))
        ->and($second->json('links.next'))->toContain('cursor=');
});

it('uses the CMS identifier as a deterministic tie-breaker', function (): void {
    $sameInstant = now();
    foreach (['b', 'a', 'c'] as $id) {
        Content::factory()->published()->create(['cms_id' => $id, 'synced_at' => $sameInstant]);
    }

    expect(idsOf(getJson('/api/v1/contents')->json('data')))->toBe(['c', 'b', 'a']);
});

it('defaults to 15 items per page', function (): void {
    seedFeed();

    getJson('/api/v1/contents')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('meta.per_page', 15);
});

it('neither skips nor repeats items when content changes while paging', function (): void {
    seedFeed();

    $first = getJson('/api/v1/contents?per_page=15');
    // content-20 (on page 2) is synchronized again and moves to the top.
    DB::table('contents')->where('cms_id', 'content-20')->update(['synced_at' => now()->addMinute()]);
    $second = getJson('/api/v1/contents?per_page=15&cursor='.$first->json('meta.next_cursor'));
    $third = getJson('/api/v1/contents?per_page=15&cursor='.$second->json('meta.next_cursor'));

    $rest = [...idsOf($second->json('data')), ...idsOf($third->json('data'))];
    $expected = array_values(array_diff(array_map(fn (int $i): string => sprintf('content-%02d', $i), range(16, 40)), ['content-20']));

    expect($rest)->toBe($expected);
});

it('switches to page-number pagination with totals when a page is requested', function (): void {
    seedFeed();

    $response = getJson('/api/v1/contents?page=2&per_page=15')->assertOk()
        ->assertJsonPath('meta.pagination', 'page')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.per_page', 15)
        ->assertJsonPath('meta.total', 40)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonStructure(['links' => ['first', 'last', 'prev', 'next']]);

    expect(idsOf($response->json('data')))->toBe(array_map(fn (int $i): string => sprintf('content-%02d', $i), range(16, 30)));
});

it('returns an empty page with valid metadata past the end', function (): void {
    seedFeed();

    getJson('/api/v1/contents?page=10&per_page=15')->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.total', 40)
        ->assertJsonPath('meta.current_page', 10);
});

it('rejects invalid pagination parameters', function (string $query, string $field): void {
    getJson('/api/v1/contents?'.$query)
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'per_page 0' => ['per_page=0', 'per_page'],
    'per_page 101' => ['per_page=101', 'per_page'],
    'per_page not a number' => ['per_page=ten', 'per_page'],
    'page 0' => ['page=0', 'page'],
    'cursor and page together' => ['page=1&cursor=abc', 'cursor'],
    'garbage cursor' => ['cursor=not-a-cursor', 'cursor'],
    'cursor with unexpected parameters' => [fn () => 'cursor='.rtrim(strtr(base64_encode('{"foo":"bar","_pointsToNextItems":true}'), '+/', '-_'), '='), 'cursor'],
    'cursor with an invalid date' => [fn () => 'cursor='.rtrim(strtr(base64_encode('{"synced_at":"not-a-date","cms_id":"x","_pointsToNextItems":true}'), '+/', '-_'), '='), 'cursor'],
]);
