# UL.GG 2026 年度回顧｜D1 Schema Gate + M1 PHP TDD 驗收

- 日期：2026-11-24（Asia/Taipei）
- 範圍：正式 DB 唯讀查核；獨立本機工作樹中以合成資料建立 PHP 純函數、公開 CUP Reader
- 結論：**D1 SCHEMA_READONLY PASS／M1 TESTS 28/28 PASS／CUP LIVE READ-ONLY SMOKE PASS**
- Git 基底：`server-sync` `3b1c018d2b8c9fd465a367ec3002f50076c2c786`
- 本機工作樹：`D:\ULGG\Checkpoint2026\checkpoint2026-d1m1`
- 本機分支：`feature/checkpoint2026-d1m1-offline`
- 本次不涉及 PR #13/#14/#15 合併、不推送新分支、不部署、不修改正式 DB、不連線操作遊戲。

## D1 — 正式 DB Schema 與來源完整度（只讀）

使用既有 Contabo VPS SSH（BatchMode，嚴格核對已知 host key），從網站現有環境載入 DB 設定但**不列印憑證**。PHP PDO 執行 `START TRANSACTION READ ONLY`，以 `information_schema.COLUMNS / STATISTICS / TABLES`、聚合 `SELECT`、唯讀 `EXPLAIN` 查核；結束 `ROLLBACK`。結果只存入使用者 Windows 電腦。

實際核對 **11 張資料表／216 筆欄位紀錄／135 筆索引列**：
`arena_unlight`、`arena_player_match_result`、`game_user`、`raid_history`、`raid_player_history`、`tournament_reports`、`tournament_report_sections`、`tournament_matches`、`tournament_match_games`、`tournament_players`、`ranking_bp_TW_history`。

- DB `@@session.time_zone = SYSTEM`，`@@system_time_zone = CST`；同次採樣 NOW=2026-11-24 10:30:17，UTC_TIMESTAMP=02:30:17，差距 +08:00。**只驗證這次環境時差，尚未驗證全部歷史匯入的時間欄位語意**。
- `arena_player_match_result`：13 欄，`PRIMARY(id)`、`UNIQUE(match_id,side)`、`idx_update(update_time)`、`idx_match(match_id)`；**沒有** `(player_name, update_time)` 索引，且本表沒有 `region` 欄。平台必須由 `arena_unlight.region` 取得。
- `arena_unlight`：包含 `region`、`ack1/ack2`、`win/lose/tie`、`update_time`；已核對時間與結果索引。
- `game_user`：`UNIQUE(steamID)`、`UNIQUE(username,region)`；必須用 Steam Session 的 `user_id + steam_id` 重新核對 DB，同時核實 `ack`、`region`、`black_list`，不能用遊戲名稱找私人資料。
- CUP：`tournament_reports.status` enum(draft/published/archived)、`published_at`；`tournament_report_sections.is_visible`；`tournament_matches.status`、`winner_player_id`；`tournament_match_games.result_status`、`created_at/updated_at`。`tournament_players` 沒有權威連到 `game_user.id` 的欄位，個人 CUP 必須 UNKNOWN。
- Raid：`raid_history.raid_id` 為主鍵，`raid_player_history` 主鍵是 `(raid_id,player_name)`；`point` 不允許 NULL，不等於未觀測的玩家 Point 就是 0，完整度仍要按 raid ID 覆蓋率判定。

唯讀查詢窗口：`2026-01-01 00:00:00 <= time < 2026-11-24 10:00:00`。

| 來源 | 可驗證結果 | 說明 |
|---|---:|---|
| 2026 PvP 玩家結果列 | 139,902 | 69,951 個 distinct match_id；2026 時間窗口的 match_id 不存在原始 arena 表的筆數 = 0 |
| arena TW | 63,880 | ack1=ack2=1 為 63,229 |
| arena JP | 3,870 | ack1=ack2=1 為 3,848 |
| arena CR | 2,772 | ack1=ack2=1 為 2,708；暫不列入已確認 TW/JP 競技年度統計 |
| arena MATCH | 67 | 特殊對戰，年度競技統計排除 |
| CUP 大場 | 25 | 資料庫 status=confirmed |
| CUP 小局 | 59 | 資料庫 result_status=verified |
| CUP published reports | 6 | 同時核實 27 個 visible sections |
| Raid 2026/08 | 348 | 348 個 Raid 有玩家歷史列 |
| Raid 2026/09 | 1,395 | 1,247 個 Raid 有玩家歷史列 |
| Raid 2026/10 | 1,656 | 49 個 Raid 有玩家歷史列 |
| Raid 2026/11 | 3,630 | 0 個 Raid 有玩家歷史列 |

