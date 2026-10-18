# Raid 場景與可推播獎勵資訊現況

## 結論摘要

目前 `raid_bot/domain/reward_rules.py` 有「一般渦」、「六星渦」、
「魚 Boss」與「妖精 Boss」四個概念分支，但現有 research 資料
沒有驗證任何一筆實際碎片掉落。

更重要的是：

- 旧 research 規則使用 `map`。
- 搬移後的 `reward_rules.py` 使用 `stage_id`。
- 已收集到的靈龜／贔屝樣本是 `map=5`、`stage=1`，兩套規則
  會得出不同碎片顏色。

因此，現階段沒有足夠證據將碎片名稱放入生產 Discord
推播。WebSocket Raid 通知現在只推播 Boss、HP、人數與 founder，
這符合現有證據邊界。

---

## 1. `reward_rules.py` 現有支援場景

以程式實際分支為準：

| 場景 | 觸發條件 | 回傳結果 | 現況 |
| --- | --- | --- | --- |
| 妖精 Boss | `monster_code == "mc1004_01"` | `特殊妖精獎勵`，數量未知 | 可執行，但 confidence 是 `unknown` |
| 魚 Boss | `monster_code in FISH_BOSS_CODES` | `隨機碎片`，數量未知 | `FISH_BOSS_CODES` 為空，目前沒有任何 Boss 可進入此分支 |
| 一般渦 | `stage_id` 是 1～5，且 `rarity != 6` | 依 stage 回傳一般碎片表 | confidence 是 `partial` |
| 六星渦 | `stage_id` 是 1～5，且 `rarity == 6` | 依 stage 回傳輪移後的碎片表 | confidence 是 `partial` |
| 未知 | 缺少 `stage_id`，或無對應 stage | `未知` | confidence 是 `unknown` |

程式判斷順序是先檢查妖精與魚 Boss，再檢查 `stage_id`。
所以 `mc1004_01` 即使沒有 stage，仍會回傳「特殊妖精獎勵」。

還有一個需注意的實際行為：Python 中 `None != 6` 為真。如果未來
某個 source 有 `stage_id`、卻沒有 `rarity`，現行規則會進入「一般渦」
分支，而不是回傳未知。目前 WebSocket source 連 `stage_id` 也沒有，
所以實際會在缺少 stage 時回傳未知。

程式證據：

