# -*- coding: utf-8 -*-
"""
UNLIGHT:Revive 戰鬥封包擷取
==============================
目的：用封包取代 OCR 手牌識別。

卡片格式（drawPhase / cardOpen_* 都是這個結構）：
    {"swd1":1,"gun1":0,"shi1":0,"mov1":0,"spe1":0,
     "swd2":0,"gun2":0,"shi2":1,"mov2":0,"spe2":0,
     "index":4,"type":"ac","clicked":true,"used":false,"rotate":true}

    swd=劍(紅) gun=槍(綠) shi=盾(藍) mov=移動(紫) spe=特殊(黃)
    尾碼 1/2 = 卡片的兩面；rotate 表示目前有沒有被轉過來。

畫面上只印有意義的事件，**檔案照樣全記**（含被濾掉的雜訊），
事後要挖協定用 分析log.py。

用法：
    python 抓戰鬥.py              # 只印有意義的
    python 抓戰鬥.py --all        # 全部都印（挖協定用）
    python 抓戰鬥.py --raw        # 連原始 JSON 一起印
    python 抓戰鬥.py --port 1221  # 網頁版 Chrome
"""

import argparse
import json
from collections import Counter
from datetime import datetime
from pathlib import Path

from ul_sniffer import ULSniffer

OUT_DIR = Path("battle")

# 卡片五種屬性 → 對應到既有 OCR 程式碼用的顏色名稱
FACE_COLOR = {
    "swd": "紅",   # 劍，近距離攻擊
    "gun": "綠",   # 槍，遠距離攻擊
    "shi": "藍",   # 盾，防禦
    "mov": "紫",   # 移動
    "spe": "黃",   # 特殊
}

# 已知會帶卡片的事件
# A = 我方，B = 對手。依據：chara_A 是 cc078 charaIndex 775（deck1 的 chara1），
# chara_B 是 NPC cc002_03，且 dmgToB 打完就 result:"win"。
HAND_EVENTS = ("cardOpen_A", "cardOpen_B", "cardOpen")

# 牌進手牌的三個來源，合起來就是完整手牌 → 取代 OCR 的 手牌識別()
DRAW_EVENTS = ("drawPhase", "eventCard", "cardDraw")

SIDE = {"A": "我方", "B": "對手"}

# ---------------------------------------------------------------- 事件分類
# 有意義、預設會印出來的
INTERESTING = set(HAND_EVENTS) | set(DRAW_EVENTS) | {
    # 階段
    "state", "phaselabel_A", "phaselabel_B", "endPhase", "endTurn",
    "endDrawPhase", "endMovePhase", "endDefensePhase",
    "attackPhaseA", "attackPhaseB", "defensePhaseA", "defensePhaseB",
    # 開場 / 收場
    "gameStart", "gameReady", "gameReadyA", "msg_duelstart", "addArray",
    "match_waiting", "match_room_make", "match_room_error", "match_surrender",
    # 我方動作（→送出）
    "card", "rotate", "move_select", "I_am_ok",
    # 出牌回饋
    "cardclickedA", "cardclickedB", "_cardclickedA", "_cardclickedB",
    "cardrotateA", "cardrotateB",
    "cardController1A", "cardController2A", "cardController1B", "cardController2B",
    "okVisibleA", "okVisibleB", "okInvisibleA", "okInvisibleB",
    # 場況
    "distance", "movepower", "initiativeA", "initiativeB",
    "chara_A", "chara_B", "quest_charahp",
    "playerAtkA", "playerAtkB", "playerDefA", "playerDefB", "atkvalue",
    "dmgToA", "dmgToB", "healA", "healB", "goToBochi", "diceRoll",
    # 技能
    "skillbase", "skill_base", "msg_skill", "skill_effect_A", "skill_effect_B",
    # 結算
    "result", "quest_finish", "quest_item", "quest_end", "quest_isStart",
    "duelStart", "duel_end", "duel_standby",
    # 獎勵遊戲
    "db_bonusgame", "bonusgame", "throwResultDice",
    "resultSuccess", "resultFail", "item_battle_use", "db_item_battle",
    "bonus_get", "bonus_end",
    # 牌組
    "db_deck1", "db_deck2", "db_deck3",
}

# 確定是雜訊的，連「新事件」提示都不用給
# db_quest_land 每 0.5 秒輪詢一次，是洗版元凶
KNOWN_NOISE = {
    # 大廳輪詢，洗版元凶
    "db_quest_land", "db_quest_land_now", "db_quest_check", "db_quest_proceed",
    "db_quest_start", "db_quest_delete", "db_quest", "quest_port", "quest_story",
    "ap_check", "db_player_ap",
    # 玩家 / 收藏資料，戰鬥時用不到
    "db_friend", "db_player", "db_avatar", "db_stamp", "db_achievement",
    "db_characard", "db_item_avatar", "db_item_quest", "db_event",
    "ranking_bp", "ranking_qp",
    # 連線與房間管理
    "channel_length", "channel_in", "channel_out", "server_status",
    "warning_check", "comp_check", "get_card_effect", "online", "register",
    "player_duel", "joinRoom", "room_in", "gameReadyB",
    "__handshake_s", "__handshake_c", "__connected",
    # 戰鬥中的無資訊事件
    "passivebase", "timerReset", "timerstop", "timerPause", "timerResume",
    "bonus", "deleteDraw_A", "deleteDraw_B", "quest_start",
}


