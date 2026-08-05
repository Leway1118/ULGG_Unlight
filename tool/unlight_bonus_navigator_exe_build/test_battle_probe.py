from __future__ import annotations

import json
import unittest

from battle_probe import (
    READ_BATTLE_STATE_JS,
    BattleProbeState,
    room_fingerprint,
)


def snapshot(**overrides):
    value = {
        "scene": None,
        "active_scenes": [],
        "phase": None,
        "room_fingerprint": "room#0123456789abcdef",
        "result_scene_active": False,
        "result": None,
        "result_ready": False,
        "btn_ok_ready": False,
        "btn_ok": {
            "visible": False,
            "alpha": 0,
            "input_enabled": False,
        },
        "available_actions": [],
        "turn": None,
        "local_side": "A",
        "scene_events": [],
    }
    value.update(overrides)
    return value


class BattleProbeStateTests(unittest.TestCase):
    def test_a_game_start_marks_battle_started(self):
        state = BattleProbeState()
        state.observe_socket_frame({"event": "gameStart", "args": []})

        record = state.build_record(
            snapshot(
                scene="MainA",
                active_scenes=["MainA", "DrawPhaseA"],
                phase="draw",
            )
        )

        self.assertEqual(record["battle_state"], "battle_started")
        self.assertEqual(record["phase"], "draw")
        self.assertEqual(record["allowlisted_socket_events"], ["gameStart"])

    def test_b_phase_and_turn_events_are_reduced_without_payload(self):
        state = BattleProbeState()
        state.observe_socket_frame({"event": "gameStart", "args": []})
        state.observe_socket_frame(
            {"event": "endPhase", "args": ["move", {"private": "ignored"}]}
        )
        state.observe_socket_frame(
            {"event": "endTurn", "args": [4, {"private": "ignored"}]}
        )

        record = state.build_record(
            snapshot(scene="MainA", active_scenes=["MainA"])
        )
        encoded = json.dumps(record)

        self.assertEqual(record["battle_state"], "battle_active")
        self.assertEqual(record["phase"], "move")
        self.assertEqual(record["turn"], 4)
        self.assertNotIn("private", encoded)
        self.assertNotIn("ignored", encoded)

    def test_c_result_outcomes_are_allowlisted(self):
        for outcome in ("win", "lose", "draw"):
            with self.subTest(outcome=outcome):
                state = BattleProbeState()
                state.observe_socket_frame({"event": "gameStart", "args": []})
                state.observe_socket_frame(
                    {"event": "result", "args": [outcome, 34, 70]}
                )
                record = state.build_record(snapshot())
                self.assertEqual(record["battle_state"], "battle_finished")
                self.assertEqual(record["result"], outcome)

    def test_d_active_result_scene_and_ready_button_are_detected(self):
        state = BattleProbeState()
        record = state.build_record(
            snapshot(
                scene="Result",
                active_scenes=["Result"],
                phase="result",
                result_scene_active=True,
                result="win",
                result_ready=True,
                btn_ok_ready=True,
                btn_ok={
                    "visible": True,
                    "alpha": 1,
                    "input_enabled": True,
                },
                available_actions=["result_ok"],
            )
        )

        self.assertEqual(record["battle_state"], "result_ready")
        self.assertEqual(record["scene"], "Result")
        self.assertTrue(record["result_ready"])
        self.assertTrue(record["btn_ok_ready"])
        self.assertEqual(record["available_actions"], ["result_ok"])

    def test_e_non_bonus_result_event_sequence_is_preserved(self):
        state = BattleProbeState()
        state.observe_socket_frame({"event": "gameStart", "args": []})
        state.observe_socket_frame({"event": "result", "args": ["win"]})
        state.build_record(snapshot())
        state.observe_scene_events(
            [
                {
                    "name": "result_ready",
                    "source": "phaser",
                    "timestamp": "2026-08-05T00:00:01.000Z",
                },
                {
                    "name": "result_end",
                    "source": "phaser",
                    "timestamp": "2026-08-05T00:00:02.000Z",
                },
            ]
        )
        state.observe_socket_frame(
            {"event": "duel_end", "args": ["must-not-persist"]},
            timestamp="2026-08-05T00:00:03.000Z",
        )

        record = state.build_record(snapshot())

        self.assertEqual(
            record["allowlisted_socket_events"],
            ["result_ready", "result_end", "duel_end"],
        )
        self.assertEqual(record["battle_state"], "post_result")
        self.assertNotIn("must-not-persist", json.dumps(record))

    def test_e_bonus_result_event_sequence_is_preserved(self):
        state = BattleProbeState()
        state.observe_socket_frame({"event": "gameStart", "args": []})
        state.observe_socket_frame({"event": "result", "args": ["win"]})
        state.build_record(snapshot())
        state.observe_scene_events(
            [
                {
                    "name": "result_ready",
                    "timestamp": "2026-08-05T00:00:01.000Z",
                },
                {
                    "name": "bonus_exit",
                    "timestamp": "2026-08-05T00:00:02.000Z",
                },
                {
                    "name": "result_end",
                    "timestamp": "2026-08-05T00:00:03.000Z",
                },
            ]
        )
        state.observe_socket_frame(
            {"event": "duel_end", "args": []},
            timestamp="2026-08-05T00:00:04.000Z",
        )

        record = state.build_record(snapshot(scene="Bonus", active_scenes=["Bonus"]))

        self.assertEqual(
            record["allowlisted_socket_events"],
            ["result_ready", "bonus_exit", "result_end", "duel_end"],
        )

    def test_unallowlisted_socket_event_is_ignored(self):
        state = BattleProbeState()
        accepted = state.observe_socket_frame(
            {"event": "drawPhase", "args": [{"hidden": "payload"}]}
        )
        record = state.build_record(snapshot())

        self.assertFalse(accepted)
        self.assertEqual(record["allowlisted_socket_events"], [])
        self.assertNotIn("hidden", json.dumps(record))

    def test_events_before_game_start_do_not_mutate_battle_state(self):
        state = BattleProbeState()
        state.observe_socket_frame({"event": "endPhase", "args": ["move"]})
        state.observe_socket_frame({"event": "endTurn", "args": [9]})
        state.observe_socket_frame({"event": "result", "args": ["win"]})

        record = state.build_record(snapshot())

        self.assertEqual(record["battle_state"], "idle")
        self.assertIsNone(record["phase"])
        self.assertIsNone(record["turn"])
        self.assertIsNone(record["result"])
        self.assertEqual(
            record["allowlisted_socket_events"],
            ["endPhase", "endTurn", "result"],
        )

    def test_probe_javascript_contains_no_gameplay_mutation_calls(self):
        forbidden = (
            ".emit(",
            ".click(",
            ".launch(",
            ".start(",
            "pointerup",
            "pointerdown",
        )
        for marker in forbidden:
            with self.subTest(marker=marker):
                self.assertNotIn(marker, READ_BATTLE_STATE_JS)

    def test_room_fingerprint_is_stable_and_does_not_contain_room_id(self):
        first = room_fingerprint("room-identity-one")
        again = room_fingerprint("room-identity-one")
        other = room_fingerprint("room-identity-two")

        self.assertEqual(first, again)
        self.assertNotEqual(first, other)
        self.assertNotIn("room-identity-one", first or "")
        self.assertRegex(first or "", r"^room#[0-9a-f]{16}$")


if __name__ == "__main__":
    unittest.main()
