"""ADMIN/MANAGER ONLY: offline Unix-socket gatekeeper (never a live adapter).

Run only inside isolated Linux staging as an unprivileged manager user.
The worker UID must differ from the manager UID. No Steam login, getid, Raid
joining or production DB functions are reachable through this program.

Operator release is deliberately a LOCAL MANAGER command, never socket RPC.
"""
from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import signal
import sys
import threading

from raid_bot.safety.cleanup_journal import GateBlocked
from raid_bot.safety.gatekeeper_ledger import GatekeeperLedger
from raid_bot.safety.gatekeeper_socket import OfflineGatekeeperServer


FIXTURE_ACCOUNT = "76561190000000000"
FIXTURE_TARGET = "FIXTURE-ONLY-NOT-A-REAL-RAID"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--state-root", type=Path, required=True)
    parser.add_argument(
        "--socket-path", type=Path,
        help="Manager-created socket directory. Only required for serve."
    )
    sub = parser.add_subparsers(dest="operation", required=True)
    serve = sub.add_parser("serve", help="Run offline-only Unix socket")
    serve.add_argument("--worker-uid", type=int, required=True)
    serve.add_argument("--worker-gid", type=int, required=True)
    release = sub.add_parser(
        "operator-release",
        help="Owner-only manual release AFTER independently verified cleanup"
    )
    release.add_argument("--operator", required=True)
    release.add_argument("--run-id", required=True)
    release.add_argument("--evidence-ref", required=True)
    release.add_argument(
        "--independent-cleanup-confirmed", action="store_true"
    )
    release.add_argument(
        "--approval-phrase", required=True,
        help="Explicit manual approval; no automatic release supported"
    )
    sub.add_parser("status", help="Manager-only local status")
    args = parser.parse_args()

    try:
        if os.name != "posix" or os.geteuid() == 0:
            raise GateBlocked("only_separate_nonroot_linux_manager_allowed")
        ledger = GatekeeperLedger(
            args.state_root,
            steam_id=FIXTURE_ACCOUNT,
            fixture_target=FIXTURE_TARGET,
        )
        if args.operation == "serve":
            if not args.socket_path:
                raise GateBlocked("socket_path_required")
            with OfflineGatekeeperServer(
                args.socket_path, ledger=ledger,
                worker_uid=args.worker_uid, worker_gid=args.worker_gid,
            ) as server:
                # A real systemd stop/restart sends SIGTERM. Exit through
                # server_close(), which removes the Unix socket path; the
                # manager-owned journal and audit ledger remain untouched.
                def graceful_stop(_sig, _frame):
                    threading.Thread(
                        target=server.shutdown, daemon=True
                    ).start()

                signal.signal(signal.SIGTERM, graceful_stop)
                signal.signal(signal.SIGINT, graceful_stop)
                print("OFFLINE_GATEKEEPER_READY", flush=True)
                server.serve_forever(poll_interval=0.1)
            return 0
        if args.operation == "operator-release":
            value = ledger.operator_release(
                operator=args.operator, run_id=args.run_id,
                evidence_ref=args.evidence_ref,
                independent_cleanup_confirmed=args.independent_cleanup_confirmed,
                approval_phrase=args.approval_phrase,
            )
            print(value)
            return 0
        print(json.dumps(ledger.status(), sort_keys=True))
        return 0
    except (GateBlocked, OSError, ValueError) as exc:
        # Do not log credentials or raw game identities.
        print("GATEKEEPER_STOP:", type(exc).__name__, str(exc))
        return 2


if __name__ == "__main__":
    sys.exit(main())
