# CLAUDE.md

Guidance for AI coding agents working in this repository.

## Project

Laravel service that ingests CMS webhook events, keeps a version-correct replica of content in
PostgreSQL, and exposes it through a secure REST API. This is a take-home challenge for an
international Senior PHP Engineer position.

## Stack & key design (see `specs/001-cms-content-sync/plan.md` and `research.md`)

- PHP 8.4 (`^8.3`, Composer platform pinned to 8.3), Laravel 13, Sanctum 4, PostgreSQL 18,
  Pest 4 (Pest 5 needs PHP 8.4), Larastan 3, Pint. Tests use `Pest\Laravel\*` functions
  (typed for Larastan), not `$this->`. Pest resolves closures inside dataset tuples only when
  the test parameter has a concrete type (not `mixed`/`Closure`); never nest `fn () => fn () =>`.
- Docker Compose: nginx + php-fpm `app`, `worker` (queue), `scheduler`, `postgres`.
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
- Test helpers (`tests/Pest.php`): `postBatch()`, `drainQueue()` (real database worker, sleep 0),
  `actingAsRole()` (forgets cached guards), `cmsEvent()` (pass `(object) []` for `{}`).
- Role decisions only through the `admin` gate (`$user->can('admin')`); an arch test enforces it.
- Never log payloads, credentials or SQL bindings; use `SafeError` for error summaries.
- Generated storage and the PHPStan cache must never be committed (they capture env values).

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
