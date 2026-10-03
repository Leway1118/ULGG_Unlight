<?php
// ⚠️ 不要 session_start()，交給 config.php
require_once __DIR__ . '/../config.php';
$pdo = $db;

$pageTitleText = '地圖任務查詢';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "quest_map";

ob_start();

if (!function_exists('h')) {
  function h($s)
  {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
  }
}
function questRouteText($route)
{
  if ($route === 'left') return '左';
  if ($route === 'mid') return '中';
  if ($route === 'right') return '右';
  return $route;
}

function questRewardBadge($type)
{
  if ($type === 'gem') return 'Gem';
  if ($type === 'card') return 'Card';
  return $type ?: 'Get';
}

function questBuildUrl(array $extra = [])
{
  $base = $_GET;
  foreach ($extra as $k => $v) {
    if ($v === null) {
      unset($base[$k]);
    } else {
      $base[$k] = $v;
    }
  }
  return '?' . http_build_query($base);
}
function questApText($searchAp, $missionAp, $baseApOnce)
{
  return '♡' . (int)$searchAp . ' 🗡️' . (int)$missionAp . ' = ' . (int)$baseApOnce . ' AP';
}

function questShortRouteText($route)
{
  if ($route === 'left') return '左';
  if ($route === 'mid') return '中';
  if ($route === 'right') return '右';
  return $route;
}
function questRouteShortText($route)
{
  if ($route === 'left') return '左';
  if ($route === 'mid') return '中';
  if ($route === 'right') return '右';
  return $route;
}

function questNodeHasContent($node)
{
  if (!$node) return false;
  return !empty($node['battle_name'])
    || !empty($node['reward_name'])
    || (int)($node['is_goal'] ?? 0) === 1
    || (int)($node['is_boss'] ?? 0) === 1;
}

function questNodeSummaryText($node)
{
  if (!$node) return '';

  $parts = [];

  if (!empty($node['reward_name'])) {
    $parts[] = $node['reward_name'];
  }

  if ((int)($node['is_boss'] ?? 0) === 1) {
    $parts[] = 'BOSS';
  }

  if ((int)($node['is_goal'] ?? 0) === 1) {
    $parts[] = 'GOAL';
  }

  return implode('/', $parts);
}
function questNodeBattleText($node)
{
  if (!$node || empty($node['battle_name'])) return '';
  return $node['battle_name'];
}

function questBuildRouteStageTable(array $nodesByStage): array
{
  $routes = ['right', 'mid', 'left'];
  $maxStage = 0;

  foreach ($nodesByStage as $stageNo => $routeNodes) {
    $maxStage = max($maxStage, (int)$stageNo);
  }

  if ($maxStage <= 0) {
    $maxStage = 5;
  }

  $table = [];
  $battles = [];

  foreach ($routes as $route) {
    $table[$route] = [];

    for ($stageNo = 1; $stageNo <= $maxStage; $stageNo++) {
      $node = $nodesByStage[$stageNo][$route] ?? null;
      $text = questNodeSummaryText($node);
      $battleText = questNodeBattleText($node);

      $table[$route][$stageNo] = [
        'text' => $text,
        'battle' => $battleText,
      ];

      if ($battleText !== '') {
        $battles[] = [
          'route_pos' => $route,
          'stage_no' => $stageNo,
          'text' => $battleText,
        ];
      }
    }
  }

  return [
    'max_stage' => $maxStage,
    'rows' => $table,
    'battles' => $battles,
  ];
}
function questHighlight($text, $keyword)
{
  $text = (string)$text;
  $keyword = trim((string)$keyword);

  if ($text === '' || $keyword === '') {
    return h($text);
  }

  $escapedText = h($text);
  $escapedKeyword = h($keyword);

  return preg_replace(
    '/' . preg_quote($escapedKeyword, '/') . '/iu',
    '<mark class="quest-highlight">$0</mark>',
    $escapedText
  );
}

$q = trim((string)($_GET['q'] ?? ''));
$mode = (string)($_GET['mode'] ?? 'all');
$exact = (int)($_GET['exact'] ?? 0);
$missionId = (int)($_GET['mission_id'] ?? 0);
$includeEvent = (int)($_GET['include_event'] ?? 0);
$includeGoal = array_key_exists('include_goal', $_GET) ? (int)$_GET['include_goal'] : 1;

$allowedModes = ['all', 'reward', 'battle', 'mission', 'region'];
if (!in_array($mode, $allowedModes, true)) {
  $mode = 'all';
}

$results = [];
$missionDetail = null;
$missionNodes = [];
$popularRewards = [];
$coinItems = [
  [
    'name' => '鐵幣',
    'en' => 'Iron Coin',
    'desc' => '封印著低級怪物靈魂的鐵幣，有很多用途。',
    'craft' => '合成 M1 怪物 × 1',
    'rarity' => 1,
    'id' => 10001,
  ],
  [
    'name' => '銅幣',
    'en' => 'Bronze Coin',
    'desc' => '封印著怪物靈魂的銅幣，有很多用途。',
    'craft' => '合成 M2 怪物 × 1',
    'rarity' => 2,
    'id' => 10002,
  ],
  [
    'name' => '銀幣',
    'en' => 'Silver Coin',
    'desc' => '封印著上級怪物靈魂的銀幣，有很多用途。',
    'craft' => '合成 M3 怪物 × 1',
    'rarity' => 3,
    'id' => 10003,
  ],
  [
    'name' => '金幣',
    'en' => 'Gold Coin',
    'desc' => '凝縮著怪物靈魂的金幣，有很多用途。',
    'craft' => '合成任意怪物 × 3',
    'rarity' => 4,
    'id' => 10004,
  ],
  [
    'name' => '白金幣',
    'en' => 'Platinum Coin',
    'desc' => '凝縮著怪物靈魂的白金幣，有很多用途。',
    'craft' => '同種族怪物 M1、M2、M3',
    'rarity' => 5,
    'id' => 10005,
  ],
];

