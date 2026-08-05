# Tracker Sprint 1 Closure Review

Review date: 2026-07-29

Scope: the 16 production files and 16 tests identified by `Tracker_Convergence_Audit.md`

Mode: source review and temporary-snapshot tests. This report is the only repository file created during Gate 2. No existing file was modified, deleted, staged, or committed.

## Executive decision

Neither proposal A nor proposal B is safe exactly as written.

The repository `HEAD` already contains two references to files that are not in `HEAD`:

- `unlight_websocket_sniffer.py` imports the untracked `battle_stream_processor.py`.
- `server.py` serves, and `UnlightTrackerLauncher.spec` packages, the untracked `tracker-domain-reducer.js`.

Therefore the baseline has two independent missing-file defects:

- importing the Sniffer from a clean `HEAD` checkout fails;
- a clean `HEAD` PyInstaller build cannot collect `tracker-domain-reducer.js`.

There is also an activation-order hazard. The modified `observation-poller.js` treats `websocket_sniffer` sessions as domain sessions and fails closed with `DOMAIN_PROJECTION_UNAVAILABLE` if `tracker-domain-reducer.js` and the new IndexedDB methods are not loaded. If browser implementation files are committed before the matching `control.html` script order, the current browser entry temporarily breaks.

Recommended solution: proposal C, which first closes the two pre-existing missing dependencies, then adds Python and dormant JavaScript cores, and only then activates them through an atomic integration commit.

## 1. Exact 32-file closure

### Production files

```
tool/unlight-card-tracker/detector/battle_stream_processor.py
tool/unlight-card-tracker/tracker-stage-sync.js
tool/unlight-card-tracker/tracker-domain-reducer.js
tool/unlight-card-tracker/tracker-auto-card-sync.js
tool/unlight-card-tracker/control.html
tool/unlight-card-tracker/detector/UnlightTrackerLauncher.spec
tool/unlight-card-tracker/detector/battle_observation_stream.py
tool/unlight-card-tracker/detector/battle_protocol.py
tool/unlight-card-tracker/detector/battle_state_reducer.py
tool/unlight-card-tracker/detector/battle_tracker_adapter.py
tool/unlight-card-tracker/detector/server.py
tool/unlight-card-tracker/detector/unlight_websocket_sniffer.py
tool/unlight-card-tracker/observation-poller.js
tool/unlight-card-tracker/tracker-api.js
tool/unlight-card-tracker/tracker-db.js
tool/unlight-card-tracker/tracker.js
```

### Test files

```
tool/unlight-card-tracker/detector/tests/test_battle_stream_processor.py
tool/unlight-card-tracker/detector/tests/js/test_tracker_stage_sync.js
tool/unlight-card-tracker/detector/tests/js/test_tracker_domain_reducer.js
tool/unlight-card-tracker/detector/tests/js/test_tracker_auto_card_sync.js
tool/unlight-card-tracker/detector/tests/js/test_observation_poller.js
tool/unlight-card-tracker/detector/tests/js/test_tracker_api.js
tool/unlight-card-tracker/detector/tests/js/test_tracker_db.js
tool/unlight-card-tracker/detector/tests/launcher_exe_smoke.py
tool/unlight-card-tracker/detector/tests/test_battle_observation_stream.py
tool/unlight-card-tracker/detector/tests/test_battle_protocol.py
tool/unlight-card-tracker/detector/tests/test_battle_state_reducer.py
tool/unlight-card-tracker/detector/tests/test_battle_tracker_adapter.py
tool/unlight-card-tracker/detector/tests/test_sniffer_battle_runtime.py
tool/unlight-card-tracker/detector/tests/test_static_tracker.py
tool/unlight-card-tracker/detector/tests/test_tracker_launcher.py
tool/unlight-card-tracker/detector/tests/test_uvicorn_smoke.py
```

## 2. Production dependency and ownership matrix

“Order dependency” describes the minimum prerequisite commit, not merely an import statement.

