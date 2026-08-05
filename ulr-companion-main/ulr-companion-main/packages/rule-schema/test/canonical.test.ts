import { describe, expect, it } from "vitest";
import { CanonicalizationError, canonicalBytes, canonicalize } from "@ulr/rule-schema";

// 排序測試專用的鍵。刻意用 fromCharCode 而不是字面字元或 \u 跳脫：
//   - U+FB33 是「希伯來 dalet 預組合形」，單一 code unit 0xFB33 = 64307。
//     直接打字面字元很容易變成 U+05D3 + U+05BC 的組合序列（首個 code unit
//     才 0x05D3），那就完全測不到 surrogate 這條界線 —— 本測試第一版就是
//     這樣寫錯的，跑起來永遠會過但什麼都沒驗到。
//   - U+1F602 是 emoji，UTF-16 表示為 surrogate pair，首個 code unit
//     0xD83D = 55357。
// 原始碼保持純 ASCII，就不會被編輯器、剪貼簿或 Unicode 正規化偷改。
const DALET = String.fromCharCode(0xfb33);
const EMOJI = String.fromCodePoint(0x1f602);

describe("canonicalize", () => {
  it("排序物件鍵，且不留任何空白", () => {
    expect(canonicalize({ z: 1, a: 2, m: 3 })).toBe('{"a":2,"m":3,"z":1}');
  });

  it("陣列維持原順序", () => {
    expect(canonicalize([3, 1, 2])).toBe("[3,1,2]");
  });

  it("整數值的 float 不帶 .0（跨語言最常錯的一題）", () => {
    expect(canonicalize({ cost: 21.0 })).toBe('{"cost":21}');
    expect(canonicalize({ cost: 18.5 })).toBe('{"cost":18.5}');
  });

  it("-0 正規化成 0", () => {
    expect(canonicalize(-0)).toBe("0");
  });

  it("依 UTF-16 code unit 排序，不是 code point", () => {
    // UTF-16 序：0xD83D(55357) < 0xFB33(64307) → emoji 在前
    // code point 序（Python 預設）：64307 < 128514 → 反過來
    expect(DALET).toHaveLength(1);
    expect(EMOJI).toHaveLength(2);
    expect(EMOJI.charCodeAt(0)).toBeLessThan(DALET.charCodeAt(0));

    const out = canonicalize({ [EMOJI]: 1, [DALET]: 2 });
    expect(out.indexOf(EMOJI)).toBeLessThan(out.indexOf(DALET));
  });

  it("非 ASCII 原樣輸出，不轉成 \\u", () => {
    expect(canonicalize({ n: "亞城" })).toBe('{"n":"亞城"}');
  });

  it("忽略物件裡值為 undefined 的屬性", () => {
    expect(canonicalize({ a: 1, b: undefined })).toBe('{"a":1}');
  });

  it("同樣內容不同排版得到同一個字串", () => {
    const a = { ruleSetId: "x/y", version: "1.0.0", teamCostLimit: 62.0 };
    const b = { teamCostLimit: 62, ruleSetId: "x/y", version: "1.0.0" };
    expect(canonicalize(a)).toBe(canonicalize(b));
  });

  it("canonicalBytes 是 UTF-8", () => {
    expect(canonicalBytes("亞")).toEqual(new Uint8Array([0x22, 0xe4, 0xba, 0x9e, 0x22]));
  });

  describe("拒絕會造成靜默資料損壞的輸入", () => {
    it("NaN / Infinity（JSON.stringify 會變成 null）", () => {
      expect(() => canonicalize({ cost: NaN })).toThrow(CanonicalizationError);
      expect(() => canonicalize({ cost: Infinity })).toThrow(CanonicalizationError);
    });

    it("陣列裡的 undefined（JSON.stringify 會變成 null）", () => {
      expect(() => canonicalize([1, undefined, 3])).toThrow(CanonicalizationError);
    });

    it("bigint 與函式", () => {
      expect(() => canonicalize({ a: 1n })).toThrow(CanonicalizationError);
      expect(() => canonicalize({ a: () => 1 })).toThrow(CanonicalizationError);
    });

    it("錯誤訊息指出出錯位置", () => {
      expect(() => canonicalize({ characters: { WOLAND_L4: NaN } })).toThrow(
        /\$\.characters\.WOLAND_L4/,
      );
    });
  });
});
