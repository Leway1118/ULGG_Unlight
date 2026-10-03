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


ROOT = Path(__file__).resolve().parent

REFERENCE_WIDTH = 848
REFERENCE_HEIGHT = 760

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


def process_frame(
    frame: np.ndarray,
    skip_indices: set[int],
) -> dict[str, Any]:
    hand_detector = card_detection_api.hand_detector

    if hand_detector is None:
        raise RuntimeError(
            "HandDetector 尚未初始化"
        )

    hand_result = hand_detector.detect(frame)

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

    return {
        "success": True,
        "running": True,
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
        "public_card_count": len(cards),
        "cards": cards,
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

                signature = create_signature(
                    result["cards"]
                )

                with state_lock:
                    card_detection_api.current_state.clear()
                    card_detection_api.current_state.update(
                        result
                    )

                if signature != previous_signature:
                    timestamp = result["updated_at"]

                    print(
                        f"\n[{timestamp}] "
                        f"手牌 {result['hand_count']} 張，"
                        f"公牌 {result['public_card_count']} 張"
                    )

                    for card in result["cards"]:
                        print(
                            f"card_{card['card_index']:02d}："
                            f"{card['display']}"
                        )

                    previous_signature = signature

                if debug:
                    debug_path = (
                        ROOT
                        / "debug_frames"
                        / "live-screen.jpg"
                    )

                    debug_path.parent.mkdir(
                        parents=True,
                        exist_ok=True,
                    )

                    saved = write_image_unicode(
                        debug_path,
                        frame,
                    )

                    if not saved:
                        print(
                            f"除錯圖片儲存失敗：{debug_path}"
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