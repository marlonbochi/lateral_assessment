<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use App\Models\User;

use function Pest\Laravel\call;

/*
 * Every endpoint × every actor → expected status (SC-004). Later phases append
 * rows for their endpoints; T098 checks that all 8 endpoints are covered.
 */

/**
 * @return array<string, string>
 */
function actorServerHeaders(string $actor): array
{
    return match ($actor) {
        'anonymous' => [],
        'cms' => ['HTTP_AUTHORIZATION' => cmsAuthHeaders()['Authorization']],
        'user', 'admin' => ['HTTP_AUTHORIZATION' => 'Bearer '.User::factory()
            ->create(['role' => $actor === 'admin' ? Role::Admin : Role::User])
            ->createToken('matrix')->plainTextToken],
        default => throw new InvalidArgumentException("Unknown actor [{$actor}]."),
    };
}

/**
 * @return array<string, array{0: string, 1: string, 2: string, 3: int}>
 */
function accessMatrix(): array
{
    $rows = [];
    $endpoints = [
        // [method, uri, [anonymous, cms, user, admin]]
        ['POST', '/cms/events', [401, 202, 401, 401]],
        ['GET', '/api/v1/contents', [401, 401, 200, 200]],
        ['GET', '/api/v1/contents/content-1', [401, 401, 200, 200]],
        // US3: administrator filters and local overrides.
        ['GET', '/api/v1/contents?status=unpublished', [401, 401, 403, 200]],
        ['PUT', '/api/v1/contents/content-1/disabled', [401, 401, 403, 200]],
        ['DELETE', '/api/v1/contents/content-1/disabled', [401, 401, 403, 200]],
    ];

    foreach ($endpoints as [$method, $uri, $statuses]) {
        foreach (['anonymous', 'cms', 'user', 'admin'] as $i => $actor) {
            $rows["{$method} {$uri} as {$actor}"] = [$method, $uri, $actor, $statuses[$i]];
        }
    }

    return $rows;
}

it('enforces the access matrix', function (string $method, string $uri, string $actor, int $status): void {
    Content::factory()->published()->create(['cms_id' => 'content-1']);

    $body = $method === 'POST' && str_starts_with($uri, '/cms/')
        ? (string) json_encode([cmsEvent('delete', 'content-2', 1)])
        : '';

    call($method, $uri, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        ...actorServerHeaders($actor),
    ], $body)->assertStatus($status);
})->with(accessMatrix());
