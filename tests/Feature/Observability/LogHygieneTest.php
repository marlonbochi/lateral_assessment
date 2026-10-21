<?php

declare(strict_types=1);

use App\Enums\Role;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\putJson;
use function Pest\Laravel\withHeaders;

const SENTINEL = 'SECRET-PAYLOAD-SENTINEL';

/**
 * Exercise the quickstart scenarios S1–S11 end to end while capturing every
 * log record on every channel (SC-008).
 */
it('never logs credentials, tokens, authorization headers or payload content', function (): void {
    /** @var list<string> $records */
    $records = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$records): void {
        $context = array_map(fn (mixed $v): mixed => $v instanceof Throwable ? (string) $v : $v, $e->context);
        $records[] = $e->message.' '.json_encode($context);
    });

    $payload = fn (string $v): array => ['title' => SENTINEL.'-'.$v, 'body' => SENTINEL];

    // S1: webhook authentication failures.
    withHeaders(cmsAuthHeaders('cms', 'wrong-password'))->postJson('/cms/events', [cmsEvent('publish', 'c-1', 1, $payload('1'))])->assertUnauthorized();
    // S3: invalid batch (with sentinel in the payload).
    postBatch([cmsEvent('publish', 'c-1', 1, $payload('x')), cmsEvent('delete', 'c-2', 0)])->assertUnprocessable();
    // S2, S4–S8: accepted batches, duplicates, stale, unpublish edge case, delete, late event, re-create.
    postBatch([cmsEvent('publish', 'c-1', 2, $payload('2')), cmsEvent('publish', 'c-1', 2, $payload('dup'))])->assertAccepted();
    postBatch([cmsEvent('publish', 'c-1', 1, $payload('stale'))])->assertAccepted();
    postBatch([cmsEvent('publish', 'c-9', 3, $payload('3')), cmsEvent('unpublish', 'c-9', 4, $payload('4'))])->assertAccepted();
    postBatch([cmsEvent('delete', 'c-1', 5), cmsEvent('publish', 'c-1', 4, $payload('late'))])->assertAccepted();
    postBatch([cmsEvent('publish', 'c-1', 6, $payload('6'))])->assertAccepted();
    drainQueue();

    // A failing attempt, so error paths are covered too.
    config(['cms.processing.backoff' => [0]]);
    DB::unprepared(<<<'SQL'
        CREATE FUNCTION test_fail_hygiene() RETURNS trigger AS $$
        BEGIN RAISE EXCEPTION 'simulated failure'; END
        $$ LANGUAGE plpgsql;
        CREATE TRIGGER test_fail_hygiene BEFORE INSERT OR UPDATE ON contents
            FOR EACH ROW EXECUTE FUNCTION test_fail_hygiene();
        SQL);
    postBatch([cmsEvent('publish', 'c-7', 1, $payload('7'))])->assertAccepted();
    drainQueue();
    DB::unprepared('DROP TRIGGER test_fail_hygiene ON contents; DROP FUNCTION test_fail_hygiene();');

    // S9–S11: overrides, role enforcement, pagination, with real bearer tokens.
    $admin = actingAsRole(Role::Admin);
    $adminToken = (string) request()->bearerToken();
    putJson('/api/v1/contents/c-1/disabled')->assertOk();
    deleteJson('/api/v1/contents/c-1/disabled')->assertOk();
    actingAsRole(Role::User);
    putJson('/api/v1/contents/c-1/disabled')->assertForbidden();
    getJson('/api/v1/contents?status=unpublished')->assertForbidden();
    getJson('/api/v1/contents?per_page=1')->assertOk();
    app('auth')->forgetGuards();
    withHeaders(['Authorization' => 'Bearer invalid-token-value'])->getJson('/api/v1/contents')->assertUnauthorized();

    $all = implode("\n", $records);
    $secrets = [
        SENTINEL,
        (string) config('cms.webhook.password'),
        base64_encode(config('cms.webhook.username').':'.config('cms.webhook.password')),
        'wrong-password',
        'invalid-token-value',
        'Authorization',
    ];

    expect($records)->not->toBeEmpty();
    foreach ($secrets as $secret) {
        expect($all)->not->toContain($secret);
    }
    expect($all)->not->toMatch('/\d+\|cms_[A-Za-z0-9]{20,}/')
        ->and($admin->id)->toBeInt();
});
