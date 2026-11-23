# G2-R3｜獨立權限 Gatekeeper 與人工解除（離線驗收）

狀態：**OFFLINE_G2R3_PASS / REAL_GAME_BLOCKED / NO_PRODUCTION_DEPLOY**
日期：2026-11-24（台灣時間）

## 1. 安全模型

本工作包新增「由獨立權限的 Gatekeeper 管理帳號鎖」的**離線**架構。目的是防止 Worker／Collector 能自行刪除 Journal 或跳過前一次尚未解決的清理狀態。

角色與權限（Linux staging 使用合成 UID）：

| 角色 | 示範 UID | 權限 |
|---|---:|---|
| Gatekeeper | 12001 | 擁有私有狀態目錄、帳號 Journal、加雜湊的 audit log、Unix Socket |
| Fixture Worker | 12002 | 只能透過 Socket 呼叫 status / reserve / complete；不能讀、修改或刪除管理者 Journal |
| 非授權使用者 | 12003 | 即使擁有 Socket 群組資格，SO_PEERCRED 仍拒絕 |
| 人工操作員 | 管理者本地執行身分 | 需人工取得獨立清理證據後，透過僅管理者可執行的 CLI 解除；**非 Worker RPC** |

**目前管理者程序及操作員 CLI 使用相同 Linux UID，因此此為 Worker 與 Gatekeeper 之間的 OS 權限隔離，尚不是具有獨立簽核密鑰的第三層人員授權系統。**未來若正式化，應改由受獨立管理者保護的簽核程序與佐證工件發出 release 許可。

流程：

1. Gatekeeper 啟動時固定帳號 Steam ID（此版僅合成資料）與單一允許的合成 Raid Code。
2. Worker 以 Unix Socket 傳送 `reserve`，Gatekeeper 透過 Linux `SO_PEERCRED` 核對精確 UID。
3. Gatekeeper 對該帳號先 `fsync` 寫入 `RESERVE_INTENT`，才允許建 Journal，再 `fsync` 寫入 `RESERVE_DONE`。
4. Worker 完成合成資料流程後，只能呼叫 `complete`；不論回報成功或失敗，Journal **都不會自動清除**。
5. 必須由操作員在管理者本地 CLI 提供 Run ID、獨立清理佐證參照、明確核准文字，才會先記 `RELEASE_INTENT`、封存原 Journal、再記 `RELEASE_DONE`。
6. 若在保留意圖、封存或寫入過程當機，缺失的 Journal 不等於可以再試；稽核紀錄顯示未完成交易時，新 `reserve` 必須 fail closed。

工作程式：

- `raid_bot/safety/gatekeeper_ledger.py`：manager-owned Journal，flock 鎖、fsync 與有 hash chain 的 JSONL audit，人工解除與封存。
- `raid_bot/safety/gatekeeper_socket.py`：Linux Unix domain socket，SO_PEERCRED 精確 UID 白名單，僅允許 status/reserve/complete。
- `run_gatekeeper_fixture.py`：manager-only daemon CLI 與 local-only operator-release；沒有 live game adapter。
- `run_gatekeeper_worker_fixture.py`：合成資料 worker；沒有 --state-root、--steam-id 或任何解鎖指令。
- `tests/test_g2r3_gatekeeper_contract.py`、`tests/test_g2r3_service_specs.py`：Windows／Linux 離線回歸契約。
- `tests/linux_gatekeeper_owner_tests.py`：獨立 Linux non-root 權限下執行的 Ledger 單元測試（不納入 Windows `test_*.py` 自動發現）。

## 2. 真正執行過的獨立 Linux staging

使用**ChatGPT 隔離 Linux 測試容器**（不是你的 Contabo VPS），檔案由筆電隔離 Git 工作樹複製，SHA256 比對一致。

核心程式 SHA256：

- `gatekeeper_ledger.py`：`50ba90e69b62437fbcab75defae29401833dc4809df1f014c2be780a18618873`
- `gatekeeper_socket.py`：`6928264333daa2f5a6d397a7de8ea3a919ccdb7c39639a56a538651290af6f24`
- `account_guard.py`：`e06bd56203748a789ee047c55e7895d13166bf65c75d1ad39fccc0df61de4a57`
- `cleanup_journal.py`：`c36ff2f602f1877f02ca5ca21e34a6471f8e2c782140bbffde8f6bd927330a2e`
- Linux 專用測試碼：`a17065b1ab92e1e6ddcdf229af3a533b0624f114ad3f1f212b18e3e76cbfc1b1`

實測：

