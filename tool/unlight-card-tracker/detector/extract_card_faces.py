from __future__ import annotations

import argparse
import json
from pathlib import Path

import cv2

from hand_detector import HandDetector


ROOT = Path(__file__).resolve().parent


def main() -> None:
    parser = argparse.ArgumentParser(
        description="擷取公牌上下半部"
    )

    parser.add_argument(
        "video",
        type=Path
    )

    parser.add_argument(
        "--time",
        type=float,
        default=20.0
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
        / "card-faces"
        / f"{args.time:.1f}"
    )

    output_dir.mkdir(
        parents=True,
        exist_ok=True
    )

    public_index = 0

    for card in result.cards:
        if card.card_type != "public_event":
            continue

        public_index += 1

        cropped = result.roi[
            card.y:card.y + card.height,
            card.x:card.x + card.width
        ]

        normalized = cropped.copy()

        # 先切成上下兩區。
        # 中央交界稍微避開，減少兩面互相干擾。
        top_face = normalized.copy()
        bottom_face = normalized.copy()

        cv2.imwrite(
            str(
                output_dir
                / f"card_{public_index:02d}_full.png"
            ),
            normalized
        )

        cv2.imwrite(
            str(
                output_dir
                / f"card_{public_index:02d}_top.png"
            ),
            top_face
        )

        cv2.imwrite(
            str(
                output_dir
                / f"card_{public_index:02d}_bottom.png"
            ),
            bottom_face
        )

    print(
        f"時間：{args.time:.1f} 秒"
    )

    print(
        f"已輸出 {public_index} 張公牌"
    )

    print(
        f"輸出位置：{output_dir}"
    )


if __name__ == "__main__":
    main()