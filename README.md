<p align="center">
  <img src="assets/banner.png" alt="UL.GG Unlight Analytics Banner" width="800">
</p>

# UL.GG — Unlight Analytics

**UL.GG** 是一套針對 **Unlight 對戰、排行榜、角色、牌組、Raid 與遊戲即時資料** 建立的社群分析平台。

正式網站：**https://ulgg.online/**

目前專案同時包含網站前後端、遊戲資料 Watcher、Raid Bot、本機 Card Tracker、排程工具與維運腳本。

---

## 主要功能

### 網站與玩家資料

- Steam 登入與 UL.GG 會員系統
- 玩家戰績與對戰紀錄查詢
- 對戰組合 / Team 分析
- 角色、卡片、COST 與使用率資料
- BP / QP 排行榜與歷史資料
- 公開 Raid / 渦資料觀察
- 後台 Dashboard 與資料狀態監控

### Watcher

`watcher/` 負責持續讀取與整理遊戲公開資料，包括：

- 房間 / Lobby 狀態
- BP / QP 排名資料
- JSON 資料更新與歷史保存
- TW 遊戲服務的共享認證 Session
- 將可用資料交給後續匯入與統計流程

Watcher 是目前 Raid Bot 的上游依賴之一。

### Raid Bot

`tool/raid-bot/` 負責公開 Raid 資料處理與 API：

- Raid 即時事件讀取
- Raid 狀態與獎勵解析
- 玩家 / Raid 關聯資料
- Discord 通知
- HTTP API / health endpoint
- WebSocket / DB 資料來源整合

正式環境中 Raid Bot API 僅綁定 localhost，由網站與本機服務使用。

### Unlight Card Tracker

`tool/unlight-card-tracker/` 為本機戰鬥輔助與研究工具，包含：

- 卡牌辨識
- 數字 / 圖示 / 場景辨識
- 對戰狀態追蹤
- Detector / Electron 客戶端
- 測試與辨識模板

部分工具主要於 Windows 環境執行，不代表所有內容都會部署到 VPS。

---

## 專案結構

```text
ULGG_Unlight/
├─ api/                         # 網站 API、認證、Cron endpoint
├─ assets/                      # CSS、JavaScript、圖片與前端資產
├─ database/                    # 資料庫相關檔案
├─ layout/                      # 共用頁面版型
├─ pages/                       # UL.GG 主要網站頁面
├─ scripts/                     # 排程、維運與資料處理工具
│  └─ ops/                      # VPS / Git 同步與 systemd 維運設定
├─ sql/                         # SQL migration / schema helper
├─ src/                         # 其他工具與資料處理程式
├─ tests/                       # 網站與工具測試
├─ tool/
│  ├─ raid-bot/                 # Raid Bot / API / Discord / Raid parser
│  └─ unlight-card-tracker/     # 本機 Card Tracker / Detector
├─ watcher/                     # Lobby / Ranking / Game data watcher
├─ composer.json
├─ composer.lock
├─ config.php
├─ .env.example
└─ README.md
```

---

## Production 技術棧

目前 UL.GG production 主要使用：

- **Nginx**
- **PHP 8.0 + PHP-FPM**
- **MariaDB 10.5**
- **Python 3.11**
- **Composer**
- **systemd**
- **Certbot / Let's Encrypt**
- **Cloudflare**
- **GitHub**

目前 production 運行於 EL9 系列 Linux 環境。

### Python

網站資料 Watcher 使用獨立 Python 3.11 virtual environment。

Raid Bot 使用自己的 Python 3.11 requirements：

```text
tool/raid-bot/requirements-raid-bot.txt
```

Card Tracker / Detector 的依賴請參考：

```text
tool/unlight-card-tracker/detector/requirements.txt
```

### PHP / Composer

PHP 套件由：

```text
composer.json
composer.lock
```

管理。

正式環境的 `vendor/` 不納入 Git server-sync，而是在部署時由 Composer 重建。

---

## Git 分支與 Server Sync

### `server-sync`

`server-sync` 是目前 UL.GG production source 的同步分支。

VPS 每日會執行一次安全同步：

```text
Production VPS
      ↓
source-only sync
      ↓
secret / runtime / large-file safety checks
      ↓
server-sync
```

同步流程只保存應進入版本控制的 source code。

以下內容不會自動提交：

- `.env`
- SSH private keys
- DB backup / dump
- runtime JSON / JSONL
- Watcher snapshot / backup
- Raid Bot runtime data
- cache / log
- Python virtual environment
- Composer `vendor/`
- 使用者上傳內容
- Debug / rollback copy
- 第三方大型 dependency tree

同步腳本：

```text
scripts/ops/ulgg-server-sync.sh
scripts/ops/server-sync.rsync-exclude
```

### `master`

`master` 保留作為整理後的主要開發分支。

Production 變更不會由排程直接寫入 `master`。

---

## 開發環境

複製 Repository：

```bash
git clone git@github.com:Leway1118/ULGG_Unlight.git
cd ULGG_Unlight
```

如需直接查看 production source snapshot：

```bash
git checkout server-sync
```

請依各子系統安裝對應依賴，不建議直接複製 production 的 virtual environment 或 `vendor/`。

---

## 環境變數與敏感資料

正式環境設定使用 `.env`，敏感資訊不應提交至公開 repository。

請勿提交：

- DB 密碼
- API Token
- Discord Webhook
- VAPID Private Key
- Cookie / Session
- SSH private key
- `.ppk` / `.pem` / `.key`
- production `.env`
- DB dump
- HAR / traffic capture
- runtime cache / log / snapshot

公開範例設定請使用：

```text
.env.example
tool/raid-bot/.env.example
```

---

## Production 服務

目前主要背景服務包括：

```text
unlight_watcher.service
unlight-raid-bot.service
unlight_tasks.timer
ulgg-git-sync.timer
certbot-renew.timer
```

其中 Watcher 會先建立 TW shared auth session，Raid Bot 再使用該 session 讀取相關資料。

部分歷史 / 實驗 service 可能保留 unit file，但不代表目前 production 會啟用。

---

## 資料與版本注意事項

2026-09-22 10:00（Asia/Taipei）後，Unlight Protocol 1.4 的排名資料格式與舊版不同。

UL.GG 對新舊資料採分段處理，避免將舊版與新版欄位直接混用。

---

## 專案狀態

UL.GG 持續開發中。

目前重點包含：

- Production / Git source 一致化
- Raid / Ranking 資料穩定性
- Watcher 與自動化維運
- 網站統計與分析功能
- VPS 架構整理與遷移

---

## 使用聲明

本專案供 **Unlight 社群研究、資料分析與工具開發** 使用。

遊戲名稱、角色、卡片、圖片及相關內容之權利屬於其原權利人。

本專案與原遊戲營運方不存在官方合作或授權關係。

請勿將本專案用於破壞遊戲服務、繞過安全機制或其他違反遊戲使用規範的用途。
