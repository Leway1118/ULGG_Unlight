# Unlight Tracker Convergence Audit

Audit date: 2026-07-29  
Scope: `tool/unlight-card-tracker/` only  
Mode: read-only inspection and tests; this report is the only file created. No source/config/runtime file was modified, deleted, staged, or committed.

## Executive recommendation

Proceed with a narrowly scoped first Tracker Sprint for the safe battle stream, durable browser projection, stage sync, automatic card sync, and their tests. Do not mix in computer-vision recognition changes, template curation, raw packet tools, screenshots, JSONL, Watcher, or historical copies.

The four new core files are not an isolated change. They depend on modified protocol, reducer, adapter, sniffer, API, persistence, poller, UI, server, launcher packaging, and tests. The proposed Sprint closure is listed below.

Current Tracker-scoped state before creating this report:

| State | Count |
|---|---:|
| Modified tracked files | 24 |
| Untracked files | 712 |
| Staged Tracker files | 0 |
| Total dirty Tracker entries | 736 |
| Tracked diff | 3,607 insertions, 894 deletions |

The 712 untracked files total approximately 5.35 MB. Of those, 658 are images: 563 PNG, 94 JPG, and 1 WEBP.

## 1. Proposed first Sprint: production source

### New production source

| File | Purpose | Dependency evidence | Recommendation |
|---|---|---|---|
| `tool/unlight-card-tracker/detector/battle_stream_processor.py` | Converts safe observations into ordered domain events and projection updates | Imported and instantiated by `unlight_websocket_sniffer.py`; uses `battle_state_reducer.py` and `battle_tracker_adapter.py` | Include |
| `tool/unlight-card-tracker/tracker-stage-sync.js` | Resolves stage codes and coordinates field-deck rebasing | Required by auto card sync and loaded before it by the Tracker UI | Include |
| `tool/unlight-card-tracker/tracker-domain-reducer.js` | Browser-side replayable domain projection | Used by `observation-poller.js`; persists through `tracker-db.js` | Include |
| `tool/unlight-card-tracker/tracker-auto-card-sync.js` | Applies safe dealt-card events to Tracker ownership | Requires stage sync and Tracker integration hooks | Include |

### Modified production closure

| File | Role in the closure |
|---|---|
| `tool/unlight-card-tracker/control.html` | Loads stage sync, domain reducer, auto card sync, and the existing Tracker scripts in dependency order |
| `tool/unlight-card-tracker/detector/UnlightTrackerLauncher.spec` | Includes the new browser scripts in frozen one-folder packaging |
| `tool/unlight-card-tracker/detector/battle_observation_stream.py` | Safe observation stream and sequence boundary |
| `tool/unlight-card-tracker/detector/battle_protocol.py` | Canonical event/schema validation |
| `tool/unlight-card-tracker/detector/battle_state_reducer.py` | Python battle-state reducer used by the stream processor |
| `tool/unlight-card-tracker/detector/battle_tracker_adapter.py` | Maps safe observations to tracker domain events |
| `tool/unlight-card-tracker/detector/server.py` | Localhost Tracker API/static asset routes for the new scripts |
| `tool/unlight-card-tracker/detector/unlight_websocket_sniffer.py` | Instantiates and feeds `BattleStreamProcessor` |
| `tool/unlight-card-tracker/observation-poller.js` | Polls ordered events and commits the browser projection |
| `tool/unlight-card-tracker/tracker-api.js` | Client API for current/active session and cursor event pages |
| `tool/unlight-card-tracker/tracker-db.js` | IndexedDB checkpoint, projection, identity, and idempotency storage |
| `tool/unlight-card-tracker/tracker.js` | Tracker ownership, reset, undo, and auto-sync integration |

Recommendation: review and eventually stage these 16 production files as one coherent implementation batch, together with the test closure below. Omitting any of the server, packaging, persistence, or UI integration files risks a new-checkout 404, missing frozen asset, replay gap, or duplicate deduction.

`tool/unlight-card-tracker/index.php` is not part of this closure. `server.py` and the PyInstaller spec both select `control.html`; no formal runtime reference to `index.php` was found. Treat it as uncertain/alternative hosting entry until deployment ownership is established.

## 2. Proposed first Sprint: tests

### New tests

