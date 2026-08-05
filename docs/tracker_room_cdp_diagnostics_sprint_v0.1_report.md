# Tracker ROOM/CDP Diagnostics Sprint v0.1 Report

## 1. 實作摘要

本 Sprint 在既有 localhost Tracker API 上新增 ROOM observation 與 CDP diagnostics 的獨立診斷資料流，包含：

- producer-specific runtime credential 與 loopback-only mutation authorization
- ROOM complete snapshot、reset、current/history persistence
- CDP atomic partial patch、explicit null clearing、restart normalization
- 依 `server_received_at` 推導的 freshness
- ROOM/CDP read APIs 與 diagnostics aggregate API
- Tracker 診斷 UI 的 ROOM/CDP 狀態顯示
- 不需真實 CDP 的 CLI presets
- temporary SQLite、FastAPI TestClient、JavaScript 與 frozen EXE smoke tests

ROOM/CDP 資料沒有接入既有 session authority、`gameStart`、`result`、卡牌事件、Stage authority 或 browser IndexedDB projection；`/health` 仍維持原本的小型 liveness response。

## 2. 修改檔案、函式與約略行號

### Production

| 檔案 | 約略行號 | 內容 |
|---|---:|---|
| `tool/unlight-card-tracker/detector/diagnostics_models.py` | 1–292 | ROOM/CDP enums、Pydantic request models、COST final-compliance 驗證、敏感 error detail 防護、freshness 推導 |
| `tool/unlight-card-tracker/detector/runtime_credentials.py` | 1–158 | API-owned producer credentials、runtime directory 與 private token file 交付 |
| `tool/unlight-card-tracker/detector/event_store.py` | 141–319、355–623 | schema v4 migration、四張 diagnostics tables、ROOM replace/reset、CDP merge/restart normalization、history retention |
| `tool/unlight-card-tracker/detector/server.py` | 129–426 | producer authorization、ROOM/CDP mutation/read routes、diagnostics aggregate |
| `tool/unlight-card-tracker/detector/tracker_api_server.py` | 87–253 | `--runtime-directory`、credential 產生與寫入、app injection |
| `tool/unlight-card-tracker/detector/tracker_launcher.py` | 278–307、562–608 | reuse probe 同時確認 diagnostics capability；child 只取得 runtime directory，不取得 token 值 |
| `tool/unlight-card-tracker/tracker-api.js` | 206–221、298 | read-only `getDiagnosticsStatus()` |
| `tool/unlight-card-tracker/observation-poller.js` | 39–58、427–441、632–706 | diagnostics 獨立 polling/state/rendering；無 active Tracker session 時仍顯示 |
| `tool/unlight-card-tracker/control.html` | 696–728、927–1162 | 原診斷 details 內新增 ROOM/CDP 欄位與 RWD-safe wrapping |
| `tool/unlight-card-tracker/detector/tools/_diagnostic_cli.py` | 1–65 | localhost origin、token file 與 HTTP 共用工具 |
| `tool/unlight-card-tracker/detector/tools/post_room_observation.py` | 1–112 | ROOM normal/error/reset presets |
| `tool/unlight-card-tracker/detector/tools/patch_cdp_status.py` | 1–128 | CDP disconnected/connected/context/injected/applied/partial/failed presets |

### Tests

| 檔案 | 約略行號 | 內容 |
|---|---:|---|
| `detector/tests/test_room_cdp_diagnostics.py` | 1–557 | authorization、validation、freshness、atomicity、history、retention、restart、migration、token tests |
| `detector/tests/test_event_store.py` | schema assertion | 預期 schema version 更新為 4 |
| `detector/tests/test_tracker_api_server.py` | schema assertion | 啟動後 schema version 更新為 4 |
| `detector/tests/test_tracker_launcher.py` | diagnostics/reuse/runtime tests | 舊 v1 API 不重用、runtime directory 不洩漏 token |
| `detector/tests/test_static_tracker.py` | diagnostics DOM assertions | ROOM/CDP UI contract |
| `detector/tests/js/test_tracker_api.js` | diagnostics API tests | read-only aggregate client |
| `detector/tests/js/test_observation_poller.js` | diagnostics polling/render tests | no-session diagnostics 與錯誤隔離 |
| `detector/tests/launcher_exe_smoke.py` | 151–339 | dynamic ports、isolated LOCALAPPDATA、exact UUID、aggregate API、SQLite close、process/port cleanup |

## 3. SQLite Migration

SQLite schema version 由 3 升至 4；支援由 schema 1、2、3 逐步初始化至 4，沒有破壞既有 `sessions`、`events` 或 projection 資料。

新增：

- `room_observation_current`
- `room_observation_history`
- `cdp_diagnostic_current`
- `cdp_diagnostic_history`

current 與 history 寫入位於同一 transaction。ROOM heartbeat 與 CDP 無實質狀態變更不寫 history；ROOM history 最多保留 500 筆，CDP history最多保留 1000 筆。Tracker API restart 時，先前非 `DISCONNECTED` 的 CDP current 會正規化為 `DISCONNECTED` 並留下 history，避免重啟後顯示假連線。

