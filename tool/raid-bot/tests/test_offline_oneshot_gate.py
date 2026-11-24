"""G2 safety tests: synthetic account, fake client, no external socket."""
from __future__ import annotations

import asyncio
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from raid_bot.safety.cleanup_journal import (
    BLOCKED, PENDING, VERIFIED, VERIFIED_NO_RANK,
    GateBlocked, PersistentCleanupJournal,
)
from raid_bot.safety.dedicated_account import (
    DedicatedAccountConfig, DedicatedAuthProvider,
)
from raid_bot.safety.offline_oneshot import (
    OfflineIdentityProof, OfflineOneShotPlan, OfflineOneShotRunner,
)
from run_collector_oneshot_offline import (
    FixtureEnricher, FixtureFreshVerifier, FIXTURE_RAID_CODE,
    FIXTURE_STEAM_ID,
)


class OneShotTestBase(unittest.IsolatedAsyncioTestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / "blocked-until-operator.json"
        self.plan = OfflineOneShotPlan(
            account_alias="ULGG-RAID-TEST-01",
            steam_id=FIXTURE_STEAM_ID,
            target_code=FIXTURE_RAID_CODE,
            allowed_codes=frozenset({FIXTURE_RAID_CODE}),
            timeout_seconds=0.5,
        )

    def make_runner(self, scenario="success", journal=None):
        enricher = FixtureEnricher(scenario)
        verifier = FixtureFreshVerifier(scenario)
        controller = OfflineOneShotRunner(
            self.plan,
            journal or PersistentCleanupJournal(self.path),
            identity=OfflineIdentityProof(
                self.plan.account_alias, self.plan.steam_id
            ),
            enricher=enricher, fresh_verifier=verifier,
        )
        return controller, enricher, verifier

    async def test_success_needs_fresh_absence_and_persists(self):
        runner, client, verifier = self.make_runner()
        result = await runner.run()
        self.assertEqual(result["state"], VERIFIED)
        self.assertTrue(result["cleanup_verified"])
        self.assertEqual(result["players_count"], 1)
        self.assertEqual(client.calls, 1)
        self.assertEqual(verifier.calls, 1)
        # New journal instance simulates restart/re-read.
        state = PersistentCleanupJournal(self.path).read_public_state()
        self.assertEqual(state["state"], VERIFIED)
        raw = self.path.read_text(encoding="utf-8")
        self.assertNotIn(FIXTURE_RAID_CODE, raw)
        self.assertNotIn(FIXTURE_STEAM_ID, raw)
        self.assertNotIn("synthetic-raid", raw)
        self.assertNotIn("Fixture", raw)
        if os.name != "nt":
            self.assertEqual(self.path.stat().st_mode & 0o077, 0)

    async def test_restarting_finished_run_cannot_join_again(self):
        first, client, _ = self.make_runner()
        await first.run()
        second, second_client, _ = self.make_runner()
        with self.assertRaises(GateBlocked):
            await second.run()
        self.assertEqual(client.calls, 1)
        self.assertEqual(second_client.calls, 0)

    async def test_join_rejection_keeps_persistent_block(self):
        runner, _, verifier = self.make_runner("join-rejected")
        state = await runner.run()
        self.assertEqual(state["state"], BLOCKED)
        self.assertFalse(state["cleanup_verified"])
        self.assertEqual(verifier.calls, 0)
        with self.assertRaises(GateBlocked):
            await self.make_runner()[0].run()

    async def test_cleanup_failure_keeps_block(self):
        result = await self.make_runner("cleanup-failed")[0].run()
        self.assertEqual(result["state"], BLOCKED)

    async def test_fresh_verification_failure_keeps_block(self):
        runner, enricher, verifier = self.make_runner("verification-failed")
        result = await runner.run()
        self.assertEqual(result["state"], BLOCKED)
        self.assertEqual(enricher.calls, 1)
        self.assertEqual(verifier.calls, 1)

    async def test_rank_unknown_keeps_cleanup_verified_but_no_stats(self):
        result = await self.make_runner("unknown-rank")[0].run()
        self.assertEqual(result["state"], VERIFIED_NO_RANK)
        self.assertTrue(result["cleanup_verified"])
        self.assertFalse(result["rank_complete"])
        self.assertIsNone(result["players_count"])

    async def test_exception_still_persists_block(self):
        with self.assertRaises(RuntimeError):
            await self.make_runner("exception")[0].run()
        state = PersistentCleanupJournal(self.path).read_public_state()
        self.assertEqual(state["state"], BLOCKED)

    async def test_timeout_still_persists_block(self):
        self.plan = OfflineOneShotPlan(
            self.plan.account_alias, self.plan.steam_id,
            self.plan.target_code, self.plan.allowed_codes,
            timeout_seconds=0.12,
        )
        with self.assertRaises(TimeoutError):
            await self.make_runner("timeout")[0].run()
        state = PersistentCleanupJournal(self.path).read_public_state()
        self.assertEqual(state["state"], BLOCKED)

    async def test_cancellation_keeps_block_and_never_repeats(self):
        class WaitingFixture:
            offline_fixture = True
            calls = 0
            async def enrich(self, code):
                self.calls += 1
                await asyncio.Event().wait()

        fake = WaitingFixture()
        runner = OfflineOneShotRunner(
            self.plan, PersistentCleanupJournal(self.path),
            identity=OfflineIdentityProof(self.plan.account_alias, self.plan.steam_id),
            enricher=fake, fresh_verifier=FixtureFreshVerifier("success"),
        )
        task = asyncio.create_task(runner.run())
        for _ in range(100):
            if fake.calls:
                break
            await asyncio.sleep(0.001)
        self.assertEqual(fake.calls, 1)
        task.cancel()
        with self.assertRaises(asyncio.CancelledError):
            await task
        self.assertEqual(
            PersistentCleanupJournal(self.path).read_public_state()["state"],
            BLOCKED,
        )
        with self.assertRaises(GateBlocked):
            await self.make_runner()[0].run()

    async def test_parallel_launches_allow_exactly_one_attempt(self):
        r1, c1, _ = self.make_runner()
        r2, c2, _ = self.make_runner()
        results = await asyncio.gather(
            r1.run(), r2.run(), return_exceptions=True,
        )
        self.assertEqual(sum(isinstance(r, GateBlocked) for r in results), 1)
        self.assertEqual(c1.calls + c2.calls, 1)

    async def test_missing_journal_directory_blocks_before_join(self):
        journal = PersistentCleanupJournal(
            Path(self.temp.name) / "not-created" / "journal.json"
        )
        runner, client, _ = self.make_runner(journal=journal)
        with self.assertRaises(GateBlocked):
            await runner.run()
        self.assertEqual(client.calls, 0)

    async def test_bad_existing_journal_blocks_all_attempts(self):
        self.path.write_text("{invalid_json", encoding="utf-8")
        self.assertRaises(
            GateBlocked, PersistentCleanupJournal(self.path).read_public_state,
        )
        runner, client, _ = self.make_runner()
        with self.assertRaises(GateBlocked):
            await runner.run()
        self.assertEqual(client.calls, 0)

    async def test_non_allowlisted_target_blocks_before_journal(self):
        self.plan = OfflineOneShotPlan(
            self.plan.account_alias, self.plan.steam_id,
            "NOT-THE-ALLOWED-CODE", self.plan.allowed_codes,
        )
        with self.assertRaises(GateBlocked):
            self.make_runner()
        self.assertFalse(self.path.exists())

    async def test_multiple_allowed_codes_are_forbidden(self):
        self.plan = OfflineOneShotPlan(
            self.plan.account_alias, self.plan.steam_id,
            self.plan.target_code, frozenset({
                self.plan.target_code, "a-second-code",
            }),
        )
        with self.assertRaises(GateBlocked):
            self.make_runner()
        self.assertFalse(self.path.exists())

    async def test_live_mode_is_not_implemented(self):
        self.plan = OfflineOneShotPlan(
            self.plan.account_alias, self.plan.steam_id,
            self.plan.target_code, self.plan.allowed_codes,
            mode="live",
        )
        with self.assertRaises(GateBlocked):
            self.make_runner()

    async def test_bad_steam_identity_blocks_before_journal(self):
        enricher = FixtureEnricher("success")
        with self.assertRaises(GateBlocked):
            OfflineOneShotRunner(
                self.plan, PersistentCleanupJournal(self.path),
                identity=OfflineIdentityProof(self.plan.account_alias, "76561190000000001"),
                enricher=enricher,
                fresh_verifier=FixtureFreshVerifier("success"),
            )
        self.assertFalse(self.path.exists())
        self.assertEqual(enricher.calls, 0)

    async def test_network_capable_client_without_fixture_marker_rejected(self):
        class UnknownClient:
            async def enrich(self, code):
                raise AssertionError("must not reach this")

        with self.assertRaises(GateBlocked):
            OfflineOneShotRunner(
                self.plan, PersistentCleanupJournal(self.path),
                identity=OfflineIdentityProof(self.plan.account_alias, self.plan.steam_id),
                enricher=UnknownClient(),
                fresh_verifier=FixtureFreshVerifier("success"),
            )

    async def test_verification_cannot_use_same_client(self):
        client = FixtureEnricher("success")
        with self.assertRaises(GateBlocked):
            OfflineOneShotRunner(
                self.plan, PersistentCleanupJournal(self.path),
                identity=OfflineIdentityProof(self.plan.account_alias, self.plan.steam_id),
                enricher=client, fresh_verifier=client,
            )

    async def test_existing_pending_state_blocks_after_restart(self):
        journal = PersistentCleanupJournal(self.path)
        journal.reserve(account_alias=self.plan.account_alias,raid_code=self.plan.target_code)
        self.assertEqual(journal.read_public_state()["state"], PENDING)
        runner, client, _ = self.make_runner()
        with self.assertRaises(GateBlocked):
            await runner.run()
        self.assertEqual(client.calls, 0)

    async def test_operator_cannot_transition_old_terminal_journal(self):
        journal = PersistentCleanupJournal(self.path)
        run_id = journal.reserve(
            account_alias=self.plan.account_alias,raid_code=self.plan.target_code,
        )
        journal.transition(run_id, state=VERIFIED,cleanup_verified=True)
        with self.assertRaises(GateBlocked):
            journal.transition(run_id,state=VERIFIED,cleanup_verified=True)


class ActualProtocolFixtureIntegration(unittest.IsolatedAsyncioTestCase):
    """Exercise the real protocol client with fake sockets plus disk journal."""

    def setUp(self):
        OneShotTestBase.setUp(self)

    async def test_actual_protocol16_join_delete_fresh_verify_one_shot(self):
        from tests.test_protocol16_collector_flow import (
            FakeSocket, baseline, evt, joined_row, collector,
        )
        primary = FakeSocket(baseline() + [
            evt("raid_code_input", [True]),
            evt("db_raid", [[joined_row()]]),
            evt("raid_delete", [True]),
            evt("db_raid", [[]]),
        ])
        fresh = FakeSocket(baseline())

        class EnrichmentAdapter:
            offline_fixture = True
            async def enrich(self, code):
                return await collector(primary).enrich(code)

        class VerificationAdapter:
            offline_fixture = True
            async def verify_profound_id_absent(self, raid_id):
                return await collector(fresh).verify_profound_id_absent(raid_id)

        controller = OfflineOneShotRunner(
            self.plan, PersistentCleanupJournal(self.path),
            identity=OfflineIdentityProof(self.plan.account_alias, self.plan.steam_id),
            enricher=EnrichmentAdapter(),fresh_verifier=VerificationAdapter(),
        )
        result = await controller.run()
        self.assertEqual(result["state"], VERIFIED)
        events = [x["event"] for x in primary.sent]
        self.assertEqual(events.count("raid_code_input"), 1)
        self.assertEqual(events.count("raid_delete"), 1)
        fresh_events = [x["event"] for x in fresh.sent]
        self.assertEqual(fresh_events, [
            "__handshake_c", "register", "online", "db_raid",
        ])
        self.assertNotIn(FIXTURE_RAID_CODE, self.path.read_text(encoding="utf-8"))
        with self.assertRaises(GateBlocked):
            await controller.run()

    async def test_fresh_verifier_still_sees_raid_and_blocks(self):
        from tests.test_protocol16_collector_flow import (
            FakeSocket, baseline, evt, joined_row, collector,
        )
        primary = FakeSocket(baseline() + [
            evt("raid_code_input", [True]),
            evt("db_raid", [[joined_row()]]),
            evt("raid_delete", [True]),
            evt("db_raid", [[]]),
        ])
        fresh = FakeSocket(baseline()[:-1] + [
            evt("db_raid", [[joined_row()]])
        ])

        class EnrichmentAdapter:
            offline_fixture = True
            async def enrich(self, code):
                return await collector(primary).enrich(code)

        class VerificationAdapter:
            offline_fixture = True
            async def verify_profound_id_absent(self, raid_id):
                return await collector(fresh).verify_profound_id_absent(raid_id)

        controller = OfflineOneShotRunner(
            self.plan, PersistentCleanupJournal(self.path),
            identity=OfflineIdentityProof(self.plan.account_alias, self.plan.steam_id),
            enricher=EnrichmentAdapter(),fresh_verifier=VerificationAdapter(),
        )
        result = await controller.run()
        self.assertEqual(result["state"], BLOCKED)
        self.assertFalse(result["cleanup_verified"])
        self.assertEqual(
            [x["event"] for x in fresh.sent].count("raid_delete"), 0
        )


class DedicatedAccountTestCases(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.base = Path(self.temp.name)
        self.repo = self.base / "repo"
        self.repo.mkdir()
        self.secret = self.base / "dedicated-secret.txt"
        self.config = DedicatedAccountConfig(
            "ULGG-RAID-TEST-01", FIXTURE_STEAM_ID, self.secret
        )

    def test_dedicated_path_outside_repo_allowed_for_preflight(self):
        self.config.validate(self.repo)
        self.assertFalse(self.secret.exists())

    def test_credential_inside_git_checkout_rejected(self):
        config = DedicatedAccountConfig(
            self.config.alias, self.config.steam_id,
            self.repo / "dont-store-credentials-here.txt",
        )
        with self.assertRaisesRegex(ValueError, "within_repository"):
            config.validate(self.repo)

    def test_watcher_owned_source_path_rejected(self):
        with patch.dict(os.environ, {
            "RAID_PLAYER_SHARED_TW_AUTH_FILE": str(self.secret),
        }):
            with self.assertRaisesRegex(ValueError, "shared_auth"):
                self.config.validate(self.repo)

    def test_windows_credentials_must_fail_closed_without_acl_audit(self):
        if os.name != "nt":
            self.skipTest("Only meaningful with Windows ACL")
        self.secret.write_text("00000000-0000-4000-8000-000000000000")
        provider = DedicatedAuthProvider(self.config,self.repo)
        with self.assertRaises(PermissionError):
            asyncio.run(provider.get())


if __name__ == "__main__":
    unittest.main()
