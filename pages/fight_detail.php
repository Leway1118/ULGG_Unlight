<?php
require_once __DIR__ . '/../config.php';
$pdo = $db;

// ===============================
// 對戰詳細頁權限檢查
// permission >= 2 才能查看
// ===============================
$permission = (int)(
  $_SESSION['permission']
  ?? $_SESSION['perm']
  ?? $_SESSION['user_permission']
  ?? 0
);

$currentLoginName = trim((string)(
  $_SESSION['username']
  ?? $_SESSION['loginName']
  ?? $_SESSION['login_name']
  ?? $_SESSION['user_name']
  ?? $_SESSION['name']
  ?? ''
));

$isLoggedIn =
  !empty($_SESSION['steam_id'])
  || $currentLoginName !== '';

if (!$isLoggedIn) {
  http_response_code(403);

  echo '<div style="
    max-width:560px;
    margin:60px auto;
    padding:24px;
    border:1px solid #b73c3c;
    border-radius:12px;
    background:#17171c;
    color:#eee;
    text-align:center;
  ">
    <h2 style="margin-top:0;color:#ff8b8b;">請先登入</h2>
    <p>登入後才能查看對戰詳細資料。</p>
    <a href="/pages/fight.php" style="color:#e8d19e;">返回對戰查詢</a>
  </div>';

  exit;
}

