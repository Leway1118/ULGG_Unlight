"""Offline News Timeline tests; never connects to the live website or DB."""
from pathlib import Path
import subprocess
import sys
import unittest


class NewsTimelineTests(unittest.TestCase):
    def test_php_sqlite(self):
        root = Path(__file__).resolve().parents[2]
        result = subprocess.run(["php", str(root / "tests/news/test_news.php")], capture_output=True, text=True, timeout=60)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_asset_hook(self):
        root = Path(__file__).resolve().parents[2]
        result = subprocess.run([sys.executable, str(root / "tests/news/test_auto_news.py")], capture_output=True, text=True, timeout=60)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
