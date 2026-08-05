# Unlight Tracker v0.5b 真實 PVE 驗收

## 測試前準備

- 使用本次重建的 `UnlightTrackerLauncher.exe`。
- 確認 Electron CDP 正在 `127.0.0.1:9333` 監聽。
- 確認 Tracker API 為 `http://127.0.0.1:8765/`。
- Tracker 頁執行 `Ctrl+F5`。
- Console 確認以下全域物件存在：
  - `TrackerStageSync`
  - `TrackerAutoCardSync`
  - `TrackerAutoSyncTarget`
  - `observationPoller`
- 每個案例保存 session ID、battle started time、event ID、sequence、畫面與 localStorage 證據。

## A. 已知場地 003：冰封湖畔

- [ ] `battle.started.payload.stage_code` 為 `"003"`。
- [ ] Tracker 自動切換至「冰封湖畔」。
- [ ] `initial` 總數為 53。
- [ ] 收到 `hand.cards_dealt`，記錄一般公牌數量 N。
- [ ] `remaining` 總數為 `53 - N`。
- [ ] `myHand` 總數為 N。
- [ ] pending events 為 0。
- [ ] pending cards 為 0。
- [ ] processed events 增加 1。
- [ ] 沒有使用原先預設場地建立 deduction plan。

## B. 已知場地 002：垃圾之街

- [ ] `battle.started.payload.stage_code` 為 `"002"`。
- [ ] Tracker 自動切換至「垃圾之街」。
- [ ] 不需要人工選擇場地。
- [ ] 收牌只套用一次，remaining/myHand 數量正確。

## C. Reload

- [ ] 記錄 reload 前 remaining、myHand、pending、processed。
- [ ] 執行 Tracker 頁 reload。
- [ ] remaining 不重扣。
- [ ] myHand 不重加。
- [ ] pending 不遺失或重複。
- [ ] processed 不重複新增。
- [ ] 同一 `session_id + event_id` 再次 callback 為 no-op。

## D. 隨機場地 011 或 stage missing

- [ ] `"011"` 顯示隨機場地提示；缺 stage 顯示尚未取得場地。
- [ ] `hand.cards_dealt` 後 remaining 不變。
- [ ] myHand 不變。
- [ ] pending events 與 pending cards 數量正確。
- [ ] processed 不增加。
- [ ] reload 後 pending 仍存在。
- [ ] 人工選擇有 FIELD_DECKS 的實際場地。
- [ ] 先完成安全 rebase，再一次套用所有 pending。
- [ ] pending 清空。
- [ ] processed 依事件筆數寫入。
- [ ] remaining/myHand 只變更一次。

## E. Conflict

- [ ] 先建立至少一筆 Tracker 手動進度。
- [ ] 收到與目前場地不同的已知 stage。
- [ ] Tracker 不自動清除既有進度。
- [ ] pending 保留並顯示 conflict。
- [ ] 在確認對話框選擇取消。
- [ ] field、remaining、myHand、history、pending、processed 完全不變。
- [ ] 再次選擇場地並明確確認。
- [ ] 建立 fresh field baseline。
- [ ] 同 battle/session 的 pending 原子套用。
- [ ] 任一 pending 失敗時不得部分套用。

## F. Cleanup

- [ ] 「重設本局」清除 pending。
- [ ] 「重設本局」清除 processed 與 stage status。
- [ ] 新 `battle.started` 清除上一場 pending。
- [ ] session close 清除該 session pending。
- [ ] pending 不跨 battle。
- [ ] pending 不跨 producer session。

## 驗收紀錄

| 項目 | 結果 | 證據／備註 |
|---|---|---|
| 003 冰封湖畔 | 未測 | |
| 002 垃圾之街 | 未測 | |
| Reload 去重 | 未測 | |
| 011／missing pending | 未測 | |
| Conflict cancel/confirm | 未測 | |
| Cleanup boundaries | 未測 | |