if (!defined('IMG_BASE')) {
  define('IMG_BASE', '/assets/uploads/');
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
// 返回列表網址，保留 fight.php 原本搜尋條件
$backUrl = $_GET['back'] ?? '/pages/fight.php';
$backUrl = urldecode((string)$backUrl);

// 安全限制：只允許回到本站 fight.php，避免外部跳轉
if (preg_match('#^/pages/fight\.php(\?.*)?$#', $backUrl)) {
  // OK
} elseif (preg_match('#^fight\.php(\?.*)?$#', $backUrl)) {
  $backUrl = '/pages/' . $backUrl;
} else {
  $backUrl = '/pages/fight.php';
}

// 安全限制：只允許回到本站 /pages/fight.php，避免外部跳轉
if (!preg_match('#^/pages/fight\.php(\?.*)?$#', $backUrl)) {
  $backUrl = '/pages/fight.php';
}
$pageTitleText = '對戰詳細';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "fight";

ob_start();

function h($v)
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function rowVal(array $row, array $keys, $default = '-')
{
  foreach ($keys as $key) {
    if (isset($row[$key]) && $row[$key] !== '' && $row[$key] !== null) {
      return $row[$key];
    }
  }
  return $default;
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
  if ($stage === null || $stage === '' || $stage === '-') {
    return '-';
  }

  $stageInt = (int)$stage;
  return $stages[$stageInt] ?? ('未知場景 ' . $stageInt);
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

function mainResultText(array $row): array
{
  $win  = (int)($row['win'] ?? 0);
  $lose = (int)($row['lose'] ?? 0);
  $tie  = (int)($row['tie'] ?? 0);

  $p1 = (string)($row['name_p1'] ?? '');
  $p2 = (string)($row['name_p2'] ?? '');

  if ($tie === 1) {
    return ['平手', 'result-tie'];
  }

  if ($win + $lose + $tie === 0) {
    return ['未判定', 'result-unknown'];
  }

  if ($win === 1) {
    return [($p2 !== '' ? $p2 : 'P2') . ' 勝', 'result-win'];
  }

  if ($lose === 1) {
    return [($p1 !== '' ? $p1 : 'P1') . ' 勝', 'result-win'];
  }

  return ['未判定', 'result-unknown'];
}

function getCharCards(PDO $pdo, array $ids): array
{
  $ids = array_values(array_filter(array_map('intval', $ids)));
  if (!$ids) return [];

  $placeholders = implode(',', array_fill(0, count($ids), '?'));

  $stmt = $pdo->prepare("    SELECT id, ico, name, level, cost    FROM unlight    WHERE id IN ($placeholders)  ");
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

function loadEventMap(PDO $pdo): array
{
  try {
    $stmt = $pdo->query("      SELECT id, ico, name, cost      FROM unlight_eventindex    ");
  } catch (Throwable $e) {
    return [];
  }

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
  try {
    $stmt = $pdo->query("      SELECT        id,        ico,        name,        cost,        atk_melee,        atk_ranged,        def_melee,        def_ranged      FROM unlight_weapon    ");
  } catch (Throwable $e) {
    return [];
  }

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

    $ico = trim((string)($c['ico'] ?? ''));
    $icoFs = APP_ROOT . '/assets/uploads/' . ltrim($ico, '/');
    $level = trim((string)($c['level'] ?? ''));
    $name  = trim((string)($c['name'] ?? ''));

    [$atkText, $atkClass] = $weapon
      ? computeAttr((int)$weapon['atk_melee'], (int)$weapon['atk_ranged'], 'ATK')
      : ['', ''];

    [$defText, $defClass] = $weapon
      ? computeAttr((int)$weapon['def_melee'], (int)$weapon['def_ranged'], 'DEF')
      : ['', ''];

    $html .= '<div class="detail-char-card">';

    $html .= '<div class="detail-char-img-wrap">';

    if ($ico !== '' && is_file($icoFs)) {
      $html .= '<img src="' . IMG_BASE . h($ico) . '" loading="lazy" alt="' . h($name) . '">';
    } else {
      $html .= '<div class="detail-char-img-missing" title="卡面圖片待補">'
        . '<i class="fa fa-image" aria-hidden="true"></i>'
        . '<span>卡面待補</span>'
        . '</div>';
    }

    $html .= '</div>'
      . '<div class="detail-char-meta">'
      . '<div class="detail-char-level">' . h($level) . '</div>'
      . '<div class="detail-char-name">' . h($name) . '</div>'
      . '<div class="detail-char-cost">COST ' . h($c['cost'] ?? '-') . '</div>';

    if ($weapon) {
      $weaponIco = $weapon['ico'] ?: '';

      $html .= '<div class="detail-weapon-box">';

      if ($weaponIco !== '') {
        $html .= '          <img class="weapon-img"               src="' . IMG_BASE . h($weaponIco) . '"               loading="lazy"               title="' . h($weapon['name']) . '"               alt="' . h($weapon['name']) . '">        ';
      }

      $html .= '        <div class="weapon-text">          <div class="weapon-name">' . h($weapon['name'] ?: '武器') . '</div>          <div class="weapon-cost">' . h($weapon['cost']) . 'C</div>      ';

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

      $html .= '          </div>        </div>      ';
    } else {
      $html .= '<div class="detail-weapon-empty">武器：無</div>';
    }

    $html .= '      </div>    </div>';
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
    $html .= '<div class="event-character-title">角色 ' . h($charNo + 1) . '</div>';
    $html .= '<div class="event-six-grid">';

    for ($i = 0; $i < 6; $i++) {
      $idx = $startIndex + $i;
      $eid = $events[$idx] ?? null;

      if ($eid === null || $eid === '') {
        $html .= '<div class="event-slot empty"></div>';
        continue;
      }

      $eid = (int)$eid;
      $event = $eventMap[$eid] ?? null;

      if (!$event) {
        $html .= '          <div class="event-slot unknown">            <div class="event-id">' . h($eid) . '</div>          </div>        ';
        continue;
      }

      $ico = $event['ico'] ?: 'na_event.png';
      $name = $event['name'] ?: '';
      $cost = (int)$event['cost'];

      $html .= '        <div class="event-slot">          <img class="event-img"              src="' . IMG_BASE . h($ico) . '"              loading="lazy"              title="' . h($name . ' / ' . $cost . 'C') . '"              alt="' . h($name) . '">          <div class="event-cost">' . h($cost) . 'C</div>        </div>      ';
    }

    $html .= '</div>';
    $html .= '</div>';
  }

  $html .= '</div>';

  return $html;
}
function renderHiddenDetailBox(string $playerName = ''): string
{
  $nameText = $playerName !== '' ? h($playerName) . ' 的' : '';

  return '
    <div class="detail-hidden-box">
      <div class="detail-hidden-icon">🔒</div>
      <div class="detail-hidden-title">' . $nameText . '牌組資訊已隱藏</div>
      <div class="detail-hidden-text">
        此區塊僅限該玩家本人或管理員查看。
      </div>
    </div>
  ';
}

// =========================================================
// 查詢一般對戰資料：fight_detail.php?id=arena_unlight.id
// =========================================================
$row = null;

if ($id > 0) {
  $stmt = $pdo->prepare("    SELECT      id AS arena_id,      region,      cost,      stage,      e1, e2, e3,      u1, u2, u3,      w1, w2, w3,      v1, v2, v3,      eventindex1,      eventindex2,      win,      lose,      tie,      judge_source,      update_time,      username,      name_p1,      bp_p1,      win_p1,      draw_p1,      lose_p1,      name_p2,      bp_p2,      win_p2,      draw_p2,      lose_p2    FROM arena_unlight    WHERE id = :id    LIMIT 1  ");

  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($row) {
  $eventMap = loadEventMap($pdo);
  $weaponMap = loadWeaponMap($pdo);

  $left = [
    'name' => (string)rowVal($row, ['name_p1'], '-'),
    'bp' => rowVal($row, ['bp_p1'], '-'),
    'win_count' => rowVal($row, ['win_p1'], '-'),
    'draw_count' => rowVal($row, ['draw_p1'], '-'),
    'lose_count' => rowVal($row, ['lose_p1'], '-'),
    'chars' => [(int)($row['e1'] ?? 0), (int)($row['e2'] ?? 0), (int)($row['e3'] ?? 0)],
    'weapons' => [$row['w1'] ?? null, $row['w2'] ?? null, $row['w3'] ?? null],
    'events' => $row['eventindex1'] ?? '',
  ];

  $right = [
    'name' => (string)rowVal($row, ['name_p2'], '-'),
    'bp' => rowVal($row, ['bp_p2'], '-'),
    'win_count' => rowVal($row, ['win_p2'], '-'),
    'draw_count' => rowVal($row, ['draw_p2'], '-'),
    'lose_count' => rowVal($row, ['lose_p2'], '-'),
    'chars' => [(int)($row['u1'] ?? 0), (int)($row['u2'] ?? 0), (int)($row['u3'] ?? 0)],
    'weapons' => [$row['v1'] ?? null, $row['v2'] ?? null, $row['v3'] ?? null],
    'events' => $row['eventindex2'] ?? '',
  ];
  // =========================================================
  // 固定從 fight.php 點進來時的左右玩家位置
  // 避免列表 A/B，詳細頁變成 B/A
  // =========================================================
  $sourceLeftName = trim((string)($_GET['p1'] ?? ''));
  $sourceRightName = trim((string)($_GET['p2'] ?? ''));

  if ($sourceLeftName !== '' && $sourceRightName !== '') {
    $currentLeftName = trim((string)($left['name'] ?? ''));
    $currentRightName = trim((string)($right['name'] ?? ''));

    // 如果 detail 目前左右剛好跟來源相反，就交換左右資料
    if ($currentLeftName === $sourceRightName && $currentRightName === $sourceLeftName) {
      $tmp = $left;
      $left = $right;
      $right = $tmp;
    }
  }

  $leftEvents = decodeEventIndex($left['events']);
  $rightEvents = decodeEventIndex($right['events']);

  $leftWeaponCost = calcWeaponCost($left['weapons'], $weaponMap);
  $rightWeaponCost = calcWeaponCost($right['weapons'], $weaponMap);

  $leftEventCost = calcEventCost($leftEvents, $eventMap);
  $rightEventCost = calcEventCost($rightEvents, $eventMap);

  // 先交換左右
  $sourceLeftName = trim((string)($_GET['p1'] ?? ''));
  $sourceRightName = trim((string)($_GET['p2'] ?? ''));

  if ($sourceLeftName !== '' && $sourceRightName !== '') {
    $currentLeftName = trim((string)($left['name'] ?? ''));
    $currentRightName = trim((string)($right['name'] ?? ''));

    if ($currentLeftName === $sourceRightName && $currentRightName === $sourceLeftName) {
      $tmp = $left;
      $left = $right;
      $right = $tmp;
    }
  }

  // 再算 COST


  [$leftResultText, $leftResultClass] = resultLabel($row, (string)$left['name']);
  [$rightResultText, $rightResultClass] = resultLabel($row, (string)$right['name']);
  [$mainResultText, $mainResultClass] = mainResultText($row);

  $showDate = !empty($row['update_time'])
    ? date('Y-m-d H:i:s', strtotime($row['update_time']))
    : '-';

  $region = (string)rowVal($row, ['region'], '-');
  $stage = stageName($row['stage'] ?? null, $stages);
  $roomCost = rowVal($row, ['cost'], '-');

  // =========================================================
  // 權限判斷：比照賽事頁
  // 管理員：看雙方
  // 玩家本人：只看自己
  // 其他會員 / 訪客：都隱藏
  // =========================================================

  // config.php / _admin_gate.php 如果已經有 $permission / $currentLoginName，優先沿用；
  // 沒有的話再從 SESSION 常見欄位抓。
  $permission = max(
    (int)($permission ?? 0),
    (int)($_SESSION['permission'] ?? $_SESSION['perm'] ?? $_SESSION['user_permission'] ?? 0)
  );

  $currentLoginName = trim((string)(
    ($currentLoginName ?? '')
    ?: ($_SESSION['username']
      ?? $_SESSION['loginName']
      ?? $_SESSION['login_name']
      ?? $_SESSION['user_name']
      ?? $_SESSION['name']
      ?? '')
  ));

  $realPermission = $permission;
  $isRealAdmin = $realPermission >= 2;

  // 只有真正管理員可以用 debug_as / debug_perm 模擬身分
  if ($isRealAdmin && isset($_GET['debug_perm'])) {
    $permission = (int)$_GET['debug_perm'];
  }

  $isAdmin = $permission >= 2;

  if ($isRealAdmin && isset($_GET['debug_as'])) {
    $currentLoginName = trim((string)$_GET['debug_as']);
  }

  $isLeftPlayer = $currentLoginName !== '' && $currentLoginName === (string)$left['name'];
  $isRightPlayer = $currentLoginName !== '' && $currentLoginName === (string)$right['name'];

  $canViewLeftDetail = $isAdmin || $isLeftPlayer;
  $canViewRightDetail = $isAdmin || $isRightPlayer;

  if ($isAdmin) {
    $viewerSide = 'admin';
  } elseif ($isLeftPlayer) {
    $viewerSide = 'left';
  } elseif ($isRightPlayer) {
    $viewerSide = 'right';
  } elseif ($currentLoginName !== '') {
    $viewerSide = 'member';
  } else {
    $viewerSide = 'guest';
  }
}
?>

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
    padding-top: 14px;
    padding-bottom: 28px;
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
    color: #fff;
  }

  .detail-sub {
    color: var(--ul-text-soft);
    font-size: 14px;
    line-height: 1.7;
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
    font-weight: 900;
    font-size: 13px;
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
    align-items: stretch;
    gap: 10px;
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
    line-height: 1.7;
  }

  .detail-vs-mid {
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ul-blue);
    font-size: 24px;
    font-weight: 900;
    text-shadow: 0 0 12px rgba(111, 168, 255, .45);
  }

  .result-pill,
  .detail-status {
    display: inline-block;
    min-width: 42px;
    border-radius: 8px;
    padding: 5px 9px;
    font-weight: 900;
    font-size: 14px;
    text-align: center;
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

  .detail-char-img-missing {
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    color: #8a93a0;
    border: 1px dashed #4b5563;
    font-size: 11px;
  }

  .detail-char-img-missing i {
    font-size: 18px;
  }

  .detail-char-meta {
    padding: 8px;
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

  .detail-char-cost,
  .detail-weapon-empty {
    color: var(--ul-text-soft);
    font-size: 12px;
    margin-top: 3px;
  }

  .detail-block-title {
    font-size: 18px;
    font-weight: 900;
    margin: 0 0 10px;
    color: #fff;
  }

  .detail-empty {
    color: var(--ul-text-soft);
    text-align: center;
    padding: 22px 8px;
    border: 1px dashed var(--ul-border);
    border-radius: 10px;
    background: rgba(0, 0, 0, .12);
  }

  .detail-hidden-box {
    min-height: 160px;
    border: 1px dashed rgba(230, 199, 122, .45);
    border-radius: 14px;
    background:
      radial-gradient(circle at top left, rgba(230, 199, 122, .10), transparent 36%),
      rgba(0, 0, 0, .18);
    color: #e6c77a;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    text-align: center;
    padding: 18px;
  }

  .detail-hidden-icon {
    font-size: 28px;
  }

  .detail-hidden-title {
    font-size: 16px;
    font-weight: 900;
    color: #fff;
  }

  .detail-hidden-text {
    color: #b8c0d4;
    font-size: 13px;
    line-height: 1.6;
  }

  .detail-debug-box {
    margin-top: 12px;
    padding: 10px 12px;
    border-radius: 10px;
    background: rgba(0, 0, 0, .25);
    border: 1px dashed rgba(230, 199, 122, .45);
    color: #e6c77a;
    font-family: Consolas, monospace;
    font-size: 12px;
    line-height: 1.6;
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

  .event-vs-layout {
    display: grid;
    grid-template-columns: 1fr 70px 1fr;
    /* gap: 12px; */
    align-items: stretch;
  }

  .event-player-panel {
    background: linear-gradient(180deg, #303643, #242935);
    border: 1px solid var(--ul-border);
    border-radius: 12px;
    padding: 8px;
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
    background: linear-gradient(180deg, rgba(180, 160, 120, .14), rgba(0, 0, 0, .10));
    border: 1px solid rgba(230, 199, 122, .28);
    border-radius: 10px;
    padding: 6px;
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
    .detail-info-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .detail-vs,
    .event-vs-layout {
      grid-template-columns: 1fr;
    }

    .detail-vs-mid,
    .event-vs-mid {
      writing-mode: horizontal-tb;
      padding: 4px 0;
    }

    .detail-player.right,
    .event-player-panel.right {
      text-align: left;
    }

    .detail-player.right .detail-player-head {
      flex-direction: row;
    }
  }

  @media (max-width: 520px) {
    .detail-info-grid {
      grid-template-columns: 1fr;
    }

    .detail-title {
      font-size: 22px;
    }

    .detail-player-name {
      font-size: 19px;
    }

    .detail-char-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 6px;
    }

    .detail-char-img-wrap {
      height: 82px;
    }

    .detail-char-img-wrap img {
      width: 62px;
      height: 92px;
    }

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

    .weapon-img {
      width: 30px;
      height: 30px;
    }

    .weapon-name,
    .weapon-cost,
    .weapon-attr-line {
      font-size: 9px;
    }
  }
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container detail-page">

      <?php if (!$row): ?>
        <div class="detail-section">
          <h2 class="detail-block-title">找不到對戰詳細資料</h2>
          <div class="detail-empty">
            找不到此一般對戰紀錄，請確認網址是否包含正確的 arena_unlight id。<br>
            例如：fight_detail.php?id=148922
          </div>
          <div class="detail-actions">
            <a class="detail-btn" href="<?= h($backUrl) ?>">← 返回對戰列表</a>
          </div>
        </div>
      <?php else: ?>

        <div class="detail-section detail-hero">
          <div class="detail-head">
            <div>
              <div class="detail-kicker">UL.GG 一般對戰詳細</div>
              <h1 class="detail-title">
                <?= h($left['name']) ?> VS <?= h($right['name']) ?>
              </h1>
              <div class="detail-sub">
                #<?= h($row['arena_id']) ?>
                ｜<?= regionIcon($region) ?> <?= h($region) ?>
                ｜<?= h($showDate) ?>
              </div>
            </div>

            <div>
              <span class="detail-status <?= h($mainResultClass) ?>"><?= h($mainResultText) ?></span>
            </div>
          </div>

          <div class="detail-actions">
            <a class="detail-btn" href="<?= h($backUrl) ?>">← 返回對戰列表</a>
          </div>
          <?php if (($permission ?? 0) >= 2 && isset($_GET['debug'])): ?>
            <div class="detail-debug-box">
              currentLoginName = <?= h($currentLoginName ?? '') ?><br>
              realPermission = <?= h($realPermission ?? $permission ?? 0) ?><br>
              permission = <?= h($permission ?? 0) ?><br>
              viewerSide = <?= h($viewerSide ?? '') ?><br>
              canViewLeftDetail = <?= !empty($canViewLeftDetail) ? 'YES' : 'NO' ?><br>
              canViewRightDetail = <?= !empty($canViewRightDetail) ? 'YES' : 'NO' ?><br>
              leftPlayer = <?= h($left['name'] ?? '') ?><br>
              rightPlayer = <?= h($right['name'] ?? '') ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="detail-section">
          <div class="detail-info-grid">
            <div class="detail-info-card">
              <div class="detail-info-label">對戰 ID</div>
              <div class="detail-info-value">#<?= h($row['arena_id']) ?></div>
            </div>

            <div class="detail-info-card">
              <div class="detail-info-label">場景</div>
              <div class="detail-info-value"><?= h($stage) ?></div>
            </div>

            <div class="detail-info-card">
              <div class="detail-info-label">房間 COST</div>
              <div class="detail-info-value"><?= h($roomCost) ?></div>
            </div>

            <div class="detail-info-card">
              <div class="detail-info-label">判定來源</div>
              <div class="detail-info-value"><?= h(rowVal($row, ['judge_source'], '-')) ?></div>
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
                    BP <?= h($left['bp']) ?>
                    ｜勝 <?= h($left['win_count']) ?>
                    ｜平 <?= h($left['draw_count']) ?>
                    ｜敗 <?= h($left['lose_count']) ?>
                    <br>
                    武器 <?= $canViewLeftDetail ? h($leftWeaponCost) . 'C' : '?C' ?>
                    ｜事件 <?= $canViewLeftDetail ? h($leftEventCost) . 'C' : '?C' ?>
                  </div>
                </div>
                <div>
                  <span class="result-pill <?= h($leftResultClass) ?>"><?= h($leftResultText) ?></span>
                </div>
              </div>

              <?php if ($canViewLeftDetail): ?>
                <?= renderCharPanel($pdo, $left['chars'], $left['weapons'], $weaponMap) ?>
              <?php else: ?>
                <?= renderHiddenDetailBox((string)$left['name']) ?>
              <?php endif; ?>
            </div>

            <div class="detail-vs-mid">VS</div>

            <div class="detail-player right">
              <div class="detail-player-head">
                <div>
                  <div class="detail-player-name"><?= h($right['name']) ?></div>
                  <div class="detail-player-meta">
                    BP <?= h($right['bp']) ?>
                    ｜勝 <?= h($right['win_count']) ?>
                    ｜平 <?= h($right['draw_count']) ?>
                    ｜敗 <?= h($right['lose_count']) ?>
                    <br>
                    武器 <?= $canViewRightDetail ? h($rightWeaponCost) . 'C' : '?C' ?>
                    ｜事件 <?= $canViewRightDetail ? h($rightEventCost) . 'C' : '?C' ?>
                  </div>
                </div>
                <div>
                  <span class="result-pill <?= h($rightResultClass) ?>"><?= h($rightResultText) ?></span>
                </div>
              </div>

              <?php if ($canViewRightDetail): ?>
                <?= renderCharPanel($pdo, $right['chars'], $right['weapons'], $weaponMap) ?>
              <?php else: ?>
                <?= renderHiddenDetailBox((string)$right['name']) ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="detail-section">
          <h2 class="detail-block-title">事件卡</h2>

          <div class="event-vs-layout">
            <div class="event-player-panel">
              <div class="event-player-title"><?= h($left['name']) ?></div>
              <?php if ($canViewLeftDetail): ?>
                <?= renderEventCards($left['events'], $eventMap) ?>
              <?php else: ?>
                <?= renderHiddenDetailBox((string)$left['name']) ?>
              <?php endif; ?>
            </div>

            <div class="event-vs-mid">EVENT</div>

            <div class="event-player-panel right">
              <div class="event-player-title"><?= h($right['name']) ?></div>
              <?php if ($canViewRightDetail): ?>
                <?= renderEventCards($right['events'], $eventMap) ?>
              <?php else: ?>
                <?= renderHiddenDetailBox((string)$right['name']) ?>
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
include __DIR__ . '/../layout/base.php';
?>