from __future__ import annotations

from dataclasses import dataclass
from typing import Dict, List

import cv2
import numpy as np
from pathlib import Path
from statistics import median

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
        cluster_mode: str = "largest",
        crop_offset_x: int = 0,
    ) -> None:
        self.region = region

        self.expected_card_width = expected_card_width
        self.expected_card_height = expected_card_height
        self.card_pitch = card_pitch

        self.min_card_width = min_card_width
        self.max_card_width = max_card_width
        self.min_card_height = min_card_height
        self.cluster_mode = cluster_mode
        self.crop_offset_x = crop_offset_x
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

        card_ranges = self._find_card_ranges(
            roi
        )

        # 只保留主要連續手牌群，排除遠處背景誤判。
        card_ranges = self._keep_main_hand_cluster(
            card_ranges
        )

        

        if self.cluster_mode == "center":
            card_ranges = (
                self._normalize_detected_ranges(
                    card_ranges,
                    roi.shape[1]
                )
            )

            

        else:
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

            # 最終卡片裁切左右各放寬 2px，
            # 降低不同解析度縮放造成的邊界誤差。
            crop_padding_x = 2

            crop_start_x = max(
                0,
                start_x - crop_padding_x
            )

            crop_end_x = min(
                roi.shape[1],
                start_x
                + full_card_width
                + crop_padding_x
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
    def _normalize_detected_ranges(
        self,
        ranges: List[tuple[int, int]],
        roi_width: int
    ) -> List[tuple[int, int]]:
        """
        將中央出牌區候選框依張數重新置中。

        若四張牌只偵測到連續三張，
        依整組中心偏移補回漏掉的外側牌。
        """
        if not ranges:
            return []

        sorted_ranges = sorted(
            ranges,
            key=lambda item: item[0]
        )

        detected_count = len(
            sorted_ranges
        )

        card_width = int(
            self.expected_card_width
        )

        pitch = float(
            self.card_pitch
        )

        detected_centers = [
            (
                start_x + end_x
            ) / 2.0
            for start_x, end_x in sorted_ranges
        ]

        # -----------------------------------------
        # 依候選間距推算是否漏掉中間卡片
        # -----------------------------------------
        missing_middle_count = 0

        for index in range(
            len(detected_centers) - 1
        ):
            distance = (
                detected_centers[index + 1]
                - detected_centers[index]
            )

            estimated_steps = max(
                1,
                round(
                    distance / pitch
                )
            )

            # 例如兩個候選中心相距約 136px：
            # 136 / 68 = 2，代表中間漏掉一張。
            if (
                estimated_steps >= 2
                and 48.0
                <= distance / estimated_steps
                <= 86.0
            ):
                missing_middle_count += (
                    estimated_steps - 1
                )

        card_count = (
            detected_count
            + missing_middle_count
        )
        

        roi_center = (
            roi_width / 2.0
        )

        detected_group_center = (
            detected_centers[0]
            + detected_centers[-1]
        ) / 2.0

        center_offset = (
            detected_group_center
            - roi_center
        )
        

        # 偶數牌組若漏掉一側最外牌，
        # 剩餘牌組中心會偏離約半個卡距。
        if (
            missing_middle_count == 0
            and detected_count >= 2
            and 24.0
            <= abs(center_offset)
            <= 44.0
        ):
            card_count += 1

            

        group_width = (
            card_width
            + (
                card_count - 1
            ) * pitch
        )

        group_left = round(
            (
                roi_width
                - group_width
            ) / 2.0
            + self.crop_offset_x
        )
        

        normalized: List[
            tuple[int, int]
        ] = []

        for index in range(
            card_count
        ):
            crop_start_x = round(
                group_left
                + index * pitch
            )

            crop_start_x = max(
                0,
                crop_start_x
            )

            crop_end_x = min(
                roi_width,
                crop_start_x + card_width
            )

            if (
                crop_end_x - crop_start_x
                < self.min_card_width
            ):
                continue

            normalized.append(
                (
                    crop_start_x,
                    crop_end_x
                )
            )

        return normalized


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

        starts = [
            start_x
            for start_x, _ in ranges
        ]

        first_x = starts[0]

        # -----------------------------------------
        # 自動估算實際卡距
        # 避免固定 68px 導致越後面的牌誤差越大
        # -----------------------------------------
        pitch_candidates: List[float] = []

        for index in range(
            len(starts) - 1
        ):
            distance = (
                starts[index + 1]
                - starts[index]
            )

            # 正常相鄰卡距
            if 50 <= distance <= 85:
                pitch_candidates.append(
                    float(distance)
                )

            # 中間可能漏抓一張，將距離除以推測張數
            elif 85 < distance <= 220:
                estimated_steps = max(
                    2,
                    round(
                        distance
                        / float(self.card_pitch)
                    )
                )

                corrected_pitch = (
                    distance
                    / estimated_steps
                )

                if 50 <= corrected_pitch <= 85:
                    pitch_candidates.append(
                        corrected_pitch
                    )

        if pitch_candidates:
            actual_pitch = float(
                median(pitch_candidates)
            )
        else:
            actual_pitch = float(
                self.card_pitch
            )

        # 根據第一張至最後一張的距離推算牌數
        if len(starts) >= 2:
            estimated_count = max(
                1,
                round(
                    (
                        starts[-1]
                        - first_x
                    )
                    / actual_pitch
                )
                + 1
            )
        else:
            estimated_count = 1

        rebuilt: List[tuple[int, int]] = []

        for index in range(
            estimated_count
        ):
            # 使用浮點卡距，最後才四捨五入，
            # 避免每張卡累積整數誤差。
            start_x = round(
                first_x
                + index * actual_pitch
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
                (
                    start_x,
                    end_x
                )
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

        if self.cluster_mode == "center":
            roi_center = (
                int(self.region["width"])
                / 2.0
            )

            expected_pitch = float(
                self.card_pitch
            )

            def build_grid_candidate(
                half_offset: float,
            ) -> tuple[
                list[tuple[int, int]],
                list[int],
                float,
            ]:
                """
                half_offset：
                0.0 = 奇數張排列，中間有一張牌
                0.5 = 偶數張排列，中間是兩張牌間隙
                """
                snapped_slots: Dict[
                    int,
                    tuple[
                        float,
                        tuple[int, int]
                    ]
                ] = {}

                for start_x, end_x in ranges:
                    center_x = (
                        start_x + end_x
                    ) / 2.0

                    raw_slot = (
                        (
                            center_x
                            - roi_center
                        ) / expected_pitch
                        - half_offset
                    )

                    slot_index = round(
                        raw_slot
                    )

                    slot_center = (
                        roi_center
                        + (
                            slot_index
                            + half_offset
                        ) * expected_pitch
                    )

                    slot_error = abs(
                        center_x
                        - slot_center
                    )

                    # 不同解析度允許少量誤差，
                    # 但避免吸入遠處背景。
                    if slot_error > 20.0:
                        continue

                    current = snapped_slots.get(
                        slot_index
                    )

                    if (
                        current is None
                        or slot_error < current[0]
                    ):
                        snapped_slots[slot_index] = (
                            slot_error,
                            (
                                start_x,
                                end_x
                            )
                        )

                if not snapped_slots:
                    return (
                        [],
                        [],
                        float("inf"),
                    )

                available_slots = sorted(
                    snapped_slots
                )

                groups: list[list[int]] = []
                current_group = [
                    available_slots[0]
                ]

                for slot in available_slots[1:]:
                    if slot == current_group[-1] + 1:
                        current_group.append(
                            slot
                        )
                    else:
                        groups.append(
                            current_group
                        )
                        current_group = [
                            slot
                        ]

                groups.append(
                    current_group
                )

                best_slots: list[int] = []
                best_score = None

                # 每個連續群都要再檢查所有連續子群。
                # 例如 [-3, -2, -1, 0, 1] 中，
                # [-2, -1, 0, 1] 才可能是真正置中的 4 張牌。
                for group_slots in groups:
                    group_length = len(
                        group_slots
                    )

                    for start_index in range(
                        group_length
                    ):
                        for end_index in range(
                            start_index + 1,
                            group_length + 1
                        ):
                            candidate_slots = group_slots[
                                start_index:end_index
                            ]

                            first_slot_center = (
                                roi_center
                                + (
                                    candidate_slots[0]
                                    + half_offset
                                ) * expected_pitch
                            )

                            last_slot_center = (
                                roi_center
                                + (
                                    candidate_slots[-1]
                                    + half_offset
                                ) * expected_pitch
                            )

                            group_center = (
                                first_slot_center
                                + last_slot_center
                            ) / 2.0

                            center_error = abs(
                                group_center
                                - roi_center
                            )

                            # 出牌區必須置中。
                            if center_error > 20.0:
                                continue

                            average_slot_error = (
                                sum(
                                    snapped_slots[slot][0]
                                    for slot in candidate_slots
                                )
                                / len(candidate_slots)
                            )

                            # 在已經置中的條件下，
                            # 優先保留張數較多的連續牌組。
                            score = (
                                len(candidate_slots),
                                -center_error,
                                -average_slot_error,
                            )

                            if (
                                best_score is None
                                or score > best_score
                            ):
                                best_score = score
                                best_slots = (
                                    candidate_slots
                                )
                if not best_slots:
                    return (
                        [],
                        [],
                        float("inf"),
                    )
                    
                selected = [
                    snapped_slots[slot][1]
                    for slot in best_slots
                ]

                average_error = (
                    sum(
                        snapped_slots[slot][0]
                        for slot in best_slots
                    )
                    / len(best_slots)
                )

                return (
                    selected,
                    best_slots,
                    average_error,
                )

            # 奇數張排列：
            # ..., 356, 424, 492, ...
            (
                odd_selected,
                odd_slots,
                odd_error,
            ) = build_grid_candidate(
                half_offset=0.0
            )

            # 偶數張排列：
            # ..., 322, 390, 458, 526, ...
            (
                even_selected,
                even_slots,
                even_error,
            ) = build_grid_candidate(
                half_offset=0.5
            )

            def candidate_score(
                selected: list[tuple[int, int]],
                slots: list[int],
                average_error: float,
                half_offset: float,
            ) -> tuple:
                if not selected:
                    return (
                        -1,
                        float("-inf"),
                        float("-inf"),
                    )

                first_center = (
                    roi_center
                    + (
                        slots[0]
                        + half_offset
                    ) * expected_pitch
                )

                last_center = (
                    roi_center
                    + (
                        slots[-1]
                        + half_offset
                    ) * expected_pitch
                )

                group_center = (
                    first_center
                    + last_center
                ) / 2.0

                center_error = abs(
                    group_center
                    - roi_center
                )

                # 出牌組一定是置中的。
                # 整組偏離中央超過 20px，
                # 即使候選張數較多，也視為背景或錯誤群組。
                if center_error > 20.0:
                    return (
                        -1,
                        float("-inf"),
                        float("-inf"),
                    )

                # 先比較是否置中，再比較張數，
                # 避免偏左／偏右的背景群組因張數多而勝出。
                return (
                    -center_error,
                    len(selected),
                    -average_error,
                )


            odd_score = candidate_score(
                odd_selected,
                odd_slots,
                odd_error,
                0.0,
            )

            even_score = candidate_score(
                even_selected,
                even_slots,
                even_error,
                0.5,
            )

            if even_score > odd_score:
                selected = even_selected
            else:
                selected = odd_selected
                
            return selected

        clusters: List[
            List[tuple[int, int]]
        ] = []

        current_cluster = [
            ranges[0]
        ]

        for current_range in ranges[1:]:
            previous_range = (
                current_cluster[-1]
            )

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

        clusters.sort(
            key=lambda cluster: (
                len(cluster),
                (
                    cluster[-1][1]
                    - cluster[0][0]
                ),
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

    def classify_card_type(
        self,
        card: np.ndarray,
    ) -> tuple[str, float]:
        """
        對外提供卡片類型重新辨識。

        用於卡片位置經過二次校正後，
        以最終裁切圖片再次判斷：
        - personal_event
        - public_event
        - empty
        """
        return self._classify_card_type(
            card
        )

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

        if best_personal_score >= 0.82:
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