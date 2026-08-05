/**
 * Canonical JSON — RFC 8785 (JSON Canonicalization Scheme, JCS)
 * ==============================================================
 * 規格書 §5.2：「不得直接對排版任意的 JSON 字串計算 Hash，否則只改空白或
 * 欄位順序就會產生不同值。發布程序須先執行確定性的 JSON 正規化（鍵值排序、
 * UTF-8、消除非語義空白、統一數值表示），再計算 SHA-256。」
 *
 * 這四項要求就是 RFC 8785 的定義，所以直接採用標準而不自訂格式 —— ULGG 端
 * （PHP）與任何第三方工具都能找到現成實作對照。
 *
 * ---------------------------------------------------------------------------
 * 跨語言最容易踩的雷：數值表示
 * ---------------------------------------------------------------------------
 * JCS 規定數值用 ECMAScript 的 Number::toString，也就是「能 round-trip 的
 * 最短表示」。JS 的 String(21.0) 得到 "21"，但：
 *
 *     PHP     json_encode(21.0)  → "21.0"      ✗
 *     Python  json.dumps(21.0)   → "21.0"      ✗
 *
 * 兩邊都會多一個 ".0"，Hash 就對不起來了。實作其他語言版本時，整數值的
 * float 必須輸出成不帶小數點的形式。test-vectors/canonical.json 裡有專門
 * 針對這點的案例，請務必讓你的實作通過。
 */

export class CanonicalizationError extends Error {
  override readonly name = "CanonicalizationError";
  constructor(
    message: string,
    readonly path: string,
  ) {
    super(`${message}（位置 ${path}）`);
  }
}

/**
 * 把值序列化成 RFC 8785 canonical JSON 字串。
 *
 * 接受的是「JSON 資料模型」：物件、陣列、字串、有限數值、布林、null。
 * 刻意不接受的東西一律丟 CanonicalizationError 而不是默默轉換 —— 這個字串
 * 會變成規則的身分，任何靜默的轉換都可能讓兩份不同內容產生同一個 Hash。
 */
export function canonicalize(value: unknown): string {
  return write(value, "$");
}

/** canonical 字串的 UTF-8 bytes，也就是實際拿去算 SHA-256 的東西 */
export function canonicalBytes(value: unknown): Uint8Array {
  return new TextEncoder().encode(canonicalize(value));
}

function write(value: unknown, path: string): string {
  if (value === null) return "null";

  switch (typeof value) {
    case "boolean":
      return value ? "true" : "false";
    case "number":
      return writeNumber(value, path);
    case "string":
      return JSON.stringify(value);
    case "object":
      return Array.isArray(value)
        ? writeArray(value, path)
        : writeObject(value as Record<string, unknown>, path);
    case "undefined":
      throw new CanonicalizationError("undefined 不是合法的 JSON 值", path);
    case "bigint":
      throw new CanonicalizationError("bigint 無法用 JSON 數值表示", path);
    default:
      throw new CanonicalizationError(`不支援的型別 ${typeof value}`, path);
  }
}

function writeNumber(value: number, path: string): string {
  if (!Number.isFinite(value)) {
    // JSON.stringify 會把 NaN/Infinity 變成 null。COST 欄位被靜默換成 null
    // 是災難等級的錯誤，這裡一定要吵。
    throw new CanonicalizationError(`數值必須是有限數，收到 ${String(value)}`, path);
  }
  // -0 與 0 在 JSON 裡是同一個值，JCS 規定輸出 "0"
  if (Object.is(value, -0)) return "0";
  return String(value);
}

function writeArray(value: unknown[], path: string): string {
  const parts = value.map((item, i) => {
    const p = `${path}[${i}]`;
    if (item === undefined) {
      // JSON.stringify 這裡會給 null。陣列長度有意義（例如 bands 清單），
      // 把缺漏變成 null 會產生看起來合法但語義錯誤的規則。
      throw new CanonicalizationError("陣列元素不得為 undefined", p);
    }
    return write(item, p);
  });
  return `[${parts.join(",")}]`;
}

function writeObject(value: Record<string, unknown>, path: string): string {
  // JCS：鍵依 UTF-16 code unit 排序。JS 字串的 < 運算子正好就是 code unit
  // 比較，所以不需要自訂 collator（用 localeCompare 反而會錯）。
  const keys = Object.keys(value)
    .filter((k) => value[k] !== undefined) // 對齊 JSON.stringify：物件的 undefined 屬性視為不存在
    .sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));

  const parts = keys.map((k) => `${JSON.stringify(k)}:${write(value[k], `${path}.${k}`)}`);
  return `{${parts.join(",")}}`;
}
