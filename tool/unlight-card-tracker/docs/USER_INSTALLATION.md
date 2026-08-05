# 使用者安裝流程

本專案的 Raid VPS Watcher 與 Bonus Navigator 是兩個不同的執行流程。
前者在 Linux VPS 上以 WebSocket 執行；後者在 Windows 上透過
CDP 操作已開啟的桌面遊戲。

## 如果你要安裝 Raid Discord Watcher

1. 準備 CentOS 9 VPS、Python 3.11、Raid owner ID 與 Discord webhook。
2. 把專案放到 `/opt/ulgg-raid-bot`。
3. 建立非 root 帳號 `ulgg` 與 Python virtual environment。
4. 安裝 `detector/requirements-raid-bot.txt`。
5. 從 `.env.example` 複製 `.env`，填入：
   - `RAID_SOURCE=websocket`
   - `RAID_OWNER_ID`
   - `DISCORD_WEBHOOK_URL`
6. 確認 `RAID_STATE_FILE=./data/raid_state.json` 所在目錄可由 `ulgg`
   寫入。
7. 先在前景執行 `python detector/run_raid_bot.py`。
8. 以 `curl http://127.0.0.1:8766/api/health` 檢查 `connected`。
9. 等待第一份 snapshot 建立 baseline；這一步不會發 Discord。
10. 前景驗證完成後安裝並啟用 systemd unit。

詳細指令請見 [../README_RAID_VPS.md](../README_RAID_VPS.md)。

## 如果你要安裝 Bonus Navigator

1. 在 Windows 安裝包含 Tkinter 的 Python 3.11+。
2. 建立 virtual environment，安裝 `requests` 與 `websockets`。
3. 在 Steam 遊戲啟動選項加入
   `--remote-debugging-port=59222`。
4. 完全重新啟動 UNLIGHT 桌面版。
5. 使用 `http://127.0.0.1:59222/json/version` 確認 CDP 可用。
6. 登入遊戲並進入 Bonus 獎勵畫面。
7. 執行 `detector/unlight_bonus_navigator_gui_v02.py`。
8. 等待 GUI 同時顯示已連接與已偵測 Bonus。
9. 選擇目標，確認位置、距離、庫存與 95% 建議量。
10. 明確確認工具可能消耗救援道具後，才開始自動爬星。

詳細說明請見 [../README_BONUS_NAVIGATOR.md](../README_BONUS_NAVIGATOR.md)。

## 不要混用的設定

| 項目 | Raid VPS WebSocket | Bonus Navigator |
| --- | --- | --- |
| 執行主機 | Linux VPS | Windows 桌面 |
| Steam／遊戲 | 不需要 | 需要已啟動 |
| CDP | 不需要 | `127.0.0.1:59222` |
| Raid owner ID | 需要 | 不需要 |
| Discord webhook | 需要 | 不需要 |
| Extension | 不需要 | 現行 Python 程式沒有依賴 |
| 狀態檔 | `raid_state.json` | 沒有持久化狀態檔 |

