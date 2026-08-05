from __future__ import annotations

import json
import sqlite3
import tempfile
import unittest
from contextlib import closing
from datetime import datetime, timedelta, timezone
from pathlib import Path

from fastapi.testclient import TestClient

from client_profile import verify_client_profile
from diagnostics_models import CDP_DEFAULTS, utc_iso, validate_cdp_current
from event_schema import (
    PRODUCER_VERSION,
    TEMPLATE_SET_VERSION,
)
from event_store import EventStore
from runtime_credentials import (
    CDP_TOKEN_FILENAME,
    ROOM_TOKEN_FILENAME,
    RUNTIME_METADATA_FILENAME,
    ProducerCredentials,
    write_runtime_credentials,
)
from server import RuntimeStatus, create_app


class FakeClock:
    def __init__(self) -> None:
        self.value = datetime(
            2026,
            7,
            29,
            15,
            0,
            tzinfo=timezone.utc,
        )

    def __call__(self) -> datetime:
        return self.value

    def advance(self, seconds: float) -> None:
        self.value += timedelta(seconds=seconds)


def room_snapshot(**overrides) -> dict:
    snapshot = {
        "observed_room_state": "WAITING",
        "raw_state": "waiting",
        "game_room_id": "GAME_ROOM_1",
        "platform": "DMM",
        "channel": "room4",
        "room_name": "Diagnostics room",
        "game_host_player_id": "game-host",
        "game_opponent_player_id": None,
        "host_display_name": "Way",
        "opponent_display_name": None,
        "rule_version_id": "watermelon-126",
        "content_hash": None,
        "tracker_session_id": None,
        "client_observed_at": "2000-01-01T00:00:00Z",
    }
    snapshot.update(overrides)
    return snapshot


def compliant_counts(**overrides) -> dict:
    counts = {
        "expected_count": 5,
        "matched_count": 5,
        "changed_count": 4,
        "already_compliant_count": 1,
        "missing_count": 0,
        "invalid_count": 0,
        "verified_count": 5,
    }
    counts.update(overrides)
    return counts


class RoomCdpDiagnosticsApiTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        root = Path(self.temporary_directory.name)
        self.store = EventStore(root / "events.sqlite3")
        self.store.initialize()
        profile = verify_client_profile(root / "missing.asar", "0" * 64)
        self.credentials = ProducerCredentials.for_testing()
        self.clock = FakeClock()
        app = create_app(
            event_store=self.store,
            client_profile=profile,
            runtime_status=RuntimeStatus(detector="not_running"),
            producer_credentials=self.credentials,
            now_provider=self.clock,
        )
        self.client = TestClient(
            app,
            client=("127.0.0.1", 50000),
        )
        self.room_headers = {
            "Authorization": "Bearer test-room-token",
        }
        self.cdp_headers = {
            "Authorization": "Bearer test-cdp-token",
        }

    def tearDown(self) -> None:
        self.client.close()
        self.temporary_directory.cleanup()

    def test_authorization_and_endpoint_permissions(self) -> None:
        missing = self.client.put(
            "/api/v1/room/observation",
            json=room_snapshot(),
        )
        invalid = self.client.put(
            "/api/v1/room/observation",
            headers={"Authorization": "Bearer wrong"},
            json=room_snapshot(),
        )
        wrong_producer = self.client.put(
            "/api/v1/room/observation",
            headers=self.cdp_headers,
            json=room_snapshot(),
        )
        accepted = self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json=room_snapshot(),
        )

        self.assertEqual(missing.status_code, 401)
        self.assertEqual(invalid.status_code, 401)
        self.assertEqual(wrong_producer.status_code, 403)
        self.assertEqual(accepted.status_code, 200)
        self.assertEqual(
            accepted.json()["room_observation"]["producer_id"],
            "room-observer",
        )

    def test_non_loopback_mutation_is_rejected(self) -> None:
        with TestClient(
            self.client.app,
            client=("192.0.2.10", 50000),
        ) as remote:
            response = remote.put(
                "/api/v1/room/observation",
                headers=self.room_headers,
                json=room_snapshot(),
            )
        self.assertEqual(response.status_code, 403)
        self.assertEqual(
            response.json()["error"]["code"],
            "NON_LOOPBACK_REQUEST",
        )

    def test_room_requires_full_snapshot_and_forbids_ulgg_authority(self) -> None:
        missing = room_snapshot()
        missing.pop("room_name")
        missing_response = self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json=missing,
        )
        authority_response = self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json={
                **room_snapshot(),
                "ulgg_match_id": "not-writer-controlled",
            },
        )
        invalid_state = self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json=room_snapshot(observed_room_state="STALE"),
        )
        invalid_hash = self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json=room_snapshot(content_hash="sha256:short"),
        )

        for response in (
            missing_response,
            authority_response,
            invalid_state,
            invalid_hash,
        ):
            self.assertEqual(response.status_code, 422)

    def test_room_null_clear_heartbeat_history_and_server_time(self) -> None:
        first = self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json=room_snapshot(),
        )
        self.clock.advance(5)
        heartbeat = self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json=room_snapshot(),
        )
        self.clock.advance(5)
        cleared = self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json=room_snapshot(room_name=None),
        )
        history = self.store.diagnostic_history(
            "room",
            producer_id="room-observer",
        )

        self.assertEqual(first.status_code, 200)
        self.assertTrue(first.json()["history_appended"])
        self.assertFalse(heartbeat.json()["history_appended"])
        self.assertTrue(cleared.json()["history_appended"])
        self.assertIsNone(cleared.json()["room_observation"]["room_name"])
        self.assertEqual(len(history), 2)
        self.assertNotEqual(
            first.json()["room_observation"]["server_received_at"],
            room_snapshot()["client_observed_at"],
        )

    def test_room_delete_preserves_history_and_session_authority(self) -> None:
        session = self.store.start_session(
            producer_version=PRODUCER_VERSION,
            source="detector",
            client_profile="test",
            app_asar_hash=None,
            template_set_version=TEMPLATE_SET_VERSION,
            reference_width=848,
            reference_height=760,
        )
        self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json=room_snapshot(),
        )
        response = self.client.delete(
            "/api/v1/room/observation",
            headers=self.room_headers,
        )
        history = self.store.diagnostic_history(
            "room",
            producer_id="room-observer",
        )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json()["status"], "cleared")
        self.assertEqual(
            response.json()["room_observation"]["observed_room_state"],
            "UNKNOWN",
        )
        self.assertEqual(history[-1]["action"], "reset")
        self.assertEqual(
            self.store.get_session(session["session_id"])["status"],
            "running",
        )

    def test_cdp_patch_preserves_omitted_and_clears_explicit_null(self) -> None:
        first = self.client.patch(
            "/api/v1/cdp/status",
            headers=self.cdp_headers,
            json={
                "connection_state": "CONNECTED",
                "target": "game-frame",
                "context_id": 7,
            },
        )
        second = self.client.patch(
            "/api/v1/cdp/status",
            headers=self.cdp_headers,
            json={
                "context_state": "FOUND",
                "target": None,
            },
        )
        body = second.json()["cdp_diagnostic"]

        self.assertEqual(first.status_code, 200)
        self.assertEqual(second.status_code, 200)
        self.assertEqual(body["connection_state"], "CONNECTED")
        self.assertEqual(body["context_id"], 7)
        self.assertIsNone(body["target"])

    def test_cdp_error_clear_and_final_compliance(self) -> None:
        error_response = self.client.patch(
            "/api/v1/cdp/status",
            headers=self.cdp_headers,
            json={
                "connection_state": "ERROR",
                "error": {
                    "code": "CDP_RUNTIME_EXCEPTION",
                    "message": "demo",
                    "stage": "INJECTION",
                    "occurred_at": "2026-07-29T15:00:00Z",
                    "details": {},
                },
            },
        )
        cleared = self.client.patch(
            "/api/v1/cdp/status",
            headers=self.cdp_headers,
            json={
                "connection_state": "CONNECTED",
                "error": None,
            },
        )
        invalid_applied = self.client.patch(
            "/api/v1/cdp/status",
            headers=self.cdp_headers,
            json={
                "ruleset_apply_state": "APPLIED",
                **compliant_counts(matched_count=4, missing_count=1),
            },
        )
        valid_applied = self.client.patch(
            "/api/v1/cdp/status",
            headers=self.cdp_headers,
            json={
                "ruleset_apply_state": "APPLIED",
                **compliant_counts(),
            },
        )

        self.assertEqual(error_response.status_code, 200)
        self.assertIsNotNone(
            error_response.json()["cdp_diagnostic"]["error"]
        )
        self.assertIsNone(cleared.json()["cdp_diagnostic"]["error"])
        self.assertEqual(invalid_applied.status_code, 422)
        self.assertEqual(valid_applied.status_code, 200)

    def test_sensitive_error_details_are_rejected(self) -> None:
        response = self.client.patch(
            "/api/v1/cdp/status",
            headers=self.cdp_headers,
            json={
                "connection_state": "ERROR",
                "error": {
                    "code": "CDP_RUNTIME_EXCEPTION",
                    "message": "redacted diagnostic",
                    "stage": "INJECTION",
                    "occurred_at": "2026-07-29T15:00:00Z",
                    "details": {
                        "nested": {
                            "authorization_token": "must-not-persist"
                        }
                    },
                },
            },
        )
        self.assertEqual(response.status_code, 422)
        self.assertNotIn(
            "must-not-persist",
            json.dumps(
                self.store.get_cdp_diagnostic(),
                ensure_ascii=False,
            ),
        )

    def test_freshness_is_server_derived_without_overwriting_states(self) -> None:
        self.client.put(
            "/api/v1/room/observation",
            headers=self.room_headers,
            json=room_snapshot(observed_room_state="ACTIVE"),
        )
        self.client.patch(
            "/api/v1/cdp/status",
            headers=self.cdp_headers,
            json={"connection_state": "CONNECTED"},
        )

        fresh = self.client.get("/api/v1/diagnostics/status").json()
        self.clock.advance(46)
        stale = self.client.get("/api/v1/diagnostics/status").json()
        self.clock.advance(75)
        expired = self.client.get("/api/v1/diagnostics/status").json()

        self.assertEqual(
            fresh["room_observation"]["freshness_state"],
            "FRESH",
        )
        self.assertEqual(
            stale["room_observation"]["freshness_state"],
            "STALE",
        )
        self.assertEqual(
            stale["cdp_diagnostic"]["freshness_state"],
            "STALE",
        )
        self.assertEqual(
            expired["room_observation"]["freshness_state"],
            "EXPIRED",
        )
        self.assertEqual(
            expired["room_observation"]["observed_room_state"],
            "ACTIVE",
        )
        self.assertEqual(
            expired["cdp_diagnostic"]["connection_state"],
            "CONNECTED",
        )

    def test_aggregate_has_sections_and_never_exposes_tokens(self) -> None:
        response = self.client.get("/api/v1/diagnostics/status")
        serialized = json.dumps(response.json())

        self.assertEqual(response.status_code, 200)
        self.assertIn("room_observation", response.json())
        self.assertIn("cdp_diagnostic", response.json())
        self.assertNotIn("test-room-token", serialized)
        self.assertNotIn("test-cdp-token", serialized)
        self.assertNotIn("stage_status", response.json())


class DiagnosticPersistenceTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary_directory = tempfile.TemporaryDirectory()
        self.database = (
            Path(self.temporary_directory.name) / "events.sqlite3"
        )
        self.store = EventStore(self.database)
        self.store.initialize()

    def tearDown(self) -> None:
        self.temporary_directory.cleanup()

    def test_current_and_history_are_atomic(self) -> None:
        with closing(sqlite3.connect(self.database)) as connection:
            connection.execute(
                """
                CREATE TRIGGER reject_room_history
                BEFORE INSERT ON room_observation_history
                BEGIN
                    SELECT RAISE(ABORT, 'history failed');
                END
                """
            )
        with self.assertRaises(sqlite3.IntegrityError):
            self.store.put_room_observation(
                producer_id="room-observer",
                payload=room_snapshot(),
                server_received_at="2026-07-29T15:00:00Z",
            )
        self.assertIsNone(self.store.get_room_observation())

    def test_history_retention_trims_oldest_meaningful_rows(self) -> None:
        for index in range(5):
            self.store.put_room_observation(
                producer_id="room-observer",
                payload=room_snapshot(game_room_id=f"ROOM_{index}"),
                server_received_at=f"2026-07-29T15:00:0{index}Z",
                history_limit=3,
            )
        history = self.store.diagnostic_history(
            "room",
            producer_id="room-observer",
        )
        self.assertEqual(len(history), 3)
        self.assertEqual(history[0]["payload"]["game_room_id"], "ROOM_2")

    def test_restart_forces_disconnected_and_preserves_history(self) -> None:
        self.store.patch_cdp_diagnostic(
            producer_id="cdp-adapter",
            patch={"connection_state": "CONNECTED"},
            defaults=CDP_DEFAULTS,
            server_received_at="2026-07-29T15:00:00Z",
            validator=validate_cdp_current,
        )
        normalized = self.store.normalize_cdp_restart(
            server_received_at="2026-07-29T15:01:00Z"
        )
        current = self.store.get_cdp_diagnostic()
        history = self.store.diagnostic_history(
            "cdp",
            producer_id="cdp-adapter",
        )

        self.assertEqual(normalized, 1)
        self.assertEqual(current["connection_state"], "DISCONNECTED")
        self.assertEqual(history[-1]["action"], "restart")
        self.assertEqual(history[0]["payload"]["connection_state"], "CONNECTED")

    def test_schema_v3_migrates_to_diagnostic_tables(self) -> None:
        legacy = Path(self.temporary_directory.name) / "legacy.sqlite3"
        with closing(sqlite3.connect(legacy)) as connection:
            connection.execute(
                """
                CREATE TABLE schema_metadata (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                )
                """
            )
            connection.execute(
                """
                INSERT INTO schema_metadata(key, value)
                VALUES('sqlite_schema_version', '3')
                """
            )
        EventStore(legacy).initialize()
        with closing(sqlite3.connect(legacy)) as connection:
            tables = {
                row[0]
                for row in connection.execute(
                    "SELECT name FROM sqlite_master WHERE type = 'table'"
                )
            }
            version = connection.execute(
                """
                SELECT value FROM schema_metadata
                WHERE key = 'sqlite_schema_version'
                """
            ).fetchone()[0]
        self.assertEqual(version, "4")
        self.assertIn("room_observation_current", tables)
        self.assertIn("cdp_diagnostic_history", tables)


class RuntimeCredentialTest(unittest.TestCase):
    def test_runtime_files_are_separate_and_metadata_is_non_sensitive(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            runtime = Path(directory)
            credentials = ProducerCredentials.for_testing()
            write_runtime_credentials(
                runtime_directory=runtime,
                credentials=credentials,
                pid=123,
                port=8765,
                api_version="v1",
            )
            metadata = (
                runtime / RUNTIME_METADATA_FILENAME
            ).read_text(encoding="utf-8")

            self.assertEqual(
                (runtime / ROOM_TOKEN_FILENAME)
                .read_text(encoding="utf-8"),
                "test-room-token",
            )
            self.assertEqual(
                (runtime / CDP_TOKEN_FILENAME)
                .read_text(encoding="utf-8"),
                "test-cdp-token",
            )
            self.assertNotIn("test-room-token", metadata)
            self.assertNotIn("test-cdp-token", metadata)


if __name__ == "__main__":
    unittest.main()
