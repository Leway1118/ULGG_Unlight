from __future__ import annotations

import argparse
import asyncio
import json
import os
import tempfile
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Awaitable, Callable

from websockets.asyncio.client import connect

from db_raid_probe import parse_db_raid_args
from raid_bot.infrastructure.public_raid_enrichment_client import (
    DEFAULT_DELETE_SETTLE_SECONDS,
)


DEFAULT_ENDPOINT = "wss://www.playunlight.online:15003/"
DEFAULT_TIMEOUT_SECONDS = 10.0
DEFAULT_REGISTER_SETTLE_SECONDS = 1.0
DEFAULT_OUTPUT_ROOT = Path(__file__).resolve().parent / "data" / "scanner_runs"

CONTROL_OUTBOUND_EVENTS = frozenset({
    "__handshake_c",
    "__pong_s",
})
READ_ONLY_OUTBOUND_EVENTS = frozenset({
    "register",
    "db_raid",
    "db_raid_reward",
})
MUTATING_OUTBOUND_EVENTS = frozenset({
    "raid_code_input",
    "db_raid_delete",
})
ALLOWED_OUTBOUND_EVENTS = (
    CONTROL_OUTBOUND_EVENTS
    | READ_ONLY_OUTBOUND_EVENTS
    | MUTATING_OUTBOUND_EVENTS
)

JOIN_RESPONSE_EVENTS = frozenset({
    "raid_found",
    "raid_code_error",
})


class ScannerTimeoutError(TimeoutError):
    pass


@dataclass(frozen=True)
class InboundEvent:
    event: str
    args: Any
    message: dict[str, Any]


@dataclass(frozen=True)
class CleanupCandidate:
    profound_id: str | None
    reason: str


@dataclass(frozen=True)
class RunArtifacts:
    directory: Path
    prefix: str

    def path(self, suffix: str) -> Path:
        return self.directory / f"{self.prefix}_{suffix}.json"


EnrichmentCallback = Callable[
    [dict[str, Any]],
    dict[str, Any] | Awaitable[dict[str, Any]],
]


def _now_local() -> datetime:
    return datetime.now(timezone.utc).astimezone()


def create_run_artifacts(
    output_root: Path,
    *,
    now: datetime | None = None,
) -> RunArtifacts:
    captured_at = now or _now_local()
    day = captured_at.strftime("%Y%m%d")
    prefix = captured_at.strftime("%H%M%S_%f")
    return RunArtifacts(output_root / day, prefix)


def _write_restricted_json(path: Path, payload: Any) -> None:
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
            os.chmod(temporary_path, 0o600)
            json.dump(payload, temporary, ensure_ascii=False, indent=2)
            temporary.write("\n")
            temporary.flush()
            os.fsync(temporary.fileno())
        os.replace(temporary_path, path)
    finally:
        if temporary_path is not None and temporary_path.exists():
            temporary_path.unlink()


def write_raw_event(
    path: Path,
    *,
    stage: str,
    inbound: InboundEvent,
    captured_at: datetime | None = None,
) -> None:
    timestamp = captured_at or _now_local()
    _write_restricted_json(path, {
        "warning": "Sensitive research payload; do not commit or share.",
        "captured_at": timestamp.isoformat(timespec="milliseconds"),
        "stage": stage,
        "response": inbound.message,
    })


def _safe_rows(args: Any) -> list[dict[str, Any]]:
    try:
        return parse_db_raid_args(args)
    except ValueError:
        return []


def select_cleanup_candidate(
    db_raid_args: Any,
    *,
    prf_code: str,
    require_pass_match: bool = False,
) -> CleanupCandidate:
    try:
        rows = parse_db_raid_args(db_raid_args)
    except ValueError:
        return CleanupCandidate(None, "malformed_db_raid")
    rows_with_id = [
        row
        for row in rows
        if isinstance(row.get("profound_id"), str)
        and row["profound_id"].strip()
    ]
    pass_matches = [
        row
        for row in rows_with_id
        if isinstance(row.get("pass"), str)
        and row["pass"] == prf_code
    ]
    if len(pass_matches) == 1:
        return CleanupCandidate(
            pass_matches[0]["profound_id"].strip(),
            "unique_pass_match",
        )
    if require_pass_match:
        return CleanupCandidate(None, "no_unique_pass_match")
    if len(rows_with_id) == 1 and not rows_with_id[0].get("pass"):
        return CleanupCandidate(
            rows_with_id[0]["profound_id"].strip(),
            "single_db_raid_row_without_pass",
        )
    if not rows_with_id:
        return CleanupCandidate(None, "no_profound_id")
    return CleanupCandidate(None, "ambiguous_db_raid_rows")


