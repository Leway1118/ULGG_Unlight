# UNLIGHT Bonus Navigator v0.2

Bonus Navigator 是 Windows Tkinter 桌面工具。它透過 CDP 連到已啟動的
UNLIGHT 桌面版，在含有 `window.game` 的 execution context 內讀取
Bonus 場景，並注入自動 HIGH／LOW 控制程式。

目前文件對應
`detector/unlight_bonus_navigator_gui_v02.py`。`v01` 仍存在，但不包含
v0.2 已實作的庫存選擇與 95% 建議量檢查。

## 現有功能

- 從 `window.game.scene.keys.Bonus` 取得目前 Bonus 場景。
- 使用遊戲內已有 WebSocket client constructor 查詢
  `db_bonusgame`，讀取本輪獎品表。
- 顯示目前位置、目標位置與剩餘距離。
- 可選擇：
  - β型渦探知機Ⅰ
  - β型渦探知機Ⅱ
  - 指定事件卡
  - 指定角色卡
  - 指定道具
  - 自訂停止階數
  - 不限目標，持續爬星
- 同名獎品有多筆時，選擇目前位置之後最近的一筆。
- 按鈕或 Space 可開始／停止自動操作。
- 骰點小於 7 時選 High，大於 7 時選 Low，等於 7 時隨機選擇。
- 失敗需要救援道具時，優先使用「剛好足夠」的最小道具；
  沒有可用道具時安全停止。
- 讀取花1、花3、花5、跳越星的庫存。
- 根據程式內固定機率模型顯示 95% 建議量；這是程式估算，
  不是結果保證。

這不是純讀取工具。開始後會觸發 Bonus 場景按鈕，並可能消耗
花1、花3、花5 或跳越星。開始前應先確認目標與庫存。

## 實際需求

- Windows 與 Python 3.11+。
- Python 發行版包含 Tkinter。
- Python package `requests` 與 `websockets`。
- UNLIGHT 桌面版已開啟 CDP port `59222`。
- 遊戲已正常啟動，CDP 內能找到包含 `window.game` 的 context。
- 要讀取獎品表時，必須已進入 Bonus 獎勵畫面。

Bonus Navigator 本身不會啟動 Steam、不會重啟遊戲，也不會自動加上
CDP 參數。桌面遊戲必須在啟動時已有：

```text
--remote-debugging-port=59222
```

工具只從專案內 import `ul_sniffer.ULSniffer`。現行程式沒有 import
Extension，也沒有讀取 `manifest.json`、`content.js` 或 `shell.js`。

## 安裝

在 PowerShell 進入專案目錄：

```powershell
cd "D:\path\to\unlight-card-tracker"
py -3.11 -m venv detector\.venv-bonus
detector\.venv-bonus\Scripts\python.exe -m pip install --upgrade pip
detector\.venv-bonus\Scripts\python.exe -m pip install requests==2.34.2 websockets==16.1.1
```

專案的 `detector/requirements.txt` 也包含這兩個 package，但同時包含
Tracker、OpenCV、PyInstaller 等其他工具依賴；單獨執行 Bonus
Navigator 不需要安裝這些其他功能。

## 啟動步驟

1. 在 Steam 的 UNLIGHT 啟動選項確認
   `--remote-debugging-port=59222`。
2. 完全關閉已開啟的遊戲，再從 Steam 重新啟動。
3. 可先在 PowerShell 驗證 CDP：

   ```powershell
   Invoke-RestMethod http://127.0.0.1:59222/json/version
   ```

4. 登入遊戲並進入 Bonus 獎勵畫面。
5. 從專案根目錄執行：

   ```powershell
   detector\.venv-bonus\Scripts\python.exe detector\unlight_bonus_navigator_gui_v02.py
   ```

6. 等待畫面顯示「已連接 UNLIGHT 桌面版」與「已偵測獎勵遊戲」。
7. 選擇目標，確認目標位置、剩餘距離與庫存。
8. 按「開始自動爬星」或 Space。

開始前若庫存低於 95% 建議量，GUI 會詢問是否仍要開始；
現行程式允許使用者選擇繼續。

## 停止與重新讀取

- 按「停止自動爬星」或 Space 可手動停止。
- 到達指定目標時自動停止。
- 沒有可處理當前骰差的救援道具時自動停止。
- JavaScript runtime error 時自動停止。
- 「重新讀取本輪獎品」會重新查詢當前 Bonus 獎品表。
- CDP 斷線後 GUI 會顯示「請重新啟動本工具」；現行 GUI 沒有
  自動重連流程。

完整安裝摘要請見 [docs/USER_INSTALLATION.md](docs/USER_INSTALLATION.md)，
錯誤對照請見 [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)。