| Production file | Entry/caller | Direct first-party dependencies | Referenced by | External behavior | Domain | Recommended ownership | Order dependency |
|---|---|---|---|---|---|---|---|
| `detector/battle_stream_processor.py` | Constructed by `ULSniffer` when battle processing is enabled | `battle_state_reducer.py`, `battle_tracker_adapter.py` | Sniffer and processor/runtime tests | Converts safe observations into ordered domain events and an in-memory projection; performs no I/O itself | battle stream | Python Core | None; currently missing from `HEAD` despite existing import |
| `tracker-stage-sync.js` | Browser global loaded before Tracker/Auto Sync | Existing tracked `field-decks.js` data supplied through caller context | `tracker-auto-card-sync.js`, `control.html`, server/spec, tests | Maps stage codes to a fixed field or a fail-closed status | stage sync | JavaScript Tracker | Existing tracked field-deck data |
| `tracker-domain-reducer.js` | Browser global used by the poller and IndexedDB transaction callback | None beyond standard JavaScript | Poller, control, server/spec, tests | Builds a replayable, safe domain projection and rejects restricted/raw fields | domain reducer | JavaScript Tracker | None; currently missing from `HEAD` despite server/spec references |
| `tracker-auto-card-sync.js` | Poller callback after a durable batch commits | `TrackerStageSync`, `TrackerAutoSyncTarget` exposed by `tracker.js` | Poller, control, server/spec, tests | Plans and applies dealt-card deductions with idempotency, pending-stage and session/battle boundaries | auto card sync | JavaScript Tracker | JavaScript Tracker |
| `control.html` | `/tracker/` browser entry served by `server.py` | Eight local scripts in strict order | Server, static/smoke tests, browser | Activates the complete browser pipeline and supplies stage diagnostic DOM nodes | integration | Integration | Both Python Core and JavaScript Tracker |
| `detector/UnlightTrackerLauncher.spec` | PyInstaller build entry | `tracker_launcher.py`, tracker scripts, entire `assets/` directory | PyInstaller and launcher tests | Builds a Windows console one-folder launcher and packages browser assets | integration | Integration | JavaScript Tracker; packaging validation also exercises Python runtime |
| `detector/battle_observation_stream.py` | Created and fed by `ULSniffer` | `battle_protocol.py`; optional caller-provided JSONL output path | Sniffer and observation tests | Converts redacted discovery records into safe observations; correlates stage context without exposing private args | battle stream | Python Core | Python Core |
| `detector/battle_protocol.py` | Called by `BattleObservationStream` | Python standard library only | Observation stream and protocol tests | Parses allowed protocol events, classifies visibility and emits safe observation payloads | battle stream | Python Core | None |
| `detector/battle_state_reducer.py` | Called by `BattleStreamProcessor` | Python standard library only | Processor and reducer tests | Applies domain events to deterministic battle state; enforces ordering/idempotency and stage context | battle stream | Python Core | None |
| `detector/battle_tracker_adapter.py` | Called by `BattleStreamProcessor` | Python standard library only | Processor/reducer/adapter tests | Projects safe observations into durable tracker events and rejects forbidden fields | battle stream | Python Core | None |
| `detector/server.py` | `tracker_api_server.py` calls `create_app()` | Existing tracked `client_profile.py`, `domain_event_schema.py`, `event_store.py`; tracker files under `TRACKER_ROOT` | API runner, static/uvicorn tests, launcher | Local API routes plus explicit browser/static routes | integration | Integration | Both: receives Python domain events and serves JavaScript consumers |
| `detector/unlight_websocket_sniffer.py` | Launcher child command or direct CLI | Observation stream, processor, existing `tracker_event_client.py`, requests/websockets | Launcher and Python runtime tests | Reads CDP WebSocket events, redacts diagnostics, produces safe events and submits them to localhost API | battle stream / integration | Python Core | Python Core; processor file must exist |
| `observation-poller.js` | Auto-bootstraps from `control.html` | `TrackerApi`, `TrackerDb`, `TrackerDomainReducer`; optional `TrackerAutoCardSync` callback | Control and poller/static tests | Polls the active session, atomically commits projection/cursor, invokes auto sync and performs bounded retry | domain reducer / integration | Integration | Both |
| `tracker-api.js` | Instantiated by poller | Browser `fetch`, relative `/api/v1` origin | Poller, control and API tests | Reads active/current session and cursor-paged events; preserves structured errors | integration | Integration | Python API |
| `tracker-db.js` | Browser global used by poller | IndexedDB | Poller, control and DB tests | Stores checkpoint and per-session domain projection atomically with event identity and commit token | domain reducer / integration | Integration | JavaScript domain reducer contract |
| `tracker.js` | Main UI script loaded after field/stage data | Existing tracked `field-decks.js`, DOM, localStorage; Auto Sync is optional at load time | Control, Auto Sync and static tests | Owns deck state, migrations, undo, field selection and the `TrackerAutoSyncTarget` mutation boundary | auto card sync / stage sync | JavaScript Tracker | JavaScript Tracker |

