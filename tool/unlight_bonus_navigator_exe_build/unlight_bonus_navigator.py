# -*- coding: utf-8 -*-
"""
UNLIGHT Bonus Navigator GUI v0.2
================================
第一版圖形介面：
- 讀取本輪 500 格獎品表
- 選擇停止目標
- 顯示目標位置、目前位置、剩餘距離
- 按鈕或 Space 開始／停止自動 HIGH／LOW
- 同名目標重複出現時，自動選目前位置後最近的一筆
- 讀取花1／花3／花5／跳越星庫存
- 依剩餘距離估算 95% 建議量並在不足時提醒
- 使用道具前檢查庫存，避免花5誤用於骰差大於5

需求：
    ul_sniffer.py
    Python 3.11+
"""

from __future__ import annotations

import asyncio
import json
import math
import os
import queue
import statistics
import sys
import threading
from datetime import datetime
from pathlib import Path
import tkinter as tk
from tkinter import messagebox, ttk
from typing import Any

from ul_sniffer import ULSniffer


CDP_PORT = 59222
HOST = "www.playunlight.online"
LANG = "tcn"
POLL_MS = 700
HISTORY_VERSION = 1
HISTORY_DIR = Path(os.environ.get("LOCALAPPDATA", Path.home())) / "UNLIGHTBonusNavigator"
HISTORY_PATH = HISTORY_DIR / "bonus_history.json"
WINNER_START_POSITION = 75
MIN_HISTORY_FOR_RATING = 10

# 1～13 骰、目前策略（<7 猜 High、>7 猜 Low、7 隨機）下，
# 每前進一格需要各類救援道具的理論機率。
ITEM_USE_PROBABILITIES = {
    0: 24 / 169,  # 白色石楠1
    1: 16 / 169,  # 白色石楠3
    2: 8 / 169,   # 白色石楠5
    4: 1 / 169,   # 差值 > 5，使用跳越星
}

BATTLE_ITEM_NAMES = {
    0: "花1",
    1: "花3",
    2: "花5",
    3: "四葉草",
    4: "跳越星",
    5: "情人節卡片",
    6: "白色情人節卡片",
}

