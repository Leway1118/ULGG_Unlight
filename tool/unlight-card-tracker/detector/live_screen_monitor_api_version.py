from __future__ import annotations

import argparse
import json
import time
import shutil
from datetime import datetime
from pathlib import Path
from threading import Event, Lock, Thread
from typing import Any

import cv2
import mss
import numpy as np
import uvicorn

import card_detection_api

from public_card_count_detector import (
    PublicCardCountDetector,
)
from hand_detector import HandDetector
from site_detector import SiteDetector
from ok_button_detector import OkButtonDetector
from result_detector import ResultDetector



ROOT = Path(__file__).resolve().parent

REFERENCE_WIDTH = 848
REFERENCE_HEIGHT = 760

SITE_TEMPLATE_DIR = (
    ROOT.parent
    / "assets"
    / "sites"
)
# 場地背景辨識區域
# 後續再依 debug 圖微調
SITE_BACKGROUND_ROI = {
    "x": 0,
    "y": 63,
    "width": 848,
    "height": 418,
}

PLAYER_PLAY_ROI = {
    "x": 0,
    "y": 380,
    "width": 848,
    "height": 90,
}

# =========================================================
# OK／鎖鍊按鈕 DEBUG ROI
# 基準畫面：848 × 760
# =========================================================
OK_BUTTON_ROI = {
    "x": 590,
    "y": 665,
    "width": 95,
    "height": 75,
}
# =========================================================
# 對戰結果畫面 ROI
# 基準畫面：848 × 760
# =========================================================
RESULT_ROI = {
    "x": 339,
    "y": 217,
    "width": 169,
    "height": 113,
}

player_play_detector = HandDetector(
    region=PLAYER_PLAY_ROI,
    personal_template_dir=(
        ROOT
        / "templates"
        / "personal-events"
    ),
    expected_card_width=57,
    expected_card_height=90,

    # 我方出牌越往右累積偏移，
    # 先將卡距由 68px 縮回 1px。
    card_pitch=67,

    min_card_width=48,
    max_card_width=62,
    min_card_height=82,

    # 出牌區以中央牌組為準，
    # 不使用最大背景群組。
    cluster_mode="center",
)


# =========================================================
# 對方出牌區 DEBUG ROI
# 基準畫面：848 × 760
# 以畫面中央向左右擴張，支援約 10 張出牌
# 高度與自身手牌一致：95px
# 格式：(x, y, width, height)
# =========================================================
OPPONENT_PLAY_ROI = {
    "x": 0,
    "y": 100,
    "width": 848,
    "height": 90,
}

# =========================================================
# 公牌剩餘數量 DEBUG ROI
# 基準畫面：848 × 760
# 格式：(x, y, width, height)
# 先抓大範圍，之後依輸出圖片手動微調
# =========================================================
PUBLIC_CARD_COUNT_ROI = {
    "x": 70,
    "y": 60,
    "width": 35,
    "height": 35,
}

PUBLIC_COUNT_TENS_ROI = (
    0,
    0,
    17,
    35,
)

PUBLIC_COUNT_ONES_ROI = (
    17,
    0,
    35,
    35,
)

def write_image_unicode(
    path: Path,
    image: np.ndarray,
) -> bool:
    if image is None or image.size == 0:
        return False

    path.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    suffix = path.suffix.lower()

    if suffix not in {
        ".jpg",
        ".jpeg",
        ".png",
        ".webp",
    }:
        suffix = ".jpg"

    success, encoded = cv2.imencode(
        suffix,
        image,
    )

    if not success:
        return False

    try:
        encoded.tofile(
            str(path)
        )
        return True

    except OSError:
        return False

def clear_debug_card_dir(
    folder: Path,
) -> None:
    if not folder.exists():
        return

    for path in folder.glob("*.png"):
        try:
            path.unlink()
        except OSError:
            pass


state_lock = Lock()
stop_event = Event()
public_count_detector: (
    PublicCardCountDetector | None
) = None

opponent_hand_detector: (
    HandDetector | None
) = None

site_detector: (
    SiteDetector | None
) = None

ok_button_detector: (
    OkButtonDetector | None
) = None
result_detector: (
    ResultDetector | None
) = None

confirmed_site: str | None = None
confirmed_site_confidence: float = 0.0
confirmed_site_score_gap: float = 0.0

site_candidate: str | None = None
site_candidate_count: int = 0

SITE_CONFIRM_COUNT = 3

opponent_hand_detector = HandDetector(
    region=OPPONENT_PLAY_ROI,
    personal_template_dir=(
        ROOT
        / "templates"
        / "personal-events"
    ),
    expected_card_width=57,
    expected_card_height=90,
    card_pitch=68,
    min_card_width=37,
    max_card_width=62,
    min_card_height=70,

    # 對方出牌同樣以中央牌組為準。
    cluster_mode="center",
)


def grab_screen(
    screen_capture: mss.MSS,
    monitor: dict[str, int],
) -> np.ndarray:
    screenshot = screen_capture.grab(
        monitor
    )

    image = np.asarray(
        screenshot
    )

    frame = cv2.cvtColor(
        image,
        cv2.COLOR_BGRA2BGR,
    )

    # 將玩家目前的遊戲畫面統一轉換為
    # 辨識器原始基準尺寸 848 × 760。
    if (
        frame.shape[1] != REFERENCE_WIDTH
        or frame.shape[0] != REFERENCE_HEIGHT
    ):
        frame = cv2.resize(
            frame,
            (
                REFERENCE_WIDTH,
                REFERENCE_HEIGHT,
            ),
            interpolation=cv2.INTER_CUBIC,
        )

    return frame

def crop_region(
    image: np.ndarray,
    region: dict[str, int],
) -> np.ndarray:
    x = int(region["x"])
    y = int(region["y"])
    width = int(region["width"])
    height = int(region["height"])

    image_height, image_width = image.shape[:2]

    x1 = max(
        0,
        min(x, image_width)
    )

    y1 = max(
        0,
        min(y, image_height)
    )

    x2 = max(
        0,
        min(x + width, image_width)
    )

    y2 = max(
        0,
        min(y + height, image_height)
    )

    if x2 <= x1 or y2 <= y1:
        return np.empty(
            (0, 0, 3),
            dtype=np.uint8
        )

    return image[
        y1:y2,
        x1:x2
    ].copy()
    

def classify_final_card_type(
    detector: HandDetector,
    card_image: np.ndarray,
) -> tuple[str, float]:
    public_method = getattr(
        detector,
        "classify_card_type",
        None,
    )

    if callable(public_method):
        return public_method(
            card_image
        )

    private_method = getattr(
        detector,
        "_classify_card_type",
        None,
    )

    if callable(private_method):
        return private_method(
            card_image
        )

    raise AttributeError(
        "HandDetector 缺少卡片類型辨識方法"
    )
  