### Existing dependencies outside the 32 files

All required first-party dependencies below exist and are already tracked:

```
tool/unlight-card-tracker/field-decks.js
tool/unlight-card-tracker/assets/cards/acswd.png
tool/unlight-card-tracker/assets/cards/acbow.png
tool/unlight-card-tracker/assets/cards/acshi.png
tool/unlight-card-tracker/assets/cards/acmov.png
tool/unlight-card-tracker/assets/cards/acspe.png
tool/unlight-card-tracker/detector/client_profile.py
tool/unlight-card-tracker/detector/domain_event_schema.py
tool/unlight-card-tracker/detector/event_store.py
tool/unlight-card-tracker/detector/tracker_event_client.py
tool/unlight-card-tracker/detector/tracker_api_server.py
tool/unlight-card-tracker/detector/tracker_launcher.py
tool/unlight-card-tracker/detector/requirements.txt
tool/unlight-card-tracker/detector/requirements-build.txt
```

No file in the 32-file closure imports or loads Detector runtime templates.

## 3. Test mapping

| Test file | Production correspondence | Purpose | Runtime boundary | Test type | Recommended ownership / dependency |
|---|---|---|---|---|---|
| `tests/test_battle_stream_processor.py` | Processor, adapter, reducer | Ordering, identity, strict/bootstrap behavior, rollback, safe boundary and corpus replay | Python only | Real regression unit/integration test | Python Core / none |
| `tests/js/test_tracker_stage_sync.js` | Stage Sync and tracked field decks | Fixed/random/unsupported/missing-deck resolution | Node VM only | Regression unit test | JavaScript Tracker / tracked field decks |
| `tests/js/test_tracker_domain_reducer.js` | Domain Reducer | Replay, stage payload, event semantics, session/schema and raw-field rejection | Node VM only | Regression unit test | JavaScript Tracker / none |
| `tests/js/test_tracker_auto_card_sync.js` | Auto Sync, Stage Sync, `tracker.js`, field decks | Deduction, idempotency, migration, pending stage, confirmation, reset and undo | Node VM with browser stubs | Cross-module regression test, not smoke | JavaScript Tracker / JavaScript core |
| `tests/js/test_observation_poller.js` | Poller and Domain Reducer; mocked API/DB/Auto Sync | Active authority, atomic commit, cursor/session changes, rollback, stop/backoff | JavaScript integration with mocks | Regression integration test | Integration / both |
| `tests/js/test_tracker_api.js` | Tracker API client | Relative endpoint construction, response validation, structured/network errors | Node VM with mocked fetch | Regression unit test | Integration / Python API contract |
| `tests/js/test_tracker_db.js` | Tracker DB | IndexedDB upgrade, checkpoints, atomic projection, gaps, duplicates and rollback | Node VM with fake IndexedDB | Regression persistence test | Integration / domain contract |
| `tests/launcher_exe_smoke.py` | Frozen launcher, API, browser routes, control and packaged scripts | Starts fake CDP and built EXE, verifies health/static files/session shutdown | Windows EXE + Python + JavaScript packaging | Opt-in smoke test only | Integration / all commits and completed build |
| `tests/test_battle_observation_stream.py` | Observation Stream, Protocol and Sniffer integration | Safe parsing, JSONL persistence option, stage correlation and purity constraints | Python only; temp files | Regression unit/integration test | Python Core |
| `tests/test_battle_protocol.py` | Protocol | Card parsing, visibility, event conversion, stage safety and forbidden imports | Python only | Regression unit test | Python Core |
| `tests/test_battle_state_reducer.py` | Reducer plus Adapter | State transitions, replay/idempotency, stage data, payload and dependency boundary | Python only | Regression unit test | Python Core |
| `tests/test_battle_tracker_adapter.py` | Adapter | Mapping semantics, event IDs, stage context and forbidden-field rejection | Python only | Regression unit test | Python Core |
| `tests/test_sniffer_battle_runtime.py` | Sniffer, Stream Processor, Stream and Tracker Event Client | CLI validation, session identity, processing/submission failures, redaction and lifecycle | Python integration with mocks/temp files | Regression integration test | Python Core |
| `tests/test_static_tracker.py` | Server, control, all local browser assets | Routes, content types, script order, non-public detector paths and traversal rejection | Python FastAPI plus JavaScript files | Regression integration test | Integration / both |
| `tests/test_tracker_launcher.py` | Launcher, spec and frozen resource root | Process ownership/order, API/CDP lifecycle, exact sessions, build specification | Python with process mocks | Regression integration test | Integration / all |
| `tests/test_uvicorn_smoke.py` | Server under real Uvicorn socket | Health/static route availability on a loopback ephemeral port | Python real socket | Smoke test | Integration / server and static assets |

