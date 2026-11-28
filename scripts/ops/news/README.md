# News Timeline V1-R3-AUTO

Production first installation and R3 update passed on 2026-11-28. This PR is source reconciliation only. It depends on PR #30 (official assets V3-R2), and should be reviewed after that PR.

- Reuses ulgg_notices and existing admin permission >=2. Public timeline shows active, started notices, including historical expired notices. Drafts are inactive; publication needs admin preview and confirmation.
- Reuses theme.css card-dark, sidebar-link, is-active and CSS variables. The live shell uses layout/sidebar_public.php; the older Git base has an inline sidebar in layout/base.php. This PR adds the same menu fragment to the existing Git sidebar instead of overwriting unrelated shell changes. Reconcile the production shared-shell baseline separately before any future deploy.
- Existing Simple Deploy is unchanged. Optional deploy_with_news.py is an owner-operated wrapper, not a scheduled service; it invokes the existing release runner. The installed first-release wrapper resides outside the document root. The weekly sync invokes scripts/news_draft.php only after verified DB PASS, under the existing shared writer lock. The private generation outbox deduplicates and retries pending receipts without changing sync success. No official JSON or DB exports are committed.
- Initial assets sync announcement was backfilled as draft 15; production R3 verified 13 active and 2 inactive notices unchanged. No extra sync or timer restart was performed. The first recurring execution has not yet been observed. No new table or schema change.

## Evidence

Local 15 PHP SQLite cases, 8 Python asset-hook cases and 10 Chromium fixture E2E cases passed (including desktop/mobile shared-theme computed styles). Production release: first two attempts rolled back safely (HTTP content gate timing and a diagnostic regex issue); third attempt passed actual HTTP 200 for public and data-filter routes, 403 for anonymous management, verified receipt CLI preview, existing draft preservation and unchanged timer/unit hashes. No authenticated production publish action was performed. CI tests are offline and do not certify the live environment.
