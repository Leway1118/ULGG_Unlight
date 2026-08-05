from __future__ import annotations

from ..formatter.raid_text import (
    format_raid_list,
)

from .discord import (
    DiscordWebhook,
)


class RaidNotifier:
    """
    Raid 通知管理

    負責：
    Raid資料
        ↓
    格式化
        ↓
    發送
    """


    def __init__(
        self,
        webhook: DiscordWebhook,
    ) -> None:

        self.webhook = webhook



    def notify_snapshot(
        self,
        raids,
    ) -> bool:

        text = format_raid_list(
            list(raids)
        )


        return self.webhook.send(
            text
        )
        
    def notify_text(
        self,
        text: str,
    ) -> bool:

        return self.webhook.send(
            text
        )