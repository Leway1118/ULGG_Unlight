from __future__ import annotations

from contextlib import asynccontextmanager
from pathlib import Path
from typing import Any

import cv2
import numpy as np
from fastapi import (
    FastAPI,
    File,
    Form,
    HTTPException,
    UploadFile,
)
from fastapi.responses import HTMLResponse
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
UPPER_ICON_ROI = (25, 10, 57, 48)

LOWER_ICON_ROI = (0, 39, 37, 85)
LOWER_DIGIT_ROI = (32, 47, 57, 90)


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


ALLOWED_TEMPLATE_TYPES = {
    "digit",
    "icon",
    "personal_event",
    "card_back",
    "battle_result",
    "ok_button",
}

ALLOWED_DIGIT_POSITIONS = {
    "upper",
    "lower",
}

ALLOWED_ICON_LABELS = {
    "sword",
    "gun",
    "shield",
    "special",
    "move",
}

ALLOWED_BATTLE_RESULTS = {
    "win",
    "lose",
    "tie",
    "timeout",
}

ALLOWED_OK_STATES = {
    "locked",
    "unlocked",
}

ALLOWED_SOURCES = {
    "hand",
    "player_play",
    "opponent",
    "live",
    "manual",
}


def sanitize_source(
    source: str,
) -> str:
    normalized = (
        source.strip()
        .lower()
        .replace("-", "_")
        .replace(" ", "_")
    )

    if normalized not in ALLOWED_SOURCES:
        return "manual"

    return normalized


def resolve_template_destination(
    template_type: str,
    label: str,
    position: str,
    source: str,
) -> tuple[Path, str]:
    template_type = (
        template_type.strip().lower()
    )

    label = label.strip().lower()
    position = position.strip().lower()
    source = sanitize_source(source)

    timestamp = datetime.now().strftime(
        "%Y%m%d_%H%M%S_%f"
    )

    if template_type == "digit":
        if position not in ALLOWED_DIGIT_POSITIONS:
            raise HTTPException(
                status_code=400,
                detail="數字模板位置必須是 upper 或 lower",
            )

        if not label.isdigit():
            raise HTTPException(
                status_code=400,
                detail="數字模板標籤必須是數字",
            )

        value = int(label)

        if not 0 <= value <= 9:
            raise HTTPException(
                status_code=400,
                detail="數字模板只接受 0～9",
            )

        folder = (
            ROOT
            / "templates"
            / "card-digits-runtime"
            / position
        )

        filename = (
            f"{value}_"
            f"{source}_"
            f"{timestamp}.png"
        )

        return folder, filename

    if template_type == "icon":
        if label not in ALLOWED_ICON_LABELS:
            raise HTTPException(
                status_code=400,
                detail=(
                    "圖示標籤必須是 "
                    "sword、gun、shield、special 或 move"
                ),
            )

        folder = (
            ROOT
            / "templates"
            / "card-icons"
        )

        filename = (
            f"{label}_"
            f"{source}_"
            f"{timestamp}.png"
        )

        return folder, filename

    if template_type == "personal_event":
        folder = (
            ROOT
            / "templates"
            / "personal-events"
        )

        filename = (
            f"event_"
            f"{source}_"
            f"{timestamp}.png"
        )

        return folder, filename

    if template_type == "card_back":
        folder = (
            ROOT
            / "templates"
            / "card-back"
        )

        filename = (
            f"card_back_"
            f"{source}_"
            f"{timestamp}.png"
        )

        return folder, filename

    if template_type == "battle_result":
        if label not in ALLOWED_BATTLE_RESULTS:
            raise HTTPException(
                status_code=400,
                detail=(
                    "對戰結果標籤必須是 "
                    "win、lose、tie 或 timeout"
                ),
            )

        folder = (
            ROOT
            / "templates"
            / "battle-result"
            / label
        )

        filename = (
            f"{label}_"
            f"{source}_"
            f"{timestamp}.png"
        )

        return folder, filename

    if template_type == "ok_button":
        if label not in ALLOWED_OK_STATES:
            raise HTTPException(
                status_code=400,
                detail=(
                    "按鈕標籤必須是 "
                    "locked 或 unlocked"
                ),
            )

        folder = (
            ROOT
            / "templates"
            / "ok-button"
            / label
        )

        filename = (
            f"{label}_"
            f"{source}_"
            f"{timestamp}.png"
        )

        return folder, filename

    raise HTTPException(
        status_code=400,
        detail="不支援的模板類型",
    )


