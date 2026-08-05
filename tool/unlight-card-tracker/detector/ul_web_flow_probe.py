# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
import json
from datetime import datetime
from pathlib import Path
from typing import Any

from ul_sniffer import ULSniffer

CDP_PORT = 59223
API_NAME = "__UL_WEB_FLOW_PROBE__"
OUTPUT_DIR = Path("runtime")

PROBE_JS = r'''
(() => {
  const API_NAME = "__UL_WEB_FLOW_PROBE__";
  const old = globalThis[API_NAME];
  if (old?.stop) old.stop();

  const state = {
    running: true,
    seq: 0,
    events: [],
    lastSignature: null,
    hookedScenes: new Set(),
    hookedObjects: new WeakSet(),
    hookedSockets: new WeakSet()
  };

  function safe(value, depth = 0, seen = new WeakSet()) {
    if (value == null) return value;
    const t = typeof value;
    if (["string", "number", "boolean"].includes(t)) return value;
    if (t === "function") return `[Function ${value.name || "anonymous"}]`;
    if (t !== "object") return String(value);
    if (seen.has(value)) return "[Circular]";
    if (depth >= 2) return `[${value.constructor?.name || "Object"}]`;
    seen.add(value);
    if (Array.isArray(value)) return value.slice(0, 20).map(v => safe(v, depth + 1, seen));
    const out = {};
    for (const key of Object.keys(value).slice(0, 30)) {
      try { out[key] = safe(value[key], depth + 1, seen); }
      catch { out[key] = "[Unreadable]"; }
    }
    return out;
  }

  function push(kind, payload = {}) {
    const event = {
      seq: ++state.seq,
      at: new Date().toISOString(),
      kind,
      payload: safe(payload)
    };
    state.events.push(event);
    if (state.events.length > 1500) state.events.splice(0, state.events.length - 1500);
    console.log("[UL FLOW PROBE]", event);
    return event;
  }

  function describe(sceneName, obj, index = null) {
    if (!obj) return null;
    return {
      scene: sceneName,
      index,
      name: obj.name ?? null,
      type: obj.constructor?.name ?? null,
      texture: obj.texture?.key ?? null,
      frame: obj.frame?.name ?? null,
      text: typeof obj.text === "string" ? obj.text : null,
      visible: obj.visible ?? null,
      active: obj.active ?? null,
      x: Number.isFinite(obj.x) ? obj.x : null,
      y: Number.isFinite(obj.y) ? obj.y : null,
      inputEnabled: Boolean(obj.input?.enabled),
      interactive: Boolean(obj.input)
    };
  }

  function sceneSnapshot() {
    const scenes = globalThis.game?.scene?.keys || {};
    return Object.entries(scenes).map(([name, scene]) => {
      const children = scene?.children?.list || [];
      const visibleInteractive = [];
      children.forEach((child, index) => {
        if (child && child.visible !== false && (child.input || typeof child.emit === "function")) {
          visibleInteractive.push(describe(name, child, index));
        }
      });
      return {
        name,
        active: Boolean(scene?.scene?.isActive?.()),
        visible: Boolean(scene?.scene?.isVisible?.()),
        sleeping: Boolean(scene?.scene?.isSleeping?.()),
        paused: Boolean(scene?.scene?.isPaused?.()),
        selectedFields: {
          id: scene?.id ?? null,
          start: scene?.start ?? null,
          step: scene?.step ?? null,
          rand: scene?.rand ?? null,
          dice_tmp: scene?.dice_tmp ?? null,
          result: scene?.result ?? null,
          room_id: scene?.room_id ?? scene?.roomId ?? null,
          ap: scene?.ap ?? scene?.energy ?? null
        },
        visibleInteractive: visibleInteractive.slice(0, 150)
      };
    });
  }

  function snapshot(reason = "poll", force = false) {
    const scenes = sceneSnapshot();
    const payload = {
      reason,
      href: location.href,
      activeScenes: scenes.filter(s => s.active || s.visible).map(s => s.name),
      scenes
    };
    const signature = JSON.stringify(payload);
    if (force || signature !== state.lastSignature) {
      state.lastSignature = signature;
      push("snapshot", payload);
      return payload;
    }
    return null;
  }

  function hookObject(sceneName, obj, index) {
    if (!obj || state.hookedObjects.has(obj) || typeof obj.emit !== "function") return;
    state.hookedObjects.add(obj);
    const original = obj.emit;
    obj.emit = function(eventName, ...args) {
      if (typeof eventName === "string" && (
        eventName.includes("pointer") ||
        eventName === "click" ||
        eventName === "select" ||
        eventName === "confirm"
      )) {
        push("gameobject_emit", {
          eventName,
          object: describe(sceneName, obj, index),
          args: args.slice(0, 5)
        });
      }
      return original.call(this, eventName, ...args);
    };
  }

  function hookSocket(sceneName, socket) {
    if (!socket || state.hookedSockets.has(socket)) return;
    state.hookedSockets.add(socket);
    for (const method of ["emit", "send", "fetch", "request"]) {
      const original = socket[method];
      if (typeof original !== "function") continue;
      socket[method] = function(...args) {
        push("socket_call", {scene: sceneName, method, args: args.slice(0, 10)});
        try {
          const result = original.apply(this, args);
          if (result && typeof result.then === "function") {
            result.then(
              value => push("socket_result", {scene: sceneName, method, value}),
              error => push("socket_error", {scene: sceneName, method, error: String(error?.stack || error)})
            );
          }
          return result;
        } catch (error) {
          push("socket_error", {scene: sceneName, method, error: String(error?.stack || error)});
          throw error;
        }
      };
    }
  }

  function refreshHooks() {
    const scenes = globalThis.game?.scene?.keys || {};
    for (const [sceneName, scene] of Object.entries(scenes)) {
      if (!scene) continue;
      if (!state.hookedScenes.has(sceneName) && scene.input?.on) {
        scene.input.on("gameobjectdown", (pointer, object) => {
          push("gameobjectdown", {
            scene: sceneName,
            pointer: {x: pointer?.x ?? null, y: pointer?.y ?? null, button: pointer?.button ?? null},
            object: describe(sceneName, object)
          });
          setTimeout(() => snapshot("after_gameobjectdown", true), 80);
        });
        scene.input.on("gameobjectup", (pointer, object) => {
          push("gameobjectup", {
            scene: sceneName,
            pointer: {x: pointer?.x ?? null, y: pointer?.y ?? null, button: pointer?.button ?? null},
            object: describe(sceneName, object)
          });
          setTimeout(() => snapshot("after_gameobjectup", true), 80);
        });
        state.hookedScenes.add(sceneName);
        push("scene_hooked", {scene: sceneName});
      }
      const children = scene.children?.list || [];
      children.forEach((child, index) => hookObject(sceneName, child, index));
      [scene.socket, scene.client, scene.connection, scene.network].forEach(s => hookSocket(sceneName, s));
    }
  }

  const domHandler = event => push(`dom_${event.type}`, {
    tag: event.target?.tagName ?? null,
    id: event.target?.id ?? null,
    className: event.target?.className ?? null,
    x: event.clientX ?? null,
    y: event.clientY ?? null,
    button: event.button ?? null
  });

  ["click", "pointerdown", "pointerup"].forEach(name => {
    document.addEventListener(name, domHandler, true);
  });

  const timer = setInterval(() => {
    if (!state.running) return;
    refreshHooks();
    snapshot("poll");
  }, 300);

  function drain() {
    return state.events.splice(0, state.events.length);
  }

  function mark(label) {
    snapshot(`mark:${label}`, true);
    return push("mark", {label});
  }

  function status() {
    const scenes = sceneSnapshot();
    return {
      running: state.running,
      seq: state.seq,
      queuedEvents: state.events.length,
      href: location.href,
      activeScenes: scenes.filter(s => s.active || s.visible).map(s => s.name)
    };
  }

  function stop() {
    state.running = false;
    clearInterval(timer);
    ["click", "pointerdown", "pointerup"].forEach(name => {
      document.removeEventListener(name, domHandler, true);
    });
    push("probe_stopped");
    return status();
  }

  globalThis[API_NAME] = {
    drain,
    mark,
    status,
    snapshot: () => snapshot("manual", true),
    stop
  };

  refreshHooks();
  snapshot("installed", true);
  push("probe_installed", {apiName: API_NAME});
  return status();
})()
'''


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


