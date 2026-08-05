"""Legacy compatibility for Steam Electron Auto Player card actions."""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from clients.electron.unlight.auto_player.card_actions import (  # noqa: E402
    CARD_SELECTION_CONFIRMATION_TIMEOUT_MS,
    CardActions,
    build_select_card_script,
)

__all__ = [
    "CARD_SELECTION_CONFIRMATION_TIMEOUT_MS",
    "CardActions",
    "build_select_card_script",
]
