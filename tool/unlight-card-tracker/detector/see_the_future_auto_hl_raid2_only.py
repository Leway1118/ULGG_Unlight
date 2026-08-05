# -*- coding: utf-8 -*-
"""
UNLIGHT:Revive 獎勵遊戲未來視
================================
社群那支書籤（未來視）的桌面視窗版。

原理一樣：window.game.scene.keys.Bonus 裡有 socket 的 class，
用它另開一條連線到 :11002 打 db_bonusgame，會回**整張 500 格**獎品表，
而不是遊戲連線 :14002 每次只餵的 9 格滾動視窗。

差別在於書籤只能在網頁版用（要有網址列貼書籤），
桌面視窗版沒網址列、devTools 也被 main.js 關掉了 —— 這支改用 CDP
的 Runtime.evaluate 把同一段 JS 送進去，所以視窗版也能用。

用法：
    python 未來視.py                  # 抓完整表，印前 60 格
    python 未來視.py --all            # 印全部 500 格
    python 未來視.py --top 100        # 印前 100 格
    python 未來視.py --watch          # 持續盯著，自動定位目前走到第幾格
    python 未來視.py --lang ja        # 用日文名（預設 tcn 繁中）
    python 未來視.py --csv 表.csv     # 匯出 CSV

注意：--watch 以外的模式只會對伺服器發一次 request。
     書籤作者也提醒過別狂發。
"""

import argparse
import asyncio
import csv
import json
import sys
import unicodedata
from pathlib import Path

from ul_sniffer import ULSniffer

sys.stdout.reconfigure(encoding="utf-8")


def dwidth(s):
    """中日文是全形，佔兩個字元寬。用 ljust 對不齊，要自己算。"""
    return sum(2 if unicodedata.east_asian_width(c) in "WF" else 1 for c in str(s))


def pad(s, n):
    return str(s) + " " * max(0, n - dwidth(s))


def rpad(s, n):
    return " " * max(0, n - dwidth(s)) + str(s)

# 遊戲連線每次餵的滾動視窗大小，用來比對目前走到第幾格
WINDOW = 9

# 值得停下來的獎品類型（gem/exp 到處都是，不算）
JUICY_KINDS = {"item", "event"}


# gem / exp 在 itemInfo 裡沒有對應項目，遊戲也只當純數值顯示，所以自己補名稱
PLAIN_NAME = {
    "tcn": {"gem": "寶石", "exp": "經驗值"},
    "scn": {"gem": "宝石", "exp": "经验值"},
    "ja":  {"gem": "ジェム", "exp": "経験値"},
    "en":  {"gem": "Gem", "exp": "EXP"},
    "kr":  {"gem": "젬", "exp": "경험치"},
}


