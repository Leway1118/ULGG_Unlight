# Raid 奖励查询接口

## URL

```
POST https://www.ulrmap.wiki/api/raid-rewards/query
Content-Type: application/json
```

## 字段

### 请求体

| 字段 | 类型 | 说明 |
|------|------|------|
| `levels` | `int[]` | 宝箱等级 ID 列表 |
| `locale` | `string` | 语言，默认 `zh-CN`；可选 `zh-TW` / `en` / `ja` / `ko` |

### 响应（数组元素）

| 字段 | 类型 | 说明 |
|------|------|------|
| `treasureLevel` | `int` | 宝箱等级 ID |
| `bossDisplayName` | `string` | Boss 名称 |
| `rarity` | `int \| null` | 稀有度（星数） |
| `mapLevel` | `int \| null` | 地图阶段 |
| `whirlpoolTier` | `string \| null` | 涡层级：`涡I` / `涡II/III` / `涡IV` |
| `discovery` | `object[]` | 发现奖励 |
| `participation` | `object[]` | 参与奖励 |
| `ranking` | `object[]` | 排名奖励 |
| `defeat` | `object[]` | 击破奖励 |

### 奖励条目

| 字段 | 类型 | 说明 |
|------|------|------|
| `itemName` | `string` | 道具名称 |
| `quantity` | `int` | 数量 |
| `textColor` | `string \| null` | 展示颜色 `#RRGGBB` |
| `textBold` | `bool` | 是否加粗 |
| `rankMin` | `int` | 排名下限（仅 `ranking`） |
| `rankMax` | `int` | 排名上限（仅 `ranking`） |

## 示例调用

```bash
curl -X POST "https://www.ulrmap.wiki/api/raid-rewards/query" \
  -H "Content-Type: application/json" \
  -d '{"levels":[2161],"locale":"zh-CN"}'
```

## 示例返回

```json
[
  {
    "treasureLevel": 2161,
    "bossDisplayName": "M10 龙鲇",
    "rarity": 1,
    "mapLevel": 5,
    "whirlpoolTier": "涡II/III",
    "discovery": [
      {
        "itemName": "异化矿材",
        "quantity": 1,
        "textColor": "#64748b",
        "textBold": false
      },
      {
        "itemName": "抽奖券(免费)",
        "quantity": 3,
        "textColor": "#f59e0b",
        "textBold": false
      }
    ],
    "participation": [
      {
        "itemName": "古代妙药",
        "quantity": 2,
        "textColor": "#22c55e",
        "textBold": false
      }
    ],
    "ranking": [
      {
        "rankMin": 1,
        "rankMax": 10,
        "itemName": "死亡的碎片",
        "quantity": 2,
        "textColor": "#8a4fff",
        "textBold": false
      },
      {
        "rankMin": 11,
        "rankMax": 30,
        "itemName": "死亡的碎片",
        "quantity": 1,
        "textColor": "#8a4fff",
        "textBold": false
      },
      {
        "rankMin": 31,
        "rankMax": 60,
        "itemName": "白金币",
        "quantity": 1,
        "textColor": "#f8fafc",
        "textBold": false
      }
    ],
    "defeat": [
      {
        "itemName": "魔女秘药",
        "quantity": 1,
        "textColor": "#a855f7",
        "textBold": false
      }
    ]
  }
]
```
