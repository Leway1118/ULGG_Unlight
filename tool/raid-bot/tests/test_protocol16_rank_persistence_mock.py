"""Offline verification of rank persistence SQL. No DB credentials or connections."""
from __future__ import annotations

import unittest
from unittest.mock import patch

from raid_bot.infrastructure.raid_history_repository import RaidHistoryRepository


class FakeCursor:
    def __init__(self, raid_exists=True):
        self.raid_exists = raid_exists
        self.sql = []
        self.writes = []
        self.rowcount = 0

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def execute(self, sql, params=()):
        self.sql.append((sql, params))

    def fetchone(self):
        return (1,) if self.raid_exists else None

    def executemany(self, sql, rows):
        self.writes.append((sql, list(rows)))
        self.rowcount = len(rows)


class FakeConn:
    def __init__(self, raid_exists=True):
        self.cur = FakeCursor(raid_exists=raid_exists)
        self.commits = 0
        self.rollbacks = 0
        self.closed = False

    def cursor(self):
        return self.cur

    def commit(self):
        self.commits += 1

    def rollback(self):
        self.rollbacks += 1

    def close(self):
        self.closed = True


class RankPersistenceMockTests(unittest.TestCase):
    def setUp(self):
        self.repo = RaidHistoryRepository(
            host="unused.invalid",
            user="dummy",
            password="dummy",
            database="fake-only",
        )

    def test_valid_rank_rows_preserve_known_damage_fields(self):
        conn = FakeConn()
        with patch.object(self.repo, "_connect", return_value=conn) as connect:
            result = self.repo.upsert_protocol14_rank(
                raid_id="raid-A",
                players=[
                    {"rank": 1, "name": "Alice", "level": 35, "point": 123},
                    {"rank": 2, "name": "Bob", "level": 12, "point": 50},
                ],
                source="protocol14_db_raid_rank",
            )
        connect.assert_called_once()
        self.assertTrue(result["updated"])
        self.assertEqual(result["players"], 2)
        self.assertEqual(conn.commits, 1)
        self.assertEqual(conn.rollbacks, 0)
        self.assertTrue(conn.closed)
        sql, rows = conn.cur.writes[0]
        self.assertEqual([(r[0], r[1], r[2], r[3], r[4]) for r in rows], [
            ("raid-A", "Alice", 35, 1, 123),
            ("raid-A", "Bob", 12, 2, 50),
        ])
        # damage, damage_known, damage_source are INSERT placeholders only;
        # UPDATE must not overwrite any earlier observed damage.
        self.assertEqual([row[5:8] for row in rows], [(None, 0, None)] * 2)
        # Ignore a trailing explanatory SQL comment; inspect assignments.
        update_sql = sql.split("ON DUPLICATE KEY UPDATE", 1)[1].split("/*", 1)[0]
        for key in ("damage", "damage_known", "damage_source"):
            self.assertNotIn(key, update_sql)
        self.assertIn("rank_source", update_sql)

    def test_empty_rank_skips_database(self):
        with patch.object(self.repo, "_connect", side_effect=AssertionError("DB called")):
            result = self.repo.upsert_protocol14_rank(raid_id="raid-B", players=[])
        self.assertFalse(result["updated"])
        self.assertEqual(result["reason"], "players_empty")

    def test_missing_raid_prevents_orphaned_player_rows(self):
        conn = FakeConn(raid_exists=False)
        with patch.object(self.repo, "_connect", return_value=conn):
            result = self.repo.upsert_protocol14_rank(
                raid_id="missing-raid",
                players=[{"rank": 1, "name": "Alice", "level": 35, "point": 123}],
            )
        self.assertEqual(result["reason"], "raid_history_missing")
        self.assertEqual(conn.cur.writes, [])
        self.assertEqual(conn.commits, 0)
        self.assertTrue(conn.closed)


if __name__ == "__main__":
    unittest.main()
