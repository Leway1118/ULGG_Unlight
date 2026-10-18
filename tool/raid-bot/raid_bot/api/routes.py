from __future__ import annotations

import asyncio
import hashlib
import hmac
import json
import os
import time
from urllib.parse import unquote

from datetime import UTC, datetime
from pathlib import Path

from fastapi import APIRouter, HTTPException, Request

from ..domain.models import RaidEntry
from ..services.treasure_level_enricher import (
    apply_metadata,
)
from ..domain.reward_rules import (
    predict_fragment,
)

from ..domain.raid_rules import (
    map_name,
    star_text,
    detector_tier,
)
from ..formatter.raid_text import (
    format_raid_list,
)
from ..formatter.status_text import format_status_labels

from ..notifications.discord import (
    DiscordWebhook,
)

from ..notifications.notifier import (
    RaidNotifier,
)
from ..config import (
    DISCORD_WEBHOOK_URL,
    RAID_STAGE_API_SECRET,
    RAID_STAGE_AUTH_WINDOW_SECONDS,
    RAID_STAGE_MATCH_SECRET,
)


router = APIRouter()


# RAID_COMMUNITY_FRAGMENT_API_V1
_COMMUNITY_FRAGMENT_RATE: dict[str, list[float]] = {}
_COMMUNITY_FRAGMENT_RATE_WINDOW = 60.0
_COMMUNITY_FRAGMENT_RATE_LIMIT = 30


def _community_fragment_client_ip(
    request: Request,
) -> str:
    forwarded = request.headers.get(
        "x-ulgg-client-ip",
        "",
    ).strip()

    if forwarded:
        return forwarded[:128]

    client = request.client

    if client is None:
        return "unknown"

    return str(
        client.host
        or "unknown"
    )[:128]


def _community_fragment_rate_ok(
    client_id: str,
) -> bool:
    now = time.time()
    cutoff = (
        now
        - _COMMUNITY_FRAGMENT_RATE_WINDOW
    )

    rows = _COMMUNITY_FRAGMENT_RATE.get(
        client_id,
        [],
    )

    rows = [
        ts
        for ts in rows
        if ts >= cutoff
    ]

    if len(rows) >= _COMMUNITY_FRAGMENT_RATE_LIMIT:
        _COMMUNITY_FRAGMENT_RATE[
            client_id
        ] = rows

        return False

    rows.append(now)

    _COMMUNITY_FRAGMENT_RATE[
        client_id
    ] = rows

    # Cheap bounded cleanup.
    if len(_COMMUNITY_FRAGMENT_RATE) > 4096:
        for key in list(
            _COMMUNITY_FRAGMENT_RATE
        )[:1024]:
            values = [
                ts
                for ts in _COMMUNITY_FRAGMENT_RATE[
                    key
                ]
                if ts >= cutoff
            ]

            if values:
                _COMMUNITY_FRAGMENT_RATE[
                    key
                ] = values
            else:
                _COMMUNITY_FRAGMENT_RATE.pop(
                    key,
                    None,
                )

    return True


# RAID_PLAYER_CACHE_OVERLAY_V1
_RAID_PLAYER_CACHE_PATH = (
    Path(__file__).resolve().parents[2]
    / "data"
    / "raid_player_cache.json"
)

_raid_player_cache_mtime_ns = None
_raid_player_cache_payload = {}


def _raid_player_cache_data():
    global _raid_player_cache_mtime_ns
    global _raid_player_cache_payload

    try:
        stat = _RAID_PLAYER_CACHE_PATH.stat()
    except OSError:
        return {}

    if (
        _raid_player_cache_mtime_ns
        == stat.st_mtime_ns
    ):
        return _raid_player_cache_payload

    try:
        payload = json.loads(
            _RAID_PLAYER_CACHE_PATH.read_text(
                encoding="utf-8",
            )
        )
    except Exception:
        return {}

    if not isinstance(payload, dict):
        return {}

    _raid_player_cache_mtime_ns = (
        stat.st_mtime_ns
    )
    _raid_player_cache_payload = payload

    return payload


