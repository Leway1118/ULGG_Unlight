# -*- coding: utf-8 -*-
"""Manual Result OK injection probe for the UNLIGHT Steam client.

The probe observes Result/Bonus once per second.  It never injects by itself:
the operator must press ``o`` while Result.btn_ok is ready.  The injection is
guarded in the game context so each Result scene activation can be dispatched
at most once, even if the key is pressed repeatedly.
"""

from __future__ import annotations

import argparse
import asyncio
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Mapping


CDP_PORT = 59222
POLL_SECONDS = 1.0
DEFAULT_LOG_PATH = Path(__file__).with_name("result_ok_probe.log")
SOCKET_EVENTS = frozenset({"duel_end"})


READ_RESULT_OK_STATE_JS = r"""
(() => {
  const scenes = globalThis.game?.scene?.keys || {};
  const resultScene = scenes.Result || null;
  const bonusScene = scenes.Bonus || null;
  const bridge = globalThis.__UL_RESULT_OK_PROBE__ ||= {
    cycle: 0,
    resultWasActive: false,
    injectedCycle: null,
    queue: [],
    handlers: {},
  };

  function isActive(scene) {
    try { return Boolean(scene?.scene?.isActive?.()); }
    catch (error) { return false; }
  }

  const resultActive = isActive(resultScene);
  const bonusActive = isActive(bonusScene);
  if (resultActive && !bridge.resultWasActive) bridge.cycle += 1;
  bridge.resultWasActive = resultActive;

  function attach(emitter, source, eventName) {
    if (!emitter || typeof emitter.on !== "function") return;
    const key = `${source}:${eventName}`;
    bridge.handlers[key] ||= (() => {
      bridge.queue.push({
        name: eventName,
        source,
        cycle: bridge.cycle,
        timestamp: new Date().toISOString(),
      });
    });
    const handler = bridge.handlers[key];
    let attached = false;
    try {
      attached = typeof emitter.listeners === "function"
        && emitter.listeners(eventName).includes(handler);
    } catch (error) {}
    if (!attached) emitter.on(eventName, handler);
  }

  attach(resultScene?.events, "phaser", "result_ready");
  attach(resultScene?.events, "phaser", "result_end");
  attach(bonusScene?.events, "phaser", "bonus_exit");

  const button = resultActive ? resultScene?.btn_ok : null;
  let clickHandlerCount = 0;
  try {
    if (typeof button?.listenerCount === "function") {
      clickHandlerCount = Number(button.listenerCount("click")) || 0;
    } else if (typeof button?.listeners === "function") {
      clickHandlerCount = button.listeners("click").length;
    }
  } catch (error) {}

  const alpha = Number.isFinite(Number(button?.alpha))
    ? Number(button.alpha)
    : null;
  const buttonState = {
    exists: Boolean(button),
    visible: Boolean(button?.visible),
    alpha,
    input_enabled: Boolean(button?.input?.enabled),
    click_handler_count: clickHandlerCount,
  };
  const ready = resultActive
    && buttonState.exists
    && buttonState.visible
    && buttonState.alpha !== null
    && buttonState.alpha >= 0.99
    && buttonState.input_enabled;

  return JSON.stringify({
    result_cycle: bridge.cycle || null,
    result_active: resultActive,
    result: resultActive && typeof resultScene?.result === "string"
      ? resultScene.result.toLowerCase()
      : null,
    bonusgame: resultActive ? Boolean(resultScene?.bonusgame) : null,
    bonus_active: bonusActive,
    btn_ok: buttonState,
    btn_ok_ready: ready,
    injection_used: bridge.injectedCycle === bridge.cycle,
    scene_events: bridge.queue.splice(0, bridge.queue.length),
  });
})()
"""


