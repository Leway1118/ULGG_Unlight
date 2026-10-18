from __future__ import annotations

import argparse
import asyncio
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from websockets.asyncio.client import connect

from db_raid_probe import (
    DEFAULT_TIMEOUT_SECONDS,
    RESPONSE_EVENT,
    DbRaidReadOnlyProbe,
    parse_db_raid_args,
    write_result,
)


DEFAULT_ENDPOINT = "wss://www.playunlight.online:15003/"
DEFAULT_OUTPUT_ROOT = (
    Path(__file__).resolve().parent
    / "data"
    / "db_raid_watcher"
)


def _now_local() -> datetime:
    return datetime.now(timezone.utc).astimezone()


def _output_paths(
    output_root: Path,
    *,
    now: datetime | None = None,
) -> tuple[Path, Path]:
    captured_at = now or _now_local()
    directory = output_root / captured_at.strftime("%Y%m%d")
    prefix = captured_at.strftime("%H%M%S_%f")
    return (
        directory / f"{prefix}_db_raid_raw.json",
        directory / f"{prefix}_db_raid_summary.json",
    )


def project_db_raid_rows(args: Any) -> list[dict[str, Any]]:
    rows = parse_db_raid_args(args)
    return [
        {
            "profound_id": row.get("profound_id"),
            "boss": row.get("name_tcn"),
            "hp": row.get("hp"),
            "hp_max": row.get("hp_max"),
            "map": row.get("map"),
            "stage": row.get("stage"),
            "rarity": row.get("rarity"),
            "treasure_level": row.get("treasure_level"),
        }
        for row in rows
    ]


def _read_raw_args(path: Path) -> Any:
    data = json.loads(path.read_text(encoding="utf-8"))
    return data["raw_args"]


class RaidDbWatcher:
    """Fetch one db_raid snapshot without joining or mutating a Raid."""

    def __init__(self, unlight_id: str) -> None:
        if not unlight_id.strip():
            raise ValueError("UNLIGHT_ID is required")
        self._probe = DbRaidReadOnlyProbe(unlight_id.strip())

    def _finalize(
        self,
        probe_result: dict[str, Any],
        *,
        raw_path: Path,
        summary_path: Path,
    ) -> dict[str, Any]:
        response_received = (
            probe_result.get("response_event") == RESPONSE_EVENT
            and raw_path.is_file()
        )
        rows: list[dict[str, Any]] = []
        parse_error: str | None = None
        if response_received:
            try:
                rows = project_db_raid_rows(_read_raw_args(raw_path))
            except (OSError, KeyError, TypeError, ValueError, json.JSONDecodeError):
                parse_error = "malformed_db_raid_payload"

        summary = {
            "ok": response_received and parse_error is None,
            "request_event": "db_raid",
            "request_args_shape": probe_result.get(
                "request_args_shape",
                ["id"],
            ),
            "request_sent": bool(probe_result.get("request_sent")),
            "response_event": (
                RESPONSE_EVENT if response_received else ""
            ),
            "raid_count": len(rows),
            "raids": rows,
            "raw_payload_file": (
                str(raw_path) if response_received else None
            ),
            "error": parse_error,
        }
        write_result(summary_path, summary)
        return summary

    async def run_on_socket(
        self,
        websocket,
        *,
        timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
        register_settle_seconds: float = 1.0,
        output_root: Path = DEFAULT_OUTPUT_ROOT,
        now: datetime | None = None,
    ) -> dict[str, Any]:
        raw_path, summary_path = _output_paths(output_root, now=now)
        probe_result = await self._probe.run_on_socket(
            websocket,
            timeout_seconds=timeout_seconds,
            register_settle_seconds=register_settle_seconds,
            debug_output=raw_path,
        )
        return self._finalize(
            probe_result,
            raw_path=raw_path,
            summary_path=summary_path,
        )

    async def run(
        self,
        endpoint: str,
        *,
        timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
        output_root: Path = DEFAULT_OUTPUT_ROOT,
    ) -> dict[str, Any]:
        async with connect(
            endpoint,
            ping_interval=None,
            max_size=2 * 1024 * 1024,
        ) as websocket:
            return await self.run_on_socket(
                websocket,
                timeout_seconds=timeout_seconds,
                output_root=output_root,
            )


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Fetch one read-only db_raid snapshot.",
    )
    parser.add_argument(
        "--endpoint",
        default=os.getenv("RAID_WS_ENDPOINT", DEFAULT_ENDPOINT).strip(),
    )
    parser.add_argument(
        "--timeout",
        type=float,
        default=DEFAULT_TIMEOUT_SECONDS,
    )
    parser.add_argument(
        "--output-root",
        type=Path,
        default=DEFAULT_OUTPUT_ROOT,
    )
    return parser.parse_args()


async def _async_main(args: argparse.Namespace) -> int:
    unlight_id = os.getenv("UNLIGHT_ID", "").strip()
    if not unlight_id:
        raise ValueError("UNLIGHT_ID is required")
    if args.timeout <= 0:
        raise ValueError("timeout must be positive")
    if not args.endpoint.startswith("wss://"):
        raise ValueError("RAID_WS_ENDPOINT must use wss://")

    watcher = RaidDbWatcher(unlight_id)
    result = await watcher.run(
        args.endpoint,
        timeout_seconds=args.timeout,
        output_root=args.output_root,
    )
    print(f"request_event={result['request_event']}")
    print(f"request_args_shape={result['request_args_shape']}")
    print(f"request_sent={result['request_sent']}")
    print(f"response_event={result['response_event']}")
    print(f"raid_count={result['raid_count']}")
    if result["raw_payload_file"]:
        print(f"raw payload written to {result['raw_payload_file']}")
    return 0 if result["ok"] else 2


def main() -> None:
    raise SystemExit(asyncio.run(_async_main(_parse_args())))


if __name__ == "__main__":
    main()
