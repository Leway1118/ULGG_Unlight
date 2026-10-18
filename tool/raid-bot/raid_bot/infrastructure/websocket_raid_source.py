from __future__ import annotations

import asyncio
import json
import os
import logging
import time
from contextlib import suppress
from dataclasses import replace
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from websockets.asyncio.client import connect

from ..domain.models import RaidEntry, RawRaidSnapshot


logger = logging.getLogger(__name__)
RECONNECT_DELAYS = (2, 5, 10, 30, 60)
DEFAULT_EVENT_CAPTURE_FILE = Path("./data/websocket_events.jsonl")
DEFAULT_EVENT_CAPTURE_MAX_BYTES = 10 * 1024 * 1024
DEFAULT_EVENT_CAPTURE_BACKUPS = 3
CAPTURE_WARNING_INTERVAL_SECONDS = 60.0


# RAID_WS_SHARED_AUTH_CLEANUP_20260927_V2
# Dynamic getid loader removed. Watcher is the sole auth owner.

# RAID_WS_SHARED_AUTH_20260927_V1
SHARED_TW_AUTH_FILE = Path(
    os.getenv(
        "RAID_WS_SHARED_TW_AUTH_FILE",
        "/run/unlight/tw_auth_session",
    )
)


async def _fresh_tw_auth_id() -> str:
    """
    Consume the TW auth session published by
    channel_watcher_unlight.

    IMPORTANT:
    Raid bot must never call 9998/getid itself.
    A new getid invalidates the session currently used by
    Channel / Ranking sockets.
    """

    deadline = time.monotonic() + 60.0

    last_error = None

    while time.monotonic() < deadline:
        try:
            auth_id = SHARED_TW_AUTH_FILE.read_text(
                encoding="utf-8"
            ).strip()

            if (
                len(auth_id) == 36
                and auth_id.count("-") == 4
            ):
                print(
                    "[WS SHARED AUTH READY] "
                    "watcher-owned session",
                    flush=True,
                )
                return auth_id

            last_error = RuntimeError(
                "shared auth file contains invalid UUID"
            )

        except Exception as e:
            last_error = e

        await asyncio.sleep(0.5)

    raise RuntimeError(
        "watcher shared TW auth unavailable: "
        f"{type(last_error).__name__}: "
        f"{last_error}"
    )


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


# RAID_DB_RAID_REWARD_BRIDGE_V1
def _db_raid_rows(payload: Any) -> list[dict[str, Any]]:
    """Return protocol 1.4 db_raid rows without mutating game state."""
    if not isinstance(payload, list):
        return []

    rows: Any = payload
    if len(payload) == 1 and isinstance(payload[0], list):
        rows = payload[0]

    if not isinstance(rows, list):
        return []

    return [row for row in rows if isinstance(row, dict)]


def _db_raid_match_keys(row: dict[str, Any]) -> tuple[str, ...]:
    """Known identifiers that may equal db_raid_support.profound_code."""
    keys: list[str] = []
    for field in ("code", "pass", "profound_code", "profound_id"):
        value = row.get(field)
        if isinstance(value, (str, int)) and not isinstance(value, bool):
            text = str(value).strip()
            if text and text not in keys:
                keys.append(text)
    return tuple(keys)


def _merge_db_raid_detail(
    raid: RaidEntry,
    row: dict[str, Any] | None,
) -> RaidEntry:
    if not isinstance(row, dict):
        return raid

    raw = dict(raid.raw) if isinstance(raid.raw, dict) else {}
    raw["db_raid"] = dict(row)

    reward = row.get("reward")
    raw["reward_items"] = (
        [dict(item) for item in reward if isinstance(item, dict)]
        if isinstance(reward, list)
        else []
    )

    return replace(raid, raw=raw)


