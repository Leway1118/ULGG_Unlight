# Strength Rank V1-R7 — production release record

Released 2026-11-28 (Asia/Taipei). R6 BETA promoted to the public strength ranking; removed per-row seven-day trend, retained Meta rising/falling cards, compact portraits and level/name labels, default COST 50–59. Strength ranking now precedes Team Ranking in the production sidebar. Removed redundant BETA entry; BETA remains admin-only.

## Acceptance

67/67 isolated regression checks; production Simple Deploy RELEASE_PASS, with pre/post HTTP checks, PHP lint, SHA-256 verification, backup and writer lock release. Anonymous live browser desktop (16 ranked/observed decks at acceptance time) and mobile checks passed, including sidebar order, eight columns, default COST and BETA HTTP 403. Statistics unchanged relative to R6. No DB/schema, shared CSS, collector, watcher or scheduling changes in this release.

| Production file | SHA-256 |
| --- | --- |
| pages/strength_rank.php | eee3063a2f75a92ada79db5093cad3494806c22c1581421fac8603741b6cffd9 |
| layout/sidebar_public.php | 9a9e3066585ee52078e573c313407c753fdc3cb67f210100dc3737c546040e6c |

Bundle SHA-256: f26cdc39d1f9fadd3e9c32054a51d9149fb7879888586780a95d9d7dac045f4b.

This PR records the deployed page and release evidence. The separately maintained production sidebar is absent from master; its digest is recorded rather than adding unrelated shell changes. This is not a deployment mechanism or an exact snapshot of the entire production site.
