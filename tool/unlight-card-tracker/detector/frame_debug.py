from __future__ import annotations

import argparse
import json
from pathlib import Path

import cv2

from roi import draw_regions


ROOT = Path(__file__).resolve().parent


def main() -> None:
    parser = argparse.ArgumentParser(
        description="輸出指定秒數的 ROI 校正圖"
    )
    parser.add_argument("video", type=Path)
    parser.add_argument("--time", type=float, default=0.0)
    parser.add_argument(
        "--config",
        type=Path,
        default=ROOT / "config.json"
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=ROOT / "debug_frames" / "roi-preview.jpg"
    )
    args = parser.parse_args()

    config = json.loads(args.config.read_text(encoding="utf-8"))
    capture = cv2.VideoCapture(str(args.video))
    if not capture.isOpened():
        raise RuntimeError(f"無法開啟影片：{args.video}")

    capture.set(cv2.CAP_PROP_POS_MSEC, args.time * 1000)
    ok, frame = capture.read()
    capture.release()

    if not ok:
        raise RuntimeError("無法讀取指定時間的畫面")

    preview = draw_regions(frame, config["regions"])
    args.output.parent.mkdir(parents=True, exist_ok=True)
    cv2.imwrite(str(args.output), preview)
    print(f"已輸出：{args.output}")


if __name__ == "__main__":
    main()
