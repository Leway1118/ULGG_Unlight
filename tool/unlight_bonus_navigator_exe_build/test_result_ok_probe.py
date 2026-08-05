from __future__ import annotations

import asyncio
import json
import tempfile
import unittest
from pathlib import Path

from result_ok_probe import (
    INJECT_RESULT_OK_JS,
    READ_RESULT_OK_STATE_JS,
    ResultOkProbe,
    result_button_ready,
)


def snapshot(**overrides):
    value = {
        "result_cycle": 1,
        "result_active": True,
        "result": "win",
        "bonusgame": True,
        "bonus_active": False,
        "btn_ok": {
            "exists": True,
            "visible": True,
            "alpha": 1,
            "input_enabled": True,
            "click_handler_count": 2,
        },
        "btn_ok_ready": True,
        "injection_used": False,
        "scene_events": [],
    }
    value.update(overrides)
    return value


class ResultOkProbeTests(unittest.TestCase):
    def test_ready_requires_all_result_button_guards(self):
        self.assertTrue(result_button_ready(snapshot()))

        cases = (
            {"result_active": False},
            {"btn_ok": {**snapshot()["btn_ok"], "exists": False}},
            {"btn_ok": {**snapshot()["btn_ok"], "visible": False}},
            {"btn_ok": {**snapshot()["btn_ok"], "alpha": 0.98}},
            {"btn_ok": {**snapshot()["btn_ok"], "input_enabled": False}},
        )
        for changes in cases:
            with self.subTest(changes=changes):
                self.assertFalse(result_button_ready(snapshot(**changes)))

    def test_injection_uses_only_confirmed_action_boundary(self):
        self.assertIn('button.emit("click", button)', INJECT_RESULT_OK_JS)
        for forbidden in ("pointerdown", "pointerup", "result_exit", "document."):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, INJECT_RESULT_OK_JS)

    def test_javascript_has_atomic_per_cycle_duplicate_gate(self):
        self.assertIn(
            "bridge.injectedCycle === bridge.cycle",
            INJECT_RESULT_OK_JS,
        )
        self.assertLess(
            INJECT_RESULT_OK_JS.index("bridge.injectedCycle = bridge.cycle"),
            INJECT_RESULT_OK_JS.index('button.emit("click", button)'),
        )
        self.assertIn("resultWasActive", READ_RESULT_OK_STATE_JS)

    def test_snapshot_log_contains_evidence_without_socket_payload(self):
        with tempfile.TemporaryDirectory() as directory:
            probe = ResultOkProbe(Path(directory) / "probe.jsonl")
            asyncio.run(
                probe.on_frame(
                    "received",
                    {"event": "duel_end", "args": ["private-payload"]},
                    "raw-private-payload",
                    None,
                )
            )
            record = probe._record_snapshot(
                snapshot(
                    result_active=False,
                    bonus_active=True,
                    scene_events=[
                        {"name": "result_end", "source": "phaser"},
                    ],
                )
            )
            encoded = json.dumps(record)

            self.assertTrue(record["evidence"]["bonus_entered"])
            self.assertTrue(record["evidence"]["result_end_seen"])
            self.assertTrue(record["evidence"]["duel_end_seen"])
            self.assertNotIn("private-payload", encoded)
            self.assertEqual(record["socket_events"][0]["name"], "duel_end")

    def test_unallowlisted_socket_event_is_not_logged(self):
        with tempfile.TemporaryDirectory() as directory:
            probe = ResultOkProbe(Path(directory) / "probe.jsonl")
            asyncio.run(
                probe.on_frame(
                    "received",
                    {"event": "card", "args": ["hidden"]},
                    "raw-hidden",
                    None,
                )
            )
            record = probe._record_snapshot(snapshot())

            self.assertEqual(record["socket_events"], [])
            self.assertNotIn("hidden", json.dumps(record))


if __name__ == "__main__":
    unittest.main()
