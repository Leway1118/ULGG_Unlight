# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
from typing import Any

from ul_sniffer import ULSniffer

CDP_PORT = 59223
API_NAME = "__UL_WEB_WHITE3_TEST__"

CONTROLLER_JS = r"""
(() => {
  const API_NAME = "__UL_WEB_WHITE3_TEST__";
  const old = globalThis[API_NAME];
  if (old?.stop) old.stop("replaced");

  const state = {
    running: false,
    busy: false,
    timer: null,
    bonus: null,
    gameId: null,
    targetRank: null,
    targetCode: "item_battle_1_1",
    targetName: "白色石楠3",
    tableSize: 0,
    prepared: false,
    lastStopReason: null,
    lastAction: null,
    lastError: null,
    lastDiceBefore: null,
    lastDiceAfter: null,
    lastDiceDiff: null,
    flower1Before: null,
    flower1After: null,
    rescueCount: 0
  };

  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

  function getBonus() {
    state.bonus = globalThis.game?.scene?.keys?.Bonus || null;
    return state.bonus;
  }

  function currentPosition() {
    const b = getBonus();
    if (!b) return null;
    const value = Number(b.step ?? b.start);
    return Number.isFinite(value) ? value : null;
  }

  function itemQuantity(index) {
    const b = getBonus();
    const items = b?.item_list?.item_list || b?.items || [];
    const item = items.find(x =>
      x?.type === "battle" && Number(x.index) === Number(index)
    );
    return Number(item?.quantity || 0);
  }

  function action(text) {
    state.lastAction = text;
    console.log("[UL Web White3]", text);
  }

  function stop(reason = "manual") {
    state.running = false;
    state.busy = false;
    state.lastStopReason = String(reason);

    if (state.timer !== null) {
      clearTimeout(state.timer);
      state.timer = null;
    }

    action(`停止：${reason}`);
    return state.lastStopReason;
  }

  function schedule(delay = 300) {
    if (!state.running) return;
    if (state.timer !== null) clearTimeout(state.timer);
    state.timer = setTimeout(checkButton, delay);
  }

  function chooseHighLow(b) {
    b.dice_tmp = b.rand;

    const dice = Number(b.dice_tmp);

    state.lastDiceBefore = dice;
    state.lastDiceAfter = null;
    state.lastDiceDiff = null;

    if (dice > 7) {
      b.btn_low.emit("pointerup");
      action(`骰子 ${dice}，選 LOW`);
    } else if (dice < 7) {
      b.btn_high.emit("pointerup");
      action(`骰子 ${dice}，選 HIGH`);
    } else if (Math.random() < 0.5) {
      b.btn_low.emit("pointerup");
      action("骰子 7，隨機選 LOW");
    } else {
      b.btn_high.emit("pointerup");
      action("骰子 7，隨機選 HIGH");
    }
  }

  async function fetchTable() {
    const scenes = globalThis.game?.scene?.keys || {};
    const b = scenes.Bonus;

    if (!b) throw new Error("bonus_scene_missing");
    if (b.id == null) throw new Error("bonus_not_initialized");

    const source = [
      b.socket,
      scenes.Result?.socket,
      scenes.Lobby?.socket,
      scenes.MainA?.socket,
      scenes.Initialize?.socket,
      scenes.Unlight_Init?.socket,
      scenes.ConnectionCheck?.socket,
      scenes.Raid?.socket
    ].find(s => s && typeof s.fetch === "function");

    if (!source) throw new Error("socket_not_found");

    const SocketClass = source.constructor;
    const socket = new SocketClass(
      "https://www.playunlight.online:11002"
    );

    const data = await socket.fetch("db_bonusgame", b.id);
    if (!data?.items) throw new Error("bonus_table_missing");

    return {
      gameId: String(b.id),
      rows: String(data.items).split(",").map((code, index) => ({
        rank: index + 1,
        code: String(code)
      }))
    };
  }

  async function prepare() {
    state.lastError = null;
    state.prepared = false;
    state.targetRank = null;

    try {
      const current = currentPosition();
      if (current === null) throw new Error("bonus_position_missing");

      const table = await fetchTable();
      const target = table.rows.find(row =>
        row.rank >= current && row.code === state.targetCode
      );

      if (!target) {
        throw new Error("white3_not_found_after_current");
      }

      state.gameId = table.gameId;
      state.tableSize = table.rows.length;
      state.targetRank = target.rank;
      state.prepared = true;
      state.lastStopReason = null;

      action(
        `準備完成：目前 ${current}，目標 ${target.rank}，距離 ${target.rank - current}`
      );

      return status();
    } catch (error) {
      state.lastError = String(error?.message || error);
      stop(state.lastError);
      return status();
    }
  }

  async function useFlower1Only(b) {
    await sleep(2163);
    if (!state.running) return;

    const diceBefore = Number(b.dice_tmp);
    const diceAfter = Number(b.rand);
    const diff = Math.abs(diceAfter - diceBefore);

    state.lastDiceBefore = diceBefore;
    state.lastDiceAfter = diceAfter;
    state.lastDiceDiff = diff;

    action(
      `失敗：原骰 ${diceBefore}，新骰 ${diceAfter}，骰差 ${diff}`
    );

    if (!Number.isFinite(diff)) {
      stop("dice_difference_invalid");
      return;
    }

    if (diff > 1) {
      stop(`dice_diff_${diff}_flower1_unusable`);
      return;
    }

    const quantity = itemQuantity(0);
    state.flower1Before = quantity;
    state.flower1After = null;
    if (quantity <= 0) {
      stop("flower1_insufficient");
      return;
    }

    b.btn_item.emit("pointerup");
    action(`骰差 ${diff}，開啟道具選單`);
    await sleep(515);

    if (!state.running) return;
    if (!b.item_list?.item_use) {
      stop("item_panel_missing");
      return;
    }

    b.item_list.item_select = {
      index: 0,
      type: "battle"
    };
    b.item_list.item_use.setVisible();
    b.item_list.item_use.emit("pointerdown");

    state.rescueCount += 1;

    action(
      `已送出白色石楠1，使用前庫存 ${quantity}，救援次數 ${state.rescueCount}`
    );

    await sleep(1200);

    state.flower1After = itemQuantity(0);

    action(
      `花1庫存 ${state.flower1Before} → ${state.flower1After}`
    );
  }

  async function checkButton() {
    state.timer = null;
    if (!state.running) return;

    if (state.busy) {
      schedule();
      return;
    }

    const b = getBonus();
    if (!b) {
      stop("bonus_scene_missing");
      return;
    }

    state.busy = true;

    try {
      const current = currentPosition();

      if (
        state.targetRank !== null &&
        current !== null &&
        current >= state.targetRank
      ) {
        stop("target_reached");
        return;
      }

      if (b.btn_next?.input?.enabled && b.btn_next.visible) {
        b.btn_next.emit("pointerup");
        action(`按下一步，目前 step=${current}`);
      } else if (
        b.btn_high?.input?.enabled &&
        b.btn_low?.input?.enabled &&
        b.btn_high.visible &&
        b.btn_low.visible
      ) {
        chooseHighLow(b);
      } else if (
        b.btn_item?.input?.enabled &&
        b.btn_item.visible
      ) {
        await useFlower1Only(b);
      }
    } catch (error) {
      state.lastError = String(error?.stack || error);
      console.error("[UL Web White3]", error);
      stop("runtime_error");
      return;
    } finally {
      state.busy = false;
    }

    schedule();
  }

  function continueTest(steps = 10) {
    const current = currentPosition();

    if (current === null) {
      stop("bonus_position_missing");
      return status();
    }

    const safeSteps = Math.max(1, Number(steps) || 10);

    state.targetRank = current + safeSteps;
    state.prepared = true;
    state.lastStopReason = null;
    state.lastError = null;

    action(
      `測試續跑：目前 ${current}，暫定目標 ${state.targetRank}，` +
      `繼續 ${safeSteps} 格`
    );

    return status();
  }

  async function start() {
    if (state.running) return status();

    if (!state.prepared) await prepare();
    if (!state.prepared || state.targetRank === null) return status();

    const current = currentPosition();
    if (current !== null && current >= state.targetRank) {
      stop(
        current === state.targetRank
          ? "target_at_current_position"
          : "target_already_reached"
      );
      return status();
    }

    state.running = true;
    state.lastStopReason = null;
    action("開始自動 HIGH / LOW");
    schedule(100);
    return status();
  }

  function status() {
    const b = getBonus();
    const current = currentPosition();

    return {
      installed: true,
      prepared: state.prepared,
      running: state.running,
      busy: state.busy,
      gameId: state.gameId,
      tableSize: state.tableSize,
      start: b ? Number(b.start ?? 0) : null,
      current,
      targetRank: state.targetRank,
      distance:
        current !== null && state.targetRank !== null
          ? Math.max(0, state.targetRank - current)
          : null,
      targetCode: state.targetCode,
      targetName: state.targetName,
      flower1: itemQuantity(0),
      flower3: itemQuantity(1),
      lastAction: state.lastAction,
      lastStopReason: state.lastStopReason,
      lastDiceBefore: state.lastDiceBefore,
      lastDiceAfter: state.lastDiceAfter,
      lastDiceDiff: state.lastDiceDiff,
      flower1Before: state.flower1Before,
      flower1After: state.flower1After,
      rescueCount: state.rescueCount,
      lastError: state.lastError
    };
  }

  globalThis[API_NAME] = {
    state,
    prepare,
    continueTest,
    start,
    stop,
    status
  };

  return {installed: true, apiName: API_NAME};
})()
"""


