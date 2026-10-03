<?php

/**
 * analysis_character_card.php
 *
 * Single Card Analysis Page
 *
 * URL:
 *   /pages/analysis_character_card.php?char_id={card_id}
 *
 * Back Link:
 *   /pages/character.php?char_base={L1id}-{name}
 */

session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/_analysis_base.php';
$activeTab = $_GET['tab'] ?? 'synergy';
$pdo = $db;

$pageTitleText = '單卡分析 Card Analysis';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "character_analysis";

ob_start();
$validTabs = ['synergy', 'counter', 'position', 'event', 'map', 'skill'];

$activeTab = $_GET['tab'] ?? 'synergy';
if (!in_array($activeTab, $validTabs, true)) {
  $activeTab = 'synergy';
}
?>

<style>
  .nav-tabs .nav-link {
    color: #9aa4b2;
  }

  .nav-tabs .nav-link.active {
    background: #1b1e27;
    color: #fff;
    border-color: #2a2e3a #2a2e3a #1b1e27;
  }

  .table-striped>tbody>tr:nth-of-type(odd) {
    background-color: #0b0c10 !important;
  }

  .table-striped>tbody>tr:nth-of-type(even) {
    background-color: #10121a !important;
  }
</style>
<style>
  /* =========================================================
 * UL.GG 角色分析頁專用 Style
 * ========================================================= */

  /* ---------- 基本卡片 ---------- */
  .ul-card {
    background: #1b1e27;
    border: 1px solid #2a2e3a;
    border-radius: 8px;
    padding: 16px 18px;
    margin-bottom: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
  }

  /* ---------- 數字統計卡 ---------- */
  .stat-card {
    text-align: center;
    min-height: 108px;
    /* margin: 5px; */
  }

  .stat-title {
    font-size: 13px;
    color: #9aa4b2;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
  }

  .stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #e6e9ef;
  }

  /* ---------- 頁面標題 ---------- */
  .page-title {
    font-size: 26px;
    font-weight: 700;
    color: #f2f4f8;
    letter-spacing: 1px;
  }

  /* ---------- 卡片標題 ---------- */
  .card-title {
    font-size: 16px;
    font-weight: 600;
    color: #f0f2f6;
    margin-bottom: 12px;
  }

  /* ---------- 圖表區 ---------- */
  .chart-area {
    width: 100%;
    min-height: 220px;
    background: #161922;
    border: 1px dashed #2f3443;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .chart-placeholder {
    color: #6f7687;
    font-size: 13px;
    letter-spacing: 0.5px;
  }

  /* ---------- 技能摘要 ---------- */
  .highlight-card {
    border-left: 4px solid #8fa3ff;
  }

  .skill-summary {
    padding-left: 18px;
    margin: 0;
  }

  .skill-summary li {
    color: #d6dae3;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 6px;
  }

  /* ---------- 玩家 Top 5 表格 ---------- */
  .table-dark {
    background-color: #161922;
  }

  .table-dark th {
    color: #aab1c3;
    font-size: 13px;
    border-bottom: 1px solid #2a2e3a;
  }

  .table-dark td {
    color: #e6e9ef;
    font-size: 14px;
    border-top: 1px solid #2a2e3a;
  }

  .table-dark tbody tr:hover {
    background-color: #1f2430;
  }

  /* ---------- 小字說明 ---------- */
  .text-muted.small {
    color: #8c93a6 !important;
  }

  /* ---------- 角色圖片 ---------- */
  .character-stand-img {
    max-height: 210px;
    filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.6));
  }

  /* ---------- RWD 微調 ---------- */
  @media (max-width: 768px) {
    .page-title {
      font-size: 22px;
    }

    .stat-value {
      font-size: 24px;
    }

    .chart-area {
      min-height: 180px;
    }
  }

  .table-dark td.rank-player {
    color: #f2c94c;
    font-weight: 600;
  }

  /* ===============================
 * UL.GG Dark Table (Soft Line)
 * =============================== */

  .table-dark {
    background-color: #151822;
    border-collapse: separate;
    border-spacing: 0;
  }

  /* 表頭 */
  .table-dark thead th {
    background-color: #1a1e2a;
    color: #9aa4b2;
    font-weight: 600;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  }

  /* 表身：關鍵在這 */
  .table-dark tbody td {
    color: #e0e3ea;
    border-top: 1px solid rgba(255, 255, 255, 0.035);
    /* 幾乎隱形 */
  }

  /* 第一列不要線（避免雙線） */
  .table-dark tbody tr:first-child td {
    border-top: none;
  }

  /* Stripe：極淡，幾乎不可察 */
  .table-striped>tbody>tr:nth-of-type(odd) {
    background-color: rgba(255, 255, 255, 0.015);
  }

  /* Hover：比 stripe 深一點即可 */
  .table-dark tbody tr:hover {
    background-color: rgba(255, 255, 255, 0.045);
  }

  .stat-sub {
    margin-top: 6px;
    font-size: 0.85rem;
    color: #9aa4b2;
    letter-spacing: 0.3px;
  }

  .stat-sub-rate {
    margin-left: 4px;
    color: #cfd3dc;
  }

  .analysis-sticky-info {
    position: fixed;
    top: 0;
    /* mobile 用 0 */
    left: 0;
    right: 0;
    z-index: 999;

    background: #151822;
    border-bottom: 1px solid rgba(255, 255, 255, .08);

    /* 🔑 關鍵防塌 */
    min-height: 32px;
    padding: 0px 12px;
    line-height: 1.4;

    display: flex;
    align-items: center;

    opacity: 0;
    pointer-events: none;
    transform: translateY(-100%);
    transition: transform .18s ease, opacity .18s ease;
  }

  /* 顯示狀態 */
  .analysis-sticky-info.show {
    opacity: 1;
    pointer-events: auto;
    transform: translateY(0);
  }

  /* 桌機補 headbar */
  @media (min-width: 769px) {
    .analysis-sticky-info {
      top: 56px;
    }
  }

  .stat-value:hover {
    text-decoration: underline;
    opacity: 0.9;
  }

  /* ===============================
 * 單卡分析 CTA 卡面
 * =============================== */

  .card-cta {
    width: 84px;
    padding: 8px 6px;
    background: #161922;
    border: 1px solid #2a2e3a;
    border-radius: 8px;
    text-align: center;
    color: #e6e9ef;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }

  .card-cta:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.45);
  }

  .card-cta-ico {
    width: 48px;
    height: 48px;
    object-fit: contain;
    margin-bottom: 4px;
  }

  .card-cta-level {
    font-size: 13px;
    font-weight: 600;
    margin-top: 2px;
  }

  .card-cta-rate {
    font-size: 12px;
    color: #8fa3ff;
    margin-top: 2px;
  }

  .card-cta-rate.muted {
    color: #6f7687;
  }

  .card-album {
    display: flex;
    flex-wrap: wrap
  }

  /* ===============================
 * UL.GG 開發中 / 施工中標語
 * =============================== */
  .ul-notice-dev {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 14px;
    background: linear-gradient(180deg, #1b1f2a, #151822);
    border: 1px dashed #3a3f55;
    border-radius: 8px;
    color: #d6dae3;
  }

  .ul-notice-icon {
    font-size: 22px;
    line-height: 1;
  }

  .ul-notice-text strong {
    font-size: 14px;
    font-weight: 600;
    color: #f2f4f8;
  }

  .ul-notice-text .small {
    color: #9aa4b2;
    margin-top: 2px;
    font-size: 12px;
  }

  /* 手機微調 */
  @media (max-width: 768px) {
    .ul-notice-dev {
      padding: 8px 10px;
    }

    .ul-notice-icon {
      font-size: 20px;
    }

    .table-responsive {
      border: 0px;
    }
  }

  /* ===============================
 * UL.GG Tab Content Dark Theme
 * =============================== */

  /* TAB 內容外框 */
  .tab-content {
    background: #1b1e27;
    border: 1px solid #2a2e3a;
    border-top: none;
    /* 與 nav-tabs 接起來 */
    border-radius: 0 0 8px 8px;
    padding: 16px 18px;
  }

  /* 每個 tab-pane 保持一致 */
  .tab-pane {
    color: #e6e9ef;
  }

  /* 防止 Bootstrap 預設白底殘留 */
  .tab-pane,
  .tab-pane.fade {
    background: transparent;
  }

  /* 若 tab 內有 ul-card，縮小間距更緊湊 */
  .tab-pane .ul-card:first-child {
    margin-top: 0;
  }

  .nav-tabs {
    border-bottom: 1px solid #2a2e3a;
  }

  .nav-tabs>li>a {
    background: #161922;
    border: 1px solid #2a2e3a;
    border-bottom: none;
    color: #9aa4b2;
  }

  .nav-tabs>li.active>a,
  .nav-tabs>li.active>a:hover,
  .nav-tabs>li.active>a:focus {
    background: #1b1e27;
    color: #ffffff;
    border-color: #2a2e3a #2a2e3a transparent;
  }



  .sticky-inner {
    max-width: 1280px;
    margin: 0 auto;
    padding: 8px 16px;

    display: flex;
    align-items: center;
    justify-content: center;
    /* ⭐ 改這行 */
    gap: 12px;

    font-size: 14px;
  }

  .sticky-title {
    font-weight: 600;
    color: #e6eaf2;
    white-space: nowrap;
    text-align: center;
  }

  .sticky-stats {
    color: #9aa4b2;
    font-size: 13px;
    white-space: nowrap;
  }

  /* COST 區間按鈕容器 */
  .cost-filter-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;

    gap: 0px;
    /* ⭐ 行距 / 列距分開控制 */
  }

  .cost-filter-bar .cost-filter {
    border-color: #2a2e3a;
    color: #9aa4b2;
    background: #161922;
    margin-bottom: 10px;
  }

  .cost-filter-bar .cost-filter:hover {
    color: #ffffff;
    border-color: #8fa3ff;
  }

  .cost-filter-bar .cost-filter.active {
    background: #8fa3ff;
    color: #111;
    border-color: #8fa3ff;
    font-weight: 600;
  }

  /* BP 排名 ICON 微調 */
  .table .fa-steam {
    color: #9ecbff;
    opacity: 0.85;
  }

  .server-icon.dmm {
    font-weight: 700;
    font-size: 12px;
    color: #ffb3b3;
  }

  .region-globe {
    opacity: 0.7;
  }

  /* =========================================================
 * 📱 UL.GG 單卡分析頁｜Mobile Style（集中管理）
 * ========================================================= */
  @media (max-width: 768px) {

    /* ===============================
   * 版面貼齊（Edge to Edge）
   * =============================== */

    /* Tab 內容滿版 */
    .tab-content {
      padding-left: 5px !important;
      padding-right: 5px !important;
      border-radius: 5px;
    }

    /* 卡片左右貼齊 */
    .ul-card {
      padding-left: 5px !important;
      padding-right: 5px !important;
      border-radius: 5px;
    }

    /* 若卡片內需要保留內距，可用這層包 */
    .ul-card>.card-inner {
      padding: 12px 14px;
    }

    /* col 預設 padding 移除（僅分析頁） */
    .content-wrapper .container>.row>[class^="col-"],
    .content-wrapper .container>.row>[class*=" col-"] {
      padding-left: 8px;
      padding-right: 8px;
    }

    /* ===============================
   * Tabs 手機優化
   * =============================== */

    .ulgg-tabs {
      overflow-x: auto;
      white-space: nowrap;
      -webkit-overflow-scrolling: touch;
    }

    .ulgg-tabs .nav-link {
      padding: 10px 14px;
      font-size: 14px;
    }

    /* ===============================
   * 標題與數字縮放
   * =============================== */

    .page-title {
      font-size: 20px;
      line-height: 1.3;
    }

    .stat-value {
      font-size: 22px;
    }

    .stat-sub {
      font-size: 12px;
    }

    /* ===============================
   * Sticky Bar（手機）
   * =============================== */

    .sticky-inner {
      padding: 6px 10px;
      font-size: 13px;
    }

    .sticky-title {
      font-size: 13px;
    }

    /* ===============================
   * 表格優化
   * =============================== */

    .table-dark th,
    .table-dark td {
      padding: 8px 10px;
      font-size: 13px;
    }

    /* ===============================
   * CTA 卡片縮小
   * =============================== */

    .card-cta {
      width: 72px;
      padding: 6px 4px;
    }

    .card-cta-ico {
      width: 42px;
      height: 42px;
    }

    .card-cta-level {
      font-size: 12px;
    }

    .card-cta-rate {
      font-size: 11px;
    }

  }

  /* =========================
     Controls
  ========================= */

  .ca-controls {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }

  .ca-search {
    flex: 1;
    min-width: 220px;
    display: flex;
    gap: 8px;
    align-items: center;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.10);
    border-radius: 12px;
    padding: 8px 10px;
  }

  .ca-search input {
    width: 100%;
    background: transparent;
    border: none;
    outline: none;
    color: #eaf2ff;
    font-size: 14px;
  }

  .ca-search .hint {
    color: #9aa4b2;
    font-size: 12px;
    white-space: nowrap;
  }

  .ca-sort {
    display: inline-flex;
    gap: 8px;
    align-items: center;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.10);
    border-radius: 12px;
    padding: 8px 10px;
  }

  .ca-sort label {
    color: #9aa4b2;
    font-size: 12px;
    margin: 0;
    white-space: nowrap;
  }

  .ca-sort select {
    background: transparent;
    border: none;
    outline: none;
    color: #eaf2ff;
    font-size: 14px;
  }

  /* =========================
   Dark UI Select Fix
========================= */

  .ca-sort select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;

    background-color: rgba(0, 0, 0, 0.25);
    color: #eaf2ff;

    border: 1px solid rgba(180, 200, 255, 0.25);
    border-radius: 8px;

    padding: 6px 30px 6px 10px;
    font-size: 13px;
    font-weight: 700;

    cursor: pointer;
  }

  /* option 顏色（關鍵） */
  .ca-sort select option {
    background-color: #1f2433;
    color: #eaf2ff;
  }

  /* hover / focus */
  .ca-sort select:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(120, 170, 255, 0.35);
    border-color: rgba(120, 170, 255, 0.55);
  }

  /* 下拉箭頭（純 CSS） */
  .ca-sort {
    position: relative;
  }

  .ca-sort::after {
    content: '▾';
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #c9d2e3;
    font-size: 12px;
    pointer-events: none;
  }

  .ca-pill.muted {
    opacity: .45;
    border-color: rgba(180, 200, 255, .08);
  }

  .char-ico-sm {
    width: 50px;
    height: 50px;
    border-radius: 6px;
    border: 1px solid rgba(255, 255, 255, .15);
    background: #111;
    object-fit: cover;
    /* 關鍵：裁切多餘部分 */
    object-position: top;
    /* 保留臉部，上半優先 */
  }

  .char-ico-sm.border-warning {
    border: 2px solid #f0ad4e;
  }

  /* ===============================
   UL.GG Tabs – Mobile Optimize
=============================== */

  /* 基本 tab 樣式微調 */
  .ulgg-tabs>li>a {
    padding: 10px 14px;
    font-size: 14px;
    white-space: nowrap;
  }

  /* 手機版優化 */
  @media (max-width: 576px) {

    .ulgg-tabs {
      display: flex;
      flex-wrap: nowrap;
      overflow-x: auto;
      overflow-y: hidden;
      -webkit-overflow-scrolling: touch;
      border-bottom: 1px solid rgba(255, 255, 255, .08);
    }

    .ulgg-tabs::-webkit-scrollbar {
      display: none;
      /* 隱藏 scrollbar */
    }

    .ulgg-tabs>li {
      float: none;
      /* BS3 關鍵 */
    }

    .ulgg-tabs>li>a {
      font-size: 13px;
      padding: 8px 12px;
      border-radius: 10px 10px 0 0;
      margin-right: 4px;
    }

    .ulgg-tabs>li.active>a {
      background: rgba(143, 163, 255, .15);
      border-color: rgba(143, 163, 255, .4);
      color: #e6e9ef;
    }
  }

  /* =========================
   Character Card Flip (Single Card)
========================= */

  .char-flip-wrap {
    display: inline-block;
    perspective: 1000px;
    cursor: pointer;
  }

  .char-flip-img {
    max-height: 210px;
    transition: transform .45s ease;
    transform-style: preserve-3d;
    filter: drop-shadow(0 6px 12px rgba(0, 0, 0, 0.6));
  }

  /* 翻到背面（補正鏡像） */
  .char-flip-wrap.is-back .char-flip-img {
    transform: rotateY(180deg) scaleX(-1);
  }

  /* 手機微調 */
  @media (max-width: 576px) {
    .char-flip-img {
      max-height: 180px;
    }
  }

  .card-cta.active {
    border-color: #8fa3ff;
    box-shadow: 0 0 0 2px rgba(143, 163, 255, .35);
  }

  /* 讓 deck 當定位基準 */
  .deck-clickable {
    position: relative;
    display: inline-flex;
    align-items: center;
  }

  /* hover 提示文字（預設隱藏） */
  .deck-clickable::after {
    content: '🔍 查看隊伍分析';
    position: absolute;
    left: 100%;
    top: 50%;
    transform: translateY(-50%) translateX(6px);

    white-space: nowrap;
    font-size: 12px;
    color: #facc15;

    opacity: 0;
    pointer-events: none;
    transition: opacity .2s ease, transform .2s ease;
  }

  /* 只有 hover 到圖片群組才顯示 */
  .deck-clickable:hover::after {
    opacity: .9;
    transform: translateY(-50%) translateX(10px);
  }

  /* 視覺提示：deck 可點 */
  .deck-clickable {
    cursor: pointer;
  }

  tr.hof-clickable:hover {
    background: rgba(250, 204, 21, .04);
  }