def select_target_row(
    db_raid_args: Any,
    *,
    prf_code: str,
) -> dict[str, Any] | None:
    try:
        rows = parse_db_raid_args(db_raid_args)
    except ValueError:
        return None
    matches = [
        row
        for row in rows
        if isinstance(row, dict)
        and isinstance(row.get("pass"), str)
        and row["pass"] == prf_code
        and isinstance(row.get("profound_id"), (str, int))
        and not isinstance(row.get("profound_id"), bool)
        and str(row["profound_id"]).strip()
    ]
    return dict(matches[0]) if len(matches) == 1 else None


def profound_id_is_absent(args: Any, profound_id: str) -> bool:
    try:
        rows = parse_db_raid_args(args)
    except ValueError:
        return False
    return all(row.get("profound_id") != profound_id for row in rows)


class RaidMetadataScanner:
    def __init__(
        self,
        unlight_id: str,
        prf_code: str,
        *,
        execute: bool = False,
        clock_ms: Callable[[], int] | None = None,
    ) -> None:
        if not unlight_id.strip():
            raise ValueError("UNLIGHT_ID is required")
        if not prf_code.strip():
            raise ValueError("prf_code is required")
        self._unlight_id = unlight_id.strip()
        self._prf_code = prf_code.strip()
        self._execute = execute
        self._clock_ms = clock_ms or (lambda: int(time.time() * 1000))
        self._message_index = 0
        self._acks: list[str] = []

    @property
    def execute(self) -> bool:
        return self._execute

    def _build_message(self, event: str, args: list[Any]) -> str:
        if event not in ALLOWED_OUTBOUND_EVENTS:
            raise ValueError(f"outbound event is forbidden: {event}")
        if event in MUTATING_OUTBOUND_EVENTS and not self._execute:
            raise ValueError(
                f"outbound mutation requires --execute: {event}"
            )

        now = self._clock_ms()
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

    async def _wait_for_event(
        self,
        websocket,
        expected: frozenset[str],
        *,
        timeout_seconds: float,
    ) -> InboundEvent:
        loop = asyncio.get_running_loop()
        deadline = loop.time() + timeout_seconds

        while True:
            remaining = deadline - loop.time()
            if remaining <= 0:
                raise ScannerTimeoutError(
                    f"timed out waiting for {sorted(expected)}"
                )
            try:
                message = await asyncio.wait_for(
                    websocket.recv(),
                    timeout=remaining,
                )
            except asyncio.TimeoutError as error:
                raise ScannerTimeoutError(
                    f"timed out waiting for {sorted(expected)}"
                ) from error

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

            if event not in expected:
                continue
            return InboundEvent(
                event=event,
                args=data.get("args"),
                message=data,
            )

    async def _request(
        self,
        websocket,
        event: str,
        *,
        timeout_seconds: float,
    ) -> InboundEvent:
        await self._send(websocket, event, [self._unlight_id])
        return await self._wait_for_event(
            websocket,
            frozenset({event}),
            timeout_seconds=timeout_seconds,
        )

    def dry_run_result(
        self,
        *,
        request_db_raid_reward: bool = True,
    ) -> dict[str, Any]:
        planned_events = [
            "register",
            "raid_code_input",
            "db_raid",
        ]
        if request_db_raid_reward:
            planned_events.append("db_raid_reward")
        planned_events.extend([
            "db_raid_delete (only with an unambiguous profound_id)",
            "db_raid (cleanup verification)",
        ])
        return {
            "ok": True,
            "mode": "dry-run",
            "connected": False,
            "single_raid_only": True,
            "planned_events": planned_events,
        }

    async def run_on_socket(
        self,
        websocket,
        *,
        timeout_seconds: float = DEFAULT_TIMEOUT_SECONDS,
        register_settle_seconds: float = DEFAULT_REGISTER_SETTLE_SECONDS,
        delete_settle_seconds: float = DEFAULT_DELETE_SETTLE_SECONDS,
        output_root: Path = DEFAULT_OUTPUT_ROOT,
        now: datetime | None = None,
        enrich_target: EnrichmentCallback | None = None,
        request_db_raid_reward: bool = True,
    ) -> dict[str, Any]:
        if timeout_seconds <= 0:
            raise ValueError("timeout must be positive")
        if register_settle_seconds < 0 or delete_settle_seconds < 0:
            raise ValueError("settle delays cannot be negative")
        if not self._execute:
            return self.dry_run_result(
                request_db_raid_reward=request_db_raid_reward
            )

        artifacts = create_run_artifacts(output_root, now=now)
        result: dict[str, Any] = {
            "ok": False,
            "mode": "execute",
            "single_raid_only": True,
            "join_outcome": "timeout",
            "db_raid_received": False,
            "db_raid_reward_requested": request_db_raid_reward,
            "db_raid_reward_received": False,
            "db_raid_count": 0,
            "cleanup_attempted": False,
            "cleanup_confirmed": False,
            "cleanup_lookup_attempted": False,
            "cleanup_candidate_reason": "not_evaluated",
            "enrichment": None,
            "enrichment_error": None,
            "output_directory": str(artifacts.directory),
        }

        await self._send(websocket, "register", [self._unlight_id])
        if register_settle_seconds:
            await asyncio.sleep(register_settle_seconds)

        await self._send(
            websocket,
            "raid_code_input",
            [self._unlight_id, self._prf_code],
        )
        try:
            join_event = await self._wait_for_event(
                websocket,
                JOIN_RESPONSE_EVENTS,
                timeout_seconds=timeout_seconds,
            )
            result["join_outcome"] = join_event.event
            write_raw_event(
                artifacts.path("join_outcome"),
                stage="join_outcome",
                inbound=join_event,
                captured_at=now,
            )
        except ScannerTimeoutError:
            join_event = None

        if join_event is None:
            result["cleanup_lookup_attempted"] = True
        try:
            db_raid_event = await self._request(
                websocket,
                "db_raid",
                timeout_seconds=timeout_seconds,
            )
            result["db_raid_received"] = True
            db_raid_rows = _safe_rows(db_raid_event.args)
            result["db_raid_count"] = len(db_raid_rows)
            write_raw_event(
                artifacts.path("db_raid"),
                stage="db_raid_after_join",
                inbound=db_raid_event,
                captured_at=now,
            )
        except ScannerTimeoutError:
            db_raid_event = None

        candidate = CleanupCandidate(None, "join_not_confirmed")
        target_row = None
        if join_event is not None and join_event.event == "raid_code_error":
            candidate = CleanupCandidate(None, "join_not_confirmed")
        elif db_raid_event is None:
            candidate = CleanupCandidate(None, "db_raid_missing")
        else:
            candidate = select_cleanup_candidate(
                db_raid_event.args,
                prf_code=self._prf_code,
                require_pass_match=(join_event is None),
            )
            target_row = select_target_row(
                db_raid_event.args,
                prf_code=self._prf_code,
            )
        result["cleanup_candidate_reason"] = candidate.reason

        if enrich_target is not None and target_row is not None:
            try:
                enrichment = enrich_target(target_row)
                if asyncio.iscoroutine(enrichment):
                    enrichment = await asyncio.wait_for(
                        enrichment,
                        timeout=timeout_seconds,
                    )
                if not isinstance(enrichment, dict):
                    raise TypeError("enrichment callback must return an object")
                result["enrichment"] = enrichment
            except Exception as error:
                result["enrichment_error"] = type(error).__name__

        reward_event = None
        if request_db_raid_reward:
            try:
                reward_event = await self._request(
                    websocket,
                    "db_raid_reward",
                    timeout_seconds=timeout_seconds,
                )
                result["db_raid_reward_received"] = True
                write_raw_event(
                    artifacts.path("db_raid_reward"),
                    stage="db_raid_reward_after_join",
                    inbound=reward_event,
                    captured_at=now,
                )
            except ScannerTimeoutError:
                pass

        if candidate.profound_id is not None:
            result["cleanup_attempted"] = True
            await self._send(
                websocket,
                "db_raid_delete",
                [self._unlight_id, candidate.profound_id],
            )
            if delete_settle_seconds:
                await asyncio.sleep(delete_settle_seconds)
            try:
                verify_event = await self._request(
                    websocket,
                    "db_raid",
                    timeout_seconds=timeout_seconds,
                )
                result["cleanup_confirmed"] = profound_id_is_absent(
                    verify_event.args,
                    candidate.profound_id,
                )
                write_raw_event(
                    artifacts.path("db_raid_after_delete"),
                    stage="db_raid_after_delete",
                    inbound=verify_event,
                    captured_at=now,
                )
            except ScannerTimeoutError:
                pass

        result["ok"] = (
            result["join_outcome"] == "raid_found"
            and result["db_raid_received"]
            and (
                not request_db_raid_reward
                or result["db_raid_reward_received"]
            )
            and result["cleanup_attempted"]
            and result["cleanup_confirmed"]
        )
        _write_restricted_json(artifacts.path("summary"), result)
        return result

    async def run(
        self,
        endpoint: str,
        **kwargs: Any,
    ) -> dict[str, Any]:
        if not self._execute:
            return self.dry_run_result(
                request_db_raid_reward=bool(
                    kwargs.get("request_db_raid_reward", True)
                )
            )
        async with connect(
            endpoint,
            ping_interval=None,
            max_size=2 * 1024 * 1024,
        ) as websocket:
            return await self.run_on_socket(websocket, **kwargs)


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Run one isolated Raid metadata scan. The default mode is "
            "dry-run and sends no WebSocket events."
        ),
    )
    parser.add_argument(
        "--prf-code",
        default=os.getenv("PRF_CODE", "").strip(),
        help="One Raid support code; defaults to PRF_CODE.",
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
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument(
        "--execute",
        action="store_true",
        help=(
            "Allow one raid_code_input and, if safely identified, one "
            "db_raid_delete."
        ),
    )
    mode.add_argument(
        "--dry-run",
        action="store_true",
        help="Explicitly select the default no-network mode.",
    )
    return parser.parse_args()


async def _async_main(args: argparse.Namespace) -> int:
    unlight_id = os.getenv(
        "UNLIGHT_ID",
        "",
    ).strip()

    if not unlight_id:
        raise ValueError("UNLIGHT_ID is required")
    if not args.prf_code.strip():
        raise ValueError("--prf-code or PRF_CODE is required")
    if args.timeout <= 0:
        raise ValueError("timeout must be positive")
    if args.execute and not args.endpoint.startswith("wss://"):
        raise ValueError("RAID_WS_ENDPOINT must use wss://")

    scanner = RaidMetadataScanner(
        unlight_id,
        args.prf_code,
        execute=args.execute,
    )
    result = await scanner.run(
        args.endpoint,
        timeout_seconds=args.timeout,
        output_root=args.output_root,
    )
    if result["mode"] == "dry-run":
        print("Dry-run only; no WebSocket connection or event was sent.")
        for event in result["planned_events"]:
            print(f"- {event}")
        return 0

    print(f"Scanner output written under {result['output_directory']}")
    print(f"join_outcome={result['join_outcome']}")
    print(f"db_raid_received={result['db_raid_received']}")
    print(
        "db_raid_reward_received="
        f"{result['db_raid_reward_received']}"
    )
    print(f"cleanup_attempted={result['cleanup_attempted']}")
    print(f"cleanup_confirmed={result['cleanup_confirmed']}")
    return 0 if result["ok"] else 2


def main() -> None:
    args = _parse_args()
    raise SystemExit(asyncio.run(_async_main(args)))


if __name__ == "__main__":
    main()
