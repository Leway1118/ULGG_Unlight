"""Run standalone Card Assets release-control tests without production access."""
from pathlib import Path
import subprocess
import sys
import unittest


class CardAssetsRuntimeTests(unittest.TestCase):
    def test_release_control_fixtures(self):
        root = Path(__file__).resolve().parents[2] / "scripts" / "ops" / "card-assets"
        result = subprocess.run(
            [sys.executable, "-m", "unittest", "-v", "test_http_fix.py", "test_runtime_fix.py"],
            cwd=root,
            capture_output=True,
            text=True,
            timeout=60,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