CARD_BACK_TEMPLATE_DIR = (
    ROOT
    / "templates"
    / "card-back"
)

card_back_templates: list[
    np.ndarray
] = []


def load_card_back_templates() -> None:
    card_back_templates.clear()

    if not CARD_BACK_TEMPLATE_DIR.exists():
        return

    for path in CARD_BACK_TEMPLATE_DIR.glob(
        "*.png"
    ):
        image = cv2.imdecode(
            np.fromfile(
                str(path),
                dtype=np.uint8,
            ),
            cv2.IMREAD_COLOR,
        )

        if image is None:
            continue

        card_back_templates.append(
            image
        )


def is_card_back(
    card_image: np.ndarray,
    threshold: float = 0.82,
) -> bool:
    if (
        card_image is None
        or card_image.size == 0
        or not card_back_templates
    ):
        return False

    best_score = 0.0

    for template in card_back_templates:
        resized = cv2.resize(
            card_image,
            (
                template.shape[1],
                template.shape[0],
            ),
            interpolation=cv2.INTER_AREA,
        )

        result = cv2.matchTemplate(
            resized,
            template,
            cv2.TM_CCOEFF_NORMED,
        )

        score = float(
            result.max()
        )

        best_score = max(
            best_score,
            score,
        )

    return best_score >= threshold 
  
   
def is_valid_play_card_crop(
    card_image: np.ndarray,
) -> bool:
    """
    驗證候選框頂端是否具有卡片的黑色標題列。

    用來排除人物、場地、特效與其他背景矩形。
    """
    if (
        card_image is None
        or card_image.size == 0
        or card_image.shape[0] < 70
        or card_image.shape[1] < 42
    ):
        return False

    # 候選框可能上下偏移 1～2px，
    # 避開最外側邊框後檢查標題列。
    top_end = min(
        16,
        card_image.shape[0],
    )

    top_area = card_image[
        2:top_end,
        :
    ]

    if top_area.size == 0:
        return False

    gray = cv2.cvtColor(
        top_area,
        cv2.COLOR_BGR2GRAY,
    )

    mean_brightness = float(
        gray.mean()
    )

    dark_ratio = float(
        np.mean(
            gray < 110
        )
    )

    very_dark_ratio = float(
        np.mean(
            gray < 65
        )
    )

    return (
        mean_brightness <= 120
        and dark_ratio >= 0.42
        and very_dark_ratio >= 0.12
    )

def create_signature(
    cards: list[dict[str, Any]],
) -> tuple:
    """
    將卡片結果轉成可比較的簽名，
    用來判斷本次辨識結果是否有變化。
    """
    signature = []

    for card in cards:
        upper = card.get(
            "upper",
            {},
        )

        lower = card.get(
            "lower",
            {},
        )

        signature.append(
            (
                upper.get(
                    "icon_type"
                ),
                upper.get(
                    "value"
                ),
                lower.get(
                    "icon_type"
                ),
                lower.get(
                    "value"
                ),
                card.get(
                    "card_type"
                ),
            )
        )

    return tuple(
        signature
    )

def select_play_candidate_group(
    candidates: list[
        tuple[
            Any,
            np.ndarray,
            str,
            float,
        ]
    ],
    roi_width: int,
    expected_pitch: float = 68.0,
    pitch_tolerance: float = 12.0,
    center_tolerance: float = 18.0,
) -> list[
    tuple[
        Any,
        np.ndarray,
        str,
        float,
    ]
]:
    """
    從已通過卡面驗證的候選框中，
    選出間距規律且整組置中的連續牌組。

    優先順序：
    1. 整組中心接近 ROI 中央
    2. 相鄰距離接近 68px
    3. 張數較多
    """
    if not candidates:
        return []

    ordered = sorted(
        candidates,
        key=lambda item: (
            float(item[0].x)
            + float(item[0].width) / 2
        ),
    )

    roi_center = (
        roi_width / 2
    )

    best_group = []
    best_score = None

    candidate_count = len(
        ordered
    )

    for start_index in range(
        candidate_count
    ):
        for end_index in range(
            start_index + 1,
            candidate_count + 1,
        ):
            group = ordered[
                start_index:end_index
            ]

            centers = [
                (
                    float(item[0].x)
                    + float(item[0].width) / 2
                )
                for item in group
            ]

            if len(centers) >= 2:
                distances = [
                    centers[index]
                    - centers[index - 1]
                    for index in range(
                        1,
                        len(centers)
                    )
                ]

                if any(
                    abs(
                        distance
                        - expected_pitch
                    ) > pitch_tolerance
                    for distance in distances
                ):
                    continue

                pitch_error = (
                    sum(
                        abs(
                            distance
                            - expected_pitch
                        )
                        for distance in distances
                    )
                    / len(distances)
                )
            else:
                pitch_error = 0.0

            group_center = (
                centers[0]
                + centers[-1]
            ) / 2

            center_error = abs(
                group_center
                - roi_center
            )

            # 出牌組正常應置中。
            if (
                len(group) >= 2
                and center_error
                > center_tolerance
            ):
                continue

            detector_confidence = sum(
                float(item[0].confidence)
                for item in group
            ) / len(group)

            type_confidence = sum(
                float(item[3])
                for item in group
            ) / len(group)

            score = (
                len(group),
                -center_error,
                -pitch_error,
                detector_confidence
                + type_confidence,
            )

            if (
                best_score is None
                or score > best_score
            ):
                best_score = score
                best_group = group

    return best_group


def get_normalized_play_card_x(
    card_index: int,
    card_count: int,
    roi_width: int,
    card_width: int = 57,
    card_pitch: int = 68,
) -> int:
    """
    依照總張數計算置中的標準卡片位置。

    card_index 從 0 開始。
    """
    if card_count <= 0:
        return 0

    group_width = (
        card_width
        + (
            card_count - 1
        ) * card_pitch
    )

    group_left = int(
        round(
            (
                roi_width
                - group_width
            ) / 2
        )
    )

    return (
        group_left
        + card_index * card_pitch
    )


