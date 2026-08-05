/**
 * 內容雜湊 —— 規則的身分
 * ==========================
 * 規格書 §5.2：Rule JSON → Schema 驗證 → Canonical JSON → SHA-256 → 寫入不可變 rule_version
 *
 * 表示法統一用 "sha256:" 前綴，對齊 §8.2 API 契約裡的 "contentHash": "sha256:7c91…"。
 */

import { createHash } from "node:crypto";
import { canonicalize } from "./canonical.js";

/** §5.4：插件顯示 Hash 前 8～12 碼供人工核對，這裡取 8 */
export const SHORT_HASH_LENGTH = 8;

/**
 * 算出規則內容的 SHA-256。
 *
 * 注意輸入應該是「規則內容本身」，不含 contentHash 欄位 —— 規則檔裡放自己的
 * Hash 會變成雞生蛋問題。Hash 住在外層的規則包信封（見 rule-package.ts）。
 */
export function contentHash(value: unknown): string {
  const canonical = canonicalize(value);
  const digest = createHash("sha256").update(canonical, "utf8").digest("hex");
  return `sha256:${digest}`;
}

/** 給人眼核對用的短碼，例如 "7c91a23f" */
export function shortHash(hash: string, length = SHORT_HASH_LENGTH): string {
  return stripPrefix(hash).slice(0, length);
}

/** 去掉 "sha256:" 前綴，拿到純 hex */
export function stripPrefix(hash: string): string {
  return hash.startsWith("sha256:") ? hash.slice("sha256:".length) : hash;
}

/**
 * 比對 Hash。用常數時間比較，避免把驗證變成計時側channel。
 * （這裡的威脅模型很弱，但成本也接近零。）
 */
export function hashEquals(a: string, b: string): boolean {
  const x = stripPrefix(a).toLowerCase();
  const y = stripPrefix(b).toLowerCase();
  if (x.length !== y.length) return false;
  let diff = 0;
  for (let i = 0; i < x.length; i++) diff |= x.charCodeAt(i) ^ y.charCodeAt(i);
  return diff === 0;
}

/**
 * 驗證內容確實對應到宣稱的 Hash。
 * §9 Rule Client 驗收要點：「內容不符 Hash 時拒絕載入」。
 */
export function verifyContentHash(value: unknown, expected: string): boolean {
  return hashEquals(contentHash(value), expected);
}
