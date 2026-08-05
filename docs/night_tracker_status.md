# Night Tracker Sprint 狀態摘要

日期：2026-07-29
範圍：Auto Card Sync v0.1 提交前／真實驗收前稽核
結論：程式測試可通過，但目前工作樹不是可直接發布或建立單一乾淨 commit 的狀態。

## Git 基準

- 目前 HEAD：`2979ece9e3192e37695a8c2eab6b53a058bb3602`
- 目前 commit：`2979ece chore(config): add safe application bootstrap`
- 分支：`master`
- Launcher 基準 `6e9c0c7` 存在，且是目前 HEAD 的 ancestor。
- `origin/master` 目前顯示在 `e9f662f`；本次 Sprint 未 fetch、pull、push、commit 或變更分支。

## 工作樹狀態

以 `git status --porcelain=v1 --untracked-files=all` 計算：

- status entries：8,952
- tracked changes：45
- untracked files：8,907
- staged entries：10
- unstaged tracked entries：35

這些變更並非都由 Auto Card Sync 產生。工作樹包含已暫存的網站資料／PHP helper、既有刪除、Steam scripts、完整網站資源、Detector 實驗檔、模板、備份、舊版本目錄及大量未追蹤資產。

目前已暫存但不屬於 Auto Card Sync 的 10 個 entries：

- `data/charaProfile.json`
- `includes/fortune_character_insight.php`
- `lib/_analysis_base.php`
- `lib/_skill_helper.php`
- `lib/_skill_render.php`
- `lib/checkpoint_data.php`
- `lib/discord_webhook.php`
- `lib/mail_helper.php`
- `lib/stage_map.php`
- `lib/visitor_logger.php`

## Auto Card Sync v0.1 變更邊界

功能直接涉及：

- `tool/unlight-card-tracker/tracker-auto-card-sync.js`（未追蹤，新 adapter）
- `tool/unlight-card-tracker/tracker.js`（remaining、autoReserved、dedupe ledger、Undo 與手動加入橋接）
- `tool/unlight-card-tracker/observation-poller.js`（projection commit 後 callback）
- `tool/unlight-card-tracker/control.html`（載入 Auto Sync script）
- `tool/unlight-card-tracker/detector/tests/js/test_tracker_auto_card_sync.js`（未追蹤，adapter／UI state 測試）
- `tool/unlight-card-tracker/detector/tests/js/test_observation_poller.js`（commit callback 測試）

需特別處理、不能直接視為單純 Auto Sync 檔案：

- `tool/unlight-card-tracker/index.php` 是整份未追蹤的 UL.GG PHP 整合頁；它包含 Auto Sync script 引用，但其大部分內容屬於先前的網站整合。
- `observation-poller.js` 與其測試的 diff 同時包含既有 projection/checkpoint 工作，不能只憑整檔名稱認定全部屬於 Auto Sync。
- `tracker.js` diff 很大；建立 release commit 前仍需依功能 hunk 精確複核。

本次 scoped `git diff --check` 沒有 whitespace error；Git 只提示這些工作副本未來可能由 LF 轉成 CRLF。

## 不應納入 Auto Card Sync release

除上列精確功能邊界外，其他 dirty content 均不得混入。尤其排除：

- 已暫存的網站資料與 `lib/` PHP helpers。
- 根目錄既有刪除與所有無關網站、Steam、VPS、watcher 變更。
- `tool/unlight-card-tracker-v1/`、`tool/unlight-card-tracker-v2-starter/`、`control_v1.html`。
- Detector、Sniffer、Launcher、API、SQLite、site detector、影像辨識與 Python 測試變更。
- `assets/event/`、`assets/sites/tw/`、`detector/templates/` 等大型資產。
- `build/`、`dist/`、`deck/`、`debug_frames/`、logs、JSONL、SQLite、`__pycache__`、`.venv` 等建置或執行資料。
- backup/copy/API-version 檔案。
- 今晚新增的四份 `docs/night_tracker_*.md` 應作為驗收證據獨立處理，不應未經決定便混入產品程式 commit。

## 測試紀錄

### JavaScript syntax

執行：

```powershell
node --check tracker.js
node --check tracker-auto-card-sync.js
node --check observation-poller.js
```

結果：3/3 通過，0 error。

### JavaScript tests

執行：

```powershell
node --test detector/tests/js/*.js
```

結果：

- tests：48
- pass：48
- fail：0
- skipped：0
- cancelled：0
- todo：0
- duration：224.4817 ms

### Python regression（Guardian 額外檢查）

執行：

```powershell
$env:PYTHONPATH='C:\Users\UVE\AppData\Local\Temp\unlight-api-test-deps'
& 'C:\Users\UVE\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe' -m unittest discover -s tests -q
```

最終結果：

- tests：400
- pass：399
- fail：0
- error：0
- skipped：1
- duration：16.024 s

首次 sandbox 執行因無權讀取既有暫存 dependency directory 而產生 11 個 import errors；使用完全相同的測試命令、允許唯讀載入依賴後重跑即通過。這不是產品測試斷言失敗。

## 額外 catalog 稽核

對目前 `FIELD_DECKS` 執行只讀 inventory：

- 場地：10
- 全域 canonical card keys：34
- 格式不合法 keys：0
- 同一場地內 forward/reverse 同時存在造成的 ambiguity：0

這只證明目前靜態 catalog 內部一致，不能替代真實 `hand.cards_dealt` corpus 與正確場地來源驗收。

## 本次未驗證

- 未連接真實遊戲 server、Electron CDP 或實際 Room。
- 未執行真實 Launcher lifecycle。
- 未在實際瀏覽器驗證 IndexedDB、localStorage、reload、右鍵與 UI。
- 未取得真實八張初始手牌的 canonical mapping 結果。
- 未驗證跨下一場 battle／Sniffer session transition。
- 未 commit、未 push、未 stage。
