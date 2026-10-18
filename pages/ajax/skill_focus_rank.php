<?php
require_once __DIR__ . '/../../config.php';
$reqType = trim($_GET['req_type'] ?? '');
$reqOp = trim($_GET['req_op'] ?? 'ge');
$reqQtyRaw = trim($_GET['req_qty'] ?? '');

if (!in_array($reqType, ['', '0', '1', '2', '3', '4', '5', '0,1', '0,1,2'], true)) {
  $reqType = '';
}

if (!in_array($reqOp, ['ge', 'eq', 'le'], true)) {
  $reqOp = 'ge';
}

$reqQty = $reqQtyRaw === '' ? null : (int)$reqQtyRaw;

if ($reqQty !== null && ($reqQty < 0 || $reqQty > 9)) {
  $reqQty = null;
}
$minCount = (int)($_GET['min_count'] ?? 3);
$onlyGe = (int)($_GET['only_ge'] ?? 1);
$q = trim($_GET['q'] ?? '');
$reqType = trim($_GET['req_type'] ?? '');
$reqOp = trim($_GET['req_op'] ?? 'ge');
$reqQtyRaw = trim($_GET['req_qty'] ?? '');

if (!in_array($reqType, ['', '0', '1', '2', '3', '4', '5', '0,1', '0,1,2'], true)) {
  $reqType = '';
}

if (!in_array($reqOp, ['ge', 'eq', 'le'], true)) {
  $reqOp = 'ge';
}

$reqQty = $reqQtyRaw === '' ? null : (int)$reqQtyRaw;

if ($reqQty !== null && ($reqQty < 0 || $reqQty > 9)) {
  $reqQty = null;
}
$cardPick = $_GET['card_pick'] ?? 'min';

if (!in_array($cardPick, ['min', 'max'], true)) {
  $cardPick = 'min';
}

$cardRankAgg = $cardPick === 'max' ? 'MAX' : 'MIN';

$phaseInput = $_GET['phase'] ?? ['0', '1', '2'];

if (!is_array($phaseInput)) {
  $phaseInput = [$phaseInput];
}

$phases = array_values(array_intersect($phaseInput, ['0', '1', '2']));

if (empty($phases)) {
  $phases = ['0', '1', '2'];
}

$distanceInput = $_GET['distance'] ?? ['0', '1', '2'];

if (!is_array($distanceInput)) {
  $distanceInput = [$distanceInput];
}

$distances = array_values(array_intersect($distanceInput, ['0', '1', '2']));

if (empty($distances)) {
  $distances = ['0', '1', '2'];
}

if ($minCount < 1) {
  $minCount = 1;
}

$params = [];

$phasePlaceholders = [];

foreach ($phases as $i => $p) {
  $key = ':phase_' . $i;
  $phasePlaceholders[] = $key;
  $params[$key] = (int)$p;
}

$phaseWhere = "s.phase IN (" . implode(',', $phasePlaceholders) . ")";

$distanceWhere = "";

if (count($distances) < 3) {
  $distancePlaceholders = [];

  foreach ($distances as $i => $d) {
    $key = ':distance_' . $i;
    $distancePlaceholders[] = $key;
    $params[$key] = $d;
  }

  $distanceWhere = "AND dm.distance_code IN (" . implode(',', $distancePlaceholders) . ")";
}

$requireWhere = "";
if ($onlyGe === 1) {
  $requireWhere = "
    AND s.require_json IS NOT NULL
    AND s.require_json REGEXP '\"num\"[[:space:]]*:[[:space:]]*0'
    AND s.require_json NOT REGEXP '\"num\"[[:space:]]*:[[:space:]]*[12]'
  ";
}

$skillNameSql = "s.name_tcn";
$keywordHitSql = "0 AS keyword_hit_count";
$keywordFilterSql = "";

