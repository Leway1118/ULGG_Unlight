# UL.GG 2026 年度回顧 M3-B｜合成環境階段驗收

日期：2026-11-24（台灣時間）
基底：已通過 M3-A Linux CI 的 Commit 695ab805024012613bd90c81ae625685f2cf4e93
本機分支：feature/checkpoint2026-m3b-offline
結論：**合成資料 TDD、跨程序 E2E 與查詢計畫 Gate PASS；正式獨立 Linux Staging、MariaDB 專用只讀帳號、真實資料完整度及真人 Steam OAuth 均 BLOCKED／未驗證，不放行上線。**

## Gate 結果

| Gate | 階段項目 | 驗證結論 |
| --- | --- | --- |
| G0 | 既有代碼、D1 Schema、VPS/Preview/DB role Metadata 唯讀盤點 | READONLY_AUDIT_PASS |
| G0-I | 確認獨立 Linux Staging 不共正式 VPS/資料庫/UID | BLOCKED，Preview 仍位於同一正式 VPS，隔離未證明 |
| G1 | 專用 MariaDB SELECT-only 身分 | BLOCKED，未找到按 checkpoint/ulgg_ro 命名的候選；無可用獨立角色證據，未建立任何帳號 |
| G2 | Steam 合成 Session 的 IdentityReader＋Opaque Scope | SYNTHETIC_PASS，真實 OAuth BLOCKED |
| G3 | PvP 與公開 CUP 個別 HMAC provenance、schema、count、revision、watermark | SYNTHETIC_PASS，正式 ingestion 狀態 BLOCKED |
| G4 | 年度 Snapshot雙來源封存、append-only revision、tamper-evident chain | SYNTHETIC_PASS，正式 WORM/KMS 尚未核准 |
| G5 | 70k arena／140k per-player results SQL 計畫及延遲 | SYNTHETIC_PASS，正式 MariaDB效能 BLOCKED |
| G6 | M1/M2/M3-B 連續回歸、手機 Chromium、本機資料持久化 E2E | SYNTHETIC_PASS |
| G7 | PR 合併、正式 DB／網站部署及操作真實遊戲 | NOT_AUTHORIZED |

## 完成的合成資料模組

- IdentityScopeResolver.php：Steam Session 與 game_user 權威查核後產生不透明 HMAC Scope；跨帳號、跨 TW/JP 同名及偽造 Session 不允許。
- SourceProvenance.php：HMAC 簽章檢查資料類型、合成 issuer、合法日期、schema 指紋、來源 hash、水位、source count 等；不信任 Client 自稱 verified。
- AnnualSourceBundleGate.php：PvP 與公開 CUP **分開驗證**，缺任何一種簽章都不能 VERIFIED。
- ReadOnlyStagingPdo.php：限於合成暫存路徑，SQLite query_only 阻止 DML/DDL。
- ImmutableSnapshotLedger.php：合成 SQLite 逐版 append、PRIMARY KEY(scope_hash,revision)、UPDATE/DELETE triggers、hash chain；支援新 revision、不允許修改、跨 Scope/篡改時 fail-closed。
- tests/checkpoint2026/m3b.php：31 種 TDD，包括來源偽造、過期、無效日期、錯誤 Schema／帳號／Watermark／筆數、CUP 資料集獨立、Snapshot 鍊、資料庫寫入阻擋。
- tests/checkpoint2026/m3b_perf.php：合成 140k rows query plan + baseline vs candidate index。
- tests/checkpoint2026/m3b_persist.php：在不同 PHP Process 中寫入、重新打開、驗證、拒絕寫入及再次驗證。

## TDD 與 E2E 真實執行結果