### Coverage findings

- No production file is completely uncovered.
- `tracker.js` lacks a dedicated standalone test file, but is loaded extensively by `test_tracker_auto_card_sync.js`; static presence/order is also checked.
- `server.py` static behavior is covered by `test_static_tracker.py` and a real socket by `test_uvicorn_smoke.py`. Its API semantics also have existing tracked tests outside this 32-file delta, notably `test_tracker_api_server.py`; these should remain in the full-suite gate.
- No test in the 32-file list is orphaned.
- `launcher_exe_smoke.py` is intentionally not named `test_*.py`; `unittest discover` does not execute it. It must be invoked explicitly after a PyInstaller build.

## 4. Duplication, temporary files and naming

- No byte-identical duplicate or obvious temporary/copy file is present in the 32-file closure.
- Python uses `snake_case`; browser modules use hyphenated filenames with `Tracker*` globals. The difference is ecosystem-consistent rather than accidental.
- `UnlightTrackerLauncher.spec` uses CamelCase to match the generated EXE name.
- `launcher_exe_smoke.py` is a runnable acceptance harness, not an auto-discovered unit test. Its naming is intentional but easy to omit from CI.
- `getCurrentSession()` remains in `tracker-api.js` for compatibility and is directly tested; the poller now uses `getActiveSession()`. It is not an unreferenced replacement candidate yet.
- No replaced production file in this 32-file set is still referenced.

## 5. Per-file security and portability review

Legend:

- runtime = writes or references generated local runtime state
- local ports = expected loopback Tracker/CDP defaults
- Windows = intentionally Windows-specific launcher/build behavior
- sentinel = secret-like words are deny-list entries or fake test values, not credentials

### Production files

| File | Absolute local path | Runtime output | Credential/secret | Debug default | Windows assumption | Localhost/port | Untracked/template dependency |
|---|---|---|---|---|---|---|---|
| `battle_stream_processor.py` | None | None | None | Off/no debug default | None | None | None |
| `tracker-stage-sync.js` | None | None | None | None | None | None | Only tracked `field-decks.js` |
| `tracker-domain-reducer.js` | None | None | Deny-list key such as `session_token`; no value | None | None | None | None |
| `tracker-auto-card-sync.js` | None | None | None | None | None | None | New Stage Sync and `tracker.js` target, both in closure |
| `control.html` | None | None | None | None | None | Same-origin only | New scripts in closure |
| `UnlightTrackerLauncher.spec` | None; paths derive from `SPECPATH` | Writes standard PyInstaller build/dist when executed | None | `debug=False` | Windows EXE target | None | New scripts in closure; broad tracked/untracked `assets/` source tree |
| `battle_observation_stream.py` | None | Optional caller-provided JSONL path | Deny-list sentinel only | Off | None | None | No template dependency |
| `battle_protocol.py` | None | None | None | None | None | None | None |
| `battle_state_reducer.py` | None | None | None | None | None | None | None |
| `battle_tracker_adapter.py` | None | None | Deny-list sentinel only | None | None | None | None |
| `server.py` | None; root derives from file or `UNLIGHT_TRACKER_ROOT` | Existing SQLite event store is injected; this file does not choose the path | None | None | Frozen/source roots supported | No bind in this file | Serves new closure scripts; no templates |
| `unlight_websocket_sniffer.py` | None | `deck/events.jsonl`, event JSONL, redacted `packets.log`, summaries and optional observations | Redaction/deny lists; raw logging requires explicit `--unsafe-raw-log` | Raw/debug modes off by CLI default | Optional `launch_game()` uses Windows `start steam://...` | CDP defaults to `127.0.0.1:9333` | Processor in closure; no templates |
| `observation-poller.js` | None | IndexedDB through Tracker DB | None | None | None | Relative API through client | Domain/DB/API/Auto modules in closure |
| `tracker-api.js` | None | None | None | None | None | Relative `/api/v1`; no fixed host/port | Python API contract |
| `tracker-db.js` | None | Browser IndexedDB | None | None | None | None | None |
| `tracker.js` | None | Existing browser localStorage | None | None | None | None | Tracked card assets/field decks and new optional Auto Sync boundary |

