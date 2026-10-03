from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Dict, Optional

import cv2
import numpy as np


@dataclass
class SiteResult:
    site_key: Optional[str]
    confidence: float
    second_confidence: float = 0.0


class SiteDetector:
    def __init__(
        self,
        template_dir: Path,
        region: Dict[str, int],
        threshold: float = 0.55
    ) -> None:
        self.region = region
        self.threshold = threshold

        self.templates = self._load_templates(
            template_dir
        )

    def _load_templates(
        self,
        folder: Path
    ) -> Dict[str, np.ndarray]:

        templates: Dict[str, np.ndarray] = {}

        for path in sorted(folder.glob("*.jpg")):
            image = cv2.imread(str(path))

            if image is None:
                print(
                    f"略過無法讀取的場景圖：{path}"
                )
                continue

            image = cv2.resize(
                image,
                (320, 180),
                interpolation=cv2.INTER_AREA
            )

            hsv = cv2.cvtColor(
                image,
                cv2.COLOR_BGR2HSV
            )

            histogram = cv2.calcHist(
                [hsv],
                [0, 1],
                None,
                [30, 32],
                [0, 180, 0, 256]
            )

            cv2.normalize(
                histogram,
                histogram,
                0,
                1,
                cv2.NORM_MINMAX
            )

            templates[path.stem] = histogram

        print(
            f"已載入 {len(templates)} 張場景模板"
        )

        return templates

    def detect(
        self,
        frame: np.ndarray
    ) -> SiteResult:

        x = int(self.region["x"])
        y = int(self.region["y"])
        width = int(self.region["width"])
        height = int(self.region["height"])

        roi = frame[
            y:y + height,
            x:x + width
        ]

        if roi.size == 0:
            return SiteResult(
                None,
                0.0,
                0.0
            )

        roi = cv2.resize(
            roi,
            (320, 180),
            interpolation=cv2.INTER_AREA
        )

        hsv = cv2.cvtColor(
            roi,
            cv2.COLOR_BGR2HSV
        )

        histogram = cv2.calcHist(
            [hsv],
            [0, 1],
            None,
            [30, 32],
            [0, 180, 0, 256]
        )

        cv2.normalize(
            histogram,
            histogram,
            0,
            1,
            cv2.NORM_MINMAX
        )

        scores: list[tuple[str, float]] = []

        for (
            site_key,
            template_histogram
        ) in self.templates.items():

            score = float(
                cv2.compareHist(
                    histogram,
                    template_histogram,
                    cv2.HISTCMP_CORREL
                )
            )

            scores.append(
                (site_key, score)
            )

        if not scores:
            return SiteResult(
                None,
                0.0,
                0.0
            )

        scores.sort(
            key=lambda item: item[1],
            reverse=True
        )

        best_site, best_score = scores[0]

        second_score = (
            scores[1][1]
            if len(scores) >= 2
            else 0.0
        )

        # 除錯時才打開
        print("場景候選：")

        for site_key, score in scores[:5]:
            print(
                f"  {site_key}: {score:.4f}"
            )

        if best_score < self.threshold:
            return SiteResult(
                None,
                best_score,
                second_score
            )

        return SiteResult(
            best_site,
            best_score,
            second_score
        )