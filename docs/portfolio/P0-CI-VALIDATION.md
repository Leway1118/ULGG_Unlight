# UL.GG P0 — PR-only CI 隔離驗證

日期：2026-10-27（台灣時間）  
範圍：ULGG_Unlight 的獨立 Draft PR #5（以 master 為基準）  
狀態：**品質閘門測試 PASS；整站回歸 / E2E 未執行**

## 可重查證據

- PR：https://github.com/Leway1118/ULGG_Unlight/pull/5
- GitHub Actions 第二次執行（Checkout v5）：https://github.com/Leway1118/ULGG_Unlight/actions/runs/40510029422
- 成功執行時的 Head SHA：`b11c08a9ae48f9678bb2cdd03d757a5f89e6a0f1`
- Trigger：`pull_request`；執行環境：GitHub-hosted `ubuntu-24.04`
- Token 權限：`contents: read`，checkout 禁止保留認證資訊

## 實際驗證內容

| 項目 | 結果 | 邊界 |
|---|---|---|
| git diff --check | PASS | 比對 PR 變更 |
| 路徑與秘密模式掃描 | PASS，8 條變更路徑 | 啟發式規則，不能取代人工資安審核 |
| PR guard Python 單元測試 | PASS，4/4 | 涵蓋敏感路徑、允許路徑、加入行秘密特徵 |
| PHP lint | SKIP | 本 PR 無 .php 修改；日後 PHP PR 才會執行 |
| Raising test .mjs 檔案語法 | PASS | 僅語法，不代表模組載入及遊戲邏輯 |
| Raising runtime unit tests | SKIP + warning | master 缺少 `assets/js/raising/engine.js` 和／或 `scripts/import/cc_asset.json` |
| 正式站整合與 E2E | NOT RUN | 不使用正式 DB/服務/Token |

## 安全界線與後續

- 不修改 VPS、每日 03:15 的 systemd Timer、server-sync、master。
- Workflow 不提供 `push`、`schedule`、`workflow_dispatch` 或 `pull_request_target` 觸發。
- 無 Merge / Deploy 步驟；**Draft PR 需人工批准後才可以合併**（屬作業規則；尚未驗證 branch protection 強制設定）。
- 既有 Draft PR #4 尚未套用此 CI；待 P0 合併進 master 後，才在原 PR 更新觸發完整檢查。
- OpenCode / Caveman 的遠端執行環境目前不可使用；本輪使用 GitHub 介面/API 實作及 GitHub Actions 隔離驗證，未冒稱已由兩者完成。
- Rollback：在合併前關閉 Draft PR #5 即可，正式站不需要回滾。

## 技術重點

Commit 與 PR 保存工程決策證據；CI 是合併前的可重現品質檢查；`server-sync` 每日備份與 `master` 維護為互相獨立的機制。綠格不是測試證據，應以可驗證的 PR / CI / 修復記錄為主。
