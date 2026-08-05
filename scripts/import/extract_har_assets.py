from __future__ import annotations

import base64
import json
import mimetypes
import re
from pathlib import Path
from urllib.parse import unquote, urlparse

HAR_FILE = Path(__file__).parent / "har" / "play.games.dmm.com2.har"
OUTPUT_DIR = Path(__file__).parent / "data" / "playunlight_assets_DMM2"


def safe_path_from_url(url: str, mime_type: str = "") -> Path | None:
    parsed = urlparse(url)
    path = unquote(parsed.path).lstrip("/")

    if not path:
        return None

    parts = []

    for part in Path(path).parts:
        clean_part = re.sub(r'[<>:"|?*]', "_", part)

        if clean_part in {"", ".", ".."}:
            continue

        parts.append(clean_part)

    if not parts:
        return None

    output_path = Path(*parts)

    # 沒有副檔名時，依 MIME Type 補上
    if not output_path.suffix and mime_type:
        extension = mimetypes.guess_extension(
            mime_type.split(";")[0].strip()
        )

        if extension:
            output_path = output_path.with_suffix(extension)

    return output_path


def extract_har_assets() -> None:
    if not HAR_FILE.exists():
        raise FileNotFoundError(f"找不到 HAR：{HAR_FILE}")

    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)

    with HAR_FILE.open("r", encoding="utf-8") as file:
        har_data = json.load(file)

    entries = har_data.get("log", {}).get("entries", [])

    success_count = 0
    skipped_count = 0
    failed_count = 0

    for entry in entries:
        request = entry.get("request", {})
        response = entry.get("response", {})
        content = response.get("content", {})

        url = request.get("url", "")
        mime_type = content.get("mimeType", "")
        text = content.get("text")

        if not url or text is None:
            skipped_count += 1
            continue

        relative_path = safe_path_from_url(url, mime_type)

        if relative_path is None:
            skipped_count += 1
            continue

        output_file = OUTPUT_DIR / relative_path
        output_file.parent.mkdir(parents=True, exist_ok=True)

        try:
            if content.get("encoding") == "base64":
                file_data = base64.b64decode(text)
                output_file.write_bytes(file_data)
            else:
                output_file.write_text(
                    text,
                    encoding="utf-8",
                    errors="replace"
                )

            print(f"[完成] {relative_path}")
            success_count += 1

        except Exception as error:
            print(f"[失敗] {url}")
            print(f"       {error}")
            failed_count += 1

    print()
    print("解析完成")
    print(f"成功：{success_count}")
    print(f"略過：{skipped_count}")
    print(f"失敗：{failed_count}")
    print(f"輸出：{OUTPUT_DIR}")


if __name__ == "__main__":
    extract_har_assets()