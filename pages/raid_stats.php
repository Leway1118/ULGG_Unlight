<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
$pdo = $db;

$pageTitleText = 'Raid 統計';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = 'raid_stats';

/* RAID_STATS_ADMIN_VISIBILITY_V1 */
$isRaidStatsAdmin =
    (int)($_SESSION['permission'] ?? 0) >= 2
    ||
    (int)($_SESSION['ack'] ?? 0) >= 2
    ||
    (int)($_SESSION['check_ack'] ?? 0) >= 2;

$range = (string)($_GET['range'] ?? '7');
if (!in_array($range, ['all','30','7'], true)) $range = '7';
$rangeLabel = ['all'=>'全部','30'=>'30天','7'=>'7天'][$range];
$where = '';
$params = [];
if ($range !== 'all') { $where = ' AND r.first_seen_at >= DATE_SUB(NOW(), INTERVAL ? DAY) '; $params[] = (int)$range; }

function qAll(PDO $pdo, string $sql, array $params=[]): array {
  $st=$pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC);
}
function qOne(PDO $pdo, string $sql, array $params=[]): array {
  $st=$pdo->prepare($sql); $st->execute($params); $r=$st->fetch(PDO::FETCH_ASSOC); return is_array($r)?$r:[];
}
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function n($v,$d=0): string { return $v===null||$v===''?'—':number_format((float)$v,$d); }
function pct($a,$b,$d=1): string { return (float)$b<=0?'—':number_format(((float)$a/(float)$b)*100,$d).'%'; }
function dur($s): string { if($s===null||$s==='') return '—'; $s=max(0,(int)round((float)$s)); $h=intdiv($s,3600); $m=intdiv($s%3600,60); return $h>0?"{$h}小時 {$m}分":($m>0?"{$m}分":"{$s}秒"); }

$kpi=qOne($pdo,"SELECT COUNT(DISTINCT r.raid_id) raid_count, COUNT(p.player_name) participation_count, COUNT(DISTINCT p.player_name) player_count FROM raid_history r LEFT JOIN raid_player_history p ON p.raid_id=r.raid_id WHERE 1=1 {$where}",$params);
$topBoss=qOne($pdo,"SELECT COALESCE(NULLIF(r.boss,''),'未知 Boss') boss, COUNT(*) c FROM raid_history r WHERE 1=1 {$where} GROUP BY COALESCE(NULLIF(r.boss,''),'未知 Boss') ORDER BY c DESC,boss LIMIT 1",$params);

/*
 * RAID_TODAY_KUSO_TIER_PRIORITY_V2
 *
 * 今日冒險日誌：
 *
 * 1. 渦IV 永遠優先歸類為 OTHERS。
 * 2. 非渦IV才依 Boss 名稱分類。
 * 3. 每個分類均 COUNT DISTINCT raid_id。
 * 4. Independent from ?range=all/30/7.
 */
$todayKuso=qOne(
    $pdo,
    "SELECT

        COUNT(
            DISTINCT CASE
                WHEN
                    REPLACE(
                        COALESCE(
                            r.whirlpool_tier,
                            ''
                        ),
                        '涡',
                        '渦'
                    ) <> '渦IV'

                    AND r.boss='龍鯰'

                THEN r.raid_id
            END
        ) fish_count,

        COUNT(
            DISTINCT CASE
                WHEN
                    REPLACE(
                        COALESCE(
                            r.whirlpool_tier,
                            ''
                        ),
                        '涡',
                        '渦'
                    ) <> '渦IV'

                    AND r.boss='屠殺者'

                THEN r.raid_id
            END
        ) bug_count,

        COUNT(
            DISTINCT CASE
                WHEN
                    REPLACE(
                        COALESCE(
                            r.whirlpool_tier,
                            ''
                        ),
                        '涡',
                        '渦'
                    ) <> '渦IV'

                    AND r.boss='誘引之者'

                THEN r.raid_id
            END
        ) goddess_count,

        COUNT(
            DISTINCT CASE
                WHEN
                    REPLACE(
                        COALESCE(
                            r.whirlpool_tier,
                            ''
                        ),
                        '涡',
                        '渦'
                    ) <> '渦IV'

                    AND r.boss IN (
                        '靈龜',
                        '黑死獸'
                    )

                THEN r.raid_id
            END
        ) burden_count,

        COUNT(
            DISTINCT CASE
                WHEN
                    REPLACE(
                        COALESCE(
                            r.whirlpool_tier,
                            ''
                        ),
                        '涡',
                        '渦'
                    ) = '渦IV'

                    OR (
                        REPLACE(
                            COALESCE(
                                r.whirlpool_tier,
                                ''
                            ),
                            '涡',
                            '渦'
                        ) <> '渦IV'

                        AND COALESCE(
                            NULLIF(
                                r.boss,
                                ''
                            ),
                            '未知 Boss'
                        ) NOT IN (
                            '龍鯰',
                            '屠殺者',
                            '誘引之者',
                            '靈龜',
                            '黑死獸'
                        )
                    )

                THEN r.raid_id
            END
        ) mystery_count

     FROM raid_history r

     WHERE
        r.first_seen_at >= CURRENT_DATE()

        AND r.first_seen_at
            < CURRENT_DATE()
              + INTERVAL 1 DAY"
);