# RAID_PLAYER_METADATA_OVERLAY_V1
def _raid_player_cached_metadata(
    raid: RaidEntry,
) -> dict | None:
    payload = _raid_player_cache_data()

    raids = payload.get("raids")

    if not isinstance(raids, dict):
        return None

    cached = raids.get(
        raid.profound_id
    )

    if not isinstance(cached, dict):
        return None

    try:
        updated_at = float(
            cached.get("updated_at")
            or 0.0
        )

        ttl = float(
            payload.get("ttl_seconds")
            or 180.0
        )
    except (TypeError, ValueError):
        return None

    if (
        time.time() - updated_at
        > ttl
    ):
        return None

    metadata = cached.get(
        "metadata"
    )

    if not isinstance(
        metadata,
        dict,
    ):
        return None

    return metadata


def _observed_players(
    service,
    raid: RaidEntry,
):
    payload = _raid_player_cache_data()

    raids = payload.get("raids")

    if isinstance(raids, dict):
        cached = raids.get(
            raid.profound_id
        )

        if isinstance(cached, dict):
            try:
                updated_at = float(
                    cached.get(
                        "updated_at"
                    )
                    or 0.0
                )

                ttl = float(
                    payload.get(
                        "ttl_seconds"
                    )
                    or 180.0
                )
            except (TypeError, ValueError):
                updated_at = 0.0
                ttl = 180.0

            players = cached.get(
                "players"
            )

            if (
                time.time() - updated_at
                <= ttl
                and isinstance(
                    players,
                    list,
                )
            ):
                return [
                    {
                        "rank": item.get(
                            "rank"
                        ),
                        "level": item.get(
                            "level"
                        ),
                        "name": item.get(
                            "name"
                        ),
                        "point": item.get(
                            "point"
                        ),
                    }
                    for item in players
                    if isinstance(
                        item,
                        dict,
                    )
                ]

    # Legacy fallback only.
    return [
        {
            "rank": player.rank,
            "level": None,
            "name": player.name,
            "point": player.point,
            "damage": player.damage,
        }
        for player in raid.players
    ]



# RAID_STAGE_SYNC_V1
# STAGE_SYNC_PROFOUND_ID_FALLBACK_20260925_V1
def _stage_public_code_from_profound_id(
    profound_id: str,
) -> str | None:
    """Resolve one exact account-side profound_id to public Raid code.

    raid_player_cache.json is keyed by public prf_code.  The collector stores
    the verified db_raid profound_id obtained from that exact public-code join.

    Fail closed on zero or multiple matches.
    """
    target = str(
        profound_id or ""
    ).strip()

    if not target:
        return None

    payload = _raid_player_cache_data()

    raids = (
        payload.get("raids")
        if isinstance(payload, dict)
        else None
    )

    if not isinstance(raids, dict):
        return None

    matches = []

    for public_code, record in raids.items():
        if (
            isinstance(public_code, str)
            and public_code
            and isinstance(record, dict)
            and record.get("profound_id")
                == target
        ):
            matches.append(
                public_code
            )

    if len(matches) != 1:
        return None

    return matches[0]


def _stage_notification_policy(service):
    dispatcher = getattr(service, "dispatcher", None)
    return getattr(dispatcher, "notification_policy", None)


def _verify_stage_signature(
    request: Request,
    *,
    body: bytes,
    expected_action: str,
) -> None:
    if (
        not RAID_STAGE_API_SECRET
        or not RAID_STAGE_MATCH_SECRET
    ):
        raise HTTPException(
            status_code=503,
            detail="stage_sync_disabled",
        )

    timestamp = request.headers.get(
        "x-stage-timestamp",
        "",
    ).strip()
    signature = request.headers.get(
        "x-stage-signature",
        "",
    ).strip().lower()
    action = request.headers.get(
        "x-stage-action",
        "",
    ).strip().lower()

    if action != expected_action:
        raise HTTPException(
            status_code=401,
            detail="stage_sync_action_mismatch",
        )

    try:
        timestamp_value = int(timestamp)
    except (TypeError, ValueError):
        raise HTTPException(
            status_code=401,
            detail="stage_sync_timestamp_invalid",
        )

    if (
        abs(time.time() - timestamp_value)
        > RAID_STAGE_AUTH_WINDOW_SECONDS
    ):
        raise HTTPException(
            status_code=401,
            detail="stage_sync_timestamp_expired",
        )

    canonical = (
        timestamp.encode("utf-8")
        + b"\n"
        + expected_action.encode("utf-8")
        + b"\n"
        + body
    )
    expected = hmac.new(
        RAID_STAGE_API_SECRET.encode("utf-8"),
        canonical,
        hashlib.sha256,
    ).hexdigest()

    if not hmac.compare_digest(expected, signature):
        raise HTTPException(
            status_code=401,
            detail="stage_sync_signature_invalid",
        )


