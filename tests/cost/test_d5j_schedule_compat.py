"""D5-J local PR safety assertions. These check code shape; do not run legacy importer."""
from __future__ import annotations
import hashlib
import re
import subprocess
import sys
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
BASE_SHA = sys.argv[1] if len(sys.argv)>1 else None
if BASE_SHA and not re.fullmatch(r"[0-9a-f]{40}", BASE_SHA):
    raise SystemExit("D5J_STOP_INVALID_BASE_SHA")

def git_blob(revision: str, path: str) -> bytes:
    r=subprocess.run(["git","show",f"{revision}:{path}"], cwd=REPO,
        check=True,stdout=subprocess.PIPE,stderr=subprocess.PIPE)
    return r.stdout

class ScheduleCompatibility(unittest.TestCase):
    def test_legacy_path_exists(self):
        self.assertTrue((REPO/"scripts/ruleset/import_weekly_cost.php").is_file())

    def test_no_legacy_importer_byte_changes_from_base(self):
        self.assertTrue(BASE_SHA,"BASE_SHA_REQUIRED")
        old=git_blob(BASE_SHA,"scripts/ruleset/import_weekly_cost.php")
        current=git_blob("HEAD","scripts/ruleset/import_weekly_cost.php")
        self.assertEqual(hashlib.sha256(old).digest(),hashlib.sha256(current).digest())

    def test_separate_v2_path_exists(self):
        self.assertTrue((REPO/"scripts/ruleset/import_weekly_cost_v2.php").is_file())

    def test_v2_has_no_production_mode(self):
        src=(REPO/"scripts/ruleset/import_weekly_cost_v2.php").read_text(encoding="utf-8")
        self.assertIn("LOCAL_STAGING_CREDENTIAL_GUARD",src)
        self.assertIn("ulgg_d5i_staging",src)
        self.assertNotIn("config.php",src)

    def test_shadow_noargs_entrypoint_exists(self):
        self.assertTrue((REPO/"scripts/ruleset/cron_weekly_cost_shadow.php").is_file())

    def test_shadow_does_not_connect_or_write_db(self):
        files=[
            "scripts/ruleset/cron_weekly_cost_shadow.php",
            "scripts/ruleset/lib/WeeklyCostShadow.php",
            "scripts/ruleset/lib/WeeklyCostSource.php",
            "scripts/ruleset/lib/WeeklyCostPolicy.php",
        ]
        text="\n".join((REPO/f).read_text(encoding="utf-8") for f in files)
        for banned in ("config.php","new PDO(", "PDO::", "file_put_contents(",
                       "unlink(", "fopen(", "INSERT INTO ", "UPDATE quickmatch", "DELETE FROM "):
            with self.subTest(banned=banned):
                self.assertNotIn(banned,text)

    def test_default_mode_live_readonly(self):
        src=(REPO/"scripts/ruleset/cron_weekly_cost_shadow.php").read_text(encoding="utf-8")
        self.assertIn("weeklyCostFetchNews(100)",src)
        self.assertIn("weeklyCostShadowReport(",src)
        self.assertIn("UNSUPPORTED_SHADOW_OPTION",src)

    def test_stage_db_hard_pinned(self):
        src=(REPO/"scripts/ruleset/lib/WeeklyCostStaging.php").read_text(encoding="utf-8")
        self.assertIn("ulgg_d5i_staging",src)
        self.assertIn("STAGING_IDENTITY_GUARD_FAIL",src)
        self.assertIn("127.0.0.1",src)
        self.assertNotIn("ON DUPLICATE KEY UPDATE",src)

    def test_legacy_cron_and_new_shadow_are_separate(self):
        old=(REPO/"scripts/ruleset/import_weekly_cost.php").read_text(encoding="utf-8")
        shadow=(REPO/"scripts/ruleset/cron_weekly_cost_shadow.php").read_text(encoding="utf-8")
        self.assertIn("getUnlightSteamNews(10)",old)
        self.assertNotIn("getUnlightSteamNews(",shadow)
        self.assertIn("weeklyCostFetchNews(100)",shadow)

if __name__=="__main__":
    # Stop unittest from treating BASE_SHA as a test selector.
    sys.argv=[sys.argv[0]]
    unittest.main(verbosity=2)
