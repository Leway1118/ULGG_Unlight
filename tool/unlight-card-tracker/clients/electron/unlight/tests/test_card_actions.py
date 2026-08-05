from __future__ import annotations

import asyncio
import json
import shutil
import subprocess
import unittest

from clients.electron.unlight.auto_player.card_actions import (
    CardActions,
    build_select_card_script,
)


class FakeSniffer:
    def __init__(self, value: str = '{"ok":true}'):
        self.value = value
        self.calls = []

    async def evaluate(self, script, *, await_promise=False):
        self.calls.append(
            {
                "script": script,
                "await_promise": await_promise,
            }
        )
        return self.value, None


class CardActionsTests(unittest.TestCase):
    def test_select_card_awaits_server_confirmation(self):
        sniffer = FakeSniffer()

        result = asyncio.run(CardActions(sniffer).select_card(2))

        self.assertEqual(result, '{"ok":true}')
        self.assertEqual(len(sniffer.calls), 1)
        call = sniffer.calls[0]
        self.assertTrue(call["await_promise"])
        self.assertIn("cardclicked${player}", call["script"])
        self.assertLess(
            call["script"].index("socket.on("),
            call["script"].index('card.emit("pointerdown")'),
        )

    def test_script_uses_original_pointerdown_handler_not_direct_socket_emit(self):
        script = build_select_card_script(4, 25)

        self.assertIn('card.emit("pointerdown")', script)
        self.assertNotIn('socket.emit("card"', script)
        self.assertNotIn('card.emit("pointerup")', script)

    def test_builder_rejects_unsafe_index_and_timeout(self):
        for value in (-1, True, "2"):
            with self.subTest(index=value):
                with self.assertRaises(ValueError):
                    build_select_card_script(value, 25)

        for value in (0, -1, True, "25"):
            with self.subTest(timeout=value):
                with self.assertRaises(ValueError):
                    build_select_card_script(2, value)


@unittest.skipUnless(shutil.which("node"), "Node.js is required")
class CardActionsJavaScriptTests(unittest.TestCase):
    @staticmethod
    def run_script(
        *,
        index: int = 2,
        timeout_ms: int = 50,
        confirmation_javascript: str,
    ) -> dict:
        expression = build_select_card_script(index, timeout_ms).strip()
        if expression.endswith(";"):
            expression = expression[:-1]

        harness = f"""
        const {{ EventEmitter }} = require("events");
        const socket = new EventEmitter();
        socket.off = socket.removeListener.bind(socket);
        const card = new EventEmitter();
        card.input = {{ enabled: true }};
        card.on("pointerdown", () => {{
            card.input.enabled = false;
            {confirmation_javascript}
        }});
        globalThis.game = {{
            scene: {{
                keys: {{
                    MainA: {{
                        PLAYER: "A",
                        socket,
                        arr1: [null, null, [card]]
                    }}
                }}
            }}
        }};
        Promise.resolve({expression})
            .then(value => console.log(value))
            .catch(error => {{
                console.error(error);
                process.exitCode = 1;
            }});
        """
        completed = subprocess.run(
            ["node", "-e", harness],
            check=True,
            capture_output=True,
            text=True,
            timeout=5,
        )
        return json.loads(completed.stdout.strip())

    def test_matching_cardclicked_echo_confirms_selection(self):
        result = self.run_script(
            confirmation_javascript=(
                'setTimeout(() => socket.emit("cardclickedA", '
                "2, true, false), 5);"
            ),
        )

        self.assertTrue(result["ok"])
        self.assertEqual(result["index"], 2)
        self.assertEqual(
            result["confirmation"]["event"],
            "cardclickedA",
        )
        self.assertTrue(result["confirmation"]["clicked"])
        self.assertFalse(result["inputEnabledAfter"])

    def test_unrelated_echo_is_ignored_until_matching_index(self):
        result = self.run_script(
            confirmation_javascript=(
                'socket.emit("cardclickedA", 7, true, false); '
                'setTimeout(() => socket.emit("cardclickedA", '
                "2, true, false), 5);"
            ),
        )

        self.assertTrue(result["ok"])
        self.assertEqual(result["index"], 2)

    def test_missing_server_echo_times_out_fail_closed(self):
        result = self.run_script(
            timeout_ms=10,
            confirmation_javascript="",
        )

        self.assertFalse(result["ok"])
        self.assertEqual(
            result["error"],
            "card selection confirmation timeout",
        )

    def test_server_rejection_does_not_report_success(self):
        result = self.run_script(
            confirmation_javascript=(
                'setTimeout(() => socket.emit("cardclickedA", '
                "2, false, false), 5);"
            ),
        )

        self.assertFalse(result["ok"])
        self.assertEqual(result["error"], "card selection rejected")


if __name__ == "__main__":
    unittest.main()
