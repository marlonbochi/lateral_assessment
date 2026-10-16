# Feature Specification: CMS Content Sync Service

**Feature Branch**: `001-cms-content-sync`

**Created**: 2026-10-16

**Status**: Ready for implementation

**Input**: User description: "Build a service that receives CMS webhook events in batches, maintains
a synchronized, version-correct representation of CMS content, supports local administrative
overrides (disable/re-enable) without modifying CMS-managed data, and exposes the content through
a secure, paginated REST API for regular users and administrators." (Source: Senior PHP Engineer
Take-Home Coding Challenge.)

## Clarifications

### Session 2026-10-16

- Q: What should happen when an event with a version higher than the deletion version arrives
  for deleted content? → A: Re-create it — apply it like any newer event (version precedence
  is the single rule); the local disabled flag is preserved.
- Q: How should regular users and administrators authenticate to the consumer API? → A: Opaque
  bearer API tokens issued per consumer account (Laravel Sanctum personal access tokens); the
  account carries the role (user/admin); tokens are stored hashed, revocable, and issued by an
  operator command.
- Q: Should the webhook process the batch inside the request, or queue it for a background
  worker? → A: Asynchronous with a durable inbox — the request validates the whole batch,
  stores it and enqueues its processing in a single transaction, and acknowledges with
  `202 Accepted` and a batch identifier; a background worker applies the batch atomically.
  Data-loss prevention is the service's responsibility once a batch is acknowledged. No batch
  status endpoint for the CMS (the sender does not consume per-event outcomes).
- Q: Which pagination style and ordering should the content listing use? → A: Both — cursor
  pagination by default; clients can opt into page-number pagination (with totals) explicitly.
  Default order: most recently synchronized first, CMS identifier as tie-breaker.
- Q: Who should be able to see and re-process batches that failed? → A: Administrators through
  the API (list, inspect, re-process) and operators through the command line (list,
  re-process, discard). Payloads are never exposed by the batch endpoints.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - CMS delivers content change events (Priority: P1)

The CMS sends batches of content events (publish, unpublish, delete) whenever content changes.
The service authenticates the CMS, validates the whole batch, durably records it, and
acknowledges receipt immediately. Shortly after, the batch is processed in the background:
every event that is newer than what the service already holds is applied, in the order
received, as a single all-or-nothing operation. Duplicates, replays, and stale events are safely
ignored. Once a batch is acknowledged, the service is responsible for never losing it.

**Why this priority**: Without correct ingestion there is no content to serve. Correct event
processing is the highest-priority evaluation criterion and the foundation of every other story.

**Independent Test**: Send batches to the webhook endpoint with CMS credentials, let background
processing complete, and verify the stored state (status, version, content) for each entity,
without using the consumer API. In the scenarios below, "Then" describes the state after the
batch has been processed.

**Acceptance Scenarios**:

1. **Given** no record of entity `content-123`, **When** the CMS sends `publish` v2 for it,
   **Then** the entity is stored as published at version 2 with the supplied content.
2. **Given** `content-123` is stored at version 2, **When** the CMS sends the same `publish` v2
   event again, **Then** the event is ignored as a duplicate and nothing changes.
3. **Given** `content-123` is stored at version 5, **When** the CMS sends `publish` v3,
   **Then** the event is ignored as stale and the stored state remains at version 5.
4. **Given** `content-123` is stored as published at version 3, **When** the CMS sends
   `unpublish` v4 (a version never previously received or published), **Then** the entity is
   stored as unpublished at version 4 with the content supplied in that event.
5. **Given** no record of entity `content-999`, **When** the first event received for it is
   `unpublish` v7, **Then** the entity is stored as unpublished at version 7 with its content.
6. **Given** `content-789` is stored at version 4, **When** the CMS sends `delete` v5,
   **Then** the entity is marked deleted at version 5 and its content is no longer served.
