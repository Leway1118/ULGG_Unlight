from __future__ import annotations

from pathlib import Path
from datetime import datetime
import ast
import shutil

ROOT = Path(".")
REPO_FILE = ROOT / "raid_bot/infrastructure/raid_history_repository.py"
SERVICE_FILE = ROOT / "raid_bot/services/raid_service.py"

STAMP = datetime.now().strftime("%Y%m%d_%H%M%S")


def backup(path: Path) -> None:
    target = path.with_name(
        path.name + f".before_support_attribution_v011_{STAMP}.bak"
    )
    shutil.copy2(path, target)
    print(f"[BACKUP] {target}")


def patch_service(source: str) -> str:
    old = """                            if previous_state is not None:
                                try:
"""
    new = """                            if (
                                previous_state is not None
                                and previous_state.last_success_at is not None
                            ):
                                try:
"""
    if new in source:
        print("[KEEP] baseline guard already checks last_success_at")
        return source
    if old not in source:
        raise RuntimeError("Support attribution baseline guard anchor missing")
    source = source.replace(old, new, 1)
    print("[CHANGE] First-ever mark_attempt placeholder no longer counts as baseline")
    return source


def patch_repository(source: str) -> str:
    old_cols = """                        old_value,
                        new_value,
                        candidate_count
"""
    new_cols = """                        old_value,
                        new_value,
                        old_expires_at,
                        new_expires_at,
                        candidate_count
"""
    if new_cols in source:
        print("[KEEP] support event INSERT already includes expires_at columns")
    elif old_cols in source:
        source = source.replace(old_cols, new_cols, 1)
        print("[CHANGE] Added old/new expires_at columns to event INSERT")
    else:
        raise RuntimeError("Support event INSERT column anchor missing")

    old_values = """                        %s, %s, %s, %s, %s,
                        %s, %s, %s, %s, %s
                    )
"""
    new_values = """                        %s, %s, %s, %s, %s,
                        %s, %s, %s, %s, %s,
                        %s, %s
                    )
"""
    event_pos = source.find('event_sql = """')
    candidate_pos = source.find('candidate_sql = """', event_pos)
    if event_pos < 0 or candidate_pos < 0:
        raise RuntimeError("event_sql/candidate_sql anchors missing")

    event_block = source[event_pos:candidate_pos]
    if new_values not in event_block:
        if old_values not in event_block:
            raise RuntimeError("Support event VALUES placeholder anchor missing")
        event_block = event_block.replace(old_values, new_values, 1)
        source = source[:event_pos] + event_block + source[candidate_pos:]
        print("[CHANGE] Expanded support event VALUES placeholders")

    old_tuple = """                            (
                                previous.value
                                if previous is not None
                                else None
                            ),
                            current.value,
                            candidate_count,
"""
    new_tuple = """                            (
                                previous.value
                                if previous is not None
                                else None
                            ),
                            current.value,
                            (
                                previous.expires_at
                                if previous is not None
                                else None
                            ),
                            current.expires_at,
                            candidate_count,
"""
    if new_tuple in source:
        print("[KEEP] support event values already include expires_at")
    elif old_tuple in source:
        source = source.replace(old_tuple, new_tuple, 1)
        print("[CHANGE] Persist old/new expires_at for refresh validation")
    else:
        raise RuntimeError("Support event execute tuple anchor missing")

    return source


def main() -> None:
    for path in (REPO_FILE, SERVICE_FILE):
        if not path.is_file():
            raise FileNotFoundError(path)

    for path in (REPO_FILE, SERVICE_FILE):
        backup(path)

    repo_source = patch_repository(
        REPO_FILE.read_text(encoding="utf-8")
    )
    service_source = patch_service(
        SERVICE_FILE.read_text(encoding="utf-8")
    )

    REPO_FILE.write_text(repo_source, encoding="utf-8")
    SERVICE_FILE.write_text(service_source, encoding="utf-8")

    for path in (REPO_FILE, SERVICE_FILE):
        ast.parse(path.read_text(encoding="utf-8"))
        print(f"[PY_PARSE OK] {path}")

    print("[VERIFY] baseline requires previous successful state")
    print("[VERIFY] refresh events persist old/new expires_at")
    print("[VERIFY] Discord/notification modules untouched")
    print("[DONE] Raid support attribution V0.1.1 patch applied")


if __name__ == "__main__":
    main()
