<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;

use function Pest\Laravel\getJson;

beforeEach(function (): void {
    Content::factory()->published()->create(['cms_id' => 'published', 'synced_at' => now()->subMinutes(1)]);
    Content::factory()->unpublished()->create(['cms_id' => 'unpublished', 'synced_at' => now()->subMinutes(2)]);
    Content::factory()->published()->disabled()->create(['cms_id' => 'disabled', 'synced_at' => now()->subMinutes(3)]);
    Content::factory()->unpublished()->disabled()->create(['cms_id' => 'unpublished-disabled', 'synced_at' => now()->subMinutes(4)]);
    Content::factory()->deleted()->create(['cms_id' => 'deleted', 'synced_at' => now()->subMinutes(5)]);
});

/**
 * @return list<string>
 */
function listedIds(string $query = ''): array
{
    return array_values(array_map(
        fn (array $item): string => (string) $item['id'],
        (array) getJson('/api/v1/contents'.$query)->assertOk()->json('data'),
    ));
}

it('shows administrators published, unpublished and disabled content, never deleted', function (): void {
    actingAsRole(Role::Admin);

    expect(listedIds())->toBe(['published', 'unpublished', 'disabled', 'unpublished-disabled']);
});

it('lets administrators filter by publication status and by the disabled flag', function (string $query, array $expected): void {
    actingAsRole(Role::Admin);

    expect(listedIds($query))->toBe($expected);
})->with([
    'unpublished only' => ['?status=unpublished', ['unpublished', 'unpublished-disabled']],
    'published only' => ['?status=published', ['published', 'disabled']],
    'disabled only' => ['?disabled=true', ['disabled', 'unpublished-disabled']],
    'enabled only' => ['?disabled=false', ['published', 'unpublished']],
    'combined' => ['?status=published&disabled=true', ['disabled']],
    'combined with page numbers' => ['?status=unpublished&disabled=false&page=1', ['unpublished']],
]);

it('rejects invalid filter values', function (string $query, string $field): void {
    actingAsRole(Role::Admin);

    getJson('/api/v1/contents'.$query)->assertUnprocessable()->assertJsonStructure(['errors' => [$field]]);
})->with([
    'deleted is not a filterable status' => ['?status=deleted', 'status'],
    'unknown status' => ['?status=archived', 'status'],
    'non-boolean disabled' => ['?disabled=maybe', 'disabled'],
]);

it('forbids regular users from using administrator filters', function (string $query): void {
    actingAsRole(Role::User);

    getJson('/api/v1/contents'.$query)
        ->assertForbidden()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('detail', 'This action requires the admin role.');
})->with(['?status=unpublished', '?status=published', '?disabled=true', '?disabled=false']);

it('adds the publication status and the local override to the administrator view', function (): void {
    $admin = actingAsRole(Role::Admin);
    Content::query()->where('cms_id', 'disabled')->update(['disabled_by' => $admin->id]);

    $item = getJson('/api/v1/contents/disabled')->assertOk()->json('data');

    expect(array_keys((array) $item))->toBe(['id', 'version', 'content', 'cms_updated_at', 'synced_at', 'status', 'local_override'])
        ->and($item['status'])->toBe('published')
        ->and($item['local_override']['disabled'])->toBeTrue()
        ->and($item['local_override']['changed_by'])->toBe($admin->id)
        ->and($item['local_override']['changed_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T.*Z$/');
});

it('lets administrators retrieve unpublished content but not deleted content', function (): void {
    actingAsRole(Role::Admin);

    getJson('/api/v1/contents/unpublished')->assertOk()->assertJsonPath('data.status', 'unpublished');
    getJson('/api/v1/contents/deleted')->assertNotFound()->assertJsonPath('detail', 'Content not found.');
});