Important runtime finding: `tracker_launcher.py`—already tracked and outside the 32-file delta—starts the Sniffer with `--all`, `--log`, `--event-dir`, and `--battle-observations`. The output is local/redacted unless `--unsafe-raw-log` is separately supplied, and Gate 1 ignores the generated paths. This is not a secret literal, but production launcher logging is intentionally enabled rather than entirely off.

### Test files

| Test file | Relevant finding |
|---|---|
| `test_battle_stream_processor.py` | Uses safe fake secret sentinels and optional local corpus; no absolute path |
| `test_tracker_stage_sync.js` | Pure Node VM; no runtime/secret/port |
| `test_tracker_domain_reducer.js` | Contains a fake forbidden-key payload; no credential value |
| `test_tracker_auto_card_sync.js` | Browser/localStorage stubs only; no runtime file or port |
| `test_observation_poller.js` | Mock API/DB/timers only |
| `test_tracker_api.js` | Mock fetch only; no fixed real endpoint |
| `test_tracker_db.js` | Fake IndexedDB only |
| `launcher_exe_smoke.py` | Windows-only (`CREATE_NEW_PROCESS_GROUP`), explicitly uses `127.0.0.1:8765` and `:9333`, creates temporary SQLite/process state |
| `test_battle_observation_stream.py` | Uses temporary JSONL and fake restricted/secret payloads to prove rejection |
| `test_battle_protocol.py` | Fake secret/forbidden payloads only |
| `test_battle_state_reducer.py` | Local fixture paths derived from `__file__`; fake restricted payloads |
| `test_battle_tracker_adapter.py` | Local fixture paths derived from `__file__`; fake restricted payloads |
| `test_sniffer_battle_runtime.py` | Temporary runtime output, fake API/CDP URLs and secret sentinels; no production credential |
| `test_static_tracker.py` | Temporary SQLite and in-process FastAPI client; no fixed external host |
| `test_tracker_launcher.py` | Tests expected `127.0.0.1:8765/9333` defaults and process ownership with mocks |
| `test_uvicorn_smoke.py` | Real loopback socket with a dynamically selected port and temporary SQLite |

No private-key header, credential literal, user-specific absolute path, or Detector runtime-template dependency was found in the 32 files.

## 6. Deep integration findings

### `server.py`

- `create_app()` owns API and static routing, but does not bind a network interface.
- It receives events produced by the Python stream through the already tracked `tracker_event_client.py`.
- It serves the browser control page and every browser script explicitly.
- `FileResponse` route creation does not prove the target exists at startup; a missing script becomes a request-time failure.
- `HEAD` already has a Domain Reducer route although the file is not in `HEAD`.
- The working-tree diff adds Stage Sync and Auto Card Sync routes; therefore its final form depends on the JavaScript side.

### Localhost API boundary

- The normal `tracker_launcher.py` validates that Tracker API host is exactly `127.0.0.1`.
- `tracker-api.js` uses same-origin `/api/v1`, so the browser does not embed an external host.
- `unlight_websocket_sniffer.py` receives the API origin from the launcher.
- Pre-existing release blocker outside the 32 files: standalone `tracker_api_server.py --host` accepts arbitrary host text and does not itself reject `0.0.0.0`, `::`, or LAN addresses. The normal launcher remains safe, but the standalone runner does not enforce the repository’s localhost-only production rule.

Recommendation: do not expand this Gate 2 commit automatically. Before release, open a separate scoped security fix for `tracker_api_server.py` and its existing tests.

### `control.html`

