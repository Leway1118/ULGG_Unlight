"""G2-R4: ACTUAL systemd PID1 staging on a disposable GitHub-hosted Ubuntu VM.

DANGER: This deliberately installs TEMPORARY systemd units and synthetic users
on the host where it runs. Strictly refuse non-GitHub, non-ephemeral hosts.
Never run on UL.GG, Contabo, personal VPS, or a persistent workstation.
No game credentials, WebSocket, player data, databases, or network requests.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import pwd
import grp
import shutil
import socket
import stat
import subprocess
import sys
import tempfile
import time
import uuid


SOURCE = Path(__file__).resolve().parents[1]
MGR_ID, WORKER_ID = 12001, 12002
MGR_NAME, WORKER_NAME = "ulggg2r4mgr", "ulggg2r4wrk"
SYNTHETIC_ID = "76561190000000000"
APPROVAL = "I REVIEWED CLEANUP AND AUTHORIZE THIS TEST RESET"


def shell(args, *, okay=False, timeout=15):
    proc = subprocess.run(
        list(map(str, args)), text=True, capture_output=True,
        encoding="utf-8", errors="replace", timeout=timeout,
    )
    if proc.returncode and not okay:
        raise RuntimeError(
            f"COMMAND_STOP {args[0]} rc={proc.returncode} "
            f"stdout={proc.stdout[-350:]} stderr={proc.stderr[-350:]}"
        )
    return proc


def stage_guard():
    if not (
        sys.platform.startswith("linux")
        and os.geteuid() == 0
        and os.getenv("GITHUB_ACTIONS") == "true"
        and os.getenv("RUNNER_ENVIRONMENT") == "github-hosted"
        and os.getenv("G2R4_EPHEMERAL_STAGING") == "synthetic-only"
    ):
        raise RuntimeError("BLOCKED_NOT_EXPLICIT_GITHUB_HOSTED_STAGING")
    if Path("/proc/1/comm").read_text().strip() != "systemd":
        raise RuntimeError("BLOCKED_PID1_NOT_SYSTEMD")
    if SOURCE.joinpath("run_gatekeeper_fixture.py").is_file() is False:
        raise RuntimeError("BLOCKED_NO_SYNTHETIC_SOURCE")
    for ident in (MGR_ID, WORKER_ID):
        try:
            pwd.getpwuid(ident)
        except KeyError:
            pass
        else:
            raise RuntimeError("BLOCKED_SYNTHETIC_UID_ALREADY_IN_USE")
        try:
            grp.getgrgid(ident)
        except KeyError:
            pass
        else:
            raise RuntimeError("BLOCKED_SYNTHETIC_GID_ALREADY_IN_USE")


def render_units(stage: Path, state_name: str, manager_unit: str, worker_unit: str):
    src = stage / "src"
    sockdir = stage / "sockets"
    templates = SOURCE / "docs"
    out = []
    for filename, name in (
        ("collector-g2r3-gatekeeper.service.example", manager_unit),
        ("collector-g2r3-fixture-worker.service.example", worker_unit),
    ):
        text = (templates / filename).read_text(encoding="utf-8")
        required = (
            "PrivateNetwork=yes", "IPAddressDeny=any",
            "RestrictAddressFamilies=AF_UNIX", "Restart=no",
            "NoNewPrivileges=yes", "ProtectSystem=strict",
            "ProtectHome=yes",
        )
        for marker in required:
            if text.count(marker) != 1:
                raise RuntimeError("BLOCKED_TEMPLATE_SECURITY_CHANGED:" + marker)
        replacements = {
            "/opt/ulgg-g2-offline/tool/raid-bot": str(src),
            "/run/ulgg-g2-socket": str(sockdir),
            "User=ulgg-g2-gatekeeper": f"User={MGR_NAME}",
            "Group=ulgg-g2-gatekeeper": f"Group={MGR_NAME}",
            "SupplementaryGroups=ulgg-g2-worker": f"SupplementaryGroups={WORKER_NAME}",
            "User=ulgg-g2-worker": f"User={WORKER_NAME}",
            "Group=ulgg-g2-worker": f"Group={WORKER_NAME}",
            "ulgg-g2-ledger": state_name,
            "After=collector-g2r3-gatekeeper.service": f"After={manager_unit}",
        }
        for before, after in replacements.items():
            text = text.replace(before, after)
        if name == manager_unit:
            net_probe = src / "g2r4_net_probe.py"
            # Executed inside exactly the same systemd sandbox as the manager.
            pre = f"ExecStartPre=/usr/bin/python3 {net_probe}\n"
            text = text.replace("ExecStart=/usr/bin/python3", pre + "ExecStart=/usr/bin/python3", 1)
            text = text.replace("User=" + MGR_NAME, "User=" + MGR_NAME, 1)
        text = text.replace("UMask=0077", "UMask=0077\nEnvironment=PYTHONDONTWRITEBYTECODE=1\nEnvironment=PYTHON_DOTENV_DISABLED=1", 1)
        if "/opt/ulgg-g2-offline" in text or "/run/ulgg-g2-socket" in text:
            raise RuntimeError("BLOCKED_UNEXPANDED_TEMPLATE")
        path = Path("/run/systemd/system") / name
        if path.exists():
            raise RuntimeError("BLOCKED_STAGING_UNIT_NAME_EXISTS")
        out.append((path, text))
    return out


def extract_ledger_state(folder: Path):
    import hashlib
    key = hashlib.sha256(("ulgg-raid-safety-account-v1:" + SYNTHETIC_ID).encode()).hexdigest()
    journal = folder / ("account-" + key + ".journal.json")
    audit = folder / (key + ".audit.jsonl")
    return journal, audit


def manager_command(state: Path, staged_source: Path, *args):
    return [
        "/usr/bin/setpriv", f"--reuid={MGR_ID}", f"--regid={MGR_ID}",
        "--clear-groups", "/usr/bin/python3",
        str(staged_source / "run_gatekeeper_fixture.py"),
        "--state-root", str(state), *args,
    ]


def expected_fail(command, expected_code=2):
    result = shell(command, okay=True, timeout=18)
    if result.returncode != expected_code:
        raise RuntimeError(f"BLOCKED_EXPECTED_RC_{expected_code}_GOT_{result.returncode}")
    return result


def wait_socket(path, unit):
    for _ in range(90):
        if path.is_socket():
            return
        if shell(["systemctl", "is-active", "--quiet", unit], okay=True).returncode:
            raise RuntimeError("BLOCKED_MANAGER_STOPPED_BEFORE_SOCKET")
        time.sleep(0.1)
    raise RuntimeError("BLOCKED_SOCKET_NOT_READY")


def unit_run(name, expect_success):
    result = shell(["systemctl", "start", name], okay=True, timeout=20)
    status = shell(["systemctl", "show", name, "-p", "ExecMainStatus", "--value"]).stdout.strip()
    code = int(status) if status else -999
    if expect_success and (result.returncode != 0 or code != 0):
        raise RuntimeError(f"BLOCKED_WORKER_EXPECTED_SUCCESS_rc{result.returncode}_code{code}")
    if not expect_success and (result.returncode == 0 or code != 2):
        raise RuntimeError(f"BLOCKED_WORKER_EXPECTED_DENIAL_rc{result.returncode}_code{code}")
    return code


def main(report: Path):
    stage_guard()
    evidence = {
        "schema": 1,
        "stage": "GitHub hosted Ubuntu ephemeral systemd PID1",
        "synthetic_only": True,
        "no_live_game": True,
        "steps": {},
        "status": "BLOCKED",
        "errors": [],
    }
    stages = evidence["steps"]
    token = uuid.uuid4().hex[:10]
    manager_unit = f"ulgg-g2r4-{token}-manager.service"
    worker_unit = f"ulgg-g2r4-{token}-worker.service"
    state_name = f"ulgg-g2r4-{token}-ledger"
    state = Path("/var/lib") / state_name
    sandbox = Path("/run") / ("ulgg-g2r4-" + token)
    socket_path = sandbox / "sockets" / "gate.sock"
    unit_paths = []
    made_users = []
    made_groups = []
    try:
        evidence["pid1"] = Path("/proc/1/comm").read_text().strip()
        evidence["host_netns_inode"] = os.stat("/proc/self/ns/net").st_ino
        for ident, name in ((MGR_ID, MGR_NAME), (WORKER_ID, WORKER_NAME)):
            shell(["groupadd", "--gid", ident, name])
            made_groups.append(name)
            shell(["useradd", "--uid", ident, "--gid", ident, "--no-create-home",
                   "--shell", "/usr/sbin/nologin", name])
            made_users.append(name)
        sandbox.mkdir(mode=0o755)
        src = sandbox / "src"
        src.mkdir(mode=0o755)
        (src / "raid_bot" / "safety").mkdir(parents=True, mode=0o755)
        for file in (SOURCE / "raid_bot" / "safety").glob("*.py"):
            shutil.copyfile(file, src / "raid_bot" / "safety" / file.name)
        for name in ("run_gatekeeper_fixture.py", "run_gatekeeper_worker_fixture.py"):
            shutil.copyfile(SOURCE / name, src / name)
        probe = src / "g2r4_net_probe.py"
        probe.write_text(
            "import json,os,socket,sys\n"
            "net=os.stat('/proc/self/ns/net').st_ino\n"
            "deny=[]\n"
            "for fam in (socket.AF_INET,socket.AF_INET6):\n"
            " try:\n"
            "  s=socket.socket(fam,socket.SOCK_STREAM)\n"
            " except OSError:\n"
            "  deny.append(True)\n"
            " else:\n"
            "  s.close();deny.append(False)\n"
            "print('G2R4_NET_PROBE '+json.dumps({'netns':net,'inet_socket_denied':deny}),flush=True)\n"
            "sys.exit(0 if all(deny) else 1)\n", encoding="utf-8",
        )
        sockdir = sandbox / "sockets"
        sockdir.mkdir(mode=0o750)
        os.chown(sockdir, MGR_ID, WORKER_ID)
        for file in src.rglob("*.py"):
            file.chmod(0o644)
        stages["synthetic_sources_staged"] = "PASS"
        for path, content in render_units(sandbox, state_name, manager_unit, worker_unit):
            path.write_text(content, encoding="utf-8")
            unit_paths.append(path)
        shell(["systemd-analyze", "verify", *(str(x) for x in unit_paths)])
        stages["rendered_g2r3_unit_static_verify"] = "PASS"
        shell(["systemctl", "daemon-reload"])
        shell(["systemctl", "start", manager_unit], timeout=15)
        wait_socket(socket_path, manager_unit)
        stages["manager_systemd_started"] = "PASS"
        properties = shell([
            "systemctl", "show", manager_unit,
            "-p", "MainPID", "-p", "User", "-p", "Group",
            "-p", "PrivateNetwork", "-p", "RestrictAddressFamilies",
            "-p", "StateDirectory", "-p", "Restart",
        ]).stdout
        data = dict(x.split("=", 1) for x in properties.splitlines() if "=" in x)
        manager_pid = int(data.get("MainPID", "0"))
        if manager_pid <= 1 or data.get("PrivateNetwork") != "yes":
            raise RuntimeError("BLOCKED_ACTUAL_MANAGER_NOT_RUNNING_IN_PRIVATE_NETWORK")
        manager_netns = os.stat(f"/proc/{manager_pid}/ns/net").st_ino
        evidence["manager_netns_inode"] = manager_netns
        if manager_netns == evidence["host_netns_inode"]:
            raise RuntimeError("BLOCKED_MANAGER_NETNS_EQUALS_HOST")
        logs = shell(["journalctl", "--no-pager", "-o", "cat", "-u", manager_unit]).stdout
        markers = [x for x in logs.splitlines() if x.startswith("G2R4_NET_PROBE ")]
        if not markers:
            raise RuntimeError("BLOCKED_MISSING_REAL_SYSTEMD_EXECSTARTPRE_PROBE")
        netdata = json.loads(markers[-1].split(" ", 1)[1])
        if netdata["netns"] != manager_netns or netdata["inet_socket_denied"] != [True, True]:
            raise RuntimeError("BLOCKED_NET_PROBE_MISMATCH")
        evidence["actual_systemd_net_probe"] = netdata
        stages["actual_private_network_and_no_INET_sockets"] = "PASS"
        if not (socket_path.is_socket() and socket_path.stat().st_uid == MGR_ID
                and socket_path.stat().st_gid == WORKER_ID
                and (socket_path.stat().st_mode & 0o777) == 0o660):
            raise RuntimeError("BLOCKED_SOCKET_UID_GID_MODE")
        stages["unix_socket_permissions"] = "PASS"
        if not (state.is_dir() and state.stat().st_uid == MGR_ID
                and (state.stat().st_mode & 0o077) == 0):
            raise RuntimeError("BLOCKED_STATE_DIRECTORY_PERMISSIONS")
        stages["systemd_state_directory_owner"] = "PASS"

        unit_run(worker_unit, True)
        journal, audit = extract_ledger_state(state)
        if not journal.is_file():
            raise RuntimeError("BLOCKED_MISSING_JOURNAL")
        first = json.loads(journal.read_text(encoding="utf-8"))
        if first["state"] != "VERIFIED_COMPLETE":
            raise RuntimeError("BLOCKED_FIRST_SYNTHETIC_RESULT")
        stages["worker_one_shot_success"] = "PASS"
        unit_run(worker_unit, False)
        stages["second_run_blocked"] = "PASS"

        shell(["systemctl", "restart", manager_unit], timeout=15)
        wait_socket(socket_path, manager_unit)
        unit_run(worker_unit, False)
        stages["restart_preserves_account_lock"] = "PASS"

        # Negative permission check: the worker cannot inspect manager-owned files.
        denial = shell([
            "/usr/bin/setpriv", f"--reuid={WORKER_ID}", f"--regid={WORKER_ID}",
            "--clear-groups", "/usr/bin/python3", "-c",
            "import os,sys; p=sys.argv[1]; assert not os.access(p,os.R_OK);"
            "assert not os.access(p,os.W_OK)",
            str(state),
        ])
        stages["worker_cannot_read_or_write_manager_ledger"] = "PASS"

        run_id = first["run_id"]
        release = [
            "operator-release", "--operator", "CIStagingOperator",
            "--run-id", run_id, "--evidence-ref", "FIXTURE/G2R4/VERIFIED",
            "--independent-cleanup-confirmed",
            "--approval-phrase",
        ]
        expected_fail(manager_command(state, src, *release, "INVALID_APPROVAL"))
        stages["invalid_operator_approval_rejected"] = "PASS"
        expected_fail(manager_command(state, src, *[x for x in release if x !=
                                              "--independent-cleanup-confirmed"],
                                      APPROVAL))
        stages["missing_independent_cleanup_flag_rejected"] = "PASS"
        shell(manager_command(state, src, *release, APPROVAL))
        if journal.exists() or not (state / "archive" / f"{run_id}.json").exists():
            raise RuntimeError("BLOCKED_RELEASE_ARCHIVE_INCOMPLETE")
        stages["synthetic_manual_release_archives_journal"] = "PASS"

        # Use the SAME service definition with a synthetic failure scenario.
        worker_path = unit_paths[1]
        worker_txt = worker_path.read_text(encoding="utf-8")
        if worker_txt.count("--scenario success") != 1:
            raise RuntimeError("BLOCKED_WORKER_FIXTURE_SCENARIO_CHANGED")
        worker_path.write_text(worker_txt.replace("--scenario success",
                                                  "--scenario cleanup-failed"), encoding="utf-8")
        shell(["systemctl", "daemon-reload"])
        unit_run(worker_unit, False)
        if json.loads(journal.read_text(encoding="utf-8"))["state"] != "BLOCKED_PENDING_CLEANUP":
            raise RuntimeError("BLOCKED_CLEANUP_FAILURE_WAS_NOT_PERSISTED")
        unit_run(worker_unit, False)
        stages["failure_reblocks_and_disallows_replay"] = "PASS"
        shell(manager_command(state, src, "status"))
        if (audit.stat().st_mode & 0o077) != 0:
            raise RuntimeError("BLOCKED_AUDIT_FILE_NOT_OWNER_ONLY")
        evidence["audit_event_count"] = len(audit.read_text(encoding="utf-8").splitlines())
        evidence["audit_sha256"] = hashlib.sha256(audit.read_bytes()).hexdigest()
        stages["audit_chain_and_owner_only"] = "PASS"
        evidence["status"] = "PASS"
        print("G2R4_ACTUAL_SYSTEMD_PID1_PRIVATE_NETWORK_PASS", flush=True)
    except Exception as exc:
        evidence["errors"].append(type(exc).__name__ + ": " + str(exc)[:420])
        raise
    finally:
        # ALWAYS roll back ONLY this ephemeral runner's synthetic units/users.
        for unit in (worker_unit, manager_unit):
            shell(["systemctl", "stop", unit], okay=True)
            shell(["systemctl", "reset-failed", unit], okay=True)
        for path in unit_paths:
            path.unlink(missing_ok=True)
        shell(["systemctl", "daemon-reload"], okay=True)
        # Any safety conclusion is recorded before the synthetic state is purged.
        for user in reversed(made_users):
            shell(["userdel", user], okay=True)
        for group in reversed(made_groups):
            shell(["groupdel", group], okay=True)
        if state_name.startswith("ulgg-g2r4-"):
            shutil.rmtree(state, ignore_errors=True)
        if sandbox.name.startswith("ulgg-g2r4-"):
            shutil.rmtree(sandbox, ignore_errors=True)
        evidence["rollback"] = "PASS" if (
            not state.exists() and not sandbox.exists()
            and all(not p.exists() for p in unit_paths)
        ) else "INCOMPLETE"
        if evidence["rollback"] != "PASS":
            evidence["status"] = "BLOCKED"
        report.parent.mkdir(parents=True, exist_ok=True)
        report.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n",
                          encoding="utf-8")
        print("G2R4_REPORT", report, "status", evidence["status"],
              "rollback", evidence["rollback"], flush=True)


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report", type=Path, required=True)
    args = parser.parse_args()
    if not args.report.is_absolute():
        parser.error("absolute --report path is required")
    main(args.report)
