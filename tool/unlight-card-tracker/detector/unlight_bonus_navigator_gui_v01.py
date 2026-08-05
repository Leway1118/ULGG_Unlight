# -*- coding: utf-8 -*-
"""
UNLIGHT Bonus Navigator GUI v0.1
================================
第一版圖形介面：
- 讀取本輪 500 格獎品表
- 選擇停止目標
- 顯示目標位置、目前位置、剩餘距離
- 按鈕或 Space 開始／停止自動 HIGH／LOW
- 同名目標重複出現時，自動選目前位置後最近的一筆

需求：
    ul_sniffer.py
    Python 3.11+
"""

from __future__ import annotations

import asyncio
import json
import queue
import sys
import threading
import tkinter as tk
from tkinter import messagebox, ttk
from typing import Any

from ul_sniffer import ULSniffer


CDP_PORT = 59222
HOST = "www.playunlight.online"
LANG = "tcn"
POLL_MS = 700

TARGET_TYPES = [
    "β型渦探知機Ⅰ",
    "β型渦探知機Ⅱ",
    "指定事件卡",
    "指定角色卡",
    "指定道具",
    "自訂階數",
    "不限目標，持續爬星",
]

PLAIN_NAME = {
    "tcn": {"gem": "寶石", "exp": "經驗值"},
    "scn": {"gem": "宝石", "exp": "经验值"},
    "ja": {"gem": "ジェム", "exp": "経験値"},
    "en": {"gem": "Gem", "exp": "EXP"},
    "kr": {"gem": "젬", "exp": "경험치"},
}


def build_table_js(host: str, lang: str) -> str:
    plain = json.dumps(PLAIN_NAME.get(lang, PLAIN_NAME["tcn"]), ensure_ascii=False)
    return r"""
(async () => {
  const scenes = window.game?.scene?.keys || {};
  const b = scenes.Bonus;
  if (!b) return JSON.stringify({error: "bonus_scene_missing"});
  if (b.id == null) return JSON.stringify({error: "bonus_not_initialized"});

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
  if (!data || !data.items) {
    return JSON.stringify({error: "no_active_game"});
  }

  const pick = (o) => (o && (o.name_%LANG% || o.name_ja)) || null;
  const PLAIN = %PLAIN%;

  const rows = data.items.split(",").map((raw, i) => {
    const [type, name, index, value] = raw.split("_");
    let label = PLAIN[type] || type;

    try {
      if (type === "cc") {
        const [chara, level] = b.ccInfo.frames[index].filename.split("_");
        label = `${pick(b.charaProfile[chara])} ${level}`;
      } else if (type === "item") {
        label = pick(b.itemInfo[name][index]);
      } else if (type === "event") {
        label = pick(b.eventInfo.frames[index]);
      }
    } catch (error) {
      label = raw;
    }

    return {
      rank: i + 1,
      code: raw,
      name: label || raw,
      qty: value,
      type,
    };
  });

  return JSON.stringify({
    total: rows.length,
    rows,
    start: Number(b.start ?? b.step ?? 0),
  });
})()
""".replace("%HOST%", host).replace("%LANG%", lang).replace("%PLAIN%", plain)


