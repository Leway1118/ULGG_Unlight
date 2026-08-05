/**
 * 規則包信封（.ulrcost.json）
 * =============================
 * 規格書 §5.4 私人約測策略：
 *   - 副檔名 .ulrcost.json
 *   - 檔案內含 ruleSetId、testVersion、作者、內容、Hash 與適用遊戲版本
 *   - 雙方透過 Discord 傳同一檔案；插件顯示 Hash 前 8～12 碼供人工核對
 *
 * 為什麼要有信封：Hash 是「對規則內容算出來的」，把 Hash 塞進規則內容本身
 * 會變成雞生蛋。所以分兩層 —— 內層 rule 是被雜湊的對象，外層信封帶 Hash
 * 和傳輸用的中繼資料。
 */

import { contentHash, shortHash, verifyContentHash } from "./hash.js";
import type { CostRule } from "./types.js";
import { validateCostRule } from "./validate.js";

export const RULE_PACKAGE_EXTENSION = ".ulrcost.json";

export interface RulePackage {
  /** 信封格式版本，跟 rule.schemaVersion 是兩回事 */
  packageVersion: 1;
  /** 私人測試包標記。公開發布版由 ULGG 給 ruleVersionId，這裡沒有。 */
  visibility: "private-test";
  rule: CostRule;
  /** rule 的 canonical JSON 之 SHA-256，格式 "sha256:…" */
  contentHash: string;
  exportedAt: string;
  /** 匯出這個檔案的插件版本，方便追診斷 */
  exportedBy?: string;
}

export interface LoadedRulePackage {
  pkg: RulePackage;
  /** 給人眼核對用，例如 "7c91a23f" */
  short: string;
}

export type LoadResult =
  { ok: true; value: LoadedRulePackage } | { ok: false; code: LoadErrorCode; message: string };

export type LoadErrorCode =
  "package.malformed" | "package.unsupportedVersion" | "rule.invalid" | "hash.mismatch";

/** 把一份已驗證的規則打包成可以丟 Discord 的檔案內容 */
export function createRulePackage(
  rule: CostRule,
  options: { exportedBy?: string; now?: Date } = {},
): RulePackage {
  const pkg: RulePackage = {
    packageVersion: 1,
    visibility: "private-test",
    rule,
    contentHash: contentHash(rule),
    exportedAt: (options.now ?? new Date()).toISOString(),
  };
  if (options.exportedBy !== undefined) pkg.exportedBy = options.exportedBy;
  return pkg;
}

/**
 * 讀取規則包。
 *
 * §9 Rule Client 驗收要點：「內容不符 Hash 時拒絕載入」—— 所以 Hash 不符
 * 是硬失敗，不是警告。有人手改了 JSON 卻沒重算 Hash 時，雙方的規則其實
 * 已經不同，讓它載入進去會產生假的 VALID。
 */
export function loadRulePackage(input: unknown): LoadResult {
  if (typeof input !== "object" || input === null) {
    return { ok: false, code: "package.malformed", message: "規則包必須是 JSON 物件" };
  }

  const pkg = input as Partial<RulePackage>;

  if (pkg.packageVersion !== 1) {
    return {
      ok: false,
      code: "package.unsupportedVersion",
      message: `不支援的規則包版本 ${String(pkg.packageVersion)}，這個插件只認得 1`,
    };
  }

  if (typeof pkg.contentHash !== "string") {
    return { ok: false, code: "package.malformed", message: "缺少 contentHash" };
  }

  const validation = validateCostRule(pkg.rule);
  if (!validation.valid) {
    const lines = validation.issues.map((i) => `${i.path}: ${i.message}`).join("；");
    return { ok: false, code: "rule.invalid", message: `規則內容不合法 —— ${lines}` };
  }

  if (!verifyContentHash(validation.rule, pkg.contentHash)) {
    return {
      ok: false,
      code: "hash.mismatch",
      message:
        `內容與宣稱的 Hash 不符（宣稱 ${shortHash(pkg.contentHash)}，` +
        `實際 ${shortHash(contentHash(validation.rule))}）。` +
        `檔案可能被手動修改過而沒有重新匯出。`,
    };
  }

  return {
    ok: true,
    value: {
      pkg: { ...(pkg as RulePackage), rule: validation.rule },
      short: shortHash(pkg.contentHash),
    },
  };
}
