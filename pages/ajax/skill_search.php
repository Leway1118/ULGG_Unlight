<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/_skill_render.php';

$isAdmin = ($_SESSION['permission'] ?? 0) >= 2;

$q     = trim($_GET['q'] ?? '');
$phaseInput = $_GET['phase'] ?? ['0', '1', '2'];

if (!is_array($phaseInput)) {
  $phaseInput = [$phaseInput];
}

$phases = array_values(array_intersect($phaseInput, ['0', '1', '2']));

if (empty($phases)) {
  $phases = ['0', '1', '2'];
}

$rangeInput = $_GET['range'] ?? ['0', '1', '2'];

if (!is_array($rangeInput)) {
  $rangeInput = [$rangeInput];
}

$ranges = array_values(array_intersect($rangeInput, ['0', '1', '2']));

if (empty($ranges)) {
  $ranges = ['0', '1', '2'];
}
$tag   = trim($_GET['tag'] ?? '');

$where = [];
$params = [];

$where[] = "s.skill_code IS NOT NULL";

if ($q !== '') {
  $where[] = "(
    cs.chara_name LIKE :q
    OR s.name_tcn LIKE :q
    OR s.info_tcn LIKE :q
    OR s.note_player_tcn LIKE :q
    OR s.skill_code LIKE :q
    OR EXISTS (
      SELECT 1
      FROM unlight_skill_tag stq
      WHERE stq.skill_code = s.skill_code
        AND stq.tag LIKE :q
    )
  )";
  $params[':q'] = '%' . $q . '%';
}

if (count($phases) < 3) {
  $phasePlaceholders = [];

  foreach ($phases as $i => $p) {
    $key = ':phase_' . $i;
    $phasePlaceholders[] = $key;
    $params[$key] = (int)$p;
  }

  $where[] = "s.phase IN (" . implode(',', $phasePlaceholders) . ")";
}

if (count($ranges) < 3) {
  $rangeConditions = [];

  foreach ($ranges as $i => $r) {
    $key = ':range_' . $i;
    $rangeConditions[] = "FIND_IN_SET($key, s.range_mask) > 0";
    $params[$key] = $r;
  }

  $where[] = "(" . implode(' OR ', $rangeConditions) . ")";
}

if ($tag !== '') {
  $where[] = "EXISTS (
    SELECT 1
    FROM unlight_skill_tag st2
    WHERE st2.skill_code = s.skill_code
      AND st2.tag = :tag
  )";
  $params[':tag'] = $tag;
}

$whereSql = implode("\nAND ", $where);

$sql = "
WITH card_skills AS (
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
    CASE
      WHEN c.rarity <= 5 THEN CONCAT('L', c.official_level)
      ELSE CONCAT('R', c.official_level)
    END AS card_level_label,
    c.skill1_code COLLATE utf8mb4_unicode_ci AS skill_code
  FROM unlight c
  WHERE c.skill1_code IS NOT NULL

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
    CASE
      WHEN c.rarity <= 5 THEN CONCAT('L', c.official_level)
      ELSE CONCAT('R', c.official_level)
    END,
    c.skill2_code COLLATE utf8mb4_unicode_ci
  FROM unlight c
  WHERE c.skill2_code IS NOT NULL

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
    CASE
      WHEN c.rarity <= 5 THEN CONCAT('L', c.official_level)
      ELSE CONCAT('R', c.official_level)
    END,
    c.skill3_code COLLATE utf8mb4_unicode_ci
  FROM unlight c
  WHERE c.skill3_code IS NOT NULL

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
    CASE
      WHEN c.rarity <= 5 THEN CONCAT('L', c.official_level)
      ELSE CONCAT('R', c.official_level)
    END,
    c.skill4_code COLLATE utf8mb4_unicode_ci
  FROM unlight c
  WHERE c.skill4_code IS NOT NULL
),
skill_group AS (
  SELECT
    cs.chara_code,
    cs.chara_name,
    CASE
      WHEN s.name_tcn LIKE 'Ex%' THEN SUBSTRING(s.name_tcn, 3)
      ELSE s.name_tcn
    END AS skill_base_name,
    s.phase,
    s.range_mask,
    MIN(cs.card_rank_order) AS min_card_rank,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        cs.card_id
        ORDER BY cs.card_rank_order, cs.card_id
        SEPARATOR ','
      ),
      ',',
      1
    ) AS min_card_id,
    SUBSTRING_INDEX(
      GROUP_CONCAT(
        cs.skill_code
        ORDER BY
          CASE WHEN s.name_tcn LIKE 'Ex%' THEN 1 ELSE 0 END ASC,
          cs.card_rank_order DESC,
          cs.card_id DESC
        SEPARATOR ','
      ),
      ',',
      1
    ) AS display_skill_code,
    GROUP_CONCAT(
      DISTINCT cs.skill_code
      ORDER BY cs.card_rank_order
      SEPARATOR ','
    ) AS skill_codes_csv,
    GROUP_CONCAT(
      DISTINCT cs.card_level_label
      ORDER BY cs.card_rank_order
      SEPARATOR ', '
    ) AS available_levels
  FROM card_skills cs
  JOIN unlight_skill s
    ON s.skill_code = cs.skill_code
  WHERE $whereSql
  GROUP BY
    cs.chara_code,
    cs.chara_name,
    skill_base_name,
    s.phase,
    s.range_mask
),
skill_result AS (
  SELECT
    sg.chara_code,
    sg.chara_name,
    sg.skill_base_name,
    sg.display_skill_code AS skill_code,
    sg.skill_codes_csv,
    sg.min_card_rank,
    sg.min_card_id,
    sg.available_levels,
    CASE
      WHEN sg.min_card_rank <= 5 THEN CONCAT('L', sg.min_card_rank)
      ELSE CONCAT('R', sg.min_card_rank - 5)
    END AS min_level_label,
    s.name_tcn,
    s.info_tcn,
    s.note_player_tcn,
    s.phase,
    s.range_mask,
    s.require_json
  FROM skill_group sg
  JOIN unlight_skill s
    ON s.skill_code = sg.display_skill_code
)
SELECT *
FROM skill_result
ORDER BY
  chara_name ASC,
  min_card_rank ASC,
  skill_code ASC
