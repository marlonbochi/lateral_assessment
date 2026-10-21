---

description: "Task list for the CMS Content Sync Service implementation"
---

# Tasks: CMS Content Sync Service

**Input**: Design documents from `specs/001-cms-content-sync/`

**Prerequisites**: [plan.md](plan.md), [spec.md](spec.md), [research.md](research.md),
[data-model.md](data-model.md), [contracts/openapi.yaml](contracts/openapi.yaml),
[quickstart.md](quickstart.md)

**Tests**: REQUIRED. The constitution (Principle III, NON-NEGOTIABLE) mandates test-first
development, and the challenge brief lists mandatory test scenarios. In every story phase the
test tasks come first and MUST fail before implementation starts.

**Organization**: Tasks are grouped by user story so each story can be implemented and tested
independently. Phase order follows spec priority (P1 → P3). US4 (P1, access control) comes after
US2 because it hardens endpoints that must exist first.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: User story from spec.md (US1–US6)
- Paths are relative to the repository root (a single Laravel application, see plan.md)

## Conventions that apply to every task

- Every PHP file starts with `declare(strict_types=1);`.
- Run `make lint` (Pint + Larastan level 8) and `make test` before marking a task done.
- Commit with Conventional Commits after each task or logical group.
- Tests run against the `cms_sync_test` database only. Never point a test at `cms_sync`.
- Test helper `drainQueue()` (T014) runs the real `database` queue until it is empty. Processing
  tests MUST go through the queue, not call the job synchronously, unless the task says otherwise.

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Laravel skeleton, tooling, containers, and CI so every later task runs against
PostgreSQL in Docker and is checked automatically.

- [X] T001 Scaffold the Laravel 13 skeleton into the repository root:
  - Run `composer create-project laravel/laravel:^13.0` into the scratchpad.
  - Copy the result into the root **without overwriting** `README.md`, `CLAUDE.md`, `.claude/`,
    `.specify/` or `specs/`, and merge Laravel's `.gitignore` entries into the existing
    `.gitignore`.
  - In `composer.json`, set `"name": "marlonbochi/cms-content-sync"`, a one-line
    `"description"`, and `"require": {"php": "^8.3"}`.
  - Remove the frontend scaffolding: `package.json`, `vite.config.js`, `resources/js`,
    `resources/css`, `resources/views/welcome.blade.php`, and the welcome route in
    `routes/web.php` (leave `routes/web.php` with no routes).
- [X] T002 Install the API stack:
  - Run `php artisan install:api`. This adds `laravel/sanctum:^4.0`, `routes/api.php` and the
    `personal_access_tokens` migration.
  - Add the Pest dev dependencies `pestphp/pest:^4.7` and `pestphp/pest-plugin-laravel:^4.1`
    (Pest 5 requires PHP 8.4; see research R1 amendment) with Composer
    `config.platform.php = 8.3.0`, then
    run `vendor/bin/pest --init` to create `tests/Pest.php`.
  - Delete the skeleton's example tests in `tests/Unit/ExampleTest.php` and
    `tests/Feature/ExampleTest.php`.
- [X] T003 [P] Add Larastan: `composer require --dev larastan/larastan:^3.0` and create
  `phpstan.neon`:
  - `level: 8`
  - `paths: [app, config, database, routes, tests]`
  - include `vendor/larastan/larastan/extension.neon`
  - no baseline
- [X] T004 [P] Create `pint.json`:
  - preset `laravel`
  - rules `declare_strict_types: true`, `strict_comparison: true`, `ordered_imports`,
    `no_unused_imports`, `final_class: false`
  - Then run `vendor/bin/pint` once over the skeleton.
- [X] T005 [P] Create `config/cms.php` with these keys, each read from env with the default
  shown:
  - `webhook.username` (`CMS_WEBHOOK_USERNAME`, null)
  - `webhook.password` (`CMS_WEBHOOK_PASSWORD`, null)
  - `webhook.max_body_bytes` (`CMS_WEBHOOK_MAX_BODY_BYTES`, 1048576)
  - `webhook.rate_limit_per_minute` (`CMS_WEBHOOK_RATE_LIMIT`, 300)
  - `api.rate_limit_per_minute` (`API_RATE_LIMIT`, 120)
  - `queue.connection` (`CMS_QUEUE_CONNECTION`, `database`)
  - `queue.name` (`CMS_QUEUE_NAME`, `cms-events`)
  - `processing.tries` (5)
  - `processing.backoff` ([5, 30, 120, 600])
  - `batches.retention_days` (`CMS_BATCH_RETENTION_DAYS`, 30)
- [X] T006 [P] Create `docker/php/Dockerfile` as a multi-stage build:
  - Stage `vendor`: `composer:2` image, `composer install --no-dev --prefer-dist
    --no-scripts`.
  - Stage `app`: `php:8.4-fpm-alpine` with extensions `pdo_pgsql`, `pgsql`, `opcache`,
    `pcntl`, `intl`; copy `docker/php/php.ini` and `docker/php/opcache.ini`; non-root `www-data`
    user; `WORKDIR /var/www/html`.
  - Stage `dev`: extends `app`, adds Composer and `pcov` for coverage.
