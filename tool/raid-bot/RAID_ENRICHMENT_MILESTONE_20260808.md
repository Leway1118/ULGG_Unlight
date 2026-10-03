# Raid Enrichment Milestone — 2026-08-08

> 本文件記錄 `raid-bot`／RaidWatcher 在 2026-08-08 完成的公開 Raid enrichment 階段。
> 內容以目前工作樹、單元測試、本機 CDP／scanner artifact，以及本輪提供的 production
> 觀察為依據。凡無本地 artifact 可交叉驗證的 production 紀錄，均明確標示來源與驗證邊界。

# 1. Milestone Summary

- 日期：2026-08-08
- 核心能力：從公開 `raid_support_list` 找出新 Raid，以專用 worker 加入該 Raid，從
  `db_raid` 取得 `treasure_level`，透過 ULRMap Reward API 解析 ranking 碎片，完成
  cleanup、兩層 cache、compact formatter 與 Discord 通知接線。
- 一句話架構：`public Raid discovery → 單 worker enrichment → Reward API/cache → 一次 Discord 通知`。
- 本階段狀態：**功能與 fake WebSocket／fake HTTP 測試完成；單筆真實 enrichment 子流程已驗證。**
- 尚未完成：自然、未快取 NEW Raid 到有色 Discord 的獨立留證、過夜穩定性、多 Raid
  壓力、queue latency 指標，以及 friend-only 權限情境。

完成不代表所有外部情境都已驗證。這個 milestone 的交付邊界是「production flow 已接線、
安全 fallback 已存在、關鍵 protocol／cleanup／Reward 子流程有實機與測試證據」，不是宣稱
已完成長時間或大量 production 驗收。

# 2. Final Architecture

```text
raid_get_support(owner_id)
→ raid_support_list（公開 Raid discovery）
→ RaidDiff 判定 NEW
→ prf_code
→ TreasureLevelEnricher asyncio queue
→ dedicated worker account
→ register(worker_id)
→ raid_code_input(worker_id, prf_code)
→ 等 raid_found／raid_code_error（timeout 後仍可繼續查 DB）
→ db_raid(worker_id)
→ 唯一 row：pass == prf_code
→ treasure_level + Raid metadata
→ db_raid_delete(worker_id, profound_id)
→ sleep 0.25
→ db_raid(worker_id) cleanup verify
→ profound_id 已不存在
→ TreasureLevelCache（prf_code → metadata）
→ FragmentResolver
→ RewardCache lookup（treasureLevel key）
→ cache miss：ULRMap Reward API batch query
→ ranking 碎片名稱
→ raid_text formatter
→ Discord notifier
```

## 2.1 責任邊界

| 區段 | 責任／來源 |
|---|---|
| public Raid discovery、diff、queue、worker protocol、cleanup、cache、formatter、Discord 接線 | 本專案功能 |
| `prf_code`、founder、Boss、HP | 遊戲 `raid_support_list`，不依賴 Reward API |
| `profound_id`、`pass`、`treasure_level`、rarity、map、stage | worker 加入後的遊戲 `db_raid`，不依賴 Reward API |
| boss display、rarity、map level、whirlpool tier、各 reward group | 箭頭佬／ULRMap Reward API |
| ranking reward → 碎片標準名稱／顏色 | 本專案 `FragmentResolver`，輸入資料來自 Reward API/cache |

worker account 仍是 `prf_code → treasure_level` 的必要 operational component。Reward API
本身不提供這段轉換，也不替 worker 加入 Raid。

## 2.2 Endpoint 現況差異（重要）

規劃期間曾把 `:15005` 視為 join／Raid DB endpoint，但**目前 production 接線的程式碼不是如此**：

- `raid_bot/config.py:12-19` 定義 `RAID_WS_ENDPOINT=:15003` 與
  `RAID_DB_WS_ENDPOINT=:15005`。
- `run_raid_bot.py:124-135` 建立 `PublicRaidEnrichmentClient` 時，實際傳入的是
  `RAID_WS_ENDPOINT`，不是 `RAID_DB_WS_ENDPOINT`。
