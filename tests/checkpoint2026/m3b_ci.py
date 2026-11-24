#!/usr/bin/env python3
"""UL.GG Checkpoint 2026 M3-B-R1 synthetic-only CI controller.

Runs PHP M1+M2+M3B, cross-process on-disk SQLite snapshot E2E,
then a real 140k-row *synthetic* SQLite query plan benchmark.

This script does not use config.php, OAuth, MariaDB, network, or real game data.
Only the dedicated test fixture may create a database, under a NEW /tmp dir.
"""
from __future__ import annotations
import argparse
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

ROOT = Path(__file__).resolve().parents[2]
EXPECTED = {
    "m1": ("run.php", "CHECKPOINT_D1M1_RESULTS pass=28 fail=0 tests=28"),
    "m2": ("m2.php", "M2_TDD pass=22 fail=0 total=22"),
    "m3b": ("m3b.php", "M3B_TDD pass=31 fail=0 total=31"),
}
CI_SECRET_KEYS = (
    "DB_HOST", "DB_NAME", "DB_USER", "DB_PASSWORD",
    "MYSQL_HOST", "MYSQL_PASSWORD", "TW_AUTH_TOKEN",
    "STEAM_API_KEY", "STEAM_SECRET",
)


def checked(command: list[str], timeout: int, label: str) -> str:
    result = subprocess.run(
        command,
        cwd=ROOT,
        timeout=timeout,
        check=False,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        encoding="utf-8",
        errors="replace",
        env=os.environ.copy(),
    )
    output = result.stdout
    if result.returncode:
        raise RuntimeError(
            f"FAIL_{label}_EXIT_{result.returncode}: {output[-2400:]}"
        )
    return output


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--php", type=Path, required=True)
    parser.add_argument("--report-dir", type=Path, required=True)
    parser.add_argument("--rounds", type=int, default=2)
    args = parser.parse_args()
    reportdir = args.report_dir.resolve()
    if not reportdir.is_dir() or not args.php.is_file():
        raise RuntimeError("FAIL_EXPLICIT_EXISTING_REPORT_DIR_AND_PHP_REQUIRED")
    if args.rounds not in (1, 2, 3):
        raise RuntimeError("FAIL_ROUNDS_NOT_1_TO_3")
    nonempty = [k for k in CI_SECRET_KEYS if os.environ.get(k, "")]
    if nonempty:
        raise RuntimeError(
            "FAIL_LIVE_DB_OR_GAME_CONFIG_DETECTED: " + ",".join(nonempty)
        )
    php = str(args.php.resolve())
    report = {
        "task": "ULGG_CHECKPOINT2026_M3B_R1",
        "scope": "SYNTHETIC_PHP_SQLITE_ONLY",
        "runtime": "UNVERIFIED",
        "status": "BLOCKED",
        "rounds": args.rounds,
        "unit_pass_total": 0,
        "tests": [],
        "cross_process": [],
        "cross_process_temp_removed": False,
        "performance": {},
        "errors": [],
        "no_prod_db": True,
        "no_game": True,
    }
    start = time.monotonic()
    output_path = reportdir / "m3b-r1-synthetic-ci.json"
    try:
        version = checked([php, "-r", "echo PHP_VERSION;"], 8, "PHP_VERSION").strip()
        if not (version.startswith("8.0.") or version.startswith("8.3.")):
            raise RuntimeError("FAIL_UNAPPROVED_PHP_RUNTIME_" + version)
        report["runtime"] = version
        sqlite = checked([php, "-r", 'echo extension_loaded("pdo_sqlite")?"YES":"NO";'], 8, "PDO_SQLITE")
        if sqlite.strip() != "YES":
            raise RuntimeError("FAIL_PDO_SQLITE_ABSENT")
        for idx in range(1, args.rounds + 1):
            for name, (filename, expected) in EXPECTED.items():
                result = checked(
                    [php, str(ROOT / "tests" / "checkpoint2026" / filename)],
                    40,
                    f"PHP_{name.upper()}_ROUND_{idx}",
                )
                if expected not in result.splitlines():
                    raise RuntimeError(f"FAIL_ASSERTION_{name}_{idx}: {result[-2400:]}")
                report["tests"].append({"round": idx, "suite": name, "result": expected})
                report["unit_pass_total"] += {"m1": 28, "m2": 22, "m3b": 31}[name]
                print(f"M3B_R1_UNIT_ROUND_{idx}_{name.upper()}_PASS", flush=True)

        # Directory prefix is an explicit contract in m3b_persist.php.
        # Unique per CI job; even a PHP exception cannot leave it behind.
        with tempfile.TemporaryDirectory(prefix="ULGG-M3B-SYNTH-") as directory:
            db = Path(directory) / "annual-ledger.sqlite"
            for step in ("write", "verify", "deny-write", "verify"):
                text = checked(
                    [php, str(ROOT / "tests" / "checkpoint2026" / "m3b_persist.php"),
                     step, str(db)],
                    30,
                    f"LEDGER_{step.upper()}",
                )
                expected = {
                    "write": "M3B_PERSIST_ANNUAL_WRITE_PASS",
                    "verify": "M3B_PERSIST_REOPEN_VERIFY_PASS",
                    "deny-write": "M3B_PERSIST_READONLY_DENIED_PASS",
                }[step]
                if expected not in text:
                    raise RuntimeError(f"FAIL_LEDGER_{step.upper()}_MISSING_ACK")
                report["cross_process"].append(step)
                print(f"M3B_R1_CROSS_PROCESS_{step.upper()}_PASS", flush=True)
            if not db.is_file():
                raise RuntimeError("FAIL_LEDGER_NO_RESTART_PERSISTENCE")
        report["cross_process_temp_removed"] = not Path(directory).exists()
        if not report["cross_process_temp_removed"]:
            raise RuntimeError("FAIL_LEDGER_TEMP_NOT_REMOVED")

        perf_path = reportdir / "m3b-r1-140k-synthetic-performance.json"
        perf_output = checked(
            [php, str(ROOT / "tests" / "checkpoint2026" / "m3b_perf.php"),
             str(perf_path)],
            210,
            "PERF_140K",
        )
        if "M3B_PERF_SYNTHETIC_PASS" not in perf_output:
            raise RuntimeError("FAIL_PERF_SUCCESS_MARKER")
        metrics = json.loads(perf_path.read_text(encoding="utf-8"))
        assert metrics["fixture"]["arena_matches"] == 70000
        assert metrics["fixture"]["player_result_rows"] == 140000
        assert metrics["data_result_identical"] is True
        assert metrics["baseline"]["results"]["count"] == metrics["candidate_only"]["results"]["count"]
        assert metrics["baseline"]["results"]["count"] >= 100
        assert metrics["production_index_changes"] == 0
        assert bool(metrics["baseline"]["query_plan"]) and bool(metrics["candidate_only"]["query_plan"])
        report["performance"] = {
            "arena_matches": 70000,
            "player_result_rows": 140000,
            "selected": metrics["baseline"]["results"]["count"],
            "baseline_median_ms": metrics["baseline"]["results"]["median_ms"],
            "candidate_median_ms": metrics["candidate_only"]["results"]["median_ms"],
            "same_rows": True,
            "production_indexes_changed": False,
        }
        print("M3B_R1_SYNTHETIC_PERFORMANCE_140K_PASS", flush=True)
        report["status"] = "PASS"
        return 0
    except Exception as exc:
        report["errors"].append(type(exc).__name__ + ": " + str(exc)[:1500])
        print("M3B_R1_SYNTHETIC_GATE_BLOCKED: " + repr(exc), flush=True)
        return 1
    finally:
        report["elapsed_seconds"] = round(time.monotonic() - start, 3)
        output_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n",
                               encoding="utf-8")
        print("M3B_R1_CI_EVIDENCE=" + str(output_path), flush=True)


if __name__ == "__main__":
    sys.exit(main())
