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
from collections import Counter
from decimal import Decimal, ROUND_HALF_UP
import subprocess
import platform

def stop_watcher():
    if platform.system() == "Linux":
        subprocess.run(
            ["systemctl", "stop", "unlight_watcher.service"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL
        )
        print("🛑 已停止 unlight_watcher.service")
    else:
        print("⚠️ 非 Linux 環境，跳過 stop_watcher")

def start_watcher():
    if platform.system() == "Linux":
        subprocess.run(
            ["systemctl", "start", "unlight_watcher.service"],
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL
        )
        print("▶️ 已啟動 unlight_watcher.service")
    else:
        print("⚠️ 非 Linux 環境，跳過 start_watcher")

def pick_top2_events(events):
    """
    events: list[int | None]
    回傳：(event1, event2) 允許為 None
    """

    if not events:
        return None, None

    # ① 過濾掉 None / 非 int
    clean = [e for e in events if isinstance(e, int)]

    if not clean:
        return None, None

    # ② 統計次數
    counter = {}
    for e in clean:
        counter[e] = counter.get(e, 0) + 1

    # ③ 依出現次數排序（只比 int，不會炸）
    ranked = sorted(
        counter.items(),
        key=lambda x: x[1],
        reverse=True
    )

    # ④ 取前兩名（不足就補 None）
    ev1 = ranked[0][0] if len(ranked) >= 1 else None
    ev2 = ranked[1][0] if len(ranked) >= 2 else None

    return ev1, ev2

# ----------------------------------------------------------------
# Protocol 1.4 room/deck -> legacy DB schema compatibility
# ----------------------------------------------------------------
# 2026-09-23 protocol 1.4 changed deck fields from:
#   charaIndex / weapon / eventIndex
# to:
#   chara_card_id / weapon_card_id / event_card_id
#
# Character card IDs retain the legacy relation: charaIndex = chara_card_id - 1.
# Weapon/Event IDs are explicit card IDs and MUST NOT be converted with -1.
# The maps below were verified against the legacy asset tables.
# Unknown future IDs are never guessed: legacy slot becomes None and one warning is logged.

LEGACY_EVENT_INDEX_BY_CARD_ID = {1: 0, 2: 1, 3: 2, 4: 3, 5: 4, 6: 5, 7: 6, 8: 7, 9: 21, 10: 22, 11: 23, 12: 24, 13: 25, 14: 26, 15: 27, 16: 28, 17: 42, 18: 43, 19: 44, 20: 45, 21: 46, 22: 54, 23: 55, 24: 56, 25: 57, 26: 58, 27: 66, 28: 67, 29: 68, 30: 69, 31: 70, 32: 78, 33: 79, 34: 80, 35: 81, 36: 82, 37: 88, 38: 89, 39: 90, 40: 91, 42: 83, 43: 84, 44: 85, 45: 86, 46: 87, 47: 9, 48: 10, 49: 11, 50: 30, 51: 31, 52: 32, 53: 51, 54: 52, 55: 53, 56: 75, 57: 76, 58: 77, 59: 92, 60: 93, 61: 94, 62: 95, 63: 15, 64: 16, 65: 17, 66: 12, 67: 13, 68: 14, 69: 18, 70: 19, 71: 20, 72: 36, 73: 37, 74: 38, 75: 33, 76: 34, 77: 35, 78: 39, 79: 40, 80: 41, 81: 63, 82: 64, 83: 65, 88: 102, 92: 103, 93: 104, 94: 106, 100: 107, 101: 108, 103: 109, 105: 96, 106: 8, 107: 29, 108: 47, 109: 48, 110: 49, 111: 50, 112: 59, 113: 60, 114: 61, 115: 62, 116: 71, 117: 72, 118: 73, 119: 74, 120: 97, 121: 98, 122: 99, 123: 100, 124: 101, 125: 105}

LEGACY_WEAPON_INDEX_BY_CARD_ID = {1: 0, 2: 3, 3: 6, 6: 1, 7: 4, 8: 7, 11: 2, 12: 5, 13: 8, 16: 9, 17: 10, 18: 11, 19: 12, 20: 13, 21: 14, 26: 15, 27: 17, 28: 16, 29: 18, 30: 20, 31: 19, 32: 21, 33: 23, 34: 22, 35: 24, 36: 26, 37: 25, 38: 27, 39: 29, 40: 28, 41: 30, 42: 32, 43: 31, 44: 33, 45: 35, 46: 34, 47: 36, 48: 38, 49: 37, 50: 39, 51: 41, 52: 40, 53: 42, 54: 44, 55: 43, 56: 45, 57: 47, 58: 46, 59: 48, 60: 50, 61: 49, 62: 51, 63: 53, 64: 52, 65: 54, 66: 56, 67: 55, 68: 57, 69: 59, 70: 58, 71: 60, 72: 62, 73: 61, 74: 63, 75: 65, 76: 64, 77: 66, 78: 68, 79: 67, 80: 69, 81: 71, 82: 70, 83: 72, 84: 74, 85: 73, 86: 75, 87: 77, 88: 76, 89: 78, 90: 80, 91: 79, 92: 81, 93: 83, 94: 82, 95: 84, 96: 86, 97: 85, 98: 87, 99: 89, 100: 88, 101: 90, 102: 92, 103: 91, 104: 93, 105: 95, 106: 94, 107: 96, 108: 98, 109: 97, 110: 99, 111: 101, 112: 100, 113: 102, 114: 104, 115: 103, 116: 105, 117: 107, 118: 106, 119: 108, 120: 110, 121: 109, 122: 111, 123: 113, 124: 112, 125: 114, 126: 116, 127: 115, 128: 117, 129: 119, 130: 118, 131: 120, 132: 122, 133: 121, 134: 123, 135: 125, 136: 124, 137: 126, 138: 128, 139: 127, 140: 129, 141: 131, 142: 130, 143: 132, 144: 134, 145: 133, 146: 137, 147: 139, 148: 138, 149: 140, 150: 142, 151: 141, 152: 143, 153: 145, 154: 144, 155: 146, 156: 148, 157: 147, 158: 149, 159: 151, 160: 150, 161: 152, 162: 154, 163: 153, 165: 135, 166: 136, 167: 155, 168: 157, 169: 156, 170: 158, 171: 160, 172: 159, 173: 161, 174: 163, 175: 162, 176: 164, 177: 166, 178: 165, 179: 167, 180: 169, 181: 168, 182: 172, 183: 174, 184: 173, 185: 175, 186: 177, 187: 176, 189: 179, 190: 181, 191: 180, 193: 182, 194: 184, 195: 183, 196: 185, 197: 187, 198: 186, 199: 189, 200: 191, 201: 190, 205: 192, 206: 194, 207: 193, 209: 195, 210: 197, 211: 196, 212: 198, 213: 200, 214: 199, 215: 201, 216: 203, 217: 202, 218: 207, 219: 209, 220: 208, 221: 210, 222: 212, 224: 213, 225: 215, 228: 221, 229: 223, 230: 222, 232: 224, 233: 226, 234: 225, 235: 227, 236: 229, 237: 228, 239: 235, 268: 188, 270: 204, 271: 205, 272: 206, 273: 230, 274: 232, 275: 231, 276: 236, 277: 237, 5000: 216, 5005: 217, 5006: 218, 5007: 219, 5008: 220}

_WARNED_UNMAPPED_WEAPON_IDS = set()
_WARNED_UNMAPPED_EVENT_IDS = set()


def _is_int_id(value):
    return isinstance(value, int) and not isinstance(value, bool)


def normalize_deck_for_legacy(deck, room_id=None, side="?"):
    """Return a copy containing legacy charaIndex/weapon/eventIndex keys.

    Legacy decks pass through unchanged. Protocol 1.4 decks keep their raw new
    keys and gain compatibility keys consumed by the existing importer/ULGG DB code.
    """
    if not isinstance(deck, dict):
        return {}

    out = dict(deck)
    is_v14 = any(
        key in deck
        for key in ("chara_card_id", "weapon_card_id", "event_card_id")
    )

    if not is_v14:
        return out

    raw_chara = deck.get("chara_card_id", [])
    if not isinstance(raw_chara, list):
        raw_chara = []

    chara_index = []
    for card_id in raw_chara[:3]:
        if _is_int_id(card_id) and card_id > 0:
            chara_index.append(card_id - 1)
        else:
            chara_index.append(-1)

    while len(chara_index) < 3:
        chara_index.append(-1)

    raw_weapon = deck.get("weapon_card_id", [])
    if not isinstance(raw_weapon, list):
        raw_weapon = []

    weapon = []
    for card_id in raw_weapon[:3]:
        if card_id is None:
            weapon.append(None)
            continue

        if _is_int_id(card_id):
            legacy_index = LEGACY_WEAPON_INDEX_BY_CARD_ID.get(card_id)
            if legacy_index is not None:
                weapon.append(legacy_index)
                continue

            weapon.append(None)
            if card_id not in _WARNED_UNMAPPED_WEAPON_IDS:
                _WARNED_UNMAPPED_WEAPON_IDS.add(card_id)
                print(
                    f"⚠️ [SCHEMA 1.4] UNMAPPED_WEAPON_ID id={card_id} "
                    f"room={room_id or '-'} side={side} -> legacy weapon=NULL"
                )
            continue

        weapon.append(None)

    while len(weapon) < 3:
        weapon.append(None)

    raw_event = deck.get("event_card_id", [])
    if not isinstance(raw_event, list):
        raw_event = []

    event_index = []
    for card_id in raw_event:
        if card_id is None:
            event_index.append(None)
            continue

        if _is_int_id(card_id):
            legacy_index = LEGACY_EVENT_INDEX_BY_CARD_ID.get(card_id)
            if legacy_index is not None:
                event_index.append(legacy_index)
                continue

            event_index.append(None)
            if card_id not in _WARNED_UNMAPPED_EVENT_IDS:
                _WARNED_UNMAPPED_EVENT_IDS.add(card_id)
                print(
                    f"⚠️ [SCHEMA 1.4] UNMAPPED_EVENT_CARD_ID id={card_id} "
                    f"room={room_id or '-'} side={side} -> legacy eventIndex=NULL"
                )
            continue

        event_index.append(None)

    out["charaIndex"] = chara_index
    out["weapon"] = weapon
    out["eventIndex"] = event_index
    out["_schema_compat"] = "1.4->legacy"
    return out


def normalize_room_collection_for_legacy(room_data, source_name="room"):
    """Normalize only protocol-1.4 decks; supports mixed old/new room history."""
    if not isinstance(room_data, dict):
        return room_data

    converted_rooms = 0
    for fallback_room_id, room in room_data.items():
        if not isinstance(room, dict):
            continue

        room_id = room.get("room_id") or fallback_room_id
        converted = False

        for deck_key, side in (("deckA", "A"), ("deckB", "B")):
            deck = room.get(deck_key)
            if not isinstance(deck, dict):
                continue

            if any(
                key in deck
                for key in ("chara_card_id", "weapon_card_id", "event_card_id")
            ):
                room[deck_key] = normalize_deck_for_legacy(
                    deck,
                    room_id=room_id,
                    side=side,
                )
                converted = True

        if converted:
            converted_rooms += 1

    if converted_rooms:
        print(
            f"🔄 [SCHEMA 1.4] {source_name}："
            f"已相容轉換 {converted_rooms} 間新版房間；舊格式房間保持不變"
        )

    return room_data


# ----------------------------------------------------------------
# 1) 基本設定
# ----------------------------------------------------------------
# IMPORTER_EXPLICIT_ENV_PATH_V1
ENV_FILE = Path(__file__).resolve().parent.parent / ".env"
load_dotenv(dotenv_path=ENV_FILE)

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

# ⛔ 這裡立刻停 watcher（非常重要）
stop_watcher()
time.sleep(1.5)

# ARENA_DECK_COST_SPLIT_V1
def sync_arena_player_match_result(match_id):
    cursor.execute("""
        DELETE FROM arena_player_match_result
        WHERE match_id = %s
    """, (match_id,))

    cursor.execute("""
        INSERT IGNORE INTO arena_player_match_result (
            match_id, update_time, player_name, player_bp, side,
            leader_id, back1_id, back2_id, team_key, cost, eventindex, is_win
        )
        SELECT
            id,
            update_time,
            name_p1,
            bp_p1,
            'P1',
            e1,
            LEAST(e2, e3),
            GREATEST(e2, e3),
            CONCAT(e1, '-', LEAST(e2, e3), '-', GREATEST(e2, e3)),
            COALESCE(deck_cost_p1, cost),
            NULL,
            CASE
                WHEN tie = 1 THEN NULL
                WHEN lose = 1 THEN 1
                WHEN win = 1 THEN 0
                ELSE NULL
            END
        FROM arena_unlight
        WHERE id = %s
          AND name_p1 IS NOT NULL
          AND name_p1 <> ''
    """, (match_id,))

    cursor.execute("""
        INSERT IGNORE INTO arena_player_match_result (
            match_id, update_time, player_name, player_bp, side,
            leader_id, back1_id, back2_id, team_key, cost, eventindex, is_win
        )
        SELECT
            id,
            update_time,
            name_p2,
            bp_p2,
            'P2',
            u1,
            LEAST(u2, u3),
            GREATEST(u2, u3),
            CONCAT(u1, '-', LEAST(u2, u3), '-', GREATEST(u2, u3)),
            COALESCE(deck_cost_p2, cost),
            NULL,
            CASE
                WHEN tie = 1 THEN NULL
                WHEN win = 1 THEN 1
                WHEN lose = 1 THEN 0
                ELSE NULL
            END
        FROM arena_unlight
        WHERE id = %s
          AND name_p2 IS NOT NULL
          AND name_p2 <> ''
    """, (match_id,))

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
    # BACKUP_RETENTION_V1: keep normal *_backup JSON snapshots for a bounded window.
    # Default is 7 days; set ULGG_BACKUP_RETENTION_DAYS=0 to disable cleanup.
    try:
        retention_days = max(0, int(os.getenv("ULGG_BACKUP_RETENTION_DAYS", "7")))
    except ValueError:
        retention_days = 7
        print("WARN: invalid ULGG_BACKUP_RETENTION_DAYS; fallback to 7 days")

    if retention_days > 0:
        cutoff_ts = time.time() - retention_days * 86400
        deleted_count = 0
        freed_bytes = 0
        for old_bak in backup_dir.glob(f"{name}_backup_*.json"):
            try:
                st = old_bak.stat()
                if st.st_mtime < cutoff_ts:
                    freed_bytes += st.st_size
                    old_bak.unlink()
                    deleted_count += 1
            except OSError as exc:
                print(f"WARN: failed to remove old backup {old_bak.name}: {exc}")

        if deleted_count:
            print(
                f"BACKUP RETENTION {name}: keep={retention_days}d, "
                f"deleted={deleted_count}, freed={freed_bytes / 1024 / 1024:.1f}MB"
            )
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
room_data = normalize_room_collection_for_legacy(room_data, "channel1_room")    
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
                cursor.execute("UPDATE arena_unlight SET win=1, lose=0, tie=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"✅ 更新前一筆（{name}(勝) VS {prev['name_p1']} ID {prev['id']}）"
            else:
                cursor.execute("UPDATE arena_unlight SET lose=1,win=0, tie=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"✅ 更新前一筆（{name}(勝) VS {prev['name_p2']} ID {prev['id']}）"
        elif dl >= 1 and dw == 0 and dd == 0:
            if prev['name_p2'] == name:
                cursor.execute("UPDATE arena_unlight SET lose=1,win=0, tie=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"✅ 更新前一筆（{name}(負) VS {prev['name_p1']} ID {prev['id']}）"
            else:
                cursor.execute("UPDATE arena_unlight SET win=1, lose=0, tie=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"✅ 更新前一筆（{name}(負) VS {prev['name_p2']} ID {prev['id']}）"
        elif dd >= 1 and dw == 0 and dl == 0:
            cursor.execute("UPDATE arena_unlight SET tie=1, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
            sync_arena_player_match_result(prev['id'])
            result = f"✅ 更新前一筆平手（{name} VS {prev['name_p1'] if prev['name_p2'] == name else prev['name_p2']} ID {prev['id']}）"
        elif dd == 0 and dw == 0 and dl == 0:
            cursor.execute("DELETE FROM arena_player_match_result WHERE match_id = %s", (prev['id'],))
            cursor.execute("DELETE FROM arena_unlight WHERE id = %s", (prev['id'],))
            result = f"✅ 前筆記錄失效，已刪除（{name} VS {prev['name_p1'] if prev['name_p2'] == name else prev['name_p2']} ID {prev['id']}）"        
        else:
            if prev['name_p1'] == name:
                cursor.execute("UPDATE arena_unlight SET ack1=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"⚠️ 無法判斷勝負（{name} VS {prev['name_p2']} ID {prev['id']}），已標記該紀錄失效"
            else:
                cursor.execute("UPDATE arena_unlight SET ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"⚠️ 無法判斷勝負（{name} VS {prev['name_p1']} ID {prev['id']}），已標記該紀錄失效"

        db.commit()
        return result

    for room in room_data.values():
        # ❗ 加這段來跳過目標玩家
        #skip_names = {"VUP44085", "chipping5458","哈鮭熊","蝴蝶獵人","無限泡影","比爾可"}
        #if room.get("playerA", {}).get("name") in skip_names or room.get("playerB", {}).get("name") in skip_names:
        #    continue
        room_id = room.get("room_id")
        # ← 這裡加一行，把 cost 拿出來（如果沒這個欄位就給 None）
        raw_room_cost = room.get("cost", None)
        deck_cost_p1 = room.get("deckA", {}).get("cost", None)
        deck_cost_p2 = room.get("deckB", {}).get("cost", None)

        # ARENA_COST_COMPAT_V1
        # arena_unlight.cost 是舊網站使用的「對戰 COST」。
        # Protocol 1.4 的 room.cost 已變成 0/5 等代碼，
        # 因此改由雙方實際 deck COST 產生相容值。
        if (
            isinstance(deck_cost_p1, int)
            and isinstance(deck_cost_p2, int)
        ):
            if deck_cost_p1 >= 90 and deck_cost_p2 >= 90:
                cost = 90
            elif deck_cost_p1 == deck_cost_p2:
                cost = deck_cost_p1
            else:
                cost = None
                print(
                    "⚠️ [COST] unequal sub-90 deck COST "
                    f"room={room.get('room_id')} "
                    f"P1={deck_cost_p1} P2={deck_cost_p2}"
                )
        else:
            # 舊協定 fallback
            cost = raw_room_cost

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
                room_id, region, cost, deck_cost_p1, deck_cost_p2, stage,
                name_p1, bp_p1, win_p1, draw_p1, lose_p1,
                e1, e2, e3, w1, w2, w3, eventindex1,
                name_p2, bp_p2, win_p2, draw_p2, lose_p2,
                u1, u2, u3, v1, v2, v3, eventindex2,
                update_time, username
            ) VALUES (
                %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s
            )
        """, (
            room_id,
            room.get("region", ""),   # ⭐ 新增 region
            cost, deck_cost_p1, deck_cost_p2, stage,
            nameA, bpA, winA, drawA, loseA,
            *e, *w, eventindex1,
            nameB, bpB, winB, drawB, loseB,
            *u, *v, eventindex2,
            update_time,
            "way.lee_VPS"
        ))
        match_id = cursor.lastrowid   # ← arena_unlight.id（只取一次）

        # -----------------------------
        # arena_deck_event - P1
        # -----------------------------
        events_A = room.get("deckA", {}).get("eventIndex", [])
        ev1_A, ev2_A = pick_top2_events(events_A)

        if ev1_A is not None:
            cursor.execute("""
                INSERT IGNORE INTO arena_deck_event
                (match_id, side, event_id1, event_id2)
                VALUES (%s, 'P1', %s, %s)
            """, (match_id, ev1_A, ev2_A))

        # -----------------------------
        # arena_deck_event - P2
        # -----------------------------
        events_B = room.get("deckB", {}).get("eventIndex", [])
        ev1_B, ev2_B = pick_top2_events(events_B)

        if ev1_B is not None:
            cursor.execute("""
                INSERT IGNORE INTO arena_deck_event
                (match_id, side, event_id1, event_id2)
                VALUES (%s, 'P2', %s, %s)
            """, (match_id, ev1_B, ev2_B))

        # ⭐ 新增這行：新場次一建立，就先同步 P1 / P2 到 arena_player_match_result
        sync_arena_player_match_result(match_id)

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
room_data = normalize_room_collection_for_legacy(room_data, "channel3_room")    
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
                cursor.execute("UPDATE arena_unlight SET win=1, lose=0, tie=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"✅ 更新前一筆（{name}(勝) VS {prev['name_p1']} ID {prev['id']}）"
            else:
                cursor.execute("UPDATE arena_unlight SET lose=1,win=0, tie=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"✅ 更新前一筆（{name}(勝) VS {prev['name_p2']} ID {prev['id']}）"
        elif dl >= 1 and dw == 0 and dd == 0:
            if prev['name_p2'] == name:
                cursor.execute("UPDATE arena_unlight SET lose=1,win=0, tie=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"✅ 更新前一筆（{name}(負) VS {prev['name_p1']} ID {prev['id']}）"
            else:
                cursor.execute("UPDATE arena_unlight SET win=1, lose=0, tie=0, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"✅ 更新前一筆（{name}(負) VS {prev['name_p2']} ID {prev['id']}）"
        elif dd >= 1 and dw == 0 and dl == 0:
            cursor.execute("UPDATE arena_unlight SET tie=1, ack1=1, ack2=1 WHERE id=%s", (prev['id'],))
            sync_arena_player_match_result(prev['id'])
            result = f"✅ 更新前一筆平手（{name} VS {prev['name_p1'] if prev['name_p2'] == name else prev['name_p2']} ID {prev['id']}）"
        elif dd == 0 and dw == 0 and dl == 0:
            cursor.execute("DELETE FROM arena_player_match_result WHERE match_id = %s", (prev['id'],))
            cursor.execute("DELETE FROM arena_unlight WHERE id = %s", (prev['id'],))
            result = f"✅ 前筆記錄失效，已刪除（{name} VS {prev['name_p1'] if prev['name_p2'] == name else prev['name_p2']} ID {prev['id']}）"        
        else:
            if prev['name_p1'] == name:
                cursor.execute("UPDATE arena_unlight SET ack1=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"⚠️ 無法判斷勝負（{name} VS {prev['name_p2']} ID {prev['id']}），已標記該紀錄失效"
            else:
                cursor.execute("UPDATE arena_unlight SET ack2=1 WHERE id=%s", (prev['id'],))
                sync_arena_player_match_result(prev['id'])
                result = f"⚠️ 無法判斷勝負（{name} VS {prev['name_p1']} ID {prev['id']}），已標記該紀錄失效"

        db.commit()
        return result

    for room in room_data.values():
        # ❗ 加這段來跳過目標玩家
        #skip_names = {"VUP44085", "chipping5458","哈鮭熊","蝴蝶獵人","無限泡影","比爾可"}
        #if room.get("playerA", {}).get("name") in skip_names or room.get("playerB", {}).get("name") in skip_names:
        #    continue
        room_id = room.get("room_id")
        # ← 這裡加一行，把 cost 拿出來（如果沒這個欄位就給 None）
        raw_room_cost = room.get("cost", None)
        deck_cost_p1 = room.get("deckA", {}).get("cost", None)
        deck_cost_p2 = room.get("deckB", {}).get("cost", None)

        # ARENA_COST_COMPAT_V1
        # arena_unlight.cost 是舊網站使用的「對戰 COST」。
        # Protocol 1.4 的 room.cost 已變成 0/5 等代碼，
        # 因此改由雙方實際 deck COST 產生相容值。
        if (
            isinstance(deck_cost_p1, int)
            and isinstance(deck_cost_p2, int)
        ):
            if deck_cost_p1 >= 90 and deck_cost_p2 >= 90:
                cost = 90
            elif deck_cost_p1 == deck_cost_p2:
                cost = deck_cost_p1
            else:
                cost = None
                print(
                    "⚠️ [COST] unequal sub-90 deck COST "
                    f"room={room.get('room_id')} "
                    f"P1={deck_cost_p1} P2={deck_cost_p2}"
                )
        else:
            # 舊協定 fallback
            cost = raw_room_cost

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
                room_id, region, cost, deck_cost_p1, deck_cost_p2, stage,
                name_p1, bp_p1, win_p1, draw_p1, lose_p1,
                e1, e2, e3, w1, w2, w3, eventindex1,
                name_p2, bp_p2, win_p2, draw_p2, lose_p2,
                u1, u2, u3, v1, v2, v3, eventindex2,
                update_time, username
            ) VALUES (
                %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,
                %s, %s
            )
        """, (
            room_id,
            room.get("region", ""),
            cost, deck_cost_p1, deck_cost_p2, stage,
            nameA, bpA, winA, drawA, loseA,
            *e, *w, eventindex1,
            nameB, bpB, winB, drawB, loseB,
            *u, *v, eventindex2,
            update_time,
            "way.lee_VPS"
        ))

        match_id = cursor.lastrowid   # ← arena_unlight.id（只取一次）

        # -----------------------------
        # arena_deck_event - P1
        # -----------------------------
        events_A = room.get("deckA", {}).get("eventIndex", [])
        ev1_A, ev2_A = pick_top2_events(events_A)

        if ev1_A is not None:
            cursor.execute("""
                INSERT IGNORE INTO arena_deck_event
                (match_id, side, event_id1, event_id2)
                VALUES (%s, 'P1', %s, %s)
            """, (match_id, ev1_A, ev2_A))

        # -----------------------------
        # arena_deck_event - P2
        # -----------------------------
        events_B = room.get("deckB", {}).get("eventIndex", [])
        ev1_B, ev2_B = pick_top2_events(events_B)

        if ev1_B is not None:
            cursor.execute("""
                INSERT IGNORE INTO arena_deck_event
                (match_id, side, event_id1, event_id2)
                VALUES (%s, 'P2', %s, %s)
            """, (match_id, ev1_B, ev2_B))

        # ⭐ 新增這行：新場次一建立，就先同步 P1 / P2 到 arena_player_match_result
        sync_arena_player_match_result(match_id)

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
# Ranking legacy / protocol-1.4 compatibility
# ----------------------------------------------------------------
# protocol 1.4:
#   {"data": [{"player_name": "...", "point": 1234}, ...], "update_at": <epoch ms>}
# legacy:
#   {"ranks": [{"name": "...", "bp"/"qp": ..., ...}, ...], "updatedAt": <ISO>}
#
# IMPORTANT:
# Protocol 1.4 no longer supplies level or ranked W/D/L. We do not carry old
# values forward and do not fabricate zeroes. New snapshots store these fields
# as NULL so they cannot be mistaken for current ranking data.

def _ranking_timestamp_to_tw(value):
    """Convert ranking updatedAt/update_at into a Taipei minute timestamp."""
    dt_utc = None

    if isinstance(value, (int, float)) and not isinstance(value, bool):
        try:
            seconds = float(value)
            if abs(seconds) >= 10_000_000_000:  # epoch milliseconds
                seconds /= 1000.0
            dt_utc = datetime.fromtimestamp(seconds, tz=timezone.utc)
        except (ValueError, OSError, OverflowError):
            dt_utc = None

    elif isinstance(value, str):
        value = value.strip()
        if value:
            try:
                number = float(value)
            except ValueError:
                number = None

            if number is not None:
                try:
                    if abs(number) >= 10_000_000_000:
                        number /= 1000.0
                    dt_utc = datetime.fromtimestamp(number, tz=timezone.utc)
                except (ValueError, OSError, OverflowError):
                    dt_utc = None
            else:
                try:
                    dt_utc = datetime.fromisoformat(value.replace("Z", "+00:00"))
                    if dt_utc.tzinfo is None:
                        dt_utc = dt_utc.replace(tzinfo=timezone.utc)
                    else:
                        dt_utc = dt_utc.astimezone(timezone.utc)
                except ValueError:
                    dt_utc = None

    if dt_utc is None:
        dt_utc = datetime.now(timezone.utc)

    return (dt_utc + timedelta(hours=8)).replace(
        second=0,
        microsecond=0,
    ).strftime("%Y-%m-%d %H:%M:%S")


def _normalize_ranking_payload(payload, score_key):
    """Return (rows, ts, schema) for both legacy and protocol-1.4 ranking JSON."""
    if isinstance(payload, dict) and isinstance(payload.get("data"), list):
        raw_rows = payload.get("data", [])
        updated_at = payload.get("update_at")
        schema = "1.4"
    elif isinstance(payload, dict):
        raw_rows = payload.get("ranks", [])
        updated_at = payload.get("updatedAt")
        schema = "legacy"
    elif isinstance(payload, list):
        raw_rows = payload
        updated_at = raw_rows[-1] if raw_rows and isinstance(raw_rows[-1], str) else None
        schema = "legacy-list"
    else:
        raw_rows = []
        updated_at = None
        schema = "unknown"

    rows = []
    for rec in raw_rows:
        if not isinstance(rec, dict):
            continue

        if schema == "1.4":
            name = rec.get("player_name")
            point = rec.get("point")
            level = None
            win_ranked = None
            lose_ranked = None
            draw_ranked = None
        else:
            name = rec.get("name")
            point = rec.get(score_key)
            level = rec.get("level")
            win_ranked = rec.get("win_ranked")
            lose_ranked = rec.get("lose_ranked")
            draw_ranked = rec.get("draw_ranked")

        if name is None or point is None:
            continue

        name = str(name).strip()
        if not name:
            continue

        try:
            point = int(point)
        except (TypeError, ValueError):
            continue

        rows.append({
            "name": name,
            score_key: point,
            "level": level,
            "win_ranked": win_ranked,
            "lose_ranked": lose_ranked,
            "draw_ranked": draw_ranked,
        })

    return rows, _ranking_timestamp_to_tw(updated_at), schema


def _delete_old_ranking_snapshots(table_name, ts):
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
            AND TIME(ts) != '10:00:00'  # MONTHLY_SETTLEMENT_KEEP_1000
            ORDER BY ts ASC, rank_num ASC
            LIMIT 100
        ) AS tmp
    )
    """
    cursor.execute(sql_delete, (ts,))
    print(
        f"🧹 SQL：已刪除 {cursor.rowcount} 筆前 100 中非 00:00:00 的 "
        f"{table_name} 資料"
    )


# RANK_MAINTENANCE_SPLIT_BP_QP_V2
def _handle_ranking_zero_reset(kind, rows, ts, ranking_schema):
    """Release BP/QP maintenance independently when that ranking itself is all zero."""
    if ranking_schema != "1.4" or not rows:
        return

    score_key = kind.lower()
    try:
        is_all_zero = all(int(rec.get(score_key, -1)) == 0 for rec in rows)
    except (TypeError, ValueError):
        is_all_zero = False

    if not is_all_zero:
        return

    base = Path(__file__).parent
    maintenance_flag = base / f"ranking_{kind}_maintenance.flag"

    settlement_date = ts[:10]
    if maintenance_flag.exists():
        try:
            flag_text = maintenance_flag.read_text(encoding="utf-8")
            m = re.search(r"settlement=(\d{4}-\d{2}-\d{2})", flag_text)
            if m:
                settlement_date = m.group(1)
        except Exception:
            pass

    reset_dt = datetime.strptime(ts, "%Y-%m-%d %H:%M:%S") - timedelta(seconds=1)
    runtime_marker = base / f"ranking_{kind}_season_started_TW.txt"
    runtime_marker.write_text(
        reset_dt.strftime("%Y-%m-%d %H:%M:%S") + "\n",
        encoding="utf-8",
    )

    season_marker = base / f"ranking_{kind}_season_started_{settlement_date}.flag"
    season_marker.write_text(
        f"detected_zero_snapshot={ts}\nrows={len(rows)}\n",
        encoding="utf-8",
    )

    label = kind.upper()
    if maintenance_flag.exists():
        maintenance_flag.unlink()
        print(
            f"🟢 {label} 榜單已清零：{ts} ({len(rows)} rows) "
            f"→ {label} 新賽季開始，解除 {label} 排行榜維護屏蔽"
        )
    else:
        print(f"🟢 {label} 榜單清零狀態已確認：{ts} ({len(rows)} rows)")


# ----------------------------------------------------------------
# 上傳 ranking_bp_TW（JP / DMM 已封存）
# JP_IMPORT_FINAL_RETIRE_20260930_V3
# ----------------------------------------------------------------
sources = [
    ("ranking_bp_TW", "ranking_bp_TW"),
]

for source_name, table_name in sources:
    bp_data, bp_path = backup_and_load(source_name)
    bp_list, ts, ranking_schema = _normalize_ranking_payload(bp_data, "bp")

    if not bp_list:
        print(f"⚠️ {source_name}.json 無有效 BP 排行資料 → 跳過 DB 寫入及清空原檔")
        continue

    _handle_ranking_zero_reset("bp", bp_list, ts, ranking_schema)

    _delete_old_ranking_snapshots(table_name, ts)

    if ranking_schema == "1.4":
        insert_sql = f"""
        INSERT INTO `{table_name}`
            (`ts`, `rank_num`, `name`, `level`, `bp`, `win_ranked`, `lose_ranked`, `draw_ranked`)
        VALUES (%s,%s,%s,NULL,%s,NULL,NULL,NULL)
        ON DUPLICATE KEY UPDATE
            name=VALUES(name),
            level=NULL,
            bp=VALUES(bp),
            win_ranked=NULL,
            lose_ranked=NULL,
            draw_ranked=NULL
        """

        for rankx, rec in enumerate(bp_list[:100]):
            cursor.execute(
                insert_sql,
                (ts, rankx + 1, rec["name"], rec["bp"]),
            )
    else:
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
            cursor.execute(
                insert_sql,
                (
                    ts,
                    rankx + 1,
                    rec["name"],
                    rec.get("level"),
                    rec["bp"],
                    rec.get("win_ranked"),
                    rec.get("lose_ranked"),
                    rec.get("draw_ranked"),
                ),
            )

    db.commit()
    print(
        f"✅ 已匯入 {min(100, len(bp_list))} 筆資料到 {table_name} "
        f"(ranking schema={ranking_schema}"
        + ("; level/WDL=NULL" if ranking_schema == "1.4" else "")
        + ")"
    )
    clear_json(bp_path)


# ----------------------------------------------------------------
# 上傳 ranking_qp_TW（JP / DMM 已封存）
# JP_IMPORT_FINAL_RETIRE_20260930_V3
# ----------------------------------------------------------------
sources = [
    ("ranking_qp_TW", "ranking_qp_TW"),
]

for source_name, table_name in sources:
    qp_data, qp_path = backup_and_load(source_name)
    qp_list, ts, ranking_schema = _normalize_ranking_payload(qp_data, "qp")

    if not qp_list:
        print(f"⚠️ {source_name}.json 無有效 QP 排行資料 → 跳過 DB 寫入及清空原檔")
        continue

    _handle_ranking_zero_reset("qp", qp_list, ts, ranking_schema)

    _delete_old_ranking_snapshots(table_name, ts)

    if ranking_schema == "1.4":
        insert_sql = f"""
        INSERT INTO `{table_name}`
            (`ts`, `rank_num`, `name`, `level`, `qp`)
        VALUES (%s,%s,%s,NULL,%s)
        ON DUPLICATE KEY UPDATE
            name=VALUES(name),
            level=NULL,
            qp=VALUES(qp)
        """

        for rankx, rec in enumerate(qp_list[:100]):
            cursor.execute(
                insert_sql,
                (ts, rankx + 1, rec["name"], rec["qp"]),
            )
    else:
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
            cursor.execute(
                insert_sql,
                (
                    ts,
                    rankx + 1,
                    rec["name"],
                    rec.get("level"),
                    rec["qp"],
                ),
            )

    db.commit()
    print(
        f"✅ 已匯入 {min(100, len(qp_list))} 筆資料到 {table_name} "
        f"(ranking schema={ranking_schema}"
        + ("; level=NULL" if ranking_schema == "1.4" else "")
        + ")"
    )
    clear_json(qp_path)


# ----------------------------------------------------------------
# 最後統一清空「merge 檔案」與「watcher 原始來源檔案」
# ----------------------------------------------------------------

# CR_IMPORT_PAUSE_20260930_V1
# CR Ch3/Ch4 JSON 保留歷史內容，不再被 importer 清空，
# 避免 stale archive mtime 被誤認成新資料。
FILES_TO_CLEAR = [
    # merge 結果檔（import 來源）
    "channel1_room.json",

    # watcher 現役來源檔
    "channel1_room_TW.json",

    # JP_IMPORT_FINAL_RETIRE_20260930_V3
    # channel1_room_JP.json 保留最後封存內容，不再 clear_json()。
]

base = Path(__file__).parent

for filename in FILES_TO_CLEAR:
    f = base / filename
    if f.exists():
        clear_json(f)
        
# ----------------------------------------------------------------
# ULGG Custom：READY 場次先寫入 arena_unlight_custom
# 第一階段只負責建立待判定紀錄，不改動 arena_unlight。
# ----------------------------------------------------------------

def ulgg_parse_audit(raw):
    if isinstance(raw, dict):
        return raw

    if not isinstance(raw, str) or not raw.strip():
        return {}

    try:
        value = json.loads(raw)
    except (JSONDecodeError, TypeError):
        return {}

    return value if isinstance(value, dict) else {}


def ulgg_final_cost(audit):
    value = audit.get("final_cost")

    if isinstance(value, bool):
        return None

    if isinstance(value, (int, float)):
        return int(value)

    if isinstance(value, str):
        value = value.strip()

        if value.lstrip("-").isdigit():
            return int(value)

    return None


def ulgg_three_character_ids(deck):
    raw = deck.get("charaIndex", [-1, -1, -1])

    if not isinstance(raw, list):
        raw = []

    values = []

    for item in raw[:3]:
        if isinstance(item, int) and not isinstance(item, bool):
            values.append(item + 1)
        else:
            values.append(0)

    while len(values) < 3:
        values.append(0)

    return values


def ulgg_three_optional_ids(deck, key):
    raw = deck.get(key, [None, None, None])

    if not isinstance(raw, list):
        raw = []

    values = []

    for item in raw[:3]:
        if isinstance(item, int) and not isinstance(item, bool):
            values.append(item)
        else:
            values.append(None)

    while len(values) < 3:
        values.append(None)

    return values


def ulgg_room_update_time(room, fallback_time=None):
    timestamp = room.get("date")

    if isinstance(timestamp, (int, float)):
        return (
            datetime.fromtimestamp(
                timestamp / 1000,
                tz=timezone.utc
            )
            + timedelta(hours=8)
        ).strftime("%Y-%m-%d %H:%M:%S")

    if isinstance(fallback_time, datetime):
        return fallback_time.strftime("%Y-%m-%d %H:%M:%S")

    if isinstance(fallback_time, str) and fallback_time.strip():
        return fallback_time.strip()

    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")

def ulgg_php_round(value):
    """
    模擬 PHP round(value, 0) 的一般四捨五入。
    避免 Python round() 的 bankers rounding。
    """
    return int(
        Decimal(str(value)).quantize(
            Decimal("1"),
            rounding=ROUND_HALF_UP
        )
    )


def ulgg_calculate_cp(
    own_cp,
    opponent_cp,
    result_score,
):
    """
    result_score:
      勝 = 1.0
      平 = 0.5
      負 = 0.0
    """

    expected_score = 1 / (
        1 + 10 ** (
            (opponent_cp - own_cp) / 500
        )
    )

    return ulgg_php_round(
        own_cp
        + 32 * (
            result_score - expected_score
        )
    )


def ulgg_extract_wdl(player):
    if not isinstance(player, dict):
        return None

    values = (
        player.get("win"),
        player.get("draw"),
        player.get("lose"),
    )

    if not all(
        isinstance(value, int)
        and not isinstance(value, bool)
        for value in values
    ):
        return None

    return values


def ulgg_wdl_delta(current, previous):
    if current is None:
        return None

    if not all(
        isinstance(value, int)
        and not isinstance(value, bool)
        for value in previous
    ):
        return None

    return (
        current[0] - previous[0],
        current[1] - previous[1],
        current[2] - previous[2],
    )
    
def import_ulgg_custom_ready_matches(room_data):
    """
    將 custom_matches 中 READY 且尚未匯入的場次，
    依 game_room_id 對應 channel2_room.json，
    寫入 arena_unlight_custom。
    """

    if not isinstance(room_data, dict) or not room_data:
        print(
            "⚠️ ULGG Custom：channel2_room "
            "無有效資料，跳過 READY 匯入"
        )
        return

    rooms_by_id = {}

    for room in room_data.values():
        if not isinstance(room, dict):
            continue

        room_id = str(room.get("room_id") or "").strip()

        if room_id:
            rooms_by_id[room_id] = room

    cursor.execute("""
        SELECT
            m.id,
            m.rule_version_id,
            m.game_room_id,
            m.player1_game_name,
            m.player2_game_name,
            m.player1_deck_audit_json,
            m.player2_deck_audit_json,
            m.deck_validated_at
        FROM custom_matches AS m

        LEFT JOIN arena_unlight_custom AS c
          ON c.ulgg_match_id = m.id

        WHERE m.match_status = 'READY'
          AND m.game_room_id IS NOT NULL
          AND m.game_room_id <> ''
          AND c.id IS NULL

        ORDER BY m.id ASC
    """)

    matches = cursor.fetchall()

    inserted = 0
    skipped = 0
    missing_room = 0
    invalid = 0

    for match in matches:
        ulgg_match_id = int(match["id"])

        room_id = str(
            match.get("game_room_id") or ""
        ).strip()

        rule_version_id = str(
            match.get("rule_version_id") or ""
        ).strip()

        room = rooms_by_id.get(room_id)

        if room is None:
            print(
                f"⚠️ ULGG Custom：Match {ulgg_match_id} "
                f"找不到 channel2_room snapshot，"
                f"room_id={room_id}"
            )

            missing_room += 1
            continue

        player_a = room.get("playerA")
        player_b = room.get("playerB")
        deck_a = room.get("deckA")
        deck_b = room.get("deckB")

        if not all(
            isinstance(value, dict)
            for value in (
                player_a,
                player_b,
                deck_a,
                deck_b
            )
        ):
            print(
                f"⚠️ ULGG Custom：Match {ulgg_match_id} "
                "玩家或牌組結構不完整"
            )

            invalid += 1
            continue

        name_a = str(
            player_a.get("name") or ""
        ).strip()

        name_b = str(
            player_b.get("name") or ""
        ).strip()

        match_player1 = str(
            match.get("player1_game_name") or ""
        ).strip()

        match_player2 = str(
            match.get("player2_game_name") or ""
        ).strip()

        if not name_a or not name_b or not rule_version_id:
            print(
                f"⚠️ ULGG Custom：Match {ulgg_match_id} "
                "缺少玩家名稱或 rule_version_id"
            )

            invalid += 1
            continue

        audit_player1 = ulgg_parse_audit(
            match.get("player1_deck_audit_json")
        )

        audit_player2 = ulgg_parse_audit(
            match.get("player2_deck_audit_json")
        )

        # arena_unlight_custom 的 P1 / P2
        # 必須依遊戲 snapshot 的 playerA / playerB 排列。
        if (
            name_a == match_player1
            and name_b == match_player2
        ):
            ruleset_cost_p1 = ulgg_final_cost(
                audit_player1
            )

            ruleset_cost_p2 = ulgg_final_cost(
                audit_player2
            )

        elif (
            name_a == match_player2
            and name_b == match_player1
        ):
            ruleset_cost_p1 = ulgg_final_cost(
                audit_player2
            )

            ruleset_cost_p2 = ulgg_final_cost(
                audit_player1
            )

        else:
            print(
                f"⚠️ ULGG Custom：Match {ulgg_match_id} "
                f"玩家名稱不吻合："
                f"snapshot=({name_a}, {name_b})，"
                f"match=({match_player1}, {match_player2})"
            )

            invalid += 1
            continue

        if (
            ruleset_cost_p1 is None
            or ruleset_cost_p2 is None
        ):
            print(
                f"⚠️ ULGG Custom：Match {ulgg_match_id} "
                "缺少已驗證 final_cost"
            )

            invalid += 1
            continue

        e1, e2, e3 = ulgg_three_character_ids(
            deck_a
        )

        u1, u2, u3 = ulgg_three_character_ids(
            deck_b
        )

        w1, w2, w3 = ulgg_three_optional_ids(
            deck_a,
            "weapon"
        )

        v1, v2, v3 = ulgg_three_optional_ids(
            deck_b,
            "weapon"
        )

        eventindex1 = json.dumps(
            deck_a.get("eventIndex", []),
            ensure_ascii=False
        )

        eventindex2 = json.dumps(
            deck_b.get("eventIndex", []),
            ensure_ascii=False
        )

        update_time = ulgg_room_update_time(
            room,
            match.get("deck_validated_at")
        )

        cursor.execute("""
            INSERT IGNORE INTO arena_unlight_custom (
                ulgg_match_id,
                rule_version_id,
                ruleset_name,
                ruleset_content_hash,

                room_id,
                region,
                cost,
                ruleset_cost_p1,
                ruleset_cost_p2,
                stage,

                name_p1,
                bp_p1,
                win_p1,
                draw_p1,
                lose_p1,

                e1,
                e2,
                e3,
                w1,
                w2,
                w3,
                eventindex1,

                name_p2,
                bp_p2,
                win_p2,
                draw_p2,
                lose_p2,

                u1,
                u2,
                u3,
                v1,
                v2,
                v3,
                eventindex2,

                win,
                lose,
                tie,
                ack1,
                ack2,

                update_time,
                username
            ) VALUES (
                %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s,

                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,

                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s, %s,

                0, 0, 0, 0, 0,

                %s, %s
            )
        """, (
            ulgg_match_id,
            rule_version_id,

            # 尚未有正式來源，先不猜值。
            None,
            None,

            room_id,
            room.get("region", ""),
            room.get("cost"),
            ruleset_cost_p1,
            ruleset_cost_p2,
            room.get("stage"),

            name_a,
            player_a.get("bp", 0),
            player_a.get("win", 0),
            player_a.get("draw", 0),
            player_a.get("lose", 0),

            e1,
            e2,
            e3,
            w1,
            w2,
            w3,
            eventindex1,

            name_b,
            player_b.get("bp", 0),
            player_b.get("win", 0),
            player_b.get("draw", 0),
            player_b.get("lose", 0),

            u1,
            u2,
            u3,
            v1,
            v2,
            v3,
            eventindex2,

            update_time,
            "ULGG_IMPORT"
        ))

        if cursor.rowcount == 1:
            inserted += 1

            print(
                f"✅ ULGG Custom：已匯入 "
                f"Match {ulgg_match_id}，"
                f"{name_a} VS {name_b}，"
                f"原始 COST={room.get('cost')}，"
                f"規則 COST="
                f"{ruleset_cost_p1}/"
                f"{ruleset_cost_p2}"
            )
        else:
            skipped += 1

            print(
                f"ℹ️ ULGG Custom："
                f"Match {ulgg_match_id} 已存在，跳過"
            )

    db.commit()

    print(
        "📊 ULGG Custom READY 匯入結果："
        f"新增 {inserted}、"
        f"跳過 {skipped}、"
        f"缺 snapshot {missing_room}、"
        f"資料異常 {invalid}"
    )
    
def update_ulgg_custom_results_and_cp(room_data):
    """
    依 channel2_room.json 的目前玩家累計戰績，
    與 arena_unlight_custom 建立時保存的戰績快照比對。

    判出勝負後：
    1. 更新 arena_unlight_custom
    2. 計算雙方本月 CP
    3. 寫入 CP history
    4. 更新 custom_matches 為 FINISHED
    """

    if not isinstance(room_data, dict) or not room_data:
        print(
            "⚠️ ULGG Custom：沒有可供勝負判定的 Room 2 資料"
        )
        return

    # 依玩家名稱建立所有 Room 2 snapshot 索引。
    # 後續判定不再使用原 room_id，而是尋找本場之後
    # 該玩家第一筆累計 W/D/L 有變化的 snapshot。
    player_snapshots = {}

    for fallback_room_id, room in room_data.items():
        if not isinstance(room, dict):
            continue

        room_date_ms = room.get("date")

        if not isinstance(room_date_ms, (int, float)):
            continue

        room_id = str(
            room.get("room_id")
            or fallback_room_id
            or ""
        ).strip()

        for side in ("playerA", "playerB"):
            player = room.get(side)

            if not isinstance(player, dict):
                continue

            player_name = str(
                player.get("name") or ""
            ).strip()

            current_wdl = ulgg_extract_wdl(player)

            if not player_name or current_wdl is None:
                continue

            player_snapshots.setdefault(
                player_name,
                []
            ).append({
                "date_ms": float(room_date_ms),
                "room_id": room_id,
                "wdl": current_wdl,
            })

    for snapshots in player_snapshots.values():
        snapshots.sort(
            key=lambda item: item["date_ms"]
        )

    def first_result_after(
        player_name,
        match_time,
        previous_wdl,
    ):
        """
        找到本場建立後，玩家第一筆 W/D/L 有變化的 snapshot。

        回傳：
          PLAYER_WIN  玩家勝
          PLAYER_LOSE 玩家敗
          DRAW        平手
          AMBIGUOUS   第一筆變化不是單一 +1
          None        尚無變化
        """
        snapshots = player_snapshots.get(
            player_name,
            []
        )

        match_timestamp_ms = (
            match_time.timestamp() * 1000
        )

        for snapshot in snapshots:
            if snapshot["date_ms"] <= match_timestamp_ms:
                continue

            delta = ulgg_wdl_delta(
                snapshot["wdl"],
                previous_wdl,
            )

            if delta is None:
                return "AMBIGUOUS"

            if delta == (0, 0, 0):
                continue

            if delta == (1, 0, 0):
                return "PLAYER_WIN"

            if delta == (0, 1, 0):
                return "DRAW"

            if delta == (0, 0, 1):
                return "PLAYER_LOSE"

            return "AMBIGUOUS"

        return None

    cursor.execute("""
        SELECT
            c.id,
            c.ulgg_match_id,
            c.room_id,

            c.name_p1,
            c.win_p1,
            c.draw_p1,
            c.lose_p1,

            c.name_p2,
            c.win_p2,
            c.draw_p2,
            c.lose_p2,

            c.win,
            c.lose,
            c.tie,
            c.ack1,
            c.ack2,
            c.update_time,

            m.player1_user_id,
            m.player2_user_id,
            m.player1_game_name,
            m.player2_game_name,
            m.match_status

        FROM arena_unlight_custom AS c

        INNER JOIN custom_matches AS m
          ON m.id = c.ulgg_match_id

        LEFT JOIN custom_match_cp_history AS h
          ON h.ulgg_match_id = c.ulgg_match_id

        WHERE h.id IS NULL
          AND (
                c.ack1 = 0
                OR c.ack2 = 0
          )
          AND c.room_id IS NOT NULL
          AND c.room_id <> ''
          AND m.match_status IN (
              'READY',
              'FINISHED'
          )

        ORDER BY c.id ASC
    """)

    pending_matches = cursor.fetchall()

    settled = 0
    waiting = 0
    invalid = 0
    missing_room = 0

    for custom_match in pending_matches:
        custom_id = int(custom_match["id"])

        ulgg_match_id = int(
            custom_match["ulgg_match_id"]
        )

        stored_name_p1 = str(
            custom_match.get("name_p1") or ""
        ).strip()

        stored_name_p2 = str(
            custom_match.get("name_p2") or ""
        ).strip()

        previous_p1_wdl = (
            custom_match.get("win_p1"),
            custom_match.get("draw_p1"),
            custom_match.get("lose_p1"),
        )

        previous_p2_wdl = (
            custom_match.get("win_p2"),
            custom_match.get("draw_p2"),
            custom_match.get("lose_p2"),
        )

        match_update_time = custom_match.get(
            "update_time"
        )

        if isinstance(match_update_time, str):
            try:
                match_update_time = datetime.strptime(
                    match_update_time,
                    "%Y-%m-%d %H:%M:%S",
                )
            except ValueError:
                match_update_time = None

        if not isinstance(match_update_time, datetime):
            print(
                f"⚠️ ULGG Custom：Match {ulgg_match_id} "
                "update_time 格式無效"
            )
            invalid += 1
            continue

        p1_player_result = first_result_after(
            stored_name_p1,
            match_update_time,
            previous_p1_wdl,
        )

        p2_player_result = first_result_after(
            stored_name_p2,
            match_update_time,
            previous_p2_wdl,
        )

        if (
            p1_player_result == "AMBIGUOUS"
            or p2_player_result == "AMBIGUOUS"
        ):
            print(
                f"⚠️ ULGG Custom：Match {ulgg_match_id} "
                f"後續 W/D/L 變化超過一場："
                f"P1={p1_player_result}，"
                f"P2={p2_player_result}"
            )
            invalid += 1
            continue

        result_code = None

        if p1_player_result == "PLAYER_WIN":
            result_code = "PLAYER1_WIN"
        elif p1_player_result == "PLAYER_LOSE":
            result_code = "PLAYER2_WIN"
        elif p1_player_result == "DRAW":
            result_code = "DRAW"

        p2_as_match_result = None

        if p2_player_result == "PLAYER_WIN":
            p2_as_match_result = "PLAYER2_WIN"
        elif p2_player_result == "PLAYER_LOSE":
            p2_as_match_result = "PLAYER1_WIN"
        elif p2_player_result == "DRAW":
            p2_as_match_result = "DRAW"

        # 單方有結果即可結算；雙方都有時只做衝突檢查。
        if result_code is None:
            result_code = p2_as_match_result
        elif (
            p2_as_match_result is not None
            and p2_as_match_result != result_code
        ):
            print(
                f"⚠️ ULGG Custom：Match {ulgg_match_id} "
                f"雙方 W/D/L 判定衝突："
                f"P1={result_code}，"
                f"P2={p2_as_match_result}"
            )
            invalid += 1
            continue

        if result_code is None:
            waiting += 1
            continue

        if result_code == "PLAYER1_WIN":
            arena_win = 0
            arena_lose = 1
            arena_tie = 0
            result_score_p1 = 1.0
            result_score_p2 = 0.0

        elif result_code == "PLAYER2_WIN":
            arena_win = 1
            arena_lose = 0
            arena_tie = 0
            result_score_p1 = 0.0
            result_score_p2 = 1.0

        else:
            arena_win = 0
            arena_lose = 0
            arena_tie = 1
            result_score_p1 = 0.5
            result_score_p2 = 0.5

        match_player1_name = str(
            custom_match.get(
                "player1_game_name"
            ) or ""
        ).strip()

        match_player2_name = str(
            custom_match.get(
                "player2_game_name"
            ) or ""
        ).strip()

        match_player1_user_id = int(
            custom_match["player1_user_id"]
        )

        match_player2_user_id = int(
            custom_match["player2_user_id"]
        )

        # arena P1/P2 不一定等於 custom_matches 的 Player1/Player2，
        # 必須依名稱映射會員 user_id。
        if (
            stored_name_p1 == match_player1_name
            and stored_name_p2 == match_player2_name
        ):
            arena_p1_user_id = match_player1_user_id
            arena_p2_user_id = match_player2_user_id

        elif (
            stored_name_p1 == match_player2_name
            and stored_name_p2 == match_player1_name
        ):
            arena_p1_user_id = match_player2_user_id
            arena_p2_user_id = match_player1_user_id

        else:
            print(
                f"⚠️ ULGG Custom：Match {ulgg_match_id} "
                "無法將遊戲玩家映射至會員 user_id"
            )

            invalid += 1
            continue

        result_datetime = datetime.now()

        season_month = result_datetime.strftime(
            "%Y-%m"
        )

        result_time = result_datetime.strftime(
            "%Y-%m-%d %H:%M:%S"
        )

        try:
            db.begin()

            # 防止同一場因 Import 重跑而重複計算。
            cursor.execute("""
                SELECT id
                FROM custom_match_cp_history
                WHERE ulgg_match_id = %s
                LIMIT 1
                FOR UPDATE
            """, (ulgg_match_id,))

            existing_history = cursor.fetchone()

            if existing_history:
                db.rollback()
                continue

            # 第一次參加該月份時，自動由 1500 開始。
            cursor.execute("""
                INSERT IGNORE INTO custom_match_cp_rating (
                    season_month,
                    user_id,
                    game_name,
                    cp,
                    wins,
                    draws,
                    losses,
                    matches
                ) VALUES (
                    %s,
                    %s,
                    %s,
                    1500,
                    0,
                    0,
                    0,
                    0
                )
            """, (
                season_month,
                arena_p1_user_id,
                stored_name_p1,
            ))

            cursor.execute("""
                INSERT IGNORE INTO custom_match_cp_rating (
                    season_month,
                    user_id,
                    game_name,
                    cp,
                    wins,
                    draws,
                    losses,
                    matches
                ) VALUES (
                    %s,
                    %s,
                    %s,
                    1500,
                    0,
                    0,
                    0,
                    0
                )
            """, (
                season_month,
                arena_p2_user_id,
                stored_name_p2,
            ))

            # 固定依 user_id 順序鎖定，降低同時結算時死鎖風險。
            locked_user_ids = sorted([
                arena_p1_user_id,
                arena_p2_user_id,
            ])

            cursor.execute("""
                SELECT
                    user_id,
                    cp
                FROM custom_match_cp_rating
                WHERE season_month = %s
                  AND user_id IN (%s, %s)
                ORDER BY user_id ASC
                FOR UPDATE
            """, (
                season_month,
                locked_user_ids[0],
                locked_user_ids[1],
            ))

            rating_rows = cursor.fetchall()

            ratings_by_user_id = {
                int(row["user_id"]): int(row["cp"])
                for row in rating_rows
            }

            if (
                arena_p1_user_id
                not in ratings_by_user_id
                or arena_p2_user_id
                not in ratings_by_user_id
            ):
                raise RuntimeError(
                    "無法取得雙方本月 CP。"
                )

            cp_p1_before = ratings_by_user_id[
                arena_p1_user_id
            ]

            cp_p2_before = ratings_by_user_id[
                arena_p2_user_id
            ]

            # 雙方都必須以同一組賽前 CP 計算。
            cp_p1_after = ulgg_calculate_cp(
                cp_p1_before,
                cp_p2_before,
                result_score_p1,
            )

            cp_p2_after = ulgg_calculate_cp(
                cp_p2_before,
                cp_p1_before,
                result_score_p2,
            )

            cp_p1_change = (
                cp_p1_after - cp_p1_before
            )

            cp_p2_change = (
                cp_p2_after - cp_p2_before
            )
            if arena_p1_user_id == match_player1_user_id:
                history_player1_user_id = arena_p1_user_id
                history_player1_cp_before = cp_p1_before
                history_player1_cp_change = cp_p1_change
                history_player1_cp_after = cp_p1_after

                history_player2_user_id = arena_p2_user_id
                history_player2_cp_before = cp_p2_before
                history_player2_cp_change = cp_p2_change
                history_player2_cp_after = cp_p2_after

                history_result = result_code

            else:
                history_player1_user_id = arena_p2_user_id
                history_player1_cp_before = cp_p2_before
                history_player1_cp_change = cp_p2_change
                history_player1_cp_after = cp_p2_after

                history_player2_user_id = arena_p1_user_id
                history_player2_cp_before = cp_p1_before
                history_player2_cp_change = cp_p1_change
                history_player2_cp_after = cp_p1_after

                history_result = {
                    "PLAYER1_WIN": "PLAYER2_WIN",
                    "PLAYER2_WIN": "PLAYER1_WIN",
                    "DRAW": "DRAW",
                }[result_code]

            p1_win_add = (
                1 if result_code == "PLAYER1_WIN"
                else 0
            )

            p1_draw_add = (
                1 if result_code == "DRAW"
                else 0
            )

            p1_loss_add = (
                1 if result_code == "PLAYER2_WIN"
                else 0
            )

            p2_win_add = (
                1 if result_code == "PLAYER2_WIN"
                else 0
            )

            p2_draw_add = (
                1 if result_code == "DRAW"
                else 0
            )

            p2_loss_add = (
                1 if result_code == "PLAYER1_WIN"
                else 0
            )

            cursor.execute("""
                UPDATE custom_match_cp_rating
                SET
                    game_name = %s,
                    cp = %s,
                    wins = wins + %s,
                    draws = draws + %s,
                    losses = losses + %s,
                    matches = matches + 1
                WHERE season_month = %s
                  AND user_id = %s
            """, (
                stored_name_p1,
                cp_p1_after,
                p1_win_add,
                p1_draw_add,
                p1_loss_add,
                season_month,
                arena_p1_user_id,
            ))

            if cursor.rowcount != 1:
                raise RuntimeError(
                    "更新 P1 CP 失敗。"
                )

            cursor.execute("""
                UPDATE custom_match_cp_rating
                SET
                    game_name = %s,
                    cp = %s,
                    wins = wins + %s,
                    draws = draws + %s,
                    losses = losses + %s,
                    matches = matches + 1
                WHERE season_month = %s
                  AND user_id = %s
            """, (
                stored_name_p2,
                cp_p2_after,
                p2_win_add,
                p2_draw_add,
                p2_loss_add,
                season_month,
                arena_p2_user_id,
            ))

            if cursor.rowcount != 1:
                raise RuntimeError(
                    "更新 P2 CP 失敗。"
                )

            cursor.execute("""
                INSERT INTO custom_match_cp_history (
                    ulgg_match_id,
                    season_month,

                    player1_user_id,
                    player1_cp_before,
                    player1_cp_change,
                    player1_cp_after,

                    player2_user_id,
                    player2_cp_before,
                    player2_cp_change,
                    player2_cp_after,

                    result,
                    calculated_at
                ) VALUES (
                    %s,
                    %s,

                    %s,
                    %s,
                    %s,
                    %s,

                    %s,
                    %s,
                    %s,
                    %s,

                    %s,
                    %s
                )
            """, (
                ulgg_match_id,
                season_month,

                history_player1_user_id,
                history_player1_cp_before,
                history_player1_cp_change,
                history_player1_cp_after,

                history_player2_user_id,
                history_player2_cp_before,
                history_player2_cp_change,
                history_player2_cp_after,

                history_result,
                result_time,
            ))

            cursor.execute("""
                UPDATE arena_unlight_custom
                SET
                    win = %s,
                    lose = %s,
                    tie = %s,
                    ack1 = 1,
                    ack2 = 1,
                    judge_source = 'ULGG_ROOM2_WDL',
                    is_read = 0
                WHERE id = %s
                  AND (
                        ack1 = 0
                        OR ack2 = 0
                  )
            """, (
                arena_win,
                arena_lose,
                arena_tie,
                custom_id,
            ))

            if cursor.rowcount != 1:
                raise RuntimeError(
                    "更新 ULGG Custom 勝負失敗。"
                )

            cursor.execute("""
                UPDATE custom_matches
                SET
                    match_status = 'FINISHED',
                    finished_at = %s
                WHERE id = %s
                  AND match_status = 'READY'
            """, (
                result_time,
                ulgg_match_id,
            ))

            db.commit()

            settled += 1

            print(
                f"✅ ULGG Custom 結算："
                f"Match {ulgg_match_id}，"
                f"{stored_name_p1} "
                f"{cp_p1_before}"
                f"{cp_p1_change:+d}"
                f"={cp_p1_after}，"
                f"{stored_name_p2} "
                f"{cp_p2_before}"
                f"{cp_p2_change:+d}"
                f"={cp_p2_after}，"
                f"結果={result_code}"
            )

        except Exception as error:
            db.rollback()

            print(
                f"❌ ULGG Custom："
                f"Match {ulgg_match_id} 結算失敗："
                f"{error}"
            )

            invalid += 1

    print(
        "📊 ULGG Custom 勝負／CP 結算結果："
        f"完成 {settled}、"
        f"等待 {waiting}、"
        f"缺後續 Snapshot {missing_room}、"
        f"異常 {invalid}"
    )
# ----------------------------------------------------------------
# channel_room2 - 只負責比對勝負（資料異常直接跳過）
# ----------------------------------------------------------------

room_data, room_path = backup_and_load("channel2_room")
room_data = normalize_room_collection_for_legacy(room_data, "channel2_room")

# ① JSON 本身不對 → 整段跳過
if not isinstance(room_data, dict) or not room_data:
    print("⚠️ channel2_room.json 資料異常或為空，跳過 room2 判斷")
else:
    # 第一階段：將 READY 場次寫入 Custom 表。
    import_ulgg_custom_ready_matches(room_data)

    # 第二階段：判定已結束的 Custom 對戰並結算 CP。
    update_ulgg_custom_results_and_cp(room_data)

    updated = 0
    logs = []

    def update_previous_match_by_room2(name, win_now, draw_now, lose_now, update_time):
        # ② W/D/L 必須是 int
        if not all(isinstance(x, int) for x in (win_now, draw_now, lose_now)):
            return None

        cursor.execute("""
            SELECT *
            FROM arena_unlight
            WHERE (name_p1 = %s OR name_p2 = %s)
              AND (ack1 = 0 OR ack2 = 0)
            ORDER BY update_time DESC
            LIMIT 1
        """, (name, name))

        prev = cursor.fetchone()
        if not prev:
            return None

        prev_win  = prev['win_p1']  if prev['name_p1'] == name else prev['win_p2']
        prev_draw = prev['draw_p1'] if prev['name_p1'] == name else prev['draw_p2']
        prev_lose = prev['lose_p1'] if prev['name_p1'] == name else prev['lose_p2']

        # ③ 前一筆數值也必須是 int
        if not all(isinstance(x, int) for x in (prev_win, prev_draw, prev_lose)):
            return None

        dw = win_now  - prev_win
        dd = draw_now - prev_draw
        dl = lose_now - prev_lose

        # ④ 嚴格限制：只能有「一個 +1」，否則視為異常
        if sorted([dw, dd, dl]) not in ([0, 0, 1], [-1, 0, 0]):
            return None

        judge_source = f"room2from:{name}"

        if dw == 1:
            if prev['name_p2'] == name:
                cursor.execute(
                    "UPDATE arena_unlight SET win=1, lose=0, tie=0, ack1=1, ack2=1, judge_source=%s WHERE id=%s",
                    (judge_source, prev['id'])
                )
            else:
                cursor.execute(
                    "UPDATE arena_unlight SET lose=1, win=0, tie=0, ack1=1, ack2=1, judge_source=%s WHERE id=%s",
                    (judge_source, prev['id'])
                )
            sync_arena_player_match_result(prev['id'])
            result = f"✅ room2 勝利：{name}（ID {prev['id']}）"

        elif dl == 1:
            if prev['name_p2'] == name:
                cursor.execute(
                    "UPDATE arena_unlight SET lose=1, win=0, tie=0, ack1=1, ack2=1, judge_source=%s WHERE id=%s",
                    (judge_source, prev['id'])
                )
            else:
                cursor.execute(
                    "UPDATE arena_unlight SET win=1, lose=0, tie=0, ack1=1, ack2=1, judge_source=%s WHERE id=%s",
                    (judge_source, prev['id'])
                )
            sync_arena_player_match_result(prev['id'])
            result = f"✅ room2 失敗：{name}（ID {prev['id']}）"

        elif dd == 1:
            cursor.execute(
                "UPDATE arena_unlight SET tie=1, win=0, lose=0, ack1=1, ack2=1, judge_source=%s WHERE id=%s",
                (judge_source, prev['id'])
            )
            sync_arena_player_match_result(prev['id'])
            result = f"⚖️ room2 平手：{name}（ID {prev['id']}）"
        else:
            return None

        db.commit()
        return result

    for room in room_data.values():

        # ⑤ room 結構檢查
        if not isinstance(room, dict):
            continue

        # 🔒 狀態防線
        if room.get("pending") == 1 or room.get("disconnect") == 1:
            continue

        # ⑥ 必須有 date
        if not isinstance(room.get("date"), (int, float)):
            continue

        update_time = (
            datetime.fromtimestamp(room["date"] / 1000, tz=timezone.utc)
            + timedelta(hours=8)
        ).strftime('%Y-%m-%d %H:%M:%S')

        for side in ("playerA", "playerB"):
            p = room.get(side)
            if not isinstance(p, dict):
                continue

            name = p.get("name")
            if not name:
                continue

            log = update_previous_match_by_room2(
                name,
                p.get("win"),
                p.get("draw"),
                p.get("lose"),
                update_time
            )

            if log:
                print(f"[room2] {log}")
                logs.append(log)
                updated += 1

        

# -----------------------------
# 最後：重新啟動 watcher
# -----------------------------
db.commit()
print("start_watcher")
start_watcher()