def normalize_raid_support_list(
    payload: Any,
) -> tuple[RaidEntry, ...]:
    """
    RAID_DB_RAID_SUPPORT_V1

    Normalize both:

    legacy:
        raid_support_list

    protocol 1.4:
        db_raid_support
    """

    if not isinstance(payload, list):
        raise ValueError(
            "Raid support args must be a list"
        )

    rows: Any = payload

    if (
        len(payload) == 1
        and isinstance(payload[0], list)
    ):
        rows = payload[0]

    if not isinstance(rows, list):
        raise ValueError(
            "Raid support rows must be a list"
        )

    raids: list[RaidEntry] = []

    for row in rows:

        if not isinstance(row, dict):
            raise ValueError(
                "Raid support row must be an object"
            )


        # ============================================
        # Protocol 1.4 / db_raid_support
        # ============================================

        if (
            "profound_code" in row
            or
            "raid_name" in row
        ):

            raid_id = row.get(
                "profound_code"
            )

            if (
                not isinstance(raid_id, str)
                or
                not raid_id.strip()
            ):
                raise ValueError(
                    "profound_code is required"
                )

            try:
                hp = int(row["hp"])
                hp_max = int(row["hp_max"])

                members = int(
                    row["member_length"]
                )

                member_limit = int(
                    row["member_limit"]
                )

                expires_at = int(
                    row["limit"]
                )

            except (
                KeyError,
                TypeError,
                ValueError,
            ) as error:
                raise ValueError(
                    "invalid db_raid_support numeric field"
                ) from error


            if min(
                hp,
                hp_max,
                members,
                member_limit,
                expires_at,
            ) < 0:
                raise ValueError(
                    "db_raid_support numeric field "
                    "cannot be negative"
                )


            monster_id = row.get(
                "monster_id"
            )

            if monster_id is not None:
                try:
                    monster_id = int(
                        monster_id
                    )
                except (
                    TypeError,
                    ValueError,
                ):
                    monster_id = None


            raids.append(
                RaidEntry(
                    profound_id=raid_id.strip(),

                    monster_code=None,

                    monster_id=monster_id,

                    boss_name=_optional_string(
                        row.get("raid_name")
                    ),

                    founder=_optional_string(
                        row.get("founder_name")
                    ),

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

                    raw={"support": dict(row)},
                    source="websocket",
                )
            )

            continue


        # ============================================
        # Legacy / raid_support_list
        # ============================================

        raid_id = row.get(
            "prf_code"
        )

        if (
            not isinstance(raid_id, str)
            or
            not raid_id.strip()
        ):
            raise ValueError(
                "prf_code is required"
            )

        hp, hp_max = _parse_count_pair(
            row.get("prf_hp"),
            "prf_hp",
        )

        members, member_limit = (
            _parse_count_pair(
                row.get("prf_member"),
                "prf_member",
            )
        )

        try:
            expires_at = int(
                row["prf_limit"]
            )

        except (
            KeyError,
            TypeError,
            ValueError,
        ) as error:
            raise ValueError(
                "prf_limit must be an integer"
            ) from error


        raids.append(
            RaidEntry(
                profound_id=raid_id.strip(),

                monster_code=_optional_string(
                    row.get("prf_mons")
                ),

                monster_id=None,

                boss_name=_optional_string(
                    row.get("prf_name")
                ),

                founder=_optional_string(
                    row.get("prf_founder")
                ),

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

                raw={"support": dict(row)},
                source="websocket",
            )
        )

    return tuple(raids)


