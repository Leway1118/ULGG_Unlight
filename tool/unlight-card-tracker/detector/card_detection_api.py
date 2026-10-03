from __future__ import annotations

from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any

import cv2
import numpy as np
from fastapi import FastAPI, File, Form, HTTPException, UploadFile
from fastapi.middleware.cors import CORSMiddleware

from card_digit_detector import CardDigitDetector
from card_icon_detector import CardIconDetector
from hand_detector import HandDetector
from datetime import datetime


ROOT = Path(__file__).resolve().parent


# =========================================================
# 公牌內部 ROI
# 目前完整卡片基準約為 62 × 95
# 格式：(x1, y1, x2, y2)
# =========================================================
UPPER_DIGIT_ROI = (0, 5, 27, 42)
UPPER_ICON_ROI = (25, 10, 60, 48)

LOWER_ICON_ROI = (-5, 39, 37, 85)
LOWER_DIGIT_ROI = (32, 47, 60, 90)


ICON_LABELS = {
    "sword": "劍",
    "gun": "槍",
    "shield": "盾",
    "special": "特",
    "move": "移",
    None: "?",
}


hand_detector: HandDetector | None = None
icon_detector: CardIconDetector | None = None
digit_detector: CardDigitDetector | None = None
current_state: dict[str, Any] = {
    "success": True,
    "running": False,
    "updated_at": None,
    "hand_count": 0,
    "public_card_count": 0,
    "cards": [],
    "detected_cards": [],
}


def load_config() -> dict[str, Any]:
    import json

    config_path = ROOT / "config.json"

    if not config_path.exists():
        raise RuntimeError(
            f"找不到設定檔：{config_path}"
        )

    with config_path.open(
        "r",
        encoding="utf-8"
    ) as file:
        return json.load(file)


@asynccontextmanager
async def lifespan(
    app: FastAPI
):
    global hand_detector
    global icon_detector
    global digit_detector

    config = load_config()

    bottom_hand_region = (
        config
        .get("regions", {})
        .get("bottom_hand")
    )

    if not isinstance(
        bottom_hand_region,
        dict
    ):
        raise RuntimeError(
            "config.json 找不到 regions.bottom_hand"
        )

    hand_detector = HandDetector(
        region=bottom_hand_region,
        personal_template_dir=(
            ROOT
            / "templates"
            / "personal-events"
        )
    )

    icon_detector = CardIconDetector(
        template_dir=(
            ROOT
            / "templates"
            / "card-icons"
        ),
        threshold=0.55
    )

    digit_detector = CardDigitDetector(
        template_dir=(
            ROOT
            / "templates"
            / "card-digits-runtime"
        ),
        threshold=0.65
    )

    print("卡片辨識 API 初始化完成")

    yield

    hand_detector = None
    icon_detector = None
    digit_detector = None


app = FastAPI(
    title="UL.GG Card Detection API",
    version="0.1.0",
    lifespan=lifespan
)


# 開發階段先允許跨來源。
# 正式上線後再改成只允許 ulgg.online。
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=False,
    allow_methods=["GET", "POST"],
    allow_headers=["*"],
)


def crop_image(
    image: np.ndarray,
    roi: tuple[int, int, int, int]
) -> np.ndarray:
    x1, y1, x2, y2 = roi

    height, width = image.shape[:2]

    x1 = max(
        0,
        min(x1, width)
    )

    x2 = max(
        0,
        min(x2, width)
    )

    y1 = max(
        0,
        min(y1, height)
    )

    y2 = max(
        0,
        min(y2, height)
    )

    if x2 <= x1 or y2 <= y1:
        return np.empty(
            (0, 0, 3),
            dtype=np.uint8
        )

    return image[
        y1:y2,
        x1:x2
    ].copy()
    
def translate_image(
    image: np.ndarray,
    x_offset: int = 0,
    y_offset: int = 0
) -> np.ndarray:
    if image is None or image.size == 0:
        return image

    height, width = image.shape[:2]

    matrix = np.float32([
        [1, 0, x_offset],
        [0, 1, y_offset],
    ])

    return cv2.warpAffine(
        image,
        matrix,
        (width, height),
        flags=cv2.INTER_LINEAR,
        borderMode=cv2.BORDER_CONSTANT,
        borderValue=(0, 0, 0)
    )


def parse_skip_indices(
    skip: str
) -> set[int]:
    result: set[int] = set()

    if not skip.strip():
        return result

    for item in skip.split(","):
        item = item.strip()

        if not item:
            continue

        if not item.isdigit():
            continue

        result.add(
            int(item)
        )

    return result


def format_side(
    icon_type: str | None,
    value: int | None
) -> str:
    icon_text = ICON_LABELS.get(
        icon_type,
        "?"
    )

    value_text = (
        str(value)
        if value is not None
        else "?"
    )

    return (
        f"{icon_text}{value_text}"
    )


def decode_uploaded_image(
    raw_bytes: bytes
) -> np.ndarray:
    if not raw_bytes:
        raise HTTPException(
            status_code=400,
            detail="上傳圖片內容為空"
        )

    encoded = np.frombuffer(
        raw_bytes,
        dtype=np.uint8
    )

    frame = cv2.imdecode(
        encoded,
        cv2.IMREAD_COLOR
    )

    if frame is None or frame.size == 0:
        raise HTTPException(
            status_code=400,
            detail="無法解析上傳圖片"
        )

    return frame