$bossRows=qAll($pdo,"SELECT
  COALESCE(NULLIF(r.boss,''),'未知 Boss') boss,
  r.rarity,
  r.whirlpool_tier,
  COUNT(*) raid_count,
  /* RAID_STATS_PROTOCOL14_BOSS_SEMANTICS_V1
   * Protocol 1.4 rank[] no longer provides damage.
   * Public participant_count is the authoritative
   * observable participation metric here.
   */
  ROUND(
    AVG(
      COALESCE(
        r.participant_count,
        0
      )
    ),
    1
  ) avg_effective_participants,
  ROUND(AVG(r.hp_max),0) avg_hp_max,

  /* BOSS_AVG_DEFEAT_TIME_WEB_V1 */
  SUM(
    CASE
      WHEN r.lifecycle_status='defeated'
       /* RAID_TRUSTED_DEFEAT_FILTER_V1 */
       AND COALESCE(r.defeat_method,'') <> 'missing_180s'
       AND r.ended_at IS NOT NULL
       AND r.ended_at >= r.first_seen_at
      THEN 1
      ELSE 0
    END
  ) defeated_count,

  ROUND(
    AVG(
      CASE
        WHEN r.lifecycle_status='defeated'
       /* RAID_TRUSTED_DEFEAT_FILTER_V1 */
       AND COALESCE(r.defeat_method,'') <> 'missing_180s'
         AND r.ended_at IS NOT NULL
         AND r.ended_at >= r.first_seen_at
        THEN
          TIMESTAMPDIFF(
            MICROSECOND,
            r.first_seen_at,
            r.ended_at
          ) / 1000000.0
        ELSE NULL
      END
    ),
    0
  ) avg_defeat_seconds,

  /* BOSS_DEFEAT_TIME_DISPLAY_FALLBACK_V1
   * Fallback only for display when a Boss group has
   * no trustworthy defeated sample yet.
   */
  ROUND(
    AVG(
      CASE
        WHEN r.last_seen_at >= r.first_seen_at
        THEN
          TIMESTAMPDIFF(
            MICROSECOND,
            r.first_seen_at,
            r.last_seen_at
          ) / 1000000.0
        ELSE NULL
      END
    ),
    0
  ) avg_observed_seconds

FROM raid_history r
WHERE 1=1
  /* RAID_STATS_HIDE_NULL_RARITY_V1 */
  AND r.rarity IS NOT NULL
  {$where}
GROUP BY
  COALESCE(NULLIF(r.boss,''),'未知 Boss'),
  r.rarity,
  r.whirlpool_tier
ORDER BY raid_count DESC,boss,r.rarity,r.whirlpool_tier",$params);


/*
 * RAID_STATS_REMOVE_LIVE_DAMAGE_V2
 *
 * Active player statistics are POINT-only.
 * Average POINT excludes point=0.
 */
$players=qAll(
    $pdo,
    "SELECT
        p.player_name,
        COUNT(*) raids_joined,
        COALESCE(SUM(p.point),0) total_point,

        ROUND(
            AVG(
                CASE
                    WHEN p.point>0
                    THEN p.point
                END
            ),
            0
        ) avg_point,

        COALESCE(MAX(p.point),0) max_point,

        SUM(
            CASE
                WHEN p.rank_no=1
                THEN 1
                ELSE 0
            END
        ) first_count

     FROM raid_player_history p

     JOIN raid_history r
       ON r.raid_id=p.raid_id

     WHERE 1=1 {$where}

     GROUP BY
        p.player_name",

    $params
);


foreach($players as &$r){

    $j=max(
        1,
        (int)(
            $r['raids_joined']
            ??0
        )
    );

    $r['first_rate']=
        100
        *(int)(
            $r['first_count']
            ??0
        )
        /$j;
}

unset($r);

/*
 * RAID_SUPPORT_ARCHIVE_STATIC_JS_V1
 *
 * 2026-09-22 10:00 official maintenance boundary.
 *
 * Protocol 1.4 no longer exposes the Boss state data
 * required to continue raid_support_events attribution.
 *
 * Support / specialty statistics are frozen into:
 *
 *   /assets/js/raid_support_archive_20260922.js
 *
 * IMPORTANT:
 * - no raid_support_events scan
 * - no raid_support_candidates scan
 * - legacy DB rows are preserved
 * - archive is intentionally independent of 7/30/all range
 */
$supportAvailable=false;
$supportRows=[];
$supportBoards=[];
$supportSpecialtyStatusRows=[];
$statusSpecialtyPlayers=[];
$statusSpecialtyMap=[];


$founders=qAll($pdo,"SELECT r.founder, COUNT(*) raids_opened, ROUND(AVG(r.participant_count),1) avg_participants, MAX(r.participant_count) max_participants FROM raid_history r WHERE r.founder IS NOT NULL AND r.founder<>'' {$where} GROUP BY r.founder",$params);
$favRows=qAll($pdo,"SELECT r.founder, COALESCE(NULLIF(r.boss,''),'未知 Boss') boss, COUNT(*) c FROM raid_history r WHERE r.founder IS NOT NULL AND r.founder<>'' {$where} GROUP BY r.founder,COALESCE(NULLIF(r.boss,''),'未知 Boss') ORDER BY r.founder,c DESC,boss",$params);
$fav=[]; foreach($favRows as $r){ if(!isset($fav[$r['founder']])) $fav[$r['founder']]=$r['boss']; }

function sortRows(array $rows,string $key,string $nameKey,int $min=0,int $limit=20): array {
  $rows=array_values(array_filter($rows,fn($r)=>$min<=0||(int)($r['raids_joined']??$r['raids_opened']??0)>=$min));
  usort($rows,fn($a,$b)=>(((float)$b[$key]<=> (float)$a[$key]) ?: strcmp((string)$a[$nameKey],(string)$b[$nameKey])));
  return array_slice($rows,0,$limit);
}

/*
 * PLAYER_POINT_RANKING_V1
 *
 * Player ranking:
 *
 * total_point
 *   = SUM(raid_player_history.point)
 *
 * avg_point
 *   = total_point / raids_joined
 *
 * total_point:
 *   no minimum raid requirement
 *
 * avg_point:
 *   minimum 10 raids
 */







/*
 * RAID_SINGLE_MAX_DATE_V1
 *
 * 單場最高 POINT：
 * 每位玩家保留實際產生最高值的 Raid。
 * 同值時取最早首次觀測日期。
 */

$maxPointPlayers=qAll(
    $pdo,
    "SELECT
        player_name,
        max_point,
        boss,
        record_date,
        raid_id
     FROM (
        SELECT
            p.player_name,
            COALESCE(p.point,0) max_point,

            COALESCE(
                NULLIF(r.boss,''),
                '未知 Boss'
            ) boss,

            DATE(r.first_seen_at) record_date,
            p.raid_id,

            ROW_NUMBER() OVER (
                PARTITION BY p.player_name
                ORDER BY
                    COALESCE(p.point,0) DESC,
                    r.first_seen_at ASC,
                    p.raid_id ASC
            ) row_no

        FROM raid_player_history p

        JOIN raid_history r
          ON r.raid_id=p.raid_id

        WHERE 1=1 {$where}
     ) ranked

     WHERE row_no=1

     ORDER BY
        max_point DESC,
        player_name ASC

     LIMIT 20",
    $params
);


/* RAID_STATS_SINGLE_MATCH_MAX_V2 */
/* RAID_STATS_POINT_BOARDS_ONLY_V2 */

$boards=[

    'total_point'=>
        sortRows(
            $players,
            'total_point',
            'player_name'
        ),

    'avg_point'=>
        sortRows(
            $players,
            'avg_point',
            'player_name',
            10
        ),

    'max_point'=>
        $maxPointPlayers,

    'first_rate'=>
        sortRows(
            $players,
            'first_rate',
            'player_name',
            10
        ),
];

$founderBoard=sortRows($founders,'raids_opened','founder');

/*
 * RAID_FUN_DAILY_RECORDS_V1
 *
 * -----------------------------------------------
 * 單日最高玩家參與（去重）
 * -----------------------------------------------
 *
 * 同一 calendar day：
 *   COUNT(DISTINCT player_name)
 *
 * 同一玩家當天參與多個 Raid 只算 1 人。
 *
 *
 * -----------------------------------------------
 * 單日擊敗最多 BOSS
 * -----------------------------------------------
 *
 * defeated + ended_at
 * GROUP BY DATE(ended_at)
 *
 * 每個 raid_id 只算一次。
 */


/*
 * 玩家參與：
 * 日期以 Raid 首次觀測時間 first_seen_at 為準。
 */
$dailyPlayerWhere='';
$dailyPlayerParams=[];

if($range!=='all'){
    $dailyPlayerWhere=
        ' AND r.first_seen_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ';
    $dailyPlayerParams[]=(int)$range;
}


$dailyUniquePlayers=qOne(
    $pdo,
    "SELECT
        DATE(r.first_seen_at) record_date,

        COUNT(
            DISTINCT p.player_name
        ) record_value

     FROM raid_history r

     JOIN raid_player_history p
       ON p.raid_id=r.raid_id

     WHERE
        r.first_seen_at IS NOT NULL

        AND p.player_name IS NOT NULL
        AND p.player_name<>''

        {$dailyPlayerWhere}

     GROUP BY
        DATE(r.first_seen_at)

     ORDER BY
        record_value DESC,
        record_date ASC

     LIMIT 1",

    $dailyPlayerParams
);


if($dailyUniquePlayers){

    $dailyUniquePlayers[
        'player_name'
    ]=
        (string)(
            $dailyUniquePlayers[
                'record_date'
            ]
            ??''
        );

    $dailyUniquePlayers[
        'daily_unique_players'
    ]=
        (int)(
            $dailyUniquePlayers[
                'record_value'
            ]
            ??0
        );
}


/*
 * Boss 擊敗：
 * 日期應以實際擊破 ended_at 為準，
 * 所以 7天 / 30天也使用 ended_at 篩選。
 */
/*
 * RAID_STATS_HISTORICAL_DEFEAT_V2
 *
 * 這是「歷史統計」，與即時 Discord 死亡判定分離。
 *
 * authoritative:
 *   lifecycle_status=defeated
 *
 * retrospective historical inference:
 *   - Raid 已經自然到期
 *   - 最終 last_seen_at 比 expires_at 至少早 180 秒
 *   - 最後觀測時並非滿員
 *
 * 注意：
 * 只拿來做統計，不寫回 lifecycle_status，
 * 不觸發 Discord ☠️。
 */

$dailyDefeatWhere='';
$dailyDefeatParams=[];

if($range!=='all'){
    $dailyDefeatWhere=
        ' AND h.historical_end_at >= DATE_SUB(NOW(), INTERVAL ? DAY) ';
    $dailyDefeatParams[]=(int)$range;
}


$dailyDefeatedBoss=qOne(
    $pdo,
    "SELECT
        DATE(h.historical_end_at) record_date,

        COUNT(
            DISTINCT h.raid_id
        ) record_value

     FROM (

        SELECT
            r.raid_id,

            CASE

                /*
                 * 已有正式 defeated 紀錄：
                 * 直接使用 ended_at。
                 */
                WHEN
                    r.lifecycle_status='defeated'
                    AND r.ended_at IS NOT NULL
                    AND COALESCE(
                        r.defeat_method,
                        ''
                    ) <> 'missing_180s'

                THEN r.ended_at


                /*
                 * 歷史回推：
                 *
                 * 現在已超過 Raid 自然期限，
                 * 但最後一次觀測在期限前至少
                 * 180 秒就永久消失。
                 *
                 * 暫時消失又回來的 Raid，
                 * last_seen_at 會被後續觀測更新，
                 * 因此不會因中途 omission 被計算。
                 */
                WHEN
                    r.expires_at_ms IS NOT NULL

                    AND
                    FROM_UNIXTIME(
                        r.expires_at_ms / 1000.0
                    ) < NOW()

                    AND r.last_seen_at IS NOT NULL

                    AND r.last_seen_at <
                        DATE_SUB(
                            FROM_UNIXTIME(
                                r.expires_at_ms / 1000.0
                            ),
                            INTERVAL 180 SECOND
                        )

                    /*
                     * 滿員造成退出 public support
                     * list 不視為擊敗。
                     */
                    AND (
                        r.member_limit IS NULL
                        OR r.participant_count
                           IS NULL
                        OR r.participant_count
                           < r.member_limit
                    )

                THEN r.last_seen_at

                ELSE NULL

            END historical_end_at

        FROM raid_history r

     ) h

     WHERE
        h.historical_end_at IS NOT NULL

        {$dailyDefeatWhere}

     GROUP BY
        DATE(h.historical_end_at)

     ORDER BY
        record_value DESC,
        record_date ASC

     LIMIT 1",

    $dailyDefeatParams
);


if($dailyDefeatedBoss){

    $dailyDefeatedBoss[
        'player_name'
    ]=
        (string)(
            $dailyDefeatedBoss[
                'record_date'
            ]
            ??''
        );

    $dailyDefeatedBoss[
        'daily_defeated_bosses'
    ]=
        (int)(
            $dailyDefeatedBoss[
                'record_value'
            ]
            ??0
        );
}


/*
 * 趣味紀錄
 *
 * 前兩張改成每日紀錄；
 * 後兩張保留既有功能。
 */
$fun=[
    [
        '🔥',
        '單日最高玩家參與（去重）',
        $dailyUniquePlayers ?: null,
        'daily_unique_players'
    ],

    [
        '🏆',
        '單日結束最多 BOSS',
        $dailyDefeatedBoss ?: null,
        'daily_defeated_bosses'
    ],

    ['🥇','冠軍次數最多',
        sortRows(
            $players,
            'first_count',
            'player_name'
        )[0]??null,
        'first_count'
    ],

    ['🌟','人氣渦主',
        sortRows(
            $founders,
            'avg_participants',
            'founder',
            5
        )[0]??null,
        'avg_participants'
    ],
];


/*
 * RAID_CAREER_ADMIN_PLAYER_VIEW_V1_1
 *
 * Normal user:
 *   inspect own Raid career.
 *
 * Admin:
 *   ?career_player=<name>
 *   may inspect any historical Raid player.
 */
$isRaidCareerAdmin = $isRaidStatsAdmin;


$me = trim(
    (string)(
        $_SESSION['username']
        ?? ''
    )
);


$careerRequested = trim(
    (string)(
        $_GET['career_player']
        ?? ''
    )
);


$careerPlayer = $me;


/*
 * Only admins may override the player.
 */
if (
    $isRaidCareerAdmin
    && $careerRequested !== ''
) {
    $careerPlayer =
        $careerRequested;
}


$careerIsAdminView =
    $isRaidCareerAdmin
    && $careerRequested !== ''
    && $careerPlayer !== $me;


/*
 * Preserve selected player while changing:
 * 全部 / 30天 / 7天
 */
$careerRangeSuffix =
    (
        $isRaidCareerAdmin
        && $careerRequested !== ''
    )
    ? (
        '&career_player='
        . rawurlencode(
            $careerPlayer
        )
    )
    : '';


/*
 * Admin datalist:
 * all historical Raid players,
 * not restricted by current range.
 */
$careerPlayerOptions = [];

if ($isRaidCareerAdmin) {

    $careerPlayerOptions = qAll(
        $pdo,
        "SELECT DISTINCT
            player_name
         FROM raid_player_history
         WHERE
            player_name IS NOT NULL
            AND player_name <> ''
         ORDER BY
            player_name"
    );
}


/*
 * Current range summary.
 */
$my = null;

if ($careerPlayer !== '') {

    foreach ($players as $r) {

        if (
            (string)(
                $r['player_name']
                ?? ''
            )
            === $careerPlayer
        ) {
            $my = $r;
            break;
        }
    }
}


$allies = [];
$myBoss = [];


if ($careerPlayer !== '') {

    /*
     * Best allies:
     * existing POINT > 0 rule retained.
     */
    $allies = qAll(
        $pdo,
        "SELECT
            t.player_name,
            COUNT(*) together_count

         FROM raid_player_history me

         JOIN raid_player_history t
           ON t.raid_id = me.raid_id
          AND t.player_name <> ?

         JOIN raid_history r
           ON r.raid_id = me.raid_id

         WHERE
            me.player_name = ?
            AND COALESCE(t.point,0) > 0

            {$where}

         GROUP BY
            t.player_name

         ORDER BY
            together_count DESC,
            t.player_name

         LIMIT 10",

        [
            $careerPlayer,
            $careerPlayer,
            ...$params
        ]
    );


    /*
     * Boss preference:
     * same existing calculation,
     * only target player changes.
     */
    $myBoss = qAll(
        $pdo,
        "SELECT
            COALESCE(
                NULLIF(r.boss,''),
                '未知 Boss'
            ) boss,

            COUNT(*) raid_count,

            SUM(
                CASE
                    WHEN p.rank_no
                         BETWEEN 1 AND 10
                    THEN 1
                    ELSE 0
                END
            ) top10_count

         FROM raid_player_history p

         JOIN raid_history r
           ON r.raid_id = p.raid_id

         WHERE
            p.player_name = ?

            {$where}

         GROUP BY
            COALESCE(
                NULLIF(r.boss,''),
                '未知 Boss'
            )

         ORDER BY
            raid_count DESC,
            boss",

        [
            $careerPlayer,
            ...$params
        ]
    );
}



/*
 * SUPPORT_AVG_MIN_3_RAIDS_V2
 *
 * 場均排行榜只納入觀察期參戰至少 5 場。
 *
 * 只影響：
 * - avg_support_score
 * - avg_maintenance_score
 * - avg_build_score
 *
 * 累積排行榜不受影響。
 *
 * 必須從完整 supportRows 重新排名後取 Top20，
 * 不能只從既有 Top20 移除 1 場玩家，
 * 否則後面的合格玩家不會遞補。
 */
if(
    isset($supportRows)
    && is_array($supportRows)
    && isset($supportBoards)
    && is_array($supportBoards)
){

    $supportAvgMetrics=[
        'avg_support_score',
        'avg_maintenance_score',
        'avg_skill_score',
    ];

    foreach($supportAvgMetrics as $metric){

        $eligible=array_values(
            array_filter(
                $supportRows,
                static function(array $row): bool {
                    return (int)(
                        $row['observed_raids']
                        ?? 0
                    ) >= 5;
                }
            )
        );

        usort(
            $eligible,
            static function(
                array $a,
                array $b
            ) use ($metric): int {

                $av=(float)(
                    $a[$metric]
                    ?? 0
                );

                $bv=(float)(
                    $b[$metric]
                    ?? 0
                );

                if($av !== $bv){
                    return $bv <=> $av;
                }

                /*
                 * 同分時：
                 * 場數較多者優先，
                 * 再以玩家名稱穩定排序。
                 */
                $ar=(int)(
                    $a['observed_raids']
                    ?? 0
                );

                $br=(int)(
                    $b['observed_raids']
                    ?? 0
                );

                if($ar !== $br){
                    return $br <=> $ar;
                }

                return strcmp(
                    (string)(
                        $a['player_name']
                        ?? ''
                    ),
                    (string)(
                        $b['player_name']
                        ?? ''
                    )
                );
            }
        );

        $supportBoards[$metric]=array_slice(
            $eligible,
            0,
            20
        );
    }
}


// SUPPORT_SPECIALTY_REPEAT_BUILD_V3
// added/upgraded: unique strong candidate + observed_count >= 2
// refreshed/maintenance: unique strong candidate + observed_count >= 1

// SUPPORT_POINT_ONLY_ATTRIBUTION_V5
//
// Unique strong candidate:
//   point_delta > 0
//
// damage_delta is NOT a candidate filter.
// It may be 0 or >0.
//
// Applies to:
// - six support leaderboards
// - player specialty/status attribution

// SUPPORT_REPEAT_ATTRIBUTION_V6
//
// Candidate:
//   point_delta > 0
//   damage unrestricted
//
// added/upgraded:
//   same player + same status >= 2 bundles
//
// refreshed:
//   unique POINT+ bundle => 1 is enough
//   ambiguous POINT+ bundles => same player >= 2
//
// Six boards and Specialty share this evidence.

/*
 * SUPPORT_V9_FINAL_BOARD_FILTER
 *
 * 各榜只顯示該 metric > 0 的玩家。
 * 避免延長榜或上狀態榜尾端出現 0 分玩家。
 */
if(
    isset($supportBoards)
    &&is_array($supportBoards)
){

    foreach(
        $supportBoards as
        $metric=>&$rows
    ){

        $rows=
            array_values(
                array_filter(
                    $rows,

                    static function(
                        array $row
                    ) use ($metric):bool {

                        return
                            (float)(
                                $row[
                                    $metric
                                ]??0
                            ) > 0;
                    }
                )
            );


        $rows=
            array_slice(
                $rows,
                0,
                20
            );
    }

    unset($rows);
}


// SUPPORT_EVIDENCE_MODEL_V9
// Attribution: exactly one filtered candidate + exactly one status_base.
// POINT+ / damage+ are not required.
// Ambiguous bundles are discarded.

// RAID_STATS_V8_AVG_GT3_SPECIALTY_TOTAL_ALLY_ZERO
//
// 1. avg support boards: observed_raids > 3
// 2. specialty total: use V7 player support_score
// 3. best allies: teammate POINT=0 only

// BEST_ALLY_POINT_POSITIVE_NO_MIN_V2
// Best Ally:
// - teammate POINT > 0
// - no minimum co-appearance count

// SPECIALTY_INTEGER_DISPLAY_V1
// Specialty visible scores use integer display only.
// V7 internal evidence keeps full decimal precision.

// RAID_STATUS_COMPLETE_RUNTIME_27_V1
// Complete Steam runtime status dictionary:
// poison poison2 mahi atkB atkD defB defD movB movD
// bers stun huin jikai immo scare rege bind chaos stigma
// dbuff sticka stickd curse critical control target dark


// SUPPORT_REMOVED_UI_V1
// removed = +10 accepted V9 unique-candidate evidence
// UI:
// - cumulative removal board
// - average removal board
// - specialty Top5 = 🧹解除
// - average boards require >=5 observed raids


/*
 * RAID_DAMAGE_DPM_ARCHIVED_V2
 *
 * Pre-2026-09-22 10:00 damage / DPM is served from
 * /assets/js/raid_damage_archive_20260922.js.
 *
 * No live damage-event scan and no live damage ranking lookup.
 */
$dpmAvailable=false;
$dpmBoards=[];
$dpmBossBoards=[];

/*
 * Header range links still reference this suffix.
 * Old live ranking lookup UI has been retired.
 */
/* RAID_STATS_SELF_LOOKUP_POINT_V2
 *
 * Public leaderboard remains Top20.
 * Self lookup ranks against ALL rows in the selected range.
 *
 * POINT-only:
 * - total_point
 * - avg_point
 * - max_point
 * - first_rate
 *
 * Damage / DPM intentionally remain archived.
 */
$rankLookupPlayer = trim(
    (string)(
        $_GET['rank_player']
        ?? ''
    )
);


$rankLookupOptions = [];

foreach ($players as $lookupOptionRow) {

    $lookupOptionName = trim(
        (string)(
            $lookupOptionRow['player_name']
            ?? ''
        )
    );

    if ($lookupOptionName !== '') {
        $rankLookupOptions[
            $lookupOptionName
        ] = true;
    }
}

$rankLookupOptions =
    array_keys(
        $rankLookupOptions
    );

sort(
    $rankLookupOptions,
    SORT_NATURAL | SORT_FLAG_CASE
);


function raidStatsFindPointRankV2(
    array $rows,
    string $playerName
): ?array {

    if ($playerName === '') {
        return null;
    }

    foreach ($rows as $index => $row) {

        if (
            (string)(
                $row['player_name']
                ?? ''
            )
            === $playerName
        ) {
            return [
                'rank' => $index + 1,
                'row' => $row,
            ];
        }
    }

    return null;
}


$rankLookupPointBoardsAll = [

    'total_point' =>
        sortRows(
            $players,
            'total_point',
            'player_name',
            0,
            PHP_INT_MAX
        ),

    'avg_point' =>
        sortRows(
            $players,
            'avg_point',
            'player_name',
            10,
            PHP_INT_MAX
        ),

    'max_point' =>
        sortRows(
            $players,
            'max_point',
            'player_name',
            0,
            PHP_INT_MAX
        ),

    'first_rate' =>
        sortRows(
            $players,
            'first_rate',
            'player_name',
            10,
            PHP_INT_MAX
        ),
];


$rankLookupPointStats = [];

if ($rankLookupPlayer !== '') {

    foreach (
        $rankLookupPointBoardsAll
        as $metric => $lookupRows
    ) {

        $rankLookupPointStats[$metric] =
            raidStatsFindPointRankV2(
                $lookupRows,
                $rankLookupPlayer
            );
    }
}


$rankLookupPlayerRow = null;

if ($rankLookupPlayer !== '') {

    foreach ($players as $lookupRow) {

        if (
            (string)(
                $lookupRow['player_name']
                ?? ''
            )
            === $rankLookupPlayer
        ) {

            $rankLookupPlayerRow =
                $lookupRow;

            break;
        }
    }
}


$rankLookupRangeSuffix =
    $rankLookupPlayer !== ''
    ? (
        '&rank_player='
        . rawurlencode(
            $rankLookupPlayer
        )
    )
    : '';



ob_start();
?>
<style>
.raidstats{--bg:#0f1320;--panel:rgba(18,22,35,.9);--line:rgba(255,255,255,.09);--muted:#9aa4b8;--accent:#b8beff;color:#eef2f8;padding:18px 4px 45px}.rs-hero,.rs-section{background:var(--panel);border:1px solid var(--line);border-radius:14px;padding:16px;margin-top:15px}.rs-hero{margin-top:0;background:linear-gradient(135deg,rgba(42,48,78,.96),rgba(16,19,31,.96))}.rs-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-end}.rs-title{margin:0;font-size:27px}.rs-note{color:var(--muted);font-size:12px;margin-top:5px}.rs-range,.rs-tabs{display:flex;gap:6px;flex-wrap:wrap}.rs-range a,.rs-tabs button{background:rgba(255,255,255,.04);border:1px solid var(--line);border-radius:8px;color:var(--muted);padding:7px 11px;text-decoration:none}.rs-range .on,.rs-tabs .on{background:rgba(184,190,255,.14);color:#fff;border-color:rgba(184,190,255,.4)}.rs-kpi{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px;margin-top:16px}.rs-card{background:rgba(255,255,255,.035);border:1px solid var(--line);border-radius:10px;padding:12px}

/* RAID_TODAY_KUSO_CARD_CSS_V1 */
.rs-kuso-card{
  grid-column:span 2;
  min-width:0;
  padding:12px 14px;
  overflow:hidden;
  background:
    linear-gradient(
      135deg,
      rgba(255,200,90,.09),
      rgba(111,190,255,.07)
    );
  border-color:rgba(255,210,120,.20);
}

.rs-kuso-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:8px;
  margin-bottom:8px;
}

.rs-kuso-title{
  font-size:14px;
  font-weight:900;
  letter-spacing:.02em;
}

.rs-kuso-today{
  flex:0 0 auto;
  padding:2px 7px;
  border-radius:999px;
  background:rgba(255,255,255,.05);
  color:var(--muted);
  font-size:10px;
  font-weight:700;
}

.rs-kuso-list{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:5px 10px;
}

.rs-kuso-item{
  min-width:0;
  font-size:11px;
  line-height:1.45;
  color:#dbe1ec;
}

.rs-kuso-item strong{
  margin:0 2px;
  color:#fff;
  font-size:15px;
  font-weight:900;
}

.rs-kuso-item small{
  color:var(--muted);
  font-size:9px;
}

.rs-kuso-item.rs-kuso-wide{
  grid-column:1/-1;
}

.rs-label{font-size:11px;color:var(--muted)}.rs-value{display:block;font-size:20px;font-weight:800;margin-top:3px}.rs-tablewrap{overflow:auto}.rs-table{width:100%;border-collapse:collapse;font-size:12px}.rs-table th,.rs-table td{padding:8px 7px;border-bottom:1px solid rgba(255,255,255,.06);white-space:nowrap}.rs-table th{color:var(--muted);text-align:left}
.rs-sort{cursor:pointer;user-select:none}
.rs-sort:hover{color:#fff}
.rs-sort.is-active{color:#fff}
.rs-sortmark{display:inline-block;min-width:12px;margin-left:3px;opacity:.65}
.rs-sort.is-active .rs-sortmark{opacity:1}.num{text-align:right!important}.rs-beta{border-color:rgba(111,190,255,.28);background:linear-gradient(180deg,rgba(27,38,58,.92),rgba(18,22,35,.9))}.rs-beta-pill{display:inline-block;margin-left:7px;padding:2px 7px;border:1px solid rgba(111,190,255,.35);border-radius:999px;color:#b9dcff;font-size:10px;font-weight:800;vertical-align:middle}.rs-evidence{font-size:10px;color:var(--muted);white-space:normal;line-height:1.45}.rs-two{display:grid;grid-template-columns:1fr 1fr;gap:15px}.rs-fun{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.rs-fun .who{font-weight:800;font-size:15px;margin-top:4px}.rs-personal{border-color:rgba(118,215,170,.28)}.rs-mykpi{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.rs-empty{color:var(--muted);text-align:center;padding:16px}.ranktop{color:#f3c56b;font-weight:800}@media(max-width:991px){.rs-kpi{grid-template-columns:repeat(2,1fr)}.rs-mykpi{grid-template-columns:repeat(3,1fr)}.rs-two{grid-template-columns:1fr}}
/* RAID_TODAY_KUSO_MOBILE_V1 */
@media(max-width:991px){
  .rs-kuso-card{
    grid-column:1/-1;
  }
}

@media(max-width:767px){
  .rs-kuso-list{
    grid-template-columns:1fr;
  }

  .rs-kuso-item.rs-kuso-wide{
    grid-column:auto;
  }
}

@media(max-width:767px){.rs-head{align-items:stretch;flex-direction:column}.rs-kpi,.rs-mykpi,.rs-fun{grid-template-columns:1fr}.rs-range a{flex:1;text-align:center}}


/* RAID_STATS_MOBILE_OVERFLOW_V1
 * Prevent CSS Grid min-content width from pushing the bottom cards
 * outside the mobile viewport. Tables remain horizontally scrollable
 * inside their own wrappers.
 */
.raidstats,
.raidstats * {
  box-sizing:border-box;
}

.raidstats,
.rs-hero,
.rs-section,
.rs-two,
.rs-two > *,
.rs-fun,
.rs-fun > *,
.rs-card,
.rs-tablewrap {
  min-width:0;
  max-width:100%;
}

.rs-two {
  grid-template-columns:minmax(0,1fr) minmax(0,1fr);
}

.rs-fun {
  grid-template-columns:repeat(4,minmax(0,1fr));
}

.rs-mykpi {
  grid-template-columns:repeat(4,minmax(0,1fr));
}

.rs-tablewrap {
  width:100%;
  max-width:100%;
  overflow-x:auto;
  overflow-y:hidden;
  -webkit-overflow-scrolling:touch;
  overscroll-behavior-x:contain;
}

.rs-fun .who {
  min-width:0;
  overflow-wrap:anywhere;
  word-break:break-word;
}

@media(max-width:991px) {
  .rs-two {
    grid-template-columns:minmax(0,1fr);
  }

  .rs-mykpi {
    grid-template-columns:repeat(3,minmax(0,1fr));
  }
}

@media(max-width:767px) {
  .rs-kpi,
  .rs-mykpi,
  .rs-fun {
    grid-template-columns:minmax(0,1fr);
  }
}

/* RAID_CAREER_ADMIN_PLAYER_VIEW_CSS_V1_1 */

.rs-admin-career-badge {
  display:inline-block;
  margin-left:7px;
  padding:2px 7px;
  border:1px solid rgba(118,215,170,.38);
  border-radius:999px;
  color:#9be2be;
  font-size:10px;
  font-weight:800;
  vertical-align:middle;
}

.rs-admin-career-tools {
  display:flex;
  align-items:center;
  justify-content:flex-end;
  min-width:330px;
}

.rs-admin-career-form {
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  justify-content:flex-end;
  gap:7px;
}

.rs-admin-career-input {
  width:220px;
  max-width:100%;
  padding:8px 10px;
  border:1px solid rgba(255,255,255,.12);
  border-radius:8px;
  background:rgba(255,255,255,.05);
  color:#fff;
  outline:none;
  font-size:13px;
}

.rs-admin-career-input:focus {
  border-color:rgba(118,215,170,.58);
}

.rs-admin-career-button,
.rs-admin-career-reset {
  padding:8px 11px;
  border-radius:8px;
  font-size:12px;
  font-weight:700;
  text-decoration:none;
}

.rs-admin-career-button {
  border:1px solid rgba(118,215,170,.35);
  background:rgba(118,215,170,.11);
  color:#b7edcf;
  cursor:pointer;
}

.rs-admin-career-button:hover {
  background:rgba(118,215,170,.19);
  color:#fff;
}

.rs-admin-career-reset {
  border:1px solid rgba(255,255,255,.10);
  background:rgba(255,255,255,.04);
  color:var(--muted);
}

.rs-admin-career-reset:hover {
  color:#fff;
}

@media(max-width:767px) {

  .rs-admin-career-tools {
    min-width:0;
    width:100%;
  }

  .rs-admin-career-form {
    width:100%;
    justify-content:stretch;
  }

  .rs-admin-career-input {
    width:100%;
    flex:1 1 100%;
  }

  .rs-admin-career-button,
  .rs-admin-career-reset {
    flex:1 1 auto;
    text-align:center;
  }
}


/* RAID_STATS_SELF_LOOKUP_CSS_V1 */
.rs-selflookup{
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:7px;
  margin:11px 0 10px;
}
.rs-selflookup-form{
  display:flex;
  flex:1 1 320px;
  gap:7px;
  min-width:0;
}
.rs-selflookup-input{
  flex:1 1 220px;
  min-width:0;
  padding:8px 10px;
  border:1px solid rgba(255,255,255,.12);
  border-radius:8px;
  background:rgba(255,255,255,.05);
  color:#fff;
  outline:none;
}
.rs-selflookup-button,
.rs-selflookup-me{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:8px 11px;
  border:1px solid rgba(184,190,255,.28);
  border-radius:8px;
  background:rgba(184,190,255,.09);
  color:#dfe3ff;
  text-decoration:none;
  font-size:12px;
  font-weight:800;
  white-space:nowrap;
}
.rs-selflookup-button{cursor:pointer}
.rs-selflookup-summary{
  margin:0 0 11px;
  padding:10px;
  border:1px solid rgba(118,215,170,.20);
  border-radius:10px;
  background:rgba(118,215,170,.045);
}
.rs-selflookup-title{
  margin-bottom:8px;
  font-size:12px;
  color:var(--muted);
}
.rs-selflookup-title strong{
  color:#fff;
  font-size:14px;
}
.rs-selflookup-grid{
  display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:7px;
}
.rs-selflookup-item{
  min-width:0;
  padding:8px;
  border:1px solid rgba(255,255,255,.07);
  border-radius:8px;
  background:rgba(255,255,255,.025);
}
.rs-selflookup-item span,
.rs-selflookup-item small{
  display:block;
  color:var(--muted);
  font-size:10px;
}
.rs-selflookup-item strong{
  display:block;
  margin:2px 0;
  font-size:16px;
  color:#fff;
}
@media(max-width:991px){
  .rs-selflookup-grid{
    grid-template-columns:repeat(2,minmax(0,1fr));
  }
}
@media(max-width:767px){
  .rs-selflookup-form{flex:1 1 100%}
  .rs-selflookup-grid{grid-template-columns:minmax(0,1fr)}
}


/* RAID_STATS_RANGE_ACTIVE_CSS_V1 */

/*
 * 右上角目前選擇區間
 * 與一般排行榜 tabs 做更明顯區隔。
 */
.rs-range{
    padding:4px;
    border:1px solid rgba(255,255,255,.09);
    border-radius:11px;
    background:rgba(0,0,0,.16);
}

.rs-range a{
    position:relative;
    font-weight:700;
    transition:
        background .15s ease,
        border-color .15s ease,
        box-shadow .15s ease,
        transform .15s ease;
}

.rs-range a:hover{
    color:#fff;
    border-color:rgba(184,190,255,.45);
    background:rgba(184,190,255,.09);
}

.rs-range a.on{
    color:#fff;
    font-weight:900;

    background:
        linear-gradient(
            135deg,
            rgba(123,134,255,.34),
            rgba(184,190,255,.20)
        );

    border-color:
        rgba(194,199,255,.88);

    box-shadow:
        0 0 0 1px rgba(184,190,255,.18),
        0 0 14px rgba(123,134,255,.24),
        0 4px 12px rgba(0,0,0,.20);

    transform:translateY(-1px);
}

.rs-range a.on::before{
    content:"目前";

    display:inline-block;

    margin-right:5px;
    padding:1px 5px;

    border-radius:999px;

    background:rgba(255,255,255,.16);

    color:#fff;

    font-size:9px;
    font-weight:900;

    line-height:1.5;

    vertical-align:1px;
}

/*
 * Mobile：
 * 保留目前標記，但避免按鈕過度擁擠。
 */
@media(max-width:767px){

    .rs-range{
        width:100%;
        display:flex;
    }

    .rs-range a{
        min-width:0;
    }

    .rs-range a.on::before{
        font-size:8px;
        margin-right:4px;
        padding:1px 4px;
    }
}


</style>
<div class="content-wrapper"><section class="content ul-container-nopad"><div class="container raidstats">
<!-- RAID_STATS_RANGE_KEY_TYPE_FIX_V1 -->
<header class="rs-hero"><div class="rs-head"><div><div class="rs-label">PUBLIC RAID ANALYTICS</div><h1 class="rs-title">Raid 統計中心</h1></div><nav class="rs-range"><?php foreach(['all'=>'全部','30'=>'30天','7'=>'7天'] as $k=>$v):?><a class="<?=$range===(string)$k?'on':''?>" href="<?=h('?range='.$k.$careerRangeSuffix.$rankLookupRangeSuffix)?>"><?=h($v)?></a><?php endforeach;?></nav></div>
<div class="rs-kpi"><div class="rs-card"><span class="rs-label">🌀 已記錄 Raid</span><strong class="rs-value"><?=n($kpi['raid_count']??0)?></strong></div><div class="rs-card"><span class="rs-label">👥 活躍玩家</span><strong class="rs-value"><?=n($kpi['player_count']??0)?></strong></div><div class="rs-card"><span class="rs-label">⚔️ 參戰人次</span><strong class="rs-value"><?=n($kpi['participation_count']??0)?></strong></div><div class="rs-card rs-kuso-card">

<div class="rs-kuso-head">

<div class="rs-kuso-title">
📖 今日冒險日誌
</div>

<div class="rs-kuso-today">
TODAY
</div>

</div>


<div class="rs-kuso-list">

<div class="rs-kuso-item">
🐟 今天釣到了
<strong><?=n($todayKuso['fish_count']??0)?></strong>
隻魚
<small>龍鯰</small>
</div>


<div class="rs-kuso-item">
🐛 抓到了
<strong><?=n($todayKuso['bug_count']??0)?></strong>
隻蟲
<small>屠殺者</small>
</div>


<div class="rs-kuso-item">
🐙 遇見了
<strong><?=n($todayKuso['goddess_count']??0)?></strong>
次湖中女神
<small>誘引之者</small>
</div>


<div class="rs-kuso-item">
🐢🐶 揹狗騎龜負重前行了
<strong><?=n($todayKuso['burden_count']??0)?></strong>
次
<small>靈龜＋黑死獸</small>
</div>


<div class="rs-kuso-item rs-kuso-wide">
👾 還遇到了
<strong><?=n($todayKuso['mystery_count']??0)?></strong>
次神秘的生物
<small>OTHERS...</small>
</div>

</div>

</div></div></header>

<section class="rs-section">
<div class="rs-head"><div>
<h2>👾 Boss 生態</h2>
<div class="rs-note">
<!-- BOSS_INFERRED_DEFEAT_V3_WEB -->
平均擊破時間依本站觀測紀錄估算。
</div>
</div></div>

<div class="rs-tablewrap">
<table class="rs-table" data-boss-sort>
<thead><tr>
<th class="rs-sort" data-sort-key="boss" data-sort-type="text">Boss <span class="rs-sortmark">↕</span></th>
<th class="num rs-sort" data-sort-key="rarity" data-sort-type="number">星等 <span class="rs-sortmark">↕</span></th>
<th class="rs-sort" data-sort-key="tier" data-sort-type="text">渦等 <span class="rs-sortmark">↕</span></th>
<th class="num rs-sort is-active" data-sort-key="raid_count" data-sort-type="number" data-sort-dir="desc">出現 <span class="rs-sortmark">↓</span></th>
<th class="num rs-sort" data-sort-key="effective" data-sort-type="number">平均參與人數 <span class="rs-sortmark">↕</span></th>
<th class="num rs-sort" data-sort-key="hp" data-sort-type="number">HP <span class="rs-sortmark">↕</span></th>
<th class="num rs-sort" data-sort-key="defeat" data-sort-type="number">公開平均擊破 <span class="rs-sortmark">↕</span></th>
</tr></thead>

<tbody><?php foreach($bossRows as $r):
$tier=str_replace('涡','渦',(string)($r['whirlpool_tier']??''));
$rarity=(int)($r['rarity']??0);
?>
<tr
 data-boss="<?=h($r['boss'])?>"
 data-rarity="<?=h($rarity)?>"
 data-tier="<?=h($tier)?>"
 data-raid-count="<?=h($r['raid_count'])?>"
 data-effective="<?=h($r['avg_effective_participants'])?>"
 data-hp="<?=h($r['avg_hp_max'])?>"
 data-defeat="<?=h(
    ($r['avg_defeat_seconds']??null)!==null
    ? $r['avg_defeat_seconds']
    : ($r['avg_observed_seconds']??'')
)?>"
>
<td><?=h($r['boss'])?></td>
<td class="num"><?=$rarity>0?'⭐'.h($rarity):'—'?></td>
<td><?=h($tier!==''?$tier:'—')?></td>
<td class="num"><?=n($r['raid_count'])?></td>
<td class="num"><?=n($r['avg_effective_participants'],0)?></td>
<td class="num"><?=n($r['avg_hp_max'])?></td>
<td
 class="num"
 title="<?=h(
     ((int)($r['defeated_count']??0)>0)
     ? '依 '.n($r['defeated_count']).' 場已擊破 Raid 計算'
     : '尚無新制擊破樣本'
 )?>"
>
<?php
// RAID_STATS_RESTORE_OBSERVED_TIME_V1
// BOSS_DEFEAT_TIME_DISPLAY_FALLBACK_V1
$hasDefeatTime =
    isset($r['avg_defeat_seconds'])
    && $r['avg_defeat_seconds'] !== null
    && $r['avg_defeat_seconds'] !== '';

if($hasDefeatTime){
    echo dur(
        $r['avg_defeat_seconds']
    );
}else{
    $fallback =
        $r['avg_observed_seconds']
        ?? null;

    echo (
        $fallback !== null
        && $fallback !== ''
    )
        ? '≈'.dur($fallback)
        : '—';
}
?>
</td>
</tr>
<?php endforeach;?></tbody>
</table>
</div>
</section>


<!-- RAID_STATS_FIRST_RATE_FRACTION_V1 -->
<!-- RAID_STATS_POINT_UI_V2 -->

<section class="rs-section">

<div class="rs-head">
<div>

<h2>🏆 玩家排行榜</h2>

<div class="rs-note">
新版以 POINT 為主要輸出指標。
平均分不含 0，平均類榜單至少 10 場。
2026/09/22 10:00 前的舊制傷害統計將移至封存區。
</div>

</div>
</div>


<div
 class="rs-tabs"
 data-tabs
>

<?php foreach([
    'total_point'=>'總分',
    'avg_point'=>'平均分',
    'max_point'=>'單場最高分',
    'first_rate'=>'第一名率',
] as $k=>$v):?>

<button
 type="button"
 data-tab="<?=h($k)?>"
 class="<?=$k==='total_point'?'on':''?>"
>
<?=h($v)?>
</button>

<?php endforeach;?>

</div>



<!-- RAID_STATS_SELF_LOOKUP_POINT_V2_UI -->

<div class="rs-selflookup">

<form
 method="get"
 action=""
 class="rs-selflookup-form"
>

<input
 type="hidden"
 name="range"
 value="<?=h($range)?>"
>

<?php if(
    $isRaidCareerAdmin
    && $careerRequested !== ''
):?>

<input
 type="hidden"
 name="career_player"
 value="<?=h($careerRequested)?>"
>

<?php endif;?>


<input
 type="search"
 name="rank_player"
 value="<?=h($rankLookupPlayer)?>"
 list="raid-rank-player-list"
 placeholder="🔎 輸入玩家名稱查排名"
 autocomplete="off"
 spellcheck="false"
 class="rs-selflookup-input"
>

<button
 type="submit"
 class="rs-selflookup-button"
>
🔍 自查
</button>

</form>


<?php if($me!==''):?>

<a
 class="rs-selflookup-me"
 href="<?=h(
     '?range='
     .$range
     .$careerRangeSuffix
     .'&rank_player='
     .rawurlencode($me)
 )?>"
>
🙋 查自己
</a>

<?php endif;?>


<?php if($rankLookupPlayer!==''):?>

<a
 class="rs-selflookup-me"
 href="<?=h(
     '?range='
     .$range
     .$careerRangeSuffix
 )?>"
