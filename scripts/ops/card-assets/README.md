# Official card assets weekly sync — V3-R2

Source reconciliation for the 2026-11-28 owner-operated release and change-text UI candidate. This PR is not a production deployment.

## Runtime

- Two official mirrors, immutable normalized generation snapshots, Taipei ISO-week baseline and Thursday 18:00 Asia/Taipei timer.
- Existing writer lock, exact card identity scope, restricted official fields, transaction verification and rollback backup. Player notes, tags, match history and weekly special COST rules are preserved.
- Correct ten known L-card filenames and fill six previously blank identities only with reviewed matching evidence. Unknown identity changes fail closed.
- Existing COST audit uses the verified snapshot. Admin-only snapshot downloads and change details use existing global theme and dashboard CSS.

## Packaging and operations

Stage the runtime files in this directory together with the canonical website files at `includes/card_assets_status.php`, `includes/card_assets_panel.php` and `pages/admin/card_assets_download.php` (copy to the staging root by basename). The first-install `release.py` is retained for traceability; historical baseline hashes are not a general upgrade mechanism. Do not rerun first installation on an existing service. GitHub CI performs no deployment.

## Evidence and limitations

- Initial real import: 26 card rows, 2 COST rows, 87 skill rows, 8 new skills, 633 staged illustrated cards verified. Owner dashboard readback: 71 characters / 710 character cards / 1156 skills; 26 unresolved official skill IDs.
- Failed initial service release rolled back, then owner-operated release enabled the timer. Next scheduled run 2026-12-03 18:00 Taipei. First periodic execution remains unobserved.
- V3-R2 local regression: 19 Python + 7 PHP isolated tests. Change text: 5/5 isolated tests using actual change records and synthetic role names. R2 local browser fixtures passed at 1366x768 and 390x844. R1 UI displayed on owner admin screenshot. R2 production UI deployment is not confirmed.
- Private reports, official JSON copies, database exports, SSH settings, backups and credentials excluded. Fixture-dependent regression requires authorized local fixtures; no claim that CI covers those tests.

Standalone fixture tests: `cd scripts/ops/card-assets && python3 -m unittest -v test_http_fix.py test_runtime_fix.py`. These run from the existing PR quality gate via the CI test wrapper, without networking, production access or deployment.
