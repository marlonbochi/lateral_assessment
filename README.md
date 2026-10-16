# CMS Content Sync Service

A Laravel service that receives content events from an external CMS through a webhook. It keeps
a **version-correct replica** of that content in PostgreSQL and serves it through a secure,
paginated **REST API** for regular users and administrators. Administrators can also disable
content locally without touching CMS-managed data.

> Take-home challenge for a Senior PHP Engineer position.
> PHP 8.3+ · Laravel 13 · PostgreSQL 18 · Sanctum · Pest · Docker · GitHub Actions

## Highlights

- **Version precedence.** Duplicates, replays and out-of-order events are ignored by comparing
  versions only, never timestamps. A deletion leaves a tombstone, so older events cannot restore
  the content; a newer event re-creates it.
- **No acknowledged batch is lost.** The whole batch is validated first (422 if anything is
  invalid). The batch and its job are then stored in **one transaction** and the CMS gets
  `202 Accepted`. A worker applies the batch atomically and in order, retries with backoff, and
  keeps failed batches available for re-processing.
- **Concurrency safe.** Sorted advisory locks are taken per content id. This is proven by a test
  that runs real parallel processes (it deadlocks if the locks are not sorted).
- **Separate actors.** The CMS uses HTTP Basic credentials from the environment; consumers use
  revocable bearer tokens. Every role decision goes through one gate.
- **Exact payloads.** CMS payloads are stored exactly as received (`{}` stays `{}`, numbers keep
  their precision) and are never logged.
- **Quality gates.** 535 tests at 95% coverage (100% on the domain), Larastan level 8, Pint,
  OpenAPI conformance tests, and CI on PHP 8.3 and 8.4.

## Quick start

Requirements: Docker 24+ with Compose v2, `make`, and free ports `8080` (HTTP) and `54329`
(PostgreSQL on the host). Both ports can be changed in `.env`.

```bash
make setup
```

`make setup` is the one-command bootstrap. It:

1. creates `.env` from `.env.example` (if it does not exist);
2. builds the images and starts nginx, app, worker, scheduler and postgres;
3. installs dependencies, generates the app key and runs the migrations;
4. seeds demo data.

Then give the CMS real credentials in `.env` (`CMS_WEBHOOK_USERNAME`, `CMS_WEBHOOK_PASSWORD`)
and run `make up` to apply them. Create a consumer token for each demo account (the token is
shown only once):

```bash
docker compose exec app php artisan consumer:token admin@example.test
```

```bash
docker compose exec app php artisan consumer:token reader@example.test
```

Check that the service is up:

```bash
curl -i http://localhost:8080/up
```

## Using the API

The full contract (request and response schemas, status codes, errors) is in
[`specs/001-cms-content-sync/contracts/openapi.yaml`](specs/001-cms-content-sync/contracts/openapi.yaml)
(OpenAPI 3.1). The examples below assume `$CMS_USER`, `$CMS_PASS`, `$ADMIN_TOKEN` and
`$USER_TOKEN` are exported in your shell.

### CMS webhook

`POST /cms/events` accepts 1–100 events and answers `202` with a `batch_id`.

```bash
curl -u "$CMS_USER:$CMS_PASS" -H 'Content-Type: application/json' -d @docs/examples/batch.json http://localhost:8080/cms/events
```

### Consumer API (bearer token)

