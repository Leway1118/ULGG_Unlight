# Quest Analytics Auto Publish P1.1 — G09 / G10

2026-11-27 production release record. GitHub is version history only; this directory does not deploy anything automatically.

## Verified release

- G09 first publication: 2026-11-27 14:41:32 Asia/Taipei.
- G09 manifest: ebbac54e23f6df59fcecefce3a83974f2b588489a618dc878403c067dc925a8c.
- G09 transaction: 20261127T064132Z-e18d4c23.
- Local module tests: 54/54; VPS PHP tests: 35/35; private HTTP: 5/5; HTTP regression tests: 12/12; production HTTPS: 5/5.
- Verified same-snapshot replay: SKIP_ALREADY_PUBLISHED.
- G10 Windows task ULGG-Quest-Daily-Publish first execution: 14:44:01–14:44:30; LastTaskResult=0; G10_RUN_PASS.
- G10 manifest: dfce01884ea5cd4abe94f4010561c3c902744b9c4d9bed1ac19b5d98af2478a9; independently observed in public HTTPS X-ULGG-Quest-Release header.
- Eligible all-period runs 2096 -> 2097; today 3 -> 4; v3 source rows 1534 -> 1536; accepted pairs 32 -> 33.
- Next scheduled run: 2026-11-28 06:00 Asia/Taipei. First overnight run not yet observed.

## Scope

Source-only record of the existing tested Windows builder, PHP metrics/model integration, history calculation, and Linux transaction controller. The four files under candidate_php/ correspond to the deployed Quest inc/ files. contract_reference/inc/ contains the baseline PHP inputs used to reproduce that candidate. SOURCE_SHA256.json records the exact local bytes before Git newline normalization.

The installed G09 controller uses the existing Writer Lock and atomic data pointer, immutable release directory, verified model and digest registry, public TLS validation, and rollback. The known stars.php redirect must lead to the verified index.php#stars section. G10 checks the installed controller digest, builds from local data, uploads only the checked candidate, and invokes the existing daily-auto entry; PHP changes are refused. No CI/CD, Git sync, DB migration, new key or new account.

## Runtime prerequisites (not in Git)

This is not a standalone installation package. Keep the existing local recorder/exporter and candidate workspace. The actual builder depends on the preexisting untracked tool/ulgg-quest-public exporter, detector panel and raw V3/V4 history; approved model JSON and reference data must be supplied from the authorized local workspace. Tests that use full data require these private fixtures. The original Windows paths and invocation contracts are retained as tested. No raw history, model data, generated data, private release packages, logs, credentials, or machine-specific SSH endpoint values are committed. The scheduler uses the existing interactive Windows account and existing authorized SSH identity; offline/logged-out machine delays updates. First published history has no previous-day comparison; real future snapshots are needed for historical error evaluation.

## Operations

Use the installed scripts on the existing machine, not a Git checkout as an automatic deployment source. Run-Quest-G10.ps1 Register was executed by the owner. The scheduled task invokes Run mode at 06:00. Logs are in the existing local g10_runs directory. A failed update leaves the last accepted site version in place or rolls back a switched version. Do not restore old root-directory ownership or re-enable Git sync.
