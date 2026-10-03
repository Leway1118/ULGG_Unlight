from __future__ import annotations

from pathlib import Path

import cv2
import numpy as np

from card_digit_detector import CardDigitDetector


ROOT = Path(__file__).resolve().parent


def main() -> None:
    source_dir = (
        ROOT
        / "debug_frames"
        / "card-parts"
    )

    output_dir = (
        ROOT
        / "debug_frames"
        / "problem-digits"
    )

    template_dir = (
        ROOT
        / "templates"
        / "card-digits-runtime"
    )

    detector = CardDigitDetector(
        template_dir=template_dir,
        threshold=0.45
    )

    output_dir.mkdir(
        parents=True,
        exist_ok=True
    )

    problem_count = 0

    for path in sorted(
        source_dir.rglob("*_digit.png")
    ):
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

        # 只輸出辨識失敗或信心低於 0.70
        if (
            result.value is not None
            and result.confidence >= 0.70
        ):
            continue

        relative = path.relative_to(
            source_dir
        )

        value_text = (
            str(result.value)
            if result.value is not None
            else "None"
        )

        height, width = image.shape[:2]

        scale = 8

        enlarged = cv2.resize(
            image,
            (
                width * scale,
                height * scale
            ),
            interpolation=cv2.INTER_NEAREST
        )

        canvas_width = enlarged.shape[1] + 20

        canvas_height = (
            enlarged.shape[0] + 90
        )

        canvas = np.zeros(
            (
                canvas_height,
                canvas_width,
                3
            ),
            dtype=np.uint8
        )

        canvas[
            70:70 + enlarged.shape[0],
            10:10 + enlarged.shape[1]
        ] = enlarged

        cv2.putText(
            canvas,
            str(relative),
            (8, 22),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.5,
            (255, 255, 255),
            1,
            cv2.LINE_AA
        )

        cv2.putText(
            canvas,
            (
                f"value={value_text} "
                f"confidence={result.confidence:.4f}"
            ),
            (8, 48),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.5,
            (255, 255, 255),
            1,
            cv2.LINE_AA
        )

        target_folder = (
            output_dir
            / relative.parent
        )

        target_folder.mkdir(
            parents=True,
            exist_ok=True
        )

        output_path = (
            target_folder
            / f"{path.stem}_problem.png"
        )

        cv2.imwrite(
            str(output_path),
            canvas
        )

        print(
            f"{relative} "
            f"value={value_text} "
            f"confidence={result.confidence:.4f}"
        )

        problem_count += 1

    print(
        f"完成，共輸出 {problem_count} 張問題數字"
    )

    print(
        f"輸出位置：{output_dir}"
    )


if __name__ == "__main__":
    main()