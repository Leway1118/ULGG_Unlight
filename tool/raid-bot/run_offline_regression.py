"""Run Raid Bot unittest files independently with offline-only socket guard.

Usage:
  python run_offline_regression.py --report D:/Download/raid-tests.json
  python run_offline_regression.py --pattern test_protocol16_*.py

Manual integration scripts that contain no unittest cases are reported
as MANUAL_NOT_DISCOVERED; they are never executed as standalone programs.
"""
from __future__ import annotations

import argparse
import concurrent.futures
import json
import os
import re
import subprocess
import sys
import time
from pathlib import Path


ROOT = Path(__file__).resolve().parent
TESTS = ROOT / "tests"
GUARD = TESTS / "_offline_guard"
CDP = ROOT.parent / "core" / "runtime" / "cdp"
SAFE_ENV_UNSET = (
    "DB_HOST", "DB_USER", "DB_PASSWORD", "DB_NAME", "DB_PORT",
    "DISCORD_WEBHOOK_URL", "RAID_WS_ENDPOINT", "RAID_OWNER_ID",
    "RAID_STAGE_MATCH_SECRET",
)


def run_one(path: Path, *, timeout: float, log_dir: Path | None) -> dict:
    env = os.environ.copy()
    env["PYTHONPATH"] = os.pathsep.join((str(GUARD), str(ROOT), str(CDP)))
    env["PYTHONUTF8"] = "1"
    env["PYTHONIOENCODING"] = "utf-8"
    env["PYTHON_DOTENV_DISABLED"] = "1"
    for key in SAFE_ENV_UNSET:
        env.pop(key, None)

    command = [
        sys.executable, "-m", "unittest", "discover",
        "-s", "tests", "-p", path.name, "-v",
    ]
    started = time.monotonic()
    result_code = 124
    try:
        completed = subprocess.run(
            command,
            cwd=ROOT,
            env=env,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            encoding="utf-8",
            errors="replace",
            timeout=timeout,
        )
        output = completed.stdout
        result_code = completed.returncode
        match = re.search(r"Ran (\d+) tests? in ", output)
        count = int(match.group(1)) if match else 0
        if result_code == 0 and count == 0:
            state = "MANUAL_NOT_DISCOVERED"
        elif result_code == 0:
            state = "PASS"
        else:
            state = "FAIL"
    except subprocess.TimeoutExpired as exc:
        output = exc.stdout or b""
        if isinstance(output, bytes):
            output = output.decode("utf-8", "replace")
        count = 0
        state = "TIMEOUT"

    if log_dir:
        (log_dir / (path.stem + ".txt")).write_text(output, encoding="utf-8")

    summary = [
        line for line in output.splitlines()
        if line.startswith(("FAILED", "Ran ", "OK", "ERROR:", "FAIL:"))
    ][-14:]
    return {
        "file": path.name,
        "status": state,
        "tests": count,
        "seconds": round(time.monotonic() - started, 3),
        "exit_code": result_code,
        "summary": summary,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--pattern", default="test_*.py")
    parser.add_argument("--workers", type=int, default=4)
    parser.add_argument("--timeout", type=float, default=15.0)
    parser.add_argument("--report", type=Path)
    parser.add_argument("--log-dir", type=Path)
    args = parser.parse_args()

    if args.workers < 1 or args.workers > 8:
        parser.error("--workers must be 1-8")
    if args.timeout <= 0 or args.timeout > 90:
        parser.error("--timeout must be >0 and <=90")
    if not CDP.is_file() and not (CDP / "ul_sniffer.py").exists():
        parser.error("Shared UL sniffer source is unavailable")

    files = sorted(TESTS.glob(args.pattern))
    if not files:
        parser.error("No test files matched")
    if args.log_dir:
        args.log_dir.mkdir(parents=True, exist_ok=True)

    started = time.monotonic()
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        results = list(pool.map(
            lambda path: run_one(
                path, timeout=args.timeout, log_dir=args.log_dir,
            ),
            files,
        ))

    for row in results:
        print(
            f"{row['status']:22s} {row['file']:46s} "
            f"cases={row['tests']:3d} seconds={row['seconds']:.2f}",
            flush=True,
        )

    counts = {
        name: sum(r["status"] == name for r in results)
        for name in ("PASS", "FAIL", "TIMEOUT", "MANUAL_NOT_DISCOVERED")
    }
    test_count = sum(r["tests"] for r in results if r["status"] == "PASS")
    summary = {
        "counts": counts,
        "passed_cases": test_count,
        "total_files": len(results),
        "seconds": round(time.monotonic() - started, 2),
        "network": "non-loopback socket connect/UDP blocked in test process",
        "results": results,
    }
    print("COUNTS", counts, "PASS_CASES", test_count, flush=True)
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(
            json.dumps(summary, ensure_ascii=False, indent=2),
            encoding="utf-8",
        )
        print("REPORT", args.report, flush=True)

    return 0 if counts["FAIL"] == 0 and counts["TIMEOUT"] == 0 and test_count else 1


if __name__ == "__main__":
    sys.exit(main())
