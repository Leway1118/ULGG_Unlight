# Raid ICON 邏輯搬移分析

## 結論

這不是重新設計 ICON mapping 的任務。第一版 Raid Bot 的
monster、fragment、rarity 與 formatter 邏輯大部分已經跟著專案
搬到 `tool/raid-bot/raid_bot`。

目前真正缺少的是「WebSocket formatter 接回已有 resolver」，不是
重建 Discord、diff、source 或 domain model。

但第一版也有三個必須先說清楚的邊界：

1. 第一版可正常解析 Boss ICON 的路徑是
   `boss_name → get_monster_icon()`，不是 `monster_code → ICON`。
2. 第一版 fragment icon 使用 `map_id`，不是 `stage_id`。
   `stage_id + rarity` 對應的是 `reward_rules.py` 中的碎片名稱推測。
3. 現在 WebSocket `RaidEntry` 有 `boss_name` 與 `monster_code`，但
   `rarity`、`map_id`、`stage_id` 全是 `None`。所以 Boss ICON 可直接
   接回，碎片顏色／六星顯示不能在現有 WebSocket payload 上無據產生。

---

## 版本與檔案來源

### 第一版 Raid Bot

第一版來自 Git commit：

```text
d57671d Add Raid Bot Discord notifier service
```

當時位置：

```text
tool/unlight-card-tracker/detector/raid_bot/
├─ domain/
│  ├─ monster_rules.py
│  ├─ fragment_rules.py
│  ├─ reward_rules.py
│  ├─ raid_rules.py
│  └─ models.py
├─ formatter/
│  └─ raid_text.py
├─ services/
│  └─ event_dispatcher.py
└─ notifications/
   ├─ notifier.py
   └─ discord.py
```

可用以下指令檢視第一版：

```bash
git show d57671d:tool/unlight-card-tracker/detector/raid_bot/formatter/raid_text.py
git show d57671d:tool/unlight-card-tracker/detector/raid_bot/services/event_dispatcher.py
```

`tool/unlight-card-tracker-v1` 不是這個 Raid Bot 的第一版；該目錄是
卡牌 Tracker 與畫面 detector，沒有本文所述 Raid domain／formatter。

### 目前搬移後位置

```text
tool/raid-bot/
├─ raid_bot/
│  ├─ domain/
│  ├─ formatter/
│  ├─ infrastructure/
│  ├─ services/
│  └─ notifications/
├─ research/
├─ tests/
└─ run_raid_bot.py
```

Git history 中間還有：

```text
9d01eb2 refactor: separate ulgg raid bot into standalone tool
5cc37f2 refactor: extract raid bot into standalone service
```

## 第一版已存在的 ICON／reward／formatter 邏輯

### 1. Boss ICON

