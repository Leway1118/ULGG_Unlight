"""Cross-platform static safety checks for the ephemeral G2-R4 staging runner."""
from __future__ import annotations

import ast
import re
from pathlib import Path
import unittest


ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / "tests" / "linux_systemd_g2r4_staging.py").read_text(
    encoding="utf-8"
)


class G2R4StagingContract(unittest.TestCase):
    def test_explicit_ephemeral_github_only_guard(self):
        for marker in (
            "BLOCKED_NOT_EXPLICIT_GITHUB_HOSTED_STAGING",
            "BLOCKED_PID1_NOT_SYSTEMD",
            'G2R4_EPHEMERAL_STAGING',
            'RUNNER_ENVIRONMENT',
            'GITHUB_ACTIONS',
        ):
            self.assertIn(marker, SOURCE)

    def test_actual_systemd_lifecycle_not_only_static_analysis(self):
        for marker in (
            '["systemctl", "daemon-reload"]',
            '["systemctl", "start", manager_unit]',
            '["systemctl", "restart", manager_unit]',
            '["systemctl", "stop", unit]',
            'systemd-analyze',
        ):
            self.assertIn(marker, SOURCE)

    def test_private_network_uses_real_runtime_netns_and_no_inet_socket(self):
        for marker in (
            '"/proc/1/comm"',
            'os.stat("/proc/self/ns/net").st_ino',
            '"/proc/{manager_pid}/ns/net"',
            'socket.AF_INET',
            'socket.AF_INET6',
            'G2R4_NET_PROBE ',
        ):
            self.assertIn(marker, SOURCE)

    def test_existing_hardened_templates_are_reused(self):
        for filename in (
            "collector-g2r3-gatekeeper.service.example",
            "collector-g2r3-fixture-worker.service.example",
        ):
            self.assertIn(filename, SOURCE)
        self.assertIn("BLOCKED_TEMPLATE_SECURITY_CHANGED", SOURCE)

    def test_manager_and_worker_have_different_synthetic_ids(self):
        self.assertIn('MGR_ID, WORKER_ID = 12001, 12002', SOURCE)
        self.assertIn('SYNTHETIC_ID = "76561190000000000"', SOURCE)
        self.assertEqual(
            set(re.findall(r"7656119[0-9]{10}", SOURCE)),
            {"76561190000000000"},
        )
        self.assertNotRegex(
            SOURCE,
            r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}",
        )

    def test_rollback_and_writable_state_protection(self):
        for marker in (
            '"rollback"',
            'shutil.rmtree(state, ignore_errors=True)',
            'shutil.rmtree(sandbox, ignore_errors=True)',
            '"groupdel"',
            '"userdel"',
            '"daemon-reload"',
            '"--state-root"',
            'journal.is_file()',
            'BLOCKED_STATE_DIRECTORY_PERMISSIONS',
        ):
            self.assertIn(marker, SOURCE)

    def test_graceful_sigterm_cleanup_preserves_journal(self):
        manager = (ROOT / "run_gatekeeper_fixture.py").read_text(
            encoding="utf-8"
        )
        self.assertIn("signal.SIGTERM", manager)
        self.assertIn("server.shutdown", manager)
        self.assertIn("server.serve_forever", manager)

    def test_never_touches_live_services(self):
        for forbidden in (
            "/run/unlight/tw_auth_session",
            "unlight-raid-player-collector.service",
            "ulgg.online",
            "178.212.35.218",
            "raid_code_input",
        ):
            self.assertNotIn(forbidden, SOURCE)


if __name__ == "__main__":
    unittest.main()
