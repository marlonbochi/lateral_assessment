# Implementation Plan: CMS Content Sync Service

**Branch**: `001-cms-content-sync` | **Date**: 2026-10-16 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/001-cms-content-sync/spec.md`

## Summary

A Laravel 13 / PostgreSQL 18 service that ingests CMS webhook batches and keeps a version-correct
replica of CMS content, served through a role-aware REST API.

The webhook (`POST /cms/events`, HTTP Basic) validates the whole batch. It then stores the batch
in a **transactional inbox** (`event_batches`) and enqueues its job on the same database, in one
transaction, and answers `202 Accepted`.

A queue worker applies each batch atomically and in order. It takes **sorted advisory locks** on
the affected content ids. It decides each event with a **pure version-precedence function**:
higher version wins, duplicates and stale events are ignored, and deletes leave tombstones.
Failed batches are retried with backoff, kept as `failed`, and can be re-processed from the admin
API or the CLI.

Consumers authenticate with **Sanctum bearer tokens** on accounts that carry a `user` or `admin`
role. One `/api/v1/contents` resource changes its view by role. Cursor pagination is the default
and page-number pagination is opt-in. Admins can disable or re-enable content through a local
override sub-resource. Errors use RFC 9457 Problem Details.

The project ships with Docker Compose (nginx, php-fpm, worker, scheduler, postgres) and GitHub
Actions CI (Pint, Larastan, Pest on PHP 8.3/8.4, Docker build). See [research.md](research.md)
for every decision and its trade-offs.

## Technical Context

**Language/Version**: PHP 8.4 (Composer constraint `^8.3`; CI matrix 8.3 + 8.4)

**Primary Dependencies**: Laravel 13.x, Laravel Sanctum 4.x; dev: Pest 5 (+ Laravel & arch
plugins), Larastan 3, Laravel Pint

**Storage**: PostgreSQL 18 (`jsonb`, partial indexes, CHECK constraints, advisory locks);
database queue driver and database cache store on the same connection

**Testing**: Pest 5 on PHPUnit 13 — unit, feature/integration against a dedicated
`cms_sync_test` PostgreSQL database, concurrency, and architecture tests; coverage gate 85%

**Target Platform**: Linux containers (Alpine PHP 8.4-FPM + nginx), Docker Compose locally,
GitHub Actions for CI

**Project Type**: Web service (REST API + background worker), single Laravel application

**Performance Goals**: Acknowledge a 100-event batch in < 300 ms; process it in < 5 s with one
worker; consumer list/detail p95 < 300 ms with 100k content rows (SC-005, SC-006)

**Constraints**: All-or-nothing batches; precedence by version only; no secrets in the repo; no
payloads or credentials in logs; tests never touch the dev database; every endpoint
authenticated (except `/up`)

**Scale/Scope**: About 100k content items, batches of ≤ 100 events, a single CMS sender, tens of
consumer accounts; 8 HTTP endpoints (+ `/up`), 6 public artisan commands (+ 1 hidden
test-support command)

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-checked after Phase 1 design.*

| Principle | Gate | Pre-design | Post-design | Evidence |
|---|---|---|---|---|
| I. Event-Processing Correctness | Version-only precedence, idempotent ignores, gaps accepted, tombstones, atomic ordered batches, concurrency-safe | ✅ | ✅ | research R4–R7; data-model §1 state machine; FR-004–FR-014 |
| II. Security by Default | All endpoints authenticated, strict actor separation, policies, env secrets, constant-time compare, no consumer writes to CMS data | ✅ | ✅ | research R10–R11, R17; contract security schemes; data-model field ownership |
| III. Test-First, Layered Suite | Unit + feature against real PostgreSQL, required scenarios, isolated test DB | ✅ | ✅ | research R18; quickstart §4 |
| IV. Idiomatic Laravel & Modern PHP | Strict types, enums, readonly VOs, thin controllers, Form Requests, Policies, Resources, Pint, Larastan ≥ 8 | ✅ | ✅ | Project structure below; research R1, R20 |
| V. PostgreSQL Integrity & Performance | Migrations, DB constraints, `jsonb`, indexed query paths, bounded pagination, explicit concurrency | ✅ | ✅ | data-model constraints/indexes; research R5, R13, R14 |
| VI. Observability Without Leakage | Structured logs for applied/ignored/rejected/failed, no secrets or payloads | ✅ | ✅ | research R16; FR-030–FR-032 |
| VII. Simplicity & Documented Decisions | Smallest design; optional infrastructure justified; OpenAPI contract | ⚠️ justified | ⚠️ justified | Async inbox, advisory locks, admin batch API → Complexity Tracking |
| Constraints: English, Docker, CI | English-only artifacts; Compose with one bootstrap command; CI gates | ✅ | ✅ | research R19–R20 |

**Result**: PASS. The three Principle VII items are justified below.

## Project Structure

### Documentation (this feature)

```text
specs/001-cms-content-sync/
├── spec.md              # Feature specification (+ clarifications)
├── plan.md              # This file
├── research.md          # Phase 0: decisions, rationale, alternatives, limitations
├── data-model.md        # Phase 1: tables, constraints, indexes, state machines
├── quickstart.md        # Phase 1: run & validation guide
├── contracts/
│   └── openapi.yaml     # Phase 1: webhook + consumer + admin API contract (OpenAPI 3.1)
├── checklists/
│   └── requirements.md  # Spec quality checklist
└── tasks.md             # Phase 2 (/speckit-tasks — not created here)
```

### Source Code (repository root)

The Laravel application lives at the repository root (standard Laravel 13 skeleton). Only
feature-relevant paths are listed.

```text
app/
├── Domain/                         # Framework-free core (no Illuminate imports; enforced by arch tests)
│   └── Cms/
│       ├── CmsEvent.php            # readonly VO built from a validated event
│       ├── CmsEventType.php        # enum: publish|unpublish|delete
│       ├── ContentSnapshot.php     # readonly VO: current version + status
│       ├── EventDecision.php       # readonly VO: apply | ignore(reason) + new status
│       ├── EventPrecedence.php     # pure decision function (research R6)
│       └── IgnoreReason.php        # enum: duplicate|stale|stale_after_delete
├── Enums/
│   ├── BatchStatus.php             # pending|processed|failed
│   ├── ContentStatus.php           # published|unpublished|deleted
│   └── Role.php                    # user|admin
├── Actions/
│   ├── Cms/
│   │   ├── AcceptEventBatch.php    # store inbox row + dispatch job in one transaction
│   │   ├── ProcessEventBatch.php   # locks, ordered apply via EventPrecedence, counts, logs
│   │   └── RetryEventBatch.php     # failed → pending + new job (API & CLI)
│   └── Content/
│       └── SetContentDisabled.php  # local override (disable / enable), idempotent
├── Jobs/
│   └── ProcessEventBatchJob.php    # ShouldQueue, tries/backoff, failed() → batch failed
├── Models/
│   ├── Content.php                 # casts, scopeVisibleTo(), route key cms_id
│   ├── EventBatch.php              # HasUuids (v7), MassPrunable
│   └── User.php                    # HasApiTokens, role cast
├── Policies/
│   ├── ContentPolicy.php           # + `admin` / `filter-contents` gates in AppServiceProvider
│   └── EventBatchPolicy.php
├── Http/
│   ├── Controllers/
│   │   ├── Cms/EventBatchController.php            # POST /cms/events
│   │   └── Api/V1/
│   │       ├── ContentController.php               # index, show
│   │       ├── ContentDisabledController.php       # update (PUT), destroy (DELETE)
│   │       └── Admin/EventBatchController.php      # index, show, retry
│   ├── Middleware/
│   │   ├── AuthenticateCms.php     # HTTP Basic vs env, hash_equals, fail closed
│   │   ├── EnsureJsonBody.php      # 415 non-JSON, 413 over the size limit
│   │   ├── EnsureRole.php          # thin adapter over the `admin` gate, runs before route-model binding
│   │   └── AssignRequestId.php     # X-Request-Id correlation for logs
│   ├── Requests/
│   │   ├── Cms/StoreEventBatchRequest.php   # assoc decode for rules + object decode for payloads
│   │   └── Api/V1/{ListContentsRequest,ListEventBatchesRequest}.php
│   └── Resources/
│       ├── ContentResource.php     # role-aware fields
│       ├── EventBatchResource.php
│       ├── EventBatchDetailResource.php     # + event summary, never payloads
│       └── PaginatedCollection.php # cursor/page meta shaping
├── Rules/
│   ├── StrictPositiveInteger.php
│   ├── Rfc3339DateTime.php
│   └── JsonObject.php
├── Casts/
│   ├── JsonObjectPayload.php       # contents.payload, keeps {} vs []
│   └── JsonObjectList.php          # event_batches.events, keeps {} vs []
├── Exceptions/
│   ├── ProblemDetailsRenderer.php  # RFC 9457 rendering for api/* and cms/*
│   └── BatchNotRetryableException.php   # → 409
├── Console/Commands/
│   ├── CreateConsumer.php          # consumer:create
│   ├── IssueConsumerToken.php      # consumer:token
│   ├── RevokeConsumerToken.php     # consumer:revoke
│   ├── ListFailedBatches.php       # cms:batches:failed
│   ├── RetryFailedBatches.php      # cms:batches:retry {id|--all}
│   ├── DiscardFailedBatch.php      # cms:batches:discard {id}
│   └── ProcessBatchOnce.php        # cms:batches:process {id} (hidden, concurrency tests)
└── Support/
    ├── Json/JsonPayload.php        # object-preserving decode/encode (research R8)
    └── Logging/SafeError.php       # sanitized error summaries for logs / last_error