def _cached_reward_record(service, raid: RaidEntry):
    """Return cached Reward API record only; never fetch external API."""
    resolver = getattr(service, "fragment_resolver", None)

    if resolver is None or raid.treasure_level is None:
        return None

    try:
        return resolver.cache.get_many(
            [raid.treasure_level]
        ).get(raid.treasure_level)
    except Exception:
        return None


# RAID_DB_RAID_REWARD_BRIDGE_V1
def _raw_reward_items(raid: RaidEntry) -> list[dict]:
    raw = raid.raw if isinstance(raid.raw, dict) else {}
    items = raw.get("reward_items")
    if not isinstance(items, list):
        return []
    return [dict(item) for item in items if isinstance(item, dict)]


def _partial_reward_record(raid: RaidEntry) -> dict | None:
    """
    Convert only reward tuples whose meaning has been verified.

    Unknown tuples remain available through reward_items but are NOT guessed
    into discovery/ranking/defeat/participation UI groups.
    """
    decoded: list[dict] = []

    for item in _raw_reward_items(raid):
        try:
            reward_type = int(item.get("type"))
            reward_id = int(item.get("id"))
            slot = int(item.get("slot", 0))
            value = int(item.get("value", 0))
        except (TypeError, ValueError):
            continue

        # Verified 2026-09-24 against official Raid reward rendering:
        # type=3, id=2, slot=0 => 古代妙藥; value = quantity.
        if (reward_type, reward_id, slot) == (3, 2, 0) and value > 0:
            decoded.append({
                "itemName": "古代妙藥",
                "quantity": value,
                "textColor": "#22c55e",
                "textBold": False,
            })

    if not decoded:
        return None

    raw = raid.raw if isinstance(raid.raw, dict) else {}
    detail = raw.get("db_raid") if isinstance(raw.get("db_raid"), dict) else {}
    rarity = raid.rarity
    if rarity is None:
        try:
            rarity = int(detail.get("rarity"))
        except (TypeError, ValueError):
            rarity = None

    return {
        "treasureLevel": None,
        "bossDisplayName": raid.boss_name,
        "rarity": rarity,
        "mapLevel": None,
        "whirlpoolTier": None,
        "discovery": [],
        "participation": decoded,
        "ranking": [],
        "defeat": [],
    }


# RAID_REWARD_REVERSE_LOOKUP_V1
_REWARD_REVERSE_CACHE_PATH = (
    Path(__file__).resolve().parents[2]
    / "data"
    / "raid_reward_cache.json"
)

_REWARD_REVERSE_CACHE_MTIME_NS = None
_REWARD_REVERSE_CACHE_RECORDS = []

_REWARD_TIER_BY_MEMBER_LIMIT = {
    80: "渦I",
    100: "渦II/III",
    120: "渦IV",
}

_REWARD_FRAGMENT_ICON_BY_NAME = {
    "記憶碎片": "🟡",
    "記憶的碎片": "🟡",
    "记忆碎片": "🟡",
    "记忆的碎片": "🟡",

    "時間碎片": "🟢",
    "時間的碎片": "🟢",
    "时间碎片": "🟢",
    "时间的碎片": "🟢",

    "靈魂碎片": "🔵",
    "靈魂的碎片": "🔵",
    "灵魂碎片": "🔵",
    "灵魂的碎片": "🔵",

    "生命碎片": "🔴",
    "生命的碎片": "🔴",

    "死亡碎片": "🟣",
    "死亡的碎片": "🟣",
}

_REWARD_VALID_FRAGMENT_ICONS = {
    "🟡",
    "🟢",
    "🔵",
    "🔴",
    "🟣",
}


def _reward_reverse_norm_tier(value) -> str:
    if not isinstance(value, str):
        return ""

    return (
        value.strip()
        .replace("涡", "渦")
    )


