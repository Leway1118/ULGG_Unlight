# Tracker ROOM/CDP Diagnostics Sprint Spec v0.1

**Project:** Unlight Tracker  
**Audience:** Codex implementation agent  
**Status:** Ready for implementation  
**Date:** 2026-07-29  
**Scope:** Local Tracker API, SQLite persistence, diagnostics UI, CLI/test tooling  
**Out of scope:** Official ULGG Match authority, Custom BP, Ruleset registry authority, production CDP integration

---

## 1. Sprint Objective

Add ROOM observation and CDP diagnostics support to the existing Tracker without changing the authority model of the current Tracker battle flow.

The first-phase implementation must:

1. Accept ROOM observation snapshots from an authorized local native writer.
2. Accept partial CDP diagnostic updates from an authorized local native writer.
3. Persist current state and meaningful history in SQLite.
4. Derive freshness state from server receipt time.
5. Expose a read-only diagnostics aggregate API.
6. Render ROOM/CDP information in the Tracker diagnostics panel.
7. Provide isolated tests and CLI fixtures.
8. Leave the existing Tracker game/session/stage/event authority unchanged.

The core architectural rule is:

> ROOM/CDP data is local observation and diagnostic data only. It is not the authority for ULGG Match identity, winner/result validation, Ruleset legality, Evidence acceptance, arena mapping, or Custom BP.

---

## 2. Frozen Architecture Decisions

### 2.1 Observation and Match Authority Are Separate

The following identities must remain distinct:

- `ulgg_room_id`: Website Lobby room identifier.
- `game_room_id`: Game/server-observed room identifier.
- `ulgg_match_id`: Future official Match authority identifier.
- `arena_unlight.room_id`: Existing historical battle field; must not be reused as any of the above.
- `tracker_session_id`: Existing Tracker runtime session identifier; optional diagnostic linkage only.

ROOM/CDP observations must not create or assert an official `ulgg_match_id`.

### 2.2 ROOM/CDP Must Not Drive Existing Tracker Authority

Phase 1 ROOM/CDP data must not:

- Start or end the Tracker session.
- Emit or suppress existing card events.
- Change `gameStart`, `result`, stage mapping, or battle state-machine authority.
- Confirm a winner.
- Confirm a valid Ruleset.
- Change official BP or Custom Point.
- Reopen or close an official match.
- Write directly into existing `events` as if diagnostics were domain events.

### 2.3 Lifecycle, Freshness, and Error Are Separate Dimensions

ROOM must expose:

```text
observed_room_state:
UNKNOWN
WAITING
MATCHED
STARTING
ACTIVE
ENDING
ENDED
```

```text
freshness_state:
FRESH
STALE
EXPIRED
```

```text
observation_status:
OK
ERROR
```

CDP must expose independent dimensions:

```text
connection_state:
DISCONNECTED
CONNECTING
CONNECTED
ERROR
```

```text
context_state:
NOT_FOUND
SEARCHING
FOUND
LOST
ERROR
```

```text
injection_state:
NOT_STARTED
INJECTING
INJECTED
FAILED
```

```text
ruleset_apply_state:
NOT_STARTED
WAITING_ASSET
APPLYING
APPLIED
PARTIAL
FAILED
```

```text
freshness_state:
FRESH
STALE
EXPIRED
```

`STALE` and `EXPIRED` are server-derived freshness values. Writers must not submit them as lifecycle or connection states.

### 2.4 No Multi-Source Ownership Arbitration in Phase 1

Do not implement:

- `ULGG > CDP > Sniffer > manual` whole-record priority.
- `409 SOURCE_PRIORITY_CONFLICT`.
- Automatic writer takeover after expiry.
- Client-declared trusted `source` values.

Each diagnostic category has one authorized writer identity in Phase 1.

Recommended producer identities:

- `room-observer`
- `cdp-adapter`
- `test-cli` only when test mode is enabled

The server derives `producer_id` from credentials. It must not trust a request-body string such as `"source": "ULGG"`.

### 2.5 Identity Namespaces Must Be Explicit

Do not use ambiguous fields such as:

```text
host_user_id
opponent_user_id
```

Use:

