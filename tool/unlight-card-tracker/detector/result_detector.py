from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path

import cv2
import numpy as np


@dataclass
class ResultDetection:
    state: str | None
    confidence: float
    second_confidence: float
    score_gap: float
    best_template: str | None


class ResultDetector:
    def __init__(
        self,
        template_dir: Path,
        region: dict[str, int],
        threshold: float = 0.62,
        min_score_gap: float = 0.06,
    ) -> None:
        self.template_dir = Path(
            template_dir
        )

        self.region = region
        self.threshold = threshold
        self.min_score_gap = min_score_gap

        self.templates: dict[
            str,
            list[
                tuple[str, np.ndarray]
            ],
        ] = {}

        self._load_templates()

    def _load_templates(self) -> None:
        self.templates.clear()

        for state in (
            "win",
            "lose",
            "timeout",
            "tie",
            "loading",
        ):
            state_dir = (
                self.template_dir
                / state
            )

            loaded: list[
                tuple[str, np.ndarray]
            ] = []

            if state_dir.exists():
                for path in sorted(
                    state_dir.glob("*.png")
                ):
                    image = cv2.imdecode(
                        np.fromfile(
                            str(path),
                            dtype=np.uint8,
                        ),
                        cv2.IMREAD_COLOR,
                    )

                    if (
                        image is None
                        or image.size == 0
                    ):
                        continue

                    loaded.append(
                        (
                            path.name,
                            image,
                        )
                    )

            self.templates[state] = loaded

        print(
            "結果模板已載入："
            + "，".join(
                (
                    f"{state}="
                    f"{len(items)}"
                )
                for state, items
                in self.templates.items()
            )
        )

    def _crop(
        self,
        frame: np.ndarray,
    ) -> np.ndarray:
        x = int(
            self.region["x"]
        )

        y = int(
            self.region["y"]
        )

        width = int(
            self.region["width"]
        )

        height = int(
            self.region["height"]
        )

        return frame[
            y:y + height,
            x:x + width,
        ].copy()

    def detect(
        self,
        frame: np.ndarray,
    ) -> ResultDetection:
        roi = self._crop(
            frame
        )

        if roi.size == 0:
            return ResultDetection(
                state=None,
                confidence=0.0,
                second_confidence=0.0,
                score_gap=0.0,
                best_template=None,
            )

        state_scores: list[
            tuple[
                float,
                str,
                str,
            ]
        ] = []

        for state, templates in (
            self.templates.items()
        ):
            best_state_score = -1.0
            best_state_template = None

            for template_name, template in templates:
                resized_roi = cv2.resize(
                    roi,
                    (
                        template.shape[1],
                        template.shape[0],
                    ),
                    interpolation=cv2.INTER_AREA,
                )

                result = cv2.matchTemplate(
                    resized_roi,
                    template,
                    cv2.TM_CCOEFF_NORMED,
                )

                score = float(
                    result.max()
                )

                if (
                    score
                    > best_state_score
                ):
                    best_state_score = score
                    best_state_template = (
                        template_name
                    )

            if (
                best_state_template
                is not None
            ):
                state_scores.append(
                    (
                        best_state_score,
                        state,
                        best_state_template,
                    )
                )

        if not state_scores:
            return ResultDetection(
                state=None,
                confidence=0.0,
                second_confidence=0.0,
                score_gap=0.0,
                best_template=None,
            )

        state_scores.sort(
            key=lambda item: item[0],
            reverse=True,
        )

        best_score = (
            state_scores[0][0]
        )

        best_state = (
            state_scores[0][1]
        )

        best_template = (
            state_scores[0][2]
        )

        second_score = (
            state_scores[1][0]
            if len(state_scores) >= 2
            else 0.0
        )

        score_gap = (
            best_score
            - second_score
        )

        is_reliable = (
            best_score
            >= self.threshold
            and score_gap
            >= self.min_score_gap
        )

        return ResultDetection(
            state=(
                best_state
                if is_reliable
                else None
            ),
            confidence=best_score,
            second_confidence=second_score,
            score_gap=score_gap,
            best_template=best_template,
        )