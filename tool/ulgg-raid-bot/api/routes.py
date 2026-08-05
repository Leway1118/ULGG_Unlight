from __future__ import annotations

from datetime import UTC, datetime

from fastapi import APIRouter, Request

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

from ..notifications.discord import (
    DiscordWebhook,
)

from ..notifications.notifier import (
    RaidNotifier,
)
from ..config import DISCORD_WEBHOOK_URL


router = APIRouter()



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