- `raid_metadata_scanner.py:22` 的已驗證 scanner 預設也是 `:15003`。
- `:15005` 目前只供獨立 `DbRaidSource`／`RAID_SOURCE=db_raid` 路徑使用；過去 read-only
  probe 曾收到空陣列，尚不能把它寫成 production enrichment 的已驗證 endpoint。

因此，依目前 code 為準：**public watcher 與 production enrichment client 都使用
`RAID_WS_ENDPOINT`（預設 `:15003`）**。若未來確定要把 worker 分流到 `:15005`，應新增明確的
worker endpoint 設定並重新實機驗證，而不是只改本文件。

# 3. Current Data Sources

## 3.1 公開 Raid

- Request：`raid_get_support(owner_id)`
- Response event：`raid_support_list`
- Endpoint：`wss://www.playunlight.online:15003/`
- 主要欄位：`prf_code`、`prf_name`、`prf_mons`、`prf_founder`、`prf_hp`、
  `prf_member`、`prf_limit`
- 實作：`raid_bot/infrastructure/websocket_raid_source.py:47-94, 190-194, 311-324`

附件中的 `support_raid_list` 名稱與 code 不一致；實際 event 名稱是
`raid_support_list`。

## 3.2 Worker 加入後 Raid DB

- Current production client endpoint：`RAID_WS_ENDPOINT`，預設 `:15003`（見 2.2）。
- Events：`register`、`raid_code_input`、`db_raid`、`db_raid_delete`。
- 關聯條件：只能接受唯一一筆 `db_raid.pass == prf_code` 且有有效 `profound_id` 的 row。
- 主要欄位：`profound_id`、`pass`、`profound_mons`、`name_tcn`、
  `profound_founder`、`treasure_level`、rarity、level、map、stage。
- 實作：`raid_bot/infrastructure/public_raid_enrichment_client.py:12-21, 42-70, 165-281`。

## 3.3 Reward API

- Method／URL：`POST https://www.ulrmap.wiki/api/raid-rewards/query`
- Request：`{"levels": [int, ...], "locale": "zh-TW"}`
- 主要 response：`bossDisplayName`、`rarity`、`mapLevel`、`whirlpoolTier`、
  `discovery`、`participation`、`ranking`、`defeat`
- Client：`raid_bot/infrastructure/treasure_reward_client.py:11-14, 37-59, 62-123`

目前沒有已知的公開 `prf_code → treasureLevel` API。`treasure_level` 仍需 worker
實際加入 Raid，之後從 account-scoped `db_raid` row 取得。

# 4. treasureLevel Findings

## 4.1 已確認

- TL 不是 HP。真實 row 同時有 `treasure_level=2091` 與 `hp=5364`、`hp_max=6000`。
- TL 可作為 Reward API 的 treasure table/config ID；API 以 `levels[]` 查詢。
- 已觀測的 `profound_id` 具有 `<treasure_level>-<instance suffix>` 形式，例如
  `2091-...`、`2079-...`。這是目前樣本的觀察，不應反向以字串前綴取代
  `treasure_level` 欄位解析。
- `treasure_level` 可直接提交給 Reward API，再從 `ranking` 找到碎片名稱。
- 一筆原本未參與的公開 Raid 已證明：worker join 後，能以 `pass == prf_code` 找到
  TL，並完成 Reward 解析及 cleanup。

## 4.2 參與條件的證據邊界

本機 CDP 驗證共有四筆 Raid，全部為 `already_joined=true` 且有 TL；沒有未參與樣本。
另有一筆公開、原本未參與的 Raid，是 worker join 後才在 `db_raid` 取得 TL。這些證據
**強力支持目前 operational rule：「先參與，再讀 TL」**，但只有一筆公開 enrichment
樣本，不能聲稱已對所有 Raid／權限類型證明絕對規則。

「friend-only Raid 若 worker 無權限參戰，就無法取得 TL」符合 protocol 依賴與外部回報，
但目前 repository 沒有保存一筆 friend-only 實機失敗 artifact；本文件將其列為已知
operational limitation，而不是本地已驗證結論。

