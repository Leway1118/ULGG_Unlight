from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Optional

import cv2
import numpy as np


@dataclass
class OkButtonResult:
    state: Optional[str]
    confidence: float
    second_confidence: float
    score_gap: float
    best_template: Optional[str] = None


class OkButtonDetector:
    """
    辨識 OK 按鈕目前狀態。

    template_dir/
    ├─ unlocked/
    │  ├─ ok_01.png
    │  └─ ok_02.png
    └─ locked/
       ├─ chain_01.png
       └─ chain_02.png

    回傳 state：
    - unlocked：顯示 OK，尚未鎖定
    - locked：顯示鎖鍊，出牌已鎖定
    - None：信心不足或兩者過於接近
    """

    SUPPORTED_EXTENSIONS = {
        ".png",
        ".jpg",
        ".jpeg",
        ".webp",
    }

    def __init__(
        self,
        template_dir: Path,
        region: dict[str, int],
        threshold: float = 0.62,
        min_score_gap: float = 0.08,
        target_size: tuple[int, int] = (96, 96),
    ) -> None:
        self.template_dir = Path(template_dir)
        self.region = region
        self.threshold = float(threshold)
        self.min_score_gap = float(min_score_gap)
        self.target_size = target_size

        self.templates = self._load_templates(
            self.template_dir
        )

        total = sum(
            len(items)
            for items in self.templates.values()
        )

        print(
            f"已載入 {total} 張 OK 狀態模板"
        )

    @staticmethod
    def _read_image_unicode(
        path: Path,
    ) -> Optional[np.ndarray]:
        try:
            data = np.fromfile(
                str(path),
                dtype=np.uint8,
            )

            if data.size == 0:
                return None

            return cv2.imdecode(
                data,
                cv2.IMREAD_COLOR,
            )

        except Exception:
            return None

    def _prepare(
        self,
        image: np.ndarray,
    ) -> np.ndarray:
        image = cv2.resize(
            image,
            self.target_size,
            interpolation=cv2.INTER_AREA,
        )

        gray = cv2.cvtColor(
            image,
            cv2.COLOR_BGR2GRAY,
        )

        gray = cv2.GaussianBlur(
            gray,
            (3, 3),
            0,
        )

        gray = cv2.equalizeHist(
            gray
        )

        return gray

    def _load_templates(
        self,
        folder: Path,
    ) -> dict[str, list[tuple[str, np.ndarray]]]:
        templates: dict[
            str,
            list[tuple[str, np.ndarray]]
        ] = {
            "unlocked": [],
            "locked": [],
        }

        for state in templates:
            state_dir = folder / state

            if not state_dir.exists():
                print(
                    f"OK 模板資料夾不存在："
                    f"{state_dir}"
                )
                continue

            for path in sorted(
                state_dir.iterdir()
            ):
                if (
                    not path.is_file()
                    or path.suffix.lower()
                    not in self.SUPPORTED_EXTENSIONS
                ):
                    continue

                image = self._read_image_unicode(
                    path
                )

                if image is None:
                    print(
                        f"略過無法讀取的 OK 模板："
                        f"{path}"
                    )
                    continue

                prepared = self._prepare(
                    image
                )

                templates[state].append(
                    (
                        path.name,
                        prepared,
                    )
                )

        return templates

    def crop(
        self,
        frame: np.ndarray,
    ) -> np.ndarray:
        x = int(self.region["x"])
        y = int(self.region["y"])
        width = int(
            self.region["width"]
        )
        height = int(
            self.region["height"]
        )

        frame_height, frame_width = (
            frame.shape[:2]
        )

        x1 = max(
            0,
            min(x, frame_width),
        )
        y1 = max(
            0,
            min(y, frame_height),
        )
        x2 = max(
            0,
            min(x + width, frame_width),
        )
        y2 = max(
            0,
            min(y + height, frame_height),
        )

        if x2 <= x1 or y2 <= y1:
            return np.empty(
                (0, 0, 3),
                dtype=np.uint8,
            )

        return frame[
            y1:y2,
            x1:x2,
        ].copy()

    def detect(
        self,
        frame: np.ndarray,
    ) -> OkButtonResult:
        roi = self.crop(
            frame
        )

        if roi.size == 0:
            return OkButtonResult(
                state=None,
                confidence=0.0,
                second_confidence=0.0,
                score_gap=0.0,
                best_template=None,
            )

        sample = self._prepare(
            roi
        )

        scores: list[
            tuple[str, str, float]
        ] = []

        for state, items in (
            self.templates.items()
        ):
            for (
                template_name,
                template,
            ) in items:
                result = cv2.matchTemplate(
                    sample,
                    template,
                    cv2.TM_CCOEFF_NORMED,
                )

                score = float(
                    result.max()
                )

                scores.append(
                    (
                        state,
                        template_name,
                        score,
                    )
                )

        if not scores:
            return OkButtonResult(
                state=None,
                confidence=0.0,
                second_confidence=0.0,
                score_gap=0.0,
                best_template=None,
            )

        scores.sort(
            key=lambda item: item[2],
            reverse=True,
        )

        best_state = scores[0][0]
        best_template = scores[0][1]
        best_score = scores[0][2]

        # 第二名只取「另一種狀態」的最高分，
        # 避免同一狀態多張相似模板互相壓低差距。
        second_score = 0.0

        for (
            state,
            _template_name,
            score,
        ) in scores[1:]:
            if state != best_state:
                second_score = score
                break

        score_gap = (
            best_score
            - second_score
        )

        if (
            best_score < self.threshold
            or score_gap < self.min_score_gap
        ):
            return OkButtonResult(
                state=None,
                confidence=best_score,
                second_confidence=second_score,
                score_gap=score_gap,
                best_template=best_template,
            )

        return OkButtonResult(
            state=best_state,
            confidence=best_score,
            second_confidence=second_score,
            score_gap=score_gap,
            best_template=best_template,
        )
