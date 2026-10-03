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

# MONTHLY_SETTLEMENT_IMPORT_V2
def _settlement_dt():
    now_tw = (datetime.now(timezone.utc) + timedelta(hours=8)).replace(tzinfo=None)
    target = now_tw.replace(hour=10, minute=0, second=0, microsecond=0)
    if now_tw < target:
        raise RuntimeError(f"尚未到本日 10:00 結算時間：now={now_tw}, target={target}")
    return target

SETTLEMENT_DT = _settlement_dt()
SETTLEMENT_TS = SETTLEMENT_DT.strftime("%Y-%m-%d %H:%M:%S")


def _payload_dt(payload):
    value = None
    if isinstance(payload, dict):
        value = payload.get("update_at") or payload.get("updatedAt")
    if value is None:
        return None
    try:
        if isinstance(value, (int, float)) or (isinstance(value, str) and value.strip().replace(".", "", 1).isdigit()):
            n = float(value)
            if abs(n) >= 10_000_000_000:
                n /= 1000.0
            return (datetime.fromtimestamp(n, tz=timezone.utc) + timedelta(hours=8)).replace(tzinfo=None)
        if isinstance(value, str):
            dt = datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
            if dt.tzinfo is None:
                dt = dt.replace(tzinfo=timezone.utc)
            return (dt.astimezone(timezone.utc) + timedelta(hours=8)).replace(tzinfo=None)
    except Exception:
        return None
    return None

def _payload_rows(payload):
    if isinstance(payload, dict):
        return payload.get("data") or payload.get("ranks") or payload.get("ranking") or []
    if isinstance(payload, list):
        return payload
    return []

def load_best_settlement_json(name, score_key):
    current = Path(__file__).parent / f"{name}.json"
    backup_dir = Path(__file__).parent / f"{name}_backup"
    candidates = []
    paths = []
    if current.exists():
        paths.append(current)
    if backup_dir.exists():
        paths.extend(sorted(backup_dir.glob(f"{name}_backup_*.json")))

    day0 = SETTLEMENT_DT.replace(hour=0, minute=0, second=0, microsecond=0)
    leader0 = None
    try:
        cursor.execute(
            f"SELECT {score_key} AS v FROM {name} WHERE ts=%s AND rank_num=1 LIMIT 1",
            (day0.strftime("%Y-%m-%d %H:%M:%S"),),
        )
        r = cursor.fetchone()
        if r and r.get("v") is not None:
            leader0 = int(r["v"])
    except Exception:
        leader0 = None

    for path in paths:
        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            continue
        rows = _payload_rows(payload)
        pdt = _payload_dt(payload)
        if not rows or pdt is None:
            continue
        # 月結只接受 10:00 前後 5 分鐘內，或最多往前 6 小時的來源。
        if pdt < SETTLEMENT_DT - timedelta(hours=6) or pdt > SETTLEMENT_DT + timedelta(minutes=5):
            continue
        first = rows[0] if rows and isinstance(rows[0], dict) else {}
        point = first.get("point")
        if point is None:
            point = first.get(score_key)
        try:
            point = int(point) if point is not None else None
        except Exception:
            point = None
        # 防止維修後新季已重置卻被誤當上季月結。
        if leader0 and point is not None and point < leader0 * 0.5:
            continue
        candidates.append((abs((pdt - SETTLEMENT_DT).total_seconds()), -pdt.timestamp(), path, payload, pdt))

    if not candidates:
        print(f"⚠️ {score_key.upper()} 找不到可信的月結 JSON 備份，改走 DB fallback")
        return {}, current

    candidates.sort(key=lambda x: (x[0], x[1]))
    _, _, path, payload, pdt = candidates[0]
    print(f"✅ {score_key.upper()} 月結來源：{path.name} payload_time={pdt} rows={len(_payload_rows(payload))}")
    return payload, path

def fallback_live_rows(table, score_key):
    start = SETTLEMENT_DT - timedelta(hours=6)
    cursor.execute(
        f"SELECT ts, COUNT(*) c FROM {table} WHERE ts BETWEEN %s AND %s "
        "GROUP BY ts HAVING c >= 30 ORDER BY ts DESC LIMIT 1",
        (start.strftime("%Y-%m-%d %H:%M:%S"), SETTLEMENT_TS),
    )
    hit = cursor.fetchone()
    if not hit:
        return []
    snap = hit["ts"]
    extra = ", win_ranked, lose_ranked, draw_ranked" if score_key == "bp" else ""
    cursor.execute(
        f"SELECT rank_num, name, level, {score_key}{extra} FROM {table} "
        "WHERE ts=%s ORDER BY rank_num ASC LIMIT 100",
        (snap,),
    )
    rows = cursor.fetchall()
    print(f"↩️ {score_key.upper()} JSON 無資料，改用結算前 DB 快照 {snap}（{len(rows)} rows）")
    return rows
    




# ----------------------------------------------------------------
# 上傳 ranking_bp_TW 並備份（修改後）
# ----------------------------------------------------------------
bp_data, bp_path = load_best_settlement_json("ranking_bp_TW", "bp")

# 嘗試取得排行榜資料，兼容 ranking / ranks 格式
if isinstance(bp_data, list):
    raw = bp_data