## 4.3 已有證據的 TL 範例

| TL | Boss | ranking 碎片 | 顯示 | 證據 |
|---:|---|---|---|---|
| 2079 | 黑死獸 | 生命碎片 | 紅／🔴 | CDP artifact + 既有分析中的 Reward API 交叉驗證 |
| 2080 | 黑死獸 | 死亡碎片 | 紫／🟣 | CDP artifact + 既有分析中的 Reward API 交叉驗證 |
| 2085 | 屠殺者 | 死亡碎片 | 紫／🟣 | 本輪提供的 production cache／API 觀察；本地無該 VPS artifact |
| 2091 | 靈龜 | 記憶碎片 | 黃／🟡 | scanner summary + 本地 RewardCache |
| 2093 | 靈龜 | 靈魂碎片 | 藍／🔵 | CDP artifact + 既有分析中的 Reward API 交叉驗證 |
| 2095 | 靈龜 | 死亡碎片 | 紫／🟣 | CDP artifact + 既有分析中的 Reward API 交叉驗證 |

未在此表擴充任何未觀測 TL。

# 5. Fragment Mapping

`FragmentResolver` 只從 Reward API/cache 的 `ranking[].itemName` 辨識碎片；同時接受繁體、
簡體與包含「的」的名稱（`fragment_resolver.py:14-53`）。

| 碎片 | 簡稱 | Emoji |
|---|---|---|
| 記憶碎片 | 黃 | 🟡 |
| 時間碎片 | 綠 | 🟢 |
| 靈魂碎片 | 藍 | 🔵 |
| 生命碎片 | 紅 | 🔴 |
| 死亡碎片 | 紫 | 🟣 |

Boss 顯示實際依 `monster_code` 查表，不依 Boss 中文名稱猜測
（`monster_rules.py:4-46`）：

| Boss family | Short | Icon |
|---|---|---|
| 黑死獸（`mc1003_*`） | 狗 | 🐶 |
| 屠殺者（`mc1006_*`） | 蟲 | 🐛 |
| 誘引之者（`mc1007_*`） | 海 | 🐙 |
| 靈龜（`mc1008_*`） | 龜 | 🐢 |
| 龍鯰（`mc1012_*`） | 魚 | 🐟 |

黑死獸目前使用一般 🐶。未來可評估 Discord custom emoji，但不屬於本 milestone。

# 6. Production Enrichment Flow

1. `RaidService._events_from_snapshot()` 先由 `RaidDiff` 產生 NEW／UPDATED event
   （`raid_service.py:65-91`）。
2. 只有 snapshot 含 NEW 時才 dispatch；`RaidEventDispatcher.dispatch_async()` 只把
   `event.is_new` 的 Raid 送入 enricher（`event_dispatcher.py:29-60`）。
3. `TreasureLevelEnricher` 維持一個 `asyncio.Queue` 與一個 worker task，因此不同
   `prf_code` 依序執行（`treasure_level_enricher.py:71-100`）。
4. `_pending[prf_code]` 共用 future，避免同一個 code 在 pending 期間重複排隊
   （同檔 `141-162`）。
5. `TreasureLevelCache` hit 直接套 metadata；cache miss 才 enqueue／join。
6. client 發出 join 後，即使 `raid_found` timeout，仍查 `db_raid`；只接受唯一
   `pass == prf_code` row。
7. join 已送出後，cleanup 優先於 enrichment completion；client 會在成功、timeout、
   cancellation 等路徑盡力查找唯一 candidate 並 cleanup。
8. 只有 metadata 存在且 cleanup confirmed 時，`PublicRaidEnrichmentResult.ok` 才為真，
   才會寫 `TreasureLevelCache`。
9. enrichment timeout／拒絕／錯誤不阻止 Discord；dispatcher 保留原 RaidEntry，顯示
   founder、Boss icon/name 與 HP 基本資訊。