7. **Given** `content-789` was deleted at version 5, **When** the CMS later sends `publish` v4
   (an older event delivered late), **Then** the event is ignored and the entity stays deleted.
8. **Given** no record of entity `content-555`, **When** the first event for it is `delete` v3,
   **Then** a deletion marker at version 3 is recorded so that any later event with version
   ≤ 3 is ignored.
9. **Given** a batch of 3 events where the third is invalid, **When** the CMS sends it,
   **Then** the whole batch is rejected immediately with a description of the validation
   errors, nothing is recorded or queued, and none of the 3 events is applied.
10. **Given** a batch containing `publish` v2 and then `unpublish` v3 for the same entity,
    **When** the CMS sends it, **Then** events are applied in order and the final state is
    unpublished at version 3.
11. **Given** a batch containing `unpublish` v3 and then `publish` v2 for the same entity,
    **When** the CMS sends it, **Then** the first event is applied and the second is ignored
    as stale; the final state is unpublished at version 3.
12. **Given** `content-789` was deleted at version 5 and was locally disabled before deletion,
    **When** the CMS sends `publish` v6, **Then** the entity is re-created as published at
    version 6 with the supplied content and is still locally disabled.
13. **Given** a valid batch, **When** the CMS sends it, **Then** the service responds with an
    "accepted" acknowledgement containing a batch identifier only after the batch is durably
    recorded and its processing is scheduled.
14. **Given** an acknowledged batch whose processing fails (e.g. the database is briefly
    unavailable), **When** the failure occurs, **Then** no partial changes remain and the batch
    is retried automatically; if it still fails after the maximum number of attempts it is
    kept as "failed" and can be re-processed by an operator, so no acknowledged batch is lost.
15. **Given** the service cannot durably record a valid batch (e.g. database unavailable),
    **When** the CMS sends it, **Then** the service responds with a server error so the CMS
    retries delivery.

---

### User Story 2 - Regular user reads published content (Priority: P1)

An authenticated regular user lists content page by page and retrieves individual items. They
only ever see content that is currently published by the CMS and not locally disabled.

**Why this priority**: Serving content to consumers is the purpose of the service; together with
Story 1 it forms the minimum viable product.

**Independent Test**: Seed entities in every state (published, unpublished, deleted, disabled),
authenticate as a regular user, and verify that list and detail responses contain only the
published, enabled items.

**Acceptance Scenarios**:

1. **Given** published, unpublished, deleted, and locally disabled entities exist, **When** a
   regular user requests the content list, **Then** only published and not disabled entities
   are returned, most recently synchronized first, with a cursor to the next page.
2. **Given** an entity is published and enabled, **When** a regular user requests it by its
   CMS identifier, **Then** its current content, version, and last-change information are
   returned.
3. **Given** an entity is unpublished, deleted, locally disabled, or unknown, **When** a regular
   user requests it by identifier, **Then** the response is "not found" (identical for all four
   cases, so the user cannot infer that hidden content exists).
4. **Given** a published entity is visible, **When** the CMS unpublishes or deletes it with a
   newer version, **Then** it disappears from the regular user's list and detail views.
5. **Given** 40 visible entities, **When** a regular user explicitly requests page-number
   pagination with page 2 and page size 15, **Then** items 16–30 are returned along with the
   total (40) and last page (3).

---

### User Story 3 - Administrator reviews content and manages local overrides (Priority: P2)

An authenticated administrator lists and retrieves both published and unpublished content,
including locally disabled content, can filter by state, and can locally disable or re-enable
any non-deleted content item. The override is local to this service, does not alter the CMS
data, and survives subsequent CMS publish/unpublish events.

**Why this priority**: Required capability, but it builds on Stories 1 and 2.

**Independent Test**: Authenticate as an administrator, disable a published item, confirm regular
users no longer see it, send a newer CMS publish event for it, and confirm it stays disabled
until the administrator re-enables it.

**Acceptance Scenarios**:

