"""Static checks of the NEVER-DEPLOY-PRODUCTION G2 Linux fixture unit."""
from __future__ import annotations

import unittest
from pathlib import Path

EXAMPLE = (
    Path(__file__).resolve().parents[1]
    / "docs" / "collector-g2-offline.service.example"
)


class LinuxOfflineSandboxSpecTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        content = EXAMPLE.read_text(encoding="utf-8")
        cls.settings = {}
        for line in content.splitlines():
            line=line.strip()
            if line and not line.startswith(("#", "[")) and "=" in line:
                key,value=line.split("=",1)
                cls.settings[key]=value.strip()

    def test_synthetic_scenario_no_live_adapter(self):
        cmd=self.settings["ExecStart"]
        self.assertIn("--scenario success",cmd)
        self.assertIn("run_collector_oneshot_offline.py",cmd)
        self.assertNotIn("getid",cmd)
        self.assertNotIn("raid_player_collector_daemon",cmd)

    def test_state_root_is_pinned_in_unit_not_caller_selected(self):
        self.assertEqual(
            self.settings["StateDirectory"],"ulgg-raid-g2-offline"
        )
        self.assertEqual(self.settings["StateDirectoryMode"],"0700")
        self.assertEqual(self.settings["UMask"],"0077")
        self.assertIn("--state-root /var/lib/ulgg-raid-g2-offline",
                      self.settings["ExecStart"])

    def test_no_restart_and_unprivileged_dynamic_user(self):
        self.assertEqual(self.settings["Type"],"oneshot")
        self.assertEqual(self.settings["Restart"],"no")
        self.assertEqual(self.settings["DynamicUser"],"yes")
        self.assertEqual(self.settings["NoNewPrivileges"],"yes")
        self.assertEqual(self.settings["CapabilityBoundingSet"],"")

    def test_no_tcp_or_udp_and_non_writable_repo(self):
        self.assertEqual(self.settings["PrivateNetwork"],"yes")
        self.assertEqual(self.settings["IPAddressDeny"],"any")
        self.assertEqual(self.settings["RestrictAddressFamilies"],"AF_UNIX")
        self.assertEqual(self.settings["ProtectSystem"],"strict")
        self.assertEqual(self.settings["ProtectHome"],"yes")

    def test_hardened_execution_and_no_enable_target(self):
        text=EXAMPLE.read_text(encoding="utf-8")
        self.assertNotIn("WantedBy=",text)
        self.assertEqual(self.settings["PrivateTmp"],"yes")
        self.assertEqual(self.settings["PrivateDevices"],"yes")
        self.assertEqual(self.settings["RestrictNamespaces"],"yes")
        self.assertEqual(self.settings["MemoryDenyWriteExecute"],"yes")


if __name__=="__main__":
    unittest.main()