async def drain_events(sniffer: ULSniffer) -> list[dict[str, Any]]:
    value = await evaluate_or_raise(
        sniffer,
        f"globalThis.{API_NAME}?.drain?.() ?? []",
    )
    return value if isinstance(value, list) else []


def append_events(path: Path, events: list[dict[str, Any]]) -> None:
    if not events:
        return
    with path.open("a", encoding="utf-8") as handle:
        for event in events:
            handle.write(json.dumps(event, ensure_ascii=False) + "\n")


async def collector(
    sniffer: ULSniffer,
    output_path: Path,
    stop_event: asyncio.Event,
) -> None:
    last_status = 0.0
    while not stop_event.is_set():
        try:
            append_events(output_path, await drain_events(sniffer))
            now = asyncio.get_running_loop().time()
            if now - last_status >= 2.0:
                status = await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}?.status?.()",
                )
                if isinstance(status, dict):
                    print(
                        f"[監看] Scene={status.get('activeScenes')}｜"
                        f"事件序號={status.get('seq')}"
                    )
                last_status = now
        except Exception as exc:
            print(f"[!] 收集失敗：{exc}")
            stop_event.set()
            break

        try:
            await asyncio.wait_for(stop_event.wait(), timeout=0.35)
        except asyncio.TimeoutError:
            pass