AUTO_HL_JS = r'''

 
//////// Settings Start ////////
const HOTKEY = "Space";
const SKIP_ITEMS = [
  "exp",
  "gem",
  "cc", // 角色
  "event",
  "item_avatar",
  "item_quest", // 通行證
  "item_battle",
  "item_ccoin",
  "item_cmem", // 碎片
  "item_weapon",
  "item_raid_5", //β型渦探知機 I
  // "item_raid_6", //β型渦探知機 II
];
const BATTLE_ITEMS = [
  0, // 0: 花1
  1, // 1: 花3
  2, // 2: 花5
  3, // 3: 四葉草
  4, // 4: 跳越星
];
const BATTLE_ITEM_DELAY_SCALE = 1.03; // battle item use delay = base delay * this scale
const MAX_STEP = null; // null or number, stop auto running when reaching this step
const CHECK_INTERVAL = 300; // ms, check button interval, lower value may cause higher CPU usage
//////// Settings End ////////
 
(function () {
  "use strict";
 
  let bonus = null;
  let timeoutID = null;
  let readyStates = {
    next: 1,
    highlow: 1,
    item: 1,
  };
 
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
 
  function waitBonusScene() {
    const intervalID = setInterval(() => {
      if (window?.game?.scene?.keys?.Bonus) {
        console.log("Bonus scene detected");
        clearInterval(intervalID);
        bonus = window.game.scene.keys.Bonus;
      }
    }, 500);
  }
  waitBonusScene();
 
  function addHotkey(callback) {
    document.addEventListener("keydown", (event) => {
      if (event.code === HOTKEY) {
        event.preventDefault();
        if (timeoutID === null) {
          console.log("start auto running");
          timeoutID = setTimeout(callback, CHECK_INTERVAL);
        } else {
          console.log("stop auto running");
          timeoutID = null;
        }
      }
    });
  }
 
  function checkNext() {
    console.log("checkNext");
    if (MAX_STEP !== null && bonus.step >= MAX_STEP) {
      console.log("max step reached");
      timeoutID = null;
      return;
    }
    let reward = bonus.resultArray[4];
    let reward_str = `${reward.type}_${reward.name}_${reward.index}_${reward.value}`;
    console.debug("reward", reward_str);
    if (!SKIP_ITEMS.some((p) => reward_str.startsWith(p))) {
      console.log("item not in skip list", reward_str);
      timeoutID = null;
      return;
    }
    bonus.btn_next.emit("pointerup");
  }
 
  function checkHL() {
    console.log("checkHL");
    bonus.dice_tmp = bonus.rand;
    if (bonus.dice_tmp > 7) {
      bonus.btn_low.emit("pointerup");
    } else if (bonus.dice_tmp < 7) {
      bonus.btn_high.emit("pointerup");
    } else {
      if (Math.floor(Math.random() * 2) === 0) {
        bonus.btn_low.emit("pointerup");
      } else {
        bonus.btn_high.emit("pointerup");
      }
    }
  }
 
  async function checkBattleItem() {
    console.log("checkBattleItem");
    await sleep(2100 * BATTLE_ITEM_DELAY_SCALE);
    bonus.btn_item.emit("pointerup");
    bonus.item_list.item_select = {
      index: null,
      type: "battle",
    };
    await sleep(500 * BATTLE_ITEM_DELAY_SCALE);
    const diff = Math.abs(bonus.rand - bonus.dice_tmp);
 
    function useBattleItem(i) {
      bonus.item_list.item_select.index = i;
      bonus.item_list.item_use.setVisible();
      bonus.item_list.item_use.emit("pointerdown");
      console.log(`use item ${i}`);
    }
    if (diff <= 1 && BATTLE_ITEMS.includes(0)) {
      useBattleItem(0);
    } else if (1 < diff && diff <= 3 && BATTLE_ITEMS.includes(1)) {
      useBattleItem(1);
    } else if (3 < diff && BATTLE_ITEMS.includes(2)) {
      useBattleItem(2);
    } else if (3 < diff && BATTLE_ITEMS.includes(3)) {
      useBattleItem(3);
    } else if (3 < diff && BATTLE_ITEMS.includes(4)) {
      useBattleItem(4);
    } else {
      console.log("no battle item to use");
    }
  }
 
  async function checkBtn() {
    if (timeoutID === null) {
      console.log("stop auto running");
      readyStates.next = readyStates.highlow = readyStates.item = 1;
      return;
    }
    if (
      bonus.btn_next.input.enabled &&
      bonus.btn_next.visible &&
      readyStates.next === 1
    ) {
      readyStates.next = readyStates.highlow = readyStates.item = 0;
      checkNext();
      readyStates.highlow = 1;
    } else if (
      bonus.btn_high.input.enabled &&
      bonus.btn_low.input.enabled &&
      bonus.btn_high.visible &&
      bonus.btn_low.visible &&
      readyStates.highlow === 1
    ) {
      readyStates.next = readyStates.highlow = readyStates.item = 0;
      checkHL();
      readyStates.next = readyStates.item = 1;
    } else if (
      bonus.btn_item.input?.enabled &&
      bonus.btn_item.visible &&
      readyStates.item === 1
    ) {
      readyStates.next = readyStates.highlow = readyStates.item = 0;
      await checkBattleItem();
      readyStates.next = 1;
    }
    if (timeoutID === null) {
      console.log("stop auto running");
      readyStates.next = readyStates.highlow = readyStates.item = 1;
      return;
    }
    timeoutID = setTimeout(checkBtn, CHECK_INTERVAL);
  }
  addHotkey(checkBtn);
})();
'''