1. **Given** published, unpublished, disabled, and deleted entities exist, **When** an
   administrator lists content, **Then** published, unpublished, and disabled entities are
   returned, deleted ones are not, and each item shows its publication status and disabled flag.
2. **Given** a published entity, **When** an administrator disables it, **Then** the entity is
   hidden from regular users, remains visible to administrators, and its CMS content and
   version are unchanged.
3. **Given** a disabled entity, **When** the CMS sends a newer `publish` or `unpublish` event,
   **Then** the CMS content and status are updated and the entity remains disabled.
4. **Given** a disabled entity, **When** an administrator re-enables it, **Then** it becomes
   visible to regular users again if, and only if, it is currently published.
5. **Given** a deleted or unknown entity, **When** an administrator tries to disable or enable
   it, **Then** the response is "not found".
6. **Given** an entity is already disabled, **When** an administrator disables it again,
   **Then** the operation succeeds without side effects (idempotent).

---

### User Story 4 - Access is restricted per actor (Priority: P1)

Each actor (CMS, regular user, administrator) can only reach the operations it is entitled to,
using credentials that are kept separate per actor type.

**Why this priority**: Security is a top-priority evaluation criterion and applies to every
endpoint.

**Independent Test**: Call every endpoint with no credentials, wrong credentials, and each
actor's credentials, and verify the allow/deny matrix.

**Acceptance Scenarios**:

1. **Given** no or invalid credentials, **When** any endpoint is called, **Then** the request is
   rejected as unauthenticated and nothing is processed or disclosed.
2. **Given** valid CMS credentials, **When** the CMS calls a consumer endpoint, **Then** the
   request is rejected.
3. **Given** valid regular-user or administrator credentials, **When** they call the webhook
   endpoint, **Then** the request is rejected and no events are processed.
4. **Given** valid regular-user credentials, **When** the user calls an administrator-only
   operation (unpublished content view, disable/enable, event batch management), **Then** the
   request is forbidden.
5. **Given** any consumer, **When** they attempt to create, modify, or delete CMS-managed content
   through the API, **Then** no such operation exists or it is rejected.

---

### User Story 5 - Operators can trace what happened to every event (Priority: P3)

An operator investigating a sync issue can read the service logs to see which events were
applied, which were ignored (and why), and which batches were rejected or failed, without
finding credentials or full content bodies in the logs.

**Why this priority**: Required for operability; does not change functional behaviour.

**Independent Test**: Send batches containing applied, duplicate, stale, and invalid events and
inspect the captured log entries for outcome, reason, identifiers, and absence of secrets.

**Acceptance Scenarios**:

1. **Given** a batch with one new and one duplicate event, **When** it is processed, **Then** one
   "applied" entry and one "ignored (duplicate/stale)" entry are logged, each with entity id,
   event type, incoming version, previously stored version, and a batch correlation identifier.
2. **Given** an invalid batch, **When** it is rejected, **Then** a "batch rejected" entry is
   logged with the validation error summary and no event is logged as applied.
3. **Given** an unexpected processing failure, **When** the batch is rolled back, **Then** a
   failure entry is logged with the batch identifier and attempt number, and a final
   "batch failed" entry is logged when retries are exhausted.
4. **Given** a batch is acknowledged, **Then** a "batch received" entry is logged with the batch
   identifier and event count, and a "batch processed" entry with applied/ignored counts and
   processing duration is logged when it completes.
5. **Given** any log entry, **Then** it contains no credentials, authorization headers, or full
   content payloads.

---

### User Story 6 - Administrators and operators recover failed batches (Priority: P3)

When an acknowledged batch exhausts its automatic retries, an administrator can find it through
the API (or an operator through the command line), inspect why it failed, and trigger
re-processing once the cause is fixed, without database access.

**Why this priority**: Completes the "no acknowledged batch is lost" guarantee with a supported
recovery path; it only matters after a failure, so it builds on Story 1.

**Independent Test**: Force a batch to fail permanently, list failed batches as an
administrator, inspect it, trigger re-processing after removing the fault, and verify the batch
ends as processed and the content state is correct.

