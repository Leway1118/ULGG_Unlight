from __future__ import annotations

import asyncio
import json
import logging
import os
import time
from pathlib import Path
from urllib.request import urlopen

from raid_bot.config import (
    RAID_ENRICHMENT_PROTOCOL_TIMEOUT,
    RAID_WS_ENDPOINT,
)
from raid_bot.infrastructure.raid_history_repository import (
    RaidHistoryRepository,
)

from raid_bot.infrastructure.public_raid_enrichment_client import (
    PublicRaidEnrichmentClient,
)


# RAID_PLAYER_COLLECTOR_V1

# RAID_PLAYER_SHARED_AUTH_20260927_V1
SHARED_TW_AUTH_FILE = Path(
    os.getenv(
        "RAID_PLAYER_SHARED_TW_AUTH_FILE",
        "/run/unlight/tw_auth_session",
    )
)

CACHE_PATH = Path(
    os.getenv(
        "RAID_PLAYER_CACHE_FILE",
        "./data/raid_player_cache.json",
    )
)

OBSERVED_URL = os.getenv(
    "RAID_PLAYER_OBSERVED_URL",
    "http://127.0.0.1:8766/api/observed-raids",
)

REFRESH_SECONDS = float(
    os.getenv(
        "RAID_PLAYER_COLLECTOR_REFRESH_SECONDS",
        "30",
    )
)

SPACING_SECONDS = float(
    os.getenv(
        "RAID_PLAYER_COLLECTOR_SPACING_SECONDS",
        "2",
    )
)

CACHE_TTL_SECONDS = float(
    os.getenv(
        "RAID_PLAYER_COLLECTOR_CACHE_TTL_SECONDS",
        "180",
    )
)

# RAID_PLAYER_DELETE_SETTLE_WIRING_V2
DELETE_SETTLE_SECONDS = float(
    os.getenv(
        "RAID_PLAYER_COLLECTOR_DELETE_SETTLE_SECONDS",
        "1.5",
    )
)

if DELETE_SETTLE_SECONDS < 0:
    raise ValueError(
        "RAID_PLAYER_COLLECTOR_DELETE_SETTLE_SECONDS "
        "cannot be negative"
    )


POLL_SECONDS = 2.0


logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)

logger = logging.getLogger(
    "raid-player-collector"
)


class SharedAuthProvider:
    """Read TW auth owned and published by unlight_watcher."""

    def __init__(self, host_code="TW"):
        if host_code != "TW":
            raise RuntimeError(
                "SharedAuthProvider supports TW only"
            )

    async def get(self) -> str:
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


def _auth_provider_class():
    # Compatibility shim for existing collector call sites.
    # It must never load AuthSessionProvider or call 9998/getid.
    return SharedAuthProvider


def _load_cache():
    if not CACHE_PATH.exists():
        return {
            "version": 1,
            "ttl_seconds": CACHE_TTL_SECONDS,
            "updated_at": 0.0,
            "raids": {},
        }

    try:
        data = json.loads(
            CACHE_PATH.read_text(
                encoding="utf-8",
            )
        )
    except Exception:
        logger.exception(
            "cache read failed"
        )
        data = {}

    if not isinstance(data, dict):
        data = {}

    raids = data.get("raids")

    if not isinstance(raids, dict):
        raids = {}

    return {
        "version": 1,
        "ttl_seconds": CACHE_TTL_SECONDS,
        "updated_at": float(
            data.get("updated_at") or 0.0
        ),
        "raids": raids,
    }


def _save_cache(cache):
    CACHE_PATH.parent.mkdir(
        parents=True,
        exist_ok=True,
    )

    cache["version"] = 1
    cache["ttl_seconds"] = (
        CACHE_TTL_SECONDS
    )
    cache["updated_at"] = time.time()

    tmp = CACHE_PATH.with_suffix(
        CACHE_PATH.suffix + ".tmp"
    )

    tmp.write_text(
        json.dumps(
            cache,
            ensure_ascii=False,
            separators=(",", ":"),
        ),
        encoding="utf-8",
    )

    os.replace(
        tmp,
        CACHE_PATH,
    )


