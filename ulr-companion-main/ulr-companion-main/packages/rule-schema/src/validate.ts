/**
 * 規則驗證
 * ==========
 * 兩層：
 *   1. JSON Schema（結構、型別、範圍）
 *   2. 跨欄位規則 —— JSON Schema 表達不了的，例如「publisher.id 必須等於
 *      ruleSetId 的前半段」、「壓 C 區間不得重疊」
 *
 * 規格書 §9 Rule Client 驗收要點：「不得靜默產生錯誤資料」。所以驗證失敗一律
 * 回傳結構化的錯誤清單，呼叫端自己決定要顯示還是拒絕載入。
 */

import { readFileSync } from "node:fs";
import Ajv2020Module from "ajv/dist/2020.js";
import type { ValidateFunction } from "ajv";
import { COST_DECIMAL_PLACES, isValidCost } from "./cost-number.js";
import type { CostRule, GapBand } from "./types.js";

/**
 * ajv 是 CJS 套件。在 ESM + NodeNext 下：
 *   - 執行期的 default import 可能拿到 { default: Ajv } 而不是 Ajv 本身
 *   - 型別上 TS 會把它解析成 module namespace，沒有 construct signature
 * 所以執行期與型別都要各自處理。這裡只用到 compile，宣告最小介面即可。
 */
type AjvConstructor = new (options?: Record<string, unknown>) => {
  compile(schema: object): ValidateFunction;
};

const Ajv2020 = ((Ajv2020Module as unknown as { default?: unknown }).default ??
  Ajv2020Module) as unknown as AjvConstructor;

export const SCHEMA_PATH = new URL("../schema/cost-rule.schema.json", import.meta.url);

/** 原始 JSON Schema 物件。ULGG 端要拿去做伺服器側驗證的話用這個。 */
export const costRuleSchema: object = JSON.parse(readFileSync(SCHEMA_PATH, "utf8")) as object;

export interface ValidationIssue {
  /** JSON Pointer 風格的位置，例如 "/characters/WOLAND_L4" */
  path: string;
  message: string;
  /** 機器可判讀的錯誤碼，給 UI 對應文案用 */
  code: string;
}

export type ValidationResult =
  { valid: true; rule: CostRule } | { valid: false; issues: ValidationIssue[] };

let cached: ValidateFunction | null = null;

function compiled(): ValidateFunction {
  if (cached) return cached;
  const ajv = new Ajv2020({ allErrors: true, strict: true, allowUnionTypes: true });
  cached = ajv.compile(costRuleSchema);
  return cached;
}

/**
 * 驗證一份規則內容。
 * 通過的話回傳的 rule 就是原輸入（不做任何改寫 —— 改寫會改變 Hash）。
 */
export function validateCostRule(input: unknown): ValidationResult {
  const validate = compiled();
  const issues: ValidationIssue[] = [];

  if (!validate(input)) {
    for (const e of validate.errors ?? []) {
      issues.push({
        path: e.instancePath || "/",
        message: e.message ?? "不符合 schema",
        code: `schema.${e.keyword}`,
      });
    }
    return { valid: false, issues };
  }

  issues.push(...crossFieldIssues(input as CostRule));
  return issues.length === 0 ? { valid: true, rule: input as CostRule } : { valid: false, issues };
}

/**
 * COST 精度檢查。
 *
 * 不放進 JSON Schema，因為 `multipleOf: 0.01` 的實作是 `value / 0.01` 再判斷
 * 是否為整數，而 `4.35 / 0.01 === 434.99999999999994` —— 完全合法的值會被誤判。
 * 改成檢查十進位表示的小數位數，那是精確的，而且用的正是進 Hash 的同一個
 * 字串表示法。
 */
