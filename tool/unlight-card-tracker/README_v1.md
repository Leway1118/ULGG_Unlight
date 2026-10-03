# Unlight 公牌庫追蹤器 v2 開發版

目前所有檔案都放在 `unlight-card-tracker` 底下。

## 目錄

```text
unlight-card-tracker/
├─ control.html
├─ field-decks.js
├─ tracker.js
├─ assets/
│  └─ cards/
└─ detector/
   ├─ app.py
   ├─ config.json
   ├─ deck_counter.py
   ├─ phase_detector.py
   ├─ state_tracker.py
   ├─ models.py
   ├─ frame_debug.py
   ├─ requirements.txt
   ├─ debug_frames/
   └─ templates/
      ├─ card_types/
      └─ digits/
```

## 目前完成

- 保留第一版網頁追蹤器。
- 建立第二版 Python 影像辨識模組。
- 固定 848×760 畫面 ROI 設定。
- 牌庫數字 ROI 裁切與二值化。
- 階段列初步判斷。
- 牌庫數字穩定值狀態機。
- 牌庫增加時標示可能重洗。
- 公牌、自帶卡、棄牌、中央暫存與角色死亡的資料模型。

## 安裝

Windows 命令提示字元：

```bat
cd unlight-card-tracker\detector
py -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
```

## 先測 ROI

```bat
python frame_debug.py "..\你的影片.mp4" --time 20
```

輸出：

```text
detector/debug_frames/roi-preview.jpg
```

先確認綠框是否正確包住：

- 左上牌庫數字
- 階段列
- 上方出牌區
- 下方出牌區
- 下方手牌區
- 下方死亡標記區

需要調整時修改 `detector/config.json`。

## 分析影片

```bat
python app.py "..\你的影片.mp4"
```

輸出：

```text
detector/output/events.json
detector/debug_frames/
```

## 下一步

目前尚缺 0～9 的遊戲字型模板，所以 `deck_counter.py` 會先輸出牌庫數字裁切圖，不會亂猜數字。

下一步會從 `debug_frames/deck_*.png` 建立：

```text
templates/digits/0.png
templates/digits/1.png
...
templates/digits/9.png
```

完成後即可正式讀取牌庫張數，再串接 `tracker.js` 的待確認事件。
