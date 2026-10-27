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

## Missing dependency / must not merge yet

The VPS version includes an additional call after the counter section:

```php
if (!empty($index)) {
    include __DIR__ . '/../includes/support_prompt_fight.php';
}
```

`includes/support_prompt_fight.php` **does not exist in the audited GitHub master or server-sync snapshot**. Its actual source was not available to this run. We intentionally **did not invent the file or introduce the include** in this branch. Thus the branch is only a partial parity recovery, and merging or deploying it as a complete VPS-sync patch would risk dropping the live support prompt.

Before marking ready:
- Obtain the unmodified VPS include source through an authorized read-only transfer.
- Inspect for credentials, tokens, unsafe external URLs, data exposure, and dependencies before publishing.
- Add the vetted include source plus its exact caller to this branch; add an offline contract test.
- Validate `php -l`, PR CI, a no-DB rendering sanity check, and real fight page ordering/BP/event images/support prompt in an isolated or production-approved UI test.
- Re-audit live source drift before any separately authorized deployment.

**Scope safety:** `master` and `server-sync` are unchanged by this draft; no VPS writes, service restarts, or timer edits.
