<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use App\Models\EventBatch;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

use Symfony\Component\HttpFoundation\Response;
use Tests\Support\OpenApiAsserter;

/**
 * @param  TestResponse<Response>  $response
 */
function assertConforms(string $method, string $template, TestResponse $response): void
{
    OpenApiAsserter::assertResponseMatches(
        $method,
        $template,
        $response->getStatusCode(),
        (string) $response->headers->get('Content-Type'),
        (string) $response->getContent(),
    );
}

beforeEach(function (): void {
    Content::factory()->published()->create(['cms_id' => 'content-1', 'payload' => (object) ['title' => 'T', 'tags' => (object) []]]);
    Content::factory()->unpublished()->disabled()->create(['cms_id' => 'content-2']);
});

it('documents the webhook responses', function (): void {
    assertConforms('POST', '/cms/events', postBatch([cmsEvent('publish', 'c-9', 1, ['title' => 'x'])])->assertAccepted());
    assertConforms('POST', '/cms/events', postBatch([cmsEvent('publish', 'c-9', 0, ['title' => 'x'])])->assertUnprocessable());
    assertConforms('POST', '/cms/events', postJson('/cms/events', [])->assertUnauthorized());
});

it('documents the content listing in both pagination modes and for both roles', function (Role $role): void {
    actingAsRole($role);

    assertConforms('GET', '/api/v1/contents', getJson('/api/v1/contents')->assertOk());
    assertConforms('GET', '/api/v1/contents', getJson('/api/v1/contents?page=1&per_page=1')->assertOk());
    assertConforms('GET', '/api/v1/contents', getJson('/api/v1/contents?per_page=0')->assertUnprocessable());
})->with([Role::User, Role::Admin]);

it('documents content retrieval and its 404', function (Role $role): void {
    actingAsRole($role);

    assertConforms('GET', '/api/v1/contents/{contentId}', getJson('/api/v1/contents/content-1')->assertOk());
    assertConforms('GET', '/api/v1/contents/{contentId}', getJson('/api/v1/contents/missing')->assertNotFound());
})->with([Role::User, Role::Admin]);

it('documents the local override endpoints', function (): void {
    actingAsRole(Role::User);
    assertConforms('PUT', '/api/v1/contents/{contentId}/disabled', putJson('/api/v1/contents/content-1/disabled')->assertForbidden());

    actingAsRole(Role::Admin);
    assertConforms('PUT', '/api/v1/contents/{contentId}/disabled', putJson('/api/v1/contents/content-1/disabled')->assertOk());
    assertConforms('DELETE', '/api/v1/contents/{contentId}/disabled', deleteJson('/api/v1/contents/content-1/disabled')->assertOk());
    assertConforms('DELETE', '/api/v1/contents/{contentId}/disabled', deleteJson('/api/v1/contents/missing/disabled')->assertNotFound());
});

it('documents the event batch endpoints', function (): void {
    $failed = EventBatch::factory()->failed()->create();
    $processed = EventBatch::factory()->processed()->create();
    actingAsRole(Role::Admin);

    assertConforms('GET', '/api/v1/admin/event-batches', getJson('/api/v1/admin/event-batches')->assertOk());
    assertConforms('GET', '/api/v1/admin/event-batches/{batchId}', getJson("/api/v1/admin/event-batches/{$failed->id}")->assertOk());
    assertConforms('POST', '/api/v1/admin/event-batches/{batchId}/retry', postJson("/api/v1/admin/event-batches/{$failed->id}/retry")->assertAccepted());
    assertConforms('POST', '/api/v1/admin/event-batches/{batchId}/retry', postJson("/api/v1/admin/event-batches/{$processed->id}/retry")->assertConflict());
});