def save_template_image(
    image: np.ndarray,
    destination: Path,
) -> None:
    if image is None or image.size == 0:
        raise HTTPException(
            status_code=400,
            detail="圖片內容為空",
        )

    destination.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    success, encoded = cv2.imencode(
        ".png",
        image,
    )

    if not success:
        raise HTTPException(
            status_code=500,
            detail="PNG 編碼失敗",
        )

    try:
        encoded.tofile(
            str(destination)
        )

    except OSError as error:
        raise HTTPException(
            status_code=500,
            detail=f"模板儲存失敗：{error}",
        ) from error

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

@app.post("/template/upload")
async def upload_template(
    image: UploadFile = File(...),
    template_type: str = Form(...),
    label: str = Form(""),
    position: str = Form(""),
    source: str = Form("manual"),
) -> dict[str, Any]:
    content_type = (
        image.content_type or ""
    )

    allowed_content_types = {
        "image/png",
        "image/jpeg",
        "image/webp",
    }

    if content_type not in allowed_content_types:
        raise HTTPException(
            status_code=400,
            detail="只接受 PNG、JPEG 或 WEBP",
        )

    raw_bytes = await image.read()

    max_size_bytes = (
        5 * 1024 * 1024
    )

    if len(raw_bytes) > max_size_bytes:
        raise HTTPException(
            status_code=413,
            detail="圖片不可超過 5MB",
        )

    decoded_image = decode_uploaded_image(
        raw_bytes
    )

    folder, filename = (
        resolve_template_destination(
            template_type=template_type,
            label=label,
            position=position,
            source=source,
        )
    )

    destination = (
        folder / filename
    )

    save_template_image(
        image=decoded_image,
        destination=destination,
    )

    relative_path = destination.relative_to(
        ROOT
    )

    return {
        "success": True,
        "message": "模板已儲存",
        "template_type": template_type,
        "label": label,
        "position": position,
        "source": source,
        "path": str(relative_path),
        "restart_required": True,
    }
    
