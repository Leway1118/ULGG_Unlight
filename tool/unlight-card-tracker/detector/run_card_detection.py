from __future__ import annotations

import argparse
import json
from dataclasses import asdict
from pathlib import Path
from typing import Any

import cv2
import numpy as np

from card_digit_detector import CardDigitDetector
from card_face_reader import CardFaceReader
from card_icon_detector import CardIconDetector
from hand_detector import HandDetector


ROOT = Path(__file__).resolve().parent


# =========================================================
# 公牌內部裁切範圍
# 完整卡片目前約為 62 × 95
# 格式：(x1, y1, x2, y2)
# =========================================================
UPPER_DIGIT_ROI = (0, 5, 27, 42)
UPPER_ICON_ROI = (25, 10, 60, 48)

LOWER_ICON_ROI = (0, 47, 37, 88)
LOWER_DIGIT_ROI = (32, 47, 60, 90)


ICON_LABELS = {
    "sword": "劍",
    "gun": "槍",
    "shield": "盾",
    "special": "特",
    "move": "移",
    None: "?",
}


def load_json(path: Path) -> dict[str, Any]:
    if not path.exists():
        raise RuntimeError(
            f"找不到設定檔：{path}"
        )

    with path.open(
        "r",
        encoding="utf-8"
    ) as file:
        return json.load(file)


def read_video_frame(
    video_path: Path,
    time_seconds: float
) -> np.ndarray:
    if not video_path.exists():
        raise RuntimeError(
            f"找不到影片：{video_path}"
        )

    capture = cv2.VideoCapture(
        str(video_path)
    )

    if not capture.isOpened():
        raise RuntimeError(
            f"無法開啟影片：{video_path}"
        )

    capture.set(
        cv2.CAP_PROP_POS_MSEC,
        time_seconds * 1000.0
    )

    success, frame = capture.read()

    capture.release()

    if not success or frame is None:
        raise RuntimeError(
            f"無法讀取影片 {time_seconds:.1f} 秒畫面"
        )

    return frame


