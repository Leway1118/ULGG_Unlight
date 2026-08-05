/**
 * 測試向量回歸鎖
 * ================
 * canonical.json 是跨語言的共同真相。它一旦變動，所有已發布規則的 contentHash
 * 就全部失效 —— 那是破壞性變更，必須是刻意的（改 schemaVersion、發公告），
 * 不能是誰改了 canonical.ts 的順手結果。
 *
 * 所以這個測試不是「重算一次比對」而已，它同時鎖住向量檔本身：
 * 要讓它通過，你得跑 `npm run vectors:generate` 並把 diff 一起送審。
 */

import { readFileSync } from "node:fs";
import { createHash } from "node:crypto";
import { describe, expect, it } from "vitest";
import { canonicalize, contentHash } from "@ulr/rule-schema";

interface Vector {
  name: string;
  why: string;
  input: unknown;
  canonical: string;
  hash: string;
}

const doc = JSON.parse(
  readFileSync(new URL("../test-vectors/canonical.json", import.meta.url), "utf8"),
) as { cases: Vector[] };

describe("跨語言測試向量", () => {
  it("向量檔不是空的", () => {
    expect(doc.cases.length).toBeGreaterThan(5);
  });

  it.each(doc.cases.map((c) => [c.name, c] as const))("%s", (_name, c) => {
    expect(canonicalize(c.input), c.why).toBe(c.canonical);
    expect(contentHash(c.input), c.why).toBe(c.hash);
  });

  it("hash 欄位確實是 canonical 字串的 UTF-8 SHA-256", () => {
    // 這條是給其他語言的實作者看的：可以只比對 canonical，hash 自己算得出來
    for (const c of doc.cases) {
      const digest = createHash("sha256").update(c.canonical, "utf8").digest("hex");
      expect(`sha256:${digest}`).toBe(c.hash);
    }
  });
});