if ($q !== '') {
  $skillNameSql = "
  CONCAT(
    CASE
      WHEN (
        cs.chara_name LIKE :focus_q
        OR s.name_tcn LIKE :focus_q
        OR s.info_tcn LIKE :focus_q
        OR s.note_player_tcn LIKE :focus_q
        OR s.skill_code LIKE :focus_q
        OR EXISTS (
          SELECT 1
          FROM unlight_skill_tag stq
          WHERE stq.skill_code = s.skill_code
            AND stq.tag LIKE :focus_q
        )
      )
      THEN '[[HIT]]'
      ELSE ''
    END,
    s.name_tcn,
    CASE
      WHEN (
        cs.chara_name LIKE :focus_q
        OR s.name_tcn LIKE :focus_q
        OR s.info_tcn LIKE :focus_q
        OR s.note_player_tcn LIKE :focus_q
        OR s.skill_code LIKE :focus_q
        OR EXISTS (
          SELECT 1
          FROM unlight_skill_tag stq
          WHERE stq.skill_code = s.skill_code
            AND stq.tag LIKE :focus_q
        )
      )
      THEN '[[/HIT]]'
      ELSE ''
    END
  )
";
  $keywordHitSql = "
    COUNT(DISTINCT CASE
      WHEN (
        cs.chara_name LIKE :focus_q
        OR s.name_tcn LIKE :focus_q
        OR s.info_tcn LIKE :focus_q
        OR s.note_player_tcn LIKE :focus_q
        OR s.skill_code LIKE :focus_q
        OR EXISTS (
          SELECT 1
          FROM unlight_skill_tag stq
          WHERE stq.skill_code = s.skill_code
            AND stq.tag LIKE :focus_q
        )
      )
      THEN s.skill_code
      ELSE NULL
    END) AS keyword_hit_count
  ";

  $keywordFilterSql = "AND keyword_hit_count > 0";
  $params[':focus_q'] = '%' . $q . '%';
}

$params[':min_count'] = $minCount;