| Method | Endpoint | Who | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/contents` | user, admin | List visible content. Cursor pagination by default; send `page` for page numbers with totals. Admin-only filters: `status`, `disabled`. |
| `GET` | `/api/v1/contents/{id}` | user, admin | One item by CMS id. Hidden and unknown items both return `404`. |
| `PUT` / `DELETE` | `/api/v1/contents/{id}/disabled` | admin | Disable / re-enable locally. Idempotent; CMS data is untouched. |
| `GET` | `/api/v1/admin/event-batches[/{id}]` | admin | Inspect batches (an event summary, never payloads). |
| `POST` | `/api/v1/admin/event-batches/{id}/retry` | admin | Re-process a failed batch (`409` unless the batch failed). |

Examples:

```bash
curl -H "Authorization: Bearer $USER_TOKEN" "http://localhost:8080/api/v1/contents?per_page=15"
```

```bash
curl -H "Authorization: Bearer $ADMIN_TOKEN" "http://localhost:8080/api/v1/contents?status=unpublished&page=1"
```

```bash
curl -X PUT -H "Authorization: Bearer $ADMIN_TOKEN" http://localhost:8080/api/v1/contents/content-123/disabled
```

Errors use **RFC 9457 Problem Details** (`application/problem+json`). Validation errors carry an
`errors` object keyed by field path, for example `events.2.version`.

## Authentication choice

- **CMS → HTTP Basic** (required by the brief). Credentials come only from the environment.
  They are compared in constant time, and the webhook rejects everything if they are not
  configured (fail closed).
- **Consumers → opaque bearer tokens (Laravel Sanctum)**, issued per consumer account. The
  account carries the role (`user` or `admin`).
  - Why: first-party, stateless, and revocable one token at a time.
  - Tokens are stored as SHA-256 hashes, expire after 90 days by default, and carry a `cms_`
    prefix so secret scanners can detect leaked tokens.
  - There is no password login and no self-service: an operator issues and revokes tokens with
    `consumer:create`, `consumer:token` and `consumer:revoke`.
  - JWTs were rejected (hard to revoke, extra dependency), and so were static API keys (no
    per-client identity or revocation).
- The two credential types never overlap: Basic credentials on the API and tokens on the
  webhook both get `401`.

## Synchronous vs asynchronous processing

Processing is **asynchronous**, with a **transactional inbox**:

1. The webhook validates the whole batch synchronously, so an invalid batch gets `422` and
   nothing is stored.
2. It writes the batch and its queue job in the same PostgreSQL transaction (the queue uses
   the `database` driver) and answers `202`.
3. A worker applies the batch.

**Why:** a webhook sender only needs to know its delivery was accepted. From then on, not
losing it is this service's job. Writing the inbox row and the job in one commit removes the
"acknowledged but never queued" window. This also keeps ingestion fast, absorbs load spikes, and
lets processing scale by adding workers. Because precedence is decided by version, workers can
process batches in any order and still reach the same final state.

**Trade-offs:**

- Content becomes visible to consumers shortly after acknowledgement, not at it (eventual
  consistency, about 1 s).
- The CMS does not receive per-event outcomes; those are in the logs and the batch API.
- A worker process must be running.

Synchronous processing would have been simpler, but it couples CMS latency to our write path
and makes future growth a breaking change.

## Configuration

All configuration comes from environment variables (see [`.env.example`](.env.example), which
contains no real secrets).

| Variable | Default | Purpose |
|---|---|---|
| `CMS_WEBHOOK_USERNAME` / `CMS_WEBHOOK_PASSWORD` | — | CMS Basic credentials. If empty, the webhook rejects every request. |
| `CMS_WEBHOOK_MAX_BODY_BYTES` | `1048576` | Maximum webhook body size (larger bodies get `413`). |
| `CMS_WEBHOOK_RATE_LIMIT` / `API_RATE_LIMIT` | `300` / `120` | Requests per minute, per IP (webhook) and per token (API). |
| `CMS_QUEUE_CONNECTION` / `CMS_QUEUE_NAME` | `database` / `cms-events` | Queue for batch processing. Must stay on PostgreSQL. |
| `CMS_BATCH_RETENTION_DAYS` | `30` | Processed batches are pruned after this many days (failed ones are kept). |
| `SANCTUM_TOKEN_EXPIRATION_MINUTES` | `129600` | Consumer token lifetime (90 days). |
| `SANCTUM_TOKEN_PREFIX` | `cms_` | Token prefix, so secret scanners can recognize tokens. |
| `LOG_CMS_STREAM` / `LOG_CMS_LEVEL` | `php://stderr` / `info` | Destination and level of the structured `cms` log channel. |
| `DB_*`, `DB_FORWARD_PORT`, `APP_PORT` | see `.env.example` | Database connection and the host ports Docker publishes. |

`.env.testing` is committed on purpose. It only contains test values and points the suite at
the separate `cms_sync_test` database.

## Running

| Command | What it does |
|---|---|
| `make up` / `make down` | Start or stop the stack (nginx, php-fpm, worker, scheduler, postgres). |
| `make migrate` / `make fresh` | Migrate the development database, or recreate it and seed demo data. |
| `make shell` | Open a shell in the app container. |
| `docker compose logs -f worker` | Follow the structured processing log. |

