# M3-C-R1 synthetic integrated staging

DRAFT ONLY. Base PR #21 exact `1e3ad7333775e169e464a9fdcff712b98fb13891`, R3 run 45351363203 (8/8). Eight new test/workflow/doc files. No product route changes, no deployment, no real Unlight, no existing PR/master mutation.

## Gates
G01 immutable baseline and eight-path allowlist, forward/reverse patch checks.
G02 Docker internal network, fixed synthetic DB, no published ports, runtime source mounts read-only, root bootstrap separate from writer and API, no production environment. Images pulled/built on hosted runner before tests; this is not physical air-gap.
G03 actual seven-table column-only MariaDB SELECT account; eight forbidden SELECT/DML/DDL probes, writer and API use reader principal.
G04 trusted mock Steam session resolved via MariaDB, two same-name regions A and B, request selector/foreign session and logout rejection. No actual Steam authentication.
G05 independently enumerated upstream expected rows (reused R3 fixture oracle), distinct signed receipt over actual MariaDB Reader observation. Stable actor mapping and signed history. CUP public game extraction uses fixture-specific tournament 1, matches original CUP Reader count; production adapter/generalized source authority not established. Test keys are deterministic synthetic strings in same test harness, not production authority or independent key custody.
G06 actual fixture revisions in MariaDB ACK and CUP verification; R3 SnapshotWriter produces private V1/V2 for A and B plus separate public CUP ledger records, six sealed SQLite entries, public independently reconciled. App reads verified ledger through read-only mount and connection, not live fact aggregation. Server-owned activation file is changed only by test writer, not HTTP. Exact V1 replay after V2; V2 remains. Not production WORM or activation service.
G07 actual wrong source receipt and missing MariaDB row => writer denial, UNKNOWN response, no ledger mutation. Conflicting history => R3 writer refusal and UNKNOWN. Both public and private conservatively unavailable in negative modes; no false zero.
G08 exact HTTP stage router only, disabled/host/origin/method guards, mock login, no account/revision query selectors, public payload allowlist/no identity, no-store/noindex. Existing M2 API remains unchanged. Integrated router is test-only, reuses original UI HTML/CSS/JS with explicit UNKNOWN wording and character-count corrections, not a deployed route.
G09 real Chromium desktop and 360px, A/B private counts, V1/V2/rollback/UNKNOWN, no overflow, keyboard, navigation, summary, reduced motion, external requests blocked, screenshots.
G10 PHP 8.0/8.3 complete R3 39 each plus six phases, M1/M2/M3B regression 162 each, R2 MariaDB 11 each, legacy Chromium 11; new integrated matrix both PHP versions.
G11 hosted CI logs and artifact manifests, synthetic screenshots, rollback proof. No DB or raw identity in artifacts.
G12 always cleanup containers, internal network and synthetic ledger directory; existing R2/R3 cleanup regressions. Production remains BLOCKED for real source authority, history, keys, protected persistent storage, production route wiring and separate approval.
