"""Offline safety-gate regression: reject ambiguous cleanup and unknown POINT.

All fixtures are synthetic. The network connect factory is local-only and no
authentication file, game server, real database or Raid account is accessed.
"""
from __future__ import annotations

import unittest
from unittest.mock import patch

from raid_bot.infrastructure.public_raid_enrichment_client import (
    profound_id_is_absent,
)
from raid_bot.infrastructure.raid_history_repository import RaidHistoryRepository
from raid_player_collector_daemon import _parse_rank
from tests.test_protocol16_collector_flow import (
    FakeSocket,
    baseline,
    collector,
    evt,
    joined_row,
)


class StrictCleanupGateTests(unittest.IsolatedAsyncioTestCase):
    async def test_prejoin_malformed_snapshot_never_joins(self):
        sock = FakeSocket(baseline()[:-1] + [
            evt("db_raid", {"error": "malformed"})
        ])
        result = await collector(sock, timeout=0.12).enrich("fake-code")
        self.assertFalse(result.ok)
        self.assertNotIn(
            "raid_code_input", [item["event"] for item in sock.sent]
        )
        self.assertNotIn("raid_delete", [item["event"] for item in sock.sent])

    async def test_cleanup_malformed_snapshot_does_not_claim_absent(self):
        sock = FakeSocket(baseline() + [
            evt("raid_code_input", [True]),
            evt("db_raid", [[joined_row()]]),
            evt("raid_delete", [True]),
            evt("db_raid", {"error": "not a valid list"}),
        ])
        result = await collector(sock, timeout=0.12).enrich("fake-code")
        self.assertFalse(result.ok)
        self.assertFalse(result.cleanup_confirmed)

    async def test_strict_absence_requires_valid_snapshot(self):
        for bad in (
            None, {}, {"error": "offline"}, [], [None],
            [[{"not_profound_id": "unknown"}]],
        ):
            with self.subTest(bad=bad):
                self.assertFalse(profound_id_is_absent(bad, "target"))
        self.assertTrue(profound_id_is_absent([[]], "target"))
        self.assertFalse(
            profound_id_is_absent([[{"profound_id": "target"}]], "target")
        )
        self.assertTrue(
            profound_id_is_absent([[{"profound_id": "other"}]], "target")
        )


class StrictPointGateTests(unittest.TestCase):
    def test_missing_or_invalid_point_is_unknown(self):
        for bad in (
            [{"player_name": "Alice"}],
            [{"player_name": "Alice", "point": None}],
            [{"player_name": "Alice", "point": "not-a-number"}],
            [{"player_name": "Alice", "point": -3}],
            [{"player_name": "Alice", "point": True}],
            [{"player_name": "Alice", "point": 5}, {"player_name": "Bob"}],
            [{"point": 10}],
            [],
        ):
            with self.subTest(bad=bad):
                self.assertIsNone(_parse_rank(bad))

    def test_exact_zero_is_valid_point(self):
        self.assertEqual(
            _parse_rank([{"player_name": "Alice", "point": 0}]),
            [{"rank": 1, "level": None, "name": "Alice", "point": 0}],
        )

    def test_rank_repository_rejects_invalid_point_before_connect(self):
        repo = RaidHistoryRepository(
            host="invalid.example", user="dummy", password="dummy",
            database="mock-only",
        )
        for bad in (None, "NaN", -3, True):
            with self.subTest(bad=bad):
                with patch.object(
                    repo, "_connect",
                    side_effect=AssertionError("no database permitted"),
                ):
                    result = repo.upsert_protocol14_rank(
                        raid_id="fake-raid",
                        players=[{"name": "Alice", "point": bad}],
                    )
                self.assertFalse(result["updated"])
                self.assertEqual(result.get("reason"), "point_invalid")


if __name__ == "__main__":
    unittest.main()
