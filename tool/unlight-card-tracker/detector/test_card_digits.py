from __future__ import annotations

from pathlib import Path

import cv2

from card_digit_detector import CardDigitDetector


ROOT = Path(__file__).resolve().parent


def main() -> None:
    source_dir = (
        ROOT
        / "debug_frames"
        / "card-parts"
    )

    template_dir = (
        ROOT
        / "templates"
        / "card-digits-runtime"
    )

    detector = CardDigitDetector(
        template_dir=template_dir,
        threshold=0.65
    )

    digit_paths = sorted(
        source_dir.rglob("*_digit.png")
    )

    if not digit_paths:
        raise RuntimeError(
            "找不到 card-parts 內的 digit 圖片"
        )

    for path in digit_paths:
        image = cv2.imread(str(path))

        if image is None:
            continue

        if "_upper_digit" in path.name:
            position = "upper"
        elif "_lower_digit" in path.name:
            position = "lower"
        else:
            continue

        result = detector.detect(
            image,
            position
        )

        relative_path = path.relative_to(
            source_dir
        )

        value_text = (
            str(result.value)
            if result.value is not None
            else "None"
        )

        print(
            f"{str(relative_path):50s} "
            f"value={value_text:4s} "
            f"confidence={result.confidence:.4f}"
        )


if __name__ == "__main__":
    main()