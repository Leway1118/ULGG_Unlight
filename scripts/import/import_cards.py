#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
Import Unlight card data from JSON into MySQL

- Auto locate .env from project root
- Read import_cards.json
- Insert / Update card base data + SLOT
"""

import json
import os
import sys
print("🚀 import_cards.py 已啟動")
print("__file__ =", __file__)
print("cwd =", os.getcwd())
print("python =", sys.executable)
import hashlib   # ← 加這行
from pathlib import Path

import pymysql
from dotenv import load_dotenv


# --------------------------------------------------
# Helpers
# --------------------------------------------------

def find_project_root(start: Path) -> Path:
    """
    Walk upward to find project root that contains .env.
    Fallback to parents[2] if not found.
    """
    p = start
    for _ in range(8):  # usually enough for deep paths
        if (p / ".env").exists():
            return p
        p = p.parent
    # fallback (your original assumption)
    return start.parents[2]


def require_env(key: str) -> str:
    v = os.getenv(key)
    if v is None or str(v).strip() == "":
        raise RuntimeError(f"Missing env var: {key} (check {ENV_PATH})")
    return v


def mask(s: str) -> str:
    if s is None:
        return "None"
    if len(s) <= 2:
        return "*" * len(s)
    return s[0] + "*" * (len(s) - 2) + s[-1]


# --------------------------------------------------
# Path & ENV
# --------------------------------------------------

SCRIPT_PATH = Path(__file__).resolve()
PROJECT_ROOT = find_project_root(SCRIPT_PATH)
ENV_PATH = PROJECT_ROOT / ".env"
load_dotenv(ENV_PATH)


# --------------------------------------------------
# File Path (default)
# --------------------------------------------------

DEFAULT_JSON_PATH = PROJECT_ROOT / "scripts" / "import" / "import_cards.json"


# --------------------------------------------------
# DB Config
# --------------------------------------------------

def build_db_config() -> dict:
    host = require_env("DB_HOST")
    port = int(os.getenv("DB_PORT", "3306"))
    user = require_env("DB_USER")
    passwd = require_env("DB_PASSWORD")
    db = require_env("DB_NAME")

    return {
        "host": host,
        "port": port,
        "user": user,
        "password": passwd,
        "database": db,
        "charset": "utf8mb4",
        "cursorclass": pymysql.cursors.DictCursor,
    }


# --------------------------------------------------
# SQL
# --------------------------------------------------
INSERT_SQL = """
INSERT INTO unlight (
    id,
    chara_code,
    filename,
    official_level,
    rarity,
    HP,
    ATK,
    DEF,
    cost,
    slot_1,
    slot_2,
    slot_3,
    slot_4,
    slot_5,
    slot_6,
    skill1_code,
    skill2_code,
    skill3_code,
    skill4_code,
    source_hash
) VALUES (
    %(id)s,
    %(chara_code)s,
    %(filename)s,
    %(official_level)s,
    %(rarity)s,
    %(HP)s,
    %(ATK)s,
    %(DEF)s,
    %(cost)s,
    %(slot_1)s,
    %(slot_2)s,
    %(slot_3)s,
    %(slot_4)s,
    %(slot_5)s,
    %(slot_6)s,
    %(skill1_code)s,
    %(skill2_code)s,
    %(skill3_code)s,
    %(skill4_code)s,
    %(source_hash)s
)
ON DUPLICATE KEY UPDATE
    chara_code      = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(chara_code), chara_code),
    filename        = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(filename), filename),
    official_level  = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(official_level), official_level),
    rarity          = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(rarity), rarity),

    HP              = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(HP), HP),
    ATK             = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(ATK), ATK),
    DEF             = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(DEF), DEF),
    cost            = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(cost), cost),

    slot_1          = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(slot_1), slot_1),
    slot_2          = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(slot_2), slot_2),
    slot_3          = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(slot_3), slot_3),
    slot_4          = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(slot_4), slot_4),
    slot_5          = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(slot_5), slot_5),
    slot_6          = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(slot_6), slot_6),

    skill1_code     = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(skill1_code), skill1_code),
    skill2_code     = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(skill2_code), skill2_code),
    skill3_code     = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(skill3_code), skill3_code),
    skill4_code     = IF(NOT (source_hash <=> VALUES(source_hash)), VALUES(skill4_code), skill4_code),

    source_hash     = VALUES(source_hash);