$tipItems = [
  [
    'name' => '記憶的碎片',
    'en' => 'Memory Tips',
    'desc' => '充滿著生前記憶的碎片。',
    'price' => '1 個 / 45 臺幣，12 個 / 450 臺幣',
    'craft' => '合成鐵幣 × 3',
    'rarity' => 6,
    'id' => 10006,
  ],
  [
    'name' => '時間的碎片',
    'en' => 'Time Tips',
    'desc' => '回溯時光所必要的時間碎片。',
    'price' => '1 個 / 60 臺幣，12 個 / 600 臺幣',
    'craft' => '合成銅幣 × 3',
    'rarity' => 7,
    'id' => 10007,
  ],
  [
    'name' => '靈魂的碎片',
    'en' => 'Soul Tips',
    'desc' => '遺失的靈魂碎片。',
    'price' => '1 個 / 75 臺幣，12 個 / 750 臺幣',
    'craft' => '合成銀幣 × 3',
    'rarity' => 8,
    'id' => 10008,
  ],
  [
    'name' => '生命的碎片',
    'en' => 'Light Tips',
    'desc' => '充滿希望的生命碎片。',
    'price' => '1 個 / 45 臺幣，12 個 / 450 臺幣',
    'craft' => '合成金幣 × 2',
    'rarity' => 9,
    'id' => 10009,
  ],
  [
    'name' => '死亡的碎片',
    'en' => 'Unlight Tips',
    'desc' => '充滿絕望的死亡碎片。',
    'price' => '1 個 / 60 臺幣，12 個 / 600 臺幣',
    'craft' => '合成白金幣 × 2',
    'rarity' => 10,
    'id' => 10010,
  ],
];

