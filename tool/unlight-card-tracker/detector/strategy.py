"""Legacy compatibility for the Steam Electron Auto Player strategy."""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from clients.electron.unlight.auto_player.strategy import (  # noqa: E402
    choose_attack_card,
    choose_defense_card,
    choose_move,
)

__all__ = ["choose_attack_card", "choose_defense_card", "choose_move"]
