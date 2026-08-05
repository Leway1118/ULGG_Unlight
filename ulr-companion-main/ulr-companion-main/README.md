# ULR Companion

UNLIGHT:Revive 自訂 COST 規則與對戰追蹤的執行端。

依《ULR Cost Rules Integration Plan Spec v0.3》建置。三方分工：

| 元件              | 負責                                           | 在哪                  |
| ----------------- | ---------------------------------------------- | --------------------- |
| **ULGG**          | 公開規則登錄、版本治理、Room 驗證、戰果統計    | <https://ulgg.online> |
| **GitHub**        | 原始碼、Schema、版本契約、CI、Release          | 這個 repo             |
| **ULR Companion** | 規則計算、CDP 觀測、遊戲內顯示、回報與自動更新 | 這個 repo             |

---

## 現在的狀態

**WP-01（規則格式與雜湊）已完成並有測試；其餘工作包只有介面，還沒有實作。**

| 工作包 | 內容                                            | 狀態            |
| ------ | ----------------------------------------------- | --------------- |
| WP-01  | Schema／Canonical JSON／SHA-256／跨語言測試向量 | ✅ 可用         |
| WP-02  | Cost Engine                                     | 🔲 只有介面     |
| WP-03  | ULGG 規則資料表與 GET API                       | 🔲 ULGG 端      |
| WP-04  | Room Report API                                 | 🔲 只有型別     |
| WP-05  | Match Import 擴充                               | 🔲 只有型別     |
| WP-06  | Character Analysis 版本篩選                     | 🔲 ULGG 端      |
| WP-07  | CDP UI                                          | 🔲 只有已知常數 |
| WP-08  | Release／自動更新                               | 🔲 未開始       |

先做 WP-01 是因為它是附錄 B 裡**唯一沒有依賴**的工作包，其他全部卡在它後面。
契約定下來，插件端與 ULGG 端才能平行開發而不反覆重寫（規格書 §15）。

---

## 開始

需要 Node.js 20.11 以上。

```bash
git clone https://github.com/UnlightPlugin/ulr-companion.git
cd ulr-companion
npm install
npm run verify      # format + lint + typecheck + test
```

`npm run verify` 全綠就代表環境沒問題，可以開始接工作包了。

試跑一份規則：

```bash
npx tsx apps/companion/src/index.ts packages/rule-schema/test-vectors/rules/arcadia-balance-1.2.0.json
```

---

## 專案結構

```
packages/
  rule-schema/     WP-01  規則格式、正規化、內容雜湊 ← 所有東西的依賴
  api-contract/           ULGG API 的型別契約（純型別，無實作）
  cost-engine/     WP-02  COST 計算            ← 只有介面
  cdp-adapter/     WP-07  連遊戲、解析事件、注入畫面 ← 只有已知常數
apps/
  companion/       WP-08  桌面應用             ← 佔位
docs/
  open-questions.md       開工前要先定案的事
  canonical-json.md       給 ULGG（PHP）實作同一套正規化用
  release.md              WP-08：打包、自動更新、系統匣設計
```

跨 package 直接用 `@ulr/xxx` import，指向原始碼，clone 下來不用先 build。

---

## 核心概念：規則的身分是它的 Hash

一份規則的識別不是名字也不是版本號，是**內容的 SHA-256**。

```
規則 JSON → Schema 驗證 → Canonical JSON → SHA-256 → contentHash
```

因為「同樣叫 1.2.0 但內容被人改過」的兩份規則必須被判定為不同，否則
Room 一致性檢查就是假的。正規化採 **RFC 8785 (JCS)**，規格書 §5.2 要求的
四件事（鍵值排序、UTF-8、消除非語義空白、統一數值表示）正是它的定義。

```ts
import { contentHash, validateCostRule } from "@ulr/rule-schema";

const result = validateCostRule(json);
if (result.valid) {
  console.log(contentHash(result.rule)); // sha256:bebd49f8…
}
```

⚠ **ULGG 端（PHP）要實作同一套正規化，先讀 [docs/canonical-json.md](docs/canonical-json.md)。**
`json_encode(21.0)` 在 PHP 給 `21.0`、JCS 要求 `21`，這一個差異就會讓兩邊
所有 Hash 全部對不起來。`packages/rule-schema/test-vectors/canonical.json`
是語言無關的驗收向量，照著跑就對得上。

---

## 公平性立場

這個插件只改**自己客戶端的顯示**，伺服器收到的東西一個字都沒變。

- 不修改伺服器端技能判定，不繞過官方戰鬥規則
- 自訂 COST 是**自我約束**：雙方同意才有意義，亞城／官方環境完全不受影響
- 不顯示也不上傳對手未揭露的手牌、牌組或任何隱藏資訊
- 對局限制條款（`restrictions`）只顯示給人看，引擎不強制、也不解析條件字串

詳見規格書 §12。任何 PR 若違反上述任一條，不會被合併。

---

## 授權

MIT。見 [LICENSE](LICENSE)。
