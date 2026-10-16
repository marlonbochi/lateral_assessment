# Quickstart & Validation Guide: CMS Content Sync Service

**Feature**: [spec.md](spec.md) | **Contract**: [contracts/openapi.yaml](contracts/openapi.yaml) |
**Data model**: [data-model.md](data-model.md)

This guide proves the feature works end to end. Commands assume the repository root; the
`make` targets are thin wrappers around `docker compose` (see the README once implemented).

## Prerequisites

- Docker 24+ with Docker Compose v2
- `curl` and `jq` (for the manual scenarios)
- Free ports: `8080` (HTTP, `APP_PORT`) and `54329` (PostgreSQL on the host, `DB_FORWARD_PORT`)

## 1. Boot the environment

```bash
make setup                      # creates .env from .env.example if missing, builds, starts nginx,
                                # app, worker, scheduler, postgres, migrates cms_sync, seeds demo data
```

Then set real values for `CMS_WEBHOOK_USERNAME` / `CMS_WEBHOOK_PASSWORD` in `.env` and run
`make up` to apply them.

Expected: `curl -s -o /dev/null -w '%{http_code}' localhost:8080/up` prints `200`.

## 2. Provision consumers and tokens

```bash
docker compose exec app php artisan consumer:create "Reader" reader@example.test --role=user
docker compose exec app php artisan consumer:create "Editor" admin@example.test --role=admin
docker compose exec app php artisan consumer:token reader@example.test   # prints token once
docker compose exec app php artisan consumer:token admin@example.test
```

Export them for the scenarios below:

```bash
export CMS_AUTH="$CMS_WEBHOOK_USERNAME:$CMS_WEBHOOK_PASSWORD"
export USER_TOKEN="cms_..."  ADMIN_TOKEN="cms_..."
```

## 3. Validation scenarios

Each scenario lists the request(s) and the expected observable outcome. Requests and response
shapes are defined in the [OpenAPI contract](contracts/openapi.yaml).

| # | Scenario | Action | Expected |
|---|---|---|---|
| S1 | Webhook auth | `POST /cms/events` with no auth, wrong password, and a bearer token | `401` each, `WWW-Authenticate: Basic`, nothing stored |
| S2 | Accept batch | `POST /cms/events` with the brief's 3-event example and `-u $CMS_AUTH` | `202` with `batch_id`; batch appears as `processed` within seconds in `GET /api/v1/admin/event-batches` |
| S3 | Invalid batch | Same batch with the 3rd event's `version` set to `0` | `422` problem details with `errors["events.2.version"]`; no new batch, no content change |
| S4 | Publish visible | `GET /api/v1/contents/content-123` as user | `200`, `version: 2`, `content.title = "Example content"` |
| S5 | Duplicate / stale | Re-send `publish content-123 v2`, then `publish v1` with a different title | Both `202`; content still v2 with the original title; logs show `cms.event.ignored` reasons `duplicate` and `stale` |
| S6 | Unpublish edge case | Send `publish content-900 v3`, then `unpublish content-900 v4` (v4 never published) | User: `404`; admin: `200`, `status: unpublished`, `version: 4`, content from the unpublish event |
| S7 | Delete + late event | Send `delete content-123 v5`, then `publish content-123 v4` | User and admin: `404`; tombstone stays at v5 |
| S8 | Re-create after delete | Send `publish content-123 v6` | Visible again at v6 |
| S9 | Local override | Admin `PUT /api/v1/contents/content-123/disabled`; then CMS `publish content-123 v7` | User: `404`; admin: `local_override.disabled = true`, `version: 7`; after admin `DELETE .../disabled`, user sees it again |
| S10 | Role enforcement | User calls `PUT /api/v1/contents/content-123/disabled` and `GET /api/v1/contents?status=unpublished`; CMS credentials call `GET /api/v1/contents` | `403`, `403`, `401` |
| S11 | Pagination | Seed 40 items; `GET /api/v1/contents?per_page=15` then follow `meta.next_cursor`; then `?page=2&per_page=15` | Cursor pages of 15/15/10 with no duplicates; page mode returns `total: 40`, `last_page: 3` |
| S12 | Failed batch recovery | Stop the DB for the worker only (or use the failure-injection test), let retries exhaust; `POST /api/v1/admin/event-batches/{id}/retry` | Batch `failed` → `202` → `processed`; content state correct; retry on a processed batch → `409` |
| S13 | Log hygiene | `docker compose logs worker app \| grep -Ei 'authorization\|password\|"title"'` | No matches |

Example for S2:

```bash
curl -s -u "$CMS_AUTH" -H 'Content-Type: application/json' \
  -d @docs/examples/batch.json localhost:8080/cms/events | jq
```

## 4. Automated validation

```bash
make test        # Pest (unit + feature) against the isolated cms_sync_test database
make lint        # Pint --test + Larastan
make ci          # everything CI runs, locally
```

Expected: all green. The automated suite covers S1–S13 plus permutation/concurrency tests;
the manual table above is for reviewers who want to see the behaviour by hand.

## 5. Performance spot-check (SC-005 / SC-006)

```bash
make perf        # seeds 100k content rows into the test database and runs the performance group
```

Expected: listing queries use `contents_user_feed_idx` / `contents_admin_feed_idx` (index scan,
no sort node); list/detail p95 < 300 ms; a 100-event batch is acknowledged in < 300 ms and
processed in < 5 s. Measured numbers are written to `storage/logs/performance.json`.