def detect_public_card(
    card_image: np.ndarray,
    card_index: int
) -> dict[str, Any]:
    if (
        icon_detector is None
        or digit_detector is None
    ):
        raise RuntimeError(
            "辨識器尚未初始化"
        )

    upper_icon_image = crop_image(
        card_image,
        UPPER_ICON_ROI
    )

    upper_digit_image = crop_image(
        card_image,
        UPPER_DIGIT_ROI
    )

    lower_icon_image = crop_image(
        card_image,
        LOWER_ICON_ROI
    )

    # 即時瀏覽器畫面的右下圖示偏右下，
    # 辨識前將內容往左、往上各移 5px。
    lower_icon_image = crop_image(
        card_image,
        LOWER_ICON_ROI
    )

    lower_digit_image = crop_image(
        card_image,
        LOWER_DIGIT_ROI
    )

    upper_icon_result = (
        icon_detector.detect(
            upper_icon_image,
            position="top"
        )
    )

    upper_digit_result = (
        digit_detector.detect(
            upper_digit_image,
            position="upper"
        )
    )

    lower_icon_result = (
        icon_detector.detect(
            lower_icon_image,
            position="bottom"
        )
    )

    lower_digit_result = (
        digit_detector.detect(
            lower_digit_image,
            position="lower"
        )
    )

    upper_text = format_side(
        upper_icon_result.icon_type,
        upper_digit_result.value
    )

    lower_text = format_side(
        lower_icon_result.icon_type,
        lower_digit_result.value
    )

    return {
        "card_index": card_index,

        "upper": {
            "icon_type": (
                upper_icon_result.icon_type
            ),
            "icon_confidence": round(
                upper_icon_result.confidence,
                4
            ),
            "value": (
                upper_digit_result.value
            ),
            "digit_confidence": round(
                upper_digit_result.confidence,
                4
            ),
            "text": upper_text,
        },

        "lower": {
            "icon_type": (
                lower_icon_result.icon_type
            ),
            "icon_confidence": round(
                lower_icon_result.confidence,
                4
            ),
            "value": (
                lower_digit_result.value
            ),
            "digit_confidence": round(
                lower_digit_result.confidence,
                4
            ),
            "text": lower_text,
        },

        "display": (
            f"{upper_text} / {lower_text}"
        ),
    }


@app.get("/health")
def health() -> dict[str, Any]:
    return {
        "success": True,
        "service": "ulgg-card-detection",
        "ready": (
            hand_detector is not None
            and icon_detector is not None
            and digit_detector is not None
        ),
    }


@app.post("/detect")
async def detect_cards(
    image: UploadFile = File(...),
    skip: str = Form("")
) -> dict[str, Any]:
    if hand_detector is None:
        raise HTTPException(
            status_code=503,
            detail="手牌辨識器尚未初始化"
        )

    content_type = (
        image.content_type or ""
    )

    allowed_types = {
        "image/jpeg",
        "image/png",
        "image/webp",
    }

    if content_type not in allowed_types:
        raise HTTPException(
            status_code=400,
            detail=(
                "僅支援 JPEG、PNG 或 WEBP 圖片"
            )
        )

    raw_bytes = await image.read()

    # 避免玩家上傳過大的完整截圖。
    max_size_bytes = 5 * 1024 * 1024

    if len(raw_bytes) > max_size_bytes:
        raise HTTPException(
            status_code=413,
            detail="圖片超過 5MB"
        )

    frame = decode_uploaded_image(
        raw_bytes
    )

    skip_indices = parse_skip_indices(
        skip
    )

    hand_result = hand_detector.detect(
        frame
    )

    cards: list[dict[str, Any]] = []
    detected_cards: list[dict[str, Any]] = []

    for card_index, card in enumerate(
        hand_result.cards,
        start=1
    ):
        metadata: dict[str, Any] = {
            "card_index": card_index,
            "card_type": card.card_type,
            "classification_confidence": round(
                card.confidence,
                4
            ),
            "x": card.x,
            "y": card.y,
            "width": card.width,
            "height": card.height,
            "skipped": False,
            "skip_reason": None,
        }

        card_image = hand_result.roi[
            card.y:card.y + card.height,
            card.x:card.x + card.width
        ].copy()

        if card_image.size == 0:
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "empty_crop"
            )

            detected_cards.append(
                metadata
            )

            continue

        if card_index in skip_indices:
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "manual_skip"
            )

            detected_cards.append(
                metadata
            )

            continue

        if card.card_type == "personal_event":
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "personal_event"
            )

            detected_cards.append(
                metadata
            )

            continue

        if card.card_type != "public_event":
            metadata["skipped"] = True
            metadata["skip_reason"] = (
                "unsupported_card_type"
            )

            detected_cards.append(
                metadata
            )

            continue

        result = detect_public_card(
            card_image=card_image,
            card_index=card_index
        )

        cards.append(
            result
        )

        detected_cards.append(
            metadata
        )

    result = {
        "success": True,
        "running": True,
        "updated_at": datetime.now().isoformat(
            timespec="seconds"
        ),
        "image": {
            "filename": image.filename,
            "width": int(frame.shape[1]),
            "height": int(frame.shape[0]),
        },
        "hand_count": len(hand_result.cards),
        "public_card_count": len(cards),
        "cards": cards,
        "detected_cards": detected_cards,
    }

    current_state.clear()
    current_state.update(result)

    return result

@app.get("/state")
def get_state() -> dict[str, Any]:
    return current_state