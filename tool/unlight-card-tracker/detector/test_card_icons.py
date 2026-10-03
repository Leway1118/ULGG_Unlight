from __future__ import annotations

from pathlib import Path

import cv2

from card_icon_detector import CardIconDetector


ROOT = Path(__file__).resolve().parent


def main() -> None:
    source_dir = (
        ROOT
        / "debug_frames"
        / "card-parts"
    )

    detector = CardIconDetector(
        template_dir=(
            ROOT
            / "templates"
            / "card-icons"
        ),
        threshold=0.55
    )

    icon_paths = sorted(
        source_dir.rglob("*_icon.png")
    )

    if not icon_paths:
        raise RuntimeError(
            "找不到 card-parts 內的 icon 圖片"
        )

    for path in icon_paths:
        image = cv2.imread(str(path))

        if image is None:
            continue

        if "_upper_icon" in path.name:
            position = "top"
        elif "_lower_icon" in path.name:
            position = "bottom"
        else:
            continue

        result = detector.detect(
            image,
            position
        )

        relative_path = path.relative_to(
            source_dir
        )

        print(
            f"{str(relative_path):45s} "
            f"type={str(result.icon_type):8s} "
            f"confidence={result.confidence:.4f}"
        )


if __name__ == "__main__":
    main()