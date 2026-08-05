/**
 * ULGG API 契約（規格書 §8）
 * ============================
 * 這個 package **只有型別，沒有實作**。它的用途是讓插件端與 ULGG 端對同一份
 * 欄位定義寫程式 —— §15 說「這些契約一旦穩定，插件與 ULGG 才能平行開發而
 * 不反覆重寫」。
 *
 * 每個型別上的註解就是給 ULGG 維護者的規格；欄位有疑問請開 issue 討論，
 * 不要各自在自己那邊加欄位。
 *
 * 狀態：草案。§14.1 列的待確認事項還沒回覆，標 TODO 的地方會變。
 */

/** API 路徑前綴 */
export const API_BASE_PATH = "/api/v1";

/** 這份契約自己的版本，對應 client-compatibility 的 apiSchemaVersion */
export const API_SCHEMA_VERSION = 1;

// ---------------------------------------------------------------------------
// §8.1 公開規則讀取
// ---------------------------------------------------------------------------

/** ULGG 指派的不可變版本主鍵，例如 "rv_01…"。match_results 要引用這個而不是版本名稱（§7）。 */
export type RuleVersionId = string;

export type RuleStatus = "active" | "deprecated" | "withdrawn" | "blocked";
export type RuleVisibility = "public" | "withdrawn";

/** GET /api/v1/cost-rules 的單筆 */
export interface RuleSetSummary {
  ruleSetId: string;
  name: string;
  publisher: { id: string; name: string };
  status: RuleStatus;
  latestVersion: string;
  latestVersionId: RuleVersionId;
  gameVersion: string;
  updatedAt: string;
}

/** GET /api/v1/cost-rule-versions/{versionId} */
export interface RuleVersionDetail {
  ruleVersionId: RuleVersionId;
  ruleSetId: string;
  version: string;
  /** contentHash 的計算對象，型別是 @ulr/rule-schema 的 CostRule */
  content: unknown;
  contentHash: string;
  gameVersion: string;
  status: RuleStatus;
  publishedAt: string;
}

/** GET /api/v1/cost-rules/manifest —— 插件啟動時拿這個比對本機快取 */
export interface RuleManifest {
  generatedAt: string;
  entries: RuleManifestEntry[];
}

export interface RuleManifestEntry {
  ruleSetId: string;
  ruleVersionId: RuleVersionId;
  version: string;
  contentHash: string;
  status: RuleStatus;
  updatedAt: string;
}

// ---------------------------------------------------------------------------
// §8.2 Room 規則回報
// ---------------------------------------------------------------------------

/** §6.2 的五種狀態 */
export type ValidationStatus = "VALID" | "MISMATCH" | "UNVERIFIED" | "PRIVATE_TEST" | "VANILLA";

export interface RoomRuleReportRequest {
  roomId: string;
  /** §12：玩家身分用不可逆雜湊，salt/pepper 由後端管理 */
  reporterHash: string;
  ruleVersionId: RuleVersionId | null;
  ruleSetId: string;
  version: string;
  contentHash: string;
  pluginVersion: string;
  reportedAt: string;
}

export interface RoomRuleReportResponse {
  validationStatus: ValidationStatus;
  validationId: string;
  matchedRuleVersionId: RuleVersionId | null;
  /** 對方是否也回報了。false 時 status 通常是 UNVERIFIED。 */
  peerReported: boolean;
  /** MISMATCH 時說明差在哪，給遊戲內提示用 */
  reasonCode?: string;
}

// ---------------------------------------------------------------------------
// §8.3 戰果匯入
// ---------------------------------------------------------------------------

export type MatchResult = "WIN" | "LOSE" | "DRAW";

export interface MatchImportRequest {
  /** 冪等鍵。同一個 matchId 重送不得產生重複對戰（§6.3）。 */
  matchId: string;
  roomId: string;
  ruleValidationId: string;
  ruleVersionId: RuleVersionId | null;
  result: MatchResult;
  team: string[];
  customTeamCost: number;
  pluginVersion: string;
  finishedAt: string;
}

export interface MatchImportResponse {
  accepted: boolean;
  /** 重送時為 true，用來確認冪等有生效而不是又寫了一筆 */
  duplicate: boolean;
  /** §6.3：戰果與 Room 版本不一致時標記 CONFLICT，不可靜默歸類 */
  conflict?: { reasonCode: string; message: string };
}

// ---------------------------------------------------------------------------
// §8.4 插件相容性
// ---------------------------------------------------------------------------

export interface ClientCompatibilityRequest {
  pluginVersion: string;
  apiSchemaVersion: number;
}

/**
 * §10.4：只回傳宣告式資料與版本門檻。
 * §12：不得回傳可執行程式碼 —— 這個介面刻意沒有任何 string 欄位會被 eval。
 */
export interface ClientCompatibilityResponse {
  supported: boolean;
  latestPluginVersion: string;
  minimumPluginVersion: string;
  updateRequired: boolean;
  apiSchemaVersion: number;
  releaseProvider: "github";
  releaseChannel: "stable" | "beta";
  reasonCode: string | null;
}

// ---------------------------------------------------------------------------
// 共通
// ---------------------------------------------------------------------------

/** §8 共通要求：結構化錯誤碼 */
export interface ApiError {
  error: { code: string; message: string; details?: unknown };
}
