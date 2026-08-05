# Auto Card Sync v0.1 真實 PVE 驗收

## 驗收範圍

本次只驗證：

```text
hand.cards_dealt
→ canonical card mapping
→ remaining 批次扣除
→ autoReserved 增加
```

不驗證自動加入 `myHand`、抽牌、出牌、敵方牌、事件卡或自動場地切換。

## 測試前準備

1. 若舊 Launcher 仍在執行，回到其終端按 `Ctrl+C` 正常關閉。
2. 確認沒有舊的 Tracker API 占用 `127.0.0.1:8765`。
3. 保持 UNLIGHTRevive 執行，並確認 Electron CDP 使用 `127.0.0.1:9333`。
4. 啟動新版：

   ```text
   tool\unlight-card-tracker\detector\dist\UnlightTrackerLauncher\UnlightTrackerLauncher.exe
   ```

5. 等待 Launcher 顯示完整的 `TRACKER_SESSION_ID=<UUID>`，保存該 UUID。
6. 開啟 `http://127.0.0.1:8765/tracker/`，按 `Ctrl+F5` 強制重新載入 packaged assets。

## Script 與 global 驗證

在 Tracker 頁面的瀏覽器 Console 執行：

```js
[...document.scripts].map((script) => script.src)
```

結果必須包含：

```text
/tracker/tracker-auto-card-sync.js
```

再執行：

```js
({
  TrackerAutoCardSync: typeof window.TrackerAutoCardSync,
  TrackerAutoSyncTarget: typeof window.TrackerAutoSyncTarget,
  observationPoller: typeof window.observationPoller,
})
```

三者都必須是 `"object"`。若任一為 `undefined`，停止本場驗收並保存 Console 與 Network 證據。

## 新 PVE 驗收

1. 使用全新的 PVE 對戰，不以舊 IndexedDB event 或舊 battle 當作本次證據。
2. 進入對戰後，保存本次新的：

   - `session_id`
   - `hand.cards_dealt` 的 `event_id`
   - `sequence`

3. 確認 `hand.cards_dealt` payload 的卡片總數，並分開計算：

   - 可映射至目前場地 `FIELD_DECKS` 的一般公牌數量
   - 個人事件卡或本版不處理的卡片數量

4. 在 Auto Sync 套用前後各保存一次 localStorage 與畫面，精確比較：

   - `state.remaining` 總數
   - `state.autoReserved` 總數
   - `state.myHand` 總數
   - `state.autoSync` ledger

5. 通過條件：

   ```text
   remaining 減少量
   = 成功 canonical unique match 的一般公牌數量

   autoReserved 增加量
   = remaining 減少量

   myHand 增加量
   = 0
   ```

6. 任一張卡若為 `ambiguous_match`、`no_match` 或 `invalid_card`，必須 fail closed，不得扣除；保存其 card payload 與 diagnostics。
7. 若庫存不足，整批應拒絕，不得只扣其中一部分。

## Reload 去重驗證

1. 記錄 reload 前的 `remaining`、`autoReserved` 與 auto-sync ledger。
2. 重新整理 Tracker 頁面。
3. 等待 observation poller 恢復並完成一次輪詢。
4. 確認相同的 `session_id + event_id` 沒有再次扣牌：

   - `remaining` 不再減少
   - `autoReserved` 不再增加
   - ledger 沒有同一事件的第二筆套用

## 手動右鍵 reservation 驗證

1. 從 `autoReserved` 選擇一張數量大於零的卡。
2. 記錄操作前該卡的：

   - `remaining`
   - `autoReserved`
   - `myHand`

3. 在 Tracker 對同一張牌執行既有右鍵加入我方手牌。
4. 通過條件：

   ```text
   remaining：不變
   autoReserved：減少 1
   myHand：增加 1
   ```

5. 確認既有 Undo 仍可使用，並保存 Undo 前後狀態；本次不藉此擴充或改變 Undo 契約。

## 必須保存的證據

- Launcher 終端中的完整 `TRACKER_SESSION_ID`
- `/api/v1/sessions/active` 回傳的相同 `session_id`
- `hand.cards_dealt` 的 `event_id`、`sequence` 與一般公牌數量
- Auto Sync 前後的 Tracker 畫面
- reload 前後的 Tracker 畫面
- 右鍵 reservation 前後的 Tracker 畫面
- 對應 localStorage JSON 匯出
- Console globals 驗證結果
- Network 中以下資源的 HTTP 200：

  ```text
  /tracker/
  /tracker/tracker-auto-card-sync.js
  ```

## 結果判定

只有在 remaining 精確扣除、autoReserved 同數增加、myHand 不變、reload 不重扣，且右鍵能消耗 reservation 而不再次扣 remaining 時，Auto Card Sync v0.1 才可判定通過真實 PVE 驗收。
