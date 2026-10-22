# CMS Content Sync Service Constitution

## Core Principles

### I. Event-Processing Correctness (NON-NEGOTIABLE)

The CMS is the single source of truth for content, and the service MUST converge to the state
implied by the highest version it has accepted for each entity, regardless of delivery order.

- Precedence MUST be decided by the entity `version` only. Timestamps are informational and
  MUST NOT influence ordering.
- An event whose version is less than or equal to the latest accepted version for its entity
  MUST be ignored (idempotent, replay-safe), and the ignore MUST be observable in logs.
- Version gaps MUST be accepted: the first event for an entity, or any later event, may carry
  any positive version higher than the last accepted one.
- Deletion MUST leave a durable tombstone that keeps the deleted version, so that older events
  can never restore the content.
- A batch MUST be validated in full before any change is applied, and a valid batch MUST be
  applied atomically, in the order received. Partial application is a defect.
- Concurrent deliveries touching the same entity MUST be serialized (row-level locking or an
  equivalent guarantee); the outcome MUST NOT depend on request interleaving.

Rationale: correct event processing is the highest-weighted evaluation criterion and the core
business guarantee of a downstream replica.

### II. Security by Default

- Every endpoint MUST require authentication. There are no anonymous routes except an
  unauthenticated liveness probe that exposes no data.
- Actors are strictly separated: the CMS credential can only ingest events; consumer
  credentials can never ingest events; the CMS credential can never read consumer resources.
- Authorization MUST be enforced server-side through Laravel policies/gates, never inferred
  from client input.
- Secrets (CMS credentials, tokens, DB passwords) MUST come from environment configuration and
  MUST NOT be committed. `.env.example` contains placeholders only.
- Credential comparison MUST be constant-time (`hash_equals`), and failed authentication MUST
  return a uniform `401` without revealing which part was wrong.
- Consumers MUST NOT be able to modify CMS-managed content through any endpoint. The only
  consumer-writable state is the administrator's local override.
- The PHP runtime MUST be a supported branch on a patch release with no known critical
  vulnerability. The minimum patched release per branch is defined once in code and enforced by
  the Composer constraint, the Docker build and CI; it MUST be raised whenever a PHP security
  release fixes a critical issue. PHP features the service does not use on its request path
  (form and multipart parsing, file uploads) SHOULD be disabled.

Rationale: security is a co-equal top priority with correctness in the challenge evaluation.

### III. Test-First, Layered Test Suite (NON-NEGOTIABLE)

- Behaviour is specified by tests before or alongside implementation (Red → Green → Refactor).
- Unit tests MUST cover pure domain logic (event precedence, state transitions, validation
  rules) without booting the database where possible.
- Feature/integration tests MUST exercise the HTTP layer against a real PostgreSQL instance,
  covering at minimum: valid/invalid authentication for every actor, duplicate and
  out-of-order events, the "unpublish a never-seen version" edge case, deletion with
  protection against older events, administrator overrides and consumer visibility, and
  whole-batch rejection on invalid input.
- Tests MUST NOT use the development database. They run against a dedicated test database
  configured in `phpunit.xml` / the CI service container.
- Every bug fix MUST start with a failing regression test.
- The full test suite MUST pass in CI before a change is merged.

Rationale: the evaluator explicitly lists required test scenarios and forbids using the dev DB.

### IV. Idiomatic Laravel & Modern PHP

- Target PHP 8.3+ (developed on 8.4) and the latest stable Laravel major (13.x at ratification).
- Every PHP file declares `declare(strict_types=1);`. Use typed properties, return types,
  readonly value objects, and backed enums for closed sets (e.g. event type, content status).
- Controllers stay thin: validation in Form Requests, authorization in Policies, domain logic in
  single-purpose Action/Service classes, response shaping in API Resources.
- Prefer framework features over custom infrastructure (Eloquent, migrations, middleware, guards,
  pagination, logging channels) unless a documented reason exists.
- Code style is enforced by Laravel Pint; static analysis by Larastan (PHPStan) at a high level
  (target level 8 or higher) with no baseline growth.

Rationale: maintainability and idiomatic use of the stack are explicit evaluation criteria.

### V. PostgreSQL Data Integrity & Performance

