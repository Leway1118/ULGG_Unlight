# UL.GG 2026 年度回顧｜M3-B 最小權限、來源與 Snapshot 契約

文件：2026-11-24｜DRAFT｜僅合成資料／本機獨立 Worktree，不供正式會員使用。

## 依賴與放行順序

1. Steam Session 必須由伺服器端 OpenID/session 機制保護，不能由 URL/POST 指定 SteamID 或玩家名稱。
2. IdentityReader 以 game_user.id + game_user.steamID 做唯一核對，同時核對 ack、black_list、username 與 region。IdentityScopeResolver 使用獨立 Server Key 將帳號身分化為不透明 HMAC Scope，絕不回傳 SteamID、email 或 user_id 到公開 API。
3. 專用 DB 帳號必須 SELECT-only，並僅能讀取必要欄位。PHP 的 prepared SELECT、SQLite PRAGMA query_only 都不能取代正式 MariaDB 的最小權限授權。
4. PvP 與 CUP 必須分別具備來源證據：dataset、issuer、2026 起訖時間、Asia/Taipei、schema fingerprint、revision、完整 ingestion watermark、observation count／expected count、source SHA256、subject scope 與可信簽章。
5. Signed PvP 與 Signed CUP 均 VERIFIED，年度 bundle 才 VERIFIED。缺 CUP 只能 PARTIAL，不能借 PvP 的水位推斷 CUP 完整。
6. Snapshot 每次新增一個 revision，不能覆寫舊版；資料變更只建立新 Revision，查詢舊版仍須可重播，並驗證來源簽章與 hash chain。
7. 真實帳號歸屬、水位、歷史重播或資料庫權限缺證據則 UNKNOWN/BLOCKED，不能把缺漏顯示為 0。

## 已實測 Schema 與目前缺口

D1 於 2026-11-24 唯讀核對正式 MariaDB：11 表、216 欄、135 index entries。Schema SHA256 fingerprint:
8de93e52c187059f924be7ffa9c6755767f3fe4bc2d104cc097098de6bd7cd34。
這不是來源資料 hash，也不是所有 DDL 的完整 hash。

- game_user：id、steamID UNIQUE，(username,region) UNIQUE、ack、black_list；正式 Steam OAuth Session E2E 未執行。
- arena_unlight：region、ack1、ack2、win/lose/tie、update_time；arena_player_match_result：match_id+side 唯一、player_name、三角色、team_key、cost、is_win。沒有 (player_name,update_time) 索引，亦沒有 player 結果至 game_user.id 的不可變歷史外鍵。
- tournament_reports/sections/matches/match_games：需 published_at、is_visible、result_status=verified、合法 winner、updated_at 的版本證據；現有 SQL 無法憑現在狀態回放過去每一個修訂。
- 不可由相同 player_name 推定更名帳號的歷史 PvP；不可以 CUP 同名選手推定 game_user 映射。NULL is_win 不等於 tie。MATCH 排除，CR 未另立契約前不併入 TW／JP。
- Raid 2026-10/11 玩家 rank/point 覆蓋不足，仍為 UNKNOWN，不在本 M3-B 年度 PvP＋CUP Gate。

## 只讀 DB 角色設計（文件，不執行）

建議未來獨立 Staging 設定 checkpoint2026_ro@staging_host，由 Vault／KMS 管理授權憑證，不沿用正式網站可寫帳號。授權白名單：

| 表格 | SELECT 必要欄位 |
| --- | --- |
| game_user | id, steamID, username, region, ack, apply, black_list |
| arena_unlight | id, region, update_time, ack1, ack2, name_p1, name_p2, win, lose, tie |
| arena_player_match_result | match_id, side, player_name, update_time, leader_id, back1_id, back2_id, team_key, cost, is_win |
| tournament_reports | id, tournament_id, issue_key, report_title, status, published_at, created_at, updated_at |
| tournament_report_sections | id, report_id, section_key, section_title, sort_order, is_visible, updated_at |
| tournament_matches | id, tournament_id, status, player1_id, player2_id, winner_player_id, updated_at |
| tournament_match_games | id, tournament_id, match_id, result_status, player1_id, player2_id, winner_player_id, created_at, updated_at |