def build_js(host, lang):
    """組出要送進遊戲 context 的 JS"""
    plain = json.dumps(PLAIN_NAME.get(lang, PLAIN_NAME["tcn"]), ensure_ascii=False)
    return r"""
(async () => {
  const scenes = window.game.scene.keys;
  const b = scenes.Bonus;
  if (!b) {
    return JSON.stringify({error: "Bonus scene 不存在"});
  }

  // Bonus scene 類別永遠存在，但只有真正進入獎勵遊戲後，
  // id / ccInfo / itemInfo / socket 等實例欄位才會被初始化。
  if (b.id == null) {
    return JSON.stringify({error: "bonus_not_initialized"});
  }

  // 某些版本的 Bonus.socket 不會保留；從其他已初始化 scene
  // 借用相同的 WSClient constructor。
  const socketSource = [
    b.socket,
    scenes.Result?.socket,
    scenes.Lobby?.socket,
    scenes.MainA?.socket,
    scenes.Initialize?.socket,
    scenes.Unlight_Init?.socket,
    scenes.ConnectionCheck?.socket,
    scenes.Raid?.socket,
  ].find(s => s && typeof s.fetch === "function");

  if (!socketSource || !socketSource.constructor) {
    return JSON.stringify({error: "wsclient_not_found"});
  }

  const WS = socketSource.constructor;
  const sock = new WS("https://%HOST%:11002");
  const data = await sock.fetch("db_bonusgame", b.id);
  // 沒有進行中的獎勵遊戲時 items 會是 null
  if (!data || !data.items) return JSON.stringify({error: "no_active_game"});
  const pick = (o) => (o && (o.name_%LANG% || o.name_ja)) || null;
  // 書籤原本的 default 分支直接吐 type，所以 gem/exp 會顯示英文，這裡補上
  const PLAIN = %PLAIN%;
  const rows = data.items.split(",").map((r, i) => {
    const [type, name, index, value] = r.split("_");
    let label = PLAIN[type] || type;
    try {
      if (type === "cc") {
        const [chara, level] = b.ccInfo.frames[index].filename.split("_");
        label = pick(b.charaProfile[chara]) + " " + level;
      } else if (type === "item") {
        label = pick(b.itemInfo[name][index]);
      } else if (type === "event") {
        label = pick(b.eventInfo.frames[index]);
      }
    } catch (e) { label = r; }
    return { rank: i + 1, code: r, name: label, qty: value, type: type };
  });
  // b.start 是目前走到第幾格（0-based），畫面上的 Rank 是 start+1
  return JSON.stringify({ total: rows.length, rows: rows, start: b.start ?? 0 });
})()
""".replace("%HOST%", host).replace("%LANG%", lang).replace("%PLAIN%", plain)


def is_juicy(row):
    if row["type"] in JUICY_KINDS:
        return True
    if row["type"] == "cc":
        try:
            return int(row["qty"]) >= 6
        except (ValueError, TypeError):
            return False
    return False


def show_table(rows, start=0, limit=None, cursor=None):
    """只顯示 β型渦探知機Ⅱ（item_raid_6_1）所在列。"""
    target = next(
        (r for r in rows if str(r.get("code", "")).startswith("item_raid_6_1")),
        None,
    )

    print(f"{'#':>5}  {pad('獎品', 26)}{rpad('數量', 8)}  {pad('代碼', 22)}")
    print("─" * 74)

    if target is None:
        print("[!] 本輪獎品表找不到 β型渦探知機Ⅱ（item_raid_6_1）")
    else:
        mark = "★"
        if cursor is not None and target["rank"] == cursor:
            mark = "◀ 目前 ★"

        print(
            f"{target['rank']:>5}  "
            f"{pad(target['name'], 26)}"
            f"{rpad(target['qty'], 8)}  "
            f"{pad(target['code'], 22)}"
            f"{mark}"
        )

    print("─" * 74)


def locate(rows, window):
    """把遊戲連線餵的 9 格滾動視窗對回完整表，算出目前第幾格

    用連續 9 個代碼比對，重複的機率極低，所以能唯一定位。
    """
    codes = [r["code"] for r in rows]
    n = len(window)
    for i in range(len(codes) - n + 1):
        if codes[i:i + n] == window:
            return i + 1          # 1-based rank
    return None


