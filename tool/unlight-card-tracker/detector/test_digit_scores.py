from pathlib import Path

import cv2

from deck_counter import DeckCounter


ROOT = Path(__file__).resolve().parent
TEMPLATE_DIR = ROOT / "templates" / "digits"

for image_path in sorted(
    (ROOT / "debug_frames").glob("deck_*.png")
):
    image = cv2.imread(str(image_path))

    if image is None:
        continue

    height, width = image.shape[:2]

    counter = DeckCounter(
        {
            "x": 0,
            "y": 0,
            "width": width,
            "height": height
        },
        TEMPLATE_DIR
    )

    result = counter.detect(image)

    print(
        f"{image_path.name:16}",
        f"value={result.value!s:4}",
        f"confidence={result.confidence:.4f}"
    )