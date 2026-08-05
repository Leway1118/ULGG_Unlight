"""Opt-in end-to-end smoke test for the PyInstaller launcher build."""

from __future__ import annotations

import argparse
import os
import queue
import signal
import socket
import sqlite3
import subprocess
import sys
import tempfile
import threading
import time
from contextlib import closing
from pathlib import Path
from uuid import UUID

import requests


SESSION_LINE_PREFIX = "TRACKER_SESSION_ID="


def unused_local_port() -> int:
    with socket.socket() as probe:
        probe.bind(("127.0.0.1", 0))
        return int(probe.getsockname()[1])


def port_is_open(port: int) -> bool:
    try:
        with socket.create_connection(
            ("127.0.0.1", port),
            timeout=0.5,
        ):
            return True
    except OSError:
        return False


def wait_json(url: str, timeout: float):
    deadline = time.monotonic() + timeout
    last_error = None
    while time.monotonic() < deadline:
        try:
            response = requests.get(url, timeout=1)
            if response.status_code == 200:
                return response.json()
        except (requests.RequestException, ValueError) as error:
            last_error = error
        time.sleep(0.2)
    raise RuntimeError(f"timed out waiting for {url}: {last_error}")


def capture_output(
    process: subprocess.Popen,
    output_lines: list[str],
    session_ids: queue.Queue[str],
) -> None:
    if process.stdout is None:
        return
    for line in process.stdout:
        output_lines.append(line)
        stripped = line.strip()
        marker_index = stripped.find(SESSION_LINE_PREFIX)
        if marker_index >= 0:
            value = stripped[
                marker_index + len(SESSION_LINE_PREFIX):
            ]
            try:
                session_ids.put_nowait(str(UUID(value)))
            except ValueError:
                continue


def wait_for_active_session(
    api_origin: str,
    session_id: str,
    timeout: float,
) -> dict:
    deadline = time.monotonic() + timeout
    last_active = None
    while time.monotonic() < deadline:
        try:
            response = requests.get(
                f"{api_origin}/api/v1/sessions/active",
                timeout=1,
            )
            if response.status_code == 200:
                payload = response.json()
                last_active = payload
                if (
                    payload.get("session", {}).get("session_id")
                    == session_id
                ):
                    return payload
        except (requests.RequestException, ValueError):
            pass
        time.sleep(0.2)
    raise RuntimeError(
        "timed out waiting for launcher session activation: "
        f"expected={session_id}, last_active={last_active}"
    )


def stop_process(process: subprocess.Popen) -> None:
    if process.poll() is not None:
        return
    try:
        process.send_signal(signal.CTRL_BREAK_EVENT)
        process.wait(timeout=10)
    except (OSError, subprocess.TimeoutExpired):
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=5)


