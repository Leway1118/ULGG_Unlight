from __future__ import annotations

import argparse
from pathlib import Path

import cv2
import numpy as np

from deck_counter import DeckCounter


ROOT = Path(__file__).resolve().parent
TEMPLATE_DIR = ROOT / "templates" / "digits"


def split_digits(binary: np.ndarray) -> list[np.ndarray]:
    active_columns = np.any(binary > 0, axis=0)

    groups = []
    start = None

    for x, active in enumerate(active_columns):
        if active and start is None:
            start = x

        if not active and start is not None:
            groups.append((start, x))
            start = None

    if start is not None:
        groups.append((start, binary.shape[1]))

    groups = [
        (x1, x2)
        for x1, x2 in groups
        if x2 - x1 >= 2
    ]

    digits = []

    for x1, x2 in groups:
        strip = binary[:, x1:x2]

        active_rows = np.where(
            np.any(strip > 0, axis=1)
        )[0]

        if len(active_rows) == 0:
            continue

        y1 = int(active_rows[0])
        y2 = int(active_rows[-1]) + 1

        digits.append(strip[y1:y2, :])

    return digits


def extract_digits(
    image_path: Path,
    labels: str
) -> None:
    image = cv2.imread(
        str(image_path),
        cv2.IMREAD_GRAYSCALE
    )

    if image is None:
        raise RuntimeError(
            f"無法讀取圖片：{image_path}"
        )

    _, binary = cv2.threshold(
        image,
        160,
        255,
        cv2.THRESH_BINARY
    )

    digits = split_digits(binary)

    if len(digits) != len(labels):
        raise RuntimeError(
            f"切出 {len(digits)} 個數字，"
            f"但標籤為 {labels}，共 {len(labels)} 位"
        )

    TEMPLATE_DIR.mkdir(
        parents=True,
        exist_ok=True
    )

    for label, digit_image in zip(
        labels,
        digits
    ):
        normalized = DeckCounter._normalize_digit(
            digit_image,
            target_width=24,
            target_height=36
        )

        output = TEMPLATE_DIR / f"{label}.png"

        if not cv2.imwrite(
            str(output),
            normalized
        ):
            raise RuntimeError(
                f"模板寫入失敗：{output}"
            )

        print(f"已建立：{output}")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="建立牌庫數字模板"
    )

    parser.add_argument(
        "image",
        type=Path
    )

    parser.add_argument(
        "labels"
    )

    args = parser.parse_args()

    extract_digits(
        args.image,
        args.labels
    )


if __name__ == "__main__":
    main()