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
class IconResult:
    icon_type: Optional[str]
    confidence: float


class CardIconDetector:
    def __init__(
        self,
        template_dir: Path | None = None,
        threshold: float = 0.55
    ) -> None:
        self.template_dir = template_dir
        self.threshold = threshold

        self.templates: dict[
            str,
            list[np.ndarray]
        ] = {}

        self._load_templates()

        template_count = sum(
            len(items)
            for items in self.templates.values()
        )

        print(
            f"已載入 {template_count} 張、"
            f"{len(self.templates)} 種卡片圖示模板"
        )

    def _load_templates(self) -> None:
        if self.template_dir is None:
            return

        if not self.template_dir.exists():
            return

        icon_types = [
            "gun",
            "move",
            "shield",
            "special",
            "sword",
        ]

        for icon_type in icon_types:
            paths = sorted(
                self.template_dir.glob(
                    f"{icon_type}*.png"
                )
            )

            for path in paths:
                image = read_image_unicode(
                    path,
                    cv2.IMREAD_UNCHANGED
                )

                if image is None:
                    continue

                if (
                    image.ndim == 3
                    and image.shape[2] == 4
                ):
                    alpha = image[:, :, 3]

                    bgr = image[:, :, :3].copy()

                    bgr[alpha == 0] = (
                        0,
                        0,
                        0
                    )

                    image = bgr

                elif image.ndim == 2:
                    image = cv2.cvtColor(
                        image,
                        cv2.COLOR_GRAY2BGR
                    )

                self.templates.setdefault(
                    icon_type,
                    []
                ).append(image)

    def detect(
        self,
        image: np.ndarray,
        position: str
    ) -> IconResult:
        if image is None or image.size == 0:
            return IconResult(
                None,
                0.0
            )

        if position not in {
            "top",
            "bottom"
        }:
            raise ValueError(
                f"未知卡面位置：{position}"
            )

        if not self.templates:
            return IconResult(
                None,
                0.0
            )

        

        working = image.copy()

        # 卡片下半面在畫面上是倒置的，
        # 模板比對前旋轉 180 度轉正。
        if position == "bottom":
            working = cv2.rotate(
                working,
                cv2.ROTATE_180
            )
        #
        # 若未來 lower 尚未轉正，可改成：
        #
        # if position == "bottom":
        #     working = cv2.rotate(
        #         working,
        #         cv2.ROTATE_180
        #     )

        best_type: Optional[str] = None
        best_score = -1.0

        for icon_type, templates in (
            self.templates.items()
        ):
            for template in templates:
                score = self._multiscale_match(
                    template=template,
                    sample=working
                )

                if score > best_score:
                    best_type = icon_type
                    best_score = score

        best_score = max(
            0.0,
            best_score
        )

        if best_score < self.threshold:
            return IconResult(
                None,
                best_score
            )

        return IconResult(
            best_type,
            best_score
        )

    def _prepare_icon_area(
        self,
        image: np.ndarray
    ) -> np.ndarray:
        if image.ndim == 2:
            gray = image.copy()
        else:
            gray = cv2.cvtColor(
                image[:, :, :3],
                cv2.COLOR_BGR2GRAY
            )

        height, width = gray.shape[:2]

        if height <= 0 or width <= 0:
            return np.empty(
                (0, 0),
                dtype=np.uint8
            )

        # 排除上方 ATTACK、DEFENSE 等文字。
        crop_top = round(
            height * 0.28
        )

        crop_top = max(
            0,
            min(
                crop_top,
                height - 1
            )
        )

        icon_area = gray[
            crop_top:height,
            0:width
        ]

        # 輕微平滑畫面壓縮雜訊。
        icon_area = cv2.GaussianBlur(
            icon_area,
            (3, 3),
            0
        )

        return icon_area

    def _multiscale_match(
        self,
        template: np.ndarray,
        sample: np.ndarray
    ) -> float:
        template_area = self._prepare_icon_area(
            template
        )

        sample_area = self._prepare_icon_area(
            sample
        )

        if (
            template_area.size == 0
            or sample_area.size == 0
        ):
            return 0.0

        best_score = -1.0

        # 模板縮放搜尋範圍。
        for scale in np.arange(
            0.65,
            1.46,
            0.05
        ):
            resized_width = max(
                2,
                round(
                    template_area.shape[1]
                    * float(scale)
                )
            )

            resized_height = max(
                2,
                round(
                    template_area.shape[0]
                    * float(scale)
                )
            )

            if (
                resized_width
                > sample_area.shape[1]
                or resized_height
                > sample_area.shape[0]
            ):
                continue

            resized_template = cv2.resize(
                template_area,
                (
                    resized_width,
                    resized_height
                ),
                interpolation=cv2.INTER_CUBIC
            )

            result = cv2.matchTemplate(
                sample_area,
                resized_template,
                cv2.TM_CCOEFF_NORMED
            )

            if result.size == 0:
                continue

            score = float(
                np.max(result)
            )

            if score > best_score:
                best_score = score

        return max(
            0.0,
            best_score
        )