# G2 — Raid Collector 一次性測試工具（離線版）

狀態：**OFFLINE_ONLY / LIVE_DISABLED**。本工作包不包含真正的遊戲 Socket、TW 登入、Raid 加入或正式 DB 寫入。
相依 Gate：G1 帳號候選 `ULGG-RAID-TEST-01` 已提供歷史 Steam ID／UUID 來源；**尚未獲得當前有效授權及帳號綁定驗證**，不可直接使用舊 UUID。

## 架構

```text
[DedicatedAccountConfig]
  ├─ alias / SteamID 用於測試帳號引用
  ├─ credential_file 必須在 Git repo 之外
  ├─ 禁用 watcher 共用的 session 路徑與環境來源
  └─ Windows ACL 尚未實作 -> Windows 讀取真實憑證一律 fail closed
              |
              v
[OfflineOneShotPlan] --> 必須 offline-fixture、目標唯一 allowlist、max joins = 1
              |
              v
[PersistentCleanupJournal.reserve()] --> O_EXCL + flush/fsync 先寫入 PENDING
              |
              v
[FixtureEnricher.enrich()] --> 真實版未接線；現只使用合成資料
              |
              v
[不同 fixture verifier 的 fresh-session 檢查]
       ├─ 清理未證實、斷線、超時、取消 -> BLOCKED_PENDING_CLEANUP
       ├─ 清理已驗證、rank 缺值       -> VERIFIED_CLEANUP_DATA_UNKNOWN
       └─ 清理已驗證、rank 有效       -> VERIFIED_COMPLETE
              |
              v
[同一 Journal 永遠不可重新加入；人工核查後另立新 Run]
```

檔案：
- `raid_bot/safety/dedicated_account.py`：專用憑證路徑驗證；不呼叫 `getid` 或讀取 Watcher Session。
- `raid_bot/safety/cleanup_journal.py`：磁碟寫前紀錄、原子建立、防重複、保守清理狀態。
- `raid_bot/safety/offline_oneshot.py`：只接受 offline fixture client 的單次 Orchestrator。
- `run_collector_oneshot_offline.py`：**純模擬** CLI，沒有正式遊戲 URL／Token 接線。
- `tests/test_offline_oneshot_gate.py`：斷線、逾時、重啟、併發、拒絕共用帳號來源、讀寫安全等測試。

## Windows 本機演練

在 `D:\ULGG\Checkpoint2026\collector-safety-gate\tool\raid-bot` 的隔離開發工作樹內：

```powershell
$python = 'D:\ULGG\Checkpoint2026\protocol16-offline\tool\raid-bot\.venv\Scripts\python.exe'
$script = '.\run_collector_oneshot_offline.py'
New-Item -ItemType Directory -Force 'D:\ULGG\Checkpoint2026\gate2' | Out-Null

& $python $script --state-root 'D:\ULGG\Checkpoint2026\gate2' --scenario success
& $python $script --state-root 'D:\ULGG\Checkpoint2026\gate2' --scenario success
# 第一次 exit 0，第二次必須 exit 2（拒絕重複執行）
```

G2-R2: the one-shot offline CLI derives a canonical account journal from Steam ID and state root. Never delete or rename a journal to retry. --state-root is an offline fixture option ONLY; a future live adapter must use a pinned OS-protected root.
`--scenario` 另有 `cleanup-failed`、`verification-failed`、`timeout`、`join-rejected`、`exception`、`unknown-rank`。
這些案例只用固定合成 SteamID／Raid Code／角色名稱。**沒有使用你先前提供的真實 UUID**。

## 最低限度驗收與紀錄

- CLI 不存在 `--live`、`--endpoint`、`--auth`、`--password` 或實際遊戲 Socket 參數。
- 不讀 `/run/unlight/tw_auth_session`，不呼叫 `9998/getid`，不使用真實測試帳號。
- 清理紀錄只保存 alias、隨機 Run ID、加鹽目標摘要、是否驗證退出及排名完整性；不保存原始 Raid Code、SteamID、UUID、Token、玩家姓名。
- 新程序看到任何既有 Journal（包含已完成）都拒絕再次執行。意外崩潰留下空白或損壞 Journal 也只能被阻擋。
- 同時啟動同一份 Journal，只有一個程序能使用 O_EXCL 建立，避免二次加入。
- 真實 Protocol 1.6 流程已有另一層 fake socket 整合測試：`__handshake_s → register → online → db_raid → raid_code_input → db_raid → raid_delete → fresh db_raid`。
- CLI 和 offline suite 可使用 Python socket guard 阻擋非 loopback 傳輸；這仍**不是** OS 層沙盒。

## 尚未放行的實機條件

1. **帳號**：真正獨立且合法授權的測試帳號；由專用登入機制取得目前有效 Session，驗證 SteamID 與 Session UUID 綁定。不得讀 Watcher 的共用 Session。
2. **憑證**：Linux owner-only 憑證檔（0600、正確擁有者）或另行完成 Windows ACL 安全驗證。此版本在 Windows 對實際憑證讀取必定拒絕。
3. **執行環境**：正式遊戲操作應另做獨立非 root 的 one-shot Adapter；不得直接將這個 `asyncio.wait_for` fixture runner 改成連上遊戲。實機清理要避免 timeout/cancel 在關鍵退渦中途停止，需手動復原程序。
4. **目標 Raid**：只有明確授權的單一測試 Raid，確認加入／刪除不花 AP、不戰鬥、不領獎、沒有其他玩家副作用。
5. **Journal 保護**：新 one-shot Adapter 必須先持久化 PENDING，再能送出任何 join；手動復原及 fresh-session cleanup 驗證前不得清除。
6. **正式核准**：真實連線、加入或退出、PR 合併及正式 Collector 啟動均需單獨批准。

## Gate 判定

- G2 本機離線模擬：可進行。
- G2 真實帳號認證與 OS 隔離：尚未完成。
- G3 真實測試 Raid：尚未獲得授權。
- G4 真實遊戲加入與退出：**禁止執行**。
- 原 GitHub Draft PR #13：保持不變，安全工作包仍是獨立本機分支。
