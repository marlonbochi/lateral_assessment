# CLAUDE.md

Guidance for AI coding agents working in this repository.

## Project

Laravel service that ingests CMS webhook events, keeps a version-correct replica of content in
PostgreSQL, and exposes it through a secure REST API. This is a take-home challenge for an
international Senior PHP Engineer position.

## Stack & key design (see `specs/001-cms-content-sync/plan.md` and `research.md`)

- PHP 8.4.27 (Composer `~8.3.36 || ~8.4.27 || ~8.5.12`, platform 8.3.36), Laravel 13, Sanctum 4, PostgreSQL 18,
  Pest 4 (Pest 5 needs PHP 8.4), Larastan 3, Pint. Tests use `Pest\Laravel\*` functions
  (typed for Larastan), not `$this->`. Pest resolves closures inside dataset tuples only when
  the test parameter has a concrete type (not `mixed`/`Closure`); never nest `fn () => fn () =>`.
- Docker Compose: nginx + php-fpm `app`, `worker` (`php artisan cms:work`), `scheduler`, `postgres`.
  `CmsQueue` is the only reader of the queue config and requires a database queue on the app
  database (otherwise the webhook answers 503 and `cms:work` refuses to start).
- Webhook → transactional inbox (`event_batches` + `jobs` in one transaction) → `202`;
  worker applies batches atomically with sorted `pg_advisory_xact_lock` per content id.
- Precedence lives in the pure `app/Domain/Cms/EventPrecedence` (no Illuminate imports).
- Consumer API: `/api/v1/contents` (role-aware), `PUT|DELETE /contents/{id}/disabled`,
  `/api/v1/admin/event-batches`; RFC 9457 problem details; contract in `contracts/openapi.yaml`.

## Working on the code

- Everything runs in Docker: `make setup` (bootstrap), `make test`, `make ci` (Pint + Larastan +
  tests with the 85% coverage gate), `make perf` (100k-row performance group), `make fix`.
- Test layout: `Unit`, `Feature` (RefreshDatabase), `Database`, `Concurrency`, `Arch`,
  `Performance` (opt-in). Tests run on `cms_sync_test` only; `TestCase` aborts otherwise.
- Test helpers (`tests/Pest.php`): `postBatch()`, `drainQueue()` (runs `cms:work`, sleep 0),
  `actingAsRole()` (forgets cached guards), `cmsEvent()` (pass `(object) []` for `{}`).
- Role decisions only through the `admin` gate (`$user->can('admin')`); an arch test enforces it.
- Never log payloads, credentials or SQL bindings; use `SafeError` for error summaries.
- Never decode payloads in PHP: `contents.payload` is a `RawJson` (stored `jsonb` text) that content
  resources embed verbatim (`EmbedsRawJson`); timestamps are normalized to UTC in `CmsEvent`.
- Generated storage and the PHPStan cache must never be committed (they capture env values).
- PHP versions: raise the minimums in `App\Support\Security\PhpRuntimePolicy` on every critical
  PHP security release, then update the Docker tag and `composer.json` (tests enforce they match).

## Ground rules

- **Everything in English**: code, comments, commit messages, docs, specs, API messages.
- **Spec-Driven Development** with Spec Kit. Read before changing behaviour:
  - Constitution: `.specify/memory/constitution.md` (non-negotiable principles)
  - Active feature: `specs/001-cms-content-sync/` (spec, plan, tasks, contracts)
- Event precedence is decided by `version` only; batches are all-or-nothing; deletes leave a
  tombstone. Never weaken these guarantees.
- Never commit secrets. `.env.example` holds placeholders only.
- Tests never touch the development database.
- Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `chore:`, `ci:`, `refactor:`).
