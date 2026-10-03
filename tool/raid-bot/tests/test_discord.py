from raid_bot.notifications.discord import (
    DiscordWebhook,
)
from raid_bot.config import DISCORD_WEBHOOK_URL


def main():

    discord = DiscordWebhook(
        DISCORD_WEBHOOK_URL
    )


    success = discord.send(
        """
🌀 Raid List Test

靈龜 🟡🐢
啃食者 🟡🐛☠️
黑死獸 🟡☠️
"""
    )


    print(
        "Success"
        if success
        else
        "Failed"
    )


if __name__ == "__main__":
    main()
