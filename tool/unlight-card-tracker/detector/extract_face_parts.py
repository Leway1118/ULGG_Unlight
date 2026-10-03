from __future__ import annotations

import argparse
from pathlib import Path

import cv2


ROOT = Path(__file__).resolve().parent

# =========================
# 手動調整區
# 原始卡片約為 62×95
# 格式：(x1, y1, x2, y2)
# =========================

UPPER_DIGIT_ROI = (0, 10, 27, 47)
UPPER_ICON_ROI = (25, 10, 62, 48)

LOWER_ICON_ROI = (0, 47, 37, 88)
LOWER_DIGIT_ROI = (32, 47, 62, 90)


def save_part(
    image,
    output_path: Path,
    x1: int,
    y1: int,
    x2: int,
    y2: int,
    rotate_180: bool = False
) -> None:
    part = image[y1:y2, x1:x2]

    if part.size == 0:
        return

    if rotate_180:
        part = cv2.rotate(
            part,
            cv2.ROTATE_180
        )

    output_path.parent.mkdir(
        parents=True,
        exist_ok=True
    )

    cv2.imwrite(
        str(output_path),
        part
    )


def main() -> None:
    parser = argparse.ArgumentParser(
        description="從完整公牌直接擷取數字與屬性圖示"
    )

    parser.add_argument(
        "--source",
        type=Path,
        default=(
            ROOT
            / "debug_frames"
            / "card-faces"
        )
    )

    args = parser.parse_args()

    source_dir = args.source

    output_dir = (
        ROOT
        / "debug_frames"
        / "card-parts"
    )

    output_count = 0

    for full_path in sorted(
        source_dir.rglob("card_*_full.png")
    ):
        
        image = cv2.imread(str(full_path))

        if image is None:
            continue

        # 保留原始卡片比例與尺寸
        normalized = image.copy()
        
        height, width = normalized.shape[:2]

        print(
            f"{full_path.name}: "
            f"{width}x{height}"
        )

        relative_folder = (
            full_path.parent.relative_to(
                source_dir
            )
        )

        stem = full_path.stem.replace(
            "_full",
            ""
        )

        folder = (
            output_dir
            / relative_folder
        )

        

        output_count += 4
        
        x1, y1, x2, y2 = UPPER_DIGIT_ROI
        
        save_part(
            normalized,
            folder / f"{stem}_upper_digit.png",
            x1, y1, x2, y2
        )

        x1, y1, x2, y2 = UPPER_ICON_ROI
        save_part(
            normalized,
            folder / f"{stem}_upper_icon.png",
            x1, y1, x2, y2
        )

        x1, y1, x2, y2 = LOWER_ICON_ROI
        save_part(
            normalized,
            folder / f"{stem}_lower_icon.png",
            x1, y1, x2, y2
        )

        x1, y1, x2, y2 = LOWER_DIGIT_ROI
        save_part(
            normalized,
            folder / f"{stem}_lower_digit.png",
            x1, y1, x2, y2
        )
        
        debug = normalized.copy()

        roi_items = [
            ("upper_digit", UPPER_DIGIT_ROI),
            ("upper_icon", UPPER_ICON_ROI),
            ("lower_icon", LOWER_ICON_ROI),
            ("lower_digit", LOWER_DIGIT_ROI),
        ]

        for label, (x1, y1, x2, y2) in roi_items:
            cv2.rectangle(
                debug,
                (x1, y1),
                (x2 - 1, y2 - 1),
                (0, 255, 0),
                1
            )

            cv2.putText(
                debug,
                label,
                (x1, max(9, y1 + 9)),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.22,
                (0, 255, 255),
                1,
                cv2.LINE_AA
            )

        debug_large = cv2.resize(
            debug,
            None,
            fx=4,
            fy=4,
            interpolation=cv2.INTER_NEAREST
        )

        cv2.imwrite(
            str(
                folder
                / f"{stem}_roi_debug.png"
            ),
            debug_large
        )

    print(
        f"完成，共輸出 {output_count} 張局部圖"
    )

    print(
        f"輸出位置：{output_dir}"
    )


if __name__ == "__main__":
    main()