def is_noise(event):
    if event in KNOWN_NOISE:
        return True
    # channel2_room / channel3_room …
    if event.startswith("channel") and event.endswith("_room"):
        return True
    # cc052_sk01 / cc061_sk03 … 每回合一整串角色技能狀態，全是 null
    if len(event) > 4 and event.startswith("cc") and "_sk" in event:
        return True
    return False


def side_of(event):
    return SIDE.get(event[-1], "?")


def decode_face(card, n):
    """解一張卡的其中一面 → (顏色, 數值)。空面回 ('灰', 0)"""
    for key, color in FACE_COLOR.items():
        v = card.get(f"{key}{n}", 0)
        if v:
            return color, v
    return "灰", 0


def decode_card(card):
    """一張卡 → 既有程式碼的 '紅1藍1' 格式

    rotate=True 代表卡片已被轉過，所以顯示在上半的是第 2 面。
    ※ 這個對應關係要用實戰封包對照畫面確認。
    """
    face1 = decode_face(card, 1)
    face2 = decode_face(card, 2)
    top, bottom = (face2, face1) if card.get("rotate") else (face1, face2)
    return f"{top[0]}{top[1]}{bottom[0]}{bottom[1]}", top, bottom


class BattleWatcher:
    def __init__(self, show_raw=False, show_all=False, new_limit=2):
        self.show_raw = show_raw
        self.show_all = show_all
        self.new_limit = new_limit      # 沒見過的事件最多印幾次
        OUT_DIR.mkdir(exist_ok=True)
        stamp = datetime.now().strftime("%m%d_%H%M%S")
        self.logfile = (OUT_DIR / f"battle_{stamp}.log").open("w", encoding="utf-8")
        self.seen = Counter()
        self.new_seen = Counter()
        self.last_payload = {}
        self.opp_cards = set()    # 對手本階段出的手牌位 → 取代 對手移動出牌數()
        print(f"[+] 封包記錄 → {self.logfile.name}")

    def on_frame(self, direction, data, raw, conn):
        event = data.get("event", "")
        args = data.get("args", [])
        port = conn.get("port", "?")
        self.seen[f":{port} {direction} {event}"] += 1

        ts = datetime.now().strftime("%H:%M:%S.%f")[:-3]
        # 記檔一定要帶 port，否則 PvE(:14013) 跟 PvP(:14003) 事後分不開
        # 這裡不過濾，全部都寫進去
        self.logfile.write(f"{ts}\t{port}\t{direction}\t{raw}\n")
        self.logfile.flush()

        if not self.show_all and not self.should_print(event, direction, args):
            return

        tag = f"{ts} :{port}"
        if event == "cardclickedB":
            self.track_opponent(tag, args)
            return
        if event in ("phaselabel_A", "phaselabel_B"):
            self.opp_cards.clear()   # 換階段就重新算
        if event in HAND_EVENTS:
            self.print_hand(direction, event, args, tag)
        elif event in DRAW_EVENTS:
            self.print_draw(tag, event, args)
        elif event in ("chara_A", "chara_B"):
            self.print_chara(tag, event, args)
        elif event == "state":
            print(f"{tag} 【階段】{args}")
        else:
            brief = json.dumps(args, ensure_ascii=False)
            if len(brief) > 160:
                brief = brief[:160] + f"… (共{len(brief)}字)"
            print(f"{tag} {direction} {event}  {brief}")

        if self.show_raw:
            print(f"        RAW {raw}")

    def should_print(self, event, direction, args):
        """白名單制：有意義的照印，確定的雜訊丟掉，
        沒見過的先印幾次讓人發現新協定，之後就閉嘴。"""
        if is_noise(event):
            return False

        if event in INTERESTING:
            # 只對「收到」去重：送出的是我方動作，即使內容一樣也是不同次操作，
            # 濾掉的話猜大猜小、連續出同張牌的次數就對不起來了。
            if direction == "←收到":
                payload = json.dumps(args, ensure_ascii=False, sort_keys=True)
                if self.last_payload.get(event) == payload:
                    return False
                self.last_payload[event] = payload
            return True

        # 沒見過的事件
        self.new_seen[event] += 1
        n = self.new_seen[event]
        if n <= self.new_limit:
            print(f"        [新事件] {event}（沒在白名單裡，第 {n} 次）")
            return True
        if n == self.new_limit + 1:
            print(f"        [新事件] {event} 之後不再顯示，log 檔裡還是有記")
        return False

    def track_opponent(self, tag, args):
        """cardclickedB [手牌位, 是否打出, ?]

        對手的牌面拿不到（伺服器沒送，客戶端也沒有，畫面上就是牌背），
        但「出了幾張」即時就知道 —— 這正是 對手移動出牌數() 用 OCR
        數靛青色牌背在做的事，而且不用等牌全部發完才截圖。
        """
        if not args:
            return
        slot = args[0]
        played = args[1] if len(args) > 1 else True
        if played:
            self.opp_cards.add(slot)
        else:
            self.opp_cards.discard(slot)   # 取消出牌
        n = len(self.opp_cards)
        mark = "出" if played else "收回"
        print(f"{tag} 【對手{mark}】第 {slot} 張 → 本階段共 {n} 張 "
              f"{sorted(self.opp_cards)}")

    def print_draw(self, tag, event, args):
        """進手牌的三個來源：drawPhase(開場6張) / eventCard(事件卡) / cardDraw(補牌)"""
        cards = args[0] if args and isinstance(args[0], list) else args
        print(f"\n{tag} 【進手牌】{event}  {len(cards)} 張")
        for c in cards:
            if not isinstance(c, dict):
                continue
            fmt, top, bottom = decode_card(c)
            line = (f"    idx{c.get('index'):<4} {fmt:<10}"
                    f"{top[0]}{top[1]} / {bottom[0]}{bottom[1]}   {c.get('type','')}")
            # 事件卡的特殊欄位，OCR 根本看不到
            extra = {k: c[k] for k in ("draw", "break", "heal", "holy") if k in c}
            if extra:
                line += f"   {extra}"
            print(line)
        print()

    def print_chara(self, ts, event, args):
        """角色狀態 → 取代 getMyHP / getOpponentHP"""
        who = side_of(event)
        parts = []
        for c in args:
            if not isinstance(c, dict):
                continue
            if not c.get("chara"):
                continue          # 對手只有一隻時會有兩筆全 None 的佔位
            w = c.get("weapon") or {}
            wname = w.get("name") or w.get("filename") or ""
            parts.append(
                f"{c.get('chara')}#{c.get('charaIndex')} "
                f"HP {c.get('hp')}/{c.get('hp_max')} "
                f"ATK {c.get('atk')} DEF {c.get('def')} Lv{c.get('level')}"
                + (f" [{wname}]" if wname else "")
                + (" ★主將" if c.get("main") else "")
            )
        if parts:
            print(f"{ts} 【{who}】" + "  |  ".join(parts))

    def print_hand(self, direction, event, args, tag=""):
        # 注意這是「本次攤開打出的牌」，不是完整手牌
        # 證據：cardOpen_A 的 cardindex 跟 goToBochi 的清單完全一致
        entries = args[0] if args and isinstance(args[0], list) else args
        if not entries:
            return

        who = side_of(event)
        print(f"\n{'='*56}")
        print(f"{tag} [{event}] {who}打出  共 {len(entries)} 張")
        print(f"{'-'*56}")
        print(f"{'手牌位':<6}{'卡面':<10}{'上半':<8}{'下半':<8}{'轉':<4}{'類型':<6}{'已用'}")

        for e in entries:
            card = e.get("card", e)
            if not isinstance(card, dict):
                continue
            fmt, top, bottom = decode_card(card)
            print(
                f"{e.get('cardindex', card.get('cardindex', '?')):<6}"
                f"{fmt:<10}"
                f"{top[0]}{top[1]:<7}"
                f"{bottom[0]}{bottom[1]:<7}"
                f"{'○' if card.get('rotate') else '　':<4}"
                f"{card.get('type', ''):<6}"
                f"{'✓' if card.get('used') else ''}"
            )
        print(f"{'='*56}\n")

    def summary(self):
        print("\n" + "=" * 56)
        print("這次看到的事件（含被濾掉的）：")
        for name, n in self.seen.most_common():
            print(f"  {n:>5}  {name}")
        print("=" * 56)
        with (OUT_DIR / "events.json").open("w", encoding="utf-8") as f:
            json.dump(dict(self.seen), f, indent=2, ensure_ascii=False)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=9333,
                    help="CDP port（視窗版 Electron 9333 / 網頁版 Chrome 1221）")
    ap.add_argument("--all", action="store_true", help="全部都印（挖協定用）")
    ap.add_argument("--raw", action="store_true", help="連原始 JSON 一起印")
    args = ap.parse_args()

    watcher = BattleWatcher(show_raw=args.raw, show_all=args.all)
    sniffer = ULSniffer(port=args.port, on_frame=watcher.on_frame)

    print("[+] 監聽中… 進入戰鬥就會開始跑 (Ctrl+C 結束)\n")
    try:
        sniffer.start()
    finally:
        watcher.summary()


if __name__ == "__main__":
    main()