def assert_session_finished(
    database_path: str,
    session_id: str,
) -> None:
    with closing(sqlite3.connect(database_path)) as connection:
        row = connection.execute(
            """
            SELECT status, ended_at, tracker_active
            FROM sessions
            WHERE session_id = ?
            """,
            (session_id,),
        ).fetchone()
    if row is None:
        raise RuntimeError("launcher session is missing from SQLite")
    status, ended_at, tracker_active = row
    if (
        status != "completed"
        or not ended_at
        or tracker_active != 0
    ):
        raise RuntimeError(
            "launcher session was not finished cleanly: "
            f"status={status}, ended_at={ended_at}, "
            f"tracker_active={tracker_active}"
        )


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--exe", type=Path, required=True)
    parser.add_argument("--api-port", type=int, default=0)
    parser.add_argument("--cdp-port", type=int, default=0)
    parser.add_argument("--websocket-port", type=int, default=0)
    args = parser.parse_args()
    detector_root = Path(__file__).resolve().parent.parent
    api_port = args.api_port or unused_local_port()
    cdp_port = args.cdp_port or unused_local_port()
    websocket_port = args.websocket_port or unused_local_port()
    if len({api_port, cdp_port, websocket_port}) != 3:
        raise RuntimeError("smoke test ports must be distinct")
    api_origin = f"http://127.0.0.1:{api_port}"
    flags = subprocess.CREATE_NEW_PROCESS_GROUP
    temporary_directory = tempfile.TemporaryDirectory()
    isolated_local_app_data = Path(temporary_directory.name)
    fake_cdp = subprocess.Popen(
        [
            sys.executable,
            str(Path(__file__).with_name("launcher_fake_cdp.py")),
            "--http-port",
            str(cdp_port),
            "--websocket-port",
            str(websocket_port),
        ],
        cwd=detector_root,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        creationflags=flags,
    )
    launcher = None
    output_lines: list[str] = []
    output_thread = None
    try:
        wait_json(f"http://127.0.0.1:{cdp_port}/json/version", 10)
        launcher = subprocess.Popen(
            [
                str(args.exe.resolve()),
                "--no-browser",
                "--api-port",
                str(api_port),
                "--cdp-port",
                str(cdp_port),
                "--startup-timeout",
                "15",
                "--shutdown-timeout",
                "5",
            ],
            cwd=detector_root,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            text=True,
            encoding="utf-8",
            errors="replace",
            creationflags=flags,
            env={
                **os.environ,
                "PYTHONUNBUFFERED": "1",
                "LOCALAPPDATA": str(isolated_local_app_data),
                "ULGG_TRACKER_RUNTIME_DIR": str(
                    isolated_local_app_data / "runtime"
                ),
            },
        )
        session_ids: queue.Queue[str] = queue.Queue()
        output_thread = threading.Thread(
            target=capture_output,
            args=(launcher, output_lines, session_ids),
            daemon=True,
        )
        output_thread.start()
        health = wait_json(
            f"{api_origin}/api/v1/health",
            15,
        )
        try:
            session_id = session_ids.get(timeout=15)
        except queue.Empty as error:
            raise RuntimeError(
                "launcher did not emit a complete session UUID\n"
                + "".join(output_lines)
            ) from error
        active = wait_for_active_session(
            api_origin,
            session_id,
            15,
        )
        tracker = requests.get(
            f"{api_origin}/tracker/",
            timeout=2,
        )
        auto_card_sync = requests.get(
            f"{api_origin}/tracker/tracker-auto-card-sync.js",
            timeout=2,
        )
        stage_sync = requests.get(
            f"{api_origin}/tracker/tracker-stage-sync.js",
            timeout=2,
        )
        diagnostics = requests.get(
            f"{api_origin}/api/v1/diagnostics/status",
            timeout=2,
        )
        if health.get("server") != "ready":
            raise RuntimeError("health server is not ready")
        if active["session"]["session_id"] != session_id:
            raise RuntimeError("active session does not match launcher UUID")
        if tracker.status_code != 200:
            raise RuntimeError("Tracker static page was not served")
        if "tracker-auto-card-sync.js" not in tracker.text:
            raise RuntimeError(
                "Tracker page did not load Auto Card Sync"
            )
        if "tracker-stage-sync.js" not in tracker.text:
            raise RuntimeError(
                "Tracker page did not load Stage Sync"
            )
        if auto_card_sync.status_code != 200:
            raise RuntimeError(
                "Auto Card Sync asset was not served"
            )
        if stage_sync.status_code != 200:
            raise RuntimeError(
                "Stage Sync asset was not served"
            )
        if diagnostics.status_code != 200:
            raise RuntimeError(
                "Diagnostics aggregate was not served"
            )
        if launcher.poll() is not None:
            raise RuntimeError(
                f"launcher exited early: {launcher.returncode}"
            )
        time.sleep(3)
        if launcher.poll() is not None:
            raise RuntimeError(
                f"launcher exited during soak: {launcher.returncode}"
            )
        health_after_wait = wait_json(
            f"{api_origin}/api/v1/health",
            3,
        )
        if health_after_wait.get("server") != "ready":
            raise RuntimeError("health server stopped during soak")

        stop_process(launcher)
        if launcher.returncode != 0:
            raise RuntimeError(
                f"launcher shutdown exit={launcher.returncode}\n"
                + "".join(output_lines)
            )
        assert_session_finished(
            health["database_path"],
            session_id,
        )
        print(
            "EXE_SMOKE_OK "
            f"server={health['server']} "
            f"session_id={session_id} "
            f"tracker_status={tracker.status_code} "
            "session_status=completed "
            f"api_port={api_port} "
            f"cdp_port={cdp_port} "
            f"websocket_port={websocket_port}"
        )
        return 0
    finally:
        if launcher is not None:
            stop_process(launcher)
            if output_thread is not None:
                output_thread.join(timeout=2)
            if launcher.stdout is not None:
                launcher.stdout.close()
        stop_process(fake_cdp)
        leaked_ports = [
            port
            for port in (api_port, cdp_port, websocket_port)
            if port_is_open(port)
        ]
        temporary_directory.cleanup()
        if leaked_ports:
            raise RuntimeError(
                f"smoke test left listening ports: {leaked_ports}"
            )


if __name__ == "__main__":
    raise SystemExit(main())
