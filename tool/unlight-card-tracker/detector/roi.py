from __future__ import annotations

from typing import Dict
import cv2
import numpy as np


def crop(frame: np.ndarray, region: Dict[str, int]) -> np.ndarray:
    x = int(region["x"])
    y = int(region["y"])
    w = int(region["width"])
    h = int(region["height"])
    return frame[y:y+h, x:x+w]


def draw_regions(frame: np.ndarray, regions: Dict[str, Dict[str, int]]) -> np.ndarray:
    preview = frame.copy()

    for name, region in regions.items():
        x = int(region["x"])
        y = int(region["y"])
        w = int(region["width"])
        h = int(region["height"])

        cv2.rectangle(preview, (x, y), (x + w, y + h), (0, 255, 0), 2)
        cv2.putText(
            preview,
            name,
            (x, max(18, y - 5)),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.45,
            (0, 255, 0),
            1,
            cv2.LINE_AA,
        )

    return preview
