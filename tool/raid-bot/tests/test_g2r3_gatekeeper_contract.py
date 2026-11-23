"""Offline G2-R3 contracts; real POSIX UID split tested separately on Linux."""
from __future__ import annotations

import ast
import os
from pathlib import Path
import re
import unittest
from unittest.mock import Mock

from raid_bot.safety.cleanup_journal import GateBlocked
from raid_bot.safety.gatekeeper_ledger import GatekeeperLedger
from raid_bot.safety.gatekeeper_socket import (
    OfflineGatekeeperClient, OfflineGatekeeperServer
)


class GatekeeperBoundaryContract(unittest.TestCase):
    def setUp(self):
        self.root = Path(__file__).resolve().parents[1]
        self.ledger_source = (
            self.root / "raid_bot" / "safety" / "gatekeeper_ledger.py"
        ).read_text(encoding="utf-8")
        self.socket_source = (
            self.root / "raid_bot" / "safety" / "gatekeeper_socket.py"
        ).read_text(encoding="utf-8")
        self.worker_source = (
            self.root / "run_gatekeeper_worker_fixture.py"
        ).read_text(encoding="utf-8")
        self.manager_source = (
            self.root / "run_gatekeeper_fixture.py"
        ).read_text(encoding="utf-8")

    def test_worker_api_does_not_expose_unlock_or_release(self):
        tree = ast.parse(self.socket_source)
        handler = next(
            x for x in tree.body
            if isinstance(x, ast.ClassDef)
            and x.name == "_FixtureWorkerHandler"
        )
        method = next(x for x in handler.body if getattr(x, "name", "") == "handle")
        commands = {
            node.value
            for node in ast.walk(method)
            if isinstance(node, ast.Constant) and isinstance(node.value, str)
        }
        for cmd in ("status", "reserve", "complete"):
            self.assertIn(cmd, commands)
        for cmd in ("release", "operator-release", "unlock", "reset", "clear"):
            self.assertNotIn(cmd, commands)

    def test_release_exists_only_on_manager_side(self):
        self.assertIn("def operator_release(", self.ledger_source)
        self.assertIn('sub.add_parser(\n        "operator-release"', self.manager_source)
        self.assertNotIn('"operator-release"', self.worker_source)
        self.assertNotIn('"operator-release"', self.socket_source)

    def test_release_needs_operator_evidence_and_exact_phrase(self):
        self.assertIn("human_review_evidence_required",self.ledger_source)
        self.assertIn("independent_cleanup_confirmed is not True",self.ledger_source)
        self.assertIn(
            "I REVIEWED CLEANUP AND AUTHORIZE THIS TEST RESET",self.ledger_source
        )

    def test_manager_has_write_ahead_and_audit_chain(self):
        for event in ("RESERVE_INTENT", "RESERVE_DONE", "RELEASE_INTENT", "RELEASE_DONE"):
            self.assertIn(f'"{event}"', self.ledger_source)
        self.assertIn("audit_chain_invalid",self.ledger_source)
        self.assertIn("unresolved_account_history",self.ledger_source)
        self.assertIn("os.fsync",self.ledger_source)

    def test_manager_does_not_accept_real_raid_target(self):
        self.assertIn("only_synthetic_target_supported",self.ledger_source)
        self.assertIn('FIXTURE_TARGET = "FIXTURE-ONLY-',self.manager_source)
        self.assertNotIn("raid_player_collector_daemon",self.manager_source)

    def test_worker_cannot_select_state_root_or_steam_id(self):
        self.assertNotIn("--state-root",self.worker_source)
        self.assertNotIn("--steam-id",self.worker_source)
        self.assertNotIn("operator_release(",self.worker_source)
        self.assertNotIn("getid",self.worker_source.lower())
        self.assertNotIn("websocket",self.worker_source.lower())

    def test_server_requires_exact_linux_peer_uid(self):
        self.assertIn("SO_PEERCRED",self.socket_source)
        self.assertIn("peer_uid != self.server.worker_uid",self.socket_source)
        self.assertIn("gatekeeper_requires_separate_nonroot_identity",self.socket_source)
        self.assertIn("os.chmod(str(raw), 0o660)",self.socket_source)

    def test_windows_cannot_create_gatekeeper_ledger(self):
        if os.name != "nt":
            self.skipTest("Windows-specific fail-closed boundary")
        with self.assertRaisesRegex(GateBlocked,"linux_manager_required"):
            GatekeeperLedger(
                Path("C:/not-real"),steam_id="76561190000000000",
                fixture_target="FIXTURE-ONLY-XYZ",
            )

    def test_windows_cannot_open_gatekeeper_socket(self):
        if os.name != "nt":
            self.skipTest("Windows-specific fail-closed boundary")
        with self.assertRaisesRegex(GateBlocked,"linux_peercred_required"):
            OfflineGatekeeperServer(
                Path("C:/synthetic/fixture.sock"),
                ledger=Mock(),worker_uid=1234,worker_gid=1234,
            )
        with self.assertRaisesRegex(GateBlocked,"linux_unix_client_required"):
            OfflineGatekeeperClient(Path("C:/synthetic/fixture.sock")).request(
                "status"
            )

    def test_no_actual_account_ids_added(self):
        for source in (
            self.ledger_source, self.socket_source,
            self.worker_source, self.manager_source
        ):
            for steam_id in re.findall(r"7656119[0-9]{10}", source):
                self.assertEqual(steam_id, "76561190000000000")
            self.assertNotRegex(
                source,
                r"(?i)[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-"
                r"[0-9a-f]{4}-[0-9a-f]{12}",
            )


if __name__ == "__main__":
    unittest.main()
