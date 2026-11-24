# Raid Player Collector — dedicated-account safety acceptance gate

Status: **OFFLINE_FIXTURE_PASS / LIVE_GATE_BLOCKED** (2026-11-24, Asia/Taipei).
Target base: `review/raid-protocol16-offline-p0` at `ae20e75`.
This document and code change belong to a **new LOCAL-ONLY** branch
`feature/raid-collector-safety-gate`. Do not amend or merge Draft PR #13.

## 1. Findings in the actual code / service

| Evidence | Present behavior | Risk |
|---|---|---|
| `raid_player_collector_daemon.SharedAuthProvider` | Reads watcher-owned TW session file at `/run/unlight/tw_auth_session` by default | Not a dedicated test account; watcher/gameplay isolation not guaranteed |
| `raid_player_collector_daemon.main` | Active Raid poll, 30s refresh per Raid, 2s spacing, tries all due | No single-Raid allowlist / global daily cap / one-shot stop |
| In-memory `blocked` and `last_attempt` | Not persisted on process restart | Unconfirmed join may lose blocking state |
| VPS `unlight-raid-player-collector.service` | disabled/inactive; Restart=always; User unset; ProtectSystem=no, PrivateTmp=no, NoNewPrivileges=no | Restart could bypass in-memory blocked state; insufficient isolation |
| `PublicRaidEnrichmentClient` | `register → online → db_raid → raid_code_input → db_raid → raid_delete → verify absence` | Join/delete change game-account state; requires explicit separate approval |
| `profound_id_is_absent` (before this branch) | Treated None/malformed snapshots as empty | Could incorrectly confirm cleanup |
| `_parse_rank` (before this branch) | Missing/bad point became zero; negative point passed to next layer | Incorrect statistics and potential ranking overwrite |
| History repository writer (before this branch) | Missing/bad point coalesced to zero | Could overwrite reliable prior POINT |
| Passive watcher captured events | Recent `db_raid` was `[[]]`; `db_raid_support` public list omitted player rank | Does not prove individual rank history was recovered |

No live game messages were sent by this gate.

## 2. Minimal local safety fixes in this branch

- Check `db_raid` snapshot structure and row IDs **before any join attempt**.
- Refuse to confirm target Raid absence on empty/malformed/ambiguous responses.
  A properly-shaped `[[]]` can represent a genuine empty current-account list;
  invalid responses MUST NOT be treated as successful cleanup.
- Treat partial/empty/malformed `rank[]` and missing/invalid POINT as
  **UNKNOWN**, never zero; the exact value `point=0` is still valid.
- Rank persistence rejects invalid POINT before opening any database connection.
- All changes are **local-only**. These fixes alone do not authorize live usage.

Tests: `tests/test_collector_safety_gate.py` (6 new offline cases);
all legacy offline tests must remain green.

## 3. Gate dependencies (ordered; do not skip)

### G0 — Code/CI quality
- [x] Draft PR #13 offline CI succeeded: 170/170, but PR remains unmerged.
- [x] This separate local branch: 6 targeted safety tests passed, full suite 176/176.
- [ ] Review this branch independently; PR #13 is not modified.
- [ ] Recheck Protocol 1.6 real server response on **authorized** account only.

### G1 — Test account ownership and permission (**BLOCKED**)
- [ ] Owner provides a separate, legitimate TW in-game test account explicitly
  authorized for a one-time join-and-leave test; no shared production login.
- [ ] Verify that testing is permitted under applicable game/community rules.
- [ ] Use a **dedicated** auth provider/credential store; code must not read
  `/run/unlight/tw_auth_session` or reuse the production watcher identity.
- [ ] Verify authorized account identity via privacy-safe fingerprint
  (not actual UUID/token in logs or reports).
- [ ] Credential vault permissions and rotation/revocation confirmed.
- [ ] Explicit user go/no-go approval immediately before any game interaction.

### G2 ? Isolated execution and side-effect limits (**PARTIAL: offline-only fixture; live blocked**)
- [x] Offline One-shot fixture with a single allowlisted Raid, at most one simulated join, and persisted PENDING journal; see COLLECTOR_G2_OFFLINE_ONESHOT.md.
- [x] Crash/restart/cancel/concurrent and fresh-fixture-verification tests; no real game login or client adapter.
- [ ] Production-grade account-scoped canonical journal directory, non-root OS sandbox and operator-only clearance after abnormal exit.
- [ ] Separate non-root local/VM execution; no production service, no production DB writes.
- [ ] No systemd `Restart=always`; test runner is **one-shot** / no daemon loop.
- [ ] Only one allowlisted test Raid code; max **one** join attempt per approved run.
- [ ] Account's `db_raid` pre-snapshot valid and preferably empty; otherwise STOP
  until existing account Raid presence and possible deletion safety are reviewed.
