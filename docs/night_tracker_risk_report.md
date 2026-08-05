# Auto Card Sync v0.1 風險報告

日期：2026-07-29
評級：Low / Medium / High
判定基準：狀態錯誤的破壞性、發生可能性、是否可見、是否能可靠回復。

## 風險摘要

| 風險 | 等級 | 目前控制 | 真實驗收要求 |
|---|---|---|---|
| 場地來源仍可能是 localStorage／預設第一場地 | High | mapping fail closed；不自動切場 | 每場發牌前人工確認真實場地 |
| projection commit 與 localStorage Auto Sync commit 非同一 transaction | High | callback exception 不破壞 poller | 注入 localStorage failure，確認可診斷與補救 |
| 真實 `FIELD_DECKS` mapping coverage 未驗證 | Medium | no-match／ambiguous 整批拒絕 | 保存真實 8 張 faces 與 mapping |
| session／battle transition fence 可能造成漏同步 | Medium | 新 session/battle 保守阻擋 | 測試 reload、Sniffer restart、下一場 |
| localStorage migration／舊狀態 | Medium | normalize optional auto fields | 用現有正式 profile reload 驗證 |
| reservation、Undo 與清除歷史互動 | Medium | grouped Undo 與 reservation tests | UI 實測右鍵、Undo、clear history |
| mapping ambiguity | Low（目前 catalog） | unique-only；整批 fail closed | 未來 deck data 變更後重跑 inventory |
| UI 缺少 Auto Sync 結果診斷 | Medium | Console warning 僅涵蓋 callback error | 人工驗收需直接比對 state/evidence |
| 工作樹與 release 邊界不乾淨 | High | 今晚不 stage/commit | 驗收後精確拆分 hunk 與 clean baseline |

## 1. 場地來源仍是 DEFAULT／既有 localStorage

**等級：High**

`tracker.js` 先嘗試載入 `ul_public_deck_tracker_v3`；沒有有效狀態時使用 `Object.keys(FIELD_DECKS)[0]`，目前第一個場地為「萊丁貝魯格城堡」。Auto Sync v0.1 不會從 WebSocket 或 detector 自動切場地。

不只存在 no-match 風險。不同場地共享許多 canonical keys，因此在錯誤場地上，八張牌仍可能各自得到 unique match，然後正確地執行了「錯誤場地」的扣牌。這是 silent state correctness risk。

建議：真實驗收前必須人工選定場地；在建立可信 site authority 前，不可宣稱 unattended auto sync 安全。

## 2. Projection commit 與 localStorage commit 分離

**等級：High**

`observation-poller.js` 先完成 IndexedDB projection/checkpoint commit，才呼叫 `onDomainBatchCommitted`。Auto Sync callback 失敗時只記錄 warning，不回滾已提交的 projection cursor。

`tracker.js` 的 Auto Sync 套用雖會在 `saveState()` 拋錯時恢復記憶體 state，但 durable event cursor 已經前進。現行 bootstrap 沒有在 reload 時以已保存 projection 主動重建漏掉的 Auto Sync plan；因此 localStorage quota、瀏覽器 storage failure 或 callback 例外可能造成「event 已 processed，但 remaining 未扣」。

這不會導致重複扣牌，卻可能永久漏扣。它是 release blocker，但不阻止受控真實測試；測試時必須觀察 Console 與 localStorage。

## 3. FIELD_DECKS mapping 不完整

**等級：Medium**

只讀 inventory 顯示目前 10 個場地、34 個全域 canonical keys，沒有 malformed key，也沒有同一場地內 forward/reverse ambiguity。這證明靜態資料內部一致。

尚未證明：

- 真實 `hand.cards_dealt` 的 face kind/value 全被支持。
- 目前遊戲／自訂 ASAR 的所有公共牌都存在於這 10 個場地。
- 個人事件卡或未來新增 face kind 不會混入此事件。

現有 adapter 對 invalid/no-match/ambiguous 整批 fail closed，因此主要後果是漏同步而非錯扣；但缺少 UI diagnostics 會使原因不易被使用者看到。

## 4. Card ambiguous match

**等級：Low（以目前 catalog 計）**

adapter 同時計算 `top + bottom` 與 `bottom + top`。只有一個 key 存在才接受；兩者都存在時整批拒絕。現有 10 個場地未發現此種 reverse pair。

風險會隨 `field-decks.js` 更新而改變。建議把 catalog inventory 保留為 release gate，資料變更時必須重跑。

## 5. Projection／session transition

**等級：Medium**

正面控制：

- dedupe key 是 `session_id + event_id`。
- ledger 存在 localStorage，reload 測試顯示不重複扣。
- active session 與 `battle_started_at` fence 阻止另一 session／battle 直接扣既有 tracker state。

殘餘風險：

- 新 battle 必須經明確 Tracker reset 才能同步；未 reset 會保守漏扣。
- Sniffer 在同一場中重啟會產生新 session，可能被 fence 擋住。
- projection active session 切換與 localStorage tracker lifecycle 並非同一 authority。

這符合 fail-closed 優先，但需要實機判定使用流程是否可接受。

## 6. localStorage migration

**等級：Medium**

`normalizeState` 對舊資料缺少 `autoReserved`／`autoSync` 時建立安全預設，相關 reload 測試通過。仍未在正式 browser profile 的歷史資料上驗證：

- 壞掉或過期的 JSON。
- 既有 v2/v3 fallback。
- origin 從舊 `file://` 或其他 hostname 切換後的 storage 分離。
- 儲存空間不足／被瀏覽器封鎖。

真實測試前須備份原始 localStorage；不得用測試結果取代 migration corpus。

## 7. Reservation 與 Undo

**等級：Medium**

現有自動測試證明：

- Auto deduction 同量建立 `autoReserved`。
- 手動加入先消耗 reservation，不再扣 remaining。
- shuffle 不會把 reservation 當成未知 remaining。
- grouped Undo 可回復自動 deduction，且同 event 不會被重套。

限制：

- reservation 是按 canonical key 計數，不是 card instance identity。
- 「清除最近操作」直接清空 history，但保留 reservation／ledger；清除後 UI 不再有該自動扣牌的 Undo 記錄。
- 多次手動操作、Undo 順序與 battle reset 仍需真實 UI 驗證。

## 8. 不影響手動操作的證據範圍

**等級：Medium**

自動測試涵蓋 reservation 接手、shuffle、Undo，且 v0.1 不寫 `state.myHand`。但尚未在真實 DOM 驗證右鍵、手機長按、清除歷史、場地重設與 reload 的完整組合。

因此目前只能說「自動測試未發現 regression」，不能說「已證明不影響所有舊 Tracker 操作」。

## 9. Release 邊界與可重現性

**等級：High**

目前有 8,952 個 status entries，且 Auto Sync 所需的 `observation-poller.js`、tests 與 `index.php` 混有先前切片內容。任何 broad staging 都會污染 release；`index.php` 整檔尤其不能當成單純 Auto Sync 新檔處理。

建議：真實驗收通過後，以乾淨基準或精確 hunk 建立候選 diff，再重跑同一測試矩陣。今晚不得 commit 是正確決策。