LIMIT 100
";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
  echo '<div class="ul-card"><div class="text-muted small">沒有找到符合條件的技能</div></div>';
  exit;
}

$skillCodes = [];

foreach ($rows as $row) {
  foreach (explode(',', (string)($row['skill_codes_csv'] ?? $row['skill_code'])) as $code) {
    $code = trim($code);
    if ($code !== '') {
      $skillCodes[] = $code;
    }
  }
}

$skillCodes = [];

foreach ($rows as $row) {
  foreach (explode(',', (string)($row['skill_codes_csv'] ?? $row['skill_code'])) as $code) {
    $code = trim($code);
    if ($code !== '') {
      $skillCodes[] = $code;
    }
  }
}

$skillCodes = array_values(array_unique($skillCodes));
$skillTags = [];

if ($skillCodes) {
  $in = implode(',', array_fill(0, count($skillCodes), '?'));
  $stmt = $db->prepare("
    SELECT skill_code, tag
    FROM unlight_skill_tag
    WHERE skill_code IN ($in)
    ORDER BY tag ASC
  ");
  $stmt->execute($skillCodes);

  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $skillTags[$r['skill_code']][] = $r['tag'];
  }
}

function skill_phase_text($phase): string
{
  return match ((int)$phase) {
    0 => '攻擊',
    1 => '防禦',
    2 => '移動',
    default => '-',
  };
}

function skill_range_text(?string $rangeMask): string
{
  if ($rangeMask === null || $rangeMask === '') return '-';

  $map = [
    '0' => '近',
    '1' => '中',
    '2' => '遠',
  ];

  $parts = [];
  foreach (explode(',', $rangeMask) as $r) {
    $r = trim($r);
    if (isset($map[$r])) {
      $parts[] = $map[$r];
    }
  }

  return $parts ? implode('・', $parts) : '-';
}

echo '<div class="skill-focus-result-note">搜尋結果：' . count($rows) . ' 筆，最多顯示 100 筆</div>';
echo '<div class="skill-focus-table-wrap">';
echo '<table class="skill-focus-table skill-search-list-table">';
echo '<thead>';
echo '<tr>';
echo '<th>角色</th>';
echo '<th>最低卡片</th>';
echo '<th>階段</th>';
echo '<th>距離</th>';
echo '<th>條件</th>';
echo '<th>技能</th>';
echo '<th>效果</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

foreach ($rows as $row) {
  $cardId = (int)($row['min_card_id'] ?? 0);
  $charSeoText = ($row['min_level_label'] ?? '') . ($row['chara_name'] ?? '');
  $charUrl = '/pages/analysis_character_card.php'
    . '?char_id=' . $cardId
    . '&char=' . urlencode($charSeoText)
    . '&days=14'
    . '&tab=skill';

  $phaseText = skill_phase_text($row['phase'] ?? null);
  $rangeText = skill_range_text($row['range_mask'] ?? null);
  $requireHtml = ul_format_skill_require($row['require_json'] ?? null);
  $infoText = trim((string)($row['info_tcn'] ?? ''));
  $tags = [];

  foreach (explode(',', (string)($row['skill_codes_csv'] ?? $row['skill_code'])) as $code) {
    $code = trim($code);
    if ($code !== '' && !empty($skillTags[$code])) {
      foreach ($skillTags[$code] as $t) {
        $tags[] = $t;
      }
    }
  }

  $tags = array_values(array_unique($tags));

  echo '<tr>';

  echo '<td class="skill-focus-char">
    <a class="skill-focus-char-link" href="' . h_skill($charUrl) . '">'
    . h_skill($row['chara_name']) .
    '</a>
  </td>';

  echo '<td class="skill-focus-card">
    <a class="skill-focus-card-link" href="' . h_skill($charUrl) . '">'
    . h_skill($row['min_level_label']) .
    '</a>
  </td>';

  echo '<td class="skill-search-phase">' . h_skill($phaseText) . '</td>';
  echo '<td class="skill-focus-distance">' . h_skill($rangeText) . '</td>';

  echo '<td class="skill-search-require">';
  echo $requireHtml ?: '<span class="text-muted small">-</span>';
  echo '</td>';

  echo '<td class="skill-search-name">';
  echo '<a class="skill-search-skill-link" href="' . h_skill($charUrl) . '">';
  echo h_skill($row['name_tcn'] ?: $row['skill_base_name']);
  echo '</a>';
  if (!empty($row['available_levels'])) {
    echo '<div class="text-muted small">版本：' . h_skill($row['available_levels']) . '</div>';
  }

  if ($tags) {
  echo '<div class="skill-search-tags">';
  foreach ($tags as $t) {
    echo '<a href="#skillSearchForm"
      class="skill-tag js-skill-keyword-tag"
      data-skill-keyword="' . h_skill($t) . '">'
      . h_skill($t) .
    '</a>';
  }
  echo '</div>';
}

  echo '</td>';

  echo '<td class="skill-search-info">' . nl2br(h_skill($infoText !== '' ? $infoText : '（尚無說明）')) . '</td>';

  echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>';