```text
ulgg_creator_user_id
ulgg_opponent_user_id
game_host_player_id
game_opponent_player_id
host_display_name
opponent_display_name
```

Phase 1 ROOM observer/CDP writers must not claim ULGG user IDs. ULGG user IDs may remain `null` until a future authenticated Match binding exists.

### 2.6 Ruleset Validation Is Layered

Use the existing contract name:

```text
content_hash
```

Phase 1 may only report:

```text
format_valid
```

Reserve future fields:

```text
registry_match
content_hash_match
enforcement_result
```

Do not describe Phase 1 format checks as “Ruleset verified”.

Do not hard-freeze `rule_version_id` as `name@semver` until the shared schema contract is finalized.

If `content_hash` is present and claims SHA-256 format, validate:

```text
^sha256:[0-9a-f]{64}$
```

### 2.7 COST Application Uses Final Compliance

Do not define success as:

```text
changed_count == expected_count
```

Track:

```text
expected_count
matched_count
changed_count
already_compliant_count
missing_count
invalid_count
verified_count
```

Recommended invariant:

```text
verified_count = changed_count + already_compliant_count
```

`APPLIED` means:

```text
matched_count == expected_count
verified_count == expected_count
missing_count == 0
invalid_count == 0
```

Do not hard-code an expected total such as `638`; it depends on the supplied Ruleset and asset version.

---

## 3. Runtime Credential Model

### 3.1 Token Authority

The Tracker API process generates and owns runtime writer credentials.

The Launcher may:

- Start a Tracker API process.
- Detect and reuse an existing compatible Tracker API.
- Read non-sensitive runtime metadata.
- Pass the writer credential location to native child processes.

The Launcher must not invent an independent authoritative token when reusing an existing API.

### 3.2 Token Delivery

Writer tokens must be delivered only to local native processes.

Do not expose writer tokens through:

- Browser JavaScript.
- URL query parameters.
- stdout/stderr.
- diagnostics API responses.
- SQLite history.
- project repository files.
- committed configuration.
- command-line arguments where process listings may reveal them.

Recommended Windows location:

```text
%LOCALAPPDATA%\ULGGTracker\runtime\
```

Suggested files:

```text
runtime.json
room_observer.token
cdp_adapter.token
```

`runtime.json` may contain non-sensitive metadata:

```json
{
  "pid": 12345,
  "port": 8765,
  "started_at": "2026-07-29T23:00:00+08:00",
  "api_version": "v1"
}
```

Token files should be restricted to the current Windows user where feasible.

### 3.3 Producer-Specific Permissions

Recommended mapping:

```text
room_observer.token
→ producer_id = room-observer
→ PUT/DELETE /api/v1/room/observation

cdp_adapter.token
→ producer_id = cdp-adapter
→ PATCH /api/v1/cdp/status

test token
→ producer_id = test-cli
→ only enabled in explicit test/dev mode
```

The server must derive producer identity from the credential, not request body.

### 3.4 Local Request Checks

Mutation endpoints must require both:

1. Loopback source address: `127.0.0.1` or `::1`.
2. A valid producer credential authorized for that endpoint.

Read-only diagnostics permissions may follow the existing Tracker policy; do not expose writer tokens to the browser.

---

## 4. API Design

Do not expand `/health` into a large diagnostic aggregate.

Keep `/health` stable and limited to process/API compatibility and basic persistence health.

Add:

```text
GET /api/v1/diagnostics/status
```

### 4.1 ROOM Observation

```text
GET    /api/v1/room/observation
PUT    /api/v1/room/observation
DELETE /api/v1/room/observation
```

`PUT` represents a complete replacement of the current ROOM observation.

#### Required request keys

Keys must be present even where values are `null`:

```text
observed_room_state
raw_state
game_room_id
platform
channel
room_name
game_host_player_id
game_opponent_player_id
host_display_name
opponent_display_name
rule_version_id
content_hash
tracker_session_id
client_observed_at
```

Reserved ULGG binding fields may be returned by the API but must not be writable by the ROOM observer:

```text
ulgg_room_id
ulgg_creator_user_id
ulgg_opponent_user_id
ulgg_match_id
```

#### Example request

