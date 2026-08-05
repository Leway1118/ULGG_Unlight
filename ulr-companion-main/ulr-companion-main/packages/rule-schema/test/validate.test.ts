import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import { validateCostRule } from "@ulr/rule-schema";
import type { CostRule } from "@ulr/rule-schema";

const SAMPLE = new URL("../test-vectors/rules/arcadia-balance-1.2.0.json", import.meta.url);

function sample(): CostRule {
  return JSON.parse(readFileSync(SAMPLE, "utf8")) as CostRule;
}

/** 取得驗證失敗的錯誤碼，方便斷言 */
function codes(input: unknown): string[] {
  const r = validateCostRule(input);
  return r.valid ? [] : r.issues.map((i) => i.code);
}

describe("validateCostRule", () => {
  it("附錄 A 的範例規則通過驗證", () => {
    const r = validateCostRule(sample());
    expect(r.valid, r.valid ? "" : JSON.stringify(r.issues, null, 2)).toBe(true);
  });

  it("通過時原樣回傳，不做任何改寫（改寫會改變 Hash）", () => {
    const input = sample();
    const r = validateCostRule(input);
    expect(r.valid && r.rule).toBe(input);
  });

  describe("結構", () => {
    it("擋掉未知欄位，避免打錯字被靜默忽略", () => {
      expect(codes({ ...sample(), teamCostLimt: 62 })).toContain("schema.additionalProperties");
    });

    it("缺必填欄位", () => {
      const r = sample() as Partial<CostRule>;
      delete r.teamCostLimit;
      expect(codes(r)).toContain("schema.required");
    });

    it("ruleSetId 必須是 publisher/slug 形式", () => {
      expect(codes({ ...sample(), ruleSetId: "arcadia-balance" })).toContain("schema.pattern");
    });

    it("version 必須是 SemVer", () => {
      expect(codes({ ...sample(), version: "1.2" })).toContain("schema.pattern");
    });

    it("COST 接受兩位小數", () => {
      const r = sample();
      r.characters["FINE"] = 21.35;
      r.teamCostLimit = 62.25;
      expect(validateCostRule(r).valid).toBe(true);
    });

    it("COST 超過兩位小數 → 擋掉", () => {
      const r = sample();
      r.characters["ODD"] = 21.353;
      expect(codes(r)).toContain("cost.precision");
    });

    it("精度檢查涵蓋所有 COST 欄位，不只 characters", () => {
      const r = sample();
      r.teamCostLimit = 62.001;
      r.compressionRule = {
        type: "gap-band-v1",
        bands: [{ minGap: 6, maxGap: 6, extraCost: 1.234 }],
      };
      const found = codes(r);
      expect(found.filter((c) => c === "cost.precision")).toHaveLength(2);
    });

    it("restrictions 的 enforcement 只能是 agreement-only（§12）", () => {
      const r = sample();
      r.restrictions = [
        {
          target: "EX_DOMINION",
          condition: "range != near",
          // @ts-expect-error 故意給不合法的值
          enforcement: "auto-block",
        },
      ];
      expect(codes(r)).toContain("schema.const");
    });

    it("compressionRule.type 是白名單，不接受任意字串", () => {
      const r = sample();
      // @ts-expect-error 故意給不在白名單的 type
      r.compressionRule = { type: "eval-this", bands: [] };
      expect(codes(r).some((c) => c.startsWith("schema."))).toBe(true);
    });
  });

  describe("跨欄位規則", () => {
    it("publisher.id 必須等於 ruleSetId 的前半段", () => {
      const r = sample();
      r.publisher.id = "someone-else";
      expect(codes(r)).toContain("publisher.mismatch");
    });

    it("壓 C 區間不得重疊（否則計算結果不確定）", () => {
      const r = sample();
      r.compressionRule = {
        type: "gap-band-v1",
        bands: [
          { minGap: 6, maxGap: 10, extraCost: 1 },
          { minGap: 8, maxGap: 13, extraCost: 5 },
        ],
      };
      expect(codes(r)).toContain("band.overlap");
    });

    it("相鄰但不重疊的區間可以通過", () => {
      const r = sample();
      r.compressionRule = {
        type: "gap-band-v1",
        bands: [
          { minGap: 6, maxGap: 6, extraCost: 1 },
          { minGap: 7, maxGap: 13, extraCost: 5 },
          { minGap: 14, extraCost: 8 },
        ],
      };
      expect(validateCostRule(r).valid).toBe(true);
    });

    it("maxGap 不得小於 minGap", () => {
      const r = sample();
      r.compressionRule = {
        type: "gap-band-v1",
        bands: [{ minGap: 10, maxGap: 5, extraCost: 1 }],
      };
      expect(codes(r)).toContain("band.inverted");
    });

    it("最多一個無上界區間", () => {
      const r = sample();
      r.compressionRule = {
        type: "gap-band-v1",
        bands: [
          { minGap: 6, extraCost: 1 },
          { minGap: 20, extraCost: 5 },
        ],
      };
      expect(codes(r)).toContain("band.multipleOpenEnded");
    });
  });

  it("一次回報所有問題，不是遇到第一個就停", () => {
    const r = sample();
    r.publisher.id = "wrong";
    r.teamCostLimit = -1;
    const found = codes(r);
    expect(found).toContain("publisher.mismatch");
    expect(found).toContain("teamCostLimit.nonPositive");
  });
});
