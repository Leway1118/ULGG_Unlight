# Raid Metadata Scanner 設計分析

## 結論

目前前端程式碼足以確認三個事件的請求參數，但不足以把所有接收事件斷言為伺服器端的 request/response 配對。

已確認的前端流程是：

```text
raid_get_support(this.id)
→ raid_support_list(rows)
→ raid_code_input(this.id, row.prf_code)
→ 帳號的 Raid 狀態可能改變
→ db_raid(this.id)
→ db_raid(rows)
→ db_raid_delete(this.id, selected.profound_id)
```

重要限制：這整條流程不是 read-only。

- `raid_code_input` 是參加指定 Raid 的動作。
- `db_raid_delete` 的前端確認訊息是放棄該 vortex，會移除帳號持有的 Raid。
- `db_raid` 本身是讀取，但前後兩個事件會改變遊戲狀態。

因此 Scanner 應維持在 `tools/`／research 範圍，不得接進 `run_raid_bot.py`、Discord notifier、formatter 或常駐 WebSocket source。

## 1. `raid_code_input` 協定

### 1.1 已確認請求參數

公開 Raid 清單點選加入時，前端送出：

```javascript
this.socket.emit("raid_code_input", this.id, s[t].prf_code)
```

手動輸入 Raid code 時，前端送出：

```javascript
this.socket.emit("raid_code_input", this.id, _.text)
```

所以參數格式已確認為：

```text
raid_code_input(account_scene_id, raid_code)
```

| 位置 | 前端來源 | 已確認意義 |
| --- | --- | --- |
| 第一參數 | `this.id` | Raid scene 從 Lobby scene 收到的同一個 `id` |
| 第二參數 | `row.prf_code` 或輸入框 `.text` | Raid code |

第一參數在前端只命名為 `id`。本報告不推測伺服器將它命名為 `player_id` 或 `owner_id`。

來源：[`scripts/import/har/js/755.3b4b47183730a0cef19f.js`](../../scripts/import/har/js/755.3b4b47183730a0cef19f.js)，壓縮檔第 1 行；搜尋 `raid_code_input`，約字元 260,161 與 294,477。

### 1.2 已確認的接收事件

同一個 Raid scene 註冊了失敗事件：

```javascript
this.socket.on("raid_code_error", (t) => {
  // t 被用作本地錯誤訊息陣列的索引
})
```

可確認：

- event 名稱是 `raid_code_error`。
- payload 至少有一個參數；前端把第一參數當錯誤碼／訊息索引。
- 錯誤內容包含無效 Raid code、Raid 過期、AP 不足、Raid 已變更或刪除等情況。

同一個 scene 也註冊：

```javascript
this.socket.on("raid_found", (title, mons, monsIndex, limit, raidMap) => {
  this.scene.launch("Raid_Title", {
    title,
    mons,
    monsIndex,
    limit,
    raid_map: raidMap,
  });
  this.socket.emit("db_raid", this.id);
})
```

可確認：

- 收到 `raid_found` 後，前端會立即刷新 `db_raid`。
- `raid_found` 有五個前端位置參數：title、mons、monsIndex、limit、raidMap。

不能只靠目前前端碼確認：

- `raid_found` 是否只由 `raid_code_input` 成功觸發。
- `raid_code_input` 是否另有 protocol ACK。
- `raid_found` 與某一次 `raid_code_input` 是否具有 request ID 關聯。

原因是 `emit("raid_code_input", ...)` 沒有 callback／await，listener 也是 scene 級長駐 listener；目前保存的 HAR 沒有包含可供配對的 `raid_code_input` transaction。

來源：同一個 755 bundle，第 1 行；搜尋 `on("raid_code_error"`（約字元 248,483）及 `on("raid_found"`（約字元 231,755）。

## 2. `db_raid` 協定

### 2.1 已確認請求與回傳

Raid scene 初次載入：

```javascript
this.raid_data = await this.socket.fetch("db_raid", this.id)
```

後續刷新：

```javascript
this.socket.emit("db_raid", this.id)
```

接收：

```javascript
this.socket.on("db_raid", (data) => {
  this.raid_data = data
})
```

因此已確認：

```text
request:  db_raid(this.id)
response/update event: db_raid(rows)
```

現有 [`db_raid_probe.py`](db_raid_probe.py) 已經實作僅允許 `register`、`db_raid` 與 protocol control event 的 read-only probe。Scanner 若進入實作階段，應重用它的 handshake、message envelope、timeout 和 schema inspection，不應重寫另一套 WebSocket protocol client。

