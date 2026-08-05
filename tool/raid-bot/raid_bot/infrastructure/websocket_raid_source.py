from __future__ import annotations

import asyncio
import json
import logging
import time
from contextlib import suppress
from typing import Any

from websockets.asyncio.client import connect

from ..domain.models import RaidEntry, RawRaidSnapshot


logger = logging.getLogger(__name__)
RECONNECT_DELAYS = (2, 5, 10, 30, 60)


def _parse_count_pair(value: Any, field_name: str) -> tuple[int, int]:
    if not isinstance(value, str):
        raise ValueError(f"{field_name} must be a string")
    parts = value.split("/")
    if len(parts) != 2:
        raise ValueError(f"{field_name} must contain one slash")
    try:
        current, maximum = (
            int(part.strip().replace(",", ""))
            for part in parts
        )
    except ValueError as error:
        raise ValueError(f"{field_name} contains a non-integer") from error
    if current < 0 or maximum < 0:
        raise ValueError(f"{field_name} cannot be negative")
    return current, maximum


def _optional_string(value: Any) -> str | None:
    return value.strip() if isinstance(value, str) and value.strip() else None


def normalize_raid_support_list(payload: Any) -> tuple[RaidEntry, ...]:
    """Normalize the verified raid_support_list args into RaidEntry values."""
    if not isinstance(payload, list):
        raise ValueError("raid_support_list args must be a list")

    rows: Any = payload
    if len(payload) == 1 and isinstance(payload[0], list):
        rows = payload[0]
    if not isinstance(rows, list):
        raise ValueError("raid_support_list rows must be a list")

    raids: list[RaidEntry] = []
    for row in rows:
        if not isinstance(row, dict):
            raise ValueError("raid_support_list row must be an object")

        raid_id = row.get("prf_code")
        if not isinstance(raid_id, str) or not raid_id.strip():
            raise ValueError("prf_code is required")
        hp, hp_max = _parse_count_pair(row.get("prf_hp"), "prf_hp")
        members, member_limit = _parse_count_pair(
            row.get("prf_member"),
            "prf_member",
        )
        try:
            expires_at = int(row["prf_limit"])
        except (KeyError, TypeError, ValueError) as error:
            raise ValueError("prf_limit must be an integer") from error

        raids.append(RaidEntry(
            profound_id=raid_id.strip(),
            monster_code=_optional_string(row.get("prf_mons")),
            monster_id=None,
            boss_name=_optional_string(row.get("prf_name")),
            founder=_optional_string(row.get("prf_founder")),
            rarity=None,
            level=None,
            map_id=None,
            stage_id=None,
            hp=hp,
            hp_max=hp_max,
            member_limit=member_limit,
            participant_count=members,
            pass_code=None,
            expires_at=expires_at,
            already_joined=False,
            my_damage=None,
            my_point=None,
            source="websocket",
        ))
    return tuple(raids)


