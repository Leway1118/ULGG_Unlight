#!/usr/bin/env python3
# -*- coding: utf-8 -*-

"""
Import Unlight quest TXT details into MySQL

- Read scripts/import/import_quest_details.txt
- Parse:
  World
    Region
      Mission
        1 stage:
          left  :
          mid   : M1蝙蝠, Get 30Gem
          right :
- Update quest_mission_nodes battle_name / reward_type / reward_name
"""

import os
import re
import sys
from pathlib import Path

import pymysql
from dotenv import load_dotenv


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


def count_indent(line: str) -> int:
    return len(line) - len(line.lstrip(" "))


def normalize_name(s: str) -> str:
    return re.sub(r"\s+", "", s.strip())


def parse_reward_type(reward_name: str):
    if not reward_name:
        return None
    s = reward_name.strip()
    if re.search(r"Gem$", s, re.I):
        return "gem"
    if re.search(r"^\d+\s*Gem$", s, re.I):
        return "gem"
    return "card"


def parse_route_content(content: str):
    content = content.strip()
    if content == "":
        return None, None, None

    parts = [p.strip() for p in content.split(",") if p.strip()]
    battle_parts = []
    reward_name = None

    for p in parts:
        if p.lower().startswith("get "):
            reward_name = p[4:].strip()
        elif p.startswith("Get "):
            reward_name = p[4:].strip()
        else:
            battle_parts.append(p)

    battle_name = ", ".join(battle_parts) if battle_parts else None
    reward_type = parse_reward_type(reward_name)

    return battle_name, reward_type, reward_name


SCRIPT_PATH = Path(__file__).resolve()
PROJECT_ROOT = find_project_root(SCRIPT_PATH)
ENV_PATH = PROJECT_ROOT / ".env"
load_dotenv(ENV_PATH)

DEFAULT_TXT_PATH = PROJECT_ROOT / "scripts" / "import" / "import_quest_details.txt"

FIND_MISSION_SQL = """
SELECT
  m.id AS mission_id
FROM quest_missions m
JOIN quest_regions r ON r.id = m.region_id
JOIN quest_worlds w ON w.id = r.world_id
WHERE w.world_key = %(world_key)s
  AND r.name_tcn = %(region_name)s
  AND m.name_tcn = %(mission_name)s
LIMIT 1
"""

UPDATE_NODE_SQL = """
UPDATE quest_mission_nodes
SET
  battle_name = %(battle_name)s,
  reward_type = %(reward_type)s,
  reward_name = %(reward_name)s
WHERE mission_id = %(mission_id)s
  AND stage_no = %(stage_no)s
  AND route_pos = %(route_pos)s
"""

UPDATE_NODE_CODE_SQL = """
UPDATE quest_node_codes c
JOIN quest_mission_nodes n ON n.node_code = c.node_code
SET
  c.battle_name = COALESCE(c.battle_name, %(battle_name)s),
  c.reward_type = COALESCE(c.reward_type, %(reward_type)s),
  c.reward_name = COALESCE(c.reward_name, %(reward_name)s)
WHERE n.mission_id = %(mission_id)s
  AND n.stage_no = %(stage_no)s
  AND n.route_pos = %(route_pos)s
  AND n.node_code IS NOT NULL
"""

CREATE_TMP_SQL = """
CREATE TEMPORARY TABLE tmp_quest_txt_nodes (
  world_key VARCHAR(50) NOT NULL,
  region_name VARCHAR(100) NOT NULL,
  mission_name VARCHAR(150) NOT NULL,
  stage_no INT NOT NULL,
  route_pos ENUM('left','mid','right') NOT NULL,
  battle_name VARCHAR(150) DEFAULT NULL,
  reward_type VARCHAR(50) DEFAULT NULL,
  reward_name VARCHAR(150) DEFAULT NULL,
  INDEX idx_tmp_lookup (world_key, region_name, mission_name, stage_no, route_pos)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
"""

INSERT_TMP_SQL = """
INSERT INTO tmp_quest_txt_nodes (
  world_key,
  region_name,
  mission_name,
  stage_no,
  route_pos,
  battle_name,
  reward_type,
  reward_name
) VALUES (
  %(world_key)s,
  %(region_name)s,
  %(mission_name)s,
  %(stage_no)s,
  %(route_pos)s,
  %(battle_name)s,
  %(reward_type)s,
  %(reward_name)s
)
"""