- [ ] Bounded timeouts/retries and a **persisted fail-closed pending-cleanup journal**
  before emitting `raid_code_input`; restart must not silently clear the block.
- [ ] Any uncertainty disables all new join commands; manual recovery only.
- [ ] Rate-limit and user-approved stop conditions documented.
- [ ] Operational rollback procedure independently rehearsed on fake server.

### G3 — Test Raid and game constraints (**BLOCKED**)
- [ ] Confirm test Raid explicitly permits a non-combat join/leave and is authorized
  by its creator or is an approved dedicated test target.
- [ ] Avoid public-player collateral effects; no combat, rewards or rank manipulation.
- [ ] Confirm no purchase, stamina spend, damage, reset, or exclusive claim is caused.
- [ ] Confirm exact current Protocol 1.6 `__handshake_s`, `db_raid` row schema and
  `raid_delete` response through authorized, isolated session.
- [ ] If any unexpected side effect appears, STOP immediately.

### G4 — One-shot game acceptance (**NOT RUN — requires new explicit authorization**)
- [ ] Before join: valid handshake/registration/online, validated owned-Raid list,
  authorized account fingerprint and one allowlisted target; audit ID created.
- [ ] Send exactly one `raid_code_input` and record result without exposing secrets.
- [ ] If joined, capture unique newly-added `profound_id` and direct `rank[]`
  with fields/types/coverage; missing POINT remains UNKNOWN.
- [ ] Send `raid_delete` only for the unique Raid proven to be added by this test.
- [ ] Require explicit delete acknowledgment and valid post-delete snapshot absent;
  then perform an **independent fresh-session read-only** verification.
- [ ] Any failure/timeout/cancel/network reset preserves pending-cleanup gate,
  requires manual review and prohibits further joins.
- [ ] Confirm the test account and target Raid returned to expected baseline.
- [ ] Destroy test session/auth after approval and complete redacted audit log.

### G5 — Staging data/provenance (**BLOCKED**)
- [ ] Use isolated staging DB; no changes to `raid_player_history` on production.
- [ ] Persist source protocol version, observed_at, account fingerprint hash,
  evidence type, cleanup outcome, completeness flag and collection window.
- [ ] No silent `point=0` fallback, no claim `point` is verified damage.
- [ ] Dedup by server + Raid + player identity and separate snapshot timestamp.
- [ ] Benchmark rate limit and stability before discussing any continuous collector.
- [ ] Checkpoint 2026 only uses explicitly supported observation ranges;
  missing Oct–Nov 2026 records are not reconstructed by inference.

## 4. Immediate STOP conditions

Abort and freeze *all new joins* if: test account identity is uncertain; account
has pre-existing unknown Raid; `accepted` lacks supported version; bad `db_raid`
shape; more than one new Raid candidate; no consent for target Raid;
rank row partially missing; an unexpected payment/AP/raid side effect occurs;
cleanup confirmation absent or inconsistent; verifier mismatches account;
service restarts without persisted cleanup state; or a watchdog/timeout triggers.

No automatic repeated join/delete as a repair action.

## 5. Recovery and rollback

- Test phase must not run as the existing systemd service.
- Stop the one-shot process **only when it cannot abandon a joined Raid**;
  if a join may have occurred, preserve cleanup journal and attempt read-only
  state verification with the authorized test account.
- If cleanup is uncertain: mark `BLOCKED_PENDING_CLEANUP`, disable new joins,
  alert the operator, preserve redacted evidence, and use only explicitly
  approved manual cleanup steps.
- After confirmed absence from an independent session, clear the blocking
  record with operator signoff; never clear on reboot alone.
- No app/DB/live service rollback is needed for current offline branch because
  nothing is deployed. For future approved deployment, revert specific PR and
  stop test-only process after verifying account state.

## 6. Proposed evidence without exposing secrets

`test_run_id`, protocol offered/selected, privacy-safe account alias,
prejoin Raid ID count, exact target allowlist verification result, join sent/ACK,
rank presence/count and per-field validation rate, cleanup sent/ACK,
in-session absence, fresh-session absence, error class, monotonic timestamps,
final operator signoff; **no auth tokens, actual account UUID, raw Raid codes,
game payloads, player identities, DB credentials or webhook URLs in reports**.

Recommendation: Keep PR #13 draft and production Collector inactive. The next
decision is to arrange a truly independent test account and an authorized test Raid.
Only then design and review the non-root one-shot harness (not a production daemon),
followed by a separate explicit approval for any live game interaction.
