from __future__ import annotations

from dataclasses import dataclass

from ..domain.reward_rules import RewardPrediction


@dataclass(frozen=True)
class FragmentDisplay:
    short_name: str
    icon: str


FRAGMENT_DISPLAY_BY_REWARD = {
    "記憶碎片（黃）": FragmentDisplay("黃", "🟡"),
    "時間碎片（綠）": FragmentDisplay("綠", "🟢"),
    "靈魂碎片（藍）": FragmentDisplay("藍", "🔵"),
    "生命碎片（紅）": FragmentDisplay("紅", "🔴"),
    "死亡碎片（紫）": FragmentDisplay("紫", "🟣"),
}


def get_fragment_display(
    prediction: RewardPrediction,
) -> FragmentDisplay | None:
    """Convert a domain prediction to a compact Discord representation."""
    return FRAGMENT_DISPLAY_BY_REWARD.get(prediction.reward_name)
