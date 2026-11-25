# Raid Bot offline regression checks

This directory contains the `unittest` regression tests for the Raid Bot,
Protocol 1.6 enrichment client, status display and Discord notification policy.

## Windows developer workflow

Run these commands **inside** `tool/raid-bot` on an isolated checkout:

```powershell
py -3.11 -m venv .venv
.\.venv\Scripts\python.exe -m pip install -r requirements-test.txt
.\.venv\Scripts\python.exe run_offline_regression.py --report D:\Download\RaidBot_Offline_Results.json
```

The test runner:

- Resolves `tool/core/runtime/cdp/ul_sniffer.py` from the repository;
  this fixes the otherwise missing `ul_sniffer` dependency.
- Uses `httpx2` exclusively for FastAPI/Starlette in-process test clients.
  `httpx2` is a test-only requirement, not a production service dependency.
- Runs each `test_*.py` as an isolated process with a per-file timeout.
- Installs a Python socket guard before each test process, disallowing
  non-loopback socket connections and datagrams.
- Removes DB, Discord webhook and game WebSocket credentials from its test
  process environment and disables automatic dotenv loading.
- Reports real case counts and distinguishes `MANUAL_NOT_DISCOVERED`
  from test files containing actual `unittest` cases.

The socket guard is a **development guard**, not a general-purpose security
sandbox. Always run these tests using local fixtures, never production secrets.

## Manual scripts intentionally excluded

The following modules contain `main()` functions but **zero unittest cases**:

- `tests/test_discord.py`
- `tests/test_formatter.py`
- `tests/test_notifier.py`

They are manual diagnostics and must not be invoked by an automated offline
suite. They are **not** counted as passed tests.

## Maintaining test expectations

- Protocol 1.6 handshake must negotiate an explicitly advertised supported
  version and fail closed on malformed/unsupported versions.
- Discord `notify_text_with_id` and `send_with_id` test doubles must follow
  the current message-ID and editing API; do not regress to `db_raid_delete`.
- Unconfirmed fragments remain marked unknown (`❓`); verified stage sources
  may authorize color.
- A Raid missing from an otherwise valid public snapshot remains active during
  the 60-second grace period. Verified HP=0/expiry is immediate.
- The live-state cache, rather than stale enrichment metadata, supplies
  current status icons in the observed-Raids API.
- Unknown player POINT/rank from missing observations must never be invented.

The offline suite is **not** approval to start the real Raid Player Collector.
It does not verify real game join/delete side effects or production rollback.