def collect_play_card_candidates(
    result: Any,
    detector: HandDetector,
    *,
    reject_card_back: bool,
) -> list[
    tuple[
        Any,
        np.ndarray,
        str,
        float,
    ]
]:
    """
    出牌區候選框處理流程：

    1. HandDetector 找候選框
    2. 限制中心接近出牌水平線
    3. 驗證頂部黑色標題列
    4. 驗證卡面類型
    5. 保留候選框自己的 x、y、width、height
    """
    roi = result.roi

    if (
        roi is None
        or roi.size == 0
    ):
        return []

    roi_height, roi_width = (
        roi.shape[:2]
    )

    expected_center_y = (
        roi_height / 2
    )

    candidates: list[
        tuple[
            Any,
            np.ndarray,
            str,
            float,
        ]
    ] = []

    for card in result.cards:
        card_x = int(card.x)
        card_y = int(card.y)
        card_width = int(card.width)
        card_height = int(card.height)
        detector_confidence = float(
            card.confidence
        )

        # 第一層：卡框尺寸。
        if not (
            44 <= card_width <= 66
            and 74 <= card_height <= 96
        ):
            continue

        card_center_y = (
            card_y
            + card_height / 2
        )

        # 第二層：中心必須靠近出牌水平線。
        if abs(
            card_center_y
            - expected_center_y
        ) > 11:
            continue

        crop_left = max(
            0,
            card_x,
        )

        crop_top = max(
            0,
            card_y,
        )

        crop_right = min(
            roi_width,
            card_x + card_width,
        )

        crop_bottom = min(
            roi_height,
            card_y + card_height,
        )

        if (
            crop_right <= crop_left
            or crop_bottom <= crop_top
        ):
            continue

        card_image = roi[
            crop_top:crop_bottom,
            crop_left:crop_right,
        ].copy()

        if card_image.size == 0:
            continue

        # 第三層：黑色卡片標題列。
        if not is_valid_play_card_crop(
            card_image
        ):
            continue

        # 敵方未翻開的牌背不列入正式出牌。
        if (
            reject_card_back
            and is_card_back(
                card_image
            )
        ):
            continue

        # 第四層：卡片類型。
        card_type, type_confidence = (
            classify_final_card_type(
                detector,
                card_image,
            )
        )

        if card_type not in {
            "public_event",
            "personal_event",
        }:
            continue

        

        candidates.append(
            (
                card,
                card_image,
                card_type,
                type_confidence,
            )
        )

    # 先依 X 排序。
    candidates.sort(
        key=lambda item: (
            int(item[0].x),
            -float(item[0].confidence),
        )
    )

    # 排除同一張卡被偵測出兩個相近框的情況。
    deduplicated: list[
        tuple[
            Any,
            np.ndarray,
            str,
            float,
        ]
    ] = []

    for candidate in candidates:
        card = candidate[0]

        if not deduplicated:
            deduplicated.append(
                candidate
            )
            continue

        previous = deduplicated[-1]
        previous_card = previous[0]

        current_center_x = (
            float(card.x)
            + float(card.width) / 2
        )

        previous_center_x = (
            float(previous_card.x)
            + float(previous_card.width) / 2
        )

        if abs(
            current_center_x
            - previous_center_x
        ) <= 10:
            current_score = (
                float(card.confidence)
                + float(candidate[3])
            )

            previous_score = (
                float(previous_card.confidence)
                + float(previous[3])
            )

            if current_score > previous_score:
                deduplicated[-1] = (
                    candidate
                )

            continue

        deduplicated.append(
            candidate
        )

    selected_group = (
        select_play_candidate_group(
            candidates=deduplicated,
            roi_width=roi_width,
            expected_pitch=68.0,
            pitch_tolerance=12.0,
            center_tolerance=18.0,
        )
    )

    return selected_group

def detect_player_play_cards(
    frame: np.ndarray,
) -> tuple[
    list[dict[str, Any]],
    list[dict[str, Any]],
]:
    player_result = (
        player_play_detector.detect(
            frame
        )
    )

    valid_candidates = (
        collect_play_card_candidates(
            result=player_result,
            detector=player_play_detector,
            reject_card_back=False,
        )
    )

    player_cards: list[
        dict[str, Any]
    ] = []

    player_metadata: list[
        dict[str, Any]
    ] = []

    debug_dir = (
        ROOT
        / "debug_frames"
        / "player-play-cards"
    )
    clear_debug_card_dir(
        debug_dir
    )

    card_count = len(
        valid_candidates
    )

    roi_height, roi_width = (
        player_result.roi.shape[:2]
    )

    for slot_index, candidate in enumerate(
        valid_candidates,
        start=1,
    ):
        (
            card,
            _candidate_image,
            _candidate_type,
            _candidate_confidence,
        ) = candidate

        normalized_left = get_normalized_play_card_x(
            card_index=slot_index - 1,
            card_count=card_count,
            roi_width=roi_width,
            card_width=57,
            card_pitch=int(
                player_play_detector.card_pitch
            ),
        )

        # 最終裁切左右各增加 2px。
        crop_padding_x = 2

        crop_left = max(
            0,
            normalized_left - crop_padding_x,
        )

        crop_top = 0

        crop_right = min(
            roi_width,
            normalized_left
            + 57
            + crop_padding_x,
        )

        crop_bottom = min(
            roi_height,
            crop_top + 90,
        )

        card_image = player_result.roi[
            crop_top:crop_bottom,
            crop_left:crop_right,
        ].copy()

        if card_image.size == 0:
            continue

        final_card_type, final_confidence = (
            classify_final_card_type(
                player_play_detector,
                card_image,
            )
        )

        metadata = {
            "card_index": slot_index,
            "card_type": final_card_type,

            "classification_confidence": round(
                final_confidence,
                4,
            ),

            "detector_confidence": round(
                float(card.confidence),
                4,
            ),

            "x": crop_left,
            "y": crop_top,
            "width": crop_right - crop_left,
            "height": crop_bottom - crop_top,

            "detected_x": int(card.x),
            "detected_y": int(card.y),

            "skipped": False,
            "skip_reason": None,
        }

        write_image_unicode(
            debug_dir
            / (
                f"player_play_card_"
                f"{slot_index:02d}_full.png"
            ),
            card_image,
        )

        if final_card_type == "personal_event":
            player_cards.append(
                {
                    "card_index": slot_index,
                    "slot_index": slot_index,
                    "card_type": "personal_event",
                    "display": "事件卡",

                    "upper": {
                        "icon_type": None,
                        "value": None,
                    },

                    "lower": {
                        "icon_type": None,
                        "value": None,
                    },

                    "classification_confidence": round(
                        final_confidence,
                        4,
                    ),
                }
            )

            player_metadata.append(
                metadata
            )

            continue

        result = (
            card_detection_api
            .detect_public_card(
                card_image=card_image,
                card_index=slot_index,
            )
        )

        result["slot_index"] = (
            slot_index
        )

        result["card_type"] = (
            "public_event"
        )

        player_cards.append(
            result
        )

        player_metadata.append(
            metadata
        )

    print(
        "我方出牌區有效候選："
        f"{len(player_cards)} 張"
    )

    return (
        player_cards,
        player_metadata,
    )