try {
  $popularSql = "
  SELECT
  n.reward_name,
  n.reward_type,
  COUNT(*) AS source_count
FROM quest_mission_nodes n
JOIN quest_missions m ON m.id = n.mission_id
JOIN quest_regions r ON r.id = m.region_id
JOIN quest_worlds w ON w.id = r.world_id
WHERE n.reward_name IS NOT NULL
  AND n.reward_name <> ''
  AND w.world_key NOT IN ('ExQuest', 'Event')
  AND w.world_name NOT LIKE '%Event%'
  AND w.world_name NOT LIKE '%活動%'

  -- 排除怪物卡，避免 M1/M2/M3 太多太雜
  AND n.reward_name NOT LIKE 'M1%'
  AND n.reward_name NOT LIKE 'M2%'
  AND n.reward_name NOT LIKE 'M3%'

  -- 排除角色卡，避免 L1/L2/L3 太多太雜
  AND n.reward_name NOT LIKE 'L1%'
  AND n.reward_name NOT LIKE 'L2%'
  AND n.reward_name NOT LIKE 'L3%'

GROUP BY n.reward_name, n.reward_type
HAVING source_count <= 21
ORDER BY source_count ASC, n.reward_name ASC
";
  $popularCacheFile = sys_get_temp_dir() . '/ulgg_quest_popular_rewards.json';
  $popularCacheTtl  = 3600;

  if (
    is_file($popularCacheFile) &&
    filemtime($popularCacheFile) > time() - $popularCacheTtl
  ) {
    $popularRewards = json_decode(
      file_get_contents($popularCacheFile),
      true
    ) ?: [];
  } else {
    $popularRewards = $pdo
      ->query($popularSql)
      ->fetchAll(PDO::FETCH_ASSOC);

    file_put_contents(
      $popularCacheFile,
      json_encode($popularRewards, JSON_UNESCAPED_UNICODE),
      LOCK_EX
    );
  }

  if ($q !== '') {
    $whereParts = [];
    $params = [];
    $needle = $exact ? $q : '%' . $q . '%';
    $op = $exact ? '=' : 'LIKE';

    if ($mode === 'all' || $mode === 'reward') {
      $whereParts[] = "n.reward_name {$op} :q_reward";
      $params[':q_reward'] = $needle;
    }
    if ($mode === 'all' || $mode === 'battle') {
      $whereParts[] = "n.battle_name {$op} :q_battle";
      $params[':q_battle'] = $needle;
    }
    if ($mode === 'all' || $mode === 'mission') {
      $whereParts[] = "m.name_tcn {$op} :q_mission";
      $params[':q_mission'] = $needle;
    }
    if ($mode === 'all' || $mode === 'region') {
      $whereParts[] = "r.name_tcn {$op} :q_region";
      $params[':q_region'] = $needle;
    }

    $whereSql = implode(' OR ', $whereParts);
    $extraWhere = '';

    if (!$includeEvent) {
      $extraWhere .= "
        AND w.world_key NOT IN ('ExQuest', 'Event')
        AND w.world_name NOT LIKE '%Event%'
        AND w.world_name NOT LIKE '%活動%'
      ";
    }
    if (!$includeGoal) {
      $extraWhere .= "
    AND COALESCE(n.is_goal, 0) = 0
  ";
    }

    $searchSql = "
  SELECT
    w.id AS world_id,
    w.world_key,
    w.world_name,
    r.id AS region_id,
    r.name_tcn AS region_name,
    r.ap AS search_ap,
    r.guage,
    m.id AS mission_id,
    m.name_tcn AS mission_name,
    m.name_ja AS mission_name_ja,
    m.name_en AS mission_name_en,
    m.info_tcn,
    m.rarity,
    m.ap AS mission_ap,
    m.is_boss,
    MIN(n.stage_no) AS stage_no,
    MIN(n.route_pos) AS route_pos,
    GROUP_CONCAT(DISTINCT n.battle_name ORDER BY n.stage_no SEPARATOR '、') AS battle_name,
    GROUP_CONCAT(DISTINCT n.reward_type ORDER BY n.stage_no SEPARATOR '、') AS reward_type,
    GROUP_CONCAT(DISTINCT n.reward_name ORDER BY n.stage_no SEPARATOR '、') AS reward_name,
    MAX(n.is_goal) AS is_goal,
    MAX(n.is_boss) AS node_is_boss,
    COALESCE(r.ap, 0) + COALESCE(m.ap, 0) AS base_ap_once
  FROM quest_mission_nodes n
  JOIN quest_missions m ON m.id = n.mission_id
  JOIN quest_regions r ON r.id = m.region_id
  JOIN quest_worlds w ON w.id = r.world_id
  WHERE ({$whereSql})
  {$extraWhere}
  GROUP BY
    w.id,
    w.world_key,
    w.world_name,
    r.id,
    r.name_tcn,
    r.ap,
    r.guage,
    m.id,
    m.name_tcn,
    m.name_ja,
    m.name_en,
    m.info_tcn,
    m.rarity,
    m.ap,
    m.is_boss
  ORDER BY
    base_ap_once ASC,
    m.rarity ASC,
    w.sort_order ASC,
    r.sort_order ASC,
    m.sort_order ASC
  LIMIT 100
";

    $stmt = $pdo->prepare($searchSql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $resultMissionIds = [];
    foreach ($results as $row) {
      $resultMissionIds[(int)$row['mission_id']] = true;
    }
    $resultMissionIds = array_keys($resultMissionIds);

    if (!empty($resultMissionIds)) {
      $placeholders = [];
      $detailParams = [];

      foreach ($resultMissionIds as $idx => $mid) {
        $key = ':mid' . $idx;
        $placeholders[] = $key;
        $detailParams[$key] = $mid;
      }

      $resultNodeSql = "
    SELECT
      mission_id,
      stage_no,
      route_pos,
      battle_name,
      reward_type,
      reward_name,
      is_goal,
      is_boss
    FROM quest_mission_nodes
    WHERE mission_id IN (" . implode(',', $placeholders) . ")
    ORDER BY mission_id ASC, stage_no ASC, FIELD(route_pos, 'left', 'mid', 'right')
  ";

      $stmt2 = $pdo->prepare($resultNodeSql);
      $stmt2->execute($detailParams);
      $allResultNodes = $stmt2->fetchAll(PDO::FETCH_ASSOC);

      $tmpNodesByMission = [];

      foreach ($allResultNodes as $node) {
        $mid = (int)$node['mission_id'];
        $stageNo = (int)$node['stage_no'];

        if (!isset($tmpNodesByMission[$mid])) {
          $tmpNodesByMission[$mid] = [];
        }
        if (!isset($tmpNodesByMission[$mid][$stageNo])) {
          $tmpNodesByMission[$mid][$stageNo] = [];
        }

        $tmpNodesByMission[$mid][$stageNo][$node['route_pos']] = $node;
      }

      foreach ($tmpNodesByMission as $mid => $stageRoutes) {
        $resultRouteSummaries[$mid] = questBuildRouteStageTable($stageRoutes);
      }
    }
  }
} catch (Throwable $e) {
  $pageError = $e->getMessage();
}





$nodesByStage = [];
foreach ($missionNodes as $node) {
  $stageNo = (int)$node['stage_no'];
  if (!isset($nodesByStage[$stageNo])) {
    $nodesByStage[$stageNo] = [];
  }
  $nodesByStage[$stageNo][$node['route_pos']] = $node;
}
$routePathSummaries = [];

if (!empty($nodesByStage)) {
  $routePathSummaries = questBuildRouteStageTable($nodesByStage);
}
?>

<style>
  .quest-page-wrap {
    max-width: 1320px;
    margin: 0 auto;
  }

  .quest-hero {
    background: linear-gradient(135deg, rgba(20, 28, 48, .96), rgba(10, 14, 24, .96));
    border: 1px solid rgba(120, 140, 180, .28);
    border-radius: 10px;
    padding: 18px 22px;
    margin: 18px 0 18px;
    color: #eef3ff;
    box-shadow: 0 10px 28px rgba(0, 0, 0, .28);
  }

  .quest-hero h1 {
    margin: 0 0 8px;
    font-size: 26px;
    font-weight: 800;
    letter-spacing: .03em;
    color: #f5f7ff;
    text-shadow: 0 0 8px rgba(120, 150, 255, .35);
  }

  .quest-hero p {
    margin: 0;
    color: #b8c0d6;
    font-size: 14px;
  }

  .quest-search-box {
    background: #111827;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 16px;
    border: 1px solid rgba(120, 140, 180, .25);
    box-shadow: 0 8px 22px rgba(0, 0, 0, .25);
  }

  .quest-search-row {
    display: flex;
    gap: 10px;
    align-items: center;
  }

  .quest-search-row input[type="text"] {
    flex: 1;
    height: 40px;
    border: 1px solid #374151;
    border-radius: 7px;
    padding: 0 12px;
    background: #0b1020;
    color: #e5e7eb;
    outline: none;
  }

  .quest-search-row input[type="text"]::placeholder {
    color: #7f8798;
  }

  .quest-search-row input[type="text"]:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 2px rgba(96, 165, 250, .18);
  }

  .quest-search-row button {
    height: 40px;
    border: 0;
    border-radius: 7px;
    padding: 0 20px;
    background: linear-gradient(180deg, #3b82f6, #2563eb);
    color: #fff;
    font-weight: 700;
    box-shadow: 0 4px 12px rgba(37, 99, 235, .35);
  }

  .quest-search-row button:hover {
    background: linear-gradient(180deg, #60a5fa, #2563eb);
  }

  .quest-options {
    display: flex;
    flex-wrap: wrap;
    gap: 10px 14px;
    margin-top: 11px;
    color: #cbd5e1;
    font-size: 13px;
  }

  .quest-options label {
    margin: 0;
    font-weight: 500;
    cursor: pointer;
  }

  .quest-options input {
    vertical-align: -2px;
    margin-right: 4px;
  }

  .quest-panel {
    background: #111827;
    border: 1px solid rgba(120, 140, 180, .22);
    border-radius: 10px;
    margin-bottom: 16px;
    overflow: hidden;
    color: #e5e7eb;
    box-shadow: 0 8px 22px rgba(0, 0, 0, .22);
  }

  .quest-panel-title {
    padding: 12px 14px;
    background: linear-gradient(180deg, #1f2937, #151c2b);
    border-bottom: 1px solid rgba(120, 140, 180, .2);
    font-weight: 800;
    color: #f9fafb;
    letter-spacing: .03em;
  }

  .quest-result-list {
    padding: 12px;
  }

  .quest-result-card {
    border: 1px solid rgba(120, 140, 180, .2);
    border-radius: 9px;
    padding: 12px;
    margin-bottom: 10px;
    background: #0f172a;
    transition: border-color .15s, transform .15s, box-shadow .15s;
  }

  .quest-result-card:hover {
    border-color: rgba(96, 165, 250, .65);
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(0, 0, 0, .28);
  }



  .quest-path {
    font-size: 13px;
    color: #94a3b8;
    margin-bottom: 5px;
  }

  .quest-mission-name {
    font-size: 17px;
    font-weight: 800;
    color: #f8fafc;
    margin-bottom: 8px;
  }

  .quest-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 8px;
  }

  .quest-tag {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 999px;
    font-size: 12px;
    background: rgba(99, 102, 241, .18);
    color: #c7d2fe;
    border: 1px solid rgba(129, 140, 248, .25);
  }

  .quest-tag.ap {
    background: rgba(16, 185, 129, .14);
    color: #a7f3d0;
    border-color: rgba(52, 211, 153, .22);
  }

  .quest-tag.boss {
    background: rgba(239, 68, 68, .14);
    color: #fecaca;
    border-color: rgba(248, 113, 113, .25);
  }

  .quest-tag.get {
    background: rgba(245, 158, 11, .16);
    color: #fde68a;
    border-color: rgba(251, 191, 36, .25);
  }

  .quest-node-line {
    font-size: 14px;
    color: #d1d5db;
    line-height: 1.65;
  }

  .quest-result-card .btn-primary,
  .quest-result-card .btn-primary:visited {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
    border-radius: 6px;
    font-weight: 700;
  }

  .quest-result-card .btn-primary:hover {
    background: #3b82f6;
    border-color: #3b82f6;
  }

  .quest-detail-body {
    padding: 12px;
  }

  .quest-stage {
    border: 1px solid rgba(120, 140, 180, .22);
    border-radius: 9px;
    margin-bottom: 10px;
    overflow: hidden;
    background: #0f172a;
  }

  .quest-stage-title {
    background: #1e293b;
    padding: 8px 10px;
    font-weight: 800;
    color: #f8fafc;
    border-bottom: 1px solid rgba(120, 140, 180, .18);
  }

  .quest-route-grid {
    display: grid;
    grid-template-columns: 82px 1fr;
    border-top: 1px solid rgba(120, 140, 180, .14);
  }

  .quest-stage-title+.quest-route-grid {
    border-top: 0;
  }

  .quest-route-label {
    padding: 9px 10px;
    background: rgba(15, 23, 42, .9);
    border-right: 1px solid rgba(120, 140, 180, .16);
    color: #cbd5e1;
    font-weight: 800;
  }

  .quest-route-content {
    padding: 9px 10px;
    color: #e5e7eb;
    line-height: 1.6;
  }

  .quest-empty {
    color: #8b95a7;
  }

  .quest-side-list {
    padding: 8px 12px;
    max-height: 540px;
    overflow-y: auto;
  }

  .quest-reward-link {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    padding: 8px 0;
    border-bottom: 1px dashed rgba(120, 140, 180, .18);
    color: #e5e7eb;
  }

  .quest-reward-link:hover {
    text-decoration: none;
    color: #93c5fd;
  }

  .quest-count {
    color: #94a3b8;
    font-size: 12px;
    font-weight: 500;
  }

  .quest-error {
    padding: 12px;
    background: rgba(127, 29, 29, .28);
    border: 1px solid rgba(248, 113, 113, .35);
    color: #fecaca;
    border-radius: 10px;
    margin-bottom: 15px;
  }

  .quest-panel p {
    color: #cbd5e1 !important;
  }

  @media (min-width: 1200px) {
    .quest-page-wrap {
      width: 1180px;
    }
  }

  @media (max-width: 991px) {
    .quest-page-wrap {
      max-width: 100%;
    }
  }

  @media (max-width: 768px) {
    .quest-hero {
      margin-top: 12px;
      padding: 15px;
    }

    .quest-hero h1 {
      font-size: 22px;
    }

    .quest-search-row {
      display: block;
    }

    .quest-search-row input[type="text"] {
      width: 100%;
      margin-bottom: 8px;
    }

    .quest-search-row button {
      width: 100%;
    }

    .quest-route-grid {
      grid-template-columns: 70px 1fr;
    }
  }

  .quest-material-group {
    padding: 10px 12px 12px;
  }

  .quest-material-heading {
    font-size: 13px;
    font-weight: 800;
    color: #bfdbfe;
    margin: 6px 0 8px;
    letter-spacing: .04em;
  }

  .quest-material-card {
    display: block;
    background: #0f172a;
    border: 1px solid rgba(120, 140, 180, .2);
    border-radius: 8px;
    padding: 9px 10px;
    margin-bottom: 8px;
    color: #e5e7eb;
  }

  .quest-material-card:hover {
    text-decoration: none;
    color: #fff;
    border-color: rgba(96, 165, 250, .65);
    box-shadow: 0 6px 16px rgba(0, 0, 0, .25);
  }

  .quest-material-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 4px;
  }

  .quest-material-name {
    font-weight: 800;
    color: #f8fafc;
  }

  .quest-material-en {
    font-size: 12px;
    color: #94a3b8;
  }

  .quest-material-rarity {
    font-size: 12px;
    padding: 2px 6px;
    border-radius: 999px;
    background: rgba(245, 158, 11, .16);
    color: #fde68a;
    border: 1px solid rgba(251, 191, 36, .25);
    white-space: nowrap;
  }

  .quest-material-desc {
    font-size: 12px;
    color: #cbd5e1;
    line-height: 1.45;
    margin-bottom: 4px;
  }

  .quest-material-craft {
    font-size: 12px;
    color: #a7f3d0;
  }

  .quest-material-price {
    font-size: 12px;
    color: #fca5a5;
    margin-top: 3px;
  }

  .quest-result-list {
    padding: 10px;
  }

  .quest-result-card {
    border: 1px solid rgba(120, 140, 180, .18);
    border-radius: 8px;
    padding: 9px 11px;
    margin-bottom: 8px;
    background: #0f172a;
    transition: border-color .15s, box-shadow .15s;
  }

  .quest-result-card:hover {
    border-color: rgba(96, 165, 250, .65);
    box-shadow: 0 5px 14px rgba(0, 0, 0, .22);
  }



  .quest-compact-title {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 5px;
    font-size: 15px;
    line-height: 1.45;
    color: #e5e7eb;
  }

  .quest-compact-path {
    color: #93a4bd;
  }

  .quest-compact-mission {
    color: #f8fafc;
    font-weight: 800;
  }

  .quest-compact-meta {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 5px;
  }

  .quest-mini-tag {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 999px;
    font-size: 12px;
    line-height: 1.55;
    background: rgba(99, 102, 241, .18);
    color: #c7d2fe;
    border: 1px solid rgba(129, 140, 248, .25);
  }

  .quest-mini-tag.ap {
    background: rgba(16, 185, 129, .14);
    color: #a7f3d0;
    border-color: rgba(52, 211, 153, .22);
  }

  .quest-mini-tag.get {
    background: rgba(245, 158, 11, .16);
    color: #fde68a;
    border-color: rgba(251, 191, 36, .25);
  }

  .quest-mini-tag.boss {
    background: rgba(239, 68, 68, .14);
    color: #fecaca;
    border-color: rgba(248, 113, 113, .25);
  }



  .quest-compact-actions {
    margin-top: 6px;
  }

  .quest-compact-actions .btn {
    padding: 2px 8px;
    font-size: 12px;
    line-height: 1.5;
    border-radius: 5px;
  }

  .quest-route-summary {
    border: 1px solid rgba(120, 140, 180, .22);
    background: #0f172a;
    border-radius: 9px;
    padding: 10px 12px;
    margin: 10px 0 12px;
  }

  .quest-route-summary-title {
    font-weight: 800;
    color: #bfdbfe;
    margin-bottom: 8px;
    font-size: 13px;
    letter-spacing: .04em;
  }

  .quest-route-summary-line {
    display: grid;
    grid-template-columns: 34px 1fr;
    gap: 8px;
    padding: 5px 0;
    border-top: 1px dashed rgba(120, 140, 180, .16);
    font-size: 13px;
    line-height: 1.55;
  }

  .quest-route-summary-line:first-of-type {
    border-top: 0;
  }

  .quest-route-summary-label {
    color: #fde68a;
    font-weight: 800;
  }

  .quest-route-summary-items {
    color: #dbeafe;
  }

  .quest-route-summary-items span {
    display: inline;
  }

  .quest-route-summary-empty {
    color: #8b95a7;
  }

  .quest-inline-route-summary {
    margin-top: 6px;
    padding: 6px 8px;
    border-radius: 7px;
    background: rgba(15, 23, 42, .85);
    border: 1px solid rgba(120, 140, 180, .16);
    font-size: 12px;
    line-height: 1.55;
  }

  .quest-inline-route-line {
    display: grid;
    grid-template-columns: 28px 1fr;
    gap: 4px;
    padding: 1px 0;
  }

  .quest-inline-route-label {
    color: #fde68a;
    font-weight: 800;
  }

  .quest-inline-route-items {
    color: #dbeafe;
  }

  .quest-route-mini-table-wrap {
    margin-top: 6px;
    overflow-x: auto;
    border: 1px solid rgba(120, 140, 180, .18);
    border-radius: 7px;
    background: rgba(15, 23, 42, .75);
  }

  .quest-route-mini-table {
    width: 100%;
    min-width: 520px;
    border-collapse: collapse;
    font-size: 12px;
    line-height: 1.45;
  }

  .quest-route-mini-table th,
  .quest-route-mini-table td {
    border-right: 1px solid rgba(120, 140, 180, .14);
    border-bottom: 1px solid rgba(120, 140, 180, .12);
    padding: 5px 6px;
    vertical-align: top;
  }

  .quest-route-mini-table th {
    background: rgba(30, 41, 59, .95);
    color: #bfdbfe;
    font-weight: 800;
    white-space: nowrap;
    text-align: center;
  }

  .quest-route-mini-table tr:last-child td {
    border-bottom: 0;
  }

  .quest-route-mini-table th:last-child,
  .quest-route-mini-table td:last-child {
    border-right: 0;
  }

  .quest-route-mini-table .route-label {
    width: 32px;
    text-align: center;
    color: #fde68a;
    font-weight: 800;
    background: rgba(15, 23, 42, .95);
    white-space: nowrap;
  }

  .quest-route-mini-table .has-item {
    color: #e5e7eb;
  }

  .quest-route-mini-table .empty-item {
    color: #475569;
    background: rgba(15, 23, 42, .35);
  }

  .quest-highlight {
    display: inline;
    padding: 0 3px;
    border-radius: 4px;
    background: rgba(250, 204, 21, .28);
    color: #fef08a;
    font-weight: 900;
    box-shadow: 0 0 0 1px rgba(250, 204, 21, .25);
  }

  .quest-battle-icon {
    display: inline-block;
    margin-left: 4px;
    color: #fca5a5;
    font-size: 12px;
    cursor: help;
    opacity: .9;
  }

  .quest-battle-detail {
    margin-top: 5px;
    color: #cbd5e1;
    font-size: 12px;
    line-height: 1.55;
  }

  .quest-battle-detail span {
    display: inline-block;
    margin-right: 10px;
    margin-bottom: 2px;
    color: #fca5a5;
  }

  .quest-material-card.tip-rarity-6 {
    border-color: rgba(250, 204, 21, .45);
    background: linear-gradient(135deg, rgba(250, 204, 21, .16), rgba(15, 23, 42, .92));
  }

  .quest-material-card.tip-rarity-6 .quest-material-rarity {
    background: rgba(250, 204, 21, .22);
    color: #fef08a;
    border-color: rgba(250, 204, 21, .45);
  }

  .quest-material-card.tip-rarity-7 {
    border-color: rgba(34, 197, 94, .45);
    background: linear-gradient(135deg, rgba(34, 197, 94, .16), rgba(15, 23, 42, .92));
  }

  .quest-material-card.tip-rarity-7 .quest-material-rarity {
    background: rgba(34, 197, 94, .22);
    color: #bbf7d0;
    border-color: rgba(34, 197, 94, .45);
  }

  .quest-material-card.tip-rarity-8 {
    border-color: rgba(59, 130, 246, .45);
    background: linear-gradient(135deg, rgba(59, 130, 246, .16), rgba(15, 23, 42, .92));
  }

  .quest-material-card.tip-rarity-8 .quest-material-rarity {
    background: rgba(59, 130, 246, .22);
    color: #bfdbfe;
    border-color: rgba(59, 130, 246, .45);
  }

  .quest-material-card.tip-rarity-9 {
    border-color: rgba(239, 68, 68, .45);
    background: linear-gradient(135deg, rgba(239, 68, 68, .16), rgba(15, 23, 42, .92));
  }

  .quest-material-card.tip-rarity-9 .quest-material-rarity {
    background: rgba(239, 68, 68, .22);
    color: #fecaca;
    border-color: rgba(239, 68, 68, .45);
  }

  .quest-material-card.tip-rarity-10 {
    border-color: rgba(168, 85, 247, .5);
    background: linear-gradient(135deg, rgba(168, 85, 247, .18), rgba(15, 23, 42, .92));
  }

  .quest-material-card.tip-rarity-10 .quest-material-rarity {
    background: rgba(168, 85, 247, .24);
    color: #e9d5ff;
    border-color: rgba(168, 85, 247, .5);
  }

  .quest-material-card.tip-rarity-6:hover,
  .quest-material-card.tip-rarity-7:hover,
  .quest-material-card.tip-rarity-8:hover,
  .quest-material-card.tip-rarity-9:hover,
  .quest-material-card.tip-rarity-10:hover {
    transform: translateY(-1px);
    filter: brightness(1.08);
  }

  .quest-material-card.tip-rarity-6 .quest-material-name {
    color: #fef08a;
  }

  .quest-material-card.tip-rarity-7 .quest-material-name {
    color: #bbf7d0;
  }

  .quest-material-card.tip-rarity-8 .quest-material-name {
    color: #bfdbfe;
  }

  .quest-material-card.tip-rarity-9 .quest-material-name {
    color: #fecaca;
  }

  .quest-material-card.tip-rarity-10 .quest-material-name {
    color: #e9d5ff;
  }

  .quest-tip-shortcuts {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px dashed rgba(120, 140, 180, .2);
  }

  .quest-tip-shortcuts-title {
    display: inline-block;
    margin-right: 8px;
    color: #bfdbfe;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: .04em;
  }

  .quest-tip-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin: 3px 5px 3px 0;
    padding: 4px 8px;
    border-radius: 999px;
    background: rgba(15, 23, 42, .88);
    border: 1px solid rgba(120, 140, 180, .25);
    color: #e5e7eb;
    font-size: 12px;
    line-height: 1.4;
  }

  .quest-tip-chip:hover {
    text-decoration: none;
    filter: brightness(1.12);
    transform: translateY(-1px);
  }

  .quest-tip-chip-name {
    font-weight: 900;
  }

  .quest-tip-chip-craft {
    color: #cbd5e1;
    opacity: .9;
  }

  .quest-tip-chip.tip-rarity-6 {
    border-color: rgba(250, 204, 21, .45);
    color: #fef08a;
  }

  .quest-tip-chip.tip-rarity-7 {
    border-color: rgba(34, 197, 94, .45);
    color: #bbf7d0;
  }

  .quest-tip-chip.tip-rarity-8 {
    border-color: rgba(59, 130, 246, .45);
    color: #bfdbfe;
  }

  .quest-tip-chip.tip-rarity-9 {
    border-color: rgba(239, 68, 68, .45);
    color: #fecaca;
  }

  .quest-tip-chip.tip-rarity-10 {
    border-color: rgba(168, 85, 247, .5);
    color: #e9d5ff;
  }

  .quest-tip-chip.tip-rarity-6 .quest-tip-chip-craft {
    color: #fef3c7;
  }

  .quest-tip-chip.tip-rarity-7 .quest-tip-chip-craft {
    color: #dcfce7;
  }

  .quest-tip-chip.tip-rarity-8 .quest-tip-chip-craft {
    color: #dbeafe;
  }

  .quest-tip-chip.tip-rarity-9 .quest-tip-chip-craft {
    color: #fee2e2;
  }

  .quest-tip-chip.tip-rarity-10 .quest-tip-chip-craft {
    color: #f3e8ff;
  }