本 Sprint 沒有修改 MySQL、`arena_unlight` 或建立任何網站 migration。

## 4. API Endpoints

| Method | Path | 權限／用途 |
|---|---|---|
| GET | `/api/v1/room/observation` | localhost read，取得 current 與 freshness |
| PUT | `/api/v1/room/observation` | `room-observer` token，完整替換 snapshot |
| DELETE | `/api/v1/room/observation` | `room-observer` token，清除 current 並留 reset history |
| GET | `/api/v1/cdp/status` | localhost read，取得 current 與 freshness |
| PATCH | `/api/v1/cdp/status` | `cdp-adapter` token，atomic partial update |
| GET | `/api/v1/diagnostics/status` | localhost read，聚合 ROOM/CDP |

Mutation 同時要求 loopback client 與 endpoint-specific Bearer credential。`producer_id` 由 Server credential 反查，不接受 request body 自稱來源。錯誤沿用既有：

`{"error":{"code":"...","message":"...","details":{...}}}`

`/health` 未加入 diagnostics aggregation，也沒有 API v2 envelope 重構。

## 5. Runtime Token 交付方式

Tracker API process 是 token authority。啟動時在 runtime directory 寫入：

- `runtime.json`：非敏感 metadata
- `room_observer.token`：只允許 ROOM write/delete
- `cdp_adapter.token`：只允許 CDP patch

預設目錄為 `%LOCALAPPDATA%\ULGGTracker\runtime`；測試與隔離執行可透過 `--runtime-directory` 指定。Launcher child environment 只傳 `ULGG_TRACKER_RUNTIME_DIR`，不傳實際 token，也不在 console、HTTP read API 或 UI 顯示 token。檔案以 user-private mode 建立並在 Windows 上 best-effort 設定權限。

Launcher 重用現有 API 前會同時探測 `/health` 與 `/api/v1/diagnostics/status`；因此不會把缺少本 Sprint capability 的舊 v1 API 誤判為可重用。若重用相容 API，credential authority 仍屬該 API，Launcher 不另造 token。

## 6. UI 修改

既有 `<details class="tracker-diagnostics">` 保留且預設收合；內部新增：

- ROOM：observation status、freshness、room lifecycle、game room ID、名稱、region、players、Ruleset identity、received/client time、age、error
- CDP：connection、freshness、context、target/context ID、injection、Ruleset apply、version/hash、format/COST counters、received/client time、age、error

Browser 僅呼叫無 mutation credential 的 aggregate GET。Diagnostics polling 與 Tracker active session polling 分離，因此沒有 active session 時 ROOM/CDP 仍可顯示；diagnostics request error 也不改變既有 Tracker session/persistence state。

## 7. CLI 工具

兩個工具都只接受 `http://127.0.0.1` 或 `http://localhost` origin，從 runtime token file 讀 credential，不輸出 token，HTTP 失敗回傳非零：

- `tools/post_room_observation.py`
- `tools/patch_cdp_status.py`

已驗證兩者 `--help` 可直接執行，且非 loopback origin 會被拒絕。

## 8. 測試與結果

### Python

- Affected suite：85/85 passed
- Launcher/diagnostics correction suite：37/37 passed
- Full suite：457 passed，1 skipped，0 failed
- `py_compile`：production、CLI、new diagnostics tests、EXE smoke script 全部通過

跳過項目是既有、依本機 optional corpus 條件決定的測試；不是失敗，也不是本 Sprint 新增 skip。

### JavaScript

- `test_tracker_api.js` + `test_observation_poller.js`：27/27 passed
- 不含 dashboard contract 的完整 JS：106/106 passed
- `control.html` dashboard contract：4/4 passed
- Repository 全部 JS：112/114 passed

全套中兩個失敗都只針對既有、未納入本 Sprint 的 `tool/unlight-card-tracker/index.php`：它仍使用舊的 `Tracker 詳細診斷` 摘要與舊 dashboard contract。該檔案在目前 worktree 為無關範圍，未為了湊測試結果而修改。

### CLI

- `post_room_observation.py --help`：passed
- `patch_cdp_status.py --help`：passed
- non-loopback API origin rejection：passed

## 9. Regression 與 Frozen EXE 結果

正式 `detector/dist/UnlightTrackerLauncher/` 當時被既有使用中 Launcher 鎖定；官方 build script 在刪除舊輸出前即停止，沒有覆蓋該版本。最終來源改用同一 `UnlightTrackerLauncher.spec` 在全新 `%TEMP%` 目錄隔離重建：

- Python：3.11.9
- PyInstaller：6.21.0
- Build：passed
- Validation EXE：`C:\Users\UVE\AppData\Local\Temp\unlight-room-cdp-final-21165962f04a48b1987d70ac2884f435\dist\UnlightTrackerLauncher\UnlightTrackerLauncher.exe`
- EXE size：6,896,565 bytes
- Dist size：38,404,829 bytes（198 files）
- SHA-256：`304AB414432DD710FFE5D9E33779929E6C5FFA19622C17EB18CF6ADF8A6C2850`
- Runtime/forbidden packaged files：0

