"""Isolated, dependency-free checks for scripts/ci/pr_guard.py."""
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts" / "ci"))
from pr_guard import added_lines_with_secrets, forbidden_path


class GuardTests(unittest.TestCase):
    def test_sensitive_paths_blocked(self):
        for path in (
            ".env", "tool/raid-bot/.env.production", "assets/uploads/photo.png",
            "tool/raid-bot/data/live.json", "vendor/autoload.php",
            "database/Dump20261027/dump.sql", "notes/id_ed25519",
            "logs/debug.jsonl", "backup.before_20261027",
        ):
            with self.subTest(path=path):
                self.assertTrue(forbidden_path(path))

    def test_normal_source_and_template_paths_allowed(self):
        for path in (
            ".env.example", "pages/index.php", "scripts/ci/pr_guard.py",
            ".github/workflows/ci.yml", "docs/portfolio/README.md",
            "tests/ci/test_pr_guard.py", "sql/migrations/001.sql",
        ):
            with self.subTest(path=path):
                self.assertFalse(forbidden_path(path))

    def test_secret_detection_only_added_lines(self):
        fake = "ghp_" + "A" * 24
        patch = (
            "diff --git a/foo b/foo\n"
            "--- a/foo\n+++ b/foo\n"
            "-old = " + fake + "\n"
            "+new = hello\n"
        )
        self.assertEqual(added_lines_with_secrets(patch), [])
        self.assertEqual(len(added_lines_with_secrets("+token = " + fake + "\n")), 1)

    def test_webhook_and_key_signature(self):
        url = "https://discord.com/api/" + "webhooks/12345/" + "A" * 20
        key = "-----BEGIN " + "OPENSSH PRIVATE KEY-----"
        self.assertEqual(len(added_lines_with_secrets("+" + url + "\n+" + key)), 2)


if __name__ == "__main__":
    unittest.main()
