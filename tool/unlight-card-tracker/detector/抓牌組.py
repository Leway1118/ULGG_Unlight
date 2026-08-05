# -*- coding: utf-8 -*-
"""
UNLIGHT:Revive 封包監聽 / 牌組擷取
=====================================
原理：桌面視窗版是 Electron (= Chromium)，跟網頁版載入完全相同的網址
      https://www.playunlight.online:{14012~14021}?steamid=X&token=Y
      只是視窗版用 iframe 包起來。所以用 CDP (Chrome DevTools Protocol)
      直接掛上去監聽 WebSocket frame，比 Wireshark 抓 TLS 密文簡單一百倍。

前置設定（只要做一次）：
    Steam → 遊戲庫 → UNLIGHT:Revive 右鍵 → 內容 → 一般 → 啟動選項
    填入：  --force-device-scale-factor=1.5 --remote-debugging-port=9333

    注意 1：一定要透過 Steam 啟動。直接跑 exe 的話 greenworks 拿不到
            Steam 環境，main.js 的 catch 會直接 app.quit()，視窗會秒退。
    注意 2：port 不要用預設的 9222，那個會被 Adobe UXP (After Effects) 佔走。
    注意 3：resources\app.asar 要是大的那個(404MB, 視窗版)。
            小的 153KB 是網頁版，它 openExternal 開瀏覽器後就 app.quit() 了。

用法：
    python 抓牌組.py            # 監聽，切牌組 1/2/3 就會自動存檔
    python 抓牌組.py --launch   # 順便叫 Steam 開遊戲
    python 抓牌組.py --all      # 印出所有封包，不只牌組
    python 抓牌組.py --log      # 把封包寫進 deck/packets.log

輸出：
    deck/deck1.json ~ deck3.json   牌組組成
    deck/packets.log               所有 WebSocket frame（--all 或 --log 時）
"""

import argparse
import asyncio
import json
import subprocess
import sys
import time
from datetime import datetime
from pathlib import Path

import requests
import websockets

# ---------------------------------------------------------------- 設定
DEBUG_PORT = 9333          # 別用 9222，Adobe UXP 會佔走
STEAM_GAME_ID = "3247080"
OUT_DIR = Path("deck")

# 想額外攔截的事件可以加在這裡
DECK_EVENTS = {"db_deck1", "db_deck2", "db_deck3"}


