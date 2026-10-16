# CMS Content Sync Service

A Laravel service that ingests content events from an external CMS through a webhook, keeps a
version-correct replica of that content in PostgreSQL, and exposes it through a secure,
paginated REST API for regular users and administrators. Administrators can locally disable
content without touching CMS-managed data.

> **Status:** specification phase. Setup, run, and test instructions will be added as the
> implementation lands (see [Roadmap](#roadmap)).

## Highlights

- **Version-based precedence**: duplicates, replays, and out-of-order events are ignored by
  comparing versions, never timestamps.
- **Durable async ingestion**: a batch is validated in full, stored in a transactional inbox,
  and acknowledged with `202 Accepted`; workers apply it atomically, in order, with retries.
  Failed batches are kept and can be re-processed, so no acknowledged delivery is lost.
- **Delete tombstones**: deleted content keeps its deletion version, so late events cannot
  restore it.
- **Separate actors**: the CMS uses HTTP Basic credentials from the environment; users and
  administrators authenticate with revocable bearer tokens tied to their role.
- **Local overrides**: the administrator's disabled flag is independent of CMS publication
  state and survives later publish/unpublish events.

## Tech stack

| Concern         | Choice                                                   |
|-----------------|----------------------------------------------------------|
| Language        | PHP 8.3+ (developed on 8.4)                              |
| Framework       | Laravel 13.x (latest stable)                             |
| Database        | PostgreSQL 18                                            |
| Runtime         | Docker Compose                                           |
| Auth            | HTTP Basic (CMS), Laravel Sanctum bearer tokens (consumers) |
| Queue           | Laravel database queue on PostgreSQL (transactional inbox) |
| Testing         | Pest 5 (unit, feature, concurrency, architecture)        |
| Quality gates   | Pint (style), Larastan (static analysis)                 |
| CI              | GitHub Actions                                           |

## Documentation map

| Document | Purpose |
|----------|---------|
| [`.specify/memory/constitution.md`](.specify/memory/constitution.md) | Non-negotiable engineering principles for this project |
| [`specs/001-cms-content-sync/spec.md`](specs/001-cms-content-sync/spec.md) | Feature specification: user stories, requirements, edge cases, success criteria |
| [`specs/001-cms-content-sync/checklists/requirements.md`](specs/001-cms-content-sync/checklists/requirements.md) | Specification quality checklist |
| [`specs/001-cms-content-sync/plan.md`](specs/001-cms-content-sync/plan.md) | Implementation plan, project structure, processing flow |
| [`specs/001-cms-content-sync/research.md`](specs/001-cms-content-sync/research.md) | Technical decisions, trade-offs, and known limitations |
| [`specs/001-cms-content-sync/data-model.md`](specs/001-cms-content-sync/data-model.md) | Tables, constraints, indexes, and state machines |
| [`specs/001-cms-content-sync/contracts/openapi.yaml`](specs/001-cms-content-sync/contracts/openapi.yaml) | OpenAPI 3.1 contract for the webhook and consumer API |
| [`specs/001-cms-content-sync/quickstart.md`](specs/001-cms-content-sync/quickstart.md) | End-to-end validation scenarios |
| [`specs/001-cms-content-sync/tasks.md`](specs/001-cms-content-sync/tasks.md) | 98 dependency-ordered, test-first implementation tasks |

## How this project is built

This repository follows **Spec-Driven Development** with
[GitHub Spec Kit](https://github.com/github/spec-kit):

1. `constitution` — project principles ([done](.specify/memory/constitution.md))
2. `specify` — what and why ([done](specs/001-cms-content-sync/spec.md))
3. `clarify` — resolve ambiguities ([done](specs/001-cms-content-sync/spec.md#clarifications))
4. `plan` — architecture, data model, contracts ([done](specs/001-cms-content-sync/plan.md))
5. `tasks` — ordered, testable work items ([done](specs/001-cms-content-sync/tasks.md))
6. `analyze` — cross-artifact consistency check
7. `implement` — test-first implementation

## Roadmap

- [x] Constitution and feature specification
- [x] Implementation plan, data model, OpenAPI contract
- [ ] Laravel application, migrations, and Docker environment
- [ ] Webhook ingestion and event processing
- [ ] Consumer API and administrator overrides
- [ ] CI pipeline (style, static analysis, tests)
- [ ] Final documentation: setup, configuration, running, testing, trade-offs, limitations
