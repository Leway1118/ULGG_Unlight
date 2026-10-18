# Unlight 公牌庫追蹤器 v3 開發版

目前所有檔案都放在 `unlight-card-tracker` 底下。

## 目錄

```text
unlight-card-tracker/
├─ control.html
├─ field-decks.js
├─ tracker.js
├─ assets/
│  ├─ cards/
│  └─ fields/
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

## 網頁追蹤器目前功能

- 場地牌庫切換。
- 公牌清單左鍵／點一下扣除。
- 手機長按或電腦右鍵，將卡片由公牌清單移至我方手牌區。
- 點擊我方手牌，可將卡片移回公牌清單。
- 敵方手牌候選區顯示於公牌清單上方。
- `Shuffle` 會以目前剩餘公牌建立敵方手牌候選。
- 屬性未公開量改為五個按鈕。
- 點擊劍、槍、防、移、特，可把含有該屬性的卡片排到公牌清單前方。
- 已移除「公牌庫目前張數」手動輸入與搜尋卡片欄位。
- 操作紀錄支援復原。

## 操作規則

### 公牌清單

- 左鍵／點一下：公開扣除 1 張。
- 手機長按：加入我方手牌 1 張。
- 電腦右鍵：加入我方手牌 1 張。
- `Shift + 左鍵` 已移除。

### 我方手牌區

- 點一下：將 1 張牌移回公牌清單。

### 敵方手牌候選區

- 按下 `Shuffle` 後建立。
- 我方手牌在加入時已從公牌清單扣除，因此候選區直接複製目前 `remaining`。
- 候選區目前為唯讀，避免誤操作改變牌庫資料。

## 資料狀態

```js
{
  field: "萊丁貝魯格城堡",
  initial: {},
  remaining: {},
  myHand: {},
  enemyCandidates: {},
  sortType: null,
  history: []
}
```

## SITE 英文值與場地圖片檔名

以下先作為文件規格保留。未來網站、影像辨識或 API 串接時，可使用 `site` 作為穩定英文識別值。

| 中文場地 | site | 圖片檔名 | 目前牌組資料 |
|---|---|---|---|
| 盡頭之村 | `end-village` | `end-village.jpg` | 有 |
| 藩骸兒的遺跡 | `fonghail-ruins` | `fonghail-ruins.jpg` | 有 |
| 冰封湖畔 | `frozen-lakeside` | `frozen-lakeside.jpg` | 有 |
| 冰封湖畔（第二圖） | `frozen-lakeside-2` | `frozen-lakeside-2.jpg` | 共用冰封湖畔資料 |
| 垃圾之街 | `garbage-street` | `garbage-street.jpg` | 有 |
| 瘋狂山脈 | `madness-mountains` | `madness-mountains.jpg` | 有 |
| 萊丁貝魯格城堡 | `reidenberg-castle` | `reidenberg-castle.jpg` | 有 |
| 魔都羅占布爾克 | `rosenbourg-capital` | `rosenbourg-capital.jpg` | 有 |
| 人魂墓地 | `soul-graveyard` | `soul-graveyard.jpg` | 有 |
| 風暴荒野 | `storm-wilderness` | `storm-wilderness.jpg` | 有 |
| 誘惑森林 | `temptation-forest` | `temptation-forest.jpg` | 有 |
| Ubos Black Lake | `ubos-black-lake` | `ubos-black-lake.jpg` | 尚未建立 |
| White Witch Stone Circle | `white-witch-stone-circle` | `white-witch-stone-circle.jpg` | 尚未建立 |
| Witch Valley | `witch-valley` | `witch-valley.jpg` | 尚未建立 |

未來可轉成：

```js
const FIELD_SITE_MAP = {
  "盡頭之村": "end-village",
  "藩骸兒的遺跡": "fonghail-ruins",
  "冰封湖畔": "frozen-lakeside",
  "垃圾之街": "garbage-street",
  "瘋狂山脈": "madness-mountains",
  "萊丁貝魯格城堡": "reidenberg-castle",
  "魔都羅占布爾克": "rosenbourg-capital",
  "人魂墓地": "soul-graveyard",
  "風暴荒野": "storm-wilderness",
  "誘惑森林": "temptation-forest"
};
```

## Python 影像辨識模組

- 固定 848×760 畫面 ROI 設定。
- 牌庫數字 ROI 裁切與辨識。
- 階段列初步判斷。
- 公牌、自帶卡、棄牌、中央暫存與角色死亡資料模型。
- 玩家手牌、事件卡及對方出牌辨識。
- 即時畫面狀態可再串接 `tracker.js`。

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