```json
{
  "observed_room_state": "WAITING",
  "raw_state": "waiting",
  "game_room_id": "DMM_ROOM4_12345",
  "platform": "DMM",
  "channel": "room4",
  "room_name": "西瓜版公開對戰",
  "game_host_player_id": "game-player-001",
  "game_opponent_player_id": null,
  "host_display_name": "Way",
  "opponent_display_name": null,
  "rule_version_id": "ulgg-watermelon-126@1.26.0",
  "content_hash": null,
  "tracker_session_id": null,
  "client_observed_at": "2026-07-29T23:00:00+08:00"
}
```

#### Replacement semantics

For `PUT`:

- The request is a full snapshot.
- Missing required keys cause validation failure.
- Explicit `null` clears the field.
- The API records its own `server_received_at`.
- Freshness is computed from `server_received_at`, not client time.

#### Reset semantics

`DELETE /api/v1/room/observation`:

- Clears current ROOM observation.
- Returns the diagnostic state to no-current-observation/`UNKNOWN`.
- Preserves history.
- Appends a reset history entry.
- Does not end the Tracker session.

### 4.2 CDP Diagnostic Status

```text
GET   /api/v1/cdp/status
PATCH /api/v1/cdp/status
```

`PATCH` allows partial updates.

Semantics:

- Field omitted: preserve current value.
- Field present as `null`: clear current value.
- Server sets `server_received_at`.
- Current error may be cleared with `"error": null`.

Recommended request fields:

```text
connection_state
context_state
target
context_id
injection_state
ruleset_apply_state
rule_version_id
content_hash
format_valid
registry_match
content_hash_match
enforcement_result
expected_count
matched_count
changed_count
already_compliant_count
missing_count
invalid_count
verified_count
client_observed_at
error
```

#### Example partial update

```json
{
  "connection_state": "CONNECTED",
  "context_state": "FOUND",
  "target": "Unlight game iframe",
  "context_id": 7,
  "client_observed_at": "2026-07-29T23:00:10+08:00"
}
```

#### Example successful application update

```json
{
  "injection_state": "INJECTED",
  "ruleset_apply_state": "APPLIED",
  "rule_version_id": "ulgg-watermelon-126@1.26.0",
  "content_hash": "sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
  "format_valid": true,
  "registry_match": null,
  "content_hash_match": null,
  "enforcement_result": null,
  "expected_count": 638,
  "matched_count": 638,
  "changed_count": 620,
  "already_compliant_count": 18,
  "missing_count": 0,
  "invalid_count": 0,
  "verified_count": 638,
  "error": null,
  "client_observed_at": "2026-07-29T23:00:15+08:00"
}
```

### 4.3 Diagnostics Aggregate

```text
GET /api/v1/diagnostics/status
```

Recommended response:

```json
{
  "room_observation": {
    "producer_id": "room-observer",
    "observed_room_state": "ACTIVE",
    "freshness_state": "STALE",
    "observation_status": "OK",
    "raw_state": "battle",
    "game_room_id": "DMM_ROOM4_12345",
    "platform": "DMM",
    "channel": "room4",
    "room_name": "西瓜版公開對戰",
    "game_host_player_id": "game-player-001",
    "game_opponent_player_id": "game-player-002",
    "host_display_name": "Way",
    "opponent_display_name": "Noir",
    "rule_version_id": "ulgg-watermelon-126@1.26.0",
    "content_hash": null,
    "tracker_session_id": null,
    "client_observed_at": "2026-07-29T23:00:00+08:00",
    "server_received_at": "2026-07-29T23:00:01+08:00",
    "age_seconds": 46
  },
  "cdp_diagnostic": {
    "producer_id": "cdp-adapter",
    "connection_state": "CONNECTED",
    "context_state": "FOUND",
    "injection_state": "INJECTED",
    "ruleset_apply_state": "APPLIED",
    "freshness_state": "FRESH",
    "target": "Unlight game iframe",
    "context_id": 7,
    "expected_count": 638,
    "matched_count": 638,
    "changed_count": 620,
    "already_compliant_count": 18,
    "missing_count": 0,
    "invalid_count": 0,
    "verified_count": 638,
    "error": null,
    "server_received_at": "2026-07-29T23:00:15+08:00",
    "age_seconds": 4
  },
  "server_time": "2026-07-29T23:00:19+08:00"
}
```

