from __future__ import annotations

import asyncio
import json
import logging
import time
from contextlib import suppress
from typing import Any

from websockets.asyncio.client import connect

from ..domain.models import RaidEntry, RawRaidSnapshot
from ..domain.status_rules import parse_raid_statuses


logger = logging.getLogger(__name__)
RECONNECT_DELAYS = (2, 5, 10, 30, 60)
ALLOWED_OUTBOUND_EVENTS = frozenset({
    "register",
    "db_raid",
    "__handshake_c",
    "__pong_s",
})


def _optional_string(value: Any) -> str | None:
    return value.strip() if isinstance(value, str) and value.strip() else None


def _optional_int(value: Any) -> int | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _db_raid_rows(payload: Any) -> list[Any]:
    if not isinstance(payload, list):
        raise ValueError("db_raid args must be a list")
    rows: Any = payload
    if len(payload) == 1 and isinstance(payload[0], list):
        rows = payload[0]
    if not isinstance(rows, list):
        raise ValueError("db_raid rows must be a list")
    return rows


def normalize_db_raid(payload: Any) -> tuple[RaidEntry, ...]:
    """Normalize the verified db_raid event args into the shared domain model."""
    raids: list[RaidEntry] = []
    for row in _db_raid_rows(payload):
        if not isinstance(row, dict):
            logger.warning("Ignored malformed db_raid row")
            continue

        raid_id = row.get("profound_id")
        if isinstance(raid_id, bool) or not isinstance(raid_id, (str, int)):
            logger.warning("Ignored db_raid row without profound_id")
            continue
        raid_id = str(raid_id).strip()
        if not raid_id:
            logger.warning("Ignored db_raid row without profound_id")
            continue

        points = row.get("points")
        participant_count = len(points) if isinstance(points, list) else 0
        raids.append(RaidEntry(
            profound_id=raid_id,
            monster_code=_optional_string(row.get("profound_mons")),
            monster_id=_optional_int(row.get("core_monster_id")),
            boss_name=_optional_string(row.get("name_tcn")),
            founder=_optional_string(row.get("profound_founder")),
            rarity=_optional_int(row.get("rarity")),
            level=_optional_int(row.get("level")),
            map_id=_optional_int(row.get("map")),
            stage_id=_optional_int(row.get("stage")),
            hp=_optional_int(row.get("hp")),
            hp_max=_optional_int(row.get("hp_max")),
            member_limit=_optional_int(row.get("member_limit")),
            participant_count=participant_count,
            pass_code=_optional_string(row.get("pass")),
            expires_at=_optional_int(row.get("limit")),
            already_joined=False,
            my_damage=None,
            my_point=None,
            statuses=parse_raid_statuses(row.get("state")),
            source="db_raid",
        ))
    return tuple(raids)


class DbRaidSource:
    source_name = "db_raid"
    push_driven = True

    def __init__(
        self,
        endpoint: str,
        owner_id: str,
        interval_seconds: float = 10.0,
        register_settle_seconds: float = 1.0,
    ) -> None:
        self.endpoint = endpoint
        self.owner_id = owner_id
        self.interval_seconds = interval_seconds
        self.register_settle_seconds = register_settle_seconds
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
                    "db_raid WebSocket disconnected; retrying in %s seconds (%s)",
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
            await asyncio.sleep(self.register_settle_seconds)

            loop = asyncio.get_running_loop()
            next_request = loop.time()
            while not self._closing:
                now = loop.time()
                if now >= next_request:
                    await self._emit(websocket, "db_raid", [self.owner_id])
                    next_request = now + self.interval_seconds

                timeout = max(0.05, next_request - loop.time())
                try:
                    message = await asyncio.wait_for(
                        websocket.recv(),
                        timeout=timeout,
                    )
                except asyncio.TimeoutError:
                    continue
                await self.process_message(message, websocket)

    async def _emit(self, websocket, event: str, args: list[Any]) -> None:
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
        await websocket.send(json.dumps(message, ensure_ascii=False))

    async def process_message(self, message: Any, websocket=None) -> bool:
        """Process one message while isolating malformed payloads."""
        try:
            data = json.loads(message)
            if not isinstance(data, dict):
                raise ValueError("message must be an object")
            event = data.get("event")
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

            if event != "db_raid":
                return True

            snapshot = RawRaidSnapshot(
                captured_at=time.time(),
                source=self.source_name,
                raids=normalize_db_raid(data.get("args")),
                connected=True,
            )
            if self._queue.full():
                self._queue.get_nowait()
            self._queue.put_nowait(snapshot)
            self._received_snapshot = True
            self.last_error = None
            return True
        except (TypeError, ValueError, KeyError, json.JSONDecodeError):
            self.last_error = "malformed db_raid event"
            logger.warning("Ignored malformed db_raid WebSocket event")
            return False
