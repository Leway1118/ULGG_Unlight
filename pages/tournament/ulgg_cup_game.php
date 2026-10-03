<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 不要 session_start()，交給 config.php
require_once __DIR__ . '/../../config.php';
$pdo = $db;

$matchCode = strtoupper(trim($_GET['match'] ?? 'M01'));
$gameId = (int)($_GET['game_id'] ?? 0);
$tournamentId = 1;

$pageTitleText = 'ULGG 杯對戰詳細';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "ulgg_cup_2026";

ob_start();

function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$stages = [
  -1 => '全部',
  0  => '雷城',
  1  => '誘森',
  2  => '垃圾',
  3  => '冰封',
  4  => '人魂',
  5  => '盡村',
  6  => '風暴',
  7  => '峰亥盧',
  8  => '魔都',
  9  => '狂山',
  10 => '魔女山谷',
  11 => '隨機',
  12 => '烏波斯黑湖',
];

function stageName($stage, array $stages): string
{
  if ($stage === null || $stage === '') {
    return '-';
  }

  $stageInt = (int)$stage;
  return $stages[$stageInt] ?? ('未知場景 ' . $stageInt);
}

function normalizePlayerName(string $name): string
{
  $name = trim($name);
  $name = preg_replace('/[\s　]+/u', '', $name);
  return $name;
}