def format_status(data: dict[str, Any]) -> str:
    return (
        f"執行中={bool(data.get('running'))}｜"
        f"準備={bool(data.get('prepared'))}｜"
        f"目前={data.get('current')}｜"
        f"目標={data.get('targetRank')}｜"
        f"距離={data.get('distance')}｜"
        f"花1={data.get('flower1')}｜"
        f"花3={data.get('flower3')}｜"
        f"骰差={data.get('lastDiceDiff')}｜"
        f"花1變化={data.get('flower1Before')}→"
        f"{data.get('flower1After')}｜"
        f"救援次數={data.get('rescueCount')}｜"
        f"最後動作={data.get('lastAction')}｜"
        f"停止原因={data.get('lastStopReason')}"
    )


async def evaluate_or_raise(
    sniffer: ULSniffer,
    expression: str,
    *,
    await_promise: bool = False,
) -> Any:
    value, error = await sniffer.evaluate(
        expression,
        await_promise=await_promise,
    )
    if error:
        raise RuntimeError(error)
    return value


async def get_status(sniffer: ULSniffer) -> dict[str, Any]:
    value = await evaluate_or_raise(
        sniffer,
        f"globalThis.{API_NAME}?.status?.() ?? "
        "({installed:false,error:'controller_missing'})",
    )
    if not isinstance(value, dict):
        raise RuntimeError(f"狀態格式錯誤：{value!r}")
    return value


async def monitor(
    sniffer: ULSniffer,
    stop_event: asyncio.Event,
) -> None:
    previous: tuple[Any, ...] | None = None

    while not stop_event.is_set():
        try:
            data = await get_status(sniffer)
            snapshot = (
                data.get("running"),
                data.get("prepared"),
                data.get("current"),
                data.get("targetRank"),
                data.get("flower1"),
                data.get("lastAction"),
                data.get("lastStopReason"),
            )
            if snapshot != previous:
                print(f"\n[狀態] {format_status(data)}")
                print("指令：S開始／C續跑10格／X停止／R重讀／P狀態／Q離開")
                previous = snapshot
        except Exception as exc:
            print(f"\n[!] 狀態讀取失敗：{exc}")
            return

        try:
            await asyncio.wait_for(stop_event.wait(), timeout=0.7)
        except asyncio.TimeoutError:
            pass


async def command_loop(sniffer: ULSniffer) -> None:
    print("\n=== UNLIGHT Web Bonus Test v0.1 ===")
    print("模式：敗方找最近白色石楠3；失敗只用花1")
    print("指令：S開始／C續跑10格／X停止／R重讀／P狀態／Q離開")

    stop_event = asyncio.Event()
    monitor_task = asyncio.create_task(
        monitor(sniffer, stop_event)
    )

    try:
        while True:
            command = (
                await asyncio.to_thread(input, "\n> ")
            ).strip().lower()

            if command in {"s", "start", "開始"}:
                data = await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}.start()",
                    await_promise=True,
                )
                print("[+] " + format_status(data))

            elif command in {"c", "continue", "續跑"}:
                data = await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}.continueTest(10)",
                )
                print("[+] " + format_status(data))

                data = await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}.start()",
                    await_promise=True,
                )
                print("[+] " + format_status(data))


            elif command in {"x", "stop", "停止"}:
                reason = await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}.stop('manual')",
                )
                print(f"[+] 已停止：{reason}")

            elif command in {"r", "reload", "重讀"}:
                data = await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}.prepare()",
                    await_promise=True,
                )
                print("[+] " + format_status(data))

            elif command in {"p", "status", "狀態", ""}:
                data = await get_status(sniffer)
                print("[+] " + format_status(data))

            elif command in {"q", "quit", "exit", "離開"}:
                try:
                    await evaluate_or_raise(
                        sniffer,
                        f"globalThis.{API_NAME}.stop('python_exit')",
                    )
                except Exception:
                    pass
                print("[+] 離開測試程式")
                break

            else:
                print("未知指令。使用 S／X／R／P／Q。")
    finally:
        stop_event.set()
        monitor_task.cancel()
        try:
            await monitor_task
        except asyncio.CancelledError:
            pass

        if sniffer.ws is not None:
            await sniffer.ws.close()


async def after_connect(sniffer: ULSniffer) -> None:
    print("[+] CDP 已連線，正在尋找 window.game……")

    injected = await evaluate_or_raise(
        sniffer,
        CONTROLLER_JS,
    )
    print(f"[+] 控制器已注入：{injected}")

    prepared = await evaluate_or_raise(
        sniffer,
        f"globalThis.{API_NAME}.prepare()",
        await_promise=True,
    )
    print("[+] " + format_status(prepared))

    await command_loop(sniffer)


def main() -> int:
    print(f"[+] 連接 Chrome CDP 127.0.0.1:{CDP_PORT}")
    print("[+] 請先進入 HIGH／LOW 獎勵畫面")

    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=False,
    )
    sniffer.start(after_connect=after_connect)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
