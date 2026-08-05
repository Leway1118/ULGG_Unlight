from __future__ import annotations

import argparse
import json
import time
from pathlib import Path

import cv2

from deck_counter import DeckCounter
from phase_detector import PhaseDetector
from roi import draw_regions
from state_tracker import VisionStateTracker


ROOT = Path(__file__).resolve().parent


def load_config(path: Path) -> dict:
    with path.open("r", encoding="utf-8") as file:
        return json.load(file)


def run_video(video_path: Path, config: dict, output_path: Path) -> None:
    capture = cv2.VideoCapture(str(video_path))
    if not capture.isOpened():
        raise RuntimeError(f"無法開啟影片：{video_path}")

    fps = capture.get(cv2.CAP_PROP_FPS) or 30.0
    sample_fps = float(config["capture"].get("sample_fps", 5))
    frame_step = max(1, round(fps / sample_fps))

    regions = config["regions"]
    deck_counter = DeckCounter(
        regions["deck_counter"],
        ROOT / "templates" / "digits",
    )
    phase_detector = PhaseDetector(regions["phase_bar"])
    tracker = VisionStateTracker(
        stable_seconds=float(
            config["capture"].get("stable_seconds", 0.6)
        )
    )

    events = []
    frame_index = 0

    while True:
        ok, frame = capture.read()
        if not ok:
            break

        if frame_index % frame_step != 0:
            frame_index += 1
            continue

        timestamp = frame_index / fps

        deck_result = deck_counter.detect(frame)
        phase_result = phase_detector.detect(frame)

        phase_event = tracker.update_phase(phase_result.phase)
        if phase_event:
            phase_event["time"] = round(timestamp, 3)
            phase_event["confidence"] = round(phase_result.confidence, 4)
            events.append(phase_event)

        deck_event = tracker.update_deck(deck_result.value, timestamp)
        if deck_event:
            deck_event["time"] = round(timestamp, 3)
            deck_event["confidence"] = round(deck_result.confidence, 4)
            deck_event["phase"] = tracker.last_phase
            events.append(deck_event)

        # 每 10 秒輸出一張 ROI 校正圖，方便調整 config.json。
        if int(timestamp) % 10 == 0:
            preview = draw_regions(frame, regions)
            preview_path = ROOT / "debug_frames" / f"preview_{int(timestamp):04d}.jpg"
            cv2.imwrite(str(preview_path), preview)

            binary_path = ROOT / "debug_frames" / f"deck_{int(timestamp):04d}.png"
            cv2.imwrite(str(binary_path), deck_result.binary)

        frame_index += 1

    capture.release()

    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(
        json.dumps(events, ensure_ascii=False, indent=2),
        encoding="utf-8"
    )

    print(f"完成：{output_path}")
    print(f"事件數：{len(events)}")
    if not (ROOT / "templates" / "digits" / "0.png").exists():
        print("提醒：尚未建立 0~9 數字模板，目前 deck value 會是空值。")
        print("請先檢查 detector/debug_frames 內的 deck_*.png。")


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Unlight 公牌庫畫面辨識測試器"
    )
    parser.add_argument("video", type=Path, help="要分析的 MP4 影片")
    parser.add_argument(
        "--config",
        type=Path,
        default=ROOT / "config.json",
        help="config.json 路徑",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=ROOT / "output" / "events.json",
        help="輸出事件 JSON",
    )
    args = parser.parse_args()

    config = load_config(args.config)
    run_video(args.video, config, args.output)


if __name__ == "__main__":
    main()
