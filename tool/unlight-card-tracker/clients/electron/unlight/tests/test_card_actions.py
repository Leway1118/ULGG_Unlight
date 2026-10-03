from __future__ import annotations

import asyncio
import json
import shutil
import subprocess
import unittest

from clients.electron.unlight.auto_player.card_actions import (
    CardActions,
    build_card_runtime_probe_script,
    build_select_card_script,
    build_set_card_orientation_script,
)
from clients.electron.unlight.auto_player.runtime_card_reader import READ_CARDS_JS


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
    def test_rotation_uses_native_button_and_cardrotate_ack(self):
        script = build_set_card_orientation_script(1, "reverse", 25)

        self.assertIn("main?.button?.[1]", script)
        self.assertIn('button.emit("pointerdown")', script)
        self.assertIn('button.emit("pointerup")', script)
        self.assertIn("cardrotate${player}", script)
        self.assertNotIn('socket.emit("cardrotate', script)

    def test_rotation_postcondition_supports_event_card_frame_angle(self):
        script = build_set_card_orientation_script(1, "reverse", 25)

        self.assertIn('card?.texture?.key === "event_asset"', script)
        self.assertIn('upright(card) ? "front"', script)
        self.assertIn('normalized(card?.angle) === 180 ? "reverse"', script)

    def test_set_orientation_awaits_native_ack_contract(self):
        sniffer = FakeSniffer()

        result = asyncio.run(CardActions(sniffer).set_orientation(1, "reverse"))

        self.assertEqual(result, '{"ok":true}')
        self.assertTrue(sniffer.calls[0]["await_promise"])

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
            call["script"].index('card.emit("pointerover")'),
        )

    def test_script_uses_original_pointerdown_handler_not_direct_socket_emit(self):
        script = build_select_card_script(4, 25)

        self.assertLess(
            script.index('card.emit("pointerover")'),
            script.index('card.emit("pointerdown")'),
        )
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

        for value in (-1, True, "2"):
            with self.subTest(probe_index=value):
                with self.assertRaises(ValueError):
                    build_card_runtime_probe_script(value)

    def test_probe_reports_both_faces_and_upright_face(self):
        script = build_card_runtime_probe_script(2)

        self.assertIn("const front = face(1, 2)", script)
        self.assertIn("const reverse = face(3, 4)", script)
        self.assertIn('faces: { front, reverse, upright: uprightFace }', script)


@unittest.skipUnless(shutil.which("node"), "Node.js is required")
class RuntimeCardReaderJavaScriptTests(unittest.TestCase):
    @staticmethod
    def read(*, front_angle: int, reverse_angle: int) -> dict:
        expression = READ_CARDS_JS.strip()
        if expression.endswith(";"):
            expression = expression[:-1]
        harness = r"""
        const frame={input:{enabled:true},active:true,visible:true,
          _events:{pointerdown:{fn:()=>{}}}};
        const row=[
          frame,
          {texture:{key:"acswd"},angle:FRONT_ANGLE},
          {text:"5"},
          {texture:{key:"acbow"},angle:REVERSE_ANGLE},
          {text:"4"}
        ];
        globalThis.game={scene:{keys:{MainA:{arr1:[row]}}}};
        console.log(SCRIPT);
        """.replace("FRONT_ANGLE", str(front_angle)).replace(
            "REVERSE_ANGLE", str(reverse_angle)
        ).replace("SCRIPT", expression)
        completed = subprocess.run(
            ["node", "-e", harness],
            check=True,
            capture_output=True,
            text=True,
            timeout=5,
        )
        return json.loads(completed.stdout.strip())[0]

    @staticmethod
    def read_event(*, angle: int, sword2: int = 0) -> dict:
        expression = READ_CARDS_JS.strip()
        if expression.endswith(";"):
            expression = expression[:-1]
        harness = r"""
        const eventCard={
          texture:{key:"event_asset"},frame:{name:0},angle:EVENT_ANGLE,
          input:{enabled:true},active:true,visible:true,
          _events:{pointerdown:{fn:()=>{}}}
        };
        const info={
          swd1:0,gun1:0,shi1:0,mov1:0,spe1:0,
          swd2:SWD2,gun2:0,shi2:1,mov2:0,spe2:0
        };
        globalThis.game={scene:{keys:{MainA:{arr1:[[eventCard]],eventInfo:{frames:[info]}}}}};
        console.log(SCRIPT);
        """.replace("EVENT_ANGLE", str(angle)).replace(
            "SWD2", str(sword2)
        ).replace("SCRIPT", expression)
        completed = subprocess.run(
            ["node", "-e", harness], check=True, capture_output=True, text=True, timeout=5
        )
        return json.loads(completed.stdout.strip())[0]

    def test_reader_uses_current_upright_reverse_face(self):
        card = self.read(front_angle=180, reverse_angle=360)

        self.assertEqual(card["raw_hand_count"], 1)
        self.assertEqual(card["face"], "reverse")
        self.assertEqual(card["texture"], "acbow")
        self.assertEqual(card["value"], 4)
        self.assertTrue(card["selectable"])

    def test_reader_fails_closed_during_rotation_tween(self):
        card = self.read(front_angle=90, reverse_angle=270)

        self.assertIsNone(card["face"])
        self.assertIsNone(card["texture"])
        self.assertFalse(card["selectable"])

    def test_event_card_uses_upright_side_one_metadata(self):
        card = self.read_event(angle=0)

        self.assertEqual(card["source"], "event")
        self.assertEqual(card["face"], "front")
        self.assertEqual(card["type"], "neutral")
        self.assertEqual(card["value"], 0)
        self.assertTrue(card["selectable"])

    def test_event_card_uses_rotated_side_two_metadata(self):
        card = self.read_event(angle=180)

        self.assertEqual(card["face"], "reverse")
        self.assertEqual(card["type"], "shield")
        self.assertEqual(card["value"], 1)
        self.assertEqual(card["socket_index"], 0)

    def test_event_card_preserves_all_resources_on_one_face(self):
        card = self.read_event(angle=180, sword2=2)

        reverse = next(value for value in card["orientations"] if value["face"] == "reverse")
        self.assertEqual(reverse["type"], "neutral")
        self.assertEqual(reverse["value"], 0)
        self.assertEqual(reverse["resources"], [
            {"type": "sword", "value": 2},
            {"type": "shield", "value": 1},
        ])

    def test_event_card_fails_closed_during_rotation_tween(self):
        card = self.read_event(angle=90)

        self.assertIsNone(card["face"])
        self.assertIsNone(card["type"])
        self.assertFalse(card["selectable"])


