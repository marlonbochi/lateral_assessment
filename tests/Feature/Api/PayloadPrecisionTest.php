<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\Content;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;

use Symfony\Component\HttpFoundation\Response;

/**
 * Deliver one publish event whose payload is given as raw JSON text, so
 * numbers PHP cannot represent reach the service exactly as the CMS sent them.
 */
function deliverRawPayload(string $cmsId, string $payloadJson): void
{
    $body = '[{"type":"publish","id":"'.$cmsId.'","version":1,"timestamp":"2026-01-01T10:00:00Z","payload":'.$payloadJson.'}]';

    call('POST', '/cms/events', [], [], [], [
        'HTTP_AUTHORIZATION' => cmsAuthHeaders()['Authorization'],
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $body)->assertAccepted();

    drainQueue();
}

/**
 * The payload exactly as PostgreSQL stores it (jsonb keeps numbers exact).
 */
function storedPayloadText(string $cmsId): string
{
    return (string) DB::scalar('select payload::text from contents where cms_id = ?', [$cmsId]);
}

/**
 * @param  TestResponse<Response>  $response
 */
function assertServesStoredPayload(TestResponse $response, string $cmsId): void
{
    $response->assertOk();

    expect($response->getContent())->toContain(storedPayloadText($cmsId));
}

it('serves payload numbers exactly as stored', function (string $payloadJson, string $mustAppear): void {
    deliverRawPayload('precise', $payloadJson);
    actingAsRole(Role::Admin);

    $response = getJson('/api/v1/contents/precise');

    assertServesStoredPayload($response, 'precise');
    expect($response->getContent())->toContain($mustAppear)
        ->and($response->getContent())->not->toMatch('/\d[eE][+-]?\d/');
})->with([
    'integer beyond int64' => ['{"views":123456789012345678901234567890}', '123456789012345678901234567890'],
    'negative integer beyond int64' => ['{"delta":-98765432109876543210}', '-98765432109876543210'],
    'decimal beyond double precision' => ['{"pi":3.14159265358979323846264338327950288}', '3.14159265358979323846264338327950288'],
    'exponent beyond double range' => ['{"huge":1e400}', '1'.str_repeat('0', 400)],
    'nested inside lists and objects' => ['{"stats":{"ids":[18446744073709551616]}}', '18446744073709551616'],
]);

it('serves exact payloads in listings and override responses too', function (): void {
    deliverRawPayload('precise', '{"views":123456789012345678901234567890}');
    actingAsRole(Role::Admin);

    assertServesStoredPayload(getJson('/api/v1/contents?per_page=5'), 'precise');
    assertServesStoredPayload(getJson('/api/v1/contents?page=1&per_page=5'), 'precise');
    assertServesStoredPayload(putJson('/api/v1/contents/precise/disabled'), 'precise');
});

it('keeps the response valid JSON with the payload as an object', function (): void {
    deliverRawPayload('precise', '{"title":"Exact","tags":{},"items":[],"views":123456789012345678901234567890}');
    actingAsRole(Role::User);

    $decoded = json_decode((string) getJson('/api/v1/contents/precise')->getContent(), false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);

    expect($decoded->data->content->title)->toBe('Exact')
        ->and($decoded->data->content->tags)->toEqual(new stdClass)
        ->and($decoded->data->content->items)->toBe([])
        ->and($decoded->data->content->views)->toBe('123456789012345678901234567890');
});

it('never rewrites a payload when a model is saved after reading it', function (): void {
    deliverRawPayload('precise', '{"views":123456789012345678901234567890,"huge":1e400}');
    $before = storedPayloadText('precise');

    $content = Content::query()->where('cms_id', 'precise')->firstOrFail();
    expect($content->payload)->not->toBeNull(); // read through the cast

    $content->forceFill(['is_disabled' => true])->save();

    expect(storedPayloadText('precise'))->toBe($before);
});
