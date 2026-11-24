"""S4-B defense-in-depth tests for server-sync and synthetic rsync source tree."""
from __future__ import annotations

import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
EXCLUDE = ROOT / "scripts/ops/server-sync.rsync-exclude"
SYNC = ROOT / "scripts/ops/ulgg-server-sync.sh"


class ServerSyncGuardTests(unittest.TestCase):
    def test_exclusion_blocks_entire_steam_data_tree(self):
        self.assertIn("/steam/data/", EXCLUDE.read_text(encoding="utf-8").splitlines())

    def test_stage_guard_rejects_steam_data_changes(self):
        contents = SYNC.read_text(encoding="utf-8")
        self.assertIn("^steam/data/", contents)
        self.assertIn("git diff --cached --name-only", contents)
        self.assertIn("git add -A", contents)

    def test_exclusion_is_not_too_broad(self):
        contents = EXCLUDE.read_text(encoding="utf-8").splitlines()
        self.assertNotIn("/steam/", contents)
        self.assertNotIn("/pages/", contents)
        self.assertNotIn("/assets/", contents)

    def test_server_sync_keeps_rsync_exclusion_hook(self):
        self.assertIn('--exclude-from="$EXCLUDE_FILE"', SYNC.read_text(encoding="utf-8"))

    def test_sync_push_is_after_staged_path_and_secret_checks(self):
        source = SYNC.read_text(encoding="utf-8")
        self.assertLess(source.index("BAD_PATHS="), source.index("git push origin"))
        self.assertLess(source.index("SECRET_HITS="), source.index("git push origin"))

    def test_actual_staged_path_guard_works_on_synthetic_git_index(self):
        bash = shutil.which("bash")
        if not bash:
            self.skipTest("Git Bash missing; CI contains separate shell review")
        contents = SYNC.read_text(encoding="utf-8")
        lines = [s for s in contents.splitlines() if s.startswith('BAD_PATHS=')]
        self.assertEqual(len(lines), 1)
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp)
            def git(*args):
                subprocess.run(["git", "-C", str(repo), *args], check=True,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            git("init", "-q")
            git("config", "user.name", "S4B Synthetic Test")
            git("config", "user.email", "synthetic@example.invalid")
            (repo / "README.md").write_text("synthetic", encoding="utf-8")
            git("add", "README.md")
            git("commit", "-qm", "base")
            sample = repo / "steam/data/channel1_room.json"
            sample.parent.mkdir(parents=True)
            sample.write_text('{"SYNTHETIC": true}', encoding="utf-8")
            git("add", "-f", "steam/data/channel1_room.json")
            stage_check = lines[0] + '\\n' + (
                'if [[ "$BAD_PATHS" == *"steam/data/channel1_room.json"* ]]; '
                'then echo P0_BLOCKED; else echo UNSAFE_ALLOWED; exit 9; fi'
            )
            stage_check = stage_check.replace('\\n', '\n')
            p = subprocess.run([bash, "-c", stage_check], cwd=repo,
                               text=True, capture_output=True)
            self.assertEqual(p.returncode, 0, p.stdout + p.stderr)
            self.assertIn("P0_BLOCKED", p.stdout)

    def test_real_rsync_synthetic_dry_run(self):
        rsync = shutil.which("rsync")
        if not rsync:
            if os.getenv("GITHUB_ACTIONS", "").lower() == "true":
                self.fail("rsync is required for GitHub-hosted P0 safety gate")
            self.skipTest("No rsync on local Windows; mandatory on GitHub Ubuntu CI")
        with tempfile.TemporaryDirectory() as tmp:
            base = Path(tmp)
            src, dest = base / "source", base / "destination"
            for relative in (
                "steam/data/channel1_room.json",
                "steam/data/backup/channel1_room_backup_SYNTHETIC.json",
                "steam/data/nested/status.json",
                "steam/data/import_and_compare.py",
                "pages/health.php",
                "assets/css/example.css",
            ):
                p = src / relative
                p.parent.mkdir(parents=True, exist_ok=True)
                p.write_text("SYNTHETIC_ONLY", encoding="utf-8")
            dest.mkdir()
            cmd = [rsync, "-a", "--dry-run", "--out-format=%n",
                   f"--exclude-from={EXCLUDE}", str(src) + os.sep, str(dest) + os.sep]
            p = subprocess.run(cmd, capture_output=True, text=True)
            self.assertEqual(p.returncode, 0, p.stderr)
            seen = p.stdout.replace("\\", "/")
            self.assertIn("pages/health.php", seen)
            self.assertIn("assets/css/example.css", seen)
            self.assertNotIn("steam/data/", seen)


if __name__ == "__main__":
    unittest.main()