async def command_loop(sniffer: ULSniffer, output_path: Path) -> None:
    stop_event = asyncio.Event()
    task = asyncio.create_task(collector(sniffer, output_path, stop_event))

    print("\n=== UNLIGHT Web Flow Probe ===")
    print("保持此程式執行，照順序手動操作：")
    print("Bonus離開 → 開房 → 好友限定 → 等待/開始 → 投降 → Result OK → Bonus")
    print("M：標記階段　P：立即快照　Q：結束保存\n")

    try:
        while True:
            command = (await asyncio.to_thread(input, "> ")).strip().lower()

            if command == "m":
                label = (await asyncio.to_thread(input, "標記名稱：")).strip() or "manual"
                await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}.mark({json.dumps(label, ensure_ascii=False)})",
                )
                print(f"[+] 已標記：{label}")

            elif command == "p":
                result = await evaluate_or_raise(
                    sniffer,
                    f"globalThis.{API_NAME}.snapshot()",
                )
                print(f"[+] 已快照：{result.get('activeScenes') if isinstance(result, dict) else result}")

            elif command == "q":
                try:
                    await evaluate_or_raise(sniffer, f"globalThis.{API_NAME}.stop()")
                except Exception:
                    pass
                append_events(output_path, await drain_events(sniffer))
                print(f"[+] 已保存：{output_path.resolve()}")
                break

            else:
                print("使用 M／P／Q。")
    finally:
        stop_event.set()
        task.cancel()
        try:
            await task
        except asyncio.CancelledError:
            pass
        if sniffer.ws is not None:
            await sniffer.ws.close()


async def after_connect(sniffer: ULSniffer) -> None:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    output_path = OUTPUT_DIR / f"ul_web_flow_probe_{timestamp}.jsonl"

    print("[+] CDP 已連線，正在注入全流程偵測器……")
    status = await evaluate_or_raise(sniffer, PROBE_JS)
    print(f"[+] 偵測器已啟動：{status}")
    print(f"[+] 輸出：{output_path.resolve()}")

    await command_loop(sniffer, output_path)


def main() -> int:
    print(f"[+] 連接 Chrome CDP 127.0.0.1:{CDP_PORT}")
    sniffer = ULSniffer(port=CDP_PORT, verbose=False)
    sniffer.start(after_connect=after_connect)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
