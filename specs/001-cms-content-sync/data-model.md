# Data Model: CMS Content Sync Service

**Feature**: [spec.md](spec.md) | **Research**: [research.md](research.md) | **Date**: 2026-10-16

PostgreSQL 18, managed exclusively through Laravel migrations. All timestamps are
`timestamptz` stored in UTC.

```text
users 1 ──── * personal_access_tokens        (Sanctum, polymorphic tokenable)
users 1 ──── * contents.disabled_by           (nullable, who last changed the override)
users 1 ──── * event_batches.retried_by       (nullable, who last requested a retry)
event_batches 1 ──── 1 jobs (transient)       (queue row, same transaction as the batch)
```

`contents` and `event_batches` are intentionally **not** linked by a foreign key: a content row
reflects the latest version from any number of batches, and batches are pruned independently.

---

## 1. `contents` — local replica of CMS entities (Eloquent: `Content`)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint` identity | no | | Internal surrogate PK, never exposed |
| `cms_id` | `varchar(255)` | no | | CMS identifier, exposed as `id` in the API |
| `version` | `bigint` | no | | Latest accepted version |
| `status` | `varchar(16)` | no | | `published` \| `unpublished` \| `deleted` (`ContentStatus` enum) |
| `payload` | `jsonb` | yes | | CMS content exactly as received; `NULL` iff deleted |
| `cms_timestamp` | `timestamptz` | no | | `timestamp` of the last applied event (informational only) |
| `synced_at` | `timestamptz` | no | | When the last event was applied locally; drives listing order |
| `is_disabled` | `boolean` | no | `false` | Local administrative override |
| `disabled_at` | `timestamptz` | yes | | When the override was last changed to disabled |
| `disabled_by` | `bigint` FK → `users.id` | yes | | Admin who last changed the override (`ON DELETE SET NULL`) |
| `created_at` / `updated_at` | `timestamptz` | no | | Eloquent timestamps |

**Constraints**

- `contents_cms_id_unique` — `UNIQUE (cms_id)`.
- `contents_version_positive` — `CHECK (version > 0)`.
- `contents_status_valid` — `CHECK (status IN ('published','unpublished','deleted'))`.
- `contents_payload_matches_status` —
  `CHECK ((status = 'deleted' AND payload IS NULL) OR (status <> 'deleted' AND payload IS NOT NULL))`.
- `contents_payload_is_object` — `CHECK (payload IS NULL OR jsonb_typeof(payload) = 'object')`.

**Indexes** (see research R14)

| Name | Definition | Serves |
|---|---|---|
| `contents_cms_id_unique` | `(cms_id)` unique | Lookup by id, integrity |
| `contents_user_feed_idx` | `(synced_at DESC, cms_id DESC) WHERE status = 'published' AND is_disabled = false` | Regular-user listing |
| `contents_admin_feed_idx` | `(synced_at DESC, cms_id DESC) WHERE status <> 'deleted'` | Admin listing (+ filters) |

**Field ownership**

| Owned by CMS (written only by event processing) | Owned locally (written only by admin override) |
|---|---|
| `cms_id`, `version`, `status`, `payload`, `cms_timestamp`, `synced_at` | `is_disabled`, `disabled_at`, `disabled_by` |

Event processing never writes override columns; override operations never write CMS columns.
This separation is what makes FR-028 and FR-029 hold, and it is asserted by tests.

**Visibility scope** (`Content::scopeVisibleTo(User $user)`)

| Role | Predicate |
|---|---|
| `user` | `status = 'published' AND is_disabled = false` |
| `admin` | `status <> 'deleted'` |

### State transitions (CMS status)

The current state is `∅` (no row) or `(status, version)`. An event `(type, v)` is applied only
when the row does not exist or `v > version`; otherwise it is ignored (`duplicate` if
`v = version`, `stale` if `v < version`). See research R6.

```text
                 publish(v)            unpublish(v)           delete(v)
   ∅  ──────────► published   ∅ ─────► unpublished   ∅ ─────► deleted (tombstone)

   published   ── unpublish(v>cur) ──► unpublished
   published   ── publish(v>cur)   ──► published      (content replaced)
   unpublished ── publish(v>cur)   ──► published
   unpublished ── unpublish(v>cur) ──► unpublished    (content replaced)
   published / unpublished ── delete(v>cur) ──► deleted   (payload := NULL)
   deleted     ── publish(v>cur)   ──► published      (re-created, override kept)
   deleted     ── unpublish(v>cur) ──► unpublished    (re-created, override kept)
   deleted     ── delete(v>cur)    ──► deleted        (version advanced)
   any         ── any(v<=cur)      ──► unchanged      (ignored: duplicate / stale)
```

On every applied event: `version := v`, `cms_timestamp := event.timestamp`, `synced_at := now()`.

### Local override transitions

```text
enabled  ── PUT    /contents/{id}/disabled ──► disabled (disabled_at, disabled_by set)
disabled ── PUT    /contents/{id}/disabled ──► disabled (no-op, idempotent)
disabled ── DELETE /contents/{id}/disabled ──► enabled  (disabled_at := NULL, disabled_by := actor)
enabled  ── DELETE /contents/{id}/disabled ──► enabled  (no-op, idempotent)
```

Only allowed while `status <> 'deleted'` (otherwise `404`). The override survives every CMS
event, including delete and re-creation.

