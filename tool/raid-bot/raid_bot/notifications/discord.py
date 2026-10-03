from __future__ import annotations

from collections.abc import Iterable

import requests


# RAID_DISCORD_MESSAGE_EDIT_V1

class DiscordWebhook:
    """
    Discord Webhook Client

    第一版：
    - 傳送文字訊息
    - 不保存狀態
    """


    def __init__(
        self,
        webhook_url: str,
    ) -> None:

        self.webhook_url = webhook_url



    def send(
        self,
        content: str,
        *,
        allowed_role_ids: Iterable[str] = (),
    ) -> bool:
        """
        發送 Discord 訊息
        """

        roles = list(dict.fromkeys(
            role_id.strip()
            for role_id in allowed_role_ids
            if role_id and role_id.strip()
        ))
        payload = {
            "content": content,
            "allowed_mentions": {
                "roles": roles,
            },
        }


        try:

            response = requests.post(
                self.webhook_url,
                json=payload,
                timeout=10,
                headers={
                    "User-Agent":
                        "ULGG-Raid-Bot/0.1",
                },
            )


            if response.status_code in (
                200,
                204,
            ):
                return True


            print(
                f"[Discord HTTP Error] {response.status_code}"
            )

            print(
                response.text
            )

            return False


        except Exception as exc:

            print(
                f"[Discord Exception] {type(exc).__name__}"
            )

            return False

    def send_with_id(
        self,
        content: str,
        *,
        allowed_role_ids: Iterable[str] = (),
    ) -> str | None:
        """
        Send a Discord webhook message with wait=true so Discord
        returns the created message ID.
        """
        roles = list(dict.fromkeys(
            role_id.strip()
            for role_id in allowed_role_ids
            if role_id and role_id.strip()
        ))

        payload = {
            "content": content,
            "allowed_mentions": {
                "roles": roles,
            },
        }

        try:
            response = requests.post(
                self.webhook_url,
                params={
                    "wait": "true",
                },
                json=payload,
                timeout=10,
                headers={
                    "User-Agent":
                        "ULGG-Raid-Bot/0.1",
                },
            )

            if response.status_code == 200:
                try:
                    body = response.json()
                except ValueError:
                    body = {}

                message_id = body.get("id")

                if message_id:
                    return str(message_id)

                print(
                    "[Discord Error] send succeeded "
                    "but message id missing"
                )
                return None

            print(
                f"[Discord HTTP Error] {response.status_code}"
            )
            print(response.text)
            return None

        except Exception as exc:
            print(
                f"[Discord Exception] {type(exc).__name__}"
            )
            return None


    def edit(
        self,
        message_id: str,
        content: str,
    ) -> bool:
        """
        Edit a webhook-owned Discord message.
        """
        message_id = str(message_id).strip()

        if not message_id:
            return False

        edit_url = (
            self.webhook_url.rstrip("/")
            + "/messages/"
            + message_id
        )

        payload = {
            "content": content,

            # Editing an old Raid IV notification must not ping
            # the role for a second time.
            "allowed_mentions": {
                "parse": [],
                "roles": [],
            },
        }

        try:
            response = requests.patch(
                edit_url,
                json=payload,
                timeout=10,
                headers={
                    "User-Agent":
                        "ULGG-Raid-Bot/0.1",
                },
            )

            if response.status_code == 200:
                return True

            print(
                "[Discord Edit HTTP Error] "
                f"{response.status_code}"
            )
            print(response.text)
            return False

        except Exception as exc:
            print(
                "[Discord Edit Exception] "
                f"{type(exc).__name__}"
            )
            return False