bootstrap/app.php                   # routes, middleware aliases, rate limiters, exception rendering
config/cms.php                      # webhook credentials, limits, queue connection, retention
routes/
├── api.php                         # /api/v1/...
└── cms.php                         # /cms/events
database/
├── migrations/                     # users (adapted), personal_access_tokens, contents, event_batches, jobs, cache
├── factories/                      # UserFactory, ContentFactory, EventBatchFactory
└── seeders/                        # DemoSeeder, PerformanceSeeder
tests/
├── Pest.php
├── TestCase.php                    # aborts unless DB name ends with _test
├── Arch/ArchitectureTest.php
├── Unit/Domain/Cms/                # EventPrecedenceTest (datasets + permutation property test)
├── Unit/Support/                   # JsonPayloadTest
├── Unit/Policies/                  # PolicyTest
├── Feature/Webhook/                # auth, validation, acceptance, atomic hand-off
├── Feature/Processing/             # duplicates, out-of-order, edge case, delete/tombstone, re-create, retries, concurrency
├── Feature/Api/                    # visibility, overrides, pagination, resource shape
├── Feature/Admin/                  # batch list/show/retry
├── Feature/Console/                # consumer & batch commands
├── Feature/Observability/          # log content & hygiene
├── Feature/Security/               # actor separation, token validity, access matrix, rate limits
├── Feature/Contract/               # OpenAPI conformance
└── Feature/Performance/            # query plans, p95 latency, ack/processing time (group: performance)