"""



# --------------------------------------------------
# Main
# --------------------------------------------------

def main():
    # allow override json path from cli:
    # python import_cards.py D:\path\to\import_cards.json
    json_path = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else DEFAULT_JSON_PATH

    print("== Import Cards ==")
    print("Project root :", PROJECT_ROOT)
    print(".env path    :", ENV_PATH, "(exists)" if ENV_PATH.exists() else "(MISSING)")
    print("JSON path    :", json_path, "(exists)" if json_path.exists() else "(MISSING)")

    # quick env diagnostics (do NOT print real password)
    print("DB_HOST      :", os.getenv("DB_HOST"))
    print("DB_PORT      :", os.getenv("DB_PORT", "3306"))
    print("DB_USER      :", os.getenv("DB_USER"))
    print("DB_PASSWORD      :", mask(os.getenv("DB_PASSWORD")))
    print("DB_NAME      :", os.getenv("DB_NAME"))
    print("")

    if not json_path.exists():
        raise FileNotFoundError(f"JSON not found: {json_path}")

    db_config = build_db_config()

    with open(json_path, "r", encoding="utf-8") as f:
        raw = json.load(f)

    if "frames" not in raw:
        raise RuntimeError("JSON format error: missing 'frames'")

    frames = raw["frames"]

    if not isinstance(frames, list):
        raise RuntimeError("JSON format error: 'frames' must be a list")

    cards = frames

    print("cards type:", type(cards))
    print("sample item:", next(iter(cards)))
    print(f"Loaded cards : {len(cards)}")

    conn = pymysql.connect(**db_config)

    try:
        inserted = updated = unchanged = 0

        with conn.cursor() as cursor:
            inserted = updated = unchanged = 0
            
            for card in cards:
                if "id" not in card :
                    continue

                # ① 先算 slot
                slot = card.get("slot", [])
                slot += [7] * (6 - len(slot))

                # ② 再算 hash（一定要用「補齊後的 slot」）
                hash_payload = {
                    "hp": card["hp"],
                    "atk": card["atk"],
                    "def": card["def"],
                    "cost": card["cost"],
                    "slot": slot,
                    "skill1": card.get("skill1"),
                    "skill2": card.get("skill2"),
                    "skill3": card.get("skill3"),
                    "skill4": card.get("skill4"),
                }

                raw_hash = hashlib.md5(
                    json.dumps(hash_payload, sort_keys=True, ensure_ascii=False).encode("utf-8")
                ).hexdigest()

                # ③ 組 data
                data = {
                    "id": card["id"],
                    "chara_code": card["chara"],
                    "filename": card["filename"],
                    "official_level": card["level"],
                    "rarity": card["rarity"],
                    "HP": card["hp"],
                    "ATK": card["atk"],
                    "DEF": card["def"],
                    "cost": card["cost"],
                    "slot_1": slot[0],
                    "slot_2": slot[1],
                    "slot_3": slot[2],
                    "slot_4": slot[3],
                    "slot_5": slot[4],
                    "slot_6": slot[5],
                    "skill1_code": card.get("skill1"),
                    "skill2_code": card.get("skill2"),
                    "skill3_code": card.get("skill3"),
                    "skill4_code": card.get("skill4"),
                    "source_hash": raw_hash,
                }


                # ④ 只 execute 一次
                cursor.execute(INSERT_SQL, data)

                affected = cursor.rowcount
                if affected == 1:
                    inserted += 1
                    print(f"[INSERT] id={card['id']}")
                elif affected == 2:
                    updated += 1
                    print(f"[UPDATE] id={card['id']} (META changed)")
                else:
                    unchanged += 1
                    print(f"[NO CHANGE] id={card['id']}")
        
        
        
        
        conn.commit()
        print(f"Summary: INSERT={inserted}, UPDATE={updated}, NO_CHANGE={unchanged}")


    finally:
        conn.close()
    


if __name__ == "__main__":
    main()