- [`reward_rules.py`](raid_bot/domain/reward_rules.py#L28)：一般與六星 stage 表。
- [`reward_rules.py`](raid_bot/domain/reward_rules.py#L50)：魚 Boss 清單為空。
- [`reward_rules.py`](raid_bot/domain/reward_rules.py#L55)：妖精 Boss 只有 `mc1004_01`。
- [`reward_rules.py`](raid_bot/domain/reward_rules.py#L65)：實際判斷順序與輸出信心等級。

## 2. 場景判斷使用的欄位

| 欄位 | 是否被 `reward_rules.py` 使用 | 用途 |
| --- | --- | --- |
| `prf_mons` | 間接使用 | WebSocket normalizer 將它寫入 `RaidEntry.monster_code`；獎勵規則只用它判斷特殊妖精／魚 Boss |
| `prf_code` | 否 | 只被正規化為 Raid ID，用於新 Raid diff 與去重，不判斷獎勵 |
| `prf_founder` / founder | 否 | 只用於顯示，不判斷獎勵 |
| `prf_name` / boss name | 否 | `reward_rules.py` 不使用 Boss 名稱 |
| `stage_id` | 是 | 一般／六星碎片顏色的主要索引 |
| `rarity` | 是 | 只用於分流 `rarity == 6` 與非 6 |
| `map_id` | 否 | 搬移後規則不使用；舊 research 腳本使用的是 map |
| `treasure_level` | 否 | research 曾探測到此欄位，但沒有建立獎勵映射 |
| `member_limit` | 否 | 另一套 `detector_tier()` 用來推測探測機等級，與 reward rule 無關 |
| HP、人數、到期時間 | 否 | 只用於 Raid 狀態與顯示 |

WebSocket `raid_support_list` 目前只能提供 `prf_mons`、`prf_code`、
`prf_name`、founder、HP、人數與到期時間。Normalizer 明確將
`rarity`、`map_id` 與 `stage_id` 設為 `None`。

程式證據：

- [`websocket_raid_source.py`](raid_bot/infrastructure/websocket_raid_source.py#L57)
- [`websocket_raid_source.py`](raid_bot/infrastructure/websocket_raid_source.py#L72)

## 3. Raid Boss 與目前可推導的獎勵

### 規則表的可能輸出

| `stage_id` | 一般／非 6 星 | 6 星 |
| ---: | --- | --- |
| 1 | 記憶碎片（黃） | 時間碎片（綠） |
| 2 | 時間碎片（綠） | 靈魂碎片（藍） |
| 3 | 靈魂碎片（藍） | 生命碎片（紅） |
| 4 | 生命碎片（紅） | 死亡碎片（紫） |
| 5 | 死亡碎片（紫） | 記憶碎片（黃） |

這張表是「現有程式會回傳什麼」，不代表 research 已實際
觀測到這些掉落。所有命中此表的結果都標記為 `partial`。

### research 實際看到的 Boss

| Boss | `monster_code` | 觀測到的欄位 | 現有 `reward_rules.py` 會給的可能獎勵 | 證據等級 |
| --- | --- | --- | --- | --- |
| 靈龜 | `mc1008_02` | rarity 1、map 5、stage 1 | 記憶碎片（黃） | 只是現行 stage 規則推導；舊 map 規則會得出死亡碎片，互相衝突 |
| 贔屝 | `mc1008_01` | rarity 1、map 5、stage 1 | 記憶碎片（黃） | 只是現行 stage 規則推導；同樣有 map／stage 衝突 |
| 啃食者 | `mc1006_01` | rarity 1、map 10、stage 5 | 死亡碎片（紫） | 現行 stage 規則推導；舊 map 規則對 map 10 沒有建立碎片表 |
| 黑死獸 | `mc1003_02` | rarity 1；曾見 map/stage 4，也曾見 map/stage 5 | stage 4 時生命碎片（紅）；stage 5 時死亡碎片（紫） | 同一 Boss 可對應不同 stage，證明不能只用 Boss code 決定一種碎片 |
| 妖精 Boss（名稱未驗證） | `mc1004_01` | research 沒有實際 Raid 樣本 | 特殊妖精獎勵 | 只是程式常數，獎勵名稱與數量都未確認 |
| 魚 Boss | 無 | research 明記「尚未建立 monster_code 清單」 | 概念上是隨機碎片 | 現在無任何 code 會進入此分支 |

`monster_rules.py` 中的龜、蟲、死獸、魚名稱只是圖示規則；
`raid_rules.py` 的 `BOSS_FAMILIES` 也只是家族分類。這些清單不是
Boss 到獎勵的驗證映射，不應據此補齊獎勵。

## 4. 已經驗證的資料

### Raid 識別欄位

CDP research 實際觀測到 Raid row 包含：

- `profound_id`
- founder
- `profound_mons` / monster code
- Boss 名稱
- `rarity`
- `map`
- `stage`
- HP、人數、參戰狀態與到期時間
- `treasure_level`

已觀測的 Boss/code 為靈龜 `mc1008_02`、贔屝 `mc1008_01`、
啃食者 `mc1006_01` 與黑死獸 `mc1003_02`。

dual-source 實驗共 48 次採樣：

- 19 次完整 JSON 一致。
- 17 次 Raid ID 相同，但 HP、state 或 points 等動態內容不同。
- 0 次 Raid ID 清單不一致。

這可以支持「Raid 清單與識別欄位可讀取」，不能支持
「獎勵對應已驗證」。

證據：

- [`raid_dual_source_summary.json`](research/snapshots/raid_dual_source_summary.json#L7)
- [`raid_list_snapshot.json`](research/snapshots/raid_list_snapshot.json)
- [`raid_prediction_snapshot.json`](research/snapshots/raid_prediction_snapshot.json)

### Reward probe 實際結果

`raid_reward_state_probe.jsonl` 的 30 份記錄中：

- `raid_reward_data` 每次都是空 list。
- 沒有擷取到可對應某 Raid 的實際碎片掉落。
- `raid_info_reward_text` 只顯示靜態欄位標籤「參加獎勵」，不是獎勵內容。
- `item_info_raid` 是渦探知機／探測杖定義，不是 Raid 結算掉落表。

探測腳本確實有讀取 `raid_reward_data`、reward-like fields 與可見
reward text，但當次驗證沒有捕獲「結算完成後的獎勵事件」。

證據：

- [`detect_raid_reward_state.py`](research/detect_raid_reward_state.py#L101)
- [`detect_raid_reward_state.py`](research/detect_raid_reward_state.py#L164)

## 5. 尚屬推測或未驗證的部分

| 項目 | 證據等級 | 原因 |
| --- | --- | --- |
| stage 1～5 的一般碎片輪轉表 | 部分／規則假設 | 程式已編碼，但現有 probe 沒有實際掉落佐證 |
| 6 星 stage+1 輪轉 | 部分／規則假設 | research snapshot 明記無法可靠分辨 1 星與 6 星渦 |
| 魚 Boss 隨機碎片 | 未驗證 | 無 monster code 清單、無實際樣本、無數量 |
| `mc1004_01` 妖精 Boss | 未驗證 | 只有常數，research 沒有該 code 的 Raid 或掉落樣本 |
| 碎片數量 | 未驗證 | 所有 `RewardPrediction.quantity` 都是 `None` |
| Boss name／family 可直接決定碎片 | 不成立 | 黑死獸已觀測到不同 stage，不能只依 Boss 名稱或 code |
| `treasure_level` 可決定獎勵 | 未驗證 | 有欄位樣本，沒有對應表或結算事件 |
| `prf_code` 可解碼場景／獎勵 | 未驗證 | 現有程式只把它當唯一 ID，沒有解碼規則 |
| founder 影響獎勵 | 無證據 | 只是顯示欄位 |

舊 snapshot 本身也明確記錄三個限制：無法可靠分辨 1 星／6 星、
沒有魚 Boss code 清單、沒有碎片數量。

證據：[`raid_prediction_snapshot.json`](research/snapshots/raid_prediction_snapshot.json#L75)

### research 資料安全注意

部分舊 probe JSON 包含遊戲 URL、session credential 與玩家識別資訊。
這些原始檔只適合受限研究，不應放入 VPS 部署包、Discord
訊息、README 範例或公開 repository。本文只使用去識別的彙總結果。

## 6. 建議如何接入目前 `raid_bot`

### 現階段：維持不推播獎勵

目前生產 WebSocket source 不應呼叫 `predict_fragment()`：

1. WebSocket payload 沒有 `stage_id`、`rarity` 或 `map_id`。
2. `prf_mons` 只能識別已建立 code 的特殊 Boss，不足以判斷
   一般碎片。
3. 黑死獸樣本證明同一 monster code 可有不同 stage。
4. map-based 與 stage-based 規則目前有實際衝突樣本。

現有 API 已明確只在非 WebSocket snapshot 上呼叫
`predict_fragment()`，WebSocket `/api/raids` 不回傳 reward prediction。

證據：[`routes.py`](raid_bot/api/routes.py#L106)

現有 Discord 推播也尚未使用 `reward_rules.py`。`RaidEventDispatcher`
只呼叫 `format_raid_list()`，而 WebSocket formatter 只輸出 Boss、HP、人數與
founder。CDP formatter 也沒有加入 `RewardPrediction`。

證據：

- [`event_dispatcher.py`](raid_bot/services/event_dispatcher.py)
- [`raid_text.py`](raid_bot/formatter/raid_text.py#L38)
- [`notifier.py`](raid_bot/notifications/notifier.py)

### 先完成獎勵證據閉環

建議下一次 research 只收集去識別的必要欄位，並完整關聯：

```text
Raid ID
+ monster_code
+ boss_name
+ map_id
+ stage_id
+ rarity
+ treasure_level
+ Raid 結束／結算事件
+ 實際獎勵 item code、名稱、數量
```

至少需要觀測：

- 同一 Boss 的不同 map／stage。
- 一般與 6 星渦的每個 stage。
- 特殊妖精 Boss。
- 魚 Boss 的實際 monster code 與多次掉落。
- 參加獎勵與排名／傷害獎勵是否是不同來源。

在沒有驗證 `prf_code` 與 CDP `profound_id` 是同一 ID 體系之前，
不應直接用它們作 WebSocket／CDP join key。

### 驗證後的接入層次

驗證完成後，建議保持現有分層：

```text
Source
→ 只 normalize 已驗證欄位
→ RaidEntry
→ reward_rules 產生帶 evidence/confidence 的 RewardPrediction
→ formatter 只顯示達到生產門檻的獎勵
→ 現有 RaidEventDispatcher
→ 現有 RaidNotifier / DiscordWebhook
```

具體原則：

1. 不在 WebSocket source 內寫 Discord 文字或獎勵規則。
2. 不用 founder、HP、人數或 `prf_code` 形式猜獎勵。
3. 特殊 Boss 用 `monster_code`，一般碎片則必須使用已驗證的
   stage／rarity 或實際 reward item code。
4. `confidence="unknown"` 不推播；`partial` 在管理員明確選擇前也不
   進入玩家推播。
5. 規則表應版本化，每條規則保留去識別的驗證樣本編號與
   觀測次數。
6. 多個新 Raid 仍由現有 dispatcher 合併成一則訊息，不另建
   Discord webhook 流程。

## 最終可推播邊界

依目前實際資料：

- **已在現行 WebSocket Discord 訊息顯示**：Boss 名稱、HP、
  人數、founder。
- **已正規化，但現行 Discord formatter 沒有顯示**：Raid ID、
  monster code、到期時間。
- **可做內部診斷但不建議給玩家**：現有 stage／rarity
  `partial` 碎片推測。
- **尚不可推播**：碎片顏色、碎片數量、魚 Boss 隨機獎勵、
  妖精 Boss 具體獎勵、排名／傷害獎勵。
