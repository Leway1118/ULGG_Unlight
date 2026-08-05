<?php
session_start();
require_once 'config.php';

date_default_timezone_set("Asia/Taipei");

$pageTitle  = "牌組排行榜 Deck Ranking";
$activeMenu = "ranking";

// -----------------------------
// 1) 期間選擇：3d / 7d / 30d
// -----------------------------
$period = $_GET['period'] ?? '7d';
$today = date('Y-m-d');
$yesterday = date("Y-m-d", strtotime('-1 day'));

switch ($period) {

  case 'today':
    $startDate = $today;
    $endDate   = $today;
    break;

  case 'yesterday':
    $startDate = $yesterday;
    $endDate   = $yesterday;
    break;

  case '3d':
    $startDate = date('Y-m-d', strtotime('-2 days'));
    $endDate   = $today;
    break;

  case '30d':
    $startDate = date('Y-m-d', strtotime('-29 days'));
    $endDate   = $today;
    break;

  case '7d':
  default:
    $period = '7d';
    $startDate = date('Y-m-d', strtotime('-6 days'));
    $endDate   = $today;
    break;
}


$endDate = $today;
ob_start();


?>

<style>
  .team-page-wrapper {
    max-width: 1200px;
    margin: 0 auto;
    padding: 12px 12px 40px;
  }

  /* ===== Tabs ===== */
  .un-period-tabs {
    display: inline-flex;
    gap: 4px;
    margin-bottom: 12px;
    border-radius: 999px;
    padding: 3px;
    background: rgba(15, 20, 35, 0.9);
    box-shadow: 0 0 12px rgba(0, 0, 0, 0.6);
  }

  .un-period-tabs a {
    padding: 4px 10px;
    font-size: 12px;
    border-radius: 999px;
    color: #b0bec5;
    border: 1px solid transparent;
    text-decoration: none;
  }

  .un-period-tabs a.active {
    background: radial-gradient(circle at top, #3949ab 0%, #1a237e 70%);
    color: #fff;
    border-color: rgba(144, 164, 255, 0.8);
    box-shadow: 0 0 10px rgba(120, 120, 255, 0.4);
  }

  /* ===== Ajax Fade ===== */
  #team-content {
    transition: opacity .15s ease-in-out;
  }

  /* ===== Pagination ===== */
  .un-page-btn {
    padding: 4px 10px;
    margin: 0 6px;
    font-size: 13px;
    border-radius: 6px;
    cursor: pointer;
    background: rgba(20, 25, 40, 0.5);
    border: 1px solid rgba(120, 120, 255, 0.4);
    color: #cfd8dc;
  }

  .un-page-btn:hover {
    background: rgba(120, 120, 255, 0.25);
    color: #fff;
  }

  .un-page-current {
    padding: 4px 12px;
    border-radius: 6px;
    background: rgba(90, 120, 255, 0.6);
    color: #fff;
    font-weight: 600;
  }

  /* ======================================
   強制 Deck Ranking 維持兩欄布局（桌機）
   ====================================== */
  .un-main .un-team-grid {
    display: grid !important;
    grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    gap: 12px !important;
  }

  /* 手機一欄 */
  @media (max-width: 768px) {
    .un-main .un-team-grid {
      grid-template-columns: 1fr !important;
    }
  }

  /* ======================================
   修正 .un-card / .card 的 width:100% 破壞 grid 問題
   ====================================== */
  .un-team-card {
    width: auto !important;
    /* 復原成由 grid 控制寬度 */
    display: block;
  }
</style>

<div class="team-page-wrapper">

  <!-- 標題區 -->
  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:6px;">
    <div>
      <div class="un-section-title">3 v 3 Deck META</div>
      <div class="un-section-sub">
        期間：
        <?php
        if ($period === 'today') {
          echo "今日 ($today)";
        } elseif ($period === 'yesterday') {
          echo "昨日 ($yesterday)";
        } elseif ($period === '3d') {
          echo "最近 3 日 ($startDate ~ $endDate)";
        } elseif ($period === '30d') {
          echo "最近 30 日 ($startDate ~ $endDate)";
        } else {
          echo "最近 7 日 ($startDate ~ $endDate)";
        }
        ?>
      </div>
    </div>

    <div class="un-period-tabs">
      <a href="?period=today" class="<?= $period === 'today' ? 'active' : '' ?>">今日</a>
      <a href="?period=yesterday" class="<?= $period === 'yesterday' ? 'active' : '' ?>">昨日</a>
      <a href="?period=3d" class="<?= $period === '3d' ? 'active' : '' ?>">最近 3 日</a>
      <a href="?period=7d" class="<?= $period === '7d' ? 'active' : '' ?>">最近 7 日</a>
      <a href="?period=30d" class="<?= $period === '30d' ? 'active' : '' ?>">最近 30 日</a>
    </div>

  </div>

  <!-- AJAX 的資料載入區 -->
  <div id="team-content">
    <div style="color:#78909c;font-size:14px;">Loading...</div>
  </div>

  <!-- 分頁 -->
  <div id="pagination" style="margin-top:18px; text-align:center;"></div>

</div>

<script>
  document.addEventListener("DOMContentLoaded", function() {
    loadPage(1);
  });

  function loadPage(page) {
    const period = "<?= $period ?>";

    // 讀取卡片資料
    fetch(`ranking_ajax.php?page=${page}&period=${period}`)
      .then(res => res.text())
      .then(html => {
        const box = document.getElementById("team-content");
        box.style.opacity = 0;
        setTimeout(() => {
          box.innerHTML = html;
          box.style.opacity = 1;
        }, 150);
      });

    // 讀取分頁按鈕
    fetch(`ranking_ajax.php?pagination=1&period=${period}&page=${page}`)
      .then(res => res.text())
      .then(html => {
        document.getElementById("pagination").innerHTML = html;
      });
  }
</script>

<?php
$pageContent = ob_get_clean();
include 'template.php';