Required script order:

```
field-decks.js
tracker-stage-sync.js
tracker.js
tracker-domain-reducer.js
tracker-db.js
tracker-api.js
tracker-auto-card-sync.js
observation-poller.js
```

The order is meaningful:

- Stage Sync must exist before Auto Sync uses it.
- `tracker.js` must expose `TrackerAutoSyncTarget` before a committed batch is applied.
- Domain Reducer and Tracker DB must exist before a `websocket_sniffer` domain session is loaded.
- API must exist before poller bootstrap.

The new stage diagnostic DOM elements are optional in `tracker.js`, so committing `tracker.js` before `control.html` remains backward compatible. Committing `observation-poller.js` before `control.html` is not safe for domain sessions because the old control does not load the reducer.

### Packaging

- The spec packages Domain Reducer in `HEAD` already, but the file is missing from `HEAD`.
- The working diff adds Stage Sync and Auto Card Sync.
- It uses paths derived from `SPECPATH`; no developer-specific path is embedded.
- `debug=False`, console mode and one-folder `COLLECT` match the project target.
- The spec copies the entire `tool/unlight-card-tracker/assets/` directory. A build from the current dirty working tree may therefore absorb untracked `assets/event/` and `assets/sites/tw/`, even though Git staging is precise.

Recommendation: do not perform release packaging from this dirty working tree. Build the acceptance EXE from a clean checkout or an exact staged/archive snapshot after the Detector Assets decision.

### Core modules

- `battle_stream_processor.py` is pure and caller-owned: no file, browser, API, DB or transport imports.
- `tracker-domain-reducer.js` is also pure and replayable.
- `tracker-stage-sync.js` fails closed for random, unsupported, malformed or missing field-deck stages.
- `tracker-auto-card-sync.js` mutates Tracker only through the explicit target exposed by `tracker.js`, after the poller’s durable DB commit.

## 7. Submission safety answers

### Can Python Core be committed alone?

Not directly from the current `HEAD`: it must include or follow the missing `battle_stream_processor.py`. Once the prerequisite closure commit is present, the Python Core commit can run with the old browser UI. The old browser does not activate automatic synchronization, but manual Tracker behavior remains available.

Temporary reconstructed-commit result: the prerequisite plus Python Core targeted suite ran 298 tests successfully; 8 corpus-dependent cases were skipped because untracked local corpus files were intentionally absent from the `HEAD` archive.

### Can JavaScript Tracker be committed alone?

The pure modules can be committed dormant, but the complete modified browser stack cannot safely be committed before `control.html`. In particular, the modified poller recognizes `websocket_sniffer` as a domain producer and requires the reducer and new DB methods.

The safe JavaScript Core subset is:

```
tracker-stage-sync.js
tracker-auto-card-sync.js
tracker.js
```

with Domain Reducer already supplied by the prerequisite closure. `tracker.js` tolerates the old control page because its new DOM and Auto Sync accesses are guarded.

Temporary reconstructed-commit result: Stage Sync + Domain Reducer + Auto Sync/Tracker tests passed 59/59.

### Does `server.py` depend on both sides?

Yes. Its API is the Python producer/JavaScript consumer boundary, and its static routes serve the JavaScript modules. Keep its current diff in the final Integration commit.

### Does `control.html` break an intermediate commit?

Yes if committed before all scripts exist, and the modified poller can break if committed while the old control omits Domain Reducer. Keep `control.html`, poller, API, DB, server and spec activation changes together.

### Can either feature commit be independently reverted?

Only in reverse dependency order:

1. Revert Integration first.
2. Revert JavaScript Core.
3. Revert Python Core.
4. Revert the missing-dependency closure last.

Reverting JavaScript Core while Integration remains would leave missing scripts/targets. Reverting Python Core while Integration remains degrades or breaks stage-aware automatic synchronization. Reverting the prerequisite closure while existing `HEAD` references remain reintroduces the original missing imports/assets.

### Do tests span commits?

Yes:

- pure Python and pure JS tests can run in their own commits;
- static route/order tests require Integration plus JavaScript files;
- full JS tests require poller/API/DB plus core modules;
- launcher EXE smoke requires every commit and a clean PyInstaller build.

## 8. Recommended proposal C

### Commit 1 — repository hygiene

Message:

