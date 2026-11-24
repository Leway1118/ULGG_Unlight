"""Linux-only Unix-socket boundary for OFFLINE synthetic account locks.

Worker RPC intentionally supports only status/reserve/complete; operator
release is NOT exposed to socket clients. Linux SO_PEERCRED must match the
specifically authorized fixture UID. No TCP, game auth or WebSocket exists.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import socket
import socketserver
import stat
import struct

from raid_bot.safety.cleanup_journal import GateBlocked


# Windows cannot create UnixStreamServer. Import is safe, but construction
# on a non-Linux platform always fails before binding any socket.
_UnixServerBase = getattr(socketserver, "ThreadingUnixStreamServer", socketserver.TCPServer)


class OfflineGatekeeperServer(_UnixServerBase):
    daemon_threads = True
    allow_reuse_address = False
    request_queue_size = 4

    def __init__(
        self, socket_path: Path, *, ledger, worker_uid: int, worker_gid: int
    ):
        if os.name != "posix" or not hasattr(socket, "SO_PEERCRED"):
            raise GateBlocked("linux_peercred_required")
        if os.geteuid() == 0 or os.geteuid() == worker_uid:
            raise GateBlocked("gatekeeper_requires_separate_nonroot_identity")
        raw = Path(socket_path)
        if not raw.is_absolute() or raw.is_symlink() or raw.exists():
            raise GateBlocked("socket_path_unsafe")
        parent = raw.parent
        if parent.is_symlink():
            raise GateBlocked("socket_directory_symlink_forbidden")
        try:
            mode = parent.stat()
        except OSError as exc:
            raise GateBlocked("socket_directory_unavailable") from exc
        if (
            not stat.S_ISDIR(mode.st_mode)
            or mode.st_uid != os.geteuid()
            or (mode.st_mode & 0o007)
        ):
            raise GateBlocked("socket_directory_permissions_invalid")
        self.ledger = ledger
        self.worker_uid = worker_uid
        self.worker_gid = worker_gid
        super().__init__(str(raw), _FixtureWorkerHandler)
        try:
            os.chown(str(raw), os.geteuid(), worker_gid)
            os.chmod(str(raw), 0o660)
        except BaseException:
            self.server_close()
            raw.unlink(missing_ok=True)
            raise

    def server_close(self):
        super().server_close()
        # Cleanup is permissible only for the owner. The account Journal
        # and audit ledger are NEVER removed when closing the server.
        path = Path(self.server_address)
        if path.is_socket() and path.stat().st_uid == os.geteuid():
            path.unlink()


class _FixtureWorkerHandler(socketserver.StreamRequestHandler):
    def handle(self):
        self.request.settimeout(3.0)
        creds = self.request.getsockopt(
            socket.SOL_SOCKET, socket.SO_PEERCRED, struct.calcsize("3i")
        )
        _pid, peer_uid, _gid = struct.unpack("3i", creds)
        if peer_uid != self.server.worker_uid:
            self._respond({"ok": False, "error": "worker_uid_not_authorized"})
            return
        try:
            raw = self.rfile.readline(4097)
            if not raw.endswith(b"\n") or len(raw) > 4096:
                raise GateBlocked("request_length_invalid")
            request = json.loads(raw.decode("utf-8"))
            if not isinstance(request, dict):
                raise GateBlocked("request_must_be_object")
            action = request.get("action")
            ledger = self.server.ledger
            if action == "status":
                result = ledger.status()
            elif action == "reserve":
                result = {
                    "run_id": ledger.reserve(
                        account_alias=request.get("alias", ""),
                        raid_code=request.get("target", ""),
                    )
                }
            elif action == "complete":
                result = ledger.complete(
                    request.get("run_id", ""),
                    cleanup_verified=request.get("cleanup_verified") is True,
                    rank_complete=(
                        request.get("rank_complete")
                        if type(request.get("rank_complete")) is bool else None
                    ),
                    players_count=(
                        request.get("players_count")
                        if type(request.get("players_count")) is int else None
                    ),
                )
            else:
                raise GateBlocked("rpc_method_forbidden")
            self._respond({"ok": True, "result": result})
        except (GateBlocked, ValueError, TypeError) as exc:
            error = str(exc) if isinstance(exc, GateBlocked) else "invalid_request"
            self._respond({"ok": False, "error": error})
        except Exception:
            self._respond({"ok": False, "error": "gatekeeper_internal_error"})

    def _respond(self, obj):
        self.wfile.write(
            (json.dumps(obj, separators=(",", ":"), sort_keys=True) + "\n")
            .encode("utf-8")
        )


class OfflineGatekeeperClient:
    """Fixture-only client; it never receives filesystem write privileges."""

    def __init__(self, socket_path: Path):
        self.socket_path = Path(socket_path)

    def request(self, action: str, **data):
        if os.name != "posix":
            raise GateBlocked("linux_unix_client_required")
        message = json.dumps({"action": action, **data}).encode("utf-8") + b"\n"
        if len(message) > 4096:
            raise GateBlocked("client_message_too_large")
        with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as sock:
            sock.settimeout(4)
            sock.connect(str(self.socket_path))
            sock.sendall(message)
            with sock.makefile("rb") as response:
                raw = response.readline(4097)
        if not raw.endswith(b"\n") or len(raw) > 4096:
            raise GateBlocked("invalid_gatekeeper_response")
        data = json.loads(raw)
        if data.get("ok") is not True:
            raise GateBlocked(data.get("error", "gatekeeper_refused"))
        return data["result"]