/* 工作人員：看雙方完整詳細
P1：只能看 P1 自己的武器與事件卡
P2：只能看 P2 自己的武器與事件卡
其他人：不能進詳細頁 */
function debugAsActive(PDO $pdo): bool
{
  return (
    isset($_GET['debug_as']) &&
    trim((string)$_GET['debug_as']) !== '' &&
    isTournamentAdmin($pdo)
  );
}
function realLoginName(): string
{
  return trim((string)(
    $_SESSION['username']
    ?? $_SESSION['user_name']
    ?? $_SESSION['game_name']
    ?? $_SESSION['player_name']
    ?? $_SESSION['name']
    ?? ($_SESSION['user']['username'] ?? '')
    ?? ''
  ));
}
function getUserAckByName(PDO $pdo, string $name): int
{
  $name = trim($name);

  if ($name === '') {
    return 0;
  }

  $stmt = $pdo->prepare("
    SELECT ack
    FROM game_user
    WHERE username = :name
       OR nickname = :name
    ORDER BY ack DESC
    LIMIT 1
  ");

  $stmt->execute([
    ':name' => $name,
  ]);

  $ack = $stmt->fetchColumn();

  return $ack === false ? 0 : (int)$ack;
}
function currentLoginName(?PDO $pdo = null): string
{
  $realName = realLoginName();

  if (
    $pdo instanceof PDO &&
    isset($_GET['debug_as']) &&
    trim((string)$_GET['debug_as']) !== '' &&
    isTournamentAdmin($pdo)
  ) {
    return trim((string)$_GET['debug_as']);
  }

  return $realName;
}

function isTournamentAdmin(PDO $pdo): bool
{
  $realName = realLoginName();

  if ($realName === '') {
    return false;
  }

  return getUserAckByName($pdo, $realName) >= 2;
}

function tournamentViewerSide(PDO $pdo, array $row): string
{
  $hasDebugAs = debugAsActive($pdo);

  /*
   * 沒有 debug_as 時：
   * 真實登入者 ack >= 2，直接視為管理員
   */
  if (!$hasDebugAs && isTournamentAdmin($pdo)) {
    return 'admin';
  }

  /*
   * 有 debug_as 時：
   * 用 currentLoginName($pdo) 取得模擬身分
   */
  $loginNameRaw = currentLoginName($pdo);
  $loginName = normalizePlayerName($loginNameRaw);

  if ($loginName === '') {
    return 'none';
  }

  $p1 = normalizePlayerName((string)($row['player1_game_name'] ?? ''));
  $p2 = normalizePlayerName((string)($row['player2_game_name'] ?? ''));

  if ($loginName === $p1) {
    return 'player1';
  }

  if ($loginName === $p2) {
    return 'player2';
  }

  /*
   * 如果 debug_as 的那個帳號本身也是 ack >= 2，
   * 才模擬成管理員。
   * 例如 debug_as=燈皇，ack=2 → admin
   */
  if ($hasDebugAs && getUserAckByName($pdo, $loginNameRaw) >= 2) {
    return 'admin';
  }

  return 'none';
}

function canViewTournamentGamePage(PDO $pdo, array $row): bool
{
  return tournamentViewerSide($pdo, $row) !== 'none';
}

function canViewSideDetail(string $viewerSide, string $side): bool
{
  if ($viewerSide === 'admin') {
    return true;
  }

  if ($viewerSide === 'player1' && $side === 'left') {
    return true;
  }

  if ($viewerSide === 'player2' && $side === 'right') {
    return true;
  }

  return false;
}

function regionIcon($region)
{
  switch ($region) {
    case 'TW':
      return '<i class="fab fa-steam" title="Steam 台服"></i>';
    case 'JP':
      return '<span class="server-icon dmm" title="DMM 日服">D</span>';
    case 'CR':
      return '<span title="跨服">☯</span>';
    case 'MATCH':
      return '<i class="fas fa-trophy" title="比賽專用"></i>';
    default:
      return '';
  }
}

function resultLabel(array $row, string $focusName): array
{
  $win  = (int)($row['win'] ?? 0);
  $lose = (int)($row['lose'] ?? 0);
  $tie  = (int)($row['tie'] ?? 0);

  if ($tie === 1) {
    return ['平', 'result-tie'];
  }

  if ($win + $lose + $tie === 0) {
    return ['未判', 'result-unknown'];
  }

  // arena_unlight 規則：win=1 代表 P2 勝；lose=1 代表 P1 勝
  $p1 = (string)($row['name_p1'] ?? '');
  $p2 = (string)($row['name_p2'] ?? '');

  if ($win === 1) {
    $winner = $p2;
  } elseif ($lose === 1) {
    $winner = $p1;
  } else {
    $winner = '';
  }

  if ($winner !== '' && $winner === $focusName) {
    return ['勝', 'result-win'];
  }

  return ['負', 'result-lose'];
}

function getCharCards(PDO $pdo, array $ids): array
{
  $ids = array_values(array_filter(array_map('intval', $ids)));
  if (!$ids) return [];

  $placeholders = implode(',', array_fill(0, count($ids), '?'));

  $stmt = $pdo->prepare("
    SELECT id, ico, name, level, cost
    FROM unlight
    WHERE id IN ($placeholders)
  ");
  $stmt->execute($ids);

  $map = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $map[(int)$r['id']] = $r;
  }

  $result = [];
  foreach ($ids as $id) {
    if (isset($map[$id])) {
      $result[] = $map[$id];
    }
  }

  return $result;
}
function computeAttr(int $melee, int $ranged, string $prefix): array
{
  if ($melee && $ranged) {
    $value = $melee;
    $colorClass = 'attr-all';
  } elseif ($melee) {
    $value = $melee;
    $colorClass = 'attr-melee';
  } elseif ($ranged) {
    $value = $ranged;
    $colorClass = 'attr-ranged';
  } else {
    return ['', ''];
  }

  $sign = $value >= 0 ? '+' : '';
  return [$prefix . $sign . $value, $colorClass];
}

function loadEventMap(PDO $pdo): array
{
  $stmt = $pdo->query("
    SELECT id, ico, name, cost
    FROM unlight_eventindex
  ");

  $map = [];

  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id = (int)$r['id'];
    $map[$id] = [
      'id' => $id,
      'ico' => $r['ico'] ?: 'na_event.png',
      'name' => $r['name'] ?: '',
      'cost' => (int)($r['cost'] ?? 0),
    ];
  }

  return $map;
}

function loadWeaponMap(PDO $pdo): array
{
  $stmt = $pdo->query("
    SELECT
      id,
      ico,
      name,
      cost,
      atk_melee,
      atk_ranged,
      def_melee,
      def_ranged
    FROM unlight_weapon
  ");

  $map = [];

  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id = (int)$r['id'];
    $map[$id] = [
      'id' => $id,
      'ico' => $r['ico'] ?: '',
      'name' => $r['name'] ?: '',
      'cost' => (int)($r['cost'] ?? 0),
      'atk_melee' => (int)($r['atk_melee'] ?? 0),
      'atk_ranged' => (int)($r['atk_ranged'] ?? 0),
      'def_melee' => (int)($r['def_melee'] ?? 0),
      'def_ranged' => (int)($r['def_ranged'] ?? 0),
    ];
  }

  return $map;
}

function decodeEventIndex($json): array
{
  if ($json === null || $json === '') {
    return [];
  }

  $arr = is_array($json) ? $json : json_decode((string)$json, true);

  return is_array($arr) ? $arr : [];
}

function calcEventCost(array $events, array $eventMap): int
{
  $sum = 0;

  foreach ($events as $eid) {
    if ($eid === null || $eid === '') {
      continue;
    }

    $eid = (int)$eid;
    $sum += (int)($eventMap[$eid]['cost'] ?? 0);
  }

  return $sum;
}

function calcWeaponCost(array $weaponIds, array $weaponMap): int
{
  $sum = 0;

  foreach ($weaponIds as $wid) {
    if ($wid === null || $wid === '') {
      continue;
    }

    $wid = (int)$wid;
    $sum += (int)($weaponMap[$wid]['cost'] ?? 0);
  }

  return $sum;
}
function renderCharPanel(PDO $pdo, array $charIds, array $weaponIds, array $weaponMap): string
{
  $cards = getCharCards($pdo, $charIds);

  if (!$cards) {
    return '<div class="detail-empty">無角色資料</div>';
  }

  $html = '<div class="detail-char-grid">';

  foreach ($cards as $idx => $c) {
    $weaponId = $weaponIds[$idx] ?? null;
    $weapon = null;

    if ($weaponId !== null && $weaponId !== '') {
      $weapon = $weaponMap[(int)$weaponId] ?? null;
    }

    $ico = $c['ico'] ?: '';
    //$title = trim(($c['level'] ?? '') . ' ' . ($c['name'] ?? ''));
    $level = trim((string)($c['level'] ?? ''));
    $name  = trim((string)($c['name'] ?? ''));
    [$atkText, $atkClass] = $weapon
      ? computeAttr((int)$weapon['atk_melee'], (int)$weapon['atk_ranged'], 'ATK')
      : ['', ''];

    [$defText, $defClass] = $weapon
      ? computeAttr((int)$weapon['def_melee'], (int)$weapon['def_ranged'], 'DEF')
      : ['', ''];

    $html .= '<div class="detail-char-card">';

    $html .= '
      <div class="detail-char-img-wrap">
        <img src="' . IMG_BASE . h($ico) . '" loading="lazy">
      </div>
      <div class="detail-char-meta">
        <div class="detail-char-level">' . h($level) . '</div>
        <div class="detail-char-name">' . h($name) . '</div>
        <div class="detail-char-cost">COST ' . h($c['cost'] ?? '-') . '</div>
    ';

    if ($weapon) {
      $weaponIco = $weapon['ico'] ?: '';

      $html .= '<div class="detail-weapon-box">';

      if ($weaponIco !== '') {
        $html .= '
          <img class="weapon-img"
               src="' . IMG_BASE . h($weaponIco) . '"
               loading="lazy"
               title="' . h($weapon['name']) . '">
        ';
      }

      $html .= '
        <div class="weapon-text">
          <div class="weapon-name">' . h($weapon['name'] ?: '武器') . '</div>
          <div class="weapon-cost">' . h($weapon['cost']) . 'C</div>
      ';

      if ($atkText !== '' || $defText !== '') {
        $html .= '<div class="weapon-attr-line">';

        if ($atkText !== '') {
          $html .= '<span class="' . h($atkClass) . '">' . h($atkText) . '</span>';
        }

        if ($defText !== '') {
          $html .= '<span class="' . h($defClass) . '">' . h($defText) . '</span>';
        }

        $html .= '</div>';
      }

      $html .= '
          </div>
        </div>
      ';
    } else {
      $html .= '<div class="detail-weapon-empty">武器：無</div>';
    }

    $html .= '
      </div>
    </div>';
  }

  $html .= '</div>';

  return $html;
}



function renderEventCards($eventIndex, array $eventMap): string
{
  $events = decodeEventIndex($eventIndex);

  if (!$events) {
    return '<div class="detail-empty">無事件卡資料</div>';
  }

  $html = '<div class="event-character-board">';

  // 3 個角色，每個角色 6 張事件卡
  for ($charNo = 0; $charNo < 3; $charNo++) {
    $startIndex = $charNo * 6;

    $html .= '<div class="event-character-column">';
    $html .= '<div class="event-character-title">' . h($charNo + 1) . '</div>';
    $html .= '<div class="event-six-grid">';

    // 每個角色 2列 × 3欄
    for ($i = 0; $i < 6; $i++) {
      $idx = $startIndex + $i;
      $eid = $events[$idx] ?? null;

      if ($eid === null || $eid === '') {
        $html .= '
          <div class="event-slot empty"></div>
        ';
        continue;
      }

      $eid = (int)$eid;
      $event = $eventMap[$eid] ?? null;

      if (!$event) {
        $html .= '
          <div class="event-slot unknown">
            <div class="event-id">' . h($eid) . '</div>
          </div>
        ';
        continue;
      }

      $ico = $event['ico'] ?: 'na_event.png';
      $name = $event['name'] ?: '';
      $cost = (int)$event['cost'];

      $html .= '
        <div class="event-slot">
          <img class="event-img"
              src="' . IMG_BASE . h($ico) . '"
              loading="lazy"
              title="' . h($name . ' / ' . $cost . 'C') . '">
          <div class="event-cost">' . h($cost) . 'C</div>
        </div>
      ';
    }

    $html .= '</div>';
    $html .= '</div>';
  }

  $html .= '</div>';

  return $html;
}
function renderHiddenDetailBox(string $title = '對手詳細資料已隱藏'): string
{
  return '
    <div class="hidden-detail-box">
      <div class="hidden-detail-icon">🔒</div>
      <div class="hidden-detail-title">' . h($title) . '</div>
      <div class="hidden-detail-text">
        為避免賽事期間玩家互看牌組，此區僅限本人與工作人員查看。
      </div>
    </div>
  ';
}
function mapArenaRowToTournamentSides(array $row, array $match): array
{
  $tP1 = normalizePlayerName((string)($match['player1_game_name'] ?? ''));
  $tP2 = normalizePlayerName((string)($match['player2_game_name'] ?? ''));

  $aP1 = normalizePlayerName((string)($row['name_p1'] ?? ''));
  $aP2 = normalizePlayerName((string)($row['name_p2'] ?? ''));

  $leftPrefix = 'p1';
  $rightPrefix = 'p2';

  if ($aP1 === $tP2 && $aP2 === $tP1) {
    $leftPrefix = 'p2';
    $rightPrefix = 'p1';
  }

  return [
    'left' => [
      'name' => $row['name_' . $leftPrefix] ?? '',
      'bp' => $row['bp_' . $leftPrefix] ?? '',
      'chars' => $leftPrefix === 'p1'
        ? [(int)($row['e1'] ?? 0), (int)($row['e2'] ?? 0), (int)($row['e3'] ?? 0)]
        : [(int)($row['u1'] ?? 0), (int)($row['u2'] ?? 0), (int)($row['u3'] ?? 0)],
      'weapons' => $leftPrefix === 'p1'
        ? [$row['w1'] ?? null, $row['w2'] ?? null, $row['w3'] ?? null]
        : [$row['v1'] ?? null, $row['v2'] ?? null, $row['v3'] ?? null],
      'events' => $leftPrefix === 'p1'
        ? ($row['eventindex1'] ?? '')
        : ($row['eventindex2'] ?? ''),
      'deck_cost' => $row['player1_deck_cost'] ?? null,
      'deck_code' => $row['player1_deck_code'] ?? null,
    ],
    'right' => [
      'name' => $row['name_' . $rightPrefix] ?? '',
      'bp' => $row['bp_' . $rightPrefix] ?? '',
      'chars' => $rightPrefix === 'p1'
        ? [(int)($row['e1'] ?? 0), (int)($row['e2'] ?? 0), (int)($row['e3'] ?? 0)]
        : [(int)($row['u1'] ?? 0), (int)($row['u2'] ?? 0), (int)($row['u3'] ?? 0)],
      'weapons' => $rightPrefix === 'p1'
        ? [$row['w1'] ?? null, $row['w2'] ?? null, $row['w3'] ?? null]
        : [$row['v1'] ?? null, $row['v2'] ?? null, $row['v3'] ?? null],
      'events' => $rightPrefix === 'p1'
        ? ($row['eventindex1'] ?? '')
        : ($row['eventindex2'] ?? ''),
      'deck_cost' => $row['player2_deck_cost'] ?? null,
      'deck_code' => $row['player2_deck_code'] ?? null,
    ],
  ];
}

$statusTextMap = [
  'candidate' => '候選',
  'pending'   => '待確認',
  'verified'  => '已確認',
  'rejected'  => '已駁回',
  'tie'       => '平手',
  'rematch'   => '重賽',
  'dispute'   => '爭議',
];

$stmt = $pdo->prepare("
  SELECT
    g.id AS game_id,
    g.game_no,
    g.result_status,
    g.player1_deck_code,
    g.player2_deck_code,
    g.player1_deck_cost,
    g.player2_deck_cost,
    g.winner_player_id,
    g.screenshot_path,
    g.note AS game_note,

    m.id AS match_id,
    m.match_code,
    m.round_name,
    m.tournament_id,

    tp1.display_name AS player1_display_name,
    tp1.game_name AS player1_game_name,
    tp1.seed_no AS player1_seed_no,

    tp2.display_name AS player2_display_name,
    tp2.game_name AS player2_game_name,
    tp2.seed_no AS player2_seed_no,

    winner.display_name AS winner_name,

    a.id AS arena_id,
    a.region,
    a.cost,
    a.stage,

    a.e1, a.e2, a.e3,
    a.u1, a.u2, a.u3,

    a.w1, a.w2, a.w3,
    a.v1, a.v2, a.v3,

    a.eventindex1,
    a.eventindex2,

    a.win,
    a.lose,
    a.tie,
    a.judge_source,
    a.update_time,
    a.username,

    a.name_p1,
    a.bp_p1,
    a.win_p1,
    a.draw_p1,
    a.lose_p1,

    a.name_p2,
    a.bp_p2,
    a.win_p2,
    a.draw_p2,
    a.lose_p2

  FROM tournament_match_games g
  INNER JOIN tournament_matches m
    ON m.id = g.match_id
  LEFT JOIN tournament_players tp1
    ON tp1.id = m.player1_id
  LEFT JOIN tournament_players tp2
    ON tp2.id = m.player2_id
  LEFT JOIN tournament_players winner
    ON winner.id = g.winner_player_id
  LEFT JOIN arena_unlight a
    ON a.id = g.arena_id
  WHERE g.id = :game_id
    AND m.tournament_id = :tournament_id
    AND m.match_code = :match_code
  LIMIT 1
");

$stmt->execute([
  ':game_id' => $gameId,
  ':tournament_id' => $tournamentId,
  ':match_code' => $matchCode,
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {

  $viewerSide = tournamentViewerSide($pdo, $row);

  if (!canViewTournamentGamePage($pdo, $row)) {
    http_response_code(403);
?>
    <style>
      .permission-box {
        background: rgba(46, 52, 64, .92);
        border: 1px solid rgba(180, 200, 255, 0.18);
        border-radius: 14px;
        padding: 18px;
        margin: 20px 0;
        text-align: center;
        color: #e6eaf2;
      }

      .permission-title {
        font-size: 22px;
        font-weight: 900;
        margin-bottom: 10px;
        color: #fff;
      }

      .permission-text {
        color: #b8c0d4;
        line-height: 1.7;
        margin-bottom: 14px;
      }

      .permission-btn {
        display: inline-block;
        padding: 8px 12px;
        border-radius: 8px;
        background: linear-gradient(180deg, #263044, #1c2333);
        color: #e6ecff;
        border: 1px solid rgba(140, 180, 255, 0.35);
        text-decoration: none;
        font-weight: 700;
      }
    </style>

    <div class="content-wrapper">
      <section class="content ul-container-nopad">
        <div class="container">
          <div class="permission-box">
            <div class="permission-title">無權限查看此對戰詳細資料</div>
            <div class="permission-text">
              此頁面僅限本場選手與賽事工作人員查看。<br>
              若你是本場選手，請確認目前登入帳號是否與 ULGG / UL 遊戲名稱一致。
            </div>
            <a class="permission-btn" href="/pages/tournament/ulgg_cup_match.php?match=<?= urlencode($matchCode) ?>">
              返回場次頁
            </a>
          </div>
        </div>
      </section>
    </div>
<?php

    $pageContent = ob_get_clean();
    include __DIR__ . '/../../layout/base.php';
    exit;
  }

  $eventMap = loadEventMap($pdo);
  $weaponMap = loadWeaponMap($pdo);

  $sideMap = mapArenaRowToTournamentSides($row, $row);

  $left = $sideMap['left'];
  $right = $sideMap['right'];

  $canViewLeftDetail = canViewSideDetail($viewerSide, 'left');
  $canViewRightDetail = canViewSideDetail($viewerSide, 'right');

  $leftEvents = decodeEventIndex($left['events']);
  $rightEvents = decodeEventIndex($right['events']);

  $leftWeaponCost = calcWeaponCost($left['weapons'], $weaponMap);
  $rightWeaponCost = calcWeaponCost($right['weapons'], $weaponMap);

  $leftEventCost = calcEventCost($leftEvents, $eventMap);
  $rightEventCost = calcEventCost($rightEvents, $eventMap);

  [$leftResultText, $leftResultClass] = resultLabel($row, (string)$left['name']);
  [$rightResultText, $rightResultClass] = resultLabel($row, (string)$right['name']);

  $showDate = !empty($row['update_time'])
    ? date('Y-m-d H:i:s', strtotime($row['update_time']))
    : '-';

  $gameStatusText = $statusTextMap[$row['result_status']] ?? $row['result_status'];
}


?>

<?php if (isset($_GET['debug_perm']) && $_GET['debug_perm'] === '1'): ?>
  <pre style="background:#111;color:#bfffe3;padding:10px;border-radius:8px;white-space:pre-wrap;">
realLoginName = <?= h(realLoginName()) ?>

currentLoginName = <?= h(currentLoginName($pdo)) ?>

debugAsActive = <?= debugAsActive($pdo) ? 'YES' : 'NO' ?>

realAck = <?= h(getUserAckByName($pdo, realLoginName())) ?>

currentAck = <?= h(getUserAckByName($pdo, currentLoginName($pdo))) ?>

isTournamentAdmin = <?= isTournamentAdmin($pdo) ? 'YES' : 'NO' ?>

viewerSide = <?= is_array($row) ? h(tournamentViewerSide($pdo, $row)) : 'NO ROW' ?>


SESSION:
<?php print_r($_SESSION); ?>
  </pre>
<?php endif; ?>

<style>
  :root {
    --ul-bg: #1c1f26;
    --ul-bg-soft: #252a34;
    --ul-panel: #2e3440;
    --ul-border: rgba(180, 200, 255, 0.18);
    --ul-blue: #6fa8ff;
    --ul-red: #ff6b6b;
    --ul-green: #4dd599;
    --ul-yellow: #e6c77a;
    --ul-text: #e6eaf2;
    --ul-text-soft: #b8c0d4;
    --ul-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
  }

  .detail-page {
    color: var(--ul-text);
  }

  .detail-section {
    background: rgba(46, 52, 64, .92);
    border: 1px solid var(--ul-border);
    border-radius: 14px;
    padding: 14px;
    margin-bottom: 14px;
    box-shadow: var(--ul-shadow);
  }

  .detail-hero {
    background:
      radial-gradient(circle at top left, rgba(111, 168, 255, .25), transparent 34%),
      linear-gradient(180deg, #2e3440, #20242d);
  }

  .detail-head {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
  }

  .detail-kicker {
    color: var(--ul-blue);
    font-size: 13px;
    font-weight: 700;
    letter-spacing: .08em;
    margin-bottom: 6px;
  }

  .detail-title {
    font-size: 28px;
    font-weight: 900;
    margin: 0 0 8px;
  }

  .detail-char-level {
    color: var(--ul-yellow);
    font-size: 12px;
    font-weight: 900;
    line-height: 1.2;
  }

  .detail-char-name {
    color: #fff;
    font-weight: 800;
    font-size: 13px;
    line-height: 1.35;
    word-break: keep-all;
  }

  .detail-sub {
    color: var(--ul-text-soft);
    font-size: 14px;
  }

  .detail-actions {
    margin-top: 12px;
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .detail-btn {
    display: inline-block;
    padding: 8px 12px;
    border-radius: 8px;
    background: linear-gradient(180deg, #263044, #1c2333);
    color: #e6ecff;
    border: 1px solid rgba(140, 180, 255, 0.35);
    text-decoration: none;
    font-weight: 700;
  }

  .detail-btn:hover {
    color: #fff;
    text-decoration: none;
    box-shadow: 0 0 12px rgba(120, 170, 255, .45);
  }

  .detail-status {
    display: inline-block;
    border-radius: 999px;
    padding: 6px 10px;
    font-weight: 800;
    font-size: 13px;
    background: #555;
    color: #fff;
  }

  .detail-info-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
  }

  .detail-info-card {
    background: linear-gradient(180deg, #303643, #252b36);
    border: 1px solid var(--ul-border);
    border-radius: 12px;
    padding: 12px;
  }

  .detail-info-label {
    color: var(--ul-text-soft);
    font-size: 12px;
    margin-bottom: 4px;
  }

  .detail-info-value {
    color: #fff;
    font-weight: 800;
    font-size: 16px;
  }

  .detail-vs {
    display: grid;
    grid-template-columns: 1fr 90px 1fr;
    /* gap: 12px; */
    align-items: stretch;
  }

  .detail-player {
    background: linear-gradient(180deg, #303643, #242935);
    border: 1px solid var(--ul-border);
    border-radius: 12px;
    padding: 14px;
  }

  .detail-player.right {
    text-align: right;
  }

  .detail-player-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 12px;
  }

  .detail-player.right .detail-player-head {
    flex-direction: row-reverse;
  }

  .detail-player-name {
    font-size: 22px;
    font-weight: 900;
    color: #fff;
  }

  .detail-player-meta {
    color: var(--ul-text-soft);
    font-size: 13px;
  }

  .detail-vs-mid {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ul-blue);
    font-size: 24px;
    font-weight: 900;
  }

  .result-pill {
    display: inline-block;
    min-width: 42px;
    border-radius: 8px;
    padding: 5px 9px;
    font-weight: 900;
    font-size: 14px;
  }

  .result-win {
    background: #2e8f61;
    color: #fff;
  }

  .result-lose {
    background: #b73c3c;
    color: #fff;
  }

  .result-tie {
    background: #b79534;
    color: #111;
  }

  .result-unknown {
    background: #6f6f6f;
    color: #fff;
  }

  .detail-char-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
  }

  .detail-char-card {
    background: rgba(0, 0, 0, .16);
    border: 1px solid var(--ul-border);
    border-radius: 10px;
    overflow: hidden;
  }

  .detail-char-img-wrap {
    height: 96px;
    background:
      radial-gradient(circle at center top, rgba(255, 255, 255, .12), transparent 55%),
      linear-gradient(180deg, #3b4350, #2b313c);
    overflow: hidden;
    display: flex;
    align-items: flex-start;
    justify-content: center;
  }

  .detail-char-img-wrap img {
    width: 72px;
    height: 104px;
    object-fit: cover;
  }

  .detail-char-meta {
    padding: 8px;
  }

  .detail-char-name {
    color: #fff;
    font-weight: 800;
    font-size: 13px;
    line-height: 1.35;
  }

  .detail-char-cost,
  .detail-weapon {
    color: var(--ul-text-soft);
    font-size: 12px;
    margin-top: 3px;
  }

  .detail-block-title {
    font-size: 18px;
    font-weight: 900;
    margin: 0 0 10px;
  }



  .event-slot {
    color: var(--ul-text-soft);
    font-size: 11px;
  }

  .event-id {
    color: #fff;
    font-weight: 900;
    font-size: 15px;
    margin-top: 4px;
  }

  .detail-note {
    white-space: pre-wrap;
    color: var(--ul-text-soft);
    font-size: 13px;
    line-height: 1.7;
  }

  .detail-empty {
    color: var(--ul-text-soft);
    text-align: center;
    padding: 22px 8px;
    border: 1px dashed var(--ul-border);
    border-radius: 10px;
    background: rgba(0, 0, 0, .12);
  }

  .server-icon.dmm {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 4px;
    background: rgba(255, 255, 255, .18);
    color: #fff;
  }

  @media (max-width: 900px) {
    .detail-info-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .detail-vs {
      grid-template-columns: 1fr;
    }

    .detail-vs-mid {
      padding: 4px 0;
    }

    .event-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }

  @media (max-width: 520px) {
    .detail-info-grid {
      grid-template-columns: 1fr;
    }

    .detail-char-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 6px;
    }
  }

  .detail-weapon-box {
    margin-top: 6px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    background: rgba(0, 0, 0, .14);
    border: 1px solid rgba(111, 168, 255, .22);
    border-radius: 8px;
    padding: 6px;
    text-align: center;
  }

  .weapon-img {
    width: 36px;
    height: 36px;
    object-fit: cover;
    border-radius: 5px;
    background: #222936;
    border: 1px solid var(--ul-border);
  }

  .weapon-text {
    width: 100%;
    min-width: 0;
    line-height: 1.25;
  }

  .weapon-name {
    color: #fff;
    font-weight: 800;
    font-size: 11px;
    white-space: normal;
    overflow: hidden;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }

  .weapon-cost {
    color: #6fa8ff;
    font-size: 11px;
    font-weight: 800;
    margin-top: 2px;
  }

  .weapon-attr-line {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 3px 4px;
    margin-top: 3px;
    font-size: 10px;
    font-weight: 900;
  }

  .weapon-attr-line span {
    display: inline-block;
    padding: 1px 4px;
    border-radius: 4px;
    background: rgba(255, 255, 255, .08);
    line-height: 1.25;
  }

  .attr-all {
    color: #e6eaf2;
  }

  .attr-melee {
    color: #ff6b6b;
  }

  .attr-ranged {
    color: #4dd599;
  }

  .detail-weapon-empty {
    margin-top: 6px;
    color: var(--ul-text-soft);
    font-size: 12px;
  }



  .event-slot {
    width: 34px;
    height: 48px;
    position: relative;
    border-radius: 5px;
    background: #111;
    border: 1px solid rgba(111, 168, 255, .22);
    overflow: hidden;
  }

  .event-slot.empty {
    opacity: .25;
    border-style: dashed;
  }

  .event-slot.unknown {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 11px;
  }

  .event-img {
    width: 34px;
    height: 48px;
    object-fit: cover;
    display: block;
  }

  .event-cost {
    position: absolute;
    left: 1px;
    bottom: 3px;
    background: rgba(0, 0, 0, .62);
    color: #ffd479;
    font-size: 8px;
    line-height: 1;
    font-weight: 800;
    padding: 1px 2px;
    border-radius: 3px;
  }

  @media (max-width: 520px) {
    .event-row {
      grid-template-columns: repeat(6, 30px);
      gap: 4px;
    }

    .event-slot,
    .event-img {
      width: 30px;
      height: 43px;
    }
  }

  .event-vs-layout {
    display: grid;
    grid-template-columns: 1fr 70px 1fr;
    gap: 12px;
    align-items: stretch;
  }

  .event-player-panel {
    background: linear-gradient(180deg, #303643, #242935);
    border: 1px solid var(--ul-border);
    border-radius: 12px;
    padding: 14px;
  }

  .event-player-panel.right {
    text-align: right;
  }

  .event-player-title {
    color: #fff;
    font-size: 20px;
    font-weight: 900;
    margin-bottom: 12px;
  }

  .event-vs-mid {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ul-blue);
    font-size: 18px;
    font-weight: 900;
    writing-mode: vertical-rl;
    letter-spacing: .12em;
  }

  .event-character-board {
    display: grid;
    grid-template-columns: repeat(3, max-content);
    gap: 14px;
    justify-content: center;
  }

  .event-character-column {
    background:
      linear-gradient(180deg, rgba(180, 160, 120, .14), rgba(0, 0, 0, .10));
    border: 1px solid rgba(230, 199, 122, .28);
    border-radius: 10px;
    /* padding: 9px; */
  }

  .event-character-title {
    color: var(--ul-yellow);
    font-size: 12px;
    font-weight: 900;
    text-align: center;
    margin-bottom: 6px;
  }

  .event-six-grid {
    display: grid;
    grid-template-columns: repeat(3, 46px);
    grid-template-rows: repeat(2, 65px);
    gap: 6px;
  }

  .event-slot {
    width: 46px;
    height: 65px;
    position: relative;
    border-radius: 5px;
    background: #1b1f27;
    border: 1px solid rgba(111, 168, 255, .28);
    overflow: hidden;
  }

  .event-img {
    width: 46px;
    height: 65px;
    object-fit: cover;
    display: block;
  }

  /* COST 改成小角標，不要太大擋住事件卡 */
  .event-cost {
    position: absolute;
    left: 1px;
    bottom: 3px;
    background: rgba(0, 0, 0, .62);
    color: #ffd479;
    font-size: 8px;
    line-height: 1;
    font-weight: 800;
    padding: 1px 2px;
    border-radius: 3px;
  }

  .event-slot.empty {
    opacity: .28;
    border-style: dashed;
    background:
      linear-gradient(135deg, rgba(255, 255, 255, .08) 25%, transparent 25%),
      linear-gradient(225deg, rgba(255, 255, 255, .08) 25%, transparent 25%),
      #111;
  }

  .event-slot.unknown {
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-size: 11px;
  }







  .event-id {
    color: #fff;
    font-weight: 900;
    font-size: 12px;
  }

  @media (max-width: 990px) {
    .event-vs-layout {
      grid-template-columns: 1fr;
    }

    .event-vs-mid {
      writing-mode: horizontal-tb;
      padding: 4px 0;
    }

    .event-player-panel.right {
      text-align: left;
    }
  }

  @media (max-width: 520px) {
    .event-character-board {
      grid-template-columns: 1fr;
      justify-items: center;
    }

    .event-six-grid {
      grid-template-columns: repeat(3, 44px);
      grid-template-rows: repeat(2, 62px);
    }

    .event-slot,
    .event-img {
      width: 44px;
      height: 62px;
    }
  }

  @media (max-width: 520px) {
    .detail-weapon-box {
      gap: 4px;
      padding: 5px 3px;
    }

    .weapon-img {
      width: 30px;
      height: 30px;
    }

    .weapon-name {
      font-size: 9px;
      -webkit-line-clamp: 2;
    }

    .weapon-cost {
      font-size: 9px;
    }

    .weapon-attr-line {
      font-size: 9px;
      gap: 2px;
    }

    .weapon-attr-line span {
      padding: 1px 3px;
    }

    .detail-char-level {
      font-size: 10px;
    }

    .detail-char-name {
      font-size: 11px;
      line-height: 1.25;
    }
  }

  .hidden-detail-box {
    margin-top: 10px;
    background:
      radial-gradient(circle at top, rgba(255, 255, 255, .08), transparent 45%),
      linear-gradient(180deg, #2f3541, #232832);
    border: 1px dashed rgba(230, 199, 122, .35);
    border-radius: 12px;
    padding: 18px 12px;
    text-align: center;
    color: var(--ul-text-soft);
  }

  .hidden-detail-icon {
    font-size: 24px;
    margin-bottom: 6px;
  }

  .hidden-detail-title {
    color: #fff;
    font-size: 15px;
    font-weight: 900;
    margin-bottom: 5px;
  }

  .hidden-detail-text {
    font-size: 12px;
    line-height: 1.6;
  }
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container detail-page">

      <?php if (!$row): ?>
        <div class="detail-section">
          <h2 class="detail-block-title">找不到對戰詳細資料</h2>
          <div class="detail-empty">
            找不到此賽事小局，請確認 game_id 與 match 是否正確。
          </div>
          <div class="detail-actions">
            <a class="detail-btn" href="/pages/tournament/ulgg_cup_match.php?match=<?= urlencode($matchCode) ?>">
              返回場次頁
            </a>
          </div>
        </div>
      <?php else: ?>

        <div class="detail-section detail-hero">
          <div class="detail-head">
            <div>
              <div class="detail-kicker">Unlight：Revive 非官方 ULGG 杯</div>
              <h1 class="detail-title">
                <?= h($row['match_code']) ?>｜Game <?= h($row['game_no']) ?> 對戰詳細
              </h1>
              <div class="detail-sub">
                <?= h($row['round_name']) ?>
                ｜<?= regionIcon($row['region']) ?> <?= h($row['region'] ?: '-') ?>
                ｜<?= h($showDate) ?>
              </div>
            </div>

            <div>
              <span class="detail-status"><?= h($gameStatusText) ?></span>
            </div>
          </div>

          <div class="detail-actions">
            <a class="detail-btn" href="/pages/tournament/ulgg_cup_match.php?match=<?= urlencode($row['match_code']) ?>">
              ← 返回 <?= h($row['match_code']) ?>
            </a>
          </div>
        </div>

        <div class="detail-section">
          <div class="detail-info-grid">
            <div class="detail-info-card">
              <div class="detail-info-label">場次</div>
              <div class="detail-info-value">Game <?= h($row['game_no']) ?></div>
            </div>

            <div class="detail-info-card">
              <div class="detail-info-label">場景</div>
              <div class="detail-info-value"><?= h(stageName($row['stage'], $stages)) ?></div>
            </div>

            <div class="detail-info-card">
              <div class="detail-info-label">房間 COST</div>
              <div class="detail-info-value"><?= h($row['cost'] !== null && $row['cost'] !== '' ? $row['cost'] : '-') ?></div>
            </div>

            <div class="detail-info-card">
              <div class="detail-info-label">小局勝者</div>
              <div class="detail-info-value"><?= h($row['winner_name'] ?: '-') ?></div>
            </div>
          </div>
        </div>

        <div class="detail-section">
          <h2 class="detail-block-title">雙方牌組</h2>

          <div class="detail-vs">
            <div class="detail-player">
              <div class="detail-player-head">
                <div>
                  <div class="detail-player-name"><?= h($left['name']) ?></div>
                  <div class="detail-player-meta">
                    Seed <?= h($row['player1_seed_no']) ?>
                    ｜BP <?= h($left['bp']) ?>
                    ｜Deck COST <?= h($left['deck_cost'] ?? '-') ?>
                    <?php if ($canViewLeftDetail): ?>
                      <br>
                      武器 <?= h($leftWeaponCost) ?>C
                      ｜事件 <?= h($leftEventCost) ?>C
                    <?php endif; ?>
                  </div>
                </div>
                <div>
                  <span class="result-pill <?= h($leftResultClass) ?>"><?= h($leftResultText) ?></span>
                </div>
              </div>

              <?php if ($canViewLeftDetail): ?>
                <?= renderCharPanel($pdo, $left['chars'], $left['weapons'], $weaponMap) ?>
              <?php else: ?>
                <?= renderCharPanel($pdo, $left['chars'], [null, null, null], []) ?>
                <?= renderHiddenDetailBox('武器資訊已隱藏') ?>
              <?php endif; ?>
            </div>

            <div class="detail-vs-mid">VS</div>

            <div class="detail-player right">
              <div class="detail-player-head">
                <div>
                  <div class="detail-player-name"><?= h($right['name']) ?></div>
                  <div class="detail-player-meta">
                    Seed <?= h($row['player2_seed_no']) ?>
                    ｜BP <?= h($right['bp']) ?>
                    ｜Deck COST <?= h($right['deck_cost'] ?? '-') ?>
                    <?php if ($canViewRightDetail): ?>
                      <br>
                      武器 <?= h($rightWeaponCost) ?>C
                      ｜事件 <?= h($rightEventCost) ?>C
                    <?php endif; ?>
                  </div>
                </div>
                <div>
                  <span class="result-pill <?= h($rightResultClass) ?>"><?= h($rightResultText) ?></span>
                </div>
              </div>

              <?php if ($canViewRightDetail): ?>
                <?= renderCharPanel($pdo, $right['chars'], $right['weapons'], $weaponMap) ?>
              <?php else: ?>
                <?= renderCharPanel($pdo, $right['chars'], [null, null, null], []) ?>
                <?= renderHiddenDetailBox('武器資訊已隱藏') ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="detail-section">
          <h2 class="detail-block-title">事件卡</h2>

          <div class="event-vs-layout">
            <div class="event-player-panel">
              <div class="event-player-title">
                <?= h($left['name']) ?>
              </div>
              <?php if ($canViewLeftDetail): ?>
                <?= renderEventCards($left['events'], $eventMap) ?>
              <?php else: ?>
                <?= renderHiddenDetailBox('事件卡已隱藏') ?>
              <?php endif; ?>
            </div>

            <div class="event-vs-mid">EVENT</div>

            <div class="event-player-panel right">
              <div class="event-player-title">
                <?= h($right['name']) ?>
              </div>
              <?php if ($canViewRightDetail): ?>
                <?= renderEventCards($right['events'], $eventMap) ?>
              <?php else: ?>
                <?= renderHiddenDetailBox('事件卡已隱藏') ?>
              <?php endif; ?>
            </div>
          </div>
        </div>



      <?php endif; ?>

    </div>
  </section>
</div>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>