```text
chore(repo): ignore tracker runtime artifacts
```

Files:

```
.gitignore
```

Prerequisites: none.

Tests:

```powershell
git diff --check -- .gitignore
git check-ignore -v -- tool/unlight-card-tracker/detector/templates/card-digits-runtime/lower/1_01.png
git check-ignore -v -- tool/unlight-card-tracker/detector/templates/card-icons-runtime/lower/shield.png
git check-ignore -v -- tool/unlight-card-tracker/detector/templates_structure.txt
git check-ignore -v -- tool/unlight-card-tracker/sample.jsonl
```

Rollback risk: ignored runtime files become visible again; no runtime behavior changes.

Flow availability: unchanged.

### Commit 2 — close pre-existing missing dependencies

Message:

```text
fix(tracker): close missing runtime dependencies
```

Files:

```
tool/unlight-card-tracker/detector/battle_stream_processor.py
tool/unlight-card-tracker/tracker-domain-reducer.js
tool/unlight-card-tracker/detector/tests/test_battle_stream_processor.py
tool/unlight-card-tracker/detector/tests/js/test_tracker_domain_reducer.js
```

Prerequisites: none beyond existing tracked reducer/adapter modules.

Tests:

```powershell
cd tool/unlight-card-tracker/detector
python -m unittest tests.test_battle_stream_processor -v
cd ..
node --test detector/tests/js/test_tracker_domain_reducer.js
```

Temporary `HEAD` reconstruction evidence:

- Processor: 45 passed, 4 skipped because local untracked corpus was absent.
- Domain Reducer: 9/9 passed.

Rollback risk: high unless all downstream commits and the pre-existing Sniffer/server/spec references are reverted or repaired first.

Flow availability: structurally improves clean-checkout import/build completeness; PyInstaller was not executed in Gate 2, so frozen build remains a required later validation.

### Commit 3 — Python battle stream

Message:

```text
feat(tracker): add battle stream stage context
```

Files:

```
tool/unlight-card-tracker/detector/battle_observation_stream.py
tool/unlight-card-tracker/detector/battle_protocol.py
tool/unlight-card-tracker/detector/battle_state_reducer.py
tool/unlight-card-tracker/detector/battle_tracker_adapter.py
tool/unlight-card-tracker/detector/unlight_websocket_sniffer.py
tool/unlight-card-tracker/detector/tests/test_battle_observation_stream.py
tool/unlight-card-tracker/detector/tests/test_battle_protocol.py
tool/unlight-card-tracker/detector/tests/test_battle_state_reducer.py
tool/unlight-card-tracker/detector/tests/test_battle_tracker_adapter.py
tool/unlight-card-tracker/detector/tests/test_sniffer_battle_runtime.py
```

Prerequisite: Commit 2.

Tests:

```powershell
cd tool/unlight-card-tracker/detector
python -m unittest tests.test_battle_stream_processor tests.test_battle_observation_stream tests.test_battle_protocol tests.test_battle_state_reducer tests.test_battle_tracker_adapter tests.test_sniffer_battle_runtime -v
python -m py_compile battle_stream_processor.py battle_observation_stream.py battle_protocol.py battle_state_reducer.py battle_tracker_adapter.py unlight_websocket_sniffer.py
```

Temporary reconstructed-commit result: 298 passed, 8 skipped due to deliberately absent untracked corpus.

Rollback risk: Integration must be reverted first if stage-aware browser sync is active.

Flow availability: source launcher and old/manual browser flow remain usable; automatic browser sync is not activated yet.

### Commit 4 — dormant browser synchronization core

Message:

```text
feat(tracker): add browser card synchronization core
```

Files:

```
tool/unlight-card-tracker/tracker-stage-sync.js
tool/unlight-card-tracker/tracker-auto-card-sync.js
tool/unlight-card-tracker/tracker.js
tool/unlight-card-tracker/detector/tests/js/test_tracker_stage_sync.js
tool/unlight-card-tracker/detector/tests/js/test_tracker_auto_card_sync.js
```

Prerequisite: Commit 2 and existing tracked `field-decks.js`.

Tests:

```powershell
cd tool/unlight-card-tracker
node --test detector/tests/js/test_tracker_stage_sync.js detector/tests/js/test_tracker_domain_reducer.js detector/tests/js/test_tracker_auto_card_sync.js
```

