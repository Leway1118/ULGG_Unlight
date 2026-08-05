## 做了什麼

<!-- 一兩句話 -->

## 為什麼

<!-- 相關 issue / 規格書章節 -->

## 檢查

- [ ] `npm run verify` 全綠
- [ ] 沒有貼進任何 token、cookie、原始封包或含 `steamid=` 的網址
- [ ] 沒有讓遠端資料變成可執行邏輯（eval / new Function / 動態 import / 注入腳本）
- [ ] 沒有使用或顯示對手的隱藏資訊

## 測試向量

- [ ] **沒有**改到 `packages/rule-schema/test-vectors/`
- [ ] 有改到 —— 這是破壞性變更，以下說明理由與已發布規則的遷移方式：

<!--
向量檔一變，所有已發布規則的 contentHash 就全部失效。
純重構不該產生任何 diff。
-->
