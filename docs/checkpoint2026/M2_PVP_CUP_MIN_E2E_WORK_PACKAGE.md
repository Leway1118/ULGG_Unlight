# UL.GG 2026 年度回顧｜M2 最小 E2E 工作包（待核准）

**前置依賴：** D1 真實 Schema 唯讀查核 PASS、M1 28/28 TDD PASS、CUP Reader 真實只讀 Smoke PASS。M2 不依賴 Raid Player Collector；G2-R4 已另行通過合成資料 Linux Staging Gate。

## 可先交付範圍

MVP 優先：**本人 2026 PvP 概覽＋三角色與常用隊伍＋每月趨勢＋公開 CUP 年度大事記**。個人 CUP / Raid 因身份及玩家 Rank 完整度未過 Gate，保持 `UNKNOWN` 卡，不造假 0。2025 舊頁仍保持原樣。

## M2-1｜權威 Identity Adapter

- 路徑建議：`lib/checkpoint2026/IdentityReader.php`（新增）、`api/checkpoint2026/me.php`（新增）。
- 所有私人回顧請求由 Session 取 `steam_id`、`user_id`，透過參數化 SQL 唯讀 `SELECT id,steamID,username,region,ack,apply,black_list FROM game_user WHERE id=:id AND steamID=:steam_id LIMIT 2`，必須恰好一筆。
- 執行 `IdentityGate::fromSessionAndAccount()` 後才取得 `username+region`；`$_GET['player']`、`$_GET['steam_id']` 和任意 URL ID 一律不能覆蓋本人。
- 無權時回傳 401/403 + 身分狀態，不做任何 PvP 查詢；嚴格 `Cache-Control: private, no-store`、`X-Robots-Tag: noindex,nofollow`；永不回傳 Steam ID、DB ID、email、Friend Code、Session 或原始對戰對手姓名到公開分享卡。
- 管理員跨帳號檢視如有需求，獨立 API/授權/操作記錄，**不得**使用 `me.php` 的可控 player 參數實現。
- 必驗：Steam A 的登入 Session 偽造 Steam B／player B、待審、封鎖、同名跨 TW/JP、Session 過期。

## M2-2｜PvP FactReader（先 Staging，後正式唯讀）

已查明 `arena_player_match_result` **沒有 region 欄**，必須 `JOIN arena_unlight a ON a.id=m.match_id`；`a.region` 不能從 `m` 猜測。資料場次鍵 `(match_id,side)` 有唯一鍵；玩家名稱可能在不同平台重複。

建議 SQL 骨架（待測 `EXPLAIN` 後凍結，禁止現在直接當正式 API）：

```sql
SELECT
  m.match_id,m.side,m.player_name,m.update_time,
  a.update_time AS truth_update_time,
  a.region,a.ack1,a.ack2,a.name_p1,a.name_p2,
  a.win,a.lose,a.tie,
  m.leader_id,m.back1_id,m.back2_id,m.team_key,
  m.cost,m.is_win
FROM arena_unlight a
JOIN arena_player_match_result m ON m.match_id=a.id
WHERE a.region = :region
  AND m.player_name = :trusted_db_username
  AND a.update_time >= :period_start
  AND a.update_time < :as_of_exclusive
  AND m.update_time >= :period_start
  AND m.update_time < :as_of_exclusive
ORDER BY m.match_id,m.side;
```

- `trusted_db_username` 只能由 M2-1 驗證後的 DB 身份產生，不能從前端輸入。
- 查詢 `ack`、`is_win`、MATCH、CR、原始旗標等必要欄位，由 M1 Aggregator 據契約過濾並產生被排除原因；對未知/異常不強制修正資料。
- 來源完整度由獨立、唯讀的 ingestion watermark/coverage Gate 判定，**不能由 UI 自行傳入 source_verified=true**。全部時間欄位以 DB 時區與匯入語意檢查後才能決定轉換策略。
- 正式 `EXPLAIN` 已見 `a.idx_result_time` 掃描估計 75,347 列、再 join `m.idx_match`；實作後應用 staging 的 `EXPLAIN ANALYZE` 或等效安全查核測量，不能直接對正式 DB 執行高負載分析。必要時設離線 Snapshot 預聚合；不得未經授權更動正式索引。
- 來源查不到不代表玩家沒玩：保持 `UNKNOWN`，來源完整性通過才能呈現真的 0。

## M2-3｜公開 CUP 對接與資料快照

- 沿用 `CupPublicReader.php`，正式程式注入外部已核准的唯讀 PDO；SQL 只使用 published report、visible section、verified game、confirmed/finished match、合法 winner。
- `as_of` 使用已核實觀測水位，不得大於取得資料的時間；不能把當前更新時間誤當歷史值可完全回放。
- `personal_cup_status='UNKNOWN'` 直到存在人工審查的權威 `tournament_players.id ↔ game_user.id` 映射；同名／Discord／Tonamel 不得自動認作同一人。
- 使用資料契約 `checkpoint2026/1.0.0-draft`，Response JSON Schema 及合成 Fixtures；快照記錄 `as_of`、`revision`、`schema_fingerprint`、`source_watermark`、`payload_sha256`，每次資料修正要建立新 Revision。
- 路徑建議 `api/checkpoint2026/public.php`，公開彙總不得帶個人身份或未公布的戰報欄位。

## M2-4｜手機與桌面 MVP E2E

獨立頁 `pages/checkpoint2026/index.php`（不修改 2025 Checkpoint）：四張故事卡依序為總覽、角色、隊伍＋月份、已發布 CUP。首頁只展示可驗證指標，`UNKNOWN` 使用明確文案；動態效果可跳過且遵守 `prefers-reduced-motion`。

### 必驗場景

| 類別 | 必驗 |
|---|---|
| IDOR | A Session 傳 B player / Steam ID → 403 或忽略，絕不查 B 資料 |
| 綁定 | ack=0 / apply=1 / blacklist / Session 不一致 → 拒絕私人資料 |
| PvP | P1/P2 勝負相反、平手、NULL 未決、MATCH／CR、時區年度跨界 |
| 組隊 | 三角色都計入、相同角色同局只算一次、後排排序 Key 相同 |
| 去重 | 同 match_id+side 不重複、矛盾資料隔離 |
| 資料品質 | 0 vs UNKNOWN vs PARTIAL、12 月未到=NOT_APPLICABLE、watermark |
| CUP | draft/archived/hidden 排除，verified/confirmed／winner 合法 |
| 交互 | 360px 手機無橫向捲動，鍵盤切換、動畫可跳過 |
| 安全 | NoStore、X-Robots、無 steamID/email/token/session／私有對手姓名 |
| 成本 | 快取鍵隔離，Snapshot 可重現、查詢計畫和延遲在 staging 合格 |

## M2 交付與放行條件

1. 隔離本機分支，先 TDD RED→GREEN；測試覆蓋 2026 年度與權限分支。
2. E2E 執行環境必須為 Staging 或純合成資料，正式資料庫僅 SELECT／EXPLAIN；不合併、不部署、不操作真實遊戲。
3. Schema fingerprint、水位、異常/未知及參照數量必須留存可稽核證據。
4. 交付 Draft PR 與 CI **需另外核准**；目前 D1/M1 僅本機提交、**不推送**。
5. 產品發布前仍需單獨安全審查、正式部署核准與回滾計畫。

**推薦順序：** M2-1 IdentityReader → M2-2 PvPFactReader/快照 Gate → M2-3 CUP/Response DTO → M2-4 純合成 E2E UI。Raid 走獨立工作線，不阻擋 PvP＋CUP Preview。
