from __future__ import annotations

from pathlib import Path

import cv2


ROOT = Path(__file__).resolve().parent

SOURCE = (
    ROOT
    / "debug_frames"
    / "card-faces"
)

OUTPUT = (
    ROOT
    / "debug_frames"
    / "face-regions"
)

OUTPUT.mkdir(
    parents=True,
    exist_ok=True
)


def draw_region(
    image,
    x1: int,
    y1: int,
    x2: int,
    y2: int
):
    debug = image.copy()

    cv2.rectangle(
        debug,
        (x1, y1),
        (x2 - 1, y2 - 1),
        (0, 255, 0),
        1
    )

    return debug


for path in sorted(SOURCE.rglob("*_top.png")):
    image = cv2.imread(str(path))

    if image is None:
        continue

    relative = path.relative_to(SOURCE)

    output_path = (
        OUTPUT
        / relative.parent
        / relative.name
    )

    output_path.parent.mkdir(
        parents=True,
        exist_ok=True
    )

    debug = draw_region(
        image,
        23,
        15,
        43,
        42
    )

    cv2.imwrite(
        str(output_path),
        debug
    )


for path in sorted(SOURCE.rglob("*_bottom.png")):
    image = cv2.imread(str(path))

    if image is None:
        continue

    relative = path.relative_to(SOURCE)

    output_path = (
        OUTPUT
        / relative.parent
        / relative.name
    )

    output_path.parent.mkdir(
        parents=True,
        exist_ok=True
    )

    debug = draw_region(
        image,
        1,
        9,
        22,
        38
    )

    cv2.imwrite(
        str(output_path),
        debug
    )


print(f"輸出位置：{OUTPUT}")