>
清除
</a>

<?php endif;?>


<datalist id="raid-rank-player-list">

<?php foreach(
    $rankLookupOptions
    as $rankLookupOption
):?>

<option
 value="<?=h($rankLookupOption)?>"
></option>

<?php endforeach;?>

</datalist>

</div>


<?php if($rankLookupPlayer!==''):?>


<?php if($rankLookupPlayerRow===null):?>

<div class="rs-selflookup-summary">

<div class="rs-selflookup-title">

<strong>
<?=h($rankLookupPlayer)?>
</strong>

｜<?=h($rangeLabel)?>

</div>

<div class="rs-note">
此區間找不到這名玩家的 Raid 紀錄。
</div>

</div>


<?php else:?>

<?php
$rankLookupJoined = (int)(
    $rankLookupPlayerRow[
        'raids_joined'
    ]
    ?? 0
);

$rankLookupUiMetrics = [
    'total_point' => '總分',
    'avg_point'   => '平均分',
    'max_point'   => '單場最高分',
    'first_rate'  => '第一名率',
];
?>


<div class="rs-selflookup-summary">

<div class="rs-selflookup-title">

<strong>
<?=h($rankLookupPlayer)?>
</strong>

｜<?=h($rangeLabel)?>

｜參戰
<?=n($rankLookupJoined)?>
場

