from __future__ import annotations

import argparse
import sys
from datetime import datetime, timezone
from pathlib import Path


DETECTOR_ROOT = Path(__file__).resolve().parent.parent
if str(DETECTOR_ROOT) not in sys.path:
    sys.path.insert(0, str(DETECTOR_ROOT))

from runtime_credentials import (  # noqa: E402
    CDP_TOKEN_FILENAME,
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


PRESETS = {
    "cdp-disconnected": {
        "connection_state": "DISCONNECTED",
        "context_state": "NOT_FOUND",
    },
    "cdp-connected": {
        "connection_state": "CONNECTED",
        "context_state": "SEARCHING",
    },
    "cdp-context-found": {
        "connection_state": "CONNECTED",
        "context_state": "FOUND",
        "target": "Unlight game iframe",
        "context_id": 7,
    },
    "cdp-injected": {
        "connection_state": "CONNECTED",
        "context_state": "FOUND",
        "injection_state": "INJECTED",
    },
    "cdp-applied": {
        "injection_state": "INJECTED",
        "ruleset_apply_state": "APPLIED",
        "expected_count": 5,
        "matched_count": 5,
        "changed_count": 4,
        "already_compliant_count": 1,
        "missing_count": 0,
        "invalid_count": 0,
        "verified_count": 5,
        "error": None,
    },
    "cdp-partial": {
        "ruleset_apply_state": "PARTIAL",
        "expected_count": 5,
        "matched_count": 4,
        "changed_count": 3,
        "already_compliant_count": 1,
        "missing_count": 1,
        "invalid_count": 0,
        "verified_count": 4,
    },
    "cdp-failed": {
        "connection_state": "ERROR",
        "ruleset_apply_state": "FAILED",
        "error": {
            "code": "CDP_RUNTIME_EXCEPTION",
            "message": "Demo CDP runtime failure",
            "stage": "INJECTION",
            "occurred_at": observed_at(),
            "details": {},
        },
    },
}


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Patch local CDP diagnostics.",
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
            args.runtime_directory / CDP_TOKEN_FILENAME
        )
        payload = {
            **PRESETS[args.preset],
            "client_observed_at": observed_at(),
        }
        body = send_json(
            method="PATCH",
            url=f"{origin}/api/v1/cdp/status",
            token=token,
            payload=payload,
        )
        print_result(body)
        return 0
    except Exception as error:
        print(f"CDP diagnostic update failed: {error}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
