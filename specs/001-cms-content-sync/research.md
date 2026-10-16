# Research & Technical Decisions: CMS Content Sync Service

**Feature**: [spec.md](spec.md) | **Plan**: [plan.md](plan.md) | **Date**: 2026-10-16

Each section records a decision, why it was made, and the alternatives that were rejected.
These decisions are summarized again in the README's "Architecture & trade-offs" section.

---

## R1. Runtime versions and core packages

- **Decision**: PHP 8.4 (composer constraint `^8.3`), Laravel 13.x, Laravel Sanctum 4.x,
  PostgreSQL 18, Pest 5 (on PHPUnit 13), Larastan 3, Laravel Pint.
- **Rationale**: Latest stable releases as of 2026-10. Laravel 13 requires PHP ≥ 8.3, which
  matches the challenge's "PHP 8.3+" requirement; developing on 8.4 while declaring `^8.3`
  keeps the code compatible with both. Every package except Pest/Larastan is first-party Laravel.
- **Alternatives considered**: PostgreSQL 16/17 (fine, but no reason to pick an older major);
  PHPUnit only (rejected by team decision — Pest datasets suit the access matrix and event
  permutation tests, and Pest arch tests can enforce layering rules).

## R2. Webhook processing model: asynchronous with a transactional inbox

- **Decision**: The webhook request authenticates, validates the whole batch, and then — in one
  database transaction — inserts an `event_batches` row (status `pending`, the raw events) and
  enqueues a `ProcessEventBatch` job on the **`database` queue driver using the same PostgreSQL
  connection**. After commit it returns `202 Accepted` with the batch id. A queue worker applies
  the batch.
- **Rationale**:
  - A webhook sender only cares that its delivery was accepted; once we acknowledge, not losing
    data is our job. Writing the batch and its job in the **same transaction** gives an
    all-or-nothing hand-off: either both exist or the request fails with `5xx` and the CMS retries.
    No "acknowledged but never queued" window exists.
  - Ingestion latency is decoupled from processing cost, spikes are absorbed by the queue, and
    throughput scales by adding workers.
  - Correctness does not depend on the order in which batches are processed, because precedence
    is by version (R5), so parallel workers are safe.
- **Important detail**: the job is dispatched **inside** the transaction on purpose (not
  `afterCommit`), which is only atomic because the queue lives in the same database. The batch
  job pins its queue connection via config (`cms.queue.connection`, default `database`) and a test
  asserts that a failure after the insert leaves neither a batch nor a job behind. Switching the
  queue to Redis/SQS would require an outbox relay; this is documented as a known limitation.
- **Alternatives considered**:
  - *Synchronous processing in the request* — simpler and gives per-event outcomes, but couples
    CMS latency to our write path and makes future growth a breaking change.
  - *Async with Redis queue + `afterCommit` dispatch* — leaves a crash window between commit and
    enqueue; would need a sweeper/outbox to be loss-free; adds a service to run.
  - *Configurable sync/async* — two code paths to test and explain for no real benefit.

## R3. Job reliability, retries, and failed-batch recovery

- **Decision**: `ProcessEventBatch` implements `ShouldQueue`, with `tries = 5`, exponential
  `backoff = [5, 30, 120, 600]` seconds, and `failed()` marking the batch `failed` with a sanitized
  error summary. The job is **idempotent**: it locks its batch row (`SELECT … FOR UPDATE`) and
  returns immediately if the batch is already `processed`, so at-least-once queue delivery
  cannot double-apply anything (and double-applying would be a no-op anyway thanks to versions).
  Re-processing a failed batch (API or CLI) resets `attempts`, sets status back to `pending`, and
  dispatches a new job in one transaction.
- **Rationale**: Transient DB errors (deadlock, connection loss) are retried automatically;
  permanent failures stay visible and recoverable, satisfying "no acknowledged batch is lost".
- **Alternatives considered**: relying only on Laravel's `failed_jobs` table (it stores the
  serialized job, not a queryable domain record, and is not exposed in a typed way to admins).

## R4. Atomic, ordered batch application

- **Decision**: The worker applies a batch inside a single `DB::transaction()` (with
  `attempts: 3` for serialization/deadlock retries): acquire entity locks (R5), then iterate the
  events **in received order**, evaluate each against the current state, write changes, and
  finally mark the batch `processed` with applied/ignored counts. Any exception rolls back
  everything, including the status change.
