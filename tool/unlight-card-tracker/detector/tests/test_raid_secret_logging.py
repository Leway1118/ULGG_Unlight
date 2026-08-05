from __future__ import annotations

import io
import unittest
from contextlib import redirect_stdout
from unittest import mock

from raid_bot.notifications.discord import DiscordWebhook


class RaidSecretLoggingTests(unittest.TestCase):
    def test_discord_exception_does_not_log_webhook(self) -> None:
        secret = "https://example.invalid/private-webhook-token"
        webhook = DiscordWebhook(secret)
        output = io.StringIO()

        with mock.patch(
            "raid_bot.notifications.discord.requests.post",
            side_effect=RuntimeError(secret),
        ), redirect_stdout(output):
            self.assertFalse(webhook.send("test"))

        self.assertNotIn(secret, output.getvalue())
        self.assertIn("RuntimeError", output.getvalue())


if __name__ == "__main__":
    unittest.main()
