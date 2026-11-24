# UL.GG Checkpoint 2026｜M3-B-R1 獨立 Linux CI

日期：2026-11-24（Asia/Taipei）
依賴：M3-A 全綠 Commit 695ab805024012613bd90c81ae625685f2cf4e93；M3-B 本機 Commit 34c60c593cd969e3a08e618548b52fe6c5e38dee。
**只授權獨立 GitHub Draft PR 與 GitHub-hosted Linux CI，絕不合併、不部署。**

## 審查分支與風險隔離

- Head：review/checkpoint2026-m3b-r1-linux-ci
- Base：review/checkpoint2026-m3a-linux-ci，故此為「stacked Draft PR」，PR diff 僅包含 M3-B 開發與 M3-B-R1 的 CI 增量。
- 本機：feature/checkpoint2026-m3b-r1-linux-ci、工作樹 D:\ULGG\Checkpoint2026\checkpoint2026-m3b-r1。
- Workflow 僅在 base 精確為 review/checkpoint2026-m3a-linux-ci、head 精確為 review/checkpoint2026-m3b-r1-linux-ci 的本庫 PR 執行。
- GitHub token 僅 contents: read；checkout persist-credentials: false；Actions SHA 固定，沒有 secrets、deploy、push、pull_request_target、workflow_dispatch、正式環境鍵值。
- tests/checkpoint2026/m3b_ci.py 驗證 DB_HOST、DB_USER、DB_PASSWORD、MYSQL_PASSWORD、TW_AUTH_TOKEN、STEAM_API_KEY 等均未注入；不讀本機網站設定檔／正式憑證。

## 本次測試 Gate

| Gate | 內容 | 驗收條件 |
| --- | --- | --- |
| R0 | 本機獨立分支、Git 變更範圍／敏感字串檢查 | PASS |
| R1 | Ubuntu 24.04 + PHP 8.0 PDO SQLite／PHP lint | M1 28 + M2 22 + M3-B 31，完整 2 輪，162 PASS |
| R2 | Ubuntu 24.04 + PHP 8.3 PDO SQLite／PHP lint | 同上 162 PASS |
| R3 | Linux 各版本合成 Steam Opaque Scope、PvP/CUP 雙簽章、完整度負例 | M3-B 31 項 TDD |
| R4 | Linux 各版本跨 PHP 進程 write/reopen/verify/deny-write/verify | 5 次確認成功；TEMP SQLite 被清除 |
| R5 | Linux 各版本 70k arena／140k PvP rows 計畫與 7 次延遲取樣 | 筆數一致、SQL plan 存在、候選索引未觸碰正式 DB |
| R6 | Linux PHP 8.3 + 本機 PHP Server + 預裝 Chromium | 11/11 HTTP IDOR/PvP/CUP＋手機 360px、桌面 1280px E2E；服務結束 |
| R7 | 3 個 GitHub Artifacts（PHP8.0、8.3、Chromium），Draft PR、不合併 | PASS |
| R8 | 回滾 Git Binary Patch SHA256、正反向 git apply --check | PASS |

注意：**81 項測試每個版本做 2 輪才是每版本 162 次 PASS**；HTTP Chromium 11 項在 PHP 8.3 的獨立 Job 執行。Runtime 數據保留為 JSON Artifact，效能測試結果只比較同一 CI Job 中的基線／候選，不設不合理絕對速度門檻。

## 已知邊界

- 此工作包只能驗證以 SQLite 合成資料實作的 Reader、HMAC、append-only 模型；SQLite PRAGMA query_only 是 connection 級阻擋，不相當於正式 MariaDB SELECT-only grant 或硬體 WORM。
- 後續正式來源仍需可信 ingestion watermarks、DB 欄位級 SELECT-only principal、權威 Steam 歷史會員映射、CUP 個人對應、Object Lock／KMS、真正獨立 Linux Staging。
- PR #13/#14/#15/#17 Draft 不動；master／server-sync 不變；無正式 DB/Watcher/Collector/排程修改、無真正 Steam/Unlight 登入或遊戲操作。
- 回滾是將獨立審查分支撤回或以 Patch 回到 M3-B Commit 34c60c5（M3-B-R1 改動）；不更動先前 M3-A/M3-B 檔案、正式環境或 Snapshot。
