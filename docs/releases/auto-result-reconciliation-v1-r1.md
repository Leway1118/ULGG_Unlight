# Auto Result Reconciliation V1-R1 — production acceptance

Release: 2026-11-28 (Asia/Taipei). Production deployment and existing IMPORT service acceptance passed.

## Behavior
- BP formula tolerance: ±1, unique result compatible with observed W/D/L.
- Existing single-direction W/D/L increments remain unrestricted.
- Mixed-counter BP evidence retains the 72-hour and 16-game limits.
- Already judged records are protected; conflicting or insufficient evidence stays manual.
- Existing IMPORT schedule is reused. No new schedule, schema change, or additional Collector execution.

## Verified evidence

| Gate | Result |
| --- | --- |
| Historical eligible controls | 517/517 |
| Isolated MariaDB transaction tests | 12/12 |
| Static candidate tests | 12/12 |
| Production file digest verification | 2/2 |
| Source HTTP protection, origin and public | 4/4 (403) |
| Post-release read-only recent/history scans | 66 / 1132 |
| Actual existing IMPORT service run | 16:50:46–16:50:52, exit 0 |
| AUTO_BP_TOL1_V1 | dry_run=false; scanned=66; manual=66; candidates=0; applied=0 |
| Arena row count before/after | 165255 / 165255 |
| Previously judged control records | 4/4 unchanged |
| Both-side derived results | 8/8 unchanged |
| Auto-result statistics inconsistencies | 0 (no eligible new result during acceptance) |
| Watcher | active after normal IMPORT lifecycle |
| Timer | unchanged |

The production deployment and permission adjustment used the existing shared writer lock, scoped backup and rollback contract. No production match was deleted or changed by the new formula during this run.

## Version evidence

| File | Production SHA-256 |
| --- | --- |
| watcher/import_and_compare.py | 77b1ae1e5a6fd49169b8916bcba01640d925900deaeb3c2dab5910c06b7527b7 |
| watcher/auto_result_formula.py | 28b40a47d8fa39c0af3512420e80ed773796b8e07f0ef691600c039f9e625afe |

This PR intentionally records release evidence only: the repository is public, and the production IMPORT source is not currently tracked on master. The verified sources and private recovery artifacts remain outside the public repository. This PR is not a deployment mechanism.

No new eligible live record was available to exercise a positive production write. Successful positive writes, idempotence and rollback were previously verified in isolated MariaDB. Production execution was confirmed in write-enabled mode without forcing synthetic records into live data.
