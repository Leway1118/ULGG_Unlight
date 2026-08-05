from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Optional

import cv2
import numpy as np

def read_image_unicode(
    path: Path,
    flags: int = cv2.IMREAD_COLOR
) -> np.ndarray | None:
    try:
        data = np.fromfile(
            str(path),
            dtype=np.uint8
        )

        if data.size == 0:
            return None

        return cv2.imdecode(
            data,
            flags
        )

    except (OSError, ValueError):
        return None


@dataclass
class DigitResult:
    value: Optional[int]
    confidence: float

    best_template: Optional[str] = None
    second_value: Optional[int] = None
    second_confidence: float = 0.0
    second_template: Optional[str] = None
    score_gap: float = 0.0


class CardDigitDetector:
    def __init__(
        self,
        template_dir: Path,
        threshold: float = 0.65
    ) -> None:
        self.template_dir = template_dir
        self.threshold = threshold

        self.templates: dict[
            str,
            dict[
                int,
                list[
                    tuple[np.ndarray, str]
                ]
            ]
        ] = {
            "upper": {},
            "lower": {},
        }

        self._load_templates()

        upper_count = sum(
            len(items)
            for items in self.templates["upper"].values()
        )

        lower_count = sum(
            len(items)
            for items in self.templates["lower"].values()
        )

        print(
            "已載入數字模板："
            f"upper={upper_count}，"
            f"lower={lower_count}"
        )

    def _load_templates(self) -> None:
        for position in ("upper", "lower"):
            folder = self.template_dir / position

            if not folder.exists():
                continue

            for path in sorted(folder.glob("*.png")):
                name_prefix = path.stem.split("_", 1)[0]

                if not name_prefix.isdigit():
                    continue

                value = int(name_prefix)
                

                image = read_image_unicode(
                    path,
                    cv2.IMREAD_GRAYSCALE
                )

                if image is None:
                    continue

                normalized = self._normalize(image)

                self.templates[position].setdefault(
                    value,
                    []
                ).append(
                    (
                        normalized,
                        path.name,
                    )
                )

    def _normalize(
        self,
        image: np.ndarray
    ) -> np.ndarray:
        resized = cv2.resize(
            image,
            (48, 64),
            interpolation=cv2.INTER_CUBIC
        )

        blurred = cv2.GaussianBlur(
            resized,
            (3, 3),
            0
        )

        # 白色數字通常比背景更亮
        _, binary = cv2.threshold(
            blurred,
            0,
            255,
            cv2.THRESH_BINARY
            + cv2.THRESH_OTSU
        )

        return binary

    def detect(
        self,
        image: np.ndarray,
        position: str
    ) -> DigitResult:
        if image.size == 0:
            return DigitResult(
                None,
                0.0
            )

        if position not in self.templates:
            raise ValueError(
                f"未知數字位置：{position}"
            )

        templates = self.templates[position]

        if not templates:
            return DigitResult(
                None,
                0.0
            )

        if len(image.shape) == 3:
            gray = cv2.cvtColor(
                image,
                cv2.COLOR_BGR2GRAY
            )
        else:
            gray = image.copy()

        normalized = self._normalize(gray)

        ranked_results: list[
            tuple[
                float,
                int,
                str,
            ]
        ] = []

        for value, value_templates in (
            templates.items()
        ):
            value_best_score = -1.0
            value_best_template: Optional[str] = None

            for template, template_name in (
                value_templates
            ):
                score = float(
                    cv2.matchTemplate(
                        normalized,
                        template,
                        cv2.TM_CCOEFF_NORMED,
                    )[0][0]
                )

                if score > value_best_score:
                    value_best_score = score
                    value_best_template = (
                        template_name
                    )

            if value_best_template is not None:
                ranked_results.append(
                    (
                        value_best_score,
                        value,
                        value_best_template,
                    )
                )

        ranked_results.sort(
            key=lambda item: item[0],
            reverse=True,
        )

        if not ranked_results:
            return DigitResult(
                value=None,
                confidence=0.0,
            )

        (
            best_score,
            best_value,
            best_template,
        ) = ranked_results[0]

        if len(ranked_results) >= 2:
            (
                second_score,
                second_value,
                second_template,
            ) = ranked_results[1]
        else:
            second_score = 0.0
            second_value = None
            second_template = None

        best_score = max(
            0.0,
            best_score,
        )

        second_score = max(
            0.0,
            second_score,
        )

        score_gap = max(
            0.0,
            best_score - second_score,
        )

        if best_score < self.threshold:
            return DigitResult(
                value=None,
                confidence=best_score,
                best_template=best_template,
                second_value=second_value,
                second_confidence=second_score,
                second_template=second_template,
                score_gap=score_gap,
            )

        return DigitResult(
            value=best_value,
            confidence=best_score,
            best_template=best_template,
            second_value=second_value,
            second_confidence=second_score,
            second_template=second_template,
            score_gap=score_gap,
        )