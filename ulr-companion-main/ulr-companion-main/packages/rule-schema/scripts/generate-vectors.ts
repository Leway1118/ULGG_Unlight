/**
 * 產生跨語言測試向量
 * ====================
 * WP-01 的完成定義是「跨語言測試向量一致」。這支腳本用 TypeScript 版實作
 * 算出每個案例的 canonical 字串與 SHA-256，寫進 test-vectors/canonical.json。
 *
 * ULGG（PHP）或任何第三方要實作同一套正規化時，載入那個 JSON、對每個 case
 * 跑自己的實作、比對 canonical 與 hash 即可，不需要讀懂這裡的 TypeScript。
 *
 *     npm run vectors:generate
 *
 * 產出檔進版控。test/vectors.test.ts 會確認實作改動不會悄悄改掉既有向量
 * —— 向量變了就等於所有已發布規則的 Hash 全變，那是破壞性變更。
 */

import { readFileSync, readdirSync, writeFileSync } from "node:fs";
import { canonicalize } from "../src/canonical.js";
import { contentHash } from "../src/hash.js";

interface Case {
  name: string;
  /** 這個案例在測什麼、為什麼容易錯 */
  why: string;
  input: unknown;
  canonical?: string;
  hash?: string;
}

const RULES_DIR = new URL("../test-vectors/rules/", import.meta.url);
const OUT = new URL("../test-vectors/canonical.json", import.meta.url);

// 排序案例用的非 ASCII 鍵。用 fromCharCode 產生而不是直接打字面字元 ——
// U+FB33 打成 U+05D3 + U+05BC 的組合序列的話，這個案例就失去意義了。
const DALET = String.fromCharCode(0xfb33); // 希伯來 dalet 預組合形，單一 code unit
const EMOJI = String.fromCodePoint(0x1f602); // surrogate pair，首個 code unit 0xD83D
const EURO = String.fromCharCode(0x20ac);

const primitives: Case[] = [
  {
    name: "integral-float",
    why: "PHP json_encode(21.0) 與 Python json.dumps(21.0) 都給 '21.0'，JCS 要求 '21'。COST 表滿滿都是這種值，這題錯了全部 Hash 都錯。",
    input: { teamCostLimit: 62.0, cost: 21.0, half: 18.5 },
  },
  {
    name: "key-order-ascii",
    why: "鍵必須排序，且是 code unit 序不是插入序。",
    input: { z: 1, a: 2, M: 3, _: 4, "0": 5 },
  },
  {
    name: "key-order-utf16-surrogate",
    why:
      "JCS 依 UTF-16 code unit 排序，不是 code point。" +
      "U+1F602(emoji) 首個 code unit 0xD83D=55357，小於 U+FB33 的 64307，所以 emoji 排在前面；" +
      "照 code point 排（Python 預設行為）會得到相反順序。" +
      "注意 U+FB33 必須是預組合形，寫成 dalet+dagesh 的組合序列就測不到這條界線。",
    input: { [EURO]: "euro", [DALET]: "hebrew-precomposed", [EMOJI]: "emoji", a: "ascii" },
  },
  {
    name: "negative-zero",
    why: "-0 與 0 在 JSON 是同一個值，JCS 規定輸出 '0'。",
    input: { a: -0, b: 0 },
  },
  {
    name: "string-escapes",
    why: "只跳脫必要字元；控制字元用 \\u 短碼；不得把非 ASCII 轉成 \\u（PHP 預設會，要加 JSON_UNESCAPED_UNICODE）。",
    input: { s: 'a"\\\b\f\n\r\t ä 中文 ' + EMOJI },
  },
  {
    name: "exponent-forms",
    why: "ES6 Number::toString 在 1e21 以上才用指數形式，小數則是 1e-7 以下。各語言的門檻不同。",
    input: { big: 1e21, justUnder: 1e20, small: 1e-7, justOver: 1e-6 },
  },
  {
    name: "nested-and-arrays",
    why: "陣列保持原順序（不排序），物件遞迴排序。",
    input: { b: [3, 1, 2, { y: 1, x: 2 }], a: { n: null, t: true, f: false } },
  },
  {
    name: "empty-containers",
    why: "空物件與空陣列不得產生多餘空白。",
    input: { o: {}, a: [], s: "" },
  },
];

function loadRuleCases(): Case[] {
  return readdirSync(RULES_DIR)
    .filter((f) => f.endsWith(".json"))
    .sort()
    .map((file) => ({
      name: `rule/${file.replace(/\.json$/, "")}`,
      why: "完整規則檔的端到端 Hash。ULGG 與插件對同一份規則必須算出同一個 contentHash。",
      input: JSON.parse(readFileSync(new URL(file, RULES_DIR), "utf8")) as unknown,
    }));
}

const cases = [...primitives, ...loadRuleCases()].map((c) => ({
  ...c,
  canonical: canonicalize(c.input),
  hash: contentHash(c.input),
}));

const doc = {
  $comment:
    "由 npm run vectors:generate 產生，請勿手改。任何語言的 Canonical JSON 實作都應通過這些案例：" +
    "對 input 做正規化後，字串必須完全等於 canonical，其 UTF-8 bytes 的 SHA-256 必須等於 hash。",
  spec: "RFC 8785 (JSON Canonicalization Scheme)",
  schemaVersion: 1,
  generatedBy: "@ulr/rule-schema",
  cases,
};

writeFileSync(OUT, `${JSON.stringify(doc, null, 2)}\n`, "utf8");
console.log(`寫入 ${cases.length} 個測試向量 → ${OUT.pathname}`);
for (const c of cases) console.log(`  ${c.hash.slice(7, 15)}  ${c.name}`);