INJECT_RESULT_OK_JS = r"""
(() => {
  const resultScene = globalThis.game?.scene?.keys?.Result || null;
  const bridge = globalThis.__UL_RESULT_OK_PROBE__;
  const button = resultScene?.btn_ok || null;

  let active = false;
  try { active = Boolean(resultScene?.scene?.isActive?.()); }
  catch (error) {}

  const alpha = Number.isFinite(Number(button?.alpha))
    ? Number(button.alpha)
    : null;
  let clickHandlerCount = 0;
  try {
    if (typeof button?.listenerCount === "function") {
      clickHandlerCount = Number(button.listenerCount("click")) || 0;
    } else if (typeof button?.listeners === "function") {
      clickHandlerCount = button.listeners("click").length;
    }
  } catch (error) {}

  if (!bridge || !bridge.cycle) {
    return JSON.stringify({dispatched: false, reason: "probe_cycle_missing"});
  }
  if (bridge.injectedCycle === bridge.cycle) {
    return JSON.stringify({
      dispatched: false,
      reason: "already_dispatched",
      result_cycle: bridge.cycle,
    });
  }
  if (!active) {
    return JSON.stringify({
      dispatched: false,
      reason: "result_scene_inactive",
      result_cycle: bridge.cycle,
    });
  }
  if (!button || !button.visible || alpha === null || alpha < 0.99
      || !button.input?.enabled) {
    return JSON.stringify({
      dispatched: false,
      reason: "btn_ok_not_ready",
      result_cycle: bridge.cycle,
    });
  }
  if (clickHandlerCount < 1) {
    return JSON.stringify({
      dispatched: false,
      reason: "click_handler_missing",
      result_cycle: bridge.cycle,
    });
  }

  // Set the cycle gate before dispatch so repeated commands cannot race.
  bridge.injectedCycle = bridge.cycle;
  const handled = button.emit("click", button);
  return JSON.stringify({
    dispatched: true,
    action: "Result.btn_ok.emit(click)",
    emit_handled: Boolean(handled),
    result_cycle: bridge.cycle,
    click_handler_count: clickHandlerCount,
  });
})()
"""


def utc_timestamp() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds")


def result_button_ready(snapshot: Mapping[str, Any]) -> bool:
    button = snapshot.get("btn_ok")
    if not isinstance(button, Mapping):
        return False
    alpha = button.get("alpha")
    return (
        snapshot.get("result_active") is True
        and button.get("exists") is True
        and button.get("visible") is True
        and isinstance(alpha, (int, float))
        and not isinstance(alpha, bool)
        and alpha >= 0.99
        and button.get("input_enabled") is True
    )


