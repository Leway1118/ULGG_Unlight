<?php
declare(strict_types=1);
require_once __DIR__.'/../config.php';
header('Cache-Control: private, no-store, max-age=0');
require_once __DIR__.'/../lib/_analysis_base.php';
$pdo=$db;
$pageTitleText='最強牌組排名｜強度排行';
$pageTitleFull=$seoTitle=$pageTitleText.' | UL.GG 戰績網';
$activeMenu='strength_rank';
ob_start();
// ⭐ 天數切換（預設改 30 天，比較適合強度排行）
$days = isset($_GET['days']) && is_scalar($_GET['days']) && in_array((int)$_GET['days'], [7, 14, 30], true)
  ? (int)$_GET['days']
  : 14;

// Weekly filter is opt-in; legacy range ranking remains the default.
$weeklyMode = ($_GET['mode']??null) === 'weekly';
$weeklyRule = null;
if ($weeklyMode) {
  $weeklyRule = strLoadWeeklyRule($pdo);
}

$labels = [
  '50-59' => [
    'title' => '50–59 COST',
    'note'  => '低 COST 區間，樣本最多，正式榜門檻 5 場。',
  ],
  '60-69' => [
    'title' => '60–69 COST',
    'note'  => '中高 COST 區間，兼看穩定度與勝率。',
  ],
  '70-80' => [
    'title' => '70–80 COST',
    'note'  => '高 COST 區間，樣本較少，觀察榜可輔助判斷。',
  ],
  '90+' => [
    'title' => '90+ COST',
    'note'  => '無限制高 COST 區間，牌組量少，需留意樣本數。',
  ],
];
if ($weeklyMode) {
  $labels = $weeklyRule === null ? [] : strWeeklyLabels($weeklyRule);
}

/**
 * Read the active Alexandre APPROVED source as Ruleset.
 * Never present a stale or incomplete rule as this week's rule.
 */
function strLoadWeeklyRule(PDO $pdo, ?DateTimeImmutable $now = null): ?array
{
  $tz = new DateTimeZone('Asia/Taipei');
  $now = ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
  // Rules take effect after the announced 10:00 maintenance.
  $asOf = $now->format('H:i') < '10:00' ? $now->modify('-1 day') : $now;
  try {
    $stmt = $pdo->prepare("SELECT id, effective_date, cost1, cost2, cost3, cost4, cost4_operator
      FROM quickmatch_weekly_cost
      WHERE ladder_zone_code = 'ALEXANDRE' AND import_status = 'APPROVED'
        AND matchmaking_enabled = 1
        AND effective_date <= :as_of
      ORDER BY effective_date DESC, id DESC LIMIT 1");
    $stmt->execute([':as_of' => $asOf->format('Y-m-d')]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rule || $rule['effective_date'] < $asOf->modify('-6 days')->format('Y-m-d')) return null;
    $costs = [];
    for ($i = 1; $i <= 4; $i++) {
      $cost = filter_var($rule['cost'.$i], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>999]]);
      if ($cost === false) return null;
      $costs[] = $cost;
      $rule['cost'.$i] = $cost;
    }
    $operator = strtoupper((string)$rule['cost4_operator']);
    if (!in_array($operator, ['EXACT','MIN'], true) || count(array_unique($costs)) !== 4) return null;
    if ($operator === 'MIN' && $costs[3] <= max(array_slice($costs, 0, 3))) return null;
    $rule['cost4_operator'] = $operator;
    return $rule;
  } catch (Throwable $e) {
    error_log('strength_rank: weekly COST unavailable');
    return null;
  }
}

function strWeeklyLabels(array $rule): array
{
  $labels = [];
  for ($i = 1; $i <= 4; $i++) {
    $min = $i === 4 && $rule['cost4_operator'] === 'MIN';
    $labels['weekly-'.$i] = [
      'title' => $rule['cost'.$i] . ($min ? '+' : '') . ' COST',
      'note' => $min ? '符合本週 COST 下限的牌組。' : '符合本週精確 COST 的牌組。',
    ];
  }
  return $labels;
}

function strRankHref(int $days, bool $weekly): string
{
  $params = ['days' => $days];
  if ($weekly) {
    $params['mode'] = 'weekly';
  }
  return '?' . http_build_query($params);
}

/**
 * 安全輸出
 */
function str_h($value): string
{
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * 新版 Tier：正式榜才給 S/A/B/C，觀察中不給正式 Tier。
 */
function strTierByMetaScore(float $score, string $confidence): array
{
  if (in_array($confidence, ['觀察中', '低樣本', '新興組合'], true)) {
    return ['👁', 'tier-observe'];
  }

  if ($score >= 85) {
    return ['S', 'tier-s'];
  }

  if ($score >= 72) {
    return ['A', 'tier-a'];
  }

  if ($score >= 58) {
    return ['B', 'tier-b'];
  }

  return ['C', 'tier-c'];
}

/**
 * 依 arena_player_match_result 直接計算新版強度分。
 *
 * 評分：
 * - 勝率強度 50%：使用貝葉斯修正勝率，避免小樣本 100% 霸榜
 * - 樣本可信度 20%：10 場滿分
 * - 出現熱度 15%：30 場附近滿分
 * - BP 強度 15%：1450~1700 線性加分
 */
function strFetchMetaRankRows(PDO $pdo, int $days, ?array $weeklyRule = null,string $bp="all",bool $includeAll=false): array
{
  $days = in_array($days, [7, 14, 30], true) ? $days : 30;

  // Match-time cost and current card cost must both fit the category.
  // This is a deck eligibility filter, not a claim that the matches were
  // played in a particular ladder zone or under the current week's rules.
  $costCase = "CASE
          WHEN a.cost BETWEEN 50 AND 59 THEN '50-59'
          WHEN a.cost BETWEEN 60 AND 69 THEN '60-69'
          WHEN a.cost BETWEEN 70 AND 80 THEN '70-80'
          WHEN a.cost >= 90 THEN '90+'
        END";
  $costJoins = '';
  $costMinimum = 'AND a.cost >= 50';
  if ($weeklyRule !== null) {
    $costCase = 'CASE';
    for ($i = 1; $i <= 4; $i++) {
      $cost = (int)$weeklyRule['cost'.$i];
      $op = $i === 4 && $weeklyRule['cost4_operator'] === 'MIN' ? '>=' : '=';
      $costCase .= " WHEN a.cost $op $cost AND (wc0.cost + wc1.cost + wc2.cost) $op $cost THEN 'weekly-$i'";
    }
    $costCase .= ' END';
    $costJoins = 'JOIN unlight wc0 ON wc0.id = a.leader_id
      JOIN unlight wc1 ON wc1.id = a.back1_id
      JOIN unlight wc2 ON wc2.id = a.back2_id';
    $costMinimum = '';
  }

  $discoveryMode = $weeklyRule !== null;
  $sampleFilter = $includeAll ? 'matches >= 1' : ($discoveryMode ? 'matches >= 1' : 'matches >= 3 AND raw_win_rate >= 50');
  $bpSql=srBpSql($bp);
  $winWeight = $discoveryMode ? '0.65' : '0.50';
  $usageWeight = $discoveryMode ? '0.00' : '0.15';

  $sql = "
    WITH deck_stat AS (
      SELECT
        {$costCase} AS cost_range,

        a.team_key,
        MIN(a.leader_id) AS leader_id,
        MIN(a.back1_id) AS back1_id,
        MIN(a.back2_id) AS back2_id,
        ROUND(AVG(a.cost), 1) AS avg_cost,

        COUNT(*) AS matches,
        SUM(a.is_win = 1) AS wins,
        SUM(a.is_win = 0) AS losses,
        ROUND(SUM(a.is_win = 1) / COUNT(*) * 100, 1) AS raw_win_rate,

        ROUND(AVG(a.player_bp), 1) AS avg_player_bp,
        ROUND(AVG(o.player_bp), 1) AS avg_opponent_bp,
        ROUND(AVG((IFNULL(a.player_bp, 0) + IFNULL(o.player_bp, 0)) / 2), 0) AS avg_match_bp,

        MIN(a.update_time) AS first_seen,
        MAX(a.update_time) AS last_seen

      FROM arena_player_match_result a
      JOIN arena_player_match_result o
        ON o.match_id = a.match_id
       AND o.side <> a.side
      {$costJoins}

      WHERE a.update_time >= NOW() - INTERVAL {$days} DAY AND a.update_time <= NOW()
        AND a.cost IS NOT NULL
        {$costMinimum}
        {$bpSql}
        AND a.is_win IS NOT NULL
        AND o.player_bp IS NOT NULL

      GROUP BY cost_range, a.team_key
      HAVING cost_range IS NOT NULL
    ),

    scored_base AS (
      SELECT
        *,
        ROUND((wins + 4) / (matches + 8) * 100, 1) AS adjusted_win_rate
      FROM deck_stat
      WHERE {$sampleFilter}
    ),

    scored AS (
      SELECT
        *,
        GREATEST(0, LEAST(100, (adjusted_win_rate - 50) / 20 * 100)) AS win_score,
        GREATEST(0, LEAST(100, matches / 10 * 100)) AS sample_score,
        GREATEST(0, LEAST(100, LOG(matches + 1) / LOG(31) * 100)) AS usage_score,
        GREATEST(0, LEAST(100, (avg_match_bp - 1450) / 250 * 100)) AS bp_score
      FROM scored_base
    )

    SELECT
  cost_range,
  team_key,
  leader_id,
  back1_id,
  back2_id,
  avg_cost,

  (
    SELECT SUM(u.cost)
    FROM unlight u
    WHERE u.id IN (scored.leader_id, scored.back1_id, scored.back2_id)
  ) AS cost_sum,

  matches,
  wins,
  losses,
  raw_win_rate,
  adjusted_win_rate,
  avg_player_bp,
  avg_opponent_bp,
  avg_match_bp,
  ROUND(win_score, 1) AS win_score,
  ROUND(sample_score, 1) AS sample_score,
  ROUND(usage_score, 1) AS usage_score,
  ROUND(bp_score, 1) AS bp_score,
  ROUND(
    win_score * {$winWeight} +
    sample_score * 0.20 +
    usage_score * {$usageWeight} +
    bp_score * 0.15
  , 1) AS meta_score,
  CASE
    WHEN matches >= 10 THEN '高資料'
    WHEN matches >= 5 THEN '一般'
    ELSE '觀察中'
  END AS confidence_label,
  first_seen,
  last_seen
FROM scored
ORDER BY
  CASE cost_range
    WHEN '50-59' THEN 1
    WHEN '60-69' THEN 2
    WHEN '70-80' THEN 3
    WHEN '90+' THEN 4
    WHEN 'weekly-1' THEN 1
    WHEN 'weekly-2' THEN 2
    WHEN 'weekly-3' THEN 3
    WHEN 'weekly-4' THEN 4
    ELSE 9
  END,
  meta_score DESC,
  matches DESC,
  adjusted_win_rate DESC,
  team_key ASC
  ";

  $stmt = $pdo->query($sql);
  return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * 讀取角色卡資料。
 * arena_player_match_result 的 leader/back 欄位對應 unlight.id。
 */
function strGetCharCards(PDO $pdo, array $ids): array
{
  $ids = array_values(array_filter(array_map('intval', $ids)));
  if (!$ids) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($ids), '?'));
  $stmt = $pdo->prepare("
    SELECT id, ico, name, level, cost
    FROM unlight
    WHERE id IN ($placeholders)
  ");
  $stmt->execute($ids);

  $map = [];
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $map[(int)$row['id']] = $row;
  }

  $result = [];
  foreach ($ids as $id) {
    if (isset($map[$id])) {
      $result[] = $map[$id];
    } else {
      $result[] = [
        'id' => $id,
        'ico' => '',
        'name' => '#' . $id,
        'level' => '',
        'cost' => null,
      ];
    }
  }

  return $result;
}

