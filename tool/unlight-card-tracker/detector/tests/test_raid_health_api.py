from __future__ import annotations

import unittest

from fastapi.testclient import TestClient

from raid_bot.api.server import create_app
from raid_bot.services.raid_service import RaidService
from tests.raid_bot_test_support import FakeNotifier, FakeRaidSource, raid, snapshot


class RaidHealthApiTests(unittest.IsolatedAsyncioTestCase):
    async def test_health_reports_source_without_secrets(self) -> None:
        service = RaidService(
            FakeRaidSource([snapshot(raid("raid-a"))]),
            notifier=FakeNotifier(),
            source_name="websocket",
        )
        service.process_snapshot(snapshot(raid("raid-a")))
        with TestClient(create_app(service)) as client:
            response = client.get("/api/health")
            raids_response = client.get("/api/raids")
        self.assertEqual(response.status_code, 200)
        result = response.json()

        self.assertEqual(result["source"], "websocket")
        self.assertTrue(result["connected"])
        self.assertEqual(result["raid_count"], 1)
        self.assertIsNotNone(result["last_update"])
        self.assertIsNone(result["last_error"])
        self.assertNotIn("owner", repr(result).lower())
        self.assertNotIn("webhook", repr(result).lower())
        raid_result = raids_response.json()["raids"][0]
        self.assertEqual(raid_result["founder"], "Founder")
        self.assertNotIn("star", raid_result)
        self.assertNotIn("detector", raid_result)
        self.assertNotIn("reward_prediction", raid_result)


if __name__ == "__main__":
    unittest.main()
