"""Offline Protocol 1.6 handshake regression tests: never make a network connection."""
from __future__ import annotations

import asyncio
import json
import unittest

from raid_bot.infrastructure.public_raid_enrichment_client import (
    PublicRaidEnrichmentClient,
)


class OfflineSocket:
    def __init__(self, incoming: list[dict]):
        self.incoming = asyncio.Queue()
        for payload in incoming:
            self.incoming.put_nowait(json.dumps(payload))
        self.sent: list[dict] = []

    async def send(self, text: str):
        self.sent.append(json.loads(text))

    async def recv(self):
        return await self.incoming.get()


def event(name: str, args):
    return {"event": name, "args": args, "meta": {"id": "fixture"}}


def offline_client():
    def no_network(*args, **kwargs):
        raise AssertionError("offline test must not open any socket")
    return PublicRaidEnrichmentClient(
        "wss://example.invalid",
        "fixture-only",
        timeout_seconds=0.05,
        connect_factory=no_network,
        clock_ms=lambda: 123,
    )


class Protocol16HandshakeTests(unittest.IsolatedAsyncioTestCase):
    async def check_accepted(self, accepted, expected):
        sock = OfflineSocket([
            event("__handshake_s", [accepted]),
            event("__connected", []),
        ])
        result, _ = await offline_client()._wait_for(
            sock, frozenset({"__connected"})
        )
        self.assertEqual(result, "__connected")
        handshakes = [
            item for item in sock.sent
            if item.get("event") == "__handshake_c"
        ]
        self.assertEqual(len(handshakes), 1)
        self.assertEqual(handshakes[0]["args"], [expected, None])
        self.assertEqual(
            [item["event"] for item in sock.sent],
            ["__handshake_c"],
        )

    async def test_server_advertises_protocol_16(self):
        await self.check_accepted(["1.6"], "1.6")

    async def test_patch_version_is_used_only_if_server_advertises_it(self):
        await self.check_accepted(["1.6.0"], "1.6.0")

    async def test_legacy_14_is_supported(self):
        await self.check_accepted(["1.4"], "1.4")

    async def test_choose_highest_supported_not_list_order(self):
        await self.check_accepted(["1.6", "1.4"], "1.6")
        await self.check_accepted(["1.4", "1.6"], "1.6")

    async def test_rejected_handshake_does_not_register_or_join_in_enrich(self):
        sock = OfflineSocket([
            event("__handshake_s", [["1.7"]]),
            event("__connected", []),
        ])

        class FakeConnection:
            async def __aenter__(self):
                return sock

            async def __aexit__(self, *_args):
                return None

        client = PublicRaidEnrichmentClient(
            "wss://example.invalid",
            "fixture-only",
            timeout_seconds=0.05,
            register_settle_seconds=0,
            delete_settle_seconds=0,
            connect_factory=lambda *args, **kwargs: FakeConnection(),
        )
        result = await client.enrich("fixture-raid")
        self.assertFalse(result.ok)
        self.assertFalse(result.cleanup_attempted)
        self.assertEqual(sock.sent, [])

    async def test_connected_without_handshake_is_rejected(self):
        sock = OfflineSocket([event("__connected", [])])
        with self.assertRaises(ValueError):
            await offline_client()._wait_for(
                sock, frozenset({"__connected"})
            )
        self.assertEqual(sock.sent, [])

    async def test_reject_unsupported_without_handshake_or_gameplay(self):
        for advertised in (
            [["1.7"]],
            [["1.2"]],
            [["v1.6"]],
            [["1.6-malformed"]],
            [[1.6]],
            [[]],
            [],
            ["1.6"],
            {"accepted": ["1.6"]},
        ):
            with self.subTest(advertised=advertised):
                sock = OfflineSocket([
                    event("__handshake_s", advertised),
                    event("__connected", []),
                ])
                with self.assertRaises(ValueError):
                    await offline_client()._wait_for(
                        sock, frozenset({"__connected"})
                    )
                self.assertEqual(
                    sock.sent, [],
                    "must not emit any outbound event on invalid handshake",
                )


if __name__ == "__main__":
    unittest.main()
