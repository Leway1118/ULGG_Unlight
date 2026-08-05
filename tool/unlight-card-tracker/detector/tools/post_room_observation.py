from __future__ import annotations

import argparse
import sys
from datetime import datetime, timezone
from pathlib import Path


DETECTOR_ROOT = Path(__file__).resolve().parent.parent
if str(DETECTOR_ROOT) not in sys.path:
    sys.path.insert(0, str(DETECTOR_ROOT))

from runtime_credentials import (  # noqa: E402
    ROOM_TOKEN_FILENAME,
    default_runtime_directory,
)

from _diagnostic_cli import (  # noqa: E402
    print_result,
    read_token,
    send_json,
    validate_loopback_origin,
)


def observed_at() -> str:
    return (
        datetime.now(timezone.utc)
        .isoformat(timespec="milliseconds")
        .replace("+00:00", "Z")
    )


def snapshot(state: str) -> dict:
    values = {
        "observed_room_state": state,
        "raw_state": state.casefold(),
        "game_room_id": "DEMO_ROOM4_12345",
        "platform": "DMM",
        "channel": "room4",
        "room_name": "Tracker ROOM diagnostics demo",
        "game_host_player_id": "demo-host",
        "game_opponent_player_id": None,
        "host_display_name": "Way",
        "opponent_display_name": None,
        "rule_version_id": "ulgg-watermelon-126@1.26.0",
        "content_hash": None,
        "tracker_session_id": None,
        "client_observed_at": observed_at(),
    }
    if state == "UNKNOWN":
        for key in (
            "raw_state",
            "game_room_id",
            "platform",
            "channel",
            "room_name",
            "game_host_player_id",
            "host_display_name",
            "rule_version_id",
        ):
            values[key] = None
    elif state in {"MATCHED", "STARTING", "ACTIVE", "ENDING", "ENDED"}:
        values["game_opponent_player_id"] = "demo-opponent"
        values["opponent_display_name"] = "Noir"
    return values


PRESETS = {
    "room-empty": "UNKNOWN",
    "room-waiting": "WAITING",
    "room-matched": "MATCHED",
    "room-active": "ACTIVE",
    "room-ended": "ENDED",
}


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Post a complete ROOM diagnostic snapshot.",
    )
    parser.add_argument("preset", choices=sorted(PRESETS))
    parser.add_argument(
        "--api-origin",
        default="http://127.0.0.1:8765",
    )
    parser.add_argument(
        "--runtime-directory",
        type=Path,
        default=default_runtime_directory(),
    )
    args = parser.parse_args()
    try:
        origin = validate_loopback_origin(args.api_origin)
        token = read_token(
            args.runtime_directory / ROOM_TOKEN_FILENAME
        )
        body = send_json(
            method="PUT",
            url=f"{origin}/api/v1/room/observation",
            token=token,
            payload=snapshot(PRESETS[args.preset]),
        )
        print_result(body)
        return 0
    except Exception as error:
        print(f"ROOM diagnostic update failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
