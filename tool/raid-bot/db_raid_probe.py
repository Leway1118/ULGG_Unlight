from __future__ import annotations

import argparse
import asyncio
import json
import os
import tempfile
import time
from pathlib import Path
from typing import Any

from websockets.asyncio.client import connect
from websockets.exceptions import ConnectionClosed

from raid_bot.config import RAID_OWNER_ID, RAID_WS_ENDPOINT


REQUEST_EVENT = "db_raid"
RESPONSE_EVENT = "db_raid"
PARAMETER_SOURCE = "RAID_OWNER_ID environment variable"
DEFAULT_OUTPUT_FILE = Path("db_raid_probe_result.json")
DEFAULT_TIMEOUT_SECONDS = 10.0
REGISTER_SETTLE_SECONDS = 1.0

ALLOWED_OUTBOUND_EVENTS = frozenset({
    "register",
    REQUEST_EVENT,
    "__handshake_c",
    "__pong_s",
})

TARGET_FIELDS = (
    "map",
    "stage",
    "rarity",
    "treasure_level",
)


def _empty_result(*, request_sent: bool = False) -> dict[str, Any]:
    return {
        "request_event": REQUEST_EVENT,
        "request_args_shape": ["id"],
        "request_arg_count": 1,
        "request_sent": request_sent,
        "response_event": "",
        "parameter_source": PARAMETER_SOURCE,
        "schema_keys": [],
        "raid_count": 0,
        "relation_candidates": [
            {
                "left": "prf_code",
                "right": "pass",
                "status": "not_testable",
                "reason": "raid_support_list is not requested by this probe",
            },
            {
                "left": "owner_id",
                "right": "pass",
                "status": "not_testable",
                "matching_rows": 0,
            },
            {
                "left": "profound_id",
                "right": "pass",
                "status": "not_testable",
                "matching_rows": 0,
            },
        ],
    }


def parse_db_raid_args(args: Any) -> list[dict[str, Any]]:
    """Parse the known event argument shapes without retaining raw payload."""
    if not isinstance(args, list):
        raise ValueError("db_raid args must be a list")

    rows: Any = args
    if len(args) == 1 and isinstance(args[0], list):
        rows = args[0]
    if not isinstance(rows, list) or not all(
        isinstance(row, dict)
        for row in rows
    ):
        raise ValueError("db_raid rows must be objects")
    return rows


def summarize_db_raid(
    args: Any,
    *,
    owner_id: str,
) -> dict[str, Any]:
    rows = parse_db_raid_args(args)
    schema_keys = sorted({
        key
        for row in rows
        for key in row
        if isinstance(key, str)
    })
    owner_pass_matches = sum(
        1
        for row in rows
        if isinstance(row.get("pass"), str)
        and row["pass"] == owner_id
    )
    profound_pass_matches = sum(
        1
        for row in rows
        if isinstance(row.get("pass"), str)
        and isinstance(row.get("profound_id"), str)
        and row["pass"] == row["profound_id"]
    )

    result = _empty_result(request_sent=True)
    result["response_event"] = RESPONSE_EVENT
    result["schema_keys"] = schema_keys
    result["raid_count"] = len(rows)
    result["relation_candidates"][1].update({
        "status": (
            "not_testable"
            if not rows
            else "matched" if owner_pass_matches else "not_matched"
        ),
        "matching_rows": owner_pass_matches,
    })
    result["relation_candidates"][2].update({
        "status": (
            "not_testable"
            if not rows
            else "matched" if profound_pass_matches else "not_matched"
        ),
        "matching_rows": profound_pass_matches,
    })
    result["relation_candidates"].append({
        "left": "db_raid",
        "right": "reward_metadata_fields",
        "status": "observed" if schema_keys else "not_observed",
        "present_fields": [
            field
            for field in TARGET_FIELDS
            if field in schema_keys
        ],
    })
    return result


