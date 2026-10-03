from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Optional

import cv2

from card_digit_detector import CardDigitDetector
from card_icon_detector import CardIconDetector


@dataclass
class FaceSideResult:
    icon_type: Optional[str]
    icon_confidence: float
    value: Optional[int]
    digit_confidence: float


@dataclass
class CardFaceResult:
    card_index: int
    upper: FaceSideResult
    lower: FaceSideResult


class CardFaceReader:
    def __init__(
        self,
        icon_detector: CardIconDetector,
        digit_detector: CardDigitDetector
    ) -> None:
        self.icon_detector = icon_detector
        self.digit_detector = digit_detector

    def read_card(
        self,
        card_index: int,
        folder: Path
    ) -> CardFaceResult:
        stem = f"card_{card_index:02d}"

        upper_icon = self._read_image(
            folder / f"{stem}_upper_icon.png"
        )

        upper_digit = self._read_image(
            folder / f"{stem}_upper_digit.png"
        )

        lower_icon = self._read_image(
            folder / f"{stem}_lower_icon.png"
        )

        lower_digit = self._read_image(
            folder / f"{stem}_lower_digit.png"
        )

        upper_icon_result = (
            self.icon_detector.detect(
                upper_icon,
                position="top"
            )
            if upper_icon is not None
            else None
        )

        upper_digit_result = (
            self.digit_detector.detect(
                upper_digit,
                position="upper"
            )
            if upper_digit is not None
            else None
        )

        lower_icon_result = (
            self.icon_detector.detect(
                lower_icon,
                position="bottom"
            )
            if lower_icon is not None
            else None
        )

        lower_digit_result = (
            self.digit_detector.detect(
                lower_digit,
                position="lower"
            )
            if lower_digit is not None
            else None
        )

        return CardFaceResult(
            card_index=card_index,

            upper=FaceSideResult(
                icon_type=(
                    upper_icon_result.icon_type
                    if upper_icon_result
                    else None
                ),
                icon_confidence=(
                    upper_icon_result.confidence
                    if upper_icon_result
                    else 0.0
                ),
                value=(
                    upper_digit_result.value
                    if upper_digit_result
                    else None
                ),
                digit_confidence=(
                    upper_digit_result.confidence
                    if upper_digit_result
                    else 0.0
                ),
            ),

            lower=FaceSideResult(
                icon_type=(
                    lower_icon_result.icon_type
                    if lower_icon_result
                    else None
                ),
                icon_confidence=(
                    lower_icon_result.confidence
                    if lower_icon_result
                    else 0.0
                ),
                value=(
                    lower_digit_result.value
                    if lower_digit_result
                    else None
                ),
                digit_confidence=(
                    lower_digit_result.confidence
                    if lower_digit_result
                    else 0.0
                ),
            ),
        )

    @staticmethod
    def _read_image(
        path: Path
    ):
        if not path.exists():
            return None

        return cv2.imread(str(path))