class FutureSight:
    def __init__(self, args):
        self.args = args
        self.rows = None
        self.start = 0        # 0-based 目前位置
        self.cursor = None    # 1-based，畫面上的 Rank
        self.fetching = False

    async def inject_auto_hl(self, sniffer):
        """把 ULR auto HL 注入目前的桌面版遊戲 context。"""
        wrapper = f"""
(() => {{
  if (globalThis.__ULR_AUTO_HL_INJECTED__) {{
    return "already_injected";
  }}

  globalThis.__ULR_AUTO_HL_INJECTED__ = true;

  try {{
    {AUTO_HL_JS}
    return "injected";
  }} catch (error) {{
    globalThis.__ULR_AUTO_HL_INJECTED__ = false;
    throw error;
  }}
}})()
"""
        value, error = await sniffer.evaluate(wrapper, await_promise=False)
        if error:
            print(f"[!] Auto HL 注入失敗: {error}")
            return False

        if value == "already_injected":
            print("[+] Auto HL 已存在於目前遊戲 context")
        else:
            print("[+] Auto HL 已注入桌面版")
        print("[+] 獎勵畫面中按 Space：開始／停止自動爬星")
        print("[+] 目前設定：渦 I 跳過，渦 II 停止")
        return True

    # ---------------------------------------------------------- 抓完整表
    async def fetch_table(self, sniffer):
        js = build_js(self.args.host, self.args.lang)
        val, err = await sniffer.evaluate(js, await_promise=True)
        if err:
            print(f"[!] 抓表失敗: {err}")
            return None
        data = json.loads(val)
        if data.get("error") in {"no_active_game", "bonus_not_initialized"}:
            print("[!] 目前尚未進入可讀取的獎勵遊戲")
            print("    請先進入獎勵畫面，等骰子與附近獎勵卡完整出現後再按 Enter")
            return None
        if data.get("error") == "wsclient_not_found":
            print("[!] 找不到可用的 WSClient 連線物件")
            print("    請先回到大廳一次，再重新進入獎勵遊戲")
            return None
        if data.get("error"):
            print(f"[!] {data['error']}")
            return None
        self.rows = data["rows"]
        self.start = data.get("start", 0)      # 0-based
        self.cursor = self.start + 1           # 畫面上的 Rank
        print(f"[+] 完整獎品表 {data['total']} 格，目前在第 {self.cursor} 格")
        return self.rows

    # ---------------------------------------------------------- 一次性模式
    async def once(self, sniffer):
        try:
            await self.inject_auto_hl(sniffer)
            ok = await self.fetch_table(sniffer)
            if ok:
                self.render()
                if self.args.csv:
                    self.export_csv()
            if not self.args.no_wait:
                await self.interactive(sniffer, ok)
        finally:
            # 關掉 CDP 連線，run() 的 async-for 就會自然結束
            await sniffer.ws.close()

    def render(self, from_rank=None, limit=None):
        """預設從目前位置一路印到最後一格，不印已經走過的"""
        if from_rank is None:
            from_rank = 1 if self.args.all else self.cursor
        if limit is None:
            limit = self.args.top      # None 代表印到底
        show_table(self.rows, from_rank - 1, limit, cursor=self.cursor)

    async def interactive(self, sniffer, has_table):
        """印完不要馬上關掉，讓人慢慢看。

        CDP 連線在這期間保持開著，所以 r 可以直接重抓，不用重跑整支。
        input() 丟到別的執行緒跑，事件迴圈才不會被卡住。
        """
        while True:
            hint = "[Enter]重新整理 [數字]從第N格 [a]含走過的 [q]離開 > "
            if not has_table:
                hint = "[Enter]重新抓表 [q]離開 > "
            try:
                cmd = (await asyncio.to_thread(input, "\n" + hint)).strip().lower()
            except (EOFError, KeyboardInterrupt):
                return   # 非互動環境（管線、重導向）就直接結束

            if cmd == "q":
                return
            if cmd == "a" and has_table:
                self.render(from_rank=1, limit=None)
                continue
            if cmd.isdigit() and has_table:
                self.render(from_rank=max(1, int(cmd)))
                continue

            # 其餘（含 Enter）都當成重抓一次：走過幾格之後位置會變
            has_table = await self.fetch_table(sniffer) is not None
            if has_table:
                self.render()

    def export_csv(self):
        path = Path(self.args.csv)
        with path.open("w", encoding="utf-8-sig", newline="") as f:
            w = csv.DictWriter(f, fieldnames=["rank", "name", "qty", "type", "code"])
            w.writeheader()
            w.writerows(self.rows)
        print(f"[+] 已匯出 {path}")

    # ---------------------------------------------------------- 盯場模式
    async def on_frame(self, direction, data, raw, conn):
        event = data.get("event", "")
        args = data.get("args", [])

        # 遊戲連線的 9 格視窗：db_bonusgame 開場、resultSuccess 每過一關
        window = None
        if event == "db_bonusgame" and direction == "←收到" and len(args) > 3:
            window = args[3]
        elif event == "resultSuccess" and args and isinstance(args[0], list):
            window = args[0]
        elif event == "resultFail":
            print(f"  ✗ 失敗 {args}")
            return
        elif event == "throwResultDice":
            if direction == "→送出" and len(args) > 2:
                print(f"\n  ▶ 猜 {str(args[2]).upper()}")
            else:
                print(f"  ● 骰子 {args}")
            return
        elif event == "item_battle_use" and direction == "→送出":
            print(f"  ♥ 用道具續命")
            return

        if window is None:
            return

        # 中途才掛上盯場的話會錯過開場的 db_bonusgame，
        # 所以看到任何一個 9 格視窗都補抓一次完整表。
        if self.rows is None:
            if self.fetching:
                return
            self.fetching = True
            try:
                await self.fetch_table(self.sniffer)
            finally:
                self.fetching = False
            if self.rows is None:
                return

        rank = locate(self.rows, window)
        if rank is None:
            print("[!] 對不回完整表（表可能已換一輪）")
            return
        self.cursor = rank
        ahead = self.rows[rank - 1: rank - 1 + self.args.ahead]
        print(f"\n  目前第 {rank} / {len(self.rows)} 格，接下來：")
        for i, r in enumerate(ahead):
            mark = "★" if is_juicy(r) else ""
            if i == 0:
                mark = "◀ 這格 " + mark
            print(f"   {r['rank']:>4}. {pad(r['name'], 24)}{rpad(r['qty'], 8)}  {mark}")

    async def watch(self, sniffer):
        self.sniffer = sniffer
        await self.inject_auto_hl(sniffer)
        print("[+] 盯場中… 進獎勵遊戲就會自動定位 (Ctrl+C 結束)\n")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=59222,
                    help="CDP port（視窗版 9333 / 網頁版 1221）")
    ap.add_argument("--host", default="www.playunlight.online",
                    help="Steam 版用 playunlight.online，DMM 版用 playunlight-dmm.com")
    ap.add_argument("--lang", default="tcn",
                    choices=["tcn", "scn", "ja", "en", "kr"], help="獎品名稱語言")
    ap.add_argument("--top", type=int, default=None,
                    help="最多印幾格（預設從目前位置印到底）")
    ap.add_argument("--all", action="store_true",
                    help="連已經走過的也印（從第 1 格開始）")
    ap.add_argument("--ahead", type=int, default=12, help="--watch 時往前看幾格")
    ap.add_argument("--csv", help="匯出 CSV 到指定檔名")
    ap.add_argument("--watch", action="store_true", help="持續盯場並自動定位")
    ap.add_argument("--no-wait", action="store_true",
                    help="印完就結束，不等按鍵（給排程或管線用）")
    args = ap.parse_args()

    fs = FutureSight(args)
    if args.watch:
        sniffer = ULSniffer(port=args.port, on_frame=fs.on_frame)
        sniffer.start(after_connect=fs.watch)
    else:
        sniffer = ULSniffer(port=args.port, verbose=False)
        sniffer.start(after_connect=fs.once)


if __name__ == "__main__":
    main()
