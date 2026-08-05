# Auto Card Sync v0.1 真實驗收 Checklist

日期：2026-07-29
用途：伺服器恢復後的受控人工驗收。每一項都應保留時間、session UUID、event ID、sequence、場地及驗收截圖／記錄。

## 0. 測試前置

- [ ] 使用固定基準程式碼，不在驗收途中修改任何檔案。
- [ ] 記錄 `git rev-parse HEAD` 與 `git status --short`。
- [ ] 備份瀏覽器目前 `ul_public_deck_tracker_v3` localStorage 值。
- [ ] 記錄目前 Tracker 場地、remaining、myHand、autoReserved 與 Undo history。
- [ ] 在 Tracker UI **手動選定真實對戰場地**；v0.1 尚無可信自動場地來源。
- [ ] 記錄該場地初始牌庫 canonical counts，作為扣牌前 ground truth。
- [ ] 確認 8765 給 Tracker API，9333 僅給 Electron CDP。

失敗條件：無法確認正確場地、工作樹基準不明、既有 localStorage 無法備份時，不進行自動扣牌驗收。

## 1. 啟動鏈

- [ ] 啟動整合 Launcher。
- [ ] 確認 Tracker API health 回 200。
- [ ] 確認 Tracker UI `http://127.0.0.1:8765/tracker/` 回 200。
- [ ] 確認 Electron CDP 可連線。
- [ ] 確認 Sniffer 啟動且沒有 outbound 自動操作。
- [ ] 記錄本次完整 `TRACKER_SESSION_ID`。
- [ ] 查詢 active session，確認 API 回傳的 session UUID 與 Launcher/Sniffer 本次 UUID **完全一致**。

失敗條件：API session、active session 與 Sniffer session 任一不一致，立即停止。

## 2. 進入實際 Room

- [ ] 進入一個已知場地的實際 Room。
- [ ] 不先手動扣除任何初始手牌。
- [ ] 記錄進入 Room、開始 battle 與初始發牌的實際時間。

## 3. `battle.started`

- [ ] 確認 durable event stream 出現 `battle.started`。
- [ ] 記錄 `session_id`、`event_id`、`sequence`。
- [ ] 確認 projection 進入 `battle_status = active`。
- [ ] 確認 projection 有非空 `battle_started_at`。
- [ ] 確認沒有建立第二個非預期 session。

## 4. `hand.cards_dealt`

- [ ] 確認同一 session 出現一筆 `hand.cards_dealt`。
- [ ] 確認 payload 有 8 張初始卡。
- [ ] 記錄每張 `display_top.kind/value` 與 `display_bottom.kind/value`。
- [ ] 逐張人工計算 forward 與 reverse canonical key。
- [ ] 確認每張在目前 `FIELD_DECKS[currentField]` 中只有一個 unique match。
- [ ] 確認 `shield` 顯示為 `防`，不是其他字。
- [ ] 確認 8 張牌形成單一、全有或全無的 deduction plan。
- [ ] 確認 remaining 只 render/save 一次後得到預期 counts。
- [ ] 確認每個被扣 canonical key 的 `autoReserved` 同量增加。
- [ ] 確認 `state.myHand` 完全不變。
- [ ] 保存扣牌前／扣牌後 `ul_public_deck_tracker_v3`。

失敗條件：

- 不是 8 張。
- 任一張 invalid／no-match／ambiguous 卻發生部分扣牌。
- remaining 與八張牌 multiset 不一致。
- myHand 自動增加。
- selected field 與真實場地不一致。

## 5. 重複通知去重

- [ ] 以 diagnostics 確認同一 `session_id + event_id` 不會第二次扣牌。
- [ ] 記錄第一次套用後 remaining。
- [ ] 等待至少兩輪 polling，確認 remaining 不再下降。
- [ ] 確認 `autoSync.processed_events` 對該 sync key 只有一筆有效狀態。

## 6. 手動右鍵／長按接手 reservation

- [ ] 對一張已有 `autoReserved > 0` 的牌執行既有右鍵加入我方手牌。
- [ ] 確認 `autoReserved` 減 1。
- [ ] 確認 `myHand` 增 1。
- [ ] 確認 `remaining` 不再減少。
- [ ] 若可用手機／觸控環境，再以長按重複同一驗證。
- [ ] 對一張沒有 reservation 的牌確認原本手動行為仍是 remaining 減 1、myHand 增 1。

## 7. Undo／rollback

- [ ] 在沒有清除最近操作前，對自動發牌扣除執行 Undo。
- [ ] 確認整批 deductions 回到 remaining。
- [ ] 確認尚未被手動接手的 reservation 相應減少。
- [ ] 確認 processed event 被標為 reverted，而不是刪除。
- [ ] 等待 polling，確認同一 durable event 不會因 Undo 被自動重套。
- [ ] 恢復備份 localStorage 可完整回到測試前狀態。

注意：按下「清除最近操作」會清空 history；在目前版本不可再假設 UI Undo 能回滾該批自動扣牌。

## 8. Reload

- [ ] 在自動扣牌完成後 reload Tracker。
- [ ] 確認 remaining、autoReserved、active session/battle fence、processed ledger 均由 localStorage 恢復。
- [ ] 確認 myHand 維持原值。
- [ ] 等待 active session polling 恢復。
- [ ] 確認同一 `hand.cards_dealt` 不重複扣牌。
- [ ] 確認 Console 無 JavaScript error。

## 9. 下一場／session transition

- [ ] 正常完成第一場。
- [ ] 進入下一場前記錄新／舊 session UUID 與 `battle_started_at`。
- [ ] 確認未重設 Tracker 時，新 session 或新 battle 被保守阻擋，不會誤扣舊場牌庫。
- [ ] 依既有 UI 明確重設並選定下一場正確場地。
- [ ] 確認新場 `hand.cards_dealt` 可建立新 deduction plan。
- [ ] 確認上一場 sync key 不會污染下一場。
- [ ] 若 Sniffer 重啟但仍是同一場 battle，確認不會重複扣牌，並記錄目前 fence 行為是否造成漏同步。

## 10. Fail-closed cases

- [ ] 錯誤場地或找不到 canonical key 時不扣牌。
- [ ] 人工構造／測試 ambiguous match 時不扣牌。
- [ ] 任一 canonical key 庫存不足時整批不扣。
- [ ] event card、draw card、enemy card 不觸發 v0.1。
- [ ] 非 `hand.cards_dealt` domain event 不觸發 v0.1。

## 11. 驗收證據與判定

至少保存：

- HEAD、dirty status 摘要。
- 完整 session UUID、battle start time、event ID、sequence。
- 八張原始 faces 與八張 canonical keys。
- before/after remaining、myHand、autoReserved、processed ledger。
- reload 前後結果。
- 手動 reservation 接手前後結果。
- 下一場 transition 結果。
- Console／Network 錯誤。

通過條件：

- 一筆真實 `hand.cards_dealt` 的 8 張牌全部 unique mapping。
- remaining 精確扣一次且只扣一次。
- myHand 不自動增加。
- 手動加入消耗 reservation，不二次扣 remaining。
- reload 不重複扣。
- 新 battle/session 不污染上一場。
- 任一失敗情況都 fail closed，沒有部分更新。