AUTO_HL_JS = r"""
(() => {
  if (globalThis.__ULR_BONUS_NAVIGATOR__) {
    return "already_injected";
  }

  const state = {
    bonus: null,
    running: false,
    timer: null,
    busy: false,
    config: {
      mode: "code",
      targetCode: "item_raid_6",
      targetRank: null,
    },
  };

  const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

  function log(...args) {
    console.log("[Bonus Navigator]", ...args);
  }

  function refreshBonus() {
    state.bonus = window?.game?.scene?.keys?.Bonus || null;
    return state.bonus;
  }

  function stop(reason = "manual") {
    state.running = false;
    if (state.timer !== null) {
      clearTimeout(state.timer);
      state.timer = null;
    }
    state.busy = false;
    log("stopped:", reason);
    return reason;
  }

  function schedule() {
    if (!state.running) return;
    state.timer = setTimeout(checkButton, 300);
  }

  function rewardCode(reward) {
    if (!reward) return "";
    return `${reward.type}_${reward.name}_${reward.index}_${reward.value}`;
  }

  function targetReached(bonus) {
    const config = state.config || {};

    if (config.mode === "unlimited") {
      return false;
    }

    if (config.mode === "rank") {
      const current = Number(bonus.start ?? bonus.step ?? 0) + 1;
      return Number.isFinite(config.targetRank) && current >= config.targetRank;
    }

    if (config.mode === "code") {
      const nextReward = bonus.resultArray?.[4];
      const code = rewardCode(nextReward);
      const target = String(config.targetCode || "");
      return target !== "" && (
        code === target ||
        code.startsWith(target)
      );
    }

    return false;
  }

  function chooseHighLow(bonus) {
    bonus.dice_tmp = bonus.rand;

    if (bonus.dice_tmp > 7) {
      bonus.btn_low.emit("pointerup");
    } else if (bonus.dice_tmp < 7) {
      bonus.btn_high.emit("pointerup");
    } else if (Math.floor(Math.random() * 2) === 0) {
      bonus.btn_low.emit("pointerup");
    } else {
      bonus.btn_high.emit("pointerup");
    }
  }

  async function useBattleItem(bonus) {
    await sleep(2163);

    if (!state.running) return;

    bonus.btn_item.emit("pointerup");
    bonus.item_list.item_select = {
      index: null,
      type: "battle",
    };

    await sleep(515);

    if (!state.running) return;

    const diff = Math.abs(bonus.rand - bonus.dice_tmp);

    function use(index) {
      bonus.item_list.item_select.index = index;
      bonus.item_list.item_use.setVisible();
      bonus.item_list.item_use.emit("pointerdown");
      log("use battle item", index);
    }

    // 第一版沿用原始腳本規則。
    if (diff <= 1) {
      use(0);
    } else if (diff <= 3) {
      use(1);
    } else {
      use(2);
    }
  }

  async function checkButton() {
    state.timer = null;

    if (!state.running || state.busy) {
      schedule();
      return;
    }

    const bonus = refreshBonus();
    if (!bonus) {
      stop("bonus_scene_missing");
      return;
    }

    try {
      state.busy = true;

      if (
        bonus.btn_next?.input?.enabled &&
        bonus.btn_next.visible
      ) {
        if (targetReached(bonus)) {
          stop("target_reached");
          return;
        }

        bonus.btn_next.emit("pointerup");
      } else if (
        bonus.btn_high?.input?.enabled &&
        bonus.btn_low?.input?.enabled &&
        bonus.btn_high.visible &&
        bonus.btn_low.visible
      ) {
        chooseHighLow(bonus);
      } else if (
        bonus.btn_item?.input?.enabled &&
        bonus.btn_item.visible
      ) {
        await useBattleItem(bonus);
      }
    } catch (error) {
      console.error("[Bonus Navigator]", error);
      stop("runtime_error");
      return;
    } finally {
      state.busy = false;
    }

    schedule();
  }

  function start() {
    if (state.running) return "already_running";

    const bonus = refreshBonus();
    if (!bonus) return "bonus_scene_missing";

    state.running = true;
    schedule();
    log("started", state.config);
    return "started";
  }

  function toggle() {
    return state.running ? stop("manual") : start();
  }

  function configure(config) {
    state.config = {
      ...state.config,
      ...(config || {}),
    };
    log("configured", state.config);
    return state.config;
  }

  document.addEventListener("keydown", event => {
    if (event.code === "Space") {
      event.preventDefault();
      toggle();
    }
  });

  globalThis.__ULR_BONUS_NAVIGATOR__ = {
    state,
    start,
    stop,
    toggle,
    configure,
    refreshBonus,
  };

  return "injected";
})()
"""


def build_config_js(config: dict[str, Any]) -> str:
    payload = json.dumps(config, ensure_ascii=False)
    return f"""
(() => {{
  const api = globalThis.__ULR_BONUS_NAVIGATOR__;
  if (!api) return JSON.stringify({{"error":"not_injected"}});
  return JSON.stringify(api.configure({payload}));
}})()
"""


CURRENT_POSITION_JS = r"""
(() => {
  const b = window?.game?.scene?.keys?.Bonus;
  if (!b) return JSON.stringify({error: "bonus_scene_missing"});
  return JSON.stringify({
    position: Number(b.start ?? b.step ?? 0) + 1,
    running: Boolean(globalThis.__ULR_BONUS_NAVIGATOR__?.state?.running),
  });
})()
"""


