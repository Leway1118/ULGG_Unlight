# 怎麼讓玩家不用手動設 debug port（WP-07）

CDP 的 `--remote-debugging-port` **只能在啟動時指定**，沒辦法對已經在跑的
程序補掛。所以插件必須自己啟動客戶端 —— 否則每個玩家都得手動去設 Steam
啟動選項，那是不可能推廣的。

**結論（2026-07-28 實測驗證）：桌面版與網頁版都可以由插件自己啟動，
兩邊都不需要玩家改任何設定。**

---

## 桌面版：直接執行 exe 就可以

```
<遊戲目錄>\UNLIGHTRevive.exe --remote-debugging-port=9333
```

實測撐過 45 秒正常運作，debug port 第 1 秒就通，且

```json
{
  "loaded": true,
  "phaser": "3.87",
  "url": "https://www.playunlight.online:14018/?steamid=765611998546…&token=***"
}
```

—— URL 帶著有效的 steamid 與 token，代表 **Steam 驗證確實通過了**，
不只是 Electron 起得來而已。

### 為什麼不必透過 Steam

1. **`steam_appid.txt` 遊戲本身就內建**（官方包裡就有，不是我們加的）。
   有它在，Steamworks 就能在直接執行時初始化。
2. **`main.js` 沒有 `requestSingleInstanceLock`**，多開不互擋。
3. 遊戲本來就會讀自訂 switch（`x` / `y` / `fullscreen`），不排斥額外參數。

唯一前提：**Steam 客戶端要在跑**，而且登入的是玩家想用的那個帳號。

### 插件要做的事

1. 從登錄檔找 Steam 安裝位置，掃 `libraryfolders.vdf` 找遊戲路徑
   （別寫死磁碟代號 —— 這台機器就裝在 `E:\`）
2. 檢查 Steam 客戶端在跑
3. 檢查 `app.asar` 是視窗版（見下面的陷阱 2）
4. 清掉 `ELECTRON_RUN_AS_NODE`（見陷阱 1）後啟動
5. 輪詢 `http://127.0.0.1:9333/json/version` 直到通
6. 保留玩家原本的偏好（例如 `--force-device-scale-factor=1.5`）做成設定項

參考實作：`Desktop\Unlight\launch.py`。

---

## ⚠ 兩個會讓人誤判「直接執行不可行」的陷阱

排查時兩個都踩過，各浪費不少時間。症狀都是**「一開就立刻關掉」**，
但原因完全不同，而且都跟 Steam 無關。

### 陷阱 1：`ELECTRON_RUN_AS_NODE`

```
UNLIGHTRevive.exe: bad option: --remote-debugging-port=9333
exit code 9
```

**VS Code 的 extension host 會設 `ELECTRON_RUN_AS_NODE=1`**，子程序會繼承。
帶著它啟動任何 Electron app，那個 exe 就被當成**純 Node** 執行，於是
Chromium 的參數變成無法辨識的 Node 選項，直接 exit 9。

從 VS Code 終端機、或任何 VS Code 開的程序底下跑都會中。

> **這條對 Companion 自己也適用。** Companion 是 Electron app，它 spawn
> 出去的子程序若帶著 `ELECTRON_RUN_AS_NODE`，一樣會爆。所有 spawn 前都要
> `delete env.ELECTRON_RUN_AS_NODE`。

### 陷阱 2：生效的是網頁版 `app.asar`

`resources\app.asar` 有兩種，可以用同目錄的 `換名.bat` 互換：

| 版本   | 大小      | 行為                                                     |
| ------ | --------- | -------------------------------------------------------- |
| 視窗版 | 約 404 MB | 正常開視窗跑遊戲                                         |
| 網頁版 | 約 153 KB | 拿 Steam ticket → `openExternal` 開瀏覽器 → `app.quit()` |

**網頁版「一開就立刻關掉」是設計行為，不是失敗。** 它的工作就是把玩家
丟到瀏覽器然後自己退出，所以不會留下可以掛 CDP 的視窗。

插件啟動前一定要檢查檔案大小，發現是網頁版就明確告訴玩家，
不要讓人對著正常行為除錯。

> 我第一次測時剛好處於網頁版狀態，於是得出「直接執行必定 crash、
> Steam API 載不進來」的錯誤結論，還把它寫進了這份文件。
> 換回視窗版之後同一組測試立刻通過。