- [X] T007 [P] Create `docker/nginx/default.conf`:
  - `server_tokens off`
  - `client_max_body_size 2m` (the app enforces the 1 MiB limit so the 413 comes back as
    problem+json)
  - `fastcgi_pass app:9000`
  - security headers `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
    `Referrer-Policy: no-referrer`
  - deny dotfiles
- [X] T008 [P] Create `docker/postgres/init/01-create-test-db.sql` that creates the database
  `cms_sync_test` owned by the app user, next to the default `cms_sync`.
- [X] T009 Create `docker-compose.yml` (depends on T006–T008):
  - Services:
    - `postgres` (`postgres:18-alpine`): healthcheck `pg_isready`, named volume, init script
      mounted.
    - `app` (target `dev`): `depends_on` postgres `service_healthy`.
    - `worker`: `php artisan queue:work database --queue=cms-events --tries=5 --max-time=3600`.
    - `scheduler`: `php artisan schedule:work`.
    - `nginx`: publishes port `8080`.
  - The source is bind-mounted in dev.
  - Env comes from `.env`, and no secret is hard-coded.
- [X] T010 Create a `Makefile` with these targets:
  - `up`, `down`, `build`, `shell`
  - `migrate` (`php artisan migrate`)
  - `fresh` (`php artisan migrate:fresh --seed`)
  - `test` (`php artisan test --parallel` with `APP_ENV=testing`)
  - `coverage`
  - `lint` (`vendor/bin/pint --test && vendor/bin/phpstan analyse`)
  - `fix` (`vendor/bin/pint`)
  - `ci` (lint + test)
  - `token` (wrapper for `consumer:token`)
  - `perf`: runs `php artisan migrate:fresh --env=testing` then `PERF_SEED_ROWS=100000
    vendor/bin/pest tests/Performance` (the tests seed themselves; see T091).
  - `setup`: the single bootstrap command for SC-007. It copies `.env.example` to `.env` if
    missing, generates `APP_KEY`, runs build + up, waits for a healthy DB, migrates, and seeds
    `DemoSeeder` once T090 exists.

  All targets run inside the `app` container.
- [X] T011 Configure environments:
  - `.env.example`: `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_DATABASE=cms_sync`,
    `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=array`, every
    `config/cms.php` variable with placeholder values (`CMS_WEBHOOK_PASSWORD=change-me`), and
    `SANCTUM_TOKEN_EXPIRATION_MINUTES=129600`.
  - `.env.testing`: `DB_DATABASE=cms_sync_test`, `QUEUE_CONNECTION=database`, and test-only
    CMS credentials.
  - `phpunit.xml`: env `DB_DATABASE=cms_sync_test`, `QUEUE_CONNECTION=database`,
    `CACHE_STORE=array`.
  - `.env` stays git-ignored. `.env.testing` is committed and contains only test values
    (never real secrets); document this in the README.
- [X] T012 [P] Create `.github/workflows/ci.yml`:
  - Triggers: `push` and `pull_request`.
  - `permissions: contents: read`.
  - Concurrency group that cancels superseded runs.
  - Jobs:
    1. `lint`: PHP 8.4, cached Composer, `composer validate --strict`, `composer audit`,
       `vendor/bin/pint --test`.
    2. `static-analysis`: `vendor/bin/phpstan analyse --no-progress`.
    3. `tests`:
       - Matrix PHP `8.3` and `8.4`.
       - Service `postgres:18` with healthcheck; create `cms_sync_test`.
       - `php artisan migrate --env=testing`.
       - `vendor/bin/pest --parallel --coverage` (pcov). No `--min` yet: T096 adds the 85%
         gate once the suite exists, so the Phase 1 checkpoint (smoke test only) stays green.
       - `PERF_SEED_ROWS=20000 vendor/bin/pest tests/Performance` (serial; the tests seed
         themselves, see T091). Make this step conditional on
         `hashFiles('tests/Performance/**') != ''` until T091 exists.
    4. `openapi`: `npx @redocly/cli@1 lint specs/001-cms-content-sync/contracts/openapi.yaml`.
    5. `docker`: `docker build --target app -f docker/php/Dockerfile .` (no push).
- [X] T013 Boot the stack from scratch with `make setup`, confirm that `GET /up` returns 200
  through nginx on port 8080, add the smoke test `tests/Feature/HealthTest.php` (`GET /up` →
  200) so the suite is never empty, and commit the Phase 1 result.

**Checkpoint**: `make setup`, `make lint` and `make test` (one smoke test) work, and CI is green
on GitHub.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Schema, models, auth plumbing, error format, routing, and test harness shared by
every story.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T014 Create the test harness:
  - `tests/TestCase.php`: in `setUp()`, throw a `RuntimeException` unless
    `config('database.connections.pgsql.database')` matches `/_test(_test_\d+)?$/`. The optional
    suffix is needed because `--parallel` gives each process its own database
    (`cms_sync_test_test_1`, `_2`, …). Add `tests/Unit/TestDatabaseGuardTest.php`, where
    `cms_sync_test` and `cms_sync_test_test_3` pass, and `cms_sync` and `cms_sync_test_prod`
    throw (extract the check to a static `TestCase::assertTestDatabase(string $name)` so it is
    unit-testable).
  - `tests/Pest.php` defines the DB-reset strategy per directory, because Pest's `uses()->in()`
    applies a trait to every file in a directory:
    - `uses(TestCase::class, RefreshDatabase::class)->in('Feature')`: transactional, the
      default for all HTTP and processing tests.
    - `uses(TestCase::class)->in('Concurrency', 'Performance', 'Database')`: no
      `RefreshDatabase`. These files choose `DatabaseTruncation` / `DatabaseMigrations`
      themselves, because they need committed data or real migration runs.
    - `phpunit.xml` defines the testsuites `Unit`, `Feature`, `Database` and `Concurrency`.
      `tests/Performance` is excluded from the default run **only by not being listed in any
      testsuite**. Never exclude it with `<groups><exclude>`, because that would also filter
      the tests out when the directory is run explicitly, so `pest tests/Performance` would
      silently run zero tests. Set `failOnEmptyTestSuite="true"` in `phpunit.xml`, so an
      explicit run that finds no tests fails loudly.
    - Helpers:
      - `cmsAuthHeaders(?string $user = null, ?string $pass = null): array`
      - `actingAsRole(Role $role): User` (creates a user and a real Sanctum token, returns
        the user and sets the `Authorization` header)
      - `postBatch(array $events): TestResponse`
      - `drainQueue(): void` (runs `Artisan::call('queue:work', ['connection' => 'database',
        '--queue' => 'cms-events', '--stop-when-empty' => true])`)
      - `cmsEvent(string $type, string $id, int $version, array|stdClass|null $payload = null,
        string $ts = '2026-01-01T10:00:00Z'): array` (named `cmsEvent`, not `event`, to avoid
        redeclaring Laravel's global `event()` helper). An empty PHP array is encoded as a JSON
        **list** `[]`, so tests that need an empty object MUST pass `(object) []` (T032, T036,
        T051).
- [X] T015 [P] Create the backed string enums:
  - `app/Enums/Role.php`: `User = 'user'`, `Admin = 'admin'`.
  - `app/Enums/ContentStatus.php`: `Published = 'published'`, `Unpublished = 'unpublished'`,
    `Deleted = 'deleted'`.
  - `app/Enums/BatchStatus.php`: `Pending = 'pending'`, `Processed = 'processed'`,
    `Failed = 'failed'`.
- [X] T016 [P] Create the domain enums (no Illuminate imports):
  - `app/Domain/Cms/CmsEventType.php`: `Publish = 'publish'`, `Unpublish = 'unpublish'`,
    `Delete = 'delete'`, with method `targetStatus(): ContentStatus`.
  - `app/Domain/Cms/IgnoreReason.php`: `Duplicate = 'duplicate'`, `Stale = 'stale'`,
    `StaleAfterDelete = 'stale_after_delete'`.
- [X] T017 Adapt the users schema and model:
  - Edit `database/migrations/0001_01_01_000000_create_users_table.php`:
    - Remove `email_verified_at`, `password` and `remember_token`.
    - Drop the `password_reset_tokens` and `sessions` tables.
    - Add `role varchar(16) not null default 'user'` with CHECK `users_role_valid` —
      `CHECK (role IN ('user','admin'))` — and keep `users_email_unique`.
    - `down()` drops `users` only (the removed tables are never created, so nothing else to
      restore).
  - Update `app/Models/User.php`: `HasApiTokens`, `#[Fillable(['name','email','role'])]`
    (or `$fillable`), `role` cast to `Role`, `isAdmin(): bool`.
  - Update `database/factories/UserFactory.php` with states `user()` and `admin()`.
- [X] T018 [P] Create migration `database/migrations/2026_10_16_000100_create_contents_table.php`
  per data-model §1:
  - Columns:
    - `id` bigint identity
    - `cms_id varchar(255) not null`
    - `version bigint not null`
    - `status varchar(16) not null`
    - `payload jsonb null`
    - `cms_timestamp timestamptz not null`
    - `synced_at timestamptz not null`
    - `is_disabled boolean not null default false`
    - `disabled_at timestamptz null`
    - `disabled_by bigint null` FK → `users.id` `ON DELETE SET NULL`
    - timestamps (`timestamptz`)
  - Constraints (via `DB::statement` where the schema builder can't express them):
    - `contents_cms_id_unique` — `UNIQUE (cms_id)`
    - `contents_version_positive` — `CHECK (version > 0)`
    - `contents_status_valid` — `CHECK (status IN ('published','unpublished','deleted'))`
    - `contents_payload_matches_status` —
      `CHECK ((status = 'deleted' AND payload IS NULL) OR (status <> 'deleted' AND payload IS NOT NULL))`
    - `contents_payload_is_object` —
      `CHECK (payload IS NULL OR jsonb_typeof(payload) = 'object')`
  - Partial indexes:
    - `contents_user_feed_idx` on `(synced_at DESC, cms_id DESC) WHERE status = 'published'
      AND is_disabled = false`
    - `contents_admin_feed_idx` on `(synced_at DESC, cms_id DESC) WHERE status <> 'deleted'`
  - `down()` drops the table.
- [X] T019 [P] Create migration
  `database/migrations/2026_10_16_000200_create_event_batches_table.php` per data-model §2:
  - Columns:
    - `id uuid primary key`
    - `status varchar(16) not null default 'pending'`
    - `events jsonb not null`
    - `event_count smallint not null`
    - `attempts smallint not null default 0`
    - `applied_count smallint null`
    - `ignored_count smallint null`
    - `last_error varchar(1000) null`
    - `request_id varchar(64) null`
    - `received_at timestamptz not null`
    - `processed_at`, `failed_at`, `retried_at` — `timestamptz null`
    - `retried_by bigint null` FK → `users.id` `ON DELETE SET NULL`
    - timestamps
  - Constraints:
    - `event_batches_status_valid` — `CHECK (status IN ('pending','processed','failed'))`
    - `event_batches_event_count_range` — `CHECK (event_count BETWEEN 1 AND 100)`
    - `event_batches_events_is_array` — `CHECK (jsonb_typeof(events) = 'array')`
  - Index `event_batches_status_received_idx` on `(status, received_at DESC, id DESC)`.
  - `down()` drops the table.
- [X] T020 [P] Verify that the skeleton's `jobs`, `job_batches`, `failed_jobs`, `cache` and
  `cache_locks` migrations exist and use `timestamptz` where they define timestamps. Set
  `config/queue.php` default to `database` and `config/cache.php` default to `database`
  (`array` in testing).
- [X] T021 [P] Create `app/Models/Content.php`:
  - Casts: `status` → `ContentStatus`, `cms_timestamp` / `synced_at` / `disabled_at` →
    `immutable_datetime`, `is_disabled` → bool. Leave `payload` uncast for now: T040 adds the
    object-preserving `JsonObjectPayload` cast (keeps `{}` vs `[]`).
  - `getRouteKeyName(): 'cms_id'`.
  - `belongsTo disabledBy(User)`.
  - `$guarded = ['*']` (writes only via explicit attribute assignment in actions).
  - `database/factories/ContentFactory.php` with states `published()`, `unpublished()`,
    `deleted()` (payload null), `disabled()`, and `version(int)`.
- [X] T022 [P] Create `app/Models/EventBatch.php`:
  - `HasUuids` (UUID v7).
  - Casts: `status` → `BatchStatus`, datetimes immutable. Leave `events` uncast for now:
    **never** use the `array` cast here, because it turns `{}` payloads into `[]`. T040 adds the
    object-preserving `JsonObjectList` cast.
  - `belongsTo retriedBy(User)`.
  - `database/factories/EventBatchFactory.php` with states `pending()`, `processed()`,
    `failed()`.
- [X] T023 Create `app/Exceptions/ProblemDetailsRenderer.php` and register it in
  `bootstrap/app.php` → `withExceptions()`, for requests matching `api/*` or `cms/*` (and
  `shouldRenderJsonWhen` for both):
  - Render `application/problem+json` with `type: 'about:blank'`, `title` (HTTP reason
    phrase), `status`, `detail` and `instance` (request path).
  - `ValidationException` adds `errors`.
  - Map:
    - `AuthenticationException` → 401
    - `AuthorizationException` / `AccessDeniedHttpException` → 403
    - `ModelNotFoundException` / `NotFoundHttpException` → 404 with
      `detail: 'Content not found.'` or `'Event batch not found.'` (by model)
    - `MethodNotAllowedHttpException` → 405
    - `ThrottleRequestsException` → 429 (keep `Retry-After`)
    - `QueryException` / `PDOException` during webhook acceptance → 503
    - everything else → 500, with no trace unless `app.debug`
- [X] T024 Configure routing in `bootstrap/app.php`:
  - Implementation note: `/up` was later moved behind `auth:sanctum` (the brief requires every
    endpoint to be protected); it is registered in `then:` with `HealthController`, not `health:`.
  - `withRouting(api: routes/api.php, apiPrefix: 'api', health: '/up', then:` register
    `routes/cms.php` with **no** `api` middleware group except `throttle:cms-webhook`)`.
  - `routes/api.php` wraps everything in `Route::prefix('v1')->middleware(['auth:sanctum',
    'throttle:api'])`.
  - Register named rate limiters in `app/Providers/AppServiceProvider.php::boot()`:
    - `cms-webhook`: per IP, `config('cms.webhook.rate_limit_per_minute')`.
    - `api`: per token id, `config('cms.api.rate_limit_per_minute')`.
  - Disable `statefulApi`.
  - Add `use Illuminate\Foundation\Auth\Access\AuthorizesRequests;` to
    `app/Http/Controllers/Controller.php`. Since Laravel 11 the base controller is empty, and
    T069/T085 rely on `$this->authorize()`.
- [X] T025 Configure Sanctum in `config/sanctum.php`:
  - `'stateful' => []`, `'guard' => []`
  - `'expiration' => env('SANCTUM_TOKEN_EXPIRATION_MINUTES', 129600)`
  - `'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'cms_')`