def detect_opponent_cards(
    frame: np.ndarray,
) -> tuple[
    list[dict[str, Any]],
    list[dict[str, Any]],
]:
    if opponent_hand_detector is None:
        return [], []

    opponent_result = (
        opponent_hand_detector.detect(
            frame
        )
    )

    valid_candidates = (
        collect_play_card_candidates(
            result=opponent_result,
            detector=opponent_hand_detector,
            reject_card_back=True,
        )
    )

    opponent_cards: list[
        dict[str, Any]
    ] = []

    opponent_metadata: list[
        dict[str, Any]
    ] = []

    debug_dir = (
        ROOT
        / "debug_frames"
        / "opponent-cards"
    )
    clear_debug_card_dir(
        debug_dir
    )
    card_count = len(
        valid_candidates
    )

    roi_height, roi_width = (
        opponent_result.roi.shape[:2]
    )
    for slot_index, candidate in enumerate(
        valid_candidates,
        start=1,
    ):
        (
            card,
            _candidate_image,
            _candidate_type,
            _candidate_confidence,
        ) = candidate

        normalized_left = get_normalized_play_card_x(
            card_index=slot_index - 1,
            card_count=card_count,
            roi_width=roi_width,
            card_width=57,
            card_pitch=int(
                opponent_hand_detector.card_pitch
            ),
        )

        # 最終裁切左右各增加 2px。
        crop_padding_x = 2

        crop_left = max(
            0,
            normalized_left - crop_padding_x,
        )

        crop_top = 0

        crop_right = min(
            roi_width,
            normalized_left
            + 57
            + crop_padding_x,
        )

        crop_bottom = min(
            roi_height,
            crop_top + 90,
        )

        card_image = opponent_result.roi[
            crop_top:crop_bottom,
            crop_left:crop_right,
        ].copy()

        if card_image.size == 0:
            continue

        if is_card_back(
            card_image
        ):
            continue

        final_card_type, final_confidence = (
            classify_final_card_type(
                opponent_hand_detector,
                card_image,
            )
        )

        metadata = {
            "card_index": slot_index,
            "card_type": final_card_type,

            "classification_confidence": round(
                final_confidence,
                4,
            ),

            "detector_confidence": round(
                float(card.confidence),
                4,
            ),

            "x": crop_left,
            "y": crop_top,
            "width": crop_right - crop_left,
            "height": crop_bottom - crop_top,

            "detected_x": int(card.x),
            "detected_y": int(card.y),

            "skipped": False,
            "skip_reason": None,
        }

        write_image_unicode(
            debug_dir
            / (
                f"opponent_card_"
                f"{slot_index:02d}_full.png"
            ),
            card_image,
        )

        if final_card_type == "personal_event":
            opponent_cards.append(
                {
                    "card_index": slot_index,
                    "slot_index": slot_index,
                    "card_type": "personal_event",
                    "display": "事件卡",

                    "upper": {
                        "icon_type": None,
                        "value": None,
                    },

                    "lower": {
                        "icon_type": None,
                        "value": None,
                    },

                    "classification_confidence": round(
                        final_confidence,
                        4,
                    ),
                }
            )

            opponent_metadata.append(
                metadata
            )

            continue

        result = (
            card_detection_api
            .detect_public_card(
                card_image=card_image,
                card_index=slot_index,
            )
        )

        result["slot_index"] = (
            slot_index
        )

        result["card_type"] = (
            "public_event"
        )

        opponent_cards.append(
            result
        )

        opponent_metadata.append(
            metadata
        )

    print(
        "敵方出牌區有效候選："
        f"{len(opponent_cards)} 張"
    )

    return (
        opponent_cards,
        opponent_metadata,
    )

