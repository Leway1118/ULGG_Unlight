/**
 * ULR Companion —— 佔位
 * =======================
 * 這裡還不是 Electron app。規格書 §10.3 建議 TypeScript + Electron +
 * electron-builder（NSIS），但那是 WP-08 的範圍，先不引入依賴。
 *
 * 目前只有一支能跑的煙霧測試，用來確認 workspace 串得起來：
 *
 *     npx tsx apps/companion/src/index.ts path/to/rule.ulrcost.json
 *
 * 接手 WP-08 的人請從這裡開始，並先讀 docs/open-questions.md。
 */

import { readFileSync } from "node:fs";
import { loadRulePackage, shortHash, contentHash, assertCostRule } from "@ulr/rule-schema";
import { BROWSER_DEBUG_PORT, DEBUG_PORT_SWITCH, DEFAULT_DEBUG_PORT } from "@ulr/cdp-adapter";
import { API_SCHEMA_VERSION } from "@ulr/api-contract";

function main(argv: string[]): number {
  const path = argv[2];
  if (path === undefined) {
    console.log("ULR Companion（骨架）");
    console.log(`  API schema 版本 : ${API_SCHEMA_VERSION}`);
    console.log(`  桌面版 CDP 埠   : ${DEFAULT_DEBUG_PORT}`);
    console.log(`  網頁版 CDP 埠   : ${BROWSER_DEBUG_PORT}`);
    console.log(`  啟動參數        : ${DEBUG_PORT_SWITCH}`);
    console.log("\n用法：tsx apps/companion/src/index.ts <規則檔.json 或 .ulrcost.json>");
    return 0;
  }

  const raw: unknown = JSON.parse(readFileSync(path, "utf8"));

  // 兩種都收：規則包信封，或裸的規則內容
  if (typeof raw === "object" && raw !== null && "packageVersion" in raw) {
    const result = loadRulePackage(raw);
    if (!result.ok) {
      console.error(`✗ 載入失敗 [${result.code}] ${result.message}`);
      return 1;
    }
    const { pkg, short } = result.value;
    console.log(`✓ ${pkg.rule.name} ${pkg.rule.version}  (${short})`);
    console.log(`  發布者   ${pkg.rule.publisher.name}`);
    console.log(`  上限     ${pkg.rule.teamCostLimit}`);
    console.log(`  角色     ${Object.keys(pkg.rule.characters).length} 筆`);
    return 0;
  }

  const rule = assertCostRule(raw);
  console.log(`✓ ${rule.name} ${rule.version}  (${shortHash(contentHash(rule))})`);
  return 0;
}

process.exitCode = main(process.argv);
