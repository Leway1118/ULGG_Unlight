import { describe, expect, it } from "vitest";
import {
  CostPrecisionError,
  formatCentiCost,
  fromCentiCost,
  isValidCost,
  sumCentiCost,
  toCentiCost,
} from "@ulr/rule-schema";

describe("COST 數值", () => {
  describe("isValidCost", () => {
    it("接受最多兩位小數", () => {
      for (const v of [0, 21, 21.5, 21.35, -3.07, 999, 0.01]) {
        expect(isValidCost(v), String(v)).toBe(true);
      }
    });

    it("拒絕超過兩位小數與非有限數", () => {
      for (const v of [21.353, 0.001, 1 / 3, NaN, Infinity, -Infinity]) {
        expect(isValidCost(v), String(v)).toBe(false);
      }
    });

    it("拒絕指數形式（String(1e21) 是 '1e+21'，取不出小數位）", () => {
      expect(isValidCost(1e21)).toBe(false);
      expect(isValidCost(1e-7)).toBe(false);
    });
  });

  describe("toCentiCost", () => {
    it("轉換精確 —— 用 n*100 會壞的那些值", () => {
      // 21.35 * 100 === 2134.9999999999998
      expect(toCentiCost(21.35)).toBe(2135);
      // 4.35 * 100 === 434.99999999999994
      expect(toCentiCost(4.35)).toBe(435);
      // 1.005 * 100 === 100.49999999999999（乘法版會捨成 100，錯 0.05）
      expect(toCentiCost(1.01)).toBe(101);
    });

    it("一位小數當成 .X0 不是 .0X", () => {
      expect(toCentiCost(21.5)).toBe(2150);
      expect(toCentiCost(21.05)).toBe(2105);
    });

    it("整數與負數", () => {
      expect(toCentiCost(21)).toBe(2100);
      expect(toCentiCost(0)).toBe(0);
      expect(toCentiCost(-3.07)).toBe(-307);
    });

    it("精度不合時丟錯，不是默默捨去", () => {
      expect(() => toCentiCost(21.353)).toThrow(CostPrecisionError);
      expect(() => toCentiCost(NaN)).toThrow(CostPrecisionError);
    });
  });

  it("fromCentiCost 是 toCentiCost 的逆運算", () => {
    for (const v of [0, 21, 21.5, 21.35, -3.07, 999, 0.01]) {
      expect(fromCentiCost(toCentiCost(v))).toBe(v);
    }
  });

  // 8.8 + 26.6 + 26.6 在 IEEE 754 下是 62.00000000000001，不是 62。
  // 三個都是合法的兩位小數 COST，總和「應該」剛好卡滿 62 的上限。
  // 這組數字是實際跑出來確認過的 —— 別憑感覺挑，多數看起來危險的組合
  // （20.1+20.1+21.8、21.35+18.9）其實剛好是精確的。
  const TEAM = [8.8, 26.6, 26.6];
  const LIMIT = 62;

  it("整數加總沒有浮點尾巴 —— 這才是分兩層的理由", () => {
    expect(TEAM.reduce((a, b) => a + b, 0)).toBe(62.00000000000001);

    const total = sumCentiCost(TEAM.map(toCentiCost));
    expect(total).toBe(6200);
    expect(fromCentiCost(total)).toBe(LIMIT);
  });

  it("剛好卡上限的隊伍不會被誤判超標", () => {
    const total = sumCentiCost(TEAM.map(toCentiCost));
    expect(total > toCentiCost(LIMIT)).toBe(false);

    // 對照組：用 double 直接加，同一支隊伍會被判超標
    expect(TEAM.reduce((a, b) => a + b, 0) > LIMIT).toBe(true);
  });

  it("formatCentiCost 固定兩位小數", () => {
    expect(formatCentiCost(2135)).toBe("21.35");
    expect(formatCentiCost(2100)).toBe("21.00");
    expect(formatCentiCost(2105)).toBe("21.05");
    expect(formatCentiCost(-307)).toBe("-3.07");
    expect(formatCentiCost(0)).toBe("0.00");
  });
});
