/**
 * @ulr/cost-engine — WP-02
 * ==========================
 * 狀態：**只有介面，還沒有實作。** 這是給接手 WP-02 的人的起點。
 *
 * 規格書 §9 的驗收要點只有一句，但它是整個 package 的設計約束：
 *
 *     「同一規則與輸入必須得到確定性結果」
 *
 * 所以這裡的每個函式都必須是純函式 —— 不讀時間、不讀亂數、不讀全域狀態、
 * 不碰網路。雙方玩家在各自的電腦上對同一副牌組跑，必須得到同一個數字，
 * 否則 Room 一致性判定就沒有意義了。
 *
 * 待辦（見 docs/open-questions.md）：
 *   1. 角色 ID 用什麼？封包給的是 cc078 + charaIndex + level 三件套，
 *      規格書附錄 A 寫 "WOLAND_L4"、§8.3 又寫 "CHAR_L4_001"。要先統一。
 *   2. 壓 C 的「差距」是誰跟誰的差距？（隊內最高最低？我方對敵方？）
 *      這題沒定案之前 applyCompression 寫不出來。
 */

import type { CentiCost, CostRule } from "@ulr/rule-schema";

/** 一名上場角色。欄位對應到 CDP 抓到的牌組封包。 */
export interface TeamMember {
  /** 對應 rule.characters 的鍵 */
  characterId: string;
  /** 裝備的武器，對應 rule.equipment 的鍵 */
  equipmentId?: string;
}

export interface TeamComposition {
  members: TeamMember[];
  /** 事件卡 ID 清單，對應 rule.eventCards */
  eventCards?: string[];
}

/**
 * 逐項列出每一分 COST 是哪來的 —— 遊戲內要顯示明細，玩家才信得過。
 *
 * ⚠ 所有數值都是 **CentiCost（整數百分之一）**，不是十進位 COST。
 * `8.8 + 26.6 + 26.6` 用 double 相加是 62.00000000000001，剛好卡滿 62
 * 上限的隊伍會被誤判超標。用 `toCentiCost` 轉進來、`formatCentiCost` 顯示。
 */
export interface CostBreakdownItem {
  source: "character" | "equipment" | "eventCard" | "compression";
  id: string;
  cost: CentiCost;
}

export interface CostCalculation {
  total: CentiCost;
  limit: CentiCost;
  overLimit: boolean;
  items: CostBreakdownItem[];
  /** 規則裡沒有定價的東西。不能默默當 0，那會讓超標的隊伍看起來合法。 */
  unknownIds: string[];
}

export class NotImplementedError extends Error {
  override readonly name = "NotImplementedError";
}

/**
 * 計算隊伍總 COST。
 *
 * @throws NotImplementedError 等 WP-02 實作
 */
export function calculateTeamCost(_rule: CostRule, _team: TeamComposition): CostCalculation {
  throw new NotImplementedError(
    "WP-02 Cost Engine 尚未實作。先確認 docs/open-questions.md 的角色 ID 與壓 C 定義。",
  );
}
