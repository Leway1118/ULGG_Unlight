# Auto My-Hand Sync 真實 PVE 驗收

## 驗收範圍

本次只驗證：

```text
hand.cards_dealt
→ canonical unique match
→ remaining 批次扣除
→ myHand 批次增加
→ processed event ledger
→ grouped history
```

本次不驗證自動出牌、敵方公開牌、事件卡或場地自動偵測。

## 測試前準備

1. 使用最新版 one-folder Launcher：

   ```text
   tool\unlight-card-tracker\detector\dist\
   UnlightTrackerLauncher\UnlightTrackerLauncher.exe
   ```

2. 開啟 `http://127.0.0.1:8765/tracker/`。
3. 按 `Ctrl+F5`，避免瀏覽器沿用舊版 `tracker.js`。
4. 在 Console 確認：

   ```js
   ({
     TrackerAutoCardSync:
       typeof window.TrackerAutoCardSync,
     TrackerAutoSyncTarget:
       typeof window.TrackerAutoSyncTarget,
     observationPoller:
       typeof window.observationPoller,
   })
   ```

   三個值都必須是 `"object"`。

5. 保存 Launcher 顯示的完整 `TRACKER_SESSION_ID`，並確認它與：

   ```text
   GET /api/v1/sessions/active
   ```

   回傳的 `session_id` 完全一致。

## A. 開戰前基準

在進入全新 PVE 前記錄：

```text
remaining_total =
myHand_total =
autoReserved_total =
processed_event_count =
```

同時保存：

- localStorage 的 `ul_public_deck_tracker_v3`
- Tracker 畫面
- active `session_id`
- 目前場地 key

若目前 Tracker 還保留上一場狀態，先使用既有 Reset 並確認：

```text
myHand_total = 0
autoReserved_total = 0
processed_event_count = 0
```

## B. 第一回合收到 N 張公牌

找到第一筆新的 `hand.cards_dealt`，記錄：

```text
session_id =
event_id =
sequence =
N = 可 canonical unique match 的一般公牌數量
```

套用後必須符合：

```text
remaining_total = 開戰前 remaining_total - N
myHand_total = 開戰前 myHand_total + N
autoReserved_total = 開戰前 autoReserved_total
processed_event_count = 開戰前 count + 1
```

另外確認：

- 重複 canonical card 依實際張數累加。
- history 只增加一筆 grouped action。
- history 顯示「自動加入我方手牌」。
- 任一卡為 ambiguous、no-match 或庫存不足時，整批 fail closed。

## C. 第二回合收到 M 張公牌

同一個 battle 中找到第二筆不同 `event_id` 的 `hand.cards_dealt`：

```text
event_id =
sequence =
M = 可 canonical unique match 的一般公牌數量
```

套用後必須符合：

```text
remaining_total = 第一回合後 remaining_total - M
myHand_total = 第一回合後 myHand_total + M
autoReserved_total = 第一回合後 autoReserved_total
processed_event_count = 第一回合後 count + 1
```

不得因 `battle_started_at` 相同而忽略第二筆合法事件。

## D. 點擊一張我方手牌

記錄被點擊的 canonical card key 與操作前數量。

點擊後必須符合：

```text
該卡 myHand count = 操作前 - 1
remaining count = 操作前數值，不變
```

Undo 後：

```text
該卡 myHand count = 點擊前數值
remaining count = 仍不變
```

## E. Reload 去重

Reload 前記錄：

```text
remaining_total =
myHand_total =
autoReserved_total =
processed_event_count =
```

重新整理並等待 observation poller 完成後，四個值都必須不變：

- remaining 不重扣。
- myHand 不重加。
- autoReserved 不增加。
- 相同 `session_id + event_id` 不新增第二筆 ledger。

## F. Reset 與下一場

1. 使用既有 Reset。
2. 確認：

   ```text
   myHand_total = 0
   autoReserved_total = 0
   processed_event_count = 0
   remaining_total = initial_total
   ```

3. 開始下一場新的 PVE。
4. 確認新的 `hand.cards_dealt` 可正常套用：

   ```text
   remaining - 新場 N
   myHand + 新場 N
   autoReserved 不變
   ledger + 1
   ```

舊場 myHand、battle identity 或 processed-event ledger 不得污染新場。

## 必須保存的證據

- 完整 Launcher `TRACKER_SESSION_ID`
- `/api/v1/sessions/active` 的相同 UUID
- 每筆 dealt 的 `session_id`、`event_id`、`sequence`
- 每批 N／M 的 canonical mapping 清單
- A–F 每一步的四項數值
- localStorage JSON
- 第一回合、第二回合、點擊、Reload、Reset 與下一場畫面
- Console globals 結果
- Network 中 `/tracker/`、`tracker.js` 與
  `tracker-auto-card-sync.js` 的 HTTP 200

## 通過條件

只有 A–F 全部通過，且沒有 silent reset、重複套用、部分批次套用或 autoReserved 新增，才可判定 Auto My-Hand Sync 通過真實 PVE 驗收。