@unittest.skipUnless(shutil.which("node"), "Node.js is required")
class CardActionsJavaScriptTests(unittest.TestCase):
    @staticmethod
    def run_script(
        *,
        index: int = 2,
        timeout_ms: int = 50,
        confirmation_javascript: str,
        main_overrides: str = "",
        active_phase: str = "MovePhaseA",
    ) -> dict:
        expression = build_select_card_script(index, timeout_ms).strip()
        if expression.endswith(";"):
            expression = expression[:-1]

        harness = f"""
        const {{ EventEmitter }} = require("events");
        const socket = new EventEmitter();
        socket.off = socket.removeListener.bind(socket);
        const card = new EventEmitter();
        card.active = true;
        card.visible = true;
        card.input = {{ enabled: true }};
        card.on("pointerover", () => {{
            main.info_text_timeout = main.time.addEvent();
        }});
        card.on("pointerdown", () => {{
            card.input.enabled = false;
            {confirmation_javascript}
        }});
        const main = {{
            PLAYER: "A", socket, arr1: [null, null, [card]],
            scene: {{ isActive: () => true }},
            time: {{ addEvent: () => ({{ remove: () => {{}} }}) }},
            info_text: {{}}, info_bg: {{}}, button: [null, null, {{}}]
        }};
        {main_overrides}
        globalThis.game = {{
            scene: {{
                keys: {{
                    MainA: main,
                    {active_phase}: {{ scene: {{ isActive: () => true }} }}
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

    def test_missing_hover_dependency_fails_closed_before_pointerdown(self):
        result = self.run_script(
            confirmation_javascript='throw new Error("must not click");',
            main_overrides="main.info_bg = null;",
        )

        self.assertFalse(result["ok"])
        self.assertEqual(result["error"], "card interaction dependency missing")
        self.assertIn("MainA.info_bg", result["missingDependencies"])

    def test_present_hover_dependencies_allow_pointerdown(self):
        result = self.run_script(
            confirmation_javascript=(
                'setTimeout(() => socket.emit("cardclickedA", '
                "2, true, false), 5);"
            ),
        )

        self.assertTrue(result["ok"])

    def test_attack_phase_allows_card_pointerdown(self):
        result = self.run_script(
            active_phase="AttackPhaseA",
            confirmation_javascript=(
                'setTimeout(() => socket.emit("cardclickedA", '
                "2, true, false), 5);"
            ),
        )

        self.assertTrue(result["ok"])

    def test_battle_result_interrupts_card_ack_wait(self):
        result = self.run_script(
            confirmation_javascript='socket.emit("result");',
        )

        self.assertFalse(result["ok"])
        self.assertEqual(result["error"], "battle ended during card selection")
        self.assertEqual(result["ack"], "result")

    def test_javascript_exception_preserves_name_message_and_stack(self):
        result = self.run_script(
            confirmation_javascript='throw new TypeError("probe remove failure");',
        )

        self.assertFalse(result["ok"])
        self.assertEqual(result["jsError"]["name"], "TypeError")
        self.assertEqual(result["jsError"]["message"], "probe remove failure")
        self.assertIn("probe remove failure", result["jsError"]["stack"])


if __name__ == "__main__":
    unittest.main()
