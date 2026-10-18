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
        path.name + f".before_support_evidence_v02_{STAMP}.bak"
    )
    shutil.copy2(path, target)
    print(f"[BACKUP] {target}")


def patch_repository(source: str) -> str:
    candidate_start = source.find('                candidate_sql = """')
    if candidate_start < 0:
        raise RuntimeError("candidate_sql anchor missing")

    candidate_end = source.find('                """', candidate_start + 30)
    if candidate_end < 0:
        raise RuntimeError("candidate_sql closing quote missing")
    candidate_end += len('                """')

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

    source = source[:candidate_start] + canonical_candidate_sql + source[candidate_end:]
    print("[CHANGE] Rebuilt candidate INSERT with point_before/after/delta")

    start_marker = "        candidates = []\n\n        for name, current_player in current_player_map.items():\n"
    start = source.find(start_marker)
    if start < 0:
        raise RuntimeError("candidate collection start anchor missing")

    end_marker = "        candidate_count = len(candidates)\n"
    end = source.find(end_marker, start)
    if end < 0:
        raise RuntimeError("candidate collection end anchor missing")

    canonical_collection = (
        "        candidates = []\n\n"
        "        for name, current_player in current_player_map.items():\n"
        "            previous_player = previous_player_map.get(name)\n\n"
        "            damage_before = (\n"
        "                self._nonnegative_int(previous_player.damage)\n"
        "                if previous_player is not None\n"
        "                else 0\n"
        "            )\n"
        "            damage_after = self._nonnegative_int(current_player.damage)\n"
        "            damage_delta = max(0, damage_after - damage_before)\n\n"
        "            point_before = (\n"
        "                self._nonnegative_int(previous_player.point)\n"
        "                if previous_player is not None\n"
        "                else 0\n"
        "            )\n"
        "            point_after = self._nonnegative_int(current_player.point)\n"
        "            point_delta = max(0, point_after - point_before)\n\n"
        "            is_new_player = previous_player is None\n\n"
        "            # V0.2: observable activity evidence, not caster proof.\n"
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
    )

    source = source[:start] + canonical_collection + source[end:]
    print("[CHANGE] Candidate evidence = new player OR point delta OR damage delta")

    execute_pattern = re.compile(
        r'''                        cursor\.execute\(\n                            candidate_sql,\n                            \(\n.*?                            \),\n                        \)''',
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

    source, count = execute_pattern.subn(canonical_execute, source, count=1)
    if count != 1:
        raise RuntimeError(f"candidate cursor.execute replacement count={count}")
    print("[CHANGE] Candidate rows now persist point evidence")

    source = source.replace(
        "- only players whose damage increased are candidates;",
        "- candidates are observable activity evidence, not caster proof;",
        1,
    )

    required = (
        "point_before",
        "point_after",
        "point_delta",
        "or point_delta > 0",
        "or damage_delta > 0",
    )
    for token in required:
        if token not in source:
            raise RuntimeError(f"Missing token after patch: {token}")

    candidate_start = source.find('                candidate_sql = """')
    candidate_end = source.find('                """', candidate_start + 30)
    candidate_block = source[candidate_start:candidate_end]
    placeholder_count = candidate_block.count("%s")
    if placeholder_count != 10:
        raise RuntimeError(f"candidate placeholder count={placeholder_count}, expected 10")

    print("[VERIFY] candidate placeholder count=10")
    print("[VERIFY] point evidence fields present")
    print("[VERIFY] damage-only requirement removed")
    return source


def main() -> None:
    if not REPO_FILE.is_file():
        raise FileNotFoundError(REPO_FILE)

    backup(REPO_FILE)
    source = REPO_FILE.read_text(encoding='utf-8')
    patched = patch_repository(source)
    ast.parse(patched)
    REPO_FILE.write_text(patched, encoding='utf-8')
    print(f"[PY_PARSE OK] {REPO_FILE}")
    print("[VERIFY] Discord/notification modules untouched")
    print("[DONE] Raid support evidence V0.2 patch applied")


if __name__ == "__main__":
    main()
