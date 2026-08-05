#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
Import Unlight quest map JSON into MySQL

- Auto locate .env from project root
- Read quest JSON
- Import worlds / regions / missions / mission nodes
- Preserve raw map / lands JSON
- Create node_code records for later mapping
"""

import json
import os
import sys
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
    if v is None or str(v).strip() == "":
        raise RuntimeError(f"Missing env var: {key} (check {ENV_PATH})")
    return v


def mask(s: str) -> str:
    if s is None:
        return "None"
    if len(s) <= 2:
        return "*" * len(s)
    return s[0] + "*" * (len(s) - 2) + s[-1]


def jdump(value) -> str:
    if value is None:
        return None
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"))


def route_name(col_index: int) -> str:
    if col_index == 0:
        return "left"
    if col_index == 1:
        return "mid"
    if col_index == 2:
        return "right"
    raise ValueError(f"Invalid route column: {col_index}")


def get_land_code(quest_map: dict, stage_index: int, route_index: int):
    key = f"land{stage_index}_{route_index}"
    return quest_map.get(key)


def get_land_meta(lands: list, stage_index: int, route_index: int):
    try:
        if not isinstance(lands, list):
            return None
        if stage_index >= len(lands):
            return None
        row = lands[stage_index]
        if not isinstance(row, list):
            return None
        if route_index >= len(row):
            return None
        return row[route_index]
    except Exception:
        return None


# --------------------------------------------------
# Path & ENV
# --------------------------------------------------

SCRIPT_PATH = Path(__file__).resolve()
PROJECT_ROOT = find_project_root(SCRIPT_PATH)
ENV_PATH = PROJECT_ROOT / ".env"
load_dotenv(ENV_PATH)

DEFAULT_JSON_PATH = PROJECT_ROOT / "scripts" / "import" / "import_quests.json"


# --------------------------------------------------
# DB Config
# --------------------------------------------------

def build_db_config() -> dict:
    return {
        "host": require_env("DB_HOST"),
        "port": int(os.getenv("DB_PORT", "3306")),
        "user": require_env("DB_USER"),
        "password": require_env("DB_PASSWORD"),
        "database": require_env("DB_NAME"),
        "charset": "utf8mb4",
        "cursorclass": pymysql.cursors.DictCursor,
        "autocommit": False,
    }


# --------------------------------------------------
# SQL
# --------------------------------------------------

TRUNCATE_SQL = [
    "SET FOREIGN_KEY_CHECKS = 0",
    "TRUNCATE TABLE quest_mission_nodes",
    "TRUNCATE TABLE quest_missions",
    "TRUNCATE TABLE quest_regions",
    "TRUNCATE TABLE quest_worlds",
    "SET FOREIGN_KEY_CHECKS = 1",
]

INSERT_WORLD_SQL = """
INSERT INTO quest_worlds (
  world_key,
  world_name,
  sort_order
) VALUES (
  %(world_key)s,
  %(world_name)s,
  %(sort_order)s
)
ON DUPLICATE KEY UPDATE
  world_name = VALUES(world_name),
  sort_order = VALUES(sort_order)
