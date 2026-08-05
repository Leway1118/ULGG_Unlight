# Auto Card Sync v0.1 Release Guardian Review

日期：2026-07-29
Verdict：**可進入受控真實測試；不可進入 release。**

## Summary

Auto Card Sync v0.1 維持最小功能範圍：只在 durable `hand.cards_dealt` 已成功提交 projection 後，將公共事件卡 face 映射成目前場地的唯一 canonical key，整批扣除 `remaining` 並建立 `autoReserved`。它不自動增加 `myHand`，也不處理 draw、event card、enemy card、場地切換或出牌。

三個 syntax checks、48 個 JavaScript tests 與 400 個 Python regression tests 均完成最終通過（Python 1 skip）。目前證據足以支持下一步進行受控真實 Room 驗收，但沒有真實遊戲、瀏覽器 storage 或跨 battle 證據，不能視為 release-ready。

## Passed

- **最小切片：通過。**
  - 只接受 `hand.cards_dealt`。
  - 不修改 reducer、SQLite schema、Sniffer、Launcher 或 API contract。
  - 不同步 `state.myHand`。
  - invalid/no-match/ambiguous/insufficient stock 均整批 fail closed。

- **去重與 reload 單元契約：通過。**
  - 使用 `session_id + event_id`。
  - processed ledger 持久化後，同一 event 不重複扣除。
  - 較新的 session／battle 不會直接套入舊 Tracker state。

- **手動操作相容的自動測試：通過。**
  - 有 reservation 時，手動加入只轉移到 myHand，不再扣 remaining。
  - 沒有 reservation 時保留原有手動扣牌。
  - shuffle 與 grouped Undo 測試通過。

- **可 rollback（有限契約）：通過。**
  - 自動 deduction 建立單筆 grouped history action。
  - Undo 會恢復 remaining、減少尚存 reservation，並將 processed event 標為 reverted，避免自動重套。
  - localStorage 原值可作為人工完整回退點。

- **靜態 card catalog：通過。**
  - 10 場地、34 canonical keys。
  - 0 malformed keys。
  - 0 現存 forward/reverse ambiguity。

## Warning

- **真實遊戲驗收尚未進行。** 目前所有 Auto Sync 證據來自 mocked/VM JavaScript tests。
- **場地 authority 不可信。** Tracker 仍使用 localStorage 場地，沒有狀態時預設 `FIELD_DECKS` 第一項。真實測試必須先人工選定正確場地。
- **session／battle fence 是保守設計。** 它避免重複扣牌，但 Sniffer restart 或下一場未 reset 時可能漏同步。
- **UI 可觀察性不足。** no-match、ambiguous、transition blocked 的結果沒有正式 Tracker diagnostics；驗收者必須直接保存 state 與 Console evidence。
- **rollback 不是永久可用。** 使用者若先按「清除最近操作」，auto deduction 的 history 被刪除，UI Undo 不再能找到該批操作。
- **舊 Tracker 不受影響只獲得自動測試支持。** 右鍵、手機長按、重設、排序、清除歷史與 reload 的真實 DOM 組合仍待人工驗證。
- **工作樹不可直接發布。** Auto Sync diff 與 projection、PHP 整合和大量無關 dirty content 尚未隔離。

## Blocker

以下是 release blockers，不是「開始受控測試」的 blockers：

1. **缺少真實 Room 證據。**
   - 尚未證明真實 8 張牌全部 canonical mapping。
   - 尚未證明 remaining 精確扣一次、myHand 不變、右鍵接手不二扣。
   - 尚未證明 reload 與下一場 transition。

2. **場地來源可能使 unique mapping 套在錯誤牌庫。**
   - 在建立場地 authority 前，只能以「人工先選正確場地」作為測試與操作前置。

3. **IndexedDB projection 與 localStorage Auto Sync 不是原子 commit。**
   - projection cursor 先提交；若 Auto Sync callback/localStorage save 後續失敗，event 可能不再投遞而 remaining 永久漏扣。
   - release 前需至少有明確復原／重播契約或可見故障處理。

4. **release diff 不可重現。**
   - 目前 8,952 個 status entries。
   - `index.php` 是包含其他整合內容的未追蹤整檔。
   - 多個必要檔案同時包含先前切片變更。

## Next Step

1. 伺服器恢復後，嚴格依 `docs/night_tracker_real_test_checklist.md` 執行一場受控真實 Room 測試。
2. 測試前人工選定真實場地，保存 localStorage 與牌庫 ground truth。
3. 保存完整 session UUID、event ID、sequence、八張 faces/canonical keys 及扣牌前後 state。
4. 驗證右鍵消耗 reservation、reload 不重扣、下一場／Sniffer restart 的 fence 行為。
5. 若真實測試通過，再處理兩個 release blockers：storage commit/recovery gap 與乾淨 release diff。
6. 在 blockers 解決前，不 commit、不發布、不擴充 Auto Sync 功能。