@app.get(
    "/template-manager",
    response_class=HTMLResponse,
)
    
    
def template_manager() -> HTMLResponse:
    html = """
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>UL.GG 模板管理器</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(
                    circle at top,
                    #27334b 0,
                    #111722 45%,
                    #090d14 100%
                );
            color: #edf2f7;
            font-family:
                "Segoe UI",
                "Microsoft JhengHei",
                sans-serif;
        }

        .page {
            width: min(920px, calc(100% - 32px));
            margin: 0 auto;
            padding: 36px 0 60px;
        }

        .header {
            margin-bottom: 24px;
        }

        .title {
            margin: 0;
            font-size: 28px;
        }

        .subtitle {
            margin-top: 8px;
            color: #9eabc0;
            line-height: 1.7;
        }

        .card {
            padding: 24px;
            border: 1px solid #334158;
            border-radius: 16px;
            background: rgba(17, 24, 36, 0.94);
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.28);
        }

        .form-grid {
            display: grid;
            grid-template-columns:
                repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field-full {
            grid-column: 1 / -1;
        }

        label {
            color: #cbd5e1;
            font-size: 14px;
            font-weight: 600;
        }

        select,
        input,
        button {
            min-height: 44px;
            border-radius: 9px;
            font: inherit;
        }

        select,
        input {
            width: 100%;
            border: 1px solid #46556d;
            background: #0e1521;
            color: #edf2f7;
            padding: 9px 12px;
        }

        input[type="file"] {
            padding: 8px;
        }

        .preview-wrap {
            display: none;
            margin-top: 16px;
            padding: 14px;
            border: 1px dashed #46556d;
            border-radius: 12px;
            background: #0b111b;
        }

        .preview {
            display: block;
            max-width: 100%;
            max-height: 360px;
            margin: 0 auto;
            image-rendering: auto;
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 22px;
        }

        button {
            border: 0;
            padding: 0 22px;
            background: #4f8cff;
            color: white;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            filter: brightness(1.08);
        }

        button:disabled {
            opacity: 0.55;
            cursor: wait;
        }

        .status {
            min-height: 24px;
            margin-top: 20px;
            padding: 14px;
            border-radius: 10px;
            background: #0d1420;
            color: #aebbd0;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .status.success {
            border: 1px solid #23875d;
            color: #7ee2b8;
        }

        .status.error {
            border: 1px solid #a94452;
            color: #ff9aa7;
        }

        .hint {
            color: #8391a7;
            font-size: 13px;
            line-height: 1.6;
        }

        @media (max-width: 650px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .field-full {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>
<div class="page">
    <header class="header">
        <h1 class="title">UL.GG 模板管理器</h1>

        <div class="subtitle">
            開發者本機工具。上傳圖片後會直接寫入
            detector/templates 對應資料夾。
        </div>
    </header>

    <main class="card">
        <form id="templateForm">
            <div class="form-grid">
                <div class="field">
                    <label for="templateType">模板類型</label>

                    <select
                        id="templateType"
                        name="template_type"
                    >
                        <option value="digit">卡片數字</option>
                        <option value="icon">卡片圖示</option>
                        <option value="personal_event">
                            個人事件卡
                        </option>
                        <option value="card_back">牌背</option>
                        <option value="battle_result">
                            對戰結果
                        </option>
                        <option value="ok_button">
                            OK／鎖鏈按鈕
                        </option>
                    </select>
                </div>

                <div class="field" id="labelField">
                    <label for="label">正確標籤</label>

                    <select
                        id="label"
                        name="label"
                    ></select>
                </div>

                <div class="field" id="positionField">
                    <label for="position">位置</label>

                    <select
                        id="position"
                        name="position"
                    >
                        <option value="upper">上方 upper</option>
                        <option value="lower">下方 lower</option>
                    </select>
                </div>

                <div class="field">
                    <label for="source">圖片來源</label>

                    <select
                        id="source"
                        name="source"
                    >
                        <option value="hand">手牌 hand</option>
                        <option value="player_play">
                            我方出牌 player_play
                        </option>
                        <option value="opponent">
                            敵方出牌 opponent
                        </option>
                        <option value="live">即時畫面 live</option>
                        <option value="manual">手動 manual</option>
                    </select>
                </div>

                <div class="field field-full">
                    <label for="image">選擇圖片</label>

                    <input
                        id="image"
                        name="image"
                        type="file"
                        accept="image/png,image/jpeg,image/webp"
                        required
                    >

                    <div class="hint">
                        上傳後統一轉為 PNG。圖片上限 5MB。
                    </div>
                </div>
            </div>

            <div
                id="previewWrap"
                class="preview-wrap"
            >
                <img
                    id="preview"
                    class="preview"
                    alt="圖片預覽"
                >
            </div>

            <div class="actions">
                <button
                    id="submitButton"
                    type="submit"
                >
                    儲存模板
                </button>
            </div>

            <div
                id="status"
                class="status"
            >
                尚未上傳
            </div>
        </form>
    </main>
</div>

<script>
const templateType = document.getElementById(
    "templateType"
);

const labelField = document.getElementById(
    "labelField"
);

const labelSelect = document.getElementById(
    "label"
);

const positionField = document.getElementById(
    "positionField"
);

const positionSelect = document.getElementById(
    "position"
);

const imageInput = document.getElementById(
    "image"
);

const previewWrap = document.getElementById(
    "previewWrap"
);

const preview = document.getElementById(
    "preview"
);

const form = document.getElementById(
    "templateForm"
);

const submitButton = document.getElementById(
    "submitButton"
);

const statusBox = document.getElementById(
    "status"
);

const labelOptions = {
    digit: [
        ["0", "數字 0"],
        ["1", "數字 1"],
        ["2", "數字 2"],
        ["3", "數字 3"],
        ["4", "數字 4"],
        ["5", "數字 5"],
        ["6", "數字 6"],
        ["7", "數字 7"],
        ["8", "數字 8"],
        ["9", "數字 9"],
    ],

    icon: [
        ["sword", "劍 sword"],
        ["gun", "槍 gun"],
        ["shield", "盾 shield"],
        ["special", "特 special"],
        ["move", "移 move"],
    ],

    battle_result: [
        ["win", "勝利 win"],
        ["lose", "敗北 lose"],
        ["tie", "平手 tie"],
        ["timeout", "時間到 timeout"],
    ],

    ok_button: [
        ["unlocked", "OK unlocked"],
        ["locked", "鎖鏈 locked"],
    ],
};

function refreshFields() {
    const type = templateType.value;

    labelSelect.innerHTML = "";

    const options = labelOptions[type] || [];

    for (const [value, text] of options) {
        const option = document.createElement(
            "option"
        );

        option.value = value;
        option.textContent = text;

        labelSelect.appendChild(
            option
        );
    }

    const needsLabel = [
        "digit",
        "icon",
        "battle_result",
        "ok_button",
    ].includes(type);

    labelField.style.display = (
        needsLabel ? "flex" : "none"
    );

    positionField.style.display = (
        type === "digit"
            ? "flex"
            : "none"
    );

    if (!needsLabel) {
        labelSelect.value = "";
    }

    if (type !== "digit") {
        positionSelect.value = "";
    }
}

templateType.addEventListener(
    "change",
    refreshFields
);

imageInput.addEventListener(
    "change",
    () => {
        const file = imageInput.files[0];

        if (!file) {
            previewWrap.style.display = "none";
            preview.removeAttribute("src");
            return;
        }

        preview.src = URL.createObjectURL(
            file
        );

        previewWrap.style.display = "block";
    }
);

form.addEventListener(
    "submit",
    async (event) => {
        event.preventDefault();

        const file = imageInput.files[0];

        if (!file) {
            statusBox.className = "status error";
            statusBox.textContent = "請先選擇圖片";
            return;
        }

        submitButton.disabled = true;
        statusBox.className = "status";
        statusBox.textContent = "正在儲存模板……";

        const formData = new FormData();

        formData.append(
            "image",
            file
        );

        formData.append(
            "template_type",
            templateType.value
        );

        formData.append(
            "label",
            labelSelect.value || ""
        );

        formData.append(
            "position",
            positionSelect.value || ""
        );

        formData.append(
            "source",
            document.getElementById(
                "source"
            ).value
        );

        try {
            const response = await fetch(
                "/template/upload",
                {
                    method: "POST",
                    body: formData,
                }
            );

            const result = await response.json();

            if (!response.ok) {
                throw new Error(
                    result.detail
                    || "模板儲存失敗"
                );
            }

            statusBox.className = "status success";
            statusBox.textContent = (
                "模板已儲存：\\n"
                + result.path
                + "\\n\\n"
                + "重新啟動辨識器後生效。"
            );

        } catch (error) {
            statusBox.className = "status error";
            statusBox.textContent = (
                error.message
                || "發生未知錯誤"
            );

        } finally {
            submitButton.disabled = false;
        }
    }
);

refreshFields();
</script>
</body>
</html>
    """

    return HTMLResponse(
        content=html,
        status_code=200,
    )