def inspect_nested_structure(value: Any) -> dict[str, Any]:
    nested_keys: set[str] = set()
    lengths_by_path: dict[str, set[int]] = {}

    def walk(current: Any, path: str) -> None:
        if isinstance(current, dict):
            for key, child in current.items():
                if not isinstance(key, str):
                    continue
                child_path = f"{path}.{key}" if path else key
                nested_keys.add(child_path)
                walk(child, child_path)
            return
        if isinstance(current, list):
            lengths_by_path.setdefault(path, set()).add(len(current))
            child_path = f"{path}[]"
            for child in current:
                walk(child, child_path)

    walk(value, "args")
    return {
        "nested_keys": sorted(nested_keys),
        "list_lengths": [
            {
                "path": path,
                "observed_lengths": sorted(lengths),
            }
            for path, lengths in sorted(lengths_by_path.items())
        ],
    }


def build_debug_output(
    args: Any,
    summary: dict[str, Any],
) -> dict[str, Any]:
    structure = inspect_nested_structure(args)
    return {
        "warning": "Sensitive diagnostic payload; do not commit or share.",
        "request_event": summary["request_event"],
        "request_args_shape": summary["request_args_shape"],
        "request_sent": summary["request_sent"],
        "response_event": summary["response_event"],
        "raw_args": args,
        "schema_keys": summary["schema_keys"],
        "nested_keys": structure["nested_keys"],
        "list_lengths": structure["list_lengths"],
    }


class DbRaidReadOnlyProbe:
    def __init__(self, owner_id: str) -> None:
        self._owner_id = owner_id
        self._message_index = 0
        self._acks: list[str] = []

    def _build_message(self, event: str, args: list[Any]) -> str:
        if event not in ALLOWED_OUTBOUND_EVENTS:
            raise ValueError(f"outbound event is not read-only: {event}")
        now = int(time.time() * 1000)
        if event == "__pong_s":
            args = [now]
        message_id = f"{event}:{self._message_index}:{now}"
        self._message_index += 1
        message = {
            "meta": {
                "id": message_id,
                "ACKs": self._acks,
            },
            "event": event,
            "args": args,
        }
        self._acks = []
        return json.dumps(message, ensure_ascii=False)

    async def _send(
        self,
        websocket,
        event: str,
        args: list[Any],
    ) -> None:
        await websocket.send(self._build_message(event, args))

    async def _handle_control_event(
        self,
        websocket,
        event: str,
    ) -> bool:
        if event == "__ping_s":
            await self._send(websocket, "__pong_s", [])
            return True
        if event == "__handshake_s":
            await self._send(
                websocket,
                "__handshake_c",
                ["1.1.2", None],
            )
            return True
        return event.startswith("__")

    async def run_on_socket(
        self,
        websocket,
        *,
        timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
        register_settle_seconds: float = REGISTER_SETTLE_SECONDS,
        debug_output: Path | None = None,
    ) -> dict[str, Any]:
        await self._send(websocket, "register", [self._owner_id])

        loop = asyncio.get_running_loop()
        request_at = loop.time() + register_settle_seconds
        response_deadline: float | None = None
        request_sent = False

        while True:
            now = loop.time()
            if not request_sent and now >= request_at:
                print("[DEBUG] sending db_raid request")
                await self._send(websocket, REQUEST_EVENT, [self._owner_id])
                request_sent = True
                print("[DEBUG] request_sent=True")
                response_deadline = now + timeout_seconds

            if request_sent:
                assert response_deadline is not None
                remaining = response_deadline - loop.time()
                if remaining <= 0:
                    return _empty_result(request_sent=request_sent)
                receive_timeout = remaining
            else:
                receive_timeout = max(0.01, request_at - loop.time())

            try:
                message = await asyncio.wait_for(
                    websocket.recv(),
                    timeout=receive_timeout,
                )
            except ConnectionClosed:
                return _empty_result(request_sent=request_sent)
            except asyncio.TimeoutError:
                if request_sent:
                    return _empty_result(request_sent=True)
                continue

            try:
                data = json.loads(message)
            except (TypeError, json.JSONDecodeError):
                continue
            if not isinstance(data, dict):
                continue

            event = data.get("event")
            if not isinstance(event, str):
                continue
            if await self._handle_control_event(websocket, event):
                continue

            meta = data.get("meta")
            if isinstance(meta, dict):
                message_id = meta.get("id")
                if isinstance(message_id, str) and message_id:
                    self._acks.append(message_id)
            print(
                "[DEBUG]",
                "event=",
                event,
                "request_sent=",
                request_sent,
            )
            if event != RESPONSE_EVENT or not request_sent:
                continue
            response_args = data.get("args")
            try:
                result = summarize_db_raid(
                    response_args,
                    owner_id=self._owner_id,
                )
            except ValueError:
                result = _empty_result(request_sent=True)
            if debug_output is not None:
                write_debug_output(debug_output, response_args, result)
            return result

    async def run(
        self,
        endpoint: str,
        *,
        timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
        debug_output: Path | None = None,
    ) -> dict[str, Any]:
        async with connect(
            endpoint,
            ping_interval=None,
            max_size=2 * 1024 * 1024,
        ) as websocket:
            return await self.run_on_socket(
                websocket,
                timeout_seconds=timeout_seconds,
                debug_output=debug_output,
            )


