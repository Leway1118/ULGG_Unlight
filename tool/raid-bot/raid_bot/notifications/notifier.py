from __future__ import annotations

from pathlib import Path

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
        *,
        raid4_role_id: str = "",
    ) -> None:

        self.webhook = webhook
        self.raid4_role_id = raid4_role_id.strip()

        # RAID_DISCORD_PUSH_PAUSE_SENTINEL_V1
        self._discord_pause_file = (
            Path(__file__).resolve().parents[2]
            / "data"
            / "discord_push.paused"
        )


    def discord_push_paused(self) -> bool:
        return self._discord_pause_file.exists()



    def notify_snapshot(
        self,
        raids,
    ) -> bool:

        text = format_raid_list(
            list(raids)
        )


        if self.discord_push_paused():
            print(
                "[DISCORD PAUSED] snapshot suppressed"
            )
            return True

        return self.webhook.send(
            text
        )
        
    def notify_text(
        self,
        text: str,
        *,
        mention_raid4: bool = False,
    ) -> bool:

        allowed_role_ids: tuple[str, ...] = ()
        if mention_raid4 and self.raid4_role_id:
            text = f"{text}\n<@&{self.raid4_role_id}>"
            allowed_role_ids = (self.raid4_role_id,)

        if self.discord_push_paused():
            print(
                "[DISCORD PAUSED] text suppressed"
            )
            return True

        return self.webhook.send(
            text,
            allowed_role_ids=allowed_role_ids,
        )

    # RAID_NOTIFIER_MESSAGE_EDIT_V1
    def notify_text_with_id(
        self,
        text: str,
        *,
        mention_raid4: bool = False,
    ) -> str | None:
        allowed_role_ids: tuple[str, ...] = ()

        if mention_raid4 and self.raid4_role_id:
            text = (
                f"{text}\n"
                f"<@&{self.raid4_role_id}>"
            )
            allowed_role_ids = (
                self.raid4_role_id,
            )

        if self.discord_push_paused():
            print(
                "[DISCORD PAUSED] new message suppressed"
            )
            return None

        return self.webhook.send_with_id(
            text,
            allowed_role_ids=allowed_role_ids,
        )


    def edit_text(
        self,
        message_id: str,
        text: str,
    ) -> bool:

        if self.discord_push_paused():
            print(
                "[DISCORD PAUSED] edit suppressed"
            )
            return False

        return self.webhook.edit(
            message_id,
            text,
        )

