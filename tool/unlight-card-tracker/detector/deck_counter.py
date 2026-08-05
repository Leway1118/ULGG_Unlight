from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Dict, Optional
import cv2
import numpy as np

from roi import crop


@dataclass
class DeckCountResult:
    value: Optional[int]
    confidence: float
    binary: np.ndarray


class DeckCounter:
    """
    2.1 版先完成固定 ROI、二值化與輪廓切割。
    數字辨識先保留模板介面；把 0~9 模板放入 templates/digits 後即可啟用。
    """

    def __init__(
        self,
        region: Dict[str, int],
        digit_template_dir: Path,
    ) -> None:
        self.region = region
        self.templates = self._load_templates(digit_template_dir)

    @staticmethod
    def _load_templates(folder: Path) -> Dict[str, np.ndarray]:
        templates: Dict[str, np.ndarray] = {}
        if not folder.exists():
            return templates

        for digit in range(10):
            path = folder / f"{digit}.png"
            if not path.exists():
                continue
            image = cv2.imread(str(path), cv2.IMREAD_GRAYSCALE)
            if image is not None:
                templates[str(digit)] = image
        return templates

    def preprocess(self, frame: np.ndarray) -> np.ndarray:
        image = crop(frame, self.region)
        gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
        gray = cv2.GaussianBlur(gray, (3, 3), 0)

        _, binary = cv2.threshold(
            gray,
            180,
            255,
            cv2.THRESH_BINARY
        )

        def preprocess(self, frame: np.ndarray) -> np.ndarray:
            image = crop(frame, self.region)

            if image.size == 0:
                return np.zeros((1, 1), dtype=np.uint8)

            gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)

            _, binary = cv2.threshold(
                gray,
                160,
                255,
                cv2.THRESH_BINARY
            )

            # 不做 MORPH_OPEN，避免吃掉遊戲字型的細筆畫
            return binary
        return binary

    def detect(self, frame: np.ndarray) -> DeckCountResult:
        binary = self.preprocess(frame)

        if not self.templates:
            return DeckCountResult(None, 0.0, binary)

        # 找出每一欄是否有白色像素
        active_columns = np.any(binary > 0, axis=0)

        groups = []
        start = None

        for x, active in enumerate(active_columns):
            if active and start is None:
                start = x

            if not active and start is not None:
                groups.append((start, x))
                start = None

        if start is not None:
            groups.append((start, binary.shape[1]))

        # 過濾太細的裝飾或雜訊
        groups = [
            (x1, x2)
            for x1, x2 in groups
            if x2 - x1 >= 2
        ]

        # 牌庫正常最多兩位數，保留最合理的字元群
        if len(groups) > 2:
            groups = sorted(
                groups,
                key=lambda group: group[1] - group[0],
                reverse=True
            )[:2]

            groups.sort(key=lambda group: group[0])

        digits = []
        confidences = []

        for x1, x2 in groups:
            digit_strip = binary[:, x1:x2]

            active_rows = np.any(digit_strip > 0, axis=1)
            row_indexes = np.where(active_rows)[0]

            if len(row_indexes) == 0:
                continue

            y1 = int(row_indexes[0])
            y2 = int(row_indexes[-1]) + 1

            digit_image = digit_strip[y1:y2, :]

            digit, confidence = self._match_digit(digit_image)

            if digit is None:
                # 任一位無法確認時，不輸出不完整數字
                return DeckCountResult(None, confidence, binary)

            digits.append(digit)
            confidences.append(confidence)

        if not digits:
            return DeckCountResult(None, 0.0, binary)

        return DeckCountResult(
            value=int("".join(digits)),
            confidence=float(
                sum(confidences) / len(confidences)
            ),
            binary=binary
        )

    def _match_digit(
        self,
        image: np.ndarray
    ) -> tuple[Optional[str], float]:

        best_digit: Optional[str] = None
        best_score = -1.0

        for digit, template in self.templates.items():
            normalized = self._normalize_digit(
                image,
                template.shape[1],
                template.shape[0]
            )

            score = float(
                cv2.matchTemplate(
                    normalized,
                    template,
                    cv2.TM_CCOEFF_NORMED
                )[0][0]
            )

            if score > best_score:
                best_score = score
                best_digit = digit

        if best_score < 0.55:
            return None, best_score

        return best_digit, best_score
    
    @staticmethod
    
    def _normalize_digit(
            image: np.ndarray,
            target_width: int,
            target_height: int
        ) -> np.ndarray:

            active_rows = np.where(
                np.any(image > 0, axis=1)
            )[0]

            active_columns = np.where(
                np.any(image > 0, axis=0)
            )[0]

            if (
                len(active_rows) == 0 or
                len(active_columns) == 0
            ):
                return np.zeros(
                    (target_height, target_width),
                    dtype=np.uint8
                )

            cropped = image[
                active_rows[0]:active_rows[-1] + 1,
                active_columns[0]:active_columns[-1] + 1
            ]

            padding = 3

            available_width = target_width - padding * 2
            available_height = target_height - padding * 2

            scale = min(
                available_width / cropped.shape[1],
                available_height / cropped.shape[0]
            )

            new_width = max(
                1,
                round(cropped.shape[1] * scale)
            )

            new_height = max(
                1,
                round(cropped.shape[0] * scale)
            )

            resized = cv2.resize(
                cropped,
                (new_width, new_height),
                interpolation=cv2.INTER_NEAREST
            )

            canvas = np.zeros(
                (target_height, target_width),
                dtype=np.uint8
            )

            offset_x = (target_width - new_width) // 2
            offset_y = (target_height - new_height) // 2

            canvas[
                offset_y:offset_y + new_height,
                offset_x:offset_x + new_width
            ] = resized

            return canvas