- [X] T026 [P] Write `tests/Feature/Console/ConsumerCommandsTest.php`:
  - `consumer:create` creates a user with the role and rejects a duplicate email and an
    invalid role.
  - `consumer:token` prints a token starting with `cms_` exactly once, stores only its hash,
    and accepts `--name` and `--expires-days`.
  - `consumer:revoke {email} {--token-id=}|{--all}` deletes the tokens.
- [X] T027 Implement the console commands so T026 passes:
  - `app/Console/Commands/CreateConsumer.php` — `consumer:create {name} {email}
    {--role=user}`, validating email uniqueness and role ∈ `Role`.
  - `app/Console/Commands/IssueConsumerToken.php` — `consumer:token {email} {--name=default}
    {--expires-days=}`.
  - `app/Console/Commands/RevokeConsumerToken.php` — `consumer:revoke {email}
    {--token-id=} {--all}`.
- [X] T028 [P] Write `tests/Feature/Database/SchemaConstraintsTest.php`. Raw inserts MUST fail
  for each of the following:
  - `contents.version = 0`
  - a `deleted` row with a payload
  - a `published` row with a null payload
  - a payload that is a JSON array
  - an unknown status
  - a duplicate `cms_id`
  - an event batch with `event_count = 101`
  - `events` that are a JSON object
  - an unknown batch status
  - a user role `root`

  Also assert that the partial indexes exist, via `pg_indexes`. In a separate file,
  `tests/Database/MigrationRollbackTest.php` (outside the `RefreshDatabase` directory),
  assert that `migrate:reset` down to zero followed by `migrate` succeeds (migrations are
  reversible, constitution Principle V). Implementation note: it resets and re-migrates
  explicitly instead of using `DatabaseMigrations`, whose tear-down would leave the schema
  rolled back for later `RefreshDatabase` tests in the same parallel worker.

**Checkpoint**: migrations run on both databases, the factories work, a consumer token can be
issued, and errors render as problem+json.

---

## Phase 3: User Story 1 — CMS delivers content change events (Priority: P1) 🎯 MVP

**Goal**: An authenticated, validated, durably acknowledged webhook, plus asynchronous, atomic,
ordered, version-correct batch processing with tombstones and concurrency safety.

**Independent Test**: Send batches with CMS credentials, run `drainQueue()`, and assert the
`contents` rows (status, version, payload) without using the consumer API.

### Tests for User Story 1 ⚠️ write first, they must fail

- [X] T029 [P] [US1] Write `tests/Unit/Domain/Cms/EventPrecedenceTest.php` with a Pest dataset
  covering every row of the data-model §1 state machine:
  - no snapshot + publish/unpublish/delete → apply with the target status
  - higher version → apply
  - equal version → ignore `duplicate`
  - lower version → ignore `stale`
  - lower or equal version when the current status is `deleted` → ignore `stale_after_delete`
    (equal → `duplicate`)
  - higher version after delete → apply (re-create)
  - timestamps in the opposite order to versions never change the decision
- [X] T030 [P] [US1] Write `tests/Unit/Domain/Cms/EventPrecedencePermutationTest.php` (SC-001):
  - For 200 random seeds, build a set of events for 1–3 ids with random types and versions
    (with gaps), shuffle them, and duplicate a random subset.
  - Fold them through an in-memory reducer that uses `EventPrecedence`.
  - Assert that each id's final `(status, version)` equals the result of applying only its
    highest-version event.
- [X] T031 [P] [US1] Write `tests/Feature/Webhook/CmsAuthenticationTest.php`. Each of these
  returns `401` with `WWW-Authenticate: Basic realm="cms-webhook"`, a problem+json body, and no
  `event_batches` row:
  - no `Authorization` header
  - wrong username
  - wrong password
  - malformed Base64
  - a `Bearer` token from a valid admin
  - server credentials configured empty (fail closed)

  Valid credentials → `202`.
- [X] T032 [P] [US1] Write `tests/Feature/Webhook/BatchValidationTest.php` as a dataset of
  invalid batches. Each returns `422`, and zero rows exist in `event_batches` and `jobs`.
  Batch-level errors (empty list, more than 100 events, JSON object root, malformed JSON) use
  the key `events`; event-level errors use `events.{i}.{field}`:
  - empty list
  - 101 events
  - JSON object root
  - malformed JSON
  - missing `id`; empty `id`; `id` longer than 255; `id` with a space or `/` (pattern
    `^[A-Za-z0-9][A-Za-z0-9._:-]*$`)
  - `version` given as `"2"`, `2.5`, `0`, `-1`, or `9223372036854775808`
  - `timestamp` given as `"yesterday"` or `"2026-01-01"` (no time or offset)
  - unknown `type`
  - publish without `payload`; unpublish with `payload: "text"`; publish with `payload: [1,2]`
  - a valid batch whose **third** event is invalid

  Also assert:
  - `payload: {}` is accepted.
  - A delete with a payload is accepted.
  - `Content-Type: text/plain` → `415`.
  - A body over `cms.webhook.max_body_bytes` → `413`.
- [X] T033 [P] [US1] Write `tests/Feature/Webhook/BatchAcceptanceTest.php`:
  - `202` body matches `BatchAcknowledgement` (`batch_id` uuid, `status: pending`,
    `event_count`, `received_at`).
  - The `event_batches` row holds the events exactly as sent, in order.
  - Exactly one `jobs` row on queue `cms-events`.
  - Atomic hand-off: bind a queue dispatcher that throws after the batch insert → `503`, and
    neither an `event_batches` nor a `jobs` row remains.
  - Database unavailable → `503`.