$sql = "
WITH distance_map AS (
  SELECT '0' AS distance_code, '近' AS distance_name
  UNION ALL SELECT '1', '中'
  UNION ALL SELECT '2', '遠'
),
phase_map AS (
  SELECT 0 AS phase, '攻擊' AS phase_name
  UNION ALL SELECT 1, '防禦'
  UNION ALL SELECT 2, '移動'
),
released_cards AS (
  SELECT
    c.*,
    CASE
      WHEN c.rarity <= 5 AND c.official_level = 1 THEN cu.L1
      WHEN c.rarity <= 5 AND c.official_level = 2 THEN cu.L2
      WHEN c.rarity <= 5 AND c.official_level = 3 THEN cu.L3
      WHEN c.rarity <= 5 AND c.official_level = 4 THEN cu.L4
      WHEN c.rarity <= 5 AND c.official_level = 5 THEN cu.L5
      WHEN c.rarity > 5 AND c.official_level = 1 THEN cu.R1
      WHEN c.rarity > 5 AND c.official_level = 2 THEN cu.R2
      WHEN c.rarity > 5 AND c.official_level = 3 THEN cu.R3
      WHEN c.rarity > 5 AND c.official_level = 4 THEN cu.R4
      WHEN c.rarity > 5 AND c.official_level = 5 THEN cu.R5
      ELSE NULL
    END AS release_cost
  FROM unlight c
  JOIN cost_unlight cu
    ON cu.ID = CAST(REPLACE(c.chara_code, 'cc', '') AS UNSIGNED)
),
card_skills AS (
  SELECT
    c.id AS card_id,
    c.chara_code,
    c.name AS chara_name,
    c.level AS card_level,
    c.official_level,
    c.rarity,
    CASE
      WHEN c.rarity <= 5 THEN c.official_level
      ELSE 5 + c.official_level
    END AS card_rank_order,
    1 AS skill_no,
    c.skill1_code COLLATE utf8mb4_unicode_ci AS skill_code
  FROM released_cards c
  WHERE c.skill1_code IS NOT NULL
    AND c.release_cost IS NOT NULL
    AND c.release_cost > 0

  UNION ALL

  SELECT
    c.id,
    c.chara_code,
    c.name,
    c.level,
    c.official_level,
    c.rarity,
    CASE
      WHEN c.rarity <= 5 THEN c.official_level
      ELSE 5 + c.official_level
    END,
    2 AS skill_no,
    c.skill2_code COLLATE utf8mb4_unicode_ci
  FROM released_cards c
  WHERE c.skill2_code IS NOT NULL
    AND c.release_cost IS NOT NULL
    AND c.release_cost > 0

  UNION ALL

  SELECT
    c.id,
    c.chara_code,
    c.name,
    c.level,
    c.official_level,
    c.rarity,
    CASE
      WHEN c.rarity <= 5 THEN c.official_level
      ELSE 5 + c.official_level
    END,
    3 AS skill_no,
    c.skill3_code COLLATE utf8mb4_unicode_ci
  FROM released_cards c
  WHERE c.skill3_code IS NOT NULL
    AND c.release_cost IS NOT NULL
    AND c.release_cost > 0

  UNION ALL

  SELECT
    c.id,
    c.chara_code,
    c.name,
    c.level,
    c.official_level,
    c.rarity,
    CASE
      WHEN c.rarity <= 5 THEN c.official_level
      ELSE 5 + c.official_level
    END,
    4 AS skill_no,
    c.skill4_code COLLATE utf8mb4_unicode_ci
  FROM released_cards c
  WHERE c.skill4_code IS NOT NULL
    AND c.release_cost IS NOT NULL
    AND c.release_cost > 0
),
card_distance_count AS (
  SELECT
    cs.card_id,
    cs.chara_code,
    cs.chara_name,
    cs.card_level,
    cs.official_level,
    cs.rarity,
    cs.card_rank_order,
    dm.distance_code,
    dm.distance_name,
    COUNT(DISTINCT s.skill_code) AS skill_count,
    GROUP_CONCAT(
  DISTINCT CONCAT(pm.phase_name, '/', $skillNameSql)
  ORDER BY cs.skill_no
  SEPARATOR '、'
) AS skills,
GROUP_CONCAT(
  DISTINCT CONCAT(s.skill_code, '::', TO_BASE64(COALESCE(s.require_json, '')))
  SEPARATOR '§§'
) AS require_blob,
$keywordHitSql
  FROM card_skills cs
  JOIN unlight_skill s
    ON s.skill_code = cs.skill_code
  JOIN distance_map dm
    ON FIND_IN_SET(dm.distance_code, s.range_mask) > 0
  JOIN phase_map pm
    ON pm.phase = s.phase
  WHERE $phaseWhere
    AND s.range_mask IS NOT NULL
    $distanceWhere
    $requireWhere
  GROUP BY
    cs.card_id,
    cs.chara_code,
    cs.chara_name,
    cs.card_level,
    cs.official_level,
    cs.rarity,
    cs.card_rank_order,
    dm.distance_code,
    dm.distance_name
    ),
    eligible_rows AS (
      SELECT *
      FROM card_distance_count
      WHERE 1 = 1
        $keywordFilterSql
    ),
    chara_max AS (
      SELECT
        chara_name,
        MAX(skill_count) AS max_skill_count
      FROM eligible_rows
      GROUP BY chara_name
    ),
    chara_pick_rank AS (
  SELECT
    cdc.chara_name,
    cdc.skill_count,
    $cardRankAgg(cdc.card_rank_order) AS pick_card_rank
  FROM eligible_rows cdc
  JOIN chara_max cm
    ON cm.chara_name = cdc.chara_name
   AND cm.max_skill_count = cdc.skill_count
  GROUP BY cdc.chara_name, cdc.skill_count
),
    final_rows AS (
  SELECT
    cdc.*
  FROM eligible_rows cdc
  JOIN chara_pick_rank cpr
    ON cpr.chara_name = cdc.chara_name
   AND cpr.skill_count = cdc.skill_count
   AND cpr.pick_card_rank = cdc.card_rank_order
),
    final_grouped AS (
      SELECT
        MIN(fr.card_id) AS card_id,
        fr.chara_name,
        fr.chara_code,
        MIN(fr.rarity) AS rarity,
        CASE
          WHEN fr.card_rank_order <= 5 THEN CONCAT('L', fr.card_rank_order)
          ELSE CONCAT('R', fr.card_rank_order - 5)
        END AS card_level,
        CASE
          WHEN fr.card_rank_order <= 5 THEN fr.card_rank_order
          ELSE fr.card_rank_order - 5
        END AS official_level,
        fr.card_rank_order,
        fr.skill_count,
        GROUP_CONCAT(
          DISTINCT fr.distance_name
          ORDER BY CAST(fr.distance_code AS UNSIGNED)
          SEPARATOR '・'
        ) AS distance_name,
        fr.skills AS skills,
GROUP_CONCAT(DISTINCT fr.require_blob SEPARATOR '§§') AS require_blob
      FROM final_rows fr
      GROUP BY
        fr.chara_name,
        fr.chara_code,
        fr.card_rank_order,
        fr.skill_count,
        fr.skills

    )
    SELECT
  card_id,
  chara_name,
  chara_code,
  rarity,
  card_level,
  official_level,
  distance_name,
  skill_count,
  skills,
  require_blob
FROM final_grouped
    WHERE skill_count >= :min_count
    ORDER BY
      skill_count DESC,
      chara_name ASC,
      card_rank_order ASC
    LIMIT 100
    ";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h($v): string
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}
function ul_req_type_key($type): string
{
  if (is_array($type)) {
    $vals = array_map('intval', $type);
    sort($vals);
    return implode(',', $vals);
  }

  return (string)(int)$type;
}

