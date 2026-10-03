import json
from pathlib import Path
from datetime import datetime, timedelta, timezone
import shutil
from pymysql.err import MySQLError
from dotenv import load_dotenv
import os
import sys
import pymysql
import re

# ----------------------------------------------------------------
# 1) 基本設定
# ----------------------------------------------------------------
load_dotenv()  # 讀取 .env

db_config = {
    "host": os.getenv("DB_HOST"),
    "user": os.getenv("DB_USER"),
    "password": os.getenv("DB_PASSWORD"),
    "database": os.getenv("DB_NAME"),
    "port": int(os.getenv("DB_PORT")),
    "charset": "utf8mb4",
    "cursorclass": pymysql.cursors.DictCursor
}

def create_db_connection(cfg):
    """建立並回傳一個 MySQL 連線，連線失敗時結束程式"""
    try:
        conn = pymysql.connect(**cfg)
        conn.ping(reconnect=True)
        print("✅ 成功連線到 MySQL")
        return conn
    except MySQLError as e:
        print(f"❌ 連線失敗：{e}")
        sys.exit(1)

db = create_db_connection(db_config)
cursor = db.cursor()

# ----------------------------------------------------------------
# 工具函式
# ----------------------------------------------------------------
def backup_and_load(name):
    base = Path(__file__).parent
    src = base / f"{name}.json"
    backup_dir = base / f"{name}_history_backup"
    backup_dir.mkdir(exist_ok=True)
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    bak = backup_dir / f"{name}_backup_{ts}.json"
    shutil.copy(src, bak)
    print(f"🗂 已備份 {name}.json → {bak}")
    with src.open("r", encoding="utf-8") as f:
        data = json.load(f)
    return data, src

def clear_json(path: Path):
    with path.open("w", encoding="utf-8") as f:
        json.dump({}, f, indent=4, ensure_ascii=False)
    print(f"🧹 已清空 {path.name}")


# ----------------------------------------------------------------
# 上傳 ranking_bp_JP
# ----------------------------------------------------------------
bp_data, bp_path = backup_and_load("ranking_bp_JP")

raw = bp_data if isinstance(bp_data, list) else bp_data.get("ranks") or bp_data.get("ranking") or []
updated_at = bp_data.get("updatedAt") if isinstance(bp_data, dict) else None

if not updated_at:
    print("⚠️ ranking_bp_JP.json 缺少 updatedAt，拒絕上傳。")
else:
    if re.match(r"^\d{4}-\d{2}-\d{2}T", updated_at):
        ts_utc = datetime.fromisoformat(updated_at.replace("Z", "+00:00"))
        ts_local = ts_utc.astimezone(timezone(timedelta(hours=8)))
        ts = ts_local.strftime("%Y-%m-%d %H:%M:%S")
    else:
        print("⚠️ ranking_bp_JP.json 的 updatedAt 格式無效，拒絕上傳。")
        ts = None

    if ts and raw:
        insert_bp = """
          INSERT INTO `ranking_bp_JP_history`
            (`ts`, `rank_num`, `name`, `level`, `bp`, `win_ranked`, `lose_ranked`, `draw_ranked`)
          VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """
        for i, rec in enumerate(raw, start=1):
            name        = rec.get("name")
            level       = rec.get("level")
            bp          = rec.get("bp")
            win_ranked  = rec.get("win_ranked")
            lose_ranked = rec.get("lose_ranked")
            draw_ranked = rec.get("draw_ranked")

            cursor.execute(insert_bp, (ts, i, name, level, bp, win_ranked, lose_ranked, draw_ranked))

        db.commit()
        print(f"✅ 已插入 {len(raw)} 筆到 ranking_bp_JP_history（ts={ts}）")
        clear_json(bp_path)
    else:
        print("⚠️ ranking_bp_JP.json 無任何排行物件，跳過 DB 寫入及清空原檔")


# ----------------------------------------------------------------
# 上傳 ranking_qp_JP
# ----------------------------------------------------------------
qp_data, qp_path = backup_and_load("ranking_qp_JP")

raw = qp_data if isinstance(qp_data, list) else qp_data.get("ranks") or qp_data.get("ranking") or []
updated_at = qp_data.get("updatedAt") if isinstance(qp_data, dict) else None

if not updated_at:
    print("⚠️ ranking_qp_JP.json 缺少 updatedAt，拒絕上傳。")
else:
    if re.match(r"^\d{4}-\d{2}-\d{2}T", updated_at):
        ts_utc = datetime.fromisoformat(updated_at.replace("Z", "+00:00"))
        ts_local = ts_utc.astimezone(timezone(timedelta(hours=8)))
        ts = ts_local.strftime("%Y-%m-%d %H:%M:%S")
    else:
        print("⚠️ ranking_qp_JP.json 的 updatedAt 格式無效，拒絕上傳。")
        ts = None

    if ts and raw:
        insert_qp = """
          INSERT INTO `ranking_qp_JP_history`
            (`ts`, `rank_num`, `name`, `level`, `qp`)
          VALUES (%s, %s, %s, %s, %s)
        """
        for i, rec in enumerate(raw, start=1):
            name  = rec.get("name")
            level = rec.get("level")
            qp    = rec.get("qp")
            cursor.execute(insert_qp, (ts, i, name, level, qp))

        db.commit()
        print(f"✅ 已插入 {len(raw)} 筆到 ranking_qp_JP_history（ts={ts}）")
        clear_json(qp_path)
    else:
        print("⚠️ ranking_qp_JP.json 無任何排行物件，跳過 DB 寫入及清空原檔")
