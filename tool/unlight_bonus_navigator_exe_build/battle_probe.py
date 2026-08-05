# -*- coding: utf-8 -*-
"""Passive Steam/CDP probe for UNLIGHT battle and Result state.

The probe observes Phaser scenes and an explicit WebSocket event allowlist.  It
never clicks a button, emits a socket event, joins a room, or changes scenes.
Complete room identifiers and transport payloads never leave the game context.
"""

from __future__ import annotations

import argparse
import asyncio
import hashlib
import json
from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Mapping


CDP_PORT = 59222
POLL_SECONDS = 1.0
DEFAULT_LOG_PATH = Path(__file__).with_name("battle_state_probe.log")

ALLOWLISTED_EVENTS = frozenset(
    {
        "gameStart",
        "endPhase",
        "endTurn",
        "result",
        "result_ready",
        "bonus_exit",
        "result_end",
        "duel_end",
    }
)
SOCKET_EVENTS = frozenset(
    {"gameStart", "endPhase", "endTurn", "result", "duel_end"}
)
RESULT_OUTCOMES = frozenset({"win", "lose", "draw", "timeup"})


READ_BATTLE_STATE_JS = r"""
(async () => {
  const scenes = globalThis.game?.scene?.keys || {};
  const resultScene = scenes.Result || null;
  const bonusScene = scenes.Bonus || null;
  const mainScene = scenes.MainA || null;
  const backScene = scenes.BackA || null;

  // Passive scene event bridge. These listeners only enqueue allowlisted names.
  const bridge = globalThis.__UL_BATTLE_PROBE_BRIDGE__ ||= {
    queue: [],
    handlers: {},
  };

  function attach(emitter, source, eventName) {
    if (!emitter || typeof emitter.on !== "function") return;
    const key = `${source}:${eventName}`;
    bridge.handlers[key] ||= (() => {
      bridge.queue.push({
        name: eventName,
        source,
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

  function isActive(scene) {
    try { return Boolean(scene?.scene?.isActive?.()); }
    catch (error) { return false; }
  }

  const activeScenes = Object.entries(scenes)
    .filter(([, scene]) => isActive(scene))
    .map(([name]) => name);

  const scenePriority = [
    "Result", "Bonus", "AtkResultA", "DefResultA",
    "AtkDicerollA", "DefDicerollA", "AttackPhaseA",
    "DefensePhaseA", "MovePhaseA", "DrawPhaseA", "MainA",
    "Match", "Lobby",
  ];
  const scene = scenePriority.find(name => activeScenes.includes(name))
    || activeScenes[0]
    || null;

  const phaseByScene = {
    DrawPhaseA: "draw",
    MovePhaseA: "move",
    AttackPhaseA: "attack",
    DefensePhaseA: "defense",
    AtkDicerollA: "attack_dice",
    DefDicerollA: "defense_dice",
    AtkResultA: "attack_result",
    DefResultA: "defense_result",
    Result: "result",
    Bonus: "bonus",
    MainA: "battle",
  };
  const phaseScene = scenePriority.find(
    name => activeScenes.includes(name) && phaseByScene[name]
  );
  const phase = phaseScene ? phaseByScene[phaseScene] : null;

  async function fingerprint(value) {
    if (typeof value !== "string" || value.length === 0) return null;
    try {
      const bytes = new TextEncoder().encode(value);
      const digest = await globalThis.crypto.subtle.digest("SHA-256", bytes);
      const shortHash = Array.from(new Uint8Array(digest).slice(0, 8))
        .map(byte => byte.toString(16).padStart(2, "0"))
        .join("");
      return `room#${shortHash}`;
    } catch (error) {
      // Deterministic fallback for old Chromium builds; the room itself is not returned.
      let first = 2166136261;
      let second = 2246822519;
      for (let index = 0; index < value.length; index += 1) {
        const code = value.charCodeAt(index);
        first = Math.imul(first ^ code, 16777619) >>> 0;
        second = Math.imul(second ^ code, 3266489917) >>> 0;
      }
      return `room#${first.toString(16).padStart(8, "0")}${second
        .toString(16).padStart(8, "0")}`;
    }
  }

  const resultActive = isActive(resultScene);
  const mainActive = isActive(mainScene);
  const backActive = isActive(backScene);
  const bonusActive = isActive(bonusScene);

  // Phaser keeps stopped scene fields alive. Read identity only from a scene
  // that is active now, otherwise a Lobby snapshot could reuse the last room.
  const roomValue = resultActive
    ? resultScene?.room
    : mainActive
      ? mainScene?.room
      : backActive
        ? backScene?.room
        : bonusActive
          ? bonusScene?.room
          : null;
  const roomFingerprint = await fingerprint(roomValue);

  const button = resultActive ? resultScene?.btn_ok : null;
  const buttonState = {
    visible: Boolean(button?.visible),
    alpha: Number.isFinite(Number(button?.alpha)) ? Number(button.alpha) : null,
    input_enabled: Boolean(button?.input?.enabled),
  };
  const buttonReady = resultActive
    && buttonState.visible
    && buttonState.input_enabled
    && buttonState.alpha !== null
    && buttonState.alpha > 0;

  const outcome = resultActive && typeof resultScene?.result === "string"
    ? resultScene.result.toLowerCase()
    : null;
  const sceneEvents = bridge.queue.splice(0, bridge.queue.length);

  return JSON.stringify({
    scene,
    active_scenes: activeScenes,
    phase,
    room_fingerprint: roomFingerprint,
    result_scene_active: resultActive,
    result: outcome,
    result_ready: buttonReady,
    btn_ok: buttonState,
    btn_ok_ready: buttonReady,
    available_actions: buttonReady ? ["result_ok"] : [],
    turn: mainActive && Number.isInteger(mainScene?.turn)
      ? mainScene.turn
      : null,
    local_side: mainActive && ["A", "B"].includes(mainScene?.PLAYER)
      ? mainScene.PLAYER
      : null,
    scene_events: sceneEvents,
  });
})()
"""


