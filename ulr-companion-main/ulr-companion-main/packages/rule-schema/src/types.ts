/**
 * COST 規則的型別定義（schemaVersion 1）
 * =======================================
 * 與 schema/cost-rule.schema.json 一一對應。改動任何一邊都要同步改另一邊，
 * test/schema.test.ts 會用同一份 test-vector 交叉檢查。
 *
 * 設計原則（規格書 §12）：規則內容只能驅動「既有、白名單化的計算分支」，
 * 不得轉譯為 eval / Function / 動態模組載入。所以所有具行為意義的欄位都是
 * 列舉值或數值表，沒有任何一個欄位是「會被解析執行的字串」。
 */

/** 目前唯一的 schema 版本 */
export type SchemaVersion = 1;

/** `publisherSlug/ruleSlug`，見 §5.1 */
export type RuleSetId = string;

export interface Publisher {
  /** 短代號，會出現在 ruleSetId 的前半，例如 "lampking" */
  id: string;
  /** 顯示名稱，例如 "燈皇" */
  name: string;
  /** Discord handle 之類的聯絡方式，選填 */
  contact?: string;
}

/** 這份規則打算用在哪種對戰 */
export type AppliesTo = "duel" | "quest" | "any";

/**
 * 壓 C 規則。
 *
 * 燈皇的規則：「目前是 差距7~13C +5C，可以追加 差距6C +1C，各種C的差距加多少C」
 * —— 也就是一張「COST 差距落在某區間 → 追加多少 COST」的對照表。
 * 刻意做成表而不是條件式，就是為了避免第一版就得寫條件引擎。
 */
export type CompressionRule = { type: "none" } | { type: "gap-band-v1"; bands: GapBand[] };

export interface GapBand {
  /** 區間下界（含） */
  minGap: number;
  /** 區間上界（含）。省略代表沒有上界 */
  maxGap?: number;
  /** 落在此區間時追加的 COST */
  extraCost: number;
}

/**
 * 限制條款。
 *
 * ⚠ enforcement 目前只允許 "agreement-only"：condition 是給人看的自由文字，
 * 引擎**永遠不會解析它**。要做成機器可強制的限制，必須先在 schemaVersion 2
 * 定義結構化的條件型別，不能靠解字串 —— 見 §12。
 */
export interface Restriction {
  /** 受限對象的 ID，例如某張事件卡 */
  target: string;
  /** 人類可讀的條件描述，例如 "range != near"。不被解析。 */
  condition: string;
  enforcement: "agreement-only";
  /** 補充說明，選填 */
  note?: string;
}

/** ID → COST 的對照表 */
export type CostTable = Record<string, number>;

/**
 * 一份 COST 規則的完整內容。
 * 這個物件（不含任何外層信封）就是 SHA-256 的計算對象。
 */
export interface CostRule {
  schemaVersion: SchemaVersion;
  ruleSetId: RuleSetId;
  /** SemVer，正式版不得重用（§5.1） */
  version: string;
  name: string;
  description?: string;
  publisher: Publisher;
  /** 這份 COST 表對應的遊戲版本，例如 "2026.07" */
  gameVersion: string;
  appliesTo?: AppliesTo;
  /** 隊伍 COST 上限 */
  teamCostLimit: number;
  /** 角色 → COST */
  characters: CostTable;
  /** 武器／裝備 → COST，選填 */
  equipment?: CostTable;
  /** 事件卡 → COST，選填 */
  eventCards?: CostTable;
  compressionRule?: CompressionRule;
  restrictions?: Restriction[];
  changelog?: string;
}