- All schema changes go through Laravel migrations; migrations are reversible.
- Invariants MUST be enforced in the database where practical (NOT NULL, CHECK constraints,
  unique keys on CMS identifiers, foreign keys), not only in application code.
- Arbitrary CMS payloads are stored as `jsonb`.
- Every query path used by the consumer API MUST be backed by an appropriate index (including
  partial indexes for the "visible to regular users" predicate where beneficial); listing MUST
  be paginated with a bounded page size.
- Concurrency control MUST be explicit (transactions plus `SELECT ... FOR UPDATE` or
  conditional upserts guarded by version) and documented.

Rationale: database design and concurrency are explicitly assessed.

### VI. Observability Without Leakage

- Processed events, ignored events (with the reason: duplicate, stale, post-delete), rejected
  batches, and processing failures MUST be logged with structured context (event type, content
  id, incoming version, stored version, outcome, request/batch correlation id).
- Logs MUST NOT contain credentials, authorization headers, or full CMS payload content.
- Errors returned to clients MUST be informative for integrators but MUST NOT leak stack traces
  or internals outside local/debug environments.

Rationale: required by the challenge and essential for operating a webhook consumer.

### VII. Simplicity and Documented Decisions

- Build the smallest design that satisfies the specification (YAGNI). Optional infrastructure
  (queues, read replicas, caches) is added only with a written justification.
- Every significant architectural choice and its trade-offs MUST be recorded (in the feature
  plan/research documents and summarized in the README), including known limitations.
- The API contract (webhook and consumer endpoints) MUST be documented in a machine-readable
  form (OpenAPI) and kept in sync with the implementation.

Rationale: the evaluation weighs the reasoning behind technical decisions, and the candidate must
be able to explain every part of the implementation.

## Technical Constraints & Delivery Standards

- **Language**: All code, comments, commit messages, documentation, specs, and API messages
  are written in English.
- **Stack**: PHP 8.3+, latest stable Laravel, PostgreSQL (16+), Composer. Test framework:
  Pest or PHPUnit (decided in the implementation plan).
- **Runtime**: The application MUST run locally through Docker Compose (app + PostgreSQL, plus a
  separate test database or schema) with a single documented bootstrap command.
- **CI/CD**: A GitHub Actions pipeline MUST run on every push and pull request and fail the
  build on any of: Composer validation/audit, Pint style check, Larastan analysis, migrations
  against PostgreSQL, the full test suite (unit + feature) against a PostgreSQL service, and a
  PHP runtime below the minimum patched release. The Docker image MUST build successfully in
  CI, and the build MUST refuse a vulnerable PHP base image.
- **Deliverables**: Laravel application with migrations, automated tests, `.env.example`
  without secrets, README (setup, configuration, running, testing), webhook and consumer API
  documentation, and a short explanation of data model, event processing, architecture,
  trade-offs, and known limitations.

## Development Workflow

- Work follows Spec-Driven Development with GitHub Spec Kit:
  `constitution → specify → clarify → plan → tasks → analyze → implement`.
  Specs live in `specs/NNN-feature-name/`.
- Each plan MUST include a Constitution Check gate; any violation requires an entry in the
  plan's Complexity Tracking table with justification.
- Commits follow Conventional Commits (`feat:`, `fix:`, `test:`, `docs:`, `chore:`, `ci:`,
  `refactor:`) and are small and focused; history should tell the story of the implementation.
- A change is "done" only when: tests are green, Pint and Larastan pass, documentation and the
  OpenAPI contract are updated, and CI is green.

## Governance

- This constitution supersedes ad-hoc practices. All specs, plans, tasks, and reviews MUST
  verify compliance with it.
- Amendments are made through `/speckit-constitution`, recorded with a version bump and
  rationale, and committed separately as `docs: amend constitution to vX.Y.Z`.
- Versioning: MAJOR for removing or redefining principles, MINOR for new principles or
  materially expanded guidance, PATCH for clarifications and wording.
- Complexity beyond these principles MUST be justified in writing in the relevant plan.

**Version**: 1.1.0 | **Ratified**: 2026-10-16 | **Last Amended**: 2026-10-22

Amendment history:

- 1.1.0 (2026-10-22): Principle II and the CI constraint now require a PHP runtime patched
  against known critical vulnerabilities (prompted by CVE-2026-109618 and CVE-2026-109619).
