from __future__ import annotations

from pathlib import Path
from datetime import datetime
import ast
import re
import shutil

ROOT = Path(".")
REPO_FILE = ROOT / "raid_bot/infrastructure/raid_history_repository.py"
SERVICE_FILE = ROOT / "raid_bot/services/raid_service.py"

STAMP = datetime.now().strftime("%Y%m%d_%H%M%S")


def backup(path: Path) -> None:
    target = path.with_name(
        path.name + f".before_support_attribution_v013_{STAMP}.bak"
    )
    shutil.copy2(path, target)
    print(f"[BACKUP] {target}")


def patch_service(source: str) -> str:
    guarded = """                            if (
                                previous_state is not None
                                and previous_state.last_success_at is not None
                            ):
"""
    if guarded in source:
        print("[KEEP] baseline guard already requires previous successful state")
        return source

    old = """                            if previous_state is not None:
"""
    if old not in source:
        raise RuntimeError("Support attribution baseline guard anchor missing")

    source = source.replace(old, guarded, 1)
    print("[CHANGE] Baseline now requires previous_state.last_success_at")
    return source


def patch_repository(source: str) -> str:
    event_start = source.find('                event_sql = """')
    candidate_start = source.find(
        '                candidate_sql = """',
        event_start,
    )

    if event_start < 0 or candidate_start < 0:
        raise RuntimeError("event_sql/candidate_sql block anchors missing")

    canonical_event_sql = (
        '                event_sql = """\n'
        '                    INSERT INTO raid_support_events (\n'
        '                        raid_id,\n'
        '                        observed_at,\n'
        '                        status_base,\n'
        '                        status_raw,\n'
        '                        event_type,\n'
        '                        old_level,\n'
        '                        new_level,\n'
        '                        old_value,\n'
        '                        new_value,\n'
        '                        old_expires_at,\n'
        '                        new_expires_at,\n'
        '                        candidate_count\n'
        '                    )\n'
        '                    VALUES (\n'
        '                        %s, %s, %s, %s, %s,\n'
        '                        %s, %s, %s, %s, %s,\n'
        '                        %s, %s\n'
        '                    )\n'
        '                """\n\n'
    )

    source = (
        source[:event_start]
        + canonical_event_sql
        + source[candidate_start:]
    )
    print("[CHANGE] Rebuilt support event INSERT with 12 columns/placeholders")

    execute_pattern = re.compile(
        """                    cursor\\.execute\\(
                        event_sql,
                        \\(
.*?                        \\),
                    \\)

                    event_id = cursor\\.lastrowid""",
        re.DOTALL,
    )

    canonical_execute = """                    cursor.execute(
                        event_sql,
                        (
                            str(raid.profound_id).strip(),
                            observed_at,
                            base,
                            str(current.raw_type or base),
                            event_type,
                            (
                                previous.level
                                if previous is not None
                                else None
                            ),
                            current.level,
                            (
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
                        ),
                    )

                    event_id = cursor.lastrowid"""

    source, count = execute_pattern.subn(
        canonical_execute,
        source,
        count=1,
    )
    if count != 1:
        raise RuntimeError(
            f"Support event cursor.execute block replacement count={count}"
        )

    print("[CHANGE] Added old/new expires_at values to event write")

    event_start = source.find('                event_sql = """')
    candidate_start = source.find(
        '                candidate_sql = """',
        event_start,
    )
    event_block = source[event_start:candidate_start]

    placeholder_count = event_block.count("%s")
    if placeholder_count != 12:
        raise RuntimeError(
            f"Support event placeholder count={placeholder_count}, expected 12"
        )

    required_tokens = (
        "old_expires_at",
        "new_expires_at",
        "previous.expires_at",
        "current.expires_at",
    )
    for token in required_tokens:
        if token not in source:
            raise RuntimeError(f"Missing token after patch: {token}")

    print("[VERIFY] support event placeholder count=12")
    print("[VERIFY] support event persists old/new expires_at")
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

    # Validate both modified sources before writing either one.
    ast.parse(repo_source)
    ast.parse(service_source)

    REPO_FILE.write_text(repo_source, encoding="utf-8")
    SERVICE_FILE.write_text(service_source, encoding="utf-8")

    print(f"[PY_PARSE OK] {REPO_FILE}")
    print(f"[PY_PARSE OK] {SERVICE_FILE}")
    print("[VERIFY] baseline requires previous successful state")
    print("[VERIFY] Discord/notification modules untouched")
    print("[DONE] Raid support attribution V0.1.3 patch applied")


if __name__ == "__main__":
    main()
