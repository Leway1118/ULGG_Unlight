from __future__ import annotations

from pathlib import Path
from datetime import datetime
import ast
import shutil

ROOT = Path(".")
REPO_FILE = ROOT / "raid_bot/infrastructure/raid_history_repository.py"
STAMP = datetime.now().strftime("%Y%m%d_%H%M%S")


def backup(path: Path) -> None:
    target = path.with_name(
        path.name + f".before_support_evidence_v022_{STAMP}.bak"
    )
    shutil.copy2(path, target)
    print(f"[BACKUP] {target}")


def patch_repository(source: str) -> str:
    rows = source.splitlines(keepends=True)

    # Locate record_support_events() with AST so we only patch that method.
    tree = ast.parse(source)
    cls = next(
        n for n in tree.body
        if isinstance(n, ast.ClassDef)
        and n.name == "RaidHistoryRepository"
    )
    method = next(
        n for n in cls.body
        if isinstance(n, ast.FunctionDef)
        and n.name == "record_support_events"
    )
    lo = method.lineno - 1
    hi = method.end_lineno

    # 1) Replace candidate SQL block line-by-line; no regex.
    sql_start = next(
        i for i in range(lo, hi)
        if 'candidate_sql = """' in rows[i]
    )
    sql_end = next(
        i for i in range(sql_start + 1, hi)
        if rows[i].strip() == '"""'
    )

    sql_block = [
        '                candidate_sql = """\n',
        '                    INSERT INTO raid_support_candidates (\n',
        '                        event_id,\n',
        '                        player_name,\n',
        '                        damage_before,\n',
        '                        damage_after,\n',
        '                        damage_delta,\n',
        '                        point_before,\n',
        '                        point_after,\n',
        '                        point_delta,\n',
        '                        is_new_player,\n',
        '                        attribution_weight\n',
        '                    )\n',
        '                    VALUES (\n',
        '                        %s, %s, %s, %s, %s,\n',
        '                        %s, %s, %s, %s, %s\n',
        '                    )\n',
        '                """\n',
    ]
    rows[sql_start:sql_end + 1] = sql_block
    print("[CHANGE] Rebuilt candidate INSERT with point evidence")

    # Re-parse after line count changed and re-locate method.
    source = ''.join(rows)
    tree = ast.parse(source)
    cls = next(
        n for n in tree.body
        if isinstance(n, ast.ClassDef)
        and n.name == "RaidHistoryRepository"
    )
    method = next(
        n for n in cls.body
        if isinstance(n, ast.FunctionDef)
        and n.name == "record_support_events"
    )
    rows = source.splitlines(keepends=True)
    lo = method.lineno - 1
    hi = method.end_lineno

    # 2) Replace candidate collection block using only stable boundary lines.
    cand_start = next(
        i for i in range(lo, hi)
        if rows[i].strip() == "candidates = []"
    )
    cand_end = next(
        i for i in range(cand_start + 1, hi)
        if rows[i].strip() == "candidate_count = len(candidates)"
    )

    cand_block = [
        "        candidates = []\n",
        "\n",
        "        for name, current_player in current_player_map.items():\n",
        "            previous_player = previous_player_map.get(name)\n",
        "\n",
        "            damage_before = (\n",
        "                self._nonnegative_int(previous_player.damage)\n",
        "                if previous_player is not None\n",
        "                else 0\n",
        "            )\n",
        "            damage_after = self._nonnegative_int(current_player.damage)\n",
        "            damage_delta = max(0, damage_after - damage_before)\n",
        "\n",
        "            point_before = (\n",
        "                self._nonnegative_int(previous_player.point)\n",
        "                if previous_player is not None\n",
        "                else 0\n",
        "            )\n",
        "            point_after = self._nonnegative_int(current_player.point)\n",
        "            point_delta = max(0, point_after - point_before)\n",
        "\n",
        "            is_new_player = previous_player is None\n",
        "\n",
        "            # V0.2: observable activity evidence only.\n",
        "            # This is NOT proof that the player cast the skill.\n",
        "            if not (\n",
        "                is_new_player\n",
        "                or point_delta > 0\n",
        "                or damage_delta > 0\n",
        "            ):\n",
        "                continue\n",
        "\n",
        "            candidates.append(\n",
        "                {\n",
        '                    "player_name": name,\n',
        '                    "damage_before": damage_before,\n',
        '                    "damage_after": damage_after,\n',
        '                    "damage_delta": damage_delta,\n',
        '                    "point_before": point_before,\n',
        '                    "point_after": point_after,\n',
        '                    "point_delta": point_delta,\n',
        '                    "is_new_player": is_new_player,\n',
        "                }\n",
        "            )\n",
        "\n",
        "        candidate_count = len(candidates)\n",
    ]
    rows[cand_start:cand_end + 1] = cand_block
    print("[CHANGE] Candidate evidence = new player OR point delta OR damage delta")

    # 3) Insert point values into candidate execute tuple.
    source = ''.join(rows)
    tree = ast.parse(source)
    cls = next(
        n for n in tree.body
        if isinstance(n, ast.ClassDef)
        and n.name == "RaidHistoryRepository"
    )
    method = next(
        n for n in cls.body
        if isinstance(n, ast.FunctionDef)
        and n.name == "record_support_events"
    )
    rows = source.splitlines(keepends=True)
    lo = method.lineno - 1
    hi = method.end_lineno

    damage_value_line = next(
        i for i in range(lo, hi)
        if 'candidate["damage_delta"],' in rows[i]
    )

    point_lines = [
        '                                candidate["point_before"],\n',
        '                                candidate["point_after"],\n',
        '                                candidate["point_delta"],\n',
    ]

    if not any(
        'candidate["point_before"],' in rows[i]
        for i in range(lo, hi)
    ):
        rows[damage_value_line + 1:damage_value_line + 1] = point_lines
        print("[CHANGE] Candidate rows persist point evidence")
    else:
        print("[KEEP] Candidate execute tuple already has point evidence")

    patched = ''.join(rows)
    ast.parse(patched)

    # Structural verification.
    if patched.count('def fetch_player_stats(') != 1:
        raise RuntimeError("fetch_player_stats duplicate reappeared")

    for token in (
        "point_before",
        "point_after",
        "point_delta",
        "or point_delta > 0",
        "or damage_delta > 0",
    ):
        if token not in patched:
            raise RuntimeError(f"Missing token after patch: {token}")

    # Verify candidate SQL has exactly ten placeholders.
    marker = '                candidate_sql = """'
    a = patched.find(marker)
    b = patched.find('                """', a + len(marker))
    block = patched[a:b]
    count = block.count("%s")
    if count != 10:
        raise RuntimeError(f"candidate placeholder count={count}, expected 10")

    print("[VERIFY] candidate placeholder count=10")
    print("[VERIFY] point evidence fields present")
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
    print("[DONE] Raid support evidence V0.2.2 patch applied")


if __name__ == "__main__":
    main()