/**
 * 將查詢結果整理成：
 * $rankData[cost_range]['official'][]
 * $rankData[cost_range]['watch'][]
 */
function strBuildRankData(PDO $pdo, int $days, array $labels, ?array $weeklyRule = null): array
{
  $rankData = [];
  foreach ($labels as $key => $_) {
    $rankData[$key] = [
      'official' => [],
      'watch' => [],
    ];
  }

  if (!$labels) return $rankData;
  $rows = strFetchMetaRankRows($pdo, $days, $weeklyRule);
  $discoveryMode = $weeklyRule !== null;
  $rankLimit = $discoveryMode ? 8 : 10;
  $rankMin = $discoveryMode ? 3 : 5;
  $rankData = strSelectRankRows($rows, $rankData, $discoveryMode, $rankLimit, $rankMin);
  foreach ($rankData as &$group) {
    foreach (['official', 'watch'] as $section) {
      foreach ($group[$section] as &$row) {
        $ids = [(int)$row['leader_id'], (int)$row['back1_id'], (int)$row['back2_id']];
        $row['chars'] = strGetCharCards($pdo, $ids);
        $row['ids'] = $ids;
      }
      unset($row);
    }
  }
  unset($group);
  return $rankData;
}

// ULGG_WEEKLY_DISCOVERY_V1: display selection is independent of database access.
function strSelectRankRows(array $rows, array $rankData, bool $discoveryMode, int $rankLimit, int $rankMin): array
{
  $seen = [];
  $candidates = [];
  foreach ($rows as $row) {
    $costRange = (string)$row['cost_range'];
    if (!isset($rankData[$costRange])) continue;
    $n = (int)$row['matches'];
    if ($n < 1) continue;
    $key = (string)$row['team_key'];
    if ($discoveryMode && isset($seen[$costRange][$key])) continue;
    $seen[$costRange][$key] = true;
    if ($discoveryMode && $n < 5) $row['confidence_label'] = $n >= 3 ? '低樣本' : '新興組合';
    if ($n >= $rankMin) {
      if (count($rankData[$costRange]['official']) < $rankLimit) $rankData[$costRange]['official'][] = $row;
    } elseif ($discoveryMode) {
      $candidates[$costRange][] = $row;
    } elseif (count($rankData[$costRange]['watch']) < 6) {
      $rankData[$costRange]['watch'][] = $row;
    }
  }
  if ($discoveryMode) {
    foreach ($rankData as $key => &$group) {
      $options = $candidates[$key] ?? [];
      // Discovery is not a strength ranking: prioritize number of real matches,
      // then adjusted win rate, then stable identity.
      usort($options, static function (array $a, array $b): int {
        return ((int)$b['matches'] <=> (int)$a['matches'])
          ?: ((float)$b['adjusted_win_rate'] <=> (float)$a['adjusted_win_rate'])
          ?: strcmp((string)$a['team_key'], (string)$b['team_key']);
      });
      $group['watch'] = array_slice($options, 0, max(0, 12 - count($group['official'])));
    }
    unset($group);
  }
  return $rankData;
}

/**
 * 牌組卡片
 */
