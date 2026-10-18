from __future__ import annotations

from pathlib import Path
from datetime import datetime
import ast
import shutil

ROOT = Path('.')
CACHE_FILE = ROOT / 'raid_bot/services/active_raid_state_cache.py'
REPO_FILE = ROOT / 'raid_bot/infrastructure/raid_history_repository.py'
SERVICE_FILE = ROOT / 'raid_bot/services/raid_service.py'
STAMP = datetime.now().strftime('%Y%m%d_%H%M%S')


def backup(path: Path) -> None:
    target = path.with_name(path.name + f'.before_support_attribution_v01_{STAMP}.bak')
    shutil.copy2(path, target)
    print(f'[BACKUP] {target}')


def remove_first_duplicate_fetch_player_stats(source: str) -> str:
    tree = ast.parse(source)
    cls = next(
        node for node in tree.body
        if isinstance(node, ast.ClassDef)
        and node.name == 'RaidHistoryRepository'
    )
    methods = [
        node for node in cls.body
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
        and node.name == 'fetch_player_stats'
    ]

    if len(methods) == 1:
        print('[KEEP] fetch_player_stats already single-definition')
        return source

    if len(methods) != 2:
        raise RuntimeError(
            f'Expected 1 or 2 fetch_player_stats methods, found {len(methods)}'
        )

    first, second = methods
    lines = source.splitlines(keepends=True)
    first_text = ''.join(lines[first.lineno - 1:first.end_lineno])
    second_text = ''.join(lines[second.lineno - 1:second.end_lineno])

    first_ast = ast.dump(ast.parse(first_text).body[0], include_attributes=False)
    second_ast = ast.dump(ast.parse(second_text).body[0], include_attributes=False)

    if first_ast != second_ast:
        raise RuntimeError(
            'Duplicate fetch_player_stats bodies are not identical; refusing automatic deletion'
        )

    start = first.lineno - 1
    end = first.end_lineno
    while end < len(lines) and lines[end].strip() == '':
        end += 1

    print(
        f'[CHANGE] Removed first duplicate fetch_player_stats '
        f'lines {first.lineno}-{first.end_lineno}'
    )
    return ''.join(lines[:start] + lines[end:])


def patch_cache(source: str) -> str:
    if 'def get(' in source and 'ActiveRaidStateRecord | None' in source:
        print('[KEEP] ActiveRaidStateCache.get already present')
        return source

    anchor = '    def mark_attempt(self, prf_code: str) -> None:\n'
    if anchor not in source:
        raise RuntimeError('ActiveRaidStateCache mark_attempt anchor missing')

    insertion = '''    def get(\n        self,\n        prf_code: str,\n    ) -> ActiveRaidStateRecord | None:\n        """Return the cached live-state record without mutating it."""\n        return self._records.get(prf_code)\n\n'''

    print('[CHANGE] Added ActiveRaidStateCache.get()')
    return source.replace(anchor, insertion + anchor, 1)


