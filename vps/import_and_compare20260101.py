import json
#import mysql.connector
from json.decoder import JSONDecodeError
from pathlib import Path
from datetime import datetime, timezone, timedelta
import shutil
from pymysql.err import MySQLError     # ← 把 MySQLError import 進來
from dotenv import load_dotenv
import os, time
os.environ['TZ'] = 'Asia/Taipei'
import sys                # ← 新增這行
import pymysql
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
    name: 'channel1_room' / 'ranking_bp_TW' / 'ranking_qp'
    回傳 JSON 解析後的物件，以及原始檔案路徑
    """
    base = Path(__file__).parent
    src = base / f"{name}.json"

    # 如果檔案不存在或大小為 0，直接回傳空 dict（或空 list）
    if not src.exists() or src.stat().st_size == 0:
        print(f"⚠️ 檔案不存在或為空，跳過載入：{src.name}")
        return {}, src

    # 備份
    backup_dir = base / f"{name}_backup"
    backup_dir.mkdir(exist_ok=True)
    ts = datetime.now().strftime("%Y%m%d_%H%M%S")
    bak = backup_dir / f"{name}_backup_{ts}.json"
    shutil.copy(src, bak)
    print(f"🗂 已備份 {src.name} → {bak}")

    # 讀檔並防止 JSONDecodeError
    try:
        with src.open("r", encoding="utf-8") as f:
            data = json.load(f)
    except JSONDecodeError:
        print(f"❌ 讀取 {src.name} 時發生解析錯誤，回傳空資料結構")
        data = {}

    return data, src
# helper：清空 JSON
def clear_json(path: Path):
    with path.open("w", encoding="utf-8") as f:
        json.dump({}, f, indent=4, ensure_ascii=False)
    print(f"🧹 已清空 {path.name}")
    
# ----------------------------------------------------------------
# 2) 上傳 channel1_room (舊程式) 匯入新的,更新前一筆
# ----------------------------------------------------------------
room_data, room_path = backup_and_load("channel1_room")    
# 如果 room_data 是空 dict，就跳過整個流程
if not room_data:
    print("⚠️ channel1_room.json 無任何新資料，跳過上傳與清空")
else:
    inserted = 0
    skipped = 0
    updated = 0
    logs = []

    def update_previous_match(name, win_now, draw_now, lose_now, room_id,update_time):
        cursor.execute("""
            SELECT * FROM arena_unlight
            WHERE (name_p1 = %s OR name_p2 = %s)
            AND room_id != %s AND update_time<%s
            ORDER BY update_time DESC LIMIT 1
        """, (name, name, room_id,update_time))
        prev = cursor.fetchone()
        if not prev:
            return None

        dw = win_now - (prev['win_p1'] if prev['name_p1'] == name else prev['win_p2'])
        dl = lose_now - (prev['lose_p1'] if prev['name_p1'] == name else prev['lose_p2'])
        dd = draw_now - (prev['draw_p1'] if prev['name_p1'] == name else prev['draw_p2'])

        if dw >= 1 and dl == 0 and dd == 0:
            if prev['name_p2'] == name:
                cursor.execute("UPDATE arena_unlight SET win=1,lose=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                result = f"✅ 更新前一筆（{name}(勝) VS {prev['name_p1']} ID {prev['id']}）"
            else:
                cursor.execute("UPDATE arena_unlight SET lose=1,win=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                result = f"✅ 更新前一筆（{name}(勝) VS {prev['name_p2']} ID {prev['id']}）"
        elif dl >= 1 and dw == 0 and dd == 0:
            if prev['name_p2'] == name:
                cursor.execute("UPDATE arena_unlight SET lose=1,win=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                result = f"✅ 更新前一筆（{name}(負) VS {prev['name_p1']} ID {prev['id']}）"
            else:
                cursor.execute("UPDATE arena_unlight SET win=1,lose=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                result = f"✅ 更新前一筆（{name}(負) VS {prev['name_p2']} ID {prev['id']}）"
        elif dd >= 1 and dw == 0 and dl == 0:
            cursor.execute("UPDATE arena_unlight SET tie=1, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
            result = f"✅ 更新前一筆平手（{name} VS {prev['name_p1'] if prev['name_p2'] == name else prev['name_p2']} ID {prev['id']}）"
        elif dd == 0 and dw == 0 and dl == 0:
            cursor.execute("DELETE FROM arena_unlight WHERE id = %s", (prev['id'],))
            result = f"✅ 前筆記錄失效，已刪除（{name} VS {prev['name_p1'] if prev['name_p2'] == name else prev['name_p2']} ID {prev['id']}）"        
        else:
            if prev['name_p1'] == name:
                cursor.execute("UPDATE arena_unlight SET ack1=1 WHERE id=%s", (prev['id'],))
                result = f"⚠️ 無法判斷勝負（{name} VS {prev['name_p2']} ID {prev['id']}），已標記該紀錄失效"
            else:
                cursor.execute("UPDATE arena_unlight SET ack2=1 WHERE id=%s", (prev['id'],))
                result = f"⚠️ 無法判斷勝負（{name} VS {prev['name_p1']} ID {prev['id']}），已標記該紀錄失效"

        db.commit()
        return result

    for room in room_data.values():
        # ❗ 加這段來跳過目標玩家
        skip_names = {"VUP44085", "chipping5458","哈鮭熊","蝴蝶獵人","無限泡影","比爾可"}
        if room.get("playerA", {}).get("name") in skip_names or room.get("playerB", {}).get("name") in skip_names:
            continue
        room_id = room.get("room_id")
        # ← 這裡加一行，把 cost 拿出來（如果沒這個欄位就給 None）
        cost = room.get("cost", None)
        stage = room.get("stage", None)
        #readyA = room.get("readyA", None)
        #readyB = room.get("readyB", None)
        cursor.execute("SELECT COUNT(*) as c FROM arena_unlight WHERE room_id=%s", (room_id,))
        if cursor.fetchone()['c'] > 0:
            skipped += 1
            continue

        update_time = (
            datetime.fromtimestamp(room.get("date", 0) / 1000, tz=timezone.utc)
            + timedelta(hours=8)
        ).strftime('%Y-%m-%d %H:%M:%S')
        nameA = room.get("playerA", {}).get("name", "")
        bpA = room.get("playerA", {}).get("bp", 0)
        winA = room.get("playerA", {}).get("win", 0)
        drawA = room.get("playerA", {}).get("draw", 0)
        loseA = room.get("playerA", {}).get("lose", 0)

        nameB = room.get("playerB", {}).get("name", "")
        bpB = room.get("playerB", {}).get("bp", 0)
        winB = room.get("playerB", {}).get("win", 0)
        drawB = room.get("playerB", {}).get("draw", 0)
        loseB = room.get("playerB", {}).get("lose", 0)

        e = [x + 1 for x in room.get("deckA", {}).get("charaIndex", [-1, -1, -1])]
        u = [x + 1 for x in room.get("deckB", {}).get("charaIndex", [-1, -1, -1])]
        w = room.get("deckA", {}).get("weapon", [None, None, None])
        v = room.get("deckB", {}).get("weapon", [None, None, None])
        eventindex1 =json.dumps(room.get("deckA", {}).get("eventIndex", []))
        eventindex2 =json.dumps(room.get("deckB", {}).get("eventIndex", []))

        cursor.execute("""
            INSERT INTO arena_unlight (
                room_id, region, cost, stage,
                name_p1, bp_p1, win_p1, draw_p1, lose_p1,
                e1, e2, e3, w1, w2, w3, eventindex1,
                name_p2, bp_p2, win_p2, draw_p2, lose_p2,
                u1, u2, u3, v1, v2, v3, eventindex2,
                update_time, username
            ) VALUES (
                %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s
            )
        """, (
            room_id,
            room.get("region", ""),   # ⭐ 新增 region
            cost, stage,
            nameA, bpA, winA, drawA, loseA,
            *e, *w, eventindex1,
            nameB, bpB, winB, drawB, loseB,
            *u, *v, eventindex2,
            update_time,
            "way.lee_VPS"
        ))

        inserted += 1

        logA = update_previous_match(nameA, winA, drawA, loseA, room_id, update_time)
        logB = update_previous_match(nameB, winB, drawB, loseB, room_id, update_time)
        if logA:
            logs.append(logA)
            updated += 1
        if logB:
            logs.append(logB)
            updated += 1


    # 這裡補 commit，保證 arena_unlight 的插入確實寫入
    db.commit()
    # 清空 JSON 檔
    #clear_json(room_path)

    # 印出完成時間
    now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    print(f"⏰ 完成時間：{now}")

    print(f"\n✅ 匯入完成：新增 {inserted} 筆，跳過 {skipped} 筆，更新 {updated} 筆")
    for log in logs:
        print(log)
    print("🧹 已清空 channel1_room.json")
    
# ----------------------------------------------------------------
# 2) 上傳 channel3_room (舊程式) 匯入新的,更新前一筆
# ----------------------------------------------------------------
room_data, room_path = backup_and_load("channel3_room")    
# 如果 room_data 是空 dict，就跳過整個流程
if not room_data:
    print("⚠️ channel3_room.json 無任何新資料，跳過上傳與清空")
else:
    inserted = 0
    skipped = 0
    updated = 0
    logs = []

    def update_previous_match(name, win_now, draw_now, lose_now, room_id,update_time):
        cursor.execute("""
            SELECT * FROM arena_unlight
            WHERE (name_p1 = %s OR name_p2 = %s)
            AND room_id != %s AND update_time<%s
            ORDER BY update_time DESC LIMIT 1
        """, (name, name, room_id,update_time))
        prev = cursor.fetchone()
        if not prev:
            return None

        dw = win_now - (prev['win_p1'] if prev['name_p1'] == name else prev['win_p2'])
        dl = lose_now - (prev['lose_p1'] if prev['name_p1'] == name else prev['lose_p2'])
        dd = draw_now - (prev['draw_p1'] if prev['name_p1'] == name else prev['draw_p2'])

        if dw >= 1 and dl == 0 and dd == 0:
            if prev['name_p2'] == name:
                cursor.execute("UPDATE arena_unlight SET win=1,lose=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                result = f"✅ 更新前一筆（{name}(勝) VS {prev['name_p1']} ID {prev['id']}）"
            else:
                cursor.execute("UPDATE arena_unlight SET lose=1,win=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                result = f"✅ 更新前一筆（{name}(勝) VS {prev['name_p2']} ID {prev['id']}）"
        elif dl >= 1 and dw == 0 and dd == 0:
            if prev['name_p2'] == name:
                cursor.execute("UPDATE arena_unlight SET lose=1,win=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                result = f"✅ 更新前一筆（{name}(負) VS {prev['name_p1']} ID {prev['id']}）"
            else:
                cursor.execute("UPDATE arena_unlight SET win=1,lose=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                result = f"✅ 更新前一筆（{name}(負) VS {prev['name_p2']} ID {prev['id']}）"
        elif dd >= 1 and dw == 0 and dl == 0:
            cursor.execute("UPDATE arena_unlight SET tie=1, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
            result = f"✅ 更新前一筆平手（{name} VS {prev['name_p1'] if prev['name_p2'] == name else prev['name_p2']} ID {prev['id']}）"
        elif dd == 0 and dw == 0 and dl == 0:
            cursor.execute("DELETE FROM arena_unlight WHERE id = %s", (prev['id'],))
            result = f"✅ 前筆記錄失效，已刪除（{name} VS {prev['name_p1'] if prev['name_p2'] == name else prev['name_p2']} ID {prev['id']}）"        
        else:
            if prev['name_p1'] == name:
                cursor.execute("UPDATE arena_unlight SET ack1=1 WHERE id=%s", (prev['id'],))
                result = f"⚠️ 無法判斷勝負（{name} VS {prev['name_p2']} ID {prev['id']}），已標記該紀錄失效"
            else:
                cursor.execute("UPDATE arena_unlight SET ack2=1 WHERE id=%s", (prev['id'],))
                result = f"⚠️ 無法判斷勝負（{name} VS {prev['name_p1']} ID {prev['id']}），已標記該紀錄失效"

        db.commit()
        return result

    for room in room_data.values():
        # ❗ 加這段來跳過目標玩家
        skip_names = {"VUP44085", "chipping5458","哈鮭熊","蝴蝶獵人","無限泡影","比爾可"}
        if room.get("playerA", {}).get("name") in skip_names or room.get("playerB", {}).get("name") in skip_names:
            continue
        room_id = room.get("room_id")
        # ← 這裡加一行，把 cost 拿出來（如果沒這個欄位就給 None）
        cost = room.get("cost", None)
        stage = room.get("stage", None)
        #readyA = room.get("readyA", None)
        #readyB = room.get("readyB", None)
        cursor.execute("SELECT COUNT(*) as c FROM arena_unlight WHERE room_id=%s", (room_id,))
        if cursor.fetchone()['c'] > 0:
            skipped += 1
            continue

        update_time = (
            datetime.fromtimestamp(room.get("date", 0) / 1000, tz=timezone.utc)
            + timedelta(hours=8)
        ).strftime('%Y-%m-%d %H:%M:%S')
        nameA = room.get("playerA", {}).get("name", "")
        bpA = room.get("playerA", {}).get("bp", 0)
        winA = room.get("playerA", {}).get("win", 0)
        drawA = room.get("playerA", {}).get("draw", 0)
        loseA = room.get("playerA", {}).get("lose", 0)

        nameB = room.get("playerB", {}).get("name", "")
        bpB = room.get("playerB", {}).get("bp", 0)
        winB = room.get("playerB", {}).get("win", 0)
        drawB = room.get("playerB", {}).get("draw", 0)
        loseB = room.get("playerB", {}).get("lose", 0)

        e = [x + 1 for x in room.get("deckA", {}).get("charaIndex", [-1, -1, -1])]
        u = [x + 1 for x in room.get("deckB", {}).get("charaIndex", [-1, -1, -1])]
        w = room.get("deckA", {}).get("weapon", [None, None, None])
        v = room.get("deckB", {}).get("weapon", [None, None, None])
        eventindex1 =json.dumps(room.get("deckA", {}).get("eventIndex", []))
        eventindex2 =json.dumps(room.get("deckB", {}).get("eventIndex", []))

        cursor.execute("""
            INSERT INTO arena_unlight (
                room_id, region, cost, stage,
                name_p1, bp_p1, win_p1, draw_p1, lose_p1,
                e1, e2, e3, w1, w2, w3, eventindex1,
                name_p2, bp_p2, win_p2, draw_p2, lose_p2,
                u1, u2, u3, v1, v2, v3, eventindex2,
                update_time, username
            ) VALUES (
                %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s
            )
        """, (
            room_id,
            room.get("region", ""),
            cost, stage,
            nameA, bpA, winA, drawA, loseA,
            *e, *w, eventindex1,
            nameB, bpB, winB, drawB, loseB,
            *u, *v, eventindex2,
            update_time,
            "way.lee_VPS"
        ))

        inserted += 1

        logA = update_previous_match(nameA, winA, drawA, loseA, room_id, update_time)
        logB = update_previous_match(nameB, winB, drawB, loseB, room_id, update_time)
        if logA:
            logs.append(logA)
            updated += 1
        if logB:
            logs.append(logB)
            updated += 1


    # 這裡補 commit，保證 arena_unlight 的插入確實寫入
    db.commit()
    # 清空 JSON 檔
    #clear_json(room_path)

    # 印出完成時間
    now = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    print(f"⏰ 完成時間：{now}")

    print(f"\n✅ 匯入完成：新增 {inserted} 筆，跳過 {skipped} 筆，更新 {updated} 筆")
    for log in logs:
        print(log)
    print("🧹 已清空 channel3_room.json")

# ----------------------------------------------------------------
# 上傳 ranking_bp_TW / ranking_bp_JP 並備份
# ----------------------------------------------------------------
import re
from datetime import datetime, timezone, timedelta

# 要處理的來源檔案與對應的資料表
sources = [
    ("ranking_bp_TW", "ranking_bp_TW"),
    ("ranking_bp_JP", "ranking_bp_JP")
]

for source_name, table_name in sources:
    # 讀取備份並解析 JSON
    bp_data, bp_path = backup_and_load(source_name)
    raw = bp_data if isinstance(bp_data, list) else bp_data.get("ranks", [])
    last = raw[-1] if raw else None

    # ✅ 從 JSON 最後一筆時間取出 timestamp（支援舊格式與新格式）
    # 台日本版：使用 updatedAt 欄位
    updated_at = bp_data.get("updatedAt")
    raw = bp_data.get("ranks", [])

    if isinstance(updated_at, str) and re.match(r"^\d{4}-\d{2}-\d{2}T", updated_at):
        try:
            ts_utc = datetime.fromisoformat(updated_at.replace("Z", "+00:00"))
        except ValueError:
            ts_utc = datetime.strptime(updated_at, "%Y-%m-%dT%H:%M:%S.%fZ")
        ts_local = (ts_utc + timedelta(hours=8)).replace(second=0, microsecond=0)
        ts = ts_local.strftime("%Y-%m-%d %H:%M:%S")
        bp_list = raw
    else:
        ts_local = (datetime.now(timezone.utc) + timedelta(hours=8)).replace(second=0, microsecond=0)
        ts = ts_local.strftime("%Y-%m-%d %H:%M:%S")
        bp_list = raw



    if not bp_list:
        print(f"⚠️ {source_name}.json 無任何排行物件，跳過 DB 寫入及清空原檔")
        continue

    # 補滿到 100 筆
    while len(bp_list) < 100:
        bp_list.append({})

    # ✅ 清除舊資料（僅刪除非 00:00:00 的前筆資料）
    sql_delete = f"""
    DELETE FROM `{table_name}`
    WHERE CONCAT(ts, rank_num) IN (
        SELECT CONCAT(ts, rank_num) FROM (
            SELECT ts, rank_num
            FROM `{table_name}` t1
            WHERE ts < %s
            AND ts NOT IN (
                SELECT MIN(ts) FROM `{table_name}` GROUP BY DATE(ts)
            )
            AND TIME(ts) != '00:00:00'
            ORDER BY ts ASC, rank_num ASC
            LIMIT 100
        ) AS tmp
    )
    """
    cursor.execute(sql_delete, (ts,))
    deleted = cursor.rowcount
    print(f"🧹 SQL：已刪除 {deleted} 筆前 100 中非 00:00:00 的 {table_name} 資料")

    # ✅ 匯入資料
    insert_sql = f"""
    INSERT INTO `{table_name}`
        (`ts`, `rank_num`, `name`, `level`, `bp`, `win_ranked`, `lose_ranked`, `draw_ranked`)
    VALUES (%s,%s,%s,%s,%s,%s,%s,%s)
    ON DUPLICATE KEY UPDATE
        name=VALUES(name),
        level=VALUES(level),
        bp=VALUES(bp),
        win_ranked=VALUES(win_ranked),
        lose_ranked=VALUES(lose_ranked),
        draw_ranked=VALUES(draw_ranked)
    """

    for rankx, rec in enumerate(bp_list[:100]):
        rank_num = rankx + 1
        if isinstance(rec, dict):
            values = (
                ts, rank_num,
                rec.get("name"), rec.get("level"), rec.get("bp"),
                rec.get("win_ranked"), rec.get("lose_ranked"), rec.get("draw_ranked")
            )
        else:
            values = (ts, rank_num, None, None, None, None, None, None)
        cursor.execute(insert_sql, values)

    db.commit()
    print(f"✅ 已匯入 {min(100, len(bp_list))} 筆資料到 {table_name}")
    clear_json(bp_path)


# ----------------------------------------------------------------
# 上傳 ranking_qp_TW / ranking_qp_JP 並備份
# ----------------------------------------------------------------
import re
from datetime import datetime, timezone, timedelta

# 要處理的來源檔案與對應的資料表
sources = [
    ("ranking_qp_TW", "ranking_qp_TW"),
    ("ranking_qp_JP", "ranking_qp_JP")
]

for source_name, table_name in sources:
    # 讀取備份並解析 JSON
    qp_data, qp_path = backup_and_load(source_name)
    raw = qp_data if isinstance(qp_data, list) else qp_data.get("ranks", [])
    last = raw[-1] if raw else None

    # ✅ 從 JSON 最後一筆時間取出 timestamp
    # 嘗試讀取排行榜資料
    if isinstance(qp_data, dict):
        raw = qp_data.get("ranks", [])
        updated_at = qp_data.get("updatedAt")
    else:
        raw = qp_data if isinstance(qp_data, list) else []
        updated_at = raw[-1] if raw and isinstance(raw[-1], str) else None

    # 判斷並解析時間戳
    if isinstance(updated_at, str) and re.match(r"^\d{4}-\d{2}-\d{2}T", updated_at):
        try:
            ts_utc = datetime.fromisoformat(updated_at.replace("Z", "+00:00"))
        except ValueError:
            ts_utc = datetime.strptime(updated_at, "%Y-%m-%dT%H:%M:%S.%fZ")
        ts_local = (ts_utc + timedelta(hours=8)).replace(second=0, microsecond=0)
        ts = ts_local.strftime("%Y-%m-%d %H:%M:%S")
        qp_list = raw
    else:
        ts_local = (datetime.now(timezone.utc) + timedelta(hours=8)).replace(second=0, microsecond=0)
        ts = ts_local.strftime("%Y-%m-%d %H:%M:%S")
        qp_list = raw



    if not qp_list:
        print(f"⚠️ {source_name}.json 無任何排行物件，跳過 DB 寫入及清空原檔")
        continue

    # 補滿到 100 筆
    while len(qp_list) < 100:
        qp_list.append({})

    # ✅ 清除舊資料（僅刪除非 00:00:00 的前筆資料）
    sql_delete = f"""
    DELETE FROM `{table_name}`
    WHERE CONCAT(ts, rank_num) IN (
        SELECT CONCAT(ts, rank_num) FROM (
            SELECT ts, rank_num
            FROM `{table_name}` t1
            WHERE ts < %s
            AND ts NOT IN (
                SELECT MIN(ts) FROM `{table_name}` GROUP BY DATE(ts)
            )
            AND TIME(ts) != '00:00:00'
            ORDER BY ts ASC, rank_num ASC
            LIMIT 100
        ) AS tmp
    )
    """
    cursor.execute(sql_delete, (ts,))
    deleted = cursor.rowcount
    print(f"🧹 SQL：已刪除 {deleted} 筆前 100 中非 00:00:00 的 {table_name} 資料")

    # ✅ 匯入資料
    insert_sql = f"""
    INSERT INTO `{table_name}`
        (`ts`, `rank_num`, `name`, `level`, `qp`)
      VALUES (%s,%s,%s,%s,%s)
      ON DUPLICATE KEY UPDATE
        name=VALUES(name),
        level=VALUES(level),
        qp=VALUES(qp)
    """
    
    

    for rankx, rec in enumerate(qp_list[:100]):
        rank_num = rankx + 1
        if isinstance(rec, dict):
            name  = rec.get("name")
            level = rec.get("level")
            qp    = rec.get("qp")
        else:
            name = level = qp = None

        values = (ts, rank_num, name, level, qp)  # ✅ 關鍵補上
        cursor.execute(insert_sql, values)

    db.commit()
    print(f"✅ 已匯入 {min(100, len(qp_list))} 筆資料到 {table_name}")
    clear_json(qp_path)


# ----------------------------------------------------------------
# 最後統一清空「merge 檔案」與「watcher 原始來源檔案」
# ----------------------------------------------------------------

FILES_TO_CLEAR = [
    # merge 結果檔（import 來源）
    "channel1_room.json",
    "channel3_room.json",

    # watcher TW/JP/CR 原始來源檔
    "channel1_room_TW.json",
    "channel1_room_JP.json",
    "channel3_room_CR.json",
]

base = Path(__file__).parent

for filename in FILES_TO_CLEAR:
    f = base / filename
    if f.exists():
        clear_json(f)