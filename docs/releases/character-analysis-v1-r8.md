# Character Analysis V1-R8 — production release record

Released 2026-11-28 (Asia/Taipei).

- Public character analysis replaces the administrator preview. Character Analysis precedes Character Ranking in the production sidebar.
- The L1–R5 character appearance distribution (original colors and surrounding cards) precedes the interactive Meta scatter plot, then the paginated character list.
- Dynamic axes, smart/all/dot modes, card tooltips, search, zoom and level win-rate cards are retained.
- COST/BP filters, approved weekly rules, merged sources and actual aggregate match data are retained.

## Acceptance

R7 and R8 each passed 81/81 isolated regression checks. Production Simple Deploy returned RELEASE_PASS, exit 0, with pre/post HTTP markers, verified SHA-256, backup and writer lock release. Production browser visual acceptance and independent live distribution reconciliation are not claimed.

| Production file | SHA-256 |
| --- | --- |
| pages/character_analysis.php | aa1e66f29e2263550ee887e6a3d13b98dba2b44ac886ed274caad8540bfaac21 |
| layout/sidebar_public.php (R7; unchanged in R8) | 9f86a7d3a84309a8e54a4ba2062251b01d0d21dc4330ea6d52d8068e0562934e |

R8 bundle SHA-256: bb8e3cf980a9abc7ce37fd5da19c1a6e8ea1a9ac9ec4db1a43ef37e1e454fc85.

This PR records the deployed public page source and release evidence. The production sidebar is separately maintained and absent from master; its digest is recorded rather than introducing unrelated sidebar/template changes. This PR is not a deployment mechanism; do not treat master as an exact production snapshot. No database/schema, scheduler, collector, watcher or shared CSS changes were made by R8.