def utc_timestamp() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds")


def room_fingerprint(room_id: str | None) -> str | None:
    """Testable Python equivalent; runtime hashing happens inside JavaScript."""
    if not isinstance(room_id, str) or not room_id:
        return None
    digest = hashlib.sha256(room_id.encode("utf-8")).hexdigest()[:16]
    return f"room#{digest}"


def _safe_outcome(value: Any) -> str | None:
    if not isinstance(value, str):
        return None
    outcome = value.lower()
    return outcome if outcome in RESULT_OUTCOMES else None


def _safe_event_timestamp(value: Any) -> str:
    if isinstance(value, str) and value:
        return value
    return utc_timestamp()


@dataclass(order=True, frozen=True)
class ObservedEvent:
    timestamp: str
    name: str = field(compare=False)
    source: str = field(compare=False)


class BattleProbeState:
    """State reducer that stores only allowlisted names and safe scalar values."""

    def __init__(self) -> None:
        self.battle_state = "idle"
        self.result: str | None = None
        self.phase: str | None = None
        self.turn: int | None = None
        self._battle_active = False
        self._result_seen = False
        self._events: list[ObservedEvent] = []

    def observe_event(
        self,
        name: Any,
        *,
        source: str,
        args: Any = None,
        timestamp: Any = None,
    ) -> bool:
        if name not in ALLOWLISTED_EVENTS:
            return False

        event_name = str(name)
        event_timestamp = _safe_event_timestamp(timestamp)
        self._events.append(
            ObservedEvent(event_timestamp, event_name, source)
        )

        safe_args = args if isinstance(args, list) else []
        if event_name == "gameStart":
            self.battle_state = "battle_started"
            self.result = None
            self.phase = None
            self.turn = None
            self._battle_active = True
            self._result_seen = False
        elif event_name == "endPhase":
            if self._battle_active and safe_args and isinstance(safe_args[0], str):
                self.phase = safe_args[0]
            if self._battle_active:
                self.battle_state = "battle_active"
        elif event_name == "endTurn":
            if self._battle_active and safe_args and isinstance(
                safe_args[0], int
            ) and not isinstance(
                safe_args[0], bool
            ):
                self.turn = safe_args[0]
            if self._battle_active:
                self.battle_state = "battle_active"
        elif event_name == "result":
            outcome = _safe_outcome(safe_args[0] if safe_args else None)
            if self._battle_active and outcome is not None:
                self.result = outcome
                self.battle_state = "battle_finished"
                self._battle_active = False
                self._result_seen = True
        elif event_name == "result_ready":
            if self._result_seen:
                self.battle_state = "result_ready"
        elif event_name in {"bonus_exit", "result_end", "duel_end"}:
            if self._result_seen:
                self.battle_state = "post_result"
        return True

    def observe_socket_frame(
        self,
        data: Any,
        *,
        timestamp: Any = None,
    ) -> bool:
        if not isinstance(data, Mapping):
            return False
        name = data.get("event")
        if name not in SOCKET_EVENTS:
            return False
        # args are inspected only to derive phase/turn/outcome and are never retained.
        return self.observe_event(
            name,
            source="socket",
            args=data.get("args"),
            timestamp=timestamp,
        )

    def observe_scene_events(self, entries: Any) -> None:
        if not isinstance(entries, list):
            return
        for entry in entries:
            if not isinstance(entry, Mapping):
                continue
            self.observe_event(
                entry.get("name"),
                source="phaser",
                timestamp=entry.get("timestamp"),
            )

    def _current_battle_state(self, snapshot: Mapping[str, Any]) -> str:
        if snapshot.get("result_scene_active") is True:
            return (
                "result_ready"
                if snapshot.get("btn_ok_ready") is True
                else "result_screen"
            )

        active_scenes = snapshot.get("active_scenes")
        if isinstance(active_scenes, list) and any(
            scene in active_scenes
            for scene in (
                "MainA",
                "DrawPhaseA",
                "MovePhaseA",
                "AttackPhaseA",
                "DefensePhaseA",
                "AtkDicerollA",
                "DefDicerollA",
                "AtkResultA",
                "DefResultA",
            )
        ):
            if self.battle_state == "battle_started":
                return "battle_started"
            return "battle_active"

        if snapshot.get("scene") == "Bonus" and self.result is not None:
            return "post_result_bonus"
        return self.battle_state

    def build_record(
        self,
        snapshot: Mapping[str, Any],
        *,
        timestamp: str | None = None,
    ) -> dict[str, Any]:
        self.observe_scene_events(snapshot.get("scene_events"))

        snapshot_result = _safe_outcome(snapshot.get("result"))
        if snapshot.get("result_scene_active") is True and snapshot_result:
            self.result = snapshot_result
            self._result_seen = True

        snapshot_phase = snapshot.get("phase")
        if isinstance(snapshot_phase, str) and snapshot_phase:
            self.phase = snapshot_phase

        snapshot_turn = snapshot.get("turn")
        if isinstance(snapshot_turn, int) and not isinstance(snapshot_turn, bool):
            self.turn = snapshot_turn

        observed = sorted(self._events)
        self._events.clear()
        event_names = [event.name for event in observed]
        event_sources = [
            {
                "timestamp": event.timestamp,
                "name": event.name,
                "source": event.source,
            }
            for event in observed
        ]

        button = snapshot.get("btn_ok")
        if not isinstance(button, Mapping):
            button = {
                "visible": False,
                "alpha": None,
                "input_enabled": False,
            }
        btn_ok_ready = snapshot.get("btn_ok_ready") is True
        result_ready = snapshot.get("result_ready") is True

        record = {
            "timestamp": timestamp or utc_timestamp(),
            "event": event_names[-1] if event_names else "snapshot",
            "scene": snapshot.get("scene")
            if isinstance(snapshot.get("scene"), str)
            else None,
            "active_scenes": list(snapshot.get("active_scenes") or []),
            "phase": self.phase,
            "battle_state": self._current_battle_state(snapshot),
            "result": self.result,
            "result_ready": result_ready,
            "btn_ok_ready": btn_ok_ready,
            "btn_ok": {
                "visible": button.get("visible") is True,
                "alpha": button.get("alpha")
                if isinstance(button.get("alpha"), (int, float))
                and not isinstance(button.get("alpha"), bool)
                else None,
                "input_enabled": button.get("input_enabled") is True,
            },
            "room_fingerprint": snapshot.get("room_fingerprint")
            if isinstance(snapshot.get("room_fingerprint"), str)
            else None,
            # Compatibility name requested for the probe log. The list contains
            # only allowlisted names; event_sources identifies socket vs Phaser.
            "allowlisted_socket_events": event_names,
            "event_sources": event_sources,
            "available_actions": list(snapshot.get("available_actions") or []),
            "turn": self.turn,
            "local_side": snapshot.get("local_side")
            if snapshot.get("local_side") in {"A", "B"}
            else None,
        }
        return record


