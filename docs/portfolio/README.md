# UL.GG 技術作品集｜Technical Portfolio

本頁是已公開、可核對的工程證據索引；不代表全部系統已通過 E2E 測試。

## 系統與可驗證來源

- 公開網站：https://ulgg.online/
- 程式碼：本儲存庫的 `master`（整理後主線）與 `server-sync`（VPS 每天 03:15 的來源備份）
- 既有自動備份原始碼：`scripts/ops/ulgg-server-sync.sh`、`scripts/ops/systemd/ulgg-git-sync.timer`
- 既有 Racing / Raising 測試程式：`tests/raising/`。目前 `master` 缺少完整依賴，PR CI 只先做語法檢查，不能宣稱單元測試通過。

## 技術案例（持續整理）

| 案例 | 可公開證據 | 狀態 |
|---|---|---|
| Steam OpenID 登入流程保護 | PR #1、對應原始碼與 Git 歷史 | 有 PR，尚需整理公開測試證據 |
| UL.GG 正式來源安全同步 | `scripts/ops/`、`server-sync` Commit 記錄 | 排程已在 VPS 運作；定期依日誌稽核 |
| 場景 13、14 顯示修復 | Draft PR #4 | 待人工審核與回歸測試 |
| PR 品質閘門 P0 | [CI 隔離驗證紀錄](P0-CI-VALIDATION.md)、`.github/workflows/ci.yml`、`tests/ci/` | 首次 CI PASS，舊 Raising 測試仍有缺失 |

## PR 的證據標準

請每次記錄 Problem（問題）、Decision（取捨）、Test（實際驗證）、Impact（結果）與 Rollback（回退），並把部署和合併視為不同動作。

## 對外展示注意

禁止公開任何正式資料庫、Token、Cookie、SSH 私鑰、未經同意的個資及原始後台畫面。CI 是品質閘門，不是正式站部署流程。
