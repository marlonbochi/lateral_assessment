# Feature Specification: CMS Content Sync Service

**Feature Branch**: `001-cms-content-sync`

**Created**: 2026-10-16

**Status**: Draft

**Input**: User description: "Build a service that receives CMS webhook events in batches, maintains
a synchronized, version-correct representation of CMS content, supports local administrative
overrides (disable/re-enable) without modifying CMS-managed data, and exposes the content through
a secure, paginated REST API for regular users and administrators." (Source: Senior PHP Engineer
Take-Home Coding Challenge.)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - CMS delivers content change events (Priority: P1)

The CMS sends batches of content events (publish, unpublish, delete) whenever content changes.
The service authenticates the CMS, validates the whole batch, and applies every event that is
newer than what it already holds, in the order received, as a single all-or-nothing operation.
Duplicates, replays, and stale events are safely ignored. The CMS receives a response that tells
it whether the batch was accepted and how each event was handled.

**Why this priority**: Without correct ingestion there is no content to serve. Correct event
processing is the highest-priority evaluation criterion and the foundation of every other story.

**Independent Test**: Send batches to the webhook endpoint with CMS credentials and verify the
stored state (status, version, content) for each entity, without using the consumer API.

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
   **Then** the whole batch is rejected with a description of the validation errors, and none
   of the 3 events is applied.
10. **Given** a batch containing `publish` v2 and then `unpublish` v3 for the same entity,
    **When** the CMS sends it, **Then** events are applied in order and the final state is
    unpublished at version 3.
11. **Given** a batch containing `unpublish` v3 and then `publish` v2 for the same entity,
    **When** the CMS sends it, **Then** the first event is applied and the second is ignored
    as stale; the final state is unpublished at version 3.

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
   are returned, with pagination metadata.
2. **Given** an entity is published and enabled, **When** a regular user requests it by its
   CMS identifier, **Then** its current content, version, and last-change information are
   returned.
3. **Given** an entity is unpublished, deleted, locally disabled, or unknown, **When** a regular
   user requests it by identifier, **Then** the response is "not found" (identical for all four
   cases, so the user cannot infer that hidden content exists).
4. **Given** a published entity is visible, **When** the CMS unpublishes or deletes it with a
   newer version, **Then** it disappears from the regular user's list and detail views.

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
   operation (unpublished content view, disable/enable), **Then** the request is forbidden.
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
   failure entry is logged and the CMS receives a server error so it can retry.
4. **Given** any log entry, **Then** it contains no credentials, authorization headers, or full
   content payloads.

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
  according to the new status.
- **Concurrent batches for the same entity**: two deliveries processed at the same time produce
  the same final state as if they had been processed one after the other; the higher version
  always wins.
- **Batch size limits**: an empty batch or a batch with more than 100 events is rejected.
- **Malformed events**: missing or empty id, non-integer, zero, or negative version, unknown
  type, invalid timestamp, missing or non-object payload on publish/unpublish → batch rejected.
- **Delete with payload**: a payload on a delete event is tolerated and ignored.
- **Arbitrary payload shape**: payloads may contain any JSON properties (nested objects, arrays,
  unicode); they are stored and returned unchanged, with no assumption that `title` or
  `description` exist.
- **Non-JSON or wrong top-level shape** (e.g. an object instead of a list): rejected as invalid.
- **Pagination bounds**: requesting a page size above the maximum is capped or rejected;
  requesting a page past the end returns an empty page with valid metadata.

## Requirements *(mandatory)*

### Functional Requirements

**Webhook ingestion**

- **FR-001**: System MUST accept CMS event batches at `POST /cms/events` as a JSON list of
  1 to 100 events.
- **FR-002**: System MUST validate every event in a batch: non-empty string `id`, positive
  integer `version`, valid ISO-8601 `timestamp`, `type` in {publish, unpublish, delete}, and a
  JSON object `payload` for publish and unpublish events; payload is optional for delete.
- **FR-003**: If any event in a batch is invalid, the system MUST reject the entire batch,
  apply no changes, and return validation errors that identify the offending event position
  and field.
- **FR-004**: System MUST process a valid batch atomically: either all applicable changes in the
  batch are persisted or none are (e.g. on an unexpected failure).
- **FR-005**: System MUST process events within a batch in the order received.
- **FR-006**: System MUST respond to an accepted batch with a per-event outcome (applied or
  ignored, with the ignore reason) in the same order as the request.

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
- **FR-013**: The first event received for an entity MUST be accepted for any positive version
  and any event type.
- **FR-014**: System MUST guarantee that concurrent requests affecting the same entity cannot
  cause a lower version to overwrite a higher one or lose an update.

**Authentication and authorization**

- **FR-015**: Every endpoint MUST require authentication.
- **FR-016**: The webhook MUST use HTTP Basic Authentication with credentials supplied through
  environment configuration; these credentials MUST be separate from consumer credentials and
  MUST NOT be committed to the repository.
- **FR-017**: CMS credentials MUST NOT grant access to any consumer endpoint, and consumer
  credentials MUST NOT grant access to the webhook.
- **FR-018**: Consumers MUST be authenticated with per-consumer credentials that carry a role of
  either *user* or *administrator*; the chosen mechanism MUST be documented.
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
- **FR-025**: Listings MUST return items in a stable, deterministic order and include pagination
  metadata (current page, page size, total or next-page indicator); page size MUST be bounded
  (default 15, maximum 100).
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

- **FR-030**: System MUST log each applied event, each ignored event (with reason), each
  rejected batch, and each processing failure, with contextual information: batch correlation
  id, entity id, event type, incoming version, previously stored version, and outcome.
- **FR-031**: Logs MUST NOT contain credentials, authorization headers, or full content
  payloads.
- **FR-032**: System MUST log administrator override changes (who, which entity, new value).

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
- **CMS Event** (transient): One change notification from the CMS — identifier, version,
  timestamp, type, and optional content. Not required to be stored permanently; its outcome is
  recorded in logs.
- **Consumer**: An authenticated API client with a role of *user* or *administrator* and its
  own credentials, separate from the CMS credentials.
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
- **SC-005**: A batch of 100 events is fully processed and acknowledged in under 2 seconds on a
  standard development machine.
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
- An event with a version greater than a recorded deletion version is treated as the CMS
  re-creating the entity and is applied; the local disabled flag is preserved across delete and
  re-creation because it is independent of CMS state.
- Unpublished content keeps the content supplied in the unpublish event (not the previously
  published content), since the CMS sends the latest content with every unpublish.
- Event processing is synchronous within the webhook request so the CMS gets an authoritative
  per-event outcome; the trade-offs versus asynchronous processing are documented in the plan.
- A successful (accepted) batch response means all applicable events are durably stored; the CMS
  is expected to retry on server errors and network failures, which is safe because processing
  is idempotent.
- Rejected batches are not partially retried by the service; correcting and resending is the
  CMS's responsibility.
- Consumer accounts (users and administrators) are provisioned by an operator through a
  command-line tool or seed data; self-registration is out of scope.
- Content search, full-text filtering, payload field projection, and content history/audit
  trails beyond logs are out of scope for this version.
- There is a single CMS sender; multi-tenant CMS support is out of scope.