function strRenderDeckCard(array $team, int $rankNo = 0): string
{
  $ids = $team['ids'] ?? [0, 0, 0];
  $chars = $team['chars'] ?? [];
  $confidence = (string)($team['confidence_label'] ?? '觀察中');
  [$tierText, $tierClass] = strTierByMetaScore((float)$team['meta_score'], $confidence);

  $href = '/pages/team_analysis.php?id1=' . (int)$ids[0] . '&id2=' . (int)$ids[1] . '&id3=' . (int)$ids[2];

  $charHtml = '';
  foreach ($chars as $i => $ch) {
    $icoFile = !empty($ch['ico'])
      ? IMG_BASE . $ch['ico']
      : IMG_BASE . 'ico/unknown.png';

    $alt = trim(($ch['level'] ?? '') . ' ' . ($ch['name'] ?? ''));

    $charHtml .= '
      <div class="char-card c' . (int)$i . '">
        <img src="' . str_h($icoFile) . '" alt="' . str_h($alt) . '" loading="lazy">
      </div>
    ';
  }

  $names = implode(' / ', array_map(function ($c) {
    return trim(($c['level'] ?? '') . ' ' . ($c['name'] ?? ''));
  }, $chars));

  if ($names === '') {
    $names = str_h((string)($team['team_key'] ?? '未知牌組'));
  }

  $explore = $confidence === '新興組合';
  $scoreHtml = $explore ? '實戰探索 · 暫不評級' : '綜合分 <strong>' . str_h($team['meta_score']) . '</strong>';
  $rankBadge = $rankNo > 0 ? '<span class="rank-no">#' . (int)$rankNo . '</span>' : '';

  return '
  <a href="' . str_h($href) . '" class="deck-card deck-link">

    <div class="deck-card-top">
      ' . $rankBadge . '
      <span class="tier-badge ' . str_h($tierClass) . '">' . str_h($tierText) . '</span>
      <span class="confidence-badge confidence-' . str_h($confidence) . '">' . str_h($confidence) . '</span>
    </div>

    <div class="deck-chars">' . $charHtml . '</div>

    <div class="deck-name">' . str_h($names) . '</div>

    <div class="deck-score-row">
      <span>
        ' . $scoreHtml . '
      </span>

      <span>
        勝率 <strong>' . str_h($team['raw_win_rate']) . '%</strong>
      </span>
    </div>

    <div class="deck-meta">
      COST ' . str_h($team['cost_sum'] ?? $team['avg_cost']) . '
      · ' . str_h($team['matches']) . ' 場（' . str_h($team['wins']) . '勝 ' . str_h($team['losses']) . '敗）
      · 平均 BP ' . str_h($team['avg_match_bp']) . '
    </div>

  </a>
';
}


function sbExtra(PDO $pdo,int $days,?array $weeklyRule,string $group,array $keys,string $bp="all"):array {
 if(!$keys)return [];
  $costCase = "CASE
          WHEN a.cost BETWEEN 50 AND 59 THEN '50-59'
          WHEN a.cost BETWEEN 60 AND 69 THEN '60-69'
          WHEN a.cost BETWEEN 70 AND 80 THEN '70-80'
          WHEN a.cost >= 90 THEN '90+'
        END";
  $costJoins = '';
  $costMinimum = 'AND a.cost >= 50';
  if ($weeklyRule !== null) {
    $costCase = 'CASE';
    for ($i = 1; $i <= 4; $i++) {
      $cost = (int)$weeklyRule['cost'.$i];
      $op = $i === 4 && $weeklyRule['cost4_operator'] === 'MIN' ? '>=' : '=';
      $costCase .= " WHEN a.cost $op $cost AND (wc0.cost + wc1.cost + wc2.cost) $op $cost THEN 'weekly-$i'";
    }
    $costCase .= ' END';
    $costJoins = 'JOIN unlight wc0 ON wc0.id = a.leader_id
      JOIN unlight wc1 ON wc1.id = a.back1_id
      JOIN unlight wc2 ON wc2.id = a.back2_id';
    $costMinimum = '';
  }


 $bpSql=srBpSql($bp);
 $marks=implode(',',array_fill(0,count($keys),'?'));$horizon=max(14,$days);
 $sql="SELECT a.team_key,o.team_key opponent_key,
 FLOOR(TIMESTAMPDIFF(SECOND,a.update_time,NOW())/86400) day_bucket,
 COUNT(*) n,SUM(a.is_win=1) w
 FROM arena_player_match_result a JOIN arena_player_match_result o
 ON o.match_id=a.match_id AND o.side<>a.side
 {$costJoins}
 WHERE a.update_time>=NOW()-INTERVAL {$horizon} DAY AND a.update_time<=NOW()
 AND a.cost IS NOT NULL {$costMinimum} {$bpSql} AND a.is_win IS NOT NULL AND o.player_bp IS NOT NULL
 AND ({$costCase})=? AND a.team_key IN ($marks)
 GROUP BY a.team_key,o.team_key,day_bucket";
 $q=$pdo->prepare($sql);$q->execute(array_merge([$group],$keys));return $q->fetchAll(PDO::FETCH_ASSOC);
}

