"""OFFLINE synthetic worker using gatekeeper RPC, never a game client.

This worker cannot choose a state directory or journal name, cannot access
manager-owned files and has no RPC for releasing a blocked account.
"""
from __future__ import annotations

import argparse
import json
from pathlib import Path
import sys

from raid_bot.safety.cleanup_journal import GateBlocked
from raid_bot.safety.gatekeeper_socket import OfflineGatekeeperClient


FIXTURE_TARGET = "FIXTURE-ONLY-NOT-A-REAL-RAID"


def main() -> int:
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument("--socket-path", type=Path, required=True)
    p.add_argument(
        "--scenario",
        choices=("success", "cleanup-failed", "interrupt-before-complete"),
        default="success",
    )
    args = p.parse_args()
    try:
        client = OfflineGatekeeperClient(args.socket_path)
        reserved = client.request(
            "reserve", alias="ULGG-RAID-TEST-01", target=FIXTURE_TARGET
        )
        run_id = reserved["run_id"]
        if args.scenario == "interrupt-before-complete":
            # Deliberate synthetic crash: manager journal remains PENDING.
            print("OFFLINE_SIMULATED_INTERRUPTED", flush=True)
            return 3
        result = client.request(
            "complete",
            run_id=run_id,
            cleanup_verified=(args.scenario == "success"),
            rank_complete=(args.scenario == "success"),
            players_count=1 if args.scenario == "success" else None,
        )
        print(json.dumps(result, sort_keys=True))
        return 0 if result["cleanup_verified"] else 2
    except (GateBlocked, OSError, ValueError, KeyError) as exc:
        print("OFFLINE_GATE_BLOCKED:", type(exc).__name__)
        return 2


if __name__ == "__main__":
    sys.exit(main())