目前檔案：
[`raid_bot/domain/monster_rules.py`](raid_bot/domain/monster_rules.py#L4)

第一版與目前版本內容相同，Git diff 為空。

| Boss 名稱 | ICON |
| --- | --- |
| 靈龜、赑屃、贔屝、玄帝 | 🐢 |
| 啃食者、屠殺者、爬行者 | 🐛 |
| 黑死獸、瘟疫 | 🐶 |
| 龍魚、龍鯇、龍鯉 | 🐟 |
| 未命中 | ❓ |

可直接沿用：

- `MONSTER_RULES`
- `MonsterInfo`
- `get_monster_icon(boss_name)`
- `get_monster_info(boss_name)`

注意：這張表的 key 是 Boss 中文名稱，不是 `mc1008_02` 這類
monster code。

第一版 `event_dispatcher.py` 傳入的是 `raid.boss_name`，因此能命中。
第一版與目前 `raid_text.format_short_name()` 卻傳入
`raid.monster_code`，會回傳預設 ❓。這是第一版就存在的參數語意
不一致，不應把這條路徑當成已驗證的 `prf_mons → ICON`。

目前相關位置：

- [`monster_rules.py`](raid_bot/domain/monster_rules.py#L39)
- [`raid_text.py`](raid_bot/formatter/raid_text.py#L21)

### 2. Fragment color ICON

目前檔案：
[`raid_bot/domain/fragment_rules.py`](raid_bot/domain/fragment_rules.py#L4)

第一版與目前版本內容相同，Git diff 為空。

```text
map 1  → 🟡
map 2  → 🟢
map 3  → 🔵
map 4  → 🔴
map 5  → 🟣
map 10 → 🟡
None / 未知 → ⚪
```

可直接沿用：

- `MAP_FRAGMENT_COLOR`
- `get_fragment_icon(map_id)`

第一版 `RaidEventDispatcher.format_events()` 是呼叫：

```text
get_fragment_icon(raid.map_id)
```

因此這是 `map_id → fragment icon`，不是 `stage_id → icon`。
舊檔註解也寫著「需要依 Reader 實際 map id 校正」。

### 3. Stage 與 rarity 獎勵邏輯

目前檔案：
[`raid_bot/domain/reward_rules.py`](raid_bot/domain/reward_rules.py#L28)

第一版與目前版本內容相同，Git diff 為空。

- `NORMAL_FRAGMENT_BY_STAGE`：stage 1～5 對應黃、綠、藍、紅、紫碎片名稱。
- `SIX_STAR_FRAGMENT_BY_STAGE`：`rarity == 6` 時使用輪移後的碎片名稱。
- `predict_fragment(raid)`：同時包含妖精／魚 Boss 概念分支。

這套規則回傳 `RewardPrediction`，沒有被第一版 Discord
formatter 呼叫。也就是說，「stage + rarity 獎勵名稱」與
「Discord 上的顏色 ICON」在第一版其實是兩條分開的路徑。

現有驗證等級與 map／stage 衝突請見
[`RAID_REWARD_ANALYSIS.md`](RAID_REWARD_ANALYSIS.md)。搬移 ICON 不應順便把
`partial` reward prediction 變成生產推播。

### 4. Rarity 顯示

目前檔案：
[`raid_bot/formatter/raid_text.py`](raid_bot/formatter/raid_text.py#L7)

`STAR_COLOR` 實際是：

```text
rarity 1～5 → 🟡 🟢 🔵 🔴 🟣
rarity 6   → ⚪
未知       → ❔
```

`raid_rules.star_text(rarity)` 則是回傳對應數量的 `★`。

在第一版 commit 內沒有找到 `6️⃣⭐` 輸出；六星在 compact formatter
中是 ⚪，在 `star_text()` 中是六個 `★`。這一點不應根據預期
而改寫成舊版已有功能。

### 5. Discord formatter 與 notifier

第一版有兩個格式化路徑：

1. `formatter/raid_text.py`
   - `get_star_color()`
   - `format_short_name()`
   - `format_raid_line()`
   - `format_raid_list()`
2. `services/event_dispatcher.py`
   - 當時自己組合 `fragment_icon + monster_icon`。

目前 dispatcher 已改為只處理真正的 NEW event，並統一委托
`format_raid_list()`。這個結構比第一版好，不應把舊
`RaidEventDispatcher` 整個蓋回去。

目前證據：

- [`event_dispatcher.py`](raid_bot/services/event_dispatcher.py#L11)
- [`event_dispatcher.py`](raid_bot/services/event_dispatcher.py#L25)
- [`event_dispatcher.py`](raid_bot/services/event_dispatcher.py#L43)
- [`notifier.py`](raid_bot/notifications/notifier.py)
- [`discord.py`](raid_bot/notifications/discord.py)

### 6. 第一版研究／測試資料

搬移後位置是 [`research/`](research/)：

```text
research/
├─ detect_raid.py
├─ detect_raid_list.py
├─ detect_raid_live.py
├─ detect_raid_dual_source.py
├─ detect_raid_prediction.py
├─ detect_raid_reward_state.py
├─ snapshots/
│  ├─ raid_list_snapshot.json
│  ├─ raid_prediction_snapshot.json
│  └─ raid_dual_source_summary.json
└─ logs/
```

這些資料可證明 Boss 名稱、monster code、map、stage、rarity 等
CDP 欄位曾被觀測，也包含靈龜、贔屝、啃食者、黑死獸的樣本。

但它們是手動 probe、snapshot 與原始 log，不是可重複執行的 ICON
unit tests。`research/logs` 還可能含有 session credential 與玩家識別資料，
不應被直接讀入 unit test、打包或貼到 Discord。未來測試應從這些
觀測結果製作最小、去識別的 `RaidEntry` fixture。

## 第一版與目前版本差異

| 能力 | 第一版 | 目前 `raid-bot` | 差異 |
| --- | --- | --- | --- |
| Boss ICON table | 有，以 Boss 名稱為 key | 檔案已搬入且內容相同 | 沒有缺檔，只缺 WebSocket formatter 呼叫 |
| Fragment color table | 有，以 `map_id` 為 key | 檔案已搬入且內容相同 | WebSocket 沒有 `map_id` |
| Stage reward table | 有 | 檔案已搬入且內容相同 | 兩版 Discord formatter 都沒有直接使用 |
| Rarity color | 有 | 仍在 `raid_text.py` | WebSocket `rarity=None` |
| Boss compact formatter | 有 | CDP 分支仍保留 | WebSocket 分支早退，不顯示 ICON |
| Event dispatcher | 直接拼 ICON | 只篩 NEW event，再呼叫 formatter | 不應還原舊 dispatcher |
| Discord notifier | 有 | 已搬入並使用 | 不需改 |
| WebSocket source | 無 | 有 | 不需改 |
| 持久化 RaidDiff | 舊版不是現行完整語意 | 已有 baseline、去重、atomic state | 不需改 |
| ICON unit tests | 沒有自動 assertion | 目前也沒有 ICON assertion | 需要在未來搬移實作時補最小回歸測試 |

## 目前 WebSocket 實際可用資料

[`websocket_raid_source.py`](raid_bot/infrastructure/websocket_raid_source.py#L72)
已將：

```text
prf_mons    → RaidEntry.monster_code
prf_name    → RaidEntry.boss_name
prf_founder → RaidEntry.founder
```

但同一個 normalizer 也明確將：

```text
rarity  = None
map_id  = None
stage_id = None
```

因此可以分成兩階段：

### 現在就可接回

```text
prf_name
→ RaidEntry.boss_name
→ get_monster_icon(boss_name)
→ 🐟 / 🐛 / 🐢 / 🐶 / ❓
```

這條路徑只需沿用第一版 mapping，不需要新增
`monster_code → icon` 表。

### 現在不能無據接回

```text
stage / rarity / map
→ fragment color / six-star display
```

原因不是 formatter 缺 function，而是 WebSocket event 沒有這些欄位。
在沒有另一個已驗證 source 提供它們之前，只能顯示未知圖示，
不能從 `prf_mons`、founder、HP 或人數推測。

## 可直接搬移／沿用的 function 與 class

| 項目 | 所在檔案 | 處理建議 |
| --- | --- | --- |
| `MONSTER_RULES` | `domain/monster_rules.py` | 已搬入，原地沿用 |
| `MonsterInfo` | `domain/monster_rules.py` | 已搬入，原地沿用 |
| `get_monster_icon()` | `domain/monster_rules.py` | 已搬入，formatter 應傳 `boss_name` |
| `get_monster_info()` | `domain/monster_rules.py` | 已搬入；現有 `format_short_name()` 參數語意需校正 |
| `MAP_FRAGMENT_COLOR` | `domain/fragment_rules.py` | 已搬入，只在有已驗證 `map_id` 時使用 |
| `get_fragment_icon()` | `domain/fragment_rules.py` | 已搬入，不要把 `stage_id` 直接傳進去 |
| `NORMAL_FRAGMENT_BY_STAGE` | `domain/reward_rules.py` | 已搬入，不屬於這次 Boss ICON 最小接線 |
| `SIX_STAR_FRAGMENT_BY_STAGE` | `domain/reward_rules.py` | 已搬入，需先有已驗證 rarity/stage |
| `predict_fragment()` | `domain/reward_rules.py` | 已搬入，現階段不接 WebSocket Discord |
| `STAR_COLOR` | `formatter/raid_text.py` | 仍存在，但 WebSocket 缺 rarity |
| `get_star_color()` | `formatter/raid_text.py` | 仍存在，無 rarity 時回傳 ❔ |
| `format_raid_list()` | `formatter/raid_text.py` | 保留作為 dispatcher/notifier 單一格式化入口 |
| `RaidEventDispatcher` | `services/event_dispatcher.py` | 沿用目前版，不要整類蓋回舊版 |
| `RaidNotifier` | `notifications/notifier.py` | 完全不需變更 |
| `DiscordWebhook` | `notifications/discord.py` | 完全不需變更 |

## 目前缺少的不是哪些檔案

目前不缺：

- `monster_rules.py`
- `fragment_rules.py`
- `reward_rules.py`
- `raid_rules.py`
- `raid_text.py`
- `event_dispatcher.py`
- `notifier.py`
- `discord.py`

缺的是：

1. WebSocket `format_raid_line()` 將 Boss ICON 組合進輸出的那一步。
2. `format_short_name()` 與 `get_monster_info()` 之間的參數語意一致性。
3. 對 ICON 輸出的自動回歸測試。
4. 若未來要顯示 fragment／rarity，需要已驗證的 metadata source；
   這不是這次 formatter migration 應順便發明的功能。

## 需要改動的目前檔案

以「只恢復 Boss ICON」的最小範圍來看：

### 必要

1. `raid_bot/formatter/raid_text.py`
   - WebSocket 分支使用已有 `get_monster_icon(raid.boss_name)`。
   - 保留現有 HP、Members、Founder 文字。
   - 不把 reward prediction 順便塞進去。
   - 校正 `format_short_name()` 傳給 monster resolver 的欄位，或將
     resolver 呼叫收斂到一個明確以 `boss_name` 為輸入的 helper。

2. `tests/`
   - 新增或延伸 formatter unit test，使用 Fake RaidEntry，不連 CDP、
     WebSocket 或 Discord。
   - 驗證靈龜 → 🐢、啃食者 → 🐛、龍魚 → 🐟、黑死獸 → 🐶、
     未知 Boss → ❓。
   - 驗證同一輪多 Raid 仍只有一則訊息。
   - 驗證現有 HP／Members／Founder 仍存在。

### 不必要、不應動

- `raid_bot/infrastructure/websocket_raid_source.py`
- `raid_bot/services/raid_diff.py`
- `raid_bot/services/raid_service.py`
- `raid_bot/services/event_dispatcher.py`
- `raid_bot/notifications/notifier.py`
- `raid_bot/notifications/discord.py`
- `run_raid_bot.py`

這些都不屬於 ICON migration 的最小改動面。

## 最小修改方案

### Phase 1：只恢復 Boss ICON

```text
raid_support_list.prf_name
        ↓
RaidEntry.boss_name
        ↓
現有 get_monster_icon(boss_name)
        ↓
現有 raid_text.format_raid_line()
        ↓
現有 format_raid_list()
        ↓
現有 RaidEventDispatcher
        ↓
現有 RaidNotifier / DiscordWebhook
```

這個方案：

- 不改 WebSocket protocol。
- 不改 RaidDiff。
- 不改 baseline／去重。
- 不改 notifier。
- 不新增 mapping。
- 只讓 WebSocket formatter 重用第一版 Boss name resolver。

這是建議優先執行的 migration slice。

### Phase 2：只在 metadata 已驗證後恢復 color／rarity

不應在 Phase 1 順便實作。未來如果 source 真正提供已驗證的
`map_id`、`stage_id`、`rarity`，再分別沿用：

```text
map_id          → get_fragment_icon(map_id)
rarity          → get_star_color(raid) / star_text(rarity)
stage + rarity  → predict_fragment(raid)
```

三者語意不同，不應合併為一個含糊的「color resolver」。

## 測試現況與 migration 驗收門檻

### 現況

- 第一版 commit 沒有 Raid ICON 的自動 unit test。
- 目前 `tests/test_formatter.py` 是需要真實 CDP 59222 的手動腳本，
  沒有 ICON assertions。
- 目前 WebSocket notification tests 只斷言 Boss 名稱與「同輪只一則」，
  沒有斷言 ICON。

證據：

- [`test_new_raid_notification.py`](tests/test_new_raid_notification.py#L28)
- [`test_multiple_new_raids_single_message.py`](tests/test_multiple_new_raids_single_message.py#L31)
- [`test_formatter.py`](tests/test_formatter.py)

### 未來實作時最小驗收

1. Fake WebSocket RaidEntry 能從 `boss_name` 得到舊版 ICON。
2. 未知 Boss 安全回傳 ❓。
3. `rarity=None`、`map_id=None`、`stage_id=None` 不會被伪造成已知顏色。
4. HP、Members、Founder 不因 ICON 接入而消失。
5. 第一份 snapshot 仍不通知。
6. 重複 Raid ID 仍不重複通知。
7. 同輪多 Raid 仍合併為一則 Discord 文字。
8. Fake notifier 驗證，不連真實 Discord。

## 非目標

這份 migration plan 不建議：

- 重寫 WebSocket source。
- 重寫 Discord webhook。
- 還原第一版整個 `RaidEventDispatcher`。
- 用 `prf_mons` 臆測一張新的 monster code mapping。
- 用 founder、HP、人數或 Raid ID 猜顏色。
- 把 reward `partial` 推測包裝成已驗證掉落。
- 自行新增 🐙：第一版 `MONSTER_RULES` 沒有章魚 ICON。
- 自行新增 `6️⃣⭐`：第一版實作中沒有這個格式。

## 建議決策

建議將後續實作範圍定義為：

> 保留現有 WebSocket source、RaidDiff、EventDispatcher、RaidNotifier 與
> DiscordWebhook；只讓 WebSocket formatter 以 `RaidEntry.boss_name` 呼叫
> 已搬入的第一版 `get_monster_icon()`，並補假資料格式化測試。

這是最小、可驗證，且不會擴張為新功能的搬移方案。
