from __future__ import annotations

import argparse
import json
import time
from datetime import datetime
from pathlib import Path
from threading import Event, Lock, Thread
from typing import Any

import cv2
import mss
import numpy as np

import card_detection_api

from public_card_count_detector import (
    PublicCardCountDetector,
)
from hand_detector import HandDetector
from site_detector import SiteDetector
from ok_button_detector import OkButtonDetector



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
    "y": 190,
    "width": 848,
    "height": 330,
}

PLAYER_PLAY_ROI = {
    "x": 100,
    "y": 374,
    "width": 665,
    "height": 96,
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

player_play_detector = HandDetector(
    region=PLAYER_PLAY_ROI,
    personal_template_dir=(
        ROOT
        / "templates"
        / "personal-events"
    ),
    expected_card_width=57,
    expected_card_height=90,
    card_pitch=68,

    # 我方出牌區背景雜訊較多，條件提高
    min_card_width=48,
    max_card_width=62,
    min_card_height=82,
)


# =========================================================
# 對方出牌區 DEBUG ROI
# 基準畫面：848 × 760
# 以畫面中央向左右擴張，支援約 10 張出牌
# 高度與自身手牌一致：95px
# 格式：(x, y, width, height)
# =========================================================
OPPONENT_PLAY_ROI = {
    "x": 110,
    "y": 100,
    "width": 640,
    "height": 93,
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

def create_signature(
    cards: list[dict[str, Any]],
) -> tuple:
    signature = []

    for card in cards:
        upper = card["upper"]
        lower = card["lower"]

        signature.append(
            (
                upper["icon_type"],
                upper["value"],
                lower["icon_type"],
                lower["value"],
            )
        )

    return tuple(signature)


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
    valid_card_index = 0

    for card in player_result.cards:
        if (
            card.width < 48
            or card.width > 62
            or card.height < 82
            or card.confidence < 0.70
        ):
            continue

        valid_card_index += 1
        card_index = valid_card_index

        card_image = player_result.roi[
            card.y:card.y + card.height,
            card.x:card.x + card.width,
        ].copy()

        metadata = {
            "card_index": card_index,
            "card_type": card.card_type,
            "classification_confidence": round(
                card.confidence,
                4,
            ),
            "x": card.x,
            "y": card.y,
            "width": card.width,
            "height": card.height,
            "skipped": False,
            "skip_reason": None,
        }

        write_image_unicode(
            debug_dir
            / (
                f"player_play_card_"
                f"{card_index:02d}_full.png"
            ),
            card_image,
        )

        if card_image.size == 0:
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "empty_crop"
            )
            player_metadata.append(
                metadata
            )
            continue

        if card.card_type == "personal_event":
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "personal_event"
            )
            player_metadata.append(
                metadata
            )
            continue

        if card.card_type != "public_event":
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "unsupported_card_type"
            )
            player_metadata.append(
                metadata
            )
            continue

        upper_digit = (
            card_detection_api.crop_image(
                card_image,
                card_detection_api.UPPER_DIGIT_ROI,
            )
        )

        upper_icon = (
            card_detection_api.crop_image(
                card_image,
                card_detection_api.UPPER_ICON_ROI,
            )
        )

        lower_icon = (
            card_detection_api.crop_image(
                card_image,
                card_detection_api.LOWER_ICON_ROI,
            )
        )

        lower_digit = (
            card_detection_api.crop_image(
                card_image,
                card_detection_api.LOWER_DIGIT_ROI,
            )
        )

        write_image_unicode(
            debug_dir
            / (
                f"player_play_card_"
                f"{card_index:02d}_upper_digit.png"
            ),
            upper_digit,
        )

        write_image_unicode(
            debug_dir
            / (
                f"player_play_card_"
                f"{card_index:02d}_upper_icon.png"
            ),
            upper_icon,
        )

        write_image_unicode(
            debug_dir
            / (
                f"player_play_card_"
                f"{card_index:02d}_lower_icon.png"
            ),
            lower_icon,
        )

        write_image_unicode(
            debug_dir
            / (
                f"player_play_card_"
                f"{card_index:02d}_lower_digit.png"
            ),
            lower_digit,
        )

        result = (
            card_detection_api
            .detect_public_card(
                card_image=card_image,
                card_index=card_index,
            )
        )

        result["slot_index"] = (
            card_index
        )

        player_cards.append(
            result
        )

        player_metadata.append(
            metadata
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

    for card_index, card in enumerate(
        opponent_result.cards,
        start=1,
    ):
        card_image = opponent_result.roi[
            card.y:card.y + card.height,
            card.x:card.x + card.width,
        ].copy()

        metadata = {
            "card_index": card_index,
            "card_type": card.card_type,
            "classification_confidence": round(
                card.confidence,
                4,
            ),
            "x": card.x,
            "y": card.y,
            "width": card.width,
            "height": card.height,
            "skipped": False,
            "skip_reason": None,
        }

        write_image_unicode(
            debug_dir
            / (
                f"opponent_card_"
                f"{card_index:02d}_full.png"
            ),
            card_image,
        )

        if card_image.size == 0:
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "empty_crop"
            )

            opponent_metadata.append(
                metadata
            )

            continue

        if card.card_type == "personal_event":
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "personal_event"
            )

            opponent_metadata.append(
                metadata
            )

            continue

        if card.card_type != "public_event":
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "unsupported_card_type"
            )

            opponent_metadata.append(
                metadata
            )

            continue

        upper_digit = (
            card_detection_api.crop_image(
                card_image,
                card_detection_api.UPPER_DIGIT_ROI,
            )
        )

        upper_icon = (
            card_detection_api.crop_image(
                card_image,
                card_detection_api.UPPER_ICON_ROI,
            )
        )

        lower_icon = (
            card_detection_api.crop_image(
                card_image,
                card_detection_api.LOWER_ICON_ROI,
            )
        )

        lower_digit = (
            card_detection_api.crop_image(
                card_image,
                card_detection_api.LOWER_DIGIT_ROI,
            )
        )

        write_image_unicode(
            debug_dir
            / (
                f"opponent_card_"
                f"{card_index:02d}_upper_digit.png"
            ),
            upper_digit,
        )

        write_image_unicode(
            debug_dir
            / (
                f"opponent_card_"
                f"{card_index:02d}_upper_icon.png"
            ),
            upper_icon,
        )

        write_image_unicode(
            debug_dir
            / (
                f"opponent_card_"
                f"{card_index:02d}_lower_icon.png"
            ),
            lower_icon,
        )

        write_image_unicode(
            debug_dir
            / (
                f"opponent_card_"
                f"{card_index:02d}_lower_digit.png"
            ),
            lower_digit,
        )

        result = (
            card_detection_api
            .detect_public_card(
                card_image=card_image,
                card_index=card_index,
            )
        )

        result["slot_index"] = (
            card_index
        )

        opponent_cards.append(
            result
        )

        opponent_metadata.append(
            metadata
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

        is_reliable = (
            detected_site is not None
            and detected_confidence >= 0.55
            and detected_score_gap >= 0.15
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

                print(
                    "\n場地已鎖定："
                    f"{confirmed_site} "
                    f"（信心值 "
                    f"{confirmed_site_confidence:.4f}，"
                    f"差距 "
                    f"{confirmed_site_score_gap:.4f}）"
                )

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
        metadata = {
            "card_index": card_index,
            "card_type": card.card_type,
            "classification_confidence": round(
                card.confidence,
                4,
            ),
            "x": card.x,
            "y": card.y,
            "width": card.width,
            "height": card.height,
            "skipped": False,
            "skip_reason": None,
        }

        card_image = hand_result.roi[
            card.y:card.y + card.height,
            card.x:card.x + card.width,
        ].copy()
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

        if card.card_type == "personal_event":
            metadata["skipped"] = True
            metadata["skip_reason"] = "personal_event"


            detected_cards.append(metadata)
            continue

        if card.card_type != "public_event":
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
        "public_hand_count": len(cards),

        # 暫時保留舊欄位，避免其他程式失效
        "public_card_count": len(cards),

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

                    print(
                        f"\n[{timestamp}] "
                        f"場地：{site_text} "
                        f"({result['site_confidence']:.3f})，"
                        f"手牌 {result['hand_count']} 張"
                        f"（公牌 {result['public_hand_count']}／"
                        f"事件卡 {result['personal_event_count']}），"
                        f"公牌剩餘 {public_deck_text} 張，"
                        f"對方出牌 "
                        f"{result['opponent_card_count']} 張"
                    )
                    player_play_cards = result.get(
                        "player_play_cards",
                        [],
                    )

                    if player_play_cards:
                        lock_text = (
                            "已鎖定"
                            if result["ok_locked"]
                            else "尚未鎖定"
                        )

                        print(
                            f"\n我方出牌 "
                            f"{len(player_play_cards)} 張"
                            f"（{lock_text}）："
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
                        print("\n對方出牌：")

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

    # 直接執行 FastAPI lifespan，載入所有模板與辨識器。
    import asyncio

    async def run() -> None:
        async with card_detection_api.lifespan(
            card_detection_api.app
        ):
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
                thread.join(timeout=3)

    asyncio.run(run())


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        stop_event.set()
        print("\n即時螢幕辨識已停止")