---

## 2. `event_batches` — durable inbox of acknowledged deliveries (Eloquent: `EventBatch`)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `uuid` (v7, time-ordered) | no | | Returned to the CMS as `batch_id` |
| `status` | `varchar(16)` | no | `'pending'` | `pending` \| `processed` \| `failed` (`BatchStatus` enum) |
| `events` | `jsonb` | no | | Ordered list of events exactly as received |
| `event_count` | `smallint` | no | | 1–100 |
| `attempts` | `smallint` | no | `0` | Processing attempts in the current cycle |
| `applied_count` | `smallint` | yes | | Set when processed |
| `ignored_count` | `smallint` | yes | | Set when processed |
| `last_error` | `varchar(1000)` | yes | | Sanitized summary (exception class + message, no payload) |
| `request_id` | `varchar(64)` | yes | | Correlation id of the webhook request |
| `received_at` | `timestamptz` | no | | Acknowledgement time |
| `processed_at` | `timestamptz` | yes | | When status became `processed` |
| `failed_at` | `timestamptz` | yes | | When status became `failed` |
| `retried_at` | `timestamptz` | yes | | Last manual re-processing request |
| `retried_by` | `bigint` FK → `users.id` | yes | | Admin who requested it (`NULL` when via CLI) |
| `created_at` / `updated_at` | `timestamptz` | no | | |

**Constraints**

- `event_batches_status_valid` — `CHECK (status IN ('pending','processed','failed'))`.
- `event_batches_event_count_range` — `CHECK (event_count BETWEEN 1 AND 100)`.
- `event_batches_events_is_array` — `CHECK (jsonb_typeof(events) = 'array')`.

**Indexes**

| Name | Definition | Serves |
|---|---|---|
| PK | `(id)` | Lookup |
| `event_batches_status_received_idx` | `(status, received_at DESC, id DESC)` | Admin listing by status; pruning |

### State transitions

```text
(webhook, same tx as job enqueue) ──► pending
pending ── job succeeds ─────────────────────────► processed  (counts, processed_at)
pending ── job attempt fails (attempts < max) ───► pending    (attempts++, last_error)
pending ── job attempts exhausted ───────────────► failed     (failed_at, last_error)
failed  ── retry (API or CLI) ───────────────────► pending    (attempts := 0, retried_at/by, new job)
failed  ── discard (CLI only) ───────────────────► row deleted (logged)
processed ── older than retention ───────────────► pruned
```

Retry on a `pending` or `processed` batch → `409 Conflict`.

---

## 3. `users` — consumer accounts (Eloquent: `User`, `HasApiTokens`)

Laravel's default `users` migration is adapted: no `password`, no `remember_token`,
no `password_reset_tokens`/`sessions` tables (stateless API, no login).

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigint` identity | no | | |
| `name` | `varchar(255)` | no | | Display / client name |
| `email` | `varchar(255)` | no | | Unique contact identifier |
| `role` | `varchar(16)` | no | `'user'` | `user` \| `admin` (`Role` enum) |
| `created_at` / `updated_at` | `timestamptz` | no | | |

- `users_email_unique` — `UNIQUE (email)`.
- `users_role_valid` — `CHECK (role IN ('user','admin'))`.

## 4. `personal_access_tokens` — Sanctum (unchanged schema)

`tokenable_type`, `tokenable_id`, `name`, `token` (SHA-256 hash, unique), `abilities`,
`last_used_at`, `expires_at`, timestamps. Tokens are created and revoked by artisan commands
only; plaintext is shown once at creation.

## 5. Framework tables

- `jobs`, `job_batches`, `failed_jobs` — `database` queue driver (same connection as the data,
  enabling the transactional hand-off in research R2).
- `cache`, `cache_locks` — `database` cache store (rate limiter).
- `migrations`.

---

## Domain types (PHP)

| Type | Kind | Values / fields |
|---|---|---|
| `CmsEventType` | backed enum (string) | `publish`, `unpublish`, `delete` |
| `ContentStatus` | backed enum (string) | `published`, `unpublished`, `deleted` |
| `BatchStatus` | backed enum (string) | `pending`, `processed`, `failed` |
| `Role` | backed enum (string) | `user`, `admin` |
| `CmsEvent` | readonly value object | `id`, `version`, `timestamp` (`CarbonImmutable`), `type`, `?payload` (raw JSON object) |
| `ContentSnapshot` | readonly value object | `version`, `status` — current state used by precedence |
| `EventDecision` | readonly value object | `apply` (bool), `?ignoreReason`, `?newStatus` |
| `IgnoreReason` | backed enum (string) | `duplicate`, `stale`, `stale_after_delete` |
| `BatchResult` | readonly value object | `applied`, `ignored`, list of per-event decisions (for logs) |

## Validation rules (summary — authoritative list in research R9)

| Field | Rule |
|---|---|
| batch | JSON list, 1–100 items, body ≤ 1 MiB |
| `id` | string, 1–255, `^[A-Za-z0-9][A-Za-z0-9._:-]*$` |
| `version` | strict integer, 1 … 9223372036854775807 |
| `timestamp` | RFC 3339 date-time with offset |
| `type` | `publish` \| `unpublish` \| `delete` |
| `payload` | JSON object, required for publish/unpublish; ignored for delete |