- **Rationale**: Satisfies "process valid batches atomically, in the order received" (FR-004,
  FR-005). Events for the same entity later in the batch see the state written by earlier ones,
  because they share the transaction.
- **Alternatives considered**: per-event transactions (violates batch atomicity).

## R5. Concurrency control: sorted transaction-scoped advisory locks

- **Decision**: Before applying events, the worker acquires
  `pg_advisory_xact_lock(hashtextextended(cms_id, 0))` for every **distinct** content id in the
  batch, **in sorted order**. Locks are released automatically at commit/rollback. Writes also
  include a defensive guard `WHERE version < :incoming` and the `cms_id` unique constraint.
- **Rationale**:
  - Row locks (`SELECT … FOR UPDATE`) cannot lock a row that does not exist yet, and the first
    event for an entity is common. Advisory locks lock the *identifier*, existing or not.
  - Acquiring locks in a deterministic (sorted) order prevents deadlocks between batches that
    touch the same entities in different orders (batch A: x→y, batch B: y→x).
  - Hash collisions only cause extra serialization, never incorrect results.
  - The version guard and unique index are defense in depth if a future code path forgets to lock.
- **Alternatives considered**:
  - `INSERT … ON CONFLICT DO UPDATE … WHERE contents.version < EXCLUDED.version` per event —
    atomic and fast, but does not cleanly return the previous version/status needed for
    observability and for the delete/re-create semantics; still a candidate optimization.
  - `SERIALIZABLE` isolation with retries — correct but harder to reason about and explain,
    and causes retry storms under contention.
  - Placeholder rows + `FOR UPDATE` — pollutes the table with rows in an invalid state.

## R6. Event precedence as a pure domain function

- **Decision**: A pure, framework-free class (`EventPrecedence`) decides the outcome of an event
  given the current snapshot (`?version`, `?status`):
  - no current state → **apply**
  - `incoming.version > current.version` → **apply** (including after delete → re-create)
  - `incoming.version == current.version` → **ignore: duplicate**
  - `incoming.version < current.version` → **ignore: stale** (`stale_after_delete` when the
    current status is `deleted`, for clearer logs)
  Applying maps event type to status: `publish → published`, `unpublish → unpublished`,
  `delete → deleted` (payload cleared). Timestamps are never compared.
- **Rationale**: The highest-risk logic is unit-testable in microseconds and with exhaustive
  datasets (permutations/duplications of event sequences, SC-001), independent of HTTP or DB.
- **Alternatives considered**: putting the rules in the Eloquent model or SQL only (harder to
  test exhaustively and to explain).

## R7. Delete semantics: tombstones

- **Decision**: A delete keeps the `contents` row with `status = deleted`, `version` = deletion
  version, and `payload = NULL` (CMS content is actually removed). The local override columns are
  kept. A newer publish/unpublish re-creates the content on the same row (FR-012a).
- **Rationale**: The tombstone is what makes "older events cannot restore it" enforceable, and
  clearing the payload honours "permanently removed". Not using Laravel `SoftDeletes` because
  deletion here is a CMS state with a version, not a local soft delete, and global scopes would
  hide the row from the precedence check.
- **Alternatives considered**: hard delete + separate `tombstones` table (two places to check,
  more joins, race-prone); `SoftDeletes` (wrong semantics, see above).

## R8. Payload fidelity: preserving JSON objects vs arrays

- **Decision**: The webhook controller decodes the raw body itself with
  `json_decode($body, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)` (objects stay
  `stdClass`) for validation of shape, then stores each payload as `jsonb` via an encoder that
  preserves `{}` vs `[]`. Validation requires `payload` to be a JSON **object** (not a list) for
  publish/unpublish.
- **Where it applies**: everywhere a payload crosses PHP: the request (object decode kept
  alongside the associative decode used by the validator, which cannot traverse `stdClass`),
  the inbox (`event_batches.events` uses an object-preserving list cast, never `array`), the
  worker (builds `CmsEvent` from objects), and `contents.payload` (object-preserving cast).
- **Rationale**: PHP's associative decoding turns `{}` into `[]`, making an empty object
  indistinguishable from an empty list and silently changing CMS data on round-trip. Big integers
  become strings and therefore fail the integer check instead of overflowing to floats.
- **Alternatives considered**: `$request->json()->all()` with Laravel's `array` rule (cannot
  distinguish object vs list; acceptable for most payloads but loses fidelity on edge cases).

