/**
 * COST 數值：0.01 精度，但運算一律用整數
 * ==========================================
 * 規則檔裡的 COST 寫成人看得懂的十進位數字（`21.35`），但**任何加總都必須
 * 先轉成整數的百分之一**（centi-COST，`2135`）再算。
 *
 * 為什麼分兩層：
 *
 * 1. **存檔與雜湊沒有精度問題。** JSON 的 `21.35` 解析成最接近的 double，
 *    JCS 再用「最短 round-trip 表示」序列化回 `"21.35"`。這個來回是確定性的，
 *    所以 contentHash 穩定。
 *
 * 2. **加總會出事。** `21.35 + 18.9` 在 IEEE 754 下是 40.25000000000001。
 *    隊伍總 COST 拿去跟上限比大小時，這種尾巴會讓「剛好卡上限」的隊伍
 *    被判超標。所以引擎內部必須是整數。
 *
 * 3. **轉換不能用 `n * 100`。** `21.35 * 100` 得到 2134.9999999999998。
 *    這裡改從十進位字串取數字 —— 而那個字串正是進 Hash 的同一個表示法，
 *    所以「驗證通過的值」與「算得出來的值」定義上一致。
 */

/** 對外的 COST 值（規則檔裡的樣子） */
export type Cost = number;

/** 內部運算用的整數百分之一。21.35 → 2135 */
export type CentiCost = number;

/** 允許的最大小數位數 */
export const COST_DECIMAL_PLACES = 2;

/** 只接受不帶指數的十進位，最多兩位小數 */
const DECIMAL = /^(-?)(\d+)(?:\.(\d{1,2}))?$/;

export class CostPrecisionError extends Error {
  override readonly name = "CostPrecisionError";
  constructor(readonly value: number) {
    super(
      `COST 值 ${String(value)} 超過 ${COST_DECIMAL_PLACES} 位小數精度。` +
        `規則檔的 COST 最多兩位小數，且不得使用指數形式。`,
    );
  }
}

/** 這個數值可以當 COST 嗎（有限、最多兩位小數、非指數形式） */
export function isValidCost(value: number): boolean {
  return Number.isFinite(value) && DECIMAL.test(String(value));
}

/**
 * 十進位 COST → 整數百分之一。精確，不做浮點乘法。
 * @throws CostPrecisionError 值不合精度時
 */
export function toCentiCost(value: number): CentiCost {
  const m = Number.isFinite(value) ? DECIMAL.exec(String(value)) : null;
  if (!m) throw new CostPrecisionError(value);

  const sign = m[1] === "-" ? -1 : 1;
  const whole = Number(m[2]);
  // "5" 要當成 .50 不是 .05
  const frac = Number((m[3] ?? "").padEnd(COST_DECIMAL_PLACES, "0"));
  return sign * (whole * 100 + frac);
}

/**
 * 整數百分之一 → 十進位 COST。
 * 除以 100 得到的是最接近的 double，跟直接解析 "21.35" 是同一個值
 * （IEEE 754 除法是正確捨入，2135 與 100 都可精確表示）。
 */
export function fromCentiCost(centi: CentiCost): Cost {
  return centi / 100;
}

/** 整數加總。用它取代直接對 COST 做 `+`。 */
export function sumCentiCost(values: readonly CentiCost[]): CentiCost {
  let total = 0;
  for (const v of values) total += v;
  return total;
}

/** 顯示用字串，固定兩位小數：2135 → "21.35" */
export function formatCentiCost(centi: CentiCost): string {
  const sign = centi < 0 ? "-" : "";
  const abs = Math.abs(centi);
  return `${sign}${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, "0")}`;
}