10. enrichment 成功後，同一則通知由 `FragmentResolver` 先讀 RewardCache，缺少 TL
    才 batch query API，最後交給 formatter。

# 7. Cleanup Race Condition

## 7.1 原問題

```text
db_raid_delete
→ 立即 db_raid verification
→ server 尚未完成刪除
→ profound_id 仍存在
→ cleanup_confirmed=False
→ enrichment 判定失敗
→ TreasureLevelCache 不寫入
```

## 7.2 已驗證修正

成功 scanner 的時序包含 delete settle。production 已共用相同常數：

```python
DEFAULT_DELETE_SETTLE_SECONDS = 0.25
```

`public_raid_enrichment_client.py:21, 235-254` 的最終流程：

```text
db_raid_delete(worker_id, profound_id)
→ sleep 0.25
→ db_raid(worker_id)
→ profound_id 不存在
→ cleanup_confirmed=True
```

## 7.3 真實 scanner 證據

Artifact prefix：`data/scanner_runs/20260808/155631_154204`

- `treasure_level=2091`
- `fragment_reward_name=記憶碎片（黃）`
- `cleanup_attempted=true`
- `cleanup_confirmed=true`
- after-delete response 為空 Raid list

嚴格說明：該次 summary 的 `join_outcome=timeout`、整體 `ok=false`，不能寫成整個 scanner
run 成功；但 TL 取得、Reward enrichment、cleanup 三個本階段關注的子流程均成功。

# 8. Timeout Budget Bug

## 8.1 原問題

原本同一個 `RAID_ENRICHMENT_TIMEOUT=10` 同時供：

- `PublicRaidEnrichmentClient` 每次 protocol wait。
- `TreasureLevelEnricher` 外層 job `wait_for()`。

結果：outer enricher 可能先 timeout；`asyncio.shield(future)` 保護的 inner worker 繼續完成，
於是 TL cache 稍後才寫入，但 Discord 已先發基本 fallback，`FragmentResolver` 也沒有機會用
該次 TL 查 Reward API。

## 8.2 最終 code 設定

```env
RAID_ENRICHMENT_PROTOCOL_TIMEOUT=10
RAID_ENRICHMENT_TIMEOUT=55
```

`run_raid_bot.py:93-107` 驗證兩者皆為正數；啟用 enrichment 時 overall 必須大於
protocol。`run_raid_bot.py:124-135` 分別把 protocol 傳給 client、overall 傳給 enricher。

Worst-case budget：

```text
connect                       ~10.00 s
register settle                1.00 s
join wait                     10.00 s
first db_raid                 10.00 s
cleanup lookup                10.00 s
delete settle                  0.25 s
cleanup verification          10.00 s
-------------------------------------
total                         ~51.25 s
overall upper bound           55.00 s
```

55 秒是上限，不是每筆固定等待時間；正常 event 到達後會提早完成。

## 8.3 設定檔狀態差異

程式預設與 `.env.example` 已是 `10 / 55`。但撰寫本文件時，本機未追蹤的 `.env` 仍只有
舊的 `RAID_ENRICHMENT_TIMEOUT=10`，且沒有 protocol setting。`.env` 不應 commit；部署前應在
目標主機手動更新為 `10 / 55`。本文件不宣稱 timeout split 已部署到 VPS。

# 9. Cache Design

## 9.1 TreasureLevelCache

預設：`data/raid_enrichment_cache.json`

```json
{
  "version": 1,
  "records": {
    "<prf_code>": {
      "profound_id": "<id>",
      "profound_mons": "<monster_code>",
      "name_tcn": "<boss>",
      "profound_founder": "<founder>",
      "treasure_level": 2091,
      "rarity": 1,
      "level": null,
      "map": 5,
      "stage": 1
    }
  }
}
```

- Key：`prf_code`
- Value：allowlisted metadata，TL 必須可正規化為 integer。
- Cache hit：不再 join。
- 寫入：temporary file + fsync + `os.replace()` atomic replace。
- 損壞／不存在：安全視為空 cache。
- 實作：`treasure_level_cache.py:11-106`。

## 9.2 RewardCache