def _write_json(
    path: Path,
    payload: dict[str, Any],
    *,
    restricted: bool,
) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path: Path | None = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w",
            encoding="utf-8",
            dir=path.parent,
            prefix=f".{path.name}.",
            suffix=".tmp",
            delete=False,
        ) as temporary:
            temporary_path = Path(temporary.name)
            if restricted:
                os.chmod(temporary_path, 0o600)
            json.dump(payload, temporary, ensure_ascii=False, indent=2)
            temporary.write("\n")
            temporary.flush()
            os.fsync(temporary.fileno())
        os.replace(temporary_path, path)
    finally:
        if temporary_path is not None and temporary_path.exists():
            temporary_path.unlink()


def write_result(path: Path, result: dict[str, Any]) -> None:
    _write_json(path, result, restricted=False)


def write_debug_output(
    path: Path,
    args: Any,
    summary: dict[str, Any],
) -> None:
    _write_json(
        path,
        build_debug_output(args, summary),
        restricted=True,
    )


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Run one read-only db_raid WebSocket probe.",
    )
    parser.add_argument(
        "--output",
        type=Path,
        default=DEFAULT_OUTPUT_FILE,
    )
    parser.add_argument(
        "--timeout",
        type=float,
        default=DEFAULT_TIMEOUT_SECONDS,
    )
    parser.add_argument(
        "--debug-output",
        type=Path,
        help=(
            "Write sensitive raw response args and nested schema details "
            "to this separate file."
        ),
    )
    return parser.parse_args()


async def _async_main(args: argparse.Namespace) -> int:
    if not RAID_OWNER_ID:
        raise ValueError("RAID_OWNER_ID is required")
    if not RAID_WS_ENDPOINT.startswith("wss://"):
        raise ValueError("RAID_WS_ENDPOINT must use wss://")
    if args.timeout <= 0:
        raise ValueError("timeout must be positive")

    probe = DbRaidReadOnlyProbe(RAID_OWNER_ID)
    result = await probe.run(
        RAID_WS_ENDPOINT,
        timeout_seconds=args.timeout,
        debug_output=args.debug_output,
    )
    write_result(args.output, result)
    print(f"db_raid probe result written to {args.output}")
    if args.debug_output is not None:
        print(f"sensitive debug output written to {args.debug_output}")
    return 0 if result["response_event"] == RESPONSE_EVENT else 2


def main() -> None:
    args = _parse_args()
    raise SystemExit(asyncio.run(_async_main(args)))


if __name__ == "__main__":
    main()
