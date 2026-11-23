"""One-shot Raid Collector OFFLINE fixture CLI: cannot connect to game.

Usage (dedicated temporary journal; do not reuse without manual clearance):
  python run_collector_oneshot_offline.py --journal /tmp/raid-test/journal.json
  python run_collector_oneshot_offline.py --journal /tmp/raid-test/journal2.json --scenario timeout
"""
from __future__ import annotations

import argparse
import asyncio
import json
import sys
from pathlib import Path

from raid_bot.infrastructure.public_raid_enrichment_client import (
    PublicRaidEnrichmentResult,
)
from raid_bot.safety.cleanup_journal import (
    GateBlocked, PersistentCleanupJournal,
)
from raid_bot.safety.offline_oneshot import (
    OfflineIdentityProof, OfflineOneShotPlan, OfflineOneShotRunner,
)


FIXTURE_STEAM_ID = "76561190000000000"
FIXTURE_RAID_CODE = "FIXTURE-ONLY-NOT-A-REAL-RAID"


class FixtureEnricher:
    offline_fixture = True

    def __init__(self, scenario: str):
        self.scenario = scenario
        self.calls = 0

    async def enrich(self, raid_code: str) -> PublicRaidEnrichmentResult:
        self.calls += 1
        if raid_code != FIXTURE_RAID_CODE or self.calls != 1:
            raise AssertionError("fixture_join_allowlist_violation")
        if self.scenario == "timeout":
            await asyncio.Event().wait()
        if self.scenario == "exception":
            raise RuntimeError("synthetic_fixture_failure")
        if self.scenario == "join-rejected":
            return PublicRaidEnrichmentResult(status="join_rejected")
        if self.scenario == "cleanup-failed":
            return PublicRaidEnrichmentResult(
                status="cleanup_failed",
                metadata={"profound_id": "synthetic-raid"},
                cleanup_attempted=True,
                cleanup_confirmed=False,
            )
        if self.scenario == "unknown-rank":
            rank = None
        else:
            rank = [{"player_name": "Fixture", "level": 1, "point": 0}]
        return PublicRaidEnrichmentResult(
            status="success",
            metadata={"profound_id": "synthetic-raid", "rank": rank},
            cleanup_attempted=True,
            cleanup_confirmed=True,
        )


class FixtureFreshVerifier:
    offline_fixture = True

    def __init__(self, scenario: str):
        self.scenario = scenario
        self.calls = 0

    async def verify_profound_id_absent(self, raid_id: str) -> bool:
        self.calls += 1
        if self.calls != 1 or raid_id != "synthetic-raid":
            raise AssertionError("fixture_verification_mismatch")
        return self.scenario != "verification-failed"


async def simulate(journal: Path, scenario: str) -> dict:
    plan = OfflineOneShotPlan(
        account_alias="ULGG-RAID-TEST-01",
        steam_id=FIXTURE_STEAM_ID,
        target_code=FIXTURE_RAID_CODE,
        allowed_codes=frozenset({FIXTURE_RAID_CODE}),
        timeout_seconds=0.3 if scenario == "timeout" else 5.0,
    )
    runner = OfflineOneShotRunner(
        plan,
        PersistentCleanupJournal(journal),
        identity=OfflineIdentityProof(plan.account_alias, plan.steam_id),
        enricher=FixtureEnricher(scenario),
        fresh_verifier=FixtureFreshVerifier(scenario),
    )
    return await runner.run()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--journal", type=Path, required=True)
    parser.add_argument(
        "--scenario",
        choices=(
            "success", "unknown-rank", "join-rejected",
            "cleanup-failed", "verification-failed",
            "timeout", "exception",
        ),
        default="success",
    )
    args = parser.parse_args()
    if not args.journal.is_absolute():
        parser.error("--journal must be an absolute path")
    if args.journal.resolve(strict=False).is_relative_to(
        Path(__file__).resolve().parent
    ):
        parser.error("Journal cannot be stored inside code repository")
    try:
        result = asyncio.run(simulate(args.journal, args.scenario))
        print(json.dumps(result, ensure_ascii=False, sort_keys=True))
        return 0 if result["cleanup_verified"] else 2
    except (GateBlocked, TimeoutError, RuntimeError, ValueError) as exc:
        print("OFFLINE_FIXTURE_BLOCKED:", type(exc).__name__)
        return 2


if __name__ == "__main__":
    sys.exit(main())