"""

SELECT_WORLD_ID_SQL = """
SELECT id FROM quest_worlds WHERE world_key = %s LIMIT 1
"""

INSERT_REGION_SQL = """
INSERT INTO quest_regions (
  world_id,
  name_tcn,
  name_ja,
  name_en,
  description_tcn,
  thum,
  guage,
  ap,
  texture_map_x,
  texture_map_y,
  texture_label_x,
  texture_label_y,
  sort_order
) VALUES (
  %(world_id)s,
  %(name_tcn)s,
  %(name_ja)s,
  %(name_en)s,
  %(description_tcn)s,
  %(thum)s,
  %(guage)s,
  %(ap)s,
  %(texture_map_x)s,
  %(texture_map_y)s,
  %(texture_label_x)s,
  %(texture_label_y)s,
  %(sort_order)s
)
"""

INSERT_MISSION_SQL = """
INSERT INTO quest_missions (
  region_id,
  name_tcn,
  name_ja,
  name_en,
  info_tcn,
  ap,
  rarity,
  difficulty,
  is_boss,
  sort_order,
  raw_map_json,
  raw_lands_json
) VALUES (
  %(region_id)s,
  %(name_tcn)s,
  %(name_ja)s,
  %(name_en)s,
  %(info_tcn)s,
  %(ap)s,
  %(rarity)s,
  %(difficulty)s,
  %(is_boss)s,
  %(sort_order)s,
  %(raw_map_json)s,
  %(raw_lands_json)s
)
"""

INSERT_NODE_SQL = """
INSERT INTO quest_mission_nodes (
  mission_id,
  stage_no,
  route_pos,
  node_code,
  battle_name,
  reward_type,
  reward_name,
  is_goal,
  is_boss,
  require_json
) VALUES (
  %(mission_id)s,
  %(stage_no)s,
  %(route_pos)s,
  %(node_code)s,
  %(battle_name)s,
  %(reward_type)s,
  %(reward_name)s,
  %(is_goal)s,
  %(is_boss)s,
  %(require_json)s
)
"""

INSERT_NODE_CODE_SQL = """
INSERT IGNORE INTO quest_node_codes (
  node_code
) VALUES (
  %(node_code)s
)
"""


# --------------------------------------------------
# Main
# --------------------------------------------------

def main():
    json_path = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else DEFAULT_JSON_PATH

    print("🚀 import_quest_json.py 已啟動")
    print("__file__    :", __file__)
    print("cwd         :", os.getcwd())
    print("python      :", sys.executable)
    print("")
    print("== Import Quests ==")
    print("Project root:", PROJECT_ROOT)
    print(".env path   :", ENV_PATH, "(exists)" if ENV_PATH.exists() else "(MISSING)")
    print("JSON path   :", json_path, "(exists)" if json_path.exists() else "(MISSING)")
    print("DB_HOST     :", os.getenv("DB_HOST"))
    print("DB_PORT     :", os.getenv("DB_PORT", "3306"))
    print("DB_USER     :", os.getenv("DB_USER"))
    print("DB_PASSWORD :", mask(os.getenv("DB_PASSWORD")))
    print("DB_NAME     :", os.getenv("DB_NAME"))
    print("")

    if not json_path.exists():
        raise FileNotFoundError(f"JSON not found: {json_path}")

    with open(json_path, "r", encoding="utf-8") as f:
        raw = json.load(f)

    if "frames" not in raw:
        raise RuntimeError("JSON format error: missing 'frames'")

    frames = raw["frames"]
    if not isinstance(frames, list):
        raise RuntimeError("JSON format error: 'frames' must be a list")

    db_config = build_db_config()
    conn = pymysql.connect(**db_config)

    world_count = 0
    region_count = 0
    mission_count = 0
    node_count = 0
    node_code_count = 0
    null_node_count = 0

    try:
        with conn.cursor() as cursor:
            print("清空 quest 匯入資料表...")
            for sql in TRUNCATE_SQL:
                cursor.execute(sql)

            for world_sort, frame in enumerate(frames, start=1):
                world_key = frame.get("map")
                if not world_key:
                    print("[SKIP WORLD] missing map")
                    continue

                world_data = {
                    "world_key": world_key,
                    "world_name": world_key,
                    "sort_order": world_sort,
                }

                cursor.execute(INSERT_WORLD_SQL, world_data)
                cursor.execute(SELECT_WORLD_ID_SQL, (world_key,))
                world_row = cursor.fetchone()

                if not world_row:
                    raise RuntimeError(f"Cannot get world_id: {world_key}")

                world_id = world_row["id"]
                world_count += 1

                regions = frame.get("region", [])
                if not isinstance(regions, list):
                    print(f"[SKIP REGIONS] world={world_key}, region is not list")
                    continue

                for region_sort, region in enumerate(regions, start=1):
                    texture_map = region.get("texture_map") or {}
                    texture_label = region.get("texture_label") or {}

                    region_data = {
                        "world_id": world_id,
                        "name_tcn": region.get("name_tcn") or region.get("name_ja") or region.get("name_en") or "(未命名區域)",
                        "name_ja": region.get("name_ja"),
                        "name_en": region.get("name_en"),
                        "description_tcn": region.get("info_tcn") or region.get("description_tcn"),
                        "thum": region.get("thum"),
                        "guage": region.get("guage"),
                        "ap": region.get("ap"),
                        "texture_map_x": texture_map.get("x"),
                        "texture_map_y": texture_map.get("y"),
                        "texture_label_x": texture_label.get("x"),
                        "texture_label_y": texture_label.get("y"),
                        "sort_order": region_sort,
                    }

                    cursor.execute(INSERT_REGION_SQL, region_data)
                    region_id = cursor.lastrowid
                    region_count += 1

                    quests = region.get("quests", [])
                    if not isinstance(quests, list):
                        print(f"[SKIP QUESTS] region={region_data['name_tcn']}, quests is not list")
                        continue

                    for mission_sort, quest in enumerate(quests, start=1):
                        quest_map = quest.get("map") or {}
                        lands = quest.get("lands") or []

                        mission_data = {
                            "region_id": region_id,
                            "name_tcn": quest.get("name_tcn") or quest.get("name_ja") or quest.get("name_en") or "(未命名任務)",
                            "name_ja": quest.get("name_ja"),
                            "name_en": quest.get("name_en"),
                            "info_tcn": quest.get("info_tcn"),
                            "ap": quest.get("ap"),
                            "rarity": quest.get("rarity"),
                            "difficulty": quest.get("difficulty"),
                            "is_boss": 1 if quest.get("boss") else 0,
                            "sort_order": mission_sort,
                            "raw_map_json": jdump(quest_map),
                            "raw_lands_json": jdump(lands),
                        }

                        cursor.execute(INSERT_MISSION_SQL, mission_data)
                        mission_id = cursor.lastrowid
                        mission_count += 1

                        # 原始資料目前固定看 land0~land4，每階 left/mid/right
                        for stage_index in range(5):
                            stage_no = stage_index + 1

                            for route_index in range(3):
                                node_code = get_land_code(quest_map, stage_index, route_index)
                                land_meta = get_land_meta(lands, stage_index, route_index)

                                # 完全空格子就不匯入
                                if node_code is None and land_meta is None:
                                    null_node_count += 1
                                    continue

                                is_goal = 0
                                is_boss = 0
                                require_json = None

                                if isinstance(land_meta, dict):
                                    is_goal = 1 if land_meta.get("goal") else 0
                                    is_boss = 1 if land_meta.get("boss") else 0
                                    require_json = jdump(land_meta.get("require"))

                                node_data = {
                                    "mission_id": mission_id,
                                    "stage_no": stage_no,
                                    "route_pos": route_name(route_index),
                                    "node_code": node_code,
                                    "battle_name": None,
                                    "reward_type": None,
                                    "reward_name": None,
                                    "is_goal": is_goal,
                                    "is_boss": is_boss,
                                    "require_json": require_json,
                                }

                                cursor.execute(INSERT_NODE_SQL, node_data)
                                node_count += 1

                                if node_code is not None:
                                    cursor.execute(INSERT_NODE_CODE_SQL, {"node_code": node_code})
                                    if cursor.rowcount == 1:
                                        node_code_count += 1

                        print(f"[MISSION] {world_key} > {region_data['name_tcn']} > {mission_data['name_tcn']}")

        conn.commit()

        print("")
        print("== Import Summary ==")
        print(f"worlds       : {world_count}")
        print(f"regions      : {region_count}")
        print(f"missions     : {mission_count}")
        print(f"nodes        : {node_count}")
        print(f"new codes    : {node_code_count}")
        print(f"empty cells  : {null_node_count}")
        print("DONE")

    except Exception:
        conn.rollback()
        print("ERROR: rollback")
        raise

    finally:
        conn.close()


if __name__ == "__main__":
    main()