class ResultOkProbe:
    def __init__(self, log_path: Path, interval: float = POLL_SECONDS) -> None:
        self.log_path = Path(log_path)
        self.interval = max(0.1, float(interval))
        self._socket_events: list[dict[str, Any]] = []
        self._trigger_requested = False
        self._stop_requested = False
        self._cycle_evidence: dict[int, dict[str, bool]] = {}

    async def on_frame(
        self,
        _direction: str,
        data: dict[str, Any],
        _raw: str,
        _sniffer: Any,
    ) -> None:
        event_name = data.get("event") if isinstance(data, Mapping) else None
        if event_name in SOCKET_EVENTS:
            self._socket_events.append(
                {
                    "name": str(event_name),
                    "source": "socket",
                    "timestamp": utc_timestamp(),
                }
            )

    def _write_record(self, record: Mapping[str, Any]) -> None:
        self.log_path.parent.mkdir(parents=True, exist_ok=True)
        with self.log_path.open("a", encoding="utf-8", buffering=1) as output:
            output.write(json.dumps(record, ensure_ascii=False) + "\n")

    @staticmethod
    def _commands() -> list[str]:
        """Read currently buffered console keys without blocking the CDP loop."""
        try:
            import msvcrt
        except ImportError:
            return []

        commands: list[str] = []
        while msvcrt.kbhit():
            key = msvcrt.getwch().lower()
            if key in {"o", "q"}:
                commands.append(key)
        return commands

    def _consume_commands(self) -> None:
        for command in self._commands():
            if command == "o":
                self._trigger_requested = True
                print("\n[!] 已要求 Result OK；下一輪會重新檢查安全條件。")
            elif command == "q":
                self._stop_requested = True

    def _record_snapshot(self, snapshot: Mapping[str, Any]) -> dict[str, Any]:
        scene_events = [
            event
            for event in snapshot.get("scene_events", [])
            if isinstance(event, Mapping)
            and event.get("name") in {"result_ready", "result_end", "bonus_exit"}
        ]
        socket_events = self._socket_events[:]
        self._socket_events.clear()
        cycle = snapshot.get("result_cycle")
        cycle_number = cycle if isinstance(cycle, int) and cycle > 0 else None
        evidence = self._cycle_evidence.setdefault(
            cycle_number or 0,
            {"bonus_entered": False, "result_end_seen": False, "duel_end_seen": False},
        )
        if snapshot.get("bonus_active") is True:
            evidence["bonus_entered"] = True
        if any(event.get("name") == "result_end" for event in scene_events):
            evidence["result_end_seen"] = True
        if any(event.get("name") == "duel_end" for event in socket_events):
            evidence["duel_end_seen"] = True

        record = {
            "timestamp": utc_timestamp(),
            "event": "snapshot",
            "result_cycle": cycle_number,
            "result_active": snapshot.get("result_active") is True,
            "result": snapshot.get("result")
            if isinstance(snapshot.get("result"), str)
            else None,
            "bonusgame": snapshot.get("bonusgame")
            if isinstance(snapshot.get("bonusgame"), bool)
            else None,
            "bonus_active": snapshot.get("bonus_active") is True,
            "btn_ok": dict(snapshot.get("btn_ok") or {}),
            "btn_ok_ready": result_button_ready(snapshot),
            "injection_used": snapshot.get("injection_used") is True,
            "scene_events": [dict(event) for event in scene_events],
            "socket_events": socket_events,
            "evidence": dict(evidence),
        }
        self._write_record(record)
        return record

    @staticmethod
    def _print_snapshot(record: Mapping[str, Any]) -> None:
        button = record.get("btn_ok") or {}
        print(
            "\r"
            f"cycle={record.get('result_cycle') or '-'} "
            f"Result.active={record.get('result_active')} "
            f"btn={button.get('exists')} "
            f"visible={button.get('visible')} "
            f"alpha={button.get('alpha')} "
            f"input={button.get('input_enabled')} "
            f"handlers={button.get('click_handler_count')} "
            f"Bonus.active={record.get('bonus_active')} "
            f"used={record.get('injection_used')}",
            flush=True,
        )

    async def _inject(self, sniffer: Any) -> None:
        self._trigger_requested = False
        value, error = await sniffer.evaluate(INJECT_RESULT_OK_JS)
        if error:
            result: dict[str, Any] = {
                "dispatched": False,
                "reason": "cdp_evaluate_failed",
                "error": str(error),
            }
        else:
            try:
                decoded = json.loads(value)
                result = decoded if isinstance(decoded, dict) else {
                    "dispatched": False,
                    "reason": "invalid_injection_result",
                }
            except (TypeError, ValueError, json.JSONDecodeError):
                result = {
                    "dispatched": False,
                    "reason": "invalid_injection_result",
                }
        record = {
            "timestamp": utc_timestamp(),
            "event": "injection",
            **result,
        }
        self._write_record(record)
        print(f"[INJECTION] {json.dumps(result, ensure_ascii=False)}", flush=True)

    async def run(self, sniffer: Any) -> None:
        print(f"[+] Result OK Probe 已連線；JSONL: {self.log_path}")
        print("[+] 按 o 手動觸發一次 Result OK；按 q 結束；其他時間只觀察。")
        while not self._stop_requested:
            self._consume_commands()
            if self._stop_requested:
                break

            value, error = await sniffer.evaluate(READ_RESULT_OK_STATE_JS)
            if error:
                record = {
                    "timestamp": utc_timestamp(),
                    "event": "probe_error",
                    "error": str(error),
                }
                self._write_record(record)
                print(f"[!] Result state 讀取失敗：{error}", flush=True)
            else:
                try:
                    snapshot = json.loads(value)
                    if not isinstance(snapshot, Mapping):
                        raise ValueError("snapshot is not an object")
                    record = self._record_snapshot(snapshot)
                    self._print_snapshot(record)
                except (TypeError, ValueError, json.JSONDecodeError) as exc:
                    print(f"[!] Result snapshot 解析失敗：{exc}", flush=True)

            if self._trigger_requested:
                await self._inject(sniffer)
            await asyncio.sleep(self.interval)

        if sniffer.ws is not None:
            await sniffer.ws.close()


def build_argument_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="手動驗證 Steam UNLIGHT Result OK Phaser click injection",
    )
    parser.add_argument("--port", type=int, default=CDP_PORT, help="CDP port")
    parser.add_argument(
        "--interval",
        type=float,
        default=POLL_SECONDS,
        help="Result/Bonus 輪詢秒數（預設 1）",
    )
    parser.add_argument(
        "--log",
        type=Path,
        default=DEFAULT_LOG_PATH,
        help="JSONL log 路徑",
    )
    return parser


def main(argv: list[str] | None = None) -> None:
    args = build_argument_parser().parse_args(argv)
    probe = ResultOkProbe(args.log, args.interval)

    from ul_sniffer import ULSniffer

    sniffer = ULSniffer(
        port=args.port,
        on_frame=probe.on_frame,
        verbose=False,
    )
    sniffer.start(after_connect=probe.run)


if __name__ == "__main__":
    main()