Do not include browser-only IndexedDB stage state unless a real server submission or derivation mechanism exists.

### 4.4 Existing API Envelope

Do not introduce a global `{ok, data, error}` envelope as part of this sprint.

Follow existing Tracker API v1 conventions:

- Success responses are endpoint-specific objects.
- Errors use the existing `error.code`, `error.message`, and `error.details` format.
- FastAPI request-schema validation may continue returning HTTP 422.

A universal envelope, if desired, belongs to a future API v2 breaking change.

---

## 5. Freshness Rules

Freshness thresholds are configuration, not schema constants.

Recommended defaults:

```text
ROOM heartbeat interval: 10 seconds
ROOM stale after: 30 seconds
ROOM expire after: 120 seconds

CDP heartbeat interval: 15 seconds
CDP stale after: 45 seconds
CDP expire after: 120 seconds
```

Suggested configuration keys:

```text
room_stale_after_seconds
room_expire_after_seconds
cdp_stale_after_seconds
cdp_expire_after_seconds
```

Rules:

- Use `server_received_at` for freshness calculation.
- Never overwrite `observed_room_state` with `STALE` or `EXPIRED`.
- Never overwrite `connection_state` with freshness values.
- On Tracker restart:
  - CDP live `connection_state` must be returned as `DISCONNECTED`.
  - Last known CDP state may remain available as diagnostic history/current metadata.
  - A previous `CONNECTED` or `APPLIED` state must not be treated as live proof after restart.

---

## 6. Error Model

Use a structured current error:

```json
{
  "code": "CDP_RUNTIME_EXCEPTION",
  "message": "ReferenceError: __JSON_DATA__ is not defined",
  "stage": "INJECTION",
  "occurred_at": "2026-07-29T23:00:10+08:00",
  "details": {}
}
```

Initial error codes:

```text
CDP_CONNECTION_FAILED
CDP_TARGET_NOT_FOUND
CDP_CONTEXT_NOT_FOUND
CDP_RUNTIME_EXCEPTION
SCRIPT_READ_FAILED
SCRIPT_INJECTION_FAILED
RULESET_DATA_INVALID
RULESET_ASSET_NOT_FOUND
RULESET_APPLY_PARTIAL
RULESET_APPLY_FAILED
ROOM_OBSERVATION_INVALID
PERSISTENCE_ERROR
UNAUTHORIZED_PRODUCER
```

Rules:

- A successful later update may clear the current error.
- Error history remains.
- Current error and historical error are separate concerns.
- Do not persist tokens or secrets in error details.

---

## 7. SQLite Persistence

Use four logical stores:

```text
room_observation_current
room_observation_history
cdp_diagnostic_current
cdp_diagnostic_history
```

Do not put ROOM/CDP diagnostics into the existing `events` table.

### 7.1 Migration

Integrate through the existing:

```text
EventStore.initialize()
```

schema-version/migration mechanism.

Do not create an independent ad-hoc database initializer.

### 7.2 Current and History Transaction

For meaningful updates:

1. Upsert current.
2. Append history when required.
3. Commit both in the same transaction.

Do not allow current to update while history fails independently.

### 7.3 History Write Conditions

Append history only when one of the following changes:

ROOM:

- `observed_room_state`
- `game_room_id`
- `platform`
- `channel`
- `rule_version_id`
- `content_hash`
- player identifiers/display names
- observation status/error
- reset

CDP:

- connection/context/injection/apply state
- `context_id`
- `target`
- Ruleset identity/hash
- application counters/result
- current error
- reset/restart normalization

Heartbeat-only timestamp updates must not append history.

### 7.4 Retention

Recommended limits:

```text
ROOM history: 500 entries
CDP history: 1000 entries
```

Trimming may occur after a history insert in the same persistence workflow.

### 7.5 Producer Keying

Current tables must include `producer_id`.

Even with one writer per category in Phase 1, do not assume the entire system can only ever hold one producer row.

Do not freeze exact SQL column types until the existing repository patterns are inspected.

---

## 8. Tracker Diagnostics UI

Add ROOM and CDP sections to the existing Tracker diagnostics panel.

The UI is read-only.

### 8.1 ROOM Fields

