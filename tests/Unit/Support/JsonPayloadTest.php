<?php

declare(strict_types=1);

use App\Support\Json\JsonPayload;

it('keeps empty objects and empty lists distinct through a round trip', function (string $json): void {
    expect(JsonPayload::encode(JsonPayload::decodeBody($json)))->toBe($json);
})->with([
    'empty object' => ['{}'],
    'empty list' => ['[]'],
    'nested' => ['{"tags":{},"items":[],"meta":{"seo":{}}}'],
    'list of objects' => ['[{},{"a":[]}]'],
    'unicode without escaping' => ['{"title":"Ação 🚀"}'],
]);

it('decodes objects as stdClass and lists as arrays', function (): void {
    $decoded = JsonPayload::decodeBody('{"a":{},"b":[]}');

    expect($decoded)->toBeInstanceOf(stdClass::class)
        ->and($decoded->a)->toBeInstanceOf(stdClass::class)
        ->and($decoded->b)->toBe([]);
});

it('decodes integers beyond int64 as strings instead of lossy floats', function (): void {
    expect(JsonPayload::decodeBody('{"v":9223372036854775808}')->v)->toBe('9223372036854775808')
        ->and(JsonPayload::decodeBody('{"v":9223372036854775807}')->v)->toBe(PHP_INT_MAX);
});

it('throws on malformed JSON', function (): void {
    JsonPayload::decodeBody('[{');
})->throws(JsonException::class);
