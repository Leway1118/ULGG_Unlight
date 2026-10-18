from __future__ import annotations

import time
import tempfile
import unittest
from dataclasses import replace
from pathlib import Path

from fastapi.testclient import TestClient

from raid_bot.api.server import create_app
from raid_bot.domain.models import RawRaidSnapshot
from raid_bot.domain.raid_event import RaidEventType
from raid_bot.domain.raid_lifecycle import RaidLifecycleStatus
from raid_bot.infrastructure.treasure_level_cache import TreasureLevelCache
from raid_bot.services.raid_lifecycle_tracker import RaidLifecycleTracker
from raid_bot.services.raid_service import RaidService
from raid_bot.services.treasure_level_enricher import TreasureLevelEnricher
from tests.raid_bot_test_support import FakeRaidSource, raid, snapshot


def lifecycle_snapshot(
    *items,
    captured_at: float,
    connected: bool = True,
    error: str | None = None,
    source: str = "websocket",
) -> RawRaidSnapshot:
    return RawRaidSnapshot(
        captured_at=captured_at,
        source=source,
        raids=tuple(items),
        connected=connected,
        error=error,
    )


class RaidLifecycleTrackerTests(unittest.TestCase):
    def test_first_snapshot_creates_active_record(self) -> None:
        tracker = RaidLifecycleTracker(clock=lambda: 100)
        item = raid("one")

        self.assertTrue(tracker.observe_snapshot(
            lifecycle_snapshot(item, captured_at=100)
        ))
        view = tracker.view(now=100)

        self.assertEqual([record.raid for record in view.active], [item])
        self.assertEqual(view.active[0].last_seen_at, 100)
        self.assertEqual(view.recent_ended, ())

    def test_next_snapshot_updates_hp_and_participant_count(self) -> None:
        tracker = RaidLifecycleTracker()
        tracker.observe_snapshot(lifecycle_snapshot(
            raid("one", hp=6000, members=1),
            captured_at=100,
        ))
        updated = raid("one", hp=4321, members=12)

        tracker.observe_snapshot(lifecycle_snapshot(
            updated,
            captured_at=110,
        ))
        record = tracker.view(now=110).active[0]

        self.assertEqual(record.raid.hp, 4321)
        self.assertEqual(record.raid.participant_count, 12)
        self.assertEqual(record.last_seen_at, 110)

    def test_missing_live_raid_becomes_ended(self) -> None:
        tracker = RaidLifecycleTracker()
        item = replace(raid("one", hp=123), expires_at=999_000)
        tracker.observe_snapshot(lifecycle_snapshot(item, captured_at=100))

        tracker.observe_snapshot(lifecycle_snapshot(captured_at=110))
        ended = tracker.view(now=110).recent_ended[0]

        self.assertEqual(ended.status, RaidLifecycleStatus.ENDED)
        self.assertEqual(ended.raid.hp, 123)
        self.assertEqual(ended.last_seen_at, 100)
        self.assertEqual(ended.ended_at, 110)

    def test_last_zero_hp_becomes_defeated(self) -> None:
        tracker = RaidLifecycleTracker()
        tracker.observe_snapshot(lifecycle_snapshot(
            raid("one", hp=0),
            captured_at=100,
        ))
        self.assertEqual(
            tracker.active_status(tracker.view(now=100).active[0].raid),
            RaidLifecycleStatus.DEFEATED,
        )

        tracker.observe_snapshot(lifecycle_snapshot(captured_at=101))

        self.assertEqual(
            tracker.view(now=101).recent_ended[0].status,
            RaidLifecycleStatus.DEFEATED,
        )

    def test_missing_raid_after_expiry_becomes_expired(self) -> None:
        tracker = RaidLifecycleTracker()
        item = replace(raid("one", hp=1), expires_at=100_500)
        tracker.observe_snapshot(lifecycle_snapshot(item, captured_at=100))

        tracker.observe_snapshot(lifecycle_snapshot(captured_at=101))

        self.assertEqual(
            tracker.view(now=101).recent_ended[0].status,
            RaidLifecycleStatus.EXPIRED,
        )

    def test_recent_ended_is_pruned_after_120_seconds(self) -> None:
        tracker = RaidLifecycleTracker(ended_ttl_seconds=120)
        tracker.observe_snapshot(lifecycle_snapshot(
            replace(raid("one"), expires_at=999_000),
            captured_at=10,
        ))
        tracker.observe_snapshot(lifecycle_snapshot(captured_at=20))

        self.assertEqual(len(tracker.view(now=139.999).recent_ended), 1)
        self.assertEqual(tracker.view(now=140).recent_ended, ())

    def test_reappearing_raid_removes_recent_ended_record(self) -> None:
        tracker = RaidLifecycleTracker()
        original = replace(raid("one", hp=500), expires_at=999_000)
        tracker.observe_snapshot(lifecycle_snapshot(original, captured_at=10))
        tracker.observe_snapshot(lifecycle_snapshot(captured_at=20))

        reappeared = replace(original, hp=400)
        tracker.observe_snapshot(lifecycle_snapshot(
            reappeared,
            captured_at=30,
        ))
        view = tracker.view(now=30)

        self.assertEqual(view.recent_ended, ())
        self.assertEqual(view.active[0].raid.hp, 400)

    def test_reappearing_raid_does_not_become_new_again(self) -> None:
        item = replace(raid("one"), expires_at=999_000)
        service = RaidService(
            FakeRaidSource([]),
            source_name="websocket",
        )
        service._events_from_snapshot(lifecycle_snapshot(
            item,
            captured_at=10,
        ))
        service._events_from_snapshot(lifecycle_snapshot(captured_at=20))

        events = service._events_from_snapshot(lifecycle_snapshot(
            item,
            captured_at=30,
        ))

        self.assertEqual(events[0].event_type, RaidEventType.UPDATED)
        self.assertFalse(events[0].is_new)

    def test_disconnected_error_and_invalid_snapshots_do_not_remove(self) -> None:
        tracker = RaidLifecycleTracker()
        item = raid("one")
        tracker.observe_snapshot(lifecycle_snapshot(item, captured_at=10))

        candidates = (
            lifecycle_snapshot(captured_at=20, connected=False),
            lifecycle_snapshot(captured_at=20, error="parse error"),
            lifecycle_snapshot(captured_at=20, source="db_raid"),
            lifecycle_snapshot(item, item, captured_at=20),
        )
        for candidate in candidates:
            with self.subTest(candidate=candidate):
                self.assertFalse(tracker.observe_snapshot(candidate))
                view = tracker.view(now=20)
                self.assertEqual(len(view.active), 1)
                self.assertEqual(view.recent_ended, ())
                self.assertEqual(view.updated_at, 10)

    def test_only_missing_raid_is_removed(self) -> None:
        tracker = RaidLifecycleTracker()
        first = replace(raid("first"), expires_at=999_000)
        second = raid("second")
        tracker.observe_snapshot(lifecycle_snapshot(
            first,
            second,
            captured_at=10,
        ))

        tracker.observe_snapshot(lifecycle_snapshot(
            second,
            captured_at=20,
        ))
        view = tracker.view(now=20)

        self.assertEqual([record.raid.profound_id for record in view.active], ["second"])
        self.assertEqual(
            [record.raid.profound_id for record in view.recent_ended],
            ["first"],
        )

    def test_hp_ratio_uses_existing_raid_entry_rule(self) -> None:
        tracker = RaidLifecycleTracker()
        tracker.observe_snapshot(lifecycle_snapshot(
            raid("one", hp=1200, hp_max=6000),
            captured_at=10,
        ))

        self.assertEqual(tracker.view(now=10).active[0].raid.hp_ratio, 0.2)


