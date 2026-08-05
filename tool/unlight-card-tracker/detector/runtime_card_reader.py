"""Legacy compatibility for the Steam Electron runtime card reader."""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from clients.electron.unlight.auto_player.runtime_card_reader import (  # noqa: E402
    READ_CARDS_JS,
    TYPE_MAP,
    read_cards,
)

__all__ = ["READ_CARDS_JS", "TYPE_MAP", "read_cards"]
