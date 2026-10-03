from __future__ import annotations

from pathlib import Path
from datetime import datetime
import ast
import re
import shutil

ROOT = Path(".")
REPO_FILE = ROOT / "raid_bot/infrastructure/raid_history_repository.py"
STAMP = datetime.now().strftime("%Y%m%d_%H%M%S")


def backup(path: Path) -> None:
    target = path.with_name(
        path.name + f".before_support_evidence_v021_{STAMP}.bak"
    )
    shutil.copy2(path, target)
    print(f"[BACKUP] {target}")


def find_method_span(source: str, method_name: str) -> tuple[int, int]:
    tree = ast.parse(source)
    cls = next(
        node for node in tree.body
        if isinstance(node, ast.ClassDef)
        and node.name == "RaidHistoryRepository"
    )
    method = next(
        node for node in cls.body
        if isinstance(node, ast.FunctionDef)
        and node.name == method_name
    )
    lines = source.splitlines(keepends=True)
    start = sum(len(x) for x in lines[:method.lineno - 1])
    end = sum(len(x) for x in lines[:method.end_lineno])
    return start, end


def patch_repository(source: str) -> str:
    method_start, method_end = find_method_span(source, "record_support_events")
    method = source[method_start:method_end]

    candidate_sql_pattern = re.compile(
        r'                candidate_sql = \"\"\"\n                    INSERT INTO raid_support_candidates \\(\n.*?                \"\"\"',
        re.DOTALL,
    )

    canonical_candidate_sql = (
        '                candidate_sql = """\n'
        '                    INSERT INTO raid_support_candidates (\n'
        '                        event_id,\n'
        '                        player_name,\n'
        '                        damage_before,\n'
        '                        damage_after,\n'
        '                        damage_delta,\n'
        '                        point_before,\n'
        '                        point_after,\n'
        '                        point_delta,\n'
        '                        is_new_player,\n'
        '                        attribution_weight\n'
        '                    )\n'
        '                    VALUES (\n'
        '                        %s, %s, %s, %s, %s,\n'
        '                        %s, %s, %s, %s, %s\n'
        '                    )\n'
        '                """'
    )

    method, count = candidate_sql_pattern.subn(
        canonical_candidate_sql, method, count=1
    )
    if count != 1:
        raise RuntimeError(f"candidate_sql replacement count={count}")
    print("[CHANGE] Rebuilt candidate INSERT with point evidence")

    collection_pattern = re.compile(
        r'        candidates = \\[\\]\n\n        for name, current_player in current_player_map\\.items\\(\\):\n.*?\n        candidate_count = len\\(candidates\\)\n',
        re.DOTALL,
    )

    canonical_collection = (
        "        candidates = []\n\n"
        "        for name, current_player in current_player_map.items():\n"
        "            previous_player = previous_player_map.get(name)\n\n"
        "            damage_before = (\n"
        "                self._nonnegative_int(previous_player.damage)\n"
        "                if previous_player is not None\n"
        "                else 0\n"
        "            )\n"
        "            damage_after = self._nonnegative_int(\n"
        "                current_player.damage\n"
        "            )\n"
        "            damage_delta = max(0, damage_after - damage_before)\n\n"
        "            point_before = (\n"
        "                self._nonnegative_int(previous_player.point)\n"
        "                if previous_player is not None\n"
        "                else 0\n"
        "            )\n"
        "            point_after = self._nonnegative_int(\n"
        "                current_player.point\n"
        "            )\n"
        "            point_delta = max(0, point_after - point_before)\n\n"
        "            is_new_player = previous_player is None\n\n"
        "            # V0.2: observable activity evidence only.\n"
        "            # This is NOT proof that this player cast the skill.\n"
        "            if not (\n"
        "                is_new_player\n"
        "                or point_delta > 0\n"
        "                or damage_delta > 0\n"
        "            ):\n"
        "                continue\n\n"
        "            candidates.append(\n"
        "                {\n"
        "                    \"player_name\": name,\n"
        "                    \"damage_before\": damage_before,\n"
        "                    \"damage_after\": damage_after,\n"
        "                    \"damage_delta\": damage_delta,\n"
        "                    \"point_before\": point_before,\n"
        "                    \"point_after\": point_after,\n"
        "                    \"point_delta\": point_delta,\n"
        "                    \"is_new_player\": is_new_player,\n"
        "                }\n"
        "            )\n\n"
        "        candidate_count = len(candidates)\n"
    )

    method, count = collection_pattern.subn(
        canonical_collection, method, count=1
    )
    if count != 1:
        raise RuntimeError(f"candidate collection replacement count={count}")
    print("[CHANGE] Candidate evidence = new player OR point delta OR damage delta")

    execute_pattern = re.compile(
        r'                        cursor\\.execute\\(\n                            candidate_sql,\n                            \\(\n.*?                            \\),\n                        \\)',
        re.DOTALL,
    )

    canonical_execute = (
        "                        cursor.execute(\n"
        "                            candidate_sql,\n"
        "                            (\n"
        "                                event_id,\n"
        "                                candidate[\"player_name\"],\n"
        "                                candidate[\"damage_before\"],\n"
        "                                candidate[\"damage_after\"],\n"
        "                                candidate[\"damage_delta\"],\n"
        "                                candidate[\"point_before\"],\n"
        "                                candidate[\"point_after\"],\n"
        "                                candidate[\"point_delta\"],\n"
        "                                1 if candidate[\"is_new_player\"] else 0,\n"
        "                                round(attribution_weight, 4),\n"
        "                            ),\n"
        "                        )"
    )

    method, count = execute_pattern.subn(
        canonical_execute, method, count=1
    )
    if count != 1:
        raise RuntimeError(f"candidate execute replacement count={count}")
    print("[CHANGE] Candidate rows persist point evidence")

    patched = source[:method_start] + method + source[method_end:]
    ast.parse(patched)

    new_start, new_end = find_method_span(patched, "record_support_events")
    new_method = patched[new_start:new_end]

    sql_start = new_method.find('                candidate_sql = """')
    sql_end = new_method.find('                """', sql_start + 30)
    sql_block = new_method[sql_start:sql_end]
    placeholders = sql_block.count("%s")
    if placeholders != 10:
        raise RuntimeError(f"candidate placeholder count={placeholders}, expected 10")

    for token in (
        '"point_before"',
        '"point_after"',
        '"point_delta"',
        "or point_delta > 0",
        "or damage_delta > 0",
    ):
        if token not in new_method:
            raise RuntimeError(f"Missing token after patch: {token}")

    print("[VERIFY] candidate placeholder count=10")
    print("[VERIFY] point_before/after/delta persisted")
    print("[VERIFY] damage-only candidate rule removed")
    return patched


def main() -> None:
    if not REPO_FILE.is_file():
        raise FileNotFoundError(REPO_FILE)
    backup(REPO_FILE)
    source = REPO_FILE.read_text(encoding='utf-8')
    patched = patch_repository(source)
    REPO_FILE.write_text(patched, encoding='utf-8')
    print(f"[PY_PARSE OK] {REPO_FILE}")
    print("[VERIFY] Discord/notification modules untouched")
    print("[DONE] Raid support evidence V0.2.1 patch applied")


if __name__ == "__main__":
    main()
