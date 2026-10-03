from __future__ import annotations

from dataclasses import dataclass

from .models import RaidEntry


@dataclass(frozen=True)
class RewardPrediction:
    """
    獎勵預測結果
    """

    reward_name: str

    quantity: int | None

    confidence: str

    reason: str


# =========================
# 碎片規則
# =========================


NORMAL_FRAGMENT_BY_STAGE = {
    1: "記憶碎片（黃）",
    2: "時間碎片（綠）",
    3: "靈魂碎片（藍）",
    4: "生命碎片（紅）",
    5: "死亡碎片（紫）",
}


SIX_STAR_FRAGMENT_BY_STAGE = {
    1: "時間碎片（綠）",
    2: "靈魂碎片（藍）",
    3: "生命碎片（紅）",
    4: "死亡碎片（紫）",
    5: "記憶碎片（黃）",
}


# =========================
# 特殊 Boss
# =========================

FISH_BOSS_CODES: set[str] = {
    # 尚未建立完整清單
}


FAIRY_BOSS_CODES: set[str] = {
    "mc1004_01",
}


# =========================
# 判斷
# =========================


def predict_fragment(
    raid: RaidEntry,
) -> RewardPrediction:

    monster_code = (
        raid.monster_code
        or ""
    )


    # 妖精 Boss
    if monster_code in FAIRY_BOSS_CODES:
        return RewardPrediction(
            reward_name="特殊妖精獎勵",
            quantity=None,
            confidence="unknown",
            reason=(
                "妖精Boss規則尚未完整確認"
            ),
        )


    # 魚 Boss
    if monster_code in FISH_BOSS_CODES:
        return RewardPrediction(
            reward_name="隨機碎片",
            quantity=None,
            confidence="partial",
            reason=(
                "魚Boss特殊規則"
            ),
        )


    stage = raid.stage_id

    if stage is None:
        return RewardPrediction(
            reward_name="未知",
            quantity=None,
            confidence="unknown",
            reason=(
                "缺少stage資料"
            ),
        )


    # 一般星數
    if raid.rarity != 6:

        fragment = (
            NORMAL_FRAGMENT_BY_STAGE
            .get(stage)
        )

        if fragment:
            return RewardPrediction(
                reward_name=fragment,
                quantity=None,
                confidence="partial",
                reason=(
                    "一般渦 stage 規則"
                ),
            )


    # 六星
    if raid.rarity == 6:

        fragment = (
            SIX_STAR_FRAGMENT_BY_STAGE
            .get(stage)
        )

        if fragment:
            return RewardPrediction(
                reward_name=fragment,
                quantity=None,
                confidence="partial",
                reason=(
                    "六星渦 stage+1 規則"
                ),
            )


    return RewardPrediction(
        reward_name="未知",
        quantity=None,
        confidence="unknown",
        reason=(
            "尚未建立此Raid規則"
        ),
    )