def _reward_reverse_fragment(record) -> str | None:
    found = set()

    ranking = record.get("ranking")

    if not isinstance(ranking, list):
        return None

    for item in ranking:
        if not isinstance(item, dict):
            continue

        name = str(
            item.get("itemName") or ""
        ).replace(" ", "")

        for key, icon in (
            _REWARD_FRAGMENT_ICON_BY_NAME.items()
        ):
            if key in name:
                found.add(icon)

    if len(found) == 1:
        return next(iter(found))

    return None


def _reward_reverse_records() -> list[dict]:
    global _REWARD_REVERSE_CACHE_MTIME_NS
    global _REWARD_REVERSE_CACHE_RECORDS

    try:
        stat = _REWARD_REVERSE_CACHE_PATH.stat()
    except OSError:
        return []

    if (
        _REWARD_REVERSE_CACHE_MTIME_NS
        == stat.st_mtime_ns
    ):
        return _REWARD_REVERSE_CACHE_RECORDS

    try:
        payload = json.loads(
            _REWARD_REVERSE_CACHE_PATH.read_text(
                encoding="utf-8"
            )
        )
    except Exception:
        return []

    rewards = (
        payload.get("rewards")
        if isinstance(payload, dict)
        else None
    )

    if not isinstance(rewards, dict):
        return []

    records = [
        record
        for record in rewards.values()
        if isinstance(record, dict)
    ]

    _REWARD_REVERSE_CACHE_MTIME_NS = (
        stat.st_mtime_ns
    )
    _REWARD_REVERSE_CACHE_RECORDS = records

    return records


def _reverse_cached_reward_record(
    raid: RaidEntry,
) -> dict | None:
    """
    Resolve RewardCache only when the tuple below identifies
    exactly one cached Reward API record:

        boss + whirlpool tier + rarity + fragment

    Never guess on missing fields or ambiguous matches.
    """
    boss = str(
        raid.boss_name or ""
    ).strip()

    try:
        rarity = int(raid.rarity)
    except (TypeError, ValueError):
        return None

    try:
        member_limit = int(
            raid.member_limit
        )
    except (TypeError, ValueError):
        return None

    tier = _REWARD_TIER_BY_MEMBER_LIMIT.get(
        member_limit
    )

    raw = (
        raid.raw
        if isinstance(raid.raw, dict)
        else {}
    )

    fragment = raw.get(
        "stage_fragment"
    )

    if not boss:
        return None

    if not tier:
        return None

    if (
        fragment
        not in _REWARD_VALID_FRAGMENT_ICONS
    ):
        return None

    matches = []

    for record in _reward_reverse_records():
        display = str(
            record.get("bossDisplayName")
            or ""
        ).strip()

        # Keep identical semantics to the production dry-run:
        # observed boss must occur inside Reward API display name.
        if boss not in display:
            continue

        try:
            record_rarity = int(
                record.get("rarity")
            )
        except (TypeError, ValueError):
            continue

        if record_rarity != rarity:
            continue

        if (
            _reward_reverse_norm_tier(
                record.get("whirlpoolTier")
            )
            != tier
        ):
            continue

        if (
            _reward_reverse_fragment(record)
            != fragment
        ):
            continue

        matches.append(record)

        # We only care about uniqueness.
        if len(matches) > 1:
            return None

    if len(matches) != 1:
        return None

    return matches[0]


def _observed_reward(service, raid: RaidEntry):
    # 1. Authoritative treasure_level path.
    complete = _cached_reward_record(
        service,
        raid,
    )
    if isinstance(complete, dict):
        return (
            complete,
            "treasure_level_cache",
        )

    # 2. No TL:
    #    boss + tier + rarity + fragment
    #    must uniquely identify one RewardCache record.
    reverse = _reverse_cached_reward_record(
        raid
    )
    if isinstance(reverse, dict):
        return (
            reverse,
            "tier_rarity_fragment_cache",
        )

    # 3. Existing verified db_raid tuple fallback.
    partial = _partial_reward_record(raid)
    if isinstance(partial, dict):
        return (
            partial,
            "db_raid_reward_partial",
        )

    return None, None


