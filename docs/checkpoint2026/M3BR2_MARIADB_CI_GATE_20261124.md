# UL.GG 2026 年度回顧｜M3-B-R2 MariaDB 欄位級 SELECT-only Linux Gate

狀態：DRAFT — GitHub-hosted Ubuntu 一次性合成資料驗收。基線為 Draft PR #18 的 `94dcc50b53fe69df56753c0ab5d615f08b8ba474`。

## 執行範圍與拒絕事項

- 僅獨立分支 `review/checkpoint2026-m3b-r2-mariadb-ci`、Draft PR（base: `review/checkpoint2026-m3b-r1-linux-ci`）、pull_request-only GitHub-hosted Ubuntu CI。
- GitHub Actions 不載入 Secrets，不提供正式 DB 設定、Steam OAuth、VPS 或任何正式身份。MariaDB 僅 `127.0.0.1:43306` 的 Docker 臨時容器，固定合成測試密碼不是正式憑證；每個 PHP matrix job 各自獨立建立與銷毀。
- 禁止修改 master、PR #13/#14/#15/#17/#18、正式 DB 資料／帳號／索引／表、Watcher、Collector、排程、遊戲、部署或合併。
- MariaDB 測試需要臨時 root 只用於 **建立合成 schema、產生合成資料、GRANT 合成 reader、產生比較用候選索引**；所有實際應用 Reader 呼叫一律透過 `checkpoint_m3br2_ro@%`。
- 只對七個來源資料表的指定欄位授予 SELECT。每表另外設 `ci_hidden_probe` 拒絕欄位，以確保 `SELECT *` 失敗；禁止 INSERT / UPDATE / DELETE / REPLACE / CREATE / ALTER / DROP / CREATE INDEX / CREATE USER。
- 測試故意使用 TW、JP 相同合成玩家名稱、不同合成 SteamID；核對 Session 拒絕、Opaque Scope 隔離、PvP FactReader、CUP PublicReader，只允許已發表與 verified 的 CUP 記錄。
- 實際執行 MariaDB `EXPLAIN` 並記錄 PvP、CUP report、CUP game 查詢計畫與延遲；70000 合成對戰及 140000 合成玩家結果，候選索引僅存在於一次性容器。記錄 baseline 與 candidate 的 median/p95，不強制宣稱加速、不修改正式索引。
- 保留 R1 的 PHP 8.0/8.3 各 162/162、雙來源簽章、跨程序 append-only Ledger、SQLite 140k baseline、Chromium 11/11 及 3 張合成截圖回歸。驗收需查看 R2 真正 Actions Run，不以 R1 綠燈替代 R2 綠燈。
- Artifacts 只包含 JSON、合成截圖、rollback 檔案清單及 patch hash；不傳入/輸出真實帳號或密碼。CI cleanup 必須證明 Docker 容器已銷毀、臨時資料消失，rollback forward/reverse patch --check 通過。

## R2 Gate 規格

| Gate | 條件 |
| --- | --- |
| G01 | PR base/head/SHA 嚴格比對，文件與 CI 僅白名單路徑 |
| G02 | PHP 8.0+8.3 兩套 MariaDB 合成容器，各執行 C01–C11 |
| G03 | `SHOW GRANTS` 只有欄位級 SELECT；所有白名單欄位可以 SELECT |
| G04 | 全部未授權欄位、星號查詢、DML、DDL、帳號建立都被真正 MariaDB privilege error 拒絕 |
| G05 | Steam A/B TW/JP 互不洩漏、Opaque scope 區隔；偽造 session 拒絕 |
| G06 | 原始 PvP/CUP Reader 在 MariaDB 驗證結果；CUP 個人映射維持 UNKNOWN |
| G07 | PvP/CUP EXPLAIN + latency 與 70k/140k 比較結果，不把合成測試當正式負載承諾 |
| G08 | M1/M2/M3-B PHP 8.0/8.3 雙回合各 162/162；HMAC/PvP/CUP/Snapshot 不回歸 |
| G09 | GitHub-hosted Chromium 11/11、3 screenshots、local server stopped |
| G10 | Docker 強制清理及 runner temp files 結束時銷毀 |
| G11 | 以 R1 SHA 為基準，獨立分支 forward/reverse patch check、檔案白名單與 rollback SHA |
| G12 | 完整 Linux CI jobs + Artifacts，逐 Gate 實際 PASS/FAIL/UNVERIFIED |

**重要未解決限制**：本合成 MariaDB 可證實權限設計在一次性容器上可執行，並不能證實正式 MariaDB 專用帳號已建立、真實 Steam 歷史映射已解決、CUP 身份映射已解決、真實 ingestion watermark 完整，也不能替代獨立正式 Staging 及 WORM/KMS 審計。因此真實使用放行仍 BLOCKED。

## 回滾

只撤回此獨立 Draft PR／分支。保留 PR #18 commit `94dcc50` 為原始來源；以 GitHub Actions 產出的 patch SHA256、changed files、forward/reverse `git apply --check` 驗證可逆性。不部署，不修改任何既有 Snapshot，不操作正式環境。