**Acceptance Scenarios**:

1. **Given** batches in pending, processed, and failed states, **When** an administrator lists
   batches filtered by status "failed", **Then** only failed batches are returned, each with
   identifier, status, event count, attempt count, last error summary, and received time.
2. **Given** a failed batch, **When** an administrator retrieves it, **Then** the batch metadata
   and a summary of its events (position, content id, type, version) are returned, without the
   content payloads.
3. **Given** a failed batch and the cause of failure has been fixed, **When** an administrator
   requests re-processing, **Then** the request is accepted, the batch is scheduled again, and
   it ends as processed with version-precedence rules applied against the current state.
4. **Given** a pending or already processed batch, **When** an administrator requests
   re-processing, **Then** the request is rejected as a conflict and nothing is scheduled.
5. **Given** the CMS or a regular user, **When** they call any batch management operation,
   **Then** the request is rejected (CMS: unauthenticated for consumer routes; user: forbidden).
6. **Given** an operator with server access, **When** they use the command-line tool, **Then**
   they can list failed batches, re-process one or all of them, and discard a failed batch
   (discarding is command-line only).

---

### Edge Cases

- **Version gap**: an entity jumps from version 3 to version 9; the event is applied normally.
- **First event at arbitrary version**: the first event for an entity may carry any positive
  version and any type (publish, unpublish, or delete).
- **Unpublish of a never-seen version**: applied with the content supplied in the event (Story 1,
  scenarios 4–5).
- **Same entity repeated within one batch**: events are evaluated sequentially against the state
  produced by earlier events in the same batch.
- **Duplicate event with different content**: an event with the same version as the stored one
  is ignored even if its content differs (version is authoritative).
- **Timestamps out of order with versions**: a higher version with an older timestamp still
  wins; timestamps never decide precedence.
- **Late events after delete**: any event with version ≤ the deletion version is ignored.
- **Newer event after delete**: an event with a version greater than the deletion version is
  applied (the CMS re-created or restored the entity); the entity becomes visible again
  according to the new status and its local disabled flag (FR-012a).
- **Concurrent batches for the same entity**: two batches processed at the same time (e.g. by
  parallel workers) produce the same final state as if they had been processed one after the
  other; the higher version always wins.
- **Batches processed in a different order than received**: because precedence is by version,
  the final state is the same regardless of which acknowledged batch is processed first.
- **Same batch delivered twice by the CMS**: both deliveries are acknowledged and recorded; the
  second one's events are all ignored as duplicates during processing.
- **Processing failure after acknowledgement**: the batch's changes are rolled back, the batch
  is retried with backoff, and after the maximum attempts it is kept as failed for operator
  re-processing; it is never silently dropped.
- **Read-after-write**: content becomes visible to consumers once its batch has been processed,
  not at acknowledgement time (eventual consistency, typically within seconds).
- **Batch size limits**: an empty batch or a batch with more than 100 events is rejected.
- **Malformed events**: missing or empty id, non-integer, zero, or negative version, unknown
  type, invalid timestamp, missing or non-object payload on publish/unpublish → batch rejected.
- **Delete with payload**: a payload on a delete event is tolerated and ignored.
- **Arbitrary payload shape**: payloads may contain any JSON properties (nested objects, arrays,
  unicode); they are stored and returned unchanged, with no assumption that `title` or
  `description` exist.
- **Non-JSON or wrong top-level shape** (e.g. an object instead of a list): rejected as invalid.
- **Pagination bounds**: a page size outside 1–100 is rejected; requesting a page number past
  the end returns an empty page with valid metadata; the last cursor page has no next cursor.
- **Content changes while paging**: with cursor pagination, an item synchronized while a client
  pages through the list moves to the top and does not cause other items to be skipped or
  repeated on the following pages.
- **Mixed pagination parameters**: sending a cursor and a page number together is rejected.