# RAID_OBSERVED_STAGE_FRAGMENT_FIELDS_V1
def _observed_raid_item(
    record,
    *,
    service=None,
    raid: RaidEntry | None = None,
    status: str,
    ended_at: float | None,
):
    raid = record.raid if raid is None else raid

    # RAID_PLAYER_METADATA_OVERLAY_V1
    player_metadata = (
        _raid_player_cached_metadata(
            raid
        )
    )

    if isinstance(
        player_metadata,
        dict,
    ):
        raid = apply_metadata(
            raid,
            player_metadata,
        )

    reward, reward_source = _observed_reward(service, raid)
    return {
        "raid_id": raid.profound_id,
        "status": status,
        "founder": raid.founder,
        "boss": raid.boss_name,
        "monster_code": raid.monster_code,
        "stage_id": raid.stage_id,
        "rarity": raid.rarity,
        "fragment": (
            raid.raw.get("stage_fragment")
            if isinstance(raid.raw, dict)
            else None
        ),
        "fragment_source": (
            raid.raw.get("stage_metadata_source")
            if isinstance(raid.raw, dict)
            else None
        ),
        "treasure_level": raid.treasure_level,
        "reward": reward,
        "reward_source": reward_source,
        "reward_items": _raw_reward_items(raid),
        "hp": raid.hp,
        "hp_max": raid.hp_max,
        "hp_ratio": raid.hp_ratio,
        "participant_count": raid.participant_count,
        "member_limit": raid.member_limit,
        "players": _observed_players(
            service,
            raid,
        ),
        "expires_at": raid.expires_at,
        "last_seen_at": record.last_seen_at,
        "ended_at": ended_at,
        "state_raw": [
            {
                "type": raid_status.raw_type,
                "base_type": raid_status.base_type,
                "level": raid_status.level,
                "value": raid_status.value,
                "expires_at": raid_status.expires_at,
            }
            for raid_status in raid.statuses
        ],
        "state_labels": format_status_labels(raid.statuses, limit=99),
    }



@router.get(
    "/api/health"
)
async def health(
    request: Request,
):

    service = (
        request.app.state.raid_service
    )


    snapshot = (
        service.get_snapshot()
    )

    last_update = service.last_update

    return {
        "ok": True,
        "source": service.source_name,
        "connected": service.is_connected(),
        "raid_count":
            len(
                snapshot.raids
            )
            if snapshot
            else 0,

        "last_update": (
            datetime.fromtimestamp(last_update, tz=UTC).isoformat()
            if last_update is not None
            else None
        ),
        "last_error": service.get_last_error(),
    }



@router.get(
    "/api/raids"
)
async def raids(
    request: Request,
):

    service = (
        request.app.state.raid_service
    )


    snapshot = (
        service.get_snapshot()
    )


    if snapshot is None:

        return {
            "ok": True,
            "raids": [],
        }
        
    



    result = []


    for raid in snapshot.raids:

        reward = predict_fragment(raid) if snapshot.source != "websocket" else None


        item = {
            "profound_id": raid.profound_id,
            "boss": raid.boss_name,
            "monster_code": raid.monster_code,
            "founder": raid.founder,
            "hp": raid.hp,
            "hp_max": raid.hp_max,
            "member_limit": raid.member_limit,
            "participant_count": raid.participant_count,
            "expires_at": raid.expires_at,
        }
        if snapshot.source != "websocket":
            item.update({
                "star": star_text(raid.rarity),
                "map": map_name(raid.map_id),
                "stage": raid.stage_id,
                "hp_ratio": raid.hp_ratio,
                "detector": detector_tier(raid.member_limit),
                "joined": raid.already_joined,
                "my_damage": raid.my_damage,
                "my_point": raid.my_point,
                "pass": raid.pass_code,
                "reward_prediction": {
                    "name": reward.reward_name,
                    "confidence": reward.confidence,
                    "reason": reward.reason,
                },
            })
        result.append(item)


    return {

        "ok": True,

        "source":
            snapshot.source,


        "updated_at":
            snapshot.captured_at,


        "count":
            len(result),


        "raids":
            result,

    }
    
@router.get(
    "/api/raid_text"
)
async def raid_text(
    request: Request,
):

    service = (
        request.app.state.raid_service
    )


    snapshot = (
        service.get_snapshot()
    )


    if snapshot is None:

        return {
            "ok": False,
            "text": "目前沒有 Raid 資料",
        }


    text = format_raid_list(
        list(snapshot.raids)
    )


    return {

        "ok": True,

        "updated_at":
            snapshot.captured_at,


        "count":
            len(snapshot.raids),


        "text":
            text,

    }
    