class BattleProbe:
    def __init__(self, log_path: Path, interval: float = POLL_SECONDS) -> None:
        self.log_path = Path(log_path)
        self.interval = max(0.1, float(interval))
        self.state = BattleProbeState()

    async def on_frame(
        self,
        _direction: str,
        data: dict[str, Any],
        _raw: str,
        _sniffer: Any,
    ) -> None:
        self.state.observe_socket_frame(data)

    def _write_record(self, record: Mapping[str, Any]) -> None:
        self.log_path.parent.mkdir(parents=True, exist_ok=True)
        with self.log_path.open("a", encoding="utf-8", buffering=1) as output:
            output.write(json.dumps(record, ensure_ascii=False) + "\n")

    @staticmethod
    def _print_record(record: Mapping[str, Any]) -> None:
        button = record.get("btn_ok") or {}
        actions = record.get("available_actions") or []
        print("\n目前狀態:")
        print(f"- active scene: {record.get('scene') or '—'}")
        print(f"- current phase: {record.get('phase') or '—'}")
        print(f"- room fingerprint: {record.get('room_fingerprint') or '—'}")
        print(f"- battle lifecycle: {record.get('battle_state')}")
        print(f"- result outcome: {record.get('result') or '—'}")
        print(f"- result_ready: {record.get('result_ready')}")
        print(
            "- Result.btn_ok: "
            f"visible={button.get('visible')} "
            f"alpha={button.get('alpha')} "
            f"input.enabled={button.get('input_enabled')}"
        )
        print(f"- available actions: {actions or ['—']}", flush=True)

    async def run(self, sniffer: Any) -> None:
        print(f"[+] 被動 Battle Probe 已啟動，log: {self.log_path}")
        while True:
            value, error = await sniffer.evaluate(
                READ_BATTLE_STATE_JS,
                await_promise=True,
            )
            if error:
                print(f"[!] 讀取 Battle state 失敗：{error}", flush=True)
            else:
                try:
                    snapshot = json.loads(value)
                    if not isinstance(snapshot, Mapping):
                        raise ValueError("snapshot 不是 object")
                    record = self.state.build_record(snapshot)
                    self._write_record(record)
                    self._print_record(record)
                except (TypeError, ValueError, json.JSONDecodeError) as exc:
                    print(f"[!] Battle snapshot 格式錯誤：{exc}", flush=True)
            await asyncio.sleep(self.interval)


def build_argument_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="被動記錄 Steam UNLIGHT 的戰鬥與 RESULT 狀態",
    )
    parser.add_argument("--port", type=int, default=CDP_PORT, help="CDP port")
    parser.add_argument(
        "--interval",
        type=float,
        default=POLL_SECONDS,
        help="Phaser scene 輪詢秒數（預設 1 秒）",
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
    probe = BattleProbe(args.log, args.interval)

    # Lazy import keeps reducer tests independent from CDP dependencies.
    from ul_sniffer import ULSniffer

    sniffer = ULSniffer(
        port=args.port,
        on_frame=probe.on_frame,
        verbose=False,
    )
    sniffer.start(after_connect=probe.run)


if __name__ == "__main__":
    main()
