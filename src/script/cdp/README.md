# ULR 自定義 cost
在客戶端修改自定義的 cost，便於計算牌組c值

## 使用方法
1. 收藏庫對遊戲按下右鍵 -> 內容，在`一般` -> 啟動選項中加入 `--remote-debugging-port=59222`
2. 透過 steam 啟動遊戲
3. 打開 app.exe
4. 確認遊戲中的 cost 是否被更改為自定義的 cost

## 自定義選項
如果對 cost 有多組需求，<br>
可以複製 `cc_asset_cost.toml` 並修改為其他檔案名稱如 `cc_asset_cost_custom.toml`，<br>
並且在 `config.json` 中修改 `costData` 的值為自定義的檔案名稱，便可以切換為自定義的 cost。

## 常見問題
如果修改失敗，可以先嘗試確認 port 是否已有被佔用，或是檢查 `config.json` 中的 `costData` 是否正確指向自定義的 toml 檔案。

如果防毒軟體阻擋了 app.exe 的執行，請將 app.exe 加入防毒軟體的白名單中。<br>
(可參考 https://github.com/pyinstaller/pyinstaller/issues/5854)
