import { readFileSync } from "node:fs";
import { describe, expect, it } from "vitest";
import { createRulePackage, loadRulePackage, shortHash } from "@ulr/rule-schema";
import type { CostRule } from "@ulr/rule-schema";

const SAMPLE = new URL("../test-vectors/rules/arcadia-balance-1.2.0.json", import.meta.url);
const rule = () => JSON.parse(readFileSync(SAMPLE, "utf8")) as CostRule;

/** 模擬走一趟 Discord：匯出成檔案文字，對方再讀進來 */
const roundTrip = (pkg: unknown) => loadRulePackage(JSON.parse(JSON.stringify(pkg)));

describe("規則包 .ulrcost.json", () => {
  it("匯出後再匯入，內容與 Hash 都對得上", () => {
    const pkg = createRulePackage(rule(), { exportedBy: "ulr-companion/0.0.0" });
    const r = roundTrip(pkg);
    expect(r.ok).toBe(true);
    if (r.ok) {
      expect(r.value.pkg.rule).toEqual(rule());
      expect(r.value.short).toBe(shortHash(pkg.contentHash));
      expect(r.value.short).toHaveLength(8);
    }
  });

  it("雙方拿到同一份規則就算出同一個短碼（§5.4 人工核對）", () => {
    const a = createRulePackage(rule());
    const b = createRulePackage(rule(), { exportedBy: "另一台電腦" });
    expect(shortHash(a.contentHash)).toBe(shortHash(b.contentHash));
  });

  it("有人手改 JSON 卻沒重算 Hash → 拒絕載入", () => {
    const pkg = createRulePackage(rule());
    pkg.rule.characters["WOLAND_L4"] = 15; // 偷改成本
    const r = roundTrip(pkg);
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.code).toBe("hash.mismatch");
  });

  it("規則內容本身不合法 → 在檢查 Hash 之前就擋下", () => {
    const pkg = createRulePackage(rule());
    pkg.rule.version = "not-semver";
    const r = roundTrip(pkg);
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.code).toBe("rule.invalid");
  });

  it("未來版本的規則包 → 明確說不支援，而不是硬吃", () => {
    const pkg = { ...createRulePackage(rule()), packageVersion: 99 };
    const r = roundTrip(pkg);
    expect(r.ok).toBe(false);
    if (!r.ok) expect(r.code).toBe("package.unsupportedVersion");
  });

  it("垃圾輸入不會 crash", () => {
    for (const bad of [null, 42, "字串", [], {}]) {
      expect(loadRulePackage(bad).ok).toBe(false);
    }
  });
});