class WebSocketRaidSource:
    source_name = "websocket"
    push_driven = True

    def __init__(
        self,
        endpoint: str,
        owner_id: str,
        interval_seconds: float = 10.0,
    ) -> None:
        self.endpoint = endpoint
        self.owner_id = owner_id
        self.interval_seconds = interval_seconds
        self.connected = False
        self.last_error: str | None = None
        self._message_index = 0
        self._acks: list[str] = []
        self._queue: asyncio.Queue[RawRaidSnapshot] = asyncio.Queue(maxsize=1)
        self._runner: asyncio.Task[None] | None = None
        self._closing = False
        self._received_snapshot = False

    async def read_snapshot(self) -> RawRaidSnapshot:
        if self._runner is None:
            self._runner = asyncio.create_task(self._run())
        return await self._queue.get()

    async def close(self) -> None:
        self._closing = True
        if self._runner is not None:
            self._runner.cancel()
            with suppress(asyncio.CancelledError):
                await self._runner
            self._runner = None
        self.connected = False

    async def _run(self) -> None:
        retry_index = 0
        while not self._closing:
            try:
                await self._connection_session()
            except asyncio.CancelledError:
                raise
            except Exception as error:
                self.connected = False
                self.last_error = type(error).__name__
                if self._received_snapshot:
                    retry_index = 0
                    self._received_snapshot = False
                delay = RECONNECT_DELAYS[min(
                    retry_index,
                    len(RECONNECT_DELAYS) - 1,
                )]
                retry_index += 1
                logger.warning(
                    "Raid WebSocket disconnected; retrying in %s seconds (%s)",
                    delay,
                    type(error).__name__,
                )
                await asyncio.sleep(delay)

    async def _connection_session(self) -> None:
        async with connect(
            self.endpoint,
            ping_interval=None,
            max_size=2 * 1024 * 1024,
        ) as websocket:
            self.connected = True
            self.last_error = None
            await self._emit(websocket, "register", [self.owner_id])

            print("[WS WAITING AFTER REGISTER]")

            await asyncio.sleep(1)

            print("[WS REQUEST RAID]")

            loop = asyncio.get_running_loop()
            next_request = loop.time()
            next_ping = loop.time() + 30.0
            while not self._closing:
                now = loop.time()
                if now >= next_request:
                    await self._emit(
                        websocket,
                        "raid_get_support",
                        [self.owner_id],
                    )
                    next_request = now + self.interval_seconds
                if now >= next_ping:
                    await self._emit(websocket, "__ping_c", [])
                    next_ping = now + 30.0

                timeout = max(
                    0.05,
                    min(next_request, next_ping) - loop.time(),
                )
                try:
                    message = await asyncio.wait_for(
                        websocket.recv(),
                        timeout=timeout,
                    )
                except asyncio.TimeoutError:
                    continue
                await self.process_message(message, websocket)

    async def _emit(self, websocket, event: str, args: list[Any]) -> None:
        now = int(time.time() * 1000)
        if event in {"__pong_s", "__ping_c"}:
            args = [now]
        message_id = f"{event}:{self._message_index}:{now}"
        self._message_index += 1
        print("[WS SEND]", event, args)
        message = {
            "meta": {
                "id": message_id,
                "ACKs": self._acks,
            },
            "event": event,
            "args": args,
        }
        self._acks = []
        await websocket.send(json.dumps(message, ensure_ascii=False))

    async def process_message(self, message: Any, websocket=None) -> bool:
        """Process one server message; malformed messages are isolated."""
        try:
            data = json.loads(message)
            if not isinstance(data, dict):
                raise ValueError("message must be an object")
            event = data.get("event")
            print("[WS EVENT]", event, data)
            if not isinstance(event, str):
                raise ValueError("event is required")

            if event == "__ping_s":
                if websocket is None:
                    raise ValueError("websocket is required for pong")
                await self._emit(websocket, "__pong_s", [])
                return True
            if event == "__handshake_s":
                if websocket is None:
                    raise ValueError("websocket is required for handshake")
                await self._emit(websocket, "__handshake_c", ["1.1.2", None])
                return True
            if event.startswith("__"):
                return True

            meta = data.get("meta")
            if isinstance(meta, dict):
                message_id = meta.get("id")
                if isinstance(message_id, str) and message_id:
                    self._acks.append(message_id)

            if event != "raid_support_list":
                return True

            raids = normalize_raid_support_list(data.get("args"))
            snapshot = RawRaidSnapshot(
                captured_at=time.time(),
                source=self.source_name,
                raids=raids,
                connected=True,
            )
            if self._queue.full():
                self._queue.get_nowait()
            self._queue.put_nowait(snapshot)
            self._received_snapshot = True
            self.last_error = None
            return True
        except (TypeError, ValueError, KeyError, json.JSONDecodeError):
            self.last_error = "malformed WebSocket event"
            logger.warning("Ignored malformed Raid WebSocket event")
            return False
