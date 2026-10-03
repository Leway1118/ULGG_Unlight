from __future__ import annotations

import unittest

import requests

from raid_bot.infrastructure.treasure_reward_client import TreasureRewardClient


class FakeResponse:
    def __init__(self, payload, status_code: int = 200) -> None:
        self._payload = payload
        self.status_code = status_code

    def json(self):
        return self._payload


class TreasureRewardClientTests(unittest.TestCase):
    def test_queries_missing_levels_in_one_batch_and_filters_schema(self) -> None:
        calls = []

        def fake_post(url, **kwargs):
            calls.append((url, kwargs))
            return FakeResponse([
                {
                    "treasureLevel": 2161,
                    "bossDisplayName": "M10 龍鯰",
                    "rarity": 1,
                    "mapLevel": 5,
                    "whirlpoolTier": "渦II/III",
                    "discovery": [],
                    "participation": [],
                    "ranking": [{
                        "itemName": "死亡碎片",
                        "quantity": 2,
                        "rankMin": 1,
                        "rankMax": 10,
                        "unexpected": "discard-me",
                    }],
                    "defeat": [],
                    "unexpected": "discard-me",
                },
                {"treasureLevel": 9999},
            ])

        client = TreasureRewardClient(
            endpoint="https://example.invalid/query",
            timeout_seconds=3,
            post=fake_post,
        )
        result = client.query([2162, 2161, 2161], locale="zh-TW")

        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0][1]["json"], {
            "levels": [2161, 2162],
            "locale": "zh-TW",
        })
        self.assertEqual(calls[0][1]["timeout"], 3)
        self.assertEqual(set(result), {2161})
        self.assertNotIn("unexpected", result[2161])
        self.assertNotIn("unexpected", result[2161]["ranking"][0])

    def test_timeout_returns_empty_result(self) -> None:
        def fake_post(*args, **kwargs):
            raise requests.Timeout("offline")

        client = TreasureRewardClient(post=fake_post)

        self.assertEqual(client.query([2161]), {})

    def test_http_error_and_malformed_response_return_empty_result(self) -> None:
        error_client = TreasureRewardClient(
            post=lambda *args, **kwargs: FakeResponse([], status_code=503),
        )
        malformed_client = TreasureRewardClient(
            post=lambda *args, **kwargs: FakeResponse({"not": "a list"}),
        )

        self.assertEqual(error_client.query([2161]), {})
        self.assertEqual(malformed_client.query([2161]), {})


if __name__ == "__main__":
    unittest.main()
