"""OFFLINE-ONLY one-shot Raid orchestration. No real socket adapter exists.

A future live runner needs a separate security review and explicit authorization.
This fixture runner cannot accept PublicRaidEnrichmentClient (network-capable)
because injected endpoints must identify themselves as offline fixtures.
"""
from __future__ import annotations

import asyncio
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from raid_bot.safety.cleanup_journal import (
    BLOCKED, VERIFIED, VERIFIED_NO_RANK,
    GateBlocked, PersistentCleanupJournal,
)
from raid_player_collector_daemon import _parse_rank


@dataclass(frozen=True)
class OfflineOneShotPlan:
    account_alias: str
    steam_id: str
    target_code: str
    allowed_codes: frozenset[str]
    mode: str = "offline-fixture"
    max_join_attempts: int = 1
    timeout_seconds: float = 5.0

    def validate(self) -> None:
        if self.mode != "offline-fixture":
            raise GateBlocked("live_mode_not_implemented")
        if not re.fullmatch(r"[A-Z][A-Z0-9_-]{2,47}", self.account_alias):
            raise ValueError("account_alias_invalid")
        if not re.fullmatch(r"7656119[0-9]{10}", self.steam_id):
            raise ValueError("steam_id_invalid")
        if self.max_join_attempts != 1:
            raise GateBlocked("join_attempt_limit_must_be_one")
        if (
            len(self.allowed_codes) != 1
            or not self.target_code
            or self.target_code not in self.allowed_codes
        ):
            raise GateBlocked("target_not_uniquely_allowlisted")
        if not (0.1 <= self.timeout_seconds <= 30.0):
            raise ValueError("fixture_timeout_invalid")


@dataclass(frozen=True)
class OfflineIdentityProof:
    """Fixture values only. This does not prove a real Steam-to-UUID link."""
    alias: str
    steam_id: str
    offline_fixture: bool = True


class OfflineOneShotRunner:
    def __init__(
        self,
        plan: OfflineOneShotPlan,
        journal: PersistentCleanupJournal,
        *,
        identity: OfflineIdentityProof,
        enricher: Any,
        fresh_verifier: Any,
    ):
        plan.validate()
        if (
            type(identity) is not OfflineIdentityProof
            or not identity.offline_fixture
            or identity.alias != plan.account_alias
            or identity.steam_id != plan.steam_id
        ):
            raise GateBlocked("fixture_account_identity_mismatch")
        if (
            not getattr(enricher, "offline_fixture", False)
            or not getattr(fresh_verifier, "offline_fixture", False)
            or enricher is fresh_verifier
        ):
            raise GateBlocked("only_distinct_offline_fixture_clients_allowed")
        self.plan = plan
        self.journal = journal
        self.enricher = enricher
        self.fresh_verifier = fresh_verifier

    async def run(self) -> dict[str, Any]:
        # Journal reservation must succeed *before* the join-capable call.
        run_id = self.journal.reserve(
            account_alias=self.plan.account_alias,
            raid_code=self.plan.target_code,
        )
        try:
            result = await asyncio.wait_for(
                self.enricher.enrich(self.plan.target_code),
                timeout=self.plan.timeout_seconds,
            )
            if (
                not getattr(result, "ok", False)
                or not getattr(result, "cleanup_attempted", False)
                or not getattr(result, "cleanup_confirmed", False)
                or not isinstance(getattr(result, "metadata", None), dict)
            ):
                self.journal.transition(
                    run_id,
                    state=BLOCKED,
                    cleanup_verified=False,
                    error_class="enrichment_or_cleanup_unconfirmed",
                )
                return self.journal.read_public_state()

            raid_id = result.metadata.get("profound_id")
            if not isinstance(raid_id, str) or not raid_id.strip():
                self.journal.transition(
                    run_id,
                    state=BLOCKED,
                    cleanup_verified=False,
                    error_class="joined_raid_identity_unknown",
                )
                return self.journal.read_public_state()

            # Verifier MUST be a separate client (fixture today).
            verified_absent = await asyncio.wait_for(
                self.fresh_verifier.verify_profound_id_absent(raid_id),
                timeout=self.plan.timeout_seconds,
            )
            if verified_absent is not True:
                self.journal.transition(
                    run_id,
                    state=BLOCKED,
                    cleanup_verified=False,
                    error_class="independent_absence_unconfirmed",
                )
                return self.journal.read_public_state()

            players = _parse_rank(result.metadata.get("rank"))
            if players is None:
                self.journal.transition(
                    run_id, state=VERIFIED_NO_RANK,
                    cleanup_verified=True, rank_complete=False,
                )
            else:
                self.journal.transition(
                    run_id, state=VERIFIED,
                    cleanup_verified=True, rank_complete=True,
                    players_count=len(players),
                )
            return self.journal.read_public_state()
        except BaseException as exc:
            # Cancellation and interrupted work are NOT a confirmed cleanup.
            # Keep the PENDING record on disk even if the transition itself fails.
            self.journal.transition(
                run_id,
                state=BLOCKED,
                cleanup_verified=False,
                error_class=type(exc).__name__,
            )
            raise