---

## 網頁版：插件啟動瀏覽器

Chrome、Edge、Brave 都是 Chromium，都吃 `--remote-debugging-port`。

```
chrome.exe --remote-debugging-port=1221 --user-data-dir=<插件自己的 profile>
```

### `--user-data-dir` 是必要的，不是選配

玩家已經開著 Chrome 時，用**同一個 profile** 再啟動一次，Chrome 只會在既有
實例開一個新分頁，**命令列參數整個被忽略**，debug port 不會開。

專屬 profile 才能保證是全新實例，也完全不影響玩家平常的瀏覽器
（兩邊可以同時開著）。

### 代價：要在那個 profile 裡登入一次

新 profile 沒有 cookie，玩家得在插件開的瀏覽器裡登入一次，之後會記住。
UI 要講清楚，不然會以為壞掉。

### 之後可以考慮：瀏覽器擴充功能

對純網頁版玩家，Chrome 擴充功能比 CDP 順 —— 不用另開瀏覽器、不用重新登入、
直接就有頁面存取權。但那是另一套發布管道，而且拿不到桌面端的東西
（系統匣、自動更新、本機規則檔）。**MVP 先不做。**

---

## 已經啟動的程序能不能補 debug port？

**不能。** DevTools 的 HTTP/WebSocket 伺服器是 Chromium 在**啟動過程中**
建立的，之後沒有任何 API、訊號或 IPC 可以叫它補開。

理論上還有一條路（Node 對執行中程序啟用 inspector，再從 Electron 主程序
摸到 `webContents`），**但不要走**：那是對別人的程序做注入，防毒與
SmartScreen 幾乎一定會有意見，各 Electron 版本行為又不一致。

---

## 「開遊戲時自動開插件」

### A. 插件的捷徑取代遊戲捷徑（推薦，零設定）

安裝時建立「UNLIGHT:Revive（含 ULR Companion）」捷徑。玩家點它 →
插件先起來 → 插件帶參數啟動遊戲 → 自動接上。

桌面版與網頁版都適用，完全不碰 Steam 設定。

### B. Steam 啟動選項用 `%command%` 包一層

給堅持要從 Steam 按「遊玩」的玩家。`%command%` 會被代換成遊戲真正的
命令列，可以用自己的執行檔把遊戲包起來：

```
"%LOCALAPPDATA%\Programs\ULR Companion\ULRCompanion.exe" %command% --remote-debugging-port=9333
```

代價：要玩家自己貼一次，而且**每個 Steam 帳號各設一份** ——
用小號玩時主帳號的設定不會生效。既然 A 方案可行，B 只是備案。

> ⚠ `%command%` 在 Windows 版 Steam 的行為尚未實測。

### C. 插件隨 Windows 開機常駐

`app.setLoginItemSettings({ openAtLogin: true })`，系統匣圖示一直在。
搭配 A 用，不是替代 —— 玩家若繞過插件直接開遊戲，port 還是沒開。

---

## 偵測與降級

1. 先探 `127.0.0.1:<port>/json/version`
2. 通 → 直接接上
3. 不通但遊戲已經在跑 → **不要偷偷殺掉玩家的遊戲**。說明要透過插件重新
   啟動才能接上，並提供按鈕，讓玩家自己決定何時關。
4. 遊戲沒在跑 → 直接帶參數啟動

第 3 點很重要：玩家可能正在打，插件不該替他做關閉的決定。

---

## 附註：本機雙開測試的限制

開發時想在同一台機器跑兩個客戶端互打，會遇到伺服器端的

> 錯誤：禁止多重啟動遊戲

這是**依 IP 判斷**的，兩個客戶端必須來自不同 IP。網頁版可以靠瀏覽器的
VPN 擴充功能，桌面版沒有等價做法。

不過**大部分工作不需要本機雙開**：

- 回合倒數量測 —— 單邊就夠
- 第一期的 3 秒反悔窗口 —— 本來就是單邊功能
- 只有「A 的動作會不會洩漏給 B」的驗證與第二期握手需要兩邊

那兩項用兩台裝置（其中一台用手機熱點）比在同一台上想辦法簡單得多。