def _fetch_active_raids():
    with urlopen(
        OBSERVED_URL,
        timeout=5,
    ) as response:
        payload = json.load(response)

    raids = payload.get("raids")

    if not isinstance(raids, list):
        return []

    result = []

    for raid in raids:
        if not isinstance(raid, dict):
            continue

        if raid.get("status") != "active":
            continue

        code = raid.get("raid_id")

        if (
            not isinstance(code, str)
            or not code.strip()
        ):
            continue

        result.append(
            {
                "code": code.strip(),
                "members": raid.get(
                    "participant_count"
                ),
            }
        )

    return result


def _optional_int(value):
    if (
        value is None
        or isinstance(value, bool)
    ):
        return None

    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _parse_rank(value):
    if not isinstance(value, list):
        return None

    players = []

    for index, item in enumerate(
        value,
        start=1,
    ):
        if not isinstance(item, dict):
            continue

        name = item.get(
            "player_name"
        )

        if not isinstance(name, str):
            continue

        name = name.strip()

        if not name:
            continue

        # RAID_PLAYER_STRIP_LEVEL_PREFIX_V1
        level = _optional_int(
            item.get("level")
        )

        if level is not None:
            prefix = f"Lv.{level}"

            if name.startswith(prefix):
                name = name[
                    len(prefix):
                ].lstrip()

        players.append(
            {
                "rank": index,
                "level": level,
                "name": name,
                "point": (
                    _optional_int(
                        item.get("point")
                    )
                    or 0
                ),
            }
        )

    return players


async def _get_result(
    code,
    provider,
):
    for attempt in range(2):

        if provider is None:
            provider = (
                _auth_provider_class()(
                    "TW"
                )
            )

        auth_id = await provider.get()

        client = (
            PublicRaidEnrichmentClient(
                endpoint=RAID_WS_ENDPOINT,
                worker_id=auth_id,
                timeout_seconds=(
                    RAID_ENRICHMENT_PROTOCOL_TIMEOUT
                ),
                delete_settle_seconds=(
                    DELETE_SETTLE_SECONDS
                ),
            )
        )

        result = await client.enrich(
            code
        )

        if (
            result.status
            == "register_rejected"
            and attempt == 0
        ):
            logger.warning(
                "register rejected; "
                "reload shared auth "
                "code=%s",
                code,
            )

            provider = None
            continue

        return provider, result

    return provider, None