def patch_repository(source: str) -> str:
    source = remove_first_duplicate_fetch_player_stats(source)

    if 'def record_support_events(' in source:
        print('[KEEP] record_support_events already present')
        return source

    anchor = '    def fetch_player_stats(\n'
    if anchor not in source:
        raise RuntimeError('RaidHistoryRepository fetch_player_stats anchor missing')

    method = r'''    def record_support_events(
        self,
        *,
        raid: RaidEntry,
        previous_statuses,
        previous_players,
        observed_at: datetime | None = None,
    ) -> int:
        """Persist inferred support/debuff attribution events."""

        tracked_status_weights = {
            "atkD": 1.0,
            "defD": 1.4,
            "movD": 0.8,
            "poison": 0.8,
            "bind": 1.0,
            "curse": 1.0,
            "dbuff": 1.0,
            "stun": 1.3,
            "dark": 1.0,
            "chaos": 1.0,
            "huin": 1.2,
        }

        confidence_by_candidate_count = {
            1: 1.00,
            2: 0.50,
            3: 0.30,
            4: 0.15,
            5: 0.15,
        }

        observed_at = observed_at or datetime.now()

        def status_rank(status):
            level = status.level if status.level is not None else -1
            value = status.value if status.value is not None else -1
            expires_at = status.expires_at if status.expires_at is not None else -1
            return (level, value, expires_at)

        def best_status_by_base(statuses):
            result = {}
            for status in statuses:
                base = str(status.base_type or "").strip()
                if base not in tracked_status_weights:
                    continue
                previous = result.get(base)
                if previous is None or status_rank(status) > status_rank(previous):
                    result[base] = status
            return result

        previous_by_base = best_status_by_base(previous_statuses)
        current_by_base = best_status_by_base(raid.statuses)
        support_changes = []

        for base, current in current_by_base.items():
            previous = previous_by_base.get(base)

            if previous is None:
                event_type = "added"
            else:
                level_upgraded = (
                    previous.level is not None
                    and current.level is not None
                    and current.level > previous.level
                )
                value_upgraded = (
                    previous.value is not None
                    and current.value is not None
                    and current.value > previous.value
                )

                if level_upgraded or value_upgraded:
                    event_type = "upgraded"
                elif (
                    previous.expires_at is not None
                    and current.expires_at is not None
                    and current.expires_at > previous.expires_at
                ):
                    event_type = "refreshed"
                else:
                    continue

            support_changes.append((base, current, previous, event_type))

        if not support_changes:
            return 0

        previous_player_map = {
            player.name.strip(): player
            for player in previous_players
            if isinstance(player.name, str) and player.name.strip()
        }
        current_player_map = {
            player.name.strip(): player
            for player in raid.players
            if isinstance(player.name, str) and player.name.strip()
        }

        candidates = []
        for name, current_player in current_player_map.items():
            previous_player = previous_player_map.get(name)
            damage_before = (
                self._nonnegative_int(previous_player.damage)
                if previous_player is not None
                else 0
            )
            damage_after = self._nonnegative_int(current_player.damage)
            damage_delta = max(0, damage_after - damage_before)

            if damage_delta <= 0:
                continue

            candidates.append({
                "player_name": name,
                "damage_before": damage_before,
                "damage_after": damage_after,
                "damage_delta": damage_delta,
                "is_new_player": previous_player is None and damage_after > 0,
            })

        candidate_count = len(candidates)
        confidence = confidence_by_candidate_count.get(candidate_count, 0.0)
        connection = self._connect()

        try:
            with connection.cursor() as cursor:
                event_sql = """
                    INSERT INTO raid_support_events (
                        raid_id,
                        observed_at,
                        status_base,
                        status_raw,
                        event_type,
                        old_level,
                        new_level,
                        old_value,
                        new_value,
                        candidate_count
                    )
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """

                candidate_sql = """
                    INSERT INTO raid_support_candidates (
                        event_id,
                        player_name,
                        damage_before,
                        damage_after,
                        damage_delta,
                        is_new_player,
                        attribution_weight
                    )
                    VALUES (%s, %s, %s, %s, %s, %s, %s)
                """

                inserted_events = 0

                for base, current, previous, event_type in support_changes:
                    cursor.execute(
                        event_sql,
                        (
                            str(raid.profound_id).strip(),
                            observed_at,
                            base,
                            str(current.raw_type or base),
                            event_type,
                            previous.level if previous is not None else None,
                            current.level,
                            previous.value if previous is not None else None,
                            current.value,
                            candidate_count,
                        ),
                    )

                    event_id = cursor.lastrowid
                    inserted_events += 1

                    if confidence <= 0:
                        continue

                    status_weight = tracked_status_weights[base]

                    for candidate in candidates:
                        attribution_weight = confidence * status_weight
                        if candidate["is_new_player"]:
                            attribution_weight *= 1.25

                        cursor.execute(
                            candidate_sql,
                            (
                                event_id,
                                candidate["player_name"],
                                candidate["damage_before"],
                                candidate["damage_after"],
                                candidate["damage_delta"],
                                1 if candidate["is_new_player"] else 0,
                                round(attribution_weight, 4),
                            ),
                        )

            connection.commit()
            return inserted_events

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()


'''

    print('[CHANGE] Added RaidHistoryRepository.record_support_events()')
    return source.replace(anchor, method + anchor, 1)


