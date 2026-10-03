from __future__ import annotations

import argparse
import json
import re
from dataclasses import asdict
from pathlib import Path

from card_digit_detector import CardDigitDetector
from card_face_reader import CardFaceReader
from card_icon_detector import CardIconDetector


ROOT = Path(__file__).resolve().parent


ICON_LABELS = {
    "sword": "劍",
    "gun": "槍",
    "shield": "盾",
    "special": "特",
    "move": "移",
    None: "?",
}


def find_card_indices(
    folder: Path
) -> list[int]:
    indices: set[int] = set()

    pattern = re.compile(
        r"^card_(\d+)_upper_icon\.png$"
    )

    for path in folder.glob(
        "card_*_upper_icon.png"
    ):
        matched = pattern.match(path.name)

        if matched is None:
            continue

        indices.add(
            int(matched.group(1))
        )

    return sorted(indices)


def format_side(
    side: dict
) -> str:
    icon_text = ICON_LABELS.get(
        side["icon_type"],
        "?"
    )

    value = side["value"]

    value_text = (
        str(value)
        if value is not None
        else "?"
    )

    return (
        f"{icon_text}{value_text}"
    )


def main() -> None:
    parser = argparse.ArgumentParser(
        description="測試完整事件卡圖示與數字辨識"
    )

    parser.add_argument(
        "--time",
        type=float,
        default=50.0,
        help="card-parts 的影片時間資料夾"
    )

    parser.add_argument(
        "--skip",
        type=int,
        nargs="*",
        default=[],
        help="要略過的卡片編號，例如 --skip 4"
    )

    parser.add_argument(
        "--no-json",
        action="store_true",
        help="不輸出完整 JSON，只顯示中文摘要"
    )

    args = parser.parse_args()

    time_folder = f"{args.time:.1f}"

    parts_folder = (
        ROOT
        / "debug_frames"
        / "card-parts"
        / time_folder
    )

    if not parts_folder.exists():
        raise RuntimeError(
            f"找不到資料夾：{parts_folder}"
        )

    icon_detector = CardIconDetector(
        template_dir=(
            ROOT
            / "templates"
            / "card-icons"
        ),
        threshold=0.55
    )

    digit_detector = CardDigitDetector(
        template_dir=(
            ROOT
            / "templates"
            / "card-digits-runtime"
        ),
        threshold=0.65
    )

    reader = CardFaceReader(
        icon_detector=icon_detector,
        digit_detector=digit_detector
    )

    card_indices = find_card_indices(
        parts_folder
    )

    if not card_indices:
        raise RuntimeError(
            f"找不到卡片局部圖：{parts_folder}"
        )

    skip_indices = set(
        args.skip
    )

    results = []

    for card_index in card_indices:
        if card_index in skip_indices:
            continue

        result = reader.read_card(
            card_index=card_index,
            folder=parts_folder
        )

        results.append(
            asdict(result)
        )

    if not args.no_json:
        print(
            json.dumps(
                results,
                ensure_ascii=False,
                indent=2
            )
        )

    print(
        f"\n辨識結果（{time_folder} 秒）："
    )

    for result in results:
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

    if skip_indices:
        skipped_text = ", ".join(
            f"card_{index:02d}"
            for index in sorted(skip_indices)
        )

        print(
            f"\n已略過：{skipped_text}"
        )


if __name__ == "__main__":
    main()