<?php

declare(strict_types=1);

use App\Support\Json\RawJson;

it('embeds the original text in place of its placeholder', function (): void {
    $encoded = json_encode(['id' => 'a', 'content' => new RawJson('{"n": 123456789012345678901234567890, "e": {}}')]);

    expect(RawJson::embed((string) $encoded))
        ->toBe('{"id":"a","content":{"n": 123456789012345678901234567890, "e": {}}}');
});

it('embeds several documents in one pass without rescanning them', function (): void {
    $inner = (string) json_encode(new RawJson('{"a": 1}'));
    $encoded = json_encode([new RawJson('{"a": 1}'), new RawJson('{"b": "'.addslashes($inner).'"}')]);

    expect(json_decode(RawJson::embed((string) $encoded), true))->toBe([['a' => 1], ['b' => $inner]]);
});

it('leaves documents without placeholders untouched', function (): void {
    expect(RawJson::embed('{"title":"raw-json:abc"}'))->toBe('{"title":"raw-json:abc"}');
});

it('only accepts valid JSON', function (): void {
    new RawJson('{"broken":');
})->throws(InvalidArgumentException::class);

it('tells objects apart from other documents', function (string $json, bool $isObject): void {
    expect((new RawJson($json))->isObject())->toBe($isObject);
})->with([
    'object' => ['{"a": 1}', true],
    'empty object with leading space' => [' {}', true],
    'list' => ['[]', false],
    'scalar' => ['1', false],
]);