class BonusNavigatorApp:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.root.title("UNLIGHT Bonus Navigator")
        self.root.geometry("520x510")
        self.root.minsize(500, 810)

        self.events: queue.Queue[tuple[str, Any]] = queue.Queue()
        self.rows: list[dict[str, Any]] = []
        self.cursor = 1
        self.target_row: dict[str, Any] | None = None

        self.sniffer: ULSniffer | None = None
        self.loop: asyncio.AbstractEventLoop | None = None
        self.connected = False

        self.target_type_var = tk.StringVar(value="β型渦探知機Ⅱ")
        self.detail_var = tk.StringVar()
        self.custom_rank_var = tk.StringVar(value="100")

        self.connection_var = tk.StringVar(value="正在連接桌面版……")
        self.scene_var = tk.StringVar(value="等待獎勵遊戲")
        self.target_name_var = tk.StringVar(value="β型渦探知機Ⅱ")
        self.target_position_var = tk.StringVar(value="—")
        self.current_position_var = tk.StringVar(value="—")
        self.distance_var = tk.StringVar(value="—")
        self.status_var = tk.StringVar(value="請先開啟 UNLIGHT 桌面版")
        self.start_button_var = tk.StringVar(value="開始自動爬星")

        self._build_ui()
        self._start_worker()

        self.root.after(100, self._process_events)
        self.root.after(POLL_MS, self._poll_position)
        self.root.protocol("WM_DELETE_WINDOW", self._on_close)

    def _build_ui(self) -> None:
        style = ttk.Style()
        style.configure("Title.TLabel", font=("Microsoft JhengHei UI", 18, "bold"))
        style.configure("Heading.TLabel", font=("Microsoft JhengHei UI", 11, "bold"))
        style.configure("Value.TLabel", font=("Microsoft JhengHei UI", 13, "bold"))

        outer = ttk.Frame(self.root, padding=20)
        outer.pack(fill="both", expand=True)

        ttk.Label(
            outer,
            text="UNLIGHT Bonus Navigator",
            style="Title.TLabel",
        ).pack(anchor="w")

        status_frame = ttk.LabelFrame(outer, text="連線狀態", padding=12)
        status_frame.pack(fill="x", pady=(15, 10))

        ttk.Label(status_frame, textvariable=self.connection_var).pack(anchor="w")
        ttk.Label(status_frame, textvariable=self.scene_var).pack(anchor="w", pady=(4, 0))

        target_frame = ttk.LabelFrame(outer, text="本輪目標", padding=12)
        target_frame.pack(fill="x", pady=10)

        ttk.Label(target_frame, text="目標類型").grid(row=0, column=0, sticky="w")

        self.target_type_combo = ttk.Combobox(
            target_frame,
            textvariable=self.target_type_var,
            values=TARGET_TYPES,
            state="readonly",
            width=28,
        )
        self.target_type_combo.grid(row=1, column=0, sticky="ew", pady=(4, 10))
        self.target_type_combo.bind("<<ComboboxSelected>>", self._on_target_type_changed)

        self.detail_label = ttk.Label(target_frame, text="指定目標")
        self.detail_combo = ttk.Combobox(
            target_frame,
            textvariable=self.detail_var,
            state="readonly",
            width=28,
        )
        self.detail_combo.bind("<<ComboboxSelected>>", self._on_detail_changed)

        self.rank_label = ttk.Label(target_frame, text="停止階數")
        self.rank_entry = ttk.Entry(
            target_frame,
            textvariable=self.custom_rank_var,
            width=12,
        )
        self.rank_entry.bind("<KeyRelease>", self._on_custom_rank_changed)

        target_frame.columnconfigure(0, weight=1)

        info = ttk.Frame(target_frame)
        info.grid(row=4, column=0, sticky="ew", pady=(8, 0))
        info.columnconfigure(1, weight=1)

        ttk.Label(info, text="目標：").grid(row=0, column=0, sticky="w")
        ttk.Label(
            info,
            textvariable=self.target_name_var,
            style="Value.TLabel",
        ).grid(row=0, column=1, sticky="e")

        ttk.Label(info, text="目標位置：").grid(row=1, column=0, sticky="w", pady=(8, 0))
        ttk.Label(
            info,
            textvariable=self.target_position_var,
            style="Value.TLabel",
        ).grid(row=1, column=1, sticky="e", pady=(8, 0))

        ttk.Label(info, text="目前位置：").grid(row=2, column=0, sticky="w", pady=(8, 0))
        ttk.Label(
            info,
            textvariable=self.current_position_var,
            style="Value.TLabel",
        ).grid(row=2, column=1, sticky="e", pady=(8, 0))

        ttk.Label(info, text="剩餘距離：").grid(row=3, column=0, sticky="w", pady=(8, 0))
        ttk.Label(
            info,
            textvariable=self.distance_var,
            style="Value.TLabel",
        ).grid(row=3, column=1, sticky="e", pady=(8, 0))

        self.start_button = ttk.Button(
            outer,
            textvariable=self.start_button_var,
            command=self._toggle_running,
        )
        self.start_button.pack(fill="x", ipady=9, pady=(12, 8))

        ttk.Button(
            outer,
            text="重新讀取本輪獎品",
            command=self._request_table,
        ).pack(fill="x")

        ttk.Label(
            outer,
            text="Space：開始／停止",
        ).pack(anchor="center", pady=(12, 4))

        ttk.Label(
            outer,
            textvariable=self.status_var,
            wraplength=470,
        ).pack(anchor="center", pady=(4, 0))

        self._show_target_controls()

    def _start_worker(self) -> None:
        thread = threading.Thread(target=self._worker_main, daemon=True)
        thread.start()

    def _worker_main(self) -> None:
        try:
            sniffer = ULSniffer(port=CDP_PORT, verbose=False)
            sniffer.start(after_connect=self._after_connect)
        except Exception as exc:
            self.events.put(("error", f"CDP 連線失敗：{exc}"))

    async def _after_connect(self, sniffer: ULSniffer) -> None:
        self.sniffer = sniffer
        self.loop = asyncio.get_running_loop()
        self.connected = True
        self.events.put(("connected", None))

        try:
            value, error = await sniffer.evaluate(AUTO_HL_JS, await_promise=False)
            if error:
                self.events.put(("error", f"Auto HL 注入失敗：{error}"))
            else:
                self.events.put(("injected", value))

            await self._fetch_table_async()
            await asyncio.Future()
        finally:
            self.connected = False
            self.events.put(("disconnected", None))

    def _submit(self, coroutine: Any) -> None:
        if not self.loop or not self.connected:
            self.status_var.set("尚未連接桌面版")
            return

        future = asyncio.run_coroutine_threadsafe(coroutine, self.loop)

        def done_callback(done: Any) -> None:
            try:
                done.result()
            except Exception as exc:
                self.events.put(("error", str(exc)))

        future.add_done_callback(done_callback)

    async def _fetch_table_async(self) -> None:
        if not self.sniffer:
            return

        value, error = await self.sniffer.evaluate(
            build_table_js(HOST, LANG),
            await_promise=True,
        )

        if error:
            self.events.put(("error", f"讀取獎品表失敗：{error}"))
            return

        try:
            data = json.loads(value)
        except Exception as exc:
            self.events.put(("error", f"獎品表格式錯誤：{exc}"))
            return

        if data.get("error"):
            self.events.put(("table_error", data["error"]))
            return

        self.events.put(("table", data))

    async def _poll_position_async(self) -> None:
        if not self.sniffer:
            return

        value, error = await self.sniffer.evaluate(
            CURRENT_POSITION_JS,
            await_promise=False,
        )

        if error:
            return

        try:
            data = json.loads(value)
        except Exception:
            return

        if not data.get("error"):
            self.events.put(("position", data))

    async def _configure_async(self, config: dict[str, Any]) -> None:
        if not self.sniffer:
            return

        value, error = await self.sniffer.evaluate(
            build_config_js(config),
            await_promise=False,
        )

        if error:
            self.events.put(("error", f"套用目標失敗：{error}"))
            return

        self.events.put(("configured", config))

    async def _toggle_async(self) -> None:
        if not self.sniffer:
            return

        js = r"""
(() => {
  const api = globalThis.__ULR_BONUS_NAVIGATOR__;
  if (!api) return "not_injected";
  return api.toggle();
})()
"""
        value, error = await self.sniffer.evaluate(js, await_promise=False)

        if error:
            self.events.put(("error", f"切換失敗：{error}"))
            return

        self.events.put(("toggle", value))

    def _process_events(self) -> None:
        try:
            while True:
                event, payload = self.events.get_nowait()

                if event == "connected":
                    self.connection_var.set("● 已連接 UNLIGHT 桌面版")
                    self.status_var.set("正在偵測獎勵遊戲……")

                elif event == "injected":
                    self.status_var.set("自動 HIGH／LOW 已準備")

                elif event == "table":
                    self.rows = payload["rows"]
                    self.cursor = int(payload.get("start", 0)) + 1
                    self.scene_var.set("● 已偵測獎勵遊戲")
                    self.current_position_var.set(f"第 {self.cursor} 格")
                    self.status_var.set(f"已讀取本輪 {payload['total']} 格獎品")
                    self._refresh_detail_options()
                    self._resolve_target()

                elif event == "table_error":
                    self.scene_var.set("○ 尚未進入獎勵遊戲")
                    messages = {
                        "bonus_scene_missing": "找不到 Bonus 場景",
                        "bonus_not_initialized": "請先進入獎勵畫面",
                        "no_active_game": "目前沒有進行中的獎勵遊戲",
                        "wsclient_not_found": "找不到遊戲連線物件，請先回大廳再進入",
                    }
                    self.status_var.set(messages.get(payload, str(payload)))

                elif event == "position":
                    self.cursor = max(1, int(payload.get("position", 1)))
                    self.current_position_var.set(f"第 {self.cursor} 格")
                    running = bool(payload.get("running"))
                    self.start_button_var.set(
                        "停止自動爬星" if running else "開始自動爬星"
                    )
                    self._resolve_target(update_config=False)

                elif event == "configured":
                    self.status_var.set("目標已套用")

                elif event == "toggle":
                    if payload in {"started", "already_running"}:
                        self.start_button_var.set("停止自動爬星")
                        self.status_var.set("自動爬星進行中")
                    elif payload == "bonus_scene_missing":
                        self.status_var.set("尚未進入獎勵遊戲")
                    else:
                        self.start_button_var.set("開始自動爬星")
                        self.status_var.set("自動爬星已停止")

                elif event == "disconnected":
                    self.connection_var.set("○ 已與桌面版中斷")
                    self.scene_var.set("等待重新啟動")
                    self.status_var.set("請重新啟動本工具")

                elif event == "error":
                    self.status_var.set(str(payload))

        except queue.Empty:
            pass

        self.root.after(100, self._process_events)

    def _poll_position(self) -> None:
        if self.connected:
            self._submit(self._poll_position_async())
        self.root.after(POLL_MS, self._poll_position)

    def _request_table(self) -> None:
        self.status_var.set("正在重新讀取本輪獎品……")
        self._submit(self._fetch_table_async())

    def _on_target_type_changed(self, _event: Any = None) -> None:
        self._show_target_controls()
        self._refresh_detail_options()
        self._resolve_target()

    def _on_detail_changed(self, _event: Any = None) -> None:
        self._resolve_target()

    def _on_custom_rank_changed(self, _event: Any = None) -> None:
        if self.target_type_var.get() == "自訂階數":
            self._resolve_target()

    def _show_target_controls(self) -> None:
        self.detail_label.grid_remove()
        self.detail_combo.grid_remove()
        self.rank_label.grid_remove()
        self.rank_entry.grid_remove()

        target_type = self.target_type_var.get()

        if target_type in {"指定事件卡", "指定角色卡", "指定道具"}:
            self.detail_label.grid(row=2, column=0, sticky="w")
            self.detail_combo.grid(row=3, column=0, sticky="ew", pady=(4, 8))

        elif target_type == "自訂階數":
            self.rank_label.grid(row=2, column=0, sticky="w")
            self.rank_entry.grid(row=3, column=0, sticky="ew", pady=(4, 8))

    def _refresh_detail_options(self) -> None:
        target_type = self.target_type_var.get()

        type_map = {
            "指定事件卡": "event",
            "指定角色卡": "cc",
            "指定道具": "item",
        }

        row_type = type_map.get(target_type)
        if not row_type:
            self.detail_combo["values"] = ()
            self.detail_var.set("")
            return

        # 同名只顯示一次；位置由 _resolve_target 自動選最近一筆。
        names = sorted({
            str(row["name"])
            for row in self.rows
            if row.get("type") == row_type and row.get("name")
        })

        self.detail_combo["values"] = names

        if self.detail_var.get() not in names:
            self.detail_var.set(names[0] if names else "")

    def _find_nearest(
        self,
        *,
        name: str | None = None,
        code_prefix: str | None = None,
        row_type: str | None = None,
    ) -> dict[str, Any] | None:
        candidates = []

        for row in self.rows:
            if int(row["rank"]) < self.cursor:
                continue
            if name is not None and row.get("name") != name:
                continue
            if code_prefix is not None and not str(row.get("code", "")).startswith(code_prefix):
                continue
            if row_type is not None and row.get("type") != row_type:
                continue
            candidates.append(row)

        if not candidates:
            return None

        return min(candidates, key=lambda row: int(row["rank"]))

    def _resolve_target(self, update_config: bool = True) -> None:
        target_type = self.target_type_var.get()
        self.target_row = None
        config: dict[str, Any]

        if target_type == "β型渦探知機Ⅰ":
            self.target_row = self._find_nearest(code_prefix="item_raid_5")
            self.target_name_var.set("β型渦探知機Ⅰ")
            config = {"mode": "code", "targetCode": "item_raid_5", "targetRank": None}

        elif target_type == "β型渦探知機Ⅱ":
            self.target_row = self._find_nearest(code_prefix="item_raid_6")
            self.target_name_var.set("β型渦探知機Ⅱ")
            config = {"mode": "code", "targetCode": "item_raid_6", "targetRank": None}

        elif target_type == "指定事件卡":
            name = self.detail_var.get()
            self.target_row = self._find_nearest(name=name, row_type="event")
            self.target_name_var.set(name or "尚無事件卡")
            config = {
                "mode": "code",
                "targetCode": self.target_row["code"] if self.target_row else "__missing__",
                "targetRank": None,
            }

        elif target_type == "指定角色卡":
            name = self.detail_var.get()
            self.target_row = self._find_nearest(name=name, row_type="cc")
            self.target_name_var.set(name or "尚無角色卡")
            config = {
                "mode": "code",
                "targetCode": self.target_row["code"] if self.target_row else "__missing__",
                "targetRank": None,
            }

        elif target_type == "指定道具":
            name = self.detail_var.get()
            self.target_row = self._find_nearest(name=name, row_type="item")
            self.target_name_var.set(name or "尚無道具")
            config = {
                "mode": "code",
                "targetCode": self.target_row["code"] if self.target_row else "__missing__",
                "targetRank": None,
            }

        elif target_type == "自訂階數":
            try:
                rank = max(1, int(self.custom_rank_var.get()))
            except ValueError:
                self.target_position_var.set("請輸入數字")
                self.distance_var.set("—")
                self.target_name_var.set("自訂階數")
                return

            self.target_name_var.set(f"第 {rank} 格")
            self.target_position_var.set(f"第 {rank} 格")
            self.distance_var.set(f"{max(0, rank - self.cursor)} 格")
            config = {"mode": "rank", "targetCode": "", "targetRank": rank}

            if update_config and self.connected:
                self._submit(self._configure_async(config))
            return

        else:
            self.target_name_var.set("不限目標")
            self.target_position_var.set("不停")
            self.distance_var.set("—")
            config = {"mode": "unlimited", "targetCode": "", "targetRank": None}

            if update_config and self.connected:
                self._submit(self._configure_async(config))
            return

        if self.target_row:
            rank = int(self.target_row["rank"])
            self.target_position_var.set(f"第 {rank} 格")
            self.distance_var.set(f"{max(0, rank - self.cursor)} 格")
        else:
            self.target_position_var.set("本輪未找到")
            self.distance_var.set("—")

        if update_config and self.connected:
            self._submit(self._configure_async(config))

    def _toggle_running(self) -> None:
        if not self.connected:
            messagebox.showwarning("尚未連線", "請先啟動 UNLIGHT 桌面版。")
            return

        target_type = self.target_type_var.get()

        if target_type != "不限目標，持續爬星":
            if target_type == "自訂階數":
                try:
                    int(self.custom_rank_var.get())
                except ValueError:
                    messagebox.showwarning("階數錯誤", "請輸入有效的停止階數。")
                    return
            elif self.target_row is None:
                messagebox.showwarning("找不到目標", "本輪獎品表中找不到這個目標。")
                return

        self._resolve_target()
        self._submit(self._toggle_async())

    def _on_close(self) -> None:
        try:
            if self.connected and self.sniffer and self.loop:
                async def close_connection() -> None:
                    try:
                        await self.sniffer.ws.close()
                    except Exception:
                        pass

                asyncio.run_coroutine_threadsafe(close_connection(), self.loop)
        finally:
            self.root.destroy()


def main() -> None:
    if sys.platform == "win32":
        try:
            import ctypes
            ctypes.windll.shcore.SetProcessDpiAwareness(1)
        except Exception:
            pass

    root = tk.Tk()
    BonusNavigatorApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