</style>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container quest-page-wrap">
      <div class="quest-hero">
        <h1><?= h($pageTitleText) ?></h1>
        <p>查詢任務、遭遇怪物與 Get 掉落來源，並依搜尋 AP + 任務 AP 初步排序。</p>
      </div>

      <?php if (!empty($pageError)): ?>
        <div class="quest-error">資料查詢錯誤：<?= h($pageError) ?></div>
      <?php endif; ?>

      <div class="quest-search-box">
        <form method="get" action="">
          <div class="quest-search-row">
            <input type="text" name="q" value="<?= h($q) ?>" placeholder="輸入道具、怪物、任務或區域，例如：M1蝙蝠、30Gem、魔女山谷">
            <button type="submit">搜尋</button>
          </div>
          <div class="quest-options">
            <label><input type="radio" name="mode" value="all" <?= $mode === 'all' ? 'checked' : '' ?>> 全部</label>
            <label><input type="radio" name="mode" value="reward" <?= $mode === 'reward' ? 'checked' : '' ?>> Get 掉落</label>
            <label><input type="radio" name="mode" value="battle" <?= $mode === 'battle' ? 'checked' : '' ?>> 遭遇怪物</label>
            <label><input type="radio" name="mode" value="mission" <?= $mode === 'mission' ? 'checked' : '' ?>> 任務名稱</label>
            <label><input type="radio" name="mode" value="region" <?= $mode === 'region' ? 'checked' : '' ?>> 區域名稱</label>
            <label><input type="checkbox" name="exact" value="1" <?= $exact ? 'checked' : '' ?>> 精準符合</label>
            <label><input type="checkbox" name="include_event" value="1" <?= $includeEvent ? 'checked' : '' ?>> 包含 Event / ExQuest</label>
            <input type="hidden" name="include_goal" value="0">
            <label><input type="checkbox" name="include_goal" value="1" <?= $includeGoal ? 'checked' : '' ?>> 包含 GOAL 掉落</label>
          </div>
        </form>
        <div class="quest-tip-shortcuts">
          <div class="quest-tip-shortcuts-title">碎片快速搜尋</div>

          <?php foreach ($tipItems as $item): ?>
            <a class="quest-tip-chip tip-rarity-<?= (int)$item['rarity'] ?>"
              href="?q=<?= urlencode($item['name']) ?>&mode=reward&exact=1<?= $includeEvent ? '&include_event=1' : '' ?><?= $includeGoal ? '&include_goal=1' : '&include_goal=0' ?>">
              <span class="quest-tip-chip-name"><?= h($item['name']) ?></span>
              <span class="quest-tip-chip-craft">
                <?= h(str_replace(['合成', ' '], '', $item['craft'])) ?>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>


      <div class="row">
        <div class="col-md-8">
          <div class="quest-panel">
            <div class="quest-panel-title">
              搜尋結果
              <?php if ($q !== ''): ?>
                <span class="quest-count">找到 <?= count($results) ?> 筆，最多顯示 100 筆</span>
              <?php endif; ?>
            </div>
            <div class="quest-result-list">
              <?php if ($q === ''): ?>
                <div class="quest-empty">請先輸入關鍵字，例如 M1蝙蝠、墨菲斯之門、ticket 1。</div>
              <?php elseif (empty($results)): ?>
                <div class="quest-empty">找不到符合「<?= h($q) ?>」的資料。</div>
              <?php else: ?>
                <?php foreach ($results as $row): ?>
                  <div class="quest-result-card">
                    <div class="quest-compact-title">
                      <span class="quest-compact-path">
                        <?= questHighlight($row['world_name'], $q) ?>
                        &gt;
                        <a class="quest-path-link"
                          href="?q=<?= urlencode($row['region_name']) ?>&mode=region<?= $includeEvent ? '&include_event=1' : '' ?><?= $includeGoal ? '&include_goal=1' : '&include_goal=0' ?>">
                          <?= questHighlight($row['region_name'], $q) ?>
                        </a>
                        &gt;
                      </span>

                      <span class="quest-mini-tag">★<?= h($row['rarity']) ?></span>

                      <span class="quest-compact-mission">
                        <?= questHighlight($row['mission_name'], $q) ?>
                      </span>

                      <span class="quest-mini-tag ap">
                        <?= h(questApText($row['search_ap'], $row['mission_ap'], $row['base_ap_once'])) ?>
                      </span>

                      <?php if ((int)$row['is_boss'] === 1): ?>
                        <span class="quest-mini-tag boss">BOSS</span>
                      <?php endif; ?>

                      <?php if ($row['reward_name'] !== null && $row['reward_name'] !== ''): ?>
                        <span class="quest-mini-tag get">Get <?= questHighlight($row['reward_name'], $q) ?></span>
                      <?php endif; ?>
                    </div>


                    <?php
                    $rowMissionId = (int)$row['mission_id'];
                    $rowSummary = $resultRouteSummaries[$rowMissionId] ?? [];
                    $routeLineLabels = [
                      'right' => '右',
                      'mid' => '中',
                      'left' => '左',
                    ];
                    ?>

                    <?php if (!empty($rowSummary['rows'])): ?>
                      <div class="quest-route-mini-table-wrap">
                        <table class="quest-route-mini-table">
                          <thead>
                            <tr>
                              <th>路</th>
                              <?php for ($s = 1; $s <= (int)$rowSummary['max_stage']; $s++): ?>
                                <th>第<?= h($s) ?>階</th>
                              <?php endfor; ?>
                            </tr>
                          </thead>
                          <tbody>
                            <?php
                            $routeLineLabels = [
                              'right' => '右',
                              'mid' => '中',
                              'left' => '左',
                            ];
                            ?>
                            <?php foreach ($routeLineLabels as $routeKey => $routeLabel): ?>
                              <tr>
                                <td class="route-label"><?= h($routeLabel) ?></td>
                                <?php for ($s = 1; $s <= (int)$rowSummary['max_stage']; $s++): ?>
                                  <?php
                                  $cell = $rowSummary['rows'][$routeKey][$s] ?? ['text' => '', 'battle' => ''];
                                  $cellText = $cell['text'] ?? '';
                                  $cellBattle = $cell['battle'] ?? '';
                                  ?>
                                  <td class="<?= $cellText !== '' ? 'has-item' : 'empty-item' ?>">
                                    <?php if ($cellText !== ''): ?>
                                      <?= questHighlight($cellText, $q) ?>
                                    <?php endif; ?>
                                    <?php if ($cellBattle !== ''): ?>
                                      <span class="quest-battle-icon" title="<?= h($cellBattle) ?>">⚔</span>
                                    <?php endif; ?>
                                  </td>
                                <?php endfor; ?>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>

                    <?php endif; ?>


                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="col-md-4">
          <div class="quest-panel">
            <div class="quest-panel-title">推薦 Get 道具</div>
            <div class="quest-side-list">
              <?php foreach ($popularRewards as $reward): ?>
                <a class="quest-reward-link" href="?q=<?= urlencode($reward['reward_name']) ?>&mode=reward&exact=1<?= $includeEvent ? '&include_event=1' : '' ?><?= $includeGoal ? '&include_goal=1' : '&include_goal=0' ?>">
                  <span><?= h($reward['reward_name']) ?></span>
                  <span class="quest-count"><?= h($reward['source_count']) ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="quest-panel">
            <div class="quest-panel-title">硬幣 / 碎片對照</div>

            <div class="quest-material-group">
              <div class="quest-material-heading">硬幣 Coin</div>
              <?php foreach ($coinItems as $item): ?>
                <a class="quest-material-card tip-rarity-<?= (int)$item['rarity'] ?>" href="?q=<?= urlencode($item['name']) ?>&mode=reward&exact=1">
                  <div class="quest-material-top">
                    <div>
                      <div class="quest-material-name"><?= h($item['name']) ?></div>
                      <div class="quest-material-en"><?= h($item['en']) ?></div>
                    </div>
                    <div class="quest-material-rarity">R<?= h($item['rarity']) ?></div>
                  </div>
                  <div class="quest-material-desc"><?= h($item['desc']) ?></div>
                  <div class="quest-material-craft"><?= h($item['craft']) ?></div>
                </a>
              <?php endforeach; ?>
            </div>


          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    var input = document.querySelector('input[name="q"]');
    if (input && !input.value) {
      input.focus();
    }
  });
</script>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>