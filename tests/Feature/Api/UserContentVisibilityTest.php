<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;

use Symfony\Component\HttpFoundation\Response;

beforeEach(function (): void {
    Content::factory()->published()->create(['cms_id' => 'visible']);
    Content::factory()->unpublished()->create(['cms_id' => 'unpublished']);
    Content::factory()->deleted()->create(['cms_id' => 'deleted']);
    Content::factory()->published()->disabled()->create(['cms_id' => 'disabled']);
});

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function bodyWithoutInstance(TestResponse $response): array
{
    $body = (array) $response->json();
    unset($body['instance']);

    return $body;
}

it('lists only published content that is not locally disabled', function (): void {
    actingAsRole(Role::User);

    getJson('/api/v1/contents')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', 'visible');
});

it('retrieves a visible content item by its CMS identifier', function (): void {
    actingAsRole(Role::User);

    getJson('/api/v1/contents/visible')
        ->assertOk()
        ->assertJsonPath('data.id', 'visible');
});

it('answers the same 404 for unpublished, deleted, disabled and unknown content', function (): void {
    actingAsRole(Role::User);

    $responses = collect(['unpublished', 'deleted', 'disabled', 'never-existed'])
        ->map(fn (string $id) => getJson("/api/v1/contents/{$id}")
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json'));

    $bodies = $responses->map(fn (TestResponse $r): array => bodyWithoutInstance($r))->unique(fn (array $b): string => (string) json_encode($b));

    expect($bodies)->toHaveCount(1)
        ->and($bodies->first())->toMatchArray(['status' => 404, 'title' => 'Not Found', 'detail' => 'Content not found.']);
});

it('stops showing content once a newer unpublish or delete is processed', function (string $type): void {
    Content::factory()->published()->version(1)->create(['cms_id' => 'content-1']);
    actingAsRole(Role::User);
    getJson('/api/v1/contents/content-1')->assertOk();

    postBatch([cmsEvent($type, 'content-1', 2, $type === 'delete' ? null : ['title' => 'v2'])])->assertAccepted();
    drainQueue();

    getJson('/api/v1/contents/content-1')->assertNotFound();
    expect(collect((array) getJson('/api/v1/contents')->json('data'))->pluck('id'))->not->toContain('content-1');
})->with(['unpublish', 'delete']);