預設：`data/raid_reward_cache.json`

```json
{
  "version": 1,
  "locale": "zh-TW",
  "rewards": {
    "2091": {
      "treasureLevel": 2091,
      "bossDisplayName": "...",
      "rarity": 1,
      "mapLevel": 1,
      "whirlpoolTier": "...",
      "discovery": [],
      "participation": [],
      "ranking": [],
      "defeat": []
    }
  }
}
```

- Key：`treasureLevel` 的字串表示。
- Cache 帶 locale；locale 不符會安全重建，避免不同語系混用。
- 缺少的 levels 才由 `FragmentResolver.resolve_many()` 一次 batch query。
- 同樣採 atomic replace；API/cache 錯誤會 fallback，不使 Raid Bot crash。
- 實作：`reward_cache.py:18-114`、`fragment_resolver.py:68-106`。

# 10. Real Production Evidence

下列兩筆是本輪提供的 production 觀察；對應 VPS cache/log 沒有存放在目前工作樹，因此
列為「production observation」，不是本地 artifact 再驗證。

## 10.1 `CMFoQKgWFaDi`

- founder：hajimi
- Boss：靈龜
- profound_id：`2093-...`
- treasure_level：2093
- rarity：1
- map：7
- stage：3
- production observation：enrichment cache 成功

此觀察與本機 CDP artifact 的 `2093 → 靈龜 → 靈魂碎片（藍）` 相容。

## 10.2 `MKOOzlHlKrVh`

- founder：咕嚕．挖2朵
- Boss：屠殺者
- profound_id：`2085-...`
- treasure_level：2085
- rarity：1
- map：10
- stage：5
- production observation：Reward API 回死亡碎片，reward cache 成功寫入 2085

Reward chain 已驗證；**該筆自然 Discord colored notification 是否直接顯示「紫蟲」尚未
單獨留證，狀態為 OBSERVING。**

另有 Discord／formatter 實際有色顯示例「Owlic 藍龜」。這可支持 colored formatter 能輸出，
但不能單獨替代「自然、未快取 NEW Raid 全鏈路」驗收。

# 11. Environment / Production Config

建議／目前 code 支援的 production 設定如下；敏感值只使用 placeholder：

```env
RAID_SOURCE=websocket
RAID_WS_ENDPOINT=wss://www.playunlight.online:15003/
RAID_OWNER_ID=<public-watcher-account-id>

RAID_ENRICHMENT_ENABLED=true
RAID_ENRICHMENT_WORKER_ID=<dedicated-worker-id-secret>
RAID_ENRICHMENT_PROTOCOL_TIMEOUT=10
RAID_ENRICHMENT_TIMEOUT=55
RAID_ENRICHMENT_CACHE_FILE=./data/raid_enrichment_cache.json

RAID_REWARD_API_URL=https://www.ulrmap.wiki/api/raid-rewards/query
RAID_REWARD_LOCALE=zh-TW
RAID_REWARD_TIMEOUT=10
RAID_REWARD_CACHE_FILE=./data/raid_reward_cache.json

API_HOST=127.0.0.1
API_PORT=8766
DISCORD_WEBHOOK_URL=<secret>
```

不得把真實 worker ID、owner ID、Discord webhook、token 或 session secret 寫入文件、log
或 commit。

# 12. Systemd / VPS

本輪提供的 production 環境資訊：

```text
WorkingDirectory=/var/www/html/unlight/tool/raid-bot
EnvironmentFile=/var/www/html/unlight/tool/raid-bot/.env
API=127.0.0.1:8766
service=unlight-raid-bot.service
```

Operational rule：由 systemd 維持唯一 production process。不要在 service 已運行時手動再啟
一份 `run_raid_bot.py`，否則可能發生重複 join、重複 Discord、cache 競爭或 worker 狀態干擾。

## 12.1 Repository deployment template 差異

目前 repository 內可找到的 service template 是：

```text
tool/unlight-card-tracker/deploy/systemd/ulgg-raid-watcher.service
WorkingDirectory=/opt/ulgg-raid-bot
EnvironmentFile=/opt/ulgg-raid-bot/.env
service template name=ulgg-raid-watcher.service
```

