# UL.GG 2026 年度回顧 — M2 合成資料 E2E 驗收

日期：2026-11-24（Asia/Taipei）
基底：D1＋M1 本機 Commit f67bd56a6e2acd6ffd452e72f921bf2edcb7b8e6
工作樹：D:\ULGG\Checkpoint2026\checkpoint2026-m2
分支：feature/checkpoint2026-m2-offline

**結果：本機離線 TDD 與獨立 loopback Staging PASS；正式使用者和獨立 Linux CI 尚未放行。**

## 一、M2 新增資料模組

- IdentityReader.php：以 Steam Session 的 user_id + steam_id 重新以 PDO prepared SELECT 核對 game_user。欠缺 Session、ack 未通過、黑名單、名字/伺服器區域不符即拒絕；不接受 URL 使用者選擇。
- PvPFactReader.php：只有重新驗證身份後才 SELECT，JOIN arena_player_match_result 與 arena_unlight，從 arena 表取得 region、ack、原始勝敗平。SQL 參數化，依本人名稱＋區域＋時間窗口查詢；不允許任意讀取別人。
- SourceCoverageGate.php：Schema 指紋、水位、已觀測/預期數量、缺漏及證據來源一致時，僅合成 Staging 允許 VERIFIED。瀏覽器設定的 source_verified=true 沒有效力。
- ResponseSnapshot.php：分離 PUBLIC 和 PRIVATE allowlist，提供版本、revision、as_of、source_watermark、schema_fingerprint、SHA256。私人回應不包含 Steam ID、DB ID、Email、玩家或對手名稱。
- StagingGateway.php：只允許 PHP cli-server、ULGG_CHECKPOINT2026_M2_STAGE=synthetic-only、127.0.0.1 Host + Remote IP。其餘環境統一 HTTP 503，不接正式 DB。
- api/checkpoint2026/me.php：僅供合成 Session 本人的私人 PvP；禁止 player/steam_id/user_id/region/view_as URL Selector。
- api/checkpoint2026/public.php：已發布 CUP 戰報公開摘要；不包含個人 CUP 身份。
- api/checkpoint2026/_fixture_login.php：只有合成 Steam A/B 登入與登出；不是正式 Steam OAuth。
- pages/checkpoint2026/index.php、story.css、story.js：四張故事卡；360px 手機及 1280px 桌面，上一頁/下一頁、鍵盤、跳過動畫、Reduced Motion，使用 textContent 而非注入不可信 HTML。
- tests/checkpoint2026/FixtureData.php：純合成 SQLite in-memory Fixture，含 TW/JP 同名、MATCH、未確認對戰、P1/P2、CUP hidden/draft 等反例。

## 二、測試證據

| Gate | 驗收 | 結果 |
| --- | --- | --- |
| RED→GREEN | 新模組不存在時測試失敗，實作後通過 | PASS |
| M1 回歸 | 先前 28 項 PHP 測試 | 28/28 |
| M2 TDD | 身份、PvP、完整度、CUP、Snapshot、權限、假冒 | 22/22 |
| PHP 8.3.30 語法及 Node JS | PHP、JavaScript 靜態檢查 | PASS |
| PHP 8.0.30 語法 | 10 個新 PHP 檔只透過 SSH STDIN 傳到 php -l | 10/10 |
| 隔離 HTTP E2E | 11 個 localhost synthetic API/Browser 場景 | 11/11 |
| Chromium 手機 | 360px、四卡、鍵盤、跳過、無橫向溢出、減少動畫 | PASS |
| Chromium 桌面 | 1280px、四卡、無橫向溢出 | PASS |
| 服務回收 | E2E 後 PHP localhost Process | STOPPED |

新造測試為合成資料，不使用正式 Steam ID、TW Session、Watcher、DB 帳號或真實遊戲。正式 VPS 只做唯讀抽樣和 PHP 8.0 解析；沒有上傳任何程式檔。

HTTP E2E JSON：
D:\ULGG\Checkpoint2026\gate2\M2_Local_HTTP_E2E_20261124.json

合成畫面截圖：
D:\ULGG\Checkpoint2026\gate2\M2_Story_360_20261124.png
D:\ULGG\Checkpoint2026\gate2\M2_Story_360_Summary_20261124.png
D:\ULGG\Checkpoint2026\gate2\M2_Story_1280_20261124.png

PHP8.0 語法：
D:\ULGG\Checkpoint2026\gate2\M2_PHP80_Lint_20261124.txt

## 三、正式資料表 3,000 筆唯讀抽樣

START TRANSACTION READ ONLY，限量三千筆 Arena/PvP Join，結果只有聚合統計、不回傳玩家名稱：

- 更新時間一致：3,000/3,000。
- Canonical Team Key（主將＋升序兩後排）：3,000/3,000。
- P1/P2 win/lose/tie 對應 is_win：3,000/3,000。
- is_win 為 NULL：142 筆；原始明確 tie=1：8 筆。**NULL 不能直接視為平手**。
- 雙方 ack 確認：2,866/3,000。

抽樣檔案：
D:\ULGG\Checkpoint2026\gate2\M2_ReadOnly_FactQualitySample_20261124.json

**抽樣一致不等於 2026 全年資料完整；不能把本結果當成正式來源覆蓋率 VERIFIED。**

## 四、安全限制與剩餘工作

1. M2 目前只在本機合成 Staging 提供 HTTP，刻意對正式環境 HTTP 503。尚未接真正的 Steam OAuth Session、專用唯讀 DB 身分或正式玩家資料。
2. 來源完整度只接受合成 Fixture，尚缺可信的正式 Snapshot provenance、來源水位、全量覆蓋率及不可變 revision。
3. Snapshot 目前僅記憶體內可重現 DTO，沒有持久化不可變歷史版本；CUP 修改之後，不一定能重建舊的 as_of。
4. 個人 CUP 及 Raid 維持 UNKNOWN，不得以玩家同名或缺值 0 推定個人成績。
5. PHP8.0 完成語法檢查，但獨立 Linux PHP8.0 Runtime CI 尚未完成。
6. PvP FactReader 需要效能與 Snapshot 預聚合；尚未完成正式 Staging 權限 E2E 或負載驗收。
7. 本次不合併、不部署、不變更正式網站、正式 DB、Watcher、Collector 或遊戲操作。沒有推送 M2 分支，也沒有修改 PR #13～#15。

## 五、下一個建議工作包

M3-A：先以獨立 Draft PR（另行授權）在 Linux PHP8.0/8.3 CI 驗證 M2 合成 TDD、HTTP/IDOR、Snapshot、手機體驗，依舊不部署。

M3-B：建立最小權限的獨立只讀 DB 帳號和受管理者保護的快照來源認證，在隔離 Staging 驗證真實 Steam 對應與 PvP FactReader，補水位、快照 revision/版本不可變、查詢延遲與回滾。

只有取得另案上線授權後，才可以把 M2 synthetic-only HTTP Gate 換成正式授權資料來源。