- PHP 8.3.30 syntax：M3-B 新增 8 個 PHP 檔案全部 PASS。
- M1 28/28＋M2 22/22＋M3-B 31/31＝81/81 PASS，完整連續執行 3 輪，0 FAIL。
- 既有 127.0.0.1 合成 HTTP API＋Chromium：11/11 PASS，完整連續執行 2 輪，server_stopped=true，無正式遊戲／DB 連線。
- 真實跨程序合成 SQLite Ledger：write → close → reopen → verify → deny-write → verify PASS；建立的 TEMP SQLite 最後刪除。
- 新模組 PHP 8.0 Linux Runtime 尚未執行；不能引用 M3-A 先前全綠結果當成 M3-B 已驗證。

上述結果只代表合成資料模組，不代表正式資料完整度、正式帳號權限或發布已符合要求。

## 140k 合成資料 SQL 計畫比較

資料：70,000 arena matches；140,000 per-side facts；TW/JP 混合，測試帳號取得 2,649 筆。

| 指標 | 原有索引模擬 | 合成候選索引 |
| --- | ---: | ---: |
| 中位數查詢延遲 | 10.768 ms | 8.456 ms |
| 改善倍數 | — | 1.27x |
| 查詢結果筆數 | 2,649 | 2,649 |
| 後端 | 本機 SQLite | 本機 SQLite |

原有索引 SQL plan 先依 arena name/region 做 MULTI-INDEX OR，再依 match_id+side 關聯；候選 (player_name,update_time,match_id) 改由 player/time 找 fact 再 Join arena PK，仍使用排序暫存。**此結果不可解讀為正式 MariaDB 效能，也不構成新增正式索引授權。**

詳細 JSON：D:\ULGG\Checkpoint2026\gate2\M3B_PvP_Synthetic140k_Performance_20261124.json

## 真實環境唯讀盤點證據

- VPS Rocky Linux 9.8、systemd 252、PHP CLI 8.0.30；MariaDB 服務存在。
- /var/www/ulgg-preview 與 /run/ulgg-preview 位於現有正式 VPS，無獨立主機和只讀 DB 權限驗收；不當成獨立 Linux Staging。
- Unix Socket metadata 只以 SELECT 查核，CURRENT_PRINCIPAL_IS_ROOT=YES；checkpoint/ulgg_ro 命名模式角色候選=0。這不證明其他命名角色不存在，也未讀取正式網站任何密碼或 Token。
- D1 Schema 11 表、216 欄、135 索引列；fingerprint 8de93e52c187059f924be7ffa9c6755767f3fe4bc2d104cc097098de6bd7cd34。

現場唯讀 Metadata：
D:\ULGG\Checkpoint2026\gate2\M3B_VPS_ReadOnly_EnvironmentInventory_20261124.txt
D:\ULGG\Checkpoint2026\gate2\M3B_RealVPS_ReadOnly_PrivilegeAndPreview_20261124.txt

## 安全界線及後續優先待辦

P0-1：取得真正在其他 Linux 容器/VM 的隔離 Staging 憑證與網路/權限證據（先限合成資料），驗證 PHP 8.0／8.3 的新模組及密鑰保護；不可使用正式 VPS Preview 冒充。

P0-2：獨立 MariaDB SELECT-only principal 的授權及 DBA 變更方案（目前僅設計，不執行），實測欄位級 SELECT PASS、所有 DML/DDL DENIED。正式帳號/權限變更須另案授權。

P0-3：設計可信的 ingestion reconciler，讓 PvP、CUP 分別有獨立且可核實的來源完成水位、錯誤／補抓／版本記錄，無來源完成證據時 Gate=UNKNOWN。

P0-4：確認 Steam 歷史更名／同名記錄的不可變會員映射與 CUP 參賽者對應。未確認時不得將個人歷史紀錄斷言為權威本人資料。

P1：不可變 Snapshot 的正式 Storage／KMS／retention／索引與 query performance；CUP revised reports 必須可按當時的 as_of 回放。

P1：進一步手機 E2E、CUP/ Raid UNKNOWN 顯示及發布前資安審查。

**不合併 PR #13/#14/#15/#17、不推 M3-B 分支、不部署、不修改正式 DB/Watcher/Collector/排程/master、不登入或操作 Unlight 遊戲。**