class ULSniffer:
    def __init__(self, port=DEBUG_PORT, log_all=False):
        self.port = port
        self.log_all = log_all
        self.msg_id = 0
        self.ws = None
        self.sessions = set()       # 已 attach 的 sessionId
        self.session_token = None   # db_deck1 送出時帶的那串 36 碼 session
        self.game_url = None        # 含 steamid & token 的遊戲網址
        self.decks = {}
        OUT_DIR.mkdir(exist_ok=True)
        self.logfile = (OUT_DIR / "packets.log").open("a", encoding="utf-8") if log_all else None

    # ------------------------------------------------------------ CDP 基礎
    def next_id(self):
        self.msg_id += 1
        return self.msg_id

    async def send(self, method, params=None, session_id=None):
        """送一個 CDP 指令（不等回覆，回覆會混在事件流裡）"""
        msg = {"id": self.next_id(), "method": method, "params": params or {}}
        if session_id:
            msg["sessionId"] = session_id
        await self.ws.send(json.dumps(msg))
        return msg["id"]

    def browser_endpoint(self):
        """從 /json/version 取得 browser 層級的 CDP 位址"""
        url = f"http://127.0.0.1:{self.port}/json/version"
        info = requests.get(url, timeout=5).json()
        return info["webSocketDebuggerUrl"]

    # ------------------------------------------------------------ 主流程
    async def run(self):
        endpoint = self.browser_endpoint()
        print(f"[+] 連上 CDP: {endpoint}")

        # max_size=None：牌組/圖鑑封包可能很大，別讓 websockets 砍掉
        async with websockets.connect(endpoint, max_size=None) as ws:
            self.ws = ws

            # flatten=True 讓所有子 target（iframe、彈窗）的事件都走同一條連線，
            # 用 sessionId 區分。waitForDebuggerOnStart 確保頁面還沒開始連 WS
            # 之前我們就已經 Network.enable 了，不會漏掉開場封包。
            await self.send("Target.setDiscoverTargets", {"discover": True})
            await self.send("Target.setAutoAttach", {
                "autoAttach": True,
                "waitForDebuggerOnStart": True,
                "flatten": True,
            })

            print("[+] 監聽中… 請在遊戲裡切換牌組 1 / 2 / 3")
            print("    (Ctrl+C 結束)\n")

            async for raw in ws:
                try:
                    await self.on_message(json.loads(raw))
                except Exception as e:
                    print(f"[!] 處理訊息出錯: {e}")

    async def on_message(self, msg):
        method = msg.get("method")
        params = msg.get("params", {})
        sid = msg.get("sessionId")

        if method == "Target.attachedToTarget":
            child = params["sessionId"]
            info = params["targetInfo"]
            self.sessions.add(child)
            # 對每個新 target 都開 Network 並繼續往下自動 attach
            await self.send("Network.enable", {}, child)
            await self.send("Target.setAutoAttach", {
                "autoAttach": True, "waitForDebuggerOnStart": True, "flatten": True,
            }, child)
            await self.send("Runtime.runIfWaitingForDebugger", {}, child)
            print(f"[+] attach: {info.get('type')} {info.get('url', '')[:90]}")

        elif method == "Network.webSocketCreated":
            url = params.get("url", "")
            print(f"[WS] 建立連線 {url[:120]}")
            if "playunlight" in url:
                self.game_url = url

        elif method == "Network.webSocketFrameSent":
            self.handle_frame(params, "→送出")

        elif method == "Network.webSocketFrameReceived":
            self.handle_frame(params, "←收到")

    # ------------------------------------------------------------ 封包處理
    def handle_frame(self, params, direction):
        payload = params.get("response", {}).get("payloadData", "")
        if not payload:
            return

        try:
            data = json.loads(payload)
        except (json.JSONDecodeError, TypeError):
            return
        if not isinstance(data, dict):
            return

        event = data.get("event", "")
        args = data.get("args", [])

        # ping/pong 太吵，濾掉
        is_noise = event.startswith("_ping") or event.startswith("_pong")

        if self.logfile and not is_noise:
            ts = datetime.now().strftime("%H:%M:%S.%f")[:-3]
            self.logfile.write(f"{ts} {direction} {payload}\n")
            self.logfile.flush()

        if is_noise:
            return

        if self.log_all:
            print(f"{direction} {event}: {json.dumps(args, ensure_ascii=False)[:200]}")

        # 牌組事件：送出時 args[0] 是 session token，收到時 args[0] 才是牌組內容
        if event in DECK_EVENTS:
            if args and isinstance(args[0], str):
                if self.session_token != args[0]:
                    self.session_token = args[0]
                    print(f"[*] 取得 session token: {args[0]}")
            elif args and isinstance(args[0], dict):
                self.save_deck(event, args[0])

    def save_deck(self, event, deck):
        self.decks[event] = deck
        path = OUT_DIR / f"{event.replace('db_', '')}.json"
        with path.open("w", encoding="utf-8") as f:
            json.dump(deck, f, indent=2, ensure_ascii=False)
        print(f"\n[✓] {event} → {path}")
        print(self.format_deck(deck) + "\n")

    @staticmethod
    def format_deck(deck):
        """把牌組 dict 排成人看得懂的樣子"""
        lines = []
        chara = [f"{deck.get(f'chara{i}')}(#{deck.get(f'charaIndex{i}')})" for i in (1, 2, 3)]
        lines.append(f"    角色: {'  '.join(str(c) for c in chara)}")
        weapon = [deck.get(f"weapon{i}") for i in (1, 2, 3)]
        lines.append(f"    武器: {weapon}")

        events = []
        i = 1
        while f"event{i}" in deck:
            events.append(deck[f"event{i}"])
            i += 1
        lines.append(f"    事件({len(events)}張): {events}")

        # 統計每種事件卡幾張
        tally = {}
        for e in events:
            tally[e] = tally.get(e, 0) + 1
        tally_str = "  ".join(f"{k}x{v}" for k, v in sorted(tally.items()))
        lines.append(f"    事件統計: {tally_str}")
        lines.append(f"    Cost: {deck.get('cost')}")
        return "\n".join(lines)


def launch_game(port=DEBUG_PORT):
    """透過 Steam 啟動遊戲。
    參數要事先寫在 Steam 啟動選項裡，這邊沒辦法用命令列補
    （steam://run/<id>//args/ 現在的 Steam 會直接忽略）。
    """
    print(f"[+] 透過 Steam 啟動遊戲 (ID: {STEAM_GAME_ID})…")
    subprocess.Popen(f"start steam://rungameid/{STEAM_GAME_ID}", shell=True)

    # 等 CDP 端口開起來
    for _ in range(60):
        try:
            requests.get(f"http://127.0.0.1:{port}/json/version", timeout=1)
            print("[+] debug port 已就緒")
            return True
        except requests.RequestException:
            time.sleep(1)

    print(f"[!] 等不到 debug port {port}")
    print("    請確認 Steam 啟動選項有加 --remote-debugging-port=%d" % port)
    return False


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--launch", action="store_true", help="順便啟動遊戲")
    ap.add_argument("--all", action="store_true", help="印出所有封包，不只牌組")
    ap.add_argument("--log", action="store_true", help="把封包寫進 deck/packets.log")
    ap.add_argument("--port", type=int, default=DEBUG_PORT)
    args = ap.parse_args()

    if args.launch:
        launch_game(args.port)

    sniffer = ULSniffer(port=args.port, log_all=args.all or args.log)
    try:
        asyncio.run(sniffer.run())
    except KeyboardInterrupt:
        print("\n[+] 結束")
    except requests.RequestException:
        print(f"[!] 連不上 127.0.0.1:{args.port}")
        print("    檢查清單：")
        print("    1. Steam 啟動選項有沒有加 --remote-debugging-port=%d" % args.port)
        print("    2. 遊戲是不是「透過 Steam」啟動的（直接跑 exe 會秒退）")
        print("    3. resources\\app.asar 是不是大的那個（404MB 視窗版）")


if __name__ == "__main__":
    main()
