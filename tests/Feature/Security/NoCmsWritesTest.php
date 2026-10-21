<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\json;

it('offers no way to create, modify or delete CMS-managed content', function (string $method, string $uri): void {
    Content::factory()->published()->version(3)->create(['cms_id' => 'content-1', 'payload' => (object) ['title' => 'CMS']]);
    actingAsRole(Role::Admin);
    $before = DB::table('contents')->get()->toArray();

    json($method, $uri, ['version' => 99, 'status' => 'deleted', 'payload' => ['title' => 'hacked']])
        ->assertMethodNotAllowed()
        ->assertHeader('Content-Type', 'application/problem+json');

    expect(DB::table('contents')->get()->toArray())->toEqual($before);
})->with([
    ['POST', '/api/v1/contents'],
    ['PUT', '/api/v1/contents/content-1'],
    ['PATCH', '/api/v1/contents/content-1'],
    ['DELETE', '/api/v1/contents/content-1'],
    ['POST', '/api/v1/contents/content-1'],
]);

it('only exposes the local override and batch retry as write operations', function (): void {
    $allowed = [
        'PUT api/v1/contents/{contentId}/disabled',
        'DELETE api/v1/contents/{contentId}/disabled',
        'POST api/v1/admin/event-batches/{batch}/retry',
    ];

    $writes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1'))
        ->flatMap(fn ($route): array => array_map(
            fn (string $method): string => "{$method} {$route->uri()}",
            array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']),
        ))
        ->values()
        ->all();

    expect(array_diff($writes, $allowed))->toBe([]);
});