## R9. Batch validation rules

- **Decision**: A `CmsEventBatchRequest` (Form Request) validates the decoded list:
  - root: must be a JSON array (list) with 1–100 items; request body ≤ 1 MiB (configurable,
    `413` above it); `Content-Type: application/json` required (`415` otherwise).
  - `id`: required string, 1–255 chars, pattern `^[A-Za-z0-9][A-Za-z0-9._:-]*$` (URL-safe, see
    limitations).
  - `version`: required **strict** integer (no numeric strings, no floats), 1 ≤ v ≤ 2^63−1.
  - `timestamp`: required ISO-8601 / RFC 3339 date-time with timezone.
  - `type`: required, one of `publish|unpublish|delete` (backed enum `CmsEventType`).
  - `payload`: required JSON object for publish/unpublish; optional and ignored for delete.
  - Unknown extra fields are ignored. Errors are keyed by position, e.g. `events.2.version`.
- **Rationale**: Implements FR-001–FR-003 exactly, with whole-batch rejection before anything
  is stored.
- **Alternatives considered**: JSON Schema validation package (another dependency for rules
  that Form Requests express clearly).

## R10. Authentication

- **Decision (CMS)**: Custom `AuthenticateCms` middleware for HTTP Basic on `/cms/events` only.
  Credentials come from `CMS_WEBHOOK_USERNAME` / `CMS_WEBHOOK_PASSWORD`; compared with
  `hash_equals` for both parts (constant time, no short-circuit revealing which part failed).
  Missing/empty configuration fails closed (`401` + critical log), never open. Responds `401`
  with `WWW-Authenticate: Basic realm="cms-webhook"`.
- **Decision (consumers)**: Laravel Sanctum personal access tokens as opaque bearer tokens on the
  `users` table, which carries a `role` (`user|admin`, backed enum). Tokens are SHA-256 hashed at
  rest, revocable individually, issued only via artisan commands, prefixed (`cms_`) so secret
  scanners can detect leaks, and expire after a configurable period (default 90 days). No
  password column, no login endpoint, no sessions/cookies (`statefulApi` disabled).
- **Actor separation**: the CMS route only runs `AuthenticateCms`; consumer routes only run
  `auth:sanctum`. A Basic header on a consumer route, or a bearer token on the webhook, is
  simply not a valid credential there → `401`. Nothing is shared between the two stores.
- **Rationale**: First-party, minimal, stateless, revocable, and easy to explain. Matches the
  clarified decision in the spec.
- **Alternatives considered**: Laravel's built-in `auth.basic` (session/user-provider based,
  not env credentials); JWT (third-party, hard revocation); static env API keys (no identity or
  revocation).

## R11. Authorization

- **Decision**: Backed enum `Role` + `ContentPolicy` and `EventBatchPolicy`. Visibility is
  enforced by a query scope `Content::visibleTo(User $user)`:
  - user: `status = 'published' AND is_disabled = false`
  - admin: `status <> 'deleted'`
  Retrieval resolves the content **through the scoped query**, so hidden items return the same
  `404` as unknown ones (FR-024). Admin-only operations (disable/enable, filters, batch
  management) are checked with a role gate **before** route-model binding resolves the content,
  so a regular user always receives `403` regardless of whether the id exists (no enumeration).
- **Mechanism**: the role checks live only in gates (`admin`, `filter-contents`) and policies.
  The `EnsureRole` middleware is a thin adapter that calls `Gate::authorize()` early, and the
  controllers call the policies for each resource.
- **Rationale**: Authorization lives server-side in one place per concern and is testable via
  a Pest dataset covering the whole actor × endpoint matrix (SC-004).
- **Alternatives considered**: separate `/admin/contents` namespace (rejected by team decision:
  one resource, one URL, role-aware view).

## R12. Consumer API design

- **Decision**:
  - Versioned prefix `/api/v1`. Content is addressed by its CMS id: `/api/v1/contents/{id}`.
  - Role-aware listing/retrieval; admin responses add `status` and `local_override`.
  - Local override as a sub-resource: `PUT /contents/{id}/disabled` (disable) and
    `DELETE /contents/{id}/disabled` (re-enable), both idempotent, returning the content.
  - Admin-only filters `status` (`published|unpublished`) and `disabled` (`true|false`); a
    regular user sending them gets `403`.
  - Admin batch management under `/api/v1/admin/event-batches` (list, show, retry).
  - API Resources shape responses; fields are snake_case; timestamps are RFC 3339 UTC.
