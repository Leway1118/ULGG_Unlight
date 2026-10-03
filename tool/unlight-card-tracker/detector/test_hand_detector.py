from __future__ import annotations

import argparse
import json
from pathlib import Path

import cv2

from hand_detector import HandDetector


ROOT = Path(__file__).resolve().parent


def main() -> None:
    parser = argparse.ArgumentParser(
        description="測試手牌卡片切割"
    )

    parser.add_argument(
        "video",
        type=Path
    )

    parser.add_argument(
        "--time",
        type=float,
        default=20.0,
        help="擷取影片第幾秒"
    )

    args = parser.parse_args()

    with (
        ROOT / "config.json"
    ).open(
        "r",
        encoding="utf-8"
    ) as file:
        config = json.load(file)

    capture = cv2.VideoCapture(
        str(args.video)
    )

    if not capture.isOpened():
        raise RuntimeError(
            f"無法開啟影片：{args.video}"
        )

    capture.set(
        cv2.CAP_PROP_POS_MSEC,
        args.time * 1000
    )

    ok, frame = capture.read()
    capture.release()

    if not ok:
        raise RuntimeError(
            f"無法讀取第 {args.time} 秒畫面"
        )

    detector = HandDetector(
        config["regions"]["bottom_hand"],
        ROOT / "templates" / "personal-events"
    )

    result = detector.detect(frame)

    output_dir = (
        ROOT
        / "debug_frames"
        / "hand"
    )

    output_dir.mkdir(
        parents=True,
        exist_ok=True
    )

    debug_path = (
        output_dir
        / f"hand_{args.time:.1f}_debug.jpg"
    )

    cv2.imwrite(
        str(debug_path),
        result.debug
    )

    for index, card in enumerate(
        result.cards,
        start=1
    ):
        cropped = result.roi[
            card.y:card.y + card.height,
            card.x:card.x + card.width
        ]

        card_path = (
            output_dir
            / (
                f"hand_{args.time:.1f}"
                f"_card_{index:02d}.png"
            )
        )

        cv2.imwrite(
            str(card_path),
            cropped
        )

    print(
        f"時間：{args.time:.1f} 秒"
    )

    print(
        f"偵測到手牌：{len(result.cards)} 張"
    )

    for index, card in enumerate(
        result.cards,
        start=1
    ):
        print(
            f"#{index} "
            f"type={card.card_type} "
            f"x={card.x} "
            f"y={card.y} "
            f"w={card.width} "
            f"h={card.height} "
            f"confidence={card.confidence:.3f}"
        )

    print(
        f"除錯圖：{debug_path}"
    )


if __name__ == "__main__":
    main()