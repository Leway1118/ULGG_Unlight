#!/usr/bin/env python3
"""PR-only basic safety guard; never accesses production services or secrets."""
from __future__ import annotations

import json
import pathlib
import re
import subprocess
import sys

MAX_CHANGED_FILE_BYTES = 5 * 1024 * 1024
BLOCKED_DIRS = {"vendor", "node_modules", "venv311", "cache", "uploads", ".ssh", "__pycache__"}
BLOCKED_SUFFIXES = (".pem", ".ppk", ".key", ".har", ".log", ".jsonl", ".sqlite", ".sqlite3", ".bak")
SECRET_PATTERNS = (
    re.compile(r"github_pat_[A-Za-z0-9_]{15,}"),
    re.compile(r"ghp_[A-Za-z0-9]{20,}"),
    re.compile(r"AKIA[0-9A-Z]{16}"),
    re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    re.compile(r"discord(?:app)?\.com/api/webhooks/[0-9]+/[A-Za-z0-9_-]+"),
    re.compile(r"sk-[A-Za-z0-9]{20,}"),
)


def forbidden_path(name: str) -> bool:
    normalized = name.replace("\\", "/")
    parts = pathlib.PurePosixPath(normalized).parts
    if not parts or normalized.startswith("/") or ".." in parts:
        return True
    if any(part in BLOCKED_DIRS for part in parts):
        return True
    if name.startswith(("assets/pay/", "tool/raid-bot/data/", "database/Dump")):
        return True
    filename = parts[-1]
    # Preserve historical HEAD as-is, but reject new or changed live source JSON.
    if (len(parts) >= 3 and parts[0].casefold() == "steam"
            and parts[1].casefold() == "data"
            and filename.casefold().endswith((".json", ".jsonl"))):
        return True
    if (filename == ".env" or filename.startswith(".env.")) and filename != ".env.example":
        return True
    if filename.startswith(("id_rsa", "id_ed25519")):
        return True
    if filename.endswith(BLOCKED_SUFFIXES) or ".before_" in filename or ".backup_" in filename:
        return True
    return False


def contains_room_record(raw: bytes | str) -> bool:
    """Detect a raw Unlight room object regardless of its changed JSON path.

    Never include player/deck values in CI output. Existing tracked files not
    changed by the PR remain historical-review items, not automatic deletions.
    """
    try:
        data = json.loads(raw)
    except (ValueError, UnicodeError):
        return False

    def check(value: object, depth: int) -> bool:
        if depth > 5:
            return False
        if isinstance(value, dict):
            keys = {str(key).casefold() for key in value}
            if {"playera", "playerb", "decka", "deckb"}.issubset(keys):
                return True
            return any(check(child, depth + 1) for child in value.values())
        if isinstance(value, list):
            return any(check(child, depth + 1) for child in value)
        return False

    return check(data, 0)



def added_lines_with_secrets(patch: str) -> list[str]:
    matches = []
    for line in patch.splitlines():
        if not line.startswith("+") or line.startswith("+++"):
            continue
        if any(pattern.search(line[1:]) for pattern in SECRET_PATTERNS):
            matches.append("possible secret in added diff line (value hidden)")
    return matches


def git_output(*args: str) -> bytes:
    return subprocess.check_output(["git", *args])


def main(base: str) -> int:
    if not re.fullmatch(r"[0-9a-fA-F]{40}", base):
        print("[FAIL] invalid base commit SHA", file=sys.stderr)
        return 2
    try:
        raw = git_output("diff", "--name-only", "-z", "--diff-filter=ACMR", base, "HEAD", "--")
        names = [item.decode("utf-8", "surrogateescape") for item in raw.split(b"\0") if item]
        problems = []
        for name in names:
            if forbidden_path(name):
                problems.append(f"prohibited source path: {name}")
                continue
            path = pathlib.Path(name)
            if path.is_symlink():
                problems.append(f"symlink needs manual review: {name}")
            elif path.is_file():
                if path.stat().st_size > MAX_CHANGED_FILE_BYTES:
                    problems.append(f"source file over 5 MiB: {name}")
                elif name.casefold().endswith(".json") and contains_room_record(path.read_bytes()):
                    problems.append(f"raw room JSON payload prohibited (values hidden): {name}")

        patch = git_output("diff", "--no-ext-diff", "--no-color", "--unified=0", base, "HEAD", "--").decode(
            "utf-8", "replace"
        )
        problems.extend(added_lines_with_secrets(patch))
    except subprocess.CalledProcessError as exc:
        print(f"[FAIL] cannot inspect PR diff (git exit {exc.returncode})", file=sys.stderr)
        return 2

    if problems:
        for problem in problems:
            print(f"[FAIL] {problem}", file=sys.stderr)
        print("[FAIL] safety gate rejected changes; no secrets printed", file=sys.stderr)
        return 1

    print(f"[PASS] basic PR safety gate; reviewed {len(names)} changed paths")
    print("[NOTE] heuristic checks do not replace human secret/security review")
    return 0


if __name__ == "__main__":
    if len(sys.argv) != 2:
        raise SystemExit("usage: python3 scripts/ci/pr_guard.py <40-char-base-sha>")
    raise SystemExit(main(sys.argv[1]))