Recommended display:

```text
Room observation
Observed state
Freshness
Observation status
Room name
Platform
Channel
Game room ID
Host
Opponent
Ruleset version
Content hash
Tracker session link
Last update
Age
Room error
```

Initial/no-data values:

```text
Observed state: UNKNOWN
Freshness: EXPIRED or —
Observation status: —
Other values: —
```

### 8.2 CDP Fields

Recommended display:

```text
CDP connection
CDP freshness
Context state
Target
Context ID
Injection state
Ruleset apply state
Ruleset version
Content hash
Format valid
Expected
Matched
Changed
Already compliant
Verified
Missing
Invalid
Last update
Age
CDP error
```

The UI must make these distinctions visible:

```text
CONNECTED ≠ CONTEXT FOUND
CONTEXT FOUND ≠ SCRIPT INJECTED
SCRIPT INJECTED ≠ RULESET APPLIED
```

Do not show a generic “修補完成” solely because JavaScript evaluation returned.

### 8.3 Existing Diagnostics Must Remain

Do not remove or alter the authority of existing fields such as:

```text
Connection
Persistence
Session
Cursor
Checkpoint session
Checkpoint cursor
Checkpoint commit
Loading
Observation mode
Confidence
Last event
Stage code
Mapped field
Stage status
Pending events
Pending cards
Stage error
Error
Persistence error
```

ROOM/CDP should be additive.

### 8.4 Polling

Use the existing diagnostics polling mechanism where possible.

Prefer one read request:

```text
GET /api/v1/diagnostics/status
```

Do not make the browser poll every mutation endpoint independently.

---

## 9. CLI and Test Tooling

Provide native test tools, for example:

```text
tools/post_room_observation.py
tools/patch_cdp_status.py
```

Potential presets:

```text
room-empty
room-waiting
room-matched
room-active
room-ended
cdp-disconnected
cdp-connected
cdp-context-found
cdp-injected
cdp-applied
cdp-partial
cdp-failed
```

Do not include a preset that directly submits:

```text
freshness_state = STALE
```

Freshness tests must be server-derived.

The CLI must:

- Read the appropriate producer token from the runtime location.
- Avoid printing the token.
- Target loopback only.
- Refuse to use production/real database paths in automated tests.
- Return non-zero exit status on HTTP/API failure.

---

## 10. Automated Tests

Use FastAPI TestClient and a temporary SQLite database.

Do not depend on:

```text
real events.sqlite3
real port 8765
real browser
real CDP
real Steam/DMM session
```

Required test categories:

### 10.1 Authorization

- Reject non-loopback mutation request where testable.
- Reject missing token.
- Reject invalid token.
- Reject producer token on unauthorized endpoint.
- Accept authorized producer.

### 10.2 ROOM PUT

- Complete snapshot accepted.
- Missing required key rejected.
- Explicit `null` clears values.
- `server_received_at` generated by server.
- `client_observed_at` stored but not used for freshness.
- Invalid lifecycle value rejected.
- Invalid content hash format rejected when present.
- ULGG authority fields are not writer-controlled.

### 10.3 ROOM DELETE

- Current observation clears.
- History remains.
- Reset history entry is added.
- Tracker session is not ended.

### 10.4 CDP PATCH

- Omitted field preserves existing value.
- Explicit `null` clears field.
- Invalid state rejected.
- Successful update clears current error when `error: null`.
- Final-compliance counters validate.
- `APPLIED` is rejected or normalized when counters do not prove compliance.

### 10.5 Freshness

Use a fake clock or injectable time provider.

Test:

- FRESH before threshold.
- STALE after stale threshold.
- EXPIRED after expiry threshold.
- Last observed room state remains unchanged.
- CDP connection state remains a connection value, not a freshness value.

### 10.6 Persistence

- Current and history update atomically.
- Heartbeat-only update does not create history.
- Meaningful state change creates history.
- Retention trims oldest records.
- Restart forces live CDP connection to `DISCONNECTED`.
- Last known diagnostic history remains available.

### 10.7 Diagnostics Aggregate

- Returns ROOM and CDP sections.
- Includes derived freshness and age.
- Does not expose writer tokens.
- Does not pretend to contain browser IndexedDB stage state.

