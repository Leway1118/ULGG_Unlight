"""Linux-only manager-ledger tests. Explicitly run as a non-root staging UID.

Excluded from the Windows default test_*.py discovery by design.
"""
from __future__ import annotations

import os
from pathlib import Path
import tempfile
import unittest

from raid_bot.safety.cleanup_journal import GateBlocked, VERIFIED, BLOCKED
from raid_bot.safety.gatekeeper_ledger import GatekeeperLedger


STEAM_ID = "76561190000000000"
TARGET = "FIXTURE-ONLY-NOT-A-REAL-RAID"
ALIAS = "ULGG-RAID-TEST-01"
APPROVAL = "I REVIEWED CLEANUP AND AUTHORIZE THIS TEST RESET"


@unittest.skipUnless(
    os.name == "posix" and hasattr(os, "geteuid") and os.geteuid() != 0,
    "Run only in POSIX staging as an unprivileged manager UID"
)
class LinuxGatekeeperOwnerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.ledger = GatekeeperLedger(
            self.root, steam_id=STEAM_ID, fixture_target=TARGET,
        )

    def reserve(self):
        return self.ledger.reserve(account_alias=ALIAS, raid_code=TARGET)

    def test_pending_journal_persists_after_manager_reconstruction(self):
        self.reserve()
        new = GatekeeperLedger(
            self.root, steam_id=STEAM_ID, fixture_target=TARGET
        )
        with self.assertRaises(GateBlocked):
            new.reserve(account_alias="OTHER_TEST_ALIAS", raid_code=TARGET)

    def test_synthetic_success_cannot_self_release(self):
        run_id = self.reserve()
        result = self.ledger.complete(
            run_id, cleanup_verified=True, rank_complete=True, players_count=1,
        )
        self.assertEqual(result["state"], VERIFIED)
        with self.assertRaises(GateBlocked):
            self.reserve()

    def test_bad_target_is_refused_before_journal(self):
        with self.assertRaises(GateBlocked):
            self.ledger.reserve(account_alias=ALIAS,raid_code="REAL_RAID")
        self.assertFalse(self.ledger.journal.path.exists())

    def test_release_requires_fresh_evidence_and_explicit_phrase(self):
        run_id = self.reserve()
        self.ledger.complete(run_id,cleanup_verified=True,rank_complete=True)
        kwargs={
            "operator": "StagingOperator",
            "run_id": run_id,
            "evidence_ref": "FIXTURE/STAGING/VERIFIED",
            "independent_cleanup_confirmed": True,
            "approval_phrase": "not-approved",
        }
        with self.assertRaises(GateBlocked):
            self.ledger.operator_release(**kwargs)
        kwargs["approval_phrase"] = APPROVAL
        kwargs["independent_cleanup_confirmed"] = False
        with self.assertRaises(GateBlocked):
            self.ledger.operator_release(**kwargs)
        self.assertTrue(self.ledger.journal.path.exists())

    def test_release_archives_and_allows_one_new_synthetic_trial(self):
        run_id = self.reserve()
        self.ledger.complete(run_id,cleanup_verified=True,rank_complete=True)
        self.assertEqual(
            self.ledger.operator_release(
                operator="StagingOperator",run_id=run_id,
                evidence_ref="FIXTURE/STAGING/VERIFIED",
                independent_cleanup_confirmed=True,
                approval_phrase=APPROVAL,
            ),
            "MANUALLY_RELEASED_OFFLINE_ONLY",
        )
        self.assertFalse(self.ledger.journal.path.exists())
        self.assertEqual(len(list(self.ledger.archive_dir.glob("*.json"))),1)
        new_id = self.reserve()
        self.assertNotEqual(new_id,run_id)
        with self.assertRaises(GateBlocked):
            self.reserve()

    def test_cleanup_failure_remains_blocked(self):
        run_id = self.reserve()
        state = self.ledger.complete(run_id,cleanup_verified=False)
        self.assertEqual(state["state"], BLOCKED)
        with self.assertRaises(GateBlocked):
            self.reserve()

    def test_crash_after_reserve_audit_before_journal_blocks(self):
        with self.ledger._locked():
            self.ledger._append("RESERVE_INTENT",account_alias=ALIAS)
        self.assertFalse(self.ledger.journal.path.exists())
        with self.assertRaises(GateBlocked):
            self.reserve()

    def test_crash_during_manual_release_after_archive_blocks(self):
        run_id = self.reserve()
        self.ledger.complete(run_id,cleanup_verified=False)
        with self.ledger._locked():
            self.ledger._append(
                "RELEASE_INTENT",run_id=run_id,operator="StagingOperator",
                evidence_ref="FIXTURE/STAGING/VERIFIED",
            )
            self.ledger.archive_dir.mkdir(mode=0o700)
            os.replace(
                self.ledger.journal.path,
                self.ledger.archive_dir / (run_id+".json"),
            )
        self.assertFalse(self.ledger.journal.path.exists())
        with self.assertRaises(GateBlocked):
            self.reserve()

    def test_audit_chain_corruption_blocks(self):
        self.reserve()
        old = self.ledger.audit_path.read_text(encoding="utf-8")
        self.ledger.audit_path.write_text(
            old.replace("RESERVE_DONE","RESERVE_FAKE",1),
            encoding="utf-8",
        )
        with self.assertRaises(GateBlocked):
            self.ledger.status()

    def test_real_credentials_never_enter_audit(self):
        run_id = self.reserve()
        self.ledger.complete(run_id,cleanup_verified=True)
        text=self.ledger.audit_path.read_text(encoding="utf-8")
        self.assertNotIn(STEAM_ID,text)
        self.assertNotIn(TARGET,text)

    def test_ledger_files_owned_by_manager_only(self):
        self.reserve()
        for path in (self.ledger.lock_path,self.ledger.audit_path,
                     self.ledger.journal.path):
            self.assertEqual(path.stat().st_uid,os.geteuid())
            self.assertEqual(path.stat().st_mode & 0o077,0)


if __name__ == "__main__":
    unittest.main()