BATCH_UPDATE_SQL = """
UPDATE quest_mission_nodes n
JOIN quest_missions m ON m.id = n.mission_id
JOIN quest_regions r ON r.id = m.region_id
JOIN quest_worlds w ON w.id = r.world_id
JOIN tmp_quest_txt_nodes t
  ON t.world_key = w.world_key
 AND t.region_name = r.name_tcn
 AND t.mission_name = m.name_tcn
 AND t.stage_no = n.stage_no
 AND t.route_pos = n.route_pos
SET
  n.battle_name = t.battle_name,
  n.reward_type = t.reward_type,
  n.reward_name = t.reward_name
"""


def main():
    txt_path = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else DEFAULT_TXT_PATH

    print("🚀 import_quest_txt_detail.py 已啟動")
    print("Project root:", PROJECT_ROOT)
    print(".env path   :", ENV_PATH, "(exists)" if ENV_PATH.exists() else "(MISSING)")
    print("TXT path    :", txt_path, "(exists)" if txt_path.exists() else "(MISSING)")
    print("DB_HOST     :", os.getenv("DB_HOST"))
    print("DB_PORT     :", os.getenv("DB_PORT", "3306"))
    print("DB_USER     :", os.getenv("DB_USER"))
    print("DB_PASSWORD :", mask(os.getenv("DB_PASSWORD")))
    print("DB_NAME     :", os.getenv("DB_NAME"))
    print("")

    if not txt_path.exists():
        raise FileNotFoundError(f"TXT not found: {txt_path}")

    db_config = build_db_config()
    conn = pymysql.connect(**db_config)

    current_world = None
    current_region = None
    current_mission = None
    current_stage = None

    mission_cache = {}
    parsed_routes = 0
    updated_nodes = 0
    empty_routes = 0
    missing_missions = []
    missing_nodes = []

    route_re = re.compile(r"^(left|mid|right)\s*:\s*(.*)$", re.I)
    stage_re = re.compile(r"^(\d+)\s+stage\s*:\s*$", re.I)

    try:
        rows = []
        parsed_routes = 0
        empty_routes = 0

        with open(txt_path, "r", encoding="utf-8") as f:
            for line_no, raw_line in enumerate(f, start=1):
                if line_no % 1000 == 0:
                    print(f"parsing line {line_no}...", flush=True)

                line = raw_line.rstrip("\n\r")
                if line.strip() == "":
                    continue

                indent = count_indent(line)
                stripped = line.strip()

                if indent == 0:
                    current_world = stripped
                    current_region = None
                    current_mission = None
                    current_stage = None
                    continue

                if indent == 2:
                    current_region = stripped
                    current_mission = None
                    current_stage = None
                    continue

                if indent == 4:
                    current_mission = stripped
                    current_stage = None
                    continue

                if indent == 6:
                    m_stage = stage_re.match(stripped)
                    if m_stage:
                        current_stage = int(m_stage.group(1))
                    continue

                if indent >= 8:
                    m_route = route_re.match(stripped)
                    if not m_route:
                        continue

                    route_pos = m_route.group(1).lower()
                    content = m_route.group(2)
                    parsed_routes += 1

                    if not current_world or not current_region or not current_mission or not current_stage:
                        continue

                    battle_name, reward_type, reward_name = parse_route_content(content)

                    if battle_name is None and reward_name is None:
                        empty_routes += 1

                    rows.append({
                        "world_key": current_world,
                        "region_name": current_region,
                        "mission_name": current_mission,
                        "stage_no": current_stage,
                        "route_pos": route_pos,
                        "battle_name": battle_name,
                        "reward_type": reward_type,
                        "reward_name": reward_name,
                    })

        print(f"parsed routes: {parsed_routes}", flush=True)
        print(f"rows to import: {len(rows)}", flush=True)

        with conn.cursor() as cursor:
            print("creating temp table...", flush=True)
            cursor.execute(CREATE_TMP_SQL)

            print("bulk inserting temp rows...", flush=True)
            cursor.executemany(INSERT_TMP_SQL, rows)

            print("batch updating quest_mission_nodes...", flush=True)
            cursor.execute(BATCH_UPDATE_SQL)
            updated_nodes = cursor.rowcount

        conn.commit()

        print("")
        print("== TXT Import Summary ==")
        print(f"parsed routes : {parsed_routes}")
        print(f"temp rows     : {len(rows)}")
        print(f"updated nodes : {updated_nodes}")
        print(f"empty routes  : {empty_routes}")
        print("DONE")

    except Exception:
        conn.rollback()
        print("ERROR: rollback")
        raise

    finally:
        conn.close()


if __name__ == "__main__":
    main()