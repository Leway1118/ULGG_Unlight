/**
 * @ulr/rule-schema — WP-01：規則格式、正規化與內容雜湊
 *
 * 這個 package 是所有其他工作包的依賴（見規格書附錄 B）。它刻意不含任何
 * 網路、CDP 或 UI 程式碼 —— 純函式，方便跨語言對照與單元測試。
 */

export { canonicalize, canonicalBytes, CanonicalizationError } from "./canonical.js";

export {
  isValidCost,
  toCentiCost,
  fromCentiCost,
  sumCentiCost,
  formatCentiCost,
  CostPrecisionError,
  COST_DECIMAL_PLACES,
} from "./cost-number.js";
export type { Cost, CentiCost } from "./cost-number.js";

export {
  contentHash,
  shortHash,
  stripPrefix,
  hashEquals,
  verifyContentHash,
  SHORT_HASH_LENGTH,
} from "./hash.js";

export { validateCostRule, assertCostRule, costRuleSchema, SCHEMA_PATH } from "./validate.js";
export type { ValidationIssue, ValidationResult } from "./validate.js";

export { createRulePackage, loadRulePackage, RULE_PACKAGE_EXTENSION } from "./rule-package.js";
export type { RulePackage, LoadedRulePackage, LoadResult, LoadErrorCode } from "./rule-package.js";

export type {
  CostRule,
  CostTable,
  Publisher,
  AppliesTo,
  CompressionRule,
  GapBand,
  Restriction,
  RuleSetId,
  SchemaVersion,
} from "./types.js";
