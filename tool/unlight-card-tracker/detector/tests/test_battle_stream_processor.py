import ast
import copy
import json
from pathlib import Path
import unittest

from battle_stream_processor import (
    BATTLE_CONSOLE_LEVELS,
    BattleStreamProcessor,
    format_processing_result,
)
from battle_tracker_adapter import adapt_battle_observation


DETECTOR_DIR = Path(__file__).resolve().parents[1]
SAFE_CORPUS = DETECTOR_DIR / "deck" / "battle-observations.jsonl"
DISCOVERY_CORPUS = (
    DETECTOR_DIR / "deck" / "events.jsonl.redacted.jsonl"
)


def face(kind="sword", value=2):
    return {"kind": kind, "value": value}


def card(card_id=55, slot=4):
    first = face("sword", 2)
    second = face("shield", 2)
    return {
        "card_id": card_id,
        "slot": slot,
        "type": "normal",
        "rotate": False,
        "used": False,
        "clicked": False,
        "face1": first,
        "face2": second,
        "display_top": first,
        "display_bottom": second,
        "display_order_confirmation": "provisional",
    }


SOURCE_BY_TYPE = {
    "battle_started": "gameStart",
    "cards_dealt": "drawPhase",
    "event_card_received": "eventCard",
    "card_drawn": "cardDraw",
    "card_selection_changed": "cardclickedA",
    "cards_revealed": "cardOpen_A",
    "phase_ended": "endPhase",
    "turn_ended": "endTurn",
    "battle_finished": "result",
}


def observation(
    observation_type="battle_started",
    *,
    sequence=1,
    protocol_side="unknown",
    resolved_side="unknown",
    visibility=None,
    confirmation=None,
    payload=None,
):
    pending = observation_type == "card_selection_changed"
    if visibility is None:
        if observation_type in {
            "cards_dealt",
            "event_card_received",
            "card_drawn",
        } or pending:
            visibility = "self_private"
        else:
            visibility = "public"
    if confirmation is None:
        confirmation = "pending" if pending else "confirmed"
    payloads = {
        "battle_started": {},
        "cards_dealt": {"cards": [card()]},
        "event_card_received": {"card": card()},
        "card_drawn": {"card": card()},
        "card_selection_changed": {
            "slot": 4,
            "selected": True,
            "selection": "selected",
        },
        "cards_revealed": {"cards": [card()]},
        "phase_ended": {"phase": "move"},
        "turn_ended": {"turn": 1},
        "battle_finished": {"outcome": "win"},
    }
    return {
        "schema_version": 1,
        "timestamp": f"2026-07-28T00:00:{sequence % 60:02d}+08:00",
        "sequence": sequence,
        "observation_index": 0,
        "type": observation_type,
        "source_event": SOURCE_BY_TYPE.get(observation_type, "unknown"),
        "direction": "received",
        "protocol_side": protocol_side,
        "resolved_side": resolved_side,
        "visibility": visibility,
        "confirmation": confirmation,
        "payload": copy.deepcopy(
            payload if payload is not None else payloads.get(
                observation_type, {}
            )
        ),
    }


def active_processor(**kwargs):
    processor = BattleStreamProcessor("producer-1", **kwargs)
    result = processor.process(observation("battle_started"))
    assert result["accepted"]
    return processor


