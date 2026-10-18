from __future__ import annotations

import json

import os
from datetime import datetime

import pymysql

from ..domain.models import RaidEntry


class RaidHistoryRepository:
    """Persist latest Raid/player snapshots into MySQL.

    One Raid = one raid_history row.
    One Raid + player name = one raid_player_history row.
    """

    def __init__(
        self,
        *,
        host: str,
        user: str,
        password: str,
        database: str,
        port: int = 3306,
    ) -> None:
        self.host = host
        self.user = user
        self.password = password
        self.database = database
        self.port = port

    @classmethod
    def from_environment(cls) -> "RaidHistoryRepository":
        required = (
            "DB_HOST",
            "DB_USER",
            "DB_PASSWORD",
            "DB_NAME",
        )

        missing = [
            key
            for key in required
            if not os.getenv(key)
        ]

        if missing:
            raise RuntimeError(
                "Raid history DB environment missing: "
                + ", ".join(missing)
            )

        return cls(
            host=os.environ["DB_HOST"],
            user=os.environ["DB_USER"],
            password=os.environ["DB_PASSWORD"],
            database=os.environ["DB_NAME"],
            port=int(os.getenv("DB_PORT", "3306")),
        )

    def _connect(self):
        return pymysql.connect(
            host=self.host,
            user=self.user,
            password=self.password,
            database=self.database,
            port=self.port,
            charset="utf8mb4",
            autocommit=False,
        )

    def check_connection(self) -> None:
        connection = self._connect()

        try:
            with connection.cursor() as cursor:
                cursor.execute("SELECT 1")
                cursor.fetchone()

        finally:
            connection.close()


    # RAID_DISCORD_RECONCILE_DB_V1
    def fetch_lifecycle_statuses(
        self,
        raid_ids,
    ) -> dict[str, str]:
        raid_ids = tuple(dict.fromkeys(
            str(value).strip()
            for value in raid_ids
            if str(value).strip()
        ))

        if not raid_ids:
            return {}

        placeholders = ",".join(
            ["%s"] * len(raid_ids)
        )

        sql = f"""
            SELECT
                raid_id,
                lifecycle_status
            FROM raid_history
            WHERE raid_id IN ({placeholders})
        """

        connection = self._connect()

        try:
            with connection.cursor() as cursor:
                cursor.execute(
                    sql,
                    raid_ids,
                )

                rows = cursor.fetchall()

            return {
                str(row[0]): str(
                    row[1] or ""
                ).strip().lower()
                for row in rows
            }

        finally:
            connection.close()


    @staticmethod
    def _optional_nonnegative_int(value):
        if value is None or isinstance(value, bool):
            return None

        try:
            result = int(value)
        except (TypeError, ValueError):
            return None

        return result if result >= 0 else None

    @staticmethod
    def _nonnegative_int(value) -> int:
        parsed = RaidHistoryRepository._optional_nonnegative_int(value)
        return 0 if parsed is None else parsed

    # RAID_HISTORY_PUBLIC_SNAPSHOT_V1
    def upsert_public_snapshots(
        self,
        raids,
    ) -> int:
        """Persist public Raid visibility without requiring enrichment."""

        now = datetime.now()

        values = []

        for raid in raids:
            raid_id = str(
                getattr(raid, "profound_id", "")
                or ""
            ).strip()

            if not raid_id:
                continue

            values.append(
                (
                    raid_id,
                    getattr(raid, "founder", None),
                    getattr(raid, "boss_name", None),
                    getattr(raid, "monster_code", None),

                    # RAID_HISTORY_MONSTER_LEVEL_V1
                    self._optional_nonnegative_int(
                        getattr(raid, "monster_id", None)
                    ),

                    self._optional_nonnegative_int(
                        getattr(raid, "rarity", None)
                    ),

                    self._optional_nonnegative_int(
                        getattr(raid, "level", None)
                    ),

                    self._optional_nonnegative_int(
                        getattr(raid, "treasure_level", None)
                    ),
                    self._optional_nonnegative_int(
                        getattr(raid, "map_id", None)
                    ),
                    self._optional_nonnegative_int(
                        getattr(raid, "stage_id", None)
                    ),
                    self._optional_nonnegative_int(
                        getattr(raid, "map_index", None)
                    ),
                    self._optional_nonnegative_int(
                        getattr(raid, "pos_index", None)
                    ),
                    self._optional_nonnegative_int(
                        getattr(raid, "hp_max", None)
                    ),
                    self._optional_nonnegative_int(
                        getattr(raid, "hp", None)
                    ),
                    self._optional_nonnegative_int(
                        getattr(raid, "member_limit", None)
                    ),
                    self._nonnegative_int(
                        getattr(
                            raid,
                            "participant_count",
                            0,
                        )
                    ),
                    self._optional_nonnegative_int(
                        getattr(raid, "expires_at", None)
                    ),
                    now,
                    now,
                )
            )

        if not values:
            return 0

        sql = """
            INSERT INTO raid_history (
                raid_id,
                founder,
                boss,
                monster_code,
                monster_id,
                rarity,
                raid_level,
                treasure_level,
                map_id,
                stage_id,
                map_index,
                pos_index,
                hp_max,
                hp_first_seen,
                member_limit,
                participant_count,
                expires_at_ms,
                first_seen_at,
                last_seen_at
            )
            VALUES (
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s
            )
            ON DUPLICATE KEY UPDATE
                founder =
                    COALESCE(
                        VALUES(founder),
                        founder
                    ),

                boss =
                    COALESCE(
                        VALUES(boss),
                        boss
                    ),

                monster_code =
                    COALESCE(
                        VALUES(monster_code),
                        monster_code
                    ),

                # RAID_HISTORY_MONSTER_LEVEL_V1
                monster_id =
                    COALESCE(
                        VALUES(monster_id),
                        monster_id
                    ),

                rarity =
                    COALESCE(
                        VALUES(rarity),
                        rarity
                    ),

                raid_level =
                    COALESCE(
                        VALUES(raid_level),
                        raid_level
                    ),

                treasure_level =
                    COALESCE(
                        VALUES(treasure_level),
                        treasure_level
                    ),

                map_id =
                    COALESCE(
                        VALUES(map_id),
                        map_id
                    ),

                stage_id =
                    COALESCE(
                        VALUES(stage_id),
                        stage_id
                    ),

                map_index =
                    COALESCE(
                        VALUES(map_index),
                        map_index
                    ),

                pos_index =
                    COALESCE(
                        VALUES(pos_index),
                        pos_index
                    ),

                hp_max =
                    COALESCE(
                        VALUES(hp_max),
                        hp_max
                    ),

                member_limit =
                    COALESCE(
                        VALUES(member_limit),
                        member_limit
                    ),

                participant_count =
                    GREATEST(
                        participant_count,
                        VALUES(participant_count)
                    ),

                expires_at_ms =
                    COALESCE(
                        VALUES(expires_at_ms),
                        expires_at_ms
                    ),

                last_seen_at =
                    VALUES(last_seen_at)
        """

        connection = self._connect()

        try:
            with connection.cursor() as cursor:
                cursor.executemany(
                    sql,
                    values,
                )

            connection.commit()

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()

        return len(values)


    # RAID_HISTORY_MONSTER_LEVEL_V1
    def update_protocol14_identity(
        self,
        *,
        raid_id: str,
        metadata: dict,
    ) -> bool:
        """Persist protocol-1.4 identity fields without inference."""

        raid_id = str(
            raid_id or ""
        ).strip()

        if not raid_id:
            return False

        monster_id = self._optional_nonnegative_int(
            metadata.get("monster_id")
        )

        raid_level = self._optional_nonnegative_int(
            metadata.get("level")
        )

        if (
            monster_id is None
            and raid_level is None
        ):
            return False

        connection = self._connect()

        try:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    UPDATE raid_history
                    SET
                        monster_id =
                            COALESCE(
                                %s,
                                monster_id
                            ),

                        raid_level =
                            COALESCE(
                                %s,
                                raid_level
                            )

                    WHERE raid_id = %s
                    """,
                    (
                        monster_id,
                        raid_level,
                        raid_id,
                    ),
                )

                changed = (
                    cursor.rowcount > 0
                )

            connection.commit()
            return changed

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()


    # RAID_HISTORY_RESEARCH_METADATA_V1
    # RAID_PLAYER_HISTORY_PROTOCOL14_V1
    def upsert_protocol14_rank(
        self,
        *,
        raid_id: str,
        players,
        source: str = "protocol14_db_raid_rank",
    ) -> dict:
        """
        Persist Protocol 1.4 rank[].

        Protocol 1.4 rank[] provides:
          player_name / rank / level / point

        It does NOT provide legacy damage.

        Existing known damage must never be
        overwritten by an unknown value.
        """

        raid_id = str(
            raid_id or ""
        ).strip()

        if not raid_id:
            return {
                "updated": False,
                "reason": "raid_id_missing",
                "players": 0,
                "rowcount": 0,
            }

        if not isinstance(
            players,
            (list, tuple),
        ):
            return {
                "updated": False,
                "reason": "players_invalid",
                "players": 0,
                "rowcount": 0,
            }

        source = str(
            source
            or "protocol14_db_raid_rank"
        ).strip()

        if not source:
            source = (
                "protocol14_db_raid_rank"
            )

        now = datetime.now()

        values = []

        for index, item in enumerate(
            players,
            start=1,
        ):
            if not isinstance(item, dict):
                continue

            name = item.get("name")

            if not isinstance(name, str):
                name = item.get(
                    "player_name"
                )

            if not isinstance(name, str):
                continue

            name = name.strip()

            if not name:
                continue

            rank_no = (
                self._optional_nonnegative_int(
                    item.get("rank")
                )
            )

            if (
                rank_no is None
                or rank_no <= 0
            ):
                rank_no = index

            player_level = (
                self._optional_nonnegative_int(
                    item.get("level")
                )
            )

            point = self._nonnegative_int(
                item.get("point")
            )

            values.append(
                (
                    raid_id,
                    name,
                    player_level,
                    rank_no,
                    point,

                    # Protocol 1.4 rank[]
                    # has no damage field.
                    # RAID_PROTOCOL14_UNKNOWN_DAMAGE_NULL_V1
                    None,
                    0,
                    None,

                    source,
                    now,
                    now,
                )
            )

        if not values:
            return {
                "updated": False,
                "reason": "players_empty",
                "players": 0,
                "rowcount": 0,
            }

        sql = """
            INSERT INTO raid_player_history (
                raid_id,
                player_name,
                player_level,
                rank_no,
                point,
                damage,
                damage_known,
                damage_source,
                rank_source,
                first_seen_at,
                last_seen_at
            )
            VALUES (
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s, %s
            )

            ON DUPLICATE KEY UPDATE

                player_level =
                    COALESCE(
                        VALUES(player_level),
                        player_level
                    ),

                rank_no =
                    COALESCE(
                        VALUES(rank_no),
                        rank_no
                    ),

                point =
                    VALUES(point),

                rank_source =
                    VALUES(rank_source),

                last_seen_at =
                    VALUES(last_seen_at)

                /*
                IMPORTANT:

                Do NOT update:
                  damage
                  damage_known
                  damage_source

                New Protocol 1.4 rank[]
                does not contain damage.

                Therefore any legacy known
                damage must remain untouched.
                */
        """

        connection = self._connect()

        try:
            with connection.cursor() as cursor:

                # FK safety:
                # public raid_history must exist
                # before player rows are inserted.
                cursor.execute(
                    """
                    SELECT 1
                    FROM raid_history
                    WHERE raid_id = %s
                    LIMIT 1
                    """,
                    (raid_id,),
                )

                if cursor.fetchone() is None:
                    return {
                        "updated": False,
                        "reason":
                            "raid_history_missing",
                        "players":
                            len(values),
                        "rowcount": 0,
                    }

                cursor.executemany(
                    sql,
                    values,
                )

                rowcount = cursor.rowcount

            connection.commit()

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()

        return {
            "updated": bool(rowcount),
            "reason": None,
            "players": len(values),
            "rowcount": rowcount,
            "source": source,
        }



    # RAID_PROTOCOL14_POINT_DAMAGE_V1
    #
    # 2026-09-22 10:00 maintenance boundary:
    #
    # Legacy:
    #   real observed damage
    #   -> NEVER rewrite
    #
    # Protocol 1.4:
    #   rank[] no longer exposes direct damage
    #   POINT / 100 is used as estimated damage.
    #
    # Keep damage_known=0 because this is derived,
    # not directly observed.
    #
    # Provenance:
    #   damage_source = point_div_100_v1
    #
    def update_protocol14_point_damage(
        self,
        *,
        raid_id: str,
    ) -> dict:

        raid_id = str(
            raid_id or ""
        ).strip()

        if not raid_id:
            return {
                "updated": 0,
                "reason": "empty_raid_id",
            }

        connection = self._connect()

        try:
            with connection.cursor() as cursor:

                cursor.execute(
                    """
                    UPDATE
                        raid_player_history AS p

                    JOIN
                        raid_history AS r
                      ON r.raid_id = p.raid_id

                    SET
                        p.damage =
                            CAST(
                                ROUND(
                                    COALESCE(
                                        p.point,
                                        0
                                    )
                                    / 100.0,
                                    0
                                )
                                AS UNSIGNED
                            ),

                        p.damage_known = 0,

                        p.damage_source =
                            'point_div_100_v1'

                    WHERE
                        p.raid_id = %s

                        AND r.first_seen_at >=
                            '2026-09-22 10:00:00'

                        AND COALESCE(
                            p.rank_source,
                            ''
                        ) =
                            'protocol14_db_raid_rank'
                    """,
                    (
                        raid_id,
                    ),
                )

                updated = int(
                    cursor.rowcount or 0
                )

            connection.commit()

            return {
                "updated": updated,
                "reason": None,
                "source":
                    "point_div_100_v1",
            }

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()


    # RAID_PROTOCOL14_REWARD_CAPTURE_V1
    def update_protocol14_reward_observation(
        self,
        *,
        raid_id: str,
        reward,
        rarity=None,
    ) -> dict:
        """
        Persist raw Protocol 1.4 db_raid reward.

        Direct rarity fills NULL only.
        Existing rarity is never guessed or overwritten here.
        """

        raid_id = str(
            raid_id or ""
        ).strip()

        if not raid_id:
            return {
                "updated": False,
                "reason": "raid_id_missing",
            }

        rarity = (
            self._optional_nonnegative_int(
                rarity
            )
        )

        reward_json = None

        if isinstance(
            reward,
            list,
        ):
            reward_json = json.dumps(
                reward,
                ensure_ascii=False,
                separators=(",", ":"),
                sort_keys=True,
            )

        if (
            reward_json is None
            and rarity is None
        ):
            return {
                "updated": False,
                "reason": "nothing_to_write",
            }

        connection = self._connect()

        try:
            with connection.cursor() as cursor:

                cursor.execute(
                    """
                    UPDATE raid_history
                    SET
                        # RAID_DIRECT_RARITY_OVERRIDES_INFERENCE_V1
                        # RAID_PROTOCOL14_DIRECT_RARITY_PROVENANCE_V2
                        #
                        # A direct Protocol 1.4 db_raid observation is
                        # authoritative.  It may:
                        #
                        #   1. fill a missing rarity;
                        #   2. replace legacy inferred rarity;
                        #   3. upgrade provenance when an existing
                        #      community_api value exactly agrees with
                        #      the direct Protocol 1.4 observation.
                        #
                        # A contradictory community_api value is NOT
                        # overwritten here.
                        rarity =
                            CASE
                                WHEN
                                    %s IS NOT NULL
                                    AND (
                                        rarity IS NULL
                                        OR rarity_source =
                                           'legacy_inferred_mode_v1'
                                    )
                                THEN %s
                                ELSE rarity
                            END,

                        rarity_source =
                            CASE
                                WHEN
                                    %s IS NOT NULL
                                    AND (
                                        rarity_source IS NULL

                                        OR rarity_source =
                                           'legacy_inferred_mode_v1'

                                        OR (
                                            rarity_source =
                                                'community_api'
                                            AND rarity = %s
                                        )
                                    )
                                THEN
                                    'protocol14_db_raid'
                                ELSE
                                    rarity_source
                            END,

                        reward_raw_json =
                            COALESCE(
                                %s,
                                reward_raw_json
                            )

                    WHERE raid_id = %s
                    """,
                    (
                        rarity,
                        rarity,
                        rarity,
                        rarity,
                        reward_json,
                        raid_id,
                    ),
                )

                rowcount = cursor.rowcount

            connection.commit()

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()

        return {
            "updated": bool(rowcount),
            "rowcount": rowcount,
            "rarity": rarity,
            "reward_saved":
                reward_json is not None,
        }


    def update_research_metadata(
        self,
        *,
        raid_id: str,
        metadata: dict,
    ) -> bool:

        raid_id = str(
            raid_id or ""
        ).strip()

        if not raid_id:
            return False

        if not isinstance(metadata, dict):
            return False

        monster_code = metadata.get(
            "profound_mons"
        )

        if not isinstance(
            monster_code,
            str,
        ):
            monster_code = None

        values = (
            monster_code,
            self._optional_nonnegative_int(
                metadata.get("rarity")
            ),
            self._optional_nonnegative_int(
                metadata.get("treasure_level")
            ),
            self._optional_nonnegative_int(
                metadata.get("map")
            ),
            self._optional_nonnegative_int(
                metadata.get("stage")
            ),
            self._optional_nonnegative_int(
                metadata.get("map_index")
            ),
            self._optional_nonnegative_int(
                metadata.get("pos_index")
            ),
            raid_id,
        )

        sql = """
            UPDATE raid_history
            SET
                monster_code =
                    COALESCE(%s, monster_code),

                rarity =
                    COALESCE(%s, rarity),

                treasure_level =
                    COALESCE(%s, treasure_level),

                map_id =
                    COALESCE(%s, map_id),

                stage_id =
                    COALESCE(%s, stage_id),

                map_index =
                    COALESCE(%s, map_index),

                pos_index =
                    COALESCE(%s, pos_index)

            WHERE raid_id = %s
        """

        connection = self._connect()

        try:
            with connection.cursor() as cursor:
                cursor.execute(
                    sql,
                    values,
                )

                changed = (
                    cursor.rowcount > 0
                )

            connection.commit()

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()

        return changed


    # RAID_HISTORY_FRAGMENT_V1
    _FRAGMENT_NAMES = {
        "黃": "記憶",
        "綠": "時間",
        "藍": "靈魂",
        "紅": "生命",
        "紫": "死亡",
    }

    _FRAGMENT_COLOR_NORMALIZE = {
        "🟡": "黃",
        "🟢": "綠",
        "🔵": "藍",
        "🔴": "紅",
        "🟣": "紫",
        "黃": "黃",
        "綠": "綠",
        "藍": "藍",
        "紅": "紅",
        "紫": "紫",
    }

    # RAID_FRAGMENT_STAGE_HISTORY_V2
    _FRAGMENT_SOURCE_PRIORITY = {
        "discord_store_bootstrap": 0,
        "formula": 10,

        # monster_id + map_index -> stage candidate.
        # Stronger than the retired generic formula, but always
        # weaker than an actually observed stage.
        "map_stage_formula_v1": 15,
        "stage_backfill_v1": 15,
        "treasure_reward_backfill_v1": 30,

        "stage_sync_deferred": 20,
        "stage_sync_report": 20,
        "community_api": 30,
    }

    @classmethod
    def _fragment_priority(
        cls,
        source,
    ) -> int:
        source = (
            source.strip()
            if isinstance(source, str)
            else ""
        )

        return cls._FRAGMENT_SOURCE_PRIORITY.get(
            source,
            0,
        )

    # RAID_COORD_PROVENANCE_V1

    _COORD_SOURCE_PRIORITY = {
        "map_index": {
            "stage_to_map_formula_v1": 20,
            "stage_to_map_family_v2": 20,

            "existing_pre_provenance": 60,

            "protocol14_public": 90,

            "collector_journal": 100,
            "protocol14_db_raid": 100,
        },

        "stage_id": {
            "map_stage_formula_v1": 20,
            "stage_backfill_v1": 20,

            "existing_pre_provenance": 60,

            "raid_tl_history": 90,
            "protocol14_public": 90,

            "stage_sync_deferred": 100,
            "stage_sync_report": 100,
            "community_api": 100,
            "protocol14_db_raid": 100,
        },
    }

    _PROTOCOL14_BOSS_CODE = {
        "黑死獸": "mc1003_02",
        "赤死獸": "mc1003_01",
        "瘟疫": "mc1003_03",

        "屠殺者": "mc1006_02",
        "啃食者": "mc1006_01",

        "誘引之者": "mc1007_02",
        "深沉之者": "mc1007_01",

        "靈龜": "mc1008_02",
        "贔屭": "mc1008_01",

        "龍鯰": "mc1012_02",
        "龍魚": "mc1012_01",

        "孤獨的女王": "mc1005",
    }

    _PROTOCOL14_STAGE_START_BY_CODE = {
        "mc1003_01": 1,
        "mc1003_02": 1,

        "mc1006_01": 6,
        "mc1006_02": 6,

        "mc1007_01": 11,
        "mc1007_02": 11,

        "mc1008_01": 5,
        "mc1008_02": 5,

        "mc1012_01": 5,
        "mc1012_02": 5,
    }

    _PROTOCOL14_LEVEL1_CODES = (
        set(_PROTOCOL14_STAGE_START_BY_CODE)
        | {
            "mc1003_03",
            "mc1005",
        }
    )

    _PROTOCOL14_TIER_BY_LIMIT = {
        80: "涡I",
        100: "涡II/III",
        120: "涡IV",
    }

    @classmethod
    def _coordinate_source_priority(
        cls,
        field,
        source,
    ) -> int:

        source = (
            source.strip()
            if isinstance(source, str)
            else ""
        )

        if not source:
            return 60

        return (
            cls._COORD_SOURCE_PRIORITY
            .get(field, {})
            .get(source, 50)
        )

    def update_coordinate_observation(
        self,
        *,
        raid_id,
        field,
        value,
        source,
    ) -> dict:

        config = {
            "map_index": (
                "map_index_source",
                "map_index_conflict",
            ),
            "stage_id": (
                "stage_id_source",
                "stage_id_conflict",
            ),
        }

        if field not in config:
            return {
                "updated": False,
                "reason": "invalid_field",
            }

        raid_id = str(
            raid_id or ""
        ).strip()

        value = self._optional_nonnegative_int(
            value
        )

        source = (
            source.strip()
            if isinstance(source, str)
            else ""
        )

        if (
            not raid_id
            or value is None
            or not source
        ):
            return {
                "updated": False,
                "reason": "invalid_input",
            }

        source_col, conflict_col = config[field]

        connection = self._connect()

        try:
            with connection.cursor(
                pymysql.cursors.DictCursor
            ) as cursor:

                cursor.execute(
                    f"""
                    SELECT
                        `{field}`,
                        `{source_col}`,
                        `{conflict_col}`
                    FROM raid_history
                    WHERE raid_id = %s
                    FOR UPDATE
                    """,
                    (raid_id,),
                )

                row = cursor.fetchone()

                if row is None:
                    connection.rollback()

                    return {
                        "updated": False,
                        "reason": "raid_not_found",
                    }

                old_value = (
                    self._optional_nonnegative_int(
                        row.get(field)
                    )
                )

                old_source = (
                    row.get(source_col)
                    if isinstance(
                        row.get(source_col),
                        str,
                    )
                    else ""
                )

                conflict = int(
                    row.get(conflict_col)
                    or 0
                )

                old_priority = (
                    self._coordinate_source_priority(
                        field,
                        old_source,
                    )
                )

                new_priority = (
                    self._coordinate_source_priority(
                        field,
                        source,
                    )
                )

                replace_value = False
                replace_source = False

                if old_value is None:

                    replace_value = True
                    replace_source = True
                    decision = "insert"

                elif old_value == value:

                    if not old_source:

                        if new_priority >= 90:
                            replace_source = True
                            decision = (
                                "source_upgrade_direct"
                            )
                        else:
                            source = (
                                "existing_pre_provenance"
                            )
                            replace_source = True
                            decision = (
                                "source_mark_existing"
                            )

                    elif new_priority > old_priority:

                        replace_source = True
                        decision = "source_upgrade"

                    else:
                        decision = "confirmed"

                else:

                    conflict = 1

                    if new_priority > old_priority:

                        replace_value = True
                        replace_source = True
                        decision = (
                            "replace_higher_priority"
                        )

                    else:

                        decision = (
                            "conflict_keep_existing"
                        )

                if replace_value:

                    cursor.execute(
                        f"""
                        UPDATE raid_history
                        SET
                            `{field}` = %s,
                            `{source_col}` = %s,
                            `{conflict_col}` = %s
                        WHERE raid_id = %s
                        """,
                        (
                            value,
                            source,
                            conflict,
                            raid_id,
                        ),
                    )

                elif replace_source:

                    cursor.execute(
                        f"""
                        UPDATE raid_history
                        SET
                            `{source_col}` = %s,
                            `{conflict_col}` = %s
                        WHERE raid_id = %s
                        """,
                        (
                            source,
                            conflict,
                            raid_id,
                        ),
                    )

                else:

                    cursor.execute(
                        f"""
                        UPDATE raid_history
                        SET
                            `{conflict_col}` = %s
                        WHERE raid_id = %s
                        """,
                        (
                            conflict,
                            raid_id,
                        ),
                    )

                changed = (
                    cursor.rowcount > 0
                )

            connection.commit()

            return {
                "updated": changed,
                "decision": decision,
                "field": field,
                "value": value,
                "source": source,
                "conflict": bool(conflict),
            }

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()

    def update_protocol14_coordinates(
        self,
        *,
        raid_id,
        metadata,
    ) -> dict:

        if not isinstance(metadata, dict):
            return {
                "updated": False,
                "reason": "invalid_metadata",
            }

        results = {}

        map_index = (
            self._optional_nonnegative_int(
                metadata.get("map_index")
            )
        )

        stage_id = (
            self._optional_nonnegative_int(
                metadata.get("stage")
            )
        )

        if map_index is not None:

            results["map_index"] = (
                self.update_coordinate_observation(
                    raid_id=raid_id,
                    field="map_index",
                    value=map_index,
                    source="protocol14_db_raid",
                )
            )

        if stage_id is not None:

            results["stage_id"] = (
                self.update_coordinate_observation(
                    raid_id=raid_id,
                    field="stage_id",
                    value=stage_id,
                    source="protocol14_db_raid",
                )
            )

        return {
            "updated": any(
                isinstance(x, dict)
                and x.get("updated")
                for x in results.values()
            ),
            "results": results,
        }

    @classmethod
    def _protocol14_map_from_stage(
        cls,
        monster_code,
        stage_id,
    ):

        start = (
            cls
            ._PROTOCOL14_STAGE_START_BY_CODE
            .get(monster_code)
        )

        stage_id = (
            cls._optional_nonnegative_int(
                stage_id
            )
        )

        if (
            start is None
            or stage_id
            not in (1, 2, 3, 4, 5)
        ):
            return None

        return (
            (start - 1 + stage_id - 1)
            % 11
        ) + 1

    @classmethod
    def _protocol14_stage_from_map(
        cls,
        monster_code,
        map_index,
    ):

        start = (
            cls
            ._PROTOCOL14_STAGE_START_BY_CODE
            .get(monster_code)
        )

        map_index = (
            cls._optional_nonnegative_int(
                map_index
            )
        )

        if (
            start is None
            or map_index is None
            or not 1 <= map_index <= 11
        ):
            return None

        stage_id = (
            (map_index - start)
            % 11
        ) + 1

        return (
            stage_id
            if stage_id <= 5
            else None
        )

    def reconcile_protocol14_derived_metadata(
        self,
        *,
        raid_id,
    ) -> dict:

        raid_id = str(
            raid_id or ""
        ).strip()

        if not raid_id:
            return {
                "updated": False,
                "reason": "invalid_raid_id",
            }

        connection = self._connect()

        try:
            with connection.cursor(
                pymysql.cursors.DictCursor
            ) as cursor:

                cursor.execute(
                    """
                    SELECT
                        boss,
                        monster_code,
                        raid_level,
                        member_limit,
                        whirlpool_tier,
                        rarity,
                        stage_id,
                        map_index,
                        fragment_name,
                        fragment_status
                    FROM raid_history
                    WHERE raid_id = %s
                    """,
                    (raid_id,),
                )

                row = cursor.fetchone()

                if row is None:
                    return {
                        "updated": False,
                        "reason": "raid_not_found",
                    }

                monster_code = (
                    row.get("monster_code")
                    or self._PROTOCOL14_BOSS_CODE.get(
                        row.get("boss")
                    )
                )

                raid_level = (
                    self._optional_nonnegative_int(
                        row.get("raid_level")
                    )
                )

                if (
                    raid_level is None
                    and monster_code
                    in self._PROTOCOL14_LEVEL1_CODES
                ):
                    raid_level = 1

                member_limit = (
                    self._optional_nonnegative_int(
                        row.get("member_limit")
                    )
                )

                tier = (
                    self
                    ._PROTOCOL14_TIER_BY_LIMIT
                    .get(member_limit)
                )

                cursor.execute(
                    """
                    UPDATE raid_history
                    SET
                        monster_code =
                            COALESCE(
                                monster_code,
                                %s
                            ),

                        raid_level =
                            COALESCE(
                                raid_level,
                                %s
                            ),

                        whirlpool_tier =
                            COALESCE(
                                whirlpool_tier,
                                %s
                            ),

                        fragment_status =
                            CASE
                                WHEN fragment_name
                                     IS NOT NULL
                                THEN 'known'

                                WHEN fragment_status
                                     IS NULL
                                THEN 'unknown'

                                ELSE fragment_status
                            END

                    WHERE raid_id = %s
                    """,
                    (
                        monster_code,
                        raid_level,
                        tier,
                        raid_id,
                    ),
                )

                identity_changed = (
                    cursor.rowcount > 0
                )

            connection.commit()

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()

        stage_id = (
            self._optional_nonnegative_int(
                row.get("stage_id")
            )
        )

        map_index = (
            self._optional_nonnegative_int(
                row.get("map_index")
            )
        )

        outcomes = []

        if (
            raid_level == 1
            and monster_code
            in self._PROTOCOL14_STAGE_START_BY_CODE
        ):

            if stage_id is not None:

                expected_map = (
                    self._protocol14_map_from_stage(
                        monster_code,
                        stage_id,
                    )
                )

                if expected_map is not None:

                    result = (
                        self.update_coordinate_observation(
                            raid_id=raid_id,
                            field="map_index",
                            value=expected_map,
                            source=(
                                "stage_to_map_family_v2"
                            ),
                        )
                    )

                    outcomes.append(result)

                    if map_index is None:
                        map_index = expected_map

            if map_index is not None:

                expected_stage = (
                    self._protocol14_stage_from_map(
                        monster_code,
                        map_index,
                    )
                )

                if expected_stage is not None:

                    result = (
                        self.update_coordinate_observation(
                            raid_id=raid_id,
                            field="stage_id",
                            value=expected_stage,
                            source=(
                                "map_stage_formula_v1"
                            ),
                        )
                    )

                    outcomes.append(result)

                    if stage_id is None:
                        stage_id = expected_stage

        return {
            "updated": (
                identity_changed
                or any(
                    isinstance(x, dict)
                    and x.get("updated")
                    for x in outcomes
                )
            ),

            "identity_changed":
                identity_changed,

            "monster_code":
                monster_code,

            "raid_level":
                raid_level,

            "rarity":
                self._optional_nonnegative_int(
                    row.get("rarity")
                ),

            "stage_id":
                stage_id,

            "map_index":
                map_index,

            "outcomes":
                outcomes,
        }


    # RAID_FRAGMENT_STAGE_HISTORY_V2
    # RAID_PROTOCOL14_MAP_STAGE_FRAGMENT_V1
    def reconcile_protocol14_fragment_from_map_stage(
        self,
        *,
        raid_id: str,
    ) -> dict:
        """Derive Lv1 fragment only from trusted Protocol 1.4 map-stage data."""

        raid_id = str(
            raid_id or ""
        ).strip()

        if not raid_id:
            return {
                "updated": False,
                "reason": "invalid_raid_id",
            }

        connection = self._connect()

        try:
            with connection.cursor(
                pymysql.cursors.DictCursor
            ) as cursor:

                cursor.execute(
                    """
                    SELECT
                        raid_level,
                        rarity,
                        rarity_source,

                        map_index,
                        map_index_source,
                        map_index_conflict,

                        stage_id,
                        stage_id_source,
                        stage_id_conflict,

                        fragment_color,
                        fragment_source,
                        fragment_conflict

                    FROM raid_history
                    WHERE raid_id = %s
                    LIMIT 1
                    """,
                    (raid_id,),
                )

                row = cursor.fetchone()

        finally:
            connection.close()

        if row is None:
            return {
                "updated": False,
                "reason": "raid_not_found",
            }

        raid_level = self._optional_nonnegative_int(
            row.get("raid_level")
        )

        rarity = self._optional_nonnegative_int(
            row.get("rarity")
        )

        stage_id = self._optional_nonnegative_int(
            row.get("stage_id")
        )

        if raid_level != 1:
            return {
                "updated": False,
                "reason": "unsupported_level",
            }

        if rarity not in (1, 6):
            return {
                "updated": False,
                "reason": "unsupported_rarity",
            }

        if row.get("rarity_source") != "protocol14_db_raid":
            return {
                "updated": False,
                "reason": "rarity_not_direct_protocol14",
            }

        if row.get("map_index_source") != "protocol14_db_raid":
            return {
                "updated": False,
                "reason": "map_index_not_direct_protocol14",
            }

        if row.get("stage_id_source") != "map_stage_formula_v1":
            return {
                "updated": False,
                "reason": "stage_not_map_formula",
            }

        if int(
            row.get("map_index_conflict") or 0
        ) != 0:
            return {
                "updated": False,
                "reason": "map_index_conflict",
            }

        if int(
            row.get("stage_id_conflict") or 0
        ) != 0:
            return {
                "updated": False,
                "reason": "stage_conflict",
            }

        if stage_id not in (1, 2, 3, 4, 5):
            return {
                "updated": False,
                "reason": "invalid_stage",
            }

        # Lv1 normal ★1:
        # 1 記憶 / 2 時間 / 3 靈魂 / 4 生命 / 5 死亡
        normal = {
            1: "黃",
            2: "綠",
            3: "藍",
            4: "紅",
            5: "紫",
        }

        # Lv1 ★6 rotation:
        # 1 時間 / 2 靈魂 / 3 生命 / 4 死亡 / 5 記憶
        six_star = {
            1: "綠",
            2: "藍",
            3: "紅",
            4: "紫",
            5: "黃",
        }

        fragment = (
            normal[stage_id]
            if rarity == 1
            else six_star[stage_id]
        )

        outcome = self.update_fragment_observation(
            raid_id=raid_id,
            fragment=fragment,
            source="map_stage_formula_v1",
        )

        return {
            **outcome,
            "raid_level": raid_level,
            "rarity": rarity,
            "stage_id": stage_id,
            "fragment": fragment,
            "source": "map_stage_formula_v1",
        }


    # RAID_CANONICAL_FRAGMENT_READ_V1
    def fetch_canonical_fragment_metadata(
        self,
        raid_ids,
    ) -> dict[str, dict]:
        """Return canonical fragment metadata for active Raid IDs.

        Conflicting fragments are deliberately excluded from
        website/Discord publication.
        """

        raid_ids = tuple(
            dict.fromkeys(
                str(value).strip()
                for value in raid_ids
                if str(value).strip()
            )
        )

        if not raid_ids:
            return {}

        placeholders = ",".join(
            ["%s"] * len(raid_ids)
        )

        sql = f"""
            SELECT
                raid_id,
                stage_id,
                rarity,
                fragment_color,
                fragment_source,
                fragment_conflict
            FROM raid_history
            WHERE raid_id IN ({placeholders})
              AND fragment_color IS NOT NULL
              AND COALESCE(
                    fragment_conflict,
                    0
                  ) = 0
        """

        connection = self._connect()

        try:
            with connection.cursor(
                pymysql.cursors.DictCursor
            ) as cursor:

                cursor.execute(
                    sql,
                    raid_ids,
                )

                rows = cursor.fetchall()

            return {
                str(row["raid_id"]): row
                for row in rows
                if row.get("raid_id")
            }

        finally:
            connection.close()


    def update_stage_observation(
        self,
        *,
        raid_id: str,
        stage_id=None,
        rarity=None,
        source=None,
    ) -> bool:
        """Persist observed stage/rarity with provenance."""

        raid_id = str(
            raid_id or ""
        ).strip()

        if not raid_id:
            return False

        stage_id = (
            self._optional_nonnegative_int(
                stage_id
            )
        )

        rarity = (
            self._optional_nonnegative_int(
                rarity
            )
        )

        source = (
            source.strip()
            if isinstance(source, str)
            and source.strip()
            else "existing_pre_provenance"
        )

        if (
            stage_id is None
            and rarity is None
        ):
            return False

        stage_changed = False

        if stage_id is not None:

            outcome = (
                self.update_coordinate_observation(
                    raid_id=raid_id,
                    field="stage_id",
                    value=stage_id,
                    source=source,
                )
            )

            stage_changed = bool(
                outcome.get("updated")
            )

        rarity_changed = False

        if rarity is not None:

            connection = self._connect()

            try:
                with connection.cursor() as cursor:

                    # RAID_STAGE_RARITY_PROVENANCE_V2
                    cursor.execute(
                        """
                        UPDATE raid_history
                        SET
                            rarity =
                                CASE
                                    WHEN rarity IS NULL
                                    THEN %s

                                    WHEN rarity_source =
                                         'legacy_inferred_mode_v1'
                                    THEN %s

                                    ELSE rarity
                                END,

                            rarity_source =
                                CASE
                                    WHEN rarity IS NULL
                                    THEN %s

                                    WHEN rarity_source =
                                         'legacy_inferred_mode_v1'
                                    THEN %s

                                    WHEN
                                        rarity_source IS NULL
                                        AND rarity = %s
                                    THEN %s

                                    ELSE rarity_source
                                END

                        WHERE raid_id = %s
                        """,
                        (
                            rarity,
                            rarity,
                            source,
                            source,
                            rarity,
                            source,
                            raid_id,
                        ),
                    )

                    rarity_changed = (
                        cursor.rowcount > 0
                    )

                connection.commit()

            except Exception:
                connection.rollback()
                raise

            finally:
                connection.close()

        return (
            stage_changed
            or rarity_changed
        )


    def update_fragment_observation(
        self,
        *,
        raid_id: str,
        fragment: str,
        source: str,
    ) -> dict:
        """Update canonical Raid fragment with source priority.

        A conflicting observed color permanently sets
        fragment_conflict=1.

        Lower-confidence sources never overwrite a
        higher-confidence canonical fragment.
        """

        raid_id = str(
            raid_id or ""
        ).strip()

        fragment = (
            fragment.strip()
            if isinstance(fragment, str)
            else ""
        )

        fragment = self._FRAGMENT_COLOR_NORMALIZE.get(
            fragment,
            fragment,
        )

        source = (
            source.strip()
            if isinstance(source, str)
            else ""
        )

        fragment_name = self._FRAGMENT_NAMES.get(
            fragment
        )

        if (
            not raid_id
            or fragment_name is None
            or not source
        ):
            return {
                "updated": False,
                "reason": "invalid_input",
            }

        connection = self._connect()

        try:
            with connection.cursor(
                pymysql.cursors.DictCursor
            ) as cursor:

                cursor.execute(
                    """
                    SELECT
                        fragment_color,
                        fragment_name,
                        fragment_source,
                        fragment_conflict
                    FROM raid_history
                    WHERE raid_id = %s
                    FOR UPDATE
                    """,
                    (raid_id,),
                )

                current = cursor.fetchone()

                if current is None:
                    connection.rollback()

                    return {
                        "updated": False,
                        "reason": "raid_not_found",
                    }

                old_fragment = (
                    current.get("fragment_color")
                )

                old_source = (
                    current.get("fragment_source")
                )

                conflict = int(
                    current.get(
                        "fragment_conflict"
                    )
                    or 0
                )

                old_priority = (
                    self._fragment_priority(
                        old_source
                    )
                )

                new_priority = (
                    self._fragment_priority(
                        source
                    )
                )

                # RAID_FRAGMENT_STAGE_HISTORY_V2
                #
                # Only a disagreement between trusted observed
                # sources is a canonical conflict.
                #
                # bootstrap / formula / map formula must never
                # poison a trusted fragment with conflict=1.
                if (
                    isinstance(old_fragment, str)
                    and old_fragment
                    and old_fragment != fragment
                    and old_priority >= 20
                    and new_priority >= 20
                ):
                    conflict = 1

                should_replace = (
                    not old_fragment
                    or not old_source
                    or new_priority > old_priority
                    or (
                        new_priority == old_priority
                        and old_fragment == fragment
                    )
                )

                if should_replace:
                    cursor.execute(
                        """
                        UPDATE raid_history
                        SET
                            fragment_color = %s,
                            fragment_name = %s,
                            fragment_source = %s,
                            fragment_conflict = %s,
                              fragment_status = 'known'
                        WHERE raid_id = %s
                        """,
                        (
                            fragment,
                            fragment_name,
                            source,
                            conflict,
                            raid_id,
                        ),
                    )

                    decision = "replace"

                else:
                    cursor.execute(
                        """
                        UPDATE raid_history
                        SET
                            fragment_conflict = %s,
                              fragment_status = 'known'
                        WHERE raid_id = %s
                        """,
                        (
                            conflict,
                            raid_id,
                        ),
                    )

                    decision = "keep_existing"

            connection.commit()

            return {
                "updated": True,
                "decision": decision,
                "fragment": fragment,
                "source": source,
                "conflict": bool(conflict),
            }

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()


    def upsert_snapshot(self, raid: RaidEntry) -> None:
        raid_id = str(raid.profound_id).strip()

        if not raid_id:
            raise ValueError("Raid history requires profound_id")

        now = datetime.now()

        players = [
            player
            for player in raid.players
            if isinstance(player.name, str)
            and player.name.strip()
        ]

        raid_sql = """
            INSERT INTO raid_history (
                raid_id,
                founder,
                boss,
                monster_code,
                rarity,
                whirlpool_tier,
                treasure_level,
                hp_max,
                hp_first_seen,
                member_limit,
                participant_count,
                expires_at_ms,
                first_seen_at,
                last_seen_at
            )
            VALUES (
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s
            )
            ON DUPLICATE KEY UPDATE
                founder = COALESCE(VALUES(founder), founder),
                boss = COALESCE(VALUES(boss), boss),
                monster_code =
                    COALESCE(VALUES(monster_code), monster_code),
                rarity =
                    COALESCE(VALUES(rarity), rarity),
                whirlpool_tier =
                    COALESCE(
                        VALUES(whirlpool_tier),
                        whirlpool_tier
                    ),
                treasure_level =
                    COALESCE(VALUES(treasure_level), treasure_level),
                hp_max =
                    COALESCE(VALUES(hp_max), hp_max),
                member_limit =
                    COALESCE(VALUES(member_limit), member_limit),

                participant_count =
                    GREATEST(
                        participant_count,
                        VALUES(participant_count)
                    ),

                expires_at_ms =
                    COALESCE(
                        VALUES(expires_at_ms),
                        expires_at_ms
                    ),

                last_seen_at = VALUES(last_seen_at)
        """

        player_sql = """
            # RAID_LEGACY_PLAYER_KNOWN_DAMAGE_V1
            INSERT INTO raid_player_history (
                raid_id,
                player_name,
                rank_no,
                point,
                damage,
                damage_known,
                damage_source,
                rank_source,
                first_seen_at,
                last_seen_at
            )
            VALUES (
                %s, %s, %s, %s, %s,
                1,
                'legacy_raid_players',
                'legacy_raid_players',
                %s, %s
            )
            ON DUPLICATE KEY UPDATE
                rank_no = VALUES(rank_no),
                point = VALUES(point),
                damage = VALUES(damage),
                damage_known = 1,
                damage_source =
                    'legacy_raid_players',
                rank_source =
                    'legacy_raid_players',
                last_seen_at = VALUES(last_seen_at)
        """


        # DAMAGE_TIMELINE_RECORDER_V1
        # OBSERVATION_WINDOW_RECORDER_V2
        #
        # Persist the observed player damage timeline BEFORE
        # raid_player_history is overwritten by the new snapshot.
        #
        # baseline:
        #   first observation of Raid + player.
        #
        # damage_change:
        #   current damage > previous stored damage.
        #
        # A decreasing damage value is treated as a data reset /
        # inconsistency and is intentionally NOT recorded here.

        damage_event_sql = """
            INSERT IGNORE INTO raid_player_damage_events (
                raid_id,
                player_name,
                observed_at,
                previous_observed_at,
                window_seconds,
                event_kind,
                damage_before,
                damage_after,
                damage_delta,
                point_before,
                point_after,
                point_delta,
                source,
                confidence,
                source_event_count
            )
            VALUES (
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s,
                %s, %s, %s, %s, %s
            )
        """

        raid_values = (
            raid_id,
            raid.founder,
            raid.boss_name,
            raid.monster_code,
            self._optional_nonnegative_int(
                raid.rarity
            ),
            (
                raid.whirlpool_tier.strip()
                if isinstance(raid.whirlpool_tier, str)
                and raid.whirlpool_tier.strip()
                else None
            ),
            self._optional_nonnegative_int(
                raid.treasure_level
            ),
            self._optional_nonnegative_int(
                raid.hp_max
            ),

            # RAID_HP_FIRST_SEEN_V1
            # Save the HP from the first public snapshot only.
            # ON DUPLICATE KEY UPDATE intentionally does not
            # update hp_first_seen.
            self._optional_nonnegative_int(
                raid.hp
            ),

            self._optional_nonnegative_int(
                raid.member_limit
            ),
            self._nonnegative_int(
                raid.participant_count
            ),
            self._optional_nonnegative_int(
                raid.expires_at
            ),
            now,
            now,
        )

        player_values = [
            (
                raid_id,
                player.name.strip(),
                self._optional_nonnegative_int(
                    player.rank
                ),
                self._nonnegative_int(
                    player.point
                ),
                self._nonnegative_int(
                    player.damage
                ),
                now,
                now,
            )
            for player in players
        ]

        # RAID_PUBLIC_BASELINE_SCOPE_FIX_V3
        #
        # Player damage is cumulative. Capture how much
        # damage the founder had already dealt when UL.GG
        # first observed this public Raid.
        founder_name = (
            raid.founder.strip()
            if isinstance(raid.founder, str)
            else ""
        )

        founder_damage_first_seen = None

        if founder_name:
            for player in (
                getattr(raid, "players", None)
                or ()
            ):
                player_name = (
                    player.name.strip()
                    if isinstance(
                        getattr(player, "name", None),
                        str,
                    )
                    else ""
                )

                if player_name != founder_name:
                    continue

                founder_damage_first_seen = (
                    self._optional_nonnegative_int(
                        getattr(
                            player,
                            "damage",
                            None,
                        )
                    )
                )

                break


        connection = self._connect()

        try:
            with connection.cursor(
                pymysql.cursors.DictCursor
            ) as cursor:

                # DAMAGE_TIMELINE_RECORDER_V1
                #
                # Read the PREVIOUS persisted snapshot first.
                cursor.execute(
                    """
                    SELECT
                        player_name,
                        point,
                        damage,
                        last_seen_at
                    FROM raid_player_history
                    WHERE raid_id = %s
                    """,
                    (raid_id,),
                )

                previous_rows = cursor.fetchall()

                previous_players = {
                    str(row["player_name"]): row
                    for row in previous_rows
                    if row.get("player_name") is not None
                }

                damage_event_values = []

                for player in players:

                    player_name = player.name.strip()

                    current_damage = self._nonnegative_int(
                        player.damage
                    )

                    current_point = self._nonnegative_int(
                        player.point
                    )

                    previous = previous_players.get(
                        player_name
                    )

                    # ----------------------------------------
                    # First observation:
                    #
                    # Save a baseline but DO NOT pretend
                    # existing damage happened during a known
                    # interval.
                    # ----------------------------------------
                    if previous is None:

                        damage_event_values.append(
                            (
                                raid_id,
                                player_name,
                                now,

                                # OBSERVATION_WINDOW_RECORDER_V2
                                None,
                                None,

                                "baseline",

                                None,
                                current_damage,
                                0,

                                None,
                                current_point,
                                None,

                                "live_snapshot",
                                1.0000,
                                1,
                            )
                        )

                        continue


                    previous_damage = self._nonnegative_int(
                        previous.get("damage")
                    )

                    previous_point = self._nonnegative_int(
                        previous.get("point")
                    )


                    # ----------------------------------------
                    # Only positive observed damage changes.
                    # ----------------------------------------
                    if current_damage <= previous_damage:
                        continue


                    damage_delta = (
                        current_damage
                        - previous_damage
                    )

                    point_delta = max(
                        0,
                        current_point
                        - previous_point
                    )


                    # OBSERVATION_WINDOW_RECORDER_V2
                    #
                    # previous.last_seen_at is the immediately
                    # preceding snapshot for this Raid+player,
                    # because raid_player_history has not yet
                    # been overwritten by the current snapshot.
                    previous_observed_at = previous.get(
                        "last_seen_at"
                    )

                    window_seconds = None

                    if isinstance(
                        previous_observed_at,
                        datetime,
                    ):
                        observed_window = (
                            now
                            - previous_observed_at
                        ).total_seconds()

                        if observed_window > 0:
                            window_seconds = round(
                                observed_window,
                                3,
                            )
                        else:
                            # Do not manufacture an invalid
                            # negative/zero observation window.
                            previous_observed_at = None

                    else:
                        previous_observed_at = None


                    damage_event_values.append(
                        (
                            raid_id,
                            player_name,
                            now,

                            previous_observed_at,
                            window_seconds,

                            "damage_change",

                            previous_damage,
                            current_damage,
                            damage_delta,

                            previous_point,
                            current_point,
                            point_delta,

                            "live_snapshot",
                            1.0000,
                            1,
                        )
                    )


                # raid_history must exist before normal player
                # snapshot persistence.
                cursor.execute(
                    raid_sql,
                    raid_values,
                )

                # RAID_ENDED_REOPEN_ON_VISIBLE_V1
                #
                # ended = public observation ended, NOT terminal death.
                # If the same Raid becomes visible again, reopen it.
                cursor.execute(
                    """
                    UPDATE raid_history
                    SET
                        lifecycle_status = NULL,
                        ended_at = NULL,
                        defeat_method = NULL
                    WHERE raid_id = %s
                      AND lifecycle_status = 'ended'
                    """,
                    (raid_id,),
                )

                # RAID_PUBLIC_BASELINE_WRITE_V2
                #
                # Fresh INSERT has:
                # first_seen_at == last_seen_at.
                #
                # Once captured, COALESCE guarantees the
                # public baseline can never be overwritten.
                cursor.execute(
                    """
                    UPDATE raid_history
                    SET
                        hp_first_seen =
                            COALESCE(
                                hp_first_seen,
                                %s
                            ),

                        founder_damage_first_seen =
                            COALESCE(
                                founder_damage_first_seen,
                                %s
                            )

                    WHERE
                        raid_id = %s
                        AND first_seen_at = last_seen_at
                    """,
                    (
                        self._optional_nonnegative_int(
                            raid.hp
                        ),
                        founder_damage_first_seen,
                        raid_id,
                    ),
                )


                # RAID_ACTIVITY_TIMELINE_V3_ROLLBACK_FIX
                # Activity Timeline V3 removed: it referenced previous_player_map inside upsert_snapshot where that variable does not exist.
                # Keep Damage Timeline V1 + Observation Window V2 unchanged.

                if damage_event_values:
                    cursor.executemany(
                        damage_event_sql,
                        damage_event_values,
                    )


                if player_values:
                    cursor.executemany(
                        player_sql,
                        player_values,
                    )

            connection.commit()

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()


    def record_support_events(
        self,
        *,
        raid: RaidEntry,
        previous_statuses,
        previous_players,
        observed_at: datetime | None = None,
    ) -> int:
        """Persist inferred support/debuff attribution events."""

        # SUPPORT_RECORD_ALL_PARSED_STATUSES_V1
        #
        # This dictionary controls attribution weighting only.
        # It is intentionally NOT a recorder whitelist.
        # Any parsed status absent here uses neutral weight 1.0.
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

                # SUPPORT_RECORD_ALL_PARSED_STATUSES_V1
                #
                # Data layer must keep every parsed RaidStatus.
                # tracked_status_weights is a weighting table,
                # NOT a status whitelist.
                if not base:
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

        # RAID_SUPPORT_REMOVED_EVENT_V1
        #
        # Record a status disappearance as "removed" only when
        # it vanished clearly BEFORE its known expiration time.
        #
        # This avoids treating normal expiration as a cleanse.
        #
        # One cleanse skill may remove several statuses:
        # each status receives one event row, while downstream
        # analytics should group raid_id + observed_at into
        # one cleanse action bundle.
        #
        # curse has no expires_at. It is included only when the
        # same snapshot also contains at least one confirmed
        # early timed-status removal.
        observed_epoch_ms = int(
            observed_at.timestamp()
            * 1000
        )

        early_remove_margin_ms = 10_000

        disappeared_by_base = {
            base: previous
            for base, previous
            in previous_by_base.items()
            if base not in current_by_base
        }

        early_removed_bases = {
            base
            for base, previous
            in disappeared_by_base.items()
            if (
                previous.expires_at
                is not None
                and
                previous.expires_at
                >
                observed_epoch_ms
                + early_remove_margin_ms
            )
        }

        if early_removed_bases:

            removal_bases = set(
                early_removed_bases
            )

            # A non-timed status such as curse cannot prove
            # cleanse by itself.
            #
            # But when it disappears in the SAME snapshot as
            # a confirmed early timed removal, include it as
            # part of that cleanse bundle.


            for base, previous in disappeared_by_base.items():

                if previous.expires_at is None:
                    removal_bases.add(
                        base
                    )

            for base in sorted(
                removal_bases
            ):

                previous = disappeared_by_base[
                    base
                ]

                support_changes.append(
                    (
                        base,
                        None,
                        previous,
                        "removed",
                    )
                )

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

            point_before = (
                self._nonnegative_int(previous_player.point)
                if previous_player is not None
                else 0
            )
            point_after = self._nonnegative_int(current_player.point)
            point_delta = max(0, point_after - point_before)

            is_new_player = previous_player is None

            # V0.2: observable activity evidence only.
            # This is NOT proof that the player cast the skill.
            if not (
                is_new_player
                or point_delta > 0
                or damage_delta > 0
            ):
                continue

            candidates.append(
                {
                    "player_name": name,
                    "damage_before": damage_before,
                    "damage_after": damage_after,
                    "damage_delta": damage_delta,
                    "point_before": point_before,
                    "point_after": point_after,
                    "point_delta": point_delta,
                    "is_new_player": is_new_player,
                }
            )

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
                        old_expires_at,
                        new_expires_at,
                        candidate_count
                    )
                    VALUES (
                        %s, %s, %s, %s, %s,
                        %s, %s, %s, %s, %s,
                        %s, %s
                    )
                """

                candidate_sql = """
                    INSERT INTO raid_support_candidates (
                        event_id,
                        player_name,
                        damage_before,
                        damage_after,
                        damage_delta,
                        point_before,
                        point_after,
                        point_delta,
                        is_new_player,
                        attribution_weight
                    )
                    VALUES (
                        %s, %s, %s, %s, %s,
                        %s, %s, %s, %s, %s
                    )
                """

                inserted_events = 0

                for base, current, previous, event_type in support_changes:
                    cursor.execute(
                        event_sql,
                        (
                            str(raid.profound_id).strip(),
                            observed_at,
                            base,
                            str((current.raw_type if current is not None else previous.raw_type) or base),
                            event_type,
                            (
                                previous.level
                                if previous is not None
                                else None
                            ),
                            (current.level if current is not None else None),
                            (
                                previous.value
                                if previous is not None
                                else None
                            ),
                            (current.value if current is not None else None),
                            (
                                previous.expires_at
                                if previous is not None
                                else None
                            ),
                            (current.expires_at if current is not None else None),
                            candidate_count,
                        ),
                    )

                    event_id = cursor.lastrowid
                    inserted_events += 1

                    if confidence <= 0:
                        continue

                    status_weight = tracked_status_weights.get(
                        base,
                        1.0,
                    )

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
                                candidate["point_before"],
                                candidate["point_after"],
                                candidate["point_delta"],
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




    # BOSS_AVG_DEFEAT_TIME_REPOSITORY_V1
    # BOSS_DEFEAT_ONLY_PERSISTENCE_V2
    # BOSS_INFERRED_DEFEAT_V3_REPOSITORY
    # RAID_DAMAGE_DEFEAT_EVIDENCE_V4
    def fetch_raid_damage_evidence(
        self,
        raid_id: str,
    ) -> dict:
        """Return DB evidence usable for conservative defeat verification."""

        raid_id = str(
            raid_id
        ).strip()

        if not raid_id:
            return {}

        connection = self._connect()

        try:

            with connection.cursor(
                pymysql.cursors.DictCursor
            ) as cursor:

                cursor.execute(
                    """
                    SELECT
                        r.raid_id,
                        r.hp_max,
                        r.lifecycle_status,
                        r.defeat_method,
                        r.last_seen_at,

                        COALESCE(
                            SUM(p.damage),
                            0
                        ) AS total_damage

                    FROM raid_history AS r

                    LEFT JOIN raid_player_history AS p
                        ON p.raid_id = r.raid_id

                    WHERE
                        r.raid_id = %s

                    GROUP BY
                        r.raid_id,
                        r.hp_max,
                        r.lifecycle_status,
                        r.defeat_method,
                        r.last_seen_at
                    """,
                    (
                        raid_id,
                    ),
                )

                row = (
                    cursor.fetchone()
                    or {}
                )

        finally:

            connection.close()

        if not row:
            return {}

        try:
            hp_max = int(
                row.get(
                    "hp_max"
                )
                or 0
            )
        except (
            TypeError,
            ValueError,
        ):
            hp_max = 0

        try:
            total_damage = int(
                row.get(
                    "total_damage"
                )
                or 0
            )
        except (
            TypeError,
            ValueError,
        ):
            total_damage = 0

        return {
            "raid_id": raid_id,
            "hp_max": hp_max,
            "total_damage": total_damage,
            "lifecycle_status": row.get(
                "lifecycle_status"
            ),
            "defeat_method": row.get(
                "defeat_method"
            ),
            "last_seen_at": row.get(
                "last_seen_at"
            ),
            "defeat_confirmed": (
                hp_max > 0
                and total_damage >= hp_max
            ),
        }


    # RAID_ENDED_PERSISTENCE_V1
    def mark_lifecycle_ended(
        self,
        *,
        raid_id: str,
        ended_at: float,
    ) -> bool:
        """
        Persist an unverified public-list ending.

        This is NOT defeat evidence.
        It must never create a skull or defeat_method.
        A later visible snapshot may reopen it.
        A later authoritative HP=0 may upgrade it to defeated.
        """

        raid_id = str(raid_id).strip()

        if not raid_id:
            raise ValueError(
                "Raid history requires raid_id"
            )

        ended_dt = datetime.fromtimestamp(
            float(ended_at)
        )

        connection = self._connect()

        try:
            with connection.cursor() as cursor:
                cursor.execute(
                    """
                    UPDATE raid_history
                    SET
                        lifecycle_status = 'ended',
                        ended_at = COALESCE(
                            ended_at,
                            %s
                        ),
                        defeat_method = NULL
                    WHERE raid_id = %s
                      AND (
                          lifecycle_status IS NULL
                          OR lifecycle_status = 'ended'
                      )
                    """,
                    (
                        ended_dt,
                        raid_id,
                    ),
                )

                changed = cursor.rowcount > 0

            connection.commit()
            return changed

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()


    def mark_lifecycle_end(
        self,
        *,
        raid_id: str,
        status: str,
        ended_at: float,
        method: str = "hp_zero",
    ) -> bool:
        """
        Persist a trustworthy defeat.

        method:
        - hp_zero      : directly observed HP=0
        - missing_180s : REJECTED; disappearance is not defeat evidence
        """

        raid_id = str(raid_id).strip()
        status = str(status).strip().lower()
        method = str(method).strip().lower()

        if not raid_id:
            raise ValueError(
                "Raid history requires raid_id"
            )

        if status != "defeated":
            return False

        if method not in {
            "hp_zero",
            # RAID_REJECT_MISSING_180S_DEFEAT_V1
        }:
            raise ValueError(
                f"invalid defeat method: {method}"
            )

        ended_dt = datetime.fromtimestamp(
            float(ended_at)
        )

        connection = self._connect()

        try:
            with connection.cursor() as cursor:

                cursor.execute(
                    """
                    UPDATE raid_history
                    SET
                        lifecycle_status = 'defeated',

                        ended_at =
                            CASE
                              WHEN
                                lifecycle_status='defeated'
                                AND ended_at IS NOT NULL
                              THEN ended_at
                              ELSE %s
                            END,

                        defeat_method =
                            COALESCE(
                                defeat_method,
                                %s
                            )

                    WHERE raid_id = %s

                      AND (
                        lifecycle_status IS NULL
                        OR lifecycle_status <> 'defeated'
                        OR defeat_method IS NULL
                      )
                    """,
                    (
                        ended_dt,
                        method,
                        raid_id,
                    ),
                )

                changed = cursor.rowcount > 0

            connection.commit()
            return changed

        except Exception:
            connection.rollback()
            raise

        finally:
            connection.close()

    def fetch_player_stats(
        self,
        *,
        founder: str,
        days: int | None = None,
    ) -> dict:
        """Aggregate player performance across Raids opened by founder."""

        if days is not None and days not in (7, 30):
            raise ValueError("days must be None, 7, or 30")

        date_clause = ""
        params: list[object] = [founder]

        if days is not None:
            date_clause = """
                AND r.last_seen_at >= NOW() - INTERVAL %s DAY
            """
            params.append(days)

        connection = self._connect()

        try:
            with connection.cursor(
                pymysql.cursors.DictCursor
            ) as cursor:

                # -------------------------------
                # Raid count for participation %
                # -------------------------------
                cursor.execute(
                    f"""
                    SELECT
                        COUNT(*) AS raid_count,
                        MIN(r.first_seen_at) AS first_seen_at,
                        MAX(r.last_seen_at) AS last_seen_at
                    FROM raid_history AS r
                    WHERE r.founder = %s
                    {date_clause}
                    """,
                    params,
                )

                summary = cursor.fetchone() or {}

                # -------------------------------
                # Player aggregate
                # -------------------------------
                cursor.execute(
                    f"""
                    SELECT
                        p.player_name,

                        COUNT(*) AS raids_joined,

                        SUM(p.damage) AS total_damage,

                        ROUND(
                            AVG(p.damage),
                            1
                        ) AS avg_damage,

                        MAX(p.damage) AS max_damage,

                        SUM(p.point) AS total_point,

                        ROUND(
                            AVG(p.point),
                            1
                        ) AS avg_point,

                        ROUND(
                            AVG(
                                CASE
                                    WHEN rt.raid_total_damage > 0
                                    THEN
                                        p.damage
                                        / rt.raid_total_damage
                                    ELSE 0
                                END
                            ) * 100,
                            1
                        ) AS avg_damage_percent

                    FROM raid_player_history AS p

                    JOIN raid_history AS r
                        ON r.raid_id = p.raid_id

                    JOIN (
                        SELECT
                            raid_id,
                            SUM(damage) AS raid_total_damage
                        FROM raid_player_history
                        GROUP BY raid_id
                    ) AS rt
                        ON rt.raid_id = p.raid_id

                    WHERE r.founder = %s
                    {date_clause}

                    GROUP BY p.player_name

                    ORDER BY
                        total_damage DESC,
                        raids_joined DESC,
                        p.player_name ASC
                    """,
                    params,
                )

                raw_rows = cursor.fetchall()

        finally:
            connection.close()

        raid_count = int(
            summary.get("raid_count") or 0
        )

        rows = []

        for row in raw_rows:
            joined = int(row["raids_joined"] or 0)

            participation_percent = (
                round(joined / raid_count * 100, 1)
                if raid_count > 0
                else 0.0
            )

            rows.append({
                "player_name": row["player_name"],
                "raids_joined": joined,
                "participation_percent":
                    participation_percent,
                "total_damage":
                    int(row["total_damage"] or 0),
                "avg_damage":
                    float(row["avg_damage"] or 0),
                "max_damage":
                    int(row["max_damage"] or 0),
                "total_point":
                    int(row["total_point"] or 0),
                "avg_point":
                    float(row["avg_point"] or 0),
                "avg_damage_percent":
                    float(
                        row["avg_damage_percent"] or 0
                    ),
            })

        first_seen = summary.get("first_seen_at")
        last_seen = summary.get("last_seen_at")

        return {
            "founder": founder,
            "days": days,
            "raid_count": raid_count,
            "player_count": len(rows),
            "first_seen_at": (
                first_seen.isoformat()
                if first_seen is not None
                else None
            ),
            "last_seen_at": (
                last_seen.isoformat()
                if last_seen is not None
                else None
            ),
            "players": rows,
        }

    # RAID_PLAYER_STATS_BOSS_TABLE_V1
    def fetch_founder_boss_stats(
        self,
        *,
        founder: str,
        days: int | None = None,
    ) -> list[dict]:
        """
        Aggregate Boss + rarity statistics for Raids
        opened by one founder.

        Range semantics intentionally match fetch_player_stats:
        all / last 30 days / last 7 days.
        """

        if days is not None and days not in (7, 30):
            raise ValueError(
                "days must be None, 7, or 30"
            )

        date_clause = ""
        params: list[object] = [founder]

        if days is not None:
            date_clause = """
                AND r.last_seen_at
                    >= NOW() - INTERVAL %s DAY
            """
            params.append(days)

        connection = self._connect()

        try:
            with connection.cursor(
                pymysql.cursors.DictCursor
            ) as cursor:

                cursor.execute(
                    f"""
                    SELECT
                        COALESCE(
                            NULLIF(r.boss, ''),
                            '未知 Boss'
                        ) AS boss,

                        r.rarity AS rarity,

                        COUNT(*) AS raids_opened,

                        ROUND(
                            AVG(
                                COALESCE(
                                    r.participant_count,
                                    0
                                )
                            ),
                            1
                        ) AS avg_participants,

                        SUM(
                            CASE
                                WHEN
                                    r.ended_at IS NOT NULL
                                    AND r.ended_at
                                        >= r.first_seen_at
                                THEN 1
                                ELSE 0
                            END
                        ) AS ended_count,

                        ROUND(
                            AVG(
                                CASE
                                    WHEN
                                        r.ended_at IS NOT NULL
                                        AND r.ended_at
                                            >= r.first_seen_at
                                    THEN
                                        TIMESTAMPDIFF(
                                            MICROSECOND,
                                            r.first_seen_at,
                                            r.ended_at
                                        )
                                        / 60000000.0
                                    ELSE NULL
                                END
                            ),
                            1
                        ) AS avg_observed_end_minutes

                    FROM raid_history AS r

                    WHERE r.founder = %s
                    {date_clause}

                    GROUP BY
                        COALESCE(
                            NULLIF(r.boss, ''),
                            '未知 Boss'
                        ),
                        r.rarity

                    ORDER BY
                        raids_opened DESC,
                        boss ASC,
                        rarity ASC
                    """,
                    params,
                )

                raw_rows = cursor.fetchall()

        finally:
            connection.close()

        total_raids = sum(
            int(row["raids_opened"] or 0)
            for row in raw_rows
        )

        rows: list[dict] = []

        for row in raw_rows:

            raids_opened = int(
                row["raids_opened"] or 0
            )

            rarity_raw = row.get("rarity")

            rarity = (
                int(rarity_raw)
                if rarity_raw is not None
                else None
            )

            avg_end_raw = row.get(
                "avg_observed_end_minutes"
            )

            rows.append({
                "boss":
                    str(
                        row.get("boss")
                        or "未知 Boss"
                    ),

                "rarity":
                    rarity,

                "raids_opened":
                    raids_opened,

                "share_percent":
                    (
                        round(
                            raids_opened
                            / total_raids
                            * 100,
                            1
                        )
                        if total_raids > 0
                        else 0.0
                    ),

                "avg_participants":
                    float(
                        row.get(
                            "avg_participants"
                        )
                        or 0
                    ),

                "ended_count":
                    int(
                        row.get("ended_count")
                        or 0
                    ),

                "avg_observed_end_minutes":
                    (
                        float(avg_end_raw)
                        if avg_end_raw
                            is not None
                        else None
                    ),
            })

        return rows
