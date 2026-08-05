"""Legacy compatibility for Steam Electron Auto Player actions."""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from clients.electron.unlight.auto_player.game_actions import GameActions  # noqa: E402

__all__ = ["GameActions"]
