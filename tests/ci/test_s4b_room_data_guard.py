"""S4-B: synthetic-only room payload rejection tests. No real player data."""
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(REPO / "scripts" / "ci"))
import pr_guard

SYNTHETIC = {
    "SYNTHETIC_ROOM_ONLY": {
        "playerA": "SYNTHETIC_A",
        "playerB": "SYNTHETIC_B",
        "deckA": ["TEST_CARD_A"],
        "deckB": ["TEST_CARD_B"],
        "pass": False,
    }
}


def git(directory: Path, *args: str) -> str:
    p = subprocess.run(
        ["git", "-C", str(directory), *args],
        text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE, check=True,
    )
    return p.stdout.strip()


class RoomDataGuardTests(unittest.TestCase):
    def test_known_raw_room_paths_rejected(self):
        for p in (
            "steam/data/channel1_room.json",
            "steam/data/channel2_room.json",
            "steam/data/backup/channel1_room_backup_20250415_085211.json",
            "steam/data/nested/backup/archive.json",
            "STEAM/DATA/channel1_room.JSON",
            r"steam\data\channel1_room.json",
        ):
            with self.subTest(p=p):
                self.assertTrue(pr_guard.forbidden_path(p))

    def test_other_steam_source_code_is_allowed(self):
        for p in ("steam/data/import_and_compare.py", "steam/data/.env.example",
                  "scripts/ci/pr_guard.py", "tests/fixtures/synthetic_config.json"):
            with self.subTest(p=p):
                self.assertFalse(pr_guard.forbidden_path(p))

    def test_non_room_json_is_not_sensitive(self):
        self.assertFalse(pr_guard.contains_room_record(b'{"test": 1, "module": "safe"}'))

    def test_nested_room_dictionary_is_sensitive(self):
        content = json.dumps(SYNTHETIC).encode()
        self.assertTrue(pr_guard.contains_room_record(content))

    def test_room_array_is_sensitive(self):
        content = json.dumps([SYNTHETIC["SYNTHETIC_ROOM_ONLY"]]).encode()
        self.assertTrue(pr_guard.contains_room_record(content))

    def test_unrelated_field_names_are_not_sufficient(self):
        self.assertFalse(pr_guard.contains_room_record(
            b'{"playerA": "fixture", "playerB": "fixture", "schema": "test"}'
        ))

    def test_room_payload_not_printed_by_guard(self):
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp)
            git(repo, "init", "-q")
            git(repo, "config", "user.name", "S4B Local Test")
            git(repo, "config", "user.email", "tests@example.invalid")
            (repo / "README.md").write_text("synthetic test", encoding="utf-8")
            git(repo, "add", "README.md")
            git(repo, "commit", "-qm", "init")
            base = git(repo, "rev-parse", "HEAD")
            (repo / "docs").mkdir()
            (repo / "docs" / "export.json").write_text(
                json.dumps(SYNTHETIC), encoding="utf-8"
            )
            git(repo, "add", "-A")
            git(repo, "commit", "-qm", "synthetic source")
            x = subprocess.run(
                [sys.executable, str(REPO / "scripts/ci/pr_guard.py"), base],
                cwd=repo, text=True, capture_output=True,
            )
            self.assertEqual(x.returncode, 1, x.stdout + x.stderr)
            self.assertIn("room", x.stderr.lower())
            self.assertNotIn("SYNTHETIC_A", x.stdout + x.stderr)
            self.assertNotIn("TEST_CARD_A", x.stdout + x.stderr)

    def test_changed_safe_json_passes(self):
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp)
            git(repo, "init", "-q")
            git(repo, "config", "user.name", "S4B Local Test")
            git(repo, "config", "user.email", "tests@example.invalid")
            (repo / "README.md").write_text("synthetic test", encoding="utf-8")
            git(repo, "add", ".")
            git(repo, "commit", "-qm", "init")
            base = git(repo, "rev-parse", "HEAD")
            (repo / "tests").mkdir()
            (repo / "tests" / "fixture.json").write_text(
                '{"test": true}', encoding="utf-8"
            )
            git(repo, "add", ".")
            git(repo, "commit", "-qm", "safe json")
            x = subprocess.run(
                [sys.executable, str(REPO / "scripts/ci/pr_guard.py"), base],
                cwd=repo, text=True, capture_output=True,
            )
            self.assertEqual(x.returncode, 0, x.stdout + x.stderr)

    def test_future_deletion_is_not_prohibited(self):
        """A future authorized remediation PR may remove a snapshot from HEAD."""
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp)
            git(repo, "init", "-q")
            git(repo, "config", "user.name", "S4B Local Test")
            git(repo, "config", "user.email", "tests@example.invalid")
            p = repo / "steam/data"
            p.mkdir(parents=True)
            (p / "channel1_room.json").write_text(
                json.dumps(SYNTHETIC), encoding="utf-8"
            )
            git(repo, "add", "-f", "steam/data/channel1_room.json")
            git(repo, "commit", "-qm", "synthetic history")
            base = git(repo, "rev-parse", "HEAD")
            git(repo, "rm", "steam/data/channel1_room.json")
            git(repo, "commit", "-qm", "synthetic HEAD cleanup")
            x = subprocess.run(
                [sys.executable, str(REPO / "scripts/ci/pr_guard.py"), base],
                cwd=repo, text=True, capture_output=True,
            )
            self.assertEqual(x.returncode, 0, x.stdout + x.stderr)


if __name__ == "__main__":
    unittest.main()