class BattleStreamProcessorTests(unittest.TestCase):
    def test_processor_initial_state_is_idle(self):
        processor = BattleStreamProcessor("producer-1")
        self.assertEqual(processor.snapshot()["battle_status"], "idle")

    def test_battle_started_enters_active(self):
        result = BattleStreamProcessor("producer-1").process(
            observation("battle_started")
        )
        self.assertTrue(result["accepted"])
        self.assertTrue(result["state_changed"])
        self.assertEqual(result["state"]["battle_status"], "active")

    def test_observation_flows_through_adapter_and_reducer(self):
        processor = active_processor()
        result = processor.process(
            observation(
                "card_drawn",
                sequence=2,
                payload={"card": card(31, 6)},
            )
        )
        self.assertEqual(
            result["domain_events"][0]["event_type"],
            "hand.card_drawn",
        )
        self.assertEqual(result["state"]["self_hand"][0]["card_id"], 31)

    def test_one_observation_can_emit_multiple_domain_events(self):
        start = adapt_battle_observation(
            observation("battle_started")
        )[0]
        draw = adapt_battle_observation(
            observation(
                "card_drawn",
                sequence=2,
                payload={"card": card(31, 6)},
            )
        )[0]

        def multi_adapter(_value):
            return [start, draw]

        processor = BattleStreamProcessor(
            "producer-1",
            adapter=multi_adapter,
        )
        result = processor.process({"type": "synthetic"})
        self.assertEqual(len(result["domain_events"]), 2)
        self.assertEqual(result["state"]["battle_status"], "active")
        self.assertEqual(len(result["state"]["self_hand"]), 1)

    def test_zero_domain_event_does_not_change_state(self):
        processor = BattleStreamProcessor("producer-1")
        before = processor.snapshot()
        result = processor.process(observation("character_state"))
        self.assertFalse(result["accepted"])
        self.assertFalse(result["state_changed"])
        self.assertEqual(result["state"], before)

    def test_producer_session_is_attached(self):
        result = BattleStreamProcessor("producer-x").process(
            observation("battle_started")
        )
        self.assertEqual(
            result["domain_events"][0]["producer_session_id"],
            "producer-x",
        )

    def test_missing_producer_session_is_rejected(self):
        with self.assertRaises(ValueError):
            BattleStreamProcessor("")

    def test_processor_never_generates_session_identity(self):
        processor = BattleStreamProcessor("caller-owned-session")
        self.assertEqual(
            processor.producer_session_id,
            "caller-owned-session",
        )

    def test_replaying_observation_is_idempotent(self):
        processor = active_processor()
        value = observation(
            "card_drawn",
            sequence=2,
            payload={"card": card(31, 6)},
        )
        first = processor.process(value)
        second = processor.process(value)
        self.assertEqual(len(first["state"]["self_hand"]), 1)
        self.assertEqual(len(second["state"]["self_hand"]), 1)
        self.assertFalse(second["accepted"])
        self.assertFalse(second["state_changed"])

    def test_different_processor_sessions_have_distinct_identity(self):
        first = BattleStreamProcessor("chrome-1")
        second = BattleStreamProcessor("electron-1")
        first_event = first.process(observation("battle_started"))
        second_event = second.process(observation("battle_started"))
        self.assertNotEqual(
            first_event["domain_events"][0]["producer_session_id"],
            second_event["domain_events"][0]["producer_session_id"],
        )

    def test_strict_mode_rejects_mid_battle_event(self):
        processor = BattleStreamProcessor("producer-1", mode="strict")
        result = processor.process(
            observation(
                "card_drawn",
                payload={"card": card()},
            )
        )
        self.assertFalse(result["accepted"])
        self.assertEqual(result["state"]["battle_status"], "idle")
        self.assertIn(
            "event_before_battle_start",
            [value["code"] for value in result["diagnostics"]],
        )

    def test_diagnostic_bootstrap_does_not_open_battle(self):
        processor = BattleStreamProcessor(
            "producer-1",
            mode="diagnostic-bootstrap",
        )
        result = processor.process(
            observation(
                "card_drawn",
                payload={"card": card()},
            )
        )
        self.assertEqual(result["state"]["battle_status"], "idle")
        self.assertNotIn(
            "battle.started",
            [event["event_type"] for event in result["domain_events"]],
        )

    def test_diagnostic_bootstrap_records_safe_marker(self):
        processor = BattleStreamProcessor(
            "producer-1",
            mode="diagnostic-bootstrap",
        )
        result = processor.process(
            observation(
                "card_drawn",
                sequence=33,
                payload={"card": card()},
            )
        )
        marker = next(
            value
            for value in result["diagnostics"]
            if value["code"] == "stream_started_mid_battle"
        )
        self.assertEqual(
            marker,
            {
                "code": "stream_started_mid_battle",
                "event_type": "card_drawn",
                "source_sequence": 33,
            },
        )

    def test_bootstrap_marker_is_recorded_once(self):
        processor = BattleStreamProcessor(
            "producer-1",
            mode="diagnostic-bootstrap",
        )
        processor.process(observation("card_drawn", sequence=2))
        second = processor.process(observation("card_drawn", sequence=3))
        self.assertNotIn(
            "stream_started_mid_battle",
            [value["code"] for value in second["diagnostics"]],
        )

    def test_invalid_processor_mode_is_rejected(self):
        with self.assertRaises(ValueError):
            BattleStreamProcessor("producer-1", mode="bootstrap")

    def test_local_side_none_does_not_guess_reveal(self):
        processor = active_processor(local_side=None)
        result = processor.process(
            observation(
                "cards_revealed",
                sequence=2,
                protocol_side="A",
                resolved_side="unknown",
            )
        )
        self.assertEqual(result["domain_events"], [])
        self.assertEqual(result["state"]["self_revealed_cards"], [])

    def test_local_side_a_resolves_a_as_self(self):
        processor = active_processor(local_side="A")
        result = processor.process(
            observation(
                "cards_revealed",
                sequence=2,
                protocol_side="A",
                resolved_side="unknown",
            )
        )
        self.assertEqual(
            result["domain_events"][0]["resolved_side"], "self"
        )
        self.assertEqual(len(result["state"]["self_revealed_cards"]), 1)

    def test_local_side_a_resolves_b_as_opponent(self):
        processor = active_processor(local_side="A")
        value = observation(
            "cards_revealed",
            sequence=2,
            protocol_side="B",
            resolved_side="unknown",
        )
        value["source_event"] = "cardOpen_B"
        result = processor.process(value)
        self.assertEqual(
            result["domain_events"][0]["resolved_side"], "opponent"
        )

    def test_local_side_b_resolves_b_as_self(self):
        processor = active_processor(local_side="B")
        value = observation(
            "cards_revealed",
            sequence=2,
            protocol_side="B",
            resolved_side="unknown",
        )
        value["source_event"] = "cardOpen_B"
        result = processor.process(value)
        self.assertEqual(
            result["domain_events"][0]["resolved_side"], "self"
        )

    def test_invalid_local_side_is_rejected(self):
        with self.assertRaises(ValueError):
            BattleStreamProcessor("producer-1", local_side="self")

    def test_opponent_pending_selection_is_restricted(self):
        processor = active_processor(local_side="A")
        value = observation(
            "card_selection_changed",
            sequence=2,
            protocol_side="B",
            resolved_side="unknown",
        )
        value["source_event"] = "cardclickedB"
        result = processor.process(value)
        self.assertEqual(result["domain_events"], [])
        self.assertEqual(result["state"]["self_pending_selection"], [])

    def test_restricted_observation_does_not_pass_adapter(self):
        processor = active_processor()
        result = processor.process(
            observation(
                "card_drawn",
                sequence=2,
                visibility="restricted_opponent_hidden",
            )
        )
        self.assertEqual(result["domain_events"], [])

    def test_unknown_observation_does_not_raise(self):
        result = BattleStreamProcessor("producer-1").process(
            observation("unknown")
        )
        self.assertFalse(result["accepted"])
        self.assertEqual(result["domain_events"], [])

    def test_malformed_observation_does_not_raise(self):
        processor = BattleStreamProcessor("producer-1")
        for value in (None, [], {}, "bad"):
            with self.subTest(value=value):
                result = processor.process(value)
                self.assertFalse(result["accepted"])

    def test_adapter_exception_is_isolated(self):
        def broken_adapter(_value):
            raise RuntimeError("boom")

        processor = BattleStreamProcessor(
            "producer-1",
            adapter=broken_adapter,
        )
        result = processor.process(observation("battle_started"))
        self.assertEqual(result["diagnostics"][0]["code"], "adapter_error")
        self.assertEqual(result["state"]["battle_status"], "idle")

    def test_reducer_exception_rolls_back_observation(self):
        def broken_reducer(_state, _event):
            raise RuntimeError("boom")

        processor = BattleStreamProcessor(
            "producer-1",
            reducer=broken_reducer,
        )
        result = processor.process(observation("battle_started"))
        self.assertEqual(result["diagnostics"][0]["code"], "reducer_error")
        self.assertEqual(processor.snapshot()["battle_status"], "idle")

    def test_finished_state_rejects_late_event(self):
        processor = active_processor()
        processor.process(observation("battle_finished", sequence=2))
        result = processor.process(
            observation("card_drawn", sequence=3)
        )
        self.assertFalse(result["accepted"])
        self.assertIn(
            "late_event_after_finish",
            [value["code"] for value in result["diagnostics"]],
        )

    def test_new_battle_started_reopens_finished_state(self):
        processor = active_processor()
        processor.process(observation("battle_finished", sequence=2))
        result = processor.process(
            observation("battle_started", sequence=3)
        )
        self.assertTrue(result["accepted"])
        self.assertEqual(result["state"]["battle_status"], "active")

    def test_snapshot_is_deep_copy(self):
        processor = active_processor()
        snapshot = processor.snapshot()
        snapshot["battle_status"] = "finished"
        snapshot["self_hand"].append({"card_id": 1})
        self.assertEqual(processor.snapshot()["battle_status"], "active")
        self.assertEqual(processor.snapshot()["self_hand"], [])

    def test_result_state_is_deep_copy(self):
        processor = active_processor()
        result = processor.process(observation("phase_ended", sequence=2))
        result["state"]["battle_status"] = "finished"
        self.assertEqual(processor.snapshot()["battle_status"], "active")

    def test_processors_do_not_share_state(self):
        first = active_processor()
        second = active_processor()
        first.process(observation("card_drawn", sequence=2))
        self.assertEqual(len(first.snapshot()["self_hand"]), 1)
        self.assertEqual(second.snapshot()["self_hand"], [])

    def test_chrome_and_electron_processors_do_not_mix_state(self):
        chrome = BattleStreamProcessor("chrome-generation")
        electron = BattleStreamProcessor("electron-generation")
        chrome.process(observation("battle_started"))
        self.assertEqual(chrome.snapshot()["battle_status"], "active")
        self.assertEqual(electron.snapshot()["battle_status"], "idle")

    def test_console_levels_are_explicit(self):
        self.assertEqual(
            BATTLE_CONSOLE_LEVELS,
            frozenset({"quiet", "important", "debug"}),
        )

    def test_console_quiet_does_not_change_state(self):
        processor = active_processor(console_level="quiet")
        result = processor.process(observation("phase_ended", sequence=2))
        before = processor.snapshot()
        processor.format_result(result)
        self.assertEqual(processor.snapshot(), before)

    def test_console_important_does_not_change_state(self):
        processor = active_processor(console_level="important")
        result = processor.process(observation("phase_ended", sequence=2))
        before = processor.snapshot()
        lines = processor.format_result(result)
        self.assertTrue(any("battle.phase_ended" in line for line in lines))
        self.assertEqual(processor.snapshot(), before)

    def test_console_debug_does_not_change_state(self):
        processor = active_processor(console_level="debug")
        result = processor.process(
            observation("event_card_received", sequence=2)
        )
        before = processor.snapshot()
        lines = processor.format_result(result)
        self.assertTrue(
            any("hand.event_card_received" in line for line in lines)
        )
        self.assertEqual(processor.snapshot(), before)

    def test_quiet_console_only_formats_errors(self):
        processor = BattleStreamProcessor(
            "producer-1",
            console_level="quiet",
        )
        result = processor.process(observation("card_drawn"))
        lines = processor.format_result(result)
        self.assertEqual(lines, [])
        malformed = {
            "diagnostics": [
                {
                    "code": "invalid_payload",
                    "event_type": "hand.card_drawn",
                    "source_sequence": 1,
                }
            ],
            "domain_events": [],
        }
        self.assertTrue(format_processing_result(malformed, "quiet"))

    def test_invalid_console_level_is_rejected(self):
        with self.assertRaises(ValueError):
            BattleStreamProcessor("producer-1", console_level="all")
        with self.assertRaises(ValueError):
            format_processing_result({}, "all")

    def test_result_schema_is_safe_and_complete(self):
        result = BattleStreamProcessor("producer-1").process(
            observation("battle_started")
        )
        self.assertEqual(
            set(result),
            {
                "accepted",
                "observation_type",
                "domain_events",
                "state_changed",
                "state",
                "diagnostics",
            },
        )

    def test_result_does_not_copy_transport_secrets(self):
        value = observation("battle_started")
        value["raw"] = "secret"
        value["token"] = "secret"
        value["room_id"] = "secret"
        result = BattleStreamProcessor("producer-1").process(value)
        serialized = json.dumps(result).lower()
        self.assertNotIn("secret", serialized)
        self.assertNotIn("room_id", serialized)
        self.assertNotIn("token", serialized)

    def test_module_has_no_io_database_api_or_transport_imports(self):
        source = (
            DETECTOR_DIR / "battle_stream_processor.py"
        ).read_text(encoding="utf-8")
        tree = ast.parse(source)
        imports = {
            node.names[0].name.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.Import)
        }
        imports.update(
            node.module.split(".")[0]
            for node in ast.walk(tree)
            if isinstance(node, ast.ImportFrom) and node.module
        )
        forbidden = {
            "fastapi",
            "pathlib",
            "requests",
            "socket",
            "sqlite3",
            "uvicorn",
            "websockets",
        }
        self.assertTrue(imports.isdisjoint(forbidden))
        called = {
            node.func.id
            for node in ast.walk(tree)
            if isinstance(node, ast.Call)
            and isinstance(node.func, ast.Name)
        }
        self.assertTrue(
            called.isdisjoint({"open", "connect", "fetch", "send", "urlopen"})
        )

    @unittest.skipUnless(SAFE_CORPUS.exists(), "safe corpus not present")
    def test_real_safe_corpus_replays_in_strict_mode(self):
        processor = BattleStreamProcessor("safe-corpus")
        for line in SAFE_CORPUS.read_text(encoding="utf-8").splitlines():
            processor.process(json.loads(line))
        state = processor.snapshot()
        self.assertIn(state["battle_status"], {"idle", "active", "finished"})

    @unittest.skipUnless(SAFE_CORPUS.exists(), "safe corpus not present")
    def test_partial_corpus_without_start_remains_idle(self):
        rows = [
            json.loads(line)
            for line in SAFE_CORPUS.read_text(
                encoding="utf-8"
            ).splitlines()
        ]
        if any(row.get("type") == "battle_started" for row in rows):
            self.skipTest("current local corpus includes battle_started")
        processor = BattleStreamProcessor("partial-corpus", mode="strict")
        for row in rows:
            processor.process(row)
        self.assertEqual(processor.snapshot()["battle_status"], "idle")

    @staticmethod
    def _discovery_game_starts():
        rows = [
            json.loads(line)
            for line in DISCOVERY_CORPUS.read_text(
                encoding="utf-8"
            ).splitlines()
        ]
        segments = []
        current = []
        last_sequence = None
        for row in rows:
            sequence = row.get("sequence")
            if (
                isinstance(last_sequence, int)
                and isinstance(sequence, int)
                and sequence < last_sequence
            ):
                segments.append(current)
                current = []
            current.append(row)
            last_sequence = sequence
        segments.append(current)

        with_standby = []
        without_standby = []
        for segment in segments:
            for index, row in enumerate(segment):
                if row.get("event") != "gameStart":
                    continue
                prior = segment[max(0, index - 100):index]
                target = (
                    with_standby
                    if any(
                        value.get("event") == "duel_standby"
                        for value in prior
                    )
                    else without_standby
                )
                target.append(row)
        return with_standby, without_standby

    @unittest.skipUnless(
        DISCOVERY_CORPUS.exists(),
        "redacted discovery corpus not present",
    )
    def test_real_pvp_game_start_corpus_enters_active(self):
        with_standby, _without = self._discovery_game_starts()
        self.assertTrue(with_standby)
        row = with_standby[0]
        safe = observation(
            "battle_started",
            sequence=row["sequence"],
        )
        safe["timestamp"] = row["timestamp"]
        result = BattleStreamProcessor("pvp-corpus").process(safe)
        self.assertEqual(result["state"]["battle_status"], "active")

    @unittest.skipUnless(
        DISCOVERY_CORPUS.exists(),
        "redacted discovery corpus not present",
    )
    def test_real_no_standby_game_start_corpus_enters_active(self):
        _with, without_standby = self._discovery_game_starts()
        self.assertTrue(without_standby)
        row = without_standby[0]
        safe = observation(
            "battle_started",
            sequence=row["sequence"],
        )
        safe["timestamp"] = row["timestamp"]
        result = BattleStreamProcessor(
            "no-standby-corpus"
        ).process(safe)
        self.assertEqual(result["state"]["battle_status"], "active")


if __name__ == "__main__":
    unittest.main()
