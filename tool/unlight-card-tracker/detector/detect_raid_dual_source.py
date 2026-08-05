# -*- coding: utf-8 -*-
from __future__ import annotations

import asyncio
import json
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from ul_sniffer import ULSniffer


CDP_PORT = 59222
POLL_INTERVAL_SECONDS = 1.0
DEFAULT_DURATION_SECONDS = 180
OUTPUT_PATH = Path("raid_dual_source_log.jsonl")
SUMMARY_PATH = Path("raid_dual_source_summary.json")


READ_DUAL_SOURCE_JS = r"""
(() => {
  const ROOT_KEY = "__ULGG_RAID_SOURCE_PROBE__";

  if (!globalThis[ROOT_KEY]) {
    globalThis[ROOT_KEY] = {
      version: "0.1.0",
      installedAt: Date.now(),
      attachCount: 0,
      eventSeq: 0,
      lastEventAt: null,
      lastEventData: null,
      lastError: null,
      attachedSocket: null,
      timerId: null,
    };
  }

  const probe = globalThis[ROOT_KEY];

  const cloneSafe = (value) => {
    try {
      return JSON.parse(JSON.stringify(value));
    } catch (error) {
      probe.lastError = `clone failed: ${String(error)}`;
      return null;
    }
  };

  const attachListener = () => {
    try {
      const raid = globalThis.game?.scene?.keys?.Raid;
      const socket = raid?.socket;

      if (!socket || typeof socket.on !== "function") {
        return false;
      }

      if (probe.attachedSocket === socket) {
        return true;
      }

      socket.on("db_raid", (data) => {
        probe.eventSeq += 1;
        probe.lastEventAt = Date.now();
        probe.lastEventData = cloneSafe(data);
      });

      probe.attachedSocket = socket;
      probe.attachCount += 1;
      probe.lastError = null;
      return true;
    } catch (error) {
      probe.lastError = `attach failed: ${String(error)}`;
      return false;
    }
  };

  if (!probe.timerId) {
    probe.timerId = globalThis.setInterval(attachListener, 250);
  }

  const listenerAttached = attachListener();
  const raid = globalThis.game?.scene?.keys?.Raid;
  const raidData = Array.isArray(raid?.raid_data)
    ? cloneSafe(raid.raid_data)
    : [];

  return JSON.stringify({
    ok: true,
    capturedAt: Date.now(),
    capturedAtIso: new Date().toISOString(),
    raidSceneExists: Boolean(raid),
    raidSceneActive: Boolean(raid?.scene?.isActive?.()),
    playerName:
      raid?.player?.name ??
      globalThis.game?.scene?.keys?.Lobby?.player?.name ??
      null,
    listenerAttached,
    observer: {
      version: probe.version,
      installedAt: probe.installedAt,
      attachCount: probe.attachCount,
      eventSeq: probe.eventSeq,
      lastEventAt: probe.lastEventAt,
      lastEventData: probe.lastEventData,
      lastError: probe.lastError,
    },
    sceneRaidData: raidData,
  });
})()
"""


@dataclass
class ProbeStats:
    sample_count: int = 0
    event_count: int = 0
    scene_available_count: int = 0
    scene_active_count: int = 0
    exact_match_count: int = 0
    id_match_count: int = 0
    mismatch_count: int = 0
    evaluate_error_count: int = 0
    parse_error_count: int = 0


def utc_now_iso() -> str:
    return datetime.now(timezone.utc).astimezone().isoformat(timespec="milliseconds")


def append_jsonl(path: Path, record: dict[str, Any]) -> None:
    with path.open("a", encoding="utf-8") as fp:
        fp.write(json.dumps(record, ensure_ascii=False, separators=(",", ":")))
        fp.write("\n")


def raid_id(row: Any) -> str | None:
    if not isinstance(row, dict):
        return None

    value = row.get("profound_id")
    if value is None:
        value = row.get("prodound_id")

    if value is None:
        return None

    return str(value)


