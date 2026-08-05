import { describe, expect, it } from "vitest";
import { contentHash, hashEquals, shortHash, verifyContentHash } from "@ulr/rule-schema";

describe("contentHash", () => {
  it("帶 sha256: 前綴與 64 碼 hex", () => {
    expect(contentHash({ a: 1 })).toMatch(/^sha256:[0-9a-f]{64}$/);
  });

  it("只改排版不改內容 → 同一個 Hash（§5.2 的核心要求）", () => {
    expect(contentHash({ b: 2, a: 1 })).toBe(contentHash({ a: 1, b: 2 }));
    expect(contentHash({ cost: 21.0 })).toBe(contentHash({ cost: 21 }));
  });

  it("內容真的改了 → 不同 Hash", () => {
    expect(contentHash({ cost: 21 })).not.toBe(contentHash({ cost: 21.5 }));
  });

  it("對照 RFC 6234 的已知值，確認是標準 SHA-256 而非自訂變體", () => {
    // 空物件的 canonical 形式是 "{}"，SHA-256("{}") 是公開可查的值
    expect(contentHash({})).toBe(
      "sha256:44136fa355b3678a1146ad16f7e8649e94fb4fc21fe77e8310c060f61caaff8a",
    );
  });

  it("shortHash 給人眼核對用（§5.4）", () => {
    expect(shortHash("sha256:7c91a23f0000")).toBe("7c91a23f");
    expect(shortHash("7c91a23f0000")).toBe("7c91a23f");
    expect(shortHash("sha256:7c91a23f0000", 12)).toBe("7c91a23f0000");
  });

  it("hashEquals 忽略前綴與大小寫", () => {
    expect(hashEquals("sha256:ABCD", "abcd")).toBe(true);
    expect(hashEquals("sha256:abcd", "abce")).toBe(false);
    expect(hashEquals("abcd", "abcde")).toBe(false);
  });

  it("verifyContentHash 抓得到被竄改的內容", () => {
    const rule = { cost: 21 };
    const h = contentHash(rule);
    expect(verifyContentHash(rule, h)).toBe(true);
    expect(verifyContentHash({ cost: 22 }, h)).toBe(false);
  });
});