TARGET_TYPES = [
    "β型渦探知機Ⅰ",
    "β型渦探知機Ⅱ",
    "指定事件卡",
    "指定角色卡",
    "指定道具",
    "自訂階數",
    "不限目標，持續爬星",
    "資源累積模式",
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
    gameId: String(b.id),
    total: rows.length,
    rows,
    start: Number(b.start ?? b.step ?? 0),
  });
})()
""".replace("%HOST%", host).replace("%LANG%", lang).replace("%PLAIN%", plain)


AUTO_HL_JS = r"""
(() => {
  const previous = globalThis.__ULR_BONUS_NAVIGATOR__;
  if (previous?.state?.running && typeof previous.stop === "function") {
    previous.stop("upgrade");
  }

  const state = {
    bonus: null,
    running: false,
    timer: null,
    busy: false,
    lastStopReason: null,
    config: {
      mode: "code",
      targetCode: "item_raid_6",
      targetRank: null,
      resourceMode: false,
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
    state.lastStopReason = reason;
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

  function getBattleItemQuantity(bonus, index) {
    const items = bonus?.item_list?.item_list || bonus?.items || [];
    const item = items.find(
      value => value?.type === "battle" && Number(value.index) === Number(index)
    );
    return Number(item?.quantity || 0);
  }

  function chooseRescueItem(bonus, diff) {
    /*
        花3累積模式：
        只允許使用花1。

        原因：
        花1 -> 花3 是升值交換。
        花3 / 花5 / 跳越星 都比花3目標價值高，
        不應拿來換步。
    */

    if (state.config?.resourceMode === true) {

        // 資源累積模式：
        // 只允許花1補救
        // 花3、花5、跳越星保留

        if (
            getBattleItemQuantity(bonus, 0) > 0
        ) {
            return 0;
        }

        return undefined;
    }


    // 原本一般模式邏輯
    const candidates = diff <= 1
        ? [0, 1, 2, 4]
        : diff <= 3
        ? [1, 2, 4]
        : diff <= 5
            ? [2, 4]
            : [4];

    return candidates.find(
        index => getBattleItemQuantity(bonus, index) > 0
    );
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
    const index = chooseRescueItem(bonus, diff);

    if (index === undefined) {

        if (state.config?.preserveFlower3 === true) {
            stop(`resource_mode_no_flower1_${diff}`);
        } else {
            stop(`item_insufficient_diff_${diff}`);
        }

        return;
    }

    bonus.item_list.item_select.index = index;
    bonus.item_list.item_use.setVisible();
    bonus.item_list.item_use.emit("pointerdown");
    log("use battle item", index, "diff", diff);
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

    state.lastStopReason = null;
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
    version: "0.2",
    state,
    start,
    stop,
    toggle,
    configure,
    refreshBonus,
    getBattleItemQuantity,
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

  const inventory = {};
  const items = b.item_list?.item_list || b.items || [];

  for (const item of items) {
    if (item?.type === "battle") {
      inventory[String(item.index)] = Number(item.quantity || 0);
    }
  }

  const navigatorState = globalThis.__ULR_BONUS_NAVIGATOR__?.state;
  function rewardCode(reward) {
    if (!reward) return "";
    return `${reward.type}_${reward.name}_${reward.index}_${reward.value}`;
  }

  const windowCodes = Array.isArray(b.resultArray)
    ? b.resultArray.slice(0, 9).map(rewardCode)
    : [];

   return JSON.stringify({
    start: b.start == null ? null : Number(b.start),
    step: b.step == null ? null : Number(b.step),
    windowCodes,
    running: Boolean(navigatorState?.running),
    lastStopReason: navigatorState?.lastStopReason || null,
    inventory,
  });
})()
"""


class BonusNavigatorApp:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.root.title("UNLIGHT Bonus Navigator")
        self.root.geometry("560x820")
        self.root.minsize(540, 870)

        self.events: queue.Queue[tuple[str, Any]] = queue.Queue()
        self.rows: list[dict[str, Any]] = []
        self.cursor = 1
        self.target_row: dict[str, Any] | None = None
        self.inventory: dict[int, int] = {}
        self.last_stop_reason: str | None = None
        self.history = self._load_history()
        self.current_game_id: str | None = None

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

        # 花3累積模式的候選判斷說明。
        self.flower3_reason_var = tk.StringVar(value="")
        self.inventory_vars = {
            index: tk.StringVar(value=f"{name}：—")
            for index, name in BATTLE_ITEM_NAMES.items()
            if index in {0, 1, 2, 4}
        }
        self.requirement_vars = {
            index: tk.StringVar(value=f"{BATTLE_ITEM_NAMES[index]}：—")
            for index in (0, 1, 2, 4)
        }
        self.inventory_status_var = tk.StringVar(value="進入 HIGH／LOW 後讀取道具庫存")
        self.history_sample_var = tk.StringVar(value="歷史樣本：0 輪")
        self.history_average_var = tk.StringVar(value="渦2平均位置：—")
        self.history_median_var = tk.StringVar(value="渦2中位數：—")
        self.history_percentile_var = tk.StringVar(value="本輪比較：資料收集中")
        self.history_rating_var = tk.StringVar(value="本輪評價：尚未讀取")
        self.status_var = tk.StringVar(value="請先開啟 UNLIGHT 桌面版")
        self.start_button_var = tk.StringVar(value="開始自動爬星")

        self._build_ui()
        self._update_history_display()
        self._start_worker()

        self.root.after(100, self._process_events)
        self.root.after(POLL_MS, self._poll_position)
        self.root.protocol("WM_DELETE_WINDOW", self._on_close)

    def _build_ui(self) -> None:
        style = ttk.Style()
        style.configure("Title.TLabel", font=("Microsoft JhengHei UI", 16, "bold"))
        style.configure("Heading.TLabel", font=("Microsoft JhengHei UI", 10, "bold"))
        style.configure("Value.TLabel", font=("Microsoft JhengHei UI", 12, "bold"))

        # 最外層改成三段：
        # 1. 標題
        # 2. 可切換的內容分頁
        # 3. 永遠固定在底部的操作按鈕
        outer = ttk.Frame(self.root, padding=(16, 12))
        outer.pack(fill="both", expand=True)

        ttk.Label(
            outer,
            text="UNLIGHT Bonus Navigator",
            style="Title.TLabel",
        ).pack(anchor="w", pady=(0, 8))

        notebook = ttk.Notebook(outer)
        notebook.pack(fill="both", expand=True)

        operation_tab = ttk.Frame(notebook, padding=10)
        history_tab = ttk.Frame(notebook, padding=10)
        notebook.add(operation_tab, text="操作")
        notebook.add(history_tab, text="渦2歷史")

        # -------------------------
        # 操作分頁：連線與目標
        # -------------------------
        status_frame = ttk.LabelFrame(operation_tab, text="連線狀態", padding=(10, 7))
        status_frame.pack(fill="x", pady=(0, 8))

        ttk.Label(
            status_frame,
            textvariable=self.connection_var,
        ).pack(side="left", anchor="w")

        ttk.Label(
            status_frame,
            textvariable=self.scene_var,
        ).pack(side="right", anchor="e")

        target_frame = ttk.LabelFrame(operation_tab, text="本輪目標", padding=10)
        target_frame.pack(fill="x", pady=(0, 8))
        target_frame.columnconfigure(0, weight=1)
        target_frame.columnconfigure(1, weight=1)

        ttk.Label(target_frame, text="目標類型").grid(
            row=0, column=0, columnspan=2, sticky="w"
        )

        self.target_type_combo = ttk.Combobox(
            target_frame,
            textvariable=self.target_type_var,
            values=TARGET_TYPES,
            state="readonly",
            width=28,
        )
        self.target_type_combo.grid(
            row=1, column=0, columnspan=2, sticky="ew", pady=(3, 7)
        )
        self.target_type_combo.bind(
            "<<ComboboxSelected>>",
            self._on_target_type_changed,
        )

        self.detail_label = ttk.Label(target_frame, text="指定目標")
        self.detail_combo = ttk.Combobox(
            target_frame,
            textvariable=self.detail_var,
            state="readonly",
            width=28,
        )
        self.detail_combo.bind(
            "<<ComboboxSelected>>",
            self._on_detail_changed,
        )

        self.rank_label = ttk.Label(target_frame, text="停止階數")
        self.rank_entry = ttk.Entry(
            target_frame,
            textvariable=self.custom_rank_var,
            width=12,
        )
        self.rank_entry.bind(
            "<KeyRelease>",
            self._on_custom_rank_changed,
        )

        info = ttk.Frame(target_frame)
        info.grid(
            row=4,
            column=0,
            columnspan=2,
            sticky="ew",
            pady=(4, 0),
        )
        info.columnconfigure(0, weight=1)
        info.columnconfigure(1, weight=1)

        # 兩欄摘要，減少垂直高度。
        ttk.Label(info, text="目標").grid(row=0, column=0, sticky="w")
        ttk.Label(info, text="目前").grid(row=0, column=1, sticky="w")

        ttk.Label(
            info,
            textvariable=self.target_name_var,
            style="Value.TLabel",
        ).grid(row=1, column=0, sticky="w", padx=(0, 8))

        ttk.Label(
            info,
            textvariable=self.current_position_var,
            style="Value.TLabel",
        ).grid(row=1, column=1, sticky="w")

        ttk.Label(info, text="目標位置").grid(
            row=2, column=0, sticky="w", pady=(6, 0)
        )
        ttk.Label(info, text="剩餘距離").grid(
            row=2, column=1, sticky="w", pady=(6, 0)
        )

        ttk.Label(
            info,
            textvariable=self.target_position_var,
            style="Value.TLabel",
        ).grid(row=3, column=0, sticky="w")

        ttk.Label(
            info,
            textvariable=self.distance_var,
            style="Value.TLabel",
        ).grid(row=3, column=1, sticky="w")
        self.flower3_reason_label = ttk.Label(
            info,
            textvariable=self.flower3_reason_var,
            wraplength=470,
            justify="left",
        )
        self.flower3_reason_label.grid(
            row=4,
            column=0,
            columnspan=2,
            sticky="w",
            pady=(8, 0),
        )
        # -------------------------
        # 操作分頁：庫存
        # -------------------------
        inventory_frame = ttk.LabelFrame(
            operation_tab,
            text="道具庫存與 95% 建議量",
            padding=10,
        )
        inventory_frame.pack(fill="x")
        inventory_frame.columnconfigure(0, weight=1)
        inventory_frame.columnconfigure(1, weight=1)

        ttk.Label(
            inventory_frame,
            text="目前持有",
            style="Heading.TLabel",
        ).grid(row=0, column=0, sticky="w")

        ttk.Label(
            inventory_frame,
            text="建議量",
            style="Heading.TLabel",
        ).grid(row=0, column=1, sticky="w")

        for row_number, index in enumerate((0, 1, 2, 4), start=1):
            ttk.Label(
                inventory_frame,
                textvariable=self.inventory_vars[index],
            ).grid(row=row_number, column=0, sticky="w", pady=(3, 0))

            ttk.Label(
                inventory_frame,
                textvariable=self.requirement_vars[index],
            ).grid(row=row_number, column=1, sticky="w", pady=(3, 0))

        ttk.Separator(
            inventory_frame,
            orient="horizontal",
        ).grid(
            row=5,
            column=0,
            columnspan=2,
            sticky="ew",
            pady=(8, 5),
        )

        ttk.Label(
            inventory_frame,
            textvariable=self.inventory_status_var,
            wraplength=480,
        ).grid(row=6, column=0, columnspan=2, sticky="w")

        # -------------------------
        # 歷史分頁
        # -------------------------
        history_frame = ttk.LabelFrame(
            history_tab,
            text="β型渦探知機Ⅱ位置統計",
            padding=12,
        )
        history_frame.pack(fill="x")

        ttk.Label(
            history_frame,
            textvariable=self.history_sample_var,
        ).pack(anchor="w")

        ttk.Label(
            history_frame,
            textvariable=self.history_average_var,
        ).pack(anchor="w", pady=(6, 0))

        ttk.Label(
            history_frame,
            textvariable=self.history_median_var,
        ).pack(anchor="w", pady=(6, 0))

        ttk.Separator(
            history_frame,
            orient="horizontal",
        ).pack(fill="x", pady=10)

        ttk.Label(
            history_frame,
            textvariable=self.history_percentile_var,
            wraplength=480,
        ).pack(anchor="w")

        ttk.Label(
            history_frame,
            textvariable=self.history_rating_var,
            style="Heading.TLabel",
            wraplength=480,
        ).pack(anchor="w", pady=(8, 0))

        ttk.Label(
            history_tab,
            text=(
                "歷史資料會自動保存在目前 Windows 使用者的 "
                "AppData\\Local\\UNLIGHTBonusNavigator。"
            ),
            wraplength=490,
        ).pack(anchor="w", pady=(10, 0))

        # -------------------------
        # 固定底部操作區
        # -------------------------
        action_frame = ttk.Frame(outer)
        action_frame.pack(fill="x", pady=(10, 0))

        self.start_button = ttk.Button(
            action_frame,
            textvariable=self.start_button_var,
            command=self._toggle_running,
        )
        self.start_button.pack(fill="x", ipady=8)

        ttk.Button(
            action_frame,
            text="重新讀取本輪獎品",
            command=self._request_table,
        ).pack(fill="x", pady=(6, 0))

        ttk.Label(
            action_frame,
            text="Space：開始／停止",
        ).pack(anchor="center", pady=(6, 0))

        ttk.Label(
            action_frame,
            textvariable=self.status_var,
            wraplength=500,
        ).pack(anchor="center", pady=(3, 0))

        self._show_target_controls()

    @staticmethod
    def _empty_history() -> dict[str, Any]:
        return {
            "version": HISTORY_VERSION,
            "rounds": [],
        }

    def _load_history(self) -> dict[str, Any]:
        try:
            if not HISTORY_PATH.exists():
                return self._empty_history()

            data = json.loads(HISTORY_PATH.read_text(encoding="utf-8"))
            if not isinstance(data, dict) or not isinstance(data.get("rounds"), list):
                raise ValueError("history format invalid")

            data.setdefault("version", HISTORY_VERSION)
            return data
        except Exception:
            # 損壞的歷史檔不阻止程式啟動，先保留備份再重建。
            try:
                if HISTORY_PATH.exists():
                    backup = HISTORY_PATH.with_suffix(".broken.json")
                    HISTORY_PATH.replace(backup)
            except Exception:
                pass
            return self._empty_history()

    def _save_history(self) -> None:
        HISTORY_DIR.mkdir(parents=True, exist_ok=True)
        temp_path = HISTORY_PATH.with_suffix(".tmp")
        temp_path.write_text(
            json.dumps(self.history, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        temp_path.replace(HISTORY_PATH)

    @staticmethod
    def _percentile(values: list[int], percentile: float) -> float:
        if not values:
            raise ValueError("values must not be empty")
        ordered = sorted(values)
        if len(ordered) == 1:
            return float(ordered[0])

        position = (len(ordered) - 1) * percentile
        lower = math.floor(position)
        upper = math.ceil(position)
        if lower == upper:
            return float(ordered[lower])

        weight = position - lower
        return ordered[lower] * (1.0 - weight) + ordered[upper] * weight

    def _record_current_round(self, payload: dict[str, Any]) -> None:
        game_id = str(payload.get("gameId") or "")
        rows = payload.get("rows") or []

        if not rows:
            self._update_history_display()
            return

        # b.id 在下一輪可能不會改變，因此不能只使用 game_id 去重。
        # 將本輪完整獎品表代碼組成 round_key，獎品表不同就視為新一輪。
        reward_codes = [
            str(row.get("code", ""))
            for row in rows
        ]
        table_signature = "|".join(reward_codes)
        round_key = f"{game_id}:{table_signature}"

        existing_round_keys = set()

        for record in self.history.get("rounds", []):
            if not isinstance(record, dict):
                continue

            saved_round_key = record.get("round_key")
            if saved_round_key:
                existing_round_keys.add(str(saved_round_key))
                continue

            # 相容舊資料：舊版本沒有 round_key。
            saved_game_id = str(record.get("game_id") or "")
            saved_codes = record.get("reward_codes")

            if isinstance(saved_codes, list):
                saved_signature = "|".join(
                    str(code)
                    for code in saved_codes
                )
                existing_round_keys.add(
                    f"{saved_game_id}:{saved_signature}"
                )

        if round_key in existing_round_keys:
            self._update_history_display()
            return

        raid_1_positions = [
            int(row["rank"])
            for row in rows
            if str(row.get("code", "")).startswith("item_raid_5")
        ]

        raid_2_positions = [
            int(row["rank"])
            for row in rows
            if str(row.get("code", "")).startswith("item_raid_6")
        ]

        reachable_raid_2 = [
            position
            for position in raid_2_positions
            if position >= WINNER_START_POSITION
        ]

        nearest_raid_2 = (
            min(reachable_raid_2)
            if reachable_raid_2
            else None
        )

        record = {
            "round_key": round_key,
            "game_id": game_id,
            "detected_at": datetime.now()
            .astimezone()
            .isoformat(timespec="seconds"),
            "start_position": WINNER_START_POSITION,
            "reward_codes": reward_codes,
            "raid_1_positions": raid_1_positions,
            "raid_2_positions": raid_2_positions,
            "nearest_raid_2_position": nearest_raid_2,
            "nearest_raid_2_distance": (
                nearest_raid_2 - WINNER_START_POSITION
                if nearest_raid_2 is not None
                else None
            ),
        }

        self.history.setdefault("rounds", []).append(record)

        try:
            self._save_history()
            self.status_var.set(
                f"已讀取本輪 {payload['total']} 格獎品，並新增歷史樣本"
            )
        except Exception as exc:
            self.status_var.set(
                f"獎品表已讀取，但歷史儲存失敗：{exc}"
            )

        self._update_history_display()

    def _history_raid2_positions(self) -> list[int]:
        positions = []
        for record in self.history.get("rounds", []):
            if not isinstance(record, dict):
                continue
            value = record.get("nearest_raid_2_position")
            if isinstance(value, (int, float)):
                positions.append(int(value))
        return positions

    def _current_raid2_position(self) -> int | None:
        candidates = [
            int(row["rank"])
            for row in self.rows
            if int(row["rank"]) >= WINNER_START_POSITION
            and str(row.get("code", "")).startswith("item_raid_6")
        ]
        return min(candidates) if candidates else None

    def _update_history_display(self) -> None:
        positions = self._history_raid2_positions()
        sample_count = len(positions)
        self.history_sample_var.set(f"歷史樣本：{sample_count} 輪")

        if not positions:
            self.history_average_var.set("渦2平均位置：—")
            self.history_median_var.set("渦2中位數：—")
            self.history_percentile_var.set("本輪比較：資料收集中")
            self.history_rating_var.set("本輪評價：尚無歷史樣本")
            return

        average = statistics.fmean(positions)
        median = statistics.median(positions)
        self.history_average_var.set(f"渦2平均位置：第 {average:.1f} 格")
        self.history_median_var.set(f"渦2中位數：第 {median:.0f} 格")

        current = self._current_raid2_position()
        if current is None:
            self.history_percentile_var.set("本輪比較：本輪未找到可達渦2")
            self.history_rating_var.set("本輪評價：不建議")
            return

        # 使用「歷史位置大於等於本輪位置」代表本輪比多少比例的歷史場次更近。
        better_than = (
            sum(1 for value in positions if value >= current)
            / sample_count
            * 100.0
        )
        self.history_percentile_var.set(
            f"本輪第 {current} 格，比約 {better_than:.0f}% 的歷史場次更近"
        )

        if sample_count < MIN_HISTORY_FOR_RATING:
            self.history_rating_var.set(
                f"本輪評價：資料收集中（至少 {MIN_HISTORY_FOR_RATING} 輪）"
            )
            return

        q25 = self._percentile(positions, 0.25)
        q50 = self._percentile(positions, 0.50)
        q75 = self._percentile(positions, 0.75)

        if current <= q25:
            rating = "非常近，推薦爬取"
        elif current <= q50:
            rating = "比一般場次近，可以考慮"
        elif current <= q75:
            rating = "距離偏遠，先看道具成本"
        else:
            rating = "明顯偏遠，不建議硬爬"

        self.history_rating_var.set(f"本輪評價：{rating}")

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
                    self.current_game_id = str(payload.get("gameId") or "") or None
                    self.cursor = max(
                        1,
                        int(payload.get("start", 0)) + 1,
                    )
                    self.scene_var.set("● 已偵測獎勵遊戲")
                    self.current_position_var.set(f"第 {self.cursor} 格")
                    self.status_var.set(f"已讀取本輪 {payload['total']} 格獎品")
                    self._refresh_detail_options()
                    self._record_current_round(payload)
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
                    (
                        self.cursor,
                        position_source,
                        matched_window_start,
                    ) = self._resolve_current_position(payload)

                    self.current_position_var.set(
                        f"第 {self.cursor} 格"
                    )
                    if position_source == "window_match":
                        self.status_var.set(
                            f"位置已校準：九格起點第 "
                            f"{matched_window_start} 格，"
                            f"目前第 {self.cursor} 格"
                        )
                    else:
                        self.status_var.set(
                            f"位置尚未完整校準：{position_source}，"
                            f"目前暫定第 {self.cursor} 格"
                        )
                    running = bool(payload.get("running"))
                    self.start_button_var.set(
                        "停止自動爬星" if running else "開始自動爬星"
                    )

                    raw_inventory = payload.get("inventory") or {}
                    self.inventory = {
                        int(index): int(quantity)
                        for index, quantity in raw_inventory.items()
                    }
                    self._update_inventory_display()

                    stop_reason = payload.get("lastStopReason")
                    if stop_reason and stop_reason != self.last_stop_reason:
                        self.last_stop_reason = str(stop_reason)
                        self._show_stop_reason(self.last_stop_reason)

                    if self.target_type_var.get() == "資源累積模式":
                        # 尚未開始時，可以隨目前位置重新評估候選。
                        # 自動爬星進行中必須鎖定原目標，避免到站時改選下一個目標。
                        if not running:
                            self._resolve_target(update_config=True)
                    else:
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
    @staticmethod
    def _locate_window_start_rank(
        rows: list[dict[str, Any]],
        window_codes: list[str],
    ) -> int | None:
        """
        使用畫面九格 resultArray，在完整獎品表中尋找唯一匹配。

        回傳 resultArray[0] 對應的 1-based rank。
        """
        if len(window_codes) != 9:
            return None

        full_codes = [
            str(row.get("code", ""))
            for row in rows
        ]

        matches = []

        for index in range(len(full_codes) - len(window_codes) + 1):
            if full_codes[index:index + 9] == window_codes:
                matches.append(index + 1)

        if len(matches) != 1:
            return None

        return matches[0]

    def _resolve_current_position(
            self,
            payload: dict[str, Any],
        ) -> tuple[int, str, int | None]:
            """
            回傳：
            cursor、定位來源、九格匹配起點。
            """
            raw_window = payload.get("windowCodes") or []
            window_codes = [
                str(code)
                for code in raw_window
            ]

            matched_start = self._locate_window_start_rank(
                self.rows,
                window_codes,
            )

            if matched_start is not None:
                # resultArray[4] 是九格中央，也就是真正目前格。
                return matched_start + 4, "window_match", matched_start

            step = payload.get("step")
            if step is not None:
                return max(1, int(step)), "fallback_step", None

            start = payload.get("start")
            if start is not None:
                return max(1, int(start) + 1), "fallback_start", None

            return max(1, self.cursor), "uncalibrated", None
    def _poll_position(self) -> None:
        if self.connected:
            self._submit(self._poll_position_async())
        self.root.after(POLL_MS, self._poll_position)

    def _request_table(self) -> None:
        self.status_var.set("正在重新讀取本輪獎品……")
        self._submit(self._fetch_table_async())

    @staticmethod
    def _binomial_quantile(trials: int, probability: float, confidence: float = 0.95) -> int:
        if trials <= 0 or probability <= 0:
            return 0

        cumulative = 0.0
        for count in range(trials + 1):
            cumulative += (
                math.comb(trials, count)
                * probability ** count
                * (1.0 - probability) ** (trials - count)
            )
            if cumulative >= confidence:
                return count
        return trials

    def _current_distance(self) -> int | None:
        target_type = self.target_type_var.get()

        if target_type == "不限目標，持續爬星":
            return None

        if target_type == "自訂階數":
            try:
                rank = max(1, int(self.custom_rank_var.get()))
            except ValueError:
                return None
            return max(0, rank - self.cursor)

        if self.target_row is None:
            return None

        return max(0, int(self.target_row["rank"]) - self.cursor)

    def _recommended_items(self) -> dict[int, int]:
        distance = self._current_distance()
        if distance is None:
            return {}

        return {
            index: self._binomial_quantile(
                distance,
                ITEM_USE_PROBABILITIES[index],
                0.95,
            )
            for index in (0, 1, 2, 4)
        }

    def _update_inventory_display(self) -> None:
        recommendations = self._recommended_items()

        for index in (0, 1, 2, 4):
            name = BATTLE_ITEM_NAMES[index]
            quantity = self.inventory.get(index, 0)
            self.inventory_vars[index].set(f"{name}：{quantity}")

            if recommendations:
                required = recommendations.get(index, 0)
                self.requirement_vars[index].set(f"{name}：{required}")
            else:
                self.requirement_vars[index].set(f"{name}：—")

        if not recommendations:
            self.inventory_status_var.set(
                "不限目標或尚未找到目標，無法估算總需求。"
            )
            return

        shortages = []
        for index, required in recommendations.items():
            quantity = self.inventory.get(index, 0)
            if quantity < required:
                shortages.append(
                    f"{BATTLE_ITEM_NAMES[index]}少 {required - quantity}"
                )

        if shortages:
            self.inventory_status_var.set(
                "⚠ 可能不足：" + "、".join(shortages)
            )
        else:
            self.inventory_status_var.set(
                "✓ 目前庫存達到 95% 機率建議量"
            )

    def _show_stop_reason(self, reason: str) -> None:
        if reason == "target_reached":
            self.status_var.set("已到達目標，自動爬星停止")
        elif reason.startswith("item_insufficient_diff_"):
            diff = reason.rsplit("_", 1)[-1]
            self.status_var.set(f"救援道具不足（骰差 {diff}），已安全停止")
            messagebox.showwarning(
                "救援道具不足",
                f"目前沒有可處理骰差 {diff} 的救援道具，\n"
                "自動爬星已停止，未繼續盲目操作。",
            )
        elif reason == "runtime_error":
            self.status_var.set("執行發生錯誤，自動爬星已停止")

    def _confirm_inventory_before_start(self) -> bool:
        recommendations = self._recommended_items()
        if not recommendations:
            return True

        shortages = []
        for index, required in recommendations.items():
            quantity = self.inventory.get(index, 0)
            if quantity < required:
                shortages.append(
                    f"{BATTLE_ITEM_NAMES[index]}：持有 {quantity}／建議 {required}"
                )

        if not shortages:
            return True

        return messagebox.askyesno(
            "道具可能不足",
            "以下道具低於 95% 機率建議量：\n\n"
            + "\n".join(shortages)
            + "\n\n仍然開始自動爬星嗎？",
        )

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

    @staticmethod
    def _is_flower3(row: dict[str, Any]) -> bool:
        return (
            row.get("type") == "item"
            and row.get("name") == "白色石楠3"
        )

    @staticmethod
    def _is_ancient_medicine(row: dict[str, Any]) -> bool:
        return (
            row.get("type") == "item"
            and row.get("name") == "古代妙藥"
        )

    @staticmethod
    def _is_gem(row: dict[str, Any]) -> bool:
        return row.get("type") == "gem"

    def _expected_flower3_cost(self, target_rank: int) -> float:
        distance = max(0, int(target_rank) - self.cursor)
        return distance * ITEM_USE_PROBABILITIES[1]

    def _find_resource_mode_target(self) -> dict[str, Any] | None:
        """
        資源累積模式：

        目標：
        花3 / 古代妙藥 / GEM

        規則：
        只選距離目前位置最近的一個。
        """

        candidates = []

        for row in self.rows:
            if int(row["rank"]) < self.cursor:
                continue

            if (
                self._is_flower3(row)
                or self._is_ancient_medicine(row)
                or self._is_gem(row)
            ):
                candidates.append(row)

        if not candidates:
            return None

        return min(
            candidates,
            key=lambda row: int(row["rank"])
        )

    def _build_flower3_reason(self) -> str:
        """
        顯示花3累積模式如何在：
        白色石楠3、古代妙藥、寶石之間選擇。

        只負責產生說明文字，不改變原本選擇邏輯。
        """
        future_rows = sorted(
            (
                row
                for row in self.rows
                if int(row["rank"]) >= self.cursor
            ),
            key=lambda row: int(row["rank"]),
        )

        candidate_types = (
            ("花3", self._is_flower3),
            ("古代妙藥", self._is_ancient_medicine),
            ("寶石", self._is_gem),
        )

        selected_code = (
            str(self.target_row.get("code", ""))
            if self.target_row
            else ""
        )
        selected_rank = (
            int(self.target_row["rank"])
            if self.target_row
            else None
        )

        descriptions = []

        for label, matcher in candidate_types:
            candidate = next(
                (
                    row
                    for row in future_rows
                    if matcher(row)
                ),
                None,
            )

            if candidate is None:
                descriptions.append(
                    f"{label}：目前位置後方沒有"
                )
                continue

            rank = int(candidate["rank"])
            expected_cost = self._expected_flower3_cost(rank)

            is_selected = (
                selected_rank == rank
                and str(candidate.get("code", "")) == selected_code
            )

            if is_selected:
                result = "採用"
            elif expected_cost >= 1.0:
                result = "期望值達 1 以上，略過"
            else:
                result = "未採用"

            descriptions.append(
                f"{label}：第 {rank} 格｜"
                f"花3期望 {expected_cost:.2f}｜{result}"
            )

        return "\n".join(descriptions)

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

        # 只有花3累積模式需要顯示候選判斷。
        if target_type != "花3累積模式":
            self.flower3_reason_var.set("")

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
            
        elif target_type == "資源累積模式":
            self.target_row = self._find_resource_mode_target()

            if self.target_row:
                expected_cost = self._expected_flower3_cost(
                    int(self.target_row["rank"])
                )

                self.target_name_var.set(
                    f"{self.target_row['name']}｜花3期望 {expected_cost:.2f}"
                )

                config = {
                    "mode": "code",
                    "targetCode": self.target_row["code"],
                    "targetRank": int(self.target_row["rank"]),
                    "resourceMode": True,
                }
            else:
                self.target_name_var.set("找不到花3期望低於1的目標")
                config = {
                    "mode": "code",
                    "targetCode": "__missing__",
                    "targetRank": None,
                }

            # 只補充選擇原因，不改變原本目標判斷。
            self.flower3_reason_var.set("")
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

            self._update_inventory_display()
            if update_config and self.connected:
                self._submit(self._configure_async(config))
            return

        else:
            self.target_name_var.set("不限目標")
            self.target_position_var.set("不停")
            self.distance_var.set("—")
            config = {"mode": "unlimited", "targetCode": "", "targetRank": None}

            self._update_inventory_display()
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

        self._update_inventory_display()

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

        # 停止時不需要再次詢問；只有準備啟動時檢查庫存。
        if self.start_button_var.get() == "開始自動爬星":
            if not self._confirm_inventory_before_start():
                return

        self.last_stop_reason = None
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
