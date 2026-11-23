"""Static G2-R3 Linux fixture unit checks for Windows/Linux CI."""
from __future__ import annotations

from pathlib import Path
import unittest


DOCS = Path(__file__).resolve().parents[1] / "docs"


def unit(name):
    path = DOCS / name
    raw = path.read_text(encoding="utf-8")
    obj = {}
    for line in raw.splitlines():
        line = line.strip()
        if line and not line.startswith(("#", "[")) and "=" in line:
            k, v = line.split("=", 1)
            obj[k] = v
    return raw, obj


class G2R3UnitSpecs(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.g_raw, cls.g = unit("collector-g2r3-gatekeeper.service.example")
        cls.w_raw, cls.w = unit(
            "collector-g2r3-fixture-worker.service.example"
        )

    def test_manager_and_worker_have_distinct_nonroot_identities(self):
        self.assertEqual(self.g["User"], "ulgg-g2-gatekeeper")
        self.assertEqual(self.w["User"], "ulgg-g2-worker")
        self.assertNotEqual(self.g["User"], self.w["User"])
        self.assertEqual(self.g["Group"], "ulgg-g2-gatekeeper")
        self.assertEqual(self.w["Group"], "ulgg-g2-worker")

    def test_manager_only_controls_persistent_ledger_directory(self):
        self.assertEqual(self.g["StateDirectory"], "ulgg-g2-ledger")
        self.assertEqual(self.g["StateDirectoryMode"], "0700")
        self.assertEqual(self.g["ReadWritePaths"], "/run/ulgg-g2-socket")
        self.assertEqual(self.g["UMask"], "0077")
        self.assertNotIn("StateDirectory", self.w)
        self.assertNotIn("ReadWritePaths", self.w)
        self.assertIn("--state-root /var/lib/ulgg-g2-ledger",self.g["ExecStart"])
        self.assertNotIn("--state-root", self.w["ExecStart"])

    def test_socket_only_no_network_and_no_restart(self):
        for unit in (self.g, self.w):
            for key, value in {
                "PrivateNetwork":"yes",
                "IPAddressDeny":"any",
                "RestrictAddressFamilies":"AF_UNIX",
                "Restart":"no",
                "NoNewPrivileges":"yes",
                "ProtectSystem":"strict",
                "ProtectHome":"yes",
                "CapabilityBoundingSet":"",
                "AmbientCapabilities":"",
            }.items():
                self.assertEqual(unit[key],value,key)

    def test_worker_only_runs_synthetic_one_shot(self):
        self.assertEqual(self.w["Type"],"oneshot")
        self.assertIn("run_gatekeeper_worker_fixture.py",self.w["ExecStart"])
        self.assertIn("--scenario success",self.w["ExecStart"])
        self.assertNotIn("getid",self.w["ExecStart"].lower())
        self.assertNotIn("raid_player_collector_daemon",self.w["ExecStart"])
        self.assertIn("run_gatekeeper_fixture.py",self.g["ExecStart"])

    def test_never_auto_enable_or_deploy_to_production(self):
        for raw in (self.g_raw,self.w_raw):
            # Ignore documentation comments: only active systemd directives
            # may enable deployment or watcher access.
            active = "\n".join(
                line for line in raw.splitlines()
                if not line.lstrip().startswith("#")
            )
            self.assertNotIn("WantedBy=", active)
            self.assertNotIn("[Install]", active)
            self.assertNotIn("ssh ", active.lower())
            self.assertNotIn("ulgg.online", active)
            self.assertNotIn("/run/unlight/tw_auth_session", active)


if __name__ == "__main__":
    unittest.main()
