<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

/**
 * @return array<string, mixed>
 */
function cmsColumns(string $cmsId): array
{
    return (array) DB::table('contents')
        ->where('cms_id', $cmsId)
        ->first(['cms_id', 'version', 'status', 'payload', 'cms_timestamp', 'synced_at']);
}

function userSees(string $cmsId): bool
{
    actingAsRole(Role::User);

    return getJson("/api/v1/contents/{$cmsId}")->status() === 200;
}

it('disables content locally: hidden from users, visible to admins, CMS data untouched', function (): void {
    Content::factory()->published()->create(['cms_id' => 'content-1']);
    $before = cmsColumns('content-1');
    $admin = actingAsRole(Role::Admin);

    putJson('/api/v1/contents/content-1/disabled')
        ->assertOk()
        ->assertJsonPath('data.id', 'content-1')
        ->assertJsonPath('data.local_override.disabled', true)
        ->assertJsonPath('data.local_override.changed_by', $admin->id);

    getJson('/api/v1/contents/content-1')->assertOk();
    expect(cmsColumns('content-1'))->toEqual($before)
        ->and(userSees('content-1'))->toBeFalse();
});

it('is idempotent when disabling twice', function (): void {
    Content::factory()->published()->create(['cms_id' => 'content-1']);
    actingAsRole(Role::Admin);

    $first = putJson('/api/v1/contents/content-1/disabled')->assertOk()->json('data.local_override.changed_at');
    travel(5)->minutes();
    $second = putJson('/api/v1/contents/content-1/disabled')->assertOk()->json('data.local_override.changed_at');

    expect($second)->toBe($first);
});

it('keeps the override through newer publish and unpublish events', function (): void {
    Content::factory()->published()->disabled()->version(1)->create(['cms_id' => 'content-1']);

    postBatch([cmsEvent('unpublish', 'content-1', 2, ['title' => 'v2'])])->assertAccepted();
    postBatch([cmsEvent('publish', 'content-1', 3, ['title' => 'v3'])])->assertAccepted();
    drainQueue();

    $row = DB::table('contents')->where('cms_id', 'content-1')->first();

    expect((int) $row?->version)->toBe(3)
        ->and((string) $row?->status)->toBe('published')
        ->and((bool) $row?->is_disabled)->toBeTrue()
        ->and(userSees('content-1'))->toBeFalse();
});

it('keeps the override through deletion and re-creation', function (): void {
    Content::factory()->published()->disabled()->version(1)->create(['cms_id' => 'content-1']);

    postBatch([cmsEvent('delete', 'content-1', 2)])->assertAccepted();
    postBatch([cmsEvent('publish', 'content-1', 3, ['title' => 'back'])])->assertAccepted();
    drainQueue();

    expect((bool) DB::table('contents')->where('cms_id', 'content-1')->value('is_disabled'))->toBeTrue();
});

it('re-enables content, which users see again only if it is published', function (string $state, bool $visible): void {
    Content::factory()->{$state}()->disabled()->create(['cms_id' => 'content-1']);
    actingAsRole(Role::Admin);

    deleteJson('/api/v1/contents/content-1/disabled')
        ->assertOk()
        ->assertJsonPath('data.local_override.disabled', false);
    deleteJson('/api/v1/contents/content-1/disabled')->assertOk();

    expect(userSees('content-1'))->toBe($visible);
})->with([
    'published' => ['published', true],
    'unpublished' => ['unpublished', false],
]);

it('answers 404 to administrators for deleted or unknown content', function (string $method, string $cmsId): void {
    Content::factory()->deleted()->create(['cms_id' => 'deleted']);
    actingAsRole(Role::Admin);

    ($method === 'PUT' ? putJson("/api/v1/contents/{$cmsId}/disabled") : deleteJson("/api/v1/contents/{$cmsId}/disabled"))
        ->assertNotFound()
        ->assertJsonPath('detail', 'Content not found.');
})->with([
    ['PUT', 'deleted'], ['DELETE', 'deleted'], ['PUT', 'unknown'], ['DELETE', 'unknown'],
]);

it('forbids regular users identically whether or not the content exists', function (string $method): void {
    Content::factory()->published()->create(['cms_id' => 'exists']);
    actingAsRole(Role::User);

    $call = fn (string $id): TestResponse => $method === 'PUT'
        ? putJson("/api/v1/contents/{$id}/disabled")
        : deleteJson("/api/v1/contents/{$id}/disabled");

    $existing = $call('exists')->assertForbidden();
    $unknown = $call('does-not-exist')->assertForbidden();

    $strip = function (TestResponse $r): array {
        $body = (array) $r->json();
        unset($body['instance']);

        return $body;
    };

    expect($strip($existing))->toBe($strip($unknown))
        ->and($existing->json('detail'))->toBe('This action requires the admin role.')
        ->and((bool) DB::table('contents')->where('cms_id', 'exists')->value('is_disabled'))->toBeFalse();
})->with(['PUT', 'DELETE']);

it('rejects CMS credentials on the override endpoints', function (): void {
    Content::factory()->published()->create(['cms_id' => 'content-1']);

    withHeaders(cmsAuthHeaders())->putJson('/api/v1/contents/content-1/disabled')->assertUnauthorized();
});
