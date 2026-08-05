# GRILLME.md

## Purpose

This file is the final adversarial review checklist for Unlight Tracker work.

Do not stop after the first green test run.

For every change, actively search for:

- false positives
- stale state
- hidden shared state
- test-order dependence
- old active sessions
- orphaned processes
- port leaks
- incorrect process ownership
- source/frozen path differences
- OneDrive file locks
- incomplete shutdown
- unsafe network exposure
- restricted information leaks
- production behavior that is only covered by mocks

When a defect is found, fix it and repeat the complete relevant verification cycle.

---

## Review Loop

Continue this loop until all completion criteria pass:

```text
Inspect code and current state
→ Identify failure mode or blind spot
→ Make the smallest scoped fix
→ Run targeted tests
→ Run full suite
→ Build the EXE
→ Run EXE smoke test
→ Inspect for stale state and leftovers
→ Review the tests themselves
→ Repeat when necessary
```

Do not pause merely to report intermediate progress.

---

## Stop and Ask Only When

Stop for user confirmation only if the next action requires one of these:

- deleting user data
- destructive database migration
- modifying a production database
- using a password, token, private key, or external account
- publishing or deploying externally
- staging, committing, or pushing Git changes
- choosing between product behaviors that cannot be resolved from code or existing requirements
- weakening a security or privacy boundary
- modifying unrelated dirty files

Otherwise, choose the safest, most conservative, reversible implementation and continue.

---

## Core Acceptance Criteria

### A. Launcher Startup

Verify that:

- the launcher starts from source
- the launcher starts from the PyInstaller EXE
- the Tracker API becomes ready
- an existing compatible API may be reused
- an incompatible process on port 8765 is rejected clearly
- CDP absence is treated as a waiting state, not a crash
- the browser opens only once
- `--no-browser` suppresses browser opening
- localhost-only API binding is enforced

---

### B. Exact Session Identity

The smoke test must independently verify the current launcher's session.

Required proof:

```text
Launcher output:
TRACKER_SESSION_ID=<full UUID>

GET /api/v1/sessions/active:
session_id == the exact same full UUID
```

The smoke test must fail when:

- only an old active session exists
- the active session belongs to another producer
- the UUID is abbreviated
- the launcher output and API active session differ
- the session was never registered
- activation failed

Do not accept “there is an active session” as sufficient proof.

---

### C. Session Lifecycle

Verify that:

- the Sniffer registers a new running session
- the launcher activates that exact session
- the active API returns that exact session
- shutdown finishes that session
- the finished session is not left active
- repeated finish calls are safe when designed to be idempotent
- no unrelated session is finished or cleared

After shutdown, query SQLite or the API and confirm the launcher's session has the expected final status.

---

### D. Process Ownership and Shutdown

Verify the shutdown sequence:

```text
Sniffer stop
→ session finish
→ launcher-owned API stop
→ launcher exit
```

Confirm:

- external compatible API is not stopped
- bounded graceful timeout is used
- terminate is only a fallback
- kill is only a final fallback
- child exit codes are reported
- Ctrl+C works
- Ctrl+Break works on Windows
- unexpected child exit is surfaced
- repeated shutdown is safe

After the test, confirm there are no remaining:

```text
UnlightTrackerLauncher processes
launcher-owned Python processes
Sniffer processes
Tracker API processes
test fake-CDP processes
```

---

### E. Port Cleanup

After smoke testing, verify no listener remains on:

```text
8765
9333
the fake-CDP test port
any temporary launcher test port
```

Do not assume process exit implies port cleanup.

Query the actual listeners and owning process IDs.

---

### F. Tracker Page and API

Verify:

- `/api/v1/health` returns the expected ready contract
- the active-session endpoint returns the exact current UUID
- `/tracker/` returns HTTP 200
- required static assets return HTTP 200
- API errors are displayed clearly
- an absent active session is treated as normal idle state
- a network error is distinguishable from no active session

---

### G. SQLite and Stale State

Run at least one test with pre-existing database state.

Include cases such as:

- old active session exists
- old finished session exists
- current session is different from old session
- prior smoke run terminated abnormally
- active flag and status disagree
- duplicate activation is attempted

The test must prove that stale SQLite state cannot make the new smoke test pass early.

Do not delete the user's real database to simplify testing.

Use isolated temporary data when possible.

---

### H. Source vs Frozen Paths

Verify both:

```text
Python source execution
PyInstaller frozen one-folder execution
```

Confirm correct resolution for:

- API server
- Sniffer
- Tracker frontend assets
- package modules
- runtime root
- application data directory

Do not rely on:

- current working directory
- repository absolute path
- user-specific OneDrive path
- `.venv`
- a globally installed Python

---

### I. Build Reproducibility

Build with Python 3.11.

Verify:

- build dependencies are explicitly declared
- runtime dependencies are explicitly declared
- the build script accepts an explicit Python path
- the spec includes required frontend assets
- hidden imports are handled
- one-folder output starts without the source tree
- the generated EXE version is the latest rebuild

