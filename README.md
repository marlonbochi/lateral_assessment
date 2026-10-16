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
- **All-or-nothing batches**: a batch is validated in full and applied atomically, in order.
- **Delete tombstones**: deleted content keeps its deletion version, so late events cannot
  restore it.
- **Separate actors**: the CMS uses HTTP Basic credentials from the environment; users and
  administrators have their own credentials and roles.
- **Local overrides**: the administrator's disabled flag is independent of CMS publication
  state and survives later publish/unpublish events.

## Tech stack

| Concern         | Choice                                                   |
|-----------------|----------------------------------------------------------|
| Language        | PHP 8.3+ (developed on 8.4)                              |
| Framework       | Laravel 13.x (latest stable)                             |
| Database        | PostgreSQL 16+                                           |
| Runtime         | Docker Compose                                           |
| Quality gates   | Pint (style), Larastan (static analysis), automated tests |
| CI              | GitHub Actions                                           |

## Documentation map

| Document | Purpose |
|----------|---------|
| [`.specify/memory/constitution.md`](.specify/memory/constitution.md) | Non-negotiable engineering principles for this project |
| [`specs/001-cms-content-sync/spec.md`](specs/001-cms-content-sync/spec.md) | Feature specification: user stories, requirements, edge cases, success criteria |
| [`specs/001-cms-content-sync/checklists/requirements.md`](specs/001-cms-content-sync/checklists/requirements.md) | Specification quality checklist |

The implementation plan, data model, API contract (OpenAPI), and architecture decisions will be
added under `specs/001-cms-content-sync/` during the planning phase and summarized here.

## How this project is built

This repository follows **Spec-Driven Development** with
[GitHub Spec Kit](https://github.com/github/spec-kit):

1. `constitution` — project principles ([done](.specify/memory/constitution.md))
2. `specify` — what and why ([done](specs/001-cms-content-sync/spec.md))
3. `clarify` — resolve ambiguities
4. `plan` — architecture, data model, contracts
5. `tasks` — ordered, testable work items
6. `analyze` — cross-artifact consistency check
7. `implement` — test-first implementation

## Roadmap

- [x] Constitution and feature specification
- [ ] Implementation plan, data model, OpenAPI contract
- [ ] Laravel application, migrations, and Docker environment
- [ ] Webhook ingestion and event processing
- [ ] Consumer API and administrator overrides
- [ ] CI pipeline (style, static analysis, tests)
- [ ] Final documentation: setup, configuration, running, testing, trade-offs, limitations