</div>


<div class="rs-selflookup-grid">

<?php foreach(
    $rankLookupUiMetrics
    as $metric => $label
):?>

<?php
$lookupStat =
    $rankLookupPointStats[
        $metric
    ]
    ?? null;

$needsTen =
    in_array(
        $metric,
        [
            'avg_point',
            'first_rate',
        ],
        true
    );

$eligible =
    !$needsTen
    || $rankLookupJoined >= 10;
?>


<div class="rs-selflookup-item">

<span>
<?=h($label)?>
</span>


<?php if(!$eligible):?>

<strong>—</strong>

<small>
未滿 10 場
</small>


<?php elseif($lookupStat===null):?>

<strong>—</strong>

<small>
無排名資料
</small>


<?php else:?>

<?php
$lookupRow =
    $lookupStat['row'];

$lookupValue =
    $lookupRow[$metric]
    ?? 0;

if($metric==='first_rate'){

    $lookupValueText =
        n($lookupValue,1)
        .'%';

}elseif($metric==='avg_point'){

    $lookupValueText =
        n($lookupValue);

}else{

    $lookupValueText =
        n($lookupValue);
}

$lookupTotal =
    count(
        $rankLookupPointBoardsAll[
            $metric
        ]
        ?? []
    );
?>


<strong>
<?=h($lookupValueText)?>
</strong>

