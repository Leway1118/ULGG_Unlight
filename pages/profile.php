<?php
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定
header('X-Robots-Tag: noindex, nofollow');


$seoTitle = '我的檔案 Profile | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "ranking_team"; //.php
$pageTitleFull = '我的檔案 Profile | UL.GG 戰績網'; //桌機
$pageTitleText = '我的檔案 Profile'; //手機



// 未登入 → 導回登入
if (empty($_SESSION['steam_id'])) {
  $_SESSION['login_redirect'] = '/pages/profile.php';
  header('Location: /pages/login.php');
  exit;
}

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* ================================
   UL.GG Profile Page Style
   ================================ */

  .ul-card {
    border: 1px solid rgba(255, 255, 255, 0.06);
    border-radius: 12px;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
    color: #e6eaf2;
    margin: 10px;
    position: relative;
    overflow: hidden;

    background:
      radial-gradient(circle at 10% 0%,
        rgba(143, 105, 196, .10),
        transparent 34%),
      linear-gradient(180deg,
        #1b1e29,
        #171922);

    border-color: rgba(197, 180, 225, .14);
  }

  .ul-card .card-header {
    background: transparent;
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    font-weight: 600;
    color: #f0f3ff;
    padding: 14px 18px;
  }

  .ul-card .card-body {
    padding: 18px;
  }

  /* ===== 玩家摘要 ===== */
  .ul-card h3 {
    font-weight: 700;
  }

  .ul-card .badge {
    font-size: 0.8rem;
    padding: 6px 10px;
  }

  /* ===== 角色卡 ===== */
  .character-card {
    background: rgba(255, 255, 255, 0.03);
    border-radius: 10px;
    padding: 10px 6px;
    transition: all 0.25s ease;
    height: 100%;
    display: flex;
    flex-direction: column;
    align-items: center;
    /* ⭐ 左右置中 */
    text-align: center;
    /* ⭐ 文字置中 */
    min-width: 100px;
  }

  .character-card img {
    border-radius: 8px;
    max-height: 96px;
    object-fit: cover;
  }

  .character-card:hover {
    transform: translateY(-4px);
    background: rgba(255, 255, 255, 0.06);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.6);
  }

  /* ===== BP / QP 統計 ===== */
  .stat-box {
    background: rgba(255, 255, 255, 0.035);
    border-radius: 10px;
    padding: 14px 10px;
    margin-bottom: 12px;
  }

  .stat-title {
    font-size: 0.85rem;
    color: #aeb4d4;
    margin-bottom: 4px;
  }

  .stat-value {
    font-size: 1.6rem;
    font-weight: 700;
    color: #ffffff;
  }

  /* ===== 對戰紀錄 ===== */
  .table {
    color: #e6eaf2;
  }

  .table thead th {
    border-bottom: 1px solid rgba(255, 255, 255, 0.15);
    font-size: 0.85rem;
    color: #cfd5ff;
  }

  .table tbody tr {
    transition: background 0.2s ease;
  }

  .table tbody tr:hover {
    background: rgba(255, 255, 255, 0.04);
  }

  /* ===== 鎖定提示 ===== */
  .ul-card.border-warning {
    border-color: rgba(255, 193, 7, 0.6) !important;
    background: linear-gradient(180deg, #2a2416, #1e1a10);
  }

  .ul-card.border-warning h4 {
    color: #ffda6a;
  }

  /* ===== 手機微調 ===== */
  @media (max-width: 768px) {
    .stat-value {
      font-size: 1.3rem;
    }

    .ul-card {
      border-radius: 10px;
    }
  }

  .colStyle {
    float: none;
    margin: 0 auto;
  }

  /* ===== 近期對戰紀錄 ===== */

  .ul-match-table thead th {
    font-size: 0.75rem;
    color: #aeb4d4;
    border-bottom: 1px solid rgba(255, 255, 255, .12);
  }

  .ul-match-table tbody tr {
    transition: background .15s ease;
  }

  .ul-match-table tbody tr:hover {
    background: rgba(255, 255, 255, .04);
  }

  .match-chars {
    font-weight: 500;
    letter-spacing: .2px;
  }

  /* 勝敗標籤 */
  .match-result {
    display: inline-block;
    min-width: 34px;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 700;
  }

  .result-win {
    color: #0b2a1a;
    background: linear-gradient(180deg, #3cff9a, #1dbf73);
    box-shadow: 0 0 6px rgba(61, 255, 154, .35);
  }

  .result-lose {
    color: #2a0b0b;
    background: linear-gradient(180deg, #ff6b6b, #e74c3c);
    box-shadow: 0 0 6px rgba(255, 107, 107, .35);
  }

  .result-draw {
    color: #2b2400;
    background: linear-gradient(180deg, #f1c40f, #d4ac0d);
    box-shadow: 0 0 6px rgba(241, 196, 15, .35);
  }

  /* ===== 主力角色卡片排列 ===== */
  .ul-char-grid {
    display: flex;
    justify-content: space-evenly;
    /* ⭐ 平均分散 */
    align-items: stretch;
    /* gap: 12px; */
    /* 卡片間距 */
    justify-content: center;
  }

  .ul-char-grid>[class*="col-"] {
    flex: 0 1 160px;
    /* ⭐ 固定卡片視覺寬度 */
    max-width: 160px;
    margin: 5px 0px;
  }

  @media (max-width: 768px) {
    .ul-char-grid {
      justify-content: space-between;
      flex-wrap: wrap;
      /* ⭐ 你指定的 */
    }

    .ul-char-grid>[class*="col-"] {
      flex: 0 0 calc(50% - 6px);
      /* 一排兩張 */
      max-width: calc(50% - 6px);
    }
  }

  /* ===== 最擅長角色：可點擊強化 ===== */
  .char-link {
    text-decoration: none;
    color: inherit;
    position: relative;
  }

  .char-link::after {
    content: "🔍 查看角色分析";
    position: absolute;
    bottom: 6px;
    /* right: 6px; */
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 6px;
    background: rgba(0, 0, 0, 0.55);
    color: #cfd5ff;
    opacity: 0;
    transition: opacity .2s ease;
  }

  .char-link:hover::after {
    opacity: 1;
  }

  .char-link:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 22px rgba(0, 0, 0, .65);
  }

  /* ===== 強勢角色說明區 ===== */
  .strong-char-note {
    border-top: 1px dashed rgba(255, 255, 255, 0.12);
    padding-top: 10px;
    line-height: 1.5;
    padding: 4px;
  }

  .strong-char-note .fw-semibold {
    letter-spacing: .2px;
  }

  /* ================================
   今日命運能力
================================ */

  /* ================================
   今日命運能力
================================ */

  .fortune-profile-card {
    position: relative;
    overflow: hidden;

    background:
      radial-gradient(circle at 10% 0%,
        rgba(143, 105, 196, .10),
        transparent 34%),
      linear-gradient(180deg,
        #1b1e29,
        #171922);

    border-color: rgba(197, 180, 225, .14);
  }

  .fortune-profile-card .card-header {
    background:
      linear-gradient(90deg,
        rgba(125, 89, 175, .12),
        rgba(255, 255, 255, .015));

    color: #ece5f6;
  }

  .fortune-profile-header-actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 10px;
    flex-wrap: wrap;
  }

  .fortune-profile-draw-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-height: 32px;
    padding: 6px 12px;

    border: 1px solid rgba(226, 198, 125, .42);
    border-radius: 999px;

    color: #f5e5b6;
    background:
      linear-gradient(180deg,
        rgba(116, 79, 163, .72),
        rgba(57, 40, 88, .82));

    font-size: 12px;
    font-weight: 700;
    line-height: 1.2;
    cursor: pointer;

    box-shadow: 0 4px 12px rgba(0, 0, 0, .24);
    transition:
      transform .18s ease,
      border-color .18s ease,
      box-shadow .18s ease;
  }

  .fortune-profile-draw-btn:hover,
  .fortune-profile-draw-btn:focus {
    color: #fff2c9;
    border-color: rgba(240, 213, 143, .72);
    transform: translateY(-1px);
    box-shadow: 0 6px 16px rgba(0, 0, 0, .32);
    outline: none;
  }

  .fortune-profile-layout {
    display: grid;
    grid-template-columns: 145px minmax(250px, 1fr) 210px;
    gap: 22px;
    align-items: center;
  }

  /* 卡圖 */

  .fortune-card-preview {
    min-width: 0;
    text-align: center;
  }

  .fortune-card-image {
    width: 128px;
    aspect-ratio: 2 / 3;
    margin: 0 auto;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 11px;
    background: rgba(255, 255, 255, .025);

    box-shadow:
      0 12px 24px rgba(0, 0, 0, .32),
      0 0 18px rgba(164, 119, 216, .10);
  }

  .fortune-card-image img {
    width: 100%;
    height: 100%;
    display: block;
    object-fit: contain;

    border-radius: 9px;
  }

  .fortune-card-placeholder {
    color: #7f7788;
    font-size: 12px;
  }

  .fortune-card-caption {
    margin-top: 8px;
    color: #e8d8ae;
    font-size: 13px;
    font-weight: 700;
  }

  .fortune-card-skill {
    margin-top: 2px;
    color: #a9a0b3;
    font-size: 11px;
  }

  /* 六角形圖 */

  .fortune-radar-panel {
    min-width: 0;
  }

  .fortune-radar-title {
    margin-bottom: 4px;

    color: #d8d1df;
    font-size: 13px;
    font-weight: 700;
    text-align: center;
  }

  .fortune-radar-wrap {
    position: relative;
    width: 100%;
    max-width: 330px;
    height: 245px;
    margin: 0 auto;
  }

  /* 等級摘要 */

  .fortune-profile-side {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .fortune-level-box {
    padding: 13px;

    border: 1px solid rgba(208, 188, 232, .14);
    border-radius: 11px;

    background: rgba(255, 255, 255, .025);
  }

  .fortune-level-row {
    display: flex;
    align-items: center;
    gap: 11px;
  }

  .fortune-level-orb {
    width: 48px;
    height: 48px;
    flex-shrink: 0;

    display: flex;
    align-items: center;
    justify-content: center;

    border: 1px solid rgba(226, 198, 125, .48);
    border-radius: 50%;

    color: #ffe28d;
    background:
      radial-gradient(circle at 35% 30%,
        #7353a5,
        #342744 72%);

    font-size: 18px;
    font-weight: 800;
  }

  .fortune-level-title {
    color: #f1dfaa;
    font-size: 15px;
    font-weight: 700;
  }

  .fortune-level-meta {
    margin-top: 2px;
    color: #a9a0b3;
    font-size: 11px;
  }

  .fortune-exp-track {
    height: 6px;
    margin-top: 9px;
    overflow: hidden;

    border-radius: 999px;
    background: rgba(255, 255, 255, .07);
  }

  .fortune-exp-bar {
    height: 100%;
    border-radius: inherit;

    background:
      linear-gradient(90deg,
        #9d78cc,
        #e5c477);
  }

  .fortune-profile-counters {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 7px;
  }

  .fortune-counter {
    padding: 8px 4px;

    border: 1px solid rgba(197, 180, 225, .12);
    border-radius: 9px;

    text-align: center;
    background: rgba(255, 255, 255, .018);
  }

  .fortune-counter strong {
    display: block;

    color: #ead290;
    font-size: 16px;
    line-height: 1.2;
  }

  .fortune-counter span {
    display: block;
    margin-top: 3px;

    color: #92899d;
    font-size: 10px;
  }

  /* 累積負荷 */

  .fortune-pressure-box {
    padding: 8px 10px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    border: 1px solid rgba(183, 130, 202, .16);
    border-radius: 9px;

    color: #b9afc2;
    background: rgba(105, 65, 121, .06);

    font-size: 12px;
  }

  .fortune-pressure-box strong {
    color: #cf9fe1;
  }

  /* 六項能力快速數字 */

  .fortune-aspect-mini-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 7px;
    margin-top: 16px;
  }

  .fortune-aspect-mini {
    padding: 7px 9px;

    display: flex;
    align-items: center;
    justify-content: space-between;

    border: 1px solid rgba(197, 180, 225, .10);
    border-radius: 8px;

    background: rgba(255, 255, 255, .018);
  }

  .fortune-aspect-mini.is-best {
    border-color: rgba(222, 190, 103, .38);
    background: rgba(202, 164, 70, .055);
  }

  .fortune-aspect-mini span {
    color: #bcb4c5;
    font-size: 11px;
  }

  .fortune-aspect-mini strong {
    color: #ebd48f;
    font-size: 13px;
  }

  /* 最近命運 */

  .fortune-latest {
    margin-top: 15px;
    padding: 11px 13px;

    display: grid;
    grid-template-columns: auto 1fr;
    gap: 8px 15px;

    border-top: 1px dashed rgba(255, 255, 255, .09);
    color: #aaa2b2;
  }

  .fortune-latest-title {
    color: #d6cde0;
    font-size: 12px;
    font-weight: 700;
  }

  .fortune-latest-main {
    color: #e9dfcb;
    font-size: 12px;
  }

  .fortune-latest-stars {
    color: #eacb70;
    font-size: 13px;
    letter-spacing: 1px;
  }

  .fortune-latest-tip {
    grid-column: 1 / -1;

    color: #a59dac;
    font-size: 11px;
    line-height: 1.55;
  }

  /* RWD */

  @media (max-width: 900px) {
    .fortune-profile-layout {
      grid-template-columns: 125px 1fr;
    }

    .fortune-profile-side {
      grid-column: 1 / -1;

      display: grid;
      grid-template-columns: 1fr 1fr;
    }
  }

  @media (max-width: 600px) {
    .fortune-profile-layout {
      grid-template-columns: 105px 1fr;
      gap: 12px;
    }

    .fortune-card-image {
      width: 96px;
    }

    .fortune-radar-wrap {
      height: 210px;
    }

    .fortune-profile-side {
      display: flex;
    }

    .fortune-aspect-mini-grid {
      grid-template-columns: repeat(2, 1fr);
    }

    .fortune-latest {
      display: block;
    }

    .fortune-latest-main,
    .fortune-latest-stars,
    .fortune-latest-tip {
      margin-top: 5px;
    }
  }

  #fortune-profile {
    scroll-margin-top: 80px;
  }
</style>

<?php
function regionIcon(?string $region): string
{
  $region = strtoupper(trim((string)$region));

  return match ($region) {
    'TW' => '<i class="fab fa-steam"></i>',
    'JP' => '<span class="server-icon dmm">D</span>',
    'CR' => '<span class="region-globe">☯</span>',
    default => '未註冊',
  };
}
function timeAgo(?string $datetime): string
{
  if (!$datetime) {
    return '—';
  }

  $ts = strtotime($datetime);

  if ($ts === false) {
    return '—';
  }

  $diff = max(0, time() - $ts);

  if ($diff < 3600) {
    return floor($diff / 60) . ' 分鐘前';
  }

  if ($diff < 86400) {
    return floor($diff / 3600) . ' 小時前';
  }

  return floor($diff / 86400) . ' 天前';
}
// ===== Profile 隱私總開關（Phase 0：全鎖）=====
$PROFILE_LOCKDOWN = false;
// ===== 權限旗標預設值（防 Undefined variable）=====
$isOwner = false;
$isAdmin = false;
$isAdminView = false;

// 是否允許顯示完整 Profile
$canViewProfile = !$PROFILE_LOCKDOWN || $isOwner || $isAdmin;

// 是否已綁定
$isBind = !empty($_SESSION['username']);
$isAdminView =
  ($_SESSION['permission'] ?? 0) >= 2 &&
  isset($_GET['admin_view'], $_GET['player']) &&
  $_GET['admin_view'] == '1';

// ❌ 只禁止亂用 admin_view
if (isset($_GET['admin_view']) && !$isAdminView) {
  http_response_code(403);
  exit('Forbidden');
}
// ⭐ 玩家來源：優先 GET，其次自己
if (!empty($_GET['player'])) {
  $playerName = $_GET['player'];
} else {
  $playerName = $_SESSION['username'];
}
if (!$canViewProfile) {
  http_response_code(403);
  exit('Profile locked');
}
// ===== 玩家帳號狀態（game_user）=====
$userInfo = null;

$sql = "
  SELECT
    username,
    ack,
    region,
    steamID,
    black_list,
    show_private
  FROM game_user
  WHERE username = :username
  LIMIT 1
";

$stmt = $db->prepare($sql);
$stmt->execute([':username' => $playerName]);
$userInfo = $stmt->fetch(PDO::FETCH_ASSOC);

// ===== 使用者 Region =====
$userRegion = $userInfo['region'] ?? 'TW';

// 🔹 結算 / 賽季用（TOP6、期間）
$bpHistoryTable = match ($userRegion) {
  'JP' => 'ranking_bp_JP_history',
  default => 'ranking_bp_TW_history',
};
$qpHistoryHistoryTable = match ($userRegion) {
  'JP' => 'ranking_qp_JP_history',
  default => 'ranking_qp_TW_history',
};

// 🔹 長期平均用（即時榜）
$bpCurrentTable = match ($userRegion) {
  'JP' => 'ranking_bp_JP',
  default => 'ranking_bp_TW',
};
$qpCurrentTable = match ($userRegion) {
  'JP' => 'ranking_qp_JP',
  default => 'ranking_qp_TW',
};



// 狀態判斷
$isVerifiedUser = ($userInfo && (int)$userInfo['ack'] >= 1);
$isBannedUser   = ($userInfo && (int)$userInfo['black_list'] === 1);



// ⭐ admin_view 只是「額外權限」，不是決定 player 的來源
$isOwner = ($playerName === ($_SESSION['username'] ?? null));
$isAdmin = $isAdminView;
$canViewPrivate = $isOwner || $isAdmin;

/*
 * =====================================================
 * 今日命運累積資料
 * =====================================================
 */
$fortuneProfile = null;
$fortuneLatest = null;

/*
 * 查看自己的頁面時使用 Session Steam ID；
 * 管理員查看其他玩家時，使用 game_user.steamID。
 */
$profileSteamId = trim(
  (string)(
    $userInfo['steamID']
    ?? (
      $isOwner
      ? ($_SESSION['steam_id'] ?? '')
      : ''
    )
  )
);

if ($profileSteamId !== '') {
  /*
   * 累積能力資料。
   */
  $fortuneProfileStmt = $db->prepare("
    SELECT
      steam_id,
      total_draws,
      current_streak,
      longest_streak,
      fortune_exp,
      fortune_level,

      mobility_total,
      offense_total,
      guard_total,
      insight_total,
      resource_total,
      change_total,
      pressure_total,

      pressure_days,
      five_star_count,
      last_fortune_date
    FROM member_fortune_profile
    WHERE steam_id = :steam_id
    LIMIT 1
  ");

  $fortuneProfileStmt->execute([
    'steam_id' => $profileSteamId,
  ]);

  $fortuneProfile =
    $fortuneProfileStmt->fetch(PDO::FETCH_ASSOC)
    ?: null;

  /*
   * 最近一次正式命運紀錄。
   */
  $fortuneLatestStmt = $db->prepare("
  SELECT
    d.id,
    d.card_id,
    d.chara_code,
    d.fortune_date,
    d.character_name,
    d.character_level,
    d.skill_name,
    d.main_luck,
    d.stars,

    d.mobility_value,
    d.offense_value,
    d.guard_value,
    d.insight_value,
    d.resource_value,
    d.change_value,
    d.pressure_value,

    d.fortune_exp,
    d.message,
    d.tip,

    u.ico AS card_ico
  FROM member_fortune_daily d
  LEFT JOIN unlight u
    ON u.id = d.card_id
  WHERE d.steam_id = :steam_id
    AND d.is_test = 0
  ORDER BY d.fortune_date DESC, d.id DESC
  LIMIT 1
");

  $fortuneLatestStmt->execute([
    'steam_id' => $profileSteamId,
  ]);

  $fortuneLatest =
    $fortuneLatestStmt->fetch(PDO::FETCH_ASSOC)
    ?: null;
}
$fortuneAspectLabels = [
  'mobility_total' => '機動力',
  'offense_total'  => '攻勢力',
  'guard_total'    => '守護力',
  'insight_total'  => '洞察力',
  'resource_total' => '資源力',
  'change_total'   => '變化力',
];

$fortuneAspectIcons = [
  'mobility_total' => '🏃',
  'offense_total'  => '⚔️',
  'guard_total'    => '🛡️',
  'insight_total'  => '👁️',
  'resource_total' => '💎',
  'change_total'   => '🌀',
];

/*
 * 找出目前累積最高能力。
 */
$fortuneBestAspectKey = null;
$fortuneBestAspectValue = 0;

if ($fortuneProfile) {
  foreach ($fortuneAspectLabels as $key => $label) {
    $value = (int)($fortuneProfile[$key] ?? 0);

    if (
      $fortuneBestAspectKey === null
      || $value > $fortuneBestAspectValue
    ) {
      $fortuneBestAspectKey = $key;
      $fortuneBestAspectValue = $value;
    }
  }
}

$fortuneCurrentExp =
  (int)($fortuneProfile['fortune_exp'] ?? 0);

$fortuneCurrentLevel =
  max(
    1,
    (int)($fortuneProfile['fortune_level'] ?? 1)
  );

/*
 * 目前採每 100 EXP 一級。
 */
$fortuneExpInLevel =
  $fortuneCurrentExp % 100;

$fortuneExpPercent =
  max(
    0,
    min(100, $fortuneExpInLevel)
  );
/*
 * 今日命運卡圖。
 */
$fortuneLatestCardImage = '';

if (
  $fortuneLatest
  && !empty($fortuneLatest['card_ico'])
) {
  $fortuneLatestCardImage =
    IMG_BASE
    . ltrim(
      (string)$fortuneLatest['card_ico'],
      '/'
    );
}

/*
 * 六角形能力圖資料。
 */
$fortuneRadarValues = [
  (int)($fortuneProfile['mobility_total'] ?? 0),
  (int)($fortuneProfile['offense_total'] ?? 0),
  (int)($fortuneProfile['guard_total'] ?? 0),
  (int)($fortuneProfile['insight_total'] ?? 0),
  (int)($fortuneProfile['resource_total'] ?? 0),
  (int)($fortuneProfile['change_total'] ?? 0),
];

$fortuneRadarMax = max(
  5,
  (int)(
    ceil(
      max($fortuneRadarValues ?: [0]) / 5
    ) * 5
  )
);

// ===== 預設空資料 =====
$profileStats = [
  'match_count' => 0,
  'last_active' => null,
];
$topChars = [];


if ($isBind) {
  $sql = "
    SELECT
      COUNT(*) AS match_count,
      MAX(update_time) AS last_active
    FROM arena_player_match_result
    WHERE player_name = :player
  ";

  $stmt = $db->prepare($sql);
  $stmt->execute([':player' => $playerName]);
  $profileStats = $stmt->fetch();
}
$profileStats['match_count']; // 總對戰數
$profileStats['last_active']; // 最後一場時間


// ===== 統計時段（賽季起訖）=====
$season = [
  'season_start' => null,
  'season_end'   => null,
];

$bpStats = [
  'avg_rank'        => null,
  'best_rank'       => null,
  'appear_rate'     => null,
];

if ($isBind) {
  $sql = "
    SELECT
      ROUND(AVG(rank_num), 1) AS avg_rank,
      MIN(rank_num)           AS best_rank,
      COUNT(*)                AS appear_count,
      (
        SELECT COUNT(DISTINCT ts)
        FROM {$bpCurrentTable}
      ) AS total_snapshots
    FROM {$bpCurrentTable}
    WHERE name = :player
  ";

  $stmt = $db->prepare($sql);
  $stmt->execute([':player' => $playerName]);
  $row = $stmt->fetch();

  if ($row && $row['appear_count'] > 0 && $row['total_snapshots'] > 0) {
    $bpStats['avg_rank']  = $row['avg_rank'];
    $bpStats['best_rank'] = $row['best_rank'];
    $bpStats['appear_rate'] =
      round($row['appear_count'] / $row['total_snapshots'] * 100, 1);
  }


  $sql = "
    SELECT
      MIN(ts) AS season_start,
      MAX(ts) AS season_end
    FROM {$bpHistoryTable}
  ";

  $stmt = $db->prepare($sql);
  $stmt->execute();                 // ✅ 少的就是這行
  $season = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 顯示用字串
$statPeriodText = '—';
$isSeasonUnstable = false;

if (!empty($season['season_start']) && !empty($season['season_end'])) {
  $startTs = strtotime($season['season_start']);
  $endTs   = strtotime($season['season_end']);

  $seasonDays = floor(($endTs - $startTs) / 86400) + 1;

  $statPeriodText =
    date('Y/m/d', $startTs) .
    ' – ' .
    date('Y/m/d', $endTs);

  // ⭐ 新規則：小於 3 天視為未穩定
  if ($seasonDays < 3) {
    $isSeasonUnstable = true;
  }
}
$qpStats = [
  'avg_rank'    => null,
  'best_rank'   => null,
  'appear_rate' => null,
];

// ===== 本期（TOP 6 專用）：BP 最新一期 → 現在 =====
$sql = "
  SELECT MAX(ts) AS period_start
  FROM {$bpHistoryTable}
";

$stmt = $db->prepare($sql);
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);

$topPeriodStart = $row['period_start'] ?? null;
$topPeriodEnd   = date('Y-m-d H:i:s');

// ===== TOP 6 顯示用（本期：BP 最新一期 → 現在）=====
$topStatPeriodText = '—';
$isTopPeriodUnstable = false;

if (!empty($topPeriodStart)) {
  $startTs = strtotime($topPeriodStart);
  $endTs   = time();

  $topStatPeriodText =
    date('Y/m/d', $startTs) . ' – ' . date('Y/m/d', $endTs);

  $periodDays = floor(($endTs - $startTs) / 86400) + 1;

  // 本期小於 3 天 → 標示不穩定
  if ($periodDays < 3) {
    $isTopPeriodUnstable = true;
  }
}

/* TOP6 */
$sql = "SELECT
  x.char_id,
  u.name  AS char_name,
  u.level AS char_level,
  u.ico   AS char_ico,
  COUNT(*) AS use_count,
  ROUND(SUM(x.is_win = 1) / COUNT(*) * 100, 1) AS win_rate,
  (
    (SUM(x.is_win = 1) / COUNT(*)) * LOG10(COUNT(*) + 1)
  ) AS score
FROM (
  SELECT player_name, leader_id AS char_id, is_win
  FROM arena_player_match_result
  WHERE leader_id IS NOT NULL
    AND update_time >= :period_start

  UNION ALL
  SELECT player_name, back1_id AS char_id, is_win
  FROM arena_player_match_result
  WHERE back1_id IS NOT NULL
    AND update_time >= :period_start

  UNION ALL
  SELECT player_name, back2_id AS char_id, is_win
  FROM arena_player_match_result
  WHERE back2_id IS NOT NULL
    AND update_time >= :period_start
) x
JOIN unlight u ON u.id = x.char_id
WHERE x.player_name = :player
GROUP BY x.char_id, u.name, u.level, u.ico
HAVING COUNT(*) >= 5
ORDER BY score DESC
LIMIT 6
";

$stmt = $db->prepare($sql);
$stmt->execute([
  ':player'       => $playerName,
  ':period_start' => $topPeriodStart,
]);
$topChars = $stmt->fetchAll(PDO::FETCH_ASSOC);


if ($isBind) {
  $sql = "
    SELECT
      ROUND(AVG(rank_num), 1) AS avg_rank,
      MIN(rank_num)           AS best_rank,
      COUNT(*)                AS appear_count,
      (
        SELECT COUNT(DISTINCT ts)
        FROM {$qpCurrentTable}
      ) AS total_snapshots
    FROM {$qpCurrentTable}
    WHERE name = :player
  ";

  $stmt = $db->prepare($sql);
  $stmt->execute([':player' => $playerName]);
  $row = $stmt->fetch();

  if ($row && $row['appear_count'] > 0 && $row['total_snapshots'] > 0) {
    $qpStats['avg_rank'] =
      $row['avg_rank'];

    $qpStats['best_rank'] =
      $row['best_rank'];

    $qpStats['appear_rate'] =
      round($row['appear_count'] / $row['total_snapshots'] * 100, 1);
  }
}
$recentMatches = [];

if ($isBind && $canViewPrivate) {
  $sql = "
    SELECT
      m.match_id,
      m.update_time,
      m.leader_id,
      m.back1_id,
      m.back2_id,
      m.is_win
    FROM arena_player_match_result m
    WHERE m.player_name = :player
    ORDER BY m.update_time DESC
    LIMIT 10
  ";

  $stmt = $db->prepare($sql);
  $stmt->execute([':player' => $playerName]);
  $recentMatches = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$charNameMap = [];

if (!empty($recentMatches)) {
  $charIds = [];

  foreach ($recentMatches as $m) {
    foreach (['leader_id', 'back1_id', 'back2_id'] as $k) {
      if (!empty($m[$k])) {
        $charIds[] = (int)$m[$k];
      }
    }
  }

  $charIds = array_values(array_unique($charIds));

  // ⭐⭐⭐ 關鍵防呆
  if (count($charIds) > 0) {
    $in = implode(',', array_fill(0, count($charIds), '?'));
    $sql = "SELECT id, name, level FROM unlight WHERE id IN ($in)";
    $stmt = $db->prepare($sql);
    $stmt->execute($charIds);

    foreach ($stmt->fetchAll() as $row) {
      $charNameMap[$row['id']] =
        $row['level'] . ' ' . $row['name'];
    }
  }
}

/* 統計本期對戰場次 */
$periodMatchCount = 0;

if ($isBind && $topPeriodStart) {
  $sql = "
    SELECT COUNT(*) 
    FROM arena_player_match_result
    WHERE player_name = :player
      AND update_time >= :period_start
  ";

  $stmt = $db->prepare($sql);
  $stmt->execute([
    ':player'       => $playerName,
    ':period_start' => $topPeriodStart,
  ]);

  $periodMatchCount = (int)$stmt->fetchColumn();
}


?>
<meta name="robots" content="noindex,nofollow">
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <div class="col-md-8 mx-auto colStyle">
          <?php if ($isAdminView): ?>
            <div class="alert alert-warning">
              🛠 管理者檢查模式｜
              目前檢查玩家： <strong><?= htmlspecialchars($playerName) ?></strong>
            </div>
          <?php endif; ?>
          <div class="card ul-card mb-4">
            <div class="card-body d-flex align-items-center justify-content-between">
              <div>
                <h3 class="mb-1">
                  👤 玩家：
                  <span class="text-info">
                    <?= htmlspecialchars($playerName) ?>
                  </span>
                </h3>

                <div class="text-muted small">
                  伺服器：<?= regionIcon($userInfo['region'] ?? 'Unknown') ?>
                  ｜已分析對戰數：<?= number_format($profileStats['match_count']) ?> 場
                  <?php if ($periodMatchCount > 0): ?>
                    ｜本期：<?= number_format($periodMatchCount) ?> 場
                  <?php endif; ?>
                </div>

              </div>

              <div class="text-end">

                <?php if ($isBannedUser): ?>
                  <div class="badge bg-danger mb-1">
                    ⛔ 帳號停權
                  </div>

                <?php elseif ($isVerifiedUser): ?>
                  <div class="badge badge-ul-bind mb-1">
                    <i class="fa fa-link me-1"></i> 已綁定會員
                  </div>

                <?php else: ?>
                  <div class="badge bg-secondary mb-1">
                    👤 未綁定
                  </div>
                <?php endif; ?>

                <span class="text-muted small">
                  最近活躍：<?= timeAgo($profileStats['last_active']) ?>
                </span>
              </div>
            </div>
          </div>
          <!-- /.ul-card mb-4 -->
          <?php if ($canViewPrivate): ?>
            <div
              id="fortune-profile"
              class="card ul-card fortune-profile-card mb-4">
              <div class="card-header d-flex align-items-center justify-content-between">
                <span>🔮 今日命運能力</span>

                <div class="fortune-profile-header-actions">
                  <?php if ($fortuneProfile): ?>
                    <span class="text-muted small">
                      最後領取：
                      <?= !empty($fortuneProfile['last_fortune_date'])
                        ? htmlspecialchars(
                          date(
                            'Y/m/d',
                            strtotime($fortuneProfile['last_fortune_date'])
                          )
                        )
                        : '—' ?>
                    </span>
                  <?php endif; ?>

                  <?php if ($isOwner): ?>
                    <button
                      id="fortuneOpenBtn"
                      class="fortune-profile-draw-btn"
                      type="button"
                      title="開啟今日占卜"
                      aria-label="開啟今日占卜"
                      style="visibility:hidden;">
                      <span aria-hidden="true">🔮</span>
                      <span>今日占卜</span>
                    </button>
                  <?php endif; ?>
                </div>
              </div>

              <div class="card-body">
                <?php if (!$fortuneProfile): ?>

                  <div class="text-center text-muted py-4">
                    尚未累積今日命運能力。<br>
                    <span class="small">
                      完成每日命運後，能力值將記錄在這裡。
                    </span>
                  </div>

                <?php else: ?>

                  <div class="fortune-profile-layout">

                    <!-- 左：最近抽到的卡 -->
                    <div class="fortune-card-preview">
                      <div class="fortune-card-image">
                        <?php if ($fortuneLatestCardImage !== ''): ?>
                          <img
                            src="<?= htmlspecialchars(
                                    $fortuneLatestCardImage,
                                    ENT_QUOTES,
                                    'UTF-8'
                                  ) ?>"
                            alt="<?= htmlspecialchars(
                                    (string)($fortuneLatest['character_name'] ?? ''),
                                    ENT_QUOTES,
                                    'UTF-8'
                                  ) ?>"
                            loading="lazy"
                            onerror="this.style.display='none';">
                        <?php else: ?>
                          <div class="fortune-card-placeholder">
                            尚無卡圖
                          </div>
                        <?php endif; ?>
                      </div>

                      <?php if ($fortuneLatest): ?>
                        <div class="fortune-card-caption">
                          <?= htmlspecialchars(
                            (string)$fortuneLatest['character_name']
                          ) ?>
                          <?= htmlspecialchars(
                            (string)$fortuneLatest['character_level']
                          ) ?>
                        </div>

                        <div class="fortune-card-skill">
                          <?= htmlspecialchars(
                            (string)$fortuneLatest['skill_name']
                          ) ?>
                        </div>
                      <?php endif; ?>
                    </div>

                    <!-- 中：六角形能力圖 -->
                    <div class="fortune-radar-panel">
                      <div class="fortune-radar-title">
                        累積能力分布
                      </div>

                      <div class="fortune-radar-wrap">
                        <canvas id="fortuneRadarChart"></canvas>
                      </div>
                    </div>

                    <!-- 右：等級與統計 -->
                    <div class="fortune-profile-side">

                      <div class="fortune-level-box">
                        <div class="fortune-level-row">
                          <div class="fortune-level-orb">
                            <?= $fortuneCurrentLevel ?>
                          </div>

                          <div>
                            <div class="fortune-level-title">
                              命運等級 Lv.<?= $fortuneCurrentLevel ?>
                            </div>

                            <div class="fortune-level-meta">
                              <?= number_format($fortuneCurrentExp) ?> EXP
                              ｜<?= $fortuneExpInLevel ?>/100
                            </div>
                          </div>
                        </div>

                        <div class="fortune-exp-track">
                          <div
                            class="fortune-exp-bar"
                            style="width:<?= $fortuneExpPercent ?>%;">
                          </div>
                        </div>
                      </div>

                      <div class="fortune-profile-counters">
                        <div class="fortune-counter">
                          <strong>
                            <?= number_format(
                              (int)$fortuneProfile['total_draws']
                            ) ?>
                          </strong>
                          <span>累積占卜</span>
                        </div>

                        <div class="fortune-counter">
                          <strong>
                            <?= number_format(
                              (int)$fortuneProfile['current_streak']
                            ) ?>
                          </strong>
                          <span>連續天數</span>
                        </div>

                        <div class="fortune-counter">
                          <strong>
                            <?= number_format(
                              (int)$fortuneProfile['five_star_count']
                            ) ?>
                          </strong>
                          <span>五星次數</span>
                        </div>
                      </div>

                      <div class="fortune-pressure-box">
                        <span>⚠ 累積負荷</span>

                        <strong>
                          <?= (int)$fortuneProfile['pressure_total'] > 0
                            ? '+'
                            : '' ?>
                          <?= number_format(
                            (int)$fortuneProfile['pressure_total']
                          ) ?>
                        </strong>
                      </div>
                    </div>

                  </div>

                  <!-- 六項能力數值 -->
                  <div class="fortune-aspect-mini-grid">
                    <?php foreach (
                      $fortuneAspectLabels
                      as $aspectKey => $aspectLabel
                    ): ?>
                      <?php
                      $aspectValue = (int)(
                        $fortuneProfile[$aspectKey] ?? 0
                      );

                      $isBestAspect =
                        $aspectKey === $fortuneBestAspectKey;
                      ?>

                      <div class="fortune-aspect-mini <?= $isBestAspect ? 'is-best' : '' ?>">
                        <span>
                          <?= $fortuneAspectIcons[$aspectKey] ?? '' ?>
                          <?= htmlspecialchars($aspectLabel) ?>
                        </span>

                        <strong>
                          <?= $aspectValue > 0 ? '+' : '' ?>
                          <?= number_format($aspectValue) ?>
                        </strong>
                      </div>
                    <?php endforeach; ?>
                  </div>

                  <?php if ($fortuneLatest): ?>
                    <div class="fortune-latest">
                      <div class="fortune-latest-title">
                        最近一次命運
                      </div>

                      <div class="fortune-latest-main">
                        <?= htmlspecialchars(
                          (string)$fortuneLatest['main_luck']
                        ) ?>
                        ・
                        <?= htmlspecialchars(
                          (string)$fortuneLatest['skill_name']
                        ) ?>
                      </div>

                      <div class="fortune-latest-stars">
                        <?= str_repeat(
                          '★',
                          (int)$fortuneLatest['stars']
                        ) ?>
                        <?= str_repeat(
                          '☆',
                          5 - (int)$fortuneLatest['stars']
                        ) ?>
                      </div>

                      <div></div>

                      <div class="fortune-latest-tip">
                        今日戰術：
                        <?= htmlspecialchars(
                          (string)$fortuneLatest['tip']
                        ) ?>
                      </div>
                    </div>
                  <?php endif; ?>

                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <?php
          $checkpointDeadline = strtotime('2025-12-31 23:59:59');
          $isCheckpointOpen   = time() <= $checkpointDeadline;
          ?>

          <!-- <div class="card ul-card mb-4 border-warning">
            <div class="card-body d-flex align-items-center justify-content-between flex-wrap">

              <div>
                <h4 class="mb-1">📊 年度 檢查站</h4>
                <div class="text-muted small">
                  回顧你在競技場的一年軌跡
                </div>

                <?php if (!$isBind): ?>
                  <div class="mt-1 small text-warning">
                    🔒 需先綁定 UNLIGHT ID 才能使用
                  </div>

                <?php elseif (!$isCheckpointOpen): ?>
                  <div class="mt-1 small text-muted">
                    ⏳ 2025 年度 檢查站 已結束
                  </div>

                <?php else: ?>
                  <div class="mt-1 small text-success">
                    ✔ 2025 年度回顧開放中 ~ 2025/12/31 23:59
                  </div>
                <?php endif; ?>
              </div>

              <div class="mt-2 mt-md-0">
                <?php if ($isBind && $isCheckpointOpen): ?>                  
                  <a href="checkpoint/index.php" class="btn btn-warning">
                    查看 2025 檢查站 →
                  </a>

                <?php elseif ($isBind && !$isCheckpointOpen): ?>
                  <button class="btn btn-outline-secondary" disabled>
                    2025 已結束
                  </button>

                <?php else: ?>
                  <button class="btn btn-outline-secondary" disabled>
                    尚未解鎖
                  </button>
                <?php endif; ?>
              </div>

            </div>
          </div> -->




        </div>
      </div>
      <!-- /.row -->
      <div class="row">
        <div class="col-md-8 mx-auto colStyle">
          <div class="card ul-card mb-4">
            <div class="card-header">
              🔥 本期表現最佳 TOP 6
            </div>
            <div class="small text-muted mt-2 px-2">
              📅 統計時段：
              <span class="text-info fw-semibold">
                <?= htmlspecialchars($topStatPeriodText) ?>
              </span>
            </div>
            <?php if ($isTopPeriodUnstable): ?>
              <div class="small text-warning mt-1 px-2">
                ⚠️ 本賽季資料累積未滿 3 天，排名與表現可能尚未穩定
              </div>
            <?php endif; ?>

            <!-- ⭐⭐ 關鍵：ul-char-grid 只放這裡 ⭐⭐ -->
            <div class="card-body">
              <?php if (!$isBind): ?>

                <div class="text-center text-muted py-4">
                  🔒 綁定 UNLIGHT ID 後即可查看你的主力角色
                </div>

              <?php elseif (empty($topChars)): ?>

                <div class="text-center text-muted py-4">
                  尚無足夠對戰資料 ( 大於或等於 5 場)
                </div>

              <?php else: ?>

                <!-- ⭐ Flex 容器 -->
                <div class="ul-char-grid">

                  <?php foreach ($topChars as $row): ?>

                    <?php
                    $charName  = $row['char_level'] . ' ' . $row['char_name'];
                    $charIco   = $row['char_ico'];
                    $useCount  = (int)$row['use_count'];
                    $winRate   = (float)$row['win_rate'];

                    if ($winRate >= 55) {
                      $rateClass = 'text-success';
                    } elseif ($winRate < 45) {
                      $rateClass = 'text-danger';
                    } else {
                      $rateClass = 'text-warning';
                    }

                    $charImg = IMG_BASE . $charIco;

                    $isStrong = (
                      $row['score'] >= 1.2 &&
                      $useCount >= 10
                    );
                    ?>

                    <div class="col-6 col-sm-4 col-md-2 mb-3">
                      <a
                        href="/pages/analysis_character_card.php?char_id=<?= (int)$row['char_id'] ?>"
                        class="character-card char-link"
                        title="查看 <?= htmlspecialchars($charName) ?> 的角色分析">

                        <?php if ($isStrong): ?>
                          <div class="badge bg-success mt-1">🔥 強勢角色</div>
                        <?php endif; ?>

                        <img
                          src="<?= htmlspecialchars($charImg) ?>"
                          class="img-fluid mb-2"
                          alt="<?= htmlspecialchars($charName) ?>"
                          loading="lazy"
                          onerror="this.src='/assets/img/char_placeholder.png';">

                        <div class="fw-bold">
                          <?= htmlspecialchars($charName) ?>
                        </div>

                        <div class="small text-muted">
                          使用 <?= number_format($useCount) ?> 場
                        </div>

                        <div class="small <?= $rateClass ?>">
                          勝率 <?= $winRate ?>%
                        </div>

                      </a>
                    </div>

                  <?php endforeach; ?>

                </div>

              <?php endif; ?>

            </div>

            <div class="mt-3 px-2 strong-char-note">
              <div class="small text-muted">
                <span class="fw-semibold text-success">🔥 強勢角色定義：</span>
                當角色在<span class="text-warning">累積 10 場以上實戰</span>的前提下，
                其<span class="text-info">綜合評比 ≥ 1.2</span>，
                即判定為強勢角色。
              </div>

              <div class="small text-muted mt-1">
                <span class="fw-semibold text-info">📐 綜合評比計算方式：</span>
                以<span class="text-success">勝率</span>
                除以
                <span class="text-warning">LOG10（使用場次 + 1）</span>，
                用以平衡高勝率與樣本數可信度。
              </div>
            </div>

          </div>
        </div>
      </div>


      <div class="row">
        <div class="col-md-8 mx-auto colStyle">
          <div class="card ul-card mb-4">
            <div class="card-header">
              📈 BP / QP 長期表現 ( 未上榜不列入計算 )
            </div>
            <div class="card-body">
              <div class="row text-center">

                <div class="col-6 col-md-3">
                  <div class="stat-box">
                    <div class="stat-title">BP 平均排名</div>
                    <div class="stat-value">
                      <?= $bpStats['avg_rank'] !== null ? $bpStats['avg_rank'] : '—' ?>
                    </div>
                  </div>
                </div>

                <div class="col-6 col-md-3">
                  <div class="stat-box">
                    <div class="stat-title">BP 最佳排名</div>
                    <div class="stat-value text-warning">
                      <?= $bpStats['best_rank'] !== null ? '#' . $bpStats['best_rank'] : '—' ?>
                    </div>
                  </div>
                </div>

                <div class="col-6 col-md-3">
                  <div class="stat-box">
                    <div class="stat-title">QP 平均排名</div>
                    <div class="stat-value">
                      <?= $qpStats['avg_rank'] !== null ? $qpStats['avg_rank'] : '—' ?>
                    </div>
                  </div>
                </div>

                <div class="col-6 col-md-3">
                  <div class="stat-box">
                    <div class="stat-title">QP 最佳排名</div>
                    <div class="stat-value text-info">
                      <?= $qpStats['best_rank'] !== null ? '#' . $qpStats['best_rank'] : '—' ?>
                    </div>
                  </div>
                </div>


              </div>

            </div>
          </div>
        </div>
      </div>
      <div class="row">
        <div class="col-md-8 mx-auto colStyle">
          <div class="card ul-card mb-4">

            <div class="card-header d-flex align-items-center justify-content-between">
              <span>🕒 近期對戰紀錄</span>
              <span class="text-muted small">最近 10 場</span>
            </div>

            <div class="card-body p-0">
              <table class="table table-sm mb-0 ul-match-table">
                <thead>
                  <tr>
                    <th style="width: 90px;">時間</th>
                    <th>角色組合</th>
                    <th style="width: 70px;" class="text-center">結果</th>
                  </tr>
                </thead>

                <tbody>

                  <!-- PHP 動態輸出從這裡開始 -->

                  <?php if (!$canViewPrivate): ?>
                    <tr>
                      <td colspan="3" class="text-center text-muted py-4">
                        🔒 僅限本人查看近期對戰紀錄
                      </td>
                    </tr>

                  <?php elseif (empty($recentMatches)): ?>
                    <tr>
                      <td colspan="3" class="text-center text-muted py-4">
                        尚無對戰紀錄
                      </td>
                    </tr>

                  <?php else: ?>
                    <?php foreach ($recentMatches as $m): ?>

                      <?php
                      $timeText = date('m/d H:i', strtotime($m['update_time']));

                      // 角色名稱組合
                      $charNames = [];
                      foreach (['leader_id', 'back1_id', 'back2_id'] as $k) {
                        if (!empty($m[$k]) && isset($charNameMap[$m[$k]])) {
                          $charNames[] = $charNameMap[$m[$k]];
                        }
                      }
                      $charText = implode(' / ', $charNames);

                      // 勝負
                      $isWin = ($m['is_win'] === null) ? null : (int)$m['is_win'];

                      if ($isWin === 1) {
                        $resultText  = '勝';
                        $resultClass = 'result-win';
                      } elseif ($isWin === 0) {
                        $resultText  = '敗';
                        $resultClass = 'result-lose';
                      } else {
                        $resultText  = '平';
                        $resultClass = 'result-draw';
                      }
                      ?>

                      <tr>
                        <td class="text-muted"><?= $timeText ?></td>
                        <td class="match-chars">
                          <?= htmlspecialchars($charText) ?>
                        </td>
                        <td class="text-center">
                          <span class="match-result <?= $resultClass ?>">
                            <?= $resultText ?>
                          </span>
                        </td>
                      </tr>

                    <?php endforeach; ?>
                  <?php endif; ?>

                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <!-- <div class="row">
        <div class="col-md-8 mx-auto colStyle">
          <div class="card ul-card border-warning">
            <div class="card-body text-center">
              <h4 class="mb-3">🔒 個人戰術分析尚未解鎖</h4>
              <p class="text-muted">
                綁定 UNLIGHT ID 即可查看你的角色偏好、勝率趨勢與長期排名表現。
              </p>
              <a href="/pages/bind.php" class="btn btn-warning">
                立即綁定 UNLIGHT ID
              </a>
            </div>
          </div>
        </div>
      </div> -->

    </div>
    <!-- /.container -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->


<?php if ($fortuneProfile): ?>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const canvas =
        document.getElementById('fortuneRadarChart');

      if (
        !canvas ||
        typeof Chart === 'undefined'
      ) {
        return;
      }

      const radarValues =
        <?= json_encode(
          $fortuneRadarValues,
          JSON_UNESCAPED_UNICODE
        ) ?>;

      new Chart(canvas, {
        type: 'radar',

        data: {
          labels: [
            '機動力',
            '攻勢力',
            '守護力',
            '洞察力',
            '資源力',
            '變化力'
          ],

          datasets: [{
            data: radarValues,

            backgroundColor: 'rgba(157, 120, 204, .18)',

            borderColor: 'rgba(220, 191, 126, .82)',

            pointBackgroundColor: 'rgba(238, 211, 145, 1)',

            pointBorderColor: '#30283a',

            pointHoverBackgroundColor: '#ffffff',

            pointHoverBorderColor: 'rgba(220, 191, 126, 1)',

            borderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 4
          }]
        },

        options: {
          responsive: true,
          maintainAspectRatio: false,

          animation: {
            duration: 700
          },

          plugins: {
            legend: {
              display: false
            },

            tooltip: {
              displayColors: false,

              callbacks: {
                label(context) {
                  return `${context.label}：${context.raw}`;
                }
              }
            }
          },

          scales: {
            r: {
              beginAtZero: true,
              min: 0,
              suggestedMax: <?= (int)$fortuneRadarMax ?>,

              ticks: {
                display: false,
                stepSize: 5
              },

              grid: {
                color: 'rgba(220, 213, 230, .10)'
              },

              angleLines: {
                color: 'rgba(220, 213, 230, .10)'
              },

              pointLabels: {
                color: 'rgba(223, 217, 229, .82)',

                font: {
                  size: 11,
                  weight: '600'
                }
              }
            }
          }
        }
      });
    });
  </script>
<?php endif; ?>

<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>