**注意：** PvP 的 `is_win IS NULL` 在此快照有 2,356 個玩家列；此值**不能直接宣稱 2,356 場平手**，因為仍需跟原始 `arena_unlight.tie`、結果旗標和雙方確認核對。

使用合成名字的唯讀 `EXPLAIN`：查詢計畫先走 `arena_unlight.idx_result_time`（估算掃描 75,347 列），再走 `arena_player_match_result.idx_match`（估算每場 1 列）。這是**估算計畫，不是正式負載測試**；正式上線前須從受控查詢與 Snapshot 設計解決潛在效能瓶頸，不能逕自加正式索引。

原始資料與 metadata 保留在：
- `D:\ULGG\Checkpoint2026\gate2\D1_ReadOnly_ProductionSchema_20261124.json`
- `D:\ULGG\Checkpoint2026\gate2\D1_ReadOnly_ProductionCoverage_20261124.json`
- `D:\ULGG\Checkpoint2026\gate2\D1_CupReader_LiveReadOnlySmoke_20261124.json`

這些檔案不包含 DB 密碼、Steam Token 或使用者姓名資料列。

## M1 — RED → GREEN

1. 在未建立 `lib/checkpoint2026/` 3 個 PHP 類別前先執行測試，成功得到 **RED**：缺少 `IdentityGate.php`，退出碼 255。
2. 在隔離工作樹實作：
   - `IdentityGate.php`：純函數檢查 Steam Session vs 已讀 DB 帳號、ack/blacklist/region/username，沒有任意 `GET player` 入口；
   - `PvPAnnualAggregator.php`：純函數，結果按 P1/P2 原始旗標判定、NULL 非平手要進 undecided、MATCH／CR／未確認排除、三角色各局計一次、Canonical Team Key、重複比對、年度半開窗口和未知來源；
   - `CupPublicReader.php`：受注入 PDO 所控制的唯讀 SELECT Reader，只公開已發布且可見的戰報、已驗證小局、合法勝者、大場完成狀態、created_at/updated_at 不晚於 as_of；不進行 Discord/Tonamel/遊戲名稱的個人身分猜測。
3. 使用 PHP 8.3.30 CLI（含 pdo_sqlite），完全合成的 in-memory SQLite 測試：**28/28 PASS、0 FAIL**；全部 PHP 檔案 `php -l` PASS。
4. 把 `CupPublicReader.php` 僅透過 SSH STDIN 在實際 VPS 上的 PHP 記憶體執行，`START TRANSACTION READ ONLY` + `ROLLBACK`，**沒有上傳或部署檔案**；實際查詢返回 6 published reports／27 visible sections／59 verified games、個人 CUP=UNKNOWN。

範例重跑命令（只會操作本機合成資料）：

```powershell
$repo = 'D:\ULGG\Checkpoint2026\checkpoint2026-d1m1'
php "$repo\tests\checkpoint2026\run.php"
```

## 尚未完成及不可混淆的 Gate

- 目前**沒有**正式 PvP SQL FactReader：PvP 計算器僅接收已標準化的合成資料陣列，不會自行連正式 DB，也不能保證外部呼叫者真的做好資料水位和權限查核。
- 目前**沒有**年度回顧 API、Steam session 整合 E2E、前端、快照產製、手機分享卡。
- 公開 CUP Reader 的 `as_of` 是對現存資料 `created_at/updated_at` 的截止過濾，**不能回溯還原未保存歷史版本的已修改記錄**；正式年度快照必須採 revision/hash。
- 個人 CUP 與 Raid 仍為 UNKNOWN；不得以 0、姓名猜測或非完整排名代替。
- 不操作正式遊戲、不部署、不合併 PR、不新增/修改/刪除正式資料庫資料或索引。

結論：**D1 實際 Schema／資料來源 Gate 與 M1 純程式 TDD 初階驗收通過。下一階段請使用 M2 E2E 工作包。**
