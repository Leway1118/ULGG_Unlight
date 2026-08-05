---
name: unlight-release-guardian
description: Perform the final adversarial acceptance gate for completed Unlight Tracker, CDP Sniffer, localhost Tracker API, SQLite, browser IndexedDB, PyInstaller Launcher EXE, packaging, or smoke-test work. Use after an Unlight Tracker development slice, for Launcher EXE build or acceptance, smoke tests, false-positive and stale-session audits, process or port cleanup checks, when the user says "依 GRILLME 驗收", or when explicitly invoked as $unlight-release-guardian. Do not automatically use for ordinary PHP UI edits, copy-only changes, data queries, Tracker-unrelated work, or early requirements exploration before final acceptance.
---

# Unlight Release Guardian

Apply a strict completion gate to the current Unlight repository. Treat a green test as evidence to examine, not proof by itself.

## Load project authority

Before acting, read the repository-root [AGENTS.md](../../../AGENTS.md) and [GRILLME.md](../../../GRILLME.md) completely. Treat them as authoritative and this skill as an execution guide; do not substitute this summary for either source.

## Preserve scope and safety

- Inspect relevant implementation, tests, helpers, current state, and dirty files before editing.
- Modify only files required by the current task. Preserve unrelated dirty files.
- Keep the Tracker API localhost-only and preserve the safe-event/privacy boundary.
- Never alter, delete, or migrate the user's production SQLite data to simplify verification. Prefer isolated temporary data.
- Never terminate a process without verifying that the launcher or test owns it.
- Do not run `git add`, `git commit`, `git push`, `git clean`, broad restore, or reset.

## Run the adversarial acceptance loop

1. Reconstruct the exact behavior being accepted from code and tests.
2. Identify false-positive paths, stale or shared state, test-order dependence, old active sessions, source/frozen path differences, and incomplete shutdown.
3. Make the smallest safe, reversible, in-scope correction when one is needed.
4. Run targeted tests and inspect whether they prove the intended behavior without stale state.
5. Run the full Python suite after targeted tests. Run the JavaScript suite when browser, tracker, IndexedDB, or related code is involved.
6. Run `py_compile` for applicable Python production changes.
7. When packaging or launcher behavior is in scope, build the Python 3.11 PyInstaller one-folder EXE and smoke-test that exact build.
8. Repeat the relevant complete verification cycle after every correction.

Fix and retest safe, reversible issues that do not require product judgment. Stop and ask only for actions involving user data, a production database, credentials, external publication, an unresolved product-behavior choice, a weakened security boundary, or unrelated dirty files.

## Prove exact session identity and lifecycle

For launcher smoke tests:

- Obtain `TRACKER_SESSION_ID=<full UUID>` from the current Launcher's own output.
- Independently query the active-session API and require `session_id` to equal that exact full UUID.
- Reject abbreviated UUIDs, any-active-session checks, old sessions, mismatched producers, and stale SQLite state as proof.
- After controlled shutdown, query the API or isolated SQLite state and prove that the same session is `finished` and no longer active.
- Report fake-CDP evidence separately from real-game evidence. Never claim fake-CDP validation proves real game, PvE, PvP, packet compatibility, or long-running stability.

## Prove cleanup and ownership

After tests and smoke:

- Inspect actual listeners and owning PIDs for `8765`, `9333`, the fake-CDP port, and every temporary launcher test port.
- Confirm no launcher-owned Launcher, Python, Sniffer, Tracker API, fake-CDP, or other child process remains.
- Distinguish an external compatible API from a launcher-owned API and never stop the external process.
- Verify shutdown order and bounded graceful handling before terminate or kill fallbacks.

## Inspect the exact distribution

When packaging is in scope, recursively inspect the newly built `detector/dist/UnlightTrackerLauncher/` and reject:

- SQLite databases, `*.jsonl`, logs, deck data, debug frames, raw captures, runtime backups, or restricted diagnostics
- `__pycache__`, `.venv`, credentials, tokens, secrets, or user-specific runtime data

Record the distribution file count and total size, plus the EXE path, size, and SHA-256. Confirm that the exact reported build was the one smoke-tested.

## Finish with evidence

Run scoped `git diff --check` and scoped `git status` only for task-touched files. Report:

1. Problems found, root causes, missed-test reasons, and exact fixes.
2. Changed files with approximate line ranges and function or class names where applicable.
3. Exact targeted, full-suite, JavaScript, `py_compile`, build, and smoke commands with passed, failed, and skipped totals.
4. Full session UUID comparison, final session state, port cleanup, process cleanup, and distribution inspection evidence.
5. EXE path, sizes, file count, and SHA-256 when packaging applies.
6. What was verified locally or with fake CDP, and what remains for real-game verification.
7. Scoped Git status and explicit confirmation that no staging, commit, push, clean, broad restore, or reset occurred.

Do not declare completion while any required claim lacks evidence.
