# 開發指南

## 環境

Node.js 20.11 以上。`npm install` 之後 `npm run verify` 要全綠。

```bash
npm run verify        # format:check + lint + typecheck + test（CI 跑的就是這個）
npm run test:watch    # 開發時
npm run format        # 自動排版
```

## 認領工作

工作包定義在規格書附錄 B，狀態看 [README](README.md#現在的狀態)。
開 issue 用 **Work Package** 範本，講清楚你要動哪個 WP。

**開工前先讀 [docs/open-questions.md](docs/open-questions.md)。** 裡面有兩題
（角色 ID 格式、壓 C 的「差距」定義）目前擋著 WP-02，沒定案就寫不下去。

## 分支與 PR

- 從 `main` 開分支，命名 `wp02/cost-engine`、`fix/hash-edge-case` 這種
- PR 標題寫清楚做了什麼，內文說明「為什麼」
- CI 沒過的 PR 不會被 review

## 這個專案的硬規則

以下任何一條被違反，PR 不會合併。理由都在規格書 §12。

### 1. 不碰隱藏資訊

即使客戶端收得到，也不得顯示或上傳對手未揭露的手牌、牌組或任何隱藏資訊。
正式版只顯示「一般玩家在正常介面已經可以知道」的東西。

### 2. 不記錄、不上傳憑證

Steam Token、Cookie、登入憑證、完整 CDP URL、原始 WebSocket 封包 —— 一律不准
進 log、不准進遙測、不准進 repo。

> 送 fixture 進 repo 之前務必去識別化。遊戲封包的 `args[0]` 常常就是那串
> 36 碼 session token。`.gitignore` 已經擋掉 `*.log` 與 `battle/`、`deck/`、
> `bonus/` 目錄，但那是最後一道防線不是唯一一道。

### 3. 遠端資料不得變成可執行邏輯

ULGG 或任何遠端只能下發「符合 Schema 的宣告式資料」。不得有任何路徑讓它
變成 `eval`、`new Function`、動態 `import` 或注入遊戲的 DOM 腳本。
ESLint 已經擋掉 `eval` 系列，但真正要守的是設計：
**規則內容只能驅動既有的、白名單化的計算分支。**

這就是為什麼 `compressionRule.type` 是列舉而不是字串、
`restrictions[].condition` 永遠不被解析。

### 4. 不繞過官方戰鬥規則

不修改伺服器端判定。自訂 COST 是自我約束的規則，只改自己客戶端的顯示。

### 5. 注入失敗要降級，不能讓遊戲崩潰

UI 注入是對非公開 DOM／Phaser 結構的耦合，遊戲改版一定會壞。
找不到目標節點時，要退回 Companion 視窗顯示結果並留下可匯出的診斷，
不得讓遊戲當掉，也不得靜默產生錯誤資料（§9.1）。

## 改到 WP-01 的話請特別注意

`packages/rule-schema/test-vectors/canonical.json` 是**跨語言的共同真相**。

它一旦變動，所有已發布規則的 `contentHash` 就全部失效 —— 那是破壞性變更。
`test/vectors.test.ts` 會鎖住這個檔案，要讓測試通過你得跑
`npm run vectors:generate` 並把 diff 一起送審。

**如果你的 PR 改動了向量檔，請在 PR 內文說明為什麼這個破壞性變更是必要的，
以及已發布規則要怎麼遷移。** 只是重構的話，向量檔不該有任何 diff。
