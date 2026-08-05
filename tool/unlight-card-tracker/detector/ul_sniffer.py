"""Legacy import compatibility for the relocated web CDP sniffer."""

import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from core.runtime.cdp.ul_sniffer import (  # noqa: E402
    ConnectCallback,
    FrameCallback,
    ULSniffer,
)

__all__ = ["ConnectCallback", "FrameCallback", "ULSniffer"]