function ul_req_type_label(string $key): string
{
  return match ($key) {
    '0' => '劍',
    '1' => '槍',
    '2' => '盾',
    '3' => '移',
    '4' => '特',
    '5' => '任意',
    '0,1' => '劍/槍',
    '0,1,2' => '劍/槍/盾',
    default => $key,
  };
}

function ul_req_weight(string $key): float
{
  return match ($key) {
    '3', '4' => 2.0, // 移、特
    default => 1.0,
  };
}

function ul_req_order(string $key): int
{
  return match ($key) {
    '0' => 10,
    '1' => 20,
    '2' => 30,
    '4' => 40,
    '3' => 50,
    '5' => 60,
    '0,1' => 70,
    '0,1,2' => 80,
    default => 99,
  };
}

function ul_format_req_piece(string $key, int $qty, string $op): string
{
  return ul_req_type_label($key) . $qty . $op;
}

function ul_calc_focus_resource(?string $blob, int $skillCount): array
{
  $perSkill = [];
  $seenSkill = [];

  foreach (explode('§§', (string)$blob) as $entry) {
    if ($entry === '' || !str_contains($entry, '::')) continue;

    [$skillCode, $encoded] = explode('::', $entry, 2);
    $skillCode = trim($skillCode);

    if ($skillCode === '' || isset($seenSkill[$skillCode])) continue;
    $seenSkill[$skillCode] = true;

    $json = base64_decode($encoded, true);
    if ($json === false || $json === '') continue;

    $reqs = json_decode($json, true);
    if (!is_array($reqs)) continue;

    $skillReq = [
      'ge' => [],
      'eq' => [],
      'le' => [],
    ];

    foreach ($reqs as $r) {
      if (!is_array($r) || !isset($r['type'], $r['quantity'])) continue;

      $qty = (int)$r['quantity'];
      $num = (int)($r['num'] ?? 0);

      if ($qty <= 0) continue;

      $key = ul_req_type_key($r['type']);

      if ($num === 0) {
        $skillReq['ge'][$key] = ($skillReq['ge'][$key] ?? 0) + $qty;
      } elseif ($num === 1) {
        $skillReq['eq'][$key] = ($skillReq['eq'][$key] ?? 0) + $qty;
      } elseif ($num === 2) {
        $skillReq['le'][$key] = max($skillReq['le'][$key] ?? 0, $qty);
      }
    }

    $perSkill[] = $skillReq;
  }


  $commonGe = [];
  $commonEq = [];
  $limitLe = [];

  foreach ($perSkill as $skillReq) {
    foreach ($skillReq['ge'] as $key => $qty) {
      $commonGe[$key] = max($commonGe[$key] ?? 0, $qty);
    }

    foreach ($skillReq['eq'] as $key => $qty) {
      $commonEq[$key] = max($commonEq[$key] ?? 0, $qty);
    }

    foreach ($skillReq['le'] as $key => $qty) {
      $limitLe[$key] = max($limitLe[$key] ?? 0, $qty);
    }
  }

  $sorter = fn($a, $b) => ul_req_order($a) <=> ul_req_order($b);
  uksort($commonGe, $sorter);
  uksort($commonEq, $sorter);
  uksort($limitLe, $sorter);

  $pieces = [];
  $rawCost = 0;
  $weightedCost = 0.0;

  foreach ($commonGe as $key => $qty) {
    $pieces[] = ul_format_req_piece($key, $qty, '↑');
    $rawCost += $qty;
    $weightedCost += $qty * ul_req_weight($key);
  }

  foreach ($commonEq as $key => $qty) {
    $pieces[] = ul_format_req_piece($key, $qty, '=');
    $rawCost += $qty;
    $weightedCost += $qty * ul_req_weight($key);
  }

  foreach ($limitLe as $key => $qty) {
    $pieces[] = ul_format_req_piece($key, $qty, '↓');
  }

  $resourceSumMap = [];

  foreach ($perSkill as $skillReq) {
    foreach ($skillReq['ge'] as $key => $qty) {
      $resourceSumMap[$key] = ($resourceSumMap[$key] ?? 0) + $qty;
    }

    foreach ($skillReq['eq'] as $key => $qty) {
      $resourceSumMap[$key] = ($resourceSumMap[$key] ?? 0) + $qty;
    }
  }

  $label = $pieces ? implode('・', $pieces) : '-';
  $weightedCost = round($weightedCost, 2);
  $efficiency = $weightedCost > 0 ? round($skillCount / $weightedCost, 3) : 0;

  return [
    'label' => $label,
    'raw_cost' => $rawCost,
    'weighted_cost' => $weightedCost,
    'efficiency' => $efficiency,
    'resource_sum_map' => $resourceSumMap,
  ];
}
function ul_focus_resource_sum_match(array $resInfo, string $reqType, string $reqOp, ?int $reqQty): bool
{
  if ($reqType === '' || $reqQty === null) {
    return true;
  }

  $value = (int)($resInfo['resource_sum_map'][$reqType] ?? 0);

  if ($reqOp === 'ge') {
    return $value >= $reqQty;
  }

  if ($reqOp === 'eq') {
    return $value === $reqQty;
  }

  return $value <= $reqQty;
}


