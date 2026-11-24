"""Linux GitHub-hosted runner: verify two distinct, synthetic nonroot UIDs.

Run only on an EPHEMERAL isolated CI runner with sudo:
    sudo -E python3 tests/linux_gatekeeper_ci_smoke.py

This test uses synthetic Steam/Raid IDs and a Unix socket only. It never
uses game credentials, WebSocket connections, production DB, or live servers.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import signal
import stat
import subprocess
import sys
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[1]
PYTHON = sys.executable
MGR_UID = 12001
MGR_GID = 12001
WORKER_UID = 12002
WORKER_GID = 12002
UNKNOWN_UID = 12003
ALIAS = "ULGG-RAID-TEST-01"
TARGET = "FIXTURE-ONLY-NOT-A-REAL-RAID"


def demote(uid: int, gid: int):
    """Executed only in single-threaded POSIX CI test subprocess."""
    def fn():
        os.setgroups([])
        os.setgid(gid)
        os.setuid(uid)
    return fn


def safe_env():
    env = {
        "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
        "PYTHONPATH": os.pathsep.join([
            str(ROOT),
            str(ROOT.parent / "core" / "runtime" / "cdp"),
        ]),
        "PYTHON_DOTENV_DISABLED": "1",
        "PYTHONDONTWRITEBYTECODE": "1",
        "HOME": "/tmp",
    }
    return env


def call(uid, gid, argv, *, timeout=12):
    return subprocess.run(
        [PYTHON, *argv], cwd=ROOT, env=safe_env(),
        stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
        stderr=subprocess.PIPE, text=True, encoding="utf-8",
        errors="replace", timeout=timeout, preexec_fn=demote(uid, gid),
    )


def assert_rc(proc, expected, stage):
    if proc.returncode != expected:
        raise AssertionError(
            f"{stage}: expected {expected}, got {proc.returncode}; "
            f"stdout={proc.stdout[-350:]} stderr={proc.stderr[-350:]}"
        )


def main():
    if os.name != "posix" or os.geteuid() != 0:
        raise RuntimeError("Requires an ephemeral Linux CI runner with root for setuid")
    if not sys.platform.startswith("linux"):
        raise RuntimeError("Linux CI only")
    state = tempfile.TemporaryDirectory(prefix="ulgg-g2r3-ci-", dir="/tmp")
    manager = None
    try:
        base = Path(state.name)
        os.chmod(base, 0o755)
        mgrroot = base / "ledger"
        mgrroot.mkdir(mode=0o700)
        os.chown(mgrroot, MGR_UID, MGR_GID)
        sockdir = base / "sockets"
        sockdir.mkdir(mode=0o750)
        os.chown(sockdir, MGR_UID, WORKER_GID)
        sockpath = sockdir / "gate.sock"
        manager_cmd = [
            str(ROOT / "run_gatekeeper_fixture.py"),
            "--state-root", str(mgrroot),
            "--socket-path", str(sockpath),
            "serve", "--worker-uid", str(WORKER_UID),
            "--worker-gid", str(WORKER_GID),
        ]

        def start_manager():
            nonlocal manager
            manager = subprocess.Popen(
                [PYTHON, *manager_cmd], cwd=ROOT, env=safe_env(),
                stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                stderr=subprocess.PIPE, text=True,
                preexec_fn=demote(MGR_UID, MGR_GID),
                start_new_session=True,
            )
            until = time.monotonic() + 6.0
            while time.monotonic() < until:
                if sockpath.is_socket():
                    return
                if manager.poll() is not None:
                    out, err = manager.communicate(timeout=2)
                    raise RuntimeError(
                        f"manager exited {manager.returncode}: {out[-250:]} {err[-350:]}"
                    )
                time.sleep(0.04)
            raise RuntimeError("manager_socket_not_created")

        def worker(scenario="success"):
            return call(
                WORKER_UID, WORKER_GID,
                [str(ROOT / "run_gatekeeper_worker_fixture.py"),
                 "--socket-path", str(sockpath), "--scenario", scenario],
            )

        def manager_status():
            r = call(
                MGR_UID, MGR_GID,
                [str(ROOT / "run_gatekeeper_fixture.py"),
                 "--state-root", str(mgrroot), "status"],
            )
            assert_rc(r, 0, "manager_status")
            return json.loads(r.stdout)

        start_manager()
        print("CI_SOCKET_READY", flush=True)
        status = manager_status()
        assert status["state"] == "UNRESERVED", status

        first = worker("success")
        assert_rc(first, 0, "worker_first_join_fixture")
        assert "VERIFIED_COMPLETE" in first.stdout
        print("CI_SUCCESS_1_ONLY", flush=True)
        second = worker("success")
        assert_rc(second, 2, "worker_replay_must_block")
        assert manager_status()["state"] == "VERIFIED_COMPLETE"
        print("CI_REPLAY_BLOCKED", flush=True)

        # The untrusted Worker UID may not read/write/delete manager data.
        denial = call(
            WORKER_UID, WORKER_GID,
            ["-c",
             "from pathlib import Path;import os,sys;"
             "p=Path(sys.argv[1]);"
             "fs=list(p.glob('*.journal.json'));"
             "assert not os.access(p,os.W_OK);"
             "assert not os.access(p,os.R_OK);"
             "print('WORKER_DATA_ACCESS_DENIED')",
             str(mgrroot)],
        )
        assert_rc(denial, 0, "worker_data_access")
        print("CI_WORKER_FS_DENIED", flush=True)

        # A different UID in the same worker group reaches the socket,
        # but must still be rejected by SO_PEERCRED.
        unknown_uid_script = (
            "from raid_bot.safety.gatekeeper_socket import OfflineGatekeeperClient\n"
            "from raid_bot.safety.cleanup_journal import GateBlocked\n"
            "from pathlib import Path\n"
            "import sys\n"
            "c=OfflineGatekeeperClient(Path(sys.argv[1]))\n"
            "try:\n"
            "    c.request('status')\n"
            "except GateBlocked:\n"
            "    sys.exit(2)\n"
            "sys.exit(0)\n"
        )
        unauth = call(
            UNKNOWN_UID, WORKER_GID,
            ["-c", unknown_uid_script, str(sockpath)],
        )
        assert_rc(unauth, 2, "unknown_uid_must_block")
        print("CI_PEERCRED_UNKNOWN_UID_BLOCKED", flush=True)

        # Even authorized Worker UID has no release/reset RPC.
        release_rpc_script = unknown_uid_script.replace(
            "c.request('status')", "c.request('operator-release')"
        )
        wrong = call(
            WORKER_UID, WORKER_GID,
            ["-c", release_rpc_script, str(sockpath)],
        )
        assert_rc(wrong, 2, "rpc_release_forbidden")
        print("CI_WORKER_RELEASE_FORBIDDEN", flush=True)

        # Manager-local manual approval has multiple prerequisites.
        from raid_bot.safety.account_guard import account_key
        journal = mgrroot / (
            "account-" + account_key("76561190000000000") + ".journal.json"
        )
        metadata = json.loads(journal.read_text(encoding="utf-8"))
        run_id = metadata["run_id"]
        release_args = [
            str(ROOT / "run_gatekeeper_fixture.py"),
            "--state-root", str(mgrroot), "operator-release",
            "--operator", "CIStagingOperator",
            "--run-id", run_id,
            "--evidence-ref", "FIXTURE/CI/STAGING/VERIFIED",
            "--independent-cleanup-confirmed",
            "--approval-phrase",
        ]
        denied = call(
            MGR_UID, MGR_GID,
            [*release_args, "NOT_APPROVED"],
        )
        assert_rc(denied, 2, "bad_operator_phrase")
        missing_evidence = call(
            MGR_UID, MGR_GID,
            [x for x in release_args[:-2] if x !=
             "--independent-cleanup-confirmed"] + [
                "--approval-phrase",
                "I REVIEWED CLEANUP AND AUTHORIZE THIS TEST RESET",
            ],
        )
        assert_rc(missing_evidence, 2, "missing_independent_cleanup")
        approved = call(
            MGR_UID, MGR_GID,
            [*release_args,
             "I REVIEWED CLEANUP AND AUTHORIZE THIS TEST RESET"],
        )
        assert_rc(approved, 0, "operator_manual_release_fixture")
        print("CI_MANUAL_APPROVAL_ONLY", flush=True)
        retry = worker("cleanup-failed")
        assert_rc(retry, 2, "second_fixture_cleanup_failed")
        assert manager_status()["state"] == "BLOCKED_PENDING_CLEANUP"
        print("CI_FAILURE_REBLOCKED", flush=True)

        # Complete audit is verifiable and stays manager-owned.
        audit = mgrroot / (account_key("76561190000000000") + ".audit.jsonl")
        assert (audit.stat().st_mode & 0o077) == 0
        assert len(audit.read_text(encoding="utf-8").splitlines()) >= 7
        print("CI_G2R3_LINUX_GATEKEEPER_PASS", flush=True)
    finally:
        if manager and manager.poll() is None:
            manager.terminate()
            try:
                manager.wait(timeout=4)
            except subprocess.TimeoutExpired:
                manager.kill()
                manager.wait(timeout=4)
        state.cleanup()


if __name__ == "__main__":
    main()
