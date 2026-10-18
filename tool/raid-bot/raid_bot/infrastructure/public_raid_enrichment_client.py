from __future__ import annotations

import asyncio
import json
import time
from dataclasses import dataclass
from typing import Any, Callable

from websockets.asyncio.client import connect


# RAID_ENRICHMENT_PROTOCOL14_V2
#
# UNLIGHT protocol 1.4:
# __handshake_s
# -> __handshake_c
# -> __connected
# -> register(player_id)
# -> online()
# -> raid_code_input(profound_code)
# -> db_raid()
# -> raid_delete(profound_id)
ALLOWED_OUTBOUND_EVENTS = frozenset({
    "__handshake_c",
    "__pong_s",
    "register",
    "online",
    "raid_code_input",
    "db_raid",
    "raid_delete",
})
JOIN_EVENTS = frozenset({
    "raid_code_input",
    "raid_code_error",
})
DEFAULT_DELETE_SETTLE_SECONDS = 0.25
CLIENT_VERSION = "1.4.1"


@dataclass(frozen=True)
class PublicRaidEnrichmentResult:
    status: str
    metadata: dict[str, Any] | None = None
    cleanup_lookup_attempted: bool = False
    cleanup_attempted: bool = False
    cleanup_confirmed: bool = False
    error: str | None = None

    @property
    def ok(self) -> bool:
        return (
            self.status == "success"
            and self.metadata is not None
            and self.cleanup_confirmed
        )


def _db_raid_rows(args: Any) -> list[dict[str, Any]]:
    if not isinstance(args, list):
        return []
    rows: Any = args
    if len(args) == 1 and isinstance(args[0], list):
        rows = args[0]
    if not isinstance(rows, list):
        return []
    return [row for row in rows if isinstance(row, dict)]


def find_unique_pass_match(
    args: Any,
    prf_code: str,
) -> dict[str, Any] | None:
    matches = [
        row
        for row in _db_raid_rows(args)
        if row.get("pass") == prf_code
        and isinstance(row.get("profound_id"), (str, int))
        and not isinstance(row.get("profound_id"), bool)
        and str(row["profound_id"]).strip()
    ]
    return dict(matches[0]) if len(matches) == 1 else None


def profound_id_is_absent(args: Any, profound_id: str) -> bool:
    rows = _db_raid_rows(args)
    return all(str(row.get("profound_id")) != profound_id for row in rows)


# RAID_CODE_ERROR_CAPTURE_V1
def _raid_code_error_code(
    value: Any,
) -> int | None:

    if value is None:
        return None

    if isinstance(value, bool):
        return None

    if isinstance(value, int):
        return value

    if isinstance(value, float):
        if value.is_integer():
            return int(value)
        return None

    if isinstance(value, str):
        value = value.strip()

        if not value:
            return None

        try:
            return int(value)
        except ValueError:
            return None

    if isinstance(
        value,
        (list, tuple),
    ):

        for item in value:

            result = (
                _raid_code_error_code(
                    item
                )
            )

            if result is not None:
                return result

        return None

    if isinstance(value, dict):

        for key in (
            "code",
            "error_code",
            "errorCode",
            "value",
        ):

            if key not in value:
                continue

            result = (
                _raid_code_error_code(
                    value[key]
                )
            )

            if result is not None:
                return result

    return None



# RAID_ENRICHMENT_SCHEMA14_V3
def _valid_profound_id(
    row: dict[str, Any],
) -> str | None:

    value = row.get("profound_id")

    if (
        value is None
        or isinstance(value, bool)
    ):
        return None

    text = str(value).strip()

    return text or None


def _profound_ids(
    args: Any,
) -> set[str]:

    result: set[str] = set()

    for row in _db_raid_rows(args):

        profound_id = _valid_profound_id(
            row
        )

        if profound_id is not None:
            result.add(profound_id)

    return result


def find_unique_new_row(
    before_ids: set[str],
    after_args: Any,
) -> dict[str, Any] | None:

    candidates = []

    for row in _db_raid_rows(after_args):

        profound_id = _valid_profound_id(
            row
        )

        if (
            profound_id is not None
            and profound_id not in before_ids
        ):
            candidates.append(row)

    if len(candidates) != 1:
        return None

    return dict(candidates[0])