禁止 INSERT/UPDATE/DELETE/CREATE/ALTER/DROP/INDEX/TRIGGER/EVENT/FILE/SUPER/GRANT/USER 管理或任何超過白名單權限。真實驗收須以專用身分執行 SELECT PASS、各寫入/DDL DENIED、SHOW GRANTS 驗證、查詢限時與連線關閉。不得輸出密碼、Hash、Token 或 Cookie。

正式 VPS 使用無憑證文件讀取的 Unix Socket metadata 查到目前連線 principal 是 root，按 checkpoint/ulgg_ro 命名候選數為 0；這只是候選命名比對，不是所有角色完整稽核。**尚無專用只讀 principal 的已通過證據。**

## 獨立 Linux Staging 的界線

正式 Rocky Linux 9.8 VPS 上存在 /var/www/ulgg-preview/current 與 /run/ulgg-preview，但它們與正式站同一主機，尚無獨立 UID、DB、Network Namespace、資源或 IAM 的隔離證據，亦未找到 checkpoint2026 獨立 systemd 服務。不能將此 Preview 當成本案已核准的獨立 Linux staging。

本輪只在 Windows 隔離 Worktree 建立合成 SQLite in-memory / TEMP 文件，不向正式 VPS 部署、執行測試服務或改動正式 DB。獨立 Linux Runtime 與真實 MariaDB SELECT-only 角色仍是阻擋 Gate。

## M3-B 合成資料防偽與快照實作

- SourceProvenance：合成專用 HMAC key 驗證 source dataset、schema SHA256、Asia/Taipei 有效日期、水位、資料摘要、完整筆數和帳號 Scope；HTTP 端的 source_verified=true 無效。
- AnnualSourceBundleGate：分別驗證 PVP_MATCH_FACT（私人 Scope）與 CUP_PUBLIC（全站公開 Scope）。缺任一證據只會 PARTIAL，不允許完整年度快照。
- IdentityScopeResolver：透過真實形狀的合成 Steam Session + game_user 核對後產生不透明 HMAC scope，跨 TW/JP 同名、偽造 Session 均拒絕。
- ReadOnlyStagingPdo：僅可存取指定暫存目錄內 SQLite，啟用 query_only；任何 DML/DDL 應拋錯。此策略不是 MariaDB user-level privilege。
- ImmutableSnapshotLedger：年度 PvP+CUP 來源雙證據通過後，Snapshot 使用 append-only SQLite、PRIMARY KEY(scope_hash,revision)、UPDATE/DELETE 禁止觸發器、來源 bundle digest、previous_hash 與 HMAC record_hash；重複 Revision／跳號／跨帳號／Payload 篡改均拒絕。
- 合成測試 Key 由測試檔內的固定字串衍生，**非秘密、非正式可用的金鑰**。正式產品必須由受保護的獨立金鑰管理與來源驗證服務產生簽章，不得把這份模擬程式直接上線。
- SQLite Trigger + HMAC 是隔離 Staging 的 tamper-evident 模型，並非正式 WORM。正式需不可變外部儲存、密鑰輪替、版本獨立保管和修訂稽核。

## 版本、效能與發布前條件

- 70,000 合成 arena／140,000 合成 player facts，真實 SQLite SELECT PLAN 與中位數耗時比較，候選複合索引僅加在記憶體 DB，**不得依本機 SQLite 結果修改正式 MariaDB 索引**。
- 真正的 ingestion watermark 必須由獨立 Collector 提取序列／筆數對賬形成權威證據；observed_rows = expected_rows 若兩者出自同一不完整來源，仍不能證明全年完整。
- 真正 Steam 帳號歷史歸屬與 CUP 個人選手映射尚無權威外鍵，這些欄位維持 UNKNOWN。
- M3-B 不推 GitHub、不合併 PR #13/#14/#15/#17、不修改 master、正式 DB、Watcher、Collector 或排程，不部署或操作真實遊戲。
- 回滾：以本機 M3-A 695ab80 為基準 reverse Patch；已寫入的 Snapshot 只能新增新版本或切換讀取版本，不以刪除歷史修正錯誤。