## Requirements *(mandatory)*

### Functional Requirements

**Webhook ingestion**

- **FR-001**: System MUST accept CMS event batches at `POST /cms/events` as a JSON list of
  1 to 100 events.
- **FR-002**: System MUST validate every event in a batch: non-empty string `id`, positive
  integer `version`, valid ISO-8601 `timestamp`, `type` in {publish, unpublish, delete}, and a
  JSON object `payload` for publish and unpublish events; payload is optional for delete.
- **FR-003**: Validation MUST happen before acknowledgement. If any event in a batch is invalid,
  the system MUST reject the entire batch immediately, record and apply nothing, and return
  validation errors that identify the offending event position and field.
- **FR-004**: System MUST process a valid batch atomically: either all applicable changes in the
  batch are persisted or none are (e.g. on an unexpected failure).
- **FR-005**: System MUST process events within a batch in the order received.
- **FR-006**: System MUST acknowledge a valid batch with an "accepted" response containing a
  batch identifier, and MUST send that acknowledgement only after the batch has been durably
  recorded and its processing scheduled as one atomic step.
- **FR-006a**: Batch processing MUST happen asynchronously, outside the webhook request.
- **FR-006b**: If the batch cannot be durably recorded, the system MUST respond with a server
  error so the CMS retries delivery.
- **FR-006c**: A batch whose processing fails MUST be retried automatically with backoff; after
  the maximum number of attempts it MUST be retained with a "failed" status, and operators MUST
  be able to re-process failed batches. No acknowledged batch may be silently lost.
- **FR-006d**: Each recorded batch MUST track its lifecycle status (pending, processed, failed),
  attempt count, received and processed times, and per-batch applied/ignored counts.

**Event processing rules**

- **FR-007**: System MUST maintain, for each content entity, the latest accepted version, its
  CMS status (published, unpublished, or deleted), and the latest supplied content.
- **FR-008**: System MUST determine precedence solely by version; timestamps MUST NOT affect
  precedence.
- **FR-009**: System MUST ignore any event whose version is less than or equal to the latest
  accepted version for that entity (duplicates and stale/out-of-order events).
- **FR-010**: A `publish` event with a newer version MUST store the supplied content and mark the
  entity as published.
- **FR-011**: An `unpublish` event with a newer version MUST store the supplied content and mark
  the entity as unpublished, even if that version (or the entity) was never previously received.
- **FR-012**: A `delete` event with a newer version MUST mark the entity as deleted, record the
  deletion version, and remove the content from all consumer access; the record of the deletion
  MUST persist so that events with version ≤ the deletion version are ignored.
- **FR-012a**: A `publish` or `unpublish` event whose version is greater than the recorded
  deletion version MUST re-create the entity with the supplied content and status; the local
  disabled flag MUST be preserved across deletion and re-creation.
- **FR-013**: The first event received for an entity MUST be accepted for any positive version
  and any event type.
- **FR-014**: System MUST guarantee that concurrent processing of batches affecting the same
  entity cannot cause a lower version to overwrite a higher one or lose an update, regardless of
  the number of parallel workers.

**Authentication and authorization**

- **FR-015**: Every endpoint MUST require authentication.
- **FR-016**: The webhook MUST use HTTP Basic Authentication with credentials supplied through
  environment configuration; these credentials MUST be separate from consumer credentials and
  MUST NOT be committed to the repository.
- **FR-017**: CMS credentials MUST NOT grant access to any consumer endpoint, and consumer
  credentials MUST NOT grant access to the webhook.
- **FR-018**: Consumers MUST authenticate with an opaque bearer token issued to their consumer
  account; the account carries a role of either *user* or *administrator*. Tokens MUST be
  stored only in hashed form, MUST be individually revocable, and MUST be issued by an operator
  (no self-service token endpoint). The mechanism MUST be documented.
- **FR-018a**: Revoked tokens, tokens of removed accounts, and malformed tokens MUST be rejected
  as unauthenticated.