def process_frame(
    frame: np.ndarray,
    skip_indices: set[int],
) -> dict[str, Any]:
    hand_detector = card_detection_api.hand_detector

    if hand_detector is None:
        raise RuntimeError(
            "HandDetector 尚未初始化"
        )

    hand_result = hand_detector.detect(
        frame
    )

    global confirmed_site
    global confirmed_site_confidence
    global confirmed_site_score_gap
    global site_candidate
    global site_candidate_count

    # =========================================================
    # 場地辨識
    # 場地確認後，本次程式執行期間不再重複辨識。
    # =========================================================
    if confirmed_site is not None:
        site_key = confirmed_site
        site_confidence = (
            confirmed_site_confidence
        )
        site_second_confidence = 0.0
        site_score_gap = (
            confirmed_site_score_gap
        )

    elif site_detector is not None:
        site_result = site_detector.detect(
            frame
        )

        detected_site = (
            site_result.site_key
        )

        detected_confidence = (
            site_result.confidence
        )

        detected_second_confidence = (
            site_result.second_confidence
        )

        detected_score_gap = (
            detected_confidence
            - detected_second_confidence
        )
        #print(
        #    "場地判定資料："
        #    f"site={detected_site}，"
        #    f"best={detected_confidence:.4f}，"
        #    f"second={detected_second_confidence:.4f}，"
        #    f"gap={detected_score_gap:.4f}"
        #)
        is_reliable = (

            detected_site is not None

            and detected_confidence >= 0.48

            and detected_score_gap >= 0.05

        )

        if is_reliable:
            if detected_site == site_candidate:
                site_candidate_count += 1
            else:
                site_candidate = detected_site
                site_candidate_count = 1

            if (
                site_candidate_count
                >= SITE_CONFIRM_COUNT
            ):
                confirmed_site = detected_site

                confirmed_site_confidence = (
                    detected_confidence
                )

                confirmed_site_score_gap = (
                    detected_score_gap
                )

                #print(
                #    "\n場地已鎖定："
                #    f"{confirmed_site} "
                #    f"（信心值 "
                #    f"{confirmed_site_confidence:.4f}，"
                #    f"{confirmed_site_score_gap:.4f}）"
                #)

                site_key = confirmed_site
                site_confidence = (
                    confirmed_site_confidence
                )
                site_score_gap = (
                    confirmed_site_score_gap
                )

            else:
                site_key = None
                site_confidence = (
                    detected_confidence
                )
                site_score_gap = (
                    detected_score_gap
                )

        else:
            # 不可靠的一幀不累積確認次數
            site_candidate = None
            site_candidate_count = 0

            site_key = None
            site_confidence = (
                detected_confidence
            )
            site_score_gap = (
                detected_score_gap
            )

        site_second_confidence = (
            detected_second_confidence
        )

    else:
        site_key = None
        site_confidence = 0.0
        site_second_confidence = 0.0
        site_score_gap = 0.0

    (
        player_play_cards,
        player_play_detected_cards,
    ) = detect_player_play_cards(
        frame
    )

    if ok_button_detector is not None:
        ok_result = ok_button_detector.detect(
            frame
        )

        ok_state = ok_result.state
        ok_confidence = (
            ok_result.confidence
        )
        ok_second_confidence = (
            ok_result.second_confidence
        )
        ok_score_gap = (
            ok_result.score_gap
        )
        ok_best_template = (
            ok_result.best_template
        )
    else:
        ok_state = None
        ok_confidence = 0.0
        ok_second_confidence = 0.0
        ok_score_gap = 0.0
        ok_best_template = None

    player_play_locked = (
        ok_state == "locked"
    )
    # =========================================================
    # 對戰結果辨識
    # =========================================================
    if result_detector is not None:
        battle_result = result_detector.detect(
            frame
        )

        battle_result_state = (
            battle_result.state
        )

        battle_result_confidence = (
            battle_result.confidence
        )

        battle_result_second_confidence = (
            battle_result.second_confidence
        )

        battle_result_score_gap = (
            battle_result.score_gap
        )

        battle_result_best_template = (
            battle_result.best_template
        )

    else:
        battle_result_state = None
        battle_result_confidence = 0.0
        battle_result_second_confidence = 0.0
        battle_result_score_gap = 0.0
        battle_result_best_template = None

    (
        opponent_cards,
        opponent_detected_cards,
    ) = detect_opponent_cards(
        frame
    )

    public_count_image = crop_region(
        frame,
        PUBLIC_CARD_COUNT_ROI,
    )

    if public_count_detector is not None:
        public_count_result = (
            public_count_detector.detect(
                public_count_image
            )
        )
    else:
        public_count_result = None

    cards: list[dict[str, Any]] = []
    detected_cards: list[dict[str, Any]] = []

    for card_index, card in enumerate(
        hand_result.cards,
        start=1,
    ):
        # 左側維持向外增加 2px；
        # 右側由原本 +2px 縮回 2px。
        live_card_x = max(
            0,
            card.x - 2,
        )

        live_card_right = min(
            hand_result.roi.shape[1],
            card.x + card.width,
        )

        card_image = hand_result.roi[
            card.y:card.y + card.height,
            live_card_x:live_card_right,
        ].copy()

        metadata = {
            "card_index": card_index,
            "card_type": card.card_type,
            "classification_confidence": round(
                card.confidence,
                4,
            ),
            "x": live_card_x,
            "y": card.y,
            "width": live_card_right - live_card_x,
            "height": card.height,
            "skipped": False,
            "skip_reason": None,
        }

        
        debug_card_dir = (
            ROOT
            / "debug_frames"
            / "live-cards"
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_full.png",
            card_image,
        )

        if card_image.size == 0:
            metadata["skipped"] = True
            metadata["skip_reason"] = "empty_crop"
            detected_cards.append(metadata)
            continue

        if card_index in skip_indices:
            metadata["skipped"] = True
            metadata["skip_reason"] = "manual_skip"
            detected_cards.append(metadata)
            continue
        
        final_card_type, final_confidence = (
            classify_final_card_type(
                hand_detector,
                card_image,
            )
        )

        metadata["card_type"] = (
            final_card_type
        )

        metadata["classification_confidence"] = round(
            final_confidence,
            4,
        )

        if final_card_type == "personal_event":
            event_result = {
                "card_index": card_index,
                "slot_index": card_index,
                "card_type": "personal_event",
                "display": "事件卡",
                "upper": {
                    "icon_type": None,
                    "value": None,
                },
                "lower": {
                    "icon_type": None,
                    "value": None,
                },
                "classification_confidence": round(
                    final_confidence,
                    4,
                ),
            }

            cards.append(
                event_result
            )

            detected_cards.append(
                metadata
            )

            continue
        if final_card_type != "public_event":
            metadata["skipped"] = True
            metadata["skip_reason"] = "unsupported_card_type"


            detected_cards.append(metadata)
            continue
        
        upper_digit = card_detection_api.crop_image(
            card_image,
            card_detection_api.UPPER_DIGIT_ROI,
        )

        upper_icon = card_detection_api.crop_image(
            card_image,
            card_detection_api.UPPER_ICON_ROI,
        )

        lower_icon = card_detection_api.crop_image(
            card_image,
            card_detection_api.LOWER_ICON_ROI,
        )

        

        lower_digit = card_detection_api.crop_image(
            card_image,
            card_detection_api.LOWER_DIGIT_ROI,
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_upper_digit.png",
            upper_digit,
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_upper_icon.png",
            upper_icon,
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_lower_icon.png",
            lower_icon,
        )

        write_image_unicode(
            debug_card_dir
            / f"card_{card_index:02d}_lower_digit.png",
            lower_digit,
        )

        result = card_detection_api.detect_public_card(
            card_image=card_image,
            card_index=card_index,
        )

        # 補上卡片類型，供後續統計使用
        result["card_type"] = "public_event"
        result["slot_index"] = card_index

        cards.append(result)
        detected_cards.append(metadata)
        
    personal_event_count = sum(
        1
        for item in detected_cards
        if item["card_type"] == "personal_event"
    )

    if public_count_result is None:
        public_deck_count = None
        public_deck_confidence = 0.0
        public_deck_tens = None
        public_deck_ones = None
    else:
        public_deck_count = (
            public_count_result.value
        )

        public_deck_confidence = (
            public_count_result.confidence
        )

        public_deck_tens = (
            public_count_result.tens
        )

        public_deck_ones = (
            public_count_result.ones
        )

    return {
        "success": True,
        "running": True,

        "site": site_key,

        "site_confidence": round(
            site_confidence,
            4,
        ),

        "site_second_confidence": round(
            site_second_confidence,
            4,
        ),

        "site_score_gap": round(
            site_score_gap,
            4,
        ),
        "updated_at": datetime.now().isoformat(
            timespec="seconds"
        ),
        "screen": {
            "left": int(capture_region["left"]),
            "top": int(capture_region["top"]),
            "capture_width": int(
                capture_region["width"]
            ),
            "capture_height": int(
                capture_region["height"]
            ),
            "normalized_width": REFERENCE_WIDTH,
            "normalized_height": REFERENCE_HEIGHT,
        },
        "hand_count": len(hand_result.cards),

        # 手牌中的一般公牌數量
        "public_hand_count": sum(
            1
            for card in cards
            if card.get("card_type") == "public_event"
        ),

        "public_card_count": sum(
            1
            for card in cards
            if card.get("card_type") == "public_event"
        ),

        "personal_event_count": (
            personal_event_count
        ),

        # 中央公牌牌堆剩餘數量
        "public_deck_count": (
            public_deck_count
        ),

        "public_deck_count_confidence": round(
            public_deck_confidence,
            4,
        ),

        "public_deck_digits": {
            "tens": public_deck_tens,
            "ones": public_deck_ones,
        },

        "cards": cards,

        "ok_state": ok_state,
        "ok_locked": player_play_locked,
        "ok_confidence": round(
            ok_confidence,
            4,
        ),
        "ok_second_confidence": round(
            ok_second_confidence,
            4,
        ),
        "ok_score_gap": round(
            ok_score_gap,
            4,
        ),
        "ok_best_template": (
            ok_best_template
        ),
        "battle_result": (
            battle_result_state
        ),

        "battle_result_confidence": round(
            battle_result_confidence,
            4,
        ),

        "battle_result_second_confidence": round(
            battle_result_second_confidence,
            4,
        ),

        "battle_result_score_gap": round(
            battle_result_score_gap,
            4,
        ),

        "battle_result_best_template": (
            battle_result_best_template
        ),

        "player_play_card_count": len(
            player_play_cards
        ),
        "player_play_cards": (
            player_play_cards
        ),
        "player_play_detected_cards": (
            player_play_detected_cards
        ),

        # 只有鎖鍊狀態才視為正式出牌
        "confirmed_player_play_cards": (
            player_play_cards
            if player_play_locked
            else []
        ),

        "opponent_card_count": len(
            opponent_cards
        ),

        "opponent_cards": (
            opponent_cards
        ),

        "opponent_detected_cards": (
            opponent_detected_cards
        ),

        "detected_cards": detected_cards,
    }