<small>
排名 #
<?=n($lookupStat['rank'])?>
／<?=n($lookupTotal)?>
</small>

<?php endif;?>


</div>

<?php endforeach;?>

</div>

</div>


<?php endif;?>

<?php endif;?>


<?php foreach($boards as $key=>$rows):?>

<?php
if(!in_array(
    $key,
    [
        'total_point',
        'avg_point',
        'max_point',
        'first_rate',
    ],
    true
)){
    continue;
}
?>

<div
 data-board="<?=h($key)?>"
 <?=$key==='total_point'?'':'hidden'?>
>

<div class="rs-tablewrap">

<table class="rs-table">

<thead>
<tr>
<th>排名</th>
<th>玩家</th>
<th class="num">數值</th>

<?php if($key==='max_point'):?>
<th>Boss</th>
<th>日期</th>
<?php endif;?>

</tr>
</thead>

<tbody>

<?php foreach($rows as $i=>$r):?>

<tr>

<td class="<?=$i<3?'ranktop':''?>">
#<?=$i+1?>
</td>

<td>
<?=h($r['player_name'])?>
</td>

<td class="num">

<?php

if($key==='first_rate'){

    echo
        n($r[$key]??0,1)
        .'%（'
        .n($r['first_count']??0)
        .'/'
        .n($r['raids_joined']??0)
        .'場）';


}elseif($key==='avg_point'){

    echo
        n($r[$key]??0)
        .'（'
        .n($r['raids_joined']??0)
        .'場）';

}else{

    echo n(
        $r[$key]??0
    );
}

?>

</td>


<?php if($key==='max_point'):?>

<td>
<?=h(
    $r['boss']
    ??'未知 Boss'
)?>
</td>

<td>
<?=h(
    $r['record_date']
    ??'—'
)?>
</td>

<?php endif;?>

</tr>

<?php endforeach;?>

</tbody>
</table>
</div>
</div>

<?php endforeach;?>

</section>




<!-- RAID_STATS_DPM_UI_REBUILD_V1 -->
<!-- RAID_STATS_DPM_UI_V1 -->


<!-- RAID_STATS_AVG_POINT_INTEGER_DAMAGE_TEXT_V2 -->
<!-- RAID_STATS_DAMAGE_ARCHIVE_UI_V4 -->

<details
 class="rs-section"
 data-damage-archive
>

<summary
 style="
   cursor:pointer;
   user-select:none;
 "
>

<div
 style="
   display:flex;
   justify-content:space-between;
   align-items:center;
   gap:12px;
 "
>

<div>

<strong style="font-size:20px">
💥 舊制傷害統計
</strong>

<span
 style="
   margin-left:7px;
   color:#f0c674;
   font-size:11px;
   font-weight:800;
 "
>
已封存
</span>

<div class="rs-note">
2026/09/22 10:00 維修後，
官方已不再提供 Damage 資料，
因此傷害相關統計停止更新。
</div>

</div>

<span
 class="rs-note"
 style="white-space:nowrap"
>
點擊展開
</span>

</div>

</summary>


<div
 class="rs-note"
 style="
   margin:12px 0;
   padding:10px 12px;
   border:1px solid rgba(240,198,116,.18);
   border-radius:8px;
   background:rgba(240,198,116,.05);
 "
>

資料截止：
<strong>2026/09/22 10:00</strong>

｜舊制總傷害：
<strong data-legacy-total-damage>—</strong>

<br>

固定歷史快照，
不受上方「全部／30天／7天」篩選影響。

</div>


<div data-legacy-damage-ranking></div>

<div
 data-legacy-dpm
 style="margin-top:14px"
></div>

</details>


<script
 src="/assets/js/raid_damage_archive_20260922.js?v=20260928"
></script>


