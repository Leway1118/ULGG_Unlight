from __future__ import annotations

import requests


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
    ) -> bool:
        """
        發送 Discord 訊息
        """

        payload = {
            "content": content
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
