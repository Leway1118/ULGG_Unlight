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

        kernel = np.ones((2, 2), np.uint8)
        binary = cv2.morphologyEx(binary, cv2.MORPH_OPEN, kernel)
        return binary

    def detect(self, frame: np.ndarray) -> DeckCountResult:
        binary = self.preprocess(frame)

        # 尚未建立 0~9 的遊戲字型模板時，只輸出裁切結果，不硬猜數字。
        if len(self.templates) < 10:
            return DeckCountResult(None, 0.0, binary)

        contours, _ = cv2.findContours(
            binary,
            cv2.RETR_EXTERNAL,
            cv2.CHAIN_APPROX_SIMPLE
        )

        boxes = []
        for contour in contours:
            x, y, w, h = cv2.boundingRect(contour)
            if 6 <= w <= 45 and 14 <= h <= 55:
                boxes.append((x, y, w, h))

        boxes.sort(key=lambda item: item[0])

        digits = []
        confidences = []
        for x, y, w, h in boxes[:3]:
            digit_image = binary[y:y+h, x:x+w]
            digit, confidence = self._match_digit(digit_image)
            if digit is not None:
                digits.append(digit)
                confidences.append(confidence)

        if not digits:
            return DeckCountResult(None, 0.0, binary)

        return DeckCountResult(
            int("".join(digits)),
            float(sum(confidences) / len(confidences)),
            binary,
        )

    def _match_digit(self, image: np.ndarray) -> tuple[Optional[str], float]:
        best_digit: Optional[str] = None
        best_score = -1.0

        for digit, template in self.templates.items():
            resized = cv2.resize(
                image,
                (template.shape[1], template.shape[0]),
                interpolation=cv2.INTER_AREA
            )
            score = float(
                cv2.matchTemplate(
                    resized,
                    template,
                    cv2.TM_CCOEFF_NORMED
                )[0][0]
            )
            if score > best_score:
                best_score = score
                best_digit = digit

        if best_score < 0.45:
            return None, best_score

        return best_digit, best_score