- [X] T034 [P] [US1] Write `tests/Feature/Processing/EventProcessingTest.php` with one named
  test per spec US1 acceptance scenario 1–12, each going through the webhook and then
  `drainQueue()`:
  - publish creates
  - duplicate ignored
  - stale ignored
  - **unpublish v4 after published v3 (never-seen version) stores the v4 content as
    unpublished**
  - first event unpublish v7
  - delete v5 tombstone with null payload
  - late publish v4 after delete ignored
  - first event delete v3 then publish v2 ignored
  - in-batch order publish v2 → unpublish v3
  - in-batch unpublish v3 → publish v2
  - re-create after delete keeps `is_disabled`
  - version gap 3 → 9 applied
- [X] T035 [P] [US1] Write `tests/Feature/Processing/BatchAtomicityTest.php`:
  - Inject a failure while applying the 3rd of 5 events (e.g. a listener on the
    `ContentWritten` test hook, or bind a decorator around the writer that throws). Assert that
    no `contents` row changed, the batch is still `pending`, and `attempts` = 1.
  - Assert that after the fault is removed and the job is retried, all 5 events are applied.
- [X] T036 [P] [US1] Write `tests/Feature/Processing/PayloadFidelityTest.php`. Each of these
  round-trips byte-equivalent webhook → inbox (`event_batches.events`) → worker → `contents`
  and is read back from the database as JSON:
  - `{}` (top-level empty payload: the batch MUST be processed, not fail the
    `contents_payload_is_object` CHECK)
  - `{"tags": {}, "items": []}` (nested empty object vs empty list)
  - nested objects
  - arrays of objects
  - unicode / emoji
  - large integers within the int64 range
  - floats
  - `null` values inside the payload
- [X] T037 [P] [US1] Write `tests/Concurrency/ConcurrentProcessingTest.php` (Pest group
  `concurrency`):
  - Put it in `tests/Concurrency/` (not `Feature/`) and use `DatabaseTruncation`. Child
    processes use their own connections and can only see **committed** data, so the seeded
    batches must be committed before the processes start.
  - Run two `Symfony\Component\Process\Process` workers that each call an artisan test
    command, processing batches `[x v2, y v2]` and `[y v3, x v3]` at the same time, 50
    iterations.
  - Pass each child `APP_ENV=testing` and `DB_DATABASE=` the **current** connection's database
    (`config('database.connections.pgsql.database')`), because under `--parallel` it is
    `cms_sync_test_test_N`, not the `cms_sync_test` from `.env.testing`. Assert in the test that
    the children report the same database name (the command prints it with `-v`).
  - Assert that no deadlock exception occurs, and that the final rows are `x v3` and `y v3`.

### Implementation for User Story 1

- [X] T038 [P] [US1] Create the readonly value objects in `app/Domain/Cms/`:
  - `CmsEvent.php`: `id`, `version` (int), `timestamp` (`DateTimeImmutable`), `type`
    (`CmsEventType`), `?payload` (`stdClass|null`), with named constructor `fromValidated(object
    $raw)`.
  - `ContentSnapshot.php`: `version`, `status`.
  - `EventDecision.php`: `apply` bool, `?IgnoreReason`, `?ContentStatus newStatus`, with
    factories `apply()` and `ignore()`.
- [X] T039 [US1] Implement `app/Domain/Cms/EventPrecedence.php::decide(?ContentSnapshot
  $current, CmsEvent $event): EventDecision`, a pure function per research R6, so that T029 and
  T030 pass.
- [X] T040 [P] [US1] Implement `app/Support/Json/JsonPayload.php`:
  - `decodeBody(string $json): mixed` uses `json_decode(..., false, 512,
    JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING)`.
  - `encode(stdClass|array $value): string` preserves `{}`.
  - Add the Eloquent casts:
    - `app/Casts/JsonObjectPayload.php` (`stdClass|null` ⇄ jsonb object), applied to
      `Content::$casts['payload']`.
    - `app/Casts/JsonObjectList.php` (`list<stdClass>` ⇄ jsonb array, decoding with objects
      preserved), applied to `EventBatch::$casts['events']`.
  - Add a unit test `tests/Unit/Support/JsonPayloadTest.php`: `{}` stays `{}`, `[]` stays
    `[]`, nested empty objects survive an encode/decode round-trip, and big integers are
    decoded as strings.
- [X] T041 [US1] Implement `app/Http/Middleware/AuthenticateCms.php` and alias it as `auth.cms`
  in `bootstrap/app.php`:
  - Parse `Authorization: Basic`.
  - Compare both the username and the password with `hash_equals` against `config('cms.webhook.*')`,
    evaluating both before branching.
  - Empty config → reject and log `critical`.
  - Throw `Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException('Basic
    realm="cms-webhook"', 'Authentication is required.')`. It carries the `WWW-Authenticate`
    header itself, so T031 passes in US1 without waiting for T064. Make sure
    `ProblemDetailsRenderer` (T023) keeps the exception's headers.