if (!$rows) {
  echo '<div class="ul-card"><div class="text-muted small">沒有找到符合條件的集中度排行</div></div>';
  exit;
}

echo '<div class="skill-focus-result-note">技能集中度結果：最多顯示 100 筆，可點表頭排序</div>';
echo '<div class="skill-focus-table-wrap">';
echo '<table class="skill-focus-table js-sort-table">';
echo '<thead>';
echo '<tr>';
echo '<th data-sort-type="text">角色</th>';
echo '<th data-sort-type="level">顯示卡片</th>';
echo '<th data-sort-type="text">距離</th>';
echo '<th data-sort-type="number">技能數</th>';
echo '<th>技能</th>';
echo '<th data-sort-type="number" title="加權成本＝需求資源數 × 權重。權重：劍=1、槍=1、盾=1、特=2、移=2、無=1">所需成本(加權) <span class="skill-th-help">?</span></th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

$displayRows = 0;

foreach ($rows as $r) {
  $resInfo = ul_calc_focus_resource($r['require_blob'] ?? '', (int)$r['skill_count']);

  if (!ul_focus_resource_sum_match($resInfo, $reqType, $reqOp, $reqQty)) {
    continue;
  }

  $displayRows++;

  $skillText = h($r['skills']);
  $skillText = str_replace('[[HIT]]', '<span class="skill-keyword-hit">', $skillText);
  $skillText = str_replace('[[/HIT]]', '</span>', $skillText);

  $skillText = str_replace(
    '攻擊/',
    '<span class="skill-phase skill-phase-atk">攻擊</span><span class="skill-phase-slash">/</span>',
    $skillText
  );

  $skillText = str_replace(
    '防禦/',
    '<span class="skill-phase skill-phase-def">防禦</span><span class="skill-phase-slash">/</span>',
    $skillText
  );

  $skillText = str_replace(
    '移動/',
    '<span class="skill-phase skill-phase-mov">移動</span><span class="skill-phase-slash">/</span>',
    $skillText
  );

  $skillText = str_replace('、', '<span class="skill-focus-sep">、</span>', $skillText);
  $resInfo = ul_calc_focus_resource($r['require_blob'] ?? '', (int)$r['skill_count']);
  echo '<tr>';
  $cardId = (int)$r['card_id'];
  $charSeoText = $r['card_level'] . $r['chara_name'];
  $charUrl = '/pages/analysis_character_card.php'
    . '?char_id=' . $cardId
    . '&char=' . urlencode($charSeoText)
    . '&days=14'
    . '&tab=skill';

  echo '<td class="skill-focus-char" data-sort="' . h($r['chara_name']) . '">
  <a class="skill-focus-char-link"
    href="' . h($charUrl) . '"
    target="_blank"
    rel="noopener noreferrer">'
    . h($r['chara_name']) .
    '</a>
</td>';

  echo '<td class="skill-focus-card" data-sort="' . h($r['card_level']) . '">
  <a class="skill-focus-card-link"
    href="' . h($charUrl) . '"
    target="_blank"
    rel="noopener noreferrer">'
    . h($r['card_level']) .
    '</a>
</td>';

  echo '<td class="skill-focus-distance" data-sort="' . h($r['distance_name']) . '">' . h($r['distance_name']) . '</td>';
  echo '<td class="skill-focus-count" data-sort="' . (int)$r['skill_count'] . '"><strong>' . h($r['skill_count']) . '</strong></td>';
  echo '<td class="skill-focus-skills">' . $skillText . '</td>';
  echo '<td class="skill-focus-weighted-cost" data-sort="' . h($resInfo['weighted_cost']) . '">' . number_format((float)$resInfo['weighted_cost'], 2) . '</td>';
  echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>';