def monitor_loop(
    interval: float,
    skip_indices: set[int],
    debug: bool,
) -> None:
    previous_signature: tuple | None = None

    with mss.MSS() as screen_capture:
        while not stop_event.is_set():
            started_at = time.perf_counter()

            try:
                frame = grab_screen(
                    screen_capture,
                    capture_region,
                )

                result = process_frame(
                    frame=frame,
                    skip_indices=skip_indices,
                )

                signature = (
                    create_signature(
                        result["cards"]
                    ),
                    create_signature(
                        result["opponent_cards"]
                    ),
                    result["hand_count"],
                    result["public_hand_count"],
                    result["personal_event_count"],
                    result["public_deck_count"],
                    result["opponent_card_count"],
                    create_signature(
                        result["player_play_cards"]
                    ),
                    result["player_play_card_count"],
                    result["ok_state"],
                    result["site"],
                    result["battle_result"],
                    )

                with state_lock:
                    card_detection_api.current_state.clear()
                    card_detection_api.current_state.update(
                        result
                    )

                if signature != previous_signature:
                    timestamp = result["updated_at"]

                    public_deck_count = result.get(
                        "public_deck_count"
                    )

                    public_deck_text = (
                        str(public_deck_count)
                        if public_deck_count is not None
                        else "?"
                    )

                    site_text = (
                        result["site"]
                        if result["site"] is not None
                        else "?"
                    )
                    ok_state = result.get(
                        "ok_state"
                    )

                    ok_text_map = {
                        "unlocked": "OK",
                        "locked": "已鎖定",
                    }

                    ok_state_text = ok_text_map.get(
                        ok_state,
                        "未辨識",
                    )

                    ok_confidence = float(
                        result.get(
                            "ok_confidence",
                            0.0,
                        )
                    )
                    battle_result_text_map = {
                        "win": "勝利",
                        "lose": "敗北",
                        "timeout": "時間到",
                        "tie": "平手",
                    }

                    battle_result_state = result.get(
                        "battle_result"
                    )

                    battle_result_text = (
                        battle_result_text_map.get(
                            battle_result_state,
                            "進行中",
                        )
                    )

                    battle_result_confidence = float(
                        result.get(
                            "battle_result_confidence",
                            0.0,
                        )
                    )

                    print(
                        f"\n[{timestamp}] "
                        f"場地：{site_text} "
                        f"({result['site_confidence']:.3f})，"
                        f"手牌 {result['hand_count']} 張"
                        f"（公牌 {result['public_hand_count']}／"
                        f"事件卡 {result['personal_event_count']}），"
                        f"公牌剩餘 {public_deck_text} 張，"
                        f"對方出牌 "
                        f"{result['opponent_card_count']} 張，"
                        f"按鈕：{ok_state_text} "
                        f"({ok_confidence:.3f})，"
                        f"結果：{battle_result_text} "
                        f"({battle_result_confidence:.3f})"
                    )
                    player_play_cards = result.get(
                        "player_play_cards",
                        [],
                    )

                    confirmed_player_play_cards = result.get(
                        "confirmed_player_play_cards",
                        [],
                    )

                    if result["ok_locked"]:
                        confirmed_public_count = sum(
                            1
                            for card in confirmed_player_play_cards
                            if card.get("card_type") == "public_event"
                        )

                        confirmed_event_count = sum(
                            1
                            for card in confirmed_player_play_cards
                            if card.get("card_type") == "personal_event"
                        )

                        print(
                            f"\n我方正式出牌 "
                            f"{len(confirmed_player_play_cards)} 張"
                            f"（公牌 {confirmed_public_count}／"
                            f"事件卡 {confirmed_event_count}，"
                            f"已鎖定）："
                        )

                        for card in confirmed_player_play_cards:
                            print(
                                f"player_play_"
                                f"{card['slot_index']:02d}："
                                f"{card['display']}"
                            )

                    elif player_play_cards:
                        player_public_count = sum(
                            1
                            for card in player_play_cards
                            if card.get("card_type") == "public_event"
                        )

                        player_event_count = sum(
                            1
                            for card in player_play_cards
                            if card.get("card_type") == "personal_event"
                        )

                        print(
                            f"\n我方出牌區暫存 "
                            f"{len(player_play_cards)} 張"
                            f"（公牌 {player_public_count}／"
                            f"事件卡 {player_event_count}，"
                            f"尚未鎖定，可收回）："
                        )

                        for card in player_play_cards:
                            print(
                                f"player_play_"
                                f"{card['slot_index']:02d}："
                                f"{card['display']}"
                            )

                    opponent_cards = result.get(
                        "opponent_cards",
                        [],
                    )

                    if opponent_cards:
                        opponent_public_count = sum(
                            1
                            for card in opponent_cards
                            if card.get("card_type") == "public_event"
                        )

                        opponent_event_count = sum(
                            1
                            for card in opponent_cards
                            if card.get("card_type") == "personal_event"
                        )

                        print(
                            f"\n對方出牌 "
                            f"{len(opponent_cards)} 張"
                            f"（公牌 {opponent_public_count}／"
                            f"事件卡 {opponent_event_count}）："
                        )

                        for card in opponent_cards:
                            print(
                                f"opponent_"
                                f"{card['slot_index']:02d}："
                                f"{card['display']}"
                            )

                    for card in result["cards"]:
                        print(
                            f"card_{card['card_index']:02d}："
                            f"{card['display']}"
                        )

                    previous_signature = signature

                if debug:
                    debug_dir = (
                        ROOT
                        / "debug_frames"
                    )
                    battle_result_image = crop_region(
                        frame,
                        RESULT_ROI,
                    )

                    battle_result_path = (
                        debug_dir
                        / "battle-result-area.png"
                    )

                    write_image_unicode(
                        battle_result_path,
                        battle_result_image,
                    )
                    # 場地辨識實際使用的 ROI
                    site_background_image = crop_region(
                        frame,
                        SITE_BACKGROUND_ROI,
                    )

                    site_background_path = (
                        debug_dir
                        / "site-background-area.png"
                    )

                    site_saved = write_image_unicode(
                        site_background_path,
                        site_background_image,
                    )

                    if not site_saved:
                        print(
                            "場地 ROI 圖片儲存失敗："
                            f"{site_background_path}"
                        )

                    # 完整標準化遊戲畫面
                    live_screen_path = (
                        debug_dir
                        / "live-screen.jpg"
                    )
                    # OK／鎖鍊按鈕區域
                    ok_button_image = crop_region(
                        frame,
                        OK_BUTTON_ROI,
                    )

                    ok_button_path = (
                        debug_dir
                        / "ok-button-area.png"
                    )

                    saved = write_image_unicode(
                        ok_button_path,
                        ok_button_image,
                    )

                    if not saved:
                        print(
                            "OK 按鈕區域儲存失敗："
                            f"{ok_button_path}"
                        )

                    saved = write_image_unicode(
                        live_screen_path,
                        frame,
                    )

                    if not saved:
                        print(
                            f"除錯圖片儲存失敗："
                            f"{live_screen_path}"
                        )

                    # 我方出牌區
                    player_play_image = crop_region(
                        frame,
                        PLAYER_PLAY_ROI,
                    )

                    player_play_path = (
                        debug_dir
                        / "player-play-area.png"
                    )

                    saved = write_image_unicode(
                        player_play_path,
                        player_play_image,
                    )

                    if not saved:
                        print(
                            "我方出牌區儲存失敗："
                            f"{player_play_path}"
                        )

                    # 對方出牌區
                    opponent_play_image = crop_region(
                        frame,
                        OPPONENT_PLAY_ROI,
                    )

                    opponent_play_path = (
                        debug_dir
                        / "opponent-play-area.png"
                    )

                    saved = write_image_unicode(
                        opponent_play_path,
                        opponent_play_image,
                    )

                    if not saved:
                        print(
                            "對方出牌區儲存失敗："
                            f"{opponent_play_path}"
                        )

                    # 公牌剩餘數量區域
                    public_card_count_image = crop_region(
                        frame,
                        PUBLIC_CARD_COUNT_ROI,
                    )

                    public_card_count_path = (
                        debug_dir
                        / "public-card-count-area.png"
                    )
                    
                    public_count_tens = (
                        public_card_count_image[
                            0:35,
                            0:17,
                        ].copy()
                    )

                    public_count_ones = (
                        public_card_count_image[
                            0:35,
                            17:35,
                        ].copy()
                    )

                    write_image_unicode(
                        debug_dir
                        / "public-card-count-tens.png",
                        public_count_tens,
                    )

                    write_image_unicode(
                        debug_dir
                        / "public-card-count-ones.png",
                        public_count_ones,
                    )

                    saved = write_image_unicode(
                        public_card_count_path,
                        public_card_count_image,
                    )

                    if not saved:
                        print(
                            "公牌數量區域儲存失敗："
                            f"{public_card_count_path}"
                        )

                    saved = write_image_unicode(
                        opponent_play_path,
                        opponent_play_image,
                    )

                    if not saved:
                        print(
                            f"對方出牌區儲存失敗："
                            f"{opponent_play_path}"
                        )

                    # 在完整畫面畫出 ROI 框線
                    overlay = frame.copy()
                    ok_x = int(
                        OK_BUTTON_ROI["x"]
                    )
                    ok_y = int(
                        OK_BUTTON_ROI["y"]
                    )
                    ok_width = int(
                        OK_BUTTON_ROI["width"]
                    )
                    ok_height = int(
                        OK_BUTTON_ROI["height"]
                    )

                    cv2.rectangle(
                        overlay,
                        (ok_x, ok_y),
                        (
                            ok_x + ok_width,
                            ok_y + ok_height,
                        ),
                        (0, 0, 255),
                        2,
                    )

                    cv2.putText(
                        overlay,
                        "OK / locked",
                        (
                            ok_x,
                            max(15, ok_y - 6),
                        ),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.45,
                        (0, 0, 255),
                        1,
                        cv2.LINE_AA,
                    )
                    
                    count_x = int(
                        PUBLIC_CARD_COUNT_ROI["x"]
                    )

                    count_y = int(
                        PUBLIC_CARD_COUNT_ROI["y"]
                    )

                    count_width = int(
                        PUBLIC_CARD_COUNT_ROI["width"]
                    )

                    count_height = int(
                        PUBLIC_CARD_COUNT_ROI["height"]
                    )

                    cv2.rectangle(
                        overlay,
                        (
                            count_x,
                            count_y,
                        ),
                        (
                            count_x + count_width,
                            count_y + count_height,
                        ),
                        (255, 0, 255),
                        2,
                    )

                    cv2.putText(
                        overlay,
                        "public card count",
                        (
                            count_x,
                            max(
                                15,
                                count_y - 6,
                            ),
                        ),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.45,
                        (255, 0, 255),
                        1,
                        cv2.LINE_AA,
                    )

                    player_x = int(
                        PLAYER_PLAY_ROI["x"]
                    )
                    player_y = int(
                        PLAYER_PLAY_ROI["y"]
                    )
                    player_width = int(
                        PLAYER_PLAY_ROI["width"]
                    )
                    player_height = int(
                        PLAYER_PLAY_ROI["height"]
                    )

                    cv2.rectangle(
                        overlay,
                        (
                            player_x,
                            player_y,
                        ),
                        (
                            player_x + player_width,
                            player_y + player_height,
                        ),
                        (255, 255, 0),
                        2,
                    )

                    cv2.putText(
                        overlay,
                        "player play area",
                        (
                            player_x,
                            max(
                                15,
                                player_y - 6,
                            ),
                        ),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.45,
                        (255, 255, 0),
                        1,
                        cv2.LINE_AA,
                    )

                    opponent_x = int(
                        OPPONENT_PLAY_ROI["x"]
                    )

                    opponent_y = int(
                        OPPONENT_PLAY_ROI["y"]
                    )

                    opponent_width = int(
                        OPPONENT_PLAY_ROI["width"]
                    )

                    opponent_height = int(
                        OPPONENT_PLAY_ROI["height"]
                    )

                    cv2.rectangle(
                        overlay,
                        (
                            opponent_x,
                            opponent_y,
                        ),
                        (
                            opponent_x + opponent_width,
                            opponent_y + opponent_height,
                        ),
                        (0, 255, 255),
                        2,
                    )

                    cv2.putText(
                        overlay,
                        "opponent play area",
                        (
                            opponent_x,
                            max(
                                15,
                                opponent_y - 6,
                            ),
                        ),
                        cv2.FONT_HERSHEY_SIMPLEX,
                        0.45,
                        (0, 255, 255),
                        1,
                        cv2.LINE_AA,
                    )

                    overlay_path = (
                        debug_dir
                        / "live-screen-roi-debug.png"
                    )

                    write_image_unicode(
                        overlay_path,
                        overlay,
                    )

                    

            except Exception as error:
                with state_lock:
                    card_detection_api.current_state.clear()
                    card_detection_api.current_state.update(
                        {
                            "success": False,
                            "running": True,
                            "updated_at": datetime.now().isoformat(
                                timespec="seconds"
                            ),
                            "error": str(error),
                            "hand_count": 0,
                            "public_card_count": 0,
                            "cards": [],
                            "detected_cards": [],
                        }
                    )

                print(
                    f"\n辨識錯誤：{error}"
                )

            elapsed = (
                time.perf_counter()
                - started_at
            )

            wait_seconds = max(
                0.05,
                interval - elapsed,
            )

            stop_event.wait(
                wait_seconds
            )


