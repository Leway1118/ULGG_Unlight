# G2-R2｜帳號級鎖與 Linux 隔離執行驗證

驗收日期：2026-11-24（台灣時間）
範圍：僅離線測試，沒有遊戲登入、Watcher 授權讀取、正式 DB、VPS 系統變更或 Collector 啟動。

## 1. 原始安全缺口與本次改良

原版 One-shot 將 Journal 路徑交給呼叫者指定，因此換檔名就能建立另一份 Journal。現在增加 `raid_bot/safety/account_guard.py`：

- 根據格式正確的 Steam ID 衍生 SHA256 帳號檔案鍵（不把原始 ID 寫入檔名）。
- 相同 Steam ID 在**同一個受保護狀態目錄**下，不因更換別名而改用其他鎖。
- 使用原本 `PersistentCleanupJournal` 的 `O_EXCL`、`fsync`、原子狀態更新與拒絕重試規則。
- 原本的離線 CLI 改採 `--state-root`（預先建立的狀態目錄）；不再提供 `--journal` 自選檔名。
- 拒絕位於 Git 原始碼內、不存在、不是目錄或經由 symbolic link 的狀態目錄。
- Linux 額外要求目錄歸執行者擁有、群組及其他使用者無權存取。
- Linux 以測試使用者 `nobody`（UID 65534）驗證，非 root 權限即可完成安全模擬。

## 2. 同一版本程式的 Linux 驗證

因本機 Windows 尚無 WSL Linux 發行版，也沒有 Docker，本次使用**獨立 Linux 測試容器**，僅複製並驗證以下兩份原始檔，未碰你的 VPS：

- `raid_bot/safety/account_guard.py`，Windows 與 Linux SHA256 均為 `e06bd56203748a789ee047c55e7895d13166bf65c75d1ad39fccc0df61de4a57`
- `raid_bot/safety/cleanup_journal.py`，Windows 與 Linux SHA256 均為 `c36ff2f602f1877f02ca5ca21e34a6471f8e2c782140bbffde8f6bd927330a2e`

Linux 使用實際非 root 使用者執行 13 個流程檢查，全部通過，包含：

- 0700 狀態目錄、0600 Journal 權限。
- 兩個獨立 Linux 子程序搶同一個帳號鎖，只有一個可成功建立 Journal。
- 更換帳號別名／重新建立 guard 後不能重試。
- 成功完成的 Journal 仍阻止再次啟動。
- group/world-writable 狀態目錄、符號連結及 repo 內目錄皆拒絕。
- 不同 Steam ID 衍生不同 Journal。
- 不存在目錄或一般檔案取代目錄：額外驗證 fail-closed。

**注意：Linux 測試目前驗證的是帳號鎖與 Journal 的實際原始模組，不等於整套 Raid Bot 已在該 Linux 主機完成 E2E。**

## 3. Linux systemd 隔離規格（靜態核對）

模板：`docs/collector-g2-offline.service.example`。此模板**禁止安裝在正式 VPS**，僅供日後獨立 Linux staging 使用。

- `DynamicUser=yes`：使用無特權的動態帳號。
- `PrivateNetwork=yes`、`IPAddressDeny=any`、`RestrictAddressFamilies=AF_UNIX`：拒絕 TCP/UDP 對外連線。
- `StateDirectory=ulgg-raid-g2-offline`、`StateDirectoryMode=0700`、`UMask=0077`：固定帳號 Journal 的系統狀態目錄。
- `Type=oneshot`、`Restart=no`：不可作為常駐重試服務。
- `ProtectSystem=strict`、`ProtectHome=yes`、`NoNewPrivileges=yes`、空的 capability set：收斂檔案權限。
- `ExecStart` 指向**合成資料的 offline fixture CLI**，沒有 real game adapter，也沒有 `WantedBy` 自動啟用標的。

使用獨立 Linux 測試容器執行 `systemd-analyze verify`：RC=0。使用 `systemd-analyze security --offline=yes`：總暴露分數 **2.6（OK）**。比對 systemd 指令內容（去掉註解和 Install 空區段）SHA256：`cb414d9029772d0e721a1b59402db912df3e98b640649eb5d2f60a5a8bb102cb`，與 Windows 模板相同。

**重要限制：模板尚未在 systemd 實際開機執行，沒有驗證動態使用者的持久狀態映射、Mount Namespace 的實際行為或服務重啟復原。只可稱為 Linux 非 root 的模組實測＋systemd 靜態驗證。**

## 4. 離線測試與檔案

- 既有 G2 測試：202 項。
- G2-R2 新增 account lock 測試：15 項（含狀態根目錄故障）。
- G2-R2 新增 Linux systemd 模板測試：5 項。
- 全套預期：**222 項**。
- `tests/test_account_scope_guard.py`：同帳號多別名、多程序搶鎖、跨重啟、根目錄不合法、CLI 重跑等。
- `tests/test_linux_offline_sandbox_spec.py`：5 個不可缺少的隔離選項。
- 本機驗收 JSON：`D:\ULGG\Checkpoint2026\gate2\G2R2_Full_R1_20261124.json`、`G2R2_Full_R2_20261124.json`、`G2R2_Full_R3_20261124.json`。

## 5. 仍需解決的安全缺口（沒有假裝全部完成）

1. **狀態根目錄仍可變更**：現在只是禁止自選 Journal 檔名；離線 CLI 的 `--state-root` 仍可換目錄。未來 Live Adapter 必須由可信任系統設定固定狀態根目錄，不能由使用者 CLI 任意改路徑。
2. **同一個有寫入權的執行身分可能刪除自己的 Journal**；如要達成真正「只有管理員能解除阻擋」，仍需獨立權限的守門服務、不可由 Collector 身分移除的持久紀錄，及明確人工核准流程。
3. **Steam ID 的正確格式不等於帳號認證**：未核對目前 Steam ID ↔ 遊戲 Session 的權威綁定，不使用先前腳本取得的舊 UUID 登入。
4. **Linux Unit 未實際啟動**：未建立非 root staging 主機實際服務，也未驗證 OS 網路隔離真的生效。
5. **Live cleanup 在逾時／取消時仍須額外保護**，不可把 offline `asyncio.wait_for` 直接作為真實遊戲操作保證。
6. 尚未取得單一獲授權的測試 Raid，沒有真實 `rank[]`、入渦／退渦或 staging DB 寫入。

下一步建議：保持 G2-R2 為本機離線分支；接著設計受保護的**帳號級鎖守門服務（獨立權限）**與真正獨立 staging，並在明確核准後才進入任何遊戲端操作。

**禁止事項：本工作包沒有 GitHub Push／Merge、VPS SSH 寫入、systemd 安裝／啟動、實際遊戲連線及正式 DB 寫入。**
