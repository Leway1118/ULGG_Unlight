from __future__ import annotations

import asyncio
import hashlib
import hmac
import logging
import re
import sys
import time
from dataclasses import replace
from pathlib import Path
from collections.abc import Awaitable, Callable, Iterable
from contextlib import suppress

from ..config import RAID_STAGE_MATCH_SECRET
from ..domain.models import RaidEntry
from ..domain.raid_event import RaidEvent, RaidEventType
from ..domain.reward_rules import RewardPrediction
from ..formatter.raid_notification_text import (
    format_full_support_summary,
    format_new_raid_batch,
    format_notification_raid_line,
)
from ..notifications.notifier import RaidNotifier
from ..notifications.discord_raid_message_store import (
    DiscordRaidMessageStore,
)
from .fragment_resolver import FragmentResolver
from .raid_diff import RaidDiff


logger = logging.getLogger(__name__)


class RaidNotificationPolicy:
    def __init__(
        self,
        *,
        notifier: RaidNotifier | None,
        fragment_resolver: FragmentResolver | None,
        state: RaidDiff,
        batch_seconds: float,
        full_summary_interval_seconds: float,
        clock: Callable[[], float] = time.time,
        sleep: Callable[[float], Awaitable[None]] = asyncio.sleep,
    ) -> None:
        if batch_seconds <= 0:
            raise ValueError("notification batch seconds must be positive")
        if full_summary_interval_seconds <= 0:
            raise ValueError("full summary interval seconds must be positive")
        self.notifier = notifier
        self.fragment_resolver = fragment_resolver
        self.state = state
        self.batch_seconds = batch_seconds
        self.full_summary_interval_seconds = full_summary_interval_seconds
        self._clock = clock
        self._sleep = sleep
        self._pending: dict[str, RaidEntry] = {}
        self._active: dict[str, RaidEntry] = {}
        self._batch_task: asyncio.Task[None] | None = None
        self._closing = False
        self._stage_metadata_writer = None
        self._stage_metadata_overlay = None

        # RAID_DISCORD_DEATH_EDIT_POLICY_V1
        self._discord_message_store = (
            DiscordRaidMessageStore(
                Path(__file__).resolve().parents[2]
                / "data"
                / "discord_raid_messages.json"
            )
            if isinstance(notifier, RaidNotifier)
            else None
        )

    # RAID_STAGE_METADATA_PERSIST_BRIDGE_V1
    def set_stage_metadata_bridge(
        self,
        *,
        writer,
        overlay,
    ) -> None:
        """Attach RaidService stage persistence without changing dispatcher API."""
        self._stage_metadata_writer = writer
        self._stage_metadata_overlay = overlay

        # Bootstrap fragment-only knowledge already persisted by older Discord
        # edits. This fixes existing active Raids immediately after deployment;
        # they do not need to be fought again merely to repopulate the new cache.
        store = self._discord_message_store
        rows = (
            store.data.get("raids", {})
            if store is not None and isinstance(store.data, dict)
            else {}
        )
        if isinstance(rows, dict):
            for raid_id, row in rows.items():
                if not isinstance(raid_id, str) or not raid_id:
                    continue
                if not isinstance(row, dict):
                    continue
                fragment = row.get("fragment")
                if fragment not in self._STAGE_FRAGMENT_ICONS:
                    continue
                self._persist_stage_metadata(
                    raid_id,
                    stage=None,
                    rarity=None,
                    fragment=fragment,
                    source="discord_store_bootstrap",
                )

    def _overlay_stage_metadata(
        self,
        raid: RaidEntry,
    ) -> RaidEntry:
        overlay = self._stage_metadata_overlay
        if not callable(overlay):
            return raid
        try:
            result = overlay(raid)
            return result if isinstance(result, RaidEntry) else raid
        except Exception as error:
            logger.warning(
                "Raid stage metadata overlay failed raid_id=%s error=%s",
                raid.profound_id,
                type(error).__name__,
            )
            return raid

    def _persist_stage_metadata(
        self,
        raid_id: str,
        *,
        stage,
        rarity,
        fragment: str,
        source: str,
    ) -> bool:
        writer = self._stage_metadata_writer
        if not callable(writer):
            return False

        try:
            persisted = bool(
                writer(
                    raid_id,
                    stage_id=stage,
                    rarity=rarity,
                    fragment=fragment,
                    source=source,
                )
            )
        except Exception as error:
            logger.exception(
                "Raid stage metadata bridge failed raid_id=%s error=%s",
                raid_id,
                type(error).__name__,
            )
            return False

        if persisted:
            for collection in (self._active, self._pending):
                raid = collection.get(raid_id)
                if raid is not None:
                    collection[raid_id] = self._overlay_stage_metadata(raid)
        return persisted

    # RAID_DISCORD_MIN_WHIRLPOOL_TIER_II_V1
    @staticmethod
    def _discord_whirlpool_tier_max(
        raid: RaidEntry,
    ) -> int | None:
        """
        Parse the enriched whirlpool_tier field.

        Known examples:
          涡I       -> 1
          渦I       -> 1
          涡II/III  -> 3
          渦II/III  -> 3
          涡IV      -> 4

        Unknown/unresolved metadata returns None.
        """
        raw = getattr(
            raid,
            "whirlpool_tier",
            None,
        )

        if not isinstance(raw, str):
            return None

        text = (
            raw.strip()
            .replace("渦", "涡")
            .upper()
        )

        if not text:
            return None

        roman_values = {
            "I": 1,
            "V": 5,
            "X": 10,
            "L": 50,
            "C": 100,
            "D": 500,
            "M": 1000,
        }

        def roman_to_int(token: str) -> int | None:
            if (
                not token
                or any(
                    ch not in roman_values
                    for ch in token
                )
            ):
                return None

            total = 0
            previous = 0

            for ch in reversed(token):
                current = roman_values[ch]

                if current < previous:
                    total -= current
                else:
                    total += current
                    previous = current

            return total if total > 0 else None

        tokens = []
        current = ""

        for ch in text:
            if ch in roman_values:
                current += ch
            elif current:
                tokens.append(current)
                current = ""

        if current:
            tokens.append(current)

        parsed = [
            value
            for token in tokens
            if (
                value := roman_to_int(token)
            ) is not None
        ]

        return max(parsed) if parsed else None


    # RAID_DISCORD_TIER_MEMBER_LIMIT_FALLBACK_V2
    @classmethod
    def _discord_raid_is_allowed(
        cls,
        raid: RaidEntry,
    ) -> bool:
        """
        Discord publishes Whirlpool II+.

        Authority order:
          1. enriched whirlpool_tier
          2. public member_limit fallback

        Verified Protocol 1.4 mapping:
          80  -> Whirlpool I
          100 -> Whirlpool II/III
          120 -> Whirlpool IV

        Any other unresolved value fails closed.
        """
        tier = cls._discord_whirlpool_tier_max(
            raid
        )

        if tier is None:

            raw_limit = getattr(
                raid,
                "member_limit",
                None,
            )

            try:
                member_limit = int(
                    raw_limit
                )
            except (
                TypeError,
                ValueError,
            ):
                member_limit = None

            tier = {
                80: 1,
                100: 3,
                120: 4,
            }.get(
                member_limit
            )

        return (
            tier is not None
            and tier >= 2
        )


    @property
    def pending_count(self) -> int:
        return len(self._pending)

    @property
    def batch_task(self) -> asyncio.Task[None] | None:
        return self._batch_task

    async def submit(
        self,
        events: Iterable[RaidEvent],
        *,
        active_raids: Iterable[RaidEntry],
    ) -> None:
        events = [
            replace(
                event,
                raid=self._overlay_stage_metadata(event.raid),
            )
            for event in events
        ]
        active_raids = [
            self._overlay_stage_metadata(raid)
            for raid in active_raids
        ]
        self._active = {
            raid.profound_id: raid
            for raid in active_raids
            if (
                raid.profound_id
                and self._discord_raid_is_allowed(
                    raid
                )
            )
        }

        for event in events:
            raid_id = event.raid.profound_id

            if (
                event.event_type == RaidEventType.UPDATED
                and event.raid.hp is not None
                and event.raid.hp <= 0
                and raid_id not in self._pending
            ):
                self._edit_defeated_message(
                    event.raid
                )

            if raid_id in self._pending:
                if event.event_type == RaidEventType.UPDATED:
                    self._pending[raid_id] = event.raid
                continue

            if event.event_type == RaidEventType.NEW and event.is_new:

                if not self._discord_raid_is_allowed(
                    event.raid
                ):
                    logger.info(
                        "Discord Raid notification skipped "
                        "raid_id=%s whirlpool_tier=%r "
                        "reason=WHIRLPOOL_TIER_BELOW_II_OR_UNKNOWN",
                        raid_id,
                        getattr(
                            event.raid,
                            "whirlpool_tier",
                            None,
                        ),
                    )
                    continue

                self._pending[raid_id] = event.raid

        if self._pending and self._batch_task is None and not self._closing:
            self._batch_task = asyncio.create_task(
                self._run_batch_window(),
                name="raid-notification-batch",
            )

    async def _run_batch_window(self) -> None:
        current_task = asyncio.current_task()
        try:
            await self._sleep(self.batch_seconds)
            if self._batch_task is current_task:
                self._batch_task = None
            await self._flush()
        except asyncio.CancelledError:
            raise
        except Exception as error:
            logger.exception(
                "Raid notification batch failed (%s); continuing",
                type(error).__name__,
            )
        finally:
            if self._batch_task is current_task:
                self._batch_task = None

            # RAID_DISCORD_RETRY_BATCH_V1
            if (
                self._pending
                and self._batch_task is None
                and not self._closing
            ):
                self._batch_task = asyncio.create_task(
                    self._run_batch_window(),
                    name="raid-notification-batch",
                )

    def _summary_is_due(self, now: float) -> bool:
        last_summary = self.state.last_full_summary_at
        return (
            last_summary is None
            or now - last_summary >= self.full_summary_interval_seconds
        )

    def _reward_predictions(
        self,
        raids: list[RaidEntry],
    ):
        resolved = (
            self.fragment_resolver.resolve_many(raids)
            if self.fragment_resolver is not None
            else {}
        )
        resolved = dict(resolved or {})

        # A fragment learned from an actual battle is stronger evidence than a
        # missing TL lookup. Use the persisted fragment directly so summaries
        # do not regress to unknown after the next db_raid_support snapshot.
        reward_names = {
            "🟡": "記憶碎片（黃）",
            "🟢": "時間碎片（綠）",
            "🔵": "靈魂碎片（藍）",
            "🔴": "生命碎片（紅）",
            "🟣": "死亡碎片（紫）",
        }

        for raid in raids:
            # Whirlpool IV Dragon Carp has random fragments.
            # Keep an exact Reward-API/TL prediction if one exists,
            # but never replace it with battle-stage inference.
            if self._is_random_fragment_boss(
                raid
            ):
                continue

            raw = raid.raw if isinstance(raid.raw, dict) else {}
            fragment = raw.get("stage_fragment")
            reward_name = reward_names.get(fragment)
            if reward_name is None:
                continue
            resolved[raid.profound_id] = RewardPrediction(
                reward_name=reward_name,
                quantity=None,
                confidence="confirmed_stage_sync",
                reason="local battle stage sync",
            )

        return resolved

    # RAID_DC_BATCH_RAID4_RANDOM_CARP_FIX_V1
    def _contains_confirmed_raid4(
        self,
        raids: list[RaidEntry],
    ) -> bool:
        """
        Discord @渦4 authority order:

        1. Reward metadata when available.
        2. Explicit whirlpool_tier.
        3. Protocol 1.4 public member_limit fallback:
           120 -> Whirlpool IV.

        Explicit tier remains authoritative over fallback.
        """
        resolver = self.fragment_resolver
        is_raid4 = getattr(
            resolver,
            "is_raid4",
            None,
        )

        if (
            callable(is_raid4)
            and any(
                is_raid4(raid)
                for raid in raids
            )
        ):
            return True

        for raid in raids:
            tier = (
                self._discord_whirlpool_tier_max(
                    raid
                )
            )

            if tier is not None:
                if tier >= 4:
                    return True

                # Explicit non-IV tier is authoritative.
                continue

            try:
                member_limit = int(
                    getattr(
                        raid,
                        "member_limit",
                        None,
                    )
                )
            except (
                TypeError,
                ValueError,
            ):
                member_limit = None

            if member_limit == 120:
                return True

        return False


    @staticmethod
    def _is_random_fragment_boss(
        raid,
    ) -> bool:
        """
        Whirlpool IV Dragon Carp is a random-fragment exception.
        Its battle field/stage must not be interpreted as fragment color.
        """
        if raid is None:
            return False

        name = str(
            getattr(
                raid,
                "boss_name",
                None,
            )
            or ""
        )

        return (
            "龍鯉" in name
            or "龙鲤" in name
        )

    def _exclude_confirmed_raid1(
        self,
        raids: list[RaidEntry],
    ) -> list[RaidEntry]:
        resolver = self.fragment_resolver
        is_raid1 = getattr(resolver, "is_raid1", None)
        if not callable(is_raid1):
            return raids
        return [raid for raid in raids if not is_raid1(raid)]

    # RAID_DISCORD_DEATH_REFRESH_SIBLING_HP_V1
    @staticmethod
    def _refresh_hp_suffix(
        line: str,
        raid: RaidEntry,
    ) -> str:
        if (
            raid.hp is None
            or raid.hp_max is None
        ):
            return line

        try:
            hp = max(
                0,
                int(raid.hp),
            )

            hp_max = max(
                0,
                int(raid.hp_max),
            )

        except (TypeError, ValueError):
            return line

        return re.sub(
            r"\\d[\\d,]*/\\d[\\d,]*\\s*$",
            f"{hp}/{hp_max}",
            line,
        )


    def _edit_defeated_message(
        self,
        raid: RaidEntry | str,
        *,
        active_raids: Iterable[RaidEntry] = (),
    ) -> bool:
        raid_id = (
            raid.strip()
            if isinstance(raid, str)
            else str(
                raid.profound_id or ""
            ).strip()
        )

        store = self._discord_message_store

        if (
            store is None
            or self.notifier is None
            or not raid_id
        ):
            return False

        target = store.death_target(
            raid_id
        )

        if target is None:
            return False

        message_id = str(
            target["message_id"]
        )

        lines = target["content"].split("\n")

        active_by_id = {
            str(item.profound_id): item
            for item in active_raids
            if (
                isinstance(item, RaidEntry)
                and item.profound_id
            )
        }

        changed = False

        # ----------------------------------------------------
        # Refresh HP for other LIVE Raids in the same Discord
        # message. Missing / uncertain Raids keep old HP.
        # Already-dead Raids keep ☠️ 0/max.
        # ----------------------------------------------------

        raid_rows = store.data.get(
            "raids",
            {},
        )

        if isinstance(raid_rows, dict):
            for sibling_id, sibling in raid_rows.items():

                if sibling_id == raid_id:
                    continue

                if not isinstance(sibling, dict):
                    continue

                if str(
                    sibling.get("message_id") or ""
                ) != message_id:
                    continue

                if sibling.get("dead") is True:
                    continue

                live_raid = active_by_id.get(
                    sibling_id
                )

                if live_raid is None:
                    continue

                indexes = sibling.get(
                    "line_indexes",
                    [],
                )

                if not isinstance(indexes, list):
                    continue

                for index in indexes:
                    if (
                        not isinstance(index, int)
                        or index < 0
                        or index >= len(lines)
                    ):
                        continue

                    refreshed = self._refresh_hp_suffix(
                        lines[index],
                        live_raid,
                    )

                    if refreshed != lines[index]:
                        lines[index] = refreshed
                        changed = True

        # ----------------------------------------------------
        # Apply skull / 0 HP to the confirmed defeated Raid.
        # ----------------------------------------------------

        target_valid = False

        for index in target["line_indexes"]:
            if (
                index < 0
                or index >= len(lines)
            ):
                continue

            target_valid = True

            prefix = (
                "🆕 "
                if lines[index].startswith("🆕 ")
                else ""
            )

            replacement = (
                prefix
                + target["dead_line"]
            )

            if lines[index] != replacement:
                lines[index] = replacement
                changed = True

        if not target_valid:
            return False

        new_content = "\n".join(lines)

        if changed:
            if not self.notifier.edit_text(
                message_id,
                new_content,
            ):
                return False

        store.mark_dead(
            raid_id,
            content=new_content,
        )

        logger.info(
            "Discord Raid death edit success "
            "raid_id=%s message_id=%s "
            "sibling_hp_refresh=%s",
            raid_id,
            message_id,
            changed,
        )

        return True


    def edit_defeated_by_id(
        self,
        raid_id: str,
        *,
        active_raids: Iterable[RaidEntry] = (),
    ) -> bool:
        return self._edit_defeated_message(
            raid_id,
            active_raids=active_raids,
        )


    # RAID_STAGE_SYNC_V1
    _STAGE_FRAGMENT_ICONS = ("🟡", "🟢", "🔵", "🔴", "🟣")
    # RAID_BOSS_ICON_FALLBACK_STAGE_PLACEHOLDER_V2_3
    _STAGE_FRAGMENT_SHORT_NAMES = {
        "🟡": "黃",
        "🟢": "綠",
        "🔵": "藍",
        "🔴": "紅",
        "🟣": "紫",
    }
    _STAGE_MONSTER_ICONS = ("🐶", "🐛", "🐙", "🐢", "🐟")
    _NORMAL_FRAGMENT_BY_STAGE = {
        1: "🟡",
        2: "🟢",
        3: "🔵",
        4: "🔴",
        5: "🟣",
    }
    _SIX_STAR_FRAGMENT_BY_STAGE = {
        1: "🟢",
        2: "🔵",
        3: "🔴",
        4: "🟣",
        5: "🟡",
    }

    @staticmethod
    def _stage_match_token(
        match_secret: str,
        raid_id: str,
    ) -> str:
        return hmac.new(
            match_secret.encode("utf-8"),
            raid_id.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()

    @classmethod
    def _stage_fragment_icon(
        cls,
        stage,
        rarity,
    ) -> str | None:
        try:
            stage_value = int(str(stage).strip())
            rarity_value = int(rarity)
        except (TypeError, ValueError):
            return None

        table = (
            cls._SIX_STAR_FRAGMENT_BY_STAGE
            if rarity_value == 6
            else cls._NORMAL_FRAGMENT_BY_STAGE
        )
        return table.get(stage_value)

    @classmethod
    def _replace_unknown_fragment(
        cls,
        line: str,
        fragment: str,
    ) -> tuple[str, bool]:
        if fragment not in cls._STAGE_FRAGMENT_ICONS:
            return line, False

        # Already resolved to the requested fragment.
        if fragment in line:
            return line, True

        # Fail closed if another known fragment is already present.
        if any(icon in line for icon in cls._STAGE_FRAGMENT_ICONS):
            return line, False

        if "❓" not in line:
            return line, False

        # V2.3 display grammar:
        #   unknown: ❓海🐙
        #   learned: 紅海🔴🐙
        # The same transform also preserves a trailing ☠️ on dead_line.
        fragment_short = cls._STAGE_FRAGMENT_SHORT_NAMES.get(fragment)
        if fragment_short:
            unknown_index = line.find("❓")
            for monster_icon in cls._STAGE_MONSTER_ICONS:
                monster_index = line.find(monster_icon)
                if monster_index > unknown_index >= 0:
                    replaced = line.replace("❓", fragment_short, 1)
                    monster_index = replaced.find(monster_icon)
                    if monster_index >= 0:
                        replaced = (
                            replaced[:monster_index]
                            + fragment
                            + replaced[monster_index:]
                        )
                        return replaced, True

        # Legacy V1/V2 lines remain supported.
        if "❓☠️" in line:
            return line.replace(
                "❓☠️",
                f"{fragment} ☠️",
                1,
            ), True

        return line.replace("❓", fragment, 1), True


    def stage_pending_tokens(
        self,
        match_secret: str,
    ) -> tuple[str, ...]:
        store = self._discord_message_store

        if store is None or not match_secret:
            return ()

        store.prune()
        rows = store.data.get("raids", {})

        if not isinstance(rows, dict):
            return ()

        tokens: list[str] = []

        for raid_id, row in rows.items():
            if not isinstance(raid_id, str) or not raid_id:
                continue
            if not isinstance(row, dict):
                continue
            if row.get("fragment") in self._STAGE_FRAGMENT_ICONS:
                continue

            target = store.fragment_target(raid_id)
            if target is None:
                continue

            if (
                "❓" not in target["content"]
                and "❓" not in target["dead_line"]
            ):
                continue

            tokens.append(
                self._stage_match_token(
                    match_secret,
                    raid_id,
                )
            )

        return tuple(sorted(set(tokens)))

    def _edit_fragment_message(
        self,
        raid_id: str,
        fragment: str,
    ) -> bool:
        store = self._discord_message_store

        if (
            store is None
            or self.notifier is None
            or fragment not in self._STAGE_FRAGMENT_ICONS
        ):
            return False

        target = store.fragment_target(raid_id)
        if target is None:
            return False

        lines = target["content"].split("\n")
        valid_line = False
        changed = False

        for index in target["line_indexes"]:
            if index < 0 or index >= len(lines):
                continue

            replacement, valid = self._replace_unknown_fragment(
                lines[index],
                fragment,
            )

            if not valid:
                continue

            valid_line = True

            if replacement != lines[index]:
                lines[index] = replacement
                changed = True

        dead_line, dead_valid = self._replace_unknown_fragment(
            target["dead_line"],
            fragment,
        )

        if not valid_line and not dead_valid:
            return False

        new_content = "\n".join(lines)

        if changed:
            if not self.notifier.edit_text(
                target["message_id"],
                new_content,
            ):
                return False

        store.mark_fragment(
            raid_id,
            content=new_content,
            dead_line=dead_line,
            fragment=fragment,
        )

        logger.info(
            "Discord Raid fragment edit success "
            "raid_id=%s message_id=%s fragment=%s dead=%s",
            raid_id,
            target["message_id"],
            fragment,
            target["dead"],
        )
        return True

    # RAID_STAGE_SYNC_IMMEDIATE_DEFERRED_V2
    def _apply_deferred_stage_report_for_raid(
        self,
        raid_id: str,
        *,
        match_secret: str = RAID_STAGE_MATCH_SECRET,
    ) -> bool:
        store = self._discord_message_store

        if store is None or not match_secret or not raid_id:
            return False

        token = self._stage_match_token(
            match_secret,
            raid_id,
        )
        report = store.stage_report(token)

        if not isinstance(report, dict):
            return False

        raid = (
            self._active.get(raid_id)
            or self._pending.get(raid_id)
        )

        if self._is_random_fragment_boss(
            raid
        ):
            store.forget_stage_report(
                token
            )

            logger.info(
                "Dragon Carp random fragment; "
                "deferred stage fragment ignored "
                "raid_id=%s",
                raid_id,
            )

            return False

        fragment = report.get("fragment")

        if fragment not in self._STAGE_FRAGMENT_ICONS:
            store.forget_stage_report(token)
            return False

        self._persist_stage_metadata(
            raid_id,
            stage=report.get("stage"),
            rarity=report.get("rarity"),
            fragment=fragment,
            source="stage_sync_deferred",
        )

        edited = self._edit_fragment_message(
            raid_id,
            fragment,
        )

        if edited:
            store.forget_stage_report(token)
            logger.info(
                "Discord Raid deferred fragment applied "
                "raid_id=%s fragment=%s",
                raid_id,
                fragment,
            )

        return bool(edited)

    # RAID_COMMUNITY_FRAGMENT_BY_CODE_V1
    def edit_fragment_by_raid_code(
        self,
        *,
        raid_id: str,
        stage,
        rarity,
        source: str = "community_api",
    ) -> dict[str, object]:
        raid_id = str(raid_id or "").strip()

        if not raid_id:
            return {
                "accepted": False,
                "matched": False,
                "edited": False,
                "persisted": False,
                "reason": "raid_id_required",
            }

        fragment = self._stage_fragment_icon(
            stage,
            rarity,
        )

        if fragment is None:
            return {
                "accepted": False,
                "matched": True,
                "edited": False,
                "persisted": False,
                "reason": "invalid_stage_or_rarity",
            }

        stage_value = int(str(stage).strip())
        rarity_value = int(rarity)

        # -----------------------------------------------------
        # Conflict protection:
        # never overwrite already-known metadata with a
        # contradictory anonymous community report.
        # -----------------------------------------------------
        existing_fragment = None
        existing_stage = None
        existing_rarity = None

        raid = (
            self._active.get(raid_id)
            or self._pending.get(raid_id)
        )

        if raid is not None:
            existing_stage = getattr(
                raid,
                "stage_id",
                None,
            )
            existing_rarity = getattr(
                raid,
                "rarity",
                None,
            )

            raw = getattr(
                raid,
                "raw",
                None,
            )

            if isinstance(raw, dict):
                value = raw.get(
                    "stage_fragment"
                )

                if value in self._STAGE_FRAGMENT_ICONS:
                    existing_fragment = value

        store = self._discord_message_store

        if store is not None:
            rows = store.data.get(
                "raids",
                {}
            )

            if isinstance(rows, dict):
                row = rows.get(
                    raid_id
                )

                if isinstance(row, dict):
                    value = row.get(
                        "fragment"
                    )

                    if value in self._STAGE_FRAGMENT_ICONS:
                        existing_fragment = (
                            existing_fragment
                            or value
                        )

        try:
            existing_stage_value = (
                int(existing_stage)
                if existing_stage is not None
                else None
            )
        except (TypeError, ValueError):
            existing_stage_value = None

        try:
            existing_rarity_value = (
                int(existing_rarity)
                if existing_rarity is not None
                else None
            )
        except (TypeError, ValueError):
            existing_rarity_value = None

        conflicts = {}

        if (
            existing_fragment is not None
            and existing_fragment != fragment
        ):
            conflicts["fragment"] = {
                "existing": existing_fragment,
                "reported": fragment,
            }

        if (
            existing_stage_value is not None
            and existing_stage_value != stage_value
        ):
            conflicts["stage"] = {
                "existing": existing_stage_value,
                "reported": stage_value,
            }

        if (
            existing_rarity_value is not None
            and existing_rarity_value != rarity_value
        ):
            conflicts["rarity"] = {
                "existing": existing_rarity_value,
                "reported": rarity_value,
            }

        if conflicts:
            return {
                "accepted": False,
                "matched": True,
                "edited": False,
                "persisted": False,
                "fragment": fragment,
                "reason": "metadata_conflict",
                "conflicts": conflicts,
            }

        persisted = self._persist_stage_metadata(
            raid_id,
            stage=stage_value,
            rarity=rarity_value,
            fragment=fragment,
            source=source,
        )

        edited = self._edit_fragment_message(
            raid_id,
            fragment,
        )

        logger.info(
            "Community Raid fragment report "
            "raid_id=%s stage=%s rarity=%s "
            "fragment=%s persisted=%s edited=%s",
            raid_id,
            stage_value,
            rarity_value,
            fragment,
            persisted,
            edited,
        )

        return {
            "accepted": True,
            "matched": True,
            "edited": bool(edited),
            "persisted": bool(persisted),
            "stage": stage_value,
            "rarity": rarity_value,
            "fragment": fragment,
            "source": source,
            "reason": (
                "ok"
                if persisted
                else "metadata_persist_failed"
            ),
        }


    def edit_fragment_by_token(
        self,
        *,
        token: str,
        match_secret: str,
        stage,
        rarity,
    ) -> dict[str, object]:
        store = self._discord_message_store

        if store is None or not match_secret:
            return {
                "accepted": False,
                "matched": False,
                "edited": False,
                "deferred": False,
                "reason": "store_unavailable",
            }

        fragment = self._stage_fragment_icon(
            stage,
            rarity,
        )

        if fragment is None:
            return {
                "accepted": False,
                "matched": False,
                "edited": False,
                "deferred": False,
                "reason": "invalid_stage_or_rarity",
            }

        stage_value = str(stage).strip()
        rarity_value = int(rarity)

        # Persist first so an immediate process/service interruption cannot
        # lose a valid local learning report. The token is an HMAC only; raw
        # Raid pass codes are never persisted here.
        store.remember_stage_report(
            token=token,
            stage=stage_value,
            rarity=rarity_value,
            fragment=fragment,
        )

        store.prune()
        rows = store.data.get("raids", {})

        if not isinstance(rows, dict):
            return {
                "accepted": True,
                "matched": False,
                "edited": False,
                "deferred": True,
                "fragment": fragment,
                "reason": "stored_for_future_discord",
            }

        matched_id = None

        candidate_ids = set(rows)
        candidate_ids.update(self._active)
        candidate_ids.update(self._pending)

        for raid_id in candidate_ids:
            if not isinstance(raid_id, str) or not raid_id:
                continue

            expected = self._stage_match_token(
                match_secret,
                raid_id,
            )

            if hmac.compare_digest(expected, token):
                matched_id = raid_id
                break

        if matched_id is None:
            return {
                "accepted": True,
                "matched": False,
                "edited": False,
                "deferred": True,
                "fragment": fragment,
                "persisted": False,
                "reason": "stored_for_future_discord",
            }

        matched_raid = (
            self._active.get(matched_id)
            or self._pending.get(matched_id)
        )

        if self._is_random_fragment_boss(
            matched_raid
        ):
            store.forget_stage_report(
                token
            )

            logger.info(
                "Dragon Carp random fragment; "
                "stage-derived fragment ignored "
                "raid_id=%s",
                matched_id,
            )

            return {
                "accepted": True,
                "matched": True,
                "edited": False,
                "deferred": False,
                "fragment": None,
                "persisted": False,
                "reason":
                    "random_fragment_boss",
            }

        persisted = self._persist_stage_metadata(
            matched_id,
            stage=stage_value,
            rarity=rarity_value,
            fragment=fragment,
            source="stage_sync_report",
        )

        edited = self._edit_fragment_message(
            matched_id,
            fragment,
        )

        if edited:
            store.forget_stage_report(token)

        return {
            "accepted": True,
            "matched": True,
            "edited": bool(edited),
            "deferred": not bool(edited),
            "fragment": fragment,
            "persisted": bool(persisted),
            "reason": (
                "ok"
                if edited
                else "discord_marker_or_edit_failed_deferred"
            ),
        }


    # RAID_DISCORD_DELIVERY_RECORD_V1
    def has_discord_record(
        self,
        raid_id: str,
    ) -> bool:
        store = self._discord_message_store

        if store is None:
            return True

        raid_id = str(raid_id or "").strip()

        if not raid_id:
            return True

        rows = store.data.get("raids", {})

        return (
            isinstance(rows, dict)
            and isinstance(rows.get(raid_id), dict)
        )


    def tracked_unmarked_raid_ids(
        self,
    ) -> tuple[str, ...]:
        store = self._discord_message_store

        if store is None:
            return ()

        rows = store.data.get(
            "raids",
            {},
        )

        if not isinstance(rows, dict):
            return ()

        return tuple(
            raid_id
            for raid_id, row in rows.items()
            if (
                isinstance(row, dict)
                and row.get("dead") is not True
            )
        )


    def _remember_discord_message(
        self,
        *,
        message_id: str,
        content: str,
        pending: list[RaidEntry],
        active: list[RaidEntry],
        include_summary: bool,
        predictions,
    ) -> None:
        store = self._discord_message_store

        if store is None:
            return

        records = {}

        if len(pending) == 1:
            batch_line_count = 1
        else:
            batch_line_count = len(pending) + 1

        summary_start = (
            batch_line_count + 2
        )

        for pos, raid in enumerate(pending):
            if not raid.profound_id:
                continue

            if len(pending) == 1:
                indexes = [0]
            else:
                indexes = [pos + 1]

            if include_summary:
                for active_pos, active_raid in enumerate(active):
                    if (
                        active_raid.profound_id
                        == raid.profound_id
                    ):
                        indexes.append(
                            summary_start
                            + active_pos
                        )

            dead_raid = replace(
                raid,
                hp=0,
            )

            records[raid.profound_id] = {
                "line_indexes": indexes,
                "dead_line":
                    format_notification_raid_line(
                        dead_raid,
                        reward_prediction=
                            predictions.get(
                                raid.profound_id
                            ),
                    ),
                "dead": (
                    raid.hp is not None
                    and raid.hp <= 0
                ),
            }

        store.remember_message(
            message_id=message_id,
            content=content,
            raid_records=records,
        )

        # RAID_STAGE_SYNC_IMMEDIATE_DEFERRED_V2
        # A local learner may report before the Discord batch is sent. Apply
        # any persisted anonymous HMAC token immediately after this message
        # becomes addressable in the Discord store.
        for raid_id in records:
            self._apply_deferred_stage_report_for_raid(
                raid_id,
            )


    # RAID_DISCORD_KNOWN_FRAGMENT_ONLY_V1
    def _has_publishable_fragment(
        self,
        raid: RaidEntry,
    ) -> bool:

        raw = (
            raid.raw
            if isinstance(raid.raw, dict)
            else {}
        )

        return (
            raw.get("stage_fragment")
            in self._STAGE_FRAGMENT_ICONS
        )


    async def _flush(self) -> None:
        if not self._pending:
            return

        # RAID_DISCORD_PUSH_PAUSE_DROP_PENDING_V1
        #
        # Maintenance mode deliberately drops Discord-only pending
        # notifications. Do not retain them for delivery recovery,
        # otherwise maintenance/test Raids would flood Discord after
        # the pause sentinel is removed.
        pause_check = (
            getattr(
                self.notifier,
                "discord_push_paused",
                None,
            )
            if self.notifier is not None
            else None
        )

        if (
            callable(pause_check)
            and pause_check()
        ):
            dropped = len(self._pending)

            self._pending.clear()

            logger.info(
                "Discord push paused; dropped pending=%s",
                dropped,
            )

            return

        # Refresh every pending Raid against the latest canonical
        # stage/fragment cache before deciding whether it is allowed
        # to become public.
        pending_all = [
            self._overlay_stage_metadata(
                raid
            )
            for raid in self._pending.values()
        ]

        active_ids = set(
            self._active.keys()
        )

        # RAID_DC_30S_BATCH_UNKNOWN_FRAGMENT_V1
        #
        # The 30-second batch window is the anti-spam delay.
        # Fragment learning must NOT extend that window.
        #
        # Unknown fragments are published as unknown (❓);
        # a later stage/reward report can edit the existing
        # Discord message in place.
        ready = [
            raid
            for raid in pending_all
            if raid.profound_id in active_ids
        ]

        unknown_fragment = sum(
            1
            for raid in ready
            if not self._has_publishable_fragment(
                raid
            )
        )

        dropped = (
            len(pending_all)
            - len(ready)
        )

        self._pending = {}

        if unknown_fragment or dropped:
            logger.info(
                "Discord Raid publish gate "
                "ready=%d unknown_fragment=%d "
                "dropped_inactive=%d",
                len(ready),
                unknown_fragment,
                dropped,
            )

        now = self._clock()

        include_summary = (
            self._summary_is_due(now)
        )

        active = (
            [
                self._overlay_stage_metadata(
                    raid
                )
                for raid in self._active.values()
            ]
            if include_summary
            else []
        )

        # Full summary follows the same rule:
        # unknown fragment is internal-only.
        active = [
            raid
            for raid in active
            if self._has_publishable_fragment(
                raid
            )
        ]

        unique_raids = {
            raid.profound_id: raid
            for raid in [
                *ready,
                *active,
            ]
        }

        predictions = (
            self._reward_predictions(
                list(
                    unique_raids.values()
                )
            )
        )

        pending = (
            self._exclude_confirmed_raid1(
                ready
            )
        )

        active = (
            self._exclude_confirmed_raid1(
                active
            )
        )

        if not pending:
            return
        mention_raid4 = self._contains_confirmed_raid4(pending)
        text = format_new_raid_batch(
            pending,
            reward_predictions=predictions,
        )
        if include_summary:
            text = "\n\n".join([
                text,
                format_full_support_summary(
                    active,
                    reward_predictions=predictions,
                ),
            ])

        try:
            print(text)
        except UnicodeEncodeError:
            encoding = getattr(sys.stdout, "encoding", None) or "utf-8"
            print(text.encode(encoding, errors="replace").decode(encoding))
        message_id = None

        if self.notifier:
            message_id = (
                self.notifier.notify_text_with_id(
                    text,
                    mention_raid4=mention_raid4,
                )
            )
            sent = message_id is not None
        else:
            sent = True

        if (
            sent
            and message_id is not None
        ):
            stored_text = text

            role_id = getattr(
                self.notifier,
                "raid4_role_id",
                "",
            )

            if mention_raid4 and role_id:
                stored_text = (
                    f"{stored_text}\n"
                    f"<@&{role_id}>"
                )

            self._remember_discord_message(
                message_id=message_id,
                content=stored_text,
                pending=pending,
                active=active,
                include_summary=include_summary,
                predictions=predictions,
            )
        if not sent:
            for raid in pending:
                self._pending[
                    raid.profound_id
                ] = raid

            logger.warning(
                "Discord Raid notification send failed; "
                "retaining pending=%s for retry",
                len(self._pending),
            )
            return

        if include_summary:
            self.state.mark_full_summary(now)

        # RAID_DISCORD_KNOWN_FRAGMENT_ONLY_V1
        # Successful known-fragment rows are consumed above.
        # Unknown active rows intentionally remain in _pending.

    async def flush_pending(self) -> None:
        task = self._batch_task
        self._batch_task = None
        if task is not None:
            task.cancel()
            with suppress(asyncio.CancelledError):
                await task
        await self._flush()

    async def close(self) -> None:
        # RAID_DISCORD_GRACEFUL_SHUTDOWN_FLUSH_V1
        self._closing = True

        task = self._batch_task
        self._batch_task = None

        if task is not None:
            task.cancel()
            with suppress(asyncio.CancelledError):
                await task

        await self._flush()
