"""Protocol 1.6 collector flow tests: synthetic data, no game/network/database."""
from __future__ import annotations

import asyncio
import json
import unittest

from raid_bot.infrastructure.public_raid_enrichment_client import (
    PublicRaidEnrichmentClient,
    find_unique_new_row,
)
from raid_player_collector_daemon import _parse_rank


def evt(event, args):
    return json.dumps({"event": event, "args": args, "meta": {"id": f"fixture-{event}"}})


class FakeSocket:
    def __init__(self, messages):
        self.queue = asyncio.Queue()
        for message in messages:
            self.queue.put_nowait(message)
        self.sent = []

    async def send(self, text):
        self.sent.append(json.loads(text))

    async def recv(self):
        return await self.queue.get()


class Connection:
    def __init__(self, socket):
        self.socket = socket

    async def __aenter__(self):
        return self.socket

    async def __aexit__(self, *args):
        return False


def collector(socket, *, timeout=0.1):
    return PublicRaidEnrichmentClient(
        "wss://example.invalid/",
        "synthetic-worker",
        timeout_seconds=timeout,
        register_settle_seconds=0,
        delete_settle_seconds=0,
        clock_ms=lambda: 1000,
        connect_factory=lambda *args, **kwargs: Connection(socket),
    )


def baseline():
    return [
        evt("__handshake_s", [["1.6"]]),
        evt("__connected", []),
        evt("register", [0]),
        evt("online", []),
        evt("db_raid", [[]]),
    ]


def joined_row():
    return {
        "profound_id": "new-raid",
        "profound_mons": "m001",
        "rank": [
            {"player_name": "Lv.35 Alice", "level": 35, "point": 123},
            {"player_name": "Bob", "level": 12, "point": 50},
        ],
    }


class Protocol16CollectorFlowTests(unittest.IsolatedAsyncioTestCase):
    async def test_successful_rank_capture_requires_verified_cleanup(self):
        sock = FakeSocket(baseline() + [
            evt("raid_code_input", [True]),
            evt("db_raid", [[joined_row()]]),
            evt("raid_delete", [True]),
            evt("db_raid", [[]]),
        ])
        result = await collector(sock).enrich("public-code")
        self.assertTrue(result.ok)
        self.assertEqual([x["event"] for x in sock.sent], [
            "__handshake_c", "register", "online", "db_raid",
            "raid_code_input", "db_raid", "raid_delete", "db_raid",
        ])
        self.assertEqual(sock.sent[0]["args"], ["1.6", None])
        players = _parse_rank(result.metadata["rank"])
        self.assertEqual(players, [
            {"rank": 1, "level": 35, "name": "Alice", "point": 123},
            {"rank": 2, "level": 12, "name": "Bob", "point": 50},
        ])
        self.assertTrue(result.cleanup_confirmed)

    async def test_missing_rank_does_not_create_player_stats(self):
        row = {"profound_id": "new-raid", "profound_mons": "m001"}
        sock = FakeSocket(baseline() + [
            evt("raid_code_input", [True]),
            evt("db_raid", [[row]]),
            evt("raid_delete", [True]),
            evt("db_raid", [[]]),
        ])
        result = await collector(sock).enrich("public-code")
        self.assertTrue(result.ok)
        self.assertIsNone(_parse_rank(result.metadata.get("rank")))

    async def test_join_rejected_never_deletes(self):
        sock = FakeSocket(baseline() + [evt("raid_code_error", [2])])
        result = await collector(sock).enrich("bad-code")
        self.assertEqual(result.status, "join_rejected")
        self.assertNotIn("raid_delete", [x["event"] for x in sock.sent])

    async def test_ambiguous_new_raid_is_not_deleted(self):
        sock = FakeSocket(baseline() + [
            evt("raid_code_input", [True]),
            evt("db_raid", [[{"profound_id": "x"}, {"profound_id": "y"}]]),
            evt("db_raid", [[{"profound_id": "x"}, {"profound_id": "y"}]]),
        ])
        result = await collector(sock, timeout=0.015).enrich("public-code")
        self.assertFalse(result.ok)
        self.assertFalse(result.cleanup_confirmed)
        self.assertNotIn("raid_delete", [x["event"] for x in sock.sent])
        self.assertIsNone(find_unique_new_row(set(), [{"profound_id": "x"}, {"profound_id": "y"}]))

    async def test_cleanup_not_confirmed_fails_closed(self):
        sock = FakeSocket(baseline() + [
            evt("raid_code_input", [True]),
            evt("db_raid", [[joined_row()]]),
            evt("raid_delete", [True]),
            evt("db_raid", [[joined_row()]]),
        ])
        result = await collector(sock, timeout=0.01).enrich("public-code")
        self.assertFalse(result.ok)
        self.assertEqual(result.status, "cleanup_failed")
        self.assertTrue(result.cleanup_attempted)
        self.assertFalse(result.cleanup_confirmed)

    async def test_before_join_db_raid_timeout_never_joins(self):
        sock = FakeSocket(baseline()[:-1])
        result = await collector(sock, timeout=0.01).enrich("public-code")
        self.assertFalse(result.ok)
        sent = [x["event"] for x in sock.sent]
        self.assertIn("db_raid", sent)
        self.assertNotIn("raid_code_input", sent)
        self.assertNotIn("raid_delete", sent)

    async def test_rank_parser_rejects_missing_and_bad_values(self):
        self.assertIsNone(_parse_rank(None))
        self.assertIsNone(_parse_rank({}))
        self.assertEqual(_parse_rank([]), [])
        self.assertEqual(_parse_rank([{"player_name": " "}, {"player_name": "C", "point": "4"}]), [
            {"rank": 2, "level": None, "name": "C", "point": 4},
        ])


if __name__ == "__main__":
    unittest.main()