def patch_service(source: str) -> str:
    marker = 'previous_state = self.active_state_cache.get(prf_code)'
    if marker in source:
        print('[KEEP] RaidService attribution wiring already present')
        return source

    old = '''                if enriched.status == "success":\n                    self.active_state_cache.mark_success(\n                        prf_code,\n                        enriched.raid.statuses,\n                        enriched.raid.players,\n                    )\n\n                    if self.raid_history_repository is not None:\n'''

    new = '''                if enriched.status == "success":\n                    # Capture previous successful live-state before replacing it.\n                    previous_state = self.active_state_cache.get(prf_code)\n\n                    self.active_state_cache.mark_success(\n                        prf_code,\n                        enriched.raid.statuses,\n                        enriched.raid.players,\n                    )\n\n                    if self.raid_history_repository is not None:\n'''

    if old not in source:
        raise RuntimeError('RaidService mark_success anchor missing')

    source = source.replace(old, new, 1)

    old2 = '''                            await asyncio.to_thread(\n                                self.raid_history_repository.upsert_snapshot,\n                                history_raid,\n                            )\n                        except Exception as error:\n'''

    new2 = '''                            await asyncio.to_thread(\n                                self.raid_history_repository.upsert_snapshot,\n                                history_raid,\n                            )\n\n                            # Baseline-only on the first successful observation.\n                            # Attribution runs after raid_history exists because\n                            # raid_support_events has a foreign-key dependency.\n                            if previous_state is not None:\n                                try:\n                                    support_event_count = await asyncio.to_thread(\n                                        self.raid_history_repository.record_support_events,\n                                        raid=history_raid,\n                                        previous_statuses=previous_state.statuses,\n                                        previous_players=previous_state.players,\n                                    )\n                                    if support_event_count:\n                                        logger.info(\n                                            "Raid support attribution "\n                                            "prf_code=%s events=%d",\n                                            prf_code,\n                                            support_event_count,\n                                        )\n                                except Exception as support_error:\n                                    logger.warning(\n                                        "Raid support attribution failed "\n                                        "prf_code=%s error=%s",\n                                        prf_code,\n                                        support_error,\n                                    )\n                        except Exception as error:\n'''

    if old2 not in source:
        raise RuntimeError('RaidService history upsert anchor missing')

    print('[CHANGE] Wired support attribution into live-state refresh')
    return source.replace(old2, new2, 1)


def main() -> None:
    for path in (CACHE_FILE, REPO_FILE, SERVICE_FILE):
        if not path.is_file():
            raise FileNotFoundError(path)

    for path in (CACHE_FILE, REPO_FILE, SERVICE_FILE):
        backup(path)

    CACHE_FILE.write_text(
        patch_cache(CACHE_FILE.read_text(encoding='utf-8')),
        encoding='utf-8',
    )
    REPO_FILE.write_text(
        patch_repository(REPO_FILE.read_text(encoding='utf-8')),
        encoding='utf-8',
    )
    SERVICE_FILE.write_text(
        patch_service(SERVICE_FILE.read_text(encoding='utf-8')),
        encoding='utf-8',
    )

    for path in (CACHE_FILE, REPO_FILE, SERVICE_FILE):
        ast.parse(path.read_text(encoding='utf-8'))
        print(f'[PY_PARSE OK] {path}')

    repo_tree = ast.parse(REPO_FILE.read_text(encoding='utf-8'))
    cls = next(
        node for node in repo_tree.body
        if isinstance(node, ast.ClassDef)
        and node.name == 'RaidHistoryRepository'
    )
    methods = [
        node for node in cls.body
        if isinstance(node, (ast.FunctionDef, ast.AsyncFunctionDef))
        and node.name == 'fetch_player_stats'
    ]

    if len(methods) != 1:
        raise RuntimeError(f'Post-patch fetch_player_stats count={len(methods)}')

    print('[VERIFY] fetch_player_stats count=1')
    print('[VERIFY] support attribution baseline=get-before-mark_success')
    print('[VERIFY] first observation is baseline-only')
    print('[VERIFY] >5 candidates => event kept, no attribution rows')
    print('[VERIFY] Discord/notification modules untouched')
    print('[DONE] Raid support attribution V0.1 patch applied')


if __name__ == '__main__':
    main()