<script>
/* RAID_STATS_DAMAGE_ARCHIVE_UI_V4 */
(()=>{
'use strict';

const details =
    document.querySelector(
        '[data-damage-archive]'
    );

if(!details){
    return;
}

let mounted=false;


function parseSection(html){

    const t=
        document.createElement(
            'template'
        );

    t.innerHTML=
        String(html||'').trim();

    const section=
        t.content.querySelector(
            'section'
        );

    if(!section){
        return null;
    }

    section.querySelectorAll(
        'script'
    ).forEach(
        node=>node.remove()
    );

    return section;
}


function bindTabs(
    root,
    buttonSelector,
    boardSelector,
    buttonKey,
    boardKey,
    initial
){

    const buttons=[
        ...root.querySelectorAll(
            buttonSelector
        )
    ];

    const boards=[
        ...root.querySelectorAll(
            boardSelector
        )
    ];


    function select(key){

        buttons.forEach(button=>{

            button.classList.toggle(
                'on',
                button.dataset[
                    buttonKey
                ]===key
            );
        });


        boards.forEach(board=>{

            board.hidden=
                board.dataset[
                    boardKey
                ]!==key;
        });
    }


    buttons.forEach(button=>{

        button.addEventListener(
            'click',
            ()=>select(
                button.dataset[
                    buttonKey
                ]
            )
        );
    });


    if(buttons.length){
        select(initial);
    }
}


function mountArchive(){

    if(mounted){
        return;
    }

    mounted=true;


    const data=
        window.ULGG_RAID_DAMAGE_ARCHIVE;

    if(!data){
        return;
    }


    const total=
        document.querySelector(
            '[data-legacy-total-damage]'
        );

    if(total){

        total.textContent=
            data.totalDamage
            ||'—';
    }


    /*
     * Damage leaderboards
     */
    const ranking=
        parseSection(
            data.rankingHtml
        );

    if(ranking){

        const keep=
            new Set([
                'total_damage',
                'avg_damage',
                'max_damage',
                'solo_rate',
            ]);


        ranking.querySelectorAll(
            '[data-tab]'
        ).forEach(button=>{

            if(
                !keep.has(
                    button.dataset.tab
                )
            ){
                button.remove();
            }
        });


        ranking.querySelectorAll(
            '[data-board]'
        ).forEach(board=>{

            if(
                !keep.has(
                    board.dataset.board
                )
            ){
                board.remove();
            }
        });


        const head=
            ranking.querySelector(
                '.rs-head'
            );

        if(head){
            head.remove();
        }


        document.querySelector(
            '[data-legacy-damage-ranking]'
        )?.appendChild(
            ranking
        );


        bindTabs(
            ranking,
            '[data-tab]',
            '[data-board]',
            'tab',
            'board',
            'total_damage'
        );
    }


    /*
     * DPM archive
     */
    const dpm=
        parseSection(
            data.dpmHtml
        );

    if(dpm){

        document.querySelector(
            '[data-legacy-dpm]'
        )?.appendChild(
            dpm
        );


        bindTabs(
            dpm,
            '[data-dpm-tab]',
            '[data-dpm-board]',
            'dpmTab',
            'dpmBoard',
            'avg_dpm'
        );
    }
}


details.addEventListener(
    'toggle',
    ()=>{

        if(details.open){
            mountArchive();
        }
    }
);

})();
</script>




<!-- RAID_SUPPORT_ARCHIVE_COLLAPSE_UI_V1 -->

<details
 class="rs-section rs-beta"
 data-raid-support-archive-details
>

<summary
 style="
   cursor:pointer;
   user-select:none;
 "
>

<span
 style="
   display:flex;
   justify-content:space-between;
   gap:12px;
   align-items:center;
   width:100%;
 "
>

<span>

<strong
 style="
   font-size:20px;
 "
>
🧪 異常狀態-輔助傾向排行榜
</strong>

<span class="rs-beta-pill">
BETA
</span>

<span
 style="
   margin-left:6px;
   color:#f0c674;
   font-size:12px;
   font-weight:700;
 "
>
已封存
</span>

<span
 class="rs-note"
 style="
   display:block;
   margin-top:5px;
 "
>
2026/09/22 10:00 維修後官方不再提供 Boss 異常狀態資料，因此停止更新。
</span>

</span>

<span
 class="rs-note"
 style="white-space:nowrap"
>
點擊展開
</span>

</span>

</summary>

<!--
  保留隱藏標題，
  相容既有 support-player jump JS。
-->
<h2 style="display:none">
異常狀態-輔助傾向排行榜
</h2>

<div
 class="rs-note"
 style="
   margin:12px 0 8px;
   padding:9px 11px;
   border:1px solid rgba(240,198,116,.18);
   border-radius:8px;
   background:rgba(240,198,116,.05);
 "
>
封存快照：2026/09/22 10:00 前資料。
此區已改為靜態 JS，不再查詢資料庫，
也不受上方「全部／30天／7天」篩選影響。
</div>

<div data-support-archive-target></div>

</details>


<details
 class="rs-section rs-beta"
 style="
   border-color:rgba(185,126,255,.30)
 "
 data-raid-specialty-archive-details
>

<summary
 style="
   cursor:pointer;
   user-select:none;
 "
>

<span
 style="
   display:flex;
   justify-content:space-between;
   gap:12px;
   align-items:center;
   width:100%;
 "
>

<span>

<strong
 style="
   font-size:20px;
 "
>
🎭 玩家擅長技能
</strong>

<span class="rs-beta-pill">
BETA
</span>

<span
 style="
   margin-left:6px;
   color:#f0c674;
   font-size:12px;
   font-weight:700;
 "
>
已封存
</span>

<span
 class="rs-note"
 style="
   display:block;
   margin-top:5px;
 "
>
2026/09/22 10:00 維修後官方不再提供 Boss 異常狀態資料，因此停止更新。
</span>

</span>

<span
 class="rs-note"
 style="white-space:nowrap"
>
點擊展開
</span>

</span>

</summary>

<div
 class="rs-note"
 style="
   margin:12px 0 8px;
   padding:9px 11px;
   border:1px solid rgba(185,126,255,.18);
   border-radius:8px;
   background:rgba(185,126,255,.05);
 "
>
封存快照：2026/09/22 10:00 前資料。
搜尋、狀態圖示與 Top 20 顯示仍保留，
但不再掃描 support DB。
</div>

<div data-specialty-archive-target></div>

</details>


<script>
window.ULGG_RAID_SUPPORT_ARCHIVE_MODE =
    <?=json_encode(
        $isRaidStatsAdmin
        ? 'admin'
        : 'public',
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    )?>;
</script>

<script
 src="/assets/js/raid_support_archive_20260922.js?v=1"
></script>

<script>
/* RAID_SUPPORT_ARCHIVE_STATIC_RENDER_V1 */
(()=>{
'use strict';

const archive =
    window.ULGG_RAID_SUPPORT_ARCHIVE;

const mode =
    window.ULGG_RAID_SUPPORT_ARCHIVE_MODE
    || 'public';


if(!archive){
    return;
}


const snapshot =
    archive[mode]
    || archive.public
    || archive.admin;


if(!snapshot){
    return;
}


function mount(
    selector,
    html
){

    const target =
        document.querySelector(
            selector
        );

    if(
        !target
        || !html
    ){
        return;
    }


    const template =
        document.createElement(
            'template'
        );

    template.innerHTML =
        String(html).trim();


    const section =
        template.content.querySelector(
            'section'
        );

    if(!section){
        target.textContent =
            '封存資料載入失敗。';

        return;
    }


    /*
     * 外層 details 已經負責顯示：
     *
     * - 標題
     * - 已封存
     * - 原因
     *
     * 所以移除舊 snapshot 的 h2，
     * 但保留原 rs-note / 搜尋框。
     */
    const oldTitle =
        section.querySelector(
            '.rs-head h2'
        );

    if(oldTitle){
        oldTitle.remove();
    }


    const children =
        [
            ...section.childNodes
        ];


    target.replaceChildren(
        ...children
    );
}


mount(
    '[data-support-archive-target]',
    snapshot.supportHtml
);


mount(
    '[data-specialty-archive-target]',
    snapshot.specialtyHtml
);

})();
</script>




<div class="rs-two"><section class="rs-section"><h2>🌀 渦主排行榜</h2><div class="rs-tablewrap"><table class="rs-table"><thead><tr><th>排名</th><th>渦主</th><th class="num">開渦</th><th class="num">平均參與人數</th><th>最常開</th></tr></thead><tbody><?php foreach($founderBoard as $i=>$r):?><tr><td class="<?=$i<3?'ranktop':''?>">#<?=$i+1?></td><td><?=h($r['founder'])?></td><td class="num"><?=n($r['raids_opened'])?></td><td class="num"><?=n($r['avg_participants'],0)?></td><td><?=h($fav[$r['founder']]??'—')?></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="rs-section"><h2>🎖 趣味紀錄</h2><div class="rs-fun"><?php foreach($fun as [$icon,$title,$r,$key]):?><div class="rs-card"><div style="font-size:22px"><?=h($icon)?></div><div class="rs-label"><?=h($title)?></div><div class="who"><?=h($r['player_name']??$r['founder']??'—')?></div><div class="rs-note"><?php if(!$r) echo '—'; elseif($key==='idle_rate') echo n($r[$key],1).'%'; elseif($key==='avg_participants') {
    echo '平均 '.n($r[$key],0).' 人';
} elseif($key==='daily_unique_players') {
    echo n($r[$key]).' 人';
} elseif($key==='daily_defeated_bosses') {
    echo n($r[$key]).' 隻';
} else {
    echo n($r[$key]);
}?></div></div><?php endforeach;?></div></section></div>

<section
 class="rs-section rs-personal"
 data-raid-career
>

<div class="rs-head">

<div>

<h2>
🙋 我的 Raid 生涯

<?php if($isRaidCareerAdmin):?>

<span class="rs-admin-career-badge">
ADMIN
</span>

<?php endif;?>

</h2>


<div class="rs-note">

<?php if($careerPlayer===''):?>

登入 UL.GG 後顯示個人統計。

<?php elseif($careerIsAdminView):?>

管理員檢視：
<strong>
<?=h($careerPlayer)?>
</strong>
｜<?=h($rangeLabel)?>

<?php else:?>

<?=h($careerPlayer)?>
｜<?=h($rangeLabel)?>

<?php endif;?>

</div>

</div>


<?php if($isRaidCareerAdmin):?>

<div class="rs-admin-career-tools">

<form
 method="get"
 action=""
 class="rs-admin-career-form"
>

<input
 type="hidden"
 name="range"
 value="<?=h($range)?>"
>

<input
 type="search"
 name="career_player"
 value="<?=h(
     $careerRequested !== ''
        ? $careerRequested
        : $careerPlayer
 )?>"
 list="raid-career-player-list"
 placeholder="🔎 輸入玩家名稱"
 autocomplete="off"
 spellcheck="false"
 class="rs-admin-career-input"
 aria-label="管理員查看玩家 Raid 生涯"
>

<button
 type="submit"
 class="rs-admin-career-button"
>
查看玩家
</button>


<?php if($careerRequested!==''):?>

<a
 href="<?=h(
     '?range='.$range
 )?>"
 class="rs-admin-career-reset"
>
回到自己
</a>

<?php endif;?>

</form>


<datalist
 id="raid-career-player-list"
>

<?php foreach(
    $careerPlayerOptions
    as $careerOption
):?>

<?php
$careerOptionName = trim(
    (string)(
        $careerOption[
            'player_name'
        ]
        ?? ''
    )
);
?>

<?php if($careerOptionName!==''):?>

<option
 value="<?=h($careerOptionName)?>"
></option>

<?php endif;?>

<?php endforeach;?>

</datalist>

</div>

<?php endif;?>

</div>


<?php if($careerPlayer===''):?>

<div class="rs-empty">
此區僅登入使用者可見。
</div>


<?php elseif($my===null):?>

<div class="rs-empty">

<strong>
<?=h($careerPlayer)?>
</strong>

在「<?=h($rangeLabel)?>」範圍內
目前沒有 Raid 紀錄。

</div>


<?php else:

$joined = (int)(
    $my['raids_joined']
    ?? 0
);

