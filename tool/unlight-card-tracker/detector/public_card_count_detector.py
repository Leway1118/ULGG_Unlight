from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Any, Optional

import cv2
import numpy as np


def read_image_unicode(
    path: Path,
    flags: int = cv2.IMREAD_COLOR,
) -> np.ndarray | None:
    try:
        data = np.fromfile(
            str(path),
            dtype=np.uint8,
        )

        if data.size == 0:
            return None

        return cv2.imdecode(
            data,
            flags,
        )

    except (OSError, ValueError):
        return None


@dataclass
class PublicCardCountResult:
    value: Optional[int]
    tens: Optional[int]
    ones: Optional[int]

    tens_confidence: float
    ones_confidence: float
    confidence: float

    split_x: Optional[int]

    tens_debug: dict[str, Any]
    ones_debug: dict[str, Any]
    
    


class PublicCardCountDetector:
    def __init__(
        self,
        template_dir: Path,
        single_template_dir: Path | None = None,
        threshold: float = 0.60,
        digit_left_x: int = 0,
        split_x: int = 17,
        digit_right_x: int | None = None,
    ) -> None:
        self.template_dir = template_dir
        self.single_template_dir = single_template_dir
        self.threshold = threshold
        self.digit_left_x = digit_left_x
        self.split_x = split_x
        self.digit_right_x = digit_right_x

        self.templates: dict[
            int,
            list[
                tuple[
                    str,
                    np.ndarray,
                ]
            ]
        ] = {}
        
        self.single_templates: dict[
            int,
            list[
                tuple[
                    str,
                    np.ndarray,
                ]
            ]
        ] = {}

        self._load_templates()
        self._load_single_templates()

        template_count = sum(
            len(items)
            for items in self.templates.values()
        )

        print(
            "已載入公牌數量模板："
            f"{template_count} 張，"
            f"{len(self.templates)} 種數字"
        )

    def _load_templates(self) -> None:
        if not self.template_dir.exists():
            return

        for path in sorted(
            self.template_dir.glob("*.png")
        ):
            prefix = path.stem.split(
                "_",
                1,
            )[0]

            if not prefix.isdigit():
                continue

            value = int(prefix)

            if value < 0 or value > 9:
                continue

            image = read_image_unicode(
                path,
                cv2.IMREAD_GRAYSCALE,
            )

            if image is None:
                continue

            normalized = self._normalize(
                image
            )

            self.templates.setdefault(
                value,
                [],
            ).append(
                (
                    path.name,
                    normalized,
                )
            )
            
    def _load_single_templates(
        self,
    ) -> None:
        if self.single_template_dir is None:
            return

        if not self.single_template_dir.exists():
            return

        for path in sorted(
            self.single_template_dir.glob("*.png")
        ):
            prefix = path.stem.split(
                "_",
                1,
            )[0]

            if not prefix.isdigit():
                continue

            value = int(prefix)

            if value < 0 or value > 9:
                continue

            image = read_image_unicode(
                path,
                cv2.IMREAD_GRAYSCALE,
            )

            if image is None:
                continue

            normalized = self._normalize(
                image
            )

            self.single_templates.setdefault(
                value,
                [],
            ).append(
                (
                    path.name,
                    normalized,
                )
            )

    def _normalize(
        self,
        image: np.ndarray,
    ) -> np.ndarray:
        if image.ndim == 3:
            gray = cv2.cvtColor(
                image[:, :, :3],
                cv2.COLOR_BGR2GRAY,
            )
        else:
            gray = image.copy()

        gray = cv2.resize(
            gray,
            (32, 48),
            interpolation=cv2.INTER_CUBIC,
        )

        gray = cv2.GaussianBlur(
            gray,
            (3, 3),
            0,
        )

        _, binary = cv2.threshold(
            gray,
            0,
            255,
            cv2.THRESH_BINARY
            + cv2.THRESH_OTSU,
        )

        return binary

    def _detect_digit(
        self,
        image: np.ndarray,
    ) -> tuple[
        Optional[int],
        float,
        dict[str, Any],
    ]:
        """
        比對所有數字模板，並回傳：

        1. 通過門檻後的辨識結果
        2. 最高分
        3. 最高／第二名類別、分數與模板檔名
        """
        empty_debug = {
            "best_value": None,
            "best_confidence": 0.0,
            "best_template": None,

            "second_value": None,
            "second_confidence": 0.0,
            "second_template": None,

            "score_gap": 0.0,

            "roi_width": (
                int(image.shape[1])
                if image is not None
                and image.size > 0
                else 0
            ),

            "roi_height": (
                int(image.shape[0])
                if image is not None
                and image.size > 0
                else 0
            ),
        }

        if (
            image is None
            or image.size == 0
            or not self.templates
        ):
            return (
                None,
                0.0,
                empty_debug,
            )

        normalized = self._normalize(
            image
        )

        # 每個數字類別只保留該類最高分。
        class_best: dict[
            int,
            tuple[
                float,
                str,
            ]
        ] = {}

        for value, templates in (
            self.templates.items()
        ):
            for template_name, template in templates:
                result = cv2.matchTemplate(
                    normalized,
                    template,
                    cv2.TM_CCOEFF_NORMED,
                )

                if result.size == 0:
                    continue

                score = float(
                    result[0][0]
                )

                previous = class_best.get(
                    value
                )

                if (
                    previous is None
                    or score > previous[0]
                ):
                    class_best[value] = (
                        score,
                        template_name,
                    )

        if not class_best:
            return (
                None,
                0.0,
                empty_debug,
            )

        ranked = sorted(
            class_best.items(),
            key=lambda item: item[1][0],
            reverse=True,
        )

        best_value = ranked[0][0]
        best_score = max(
            0.0,
            float(
                ranked[0][1][0]
            ),
        )
        best_template = ranked[0][1][1]

        if len(ranked) >= 2:
            second_value = ranked[1][0]
            second_score = max(
                0.0,
                float(
                    ranked[1][1][0]
                ),
            )
            second_template = ranked[1][1][1]
        else:
            second_value = None
            second_score = 0.0
            second_template = None

        debug = {
            "best_value": best_value,
            "best_confidence": best_score,
            "best_template": best_template,

            "second_value": second_value,
            "second_confidence": second_score,
            "second_template": second_template,

            "score_gap": max(
                0.0,
                best_score - second_score,
            ),

            "roi_width": int(
                image.shape[1]
            ),

            "roi_height": int(
                image.shape[0]
            ),
        }

        detected_value = (
            best_value
            if best_score >= self.threshold
            else None
        )

        return (
            detected_value,
            best_score,
            debug,
        )
    
    def _find_dynamic_split_x(
        self,
        image: np.ndarray,
    ) -> int | None:
        """
        根據白色數字的垂直投影，
        自動尋找兩個數字之間的低谷。

        回傳整張 public-card-count ROI 內的 X 座標。
        """
        if (
            image is None
            or image.size == 0
        ):
            return None

        if image.ndim == 3:
            gray = cv2.cvtColor(
                image[:, :, :3],
                cv2.COLOR_BGR2GRAY,
            )
        else:
            gray = image.copy()

        # 僅保留較亮的數字區域。
        bright_mask = (
            gray >= 170
        ).astype(np.uint8)

        # 排除上下背景，只看數字主要高度。
        height, width = bright_mask.shape[:2]

        top = max(
            0,
            int(height * 0.15),
        )

        bottom = min(
            height,
            int(height * 0.85),
        )

        number_area = bright_mask[
            top:bottom,
            :
        ]

        if number_area.size == 0:
            return None

        vertical_projection = np.sum(
            number_area,
            axis=0,
        ).astype(np.float32)

        # 稍微平滑，避免字體抗鋸齒造成單點跳動。
        vertical_projection = np.convolve(
            vertical_projection,
            np.ones(
                3,
                dtype=np.float32,
            ) / 3.0,
            mode="same",
        )

        active_columns = np.where(
            vertical_projection >= 1.0
        )[0]

        if len(active_columns) < 4:
            return None

        content_left = int(
            active_columns[0]
        )

        content_right = int(
            active_columns[-1]
        )

        content_width = (
            content_right
            - content_left
            + 1
        )

        if content_width < 8:
            return None

        # 切線只允許落在整個數字區的中央範圍，
        # 避免切進第一個或第二個數字的外側。
        search_left = max(
            content_left + 3,
            int(
                content_left
                + content_width * 0.30
            ),
        )

        search_right = min(
            content_right - 2,
            int(
                content_left
                + content_width * 0.70
            ),
        )

        if search_right <= search_left:
            return None

        local_projection = (
            vertical_projection[
                search_left:search_right + 1
            ]
        )

        minimum_value = float(
            np.min(
                local_projection
            )
        )

        minimum_positions = np.where(
            local_projection
            == minimum_value
        )[0]

        if len(minimum_positions) == 0:
            return None

        # 若低谷有多個像素，取中央位置。
        local_middle = int(
            round(
                float(
                    np.mean(
                        minimum_positions
                    )
                )
            )
        )

        split_x = (
            search_left
            + local_middle
        )

        return max(
            1,
            min(
                split_x,
                width - 1,
            ),
        )
    
    def _has_digit_content(
            self,
            image: np.ndarray,
        ) -> bool:
            """
            判斷切片內是否真的有白色數字。

            單位數畫面中：
            - 左側有數字
            - 右側只有背景與少量雜訊
            """
            if (
                image is None
                or image.size == 0
            ):
                return False

            if image.ndim == 3:
                gray = cv2.cvtColor(
                    image[:, :, :3],
                    cv2.COLOR_BGR2GRAY,
                )
            else:
                gray = image.copy()

            # 真正的白色數字通常會有一定比例的高亮像素。
            bright_ratio = float(
                np.mean(
                    gray >= 180
                )
            )

            very_bright_ratio = float(
                np.mean(
                    gray >= 220
                )
            )

            contrast = float(
                np.std(gray)
            )

            return (
                bright_ratio >= 0.04
                and very_bright_ratio >= 0.01
                and contrast >= 35.0
            )
    
    def _detect_single_digit(
        self,
        image: np.ndarray,
    ) -> tuple[Optional[int], float]:
        if (
            image is None
            or image.size == 0
            or not self.single_templates
        ):
            return None, 0.0

        normalized = self._normalize(
            image
        )

        best_value: Optional[int] = None
        best_score = -1.0

        for value, templates in (
            self.single_templates.items()
        ):
            for _template_name, template in templates:
                result = cv2.matchTemplate(
                    normalized,
                    template,
                    cv2.TM_CCOEFF_NORMED,
                )

                if result.size == 0:
                    continue

                score = float(
                    result[0][0]
                )

                if score > best_score:
                    best_score = score
                    best_value = value

        best_score = max(
            0.0,
            best_score,
        )

        if best_score < self.threshold:
            return None, best_score

        return best_value, best_score

    def detect(
        self,
        image: np.ndarray,
    ) -> PublicCardCountResult:
        if image is None or image.size == 0:
            return PublicCardCountResult(
                value=None,
                tens=None,
                ones=None,
                tens_confidence=0.0,
                ones_confidence=0.0,
                confidence=0.0,
                split_x=None,
                tens_debug={},
                ones_debug={},
            )

        height, width = image.shape[:2]

        digit_left_x = max(
            0,
            min(
                self.digit_left_x,
                width - 2,
            ),
        )

        dynamic_split_x = (
            self._find_dynamic_split_x(
                image
            )
        )

        if dynamic_split_x is not None:
            split_x = dynamic_split_x
        else:
            split_x = max(
                digit_left_x + 1,
                min(
                    self.split_x,
                    width - 1,
                ),
            )

        digit_right_x = (
            width
            if self.digit_right_x is None
            else max(
                split_x + 1,
                min(
                    self.digit_right_x,
                    width,
                ),
            )
        )

        tens_image = image[
            0:height,
            digit_left_x:split_x,
        ].copy()

        ones_image = image[
            0:height,
            split_x:digit_right_x,
        ].copy()

        left_has_digit = (
            self._has_digit_content(
                tens_image
            )
        )

        right_has_digit = (
            self._has_digit_content(
                ones_image
            )
        )

        (
            tens,
            tens_confidence,
            tens_debug,
        ) = self._detect_digit(
            tens_image
        )

        (
            ones,
            ones_confidence,
            ones_debug,
        ) = self._detect_digit(
            ones_image
        )

        # -----------------------------------------
        # 兩位數模式
        # 左右兩側都必須真的具有數字內容
        # -----------------------------------------
        if (
            left_has_digit
            and right_has_digit
            and tens is not None
            and ones is not None
        ):
            value = (
                tens * 10
                + ones
            )

            confidence = min(
                tens_confidence,
                ones_confidence,
            )

        # -----------------------------------------
        # 單位數模式
        # 數字顯示在左側，而不是右側
        # -----------------------------------------
        elif (
            left_has_digit
            and not right_has_digit
        ):
            (
                single_value,
                single_confidence,
            ) = self._detect_single_digit(
                tens_image
            )

            if single_value is not None:
                value = single_value
                confidence = (
                    single_confidence
                )

                tens = None
                ones = single_value

                tens_confidence = 0.0
                ones_confidence = (
                    single_confidence
                )

            else:
                value = None

                tens = None
                ones = None

                tens_confidence = 0.0
                ones_confidence = (
                    single_confidence
                )

                confidence = (
                    single_confidence
                )

        # -----------------------------------------
        # 內容不明或部分辨識失敗
        # -----------------------------------------
        else:
            value = None

            confidence = max(
                tens_confidence,
                ones_confidence,
            )

        return PublicCardCountResult(
            value=value,
            tens=tens,
            ones=ones,
            tens_confidence=tens_confidence,
            ones_confidence=ones_confidence,
            confidence=confidence,
            split_x=split_x,
            tens_debug=tens_debug,
            ones_debug=ones_debug,
        )