它與上述實際 production path／service name 不一致。本文只記錄差異，未修改 template；後續
部署或文件維護時應先確認 VPS 真實 unit，而不能直接假設 repository template 已同步。

# 13. Known Limitations

- Friend-only Raid：worker 無參戰權限時，預期無法取得 account-scoped TL；尚缺保存的實機
  failure artifact。
- 單 worker：Raid 數量暴增時 queue 會累積，後面的 NEW 通知可能延遲。
- 單筆 worst-case overall timeout 可到 55 秒。
- support list 與遊戲 server response 速度都可能成為 bottleneck。
- Reward API 是外部依賴；timeout、HTTP error、schema error 時只能 fallback。
- 新活動若未被 API 資料涵蓋，需要資料提供方更新。
- 本專案目前不打算自行重建完整 reward database。
- `asyncio.shield()` 使外層 timeout 後 inner worker 繼續以完成 cleanup；這是安全取捨，也表示
  timeout 當下不能假設 worker 已完全停止。
- 自然 uncached NEW → colored Discord、過夜穩定性與多 Raid load 仍未驗證。

# 14. Design Decision / Scope

本階段的收斂決策：**RaidWatcher 不再朝完整 Raid 資料平台擴張。**

- 本專案負責 public Raid discovery。
- 本專案負責短生命週期 `prf_code → treasure_level` enrichment 與 cleanup。
- Reward metadata 優先使用既有 ULRMap API/cache。
- Discord 只維護必要的 compact formatter 與可靠 fallback。
- 不由 Boss 名稱猜碎片，不自行維護未驗證 TL mapping。
- 若未來出現可靠的完整 public Raid feed + TL API，可移除目前的 join worker；若 feed 同時取代
  discovery，才值得進一步砍掉相關 websocket path。

# 15. Next Milestone

下一階段先驗收，不擴功能：

1. 保存一筆自然、uncached NEW Raid 的全鏈路證據。
2. 驗證多筆 NEW 連續、單 worker 順序處理。
3. 過夜觀察 reconnect、queue、cache 與 Discord。
4. 實測 protocol timeout、permission failure、cleanup failure 的基本通知 fallback。
5. 增加 queue backlog、等待時間與單筆 processing latency 的安全 metrics；不得記 worker ID。
6. 只有量與延遲真的超標，才評估 parallel worker／多帳號。
7. 上述穩定性完成後，才考慮 formatter／Discord UI 優化。

# 16. Important Files

| 檔案 | 用途 |
|---|---|
| `run_raid_bot.py` | 組裝 source、enricher、RewardResolver、service 與 API；設定驗證 |
| `raid_bot/config.py` | 環境變數與安全預設 |
| `raid_bot/infrastructure/public_raid_enrichment_client.py` | worker WebSocket protocol、唯一 pass matching、cleanup |
| `raid_bot/infrastructure/treasure_level_cache.py` | `prf_code → TL/metadata` atomic cache |
| `raid_bot/infrastructure/treasure_reward_client.py` | ULRMap Reward API batch client／schema allowlist |
| `raid_bot/infrastructure/reward_cache.py` | locale-aware Reward API atomic cache |
| `raid_bot/services/treasure_level_enricher.py` | single queue、pending dedupe、overall timeout、fallback |
| `raid_bot/services/fragment_resolver.py` | cache-first API query、ranking 碎片解析 |
| `raid_bot/services/event_dispatcher.py` | 只 enrich NEW、注入 prediction、通知 fallback |
| `raid_bot/services/raid_service.py` | snapshot → RaidDiff → event → async dispatcher |
| `raid_bot/formatter/raid_text.py` | NEW、founder、碎片 short、Boss short/icon、HP 顯示 |
| `raid_metadata_scanner.py` | 單筆研究工具與已驗證 cleanup 時序；不是 production dispatcher |
| `public_raid_enrichment_probe.py` | 單筆 public Raid → TL → Reward → preview probe |

