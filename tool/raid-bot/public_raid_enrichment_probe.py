from __future__ import annotations

import argparse
import asyncio
import os
from pathlib import Path
from typing import Any

from raid_bot.domain.models import RaidEntry
from raid_bot.formatter.raid_text import format_raid_line
from raid_bot.infrastructure.reward_cache import RewardCache
from raid_bot.infrastructure.treasure_reward_client import TreasureRewardClient
from raid_bot.services.fragment_resolver import FragmentResolver
from raid_metadata_scanner import (
    DEFAULT_ENDPOINT,
    DEFAULT_OUTPUT_ROOT,
    DEFAULT_TIMEOUT_SECONDS,
    RaidMetadataScanner,
)


DEFAULT_CACHE_PATH = Path(__file__).resolve().parent / "data" / "raid_reward_cache.json"


def _optional_int(value: Any) -> int | None:
    if value is None or isinstance(value, bool):
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None


def _optional_text(value: Any) -> str | None:
    return value.strip() if isinstance(value, str) and value.strip() else None


def raid_entry_from_db_row(row: dict[str, Any]) -> RaidEntry:
    profound_id = row.get("profound_id")
    if isinstance(profound_id, bool) or not isinstance(profound_id, (str, int)):
        raise ValueError("matched db_raid row has no profound_id")
    profound_id = str(profound_id).strip()
    if not profound_id:
        raise ValueError("matched db_raid row has no profound_id")

    points = row.get("points")
    return RaidEntry(
        profound_id=profound_id,
        monster_code=_optional_text(row.get("profound_mons")),
        monster_id=_optional_int(row.get("core_monster_id")),
        boss_name=_optional_text(row.get("name_tcn")),
        founder=_optional_text(row.get("profound_founder")),
        rarity=_optional_int(row.get("rarity")),
        level=_optional_int(row.get("level")),
        map_id=_optional_int(row.get("map")),
        stage_id=_optional_int(row.get("stage")),
        hp=_optional_int(row.get("hp")),
        hp_max=_optional_int(row.get("hp_max")),
        member_limit=_optional_int(row.get("member_limit")),
        participant_count=len(points) if isinstance(points, list) else 0,
        pass_code=_optional_text(row.get("pass")),
        expires_at=_optional_int(row.get("limit")),
        already_joined=True,
        my_damage=None,
        my_point=None,
        treasure_level=_optional_int(row.get("treasure_level")),
        source="public_raid_enrichment_probe",
    )


class PublicRaidEnricher:
    def __init__(self, fragment_resolver: FragmentResolver) -> None:
        self.fragment_resolver = fragment_resolver

    def _enrich_sync(self, row: dict[str, Any]) -> dict[str, Any]:
        raid = raid_entry_from_db_row(row)
        predictions = self.fragment_resolver.resolve_many([raid])
        prediction = predictions.get(raid.profound_id)
        return {
            "profound_id": raid.profound_id,
            "pass": raid.pass_code,
            "founder": raid.founder,
            "monster_code": raid.monster_code,
            "treasure_level": raid.treasure_level,
            "rarity": raid.rarity,
            "map": raid.map_id,
            "stage": raid.stage_id,
            "hp": raid.hp,
            "hp_max": raid.hp_max,
            "reward_resolved": prediction is not None,
            "fragment_reward_name": (
                prediction.reward_name if prediction is not None else None
            ),
            "discord_preview": format_raid_line(
                raid,
                reward_prediction=prediction,
            ),
        }

    async def enrich(self, row: dict[str, Any]) -> dict[str, Any]:
        return await asyncio.to_thread(self._enrich_sync, row)


def _parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description=(
            "Join and enrich exactly one public Raid, preview its Discord "
            "line, then remove it from the worker account."
        )
    )
    parser.add_argument(
        "--prf-code",
        default=os.getenv("PRF_CODE", "").strip(),
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
    parser.add_argument(
        "--cache",
        type=Path,
        default=Path(
            os.getenv("RAID_REWARD_CACHE_FILE", str(DEFAULT_CACHE_PATH))
        ),
    )
    parser.add_argument(
        "--execute",
        action="store_true",
        help="Allow one join and the matching cleanup operation.",
    )
    return parser.parse_args()


async def _async_main(args: argparse.Namespace) -> int:
    owner_id = os.getenv("UNLIGHT_ID", "").strip()
    prf_code = args.prf_code.strip()
    if not owner_id:
        raise ValueError("UNLIGHT_ID is required")
    if not prf_code:
        raise ValueError("--prf-code or PRF_CODE is required")
    if args.execute and not args.endpoint.startswith("wss://"):
        raise ValueError("RAID_WS_ENDPOINT must use wss://")

    resolver = FragmentResolver(
        TreasureRewardClient(
            endpoint=os.getenv(
                "RAID_REWARD_API_URL",
                "https://www.ulrmap.wiki/api/raid-rewards/query",
            ).strip(),
            timeout_seconds=float(os.getenv("RAID_REWARD_TIMEOUT", "10")),
        ),
        RewardCache(
            args.cache,
            locale=os.getenv("RAID_REWARD_LOCALE", "zh-TW").strip(),
        ),
        locale=os.getenv("RAID_REWARD_LOCALE", "zh-TW").strip(),
    )
    scanner = RaidMetadataScanner(
        owner_id,
        prf_code,
        execute=args.execute,
    )
    result = await scanner.run(
        args.endpoint,
        timeout_seconds=args.timeout,
        output_root=args.output_root,
        enrich_target=PublicRaidEnricher(resolver).enrich,
        request_db_raid_reward=False,
    )

    if result["mode"] == "dry-run":
        print("Dry-run only; no WebSocket connection or event was sent.")
        for event in result["planned_events"]:
            print(f"- {event}")
        return 0

    enrichment = result.get("enrichment")
    if isinstance(enrichment, dict):
        print(enrichment.get("discord_preview") or "No Discord preview")
        print(f"treasure_level={enrichment.get('treasure_level')}")
        print(f"fragment={enrichment.get('fragment_reward_name')}")
    else:
        print(f"enrichment_error={result.get('enrichment_error')}")
    print(f"cleanup_attempted={result['cleanup_attempted']}")
    print(f"cleanup_confirmed={result['cleanup_confirmed']}")
    print(f"output_directory={result['output_directory']}")
    return 0 if result["ok"] and enrichment is not None else 2


def main() -> None:
    raise SystemExit(asyncio.run(_async_main(_parse_args())))


if __name__ == "__main__":
    main()