Frozen EXE smoke：

- exact session ID：`f7f8ef85-3b4a-4308-89ea-55cde214eb3f`
- Tracker HTTP：200
- session final status：`completed`
- API/CDP/fake WebSocket dynamic ports：55168 / 55169 / 55170
- diagnostics aggregate：200
- smoke 後 validation processes：0
- smoke 後 validation listeners：0

這是 fake CDP／local test server smoke，不代表已在真實 Steam 遊戲環境驗證。

## 10. 安全與權威邊界確認

- ROOM/CDP tables 與既有 Tracker session/event tables 分離。
- ROOM reset 不啟動、結束或切換 Tracker session。
- CDP update 不產生 `gameStart`、`result`、卡牌或 Stage event。
- Browser 沒有 writer token。
- Writer 不能提交 `ulgg_room_id`、user ID、participant ID 等保留 identity。
- Diagnostics error details 遞迴拒絕 token/secret/auth/cookie/password/API key 類欄位。
- Task source 掃描未發現本機絕對路徑或 literal credential。
- Production API 維持 localhost-only。

## 11. 已知限制

1. v0.1 明確不包含 production CDP integration；CLI presets 用於行為與錯誤路徑驗證。
2. ROOM/CDP 是 observation/evidence，不是 ULGG Match、Ruleset legality、winner、arena mapping 或 BP authority。
3. Windows token file ACL 採 best-effort user-private file mode；正式安裝器若建立跨使用者目錄，仍應做實機 ACL 稽核。
4. Runtime token files由 API 啟動時覆寫，但本版沒有 token rotation/revocation protocol。
5. 正式 `detector/dist` 未覆蓋，因當時有使用中的舊 Launcher；交付 build 仍需在無鎖定的乾淨環境重跑正式 build script。
6. Repository 的既有 `index.php` dashboard contract 造成兩個無關 JS failures，未納入本 Sprint 修改。
7. 尚未進行真實 Steam Electron/CDP 9333、實際 ROOM writer、實際 CDP adapter 的端到端驗證。

## 12. 手動驗證步驟

1. 關閉舊 Launcher 後，以正式 build script 重新建立 one-folder EXE。
2. 啟動 Launcher，確認 API 只監聽 `127.0.0.1:8765`。
3. 確認 `%LOCALAPPDATA%\ULGGTracker\runtime` 有 metadata 與兩個分離 token files，UI/API response/console 均沒有 token。
4. 以 ROOM CLI 發送 waiting/playing/error snapshot，確認 diagnostics UI 與 GET response 更新。
5. 發送 ROOM reset，確認 current 清除，但 active Tracker session 完全不受影響。
6. 以 CDP CLI 依序發送 connected/context-found/injected/applied/partial/failed，確認四個狀態軸與 final-compliance counters。
7. 等待 45/120 秒，確認 CDP freshness 分別變為 stale/expired；ROOM 依 30/120 秒門檻變化。
8. 重啟 API，確認原 live CDP connection 顯示 `DISCONNECTED`，history 有 restart normalization。
9. 在真實遊戲環境確認 `gameStart`/`result` 仍是唯一 session start/finish authority，ROOM/CDP 更新不產生任何 battle event。
10. 關閉 Launcher，確認只結束 Launcher 自有 Sniffer/API，外部相容 API 不被停止，8765 與測試埠無殘留。

## 13. 最終 Git 狀態

本 Sprint 新增：

- `detector/diagnostics_models.py`
- `detector/runtime_credentials.py`
- `detector/tools/_diagnostic_cli.py`
- `detector/tools/post_room_observation.py`
- `detector/tools/patch_cdp_status.py`
- `detector/tests/test_room_cdp_diagnostics.py`
- `docs/tracker_room_cdp_diagnostics_sprint_v0.1_report.md`

本 Sprint 修改：

- `control.html`
- `tracker-api.js`
- `observation-poller.js`
- `detector/event_store.py`
- `detector/server.py`
- `detector/tracker_api_server.py`
- `detector/tracker_launcher.py`
- `detector/tests/test_event_store.py`
- `detector/tests/test_tracker_api_server.py`
- `detector/tests/test_static_tracker.py`
- `detector/tests/test_tracker_launcher.py`
- `detector/tests/js/test_tracker_api.js`
- `detector/tests/js/test_observation_poller.js`
- `detector/tests/launcher_exe_smoke.py`

整個 repository 在本 Sprint 前已有大量 staged、unstaged、deleted 與 untracked 內容；最終全域 `git status --short` 為 340 entries。部分本 Sprint 範圍檔案顯示 `MM`，代表它們在本次開始前已有 staged 內容，另有本 Sprint 的 unstaged 修改；本 Sprint 沒有執行 `git add`、commit、push、restore、reset 或 clean，也沒有整理無關 dirty files。

Scoped tracked `git diff --check`：passed（只有既有 line-ending conversion warnings，沒有 whitespace error）。