class ObservedRaidsApiTests(unittest.TestCase):
    def test_observed_raids_response_schema(self) -> None:
        now = time.time()
        item = replace(
            raid("one", hp=1200, hp_max=6000, members=12),
            expires_at=int((now + 300) * 1000),
        )
        service = RaidService(
            FakeRaidSource([snapshot(item)]),
            source_name="websocket",
        )
        service.process_snapshot(lifecycle_snapshot(item, captured_at=now))

        with TestClient(create_app(service)) as client:
            response = client.get("/api/observed-raids")

        self.assertEqual(response.status_code, 200)
        payload = response.json()
        self.assertEqual(payload["source"], "websocket")
        self.assertTrue(payload["connected"])
        self.assertEqual(payload["ended_ttl_seconds"], 120)
        self.assertEqual(payload["active_count"], 1)
        self.assertEqual(payload["recent_ended_count"], 0)
        self.assertEqual(payload["updated_at"], now)
        self.assertEqual(payload["raids"], [{
            "raid_id": "one",
            "status": "active",
            "founder": "Founder",
            "boss": "靈龜",
            "monster_code": "mc1008_02",
            "hp": 1200,
            "hp_max": 6000,
            "hp_ratio": 0.2,
            "participant_count": 12,
            "member_limit": 100,
            "expires_at": item.expires_at,
            "last_seen_at": now,
            "ended_at": None,
            "state_raw": [],
            "state_labels": [],
        }])

    def test_observed_raids_hydrates_cached_status_without_client_call(self) -> None:
        class ForbiddenClient:
            def __init__(self) -> None:
                self.calls = 0

            async def enrich(self, _prf_code):
                self.calls += 1
                raise AssertionError("API must not trigger enrichment")

        now = time.time()
        now_ms = int(now * 1000)
        client = ForbiddenClient()
        with tempfile.TemporaryDirectory() as directory:
            cache = TreasureLevelCache(Path(directory) / "cache.json")
            cache.put("cached", {
                "treasure_level": 2091,
                "state": [
                    {"type": "bers", "turn": now_ms + 60_000},
                    {"type": "defD9", "turn": now_ms + 60_000},
                    {"type": "huin", "turn": now_ms + 60_000},
                    {"type": "poison", "turn": now_ms - 1},
                    {"type": "unknown7", "turn": now_ms + 60_000},
                ],
            })
            service = RaidService(
                FakeRaidSource([]),
                source_name="websocket",
                treasure_enricher=TreasureLevelEnricher(client, cache),
            )
            service.process_snapshot(lifecycle_snapshot(
                raid("cached"),
                captured_at=now,
            ))

            with TestClient(create_app(service)) as api_client:
                payload = api_client.get("/api/observed-raids").json()

        observed = payload["raids"][0]
        self.assertEqual(client.calls, 0)
        self.assertEqual(
            [item["type"] for item in observed["state_raw"]],
            ["bers", "defD9", "huin", "poison", "unknown7"],
        )
        self.assertEqual(observed["state_raw"][1], {
            "type": "defD9",
            "base_type": "defD",
            "level": 9,
            "value": None,
            "expires_at": now_ms + 60_000,
        })
        self.assertEqual(observed["state_labels"], ["封", "狂", "防-9"])

    def test_observed_raids_cache_miss_has_empty_status_without_client_call(self) -> None:
        class ForbiddenClient:
            def __init__(self) -> None:
                self.calls = 0

            async def enrich(self, _prf_code):
                self.calls += 1
                raise AssertionError("API must not trigger enrichment")

        client = ForbiddenClient()
        with tempfile.TemporaryDirectory() as directory:
            service = RaidService(
                FakeRaidSource([]),
                source_name="websocket",
                treasure_enricher=TreasureLevelEnricher(
                    client,
                    TreasureLevelCache(Path(directory) / "cache.json"),
                ),
            )
            service.process_snapshot(lifecycle_snapshot(
                raid("not-cached"),
                captured_at=time.time(),
            ))

            with TestClient(create_app(service)) as api_client:
                payload = api_client.get("/api/observed-raids").json()

        self.assertEqual(client.calls, 0)
        self.assertEqual(payload["raids"][0]["state_raw"], [])
        self.assertEqual(payload["raids"][0]["state_labels"], [])

    def test_observed_raids_includes_recent_ended_record(self) -> None:
        now = time.time()
        active = raid("active")
        ending = replace(
            raid("ending", hp=321),
            expires_at=int((now + 300) * 1000),
        )
        service = RaidService(
            FakeRaidSource([snapshot(active)]),
            source_name="websocket",
        )
        service.process_snapshot(lifecycle_snapshot(
            active,
            ending,
            captured_at=now,
        ))
        service.process_snapshot(lifecycle_snapshot(
            active,
            captured_at=now + 1,
        ))

        with TestClient(create_app(service)) as client:
            payload = client.get("/api/observed-raids").json()

        self.assertEqual(payload["active_count"], 1)
        self.assertEqual(payload["recent_ended_count"], 1)
        ended = next(
            item for item in payload["raids"]
            if item["raid_id"] == "ending"
        )
        self.assertEqual(ended["status"], "ended")
        self.assertEqual(ended["last_seen_at"], now)
        self.assertEqual(ended["ended_at"], now + 1)


if __name__ == "__main__":
    unittest.main()