- **FR-019**: Authentication failures MUST return an "unauthenticated" response; authenticated
  requests lacking the required role MUST return a "forbidden" response.

**Consumer API**

- **FR-020**: System MUST provide a paginated content listing and a retrieval-by-identifier
  operation, identifying content by its CMS identifier.
- **FR-021**: Regular users MUST only see content that is published and not locally disabled.
- **FR-022**: Administrators MUST see published and unpublished content, including locally
  disabled content, and MUST be able to filter the listing by publication status and by
  disabled flag.
- **FR-023**: Deleted content MUST NOT be returned to any consumer, in listings or retrieval.
- **FR-024**: Content hidden from the requester (unpublished, disabled, deleted, or unknown)
  MUST produce the same "not found" response on retrieval.
- **FR-025**: Listings MUST return items in a stable, deterministic order: most recently
  synchronized first, with the CMS identifier as tie-breaker. Page size MUST be bounded
  (default 15, maximum 100); values outside 1–100 MUST be rejected as invalid input.
- **FR-025a**: By default, listings MUST use cursor pagination: the response includes opaque
  next/previous cursors and the page size, and never skips or repeats items when content changes
  between page requests. No total count is computed in this mode.
- **FR-025b**: Clients MUST be able to explicitly request page-number pagination instead; the
  response then includes current page, page size, total items, and last page. Requesting both
  modes at once (a cursor and a page number) MUST be rejected as invalid input.
- **FR-025c**: Invalid or tampered cursors MUST be rejected as invalid input, not cause a server
  error.
- **FR-026**: Each content item MUST expose its CMS identifier, version, CMS content (payload as
  received), and last-synchronized time; administrator responses MUST additionally expose the
  publication status and local disabled flag.
- **FR-027**: Administrators MUST be able to locally disable and re-enable any non-deleted
  content item; both operations MUST be idempotent.
- **FR-028**: The local disabled flag MUST be independent of CMS state and MUST NOT be changed by
  any incoming publish or unpublish event.
- **FR-029**: The consumer API MUST NOT offer any operation that creates, modifies, or deletes
  CMS-managed content (identifier, version, status, payload).

**Observability**

- **FR-030**: System MUST log each received batch, each applied event, each ignored event (with
  reason), each rejected batch, each processing failure and retry, and each batch that exhausts
  its retries, with contextual information: batch identifier, attempt number, entity id, event
  type, incoming version, previously stored version, and outcome.
- **FR-031**: Logs MUST NOT contain credentials, authorization headers, or full content
  payloads.
- **FR-032**: System MUST log administrator override changes (who, which entity, new value) and
  batch re-processing or discard requests (who, which batch, origin: API or command line).

**Event batch management**

- **FR-032a**: Administrators MUST be able to list recorded event batches through the API with
  pagination and a status filter, and retrieve a single batch with its metadata and an event
  summary (position, content id, type, version). Content payloads MUST NOT be exposed through
  these operations.
- **FR-032b**: Administrators MUST be able to request re-processing of a failed batch through
  the API; requests for batches that are not in the failed state MUST be rejected as a conflict.
  Re-processing resets the attempt counter and follows the same rules as the original
  processing (atomic, in order, version precedence against the current state).
- **FR-032c**: Operators MUST be able to list failed batches, re-process one or all failed
  batches, and discard a failed batch through a command-line tool. Discarding is not available
  through the API.
- **FR-032d**: Batch management operations MUST NOT allow editing a batch's events.

**Documentation and delivery**

- **FR-033**: The webhook and consumer API contracts MUST be documented, including request and
  response formats, authentication, status codes, and error formats.
- **FR-034**: The repository MUST include setup, configuration, run, and test instructions, an
  example environment file without real secrets, and an explanation of the data model, event
  processing approach, architectural decisions, trade-offs, and known limitations.

### Key Entities *(include if feature involves data)*