---

## 11. Implementation Order

Codex should work in this order:

1. Inspect the existing repository structure.
2. Locate Tracker API app, EventStore, schema migration logic, diagnostics UI, Launcher health checks, and existing API error conventions.
3. Document exact files and integration points before editing.
4. Add configuration values for freshness thresholds and runtime credential paths.
5. Add runtime producer credential management.
6. Add EventStore migration and persistence methods.
7. Add ROOM models and API.
8. Add CDP models and API.
9. Add freshness derivation/time-provider abstraction.
10. Add diagnostics aggregate API.
11. Add diagnostics UI fields.
12. Add CLI tools.
13. Add tests.
14. Run targeted test suite.
15. Run existing Tracker test suite/regression checks.
16. Write an implementation report.
17. Do not commit unless explicitly instructed.

---

## 12. Codex Working Rules

Before modifying code:

- Explore the repository.
- Do not guess file paths or APIs.
- Reuse existing configuration, validation, persistence, and response patterns.
- Confirm how the Launcher detects and reuses an existing Tracker API.
- Confirm how `EventStore.initialize()` migrations currently work.
- Confirm how diagnostics UI currently reads Tracker status.
- Confirm current CORS/loopback behavior.
- Confirm current test fixtures and temporary database conventions.

While editing:

- Keep changes scoped to this sprint.
- Do not refactor unrelated Tracker logic.
- Do not change existing `arena_unlight`.
- Do not alter existing `gameStart`/`result` authority.
- Do not rename existing public endpoints unnecessarily.
- Do not introduce API v2 envelope changes.
- Do not expose secrets.
- Do not stage, commit, or push.
- Preserve existing dirty worktree files not owned by this sprint.

When reporting modifications, include:

- File path.
- Function/class/section name.
- Approximate line range.
- Searchable original code anchor.
- Summary of change.
- Test performed and result.
- Known limitations.

---

## 13. Acceptance Criteria

The sprint is complete only when all of the following are true:

1. Existing `/health` remains compatible with the Launcher.
2. Authorized native writers can update ROOM/CDP via loopback-only protected endpoints.
3. Browser code cannot obtain mutation credentials.
4. ROOM observation is stored as a complete snapshot.
5. CDP diagnostics support partial updates and explicit clearing.
6. Lifecycle and freshness are separate.
7. ROOM/CDP observations do not affect existing Tracker battle/session authority.
8. Current and history are persisted separately.
9. Meaningful updates write current/history atomically.
10. Heartbeats do not flood history.
11. Freshness is computed from server receipt time.
12. Tracker restart does not falsely preserve a live CDP connection.
13. COST application reports final compliance, not only mutation count.
14. Ruleset checking is labeled `format_valid`, not “verified”.
15. Diagnostics UI displays ROOM and CDP states clearly.
16. `CONNECTED`, `INJECTED`, and `APPLIED` are visibly distinct.
17. CLI presets can exercise normal/error paths without real CDP.
18. Tests use temporary SQLite and fake/injectable time.
19. Existing Tracker regression tests pass.
20. No commit or push is performed.

---

## 14. Out of Scope for v0.1

Do not implement in this sprint:

- Real ULGG Lobby database integration.
- Official `ulgg_match_id` creation.
- Match result authority.
- Client Evidence acceptance.
- Server-side match verification.
- Ruleset Registry authority.
- Automatic Ruleset download.
- Official Hash binding.
- Custom Point/BP calculation.
- Arena mapping.
- Multi-source Room merge/priority.
- CDP-driven Tracker session start/end.
- Browser-side mutation endpoints.
- API v2 response envelope.
- Production packaging changes unless required to run tests.

---

## 15. Deliverables

Codex must leave:

1. Source changes implementing the sprint.
2. Database migration integrated with existing EventStore initialization.
3. Automated tests.
4. Local CLI test tools.
5. Updated diagnostic UI.
6. A report file, suggested path:

```text
docs/tracker_room_cdp_diagnostics_sprint_v0.1_report.md
```

The report must include:

```text
Summary
Files changed
Schema/migration
API endpoints
Runtime token delivery
UI changes
Tests run
Regression results
Known limitations
Manual verification steps
Git status
```

Do not commit the changes.
