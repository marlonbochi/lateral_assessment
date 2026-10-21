<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;

use function Pest\Laravel\getJson;

it('exposes exactly the public fields to regular users', function (): void {
    Content::factory()->published()->version(7)->create([
        'cms_id' => 'content-1',
        'payload' => (object) ['title' => 'Hello', 'tags' => (object) [], 'items' => []],
        'cms_timestamp' => '2026-01-01 10:00:00+00',
    ]);
    actingAsRole(Role::User);

    $item = getJson('/api/v1/contents/content-1')->assertOk()->json('data');

    expect(array_keys((array) $item))->toBe(['id', 'version', 'content', 'cms_updated_at', 'synced_at'])
        ->and($item['id'])->toBe('content-1')
        ->and($item['version'])->toBe(7)
        ->and($item['cms_updated_at'])->toBe('2026-01-01T10:00:00.000000Z')
        ->and($item['synced_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/');
});

it('serializes CMS payloads with empty objects as objects', function (): void {
    Content::factory()->published()->create(['cms_id' => 'empty', 'payload' => (object) []]);
    Content::factory()->published()->create(['cms_id' => 'nested', 'payload' => (object) ['tags' => (object) [], 'items' => []]]);
    actingAsRole(Role::User);

    // Decoded as objects so {} (stdClass) and [] (array) stay distinguishable.
    $content = fn (string $id): mixed => json_decode((string) getJson("/api/v1/contents/{$id}")->getContent(), false)->data->content;

    expect($content('empty'))->toEqual(new stdClass)
        ->and($content('nested'))->toEqual((object) ['tags' => new stdClass, 'items' => []]);
});

it('never exposes internal identifiers or override details to regular users', function (): void {
    Content::factory()->published()->create(['cms_id' => 'content-1']);
    actingAsRole(Role::User);

    $json = (string) getJson('/api/v1/contents')->getContent();

    expect($json)->not->toContain('"status"')
        ->and($json)->not->toContain('local_override')
        ->and($json)->not->toContain('disabled')
        ->and($json)->not->toContain('"created_at"');
});