The worker runs `queue:work` and polls every second. The scheduler prunes old batches and
expired tokens daily.

Operations commands:

```bash
docker compose exec app php artisan cms:batches:failed
```

```bash
docker compose exec app php artisan cms:batches:retry --all
```

```bash
docker compose exec app php artisan cms:batches:discard <batch-id>
```

## Testing

```bash
make test
```

```bash
make ci
```

`make test` runs the suite in parallel. `make ci` runs Pint, Larastan level 8 and the tests
with the 85% coverage gate.

```bash
make perf
```

`make perf` runs the performance group against 100k content rows.

- Tests **never use the development database.** They run on `cms_sync_test`, and `TestCase`
  aborts if the configured database is not a `*_test` database. That guard caught a real
  misconfiguration during development.
- Processing tests use the real `database` queue and worker, not a fake.
- Test suites:
  - `Unit` — domain precedence, including a 200-seed permutation property test.
  - `Feature` — HTTP and PostgreSQL: webhook, processing scenarios, access matrix, overrides,
    pagination, logging hygiene, OpenAPI conformance.
  - `Database` — migration rollback.
  - `Concurrency` — real parallel processes.
  - `Arch` — layering rules.
  - `Performance` — opt-in.

Measured on 100k rows with `make perf`, on a laptop:

| Metric | Target | Measured |
|---|---|---|
| List/detail p95, user | < 300 ms | 14 ms |
| List/detail p95, admin | < 300 ms | 15 ms |
| Acknowledge a 100-event batch | < 300 ms | 75 ms |
| Process a 100-event batch | < 5 s | 0.17 s (plus up to 1 s worker polling) |

CI (GitHub Actions) runs Composer validate and audit, Pint, Larastan, the tests on PHP 8.3 and
8.4 against `postgres:18` with the coverage gate, the performance group (20k rows), an OpenAPI
lint, gitleaks over the full history, and a Docker image build.

## Architecture

The full write-up is in [`docs/architecture.md`](docs/architecture.md): data model and state
machine, processing sequence, concurrency, security model, decisions and trade-offs.

In short:

- A pure `EventPrecedence` domain function decides every event.
- Single-purpose actions (`AcceptEventBatch`, `ProcessEventBatch`, `RetryEventBatch`,
  `SetContentDisabled`) are shared by controllers, jobs and commands.
- Thin controllers rely on Form Requests, policies, the `admin` gate and API Resources.
- PostgreSQL enforces the invariants with CHECK constraints, partial feed indexes, `jsonb` and
  advisory locks.

## Known limitations

- **Eventual consistency.** Content is visible about 1 s after acknowledgement.
- **Queue location.** The atomic hand-off relies on the queue living in the same PostgreSQL
  database; Redis or SQS would need an outbox relay.
- **Content ids** must be URL-safe (`^[A-Za-z0-9][A-Za-z0-9._:-]*$`, at most 255 characters).
- **No content history.** Only the latest version is kept; raw batches are retained for 30 days.
- **Credential rotation.** There is one CMS credential pair, and rotating it requires a restart.
- **Numeric precision when serving.** Payload numbers beyond int64 or double precision are
  stored exactly but served as doubles.

## How this was built

This repository follows **Spec-Driven Development** with
[GitHub Spec Kit](https://github.com/github/spec-kit). The steps were:

1. Constitution: [`.specify/memory/constitution.md`](.specify/memory/constitution.md).
2. Specification and clarification: [`spec.md`](specs/001-cms-content-sync/spec.md).
3. Plan, research, data model and contract: [`plan.md`](specs/001-cms-content-sync/plan.md),
   [`research.md`](specs/001-cms-content-sync/research.md),
   [`data-model.md`](specs/001-cms-content-sync/data-model.md),
   [`openapi.yaml`](specs/001-cms-content-sync/contracts/openapi.yaml).
4. Cross-artifact analysis.
5. Test-first implementation of the 98 tasks in
   [`tasks.md`](specs/001-cms-content-sync/tasks.md).

Deviations discovered during implementation are recorded next to the affected task and research
item. Examples: Pest 4 instead of 5, payloads copied in SQL, and the contract's `oneOf` →
`anyOf` fix found by the conformance tests.

AI-assisted development (Claude Code) was used throughout, as the brief encourages. Every
decision is documented and covered by tests.