- **Content Item**: The local replica of one CMS entity, identified by its CMS identifier.
  Holds the latest accepted version, the CMS status (published / unpublished / deleted), the
  latest supplied content (arbitrary structured data; absent after deletion), the timestamp
  carried by the last applied event, when it was last synchronized, and the local disabled flag
  (with who/when it was last changed). A deleted item remains as a tombstone that keeps its
  deletion version.
- **Event Batch** (inbox): One acknowledged delivery from the CMS — batch identifier, the
  ordered list of events exactly as received, lifecycle status (pending, processed, failed),
  attempt count, last error summary, received and processed times, and applied/ignored counts.
  It is the durable record that guarantees no acknowledged delivery is lost.
- **CMS Event**: One change notification inside a batch — identifier, version, timestamp, type,
  and optional content. Stored only as part of its batch; its individual outcome is recorded in
  logs.
- **Consumer**: An API client account with a name, a role of *user* or *administrator*, and
  zero or more revocable bearer tokens, separate from the CMS credentials.
- **Access Token**: A revocable credential belonging to one consumer; only its hash is stored;
  records when it was created and last used.
- **CMS Client** (configuration only): The single webhook sender, authenticated with credentials
  supplied by environment configuration.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For any permutation and duplication of a given set of events (including replays
  of whole batches), the final stored state of every entity equals the state implied by its
  highest-version event — verified by automated tests over shuffled and duplicated sequences.
- **SC-002**: 100% of batches containing at least one invalid event are rejected with zero
  persisted changes.
- **SC-003**: 0 instances of deleted content being returned or restored by events with a version
  less than or equal to the deletion version, across all automated tests.
- **SC-004**: 100% of endpoint/actor combinations in the access matrix behave as specified
  (allowed, unauthenticated, or forbidden), verified by automated tests.
- **SC-005**: A valid batch of 100 events is acknowledged in under 300 ms, and is fully
  processed within 5 seconds of acknowledgement under normal load with one worker, on a
  standard development machine.
- **SC-005a**: 0 acknowledged batches are lost when processing fails transiently: every
  acknowledged batch ends either processed or retained as failed and re-processable, verified
  by automated tests that inject processing failures.
- **SC-006**: Consumer listing and retrieval requests respond in under 300 ms at the 95th
  percentile with 100,000 stored content items on a standard development machine.
- **SC-007**: A new developer can start the service and run the full test suite by following
  the README in under 10 minutes, with a single command for each.
- **SC-008**: 0 credentials or full content payloads appear in logs produced by the automated
  test suite.

## Assumptions

- The CMS identifier (`id`) is a string, unique per entity across all content types, at most
  255 characters.
- Versions are strictly increasing per entity on the CMS side; two different events for the same
  entity never share a version, so an equal version is always treated as a duplicate.
- Unpublished content keeps the content supplied in the unpublish event (not the previously
  published content), since the CMS sends the latest content with every unpublish.
- Event processing is asynchronous: a webhook sender only needs to know its delivery was
  accepted, and responsibility for not losing data moves to this service once it acknowledges.
  The CMS does not need per-event outcomes or a batch status endpoint; the trade-offs
  (eventual consistency, worker operations) are documented in the plan.
- An "accepted" response means the batch is durably recorded and scheduled, not yet applied.
  The CMS is expected to retry on server errors and network failures, which is safe because
  processing is idempotent.
- Recorded batches are retained for a limited period after successful processing (default
  30 days, configurable) for troubleshooting and then pruned; failed batches are retained until
  re-processed or manually discarded.
- Rejected batches are not partially retried by the service; correcting and resending is the
  CMS's responsibility.
- Consumer accounts (users and administrators) and their tokens are provisioned by an operator
  through a command-line tool or seed data; self-registration, password login, and token
  self-service are out of scope.
- Content search, full-text filtering, payload field projection, and content history/audit
  trails beyond logs are out of scope for this version.
- There is a single CMS sender; multi-tenant CMS support is out of scope.
