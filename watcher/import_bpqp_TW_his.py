import json
#import mysql.connector
from pathlib import Path
from datetime import datetime, timedelta, timezone
import shutil
from pymysql.err import MySQLError     # ← 把 MySQLError import 進來
from dotenv import load_dotenv
import os
import sys                # ← 新增這行
import pymysql
import re
# ----------------------------------------------------------------
# 1) 基本設定
# ----------------------------------------------------------------
load_dotenv()  # 讀取 .env

# 一次性讀取所有設定
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
        # ping() 可以在連線已經存在時測試是否還活著
        conn.ping(reconnect=True)
        print("✅ 成功連線到 MySQL")
        return conn
    except MySQLError as e:
        print(f"❌ 連線失敗：{e}")
        sys.exit(1)

# 正式拿到連線與 cursor
db = create_db_connection(db_config)
cursor = db.cursor()

# helper：備份並讀 JSON
def backup_and_load(name):
    """
    name: 'channel1_room' / 'ranking_bp_TW' / 'ranking_qp_TW'
    回傳 JSON 解析後的物件
    """
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

# helper：清空 JSON
def clear_json(path: Path):
    with path.open("w", encoding="utf-8") as f:
        json.dump({}, f, indent=4, ensure_ascii=False)
    print(f"🧹 已清空 {path.name}")
    




# ----------------------------------------------------------------
# 上傳 ranking_bp_TW 並備份（修改後）
# ----------------------------------------------------------------
bp_data, bp_path = backup_and_load("ranking_bp_TW")

# 嘗試取得排行榜資料，兼容 ranking / ranks 格式
if isinstance(bp_data, list):
    raw = bp_data
elif isinstance(bp_data, dict):
    raw = (
        bp_data.get("ranking")
        or bp_data.get("ranks")  # 支援 _JP 格式
        or []
    )
else:
    raw = []


last = bp_data.get("updatedAt") if isinstance(bp_data, dict) else None

if isinstance(last, str) and re.match(r"^\d{4}-\d{2}-\d{2}T", last):
    try:
        ts_utc = datetime.fromisoformat(last.replace("Z", "+00:00"))
    except ValueError:
        ts_utc = datetime.strptime(last, "%Y-%m-%dT%H:%M:%S.%fZ")
    ts_local = ts_utc + timedelta(hours=8)
    ts = ts_local.strftime("%Y-%m-%d %H:%M:%S")
    bp_list = raw  # ✅ 注意：這裡不再用 raw[:-1]，因為 updatedAt 不是在 list 裡
else:
    ts = (datetime.now(timezone.utc) + timedelta(hours=8)).strftime("%Y-%m-%d %H:%M:%S")
    bp_list = raw

if not bp_list:
    print("⚠️ ranking_bp_TW.json 無任何排行物件，跳過 DB 寫入及清空原檔")
else:
    # INSERT 語句多加了 rank_num
    insert_bp = """
      INSERT INTO `ranking_bp_TW_history`
        (`ts`, `rank_num`, `name`, `level`, `bp`, `win_ranked`, `lose_ranked`, `draw_ranked`)
      VALUES (%s,     %s,        %s,     %s,    %s,   %s,           %s,            %s)
    """

    # enumerate 從 1 開始，i 就是我們要塞給 rank_num 的值
    for i, rec in enumerate(bp_list, start=1):
        if isinstance(rec, dict):
            name        = rec.get("name")
            level       = rec.get("level")
            bp          = rec.get("bp")
            win_ranked  = rec.get("win_ranked")
            lose_ranked = rec.get("lose_ranked")
            draw_ranked = rec.get("draw_ranked")
        else:
            # 如果遇到不是 dict（例如 null），全部欄位設為 None
            name = level = bp = win_ranked = lose_ranked = draw_ranked = None

        cursor.execute(insert_bp, (
            ts,
            i,                # rank_num
            name, level, bp,
            win_ranked, lose_ranked, draw_ranked
        ))

    db.commit()
    print(f"✅ 已插入 {len(bp_list)} 筆到 ranking_bp_TW_history（含 rank_num）")
    clear_json(bp_path)


print("🚧 進入 QP 區段")
# ----------------------------------------------------------------
# 上傳 ranking_qp_TW 並備份（修改後，帶入 rank_num）
# ----------------------------------------------------------------
qp_data, qp_path = backup_and_load("ranking_qp_TW")

# --- 明確使用獨立變數 ---
qp_raw = []

if isinstance(qp_data, dict):
    qp_raw = qp_data.get("ranks") or qp_data.get("ranking") or []
elif isinstance(qp_data, list):
    qp_raw = qp_data
else:
    qp_raw = []

# updatedAt
qp_last = qp_data.get("updatedAt") if isinstance(qp_data, dict) else None

if isinstance(qp_last, str) and re.match(r"^\d{4}-\d{2}-\d{2}T", qp_last):
    try:
        ts_utc = datetime.fromisoformat(qp_last.replace("Z", "+00:00"))
    except ValueError:
        ts_utc = datetime.strptime(qp_last, "%Y-%m-%dT%H:%M:%S.%fZ")
    ts_qp = (ts_utc + timedelta(hours=8)).strftime("%Y-%m-%d %H:%M:%S")
else:
    ts_qp = (datetime.now(timezone.utc) + timedelta(hours=8)).strftime("%Y-%m-%d %H:%M:%S")

qp_list = qp_raw

print("DEBUG QP raw type:", type(qp_raw))
print("DEBUG QP list len:", len(qp_list))
print("DEBUG QP first:", qp_list[0] if qp_list else None)
if not qp_list:
    print("⚠️ ranking_qp_TW.json 無任何排行物件，跳過 DB 寫入及清空原檔")
else:
    # INSERT 語句多了 rank_num 欄位
    insert_qp = """
      INSERT INTO `ranking_qp_TW_history`
        (`ts`, `rank_num`, `name`, `level`, `qp`)
      VALUES (%s,     %s,         %s,      %s,    %s)
    """

    # 用 enumerate 產生從 1 開始的 i 當作 rank_num
    for i, rec in enumerate(qp_list, start=1):
        if isinstance(rec, dict):
            name  = rec.get("name")
            level = rec.get("level")
            qp    = rec.get("qp")
        else:
            # 如果碰到不是 dict（例如 null），全部設為 None
            name = level = qp = None

        cursor.execute(insert_qp, (
            ts_qp,
            i,       # 這裡就是第幾名
            name, level, qp
        ))

    db.commit()
    print(f"✅ 已插入 {len(qp_list)} 筆到 ranking_qp_TW_history（含 rank_num）")
    clear_json(qp_path)