# 17. Tests / Quality

最近一次紀錄：

- cleanup race 修正相關 tests：OK。
- timeout split 針對性 tests：`23 OK`。
- 排除兩支手動 CDP 腳本後，可執行完整單元測試：`89 OK`。
- full discovery：`91 discovered / 89 passed / 2 collection errors`。

既有 collection errors：

- `tests/test_formatter.py`
- `tests/test_notifier.py`

兩者 import `raid_bot.infrastructure.cdp_client` 時找不到 `ul_sniffer` wrapper。這是既有手動
CDP 測試的收集環境問題，與 enrichment 本次修改無關，不應記成 regression。

主要 coverage：

- outbound allowlist 不含 battle／attack／claim。
- join success／reject／timeout。
- 唯一 pass matching 與 ambiguous row 不刪除。
- delayed delete settle／cleanup verify／cleanup failure fallback。
- cancellation 後仍 cleanup。
- pending `prf_code` dedupe、不同 code 串行。
- cache hit／persist／restart。
- near-protocol-timeout 後仍在 overall budget 內完成 TL、cleanup 與 RewardResolver。
- fake HTTP Reward API、ranking fragment、cache-first/fallback。
- NEW-only enrichment、一次 combined notification。

這些是 fake WebSocket／fake HTTP 或本機 artifact 驗證；不能冒充 VPS 過夜或真 Discord
load test。

# 18. Security / Operational Notes

- 不記錄完整 worker ID、Discord webhook、token 或 session secret。
- `.env` 不 commit。
- `data/scanner_runs/` 可能含 founder、pass、profound ID 與 raw payload，不應公開 commit。
- scanner／probe artifact 與 production state 必須分離。
- 部署不得覆蓋 VPS `.env`、`data/`、cache 或 state。
- log 只記 status、exception type、cleanup flags 等安全資訊，不輸出 worker ID。
- 測試期曾暫時放寬 VPS directory permissions；production 穩定後應恢復最小必要權限，並確認
  systemd user 仍能 atomic replace cache。
- 不允許新增 battle、attack、reward claim。production outbound allowlist 目前只有 control、
  `register`、`raid_code_input`、`db_raid`、`db_raid_delete`。

# 19. Final Status

| 能力 | 狀態 |
|---|---|
| Public Raid discovery | PASS |
| `prf_code` detection | PASS |
| Worker join | PASS |
| treasureLevel acquisition | PASS |
| Cleanup | PASS |
| TreasureLevel cache | PASS |
| Reward API | PASS |
| Reward cache | PASS |
| Fragment parsing | PASS |
| Colored formatter | PASS |
| Natural uncached NEW → colored Discord | OBSERVING |
| Overnight stability | NOT YET VERIFIED |
| Multi-Raid load | NOT YET VERIFIED |

## 這個 milestone 的完成定義

本 milestone 視為完成，因為下列技術鏈已有 code、測試與至少一筆真實子流程證據：公開 Raid
提供 `prf_code`；專用 worker 能 join；唯一 `pass == prf_code` row 能提供 TL；cleanup 有
settle/verify；TL 能經 Reward API/cache 解析碎片；formatter/Discord flow 已接線；任一 enrichment
失敗仍保留基本通知。自然 notification 與長時間穩定性是下一 milestone 的 acceptance work，
不是再擴充 resolver 的理由。

## 何時才值得開發 parallel workers

只有先加入安全 metrics 並觀測到以下條件之一，才值得承擔多帳號、並行 cleanup、rate limit、
cache concurrency 與權限管理的複雜度：

- queue backlog 在正常 Raid 流量下持續增長，而不是短暫尖峰；
- p95 processing latency 經常接近 55 秒，造成新 Raid 通知失去時效；
- 大量 NEW Raid 在死亡／過期前無法完成串行 enrichment；
- 已排除 game server、support list 與 Reward API latency，確認 bottleneck 真的是單 worker。

在沒有上述 production evidence 前，維持單 worker 是較安全、可觀測、容易 cleanup 的設計。