@router.post(
    "/api/notify_test"
)
async def notify_test(
    request: Request,
):

    service = (
        request.app.state.raid_service
    )

    snapshot = (
        service.get_snapshot()
    )


    if snapshot is None:

        return {
            "ok": False,
            "error": "no snapshot",
        }

    discord = DiscordWebhook(
        DISCORD_WEBHOOK_URL
    )




    notifier = RaidNotifier(
        discord
    )


    result = notifier.notify_snapshot(
        snapshot.raids
    )


    return {
        "ok": result,
        "count": len(snapshot.raids),
    }


@router.get("/api/stage-sync/pending")
async def stage_sync_pending(request: Request):
    _verify_stage_signature(
        request,
        body=b"",
        expected_action="pending",
    )

    service = request.app.state.raid_service
    policy = _stage_notification_policy(service)

    if policy is None:
        raise HTTPException(
            status_code=503,
            detail="stage_sync_policy_unavailable",
        )

    tokens = policy.stage_pending_tokens(
        RAID_STAGE_MATCH_SECRET
    )

    return {
        "ok": True,
        "count": len(tokens),
        "tokens": list(tokens),
    }


@router.post("/api/stage-sync/report")
async def stage_sync_report(request: Request):
    body = await request.body()

    _verify_stage_signature(
        request,
        body=body,
        expected_action="report",
    )

    try:
        payload = json.loads(
            body.decode("utf-8")
        )
    except (UnicodeDecodeError, ValueError, TypeError):
        raise HTTPException(
            status_code=400,
            detail="stage_sync_json_invalid",
        )

    if not isinstance(payload, dict):
        raise HTTPException(
            status_code=400,
            detail="stage_sync_payload_invalid",
        )

    # STAGE_SYNC_PROFOUND_ID_FALLBACK_20260925_V1
    #
    # Preferred legacy path:
    #   client knows public code -> sends anonymous HMAC token.
    #
    # Fallback:
    #   client only knows account-side profound_id -> VPS reverse-resolves
    #   through raid_player_cache.json and computes the exact same token here.
    token = str(
        payload.get("token") or ""
    ).strip().lower()

    profound_id = str(
        payload.get("profound_id") or ""
    ).strip()

    token_valid = (
        len(token) == 64
        and not any(
            ch not in
            "0123456789abcdef"
            for ch in token
        )
    )

    resolved_public_code = None

    if not token_valid:
        if not profound_id:
            raise HTTPException(
                status_code=400,
                detail=(
                    "stage_sync_token_or_"
                    "profound_id_required"
                ),
            )

        resolved_public_code = (
            _stage_public_code_from_profound_id(
                profound_id
            )
        )

        if not resolved_public_code:
            return {
                "ok": True,
                "accepted": False,
                "deferred": True,
                "reason":
                    "profound_id_mapping_pending",
                "profound_id":
                    profound_id,
            }

        token = hmac.new(
            RAID_STAGE_MATCH_SECRET.encode(
                "utf-8"
            ),
            resolved_public_code.encode(
                "utf-8"
            ),
            hashlib.sha256,
        ).hexdigest()

    service = request.app.state.raid_service
    policy = _stage_notification_policy(service)

    if policy is None:
        raise HTTPException(
            status_code=503,
            detail="stage_sync_policy_unavailable",
        )

    result = policy.edit_fragment_by_token(
        token=token,
        match_secret=RAID_STAGE_MATCH_SECRET,
        stage=payload.get("stage"),
        rarity=payload.get("rarity"),
    )

    response = {
        "ok": True,
        **result,
    }

    if resolved_public_code is not None:
        response[
            "identity_source"
        ] = (
            "raid_player_cache:"
            "profound_id"
        )

    return response