/*
 * RAID_CAREER_REAL_JOIN_COUNT_V1
 *
 * 個人生涯「參戰數」應顯示實際 Raid 紀錄數：
 *   COUNT(*) = raids_joined
 *
 * 不使用 effective_raids_joined，
 * 因為那會排除：
 * - POINT = 0
 * - 自己開的渦
 */
$displayJoined = (int)(
    $my['raids_joined']
    ?? 0
);


?>


<div class="rs-mykpi">

<div class="rs-card">
<span class="rs-label">
參戰數
</span>
<strong class="rs-value">
<?=n($displayJoined)?>
</strong>
</div>

<div class="rs-card">
<span class="rs-label">
總分
</span>
<strong class="rs-value">
<?=n(
    $my['total_point']
    ?? 0
)?>
</strong>
</div>


<div class="rs-card">
<span class="rs-label">
平均分
</span>
<strong class="rs-value">
<?=n(
    $my['avg_point']
    ?? null
)?>
</strong>
</div>












<div class="rs-card">
<span class="rs-label">
單場最高分
</span>
<strong class="rs-value">
<?=n(
    $my[
        'max_point'
    ]
    ?? 0
)?>
</strong>
</div>

</div>


<div class="rs-two">

<div class="rs-section">

<h3>
🤝 我的最佳戰友
</h3>

<div class="rs-note">
只計戰友 POINT&gt;0 的同場紀錄，
無最低同場次數限制
</div>

<div class="rs-tablewrap">
<table class="rs-table">

<thead>
<tr>
<th>玩家</th>
<th class="num">同場</th>
</tr>
</thead>

<tbody>

<?php foreach($allies as $r):?>

<tr>

<td>
<?=h($r['player_name'])?>
</td>

<td class="num">
<?=n($r['together_count'])?>
</td>

</tr>

<?php endforeach;?>

</tbody>

</table>
</div>

</div>


<div class="rs-section">

<h3>
👾 我的 Boss 偏好
</h3>

<div class="rs-note">
點表頭可排序
</div>

<div class="rs-tablewrap">
<table class="rs-table" data-my-boss-sort>

<thead>
<tr>

<th
 class="rs-sort"
 data-sort-key="boss"
 data-sort-type="text"
>
Boss
<span class="rs-sortmark">↕</span>
</th>

<th
 class="num rs-sort is-active"
 data-sort-key="raid_count"
 data-sort-type="number"
 data-sort-dir="desc"
>
場次
<span class="rs-sortmark">↓ DESC</span>
</th>

<th
 class="num rs-sort"
 data-sort-key="top10_rate"
 data-sort-type="number"
>
TOP10率
<span class="rs-sortmark">↕</span>
</th>

</tr>
</thead>

<tbody>

<?php foreach($myBoss as $r):?>

<tr
 data-boss="<?=h(
     (string)(
         $r['boss']
         ?? ''
     )
 )?>"
 data-raid-count="<?=h(
     (string)(
         (int)(
             $r['raid_count']
             ?? 0
         )
     )
 )?>"
 data-top10-rate="<?=h(
     (string)(
         (
             (int)(
                 $r['raid_count']
                 ?? 0
             ) > 0
         )
         ? (
             (
                 (float)(
                     $r['top10_count']
                     ?? 0
                 )
                 /
                 (int)$r['raid_count']
             )
             * 100
           )
         : 0
     )
 )?>"
>

<td>
<?=h($r['boss'])?>
</td>

<td class="num">
<?=n($r['raid_count'])?>
</td>

<td class="num">
<?=h(
    pct(
        $r['top10_count'],
        $r['raid_count']
    )
)?>
</td>

</tr>

<?php endforeach;?>

</tbody>

</table>
</div>

</div>

</div>

<?php endif;?>

</section>
</div></section></div>
<script>(()=>{'use strict';const t=document.querySelector('[data-tabs]');if(!t)return;const bs=[...document.querySelectorAll('[data-board]')];t.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>{t.querySelectorAll('[data-tab]').forEach(x=>x.classList.toggle('on',x===b));bs.forEach(x=>x.hidden=x.dataset.board!==b.dataset.tab)}));})();</script>

<script>
(()=>{
'use strict';

const table=document.querySelector('[data-boss-sort]');
if(!table)return;

const tbody=table.querySelector('tbody');
const headers=[...table.querySelectorAll('th[data-sort-key]')];

// BOSS_SORT_DATA_ATTR_FIX_V1
const valueFor=(row,key,type)=>{
  /*
   * data-sort-key="raid_count"
   * 對應 HTML data-raid-count。
   *
   * 不直接用 dataset[key]，因為：
   * data-raid-count -> dataset.raidCount
   * 而不是 dataset.raid_count。
   */
  const attr=
    'data-'+String(key).replace(/_/g,'-');

  const raw=
    row.getAttribute(attr) ?? '';

  if(type==='number'){
    const value=Number(raw);
    return Number.isFinite(value)
      ? value
      : Number.NEGATIVE_INFINITY;
  }

  return String(raw);
};

headers.forEach(header=>{
  header.addEventListener('click',()=>{
    const key=header.dataset.sortKey;
    const type=header.dataset.sortType || 'text';

    const previous=header.dataset.sortDir;
    const dir=previous==='asc' ? 'desc' : 'asc';

    headers.forEach(item=>{
      item.classList.remove('is-active');
      item.dataset.sortDir='';

      const mark=item.querySelector('.rs-sortmark');
      if(mark)mark.textContent='↕';
    });

    header.classList.add('is-active');
    header.dataset.sortDir=dir;

    const mark=header.querySelector('.rs-sortmark');
    if(mark)mark.textContent=dir==='asc' ? '↑' : '↓';

    const rows=[...tbody.querySelectorAll('tr')];

    rows.sort((a,b)=>{
      const av=valueFor(a,key,type);
      const bv=valueFor(b,key,type);

      let result=0;

      if(type==='number'){
        result=av-bv;
      }else{
        result=av.localeCompare(
          bv,
          'zh-Hant',
          {numeric:true,sensitivity:'base'}
        );
      }

      return dir==='asc' ? result : -result;
    });

    rows.forEach(row=>tbody.appendChild(row));
  });
});
})();
</script>



<script>
// SUPPORT_BOARDS_V2
(()=>{
'use strict';

const tabs=document.querySelector(
  '[data-support-tabs]'
);

if(!tabs)return;

const boards=[
  ...document.querySelectorAll(
    '[data-support-board]'
  )
];

tabs.querySelectorAll(
  '[data-support-tab]'
).forEach(button=>{

  button.addEventListener(
    'click',
    ()=>{

      const key=
        button.dataset.supportTab;

      tabs.querySelectorAll(
        '[data-support-tab]'
      ).forEach(item=>{
        item.classList.toggle(
          'on',
          item===button
        );
      });

      boards.forEach(board=>{
        board.hidden=
          board.dataset.supportBoard
          !==key;
      });
    }
  );

});

})();
</script>


<script src="/assets/js/raid_status_icons.js"></script>

<script>
// SUPPORT_STATUS_SPECIALTY_V2
(()=>{
'use strict';

function resolveStatusIcon(label){

    const icons=
        window.RAID_STATUS_ICONS
        ||{};

    const clean=
        String(label||'')
        .replace(/\.\.\.$/,'');

/*
 * STATUS_LABEL_COMPAT_V1
 * 舊版頁面/cache 可能仍有這些文字。
 */
const aliases={
    '弱':'低',
    '能力低下':'低',
    '麻痺':'麻',
    '麻痹':'麻',
    '自壞':'壞',
    '聖痕':'聖',
    '操想':'操',
    '標靶':'標',
    '斷絕':'斷',
    '狂戰士':'狂',
    '暈眩':'暈',
    '咒縛':'咒',
    '恐懼':'恐'
};

const normalized=
    aliases[clean]
    ||clean;

    const exact={
        '毒':'poison',
        '猛毒':'poison2',
        '麻':'mahi',

        '狂':'bers',
        '暈':'stun',
        '封':'huin',
        '壞':'jikai',
        '不':'immo',
        '恐':'scare',
        '再':'rege',
        '咒':'bind',
        '混':'chaos',
        '聖':'stigma',
        '低':'dbuff',

        '棍攻':'sticka',
        '棍防':'stickd',

        '臨':'critical',
        '操':'control',
        '標':'target',
        '斷':'dark'
    };

    if(exact[normalized]){
        return icons[
            exact[normalized]
        ]||null;
    }

    if(/^詛\d*$/.test(normalized)){
        return icons.curse||null;
    }

    const patterns=[
        [/^攻\+(\d+)$/,'atkB'],
        [/^攻-(\d+)$/,'atkD'],
        [/^防\+(\d+)$/,'defB'],
        [/^防-(\d+)$/,'defD'],
        [/^移\+(\d+)$/,'movB'],
        [/^移-(\d+)$/,'movD']
    ];

    for(const [pattern,type] of patterns){

        const match=
            normalized.match(pattern);

        if(!match){
            continue;
        }

        const level=
            Number(match[1]);

        const group=
            icons[type];

        if(
            group
            && typeof group==='object'
        ){
            return group[level]||null;
        }
    }

    return null;
}

document.querySelectorAll(
    '[data-raid-specialty]'
).forEach(chip=>{

    const label=
        chip.dataset.statusLabel
        ||'';

    const url=
        resolveStatusIcon(label);

    if(!url){
        return;
    }

    const slot=
        chip.querySelector(
            '[data-specialty-icon]'
        );

    if(!slot){
        return;
    }

    const img=
        document.createElement('img');

    img.src=url;
    img.alt='';
    img.width=24;
    img.height=24;
    img.loading='lazy';

    img.style.width='24px';
    img.style.height='24px';
    img.style.objectFit='contain';

    slot.appendChild(img);
});

})();
</script>


