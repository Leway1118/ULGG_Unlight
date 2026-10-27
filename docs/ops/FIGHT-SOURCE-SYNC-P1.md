# ULGG-FIGHT-SOURCE-SYNC-P1 — production source reconciliation

**Status: BLOCKED / DRAFT ONLY. Do not merge or deploy.**

## Grounding (2026-10-27, Asia/Taipei)

- GitHub master baseline: `4fc7226038779fb2d3106fb9368b2bd400584170`.
- Read-only VPS SHA256 for `pages/fight.php`: `b6b57327ae113953a4cb22851e0daba95265fb20df9dccbd93ad4c39b85d09ca`.
- Read-only VPS SHA256 for `includes/support_prompt_fight.php`: `75d7de377989d654e73595abef9f1485adbefe18b595e11299a023df464cf22b`.
- Both VPS PHP files: `php -l` passed and paths exist.
- The user-supplied normalized master/live GNU diff is the source of the two implemented changes.

## Implemented in this draft branch

1. `ULGG_CLS_BP_V3`: iterate ascending SQL results to preserve BP calculations, buffer each row HTML fragment, then reverse rendered fragments. CSS uses normal `flex-direction: column`. Do not use `column-reverse` together with reversed fragments.
2. `ULGG_CLS_FIGHT_V1`: explicitly size both event images and matching empty slots to 44×68 to reduce layout shifting.
3. Offline source-contract assertions run inside the existing PR-only CI script.

## Support prompt restored, original source audited

The original VPS file `includes/support_prompt_fight.php` was transferred read-only and verified as SHA256
`75d7de377989d654e73595abef9f1485adbefe18b595e11299a023df464cf22b` (3,816 bytes, UTF-8). The source matches the user-uploaded file byte-for-byte. No hardcoded password, token or private key was detected. The caller is restored verbatim after fight search results:

```php
if (!empty($index)) {
    include __DIR__ . '/../includes/support_prompt_fight.php';
}
```

- Shared `layout/base.php` on master includes `#supportModal`, the `.open-support-modal` click-handler and the same Buy Me a Coffee URL.
- CTA uses `IntersectionObserver` and same-origin `POST /api/support_event.php?e=...&src=fight` for `impression`, `click_tw`, `click_bmc`. Client opts out of cookies and referrer propagation. **HTTP server access logs can still contain metadata such as IP address, timestamp and User-Agent; do not describe these logs as necessarily unidentifiable.**
- Existing PHP lint on the original VPS component passed; this branch's PR CI checks static integration and includes the component under output buffering, without a DB.
- The component is a presentation and interaction element, not a new payment processor.

## Remaining dependency / do not merge yet

**BLOCKED: `api/support_event.php` is referenced by the verified support component but absent in the GitHub master and server-sync snapshots.** The live VPS endpoint has **not** yet been checked. The UI can still render without the endpoint, but telemetry requests will fail if the file is not present; client-side catch suppresses errors.

Before ready:
- Perform a *read-only* VPS existence / SHA256 / `php -l` audit for `api/support_event.php`.
- If live file exists, obtain the exact source, review it for secrets, IP/UA/session handling, log volume, caching and rate controls, then add the vetted source with tests. If it does not exist, explicitly decide to drop telemetry or implement and separately review a minimal event endpoint instead of assuming it works.
- Confirm the production QR modal and Buy Me a Coffee link render and respond correctly, and run real fight-page BP/order/event-card/UI smoke tests under separately authorized access.
- Recheck VPS source drift before any separately authorized deployment.

**Scope safety:** `master` and `server-sync` are unchanged by this draft; no VPS writes, service restarts, or timer edits.