class WebSocketRaidSource:
    source_name = "websocket"
    push_driven = True

    def __init__(
        self,
        endpoint: str,
        owner_id: str,
        interval_seconds: float = 10.0,
        event_capture_file: Path | str = DEFAULT_EVENT_CAPTURE_FILE,
        event_capture_max_bytes: int = DEFAULT_EVENT_CAPTURE_MAX_BYTES,
        event_capture_backups: int = DEFAULT_EVENT_CAPTURE_BACKUPS,
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
        self._event_capture_file = Path(event_capture_file)
        self._event_capture_max_bytes = event_capture_max_bytes
        self._event_capture_backups = event_capture_backups
        self._last_capture_warning_at = 0.0
        # RAID_DB_RAID_REWARD_BRIDGE_V1
        # Read-only detail cache keyed by public/known Raid identifiers.
        self._db_raid_by_key: dict[str, dict[str, Any]] = {}

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
            # RAID_PROTOCOL14_HANDSHAKE_FIRST_V1
            #
            # Protocol 1.4:
            # application events sent before __connected are ignored.
            #
            # Correct flow:
            #   handshake_s
            #   -> handshake_c
            #   -> __connected
            #   -> register
            #   -> online
            #   -> db_raid_support
            #

            await self._wait_for_event(
                websocket,
                "__connected",
            )

            print(
                "[WS TRANSPORT READY]",
                flush=True,
            )

            # RAID_WS_SHARED_AUTH_20260927_V1
            #
            # Consume watcher-owned TW auth session.
            # Do NOT call 9998/getid from this process.
            dynamic_owner_id = (
                await _fresh_tw_auth_id()
            )

            print(
                "[WS DYNAMIC AUTH READY]",
                dynamic_owner_id[:6]
                + "..."
                + dynamic_owner_id[-4:],
                flush=True,
            )

            await self._emit(
                websocket,
                "register",
                [dynamic_owner_id],
            )

            register_args = await self._wait_for_event(
                websocket,
                "register",
            )

            if (
                not isinstance(register_args, list)
                or not register_args
                or register_args[0] != 0
            ):
                print(
                    "[WS REGISTER REJECTED]",
                    flush=True,
                )
                raise ValueError(
                    "Raid register rejected"
                )

            print(
                "[WS REGISTER OK]",
                flush=True,
            )

            await self._emit(
                websocket,
                "online",
                [],
            )

            await self._wait_for_event(
                websocket,
                "online",
            )

            print(
                "[WS ONLINE OK]",
                flush=True,
            )

            print(
                "[WS SESSION READY]",
                flush=True,
            )

            loop = asyncio.get_running_loop()
            next_request = loop.time()
            next_ping = loop.time() + 30.0
            while not self._closing:
                now = loop.time()
                if now >= next_request:
                    # RAID_DB_RAID_SUPPORT_V1
                    await self._emit(
                        websocket,
                        "db_raid_support",
                        [],
                    )
                    # RAID_DB_RAID_REWARD_BRIDGE_V1
                    # db_raid is read-only here. No raid_code_input/delete/start.
                    await self._emit(
                        websocket,
                        "db_raid",
                        [],
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

    async def _wait_for_event(
        self,
        websocket,
        target_event: str,
        timeout_seconds: float = 10.0,
    ) -> Any:
        """
        Wait for one protocol response while still processing
        handshake / ping / ACK traffic.
        """

        loop = asyncio.get_running_loop()
        deadline = loop.time() + timeout_seconds

        while True:

            remaining = (
                deadline
                -
                loop.time()
            )

            if remaining <= 0:
                raise TimeoutError(
                    f"timeout waiting for {target_event}"
                )

            message = await asyncio.wait_for(
                websocket.recv(),
                timeout=remaining,
            )

            try:
                data = json.loads(message)
            except (
                TypeError,
                ValueError,
                json.JSONDecodeError,
            ):
                await self.process_message(
                    message,
                    websocket,
                )
                continue

            await self.process_message(
                message,
                websocket,
            )

            if (
                isinstance(data, dict)
                and
                data.get("event") == target_event
            ):
                print(
                    "[WS FETCH OK]",
                    target_event,
                    flush=True,
                )

                return data.get("args")


    async def _emit(self, websocket, event: str, args: list[Any]) -> None:
        now = int(time.time() * 1000)
        if event in {"__pong_s", "__ping_c"}:
            args = [now]
        message_id = f"{event}:{self._message_index}:{now}"
        self._message_index += 1
        print("[WS SEND]", event, flush=True)
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

    def _capture_event(self, event: str, args: Any) -> None:
        """Append one inbound event without affecting message processing."""
        try:
            record = {
                "time": datetime.now(timezone.utc).isoformat(),
                "event": event,
                "args": args,
            }
            encoded = (
                json.dumps(record, ensure_ascii=False, separators=(",", ":"))
                + "\n"
            ).encode("utf-8")
            path = self._event_capture_file
            path.parent.mkdir(parents=True, exist_ok=True)
            if (
                path.exists()
                and path.stat().st_size + len(encoded)
                > self._event_capture_max_bytes
            ):
                self._rotate_event_capture()
            with path.open("ab") as stream:
                stream.write(encoded)
        except (OSError, TypeError, ValueError) as error:
            now = time.monotonic()
            if (
                now - self._last_capture_warning_at
                >= CAPTURE_WARNING_INTERVAL_SECONDS
            ):
                self._last_capture_warning_at = now
                logger.warning(
                    "Raid WebSocket event capture failed (%s); continuing",
                    type(error).__name__,
                )

    def _rotate_event_capture(self) -> None:
        path = self._event_capture_file
        if self._event_capture_backups <= 0:
            path.unlink(missing_ok=True)
            return

        oldest = Path(f"{path}.{self._event_capture_backups}")
        oldest.unlink(missing_ok=True)
        for index in range(self._event_capture_backups - 1, 0, -1):
            source = Path(f"{path}.{index}")
            if source.exists():
                source.replace(Path(f"{path}.{index + 1}"))
        if path.exists():
            path.replace(Path(f"{path}.1"))

    async def process_message(self, message: Any, websocket=None) -> bool:
        """Process one server message; malformed messages are isolated."""
        try:
            data = json.loads(message)
            if not isinstance(data, dict):
                raise ValueError("message must be an object")
            event = data.get("event")
            if not isinstance(event, str):
                raise ValueError("event is required")
            print("[WS EVENT]", event, flush=True)
            self._capture_event(event, data.get("args"))

            if event == "__ping_s":
                if websocket is None:
                    raise ValueError("websocket is required for pong")
                await self._emit(websocket, "__pong_s", [])
                return True
            if event == "__handshake_s":
                # RAID_WS_DYNAMIC_HANDSHAKE_VERSION_V1
                if websocket is None:
                    raise ValueError("websocket is required for handshake")

                args = data.get("args")

                if (
                    not isinstance(args, list)
                    or not args
                    or not isinstance(args[0], list)
                ):
                    raise ValueError(
                        "__handshake_s supported versions missing"
                    )

                versions = [
                    value.strip()
                    for value in args[0]
                    if isinstance(value, str)
                    and value.strip()
                ]

                if not versions:
                    raise ValueError(
                        "__handshake_s has no usable version"
                    )

                # Server 目前依序提供可接受版本；
                # 選最後一個，舊版為 1.1.0 / 1.1.1 / 1.1.2，
                # 2026-09-23 新版目前為 1.4。
                selected_version = versions[-1]

                print(
                    "[WS HANDSHAKE VERSION]",
                    selected_version,
                    flush=True,
                )

                await self._emit(
                    websocket,
                    "__handshake_c",
                    [selected_version, None],
                )

                return True
            if event.startswith("__"):
                return True

            meta = data.get("meta")
            if isinstance(meta, dict):
                message_id = meta.get("id")
                if isinstance(message_id, str) and message_id:
                    self._acks.append(message_id)

            # RAID_DB_RAID_REWARD_BRIDGE_V1
            if event == "db_raid":
                rows = _db_raid_rows(data.get("args"))
                by_key: dict[str, dict[str, Any]] = {}
                for row in rows:
                    for key in _db_raid_match_keys(row):
                        by_key[key] = row
                self._db_raid_by_key = by_key
                print(
                    "[WS DB RAID CACHE]",
                    f"rows={len(rows)} keys={len(by_key)}",
                    flush=True,
                )
                return True

            if event not in {
                "db_raid_support",
                "raid_support_list",
            }:
                return True

            raids = normalize_raid_support_list(data.get("args"))
            raids = tuple(
                _merge_db_raid_detail(
                    raid,
                    self._db_raid_by_key.get(raid.profound_id),
                )
                for raid in raids
            )
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