docker/
├── php/Dockerfile                  # multi-stage: composer deps → php:8.4-fpm-alpine
├── php/php.ini, php/opcache.ini
├── nginx/default.conf
└── postgres/init/01-create-test-db.sql
docker-compose.yml                  # nginx, app, worker, scheduler, postgres (healthchecks)
Makefile                            # setup (one-command bootstrap), up, down, migrate, test, lint, ci, perf, shell
.github/workflows/ci.yml            # lint, static-analysis, tests (8.3/8.4 + postgres:18), docker build
docs/
├── architecture.md                 # data model, processing flow, decisions, trade-offs, limitations
└── examples/batch.json             # sample webhook payloads
phpstan.neon, pint.json, phpunit.xml, .env.example, .env.testing
```

**Structure Decision**: One Laravel application at the repository root. Laravel conventions stay
in place: Models, Http, Policies, Jobs and Console. Two thin layers are added:

- `app/Domain/Cms` holds the framework-free precedence rules, which are the riskiest logic, so
  they can be unit tested exhaustively.
- `app/Actions` holds single-purpose use cases shared by controllers, jobs and commands. For
  example, retry is used by both the API and the CLI.

A full hexagonal or DDD layout was rejected as too heavy for this scope (Principle VII).

## Processing flow

```text
CMS ──POST /cms/events──► AuthenticateCms ─► throttle ─► StoreEventBatchRequest (whole batch)
                                                         │ invalid → 422 (nothing stored)
                                                         ▼
                              AcceptEventBatch: BEGIN; INSERT event_batches(pending);
                              dispatch ProcessEventBatchJob (jobs table); COMMIT
                                                         │ DB error → 503 (CMS retries)
                                                         ▼
                                                  202 {batch_id}

worker ─► ProcessEventBatchJob ─► ProcessEventBatch:
          BEGIN
            SELECT batch FOR UPDATE (skip if processed)
            pg_advisory_xact_lock(hash(cms_id)) for each distinct id, sorted
            for each event in received order:
              snapshot ← SELECT version, status FROM contents WHERE cms_id = ?
              decision ← EventPrecedence::decide(snapshot, event)
              apply → upsert CMS-owned columns (guard: version < incoming) | ignore → log reason
            UPDATE batch: processed, counts
          COMMIT
          on exception: rollback, log attempt_failed, retry with backoff → failed() after 5 tries
```

## Complexity Tracking

| Item (Principle VII) | Why Needed | Simpler Alternative Rejected Because |
|---|---|---|
| Asynchronous processing with a transactional inbox, a worker and a scheduler | Clarified requirement: once the webhook acknowledges a batch, losing data is our responsibility; it also scales by adding workers and absorbs spikes | Synchronous processing was rejected in clarification. Async with Redis and `afterCommit` leaves a crash window between commit and enqueue. The inbox on the same DB closes that window without new infrastructure |
| Sorted advisory locks per content id | Parallel workers must never lose updates or deadlock, including for ids that do not exist yet | `SELECT … FOR UPDATE` can't lock rows that don't exist yet. `SERIALIZABLE` + retry is harder to reason about and storms under contention |
| Admin batch-management API (list/show/retry) in addition to the CLI | Clarified requirement: administrators recover failed batches without server access | CLI only was rejected in clarification |
| RFC 9457 renderer | Consistent, standard error contract for international API clients (team decision) | Laravel's default `{message, errors}` shape is not standardized across status codes |