Temporary reconstructed-commit result: 59/59 passed.

Rollback risk: Integration must be reverted first.

Flow availability: remains usable because old `control.html` does not yet activate the new modules; modified `tracker.js` guards optional DOM/module integrations.

### Commit 5 — atomic local server/browser integration

Message:

```text
feat(tracker): integrate local stream synchronization
```

Files:

```
tool/unlight-card-tracker/control.html
tool/unlight-card-tracker/detector/UnlightTrackerLauncher.spec
tool/unlight-card-tracker/detector/server.py
tool/unlight-card-tracker/observation-poller.js
tool/unlight-card-tracker/tracker-api.js
tool/unlight-card-tracker/tracker-db.js
tool/unlight-card-tracker/detector/tests/js/test_observation_poller.js
tool/unlight-card-tracker/detector/tests/js/test_tracker_api.js
tool/unlight-card-tracker/detector/tests/js/test_tracker_db.js
tool/unlight-card-tracker/detector/tests/launcher_exe_smoke.py
tool/unlight-card-tracker/detector/tests/test_static_tracker.py
tool/unlight-card-tracker/detector/tests/test_tracker_launcher.py
tool/unlight-card-tracker/detector/tests/test_uvicorn_smoke.py
```

Prerequisites: Commits 2, 3 and 4.

Tests:

```powershell
cd tool/unlight-card-tracker
node --test detector/tests/js/test_observation_poller.js detector/tests/js/test_tracker_api.js detector/tests/js/test_tracker_auto_card_sync.js detector/tests/js/test_tracker_db.js detector/tests/js/test_tracker_domain_reducer.js detector/tests/js/test_tracker_stage_sync.js

cd detector
python -m unittest tests.test_static_tracker tests.test_tracker_launcher tests.test_uvicorn_smoke -v
python -m unittest discover -s tests -q
```

Clean-checkout packaging acceptance, after the untracked-assets contamination risk is removed:

```powershell
python -m PyInstaller --clean UnlightTrackerLauncher.spec
python tests/launcher_exe_smoke.py --exe dist/UnlightTrackerLauncher/UnlightTrackerLauncher.exe
```

Rollback risk: low when reverted first; it returns to dormant browser modules and the old manual UI.

Flow availability: intended full automatic flow after tests/build/smoke pass.

## 9. Why A and B are rejected

### A as written

```
ignore
Python Core
JavaScript Tracker
Integration
```

Problems:

- does not explicitly repair the two missing files already referenced by `HEAD`;
- if the complete modified JavaScript stack includes Poller before Control, the old browser entry omits Domain Reducer and domain sessions fail;
- packaging remains invalid until the missing Domain Reducer enters an earlier commit.

### B as written

```
ignore
Python Core + JavaScript Tracker
Integration
```

Problems:

- combining core does not solve the activation-order problem if Poller is included while Control remains old;
- putting all browser implementation in the combined commit leaves an untestable/broken intermediate browser state;
- it hides the pre-existing missing-file repair inside a large feature commit and makes rollback less clear.

Proposal C is larger in commit count but smaller in dependency ambiguity.

## 10. Blockers and remaining verification

1. Standalone `tracker_api_server.py` does not enforce localhost-only binding. This is outside the 32-file closure but is a release security blocker.
2. PyInstaller copies the entire dirty `assets/` directory. Do not build the release artifact from the present working tree because untracked assets may be included.
3. `detector/.venv` is broken and points to a missing local Python 3.11 executable. Rebuild it from tracked requirements before reproducible packaging.
4. A real PyInstaller build and `launcher_exe_smoke.py` were not run in Gate 2.
5. Real-game validation was not performed; passing mocked/corpus tests is not equivalent to a live battle.
6. Existing launcher defaults enable redacted diagnostic output. This is safe-boundary tested and ignored, but should be an explicit release policy decision.

## 11. Gate 2 repository state

- Existing Tracker production/test files modified by Gate 2: none.
- `.gitignore`: still modified and unstaged from Gate 1.
- `Tracker_Convergence_Audit.md`: still untracked.
- `Tracker_Sprint1_Closure_Review.md`: created, untracked, not staged.
- Staged files: none.
- Commits or pushes: none.
- Watcher and Detector Assets Sprint: untouched.
