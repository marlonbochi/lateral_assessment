<?php

declare(strict_types=1);

use App\Models\EventBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;

use Symfony\Component\HttpFoundation\Response;

/**
 * POST a raw body to the webhook with valid CMS credentials.
 *
 * @return TestResponse<Response>
 */
function postRawBatch(string $body, string $contentType = 'application/json'): TestResponse
{
    $server = [
        'HTTP_AUTHORIZATION' => cmsAuthHeaders()['Authorization'],
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => 'application/json',
    ];

    return call('POST', '/cms/events', [], [], [], $server, $body);
}

function expectNothingStored(): void
{
    expect(EventBatch::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
}

it('rejects the whole batch and stores nothing when it is invalid', function (string $body, string $errorKey): void {
    postRawBatch($body)
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('detail', 'The batch is invalid; no events were accepted.')
        ->assertJsonStructure(['errors' => [$errorKey]]);

    expectNothingStored();
})->with([
    // Batch-level errors use the key "events".
    'empty list' => ['[]', 'events'],
    'more than 100 events' => [fn () => json_encode(array_map(fn (int $i): array => cmsEvent('delete', "c-{$i}", 1), range(1, 101))), 'events'],
    'JSON object root' => ['{"type":"publish","id":"a","version":1,"timestamp":"2026-01-01T10:00:00Z","payload":{}}', 'events'],
    'malformed JSON' => ['[{"type":"publish",', 'events'],
    'scalar root' => ['42', 'events'],
    // Event-level errors use "events.{i}.{field}".
    'missing id' => ['[{"type":"delete","version":1,"timestamp":"2026-01-01T10:00:00Z"}]', 'events.0.id'],
    'empty id' => [fn () => json_encode([cmsEvent('delete', '', 1)]), 'events.0.id'],
    'id longer than 255' => [fn () => json_encode([cmsEvent('delete', str_repeat('a', 256), 1)]), 'events.0.id'],
    'id with a space' => [fn () => json_encode([cmsEvent('delete', 'content 1', 1)]), 'events.0.id'],
    'id with a slash' => [fn () => json_encode([cmsEvent('delete', 'a/b', 1)]), 'events.0.id'],
    'numeric id' => ['[{"type":"delete","id":123,"version":1,"timestamp":"2026-01-01T10:00:00Z"}]', 'events.0.id'],
    'version as a string' => ['[{"type":"delete","id":"a","version":"2","timestamp":"2026-01-01T10:00:00Z"}]', 'events.0.version'],
    'version as a float' => ['[{"type":"delete","id":"a","version":2.5,"timestamp":"2026-01-01T10:00:00Z"}]', 'events.0.version'],
    'version zero' => [fn () => json_encode([cmsEvent('delete', 'a', 0)]), 'events.0.version'],
    'negative version' => [fn () => json_encode([cmsEvent('delete', 'a', -1)]), 'events.0.version'],
    'version beyond int64' => ['[{"type":"delete","id":"a","version":9223372036854775808,"timestamp":"2026-01-01T10:00:00Z"}]', 'events.0.version'],
    'missing version' => ['[{"type":"delete","id":"a","timestamp":"2026-01-01T10:00:00Z"}]', 'events.0.version'],
    'timestamp "yesterday"' => [fn () => json_encode([cmsEvent('delete', 'a', 1, null, 'yesterday')]), 'events.0.timestamp'],
    'date without time and offset' => [fn () => json_encode([cmsEvent('delete', 'a', 1, null, '2026-01-01')]), 'events.0.timestamp'],
    'date-time without offset' => [fn () => json_encode([cmsEvent('delete', 'a', 1, null, '2026-01-01T10:00:00')]), 'events.0.timestamp'],
    'missing timestamp' => ['[{"type":"delete","id":"a","version":1}]', 'events.0.timestamp'],
    // RFC 3339 offsets are hh:mm with hh 00-23 and mm 00-59.
    'offset minutes out of range' => [fn () => json_encode([cmsEvent('delete', 'a', 1, null, '2026-01-01T10:00:00+23:99')]), 'events.0.timestamp'],
    'offset minute 60' => [fn () => json_encode([cmsEvent('delete', 'a', 1, null, '2026-01-01T10:00:00-05:60')]), 'events.0.timestamp'],
    'offset hour 24' => [fn () => json_encode([cmsEvent('delete', 'a', 1, null, '2026-01-01T10:00:00+24:00')]), 'events.0.timestamp'],
    'unknown type' => [fn () => json_encode([cmsEvent('archive', 'a', 1)]), 'events.0.type'],
    'publish without payload' => [fn () => json_encode([cmsEvent('publish', 'a', 1)]), 'events.0.payload'],
    'unpublish with a string payload' => ['[{"type":"unpublish","id":"a","version":1,"timestamp":"2026-01-01T10:00:00Z","payload":"text"}]', 'events.0.payload'],
    'publish with a list payload' => [fn () => json_encode([cmsEvent('publish', 'a', 1, [1, 2])]), 'events.0.payload'],
    'publish with an empty list payload' => ['[{"type":"publish","id":"a","version":1,"timestamp":"2026-01-01T10:00:00Z","payload":[]}]', 'events.0.payload'],
    'event that is not an object' => ['["publish"]', 'events.0'],
    'third event of a valid batch is invalid' => [fn () => json_encode([
        cmsEvent('publish', 'a', 1, ['title' => 'A']),
        cmsEvent('unpublish', 'b', 2, ['title' => 'B']),
        cmsEvent('delete', 'c', 0),
    ]), 'events.2.version'],
]);

it('accepts an empty JSON object as payload', function (): void {
    postRawBatch('[{"type":"publish","id":"a","version":1,"timestamp":"2026-01-01T10:00:00Z","payload":{}}]')
        ->assertAccepted();
});

it('accepts and tolerates a payload on delete events', function (): void {
    postRawBatch((string) json_encode([cmsEvent('delete', 'a', 1, ['ignored' => true])]))->assertAccepted();
});

it('accepts timestamps with a numeric offset and fractional seconds', function (): void {
    postRawBatch((string) json_encode([cmsEvent('delete', 'a', 1, null, '2026-01-01T10:00:00.123+02:00')]))->assertAccepted();
});

it('rejects a body that is not JSON with 415', function (): void {
    postRawBatch('type=publish', 'text/plain')
        ->assertStatus(415)
        ->assertHeader('Content-Type', 'application/problem+json');

    expectNothingStored();
});

it('rejects a body larger than the configured limit with 413', function (): void {
    config(['cms.webhook.max_body_bytes' => 200]);

    postRawBatch((string) json_encode([cmsEvent('publish', 'a', 1, ['body' => str_repeat('x', 500)])]))
        ->assertStatus(413)
        ->assertHeader('Content-Type', 'application/problem+json');

    expectNothingStored();
});