def normalized_raid_ids(rows: Any) -> list[str]:
    if not isinstance(rows, list):
        return []

    ids = [raid_id(row) for row in rows]
    return sorted(value for value in ids if value is not None)


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def compare_sources(event_rows: Any, scene_rows: Any) -> dict[str, Any]:
    event_list = event_rows if isinstance(event_rows, list) else []
    scene_list = scene_rows if isinstance(scene_rows, list) else []

    event_ids = normalized_raid_ids(event_list)
    scene_ids = normalized_raid_ids(scene_list)

    ids_match = event_ids == scene_ids
    exact_match = canonical_json(event_list) == canonical_json(scene_list)

    event_id_set = set(event_ids)
    scene_id_set = set(scene_ids)

    return {
        "event_count": len(event_list),
        "scene_count": len(scene_list),
        "event_ids": event_ids,
        "scene_ids": scene_ids,
        "ids_match": ids_match,
        "exact_match": exact_match,
        "only_in_event": sorted(event_id_set - scene_id_set),
        "only_in_scene": sorted(scene_id_set - event_id_set),
    }


def read_duration_seconds() -> int:
    raw = input(
        f"[+] 測試秒數（直接 Enter = {DEFAULT_DURATION_SECONDS} 秒）："
    ).strip()

    if not raw:
        return DEFAULT_DURATION_SECONDS

    try:
        value = int(raw)
    except ValueError:
        print(f"[!] 無效數字，改用 {DEFAULT_DURATION_SECONDS} 秒")
        return DEFAULT_DURATION_SECONDS

    return max(10, value)


