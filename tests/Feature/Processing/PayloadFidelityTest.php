<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\call;

/**
 * Send one publish event whose payload is the given raw JSON, process it, and
 * return the payload read back from contents as raw JSON text.
 */
function roundTripPayload(string $payloadJson): string
{
    $body = '[{"type":"publish","id":"content-1","version":1,"timestamp":"2026-01-01T10:00:00Z","payload":'.$payloadJson.'}]';

    call('POST', '/cms/events', [], [], [], [
        'HTTP_AUTHORIZATION' => cmsAuthHeaders()['Authorization'],
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertAccepted();

    drainQueue();

    return (string) DB::table('contents')->where('cms_id', 'content-1')->value('payload');
}

it('round-trips payloads through inbox and worker without changing them', function (string $payloadJson): void {
    $stored = roundTripPayload($payloadJson);

    // Decode as objects so {} (stdClass) and [] (array) stay distinguishable.
    expect(json_decode($stored, false, 512, JSON_BIGINT_AS_STRING))
        ->toEqual(json_decode($payloadJson, false, 512, JSON_BIGINT_AS_STRING));
})->with([
    'empty object' => ['{}'],
    'nested empty object vs empty list' => ['{"tags":{},"items":[]}'],
    'nested objects' => ['{"seo":{"meta":{"title":"T","robots":"noindex"}}}'],
    'arrays of objects' => ['{"blocks":[{"type":"text","body":"a"},{"type":"image","src":"b.png"}]}'],
    'unicode and emoji' => ['{"title":"Ação ✓ 日本語 🚀"}'],
    'large int64 integers' => ['{"views":9223372036854775807,"negative":-9223372036854775808}'],
    'floats' => ['{"price":19.99,"ratio":0.5}'],
    'null values' => ['{"subtitle":null,"tags":[null]}'],
    'booleans' => ['{"featured":true,"archived":false}'],
]);

it('keeps empty objects as objects in the inbox and in contents', function (): void {
    roundTripPayload('{"tags":{}}');

    $inboxType = DB::scalar("select jsonb_typeof(events->0->'payload'->'tags') from event_batches");
    $contentType = DB::scalar("select jsonb_typeof(payload->'tags') from contents where cms_id = 'content-1'");

    expect($inboxType)->toBe('object')->and($contentType)->toBe('object');
});

it('processes a batch whose payload is a top-level empty object', function (): void {
    expect(roundTripPayload('{}'))->toBe('{}');
});