class PublicRaidEnrichmentClient:
    def __init__(
        self,
        endpoint: str,
        worker_id: str,
        *,
        timeout_seconds: float = 10.0,
        register_settle_seconds: float = 1.0,
        delete_settle_seconds: float = DEFAULT_DELETE_SETTLE_SECONDS,
        connect_factory: Callable[..., Any] = connect,
        clock_ms: Callable[[], int] | None = None,
    ) -> None:
        if not endpoint.startswith("wss://"):
            raise ValueError("Raid enrichment endpoint must use wss://")
        if not worker_id.strip():
            raise ValueError("Raid enrichment worker ID is required")
        if timeout_seconds <= 0:
            raise ValueError("Raid enrichment timeout must be positive")
        if register_settle_seconds < 0 or delete_settle_seconds < 0:
            raise ValueError("Raid enrichment settle delays cannot be negative")
        self.endpoint = endpoint
        self._worker_id = worker_id.strip()
        self.timeout_seconds = timeout_seconds
        self.register_settle_seconds = register_settle_seconds
        self.delete_settle_seconds = delete_settle_seconds
        self._connect_factory = connect_factory
        self._clock_ms = clock_ms or (lambda: int(time.time() * 1000))
        self._message_index = 0
        self._acks: list[str] = []

    def _build_message(self, event: str, args: list[Any]) -> str:
        if event not in ALLOWED_OUTBOUND_EVENTS:
            raise ValueError(f"outbound event is forbidden: {event}")
        now = self._clock_ms()
        if event == "__pong_s":
            args = [now]
        message = {
            "meta": {
                "id": f"{event}:{self._message_index}:{now}",
                "ACKs": self._acks,
            },
            "event": event,
            "args": args,
        }
        self._message_index += 1
        self._acks = []
        return json.dumps(message, ensure_ascii=False)

    async def _send(self, websocket, event: str, args: list[Any]) -> None:
        await websocket.send(self._build_message(event, args))

    async def _handle_control(self, websocket, event: str) -> bool:
        if event == "__ping_s":
            await self._send(websocket, "__pong_s", [])
            return True

        if event == "__handshake_s":
            await self._send(
                websocket,
                "__handshake_c",
                [CLIENT_VERSION, None],
            )
            return True

        # Protocol 1.4 requires the caller to observe
        # __connected before sending application events.
        if event == "__connected":
            return False

        return event.startswith("__")

    async def _wait_for(self, websocket, expected: frozenset[str]):
        loop = asyncio.get_running_loop()
        deadline = loop.time() + self.timeout_seconds
        while True:
            remaining = deadline - loop.time()
            if remaining <= 0:
                raise TimeoutError
            message = await asyncio.wait_for(websocket.recv(), remaining)
            try:
                payload = json.loads(message)
            except (TypeError, json.JSONDecodeError):
                continue
            if not isinstance(payload, dict):
                continue
            event = payload.get("event")
            if not isinstance(event, str):
                continue
            if await self._handle_control(websocket, event):
                continue
            meta = payload.get("meta")
            if isinstance(meta, dict):
                message_id = meta.get("id")
                if isinstance(message_id, str) and message_id:
                    self._acks.append(message_id)
            if event in expected:
                return event, payload.get("args")

    async def _request_db_raid(self, websocket) -> Any:
        await self._send(
            websocket,
            "db_raid",
            [],
        )
        _, args = await self._wait_for(
            websocket,
            frozenset({"db_raid"}),
        )
        return args

    # RAID_CLEANUP_FRESH_VERIFY_V1
    async def verify_profound_id_absent(
        self,
        profound_id: str,
    ) -> bool:
        """Read-only cleanup verification on a fresh Protocol 1.4 session.

        This method NEVER joins or deletes a Raid.
        It only performs:
            handshake
            -> register
            -> online
            -> db_raid
        """

        profound_id = str(
            profound_id or ""
        ).strip()

        if not profound_id:
            return False

        # New socket = new ACK/message state.
        self._acks = []

        async with self._connect_factory(
            self.endpoint,
            ping_interval=None,
            max_size=2 * 1024 * 1024,
        ) as websocket:

            # Protocol 1.4 handshake.
            await self._wait_for(
                websocket,
                frozenset({"__connected"}),
            )

            # Register worker.
            await self._send(
                websocket,
                "register",
                [self._worker_id],
            )

            _, register_args = await self._wait_for(
                websocket,
                frozenset({"register"}),
            )

            register_ok = (
                isinstance(register_args, list)
                and len(register_args) >= 1
                and register_args[0] == 0
            )

            if not register_ok:
                return False

            # Establish online session.
            await self._send(
                websocket,
                "online",
                [],
            )

            await self._wait_for(
                websocket,
                frozenset({"online"}),
            )

            # Read only. Never raid_code_input / raid_delete here.
            verify_args = await self._request_db_raid(
                websocket
            )

            absent = profound_id_is_absent(
                verify_args,
                profound_id,
            )

            rows = _db_raid_rows(
                verify_args
            )

            ids = [
                str(row.get("profound_id"))
                for row in rows
                if row.get("profound_id") is not None
            ]

            print(
                "[RAID CLEANUP FRESH VERIFY] "
                f"profound_id={profound_id} "
                f"confirmed={absent} "
                f"rows={len(rows)} "
                f"ids={ids}"
            )

            return absent


    async def enrich(self, prf_code: str) -> PublicRaidEnrichmentResult:
        if not prf_code.strip():
            return PublicRaidEnrichmentResult(status="invalid_prf_code")

        join_attempted = False
        register_rejected = False
        join_rejected = False
        join_timed_out = False
        join_error_code: int | None = None
        matched_row: dict[str, Any] | None = None

        # Protocol 1.4 no longer exposes pass.
        # Identify our joined Raid by comparing
        # db_raid profound_id BEFORE vs AFTER.
        before_ids: set[str] = set()
        cleanup_attempted = False
        cleanup_confirmed = False
        operation_error: str | None = None
        cancellation: asyncio.CancelledError | None = None
        cleanup_lookup_attempted = False
        self._acks = []

        try:
            async with self._connect_factory(
                self.endpoint,
                ping_interval=None,
                max_size=2 * 1024 * 1024,
            ) as websocket:
                try:
                    # 1. Complete protocol handshake first.
                    await self._wait_for(
                        websocket,
                        frozenset({"__connected"}),
                    )

                    # 2. Register current player UUID.
                    await self._send(
                        websocket,
                        "register",
                        [self._worker_id],
                    )

                    _, register_args = await self._wait_for(
                        websocket,
                        frozenset({"register"}),
                    )

                    register_ok = (
                        isinstance(register_args, list)
                        and len(register_args) >= 1
                        and register_args[0] == 0
                    )

                    if not register_ok:
                        register_rejected = True
                        operation_error = "register_rejected"

                    # 3. Current protocol performs online()
                    # after successful registration.
                    if not register_rejected:
                        await self._send(
                            websocket,
                            "online",
                            [],
                        )

                        await self._wait_for(
                            websocket,
                            frozenset({"online"}),
                        )

                        # V3: capture account Raid IDs BEFORE join.
                        #
                        # If this request fails, the exception leaves
                        # this block before raid_code_input is sent,
                        # so we never perform an untrackable join.
                        before_args = (
                            await self._request_db_raid(
                                websocket
                            )
                        )

                        before_ids = _profound_ids(
                            before_args
                        )

                        # 4. Current raid_code_input accepts
                        # only the public profound code.
                        await self._send(
                            websocket,
                            "raid_code_input",
                            [prf_code],
                        )

                        join_attempted = True

                        try:
                            event, join_args = await self._wait_for(
                                websocket,
                                JOIN_EVENTS,
                            )

                            if event == "raid_code_error":
                                join_rejected = True
                                join_error_code = (
                                    _raid_code_error_code(
                                        join_args
                                    )
                                )

                            elif event == "raid_code_input":
                                join_ok = (
                                    isinstance(join_args, list)
                                    and len(join_args) >= 1
                                    and join_args[0] is True
                                )

                                if not join_ok:
                                    join_rejected = True
                                    operation_error = (
                                        "raid_code_input_rejected"
                                    )

                        except (
                            TimeoutError,
                            asyncio.TimeoutError,
                        ):
                            # The server can accept the join even
                            # when the join reply is missed.
                            # finally() will still inspect db_raid
                            # and clean up if a matching row exists.
                            join_timed_out = True

                    if (
                        not register_rejected
                        and not join_rejected
                    ):
                        try:
                            args = await self._request_db_raid(
                                websocket
                            )
                            matched_row = find_unique_new_row(before_ids, args)
                        except (
                            TimeoutError,
                            asyncio.TimeoutError,
                        ):
                            operation_error = "db_raid_timeout"
                except asyncio.CancelledError as error:
                    cancellation = error
                except Exception as error:
                    operation_error = type(error).__name__
                finally:
                    # Once join was sent, cleanup takes priority over enrichment.
                    if join_attempted and not join_rejected:
                        cleanup_lookup_attempted = True
                        if matched_row is None:
                            try:
                                args = await self._request_db_raid(websocket)
                                matched_row = find_unique_new_row(before_ids, args)
                            except (TimeoutError, asyncio.TimeoutError):
                                operation_error = (
                                    operation_error or "cleanup_lookup_timeout"
                                )
                            except Exception as error:
                                operation_error = type(error).__name__

                        if matched_row is not None:
                            profound_id = str(
                                matched_row["profound_id"]
                            ).strip()
                            cleanup_attempted = True
                            try:
                                await self._send(
                                    websocket,
                                    "raid_delete",
                                    [profound_id],
                                )

                                # RAID_CLEANUP_DIAGNOSTIC_V1
                                delete_args = None

                                try:
                                    _, delete_args = await self._wait_for(
                                        websocket,
                                        frozenset({"raid_delete"}),
                                    )

                                    print(
                                        "[RAID CLEANUP DELETE]",
                                        f"profound_id={profound_id}",
                                        f"args={delete_args!r}",
                                        flush=True,
                                    )

                                except (
                                    TimeoutError,
                                    asyncio.TimeoutError,
                                ):
                                    print(
                                        "[RAID CLEANUP DELETE]",
                                        f"profound_id={profound_id}",
                                        "reply=TIMEOUT",
                                        flush=True,
                                    )

                                if self.delete_settle_seconds:
                                    await asyncio.sleep(
                                        self.delete_settle_seconds
                                    )
                                # RAID_CLEANUP_VERIFY_RETRY_V1
                                #
                                # Protocol 1.4 may return a stale db_raid row
                                # briefly after raid_delete has already been
                                # accepted. Keep fail-closed semantics, but
                                # confirm absence with bounded polling instead
                                # of trusting one immediate read.
                                cleanup_confirmed = False

                                for verify_attempt in range(1, 6):
                                    verify_args = await self._request_db_raid(
                                        websocket
                                    )

                                    cleanup_confirmed = profound_id_is_absent(
                                        verify_args,
                                        profound_id,
                                    )

                                    verify_rows = _db_raid_rows(
                                        verify_args
                                    )

                                    present_ids = [
                                        _valid_profound_id(row)
                                        for row in verify_rows
                                    ]

                                    present_ids = [
                                        value
                                        for value in present_ids
                                        if value is not None
                                    ]

                                    print(
                                        "[RAID CLEANUP VERIFY]",
                                        f"profound_id={profound_id}",
                                        f"attempt={verify_attempt}",
                                        f"confirmed={cleanup_confirmed}",
                                        f"rows={len(verify_rows)}",
                                        f"ids={present_ids!r}",
                                        flush=True,
                                    )

                                    if cleanup_confirmed:
                                        break

                                    if verify_attempt < 5:
                                        await asyncio.sleep(1.0)
                            except (TimeoutError, asyncio.TimeoutError):
                                operation_error = "cleanup_verification_timeout"
                            except Exception as error:
                                operation_error = type(error).__name__
        except asyncio.CancelledError:
            raise
        except Exception as error:
            operation_error = type(error).__name__

        if cancellation is not None:
            raise cancellation

        if register_rejected:
            status = "register_rejected"
        elif join_rejected:
            status = "join_rejected"
        elif matched_row is None:
            status = "join_timeout" if join_timed_out else "metadata_missing"
        elif not cleanup_confirmed:
            status = "cleanup_failed"
        else:
            # Protocol 1.4 no longer exposes treasure_level.
            # A uniquely identified row plus confirmed cleanup
            # is sufficient for successful enrichment.
            status = "success"
        return PublicRaidEnrichmentResult(
            status=status,
            metadata=matched_row,
            cleanup_lookup_attempted=cleanup_lookup_attempted,
            cleanup_attempted=cleanup_attempted,
            cleanup_confirmed=cleanup_confirmed,
            error=(
                (
                    "raid_code_error:"
                    + str(join_error_code)
                )
                if (
                    join_rejected
                    and join_error_code
                    is not None
                )
                else operation_error
            ),
        )