function costPrecisionIssues(rule: CostRule): ValidationIssue[] {
  const issues: ValidationIssue[] = [];

  const check = (value: number, path: string) => {
    if (!isValidCost(value)) {
      issues.push({
        path,
        message: `COST 值 ${String(value)} 超過 ${COST_DECIMAL_PLACES} 位小數精度`,
        code: "cost.precision",
      });
    }
  };

  check(rule.teamCostLimit, "/teamCostLimit");

  for (const table of ["characters", "equipment", "eventCards"] as const) {
    const entries = rule[table];
    if (!entries) continue;
    for (const [id, cost] of Object.entries(entries)) check(cost, `/${table}/${id}`);
  }

  if (rule.compressionRule?.type === "gap-band-v1") {
    rule.compressionRule.bands.forEach((b, i) => {
      check(b.minGap, `/compressionRule/bands/${i}/minGap`);
      if (b.maxGap !== undefined) check(b.maxGap, `/compressionRule/bands/${i}/maxGap`);
      check(b.extraCost, `/compressionRule/bands/${i}/extraCost`);
    });
  }

  return issues;
}

/** JSON Schema 表達不了的跨欄位條件 */
function crossFieldIssues(rule: CostRule): ValidationIssue[] {
  const issues: ValidationIssue[] = [...costPrecisionIssues(rule)];

  // publisher.id 必須是 ruleSetId 的前半段，否則 "a/x" 卻掛在 publisher b
  // 名下，作者歸屬會錯亂（§5.1 多作者命名空間）
  const prefix = rule.ruleSetId.split("/")[0];
  if (rule.publisher.id !== prefix) {
    issues.push({
      path: "/publisher/id",
      message: `publisher.id（${rule.publisher.id}）必須等於 ruleSetId 的前半段（${prefix}）`,
      code: "publisher.mismatch",
    });
  }

  if (rule.teamCostLimit <= 0) {
    issues.push({
      path: "/teamCostLimit",
      message: "隊伍 COST 上限必須大於 0",
      code: "teamCostLimit.nonPositive",
    });
  }

  if (rule.compressionRule?.type === "gap-band-v1") {
    issues.push(...gapBandIssues(rule.compressionRule.bands));
  }

  return issues;
}

/**
 * 壓 C 區間檢查。
 *
 * 燈皇的表是「差距 6C → +1C、差距 7~13C → +5C」這種形式。區間一旦重疊，
 * 同一個差距會對應到兩個追加值，Cost Engine 的結果就不是確定性的
 * —— 而 §9 明訂「同一規則與輸入必須得到確定性結果」。所以重疊直接擋掉。
 */
function gapBandIssues(bands: GapBand[]): ValidationIssue[] {
  const issues: ValidationIssue[] = [];

  bands.forEach((b, i) => {
    if (b.maxGap !== undefined && b.maxGap < b.minGap) {
      issues.push({
        path: `/compressionRule/bands/${i}`,
        message: `maxGap（${b.maxGap}）不得小於 minGap（${b.minGap}）`,
        code: "band.inverted",
      });
    }
  });

  // 只有一個 band 可以省略 maxGap（代表無上界），否則兩個無上界必然重疊
  const openEnded = bands.filter((b) => b.maxGap === undefined);
  if (openEnded.length > 1) {
    issues.push({
      path: "/compressionRule/bands",
      message: "最多只能有一個區間省略 maxGap（無上界）",
      code: "band.multipleOpenEnded",
    });
  }

  const sorted = [...bands].map((b, i) => ({ ...b, i })).sort((a, b) => a.minGap - b.minGap);

  for (let n = 1; n < sorted.length; n++) {
    const prev = sorted[n - 1]!;
    const cur = sorted[n]!;
    const prevEnd = prev.maxGap ?? Number.POSITIVE_INFINITY;
    if (cur.minGap <= prevEnd) {
      issues.push({
        path: `/compressionRule/bands/${cur.i}`,
        message:
          `區間 [${cur.minGap}, ${cur.maxGap ?? "∞"}] 與 ` +
          `[${prev.minGap}, ${prev.maxGap ?? "∞"}] 重疊，同一個 COST 差距會對應到兩個追加值`,
        code: "band.overlap",
      });
    }
  }

  return issues;
}

/** 驗證失敗時丟例外的版本，寫腳本方便用 */
export function assertCostRule(input: unknown): CostRule {
  const result = validateCostRule(input);
  if (!result.valid) {
    const lines = result.issues.map((i) => `  ${i.path}: ${i.message} [${i.code}]`);
    throw new Error(`規則驗證失敗：\n${lines.join("\n")}`);
  }
  return result.rule;
}
