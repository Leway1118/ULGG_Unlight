# AGENTS.md

## Project Scope

This repository contains the Unlight Tracker, detector, local API, browser tracker UI, CDP/WebSocket sniffer, and packaging tools.

The current desktop flow is:

```text
Steam Electron / CDP 9333
→ WebSocket Sniffer
→ Safe battle event parser
→ Tracker API 127.0.0.1:8765
→ SQLite
→ Browser poller / IndexedDB
→ Tracker UI
```

The Tracker API and SQLite are local-only components. Do not expose the API beyond localhost.

---

## Working Rules

### 1. Explore Before Editing

Before modifying code:

1. Read the relevant files.
2. Search for existing implementations, helpers, tests, and conventions.
3. Prefer extending existing behavior over creating parallel implementations.
4. When a question can be answered from the codebase, inspect the codebase instead of asking the user.

Do not guess file structure, runtime paths, APIs, database schema, or process lifecycle.

---

### 2. Change Scope

Only modify files required for the current task.

Do not perform unrelated cleanup, refactoring, formatting, renaming, dependency upgrades, or architecture changes.

Do not modify unrelated dirty working-tree files.

Never run:

```text
git add .
git add -A
git clean
git reset --hard
broad git restore
```

When reporting changes, include:

- file name
- approximate line range
- function or class name
- searchable original snippet when multiple similar sections exist

---

### 3. Git Safety

Unless the user explicitly asks:

- do not stage files
- do not commit
- do not push
- do not rewrite history
- do not delete branches
- do not clean untracked files

Use exact scoped paths for all Git commands.

Before finishing, run a scoped status and scoped diff check only for files touched by the task.

---

### 4. Runtime Data Safety

Do not add, package, commit, move, or delete runtime data unless explicitly required.

Excluded runtime content includes:

```text
deck/
logs/
*.jsonl
debug_frames/
events.sqlite3
__pycache__/
.venv/
runtime backups
captured raw packets
restricted diagnostic payloads
```

The Tracker SQLite database remains at:

```text
%LOCALAPPDATA%\ULGG\unlight-card-tracker\events.sqlite3
```

Do not replace, truncate, migrate destructively, or delete it without explicit approval.

---

### 5. Security Boundary

The production Tracker must process only information allowed by the safe event boundary.

Do not expose or persist:

- hidden opponent hand
- unrevealed opponent deck details
- future outcomes
- restricted packet payloads
- authentication tokens
- Steam tokens
- session secrets
- raw private diagnostic traffic

Diagnostic capture and production state must remain separated.

Do not weaken the safe parser or restricted-event filters to make a test pass.

---

### 6. Network and Ports

Expected local services:

```text
Tracker API: 127.0.0.1:8765
Electron CDP: 127.0.0.1:9333
```

The launcher may accept host arguments, but production API binding must remain localhost-only.

Do not bind the Tracker API to:

```text
0.0.0.0
::
LAN IP
public interface
```

Port ownership must be verified before reuse or shutdown.

Never terminate a process that the launcher did not start unless explicitly authorized.

---

### 7. Session Authority

Session behavior must remain explicit and deterministic.

Rules:

- `gameStart` is the authoritative battle start.
- `result` is the authoritative battle finish.
- Events before `gameStart` must not mutate battle state.
- Local player side is unknown unless explicitly resolved.
- Do not infer local side from assumptions.
- Only one Tracker session may be active.
- A launcher must activate the exact session created by its own Sniffer.
- Never select a session by abbreviated UUID.
- Never treat “any active session” as proof that the current launch succeeded.
- Finished sessions must not silently reactivate.
- Shutdown must finish the launcher's own session.

---

### 8. Launcher Process Ownership

The launcher owns only child processes it starts.

Shutdown order:

```text
1. Stop Sniffer
2. Wait for Sniffer/session finish
3. Stop launcher-owned Tracker API
4. Terminate only after bounded graceful timeout
5. Kill only as a final fallback
```

Do not shut down an external compatible Tracker API reused by the launcher.

After tests, confirm no launcher-owned process or test port remains.

---

### 9. Source and Frozen Execution

All paths must work in both modes:

```text
Python source execution
PyInstaller frozen one-folder EXE
```

Do not hardcode the current repository directory, user name, OneDrive path, or virtual environment path.

Resolve assets and scripts from an explicit runtime root.

---

### 10. Packaging

Target:

```text
Python 3.11
PyInstaller one-folder
Console mode
UnlightTrackerLauncher.exe
```

The build output is:

```text
detector/dist/UnlightTrackerLauncher/
```

The build must not include runtime data or local databases.

When OneDrive locks build output, only the following generated paths may be removed after exact-path verification:

```text
detector/build/
detector/dist/UnlightTrackerLauncher/
```

Do not delete any broader directory.

---

## Required Validation

For production-code changes, run the relevant subset of:

```text
targeted unit tests
full Python test suite
JavaScript tests when browser code changes
py_compile
PyInstaller build
EXE smoke test
scoped git diff --check
scoped git status
```

A green test is not enough. Confirm the test actually proves the intended behavior and cannot pass using stale state.

---

## Reporting Format

Final reports must include:

1. What changed.
2. Why it changed.
3. Files and approximate line ranges.
4. Test commands and results.
5. Build result.
6. EXE path, size, and SHA-256 when packaging is involved.
7. Smoke-test evidence.
8. Remaining real-environment verification.
9. Scoped Git status.
10. Explicit confirmation that no staging or commit was performed, unless requested.

Do not report a partial test as full verification.
Do not claim real-game validation when only fake CDP or mocked events were used.
