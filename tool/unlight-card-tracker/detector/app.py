from __future__ import annotations

import argparse
import json
from pathlib import Path

import cv2

from deck_counter import DeckCounter
from phase_detector import PhaseDetector
from roi import draw_regions
from site_detector import SiteDetector
from state_tracker import VisionStateTracker


ROOT = Path(__file__).resolve().parent



def load_config(path: Path) -> dict:
    with path.open("r", encoding="utf-8") as file:
        return json.load(file)


def run_video(
    video_path: Path,
    config: dict,
    output_path: Path
) -> None:
    capture = cv2.VideoCapture(str(video_path))

    if not capture.isOpened():
        raise RuntimeError(
            f"無法開啟影片：{video_path}"
        )

    fps = capture.get(cv2.CAP_PROP_FPS) or 30.0

    sample_fps = float(
        config["capture"].get(
            "sample_fps",
            5
        )
    )

    frame_step = max(
        1,
        round(fps / sample_fps)
    )

    regions = config["regions"]

    deck_counter = DeckCounter(
        regions["deck_counter"],
        ROOT / "templates" / "digits",
    )

    phase_detector = PhaseDetector(
        regions["phase_bar"]
    )

    site_detector = SiteDetector(
        ROOT.parent / "assets" / "sites",
        regions["site_background"],
    )

    tracker = VisionStateTracker(
        stable_seconds=float(
            config["capture"].get(
                "stable_seconds",
                0.6
            )
        )
    )

    events = []
    frame_index = 0

    last_site_key = None
    last_preview_second = -1

    last_site_check_time = -999.0
    site_check_interval = 2.0
    site_detection_finished = False

    site_candidate = None
    site_candidate_count = 0
    site_candidate_scores = []

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
        site_result = None

        if (
            not site_detection_finished
            and timestamp - last_site_check_time
                >= site_check_interval
        ):
            last_site_check_time = timestamp
            site_result = site_detector.detect(frame)

        phase_event = tracker.update_phase(
            phase_result.phase,
            timestamp
        )

        if phase_event:
            phase_event["time"] = round(
                timestamp,
                3
            )

            phase_event["confidence"] = round(
                phase_result.confidence,
                4
            )

            events.append(phase_event)

        deck_event = tracker.update_deck(
            deck_result.value,
            timestamp
        )

        if deck_event:
            deck_event["time"] = round(
                timestamp,
                3
            )

            deck_event["confidence"] = round(
                deck_result.confidence,
                4
            )

            deck_event["phase"] = (
                tracker.last_phase
            )

            events.append(deck_event)

        # 場景只在辨識結果改變時寫入一次，
        # 避免每幀產生重複事件。
        score_gap = 0.0

        if site_result is not None:
            score_gap = (
                site_result.confidence
                - site_result.second_confidence
            )

        if (
            site_result is not None
            and site_result.site_key is not None
            and site_result.confidence >= 0.55
            and score_gap >= 0.15
        ):
            if site_result.site_key == site_candidate:
                site_candidate_count += 1
                site_candidate_scores.append(
                    site_result.confidence
                )
            else:
                site_candidate = site_result.site_key
                site_candidate_count = 1
                site_candidate_scores = [
                    site_result.confidence
                ]

            if site_candidate_count >= 3:
                average_confidence = (
                    sum(site_candidate_scores)
                    / len(site_candidate_scores)
                )

                last_site_key = site_candidate
                site_detection_finished = True

                events.append({
                    "type": "site_detected",
                    "site": site_candidate,
                    "confidence": round(
                        average_confidence,
                        4
                    ),
                    "score_gap": round(
                        score_gap,
                        4
                    ),
                    "samples": site_candidate_count,
                    "time": round(
                        timestamp,
                        3
                    ),
                })

                print(
                    f"確認場景：{site_candidate} "
                    f"(平均 {average_confidence:.4f}，"
                    f"差距 {score_gap:.4f})"
                )

        # 每 10 秒輸出一張 ROI 校正圖。
        current_second = int(timestamp)

        if (
            current_second % 10 == 0
            and current_second != last_preview_second
        ):
            last_preview_second = current_second

            preview = draw_regions(
                frame,
                regions
            )

            preview_path = (
                ROOT
                / "debug_frames"
                / f"preview_{current_second:04d}.jpg"
            )

            cv2.imwrite(
                str(preview_path),
                preview
            )

            binary_path = (
                ROOT
                / "debug_frames"
                / f"deck_{current_second:04d}.png"
            )

            cv2.imwrite(
                str(binary_path),
                deck_result.binary
            )

        frame_index += 1

    capture.release()

    output_path.parent.mkdir(
        parents=True,
        exist_ok=True
    )

    output_path.write_text(
        json.dumps(
            events,
            ensure_ascii=False,
            indent=2
        ),
        encoding="utf-8"
    )

    print(f"完成：{output_path}")
    print(f"事件數：{len(events)}")

    digit_templates = list(
        (
            ROOT
            / "templates"
            / "digits"
        ).glob("*.png")
    )

    if len(digit_templates) < 10:
        print(
            f"提醒：目前只有 "
            f"{len(digit_templates)} 個數字模板，"
            "缺少的數字可能無法辨識。"
        )

        print(
            "請先檢查 "
            "detector/debug_frames "
            "內的 deck_*.png。"
        )


def main() -> None:
    parser = argparse.ArgumentParser(
        description=(
            "Unlight 公牌庫畫面辨識測試器"
        )
    )

    parser.add_argument(
        "video",
        type=Path,
        help="要分析的 MP4 影片"
    )

    parser.add_argument(
        "--config",
        type=Path,
        default=ROOT / "config.json",
        help="config.json 路徑",
    )

    parser.add_argument(
        "--output",
        type=Path,
        default=(
            ROOT
            / "output"
            / "events.json"
        ),
        help="輸出事件 JSON",
    )

    args = parser.parse_args()

    config = load_config(args.config)

    run_video(
        args.video,
        config,
        args.output
    )


if __name__ == "__main__":
    main()