<script>
// SUPPORT_STATUS_SPECIALTY_ALL_V1
// SUPPORT_STATUS_SPECIALTY_SEARCH_V1
// SUPPORT_STATUS_SPECIALTY_STATUS_SEARCH_V1
(()=>{
'use strict';

const table=document.querySelector(
    '[data-specialty-table]'
);

if(!table)return;

const rows=[
    ...table.querySelectorAll(
        '[data-specialty-row]'
    )
];

const pager=document.querySelector(
    '[data-specialty-pager]'
);

const summary=document.querySelector(
    '[data-specialty-summary]'
);

const search=document.querySelector(
    '[data-specialty-search]'
);

if(!pager || !rows.length){
    return;
}

const pageSize=20;
let page=1;
let query='';


function normalize(value){
    return String(value||'')
        .trim()
        .toLocaleLowerCase();
}


function getFilteredRows(){

    if(!query){
        return rows;
    }

    return rows.filter(row=>{

        const name=normalize(
            row.dataset.playerName
            ||''
        );

        /*
         * SUPPORT_STATUS_SPECIALTY_STATUS_SEARCH_V1
         *
         * 同時搜尋：
         * - 玩家名稱
         * - #1～#5 擅長狀態
         *
         * 直接讀現有 data-status-label，
         * 不增加 backend / SQL 負擔。
         */
        const statuses=[
            ...row.querySelectorAll(
                '[data-raid-specialty]'
            )
        ].map(chip=>
            normalize(
                chip.dataset.statusLabel
                ||''
            )
        );

        return (
            name.includes(query)
            ||
            statuses.some(
                status=>
                    status.includes(query)
            )
        );
    });
}


function button(
    label,
    target,
    active=false,
    disabled=false
){

    const el=document.createElement(
        disabled?'span':'button'
    );

    el.textContent=label;

    el.style.minWidth='34px';
    el.style.padding='6px 9px';
    el.style.borderRadius='7px';
    el.style.border=
        '1px solid rgba(255,255,255,.10)';

    el.style.background=
        active
        ?'rgba(184,190,255,.16)'
        :'rgba(255,255,255,.03)';

    el.style.color=
        active
        ?'#fff'
        :'var(--muted)';

    el.style.fontWeight=
        active?'800':'600';

    if(disabled){

        el.style.opacity='.35';

    }else{

        el.type='button';
        el.style.cursor='pointer';

        el.addEventListener(
            'click',
            ()=>{
                page=target;
                render();
            }
        );
    }

    return el;
}


function render(){

    const filtered=
        getFilteredRows();

    const total=
        filtered.length;

    const pages=Math.max(
        1,
        Math.ceil(
            total/pageSize
        )
    );

    if(page>pages){
        page=pages;
    }

    if(page<1){
        page=1;
    }

    const start=
        (page-1)*pageSize;

    const end=Math.min(
        start+pageSize,
        total
    );

    /*
     * 先全部藏掉，
     * 再只顯示本次搜尋結果的當前頁
     */
    rows.forEach(
        row=>{
            row.hidden=true;
        }
    );

    filtered.forEach(
        (row,index)=>{
            row.hidden=
                index<start
                ||index>=end;
        }
    );


    if(summary){

        if(total===0){

            summary.textContent=
                query
                ?'找不到符合的玩家'
                :'目前沒有玩家資料';

        }else if(query){

            summary.textContent=
                `搜尋結果 ${total} 名`
                +`｜第 ${page} / ${pages} 頁`
                +`｜顯示 ${start+1}–${end}`;

        }else{

            summary.textContent=
                `共 ${total} 名玩家`
                +`｜第 ${page} / ${pages} 頁`
                +`｜顯示 ${start+1}–${end}`;
        }
    }


    pager.replaceChildren();

    if(total===0){
        return;
    }


    pager.appendChild(
        button(
            '‹',
            page-1,
            false,
            page<=1
        )
    );


    let first=Math.max(
        1,
        page-2
    );

    let last=Math.min(
        pages,
        page+2
    );


    if(first>1){

        pager.appendChild(
            button(
                '1',
                1,
                page===1
            )
        );

        if(first>2){

            pager.appendChild(
                button(
                    '…',
                    1,
                    false,
                    true
                )
            );
        }
    }


    for(
        let i=first;
        i<=last;
        i++
    ){

        pager.appendChild(
            button(
                String(i),
                i,
                i===page
            )
        );
    }


    if(last<pages){

        if(last<pages-1){

            pager.appendChild(
                button(
                    '…',
                    pages,
                    false,
                    true
                )
            );
        }

        pager.appendChild(
            button(
                String(pages),
                pages,
                page===pages
            )
        );
    }


    pager.appendChild(
        button(
            '›',
            page+1,
            false,
            page>=pages
        )
    );
}



// SUPPORT_STATUS_SPECIALTY_CLICK_SEARCH_V1
document.querySelectorAll(
    '[data-raid-specialty]'
).forEach(chip=>{

    chip.style.cursor='pointer';

    chip.setAttribute(
        'role',
        'button'
    );

    chip.setAttribute(
        'tabindex',
        '0'
    );

    function applyStatusSearch(){

        if(!search){
            return;
        }

        const label=
            String(
                chip.dataset.statusLabel
                ||''
            ).trim();

        if(!label){
            return;
        }

        search.value=label;
        query=normalize(label);
        page=1;

        render();

        search.focus();
    }

    chip.addEventListener(
        'click',
        applyStatusSearch
    );

    chip.addEventListener(
        'keydown',
        event=>{

            if(
                event.key==='Enter'
                ||event.key===' '
            ){
                event.preventDefault();
                applyStatusSearch();
            }
        }
    );
});

if(search){

    search.addEventListener(
        'input',
        ()=>{

            query=normalize(
                search.value
            );

            page=1;
            render();
        }
    );

    /*
     * type=search 在部分瀏覽器按 X
     * 會觸發 search event。
     */
    search.addEventListener(
        'search',
        ()=>{

            query=normalize(
                search.value
            );

            page=1;
            render();
        }
    );
}


render();

})();
</script>


<script>
// SUPPORT_BOARD_PLAYER_TO_SPECIALTY_SEARCH_V1
(()=>{
'use strict';

function initSupportPlayerJump(){

    const search=document.querySelector(
        '[data-specialty-search]'
    );

    if(!search){
        return;
    }

    /*
     * 找「輔助傾向排行榜」section。
     * 不依賴特定 tab，四個榜都一起綁。
     */
    const supportSection=[
        ...document.querySelectorAll(
            '.rs-section'
        )
    ].find(section=>{

        const h2=section.querySelector('h2');

        return (
            h2
            && h2.textContent.includes(
                '輔助傾向排行榜'
            )
        );
    });

    if(!supportSection){
        return;
    }

    supportSection
        .querySelectorAll('tbody tr')
        .forEach(row=>{

            const cells=row.querySelectorAll('td');

            if(cells.length<2){
                return;
            }

            /*
             * 輔助排行榜：
             * 第1欄 = 排名
             * 第2欄 = 玩家
             */
            const playerCell=cells[1];

            const playerName=
                String(
                    playerCell.textContent
                    ||''
                ).trim();

            if(!playerName){
                return;
            }

            playerCell.style.cursor='pointer';
            playerCell.style.textDecoration='underline';
            playerCell.style.textUnderlineOffset='3px';

            playerCell.setAttribute(
                'title',
                `查看 ${playerName} 的擅長狀態`
            );

            playerCell.setAttribute(
                'role',
                'button'
            );

            playerCell.setAttribute(
                'tabindex',
                '0'
            );

            function jump(){

                search.value=playerName;

                /*
                 * 直接觸發現有搜尋邏輯，
                 * 不重寫 pagination/filter。
                 */
                search.dispatchEvent(
                    new Event(
                        'input',
                        {
                            bubbles:true
                        }
                    )
                );

                search.scrollIntoView({
                    behavior:'smooth',
                    block:'center'
                });

                window.setTimeout(
                    ()=>{
                        search.focus();
                        search.select();
                    },
                    350
                );
            }

            playerCell.addEventListener(
                'click',
                jump
            );

            playerCell.addEventListener(
                'keydown',
                event=>{

                    if(
                        event.key==='Enter'
                        || event.key===' '
                    ){
                        event.preventDefault();
                        jump();
                    }
                }
            );
        });
}

if(
    document.readyState==='loading'
){
    document.addEventListener(
        'DOMContentLoaded',
        initSupportPlayerJump
    );
}else{
    initSupportPlayerJump();
}

})();
</script>


<script>
// SUPPORT_SPECIALTY_MAINTENANCE_V1
(()=>{
'use strict';

function applyMaintenanceIcons(){

    document.querySelectorAll(
        '[data-raid-specialty]'
    ).forEach(chip=>{

        const label=String(
            chip.dataset.statusLabel
            ||''
        ).trim();

        if(label!=='延長'){
            return;
        }

        const slot=chip.querySelector(
            '[data-specialty-icon]'
        );

        if(!slot){
            return;
        }

        /*
         * 保證不跟既有 RAID_STATUS_ICONS
         * 產生衝突。
         */
        slot.replaceChildren();

        const icon=document.createElement(
            'span'
        );

        icon.textContent='⏳';
        icon.setAttribute(
            'aria-hidden',
            'true'
        );

        icon.style.display='inline-flex';
        icon.style.width='24px';
        icon.style.height='24px';
        icon.style.alignItems='center';
        icon.style.justifyContent='center';
        icon.style.fontSize='20px';
        icon.style.lineHeight='1';

        slot.appendChild(icon);
    });
}

if(document.readyState==='loading'){
    document.addEventListener(
        'DOMContentLoaded',
        applyMaintenanceIcons
    );
}else{
    applyMaintenanceIcons();
}

})();
</script>


<script>
// SUPPORT_REMOVED_UI_V1
(()=>{
'use strict';

function applyRemovedIcons(){

    document.querySelectorAll(
        '[data-raid-specialty]'
    ).forEach(chip=>{

        const label=String(
            chip.dataset.statusLabel
            ||''
        ).trim();

        if(label!=='解除'){
            return;
        }

        const slot=chip.querySelector(
            '[data-specialty-icon]'
        );

        if(!slot){
            return;
        }

        slot.replaceChildren();

        const icon=document.createElement(
            'span'
        );

        icon.textContent='🧹';

        icon.setAttribute(
            'aria-hidden',
            'true'
        );

        icon.style.display='inline-flex';
        icon.style.width='24px';
        icon.style.height='24px';
        icon.style.alignItems='center';
        icon.style.justifyContent='center';
        icon.style.fontSize='19px';
        icon.style.lineHeight='1';

        slot.appendChild(icon);
    });
}

if(document.readyState==='loading'){
    document.addEventListener(
        'DOMContentLoaded',
        applyRemovedIcons
    );
}else{
    applyRemovedIcons();
}

})();
</script>


<!-- RAID_CAREER_POINT_AND_MYBOSS_SORT_V1 -->

<script>
(()=>{
'use strict';

const table=
  document.querySelector(
    '[data-my-boss-sort]'
  );

if(!table)return;

const tbody=
  table.querySelector('tbody');

const headers=[
  ...table.querySelectorAll(
    'th[data-sort-key]'
  )
];


const valueFor=(
  row,
  key,
  type
)=>{

  const attr=
    'data-'
    +
    String(key)
      .replace(/_/g,'-');

  const raw=
    row.getAttribute(attr)
    ??'';


  if(type==='number'){

    const value=
      Number(raw);

    return Number.isFinite(value)
      ? value
      : Number.NEGATIVE_INFINITY;
  }


  return String(raw);
};


headers.forEach(header=>{

  header.addEventListener(
    'click',
    ()=>{

      const key=
        header.dataset.sortKey;

      const type=
        header.dataset.sortType
        ||'text';

      const isActive=
        header.classList.contains(
          'is-active'
        );

      const previous=
        header.dataset.sortDir
        ||'';


      /*
       * 同欄再次點：
       * ASC <-> DESC
       *
       * 換新欄：
       * numeric 預設 DESC
       * text 預設 ASC
       */
      let dir;

      if(
        isActive
        &&
        (
          previous==='asc'
          ||
          previous==='desc'
        )
      ){

        dir=
          previous==='asc'
          ?'desc'
          :'asc';

      }else{

        dir=
          type==='number'
          ?'desc'
          :'asc';
      }


      headers.forEach(item=>{

        item.classList.remove(
          'is-active'
        );

        item.dataset.sortDir='';

        const mark=
          item.querySelector(
            '.rs-sortmark'
          );

        if(mark){
          mark.textContent='↕';
        }
      });


      header.classList.add(
        'is-active'
      );

      header.dataset.sortDir=
        dir;


      const mark=
        header.querySelector(
          '.rs-sortmark'
        );

      if(mark){

        mark.textContent=
          dir==='asc'
          ?'↑ ASC'
          :'↓ DESC';
      }


      const rows=[
        ...tbody.querySelectorAll(
          'tr'
        )
      ];


      rows.sort((a,b)=>{

        const av=
          valueFor(
            a,
            key,
            type
          );

        const bv=
          valueFor(
            b,
            key,
            type
          );


        let result=0;


        if(type==='number'){

          result=
            av-bv;

        }else{

          result=
            av.localeCompare(
              bv,
              'zh-Hant',
              {
                numeric:true,
                sensitivity:'base'
              }
            );
        }


        return dir==='asc'
          ? result
          : -result;
      });


      rows.forEach(
        row=>
          tbody.appendChild(row)
      );
    }
  );

});

})();
</script>

<?php $pageContent=ob_get_clean(); include __DIR__ . '/../layout/base.php'; ?>
