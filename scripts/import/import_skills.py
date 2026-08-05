#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
Import UNLIGHT base skills from card JSON into unlight_skill table

- Read .env from project root
- Read import_cards.json
- Extract base skills (base1 ~ base4)
- Deduplicate by skill_code
- Insert / Update unlight_skill
"""

import json
import os
import sys
import hashlib
from pathlib import Path

import pymysql
from dotenv import load_dotenv


# --------------------------------------------------
# Helpers
# --------------------------------------------------

def find_project_root(start: Path) -> Path:
    p = start
    for _ in range(8):
        if (p / ".env").exists():
            return p
        p = p.parent
    return start.parents[2]


def require_env(key: str) -> str:
    v = os.getenv(key)
    if not v or not str(v).strip():
        raise RuntimeError(f"Missing env var: {key}")
    return v


def mask(s: str) -> str:
    if not s:
        return "None"
    return s[0] + "*" * (len(s) - 2) + s[-1]


# --------------------------------------------------
# Path & ENV
# --------------------------------------------------

SCRIPT_PATH = Path(__file__).resolve()
PROJECT_ROOT = find_project_root(SCRIPT_PATH)
ENV_PATH = PROJECT_ROOT / ".env"
load_dotenv(ENV_PATH)

DEFAULT_JSON_PATH = PROJECT_ROOT / "scripts" / "import" / "import_cards.json"


# --------------------------------------------------
# DB Config
# --------------------------------------------------

DB_CONFIG = {
    "host": require_env("DB_HOST"),
    "port": int(os.getenv("DB_PORT", 3306)),
    "user": require_env("DB_USER"),
    "password": require_env("DB_PASSWORD"),
    "database": require_env("DB_NAME"),
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor,
}


# --------------------------------------------------
# SQL
# --------------------------------------------------
INSERT_SQL = """
INSERT INTO unlight_skill (
    skill_code,
    source_card_id,
    phase,
    range_mask,
    require_json,
    name_tcn,
    info_tcn,
    name_ja,
    info_ja,
    name_en,
    info_en,
    name_kr,
    info_kr,
    name_scn,
    info_scn,
    source_hash
) VALUES (
    %(skill_code)s,
    %(source_card_id)s,
    %(phase)s,
    %(range_mask)s,
    %(require_json)s,
    %(name_tcn)s,
    %(info_tcn)s,
    %(name_ja)s,
    %(info_ja)s,
    %(name_en)s,
    %(info_en)s,
    %(name_kr)s,
    %(info_kr)s,
    %(name_scn)s,
    %(info_scn)s,
    %(source_hash)s
)
ON DUPLICATE KEY UPDATE
    source_card_id = IF(
        source_card_id IS NULL,
        VALUES(source_card_id),
        source_card_id
    ),
    name_tcn = IF(source_hash <> VALUES(source_hash), VALUES(name_tcn), name_tcn),
    info_tcn = IF(source_hash <> VALUES(source_hash), VALUES(info_tcn), info_tcn),
    name_ja  = IF(source_hash <> VALUES(source_hash), VALUES(name_ja),  name_ja),
    info_ja  = IF(source_hash <> VALUES(source_hash), VALUES(info_ja),  info_ja),
    name_en  = IF(source_hash <> VALUES(source_hash), VALUES(name_en),  name_en),
    info_en  = IF(source_hash <> VALUES(source_hash), VALUES(info_en),  info_en),
    name_kr  = IF(source_hash <> VALUES(source_hash), VALUES(name_kr),  name_kr),
    info_kr  = IF(source_hash <> VALUES(source_hash), VALUES(info_kr),  info_kr),
    name_scn = IF(source_hash <> VALUES(source_hash), VALUES(name_scn), name_scn),
    info_scn = IF(source_hash <> VALUES(source_hash), VALUES(info_scn), info_scn),
    source_hash = VALUES(source_hash);