elif isinstance(bp_data, dict):
    raw = (
        bp_data.get("data")      # Protocol 1.4
        or bp_data.get("ranking")
        or bp_data.get("ranks")
        or []
    )
else:
    raw = []


last = (bp_data.get("updatedAt") or bp_data.get("update_at")) if isinstance(bp_data, dict) else None

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

ts = SETTLEMENT_TS
if not bp_list:
    bp_list = fallback_live_rows("ranking_bp_TW", "bp")

if not bp_list:
    print("⚠️ ranking_bp_TW.json 無任何排行物件，跳過 DB 寫入及清空原檔")
else:
    cursor.execute("DELETE FROM ranking_bp_TW_history WHERE ts=%s", (ts,))
    # INSERT 語句多加了 rank_num
    insert_bp = """
      INSERT INTO `ranking_bp_TW_history`
        (`ts`, `rank_num`, `name`, `level`, `bp`, `win_ranked`, `lose_ranked`, `draw_ranked`)
      VALUES (%s,     %s,        %s,     %s,    %s,   %s,           %s,            %s)
    """

    # enumerate 從 1 開始，i 就是我們要塞給 rank_num 的值
    for i, rec in enumerate(bp_list, start=1):
        if isinstance(rec, dict):
            if "player_name" in rec:
                name        = rec.get("player_name")
                level       = None
                bp          = rec.get("point")
                win_ranked  = None
                lose_ranked = None
                draw_ranked = None
            else:
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
    print(f"ℹ️ 保留 {bp_path.name}，不再於月結匯入後清空來源 JSON")


print("🚧 進入 QP 區段")
# ----------------------------------------------------------------
# 上傳 ranking_qp_TW 並備份（修改後，帶入 rank_num）
# ----------------------------------------------------------------
qp_data, qp_path = load_best_settlement_json("ranking_qp_TW", "qp")

# --- 明確使用獨立變數 ---
qp_raw = []

if isinstance(qp_data, dict):
    qp_raw = qp_data.get("data") or qp_data.get("ranks") or qp_data.get("ranking") or []
elif isinstance(qp_data, list):
    qp_raw = qp_data
else:
    qp_raw = []

# updatedAt
qp_last = (qp_data.get("updatedAt") or qp_data.get("update_at")) if isinstance(qp_data, dict) else None

if isinstance(qp_last, str) and re.match(r"^\d{4}-\d{2}-\d{2}T", qp_last):
    try:
        ts_utc = datetime.fromisoformat(qp_last.replace("Z", "+00:00"))
    except ValueError:
        ts_utc = datetime.strptime(qp_last, "%Y-%m-%dT%H:%M:%S.%fZ")
    ts_qp = (ts_utc + timedelta(hours=8)).strftime("%Y-%m-%d %H:%M:%S")
else:
    ts_qp = (datetime.now(timezone.utc) + timedelta(hours=8)).strftime("%Y-%m-%d %H:%M:%S")

qp_list = qp_raw
ts_qp = SETTLEMENT_TS
if not qp_list:
    qp_list = fallback_live_rows("ranking_qp_TW", "qp")

print("DEBUG QP raw type:", type(qp_raw))
print("DEBUG QP list len:", len(qp_list))
print("DEBUG QP first:", qp_list[0] if qp_list else None)
if not qp_list:
    print("⚠️ ranking_qp_TW.json 無任何排行物件，跳過 DB 寫入及清空原檔")
else:
    cursor.execute("DELETE FROM ranking_qp_TW_history WHERE ts=%s", (ts_qp,))
    # INSERT 語句多了 rank_num 欄位
    insert_qp = """
      INSERT INTO `ranking_qp_TW_history`
        (`ts`, `rank_num`, `name`, `level`, `qp`)
      VALUES (%s,     %s,         %s,      %s,    %s)
    """

    # 用 enumerate 產生從 1 開始的 i 當作 rank_num
    for i, rec in enumerate(qp_list, start=1):
        if isinstance(rec, dict):
            if "player_name" in rec:
                name  = rec.get("player_name")
                level = None
                qp    = rec.get("point")
            else:
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
    print(f"ℹ️ 保留 {qp_path.name}，不再於月結匯入後清空來源 JSON")

# RANK_MAINTENANCE_ENABLE_ON_MONTHLY_IMPORT_V2
# BP / QP 各自屏蔽、各自等待自己的榜單清 0，再各自公開。
_gate_base = Path(__file__).parent
_season_date = SETTLEMENT_DT.strftime("%Y-%m-%d")

for _kind, _rows in (("bp", bp_list), ("qp", qp_list)):
    if not _rows:
        continue
    _season_marker = _gate_base / f"ranking_{_kind}_season_started_{_season_date}.flag"
    _maintenance_flag = _gate_base / f"ranking_{_kind}_maintenance.flag"
    _label = _kind.upper()
    if _season_marker.exists():
        print(f"ℹ️ {_label} {_season_date} 已偵測到新賽季清零，不重新啟用 {_label} 排行榜維護屏蔽")
    else:
        _maintenance_flag.write_text(
            f"settlement={SETTLEMENT_TS}\ncreated={datetime.now().strftime('%Y-%m-%d %H:%M:%S')}\n",
            encoding="utf-8",
        )
        print(f"🟡 {_label} 月結完成：已啟用 {_label} 排行榜維護屏蔽，等待 {_label} 榜單清零後自動公開")