@router.post(
    "/api/community/raid-fragment-report"
)
async def community_raid_fragment_report(
    request: Request,
):
    client_id = (
        _community_fragment_client_ip(
            request
        )
    )

    if not _community_fragment_rate_ok(
        client_id
    ):
        raise HTTPException(
            status_code=429,
            detail="rate_limit_exceeded",
        )

    body = await request.body()

    if len(body) > 4096:
        raise HTTPException(
            status_code=413,
            detail="payload_too_large",
        )

    try:
        payload = json.loads(
            body.decode("utf-8")
        )
    except (
        UnicodeDecodeError,
        ValueError,
        TypeError,
    ):
        raise HTTPException(
            status_code=400,
            detail="json_invalid",
        )

    if not isinstance(payload, dict):
        raise HTTPException(
            status_code=400,
            detail="payload_invalid",
        )

    raid_code = str(
        payload.get(
            "raid_code"
        )
        or ""
    ).strip()

    if (
        not raid_code
        or len(raid_code) > 128
        or any(
            ch.isspace()
            for ch in raid_code
        )
    ):
        raise HTTPException(
            status_code=400,
            detail="raid_code_invalid",
        )

    try:
        stage = int(
            payload.get("stage")
        )
        rarity = int(
            payload.get("rarity")
        )
    except (TypeError, ValueError):
        raise HTTPException(
            status_code=400,
            detail="stage_or_rarity_invalid",
        )

    if stage not in (1, 2, 3, 4, 5):
        raise HTTPException(
            status_code=400,
            detail="stage_invalid",
        )

    if rarity not in (1, 2, 3, 4, 5, 6):
        raise HTTPException(
            status_code=400,
            detail="rarity_invalid",
        )

    service = (
        request.app.state.raid_service
    )

    tracker = service.lifecycle
    view = tracker.view()

    known_ids = {
        record.raid.profound_id
        for record in [
            *view.active,
            *view.recent_ended,
        ]
        if (
            record.raid.profound_id
        )
    }

    if raid_code not in known_ids:
        raise HTTPException(
            status_code=404,
            detail="raid_not_found",
        )

    policy = _stage_notification_policy(
        service
    )

    if policy is None:
        raise HTTPException(
            status_code=503,
            detail="stage_policy_unavailable",
        )

    result = (
        policy.edit_fragment_by_raid_code(
            raid_id=raid_code,
            stage=stage,
            rarity=rarity,
            source="community_api",
        )
    )

    if (
        result.get("reason")
        == "metadata_conflict"
    ):
        raise HTTPException(
            status_code=409,
            detail={
                "code": "metadata_conflict",
                **result,
            },
        )

    if not result.get(
        "accepted",
        False,
    ):
        raise HTTPException(
            status_code=400,
            detail=result,
        )

    return {
        "ok": True,
        "raid_code": raid_code,
        **result,
    }


@router.get("/api/observed-raids")
async def observed_raids(request: Request):
    service = request.app.state.raid_service
    tracker = service.lifecycle
    view = tracker.view()
    active = [
        _observed_raid_item(
            record,
            service=service,
            raid=service.hydrate_cached_metadata(record.raid),
            status=tracker.active_status(record.raid).value,
            ended_at=None,
        )
        for record in view.active
    ]
    recent_ended = [
        _observed_raid_item(
            record,
            service=service,
            raid=service.hydrate_cached_metadata(record.raid),
            status=record.status.value,
            ended_at=record.ended_at,
        )
        for record in view.recent_ended
    ]
    return {
        "ok": True,
        "source": service.source_name,
        "connected": service.is_connected(),
        "updated_at": view.updated_at,
        "ended_ttl_seconds": tracker.ended_ttl_seconds,
        "active_count": len(active),
        "recent_ended_count": len(recent_ended),
        "raids": [*active, *recent_ended],
    }

@router.get("/api/raid-player-stats")
async def raid_player_stats(
    request: Request,
    days: str = "all",
):
    service = request.app.state.raid_service

    repository = getattr(
        service,
        "raid_history_repository",
        None,
    )

    if repository is None:
        return {
            "ok": False,
            "error": "raid_history_unavailable",
        }

    range_map = {
        "all": None,
        "30": 30,
        "7": 7,
    }

    if days not in range_map:
        days = "all"

    founder = unquote(
        request.headers.get(
            "x-ulgg-player",
            "",
        )
    ).strip()

    if not founder:
        return {
            "ok": False,
            "error": "player_identity_required",
        }

    try:
        result = await asyncio.to_thread(
            repository.fetch_player_stats,
            founder=founder,
            days=range_map[days],
        )

        # RAID_PLAYER_STATS_BOSS_TABLE_V1
        result["bosses"] = (
            await asyncio.to_thread(
                repository.fetch_founder_boss_stats,
                founder=founder,
                days=range_map[days],
            )
        )
    except Exception as error:
        return {
            "ok": False,
            "error": type(error).__name__,
        }

    return {
        "ok": True,
        "range": days,
        **result,
    }

