# UL.GG 2026 Checkpoint — M3 開發與安全驗收工作包

M2 結果：純合成單元 TDD PASS，本機 127.0.0.1 HTTP＋Chromium 360px／1280px E2E PASS；尚未取得任何正式部署或實際會員資料接入授權。

## M3-A（P0）獨立 Linux CI / Draft PR

前置：M2 Commit / Patch 本機清潔、來源敏感字串掃描通過。若要推送新的 GitHub 分支，需另外明確核准。

1. Linux PHP 8.0 與 PHP 8.3 / 最新 LTS Matrix：安裝 pdo_sqlite，M1 28 tests + M2 22 tests；拒絕網路、無正式配置和 DB Secrets。
2. 使用獨立 GitHub-hosted Runner 跑 synthetic PHP built-in HTTP API E2E，測試匿名 401、Steam A/B 同名跨區域、任意 player/user_id 403、CUP published/verified、Snapshot digest。
3. Chromium 或 Playwright 360/390/768/1280px：確認四卡、鍵盤、跳過動畫、reduced-motion、沒有橫向溢出或 XSS。
4. 正式工作目錄與 Staging Controller 不得相互覆蓋，定義修改範圍只在 checkpoint2026，所有 PR 保持 Draft、CI 唯讀，不啟動真實遊戲。
5. 確認 PHP8.0 runtime，不只靜態語法檢查；Gate 失敗時保存已清理的合成日誌但絕不解除保護。

## M3-B（P0）可信的只讀資料來源與 Snapshot

1. 先用獨立受控 Linux Staging 和 DB 測試資料建立只讀 DB 身份（只授權 SELECT，不能 CREATE/ALTER/INSERT/UPDATE/DELETE/TRIGGER），不用正式網站的可寫憑證；現階段尚不建立任何正式 DB 帳號。
2. 身分授權 Reader 只憑 session 的 user_id + steam_id 與 game_user 唯讀精確 Join，跨帳號／未綁定／黑名單禁止私有資料輸出；登入測試必須是真正授權測試帳號或合成 Session。
3. 建立年度快照管線的來源證據：period window（Asia/Taipei）、原始表/索引 Schema fingerprint、已完成 ingestion watermark、對賬 row count、checksum、revision 和受管金鑰簽名（另行授權與密鑰管理）。
4. Snapshot 一次寫入不可變、依版本查詢；資料修正只能新增版本而非覆蓋舊快照。公開牌／私有牌不同 cache key 和無敏感個資。
5. 針對 PvP 139k+ fact rows 做 staging 查詢計畫與延遲試測，必要時改為唯讀抽取＋離線聚合，而非未經核准加正式 DB 索引。
6. CUP Reader 保證 published + visible + verified + 合法 winner，保留 source correction/version watermark；個人 CUP 無可靠 ID 映射仍 UNKNOWN。
7. Raid 玩家資料按月覆蓋率不足時維持 UNKNOWN；任何 G1 TEST-01 真實遊戲操作仍需單獨許可。

## M3-C（P1）產品驗收與發布準備

- 2026 年度預覽 Story UI：手機、桌面、Slow network、No JS、Keyboard、Reduced Motion、分享卡去識別化與多語言；舊 2025 Checkpoint 不變。
- 測試 HTML/JS XSS、CSRF 與 SameSite Session、個人資料防快取、IDOR、跨 TW/JP 同名、OpenID 回跳。
- API Response JSON Schema 和對來源完整度狀態的用字保持一致。
- 提供 Draft PR、Linux CI 全部 Gate、Staging Test Evidence、回滾作業書。**禁止自動合併、部署正式站。**

## 尚未獲准的作業

- 建立／變更任何正式 DB 帳號或資料表、正式索引。
- 將 synthetic-only Controller 改成連正式資料庫或向真實會員提供。
- 合併任何 PR、部署、重啟正式網站、Watcher、Collector。
- 啟動、登入、加入或退出真實 Unlight Raid。

推薦下一次授權範圍：**只批准 M3-A 的獨立 Draft PR 與 Linux CI，且不含 M3-B／正式環境。**