def parse_skip(
    raw_value: str,
) -> set[int]:
    result: set[int] = set()

    for item in raw_value.split(","):
        item = item.strip()

        if item.isdigit():
            result.add(int(item))

    return result


capture_region: dict[str, int] = {}

def main() -> None:
    global capture_region
    global public_count_detector
    global opponent_hand_detector
    global site_detector
    global ok_button_detector
    global result_detector

    parser = argparse.ArgumentParser(
        description="UL.GG 即時螢幕卡片辨識測試"
    )

    parser.add_argument(
        "--left",
        type=int,
        required=True,
        help="擷取區域左上角 X",
    )

    parser.add_argument(
        "--top",
        type=int,
        required=True,
        help="擷取區域左上角 Y",
    )

    parser.add_argument(
        "--width",
        type=int,
        required=True,
        help="擷取寬度",
    )

    parser.add_argument(
        "--height",
        type=int,
        required=True,
        help="擷取高度",
    )

    parser.add_argument(
        "--interval",
        type=float,
        default=0.8,
        help="辨識間隔秒數",
    )

    parser.add_argument(
        "--skip",
        type=str,
        default="",
        help="手動略過卡號，例如 4 或 4,7",
    )

    parser.add_argument(
        "--debug",
        action="store_true",
        help="持續保存目前擷取畫面",
    )

    args = parser.parse_args()

    capture_region = {
        "left": args.left,
        "top": args.top,
        "width": args.width,
        "height": args.height,
    }

    skip_indices = parse_skip(
        args.skip
    )
    load_card_back_templates()
    public_count_detector = (
        PublicCardCountDetector(
            template_dir=(
                ROOT
                / "templates"
                / "public-card-count-digits"
            ),
            single_template_dir=(
                ROOT
                / "templates"
                / "public-card-count-single"
            ),
            threshold=0.60,
            split_x=17,
        )
    )
    site_detector = SiteDetector(
        template_dir=SITE_TEMPLATE_DIR,
        region=SITE_BACKGROUND_ROI,
        threshold=0.55,
    )
    site_roi_x = int(
    SITE_BACKGROUND_ROI["x"]
    )

    site_roi_y = int(
        SITE_BACKGROUND_ROI["y"]
    )

    site_roi_width = int(
        SITE_BACKGROUND_ROI["width"]
    )

    site_roi_height = int(
        SITE_BACKGROUND_ROI["height"]
    )

    print()
    print("場地辨識 ROI：")
    print(
        f"  x={site_roi_x}, "
        f"y={site_roi_y}, "
        f"width={site_roi_width}, "
        f"height={site_roi_height}"
    )
    print(
        "  右下角："
        f"x={site_roi_x + site_roi_width}, "
        f"y={site_roi_y + site_roi_height}"
    )
    print(
        "  標準畫面："
        f"{REFERENCE_WIDTH} × "
        f"{REFERENCE_HEIGHT}"
    )

    ok_button_detector = OkButtonDetector(
        template_dir=(
            ROOT
            / "templates"
            / "ok-button"
        ),
        region=OK_BUTTON_ROI,
        threshold=0.62,
        min_score_gap=0.08,
    )
    result_detector = ResultDetector(
        template_dir=(
            ROOT
            / "templates"
            / "battle-result"
        ),
        region=RESULT_ROI,
        threshold=0.62,
        min_score_gap=0.06,
    )

    # 直接執行 FastAPI lifespan，載入所有模板與辨識器。
    import asyncio

    async def run() -> None:
        async with card_detection_api.lifespan(
            card_detection_api.app
        ):
            uvicorn_config = uvicorn.Config(
                app=card_detection_api.app,
                host="127.0.0.1",
                port=8000,

                # lifespan 已由目前程式手動執行，
                # 避免 uvicorn 再執行一次。
                lifespan="off",

                log_level="warning",
            )

            uvicorn_server = uvicorn.Server(
                uvicorn_config
            )

            api_thread = Thread(
                target=uvicorn_server.run,
                daemon=True,
            )

            api_thread.start()

            thread = Thread(
                target=monitor_loop,
                kwargs={
                    "interval": args.interval,
                    "skip_indices": skip_indices,
                    "debug": args.debug,
                },
                daemon=True,
            )

            thread.start()

            print("即時螢幕辨識已啟動")
            print(
                "模板管理器："
                "http://127.0.0.1:8000/template-manager"
            )
            print(
                "擷取範圍："
                f"{capture_region}"
            )
            print("按 Ctrl+C 停止")

            try:
                while not stop_event.is_set():
                    await asyncio.sleep(1)

            except asyncio.CancelledError:
                stop_event.set()

            finally:
                stop_event.set()

                uvicorn_server.should_exit = True

                thread.join(timeout=3)
                api_thread.join(timeout=3)

    asyncio.run(run())


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        stop_event.set()
        print("\n即時螢幕辨識已停止")