def crop_image(
    image: np.ndarray,
    roi: tuple[int, int, int, int]
) -> np.ndarray:
    x1, y1, x2, y2 = roi

    height, width = image.shape[:2]

    x1 = max(
        0,
        min(x1, width)
    )

    x2 = max(
        0,
        min(x2, width)
    )

    y1 = max(
        0,
        min(y1, height)
    )

    y2 = max(
        0,
        min(y2, height)
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


def save_image(
    path: Path,
    image: np.ndarray
) -> bool:
    if image is None or image.size == 0:
        return False

    path.parent.mkdir(
        parents=True,
        exist_ok=True
    )

    return bool(
        cv2.imwrite(
            str(path),
            image
        )
    )


def format_side(
    side: dict[str, Any]
) -> str:
    icon_type = side.get(
        "icon_type"
    )

    value = side.get(
        "value"
    )

    icon_text = ICON_LABELS.get(
        icon_type,
        "?"
    )

    value_text = (
        str(value)
        if value is not None
        else "?"
    )

    return (
        f"{icon_text}{value_text}"
    )


def create_roi_debug(
    card_image: np.ndarray
) -> np.ndarray:
    debug = card_image.copy()

    roi_items = [
        (
            "upper_digit",
            UPPER_DIGIT_ROI
        ),
        (
            "upper_icon",
            UPPER_ICON_ROI
        ),
        (
            "lower_icon",
            LOWER_ICON_ROI
        ),
        (
            "lower_digit",
            LOWER_DIGIT_ROI
        ),
    ]

    colors = [
        (0, 255, 255),
        (0, 255, 0),
        (255, 255, 0),
        (255, 0, 255),
    ]

    for (
        label,
        (x1, y1, x2, y2)
    ), color in zip(
        roi_items,
        colors
    ):
        cv2.rectangle(
            debug,
            (x1, y1),
            (
                x2 - 1,
                y2 - 1
            ),
            color,
            1
        )

        cv2.putText(
            debug,
            label,
            (
                x1,
                max(
                    9,
                    y1 + 9
                )
            ),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.22,
            color,
            1,
            cv2.LINE_AA
        )

    return cv2.resize(
        debug,
        None,
        fx=4,
        fy=4,
        interpolation=cv2.INTER_NEAREST
    )


def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "一次完成 Unlight 手牌、公牌圖示與數字辨識"
        )
    )

    parser.add_argument(
        "video",
        type=Path,
        help="影片檔案路徑"
    )

    parser.add_argument(
        "--time",
        type=float,
        required=True,
        help="要分析的影片秒數"
    )

    parser.add_argument(
        "--config",
        type=Path,
        default=(
            ROOT
            / "config.json"
        ),
        help="設定檔路徑"
    )

    parser.add_argument(
        "--skip",
        type=int,
        nargs="*",
        default=[],
        help=(
            "手動略過的卡片編號，"
            "例如 --skip 4"
        )
    )

    parser.add_argument(
        "--icon-threshold",
        type=float,
        default=0.55,
        help="圖示辨識最低信心"
    )

    parser.add_argument(
        "--digit-threshold",
        type=float,
        default=0.65,
        help="數字辨識最低信心"
    )

    parser.add_argument(
        "--json",
        action="store_true",
        help="在終端機顯示完整 JSON"
    )

    parser.add_argument(
        "--no-debug",
        action="store_true",
        help="不輸出除錯圖片"
    )

    args = parser.parse_args()

    config_path = args.config

    if not config_path.is_absolute():
        config_path = (
            ROOT
            / config_path
        ).resolve()

    video_path = args.video

    if not video_path.is_absolute():
        video_path = (
            Path.cwd()
            / video_path
        ).resolve()

    config = load_json(
        config_path
    )

    regions = config.get(
        "regions",
        {}
    )

    bottom_hand_region = regions.get(
        "bottom_hand"
    )

    if not isinstance(
        bottom_hand_region,
        dict
    ):
        raise RuntimeError(
            "config.json 找不到 regions.bottom_hand"
        )

    frame = read_video_frame(
        video_path=video_path,
        time_seconds=args.time
    )

    personal_template_dir = (
        ROOT
        / "templates"
        / "personal-events"
    )

    hand_detector = HandDetector(
        region=bottom_hand_region,
        personal_template_dir=(
            personal_template_dir
        )
    )

    hand_result = hand_detector.detect(
        frame
    )

    time_folder = (
        f"{args.time:.1f}"
    )

    hand_output_dir = (
        ROOT
        / "debug_frames"
        / "hand"
    )

    faces_output_dir = (
        ROOT
        / "debug_frames"
        / "card-faces"
        / time_folder
    )

    parts_output_dir = (
        ROOT
        / "debug_frames"
        / "card-parts"
        / time_folder
    )

    result_output_dir = (
        ROOT
        / "debug_frames"
        / "results"
    )

    if not args.no_debug:
        save_image(
            hand_output_dir
            / (
                f"hand_{time_folder}"
                "_debug.jpg"
            ),
            hand_result.debug
        )

        save_image(
            hand_output_dir
            / (
                f"hand_{time_folder}"
                "_roi.jpg"
            ),
            hand_result.roi
        )

    skip_indices = set(
        args.skip
    )

    detected_metadata: list[
        dict[str, Any]
    ] = []

    public_indices: list[int] = []

    for card_index, card in enumerate(
        hand_result.cards,
        start=1
    ):
        card_image = hand_result.roi[
            card.y:card.y + card.height,
            card.x:card.x + card.width
        ].copy()

        metadata = {
            "card_index": card_index,
            "card_type": card.card_type,
            "confidence": card.confidence,
            "x": card.x,
            "y": card.y,
            "width": card.width,
            "height": card.height,
            "skipped": False,
            "skip_reason": None,
        }

        if (
            card_image is None
            or card_image.size == 0
        ):
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "empty_crop"
            )

            detected_metadata.append(
                metadata
            )

            continue

        if not args.no_debug:
            save_image(
                hand_output_dir
                / (
                    f"hand_{time_folder}"
                    f"_card_{card_index:02d}.png"
                ),
                card_image
            )

        if card_index in skip_indices:
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "manual_skip"
            )

            detected_metadata.append(
                metadata
            )

            continue

        if card.card_type == (
            "personal_event"
        ):
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "personal_event"
            )

            detected_metadata.append(
                metadata
            )

            continue

        if card.card_type != (
            "public_event"
        ):
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                f"unsupported_type:"
                f"{card.card_type}"
            )

            detected_metadata.append(
                metadata
            )

            continue

        full_path = (
            faces_output_dir
            / f"card_{card_index:02d}_full.png"
        )

        save_image(
            full_path,
            card_image
        )

        parts = {
            "upper_digit": crop_image(
                card_image,
                UPPER_DIGIT_ROI
            ),
            "upper_icon": crop_image(
                card_image,
                UPPER_ICON_ROI
            ),
            "lower_icon": crop_image(
                card_image,
                LOWER_ICON_ROI
            ),
            "lower_digit": crop_image(
                card_image,
                LOWER_DIGIT_ROI
            ),
        }

        for part_name, part_image in (
            parts.items()
        ):
            save_image(
                parts_output_dir
                / (
                    f"card_{card_index:02d}"
                    f"_{part_name}.png"
                ),
                part_image
            )

        if not args.no_debug:
            roi_debug = create_roi_debug(
                card_image
            )

            save_image(
                parts_output_dir
                / (
                    f"card_{card_index:02d}"
                    "_roi_debug.png"
                ),
                roi_debug
            )

        public_indices.append(
            card_index
        )

        detected_metadata.append(
            metadata
        )

    icon_detector = CardIconDetector(
        template_dir=(
            ROOT
            / "templates"
            / "card-icons"
        ),
        threshold=args.icon_threshold
    )

    digit_detector = CardDigitDetector(
        template_dir=(
            ROOT
            / "templates"
            / "card-digits-runtime"
        ),
        threshold=args.digit_threshold
    )

    face_reader = CardFaceReader(
        icon_detector=icon_detector,
        digit_detector=digit_detector
    )

    card_results: list[
        dict[str, Any]
    ] = []

    for card_index in public_indices:
        face_result = face_reader.read_card(
            card_index=card_index,
            folder=parts_output_dir
        )

        result_dict = asdict(
            face_result
        )

        card_results.append(
            result_dict
        )

    output_data = {
        "video": str(video_path),
        "time_seconds": args.time,
        "hand_count": len(
            hand_result.cards
        ),
        "public_card_count": len(
            public_indices
        ),
        "cards": card_results,
        "detected_cards": (
            detected_metadata
        ),
    }

    result_output_dir.mkdir(
        parents=True,
        exist_ok=True
    )

    json_output_path = (
        result_output_dir
        / (
            f"card-detection-"
            f"{time_folder}.json"
        )
    )

    with json_output_path.open(
        "w",
        encoding="utf-8"
    ) as file:
        json.dump(
            output_data,
            file,
            ensure_ascii=False,
            indent=2
        )

    print(
        f"時間：{time_folder} 秒"
    )

    print(
        f"偵測到手牌："
        f"{len(hand_result.cards)} 張"
    )

    print(
        f"進入公牌辨識："
        f"{len(public_indices)} 張"
    )

    print("\n辨識結果：")

    if not card_results:
        print(
            "沒有可辨識的公牌"
        )

    for result in card_results:
        upper_text = format_side(
            result["upper"]
        )

        lower_text = format_side(
            result["lower"]
        )

        print(
            f"card_{result['card_index']:02d}："
            f"{upper_text} / {lower_text}"
        )

    skipped_cards = [
        item
        for item in detected_metadata
        if item["skipped"]
    ]

    if skipped_cards:
        print("\n略過項目：")

        for item in skipped_cards:
            print(
                f"card_{item['card_index']:02d}："
                f"{item['skip_reason']} "
                f"({item['card_type']})"
            )

    print(
        f"\nJSON：{json_output_path}"
    )

    if not args.no_debug:
        print(
            "除錯圖片："
            f"{ROOT / 'debug_frames'}"
        )

    if args.json:
        print("\n完整 JSON：")

        print(
            json.dumps(
                output_data,
                ensure_ascii=False,
                indent=2
            )
        )


if __name__ == "__main__":
    main()