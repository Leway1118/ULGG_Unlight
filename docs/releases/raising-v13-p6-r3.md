# Raising V1.3 ? production acceptance (P6-R3)

Released 2026-11-28 17:36 Asia/Taipei. Public UI marker: V1.3-P5. Production release status: RELEASE_PASS.

## Scope
Server-authoritative raising, Hall of Fame, permanent history and factors, two-parent inheritance, compatibility and T9 resonance, three-generation lineage, legacy memorial records, cross-device persistence, idempotent operations. Original R6 engine and validated P0 balancing parameters preserved.

## Verified evidence

| Gate | Result |
| --- | --- |
| P0 offline matrix | 36,000/36,000 existing records |
| Final P6 E2E (MariaDB 10.5.29) | 42/42 |
| Backup/restore rehearsal | 10/10 |
| Production PHP 8.3.36 FPM + Node 22.23.5 bridge | PASS |
| Three InnoDB tables, four foreign keys, PDO rollback | PASS |
| Authenticated member identity, CSRF, history, preview, save read and fortune | PASS before and after release |
| Scope injection and nonexistent run rejection | PASS |
| Existing browser legacy save retained | PASS |
| Actual production native UI and Hall navigation | PASS |
| Exact switched files | 4/4 |
| All candidate source files | 28/28 SHA-256 |
| Anonymous authority and private source protection | PASS (403/404) |
| Temporary acceptance endpoint | removed, HTTP 404 |
| Nginx, PHP-FPM, MariaDB | active |

The first activation rolled back on a stale public HTTP version marker; retry used the existing five-attempt HTTP acceptance policy and the same writer lock. A preliminary browser check timed out; actual authenticated browser UI and Hall navigation subsequently passed. No new Steam login, session impersonation, cookie extraction, or raising quota consumption during acceptance. Cross-account isolation and positive settlement writes were exercised in prior isolated MariaDB E2E; production verification used one actual administrator session with read/preview and invalid-request controls, not an additional live account or positive quota-consuming run. SELinux remains Permissive, with zero related AVC during configuration; enforcing mode not certified.

## Production SHA-256

| File | SHA-256 |
| --- | --- |
| private/assets/account.mjs | 7a02ccf2178ae86469e98000e19ca22d8793ca6bd7d57b5375318664078fe230 |
| private/assets/index.html | 7000b578ee3ab71dedfa5a9dd80ed3e56b9f6b9ddebc9d2199849a858e5da3d1 |
| private/assets/ui.mjs | cc2574c3fa2b6ea73435d41445a3c0e95ca702b84e732fd850248534d9a41df4 |
| pages/raising/index.php | 5ec0acf5675b33c80a7a03533710ea194f2195533c7da1fc3631cccfc43c7c3a |

Original candidate archive SHA-256: 178db2382e2d849ff56ef91103939e553250755715ccae7bc74aa06ba317f393.

This PR records release evidence only. The repository is public; the private raising engine, runtime configuration, session/identity information, member data and backup artifacts remain outside the repository. The record is not a deployment mechanism. Existing global Simple Deploy allowlist, service settings and schedules were unchanged. Rollback restores the four R6 files while preserving committed V1.3 records.