- [X] T042 [US1] Implement `app/Http/Requests/Cms/StoreEventBatchRequest.php`:
  - Decode the raw body **twice**, because Laravel's validator only traverses arrays
    (`Arr::dot`), not `stdClass`:
    1. An associative decode (`json_decode(..., true, 512, JSON_THROW_ON_ERROR |
       JSON_BIGINT_AS_STRING)`) is merged as `events` in `prepareForValidation` and drives all
       the standard rules.
    2. An object decode via `JsonPayload::decodeBody` is kept in a private property and exposed
       as `rawEvents(): list<stdClass>`. It is the only source used to check object-ness and to
       store the payloads.

    Malformed JSON → 422 with error key `events`. A non-list root → 422.
  - Rules per research R9:
    - `events` → `required|list|min:1|max:100`
    - `events.*.id` → `required|string|min:1|max:255|regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/`
    - `events.*.version` → strict integer between 1 and `PHP_INT_MAX`, via a custom rule
      `app/Rules/StrictPositiveInteger.php` that rejects strings and floats
    - `events.*.timestamp` → required, RFC 3339 with offset, via a custom rule
      `app/Rules/Rfc3339DateTime.php`
    - `events.*.type` → `required|Rule::enum(CmsEventType::class)`
    - `events.*.payload` → `required_if:events.*.type,publish,unpublish`, plus an `after()`
      validation hook that, for each index `i` whose type is publish/unpublish, checks
      `rawEvents()[i]->payload instanceof stdClass`, adding the error `events.{i}.payload` ("The
      payload must be a JSON object.") otherwise. Implement the check in
      `app/Rules/JsonObject.php` (invokable with the raw value), so it is unit-testable.
  - Return `validatedEvents(): list<CmsEvent>`, built from `rawEvents()`.
  - Also add middleware `app/Http/Middleware/EnsureJsonBody.php`:
    - `Content-Type` not `application/json` → 415.
    - `Content-Length` or actual body over the configured max → 413.
- [X] T043 [US1] Implement `app/Actions/Cms/AcceptEventBatch.php`. In
  `DB::transaction(...)`:
  - Create the `EventBatch` (`status = pending`, `events` = the **validated raw request body**,
    stored as-is through the `JsonObjectList` cast so PHP never re-encodes it, `event_count`,
    `received_at = now()`, `request_id`). Implementation note: this replaces "object-decoded
    events" for even higher fidelity (exact numbers; see research R8).
  - Dispatch `ProcessEventBatchJob` with `->onConnection(config('cms.queue.connection'))
    ->onQueue(config('cms.queue.name'))`, **not** `afterCommit` (research R2; add a code comment
    explaining why).
  - Return the batch.
- [X] T044 [US1] Implement the invokable `app/Http/Controllers/Cms/EventBatchController.php`
  (`StoreEventBatchRequest` → `AcceptEventBatch` → `202` JSON `{data: {batch_id, status,
  event_count, received_at}}`). Register the route `POST /cms/events` in `routes/cms.php` with
  middleware `['auth.cms', EnsureJsonBody::class]`. `throttle:cms-webhook` is applied once,
  at the route-file level in T024; don't repeat it here.
- [X] T045 [US1] Implement `app/Actions/Cms/ProcessEventBatch.php::handle(EventBatch $batch):
  BatchResult` in `DB::transaction(..., attempts: 3)`:
  1. Re-select the batch `lockForUpdate()` and return early if it is `processed`.
  2. For each distinct `cms_id` sorted ascending, run `SELECT
     pg_advisory_xact_lock(hashtextextended(?, 0))`.
  3. Build each `CmsEvent` from the object-preserving `events` list (`CmsEvent::fromValidated`)
     and never from an associative array.
  4. For each event in order: load the snapshot (`select version, status from contents where
     cms_id = ?`), call `EventPrecedence::decide`, and on apply write **only** CMS-owned columns
     (`version`, `status`, `payload` — null for delete, `cms_timestamp`, `synced_at = now()`)
     using insert-or-update with guard `WHERE contents.version < :incoming`. The payload is
     copied inside PostgreSQL from the inbox (`events -> i -> 'payload'`), so it never
     round-trips through PHP. Never touch
     `is_disabled`, `disabled_at` or `disabled_by`.
  5. Update the batch to `processed` with `applied_count`, `ignored_count` and
     `processed_at`.

  Also create the readonly `app/Domain/Cms/BatchResult.php`.
- [X] T046 [US1] Implement `app/Jobs/ProcessEventBatchJob.php`:
  - `ShouldQueue`, constructor `public readonly string $batchId`.
  - `tries(): int` and `backoff(): array` methods read `config('cms.processing.*')`. These
    must be methods: PHP property defaults can't call functions.
  - `handle(ProcessEventBatch)` increments `attempts` in its own short transaction first, then
    processes.
  - `failed(Throwable)` sets `status = failed`, `failed_at`, and a sanitized `last_error`
    (exception class + message truncated to 1000 chars; never event payloads).
- [X] T047 [US1] Add the artisan test-support command
  `app/Console/Commands/ProcessBatchOnce.php` (`cms:batches:process {id}`, hidden; prints the
  connected database name when run with `-v`), used by T037 to run `ProcessEventBatch` in a
  separate process, and the sample webhook body
  `docs/examples/batch.json` (the brief's 3-event example).
- [X] T048 [US1] Run the whole US1 suite plus `make lint` until green, and commit.

**Checkpoint**: The MVP ingestion pipeline is complete. Every mandatory processing scenario
(duplicates, out-of-order, unpublish edge case, delete protection) passes.

---

## Phase 4: User Story 2 — Regular user reads published content (Priority: P1)

**Goal**: An authenticated user lists (cursor or page pagination) and retrieves only
published, enabled content.

**Independent Test**: Seed contents in every state with factories, call the API with a `user`
token, and assert what is visible, the response shape, and pagination.

### Tests for User Story 2 ⚠️ write first, they must fail

- [X] T049 [P] [US2] Write `tests/Feature/Api/UserContentVisibilityTest.php`:
  - The list contains only `published` + `is_disabled = false`.
  - `show` returns `200` for a visible item.
  - `show` returns `404` problem+json for unpublished, deleted, disabled and unknown ids, with
    bodies that are identical **except `instance`** (which echoes the request path). Compare
    after removing `instance`.
  - An item disappears after a newer unpublish or delete is processed via the webhook
    (scenario 4).
- [X] T050 [P] [US2] Write `tests/Feature/Api/ContentPaginationTest.php`:
  - Default cursor mode: 40 items → pages 15/15/10, no duplicates or gaps, order `synced_at DESC,
    cms_id DESC` (including tie-break on equal `synced_at`).
  - `meta.pagination = 'cursor'`, `next_cursor` null on the last page.
  - An item updated mid-paging does not cause skips or repeats.
  - `?page=2&per_page=15` → items 16–30, `meta.pagination = 'page'`, `total = 40`,
    `last_page = 3`.
  - A page past the end → empty `data` with valid meta.
  - `per_page` of 0 or 101 → 422.
  - `cursor` + `page` together → 422.
  - A tampered cursor → 422.
- [X] T051 [P] [US2] Write `tests/Feature/Api/ContentResourceShapeTest.php`:
  - A user's item has exactly the keys `id, version, content, cms_updated_at, synced_at`.
  - `id` equals `cms_id`, and the internal numeric id is never exposed.
  - `content: {}` serializes as an object.
  - Timestamps are RFC 3339 UTC.

### Implementation for User Story 2

- [X] T052 [US2] Add `scopeVisibleTo(Builder $q, User $user)` to `app/Models/Content.php`.
  It branches on `$user->can('admin')` (the gate from T062 is the single source of role truth,
  constitution Principle II): users get `status = 'published' AND is_disabled = false`, admins
  get `status <> 'deleted'`. Until T062 lands, define the `admin` gate minimally in
  `AppServiceProvider` as part of this task. Also add
  `scopeFeedOrder` (`orderByDesc('synced_at')->orderByDesc('cms_id')`).
- [X] T053 [US2] Implement `app/Http/Requests/Api/V1/ListContentsRequest.php`:
  - Rules: `per_page` → `integer|between:1,100`; `cursor` → `string|prohibits:page`; `page` →
    `integer|min:1|prohibits:cursor`.
  - `perPage(): int` (default 15) and `usesPageNumbers(): bool`.
- [X] T054 [P] [US2] Implement `app/Http/Resources/PaginatedCollection.php` (or
  `paginationInformation()` on the collection), which shapes `meta` / `links` exactly as the
  contract's `CursorMeta` / `NumberedMeta` / `Links`. Invalid cursors detected via
  `Cursor::fromEncoded()` returning null → throw `ValidationException` on `cursor`.
- [X] T055 [P] [US2] Implement `app/Http/Resources/ContentResource.php` with the user view
  `id` (cms_id), `version`, `content` (payload object), `cms_updated_at`, `synced_at`.
- [X] T056 [US2] Implement `app/Http/Controllers/Api/V1/ContentController.php`:
  - `index(ListContentsRequest)`: `Content::visibleTo($user)->feedOrder()` →
    `cursorPaginate` or `paginate`.
  - `show(Request, string $contentId)`: resolve through `visibleTo($user)->where('cms_id',
    $contentId)->firstOrFail()`. No implicit model binding, so hidden equals not found.
  - Register `GET /contents` and `GET /contents/{contentId}` in `routes/api.php`, with
    `{contentId}` constrained to `[A-Za-z0-9][A-Za-z0-9._:-]*`.
- [X] T057 [US2] Run the US2 suite plus lint and commit.

**Checkpoint**: Users can browse synchronized content. US1 + US2 = end-to-end MVP.

---

## Phase 5: User Story 4 — Access is restricted per actor (Priority: P1)

**Goal**: Strict separation between the CMS and consumers, valid-token enforcement, and role
gating that never leaks whether a resource exists.

**Independent Test**: Run the access matrix: every endpoint × every actor yields the specified
401/403/2xx.

### Tests for User Story 4 ⚠️ write first, they must fail

- [X] T058 [P] [US4] Write `tests/Feature/Security/ActorSeparationTest.php`:
  - CMS Basic credentials on `GET /api/v1/contents` and `GET /api/v1/contents/x` → `401` with
    `WWW-Authenticate: Bearer`.
  - A user or admin bearer token on `POST /cms/events` → `401`, with no batch stored.
  - Anonymous requests to every route → `401` problem+json.
  - Rate limiting: with `cms.api.rate_limit_per_minute` and
    `cms.webhook.rate_limit_per_minute` set to 2 in the test, the 3rd request → `429`
    problem+json with a `Retry-After` header, for both a consumer route and the webhook.
- [X] T059 [P] [US4] Write `tests/Feature/Security/TokenValidityTest.php`. Each of these
  returns `401`:
  - a revoked token
  - an expired token (`expires_at` in the past)
  - a token of a deleted user
  - a malformed token
  - a token from another table id
  - a correct token with the wrong secret part
- [X] T060 [P] [US4] Write `tests/Feature/Security/AccessMatrixTest.php`:
  - A Pest dataset `endpoint × actor → expected status` for the actors anonymous, cms, user and
    admin.
  - Initial rows cover `POST /cms/events`, `GET /api/v1/contents` and
    `GET /api/v1/contents/{id}`.
  - Later phases append rows (T066, T067, T081).
- [X] T061 [P] [US4] Write `tests/Feature/Security/NoCmsWritesTest.php`:
  - `POST`/`PATCH`/`PUT`/`DELETE` on `/api/v1/contents` and `/api/v1/contents/{id}` → `405`
    problem+json, with the DB unchanged.
  - Iterate `Route::getRoutes()` and assert that no route under `api/v1` other than
    `contents/{id}/disabled` and `admin/event-batches/{id}/retry` accepts write methods.

### Implementation for User Story 4

- [X] T062 [US4] Define the authorization rules in Laravel policies and gates (constitution
  Principle II: authorization goes through policies/gates):
  - Gate `admin` in `AppServiceProvider::boot()` → `$user->isAdmin() ? Response::allow() :
    Response::deny('This action requires the admin role.')`. The deny message matches the
    contract's 403 example. `User::isAdmin()` may be called **only** from this gate; every
    other role decision uses `->can('admin')` / `Gate::allows('admin')` (T052, T070). T089
    enforces this.
  - Gate `filter-contents` → admin only (used by T071).
  - `app/Policies/ContentPolicy.php`:
    - `viewAny` and `view` → true for any authenticated consumer (row visibility is enforced
      by `scopeVisibleTo`).
    - `manageOverride` → `Gate::allows('admin')`.
  - `app/Policies/EventBatchPolicy.php`: `viewAny`, `view` and `retry` →
    `Gate::allows('admin')`.

  Add `tests/Feature/Policies/PolicyTest.php` with a dataset of role × ability. It lives in
  `Feature/` because gates need the booted application container.
- [X] T063 [US4] Implement `app/Http/Middleware/EnsureRole.php` (`role:admin`) as a thin
  adapter over the gate: `Gate::authorize($role)`, which throws `AuthorizationException('This
  action requires the admin role.')`. It contains **no** role comparison of its own. Alias it in
  `bootstrap/app.php` and add it to `$middleware->priority` **before** `SubstituteBindings`,
  so a non-admin gets 403 before any id lookup. Controllers still call policies (T069, T085) as
  the authoritative per-resource check.
- [X] T064 [US4] In `ProblemDetailsRenderer`, add the `WWW-Authenticate: Bearer` header on 401
  for `api/*` (Basic for `cms/*`) and the 405 mapping. Make sure `auth:sanctum` rejects Basic
  headers without falling back to any other guard (`config/auth.php` default guard has no
  session fallback for API).
- [X] T065 [US4] Run the US4 suite plus lint and commit.

**Checkpoint**: Security matrix green for all current endpoints.

---

## Phase 6: User Story 3 — Administrator reviews content and manages local overrides (Priority: P2)

**Goal**: Admins see published, unpublished and disabled content, can filter it, and can
disable or re-enable it locally. The override survives CMS events.

**Independent Test**: As admin, disable a published item, confirm the user can't see it, send
a newer publish via the webhook, confirm it stays disabled, re-enable it, and confirm the user
sees it again.

### Tests for User Story 3 ⚠️ write first, they must fail

- [X] T066 [P] [US3] Write `tests/Feature/Api/AdminContentVisibilityTest.php`:
  - The admin list includes published, unpublished and disabled items, and excludes deleted
    ones.
  - Filters `?status=unpublished` and `?disabled=true` (combined too).
  - A user sending `status` or `disabled` → `403`.
  - The admin item shape adds `status` and `local_override{disabled, changed_at,
    changed_by}`.
  - Admin `show` of an unpublished item → 200, of a deleted item → 404.

  Also append the admin rows for these endpoints to the T060 dataset.
- [X] T067 [P] [US3] Write `tests/Feature/Api/LocalOverrideTest.php`:
  - `PUT /contents/{id}/disabled` → 200, user list hides the item, admin still sees it, CMS
    columns are byte-identical before and after.
  - A second PUT is idempotent (same `changed_at`).
  - A newer `publish` and a newer `unpublish` via the webhook keep `is_disabled = true`.
  - Delete followed by a higher-version re-create keeps `is_disabled = true`.
  - `DELETE /contents/{id}/disabled` re-enables; it is visible to the user only if published.
  - Admin on a deleted or unknown id → 404.
  - User on an existing **and** an unknown id → 403, with bodies identical except `instance`.
  - CMS credentials → 401.

  Also append the `PUT` and `DELETE /api/v1/contents/{id}/disabled` rows (anonymous, cms, user,
  admin) to the T060 dataset.

### Implementation for User Story 3

- [X] T068 [US3] Implement `app/Actions/Content/SetContentDisabled.php::handle(Content $content,
  bool $disabled, User $actor): Content`:
  - Inside a transaction, `lockForUpdate`.
  - No-op if unchanged.
  - Otherwise write only `is_disabled`, `disabled_at` (now or null) and `disabled_by`.
- [X] T069 [US3] Implement `app/Http/Controllers/Api/V1/ContentDisabledController.php`
  (`update` → disable, `destroy` → enable). Resolve via
  `Content::visibleTo($admin)->where('cms_id', …)->firstOrFail()`, then call
  `$this->authorize('manageOverride', $content)` (`ContentPolicy`), and return the admin
  `ContentResource`. Add the routes `PUT|DELETE /contents/{contentId}/disabled` with
  `role:admin` in `routes/api.php`.
- [X] T070 [US3] Extend `ContentResource` with `mergeWhen($request->user()->can('admin'), …)` for
  `status` and `local_override` (`disabled`, `changed_at` ← `disabled_at`, `changed_by` ←
  `disabled_by`).
- [X] T071 [US3] Extend `ListContentsRequest` with `status` (`in:published,unpublished`) and
  `disabled` (`boolean`). When either is present, `authorize()` returns
  `Gate::allows('filter-contents')`, so a non-admin gets 403.
  Apply the filters in `ContentController::index`.
- [X] T072 [US3] Run the US3 suite plus lint and commit.

**Checkpoint**: Local overrides are complete and independent of CMS state.

---

## Phase 7: User Story 5 — Operators can trace what happened to every event (Priority: P3)

**Goal**: Structured logs for the batch and event lifecycle, with no secrets or payloads.

**Independent Test**: Capture log records during mixed batches and assert the events,
context keys, and hygiene.

### Tests for User Story 5 ⚠️ write first, they must fail

- [X] T073 [P] [US5] Write `tests/Feature/Observability/EventLoggingTest.php`, attaching a
  Monolog `TestHandler` to the `cms` channel. Assert:
  - `cms.batch.received` with `batch_id`, `event_count`, `request_id`
  - `cms.event.applied` and `cms.event.ignored` (with `reason` duplicate/stale) with
    `batch_id`, `attempt`, `content_id`, `type`, `incoming_version`, `stored_version`,
    `previous_status`, `new_status`
  - `cms.batch.processed` with `applied`, `ignored`, `duration_ms`
  - `cms.batch.rejected` with the field paths only
  - `cms.batch.attempt_failed` and `cms.batch.failed` with `attempt`
  - `content.override.changed` with `actor_id`, `content_id`, `disabled`
- [X] T074 [P] [US5] Write `tests/Feature/Observability/LogHygieneTest.php`:
  - Run the quickstart scenarios S1–S11 end to end with a capturing handler on **all**
    channels.
  - Assert that no record contains the CMS password, the Basic header value, any bearer token
    or `cms_` token, or any payload value (e.g. the sentinel string `"SECRET-PAYLOAD-SENTINEL"`
    placed in payloads).

### Implementation for User Story 5

- [X] T075 [US5] Add the `cms` channel to `config/logging.php`: driver `monolog`, handler
  `StreamHandler` to `env('LOG_CMS_STREAM', 'php://stderr')`, formatter `JsonFormatter`, and
  level `env('LOG_CMS_LEVEL', 'info')`. Document `LOG_CMS_*` in `.env.example`.
- [X] T076 [P] [US5] Implement `app/Http/Middleware/AssignRequestId.php`:
  - Accept an incoming `X-Request-Id` if it matches `^[A-Za-z0-9-]{1,64}$`, otherwise generate a
    UUID v7.
  - Add it via `Log::shareContext`. Implementation note: Laravel's `Context` is used instead;
    it is attached to every log record and also travels with queued jobs to the worker.
  - Echo it in the response header.
  - Register it globally in `bootstrap/app.php`.
- [X] T077 [US5] Add the logging statements:
  - `AcceptEventBatch` → `cms.batch.received`.
  - `StoreEventBatchRequest::failedValidation` → `cms.batch.rejected`, with
    `array_keys($errors)` only.
  - `ProcessEventBatch` → per-event `cms.event.applied` / `cms.event.ignored`, and
    `cms.batch.processed` with `hrtime` duration.
  - Use `Log::channel('cms')->withContext(['batch_id' => …, 'attempt' => …])`.
- [X] T078 [US5] Add logging to the failure paths in `ProcessEventBatchJob`:
  - In `handle`, catch, log `cms.batch.attempt_failed`, and rethrow.
  - In `failed()`, log `cms.batch.failed`.
  - In `SetContentDisabled`, log `content.override.changed`.
  - Extract the sanitized error formatter to `app/Support/Logging/SafeError.php` (class +
    message, 1000 chars). Implementation note: for `QueryException` it keeps only the driver
    message and drops the SQL with bindings (the inbox INSERT binds the whole batch); a custom
    `QueryException` reporter in `bootstrap/app.php` does the same for framework error logs,
    and `zend.exception_ignore_args` keeps arguments out of traces.
- [X] T079 [US5] Run the US5 suite plus lint and commit.

**Checkpoint**: Every event outcome is traceable by `batch_id` and `request_id`.

---

## Phase 8: User Story 6 — Administrators and operators recover failed batches (Priority: P3)

**Goal**: Failed batches are visible and re-processable via the admin API and the CLI, and the
CLI can discard them. Payloads are never exposed.

**Independent Test**: Force permanent failure, list as admin, retry after removing the fault,
and verify `processed` and correct content.

### Tests for User Story 6 ⚠️ write first, they must fail

- [X] T080 [P] [US6] Write `tests/Feature/Processing/RetryAndRecoveryTest.php` (SC-005a):
  - Inject a persistent failure and drain the queue through all 5 attempts (fake the
    backoff to 0 in the test config).
  - Assert that the batch is `failed` with `failed_at`, `attempts = 5` and a sanitized
    `last_error`, and that no content changed.
  - Remove the fault, call `RetryEventBatch`, drain the queue, and assert `processed` with
    correct content.
  - Assert that a batch whose events are stale by the time of the retry ends `processed`, with
    the events counted as ignored.
- [X] T081 [P] [US6] Write `tests/Feature/Admin/EventBatchApiTest.php`:
  - `GET /api/v1/admin/event-batches`: ordering `received_at DESC`, `?status=failed` filter,
    cursor meta, invalid status → 422.
  - `GET …/{id}`: the event summary has `position, id, type, version, timestamp` and **no**
    `payload` key anywhere in the response JSON (recursive assertion).
  - `POST …/{id}/retry`: on a failed batch → 202, status `pending`, `attempts = 0`,
    `retried_by` = admin id, one new job; on a pending or processed batch → 409; on an unknown
    or non-uuid id → 404.
  - A user on every batch endpoint → 403.

  Also append these rows to the T060 matrix.
- [X] T082 [P] [US6] Write `tests/Feature/Console/BatchCommandsTest.php`:
  - `cms:batches:failed` prints a table of failed batches.
  - `cms:batches:retry {id}` and `--all` move batches to pending and queue jobs, with
    `retried_by` null.
  - `cms:batches:discard {id}` requires a failed batch, asks for confirmation (`--force`
    skips it), deletes the row, and logs `cms.batch.discarded`.
  - A non-failed id → error exit code.

### Implementation for User Story 6

- [X] T083 [US6] Implement `app/Actions/Cms/RetryEventBatch.php::handle(EventBatch $batch, ?User
  $actor): EventBatch`. In a transaction:
  - `lockForUpdate`.
  - If the status is not `failed`, throw `app/Exceptions/BatchNotRetryableException.php`
    (rendered as 409 `Only failed batches can be retried.`).
  - Set `status = pending`, `attempts = 0`, `failed_at = null`, `retried_at = now()`,
    `retried_by = $actor?->id`.
  - Dispatch `ProcessEventBatchJob` on the configured connection and queue inside the same
    transaction.
  - Log `cms.batch.retry_requested` with `origin: api|cli`.
- [X] T084 [P] [US6] Implement `app/Http/Resources/EventBatchResource.php` (the contract's
  `EventBatch` fields) and `app/Http/Resources/EventBatchDetailResource.php` (adds `events[]`
  summary mapped from the stored events: `position`, `id`, `type`, `version`, `timestamp` —
  never `payload`).
- [X] T085 [US6] Implement `app/Http/Requests/Api/V1/ListEventBatchesRequest.php` (`status` →
  `Rule::enum(BatchStatus::class)`, `per_page` 1–100, `cursor`) and
  `app/Http/Controllers/Api/V1/Admin/EventBatchController.php`:
  - `index` uses the `status`/`received_at DESC, id DESC` order with cursor pagination.
  - `show` and `retry` use `whereUuid` constraints and call
    `$this->authorize('view'|'retry', $batch)` (`EventBatchPolicy`); `index` calls
    `$this->authorize('viewAny', EventBatch::class)`.
  - Register `GET /admin/event-batches`, `GET /admin/event-batches/{batch}` and
    `POST /admin/event-batches/{batch}/retry` under `role:admin` in `routes/api.php`.
- [X] T086 [US6] Implement the console commands in `app/Console/Commands/`:
  - `ListFailedBatches.php` (`cms:batches:failed`)
  - `RetryFailedBatches.php` (`cms:batches:retry {id?} {--all}`), via `RetryEventBatch` with
    actor null
  - `DiscardFailedBatch.php` (`cms:batches:discard {id} {--force}`)
- [X] T087 [US6] Make `EventBatch` `MassPrunable`: `prunable()` returns `status = processed AND
  processed_at < now() - config('cms.batches.retention_days') days`. In `routes/console.php`,
  schedule `model:prune --model=App\\Models\\EventBatch` daily and `sanctum:prune-expired
  --hours=24` daily. Add a test in `tests/Feature/Console/PruningTest.php` showing that failed
  and recent batches are kept.
- [X] T088 [US6] Run the US6 suite plus lint and commit.

**Checkpoint**: The "no acknowledged batch is lost" guarantee has a supported recovery path.

---

## Phase 9: Polish & Cross-Cutting Concerns

**Purpose**: Architecture guarantees, performance evidence, contract conformance, and the
deliverable documentation.

- [X] T089 [P] Write `tests/Arch/ArchitectureTest.php` (Pest arch):
  - `app` uses strict types.
  - `App\Domain` does not use `Illuminate` or `App\Models`.
  - Controllers do not use `Illuminate\Support\Facades\DB`.
  - `App\Enums` are enums.
  - No `dd`, `dump` or `var_dump` anywhere.
  - Actions are final classes.
  - `isAdmin()` is called only from `App\Providers\AppServiceProvider` (the `admin` gate).
    Pest arch expectations work on class/namespace dependencies, not method calls, so
    implement this one as a plain Pest test in the same file: it scans `app/**/*.php` for
    `->isAdmin(` and allows matches only in `app/Providers/AppServiceProvider.php`.
- [X] T090 [P] Create `database/seeders/DemoSeeder.php` (admin + user accounts, ~30 contents in
  mixed states; prints nothing secret) and `database/seeders/PerformanceSeeder.php` (100k
  contents via chunked `insert`, realistic status mix 80/15/5 and 2% disabled).
- [X] T091 Write `tests/Performance/PerformanceTest.php` (Pest group `performance`, outside
  `Feature/` and excluded from the default run, executed by the CI step in T012 and by
  `make perf`):
  - It seeds itself: the first test checks `Content::count()` and runs `PerformanceSeeder`
    with `PERF_SEED_ROWS` (default 100000) only if fewer rows exist, so the data is committed
    and reused across the file. It uses **no** DB-reset trait (neither `RefreshDatabase` nor
    `DatabaseTruncation`, which would wipe the seed before each test); `make perf` and CI start
    from `migrate:fresh`.
  - The ingestion check uses ids prefixed `perf-ingest-{uuid}`, so it never collides with
    seeded rows.
  - `EXPLAIN (FORMAT JSON)` of the user and admin list queries (first page and a deep cursor
    page) uses `contents_user_feed_idx` / `contents_admin_feed_idx` with no `Sort` node (SC-006
    design evidence).
  - Latency: 200 sequential list and detail requests per role; assert p95 < 300 ms (SC-006).
  - Ingestion: a 100-event batch is acknowledged in < 300 ms, and `drainQueue()` processes it
    in < 5 s (SC-005, both halves).
  - Write the measured p95 and timings to `storage/logs/performance.json`, so T097 can copy
    the 100k-row numbers into the README.
  - Implementation note: measuring exposed that `queue:work --stop-when-empty` sleeps 3 s
    before stopping; `drainQueue()` now passes `--sleep=0` (the suite dropped from 44 s to
    26 s), and the compose worker polls with `--sleep=1` (≤ ~1 s pick-up latency).
- [X] T092 [P] Write `tests/Feature/Contract/OpenApiConformanceTest.php`:
  - Add the dev dependency `osteel/openapi-httpfoundation-testing` (verify that it supports
    OpenAPI 3.1; if it doesn't, implement a small recursive "required keys and types" asserter
    in `tests/Support/OpenApiAsserter.php` that reads `contracts/openapi.yaml` with
    `symfony/yaml`).
  - Validate the responses of every endpoint (2xx and the main 4xx) against
    `specs/001-cms-content-sync/contracts/openapi.yaml`.
  - Implementation note: the existing PHP validators only support OpenAPI 3.0, so the fallback
    `tests/Support/OpenApiAsserter.php` was implemented. It found a contract defect: `oneOf`
    between `Content` and `AdminContent` can never match an administrator item (it is valid for
    both), so those unions became `anyOf`.
- [X] T093 [P] Write `docs/architecture.md`: data model summary with the state machines,
  ingestion/processing sequence diagram (Mermaid), concurrency strategy, auth model,
  pagination, error format, observability, and a decisions table with links to `research.md`
  R1–R21, trade-offs, and known limitations.
- [X] T094 Rewrite `README.md` as the deliverable entry point:
  - overview
  - quick start: `make setup` (one command: env, build, up, migrate, demo seed), then create
    consumers/tokens; `make test` (one command) for the suite (SC-007)
  - configuration table of every env var
  - running the worker and scheduler
  - testing (`make test`, isolated DB, coverage, groups)
  - API overview with curl examples and a link to the OpenAPI file
  - authentication choice and rationale
  - sync vs async processing trade-offs
  - architecture summary linking `docs/architecture.md`
  - known limitations
  - how it was built with Spec Kit
- [X] T095 Security hardening pass:
  - `APP_DEBUG=false` and `APP_ENV=production` in the `app` image target.
  - Confirm that `.env` is ignored and no secret is in git history, with
    `gitleaks detect --redact` (run locally, and add a `secrets` job to
    `.github/workflows/ci.yml` using `gitleaks/gitleaks-action`, with `actions/checkout` set to
    `fetch-depth: 0` so the full history is scanned, not only the last commit).
  - `composer audit` is clean.
  - Run the `/security-review` skill on the branch and address its findings.
- [X] T096 Check coverage: run `make coverage`, require ≥ 85% overall and 100% on
  `app/Domain`, and add the missing tests. Then enable the gate in
  `.github/workflows/ci.yml` (`--coverage --min=85`) and in `make ci`.
- [X] T097 Run every scenario in `specs/001-cms-content-sync/quickstart.md` (S1–S13) manually
  against the Docker stack, and fix any discrepancy between the docs, the contract and the
  behaviour. Run `make perf` (self-seeding 100k rows, `tests/Performance`) and
  record the measured ack/processing times and p95 latencies in the README.
- [ ] T098 Final constitution compliance review: confirm that the T060 access matrix has rows for
  all 8 endpoints × 4 actors, tick the README roadmap, update `CLAUDE.md` if
  any structure changed, make sure CI is green on `main`, and tag `v1.0.0`.

---

## Phase 10: Post-merge review fixes

**Purpose**: Fix the findings from manual testing after PR #1 was merged (branch
`fix/review-findings`). Each fix is test-first.

- [X] T099 [US1] Reject timestamps whose offset is outside the RFC 3339 range (`+23:99`,
  `-05:60`, `+24:00`) in `app/Rules/Rfc3339DateTime.php`, which now also parses with the same
  parser as `CmsEvent`. Normalize event timestamps to UTC in `app/Domain/Cms/CmsEvent.php`, so
  valid offsets beyond PostgreSQL's ±15:59 are storable. Widen `contents.cms_timestamp` to
  `timestamptz(6)` (it was created as `timestamptz(0)`, dropping fractional seconds). Tests:
  `BatchValidationTest`, `EventProcessingTest` ("stores the same instant…"), `CmsEventTest`.
- [X] T100 [US2] Serve payloads exactly as stored: `JsonObjectPayload` returns
  `App\Support\Json\RawJson` and content resources embed it verbatim (`EmbedsRawJson`), so
  numbers beyond int64/double precision are exact and `1e400` no longer fails with a 500.
  Saving a model after reading its payload no longer rewrites it. Tests:
  `tests/Feature/Api/PayloadPrecisionTest.php`, `tests/Unit/Support/RawJsonTest.php`.
- [X] T101 [US6] Make the worker follow `CMS_QUEUE_CONNECTION` / `CMS_QUEUE_NAME`: new
  `cms:work` command (used by the Docker worker and `drainQueue()`); `App\Support\Queue\CmsQueue`
  is the single reader of the queue config and enforces a database queue on the application
  database (webhook and admin retry answer 503 otherwise; `cms:work` refuses to start). Tests:
  `tests/Feature/Queue/CmsWorkerTest.php`.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: no dependencies.
- **Foundational (Phase 2)**: depends on Setup. **Blocks all user stories.**
- **US1 (Phase 3)**: depends on Foundational.
- **US2 (Phase 4)**: depends on Foundational. Its tests seed data with factories, so it does
  not depend on US1. Scenario 4 of T049 uses the webhook, so run US2 after US1 or mark that one
  test as depending on US1.
- **US4 (Phase 5)**: depends on US1 (webhook) and US2 (content routes) existing.
- **US3 (Phase 6)**: depends on US2 (resource, list) and US4 (`EnsureRole`).
- **US5 (Phase 7)**: depends on US1 (processing code to instrument) and US3 (override logging).
- **US6 (Phase 8)**: depends on US1 (job, inbox) and US4 (`EnsureRole`).
- **Polish (Phase 9)**: depends on all stories.

### Story completion order

```text
Setup → Foundational → US1 ─┬─► US2 ─► US4 ─┬─► US3 ─► US5
                            │               └─► US6
                            └────────────────────────► (US5, US6 also need US1)
```

### Within Each User Story

- Tests are written first and MUST fail (red), then implementation (green), then refactor.
- Value objects/enums → domain logic → actions → HTTP (requests, controllers, resources,
  routes) → logs.
- Each phase ends with a "run suite + lint + commit" task.

### Parallel Opportunities

- Phase 1: T003, T004, T005, T006, T007, T008 and T012 are independent files.
- Phase 2: T015, T016, T018, T019, T020, T021, T022, T026 and T028 are parallel after T014/T017
  where noted.
- In each story, all test tasks marked [P] can be written in parallel, and value objects or
  resources marked [P] can be built in parallel.
- After US4: US3 and US6 touch different files and can proceed in parallel. Only
  `routes/api.php` and `AccessMatrixTest.php` are shared, so coordinate edits there.

---

## Parallel Example: User Story 1

```bash
# Tests first (all [P], different files):
Task: "EventPrecedence dataset tests in tests/Unit/Domain/Cms/EventPrecedenceTest.php"
Task: "Permutation property test in tests/Unit/Domain/Cms/EventPrecedencePermutationTest.php"
Task: "Webhook auth tests in tests/Feature/Webhook/CmsAuthenticationTest.php"
Task: "Batch validation tests in tests/Feature/Webhook/BatchValidationTest.php"
Task: "Acceptance + atomic hand-off tests in tests/Feature/Webhook/BatchAcceptanceTest.php"
Task: "Processing scenarios in tests/Feature/Processing/EventProcessingTest.php"

# Then the parallel building blocks:
Task: "Value objects in app/Domain/Cms/{CmsEvent,ContentSnapshot,EventDecision}.php"
Task: "JsonPayload support + cast in app/Support/Json/JsonPayload.php"
```

## Parallel Example: User Story 3 + User Story 6 (after US4)

```bash
Task: "LocalOverrideTest in tests/Feature/Api/LocalOverrideTest.php"            # US3
Task: "EventBatchApiTest in tests/Feature/Admin/EventBatchApiTest.php"          # US6
Task: "SetContentDisabled in app/Actions/Content/SetContentDisabled.php"        # US3
Task: "RetryEventBatch in app/Actions/Cms/RetryEventBatch.php"                  # US6
```

---

## Implementation Strategy

### MVP First (US1 + US2)

1. Phase 1 Setup → Phase 2 Foundational.
2. Phase 3 (US1): the webhook and processing pass every mandatory event scenario.
   **Stop and validate** with `make test` and quickstart S1–S8.
3. Phase 4 (US2): users can read content. **This is the demonstrable MVP.**

### Incremental Delivery

1. US4 hardens security across the MVP endpoints (top evaluation priority, alongside
   correctness).
2. US3 adds admin visibility and overrides (completes the challenge's mandatory scope).
3. US5 adds observability (mandatory in the brief).
4. US6 adds failed-batch recovery (completes the durability guarantee).
5. Polish produces the documentation deliverables, performance evidence and contract
   conformance.

### Branching

- Work on the branch `001-cms-content-sync`, with one commit per task or logical group
  (Conventional Commits), and open a PR to `main` at each checkpoint so CI validates it.

---

## Notes

- [P] tasks touch different files and have no dependency on incomplete tasks.
- The [Story] label maps each task to a spec user story for traceability.
- Never write to override columns from event processing, or to CMS columns from override
  actions (data-model "Field ownership").
- Verify that tests fail before implementing them, and stop at each checkpoint to validate the
  story on its own.