- **Rationale**: REST-consistent, no endpoint can modify CMS-managed fields (FR-029), and the
  override sub-resource makes the "local, independent of CMS" semantics explicit in the URL.
- **Alternatives considered**: `POST /contents/{id}/disable` + `/enable` action endpoints
  (RPC-style); `PATCH /contents/{id}` with `disabled` (suggests the content itself is editable).

## R13. Pagination

- **Decision**: Cursor pagination by default (`cursor`, `per_page`), ordered by
  `synced_at DESC, cms_id DESC` (deterministic tie-breaker). Clients opt into page-number
  pagination by sending `page` (`page`, `per_page`, plus `total` and `last_page` in `meta`).
  Sending both `cursor` and `page` → `422`. `per_page` 1–100, default 15. Invalid cursor → `422`.
- **Rationale**: Cursor pagination is O(1) per page and stable while the CMS keeps updating
  content; page-number pagination is available for clients that need totals. Both use the same
  indexes (R14).
- **Alternatives considered**: offset only (slow deep pages, skips/duplicates on concurrent
  updates); cursor only (no totals for UIs that need them).

## R14. Indexing strategy

- **Decision**:
  - `contents_cms_id_unique` — unique btree on `cms_id` (lookup, upsert, integrity).
  - `contents_user_feed_idx` — partial btree on `(synced_at DESC, cms_id DESC)`
    `WHERE status = 'published' AND is_disabled = false` → regular-user listing.
  - `contents_admin_feed_idx` — partial btree on `(synced_at DESC, cms_id DESC)`
    `WHERE status <> 'deleted'` → admin listing (status/disabled filters are applied on top;
    selectivity of those filters is low, so no extra index until measured).
  - `event_batches_status_received_idx` on `(status, received_at DESC, id DESC)` → batch listing.
- **Rationale**: Each consumer query path is served by an index matching both predicate and sort
  order, so pages are index scans with no sort step; partial indexes stay small because they
  exclude tombstones/hidden rows. Verified with `EXPLAIN` in a seeded dataset (SC-006).
- **Alternatives considered**: GIN index on `payload` (no payload search requirement — YAGNI).

## R15. Error format: RFC 9457 Problem Details

- **Decision**: All API and webhook errors are rendered as `application/problem+json`
  with `type`, `title`, `status`, `detail`, and `instance`; validation errors add an `errors`
  object keyed by field path. Implemented once in `bootstrap/app.php` `withExceptions()` for
  routes under `api/*` and `cms/*`. Stack traces only when `APP_DEBUG=true`.
- **Rationale**: IETF standard, consistent across 401/403/404/409/413/415/422/429/5xx, and
  friendly to non-Laravel clients.
- **Alternatives considered**: Laravel's default `{message, errors}` (non-standard).

## R16. Observability

- **Decision**: A dedicated `cms` log channel (JSON formatter to `stderr` in Docker, daily file
  locally). Context added via `Log::withContext()` per job: `batch_id`, `attempt`. Event-level
  entries: `cms.event.applied`, `cms.event.ignored` (reason), with `content_id`, `type`,
  `incoming_version`, `stored_version`, `previous_status`, `new_status`. Batch-level entries:
  `cms.batch.received` (event count, request id), `cms.batch.rejected` (error field paths only,
  not values), `cms.batch.processed` (applied/ignored counts, duration ms),
  `cms.batch.attempt_failed`, `cms.batch.failed`, `cms.batch.retry_requested` (actor),
  `content.override.changed` (actor id, content id, new value). Payloads, credentials, tokens,
  and `Authorization` headers are never logged; a test captures log output and asserts it.
- **Rationale**: FR-030–FR-032 and SC-008, using native Laravel logging (Monolog) only.
- **Alternatives considered**: OpenTelemetry tracing (valuable in production, out of scope).

## R17. Rate limiting and request limits

- **Decision**: Named rate limiters: `cms-webhook` (default 300/min, keyed by IP) and `api`
  (default 120/min per token). Body size limit for the webhook (default 1 MiB). All
  configurable via env.
- **Rationale**: Basic abuse protection on authenticated endpoints with zero extra
  infrastructure; the database cache store backs the limiter.
- **Alternatives considered**: no limits (weak security posture).