"""



# --------------------------------------------------
# Main
# --------------------------------------------------

def main():
    json_path = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else DEFAULT_JSON_PATH

    print("== Import Skills ==")
    print("Project root :", PROJECT_ROOT)
    print(".env path    :", ENV_PATH)
    print("JSON path    :", json_path)
    print("DB_HOST      :", os.getenv("DB_HOST"))
    print("DB_USER      :", os.getenv("DB_USER"))
    print("DB_PASSWORD  :", mask(os.getenv("DB_PASSWORD")))
    print("DB_NAME      :", os.getenv("DB_NAME"))
    print("")

    with open(json_path, "r", encoding="utf-8") as f:
        raw = json.load(f)

    if "frames" not in raw or not isinstance(raw["frames"], list):
        raise RuntimeError("JSON format error: frames must be a list")

    cards = raw["frames"]

    def build_range_mask(ranges):
        if not ranges:
            return None

        allowed = {"0", "1", "2"}
        vals = sorted({str(r) for r in ranges if str(r) in allowed})

        if not vals:
            return None

        return ",".join(vals)



    skills = {}

    for card in cards:
        # ❌ 跳過非正式卡（id 不存在 或 id <= 0）
        if "id" not in card or card["id"] <= 0:
            continue
        
        for idx in range(1, 5):
            skill_key = f"skill{idx}"
            skill_code = card.get(skill_key)

            if not skill_code:
                continue

            if skill_code in skills:
                # print(f"[SKIP] duplicate skill {skill_code}")
                continue

            req_block = card.get(f"skill{idx}_require") or {}            
            req = req_block.get("require")
            
            # ----------------------------
            # 組 skill 專屬 payload
            # ----------------------------
            source_payload = {
                "skill_code": skill_code,
                "phase": req_block.get("phase"),
                "range": build_range_mask(req_block.get("range")),
                "require": req,
                "name": {
                    "tcn": card.get(f"skill{idx}_tcn"),
                    "ja":  card.get(f"skill{idx}_ja"),
                    "en":  card.get(f"skill{idx}_en"),
                    "kr":  card.get(f"skill{idx}_kr"),
                    "scn": card.get(f"skill{idx}_scn"),
                },
                "info": {
                    "tcn": card.get(f"skill{idx}_info_tcn"),
                    "ja":  card.get(f"skill{idx}_info_ja"),
                    "en":  card.get(f"skill{idx}_info_en"),
                    "kr":  card.get(f"skill{idx}_info_kr"),
                    "scn": card.get(f"skill{idx}_info_scn"),
                }
            }

            canonical = json.dumps(source_payload, sort_keys=True, ensure_ascii=False)
            source_hash = hashlib.sha256(canonical.encode("utf-8")).hexdigest()


            skills[skill_code] = {
                "skill_code": skill_code,
                "source_card_id": card.get("id"),

                # ===== 新增欄位 =====
                "phase": req_block.get("phase"),
                "range_mask": build_range_mask(req_block.get("range")),
                "require_json": (
                    json.dumps(req, ensure_ascii=False)
                    if req is not None
                    else None
                ),

                # ===== 多語系 =====
                "name_tcn": card.get(f"skill{idx}_tcn"),
                "info_tcn": card.get(f"skill{idx}_info_tcn"),
                "name_ja":  card.get(f"skill{idx}_ja"),
                "info_ja":  card.get(f"skill{idx}_info_ja"),
                "name_en":  card.get(f"skill{idx}_en"),
                "info_en":  card.get(f"skill{idx}_info_en"),
                "name_kr":  card.get(f"skill{idx}_kr"),
                "info_kr":  card.get(f"skill{idx}_info_kr"),
                "name_scn": card.get(f"skill{idx}_scn"),
                "info_scn": card.get(f"skill{idx}_info_scn"),
                "source_hash": source_hash,
            }

    print(f"Extracted skills : {len(skills)}")

    conn = pymysql.connect(**DB_CONFIG)
    try:
        with conn.cursor() as cursor:
            for row in skills.values():
                cursor.execute(INSERT_SQL, row)
        conn.commit()
        print("✅ Skill import completed successfully")
    finally:
        conn.close()


if __name__ == "__main__":
    main()
