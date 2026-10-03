"""
story_summary.py
讀取 story_md/all_config.json，將角色名稱、標題、故事內容寫入 story_summary.md
"""

import json
import os

CONFIG_PATH = "story_md/all_config.json"
OUTPUT_PATH = "story_summary.md"
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def load_config(path: str) -> dict:
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def read_story(path: str) -> str:
    full_path = os.path.join(BASE_DIR, path)
    if not os.path.exists(full_path):
        return "_（檔案不存在）_"
    with open(full_path, encoding="utf-8") as f:
        return f.read().strip()


def main():
    config_full = os.path.join(BASE_DIR, CONFIG_PATH)
    config = load_config(config_full)

    output_lines = ["# 故事總覽\n"]

    for character, stories in config.items():
        # 跳過空陣列的分節標題（如「活動故事」、「人物」等）
        if not stories:
            continue

        output_lines.append(f"## {character}\n")

        for story in stories:
            title = story.get("title", "（無標題）")
            path = story.get("path", "")

            output_lines.append(f"### {title}\n")

            if path:
                path_tcn = path.replace("_ja.md", "_tcn.md") if path.endswith("_ja.md") else path
                path_ja = path.replace("_tcn.md", "_ja.md") if path.endswith("_tcn.md") else path

                content_tcn = read_story(path_tcn)
                output_lines.append("#### 繁中")
                output_lines.append(content_tcn)

                if path_ja != path_tcn:
                    content_ja = read_story(path_ja)
                    output_lines.append("\n#### 日文")
                    output_lines.append(content_ja)
            else:
                output_lines.append("_（無故事路徑）_")

            output_lines.append("\n---\n")

        output_lines.append("")

    output_full = os.path.join(BASE_DIR, OUTPUT_PATH)
    with open(output_full, "w", encoding="utf-8") as f:
        f.write("\n".join(output_lines))

    print(f"已寫入：{output_full}")


if __name__ == "__main__":
    main()
