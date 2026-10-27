# ULGG-FIGHT-SOURCE-SYNC-P1 — production source reconciliation

**Status: source dependencies recovered; DRAFT ONLY pending UI/runtime review. Do not merge or deploy.**

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

## Support telemetry endpoint recovered verbatim

The live VPS `api/support_event.php` was read-only checked with `php -l` and its 968-byte original source was verified by SHA256:
`31aec2a3d286416f20f252885ce954806b1b01ce1287fd5d70da100508832c44`.

- Script does not connect to a DB, set cookies, read sessions, or create an app log. Existing HTTP access logs may still capture IP, User-Agent, URL with `e` and `src`, timestamp and other request metadata. Treat logs as potentially personal data.
- Only `POST` is accepted; other methods return **405**.
- Event must be one of `impression`, `click_tw`, `click_bmc`; `src` must be `fight`; invalid queries return **400**.
- Explicit `Sec-Fetch-Site` values other than `same-origin` return **403**. **Absent header is accepted** (204 for a valid POST). Do not mistake Fetch Metadata for authentication, strong CSRF protection or request rate limiting.
- Accepted event returns **204** and no response body. No backend event counter is maintained here: any event counting based on HTTP access logs needs to account for rejected or spoofed requests, bots and log retention.
- The PR-only CI includes a **13-case isolated PHP HTTP-decision regression**. This tests original code with simulated superglobals; it does not prove Nginx routing, actual network responses or log semantics.

## Outstanding verification before Ready for Review / merge

- [ ] Verify the fight-page UI order and BP output (newest-first, correct calculations), card geometry, focus anchor, support CTA and payment modal in a separately authorized browser test.
- [ ] Confirm CTA placement: the current VPS-equivalent include prints its section before `echo $outPutString`, although it is invoked after result fragments are assembled; expected visual ordering must be checked without silently changing production parity.
- [ ] Verify event API via live/sandbox HTTP using low-volume controlled requests only; check 204/400/403/405 where appropriate and confirm existing Nginx logs are protected and retention is acceptable.
- [ ] Re-audit live production drift and confirm final PR CI success before proposing readiness.
- [ ] If operational metrics are important, explicitly decide on rate control and trusted aggregation; these are out of scope for source parity.

**Scope safety:** `master` and `server-sync` are unchanged by this draft; no VPS writes, service restarts, timer edits, auto merge or auto deployment.
