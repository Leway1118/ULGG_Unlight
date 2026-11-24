"""G2-R2 account scoped lock tests: offline fixtures only."""
from __future__ import annotations

import asyncio
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from raid_bot.safety.account_guard import OfflineAccountGuard, account_key
from raid_bot.safety.cleanup_journal import (
    GateBlocked, PENDING, VERIFIED,
)
from raid_bot.safety.offline_oneshot import (
    OfflineIdentityProof, OfflineOneShotPlan, OfflineOneShotRunner,
)
from run_collector_oneshot_offline import (
    FIXTURE_RAID_CODE, FIXTURE_STEAM_ID,
    FixtureEnricher, FixtureFreshVerifier,
)


class AccountScopeTests(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.repo = self.root / "repo"
        self.repo.mkdir()
        self.state = self.root / "state"
        self.state.mkdir(mode=0o700)
        self.guard = OfflineAccountGuard(self.state, repository_root=self.repo)

    def plan(self, alias, steam_id=FIXTURE_STEAM_ID):
        return OfflineOneShotPlan(
            account_alias=alias,
            steam_id=steam_id,
            target_code=FIXTURE_RAID_CODE,
            allowed_codes=frozenset({FIXTURE_RAID_CODE}),
        )

    def runner(self, alias, steam_id=FIXTURE_STEAM_ID):
        plan=self.plan(alias,steam_id)
        client=FixtureEnricher("success")
        runner=OfflineOneShotRunner(
            plan,self.guard.journal_for(steam_id),
            identity=OfflineIdentityProof(alias,steam_id),
            enricher=client,
            fresh_verifier=FixtureFreshVerifier("success"),
        )
        return runner,client

    def test_different_alias_same_steam_id_same_journal_filename(self):
        journal_a=self.guard.journal_for(FIXTURE_STEAM_ID)
        journal_b=self.guard.journal_for(FIXTURE_STEAM_ID)
        self.assertEqual(journal_a.path,journal_b.path)
        self.assertNotIn(FIXTURE_STEAM_ID,str(journal_a.path))
        self.assertEqual(journal_a.path.parent,self.state)
        self.assertTrue(journal_a.path.name.startswith("account-"))

    async def test_switching_alias_cannot_bypass_existing_verified_journal(self):
        runner_a,client_a=self.runner("ULGG-RAID-TEST-01")
        first=await runner_a.run()
        self.assertEqual(first["state"],VERIFIED)
        runner_b,client_b=self.runner("OTHER_TEST_ALIAS")
        with self.assertRaises(GateBlocked):
            await runner_b.run()
        self.assertEqual(client_a.calls,1)
        self.assertEqual(client_b.calls,0)

    async def test_two_simultaneous_aliases_same_account_only_one_attempt(self):
        a,client_a=self.runner("ULGG-RAID-TEST-01")
        b,client_b=self.runner("OTHER_TEST_ALIAS")
        results=await asyncio.gather(a.run(),b.run(),return_exceptions=True)
        self.assertEqual(sum(isinstance(x,GateBlocked) for x in results),1)
        self.assertEqual(client_a.calls+client_b.calls,1)

    async def test_different_accounts_use_independent_files(self):
        second="76561190000000001"
        a,client_a=self.runner("ULGG-RAID-TEST-01")
        b,client_b=self.runner("OTHER_TEST_ALIAS",second)
        self.assertNotEqual(self.guard.journal_for(FIXTURE_STEAM_ID).path,
                            self.guard.journal_for(second).path)
        result_a,result_b=await asyncio.gather(a.run(),b.run())
        self.assertEqual((result_a["state"],result_b["state"]),(VERIFIED,VERIFIED))
        self.assertEqual((client_a.calls,client_b.calls),(1,1))

    def test_manually_selecting_journal_name_is_not_in_cli(self):
        root=Path(__file__).resolve().parents[1]
        cli=root/"run_collector_oneshot_offline.py"
        content=cli.read_text(encoding="utf-8")
        self.assertNotIn('add_argument("--journal"',content)
        self.assertIn('"--state-root"',content)

    def test_steam_id_bad_format_fails_before_path(self):
        for bad in ("", "A", "76561190000000000\n", "../test", None):
            with self.subTest(value=repr(bad)):
                with self.assertRaises(GateBlocked):
                    self.guard.journal_for(bad)

    def test_existing_pending_file_blocks_after_process_restart(self):
        first=self.guard.journal_for(FIXTURE_STEAM_ID)
        first.reserve(account_alias="ULGG-RAID-TEST-01",raid_code=FIXTURE_RAID_CODE)
        new_process_model=OfflineAccountGuard(self.state,repository_root=self.repo)
        self.assertEqual(
            new_process_model.read_account_state(FIXTURE_STEAM_ID)["state"],
            PENDING,
        )
        with self.assertRaises(GateBlocked):
            new_process_model.journal_for(FIXTURE_STEAM_ID).reserve(
                account_alias="OTHER_TEST_ALIAS",raid_code=FIXTURE_RAID_CODE,
            )

    def test_corrupt_canonical_file_blocks(self):
        journal=self.guard.journal_for(FIXTURE_STEAM_ID)
        journal.path.write_text("{corrupt",encoding="utf-8")
        with self.assertRaises(GateBlocked):
            self.guard.read_account_state(FIXTURE_STEAM_ID)
        with self.assertRaises(GateBlocked):
            journal.reserve(account_alias="ULGG-RAID-TEST-01",raid_code=FIXTURE_RAID_CODE)

    def test_missing_state_directory_rejected_with_safe_error(self):
        with self.assertRaisesRegex(GateBlocked,"account_state_root_unavailable"):
            OfflineAccountGuard(
                self.root / "missing-canonical-state",
                repository_root=self.repo,
            )

    def test_file_as_state_directory_rejected(self):
        file=self.root / "not-a-directory"
        file.write_text("synthetic",encoding="utf-8")
        with self.assertRaises(GateBlocked):
            OfflineAccountGuard(file,repository_root=self.repo)

    def test_state_directory_inside_repo_rejected(self):
        folder=self.repo/"state"
        folder.mkdir(mode=0o700)
        with self.assertRaises(GateBlocked):
            OfflineAccountGuard(folder,repository_root=self.repo)

    def test_state_symlink_root_rejected(self):
        link=self.root/"alias-to-state"
        try:
            link.symlink_to(self.state,target_is_directory=True)
        except (NotImplementedError,OSError):
            self.skipTest("Symlink creation not available")
        with self.assertRaises(GateBlocked):
            OfflineAccountGuard(link,repository_root=self.repo)

    def test_world_writable_state_dir_rejected_on_linux(self):
        if os.name=="nt":
            self.skipTest("Windows ACL is not represented by POSIX mode")
        self.state.chmod(0o777)
        with self.assertRaises(GateBlocked):
            OfflineAccountGuard(self.state,repository_root=self.repo)

    def test_offline_cli_process_replay_blocked_by_derived_account_filename(self):
        cli=Path(__file__).resolve().parents[1]/"run_collector_oneshot_offline.py"
        cmd=[sys.executable,str(cli),"--state-root",str(self.state),
             "--scenario","success"]
        one=subprocess.run(cmd,capture_output=True,text=True,timeout=15)
        two=subprocess.run(cmd,capture_output=True,text=True,timeout=15)
        self.assertEqual(one.returncode,0,one.stderr)
        self.assertEqual(two.returncode,2,two.stderr)
        self.assertIn("GateBlocked",two.stdout)
        self.assertEqual(
            self.guard.read_account_state(FIXTURE_STEAM_ID)["state"],VERIFIED
        )

    def test_cross_process_simultaneous_cli_one_success_one_block(self):
        cli=Path(__file__).resolve().parents[1]/"run_collector_oneshot_offline.py"
        cmd=[sys.executable,str(cli),"--state-root",str(self.state),
             "--scenario","success"]
        a=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        b=subprocess.Popen(cmd,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            out_a,err_a=a.communicate(timeout=15)
            out_b,err_b=b.communicate(timeout=15)
        finally:
            for proc in (a,b):
                if proc.poll() is None:
                    proc.kill()
                    proc.communicate()
        self.assertEqual(sorted((a.returncode,b.returncode)),[0,2],
                         f"results: {a.returncode}, {b.returncode}")
        self.assertEqual(
            self.guard.read_account_state(FIXTURE_STEAM_ID)["state"],VERIFIED
        )
        self.assertNotIn(FIXTURE_STEAM_ID,out_a+out_b+err_a+err_b)
        self.assertNotIn(FIXTURE_RAID_CODE,out_a+out_b+err_a+err_b)


if __name__=="__main__":
    unittest.main()
