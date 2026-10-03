from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass, replace
from pathlib import Path
from typing import Callable

from ..domain.models import RaidEntry, RaidPlayer, RaidStatus


logger = logging.getLogger(__name__)

_STAGE_FRAGMENT_ICONS = {"🟡", "🟢", "🔵", "🔴", "🟣"}
_STAGE_FRAGMENT_RAW_KEY = "stage_fragment"
_STAGE_METADATA_SOURCE_RAW_KEY = "stage_metadata_source"


@dataclass(frozen=True)
class ActiveRaidStateRecord:
    statuses: tuple[RaidStatus, ...]
    players: tuple[RaidPlayer, ...]
    last_attempt_at: float | None = None
    last_success_at: float | None = None
    last_error: str | None = None
    stage_id: int | None = None
    rarity: int | None = None
    fragment: str | None = None
    stage_learned_at: float | None = None
    stage_source: str | None = None


class ActiveRaidStateCache:
    """
    Current Raid state keyed by public Raid code.

    statuses / players are intentionally in-memory only.
    Learned stage/rarity/fragment metadata is persisted because db_raid_support
    does not provide those fields and a later snapshot must not regress a
    previously learned fragment back to unknown.
    """

    def __init__(
        self,
        *,
        clock: Callable[[], float] = time.time,
        stage_metadata_path: str | Path | None = None,
    ) -> None:
        self._clock = clock
        self._records: dict[str, ActiveRaidStateRecord] = {}
        self._stage_metadata_path = Path(
            stage_metadata_path
            if stage_metadata_path is not None
            else (
                Path(__file__).resolve().parents[2]
                / "data"
                / "raid_stage_metadata.json"
            )
        )
        self._load_stage_metadata()

    @staticmethod
    def _optional_int(value) -> int | None:
        if value is None or isinstance(value, bool):
            return None
        try:
            return int(str(value).strip())
        except (TypeError, ValueError):
            return None

    @staticmethod
    def _optional_text(value) -> str | None:
        if not isinstance(value, str):
            return None
        value = value.strip()
        return value or None

    def _empty_record(self) -> ActiveRaidStateRecord:
        return ActiveRaidStateRecord(
            statuses=(),
            players=(),
        )

    def _load_stage_metadata(self) -> None:
        path = self._stage_metadata_path
        if not path.exists():
            return

        try:
            payload = json.loads(path.read_text(encoding="utf-8"))
        except Exception as error:
            logger.warning(
                "Raid stage metadata load failed path=%s error=%s",
                path,
                type(error).__name__,
            )
            return

        rows = payload.get("raids", {}) if isinstance(payload, dict) else {}
        if not isinstance(rows, dict):
            return

        loaded = 0
        for raid_id, raw in rows.items():
            if not isinstance(raid_id, str) or not raid_id.strip():
                continue
            if not isinstance(raw, dict):
                continue

            raid_id = raid_id.strip()
            stage_id = self._optional_int(raw.get("stage_id"))
            rarity = self._optional_int(raw.get("rarity"))
            fragment = self._optional_text(raw.get("fragment"))
            if fragment not in _STAGE_FRAGMENT_ICONS:
                fragment = None

            learned_at = raw.get("learned_at")
            try:
                learned_at = (
                    float(learned_at)
                    if learned_at is not None
                    else None
                )
            except (TypeError, ValueError):
                learned_at = None

            source = self._optional_text(raw.get("source"))

            if stage_id is None and rarity is None and fragment is None:
                continue

            self._records[raid_id] = ActiveRaidStateRecord(
                statuses=(),
                players=(),
                stage_id=stage_id,
                rarity=rarity,
                fragment=fragment,
                stage_learned_at=learned_at,
                stage_source=source,
            )
            loaded += 1

        if loaded:
            logger.info(
                "Raid stage metadata loaded records=%d path=%s",
                loaded,
                path,
            )

    def _persist_stage_metadata(self) -> None:
        rows: dict[str, dict[str, object]] = {}

        for raid_id, record in self._records.items():
            if (
                record.stage_id is None
                and record.rarity is None
                and record.fragment is None
            ):
                continue

            rows[raid_id] = {
                "stage_id": record.stage_id,
                "rarity": record.rarity,
                "fragment": record.fragment,
                "learned_at": record.stage_learned_at,
                "source": record.stage_source,
            }

        payload = {
            "schema_version": 1,
            "updated_at": self._clock(),
            "raids": rows,
        }

        path = self._stage_metadata_path
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp = path.with_name(path.name + ".tmp")
        tmp.write_text(
            json.dumps(
                payload,
                ensure_ascii=False,
                indent=2,
                sort_keys=True,
            ) + "\n",
            encoding="utf-8",
        )
        tmp.replace(path)

    def get(
        self,
        prf_code: str,
    ) -> ActiveRaidStateRecord | None:
        """Return the cached live-state record without mutating it."""
        return self._records.get(prf_code)

    def remember_stage_metadata(
        self,
        prf_code: str,
        *,
        stage_id=None,
        rarity=None,
        fragment: str | None = None,
        source: str = "stage_sync",
    ) -> bool:
        """Persist learned fragment metadata for one public Raid code."""
        prf_code = str(prf_code or "").strip()
        if not prf_code:
            return False

        parsed_stage = self._optional_int(stage_id)
        parsed_rarity = self._optional_int(rarity)
        parsed_fragment = self._optional_text(fragment)
        if parsed_fragment not in _STAGE_FRAGMENT_ICONS:
            parsed_fragment = None

        if (
            parsed_stage is None
            and parsed_rarity is None
            and parsed_fragment is None
        ):
            return False

        previous = self._records.get(prf_code) or self._empty_record()

        # A real battle stage report is authoritative over fragment-only
        # bootstrap data copied from an old Discord record. A fragment-only
        # bootstrap never overwrites an already learned stage report.
        has_authoritative_stage = parsed_stage is not None
        if has_authoritative_stage:
            next_stage = parsed_stage
            next_rarity = (
                parsed_rarity
                if parsed_rarity is not None
                else previous.rarity
            )
            next_fragment = (
                parsed_fragment
                if parsed_fragment is not None
                else previous.fragment
            )
            next_source = source
            learned_at = self._clock()
        else:
            next_stage = previous.stage_id
            next_rarity = previous.rarity
            next_fragment = previous.fragment or parsed_fragment
            next_source = previous.stage_source or source
            learned_at = (
                previous.stage_learned_at
                if previous.stage_learned_at is not None
                else self._clock()
            )

        changed = (
            previous.stage_id != next_stage
            or previous.rarity != next_rarity
            or previous.fragment != next_fragment
            or previous.stage_source != next_source
        )

        self._records[prf_code] = replace(
            previous,
            stage_id=next_stage,
            rarity=next_rarity,
            fragment=next_fragment,
            stage_learned_at=learned_at,
            stage_source=next_source,
        )

        if changed:
            try:
                self._persist_stage_metadata()
            except Exception as error:
                logger.exception(
                    "Raid stage metadata persist failed prf_code=%s error=%s",
                    prf_code,
                    type(error).__name__,
                )
                return False

            logger.info(
                "Raid stage metadata persisted prf_code=%s stage=%s rarity=%s fragment=%s source=%s",
                prf_code,
                next_stage,
                next_rarity,
                next_fragment,
                next_source,
            )

        return True

    def mark_attempt(self, prf_code: str) -> None:
        previous = self._records.get(prf_code)
        self._records[prf_code] = replace(
            previous or self._empty_record(),
            last_attempt_at=self._clock(),
        )

    def mark_success(
        self,
        prf_code: str,
        statuses: tuple[RaidStatus, ...],
        players: tuple[RaidPlayer, ...],
    ) -> None:
        now = self._clock()
        previous = self._records.get(prf_code)
        self._records[prf_code] = replace(
            previous or self._empty_record(),
            statuses=statuses,
            players=players,
            last_attempt_at=now,
            last_success_at=now,
            last_error=None,
        )

    def mark_failure(
        self,
        prf_code: str,
        error: str,
    ) -> None:
        previous = self._records.get(prf_code)
        self._records[prf_code] = replace(
            previous or self._empty_record(),
            last_attempt_at=self._clock(),
            last_error=error,
        )

    def overlay(self, raid: RaidEntry) -> RaidEntry:
        record = self._records.get(raid.profound_id)

        # Observer state must come from the live-state cache.
        # Do not accidentally expose old state stored in TL metadata cache.
        if record is None:
            return replace(
                raid,
                statuses=(),
                players=(),
            )

        raw = dict(raid.raw) if isinstance(raid.raw, dict) else {}
        if record.fragment in _STAGE_FRAGMENT_ICONS:
            raw[_STAGE_FRAGMENT_RAW_KEY] = record.fragment
            raw[_STAGE_METADATA_SOURCE_RAW_KEY] = record.stage_source

        return replace(
            raid,
            rarity=(
                raid.rarity
                if raid.rarity is not None
                else record.rarity
            ),
            stage_id=(
                raid.stage_id
                if raid.stage_id is not None
                else record.stage_id
            ),
            statuses=record.statuses,
            players=record.players,
            raw=raw,
        )