## R18. Test strategy and isolation

- **Decision**:
  - **Unit** (`tests/Unit`): `EventPrecedence` (exhaustive datasets + randomized permutation
    property test for SC-001), value objects, enums, cursor/pagination helpers.
  - **Feature/Integration** (`tests/Feature`): HTTP + PostgreSQL via `RefreshDatabase`:
    webhook auth/validation/acknowledgement, batch processing via the job (queue `sync` in tests
    where the hand-off is not under test; real `database` driver where it is), access matrix,
    visibility, overrides, pagination, problem details, logging hygiene, failure injection and
    retry/recovery.
  - **Concurrency** test: two DB connections processing conflicting batches in parallel
    processes to prove lock ordering/no lost updates. It uses `DatabaseTruncation` (as do the
    performance tests), because other processes only see committed data.
  - **Performance** group (opt-in, run in CI with a 20k seed and locally with 100k via
    `make perf`): query plans, p95 latency, and ack/processing times.
  - **Arch** tests: controllers don't touch the DB directly, domain layer has no Laravel deps,
    `declare(strict_types=1)` everywhere.
  - Tests use a **dedicated `cms_sync_test` database** (`phpunit.xml` + `.env.testing`); a guard in
    `TestCase` aborts if the configured database name does not end in `_test`.
- **Rationale**: Covers every scenario required by the brief and the constitution, and makes
  "tests must not use the development database" enforceable rather than conventional.
- **Alternatives considered**: SQLite in-memory for speed (rejected: advisory locks, partial
  indexes, `jsonb`, and CHECK constraints are PostgreSQL-specific).

## R19. Container runtime

- **Decision**: Single multi-stage `Dockerfile` (PHP 8.4-FPM Alpine, `pdo_pgsql`, opcache;
  Composer stage for vendor) reused by three Compose services: `app` (php-fpm), `worker`
  (`php artisan queue:work --tries=5 --max-time=3600`), `scheduler` (`php artisan schedule:work`
  for pruning). Plus `nginx` (alpine) and `postgres:18-alpine` with an init script creating
  `cms_sync` and `cms_sync_test`. Healthchecks on postgres and app; app waits for a healthy DB.
  `make`-style shortcuts via a `Makefile` (`make up`, `make test`, `make lint`).
- **Rationale**: The most widely understood production topology (Nginx + PHP-FPM) per team
  decision; one image for all PHP roles keeps builds simple.
- **Alternatives considered**: FrankenPHP/Octane (modern, but worker-mode state concerns and less
  familiar to reviewers); Laravel Sail (dev-only image, not representative of production).

## R20. CI pipeline (GitHub Actions)

- **Decision**: Workflow on push and pull request with jobs:
  1. `lint` — `composer validate --strict`, `composer audit`, `pint --test`.
  2. `static-analysis` — Larastan (level 8).
  3. `tests` — PHP 8.3 and 8.4 matrix, `postgres:18` service, migrate, `pest --parallel`
     with coverage report (min coverage gate 85%).
  4. `docker` — build the image (no push) to ensure the Dockerfile stays valid.
  Composer cache, concurrency group cancelling superseded runs, least-privilege
  `permissions: contents: read`.
- **Rationale**: Enforces the constitution's quality gates automatically on every change.
- **Alternatives considered**: running tests inside Docker Compose in CI (slower, duplicate setup).

## R21. Housekeeping

- **Decision**: `event_batches` processed rows are pruned after `CMS_BATCH_RETENTION_DAYS`
  (default 30) using Laravel's `MassPrunable` + scheduler; failed batches are never pruned
  automatically. Sanctum expired tokens are pruned daily (`sanctum:prune-expired`).
- **Rationale**: The inbox stores CMS payloads (potentially confidential); bounded retention
  limits exposure and table growth.

## Known limitations (to be carried into the README)

- Atomic hand-off relies on the queue being in the same PostgreSQL database; moving to
  Redis/SQS requires a transactional outbox relay.
- Content ids must match the URL-safe pattern in R9; ids with other characters are rejected.
- No content history: only the latest accepted version is kept; past versions live only in
  retained batches until pruned.
- Eventual consistency: content becomes visible after the worker processes its batch (seconds).
- Single CMS sender with one credential pair; credential rotation requires a deploy/restart
  (could support a list of active credentials later).
- Advisory-lock hashing can serialize unrelated ids on rare collisions (performance only).