### 2.2 前端已使用的 row 欄位

遊戲 bundle 與舊 userscript 已實際讀取下列欄位：

```text
profound_id
pass
profound_mons
profound_founder
name_tcn
map
stage
rarity
treasure_level
hp
hp_max
level
limit
points
state
```

舊 userscript 只監聽遊戲原本收到的 `db_raid`，並沒有證明獨立 VPS connection 一定能取得非空清單。

來源：

- [`tool/raid-bot/data/ulr_raid_info.js`](data/ulr_raid_info.js#L85)
- [`tool/raid-bot/research/detect_raid_dual_source.py`](research/detect_raid_dual_source.py#L63)
- 755 bundle 第 1 行，搜尋 `fetch("db_raid"` 與 `on("db_raid"`。

## 3. `db_raid_delete` 協定

### 3.1 已確認請求參數

刪除／放棄確認按鈕送出：

```javascript
this.socket.emit("db_raid_delete", this.id, this.raid_id)
```

`this.raid_id` 的來源是使用者在 `db_raid` 清單選取的 row：

```javascript
this.raid_id = this.raid_data[t].profound_id
```

所以參數格式已確認為：

```text
db_raid_delete(account_scene_id, profound_id)
```

明確不是：

```text
db_raid_delete(account_scene_id, prf_code)
```

來源：755 bundle 第 1 行；搜尋 `db_raid_delete`（約字元 219,505）及 `this.raid_id=this.raid_data[t].profound_id`（約字元 231,424）。

### 3.2 Response 現況

目前前端碼中沒有找到：

```javascript
socket.on("db_raid_delete", ...)
```

呼叫也沒有使用 `fetch`、callback 或 await。前端送出後立即：

```javascript
this.raid_idx = null
this.raid_id = null
this.raid_info_destroy()
```

因此只能確認它是 fire-and-forget UI flow，不能宣稱 response event 名稱。

未來 Scanner 要確認清理成功，應在送出 delete 後重新請求 `db_raid(this.id)`，並以同一個 `profound_id` 是否消失作為觀測結果。這是客戶端可驗證條件，不等同於宣稱 server ACK。

## 4. ID 關聯現況

目前只確認兩邊各自的用途：

| 資料 | 來源 | 用途 |
| --- | --- | --- |
| `prf_code` | `raid_support_list` row | `raid_code_input` 第二參數 |
| `profound_id` | `db_raid` row | UI 選取 ID及 `db_raid_delete` 第二參數 |
| `pass` | `db_raid` row | 舊 userscript 有讀取，但目前未確認語義 |

尚未確認：

```text
prf_code == pass
prf_code == profound_id
pass == profound_id
```

Scanner 的第一個研究成果應是保存同一筆操作的 before/after evidence，再判斷這三者的關係，不能先寫固定 mapping。

## 5. Isolated Scanner 設計

### 5.1 建議檔案邊界

若後續獲准實作，建議使用：

```text
tool/raid-bot/
├── run_raid_bot.py                         # 不修改
├── db_raid_probe.py                        # 重用 protocol／解析概念
├── tools/
│   ├── raid_metadata_scanner.py            # CLI 與 transaction orchestration
│   └── raid_metadata/
│       └── db_raid_collector.py            # db_raid snapshot／diff／correlation
└── data/
    └── raid_metadata.json                   # runtime research output，不提交
```

不建議新增 production `raid_bot/collectors`，因為 Scanner 需要發送會改變帳號狀態的事件，不能讓 production dependency graph 意外引用它。

Scanner 不得 import 或呼叫：

```text
raid_bot.notifications
raid_bot.formatter
raid_bot.services.event_dispatcher
raid_bot.services.raid_service
```

### 5.2 建議狀態機

```text
CONNECT
  → REGISTER(test_account_id)
  → SNAPSHOT_BEFORE: db_raid(test_account_id)
  → GET_SUPPORT: raid_get_support(test_account_id)
  → SELECT exactly one prf_code
  → REQUIRE explicit operator confirmation
  → SEND raid_code_input(test_account_id, prf_code)
  → WAIT for raid_code_error / raid_found / timeout
  → SNAPSHOT_AFTER: db_raid(test_account_id)
  → CORRELATE before IDs vs after IDs
  → CAPTURE one unambiguous new row
  → DELETE(test_account_id, new_row.profound_id)
  → VERIFY by another db_raid snapshot
  → STOP
```

關聯規則必須保守：

1. 操作前保存 `profound_id` set。
2. 操作後重新讀取 `db_raid`。
3. 只有 `after - before` 恰好一筆時，才允許把該 row 當成本次 candidate。
4. 若為零筆或多筆，停止自動清理；不得拿任意 row 或相同 Boss 名稱猜測。
5. delete 只可使用本次 transaction 確認的新 `profound_id`。
6. delete 後重新讀取，確認該 ID 已消失；否則記錄 cleanup 未確認並停止。

### 5.3 Outbound allowlist

Scanner 應以 deny-by-default 限制可發事件：

```text
允許 protocol control:
  __handshake_c
  __pong_s

允許讀取:
  register
  raid_get_support
  db_raid

僅在 --execute 且互動確認後允許:
  raid_code_input
  db_raid_delete
```

明確禁止至少包括：

```text
raid_turn
raid_ready
raid_code_send
db_raid_reward
任何 battle / attack / claim / reward event
```

`--dry-run` 應為預設，只列出將使用的 event 名稱及參數類型，不顯示完整帳號 ID 或 Raid code，也不送出 `raid_code_input`／`db_raid_delete`。

### 5.4 憑證與日誌

- 研究帳號 ID 只能由環境變數或互動輸入取得，不可硬編碼。
- 日誌不得輸出完整帳號 ID、webhook、session data 或完整 Raid code。
- `raid_metadata.json` 不應保存帳號 ID。
- 暫時性的 `prf_code`、`pass`、`profound_id` 關聯證據應放在獨立、gitignored 的 diagnostic output；完成關聯後輸出的靜態 metadata 應移除 live identifiers。
- 原始 `db_raid` payload 可能包含參與者與傷害資訊；預設只保存 allowlisted metadata 欄位，不保存完整 `points` 或其他私人診斷資料。

### 5.5 Metadata 輸出

第一階段應保存 observed sample，不應直接建立「Boss 唯一對應一種獎勵」的規則：

```json
{
  "schema_version": 1,
  "samples": [
    {
      "monster_code": "mcXXXX_XX",
      "boss_name": "observed name",
      "map": null,
      "stage": null,
      "rarity": null,
      "treasure_level": null,
      "observed_at": "ISO-8601 timestamp"
    }
  ]
}
```

在取得實際非空 row 前，不應固定欄位型別，也不應建立 fragment／reward mapping。

## 6. 未來測試設計

實作 Scanner 時，測試全部使用 fake transport，不連真實帳號：

1. `raid_code_input` message 必須是兩個參數：account scene ID、`prf_code`。
2. `db_raid_delete` message 必須是 account scene ID、`profound_id`。
3. dry-run 不得送出任何 mutation event。
4. 未指定 `--execute` 時 mutation allowlist 必須拒絕。
5. `raid_code_error` 後不得執行 delete。
6. before/after 沒有唯一新增 `profound_id` 時不得執行 delete。
7. 唯一新增 row 時只能刪除該 row 的 `profound_id`。
8. 禁止的 battle／reward event 即使由錯誤程式路徑呼叫也必須拋錯。
9. timeout 時正常結束並標記 outcome unknown，不進入戰鬥、不 claim、不猜測 cleanup target。
10. output 不得包含帳號 ID、完整 code、participant `points` 或敏感 payload。

## 7. 建議實作閘門

在開始寫 Scanner 前，仍需由真實測試帳號手動驗證並記錄一個完整封包序列：

```text
SEND raid_code_input
RECV success or error event
SEND db_raid
RECV non-empty db_raid
SEND db_raid_delete
SEND db_raid
RECV db_raid without the selected profound_id
```

最少需要確認：

- `raid_code_input` 成功時實際收到的第一個事件。
- 是否真的新增一筆 `db_raid` row。
- 新 row 的 `pass` 是否等於送入的 `prf_code`。
- `db_raid_delete` 後該 `profound_id` 是否消失。
- 參加動作是否消耗 AP 或有其他帳號副作用。

在這組 evidence 完成以前，只能建立 fake-transport scanner skeleton；不能宣稱自動加入、擷取、清理流程已驗證。

## 8. 本次未做事項

- 未修改 production Raid Bot。
- 未修改 WebSocket source、formatter、Discord notifier 或 Bonus Navigator。
- 未發送任何遊戲 WebSocket event。
- 未使用或保存任何真實帳號識別值。
- 未實作 reward、fragment、rarity mapping。
- 未 commit、未 push。
