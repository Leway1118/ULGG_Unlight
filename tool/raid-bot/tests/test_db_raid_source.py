from __future__ import annotations

import json
import unittest

from raid_bot.infrastructure.db_raid_source import (
    ALLOWED_OUTBOUND_EVENTS,
    DbRaidSource,
    normalize_db_raid,
)


class FakeWebSocket:
    def __init__(self) -> None:
        self.sent: list[dict] = []

    async def send(self, message: str) -> None:
        self.sent.append(json.loads(message))


class DbRaidNormalizerTests(unittest.TestCase):
    def test_normalizes_metadata_into_existing_raid_entry(self) -> None:
        raids = normalize_db_raid([[
            {
                "profound_id": "raid-id",
                "profound_founder": "billyzx",
                "profound_mons": "mc1007_02",
                "name_tcn": "黃海",
                "rarity": 1,
                "map": 8,
                "stage": 2,
                "hp": 6000,
                "hp_max": 6000,
                "state": [
                    {"type": "defD9", "turn": 2_000_000},
                    {"type": "curse", "turn": 9},
                ],
                "points": [{"name": "one"}],
            },
        ]])

        self.assertEqual(len(raids), 1)
        raid = raids[0]
        self.assertEqual(raid.profound_id, "raid-id")
        self.assertEqual(raid.founder, "billyzx")
        self.assertEqual(raid.monster_code, "mc1007_02")
        self.assertEqual(raid.boss_name, "黃海")
        self.assertEqual(raid.rarity, 1)
        self.assertEqual(raid.map_id, 8)
        self.assertEqual(raid.stage_id, 2)
        self.assertEqual((raid.hp, raid.hp_max), (6000, 6000))
        self.assertEqual(raid.participant_count, 1)
        self.assertEqual(raid.statuses[0].base_type, "defD")
        self.assertEqual(raid.statuses[0].level, 9)
        self.assertEqual(raid.statuses[1].value, 9)
        self.assertEqual(raid.source, "db_raid")

    def test_malformed_row_does_not_discard_valid_rows(self) -> None:
        raids = normalize_db_raid([[
            "bad-row",
            {"profound_id": "valid", "hp": 1, "hp_max": 2},
            {"hp": 3, "hp_max": 4},
        ]])

        self.assertEqual([raid.profound_id for raid in raids], ["valid"])


class DbRaidSourceProtocolTests(unittest.IsolatedAsyncioTestCase):
    async def test_source_sends_only_read_only_events(self) -> None:
        source = DbRaidSource(
            endpoint="wss://example.invalid/",
            owner_id="private-owner",
        )
        websocket = FakeWebSocket()

        await source._emit(websocket, "register", [source.owner_id])
        await source._emit(websocket, "db_raid", [source.owner_id])

        self.assertEqual(
            [message["event"] for message in websocket.sent],
            ["register", "db_raid"],
        )
        self.assertEqual(websocket.sent[1]["args"], ["private-owner"])
        self.assertTrue(
            {message["event"] for message in websocket.sent}
            <= ALLOWED_OUTBOUND_EVENTS
        )
        with self.assertRaises(ValueError):
            await source._emit(websocket, "raid_code_input", ["forbidden"])
        with self.assertRaises(ValueError):
            await source._emit(websocket, "db_raid_delete", ["forbidden"])

    async def test_db_raid_event_produces_snapshot(self) -> None:
        source = DbRaidSource(
            endpoint="wss://example.invalid/",
            owner_id="private-owner",
        )
        message = json.dumps({
            "event": "db_raid",
            "args": [[{
                "profound_id": "raid-id",
                "stage": 2,
                "rarity": 1,
            }]],
        })

        self.assertTrue(await source.process_message(message))
        snapshot = source._queue.get_nowait()
        self.assertEqual(snapshot.source, "db_raid")
        self.assertEqual(snapshot.raids[0].stage_id, 2)

    async def test_malformed_event_is_isolated(self) -> None:
        source = DbRaidSource(
            endpoint="wss://example.invalid/",
            owner_id="private-owner",
        )

        self.assertFalse(await source.process_message("not-json"))
        self.assertEqual(source.last_error, "malformed db_raid event")


if __name__ == "__main__":
    unittest.main()
