from __future__ import annotations

from dataclasses import dataclass
from typing import Dict
import cv2
import numpy as np

from roi import crop


@dataclass
class PhaseResult:
    phase: str
    confidence: float


class PhaseDetector:
    """
    先以階段列中的高飽和亮色區塊判斷。
    後續可改成模板比對，以提高不同主題與特效下的穩定度。
    """

    PHASE_NAMES = ("draw", "move", "attack_defense", "defense_attack")

    def __init__(self, region: Dict[str, int]) -> None:
        self.region = region

    def detect(self, frame: np.ndarray) -> PhaseResult:
        image = crop(frame, self.region)
        if image.size == 0:
            return PhaseResult("unknown", 0.0)

        hsv = cv2.cvtColor(image, cv2.COLOR_BGR2HSV)
        width = hsv.shape[1]
        segment_width = max(1, width // 4)

        scores = []
        for index in range(4):
            x1 = index * segment_width
            x2 = width if index == 3 else (index + 1) * segment_width
            segment = hsv[:, x1:x2]

            saturation = segment[:, :, 1]
            value = segment[:, :, 2]
            active = (saturation > 100) & (value > 120)
            scores.append(float(active.mean()))

        best = int(np.argmax(scores))
        confidence = scores[best]
        if confidence < 0.03:
            return PhaseResult("unknown", confidence)

        return PhaseResult(self.PHASE_NAMES[best], confidence)