When replacing build output:

1. verify the exact target path
2. remove only the task-owned generated directory
3. rebuild
4. rerun smoke testing

---

### J. Distribution Contents

Inspect the complete distribution recursively.

The output must not contain:

```text
events.sqlite3
*.jsonl
deck/
logs/
debug_frames/
__pycache__/
.venv/
raw packet captures
restricted diagnostic payloads
tokens or credentials
```

Report:

- file count
- total size
- EXE size
- SHA-256 of the EXE

---

### K. Security and Privacy

Confirm production behavior does not expose:

- hidden opponent hand
- unrevealed opponent deck
- future outcomes
- restricted packet contents
- Steam token
- authentication token
- private API secret
- local absolute paths in user-visible output unless necessary for diagnostics

Confirm the Tracker API binds only to localhost.

Do not relax security filtering to satisfy tests.

---

### L. Regression Tests

Run:

```text
targeted tests
full Python suite
JavaScript suite when relevant
py_compile
scoped diff --check
```

Record exact totals:

```text
passed
failed
skipped
```

A skipped test must be explained.

Warnings must be reviewed; do not automatically ignore them.

---

## Smoke Test Definition

The EXE smoke test is a minimal end-to-end verification, not complete real-game acceptance.

It must prove:

```text
EXE starts
→ API ready
→ fake or real CDP connects
→ Sniffer registers a new full UUID
→ Launcher reads that same UUID
→ Launcher activates that same UUID
→ API reports that same UUID as active
→ Tracker page returns 200
→ controlled shutdown occurs
→ session finishes
→ processes and ports are cleaned
```

When fake CDP is used, report it explicitly.

Do not claim that fake-CDP smoke proves:

- real game WebSocket compatibility
- PvE packet compatibility
- PvP packet compatibility
- battle event correctness
- game-update compatibility
- long-running stability

---

## Real-Game Verification Still Required

When the official server is available, separately verify:

```text
Steam launch with --remote-debugging-port=9333
real Electron renderer discovery
real WebSocket connection
new session registration
active session exact match
battle.started
hand/deal events
public card events
result event
session finish
Tracker cursor advancement
IndexedDB checkpoint persistence
page refresh without duplicate projection
PvE
PvP
disconnect/reconnect
launcher shutdown without leftovers
```

Do not merge mock and real-game results into one claim.

---

## Final Report Required

Do not finish with only “tests passed.”

The final report must contain:

### 1. Problems Found

For each issue:

- symptom
- root cause
- why prior tests missed it
- exact fix

### 2. Changed Files

For every changed file:

- path
- approximate line range
- function/class name
- purpose

### 3. Verification

Include:

- targeted test command and result
- full-suite command and result
- py_compile result
- build command and result
- EXE smoke result
- exact session UUID comparison
- final session status
- port cleanup result
- process cleanup result
- distribution inspection result
- scoped `git diff --check`
- scoped `git status`

### 4. Deliverable

Include:

```text
EXE path
EXE size
distribution total size
distribution file count
SHA-256
```

### 5. Remaining Work

Clearly separate:

```text
verified with mocks/fake CDP
verified locally
not yet verified against real game server
```

### 6. Git Confirmation

State whether any of the following occurred:

```text
git add
git commit
git push
git clean
broad restore/reset
```

Unless explicitly requested, all must remain not performed.

---

## Final Quality Gate

Before declaring completion, answer all of these with evidence:

1. Did this exact build run?
2. Did this exact launch create a new full session UUID?
3. Did the API report that exact UUID as active?
4. Did shutdown finish that exact session?
5. Did any stale session allow an early pass?
6. Are all launcher-owned processes gone?
7. Are all test ports closed?
8. Does the packaged Tracker page load?
9. Is the API localhost-only?
10. Does the dist exclude runtime and sensitive data?
11. Did the full regression suite pass?
12. Did the test itself prove what it claims?
13. Are real-game-only claims clearly marked as pending?
14. Were unrelated files left untouched?
15. Was no Git staging or commit performed without permission?

If any answer is unsupported, continue working.


## Overnight autonomous loop

Run the inspect → test → diagnose → minimally fix → retest loop.

Continue only while:
- a reproducible failure remains;
- the next action is inside the repository workspace;
- the action does not require network access or human interaction;
- the change remains within the declared task scope.

Stop immediately when:
- all automated acceptance criteria pass;
- the same failure remains after 3 materially different repair attempts;
- real-game interaction, credentials, external hardware, or human judgment is required;
- a requested action would cross the workspace or Git safety boundary;
- evidence becomes inconsistent or the working tree cannot be safely attributed.

Do not loop merely to consume time.

At the end, write:
- initial state;
- changes made;
- commands executed;
- pass/fail results;
- unresolved blockers;
- fake CDP status;
- real-game validation status;
- files changed;
- dirty-worktree status;
- recommended independent review steps.