async def main():
    logger.info(
        "RAID_PLAYER_COLLECTOR_V1 start"
    )

    logger.info(
        "endpoint=%s refresh=%.1fs "
        "spacing=%.1fs ttl=%.1fs "
        "delete_settle=%.2fs",
        RAID_WS_ENDPOINT,
        REFRESH_SECONDS,
        SPACING_SECONDS,
        CACHE_TTL_SECONDS,
        DELETE_SETTLE_SECONDS,
    )

    cache = _load_cache()

    history_repository = None

    try:
        history_repository = (
            RaidHistoryRepository.from_environment()
        )
        history_repository.check_connection()

        logger.info(
            "Raid history research writer enabled"
        )

    except Exception:
        logger.exception(
            "Raid history research writer unavailable"
        )
        history_repository = None

    last_attempt = {}

    provider = None

    blocked = False

    # RAID_PLAYER_BLOCK_AUTO_RECOVERY_V1
    blocked_code = None
    blocked_profound_id = None

    while True:

        if blocked:
            logger.error(
                "collector BLOCKED; "
                "fresh cleanup verification "
                "code=%s profound_id=%s",
                blocked_code,
                blocked_profound_id,
            )

            if not blocked_profound_id:
                logger.error(
                    "blocked profound_id missing; "
                    "manual restart required"
                )
                await asyncio.sleep(60)
                continue

            try:
                fresh_provider = (
                    _auth_provider_class()("TW")
                )

                fresh_auth_id = (
                    await fresh_provider.get()
                )

                fresh_client = (
                    PublicRaidEnrichmentClient(
                        endpoint=RAID_WS_ENDPOINT,
                        worker_id=fresh_auth_id,
                        timeout_seconds=(
                            RAID_ENRICHMENT_PROTOCOL_TIMEOUT
                        ),
                    )
                )

                recovered = (
                    await fresh_client
                    .verify_profound_id_absent(
                        blocked_profound_id
                    )
                )

                logger.info(
                    "blocked cleanup recheck "
                    "code=%s profound_id=%s "
                    "confirmed=%s",
                    blocked_code,
                    blocked_profound_id,
                    recovered,
                )

                if recovered:
                    logger.warning(
                        "collector AUTO UNBLOCK "
                        "code=%s profound_id=%s",
                        blocked_code,
                        blocked_profound_id,
                    )

                    provider = fresh_provider
                    blocked = False
                    blocked_code = None
                    blocked_profound_id = None

                    # Resume immediately.
                    continue

            except Exception as error:
                provider = None
                logger.warning(
                    "blocked cleanup recheck failed "
                    "code=%s error=%s",
                    blocked_code,
                    type(error).__name__,
                )

            await asyncio.sleep(60)
            continue

        try:
            active = (
                _fetch_active_raids()
            )
        except Exception:
            logger.exception(
                "observed-raids fetch failed"
            )

            await asyncio.sleep(
                POLL_SECONDS
            )
            continue

        now = time.time()

        active_codes = {
            item["code"]
            for item in active
        }

        raids_cache = cache["raids"]

        # Keep ended Raid ranking long enough
        # for the observer's recent-ended UI.
        for code in list(
            raids_cache.keys()
        ):
            item = raids_cache.get(
                code
            )

            if not isinstance(item, dict):
                raids_cache.pop(
                    code,
                    None,
                )
                continue

            updated_at = float(
                item.get(
                    "updated_at"
                )
                or 0.0
            )

            if (
                code not in active_codes
                and now - updated_at
                > CACHE_TTL_SECONDS
            ):
                raids_cache.pop(
                    code,
                    None,
                )

        due = []

        for item in active:
            code = item["code"]

            previous = float(
                last_attempt.get(
                    code,
                    0.0,
                )
            )

            if (
                now - previous
                >= REFRESH_SECONDS
            ):
                due.append(item)

        for item in due:
            code = item["code"]

            last_attempt[code] = (
                time.time()
            )

            try:
                provider, result = (
                    await _get_result(
                        code,
                        provider,
                    )
                )
            except Exception:
                logger.exception(
                    "collect exception "
                    "code=%s",
                    code,
                )

                provider = None

                await asyncio.sleep(
                    SPACING_SECONDS
                )
                continue

            if result is None:
                logger.warning(
                    "no enrichment result "
                    "code=%s",
                    code,
                )

                await asyncio.sleep(
                    SPACING_SECONDS
                )
                continue

            # RAID_PLAYER_FRESH_CLEANUP_INLINE_V1
            cleanup_confirmed = bool(
                getattr(
                    result,
                    "cleanup_confirmed",
                    False,
                )
            )

            if (
                getattr(
                    result,
                    "cleanup_attempted",
                    False,
                )
                and not cleanup_confirmed
            ):
                logger.warning(
                    "CLEANUP NOT CONFIRMED "
                    "code=%s status=%s error=%r; "
                    "trying fresh verification",
                    code,
                    result.status,
                    getattr(result, "error", None),
                )

                row = (
                    result.metadata
                    if isinstance(result.metadata, dict)
                    else {}
                )

                profound_id = str(
                    row.get("profound_id") or ""
                ).strip()

                if profound_id:
                    try:
                        fresh_provider = (
                            _auth_provider_class()("TW")
                        )

                        fresh_auth_id = (
                            await fresh_provider.get()
                        )

                        fresh_client = (
                            PublicRaidEnrichmentClient(
                                endpoint=RAID_WS_ENDPOINT,
                                worker_id=fresh_auth_id,
                                timeout_seconds=(
                                    RAID_ENRICHMENT_PROTOCOL_TIMEOUT
                                ),
                            )
                        )

                        cleanup_confirmed = (
                            await fresh_client
                            .verify_profound_id_absent(
                                profound_id
                            )
                        )

                        logger.info(
                            "fresh cleanup verify "
                            "code=%s profound_id=%s "
                            "confirmed=%s",
                            code,
                            profound_id,
                            cleanup_confirmed,
                        )

                        if cleanup_confirmed:
                            provider = fresh_provider

                    except Exception as error:
                        provider = None

                        logger.warning(
                            "fresh cleanup verify failed "
                            "code=%s error=%s",
                            code,
                            type(error).__name__,
                        )

                if not cleanup_confirmed:
                    logger.error(
                        "CLEANUP STILL NOT CONFIRMED "
                        "code=%s; collector fail-closed",
                        code,
                    )

                    blocked_code = code
                    blocked_profound_id = profound_id
                    blocked = True
                    break

                logger.info(
                    "cleanup recovered by fresh "
                    "verification code=%s",
                    code,
                )

            if (
                result.status != "success"
                and not (
                    result.status == "cleanup_failed"
                    and cleanup_confirmed
                )
            ):
                logger.warning(
                    "collect failed "
                    "code=%s status=%s "
                    "error=%s",
                    code,
                    result.status,
                    getattr(
                        result,
                        "error",
                        None,
                    ),
                )

                await asyncio.sleep(
                    SPACING_SECONDS
                )
                continue

            row = (
                result.metadata
                if isinstance(
                    result.metadata,
                    dict,
                )
                else {}
            )

            players = _parse_rank(
                row.get("rank")
            )

            if players is None:
                logger.warning(
                    "rank[] missing "
                    "code=%s keys=%s",
                    code,
                    sorted(
                        row.keys()
                    ),
                )

                await asyncio.sleep(
                    SPACING_SECONDS
                )
                continue

            # RAID_PLAYER_METADATA_CACHE_V1
            #
            # result.metadata is the unique db_raid row already
            # obtained by this same collector join.
            #
            # Keep only fields needed by the live Raid model.
            # Do NOT persist pass/auth/session data here.
            metadata = {
                key: row.get(key)
                for key in (
                    "profound_mons",

                    # RAID_PLAYER_MONSTER_LEVEL_V1
                    "monster_id",

                    "name_tcn",
                    "profound_founder",
                    "rarity",
                    "level",
                    "map",
                    "stage",
                    "map_index",
                    "pos_index",
                    "treasure_level",
                    "state",
                    "reward",
                )
                if key in row
            }

            # PLAYER_CACHE_PROFOUND_ID_20260925_V3
            profound_id = row.get("profound_id")

            raids_cache[code] = {
                "updated_at": time.time(),
                "profound_id": (
                    profound_id
                    if isinstance(profound_id, str)
                    and profound_id
                    else None
                ),
                "players": players,
                # RAID_PLAYER_RESEARCH_METADATA_V1
                "metadata": metadata,
            }

            _save_cache(
                cache
            )

            if history_repository is not None:
                try:
                    # RAID_PLAYER_MONSTER_LEVEL_V1
                    identity_changed = await asyncio.to_thread(
                        history_repository.update_protocol14_identity,
                        raid_id=code,
                        metadata=metadata,
                    )

                    # RAID_PLAYER_COORD_PROVENANCE_V1
                    #
                    # Compare direct db_raid coordinates BEFORE the
                    # legacy generic writer can overwrite an inferred
                    # value. Direct observation wins, but contradiction
                    # remains recorded as *_conflict=1.
                    coordinate_outcome = await asyncio.to_thread(
                        history_repository.update_protocol14_coordinates,
                        raid_id=code,
                        metadata=metadata,
                    )

                    # stage/map_index are now owned by the provenance
                    # writer. Keep the legacy writer for map_id,
                    # pos_index and other research metadata.
                    research_metadata = dict(metadata)
                    research_metadata["stage"] = None
                    research_metadata["map_index"] = None

                    # RAID_PROTOCOL14_REWARD_CAPTURE_COLLECTOR_V1
                    reward_result = await asyncio.to_thread(
                        history_repository.update_protocol14_reward_observation,
                        raid_id=code,
                        reward=row.get("reward"),
                        rarity=row.get("rarity"),
                    )

                    logger.info(
                        "protocol14 reward persisted "
                        "code=%s rarity=%s "
                        "reward_items=%s updated=%s",
                        code,
                        reward_result.get("rarity"),
                        (
                            len(row.get("reward"))
                            if isinstance(row.get("reward"), list)
                            else None
                        ),
                        reward_result.get("updated"),
                    )

                    changed = await asyncio.to_thread(
                        history_repository.update_research_metadata,
                        raid_id=code,
                        metadata=research_metadata,
                    )

                    derived_outcome = await asyncio.to_thread(
                        history_repository.reconcile_protocol14_derived_metadata,
                        raid_id=code,
                    )

                    # RAID_PROTOCOL14_MAP_STAGE_FRAGMENT_COLLECTOR_V1
                    fragment_outcome = await asyncio.to_thread(
                        history_repository.reconcile_protocol14_fragment_from_map_stage,
                        raid_id=code,
                    )

                    logger.info(
                        "protocol14 provenance persisted "
                        "code=%s coordinates=%s "
                        "derived=%s fragment=%s",
                        code,
                        coordinate_outcome,
                        derived_outcome,
                        fragment_outcome,
                    )

                    logger.info(
                        "research metadata persisted "
                        "code=%s monster_id=%s level=%s "
                        "map=%s stage=%s "
                        "map_index=%s pos_index=%s "
                        "identity_changed=%s changed=%s",
                        code,
                        metadata.get("monster_id"),
                        metadata.get("level"),
                        metadata.get("map"),
                        metadata.get("stage"),
                        metadata.get("map_index"),
                        metadata.get("pos_index"),
                        identity_changed,
                        changed,
                    )

                except Exception:
                    logger.exception(
                        "research metadata persist failed "
                        "code=%s",
                        code,
                    )

            # RAID_PLAYER_HISTORY_PROTOCOL14_COLLECTOR_V1
            if history_repository is not None:
                try:
                    player_history_result = await asyncio.to_thread(
                        history_repository.upsert_protocol14_rank,
                        raid_id=code,
                        players=players,
                        source="protocol14_db_raid_rank",
                    )

                    # RAID_PROTOCOL14_POINT_DAMAGE_COLLECTOR_V1
                    damage_result = await asyncio.to_thread(
                        history_repository.update_protocol14_point_damage,
                        raid_id=code,
                    )

                    logger.info(
                        "protocol14 point damage synced "
                        "code=%s updated=%s source=%s",
                        code,
                        damage_result.get("updated"),
                        damage_result.get("source"),
                    )

                    logger.info(
                        "player history persisted "
                        "code=%s players=%s "
                        "rowcount=%s reason=%s",
                        code,
                        player_history_result.get("players"),
                        player_history_result.get("rowcount"),
                        player_history_result.get("reason"),
                    )

                except Exception:
                    logger.exception(
                        "player history persist failed "
                        "code=%s",
                        code,
                    )

            logger.info(
                "rank cached "
                "code=%s players=%d "
                "public_members=%s "
                "cleanup=%s",
                code,
                len(players),
                item.get(
                    "members"
                ),
                getattr(
                    result,
                    "cleanup_confirmed",
                    False,
                ),
            )

            await asyncio.sleep(
                SPACING_SECONDS
            )

        _save_cache(
            cache
        )

        await asyncio.sleep(
            POLL_SECONDS
        )


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass
