# CLAUDE.md

Guidance for AI coding agents working in this repository.

## Project

Laravel service that ingests CMS webhook events, keeps a version-correct replica of content in
PostgreSQL, and exposes it through a secure REST API. This is a take-home challenge for an
international Senior PHP Engineer position.

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