- Gatekeeper 以 UID 12001 啟動，Worker 以 UID 12002 執行；Socket 權限 `srw-rw----`，擁有者 12001、群組 12002；Socket 目錄 `0750`。
- Gatekeeper 的私有資料目錄 `0700`；Journal／Audit／lock file `0600`。
- Worker 無權讀取或刪除 Journal；用 Worker UID 呼叫操作員 CLI 被拒絕。
- UID 12003 即使在同群組，直接呼叫狀態 API 仍由 SO_PEERCRED 拒絕。
- 同一 Worker 再次執行、Gatekeeper 重啟後再次執行都被拒絕。
- Worker 透過 Socket 呼叫 `release` 被拒絕。
- 人工核准格式錯誤、未提供 independent cleanup attestation 都被拒絕。
- **僅在合成資料**下，操作員帶完整條件進行一次 Release；舊 Journal 封存、稽核鏈保持可驗證，再一次合成資料失敗又重新進入 `BLOCKED_PENDING_CLEANUP`。
- 模擬 `RESERVE_INTENT` 後當機、Journal 封存但沒有 `RELEASE_DONE`、稽核檔內容被更改，都必須阻擋。
- `tests/linux_gatekeeper_owner_tests.py` 在 UID 12001 下實際執行 **11/11 PASS**。

這些操作沒有使用真實 Steam ID、UUID、Token、Raid Code，也沒有真實遊戲登入、Raid 加入／退出或資料庫存取。

## 3. systemd 設定驗證（靜態）

兩份 template：

- `docs/collector-g2r3-gatekeeper.service.example`
- `docs/collector-g2r3-fixture-worker.service.example`

- 不同 `User/Group`、管理者 `StateDirectoryMode=0700`、Worker 無權寫入 StateDirectory。
- Manager also needs ReadWritePaths=/run/ulgg-g2-socket because ProtectSystem=strict can otherwise prevent Unix socket creation. Worker has no StateDirectory write permission.
- `PrivateNetwork=yes`、`IPAddressDeny=any`、`RestrictAddressFamilies=AF_UNIX`。
- `ProtectSystem=strict`、`NoNewPrivileges=yes`、空 CapabilityBoundingSet。
- `Restart=no`，Worker `Type=oneshot`，兩個 Unit 都沒有 `[Install]` 或 `WantedBy`。
- `systemd-analyze verify`：**RC=0**。
- `systemd-analyze security --offline=yes`：Gatekeeper **2.3（OK）**；Worker **2.3（OK）**。
- 去掉空行／註解的 Unit SHA256（Windows 與 Linux 一致）：
  - Gatekeeper `8e0ef9cba75544e7b973487f16cb04518701f80707e453dfff9b5e7158db68be`
  - Worker `3ddfc2e053c7fa58ba4a9f040edf7853ade0e31be26bb36d58c8851f46cb9fe6`

**重要：** 容器 PID 1 為 `supervisord`，不是 systemd；`unshare -n` 回傳 Operation not permitted。故未真正透過 systemd 啟動，也未驗證 Linux network namespace 真正封鎖網路；只證明 Linux 實際 UID、檔案 ACL/權限、SO_PEERCRED 與 Unix Socket 的安全邊界及 Unit 的靜態安全規格。

## 4. 人工解除流程（先保守阻擋）

人工核准的標準程序：

1. 先用**原帳號的獨立新 Session**，確認沒有任何由上次測試加入且未退出的 Raid；目前沒有實機驗證功能。
2. 將操作時間、匿名帳號代號、Run ID、掃描期間、`rank[]` 完整度與獨立退出結果保存在受管理者保護的證據工件，取得 evidence reference。
3. 第二位具有適當權限的人員審核清理證據；本版尚未實作獨立簽核身分驗證。
4. 由具有 Gatekeeper 管理者本地執行權限的人員執行 `operator-release`，必填 Run ID、evidence reference、independent cleanup confirmed 與完整核准文字。
5. Gatekeeper 以寫前日誌及封存記錄解除，產生 RELEASE_INTENT／RELEASE_DONE；保留舊 Journal，新的測試仍須另行取得明確核准。
6. 任何失敗：保持新 join 禁止，不能手動刪除 Journal、稽核檔或清理歷史來逃避阻擋。

## 5. 後續實機放行仍需通過的條件

- [ ] 真正有效、獨立且合法的 TEST-01 Steam/TW 授權；不可共用 Watcher Session，也不能用舊 UUID 當作有效登入。
- [ ] 真正獨立 Linux staging systemd 開機與網路隔離，包含實測 PrivateNetwork／RestrictAddressFamilies；本容器無權限做到。
- [ ] 操作員簽核身分與清理證據的實質驗證（不能只信任文字／勾選）。
- [ ] Live One-shot Adapter 的 join ACK、退出 ACK、獨立 fresh-session 驗證及斷線時清理保護，必須另案設計與稽核。
- [ ] 明確獲授權的單一測試 Raid，確認沒有 AP、戰鬥、報酬或其他玩家副作用。
- [ ] 獨立 MariaDB staging 與資料來源證據（provenance）；不寫正式 DB。
- [ ] 任何真實遊戲操作、PR 合併、正式部署均需另外批准。

**不得把本 G2-R3 設計當作已可放行真正 Raid Collector。**
