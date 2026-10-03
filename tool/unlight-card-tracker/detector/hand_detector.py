from __future__ import annotations

from dataclasses import dataclass
from typing import Dict, List

import cv2
import numpy as np
from pathlib import Path

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
class DetectedCard:
    x: int
    y: int
    width: int
    height: int
    confidence: float
    card_type: str


@dataclass
class HandResult:
    cards: List[DetectedCard]
    roi: np.ndarray
    debug: np.ndarray


class HandDetector:
    def __init__(
        self,
        region: Dict[str, int],
        personal_template_dir: Path | None = None,
        expected_card_width: int = 62,
        expected_card_height: int = 90,
        card_pitch: int = 68,
        min_card_width: int = 37,
        max_card_width: int = 62,
        min_card_height: int = 70,
    ) -> None:
        self.region = region

        self.expected_card_width = expected_card_width
        self.expected_card_height = expected_card_height
        self.card_pitch = card_pitch

        self.min_card_width = min_card_width
        self.max_card_width = max_card_width
        self.min_card_height = min_card_height
        self.personal_templates = self._load_personal_templates(
            personal_template_dir
        )

    def detect(
        self,
        frame: np.ndarray
    ) -> HandResult:
        region_x = int(self.region["x"])
        region_y = int(self.region["y"])
        region_width = int(self.region["width"])
        region_height = int(self.region["height"])

        roi = frame[
            region_y:region_y + region_height,
            region_x:region_x + region_width
        ].copy()

        if roi.size == 0:
            return HandResult(
                cards=[],
                roi=roi,
                debug=roi
            )

        card_ranges = self._find_card_ranges(roi)

        # 只保留主要連續手牌群，排除遠處背景誤判。
        card_ranges = self._keep_main_hand_cluster(
            card_ranges
        )

        card_ranges = self._rebuild_card_grid(
            card_ranges,
            roi.shape[1]
        )

        cards: List[DetectedCard] = []

        for start_x, end_x in card_ranges:
            card_width = end_x - start_x

            if card_width < self.min_card_width:
                continue

            if card_width > self.max_card_width:
                continue

            card_y = 0

            card_height = min(
                self.expected_card_height,
                roi.shape[0] - card_y
            )

            if card_height < self.min_card_height:
                continue


            
            full_card_width = (
                self.expected_card_width
            )

            crop_start_x = start_x
            crop_end_x = min(
                roi.shape[1],
                crop_start_x + full_card_width
            )

            card_image = roi[
                card_y:card_y + card_height,
                crop_start_x:crop_end_x
            ]
            actual_width = (
                crop_end_x - crop_start_x
            )

            card_type, confidence = (
                self._classify_card_type(
                    card_image
                )
            )
            
            if card_type == "empty":
                continue

            cards.append(
                DetectedCard(
                    x=crop_start_x,
                    y=card_y,
                    width=actual_width,
                    height=card_height,
                    confidence=confidence,
                    card_type=card_type
                )
            )

        cards.sort(
            key=lambda card: card.x
        )

        debug = roi.copy()

        for index, card in enumerate(
            cards,
            start=1
        ):
            if card.card_type == "personal_event":
                box_color = (0, 165, 255)
            elif card.card_type == "public_event":
                box_color = (0, 255, 0)
            else:
                box_color = (255, 255, 0)

            cv2.rectangle(
                debug,
                (card.x, card.y),
                (
                    card.x + card.width,
                    card.y + card.height
                ),
                box_color,
                2
            )

            label = (
                f"{index} "
                f"{card.card_type}"
            )

            cv2.putText(
                debug,
                label,
                (
                    card.x + 1,
                    max(14, card.y + 14)
                ),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.32,
                box_color,
                1,
                cv2.LINE_AA
            )

        return HandResult(
            cards=cards,
            roi=roi,
            debug=debug
        )
    def _rebuild_card_grid(
        self,
        ranges: List[tuple[int, int]],
        roi_width: int
    ) -> List[tuple[int, int]]:
        if not ranges:
            return []

        ranges = sorted(
            ranges,
            key=lambda item: item[0]
        )

        first_x = ranges[0][0]
        last_x = max(
            end_x
            for _, end_x in ranges
        )

        occupied_width = (
            last_x - first_x
        )

        estimated_count = max(
            1,
            round(
                occupied_width
                / self.card_pitch
            )
        )

        rebuilt: List[tuple[int, int]] = []

        for index in range(estimated_count):
            start_x = (
                first_x
                + index * self.card_pitch
            )

            end_x = min(
                roi_width,
                start_x
                + self.expected_card_width
            )

            if (
                end_x - start_x
                < self.min_card_width
            ):
                continue

            rebuilt.append(
                (start_x, end_x)
            )

        return rebuilt    
    def _keep_main_hand_cluster(
        self,
        ranges: List[tuple[int, int]],
        max_gap: int = 28
    ) -> List[tuple[int, int]]:
        if not ranges:
            return []

        ranges = sorted(
            ranges,
            key=lambda item: item[0]
        )

        clusters: List[List[tuple[int, int]]] = []
        current_cluster = [ranges[0]]

        for current_range in ranges[1:]:
            previous_range = current_cluster[-1]

            gap = (
                current_range[0]
                - previous_range[1]
            )

            if gap <= max_gap:
                current_cluster.append(
                    current_range
                )
            else:
                clusters.append(
                    current_cluster
                )

                current_cluster = [
                    current_range
                ]

        clusters.append(
            current_cluster
        )

        # 優先保留張數最多的連續群；
        # 張數相同時保留總寬度較大的群。
        clusters.sort(
            key=lambda cluster: (
                len(cluster),
                cluster[-1][1] - cluster[0][0]
            ),
            reverse=True
        )

        return clusters[0]
    
    def _find_card_ranges(
        self,
        roi: np.ndarray
    ) -> List[tuple[int, int]]:
        gray = cv2.cvtColor(
            roi,
            cv2.COLOR_BGR2GRAY
        )

        blurred = cv2.GaussianBlur(
            gray,
            (3, 3),
            0
        )

        # 卡片區通常比空白背景更亮、細節更多。
        horizontal_activity = np.std(
            blurred.astype(np.float32),
            axis=0
        )

        activity_mask = (
            horizontal_activity > 14.0
        ).astype(np.uint8)

        # 補合卡片內部小空隙，但不要把相鄰卡片黏死。
        kernel = np.ones(
            3,
            dtype=np.uint8
        )

        activity_mask = cv2.morphologyEx(
            activity_mask.reshape(1, -1),
            cv2.MORPH_CLOSE,
            kernel.reshape(1, -1),
            iterations=1
        ).reshape(-1)

        groups = self._groups_from_mask(
            activity_mask
        )

        ranges: List[tuple[int, int]] = []

        for start_x, end_x in groups:
            width = end_x - start_x

            if width < self.min_card_width:
                continue

            if width <= self.max_card_width:
                ranges.append(
                    (start_x, end_x)
                )
                continue

            # 若一整排被黏在一起，依預期卡寬切割。
            ranges.extend(
                self._split_wide_group(
                    roi,
                    start_x,
                    end_x
                )
            )

        return self._merge_nearby_ranges(
            ranges
        )

    def _split_wide_group(
        self,
        roi: np.ndarray,
        start_x: int,
        end_x: int
    ) -> List[tuple[int, int]]:
        gray = cv2.cvtColor(
            roi,
            cv2.COLOR_BGR2GRAY
        )

        gray = cv2.GaussianBlur(
            gray,
            (3, 3),
            0
        )

        # 垂直邊緣強度，用來尋找卡片分隔。
        sobel_x = cv2.Sobel(
            gray,
            cv2.CV_32F,
            1,
            0,
            ksize=3
        )

        edge_strength = np.mean(
            np.abs(sobel_x),
            axis=0
        )

        group_width = end_x - start_x

        estimated_count = max(
            1,
            round(
                group_width
                / self.expected_card_width
            )
        )

        ranges: List[tuple[int, int]] = []

        current_x = start_x

        for index in range(estimated_count):
            remaining = estimated_count - index

            if remaining <= 1:
                next_x = end_x
            else:
                expected_split = (
                    current_x
                    + self.expected_card_width
                )

                search_left = max(
                    current_x + self.min_card_width,
                    expected_split - 8
                )

                search_right = min(
                    end_x - (
                        remaining - 1
                    ) * self.min_card_width,
                    expected_split + 8
                )

                if search_right <= search_left:
                    next_x = min(
                        end_x,
                        expected_split
                    )
                else:
                    local_scores = edge_strength[
                        search_left:search_right
                    ]

                    # 卡片間黑縫通常亮度低。
                    local_gray = np.mean(
                        gray[
                            :,
                            search_left:search_right
                        ],
                        axis=0
                    )

                    combined = (
                        local_gray
                        - local_scores * 0.15
                    )

                    offset = int(
                        np.argmin(combined)
                    )

                    next_x = (
                        search_left + offset
                    )

            if (
                next_x - current_x
                >= self.min_card_width
            ):
                ranges.append(
                    (current_x, next_x)
                )

            current_x = next_x

        return ranges

    def _find_card_vertical_bounds(
        self,
        roi: np.ndarray,
        start_x: int,
        end_x: int
    ) -> tuple[int, int]:
        column = roi[
            :,
            start_x:end_x
        ]

        gray = cv2.cvtColor(
            column,
            cv2.COLOR_BGR2GRAY
        )

        row_activity = np.std(
            gray.astype(np.float32),
            axis=1
        )

        active_rows = np.where(
            row_activity > 10.0
        )[0]

        if len(active_rows) == 0:
            return (
                0,
                min(
                    roi.shape[0],
                    self.expected_card_height
                )
            )

        top = max(
            0,
            int(active_rows[0]) - 2
        )

        bottom = min(
            roi.shape[0],
            int(active_rows[-1]) + 3
        )

        height = bottom - top

        if height > self.expected_card_height + 10:
            bottom = min(
                roi.shape[0],
                top + self.expected_card_height
            )

        return (
            top,
            bottom - top
        )

    def _load_personal_templates(
        self,
        folder: Path | None
    ) -> List[np.ndarray]:

        templates: List[np.ndarray] = []

        if folder is None or not folder.exists():
            return templates

        for path in sorted(folder.glob("*.png")):
            image = read_image_unicode(
                path,
                cv2.IMREAD_GRAYSCALE
            )

            if image is None:
                continue

            normalized = cv2.resize(
                image,
                (48, 112),
                interpolation=cv2.INTER_AREA
            )

            templates.append(normalized)

        print(
            f"已載入 {len(templates)} 張個人事件卡模板"
        )

        return templates


    def _classify_card_type(
        self,
        card: np.ndarray
    ) -> tuple[str, float]:

        if card.size == 0:
            return (
                "empty",
                0.0
            )

        normalized = cv2.resize(
            card,
            (48, 112),
            interpolation=cv2.INTER_AREA
        )

        gray = cv2.cvtColor(
            normalized,
            cv2.COLOR_BGR2GRAY
        )

        image_std = float(
            np.std(gray)
        )

        edge_map = cv2.Canny(
            gray,
            40,
            120
        )

        edge_ratio = float(
            np.mean(edge_map > 0)
        )

        # 排除空白欄位
        if (
            image_std < 48.0
            and edge_ratio < 0.12
        ):
            return (
                "empty",
                0.95
            )

        best_personal_score = -1.0

        for template in self.personal_templates:
            score = float(
                cv2.matchTemplate(
                    gray,
                    template,
                    cv2.TM_CCOEFF_NORMED
                )[0][0]
            )

            if score > best_personal_score:
                best_personal_score = score

        if best_personal_score >= 0.72:
            return (
                "personal_event",
                best_personal_score
            )

        # 其餘有效卡片先視為公牌
        return (
            "public_event",
            max(
                0.0,
                1.0 - max(
                    best_personal_score,
                    0.0
                )
            )
        )

    @staticmethod
    def _groups_from_mask(
        mask: np.ndarray
    ) -> List[tuple[int, int]]:
        groups: List[tuple[int, int]] = []

        start = None

        for index, value in enumerate(mask):
            if value and start is None:
                start = index

            if (
                not value
                and start is not None
            ):
                groups.append(
                    (start, index)
                )

                start = None

        if start is not None:
            groups.append(
                (start, len(mask))
            )

        return groups

    def _merge_nearby_ranges(
        self,
        ranges: List[tuple[int, int]]
    ) -> List[tuple[int, int]]:
        if not ranges:
            return []

        ranges = sorted(
            ranges,
            key=lambda item: item[0]
        )

        merged: List[tuple[int, int]] = []

        for start_x, end_x in ranges:
            if not merged:
                merged.append(
                    (start_x, end_x)
                )
                continue

            previous_start, previous_end = (
                merged[-1]
            )

            gap = start_x - previous_end

            combined_width = (
                end_x - previous_start
            )

            if (
                gap <= 2
                and combined_width
                <= self.max_card_width
            ):
                merged[-1] = (
                    previous_start,
                    end_x
                )
            else:
                merged.append(
                    (start_x, end_x)
                )

        return merged