// R3: BP is the own player's match-time BP; never filter on a deck average.
function srBpSql(string $bp):string {
 return match($bp){'low'=>' AND a.player_bp < 1500','1500'=>' AND a.player_bp >= 1500 AND a.player_bp < 1600','1600'=>' AND a.player_bp >= 1600 AND a.player_bp < 1700','1700'=>' AND a.player_bp >= 1700',default=>''};
}
function srPayload(PDO $pdo,int $days,?array $rule,array $labels,string $group,string $bp,bool $weekly):array {
 $result=['rows'=>[],'costs'=>[],'extraError'=>false,'error'=>false];
 if(!$labels)return $result;
 // All eligible team-side samples are needed for true cohort share, including losing and rare decks.
 $all=strFetchMetaRankRows($pdo,$days,$rule,$bp,true);
 $rank=[];$counts=[];
 foreach($labels as $key=>$label){$rank[$key]=['official'=>[],'watch'=>[]];$counts[$key]=0;}
 foreach($all as $row){
  $k=$row['cost_range'];if(!isset($counts[$k]))continue;
  $counts[$k]+=(int)$row['matches'];
 }
 // Rank using the original candidate SQL, not the denominator query.
 $candidates=strFetchMetaRankRows($pdo,$days,$rule,$bp);
 foreach($labels as $key=>$label)$result['costs'][]=['key'=>$key,'label'=>$label['title'],'n'=>$counts[$key]];
 $rank=strSelectRankRows($candidates,$rank,$weekly,$weekly?8:10,$weekly?3:5);
 $sets=$rank[$group]??['official'=>[],'watch'=>[]];
 $visible=array_merge($sets['official'],$sets['watch']);$keyMap=[];$trend=[];$matrix=[];
 foreach($visible as $row)$keyMap[(string)$row['team_key']]=hash('sha256',(string)$row['team_key']);
 if($visible)try{
  foreach(sbExtra($pdo,$days,$rule,$group,array_keys($keyMap),$bp) as $row){
   $k=(string)$row['team_key'];$day=(int)$row['day_bucket'];$n=(int)$row['n'];$w=(int)$row['w'];$opp=(string)$row['opponent_key'];
   if($day>=0&&$day<14){$trend[$k][$day]['n']=($trend[$k][$day]['n']??0)+$n;$trend[$k][$day]['w']=($trend[$k][$day]['w']??0)+$w;}
   if($day>=0&&$day<$days&&isset($keyMap[$opp])){$matrix[$k][$opp]['n']=($matrix[$k][$opp]['n']??0)+$n;$matrix[$k][$opp]['w']=($matrix[$k][$opp]['w']??0)+$w;}
  }
 }catch(Throwable $e){$result['extraError']=true;error_log('strength beta extra query unavailable');}
 foreach($sets as $kind=>$list)foreach($list as $i=>$row){
  $k=(string)$row['team_key'];$ids=[(int)$row['leader_id'],(int)$row['back1_id'],(int)$row['back2_id']];
  $cards=array_map(fn($c)=>['id'=>(int)$c['id'],'name'=>trim($c['name'].' '.$c['level']),'ico'=>!empty($c['ico'])?IMG_BASE.$c['ico']:null],strGetCharCards($pdo,$ids));
  [$tier]=strTierByMetaScore((float)$row['meta_score'],$row['confidence_label']);
  if(!in_array($tier,['S','A','B','C'],true)||$kind==='watch')$tier='O';
  $series=[];$nn=$nw=$pn=$pw=0;
  for($day=0;$day<14;$day++){$v=$trend[$k][$day]??null;$n=$v['n']??0;$w=$v['w']??0;if($day<7){$nn+=$n;$nw+=$w;$series[]=$n?['n'=>$n,'wr'=>100*$w/$n]:null;}else{$pn+=$n;$pw+=$w;}}
  $matchups=[];foreach($matrix[$k]??[] as $opp=>$v)if($v['n']>=5)$matchups[$keyMap[$opp]]=['n'=>$v['n'],'wr'=>100*$v['w']/$v['n']];
  $result['rows'][]=['id'=>$keyMap[$k],'rank'=>$kind==='official'?$i+1:null,'tier'=>$tier,'kind'=>$kind,'name'=>implode(' / ',array_column($cards,'name')),'cards'=>$cards,'score'=>$row['confidence_label']==='新興組合'?null:(float)$row['meta_score'],'wr'=>(float)$row['raw_win_rate'],'adjusted'=>(float)$row['adjusted_win_rate'],'pick'=>$counts[$group]?100*(int)$row['matches']/$counts[$group]:null,'n'=>(int)$row['matches'],'wins'=>(int)$row['wins'],'bp'=>(float)$row['avg_match_bp'],'confidence'=>$row['confidence_label'],'delta'=>$nn>=5&&$pn>=5?100*$nw/$nn-100*$pw/$pn:null,'series'=>array_reverse($series),'matchups'=>$matchups,'href'=>'/pages/team_analysis.php?'.http_build_query(['id1'=>$ids[0],'id2'=>$ids[1],'id3'=>$ids[2]])];
 }
 return $result;
}
$bp=is_string($_GET['bp']??null)&&in_array($_GET['bp'],['all','low','1500','1600','1700'],true)?$_GET['bp']:'all';
$group=is_string($_GET['cost']??null)&&isset($labels[$_GET['cost']])?$_GET['cost']:(!$weeklyMode&&isset($labels['50-59'])?'50-59':(array_key_first($labels)??''));
try{$payload=srPayload($pdo,$days,$weeklyRule,$labels,$group,$bp,$weeklyMode);}
catch(Throwable $e){error_log('strength beta data unavailable');http_response_code(503);$payload=['rows'=>[],'costs'=>[],'error'=>true,'extraError'=>false];}
$payload['state']=['days'=>$days,'cost'=>$group,'bp'=>$bp,'rule'=>$weeklyMode?'weekly':'normal'];
$payload['ruleMissing']=$weeklyMode&&!$weeklyRule;
?>
<style>
.srm{--srm-bg:var(--bg-card-mid,#191723);--srm-edge:var(--border-glow,#56496c);--srm-soft:var(--text-soft,#b7adca);--srm-gold:#ead09a;color:var(--text-main,#e9e4f2);font-size:12px;line-height:1.3;min-width:0}
.srm *{box-sizing:border-box}.srm h2,.srm h3,.srm p{margin:0}.srm h3{font-size:13px;line-height:1.25}.srm small{font-size:10px;color:var(--srm-soft)}.srm .muted{color:var(--srm-soft)}.srm .panel{min-width:0;background:var(--srm-bg);border:1px solid var(--srm-edge);border-radius:8px;overflow:hidden;box-shadow:inset 0 1px 0 #c3a0e31c}.srm .panel-title{display:flex;align-items:center;justify-content:space-between;gap:6px;flex-wrap:wrap;padding:7px 9px;background:linear-gradient(105deg,#51406a55,#30243d22);border-bottom:1px solid var(--srm-edge)}.srm .panel-title small{margin-left:4px}.srm .mock-note{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:8px;padding:6px 9px;border:1px solid var(--srm-edge);border-radius:7px;color:var(--srm-gold);background:var(--srm-bg)}
.srm .filters{display:flex;align-items:center;gap:7px;padding:7px;flex-wrap:wrap;margin-bottom:10px}.srm .filter-label{font-weight:bold;color:var(--srm-soft);white-space:nowrap}.srm .filter{display:flex;align-items:center;gap:5px;border-left:1px solid var(--srm-edge);padding-left:7px;min-width:0}.srm .filter>span{font-size:11px;white-space:nowrap}.srm .filter>span small{display:block}.srm .seg{display:flex;gap:2px;align-items:center}.srm .btn{font-size:11px;padding:4px 6px;min-height:25px;line-height:1.15;white-space:nowrap}.srm .btn[aria-pressed=true]{background:linear-gradient(145deg,#685036,#2d2237);border-color:#dfb76c;color:#f6dfab;box-shadow:inset 0 0 4px #f4d08b44}.srm select,.srm input{font-size:11px;min-height:26px;padding:3px 5px;width:auto;max-width:100%}.srm .search{flex:1 1 130px;min-width:110px;display:flex;gap:4px}.srm .search input{width:100%;min-width:0}
.srm .dashboard{display:grid;grid-template-columns:minmax(0,1.43fr) minmax(0,1fr);gap:10px;align-items:start}.srm .left,.srm .right{min-width:0;display:grid;gap:8px}.srm .right{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}.srm .wide{grid-column:1/-1}.srm .tabs-wrap{display:flex;gap:6px;min-width:0}.srm .tabs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));flex:1;min-width:0;gap:2px;padding:3px}.srm .tabs .btn{white-space:normal;display:flex;align-items:center;justify-content:center;gap:5px;min-height:39px;padding:4px}.srm .tabs strong{font-size:12px;display:block}.srm .tabs small{display:block;font-size:9px}.srm .tab-icon{font-size:17px}.srm .views{display:flex;gap:3px;align-items:center}.srm .views button{font-size:17px}
.srm .table-scroll{overflow:auto;max-width:100%}.srm table{width:100%;border-collapse:separate;border-spacing:0;font-variant-numeric:tabular-nums}.srm th,.srm td{white-space:nowrap;padding:4px 3px;text-align:center}.srm thead th{font-size:11px;font-weight:600;background:#30263e;color:var(--text-main,#eee);border-bottom:1px solid var(--srm-edge)}.srm thead small{display:block;font-size:9px;font-weight:400}.srm .rank-table{min-width:610px}.srm .rank-table td{border-bottom:1px solid #9f8abb1c;height:32px}.srm .rank-table th:nth-child(3){text-align:left}.srm .rank-table tr[data-deck]{cursor:pointer}.srm .rank-table tr[data-deck]:hover,.srm .rank-table tr.selected{background:#aa85db20}.srm .tier-cell{width:42px;border-right:1px solid var(--srm-edge);background:linear-gradient(110deg,var(--tier-bg),transparent);color:var(--tier-color)}.srm .tier{display:inline-flex;align-items:center;justify-content:center;width:34px;height:39px;font:700 24px Georgia,serif;clip-path:polygon(50% 0,100% 25%,100% 75%,50% 100%,0 75%,0 25%);background:var(--tier-color);position:relative;isolation:isolate}.srm .tier:before{content:"";position:absolute;inset:2px;clip-path:inherit;background:#252035;z-index:-1}.srm .tier-S{--tier-color:#ff979a;--tier-bg:#8b39455e}.srm .tier-A{--tier-color:#f6cd70;--tier-bg:#8e713652}.srm .tier-B{--tier-color:#98d3fa;--tier-bg:#355e8a60}.srm .tier-C{--tier-color:#8fe1a6;--tier-bg:#31683f50}.srm tbody.group+tbody.group tr:first-child td{border-top:4px solid var(--srm-bg)}.srm .deckcell{display:flex;align-items:center;gap:6px;text-align:left;min-width:0}.srm .faces{display:inline-flex;gap:2px;flex-shrink:0;vertical-align:middle}.srm .faces img{display:block;width:27px;height:25px;object-fit:cover;object-position:50% 24%;border:1px solid #887b9e;border-radius:3px;background:#332a42}.srm .faces img:first-child{border-color:#d0b37d}.srm .decktext{min-width:0;line-height:1.15}.srm .decktext b,.srm .decktext small{display:block;overflow:hidden;text-overflow:ellipsis;max-width:104px}.srm .score{display:inline-block;border:1px solid var(--tier-color);border-radius:4px;background:var(--tier-bg);padding:2px 5px;font-weight:bold;color:var(--text-main,#fff)}.srm .confidence{border:1px solid var(--tier-color);border-radius:3px;color:var(--tier-color);font-size:9px;padding:2px 3px}.srm .trend{display:flex;align-items:center;gap:3px;justify-content:flex-end}.srm .spark{width:48px;height:21px}.srm .up{color:#98e58c}.srm .down{color:#ff9099}
.srm .mini-list{display:grid;gap:0;padding:2px 6px}.srm .mini-row{display:flex;align-items:center;gap:4px;padding:4px 0;border-bottom:1px solid #9f8abb20;min-width:0;cursor:pointer}.srm .mini-row:last-child{border:0}.srm .mini-row .faces img{width:19px;height:19px}.srm .mini-row .mini-name{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;font-size:11px}.srm .mini-row strong{white-space:nowrap;font-size:11px}.srm .donut-body{display:flex;align-items:center;justify-content:center;gap:8px;padding:7px;min-height:133px;flex-wrap:wrap}.srm .donut{width:103px;height:103px;border-radius:50%;display:grid;place-items:center;flex-shrink:0}.srm .donut-inner{width:72px;height:72px;border-radius:50%;background:var(--srm-bg);display:grid;place-content:center;text-align:center}.srm .donut-inner b{font-size:17px}.srm .donut-legend{display:grid;gap:5px;font-size:11px;flex:1;min-width:80px}.srm .donut-legend span{display:flex;align-items:center;gap:5px}.srm .donut-legend i{width:8px;height:8px;border-radius:50%;display:inline-block}.srm .donut-legend b{margin-left:auto}.srm .scatter{display:block;width:100%;height:auto;min-height:130px;color:var(--srm-soft)}.srm .scatter text{font-size:9px;fill:currentColor}.srm .scatter circle{cursor:pointer}
.srm .matrix{font-size:10px;min-width:400px}.srm .matrix th,.srm .matrix td{padding:3px 2px;border:1px solid #9986aa25}.srm .matrix thead th{background:#2a2238}.srm .matrix .faces img{width:13px;height:16px}.srm .matrix td{font-size:10px;font-weight:600;color:#20232b}.srm .matrix .diag{background:#30283e;color:#c4b9d0}.srm .matrix .positive{background:#a8dbba}.srm .matrix .negative{background:#e7a7b2}.srm .matrix .neutral{background:#ded4d2}.srm .matrix th:first-child{min-width:106px;text-align:left}.srm .matrix-label{display:flex;align-items:center;gap:3px}.srm .matrix-label span:last-child{overflow:hidden;text-overflow:ellipsis;max-width:64px}.srm .cards-mode{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:7px;padding:8px}.srm .deck-card{border:1px solid var(--tier-color);border-radius:7px;padding:8px;background:var(--tier-bg);cursor:pointer}.srm .deck-card .faces img{width:39px;height:39px}.srm .deck-card strong{display:block;margin:5px 0}.srm .deck-card p{display:flex;justify-content:space-between}.srm .alternate{padding:10px}.srm .alt-row{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--srm-edge)}.srm .alt-row .track{height:9px;background:#322a41;border-radius:4px;flex:1;overflow:hidden}.srm .alt-row i{height:100%;display:block;background:#ac86d9}.srm .empty{padding:18px;color:var(--srm-soft);text-align:center}.srm [hidden]{display:none!important}.srm .selected-note{padding:7px 9px;border-top:1px solid var(--srm-edge);color:var(--srm-soft);font-size:11px}
@media(max-width:1200px){.srm .dashboard{grid-template-columns:1fr}.srm .rank-table{min-width:620px}.srm .right{grid-template-columns:repeat(2,minmax(0,1fr))}.srm .faces img{width:30px;height:27px}}
@media(max-width:540px){.srm .right{grid-template-columns:1fr}.srm .wide{grid-column:auto}.srm .tabs{grid-template-columns:repeat(2,minmax(0,1fr))}.srm .filters{gap:5px}.srm .filter{flex-wrap:wrap}.srm .filter-label{width:100%}.srm .search{flex-basis:100%}.srm .matrix{min-width:480px}.srm .tabs-wrap{align-items:start}.srm .donut-body{flex-wrap:nowrap}}
/* Compact layout; scoped controls use the shared dark theme tokens. */
.srm .panel{margin:0;box-shadow:inset 0 1px 0 #c3a0e328,0 1px 4px #0003}
.srm .filters{margin-bottom:10px}
.srm .btn.btn-default,.srm .form-control{background:var(--bg-card-top,#252033);color:var(--text-main,#ece6f4);border:1px solid var(--border-glow,#675679);box-shadow:none}
.srm .btn.btn-default[aria-pressed=true]{background:linear-gradient(145deg,#685036,#2d2237);border-color:#dfb76c;color:#f6dfab;box-shadow:inset 0 0 4px #f4d08b44}
.srm .btn.btn-default:hover,.srm .btn.btn-default:focus-visible{border-color:var(--accent,#9fa8da);color:var(--text-main,#ece6f4)}
.srm .btn:disabled{opacity:.45}
.srm .form-control option{background:var(--bg-card-mid,#191723);color:var(--text-main,#ece6f4)}
.srm .form-control::placeholder{color:var(--text-soft,#b7adca)}
.srm .matrix{table-layout:fixed;min-width:0}
.srm .matrix th:first-child{width:22%;min-width:0}
.srm .matrix th,.srm .matrix td{padding:3px 1px;font-size:9px;overflow:hidden;text-overflow:clip}
.srm .matrix .faces{gap:1px}
.srm .matrix .faces img{width:clamp(7px,.62vw,11px);height:14px}
.srm .matrix-label span:last-child{font-size:9px}
.srm .matrix thead th{vertical-align:middle}
.srm .rank-table thead th{background:linear-gradient(0deg,#30243d,#453553)}
.srm .panel-title{background:linear-gradient(105deg,#67508055,#35264166)}
.srm .search{flex:1 1 110px}
@media(max-width:540px){.srm .matrix{min-width:470px}.srm .matrix .faces img{width:12px}}

.srm .tier-O{--tier-color:var(--srm-soft);--tier-bg:#746a8b30}.srm .tier-O .tier{font:600 11px Arial,sans-serif}
.srm .right > .wide .mini-row .mini-name{white-space:normal;overflow-wrap:anywhere}.srm .right > .wide .mini-row .faces img{width:28px;height:25px}.srm .right > .wide .mini-row{padding:5px 3px}.srm .missing-face{width:25px;height:25px;background:#322a41;display:inline-grid;place-items:center}
/* R4: original card portraits, all right-hand panels in a vertical stack. */
.srm .dashboard{grid-template-columns:minmax(0,68fr) minmax(0,32fr);gap:10px}
.srm .right{grid-template-columns:minmax(0,1fr)}
.srm .tabs{grid-template-columns:minmax(0,1fr)}
.srm .tabs .btn{justify-content:flex-start;padding:7px 12px}
.srm .rank-table{min-width:0;table-layout:fixed}
.srm .rank-table th,.srm .rank-table td{padding:6px 2px;white-space:normal;overflow-wrap:anywhere}
.srm .rank-table th small{font-size:9px;line-height:1.1}
.srm .rank-table col:nth-child(1){width:5%}.srm .rank-table col:nth-child(2){width:5%}.srm .rank-table col:nth-child(3){width:31%}
.srm .rank-table col:nth-child(4){width:10%}.srm .rank-table col:nth-child(5){width:9%}.srm .rank-table col:nth-child(6){width:9%}.srm .rank-table col:nth-child(7){width:8%}.srm .rank-table col:nth-child(8){width:12%}.srm .rank-table col:nth-child(9){width:11%}
.srm .tier-cell{width:auto}.srm .tier{width:30px;height:35px;font-size:21px}
.srm .deckcell{display:block}.srm .faces{display:flex;gap:4px;justify-content:center;align-items:start;min-width:0}
.srm .face-item{display:flex;flex:1 1 0;min-width:0;flex-direction:column;align-items:center;gap:2px;text-align:center}
.srm .portrait-btn{display:block;position:relative;width:52px;height:52px;flex-shrink:0;overflow:hidden;padding:0;border:1px solid #887b9e;border-radius:5px;background:#332a42;cursor:zoom-in}
.srm .face-item:first-child .portrait-btn{border-color:#dfb76c;box-shadow:0 0 0 1px #dfb76c55}
.srm .portrait-btn img,.srm .right .mini-row .portrait-btn img,.srm .deck-card .portrait-btn img{position:absolute;left:50%;top:0;width:185%;height:auto;max-width:none;max-height:none;transform:translate(-50%,-16%);object-fit:initial;border:0;border-radius:0;background:none}
.srm .face-name{font-size:11px;line-height:1.18;white-space:normal;overflow-wrap:anywhere;color:var(--text-main,#eee);font-weight:600}
.srm .face-level{font-size:10px;border:1px solid var(--srm-edge);border-radius:3px;padding:0 4px;line-height:1.3;color:var(--srm-gold)}
.srm .trend{flex-direction:column;justify-content:center;align-items:center;gap:2px;font-size:11px}
.srm .spark{width:100%;max-width:66px;min-width:30px;height:24px}
.srm .confidence{display:inline-block;white-space:normal;line-height:1.2;padding:3px}
.srm .mini-row{display:grid;grid-template-columns:22px minmax(0,1fr) auto;align-items:center;gap:5px}
.srm .mini-row .faces{justify-content:start;gap:3px}
.srm .right .mini-row .portrait-btn{width:36px;height:36px}
.srm .right .mini-row .face-name{font-size:10px}
.srm .right .mini-row .face-level{font-size:9px}
.srm .mini-row .mini-name{display:none}
.srm .mini-row strong{font-size:11px;white-space:normal;text-align:right;max-width:76px}
.srm .donut-body{min-height:140px;flex-wrap:nowrap}
.srm .scatter{max-height:220px}
.srm .portrait-preview{position:fixed;z-index:40000;width:188px;max-width:calc(100vw - 16px);padding:7px;background:#17141e;border:1px solid #9f8abb;border-radius:7px;box-shadow:0 8px 28px #000a;pointer-events:none;text-align:center}
.srm .portrait-preview img{display:block;max-width:100%;width:auto;max-height:min(260px,50vh);margin:auto;object-fit:contain}
.srm .portrait-preview b{display:block;padding:4px 0 0;overflow-wrap:anywhere}
.srm .mobile-tier{display:none}
/* Fight.php-inspired enlarged overlap: artwork only, not the labels. */
.srm .rank-table .faces,.srm .deck-card .faces{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:0;width:min(100%,192px);margin:auto}
.srm .rank-table .portrait-btn,.srm .deck-card .portrait-btn{width:76px;height:76px;z-index:1}
.srm .face-item:nth-child(2) .portrait-btn{z-index:2}
.srm .face-item:nth-child(3) .portrait-btn{z-index:3}
.srm .face-item .portrait-btn:hover,.srm .face-item .portrait-btn:focus-visible{z-index:20;box-shadow:0 0 9px #c596ff88}
.srm .face-item .face-name,.srm .face-item .face-level{position:relative;z-index:0}
.srm .right .mini-row .faces{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));width:min(100%,126px);gap:0;justify-self:center}
.srm .right .mini-row .portrait-btn{width:48px;height:48px}
.srm .right .mini-row{grid-template-columns:18px minmax(0,1fr) auto}
@media(min-width:801px) and (max-width:1430px){.srm .rank-table .portrait-btn{width:68px;height:68px}.srm .rank-table .faces{width:min(100%,174px)}}
@media(max-width:380px){.srm .right .mini-row .portrait-btn{width:44px;height:44px}}
@media(max-width:1250px){.srm .dashboard{grid-template-columns:1fr}.srm .right{grid-template-columns:minmax(0,1fr)}}
@media(max-width:800px){
 .srm .rank-table,.srm .rank-table tbody{display:block;width:100%;min-width:0}
 .srm .rank-table colgroup,.srm .rank-table thead,.srm .rank-table .tier-cell{display:none}
 .srm .rank-table tr[data-deck]{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:5px;padding:8px;border-bottom:1px solid var(--srm-edge)}
 .srm .rank-table td{height:auto;border:0;padding:3px;display:flex;flex-direction:column;align-items:center;justify-content:center;min-width:0}
 .srm .rank-table td[data-label]:before{content:attr(data-label);font-size:10px;color:var(--srm-soft)}
 .srm .rank-table .rank-number,.srm .rank-table .deck-column{grid-column:1/-1}
 .srm .rank-table .rank-number{display:flex;flex-direction:row;justify-content:space-between}
 .srm .mobile-tier{display:inline;color:var(--tier-color);font-weight:700}
 .srm .rank-table .deckcell{width:100%}
 .srm .rank-table .faces{justify-content:center;gap:7px}
 .srm .rank-table .face-item{flex:0 1 78px}
 .srm .rank-table .trend{width:100%}
 .srm .rank-table .trend-column{grid-column:1/-1}
 .srm .rank-table .trend-column .trend{flex-direction:row;justify-content:center;gap:10px}
 .srm .rank-table .trend-column .spark{width:90px;max-width:90px}
 .srm .rank-table tbody.group+tbody.group tr:first-child td{border-top:0}
}


/* R5: restrained crop and slight overlap; preserve more original artwork. */
.srm .rank-table .faces,.srm .deck-card .faces{width:min(100%,174px);gap:0}
.srm .rank-table .portrait-btn,.srm .deck-card .portrait-btn{width:60px;height:60px}
.srm .right .mini-row .faces{width:min(100%,144px)}
.srm .right .mini-row .portrait-btn{width:48px;height:48px}
.srm .rank-table .faces .portrait-btn>img,.srm .deck-card .faces .portrait-btn>img,.srm .right .mini-row .faces .portrait-btn>img{
 position:absolute!important;inset:0!important;width:100%!important;height:100%!important;max-width:none!important;max-height:none!important;transform:none!important;object-fit:cover!important;object-position:50% 30%!important
}
@media(max-width:380px){.srm .right .mini-row .portrait-btn{width:44px;height:44px}}


/* R6: use the spare horizontal deck space for one-line level/name labels. */
.srm .rank-table .faces,.srm .deck-card .faces{width:min(100%,228px);gap:2px}
.srm .face-item{gap:3px}
.srm .face-label{display:flex;align-items:baseline;justify-content:center;gap:2px;max-width:100%;min-width:0;line-height:1.25}
.srm .face-label .face-level{flex:0 0 auto;padding:0 2px;font-size:10px;white-space:nowrap}
.srm .face-label .face-name{min-width:0;font-size:11px;line-height:1.25;word-break:normal;overflow-wrap:anywhere}
.srm .rank-table th,.srm .rank-table td{padding:4px 2px}
.srm .rank-table thead th{line-height:1.12}
.srm .rank-table thead small{font-size:9px;line-height:1.1}
.srm .tabs .btn{min-height:32px;padding:3px 8px}
.srm .tabs .btn span:last-child{display:flex;align-items:baseline;gap:7px;flex-wrap:wrap}
.srm .right .mini-row{padding:4px 2px;grid-template-columns:18px minmax(0,1fr) max-content;gap:4px}
.srm .right .mini-row .faces{width:min(100%,204px);gap:2px}
.srm .right .mini-row .face-label .face-name{font-size:10px}
.srm .right .mini-row .face-label .face-level{font-size:9px}
.srm .right .mini-row strong{white-space:nowrap;max-width:none;font-size:11px}
.srm .trend>span{white-space:nowrap}
@media(max-width:800px){.srm .rank-table td{padding:3px 2px}.srm .rank-table .faces{width:min(100%,228px);gap:2px}}
@media(max-width:380px){.srm .right .mini-row{grid-template-columns:15px minmax(0,1fr) max-content;gap:2px}.srm .right .mini-row strong{font-size:10px}}


/* Public R7: eight columns, reclaimed width for deck and BP. */
.srm .rank-table col:nth-child(1){width:5%}
.srm .rank-table col:nth-child(2){width:5%}
.srm .rank-table col:nth-child(3){width:36%}
.srm .rank-table col:nth-child(4){width:11%}
.srm .rank-table col:nth-child(5){width:10%}
.srm .rank-table col:nth-child(6){width:10%}
.srm .rank-table col:nth-child(7){width:9%}
.srm .rank-table col:nth-child(8){width:14%}
</style>
<div class="content-wrapper"><section class="content ul-container-nopad"><div class="container-fluid srm" data-strength-live="r7">
<div class="mock-note"><b>正式戰績資料</b><span>出場率以同 COST、期間及 BP 的有效隊伍樣本為分母；BP 篩選依我方對戰時 BP。僅列原站正式榜及觀察榜。</span></div>
<?php if($payload['error']): ?><div class="mock-note" role="alert">資料暫時無法取得。</div><?php endif; ?>
<?php if($payload['ruleMissing']): ?><div class="mock-note" role="alert">目前無已核准當週 COST 規則。</div><?php elseif($weeklyMode): ?><div class="mock-note">當週規則 <?= str_h($weeklyRule['effective_date']) ?>；依歷史與現行卡片 COST 篩選，非當週分區戰績。</div><?php endif; ?>
<?php if($payload['extraError']): ?><div class="mock-note">趨勢及對局資料暫時無法取得。</div><?php endif; ?>
<div class="panel filters"><span class="filter-label">⚑ 篩選條件 Filter</span>
<div class="filter"><span>資料期間<small>Period</small></span><div class="seg" data-filter="days"><button class="btn btn-default" data-value="7" aria-pressed="<?= $days===7?'true':'false' ?>">7 天</button><button class="btn btn-default" data-value="14" aria-pressed="<?= $days===14?'true':'false' ?>">14 天</button><button class="btn btn-default" data-value="30" aria-pressed="<?= $days===30?'true':'false' ?>">30 天</button></div></div>
<div class="filter"><span>COST 區間<small>Cost Range</small></span><div class="seg" data-filter="cost"><?php foreach($labels as $k=>$label): ?><button class="btn btn-default" data-value="<?= str_h($k) ?>" aria-pressed="<?= $group===$k?'true':'false' ?>"><?= str_h(str_replace(' COST','',$label['title'])) ?></button><?php endforeach; ?></div></div>
<div class="filter"><span>BP 區間<small>BP Range</small></span><select class="form-control" id="srm-bp" aria-label="BP 區間"><option value="all" <?= $bp==='all'?'selected':'' ?>>全部</option><option value="low" <?= $bp==='low'?'selected':'' ?>>1499 以下</option><option value="1500" <?= $bp==='1500'?'selected':'' ?>>1500–1599</option><option value="1600" <?= $bp==='1600'?'selected':'' ?>>1600–1699</option><option value="1700" <?= $bp==='1700'?'selected':'' ?>>1700+</option></select></div>
<div class="filter"><span>當週規則<small>Rule</small></span><div class="seg" data-filter="rule"><button class="btn btn-default" data-value="normal" aria-pressed="<?= !$weeklyMode?'true':'false' ?>">一般環境</button><button class="btn btn-default" data-value="weekly" aria-pressed="<?= $weeklyMode?'true':'false' ?>">當週規則</button></div></div>
<div class="search"><input class="form-control" id="srm-search" type="search" placeholder="搜尋牌組或角色卡號…" aria-label="搜尋牌組"><button class="btn btn-default" id="srm-clear" aria-label="清除搜尋">×</button></div>
</div>
<div class="dashboard"><div class="left"><div class="tabs-wrap"><nav class="panel tabs" aria-label="分析分頁"><button class="btn btn-default" data-tab="ranking" aria-pressed="true"><span class="tab-icon">♛</span><span><strong>牌組強度榜</strong><small>Deck Strength Ranking</small></span></button></nav><div class="views"><button class="btn btn-default" data-view="list" aria-label="列表" aria-pressed="true">☷</button><button class="btn btn-default" data-view="card" aria-label="卡片">▦</button></div></div><section class="panel" id="srm-main"></section></div>
<aside class="right">
<section class="panel wide"><div class="panel-title"><h3 class="up">↗ Meta 上升最快 <small>Top Rising Decks</small></h3></div><div id="srm-rise" class="mini-list"></div></section>
<section class="panel wide"><div class="panel-title"><h3 class="down">↘ Meta 下滑最快 <small>Top Falling Decks</small></h3></div><div id="srm-fall" class="mini-list"></div></section>
<section class="panel"><div class="panel-title"><h3>▥ COST 區間分布 <small>Cost Distribution</small></h3></div><div id="srm-donut" class="donut-body"></div></section>
<section class="panel"><div class="panel-title"><h3>▥ 勝率 vs 出場率（牌組）<small>Win Rate vs Pick Rate</small></h3></div><div id="srm-plot"></div></section>
<section class="panel"><div class="panel-title"><h3 class="up">優勢對戰 <small>Best Matchups</small></h3></div><div id="srm-best" class="mini-list"></div></section>
<section class="panel"><div class="panel-title"><h3 class="down">劣勢對戰 <small>Worst Matchups</small></h3></div><div id="srm-worst" class="mini-list"></div></section>
<section class="panel wide"><div class="selected-note" id="srm-selected"></div><div class="selected-note">近7日 vs 前7日勝率變化，兩期各至少5場。優劣對局只比較目前榜單內對手且至少5場；無足夠樣本不顯示數值。非因果剋制。</div></section>
</aside></div></div></section></div>
<script type="application/json" id="srm-data"><?= json_encode($payload,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script>
(()=>{
const root=document.querySelector('.srm');if(!root)return;
const $=s=>root.querySelector(s),$$=s=>[...root.querySelectorAll(s)],payload=JSON.parse(document.getElementById('srm-data').textContent),all=payload.rows;
const state={...payload.state,q:'',tab:'ranking',view:'list',selected:null};
const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmt=v=>v==null?'—':Number(v).toFixed(1)+'%',delta=v=>v==null?'—':(v>0?'▲ +':v<0?'▼ ':'')+v.toFixed(1)+' pp';
const color=v=>v==null||v===0?'muted':v>0?'up':'down';
const rows=()=>all.filter(d=>!state.q||(d.name+' '+d.cards.map(c=>c.id).join(' ')).toLowerCase().includes(state.q));
const selected=()=>rows().find(d=>d.id===state.selected)||rows()[0];
const faces=d=>'<span class="faces">'+d.cards.map(c=>{const m=c.name.match(/^(.*?)\s+([LR][1-5])$/i),name=m?m[1]:c.name,level=m?m[2]:'—';return '<span class="face-item"><button type="button" class="portrait-btn" data-card-preview="'+esc(c.ico??'')+'" data-card-name="'+esc(c.name)+'" aria-label="查看完整卡面 '+esc(c.name)+'" '+(c.ico?'':'disabled')+'>'+(c.ico?'<img src="'+esc(c.ico)+'" alt="" loading="lazy">':'?')+'</button><span class="face-label"><span class="face-level">'+esc(level)+'</span><span class="face-name">'+esc(name)+'</span></span></span>'}).join('')+'</span>';
const score=d=>'<span class="score">'+(d.score==null?'—':d.score.toFixed(1))+'</span>';
const head=(cn,en)=>cn+'<small>'+en+'</small>',tiers=['S','A','B','C','O'],tierLabel=t=>t==='O'?'觀察':t;
function ranking(list){if(!list.length)return '<div class="empty">沒有符合條件的牌組</div>';
if(state.view==='card')return '<div class="cards-mode">'+list.map(d=>'<div class="deck-card tier-'+d.tier+'" tabindex="0" role="button" data-deck="'+d.id+'">'+faces(d)+'<strong>'+esc(d.name)+' · '+tierLabel(d.tier)+'</strong><p>'+score(d)+'<span>'+fmt(d.wr)+'</span></p><small>'+d.n.toLocaleString()+' 隊 · BP '+d.bp+' · '+esc(d.confidence)+'</small></div>').join('')+'</div>';
return '<div class="table-scroll"><table class="rank-table"><colgroup><col><col><col><col><col><col><col><col></colgroup><thead><tr><th>Tier</th><th>'+head('排名','Rank')+'</th><th>'+head('牌組（3名角色）','Deck (Trio)')+'</th><th>'+head('強度分數','Strength Score')+'</th><th>'+head('勝率','Win Rate')+'</th><th>'+head('出場率','Pick Rate')+'</th><th>'+head('樣本數','Sample Size')+'</th><th>'+head('BP 強度','BP / Confidence')+'</th></tr></thead>'+tiers.map(t=>{const group=list.filter(d=>d.tier===t);return group.length?'<tbody class="group tier-'+t+'">'+group.map((d,i)=>'<tr tabindex="0" data-deck="'+d.id+'" class="'+(selected()?.id===d.id?'selected':'')+'">'+(i===0?'<td class="tier-cell" rowspan="'+group.length+'"><span class="tier">'+tierLabel(t)+'</span></td>':'')+'<td class="rank-number"><span class="mobile-tier">'+tierLabel(d.tier)+'</span><span>'+(d.rank===1?'♛ ':'')+(d.rank??'—')+'</span></td><td class="deck-column"><div class="deckcell">'+faces(d)+'</div></td><td data-label="強度分數" title="貝葉斯修正勝率 '+fmt(d.adjusted)+'">'+score(d)+'</td><td data-label="勝率">'+fmt(d.wr)+'</td><td data-label="出場率">'+fmt(d.pick)+'</td><td data-label="樣本數">'+d.n.toLocaleString()+'</td><td data-label="BP 強度"><span class="confidence">'+d.bp+' '+esc(d.confidence)+'</span></td></tr>').join('')+'</tbody>':''}).join('')+'</table></div>'}
function matchup(ref,d){return ref?.matchups?.[d.id]??null}
function mini(list,kind,ref,limit=3){return list.length?list.slice(0,limit).map((d,i)=>{const m=matchup(ref,d);return '<div class="mini-row" tabindex="0" role="button" data-deck="'+d.id+'"><span>'+(kind==='trend'?i+1:'VS')+'</span>'+faces(d)+'<span class="mini-name" title="'+esc(d.name)+'">'+esc(d.name)+'</span><strong class="'+color(kind==='trend'?d.delta:m.wr-50)+'">'+(kind==='trend'?delta(d.delta):fmt(m.wr)+' · '+m.n+' 場')+'</strong></div>'}).join(''):'<div class="empty">樣本不足或無符合條件資料</div>'}
function draw(){const list=rows(),ref=selected();state.selected=ref?.id??null;
$$('[data-tab]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.tab===state.tab)));$$('[data-view]').forEach(b=>{b.setAttribute('aria-pressed',String(b.dataset.view===state.view));b.disabled=state.tab!=='ranking'});
const opp=ref?all.filter(d=>d.id!==ref.id&&matchup(ref,d)):[],best=[...opp].filter(d=>matchup(ref,d).wr>50).sort((a,b)=>matchup(ref,b).wr-matchup(ref,a).wr),worst=[...opp].filter(d=>matchup(ref,d).wr<50).sort((a,b)=>matchup(ref,a).wr-matchup(ref,b).wr);
$('#srm-main').innerHTML=ranking(list);
$('#srm-rise').innerHTML=mini([...list].filter(d=>d.delta>0).sort((a,b)=>b.delta-a.delta),'trend');$('#srm-fall').innerHTML=mini([...list].filter(d=>d.delta<0).sort((a,b)=>a.delta-b.delta),'trend');
const sum=payload.costs.reduce((a,b)=>a+b.n,0),colors=['#aa83e1','#f1c44e','#69aaf0','#6ac986'];let pos=0;const stops=payload.costs.map((c,i)=>{const p=pos;pos+=sum?100*c.n/sum:0;return colors[i%4]+' '+p+'% '+pos+'%'});
$('#srm-donut').innerHTML=sum?'<div class="donut" style="background:conic-gradient('+stops.join(',')+')"><div class="donut-inner"><b>'+sum.toLocaleString()+'</b><small>隊伍樣本</small></div></div><div class="donut-legend">'+payload.costs.map((c,i)=>'<span><i style="background:'+colors[i%4]+'"></i>'+esc(c.label)+'<b>'+fmt(100*c.n/sum)+'</b></span>').join('')+'</div>':'<div class="empty">無有效樣本</div>';
const valid=list.filter(d=>d.pick!=null),max=Math.max(1,...valid.map(d=>d.pick)),xmax=Math.ceil(max/Math.max(1,Math.ceil(max/5)))*Math.max(1,Math.ceil(max/5)),ymin=Math.max(0,Math.floor(Math.min(50,...valid.map(d=>d.wr))/10)*10-10),ymax=Math.min(100,Math.ceil(Math.max(50,...valid.map(d=>d.wr))/10)*10+10),X=x=>30+x/xmax*244,Y=y=>113-(y-ymin)/(ymax-ymin)*96;
$('#srm-plot').innerHTML=valid.length?'<svg class="scatter" viewBox="0 0 286 142" aria-label="勝率與出場率分布">'+Array.from({length:5},(_,i)=>ymin+(ymax-ymin)*i/4).map(v=>'<line x1="30" x2="274" y1="'+Y(v)+'" y2="'+Y(v)+'" stroke="#8b7ca3" opacity=".35"/><text x="26" y="'+(Y(v)+3)+'" text-anchor="end">'+v.toFixed(0)+'%</text>').join('')+Array.from({length:4},(_,i)=>xmax*i/3).map(v=>'<text x="'+X(v)+'" y="126" text-anchor="middle">'+v.toFixed(1)+'%</text>').join('')+valid.map(d=>'<circle data-deck="'+d.id+'" cx="'+X(d.pick)+'" cy="'+Y(d.wr)+'" r="'+(ref?.id===d.id?4:2.6)+'" fill="'+(d.tier==='S'?'#efcb76':'#bd7bea')+'"><title>'+esc(d.name)+'｜'+fmt(d.wr)+'｜'+d.n+' 場</title></circle>').join('')+'<text x="150" y="140" text-anchor="middle">出場率</text></svg>':'<div class="empty">無有效樣本</div>';
$('#srm-best').innerHTML=mini(best,'match',ref);$('#srm-worst').innerHTML=mini(worst,'match',ref);
$('#srm-selected').innerHTML=ref?'目前選定：'+esc(ref.name)+' · <a href="'+esc(ref.href)+'">完整牌組分析</a>':'尚無選定牌組';
}
function navigate(changes){const params=new URLSearchParams({days:state.days,cost:state.cost,bp:state.bp,mode:state.rule,...changes});location.search=params.toString()}
const preview=document.createElement('div');preview.className='portrait-preview';preview.hidden=true;preview.setAttribute('role','tooltip');preview.innerHTML='<img alt=""><b></b>';root.append(preview);
let active=null;
function hide(){active=null;preview.hidden=true}
function show(b){if(!b?.dataset.cardPreview)return;active=b;preview.querySelector('img').src=b.dataset.cardPreview;preview.querySelector('img').alt=b.dataset.cardName;preview.querySelector('b').textContent=b.dataset.cardName;preview.hidden=false;const r=b.getBoundingClientRect();preview.style.left=Math.max(8,Math.min(innerWidth-196,r.right+8))+'px';preview.style.top=Math.max(8,Math.min(innerHeight-310,r.top))+'px'}
root.addEventListener('pointerover',e=>{const b=e.target.closest('.portrait-btn');if(b&&!b.contains(e.relatedTarget))show(b)});
root.addEventListener('pointerout',e=>{const b=e.target.closest('.portrait-btn');if(b&&!b.contains(e.relatedTarget))hide()});
root.addEventListener('focusin',e=>{const b=e.target.closest('.portrait-btn');if(b)show(b)});
root.addEventListener('focusout',e=>{if(e.target.closest('.portrait-btn'))hide()});
document.addEventListener('keydown',e=>{if(e.key==='Escape')hide()});window.addEventListener('scroll',hide,true);window.addEventListener('resize',hide);
root.addEventListener('click',e=>{const card=e.target.closest('.portrait-btn');if(card){show(card);return}hide();const b=e.target.closest('[data-filter] button,[data-tab],[data-view],[data-deck],#srm-clear');if(!b)return;if(b.id==='srm-clear'){state.q='';$('#srm-search').value=''}else if(b.dataset.tab)state.tab=b.dataset.tab;else if(b.dataset.view)state.view=b.dataset.view;else if(b.dataset.deck!==undefined)state.selected=b.dataset.deck;else{const k=b.parentElement.dataset.filter;return navigate(k==='rule'?{mode:b.dataset.value,cost:''}:{[k]:b.dataset.value})}draw()});
root.addEventListener('keydown',e=>{if((e.key==='Enter'||e.key===' ')&&e.target.matches('[data-deck]')){e.preventDefault();state.selected=e.target.dataset.deck;draw()}});
$('#srm-bp').addEventListener('change',e=>navigate({bp:e.target.value}));$('#srm-search').addEventListener('input',e=>{state.q=e.target.value.trim().toLowerCase();draw()});draw();
})();

</script>
<?php $pageContent=ob_get_clean();include __DIR__."/../layout/base.php"; ?>