</style>

<?php





$costKey = $_GET['cost'] ?? 'ALL';
$costCond = buildCostCondition($costKey);


// ===============================
// META 分析範圍設定（統一使用 days）
// ===============================
$DAYS = 14; // 預設

if (isset($_GET['days'])) {
  $d = (int)$_GET['days'];
  if (in_array($d, [14, 30, 90], true)) {
    $DAYS = $d;
  }
}

$metaDays = $DAYS;


$charStandImg = '/assets/img/char_placeholder.png';

$bestCardLabel = 'L5';
$bestScore = 87.23;
$bestCardPlays = 1280;
$bestCardWR = 61.4;

$showWinrate = 58.7;

$costPrefLabel = '輸出型事件';
$costPrefTag = '高傷害';
$costPrefWinRate = 63.2;

// ===============================
// 解析角色 ID
// ===============================
$charId = 0;
// 1️⃣ 解析 card id
$charId = (int)($_GET['char_id'] ?? 0);
if ($charId <= 0) {
  echo '<div class="alert alert-danger">卡片參數錯誤</div>';
  exit;
}

// 2️⃣ 直接抓單卡
$stmt = $pdo->prepare("
  SELECT *
  FROM unlight
  WHERE id = :id
  LIMIT 1
");
$stmt->execute(['id' => $charId]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$card) {
  echo '<div class="alert alert-danger">找不到該單卡資料</div>';
  exit;
}


// 3️⃣ 使用單卡資料
$cardLevel = $card['level'];   // L5
$charName  = $card['name'];    // 史特靈
// ===============================
// 取得角色 Base（L1）ID（回角色整體分析用）
// ===============================
$stmt = $pdo->prepare("
  SELECT id
  FROM unlight
  WHERE name = :name
    AND level = 'L1'
  LIMIT 1
");
$stmt->execute(['name' => $card['name']]);
$charBaseId = (int)($stmt->fetchColumn() ?: 0);

// 保險：若找不到 L1，就退回自己
if ($charBaseId <= 0) {
  $charBaseId = $charId;
}

// 正確的 char_base slug
$charBaseSlug = $charBaseId . '-' . urlencode($card['name']);

// ===============================
// 單卡實際勝率（依 META 天數）
// ===============================
$stmt = $pdo->prepare("
  SELECT
    COUNT(*) AS total_cnt,
    SUM(is_win = 1) AS win_cnt
  FROM arena_player_match_result
  WHERE
    (leader_id = :cid OR back1_id = :cid OR back2_id = :cid)
    AND update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
");

$stmt->bindValue(':cid',  $charId,  PDO::PARAM_INT);
$stmt->bindValue(':days', $metaDays, PDO::PARAM_INT);
$stmt->execute();

$row = $stmt->fetch(PDO::FETCH_ASSOC);

$totalCnt = (int)($row['total_cnt'] ?? 0);
$winCnt   = (int)($row['win_cnt']   ?? 0);

$cardWinRate = ($totalCnt > 0)
  ? round($winCnt / $totalCnt * 100, 1)
  : 0.0;


if (!$card) {
  echo '<div class="alert alert-danger">找不到該單卡資料</div>';
  exit;
}

$charSlug = $charId . '-' . urlencode($card['name']);

// ===============================
// Overview｜最佳隊友（取 Top 1）
// ===============================
$synergyList = getBestTeammates(
  $pdo,
  $charId,
  $metaDays,
  $cardWinRate, // ← 單卡平均勝率
  $costCond['sql'], // ✅ 少的就是這個
  5,
  10
);

$bestSynergy = $synergyList[0] ?? null;

function resolve_img($relativePath, $fallback)
{
  if (empty($relativePath)) {
    return $fallback;
  }

  $url = IMG_BASE . ltrim($relativePath, '/');
  $fs  = $_SERVER['DOCUMENT_ROOT'] . $url;

  if (!file_exists($fs)) {
    return $fallback;
  }

  return $url;
}

// 卡面（正面）
$cardIco = resolve_img(
  $card['ico'] ?? null,
  IMG_BASE . 'placeholder/card.png'
);

// 站姿
$standImg = resolve_img(
  $card['stand_ico'] ?? null,
  IMG_BASE . 'placeholder/stand.png'
);

// 卡背（之後用）
$cardBack = resolve_img(
  $card['ico_back'] ?? null,
  IMG_BASE . 'placeholder/card_back.png'
);

// ===============================
// Overview｜最佳事件卡（構築影響 TOP1）
// ===============================
$bestEvent = getCharacterTopMainEventFast(
  $pdo,
  $charId,
  $metaDays,
  $costCond['sql']
);


$metaAvgWinRate = 50.0; // 預設

$stmt = $pdo->prepare("
  SELECT ROUND(AVG(is_win) * 100, 1)
  FROM arena_player_match_result
  WHERE update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
");
$stmt->bindValue(':days', $metaDays, PDO::PARAM_INT);
$stmt->execute();

$metaAvgWinRate = (float)($stmt->fetchColumn() ?: 50.0);

$deltaVsMeta = round($cardWinRate - $metaAvgWinRate, 1);

?>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">

      <div class="text-muted small mt-1">
        分析範圍：近 <?= $metaDays ?? '全部' ?> 天（競技場 META）
      </div>
      <!-- <div class="ul-notice-dev mt-2 mb-3" role="note" aria-label="功能施工中提示">
        <span class="ul-notice-icon">🚧</span>
        <div class="ul-notice-text">
          <strong>單卡分析功能施工中</strong>
          <div class="small">
            本頁數據與分析模組將持續更新，敬請期待完整版本。
          </div>
        </div>
      </div> -->
      <!-- ===============================
           固定角色總覽（不屬於任何 TAB）
      =============================== -->
      <!-- 角色標頭 -->
      <div class="row mb-4">
        <div class="col-md-12 text-center">

          <div class="char-flip-wrap mb-2">
            <img
              src="<?= htmlspecialchars($cardIco) ?>"
              data-front="<?= htmlspecialchars($cardIco) ?>"
              data-back="<?= htmlspecialchars($cardBack) ?>"
              alt="<?= htmlspecialchars($charName) ?>"
              class="char-flip-img">
          </div>

          <h2 class="page-title">
            <?= htmlspecialchars($charName) ?>
            <span class="text-muted">｜單卡分析（<?= htmlspecialchars($cardLevel) ?>）</span>
          </h2>
          <div class="text-muted small mt-1">
            卡片 COST：
            <strong class="text-light"><?= (int)$card['cost'] ?></strong>
          </div>


          <div class="analysis-sticky-info">
            <div class="sticky-inner">
              <span class="sticky-title">
                <?= htmlspecialchars($charName) ?>
                <span class="text-muted">｜單卡分析（<?= htmlspecialchars($cardLevel) ?>）COST：
                  <strong class="text-light"><?= (int)$card['cost'] ?></strong></span>
              </span>
            </div>
          </div>


        </div>
      </div>

      <div class="row mb-4">
        <!-- Overview 數字卡片 -->
        <div class="col-md-4">
          <div class="ul-card stat-card">
            <div class="stat-title">最佳隊友（綜合表現）</div>

            <?php if ($bestSynergy):
              $seoText    = $bestSynergy['lv'] . $bestSynergy['name']; // e.g. L5史特靈
              $seoEncoded = urlencode($seoText); ?>
              <a
                href="/pages/analysis_character_card.php?char_id=<?= (int)$bestSynergy['char_id'] ?>&char=<?= $seoEncoded ?>&days=<?= (int)$metaDays ?>&cost=<?= urlencode($costKey) ?>&tab=synergy"
                class="stat-value text-decoration-none"
                style="color:inherit;"
                title="<?= htmlspecialchars($bestSynergy['lv'] . ' ' . $bestSynergy['name']) ?>">

                <?= htmlspecialchars($bestSynergy['lv']) ?>
                <?= htmlspecialchars($bestSynergy['name']) ?>↗

              </a>

              <div class="stat-sub">
                綜合表現
                <strong><?= number_format($bestSynergy['score'], 1) ?></strong>
                <span class="stat-sub-rate">
                  ｜勝率 <?= number_format($bestSynergy['win_rate'], 1) ?>%
                  ｜樣本 <?= number_format($bestSynergy['matches']) ?>
                </span>
              </div>

            <?php else: ?>
              <span class="stat-value text-muted">—</span>
              <div class="stat-sub text-muted">樣本不足</div>
            <?php endif; ?>
          </div>
        </div>



        <div class="col-md-4">
          <div class="ul-card stat-card">
            <div class="stat-title">勝率</div>

            <div class="stat-value">
              <?= number_format($cardWinRate, 1) ?>%
            </div>

            <div class="stat-sub">
              樣本 <?= number_format($totalCnt) ?> 場
              <span class="stat-sub-rate">
                ｜META
                <?php if ($deltaVsMeta >= 0): ?>
                  <span style="color:#4dd599;">+<?= number_format($deltaVsMeta, 1) ?>%</span>
                <?php else: ?>
                  <span style="color:#ff6b6b;"><?= number_format($deltaVsMeta, 1) ?>%</span>
                <?php endif; ?>
              </span>
            </div>
          </div>
        </div>



        <div class="col-md-4">
          <div class="ul-card stat-card">
            <div class="stat-title">常見主事件卡</div>
            <?php if ($bestEvent): ?>
              <div class="d-flex align-items-center justify-content-center gap-2">
                <img
                  src="<?= IMG_BASE . htmlspecialchars($bestEvent['ico']) ?>"
                  alt="<?= htmlspecialchars($bestEvent['name']) ?>"
                  style="width:36px;height:50px;object-fit:contain;">
                <span class="stat-value" style="font-size:18px;">
                  <?= htmlspecialchars($bestEvent['name']) ?>
                </span>
              </div>

              <div class="stat-sub">
                使用 <?= number_format($bestEvent['cnt']) ?> 場
                ｜勝率 <?= number_format($bestEvent['win_rate'], 1) ?>%
              </div>


            <?php else: ?>
              <div class="stat-value text-muted">近期開放</div>
              <div class="stat-sub text-muted">樣本不足</div>
            <?php endif; ?>
          </div>
        </div>


      </div>
      <div class="ca-controls">
        <div>
          <div class="d-flex align-items-center mb-3 flex-wrap cost-filter-bar">
            <span class="text-muted small me-2">COST 區間：</span>

            <?php
            $costOptions = [
              'ALL' => '全部',
              'C1'  => '50–59',
              'C2'  => '60–69',
              'C3'  => '70–80',
              'C4'  => '90+',
            ];


            foreach ($costOptions as $key => $label):
              $isActive = ($costKey === $key);
              $url = '?char_id=' . $charId
                . '&cost=' . $key
                . '&days=' . $metaDays
                . '&tab=' . $activeTab;
            ?>
              <a href="<?= $url ?>"
                class="btn btn-sm cost-filter
              <?= $isActive ? 'active btn-primary' : 'btn-outline-light' ?>">
                <?= $label ?>
              </a>
            <?php endforeach; ?>
          </div>
          <!-- <div class="text-muted small mb-2">
            ※ 分析僅統計該 COST 區間的競技場對戰資料
          </div> -->
        </div> <!-- 左邊保留空位 or 未來放搜尋 -->
        <div class="ca-sort">
          <label for="caDays">分析期間：</label>
          <select id="caDays" name="days">
            <option value="14" <?= $DAYS === 14 ? 'selected' : '' ?>>近 14 天</option>
            <option value="30" <?= $DAYS === 30 ? 'selected' : '' ?>>近 30 天</option>
            <option value="90" <?= $DAYS === 90 ? 'selected' : '' ?>>近 90 天</option>
          </select>
        </div>
      </div>

      <!-- ===============================
           TAB 導覽
      =============================== -->
      <ul class="nav nav-tabs ulgg-tabs mb-3" role="tablist">
        <li class="nav-item">
          <a
            class="nav-link <?= $activeTab === 'synergy' ? 'active' : '' ?>"
            href="#tab-synergy"
            data-toggle="tab"
            data-tab="synergy">
            🤝 最佳隊友
          </a>
        </li>

        <li class="nav-item">
          <a
            class="nav-link <?= $activeTab === 'counter' ? 'active' : '' ?>"
            href="#tab-counter"
            data-toggle="tab"
            data-tab="counter">
            💥 剋星
          </a>
        </li>

        <li class="nav-item">
          <a
            class="nav-link <?= $activeTab === 'position' ? 'active' : '' ?>"
            href="#tab-position"
            data-toggle="tab"
            data-tab="position">
            📍 定位
          </a>
        </li>
        <li class="nav-item">
          <a
            class="nav-link <?= $activeTab === 'skill' ? 'active' : '' ?>"
            href="#tab-skill"
            data-toggle="tab"
            data-tab="skill">
            🧠 技能
          </a>
        </li>
        <li class="nav-item">
          <a
            class="nav-link <?= $activeTab === 'event' ? 'active' : '' ?>"
            href="#tab-event"
            data-toggle="tab"
            data-tab="event">
            🃏 事件卡
          </a>
        </li>

        <li class="nav-item">
          <a
            class="nav-link <?= $activeTab === 'map' ? 'active' : '' ?>"
            href="#tab-map"
            data-toggle="tab"
            data-tab="map">
            🃏 地圖(待)
          </a>
        </li>

      </ul>








      <!-- ===============================
           TAB 內容
      =============================== -->
      <div class="tab-content">

        <!-- 最佳隊友 -->
        <div class="tab-pane <?= $activeTab === 'synergy' ? 'active' : '' ?>" id="tab-synergy">
          <div class="text-muted small py-3">
            ⏳ 載入最佳隊友分析中…
          </div>
        </div>


        <!-- 剋星 -->

        <div class="tab-pane <?= $activeTab === 'counter' ? 'active' : '' ?>" id="tab-counter">
          <div class="text-muted small py-3">
            ⏳ 載入剋星分析中…
          </div>
        </div>


        <!-- 定位分析 -->

        <div class="tab-pane <?= $activeTab === 'position' ? 'active' : '' ?>" id="tab-position">
          <div class="text-muted small py-3">
            ⏳ 載入定位分析中…
          </div>
        </div>

        <!-- 技能 -->
        <div class="tab-pane <?= $activeTab === 'skill' ? 'active' : '' ?>" id="tab-skill">
          <div class="text-muted small py-3">
            ⏳ 載入技能資料中…
          </div>
        </div>

        <!-- 事件卡 -->
        <div class="tab-pane <?= $activeTab === 'event' ? 'active' : '' ?>" id="tab-event">
          <div class="text-muted small py-3">
            ⏳ 載入事件卡分析中…
          </div>
        </div>

        <!-- 地圖-->
        <div class="tab-pane <?= $activeTab === 'map' ? 'active' : '' ?>" id="tab-map">
          <div class="text-muted small py-3">
            ⏳ 載入地圖分析中…
          </div>
        </div>

      </div>

      <a href="/pages/character.php?char_base=<?= htmlspecialchars($charBaseSlug) ?>&days=<?= (int)$metaDays ?>&cost=<?= urlencode($costKey) ?>&tab=synergy"
        class="small text-muted">
        ← 回角色整體分析
      </a>


      <!-- /.tab-content -->

    </div>
  </section>
</div>


<script>
  (function() {

    // ===============================
    // 工具
    // ===============================
    function getQueryParam(name) {
      return new URLSearchParams(window.location.search).get(name);
    }

    function getActiveTab() {
      return getQueryParam('tab') || 'synergy';
    }




    // ===============================
    // Tab URL Sync（Bootstrap 3）
    // ===============================
    function initTabRouting() {

      // 點 tab → 顯示
      $('a[data-toggle="tab"]').on('click', function(e) {
        e.preventDefault();
        $(this).tab('show');
      });

      // tab 顯示後 → 寫回 URL
      $('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
        const tab = $(e.target).data('tab');
        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        history.replaceState(null, '', url);
      });
    }

    // ===============================
    // Filters（cost / days）
    // ===============================
    function initFilters() {

      // COST（保留 tab）
      document.querySelectorAll('.cost-filter').forEach(btn => {
        btn.addEventListener('click', function(e) {
          e.preventDefault();
          const url = new URL(this.href, window.location.origin);
          url.searchParams.set('tab', getActiveTab());
          window.location.href = url.toString();
        });
      });

      // DAYS（保留 tab）
      document.getElementById('caDays')?.addEventListener('change', function() {
        const url = new URL(window.location.href);
        url.searchParams.set('days', this.value);
        url.searchParams.set('tab', getActiveTab());
        window.location.href = url.toString();
      });
    }

    // ===============================
    // AJAX Tabs（synergy / counter / position）
    // ===============================
    function initAjaxTabs() {

      let loaded = {
        synergy: false,
        counter: false,
        position: false,
        event: false,
        skill: false,
      };


      function loadTab(tab, force = false) {
        if (loaded[tab] && !force) return;

        let urlMap = {
          synergy: '/pages/admin/ajax/tab_synergy.php',
          counter: '/pages/admin/ajax/tab_counter.php',
          position: '/pages/admin/ajax/tab_position.php',
          event: '/pages/admin/ajax/tab_event.php',
          skill: '/pages/admin/ajax/tab_skill.php',
        };


        if (!urlMap[tab]) return;

        $('#tab-' + tab).load(
          urlMap[tab] +
          '?char_id=<?= $charId ?>' +
          '&days=' + (getQueryParam('days') || 90) +
          '&cost=' + (getQueryParam('cost') || 'ALL')
        );

        loaded[tab] = true;
      }

      // tab 切換時（一律 force reload，避免 filter 不同步）
      ['synergy', 'counter', 'position', 'event', 'skill'].forEach(tab => {
        $('a[data-tab="' + tab + '"]').on('shown.bs.tab', () => {
          loadTab(tab, true);
        });
      });


      // ===============================
      // 初始載入（依 PHP activeTab）
      // ===============================
      $(function() {
        switch ('<?= $activeTab ?>') {
          case 'counter':
            loadTab('counter', true);
            break;
          case 'position':
            loadTab('position', true);
            break;
          case 'event':
            loadTab('event', true);
            break;
          case 'skill':
            loadTab('skill', true);
            break;
          case 'synergy':
          default:
            loadTab('synergy', true);
            break;
        }

      });
    }


    // ===============================
    // Init
    // ===============================
    document.addEventListener('DOMContentLoaded', function() {

      initTabRouting();
      initFilters();
      initAjaxTabs();
    });

  })();
</script>
<script>
  (function() {
    const el = document.querySelector('.analysis-sticky-info');
    if (!el) return;

    window.addEventListener('scroll', () => {
      const y = window.scrollY;
      el.classList.toggle('show', y > 120);
      el.classList.toggle('is-stuck', y > 60);
    });
  })();
</script>

<!-- 點擊切換正 / 背面 -->
<script>
  document.querySelectorAll('.char-flip-wrap').forEach(wrap => {
    const img = wrap.querySelector('.char-flip-img');
    if (!img) return;

    const front = img.dataset.front;
    const back = img.dataset.back;
    if (!back) return; // 沒背面就不啟用

    wrap.addEventListener('click', () => {
      const isBack = wrap.classList.toggle('is-back');
      img.src = isBack ? back : front;

      // 可選：翻轉成功震動（和你 table 一致）
      if (navigator.vibrate) navigator.vibrate(10);
    });
  });
</script>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>