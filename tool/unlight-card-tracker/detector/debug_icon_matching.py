from pathlib import Path

import cv2
import numpy as np


ROOT = Path(__file__).resolve().parent

template_path = (
    ROOT
    / "templates"
    / "card-icons"
    / "sword.png"
)

sample_path = (
    ROOT
    / "debug_frames"
    / "card-parts"
    / "20.0"
    / "card_01_upper_icon.png"
)


def normalize(image):
    if image.ndim == 2:
        bgr = cv2.cvtColor(
            image,
            cv2.COLOR_GRAY2BGR
        )
    else:
        bgr = image[:, :, :3].copy()

    height, width = bgr.shape[:2]

    # 排除最上方 ATTACK 文字區
    crop_top = max(
        0,
        round(height * 0.28)
    )

    icon_area = bgr[
        crop_top:height,
        0:width
    ]

    hsv = cv2.cvtColor(
        icon_area,
        cv2.COLOR_BGR2HSV
    )

    saturation = hsv[:, :, 1]
    value = hsv[:, :, 2]

    # 白亮劍身 + 彩色握柄
    bright_mask = (
        value >= 105
    )

    color_mask = (
        (saturation >= 55)
        & (value >= 45)
    )

    mask = (
        bright_mask
        | color_mask
    ).astype(np.uint8) * 255

    # 清除小雜點
    kernel = np.ones(
        (2, 2),
        dtype=np.uint8
    )

    mask = cv2.morphologyEx(
        mask,
        cv2.MORPH_OPEN,
        kernel,
        iterations=1
    )

    # 找連通區，只保留最大圖示本體
    count, labels, stats, _ = (
        cv2.connectedComponentsWithStats(
            mask,
            connectivity=8
        )
    )

    if count <= 1:
        return np.zeros(
            (64, 64),
            dtype=np.uint8
        )

    largest_label = 1 + int(
        np.argmax(
            stats[1:, cv2.CC_STAT_AREA]
        )
    )

    largest_mask = (
        labels == largest_label
    ).astype(np.uint8) * 255

    points = cv2.findNonZero(
        largest_mask
    )

    if points is None:
        return np.zeros(
            (64, 64),
            dtype=np.uint8
        )

    x, y, box_width, box_height = (
        cv2.boundingRect(points)
    )

    cropped = largest_mask[
        y:y + box_height,
        x:x + box_width
    ]

    target_size = 48

    scale = min(
        target_size / max(1, box_width),
        target_size / max(1, box_height)
    )

    resized_width = max(
        1,
        round(box_width * scale)
    )

    resized_height = max(
        1,
        round(box_height * scale)
    )

    resized = cv2.resize(
        cropped,
        (
            resized_width,
            resized_height
        ),
        interpolation=cv2.INTER_NEAREST
    )

    canvas = np.zeros(
        (64, 64),
        dtype=np.uint8
    )

    offset_x = (
        64 - resized_width
    ) // 2

    offset_y = (
        64 - resized_height
    ) // 2

    canvas[
        offset_y:offset_y + resized_height,
        offset_x:offset_x + resized_width
    ] = resized

    return canvas

def prepare_icon_area(image):
    if image.ndim == 2:
        gray = image.copy()
    else:
        gray = cv2.cvtColor(
            image[:, :, :3],
            cv2.COLOR_BGR2GRAY
        )

    height, width = gray.shape[:2]

    # 排除上方 ATTACK 文字
    crop_top = round(height * 0.28)

    return gray[
        crop_top:height,
        0:width
    ]
    
def multiscale_match(
    template,
    sample
):
    template_area = prepare_icon_area(
        template
    )

    sample_area = prepare_icon_area(
        sample
    )

    best_score = -1.0
    best_scale = 1.0
    best_template = None

    for scale in np.arange(
        0.65,
        1.46,
        0.05
    ):
        resized_width = max(
            2,
            round(
                template_area.shape[1]
                * scale
            )
        )

        resized_height = max(
            2,
            round(
                template_area.shape[0]
                * scale
            )
        )

        if (
            resized_width
            > sample_area.shape[1]
            or resized_height
            > sample_area.shape[0]
        ):
            continue

        resized_template = cv2.resize(
            template_area,
            (
                resized_width,
                resized_height
            ),
            interpolation=cv2.INTER_CUBIC
        )

        result = cv2.matchTemplate(
            sample_area,
            resized_template,
            cv2.TM_CCOEFF_NORMED
        )

        score = float(
            result.max()
        )

        if score > best_score:
            best_score = score
            best_scale = float(scale)
            best_template = resized_template

    return (
        best_score,
        best_scale,
        best_template,
        sample_area
    )

def fit_preview(
    image,
    canvas_width=192,
    canvas_height=192
):
    height, width = image.shape[:2]

    scale = min(
        canvas_width / width,
        canvas_height / height
    )

    resized_width = max(
        1,
        round(width * scale)
    )

    resized_height = max(
        1,
        round(height * scale)
    )

    resized = cv2.resize(
        image,
        (
            resized_width,
            resized_height
        ),
        interpolation=cv2.INTER_NEAREST
    )

    if resized.ndim == 2:
        resized = cv2.cvtColor(
            resized,
            cv2.COLOR_GRAY2BGR
        )

    canvas = np.zeros(
        (
            canvas_height,
            canvas_width,
            3
        ),
        dtype=np.uint8
    )

    x = (
        canvas_width - resized_width
    ) // 2

    y = (
        canvas_height - resized_height
    ) // 2

    canvas[
        y:y + resized_height,
        x:x + resized_width
    ] = resized

    return canvas


template = cv2.imread(str(template_path))
sample = cv2.imread(str(sample_path))

if template is None:
    raise RuntimeError(
        f"找不到模板：{template_path}"
    )

if sample is None:
    raise RuntimeError(
        f"找不到樣本：{sample_path}"
    )

print(
    "template 原始尺寸：",
    template.shape[1],
    "x",
    template.shape[0]
)

print(
    "sample 原始尺寸：",
    sample.shape[1],
    "x",
    sample.shape[0]
)

template_norm = normalize(template)
sample_norm = normalize(sample)

(
    score,
    best_scale,
    best_template,
    sample_area
) = multiscale_match(
    template,
    sample
)

print("最佳縮放：", best_scale)
print("比對分數：", score)

print("比對分數：", score)

comparison = np.hstack([
    fit_preview(template),
    fit_preview(best_template),
    fit_preview(sample),
    fit_preview(sample_area),
])

output_path = (
    ROOT
    / "debug_frames"
    / "icon-match-debug.png"
)

cv2.imwrite(
    str(output_path),
    comparison
)

print("輸出：", output_path)