async def after_connect(sniffer: ULSniffer) -> None:
    print("\n[+] 已連接遊戲")
    print("[+] 這支腳本只做唯讀驗證，不會操作遊戲")
    print("[+] 建議測試動作：")
    print("    1. 停在公開 Raid 清單")
    print("    2. 等待 HP／狀態／人數更新")
    print("    3. 切離 Raid 畫面再切回")
    print("    4. 等待 Raid 新增或消失")

    duration_seconds = read_duration_seconds()
    input("[+] 準備好後按 Enter 開始記錄……")

    OUTPUT_PATH.write_text("", encoding="utf-8")
    stats = ProbeStats()
    last_event_seq = -1
    latest_event_rows: list[Any] | None = None
    start_loop_time = asyncio.get_running_loop().time()

    append_jsonl(
        OUTPUT_PATH,
        {
            "record_type": "probe_start",
            "recorded_at": utc_now_iso(),
            "duration_seconds": duration_seconds,
            "poll_interval_seconds": POLL_INTERVAL_SECONDS,
            "cdp_port": CDP_PORT,
        },
    )

    try:
        while True:
            elapsed = asyncio.get_running_loop().time() - start_loop_time
            if elapsed >= duration_seconds:
                break

            value, error = await sniffer.evaluate(
                READ_DUAL_SOURCE_JS,
                await_promise=False,
            )

            stats.sample_count += 1

            if error:
                stats.evaluate_error_count += 1
                append_jsonl(
                    OUTPUT_PATH,
                    {
                        "record_type": "evaluate_error",
                        "recorded_at": utc_now_iso(),
                        "elapsed_seconds": round(elapsed, 3),
                        "error": str(error),
                    },
                )
                print(f"[{stats.sample_count:03d}] evaluate error: {error}")
                await asyncio.sleep(POLL_INTERVAL_SECONDS)
                continue

            try:
                data = json.loads(value)
            except (TypeError, json.JSONDecodeError) as exc:
                stats.parse_error_count += 1
                append_jsonl(
                    OUTPUT_PATH,
                    {
                        "record_type": "parse_error",
                        "recorded_at": utc_now_iso(),
                        "elapsed_seconds": round(elapsed, 3),
                        "error": str(exc),
                        "raw_value": value,
                    },
                )
                print(f"[{stats.sample_count:03d}] parse error: {exc}")
                await asyncio.sleep(POLL_INTERVAL_SECONDS)
                continue

            observer = data.get("observer") or {}
            event_seq = int(observer.get("eventSeq") or 0)
            event_rows = observer.get("lastEventData")
            scene_rows = data.get("sceneRaidData") or []

            if data.get("raidSceneExists"):
                stats.scene_available_count += 1
            if data.get("raidSceneActive"):
                stats.scene_active_count += 1

            if event_seq != last_event_seq:
                if event_seq > 0:
                    stats.event_count += 1
                    latest_event_rows = event_rows if isinstance(event_rows, list) else []
                    append_jsonl(
                        OUTPUT_PATH,
                        {
                            "record_type": "db_raid_event",
                            "recorded_at": utc_now_iso(),
                            "elapsed_seconds": round(elapsed, 3),
                            "event_seq": event_seq,
                            "event_at_ms": observer.get("lastEventAt"),
                            "event_data": event_rows,
                        },
                    )
                last_event_seq = event_seq

            comparison = compare_sources(latest_event_rows, scene_rows)

            if latest_event_rows is not None:
                if comparison["exact_match"]:
                    stats.exact_match_count += 1
                elif comparison["ids_match"]:
                    stats.id_match_count += 1
                else:
                    stats.mismatch_count += 1

            append_jsonl(
                OUTPUT_PATH,
                {
                    "record_type": "scene_snapshot",
                    "recorded_at": utc_now_iso(),
                    "elapsed_seconds": round(elapsed, 3),
                    "captured_at_ms": data.get("capturedAt"),
                    "raid_scene_exists": data.get("raidSceneExists"),
                    "raid_scene_active": data.get("raidSceneActive"),
                    "player_name": data.get("playerName"),
                    "listener_attached": data.get("listenerAttached"),
                    "observer_status": {
                        "attach_count": observer.get("attachCount"),
                        "event_seq": event_seq,
                        "last_event_at_ms": observer.get("lastEventAt"),
                        "last_error": observer.get("lastError"),
                    },
                    "scene_raid_data": scene_rows,
                    "comparison": comparison,
                },
            )

            status = "no-event"
            if latest_event_rows is not None:
                if comparison["exact_match"]:
                    status = "exact"
                elif comparison["ids_match"]:
                    status = "same-ids"
                else:
                    status = "different"

            print(
                f"[{stats.sample_count:03d}] "
                f"active={bool(data.get('raidSceneActive'))} "
                f"listener={bool(data.get('listenerAttached'))} "
                f"event_seq={event_seq} "
                f"event_raids={comparison['event_count']} "
                f"scene_raids={comparison['scene_count']} "
                f"compare={status}"
            )

            await asyncio.sleep(POLL_INTERVAL_SECONDS)

    finally:
        summary = {
            "ok": True,
            "finished_at": utc_now_iso(),
            "duration_seconds": duration_seconds,
            "output_path": str(OUTPUT_PATH.resolve()),
            "stats": {
                "sample_count": stats.sample_count,
                "db_raid_event_count": stats.event_count,
                "scene_available_count": stats.scene_available_count,
                "scene_active_count": stats.scene_active_count,
                "exact_match_count": stats.exact_match_count,
                "same_ids_but_content_diff_count": stats.id_match_count,
                "id_mismatch_count": stats.mismatch_count,
                "evaluate_error_count": stats.evaluate_error_count,
                "parse_error_count": stats.parse_error_count,
            },
            "interpretation_notes": [
                "exact_match_count 代表兩來源完整 JSON 相同的採樣次數",
                "same_ids_but_content_diff_count 代表 Raid ID 相同但 HP/state/points 等內容不同",
                "id_mismatch_count 代表兩來源的 Raid 清單成員不同",
                "完整判讀仍需依 JSONL 中事件時序與玩家操作對照",
            ],
        }

        SUMMARY_PATH.write_text(
            json.dumps(summary, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )

        append_jsonl(
            OUTPUT_PATH,
            {
                "record_type": "probe_end",
                "recorded_at": utc_now_iso(),
                "summary": summary,
            },
        )

        print("\n[+] 雙來源驗證完成")
        print(f"[+] 完整紀錄：{OUTPUT_PATH.resolve()}")
        print(f"[+] 摘要：{SUMMARY_PATH.resolve()}")
        print(json.dumps(summary["stats"], ensure_ascii=False, indent=2))

        try:
            await sniffer.ws.close()
        except Exception:
            pass


def main() -> None:
    sniffer = ULSniffer(
        port=CDP_PORT,
        verbose=False,
    )
    sniffer.start(after_connect=after_connect)


if __name__ == "__main__":
    main()