| File | Coverage |
|---|---|
| `tool/unlight-card-tracker/detector/tests/test_battle_stream_processor.py` | Stream ordering, safe event boundary, sessions, sequence and failure behavior |
| `tool/unlight-card-tracker/detector/tests/js/test_tracker_stage_sync.js` | Stage mapping, fail-closed behavior, pending and confirmation flow |
| `tool/unlight-card-tracker/detector/tests/js/test_tracker_domain_reducer.js` | Replayable projection and safe payload rejection |
| `tool/unlight-card-tracker/detector/tests/js/test_tracker_auto_card_sync.js` | Automatic deduction, idempotency, pending stage and undo behavior |

### Modified tests

```
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

Recommendation: keep all 16 test files with the production closure. The launcher/static/smoke assertions verify that new assets are shipped and served, not only that isolated reducers pass.

## 3. Other production candidates, outside this Sprint

These files look like source code rather than runtime output, but they implement the separate screen-recognition pipeline. They should receive a later, dedicated review with their template provenance and accuracy fixtures:

```
tool/unlight-card-tracker/detector/app.py
tool/unlight-card-tracker/detector/card_detection_api.py
tool/unlight-card-tracker/detector/card_digit_detector.py
tool/unlight-card-tracker/detector/card_face_reader.py
tool/unlight-card-tracker/detector/card_icon_detector.py
tool/unlight-card-tracker/detector/deck_counter.py
tool/unlight-card-tracker/detector/hand_detector.py
tool/unlight-card-tracker/detector/models.py
tool/unlight-card-tracker/detector/ok_button_detector.py
tool/unlight-card-tracker/detector/phase_detector.py
tool/unlight-card-tracker/detector/public_card_count_detector.py
tool/unlight-card-tracker/detector/result_detector.py
tool/unlight-card-tracker/detector/roi.py
tool/unlight-card-tracker/detector/run_card_detection.py
tool/unlight-card-tracker/detector/state_tracker.py
tool/unlight-card-tracker/detector/config.json
```

Supporting recognition/development tools, also outside this Sprint:

```
tool/unlight-card-tracker/detector/debug_face_regions.py
tool/unlight-card-tracker/detector/debug_icon_matching.py
tool/unlight-card-tracker/detector/export_problem_digits.py
tool/unlight-card-tracker/detector/extract_card_faces.py
tool/unlight-card-tracker/detector/extract_digit_templates.py
tool/unlight-card-tracker/detector/extract_face_parts.py
tool/unlight-card-tracker/detector/frame_debug.py
```

Ad-hoc recognition checks outside the formal `detector/tests/` suite:

```
tool/unlight-card-tracker/detector/test_card_digits.py
tool/unlight-card-tracker/detector/test_card_faces.py
tool/unlight-card-tracker/detector/test_card_icons.py
tool/unlight-card-tracker/detector/test_digit_scores.py
tool/unlight-card-tracker/detector/test_hand_detector.py
```

Recommendation: do not stage these in the first Sprint. Later decide whether the ad-hoc checks become reproducible fixtures/tests or remain local calibration tools.

## 4. Detector template and image assets

Every untracked template image is covered by the directory groups below.

| Directory | Files | Classification | Evidence and recommendation |
|---|---:|---|---|
| `detector/templates/card-digits-runtime/` | 169 | Runtime/debug artifacts | Explicit runtime destination used by the upload/calibration API; ignore the whole exact directory |
| `detector/templates/card-icons-runtime/` | 4 | Runtime/debug artifacts | Explicit runtime upload/calibration destination; ignore the whole exact directory |
| `detector/templates/personal-events/` | 213 | Production asset candidate | Loaded by `live_screen_monitor.py`; 212 templates were loaded during tests, plus the manifest. Review provenance, then commit in a later detector-assets change |
| `detector/templates/card-icons/` | 66 | Production asset candidate / uncertain curation | Used by icon recognition, but includes dated live captures; curate separately |
| `detector/templates/public-card-count-digits/` | 52 | Production asset candidate / uncertain curation | Used by `live_screen_monitor.py`; contains dated calibration samples; curate separately |
| `detector/templates/digits/` | 17 | Production asset candidate / duplicates | Used by digit recognition; several files are byte-identical to runtime-digit files |
| `detector/templates/battle-result/` | 5 | Production asset candidate | Result recognition templates; separate recognition-assets commit |
| `detector/templates/card_types/` | 5 | Duplicate assets | All five are byte-identical to corresponding files under `card-icons/card_types/`; choose one canonical location |
| `detector/templates/ok-button/` | 4 | Production asset candidate | Button-state recognition; later asset review |
| `detector/templates/card-back/` | 2 | Production asset candidate | Card-back recognition; later asset review |
| `detector/templates/battle-loading/` | 1 | Production asset candidate | Loading-state recognition; later asset review |
| `detector/templates/card-digits/` | 1 | Uncertain | Incomplete-looking curated directory; verify intended loader path |
| `detector/templates/public-card-count-single/` | 1 | Uncertain | Alternative/special-case template path; verify intended loader |

Exact-content hashing found 19 duplicate image groups containing 40 files. Besides repeated runtime digit samples, the five `card_types/ac*.png` files duplicate the corresponding `card-icons/card_types/*.png` files. Do not stage both locations without a loader-path decision.

Additional untracked image groups:

| Directory | Files | Classification | Recommendation |
|---|---:|---|---|
| `tool/unlight-card-tracker/assets/event/` | 105 | Uncertain/reference assets | No current tracked or dirty source reference was found; keep out of Sprint and verify intended UI/data owner |
| `tool/unlight-card-tracker/assets/sites/tw/` | 14 | Uncertain/reference assets | No current tracked or dirty source reference was found; keep out of Sprint and compare against canonical site data before inclusion |

These 119 images and the 539 template images account for all 658 untracked Tracker image files.

## 5. Runtime/debug artifacts and ignore gaps

Already ignored correctly:

```
/tool/unlight-card-tracker/detector/build/
/tool/unlight-card-tracker/detector/dist/
/tool/unlight-card-tracker/detector/output/
/tool/unlight-card-tracker/detector/deck/
/tool/unlight-card-tracker/detector/debug_frames/
/tool/unlight-card-tracker/detector/events.sqlite3
/tool/unlight-card-tracker/detector/*.sqlite3
detector/.venv/
detector/__pycache__/
detector/logs/
detector/*.log
```

Recommended exact additions to the root `.gitignore` in a later change:

```gitignore
# Tracker generated calibration/runtime assets
/tool/unlight-card-tracker/detector/templates/card-digits-runtime/
/tool/unlight-card-tracker/detector/templates/card-icons-runtime/

# Tracker generated inventories and restricted captures
/tool/unlight-card-tracker/detector/templates_structure.txt
/tool/unlight-card-tracker/**/*.jsonl
```

`git check-ignore` confirmed that the two runtime template directories, `templates_structure.txt`, and Tracker JSONL are not currently ignored. `templates_structure.txt` is generated inventory output and contains a local absolute OneDrive path. No JSONL is currently visible as untracked, but the rule prevents future raw diagnostic captures from entering a commit.

Do not add broad ignores for all PNG/JPG or all `templates/`; most non-runtime templates are potential production recognition assets.

## 6. Obsolete, duplicate, or local-only files

| File | Finding | Recommendation |
|---|---|---|
| `detector/未來視.py` | Byte-identical to `detector/bonus_future.py`; no formal-flow reference | Do not include; moreover the behavior exposes future outcomes and is outside the production safe-event boundary |
| `detector/抓戰鬥.py` | Byte-identical to `detector/combat_packet_capture.py`; no formal-flow reference | Do not include; raw packet capture must remain diagnostic and separated from production |
| `detector/抓牌組.py` | Not identical to `deck_counter.py`; standalone CDP/deck packet capture, optionally writes `deck/packets.log`; no formal-flow reference | Keep out of Sprint; manually decide whether to retain as a restricted diagnostic tool |
| `detector/bonus_future.py` | ASCII-named copy of future-outcome inspection tool | Do not include in production |
| `detector/combat_packet_capture.py` | ASCII-named raw packet diagnostic tool | Do not include in production; never package raw logging |
| `detector/hand_detector copy.py` | Named copy/snapshot | Remove or archive after confirmation; do not ignore as production source |
| `detector/live_screen_monitor_backup.py` | Explicit backup copy | Remove or archive after confirmation |
| `detector/live_screen_monitor_api_version.py` | Version-suffixed alternative implementation | Compare against tracked `live_screen_monitor.py`, then retire or rename intentionally |
| `detector/card_detection_api_api_version.py` | Version-suffixed alternative implementation | Compare with `card_detection_api.py`; do not include both by default |
| `README_v1.md` | Versioned documentation snapshot | Archive outside source tree or remove after confirmation |
| `control_v1.html` | Versioned UI snapshot | Archive outside source tree or remove after confirmation |
| `README_updated.md` | Ambiguous replacement documentation | Reconcile into a canonical `README.md`; do not commit with a transitional name |

The Chinese filenames are referenced only by their own usage text (and by identical ASCII copies carrying the same usage text). Launcher, server, spec, formal tests, and browser flow do not import or execute them.

## 7. Uncertain/local diagnostics

```
tool/unlight-card-tracker/detector/check_events.py
tool/unlight-card-tracker/detector/check_schema.py
tool/unlight-card-tracker/detector/check_session.py
```

All three contain hard-coded local absolute paths. They are database inspection helpers, not portable production code. Recommendation: keep out of Sprint; parameterize paths and document their diagnostic scope before any later tools commit.

`tool/unlight-card-tracker/index.php` is an untracked alternative entry page. Current local server and frozen package use `control.html`, so it is not required for the desktop flow. Recommendation: leave untracked until a separate web-hosting deployment decision.

## 8. Credential and safety scan

- No private-key header was found in the untracked textual files.
- No obvious credential literal was found in the reviewed Sprint production closure or `detector/config.json`.
- Credential-related words found in tests/core code are validation fixtures, token identifiers, and safe-boundary checks, not production secret values.
- `check_events.py`, `check_schema.py`, `check_session.py`, and `templates_structure.txt` contain local absolute paths; none belongs in the first Sprint as-is.
- Raw packet/future-outcome tools are explicitly excluded from production because their purpose conflicts with the safe event boundary.
- Tracker API remains localhost-only. Existing listeners observed after the tests were `127.0.0.1:8765` and Electron CDP `127.0.0.1:9333`; no public-interface listener was observed.

## 9. Test results

### Targeted Python battle-stream suite

Command:

```powershell
$env:PYTHONPATH='C:\Users\UVE\AppData\Local\Temp\unlight-api-test-deps'
python -m unittest tests.test_battle_stream_processor tests.test_battle_observation_stream tests.test_battle_protocol tests.test_battle_state_reducer tests.test_battle_tracker_adapter tests.test_sniffer_battle_runtime -v
```

Result: 298 tests passed; 1 skipped. The skip is corpus-dependent: the local corpus already contains `battle_started`, so the “partial corpus without start remains idle” case is not applicable.

### JavaScript suite

Command:

```powershell
node --test detector/tests/js/test_observation_poller.js detector/tests/js/test_tracker_api.js detector/tests/js/test_tracker_auto_card_sync.js detector/tests/js/test_tracker_db.js detector/tests/js/test_tracker_domain_reducer.js detector/tests/js/test_tracker_stage_sync.js
```

Result: 84/84 passed.

### Full Python detector suite

Command:

```powershell
$env:PYTHONPATH='C:\Users\UVE\AppData\Local\Temp\unlight-api-test-deps'
python -m unittest discover -s tests -q
```

Result: 417 tests passed; 1 skipped. No test failed. Expected argument-parser errors printed by negative tests do not indicate suite failures. Deprecation warnings were emitted for the temporary FastAPI/Starlette/httpx test dependency combination.

### Environment issue

`detector/.venv` cannot run because its launcher points to a missing user-local Python 3.11 executable. Tests were therefore run with the bundled Python 3.12 runtime and an existing temporary dependency directory. Recommendation: rebuild `.venv` from tracked `requirements.txt` rather than committing or repairing the virtual environment in place.

No build or EXE smoke test was run in this read-only audit. Existing `UnlightTrackerLauncher` and game/CDP processes were observed and deliberately left untouched.

## 10. Recommended sequence

1. Add only the four precise ignore rules listed above; verify newly hidden files; commit separately.
2. Review the proposed 16 production and 16 test files as one battle-stream/domain-sync Sprint.
3. Run scoped diff/security review, Python suite, JS suite, PyInstaller build, and launcher smoke test before staging.
4. Stage only the reviewed exact paths; never use `git add .` or `git add -A`.
5. Handle recognition source and curated templates in a separate Sprint.
6. Decide the canonical handling of diagnostic scripts and duplicate/legacy files.
7. Audit Watcher only after Tracker is clean.

Suggested future implementation commit message:

```text
feat(tracker): converge battle stream and automatic card sync
```

## Final scoped state

- Tracker source/config/runtime files modified by this audit: none.
- Tracker files deleted: none.
- Tracker files staged: none.
- Commits created: none.
- Watcher inspected or modified: no.
- Requested report created at repository root: `Tracker_Convergence_Audit.md` (untracked, not staged).
