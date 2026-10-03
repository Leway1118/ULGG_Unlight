<?php
require_once __DIR__ . '/../config.php';

$pageTitleText = '流量統計';
$seoTitle = '流量統計 | UL.GG 戰績網｜UNLIGHT 戰術研究中心';
$pageTitleFull = '流量統計 | UL.GG 戰績網';
$activeMenu = 'statics';

date_default_timezone_set("Asia/Taipei");

// ------------------------------------------------------
// 1. 基本日期範圍
// ------------------------------------------------------
$today       = date("Y-m-d");
$sevenDaysAgo = date("Y-m-d", strtotime("-6 days"));

// ------------------------------------------------------
// 2. 今日總覽（真人 / BOT / Unknown / PV / IP / Session）
// ------------------------------------------------------
$sqlToday = "
    SELECT 
        COUNT(*) AS total,
        SUM(CASE 
                WHEN (is_bot = 0 OR is_bot IS NULL)
                 AND user_agent REGEXP 'Windows|Macintosh|iPhone|Android|Linux'
             THEN 1 ELSE 0 END) AS human,
        SUM(CASE 
                WHEN is_bot = 1 
                  OR user_agent REGEXP 'bot|crawl|spider|Headless|python-requests|curl'
             THEN 1 ELSE 0 END) AS bot,
        SUM(CASE 
                WHEN (is_bot = 0 OR is_bot IS NULL)
                 AND user_agent NOT REGEXP 'Windows|Macintosh|iPhone|Android|Linux|bot|crawl|spider|Headless|python-requests|curl'
             THEN 1 ELSE 0 END) AS unknown
    FROM visitors
    WHERE DATE(visited_at) = :today
";

$stmt = $db->prepare($sqlToday);
$stmt->execute([':today' => $today]);
$rowToday = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
  'total'   => 0,
  'human'   => 0,
  'bot'     => 0,
  'unknown' => 0
];

$todayTotal   = (int)$rowToday['total'];
$todayHuman   = (int)$rowToday['human'];
$todayBot     = (int)$rowToday['bot'];
$todayUnknown = (int)$rowToday['unknown'];

// 獨立 IP
$sqlIP = "
    SELECT COUNT(DISTINCT ip)
    FROM visitors
    WHERE DATE(visited_at) = :today
";
$stmtIP = $db->prepare($sqlIP);
$stmtIP->execute([':today' => $today]);
$todayIP = (int)$stmtIP->fetchColumn();

// Session 數
$sqlSess = "
    SELECT COUNT(DISTINCT session_id)
    FROM visitors
    WHERE DATE(visited_at) = :today
";
$stmtSess = $db->prepare($sqlSess);
$stmtSess->execute([':today' => $today]);
$todaySession = (int)$stmtSess->fetchColumn();

// ------------------------------------------------------
// 3. 今日每小時 真人 / BOT 分布
// ------------------------------------------------------
$sqlHour = "
    SELECT 
        HOUR(visited_at) AS h,
        SUM(CASE 
                WHEN (is_bot = 0 OR is_bot IS NULL)
                 AND user_agent REGEXP 'Windows|Macintosh|iPhone|Android|Linux'
             THEN 1 ELSE 0 END) AS human,
        SUM(CASE 
                WHEN is_bot = 1 
                  OR user_agent REGEXP 'bot|crawl|spider|Headless|python-requests|curl'
             THEN 1 ELSE 0 END) AS bot
    FROM visitors
    WHERE DATE(visited_at) = :today
    GROUP BY HOUR(visited_at)
    ORDER BY h
";

$stmtHour = $db->prepare($sqlHour);
$stmtHour->execute([':today' => $today]);

$hourStats = [];
while ($r = $stmtHour->fetch(PDO::FETCH_ASSOC)) {
  $hourStats[] = [
    'hour'  => sprintf('%02d:00', $r['h']),
    'human' => (int)$r['human'],
    'bot'   => (int)$r['bot'],
  ];
}

// ------------------------------------------------------
// 4. 最近 7 天真人 / BOT / Unknown 趨勢
// ------------------------------------------------------
$sql7 = "
    SELECT 
        DATE(visited_at) AS d,
        SUM(CASE 
                WHEN (is_bot = 0 OR is_bot IS NULL)
                 AND user_agent REGEXP 'Windows|Macintosh|iPhone|Android|Linux'
             THEN 1 ELSE 0 END) AS human,
        SUM(CASE 
                WHEN is_bot = 1 
                  OR user_agent REGEXP 'bot|crawl|spider|Headless|python-requests|curl'
             THEN 1 ELSE 0 END) AS bot,
        SUM(CASE 
                WHEN (is_bot = 0 OR is_bot IS NULL)
                 AND user_agent NOT REGEXP 'Windows|Macintosh|iPhone|Android|Linux|bot|crawl|spider|Headless|python-requests|curl'
             THEN 1 ELSE 0 END) AS unknown
    FROM visitors
    WHERE visited_at >= :fromDate
      AND visited_at <  DATE_ADD(:toDate, INTERVAL 1 DAY)
    GROUP BY DATE(visited_at)
    ORDER BY d ASC
";

$stmt7 = $db->prepare($sql7);
$stmt7->execute([
  ':fromDate' => $sevenDaysAgo,
  ':toDate'   => $today
]);

$stats7d = [];
while ($row = $stmt7->fetch(PDO::FETCH_ASSOC)) {
  $stats7d[] = [
    'date'    => date("m-d", strtotime($row['d'])),
    'human'   => (int)$row['human'],
    'bot'     => (int)$row['bot'],
    'unknown' => (int)$row['unknown'],
  ];
}

// ---------------------------------------------
// 國家分佈統計（只算真人 is_bot = 0）
// ---------------------------------------------
$sqlCountry = "
    SELECT country, COUNT(*) AS total
    FROM visitors
    WHERE is_bot = 0
      AND country IS NOT NULL
      AND country <> ''
      AND visited_at >= CURDATE() - INTERVAL 30 DAY  -- 最近30天(可調整)
    GROUP BY country
    ORDER BY total DESC
    LIMIT 12;   -- 只顯示前12名 (甜甜圈較清楚)
";

$countryData = $db->query($sqlCountry)->fetchAll(PDO::FETCH_ASSOC);

// 轉換成 Chart.js 需要的格式
$countryLabels = [];
$countryCounts = [];

foreach ($countryData as $row) {
  $countryLabels[] = $row['country'];
  $countryCounts[] = (int)$row['total'];
}


// ------------------------------------------------------
// 5. 今日熱門頁面 Top 10
// ------------------------------------------------------
$sqlPages = "
    SELECT page,
           COUNT(*) AS cnt,
           SUM(CASE WHEN is_bot = 0 OR is_bot IS NULL THEN 1 ELSE 0 END) AS human_cnt
    FROM visitors
    WHERE DATE(visited_at) = :today
    GROUP BY page
    ORDER BY cnt DESC
    LIMIT 10
";
$stmtPages = $db->prepare($sqlPages);
$stmtPages->execute([':today' => $today]);
$topPages = $stmtPages->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ------------------------------------------------------
// 6. 今日 referrer Top 10
// ------------------------------------------------------
$sqlRef = "
    SELECT referrer,
           COUNT(*) AS cnt
    FROM visitors
    WHERE DATE(visited_at) = :today
      AND referrer IS NOT NULL
      AND referrer <> ''
    GROUP BY referrer
    ORDER BY cnt DESC
    LIMIT 10
";
$stmtRef = $db->prepare($sqlRef);
$stmtRef->execute([':today' => $today]);
$topReferrers = $stmtRef->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ------------------------------------------------------
// 7. 今日熱門搜尋字 / 角色
// ------------------------------------------------------
$sqlSearch = "
    SELECT search_term, COUNT(*) AS cnt
    FROM visitors
    WHERE DATE(visited_at) = :today
      AND search_term IS NOT NULL
      AND search_term <> ''
    GROUP BY search_term
    ORDER BY cnt DESC
    LIMIT 5
";
$stmtSearch = $db->prepare($sqlSearch);
$stmtSearch->execute([':today' => $today]);
$topSearch = $stmtSearch->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sqlChar = "
    SELECT character_name, COUNT(*) AS cnt
    FROM visitors
    WHERE DATE(visited_at) = :today
      AND character_name IS NOT NULL
      AND character_name <> ''
    GROUP BY character_name
    ORDER BY cnt DESC
    LIMIT 5
";
$stmtChar = $db->prepare($sqlChar);
$stmtChar->execute([':today' => $today]);
$topChar = $stmtChar->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ------------------------------------------------------
// 8. 今日 BOT 類型統計
// ------------------------------------------------------
$sqlBotType = "
    SELECT bot_type, COUNT(*) AS cnt
    FROM visitors
    WHERE DATE(visited_at) = :today
      AND is_bot = 1
    GROUP BY bot_type
    ORDER BY cnt DESC
";
$stmtBotType = $db->prepare($sqlBotType);
$stmtBotType->execute([':today' => $today]);
$botTypes = $stmtBotType->fetchAll(PDO::FETCH_ASSOC) ?: [];

// ------------------------------------------------------
// 9. 計算 PV / Session / 真人 Session 資料
// ------------------------------------------------------

// 真人 Sessions（distinct）
$sqlHumanSess = "
    SELECT COUNT(DISTINCT session_id) AS cnt
    FROM visitors
    WHERE DATE(visited_at) = CURDATE()
      AND is_bot = 0
";
$todayHumanSessions = (int)$db->query($sqlHumanSess)->fetchColumn();

// 真人比例
$humanRate = $todayTotal > 0 ? round($todayHuman / $todayTotal * 100, 1) : 0;
$botRate   = $todayTotal > 0 ? round($todayBot   / $todayTotal * 100, 1) : 0;

// 人均 PV = PV / Sessions
$avgPV = $todaySession > 0 ? round($todayTotal / $todaySession, 2) : 0;

// 人均真人 PV = 真人 PV / 真人 Sessions
$avgHumanPV = $todayHumanSessions > 0 ? round($todayHuman / $todayHumanSessions, 2) : 0;

// =============================
// Watcher Status Panel
// =============================

// 讀取 WATCHER_VERSION from Python file
$watcherFile = "/var/www/unlight_project/steam/data/channel_watcher_unlight.py";
$watcherVersion = "Unknown";
if (file_exists($watcherFile)) {
  $content = file_get_contents($watcherFile);
  if (preg_match('/WATCHER_VERSION\s*=\s*"([^"]+)"/', $content, $m)) {
    $watcherVersion = $m[1];
  }
}

// systemd 狀態
$watcherActive = trim(shell_exec("systemctl is-active unlight_watcher"));
$watcherSince  = trim(shell_exec("systemctl show -p ActiveEnterTimestamp unlight_watcher | cut -d= -f2"));

// 讀取維修模式 JSON
$maintFile = "/var/www/unlight_project/steam/data/maintenance_status.json";
$maintData = file_exists($maintFile)
  ? json_decode(file_get_contents($maintFile), true)
  : [];

ob_start();
?>
<style>
    body {
      background: #0b0c10 url('/dist/img/bg_pattern.png');
      color: #e0e0e0;
    }

    .content-wrapper {
      background: transparent;
    }

    .content-header h1 {
      color: #f5f5f5;
      text-shadow: 0 0 8px rgba(120, 120, 255, 0.6);
    }

    .un-card {
      background: radial-gradient(circle at top, #2a2a3a 0%, #14141c 55%, #08080c 100%);
      border-radius: 12px;
      border: 1px solid rgba(120, 120, 255, 0.5);
      box-shadow: 0 0 12px rgba(0, 0, 0, 0.8), 0 0 18px rgba(120, 120, 255, 0.3);
      padding: 18px 16px;
      margin-bottom: 20px;
      position: relative;
      overflow: hidden;
    }

    .un-card::before {
      content: "";
      position: absolute;
      inset: 0;
      border-radius: inherit;
      border: 1px solid rgba(255, 255, 255, 0.05);
      pointer-events: none;
    }

    .stat-title {
      font-size: 14px;
      letter-spacing: 0.1em;
      text-transform: uppercase;
      color: #9fa8da;
      margin-bottom: 6px;
    }

    .stat-number {
      font-size: 32px;
      font-weight: bold;
      color: #ffffff;
      text-shadow: 0 0 6px rgba(255, 255, 255, 0.3);
    }

    .stat-sub {
      font-size: 12px;
      color: #b0bec5;
    }

    .stat-tag {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      margin-right: 4px;
    }

    .tag-human {
      background: rgba(76, 175, 80, 0.16);
      color: #a5d6a7;
      border: 1px solid rgba(76, 175, 80, 0.5);
    }

    .tag-bot {
      background: rgba(244, 67, 54, 0.16);
      color: #ef9a9a;
      border: 1px solid rgba(244, 67, 54, 0.5);
    }

    .tag-unknown {
      background: rgba(255, 152, 0, 0.18);
      color: #ffcc80;
      border: 1px solid rgba(255, 152, 0, 0.5);
    }

    .un-icon {
      font-size: 30px;
      margin-right: 6px;
    }

    .donut-wrapper {
      max-width: 260px;
      margin: 0 auto;
      height: 220px;
      position: relative;
    }

    .donut-wrapper canvas {
      position: absolute;
      inset: 0;
    }

    .un-table {
      width: 100%;
      font-size: 13px;
    }

    .un-table th,
    .un-table td {
      border-bottom: 1px solid rgba(255, 255, 255, 0.06);
      padding: 4px 6px;
    }

    .un-table th {
      color: #b0bec5;
      font-weight: 600;
    }

    .page-chip {
      display: inline-block;
      padding: 2px 8px;
      background: rgba(158, 158, 158, 0.1);
      border-radius: 999px;
      font-family: monospace;
      font-size: 11px;
    }

    .ref-chip {
      display: inline-block;
      max-width: 260px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      padding: 2px 8px;
      border-radius: 6px;
      background: rgba(33, 150, 243, 0.08);
      color: #90caf9;
    }

    .keyword-chip {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      background: rgba(156, 204, 101, 0.2);
      color: #c5e1a5;
      font-size: 12px;
    }

    .char-chip {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      background: rgba(244, 143, 177, 0.2);
      color: #f8bbd0;
      font-size: 12px;
    }

    .bot-chip {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 999px;
      background: rgba(239, 83, 80, 0.2);
      color: #ef9a9a;
      font-size: 12px;
    }

    /* 讓國家分佈的 box 跟 dark theme 一樣深色 */
    .box.box-primary {
      background: rgba(20, 20, 28, 0.75) !important;
      border-color: rgba(120, 120, 255, 0.4) !important;
      color: #e0e0e0 !important;
    }

    .box.box-primary .box-header {
      background: transparent !important;
      border-bottom: 1px solid rgba(120, 120, 255, 0.3) !important;
    }

    .box.box-primary .box-title {
      color: #9fa8da !important;
    }

    /* Canvas 透明背景 */
    #countryDonut {
      background: transparent !important;
    }
  </style>

<div class="content-wrapper">
    <section class="content-header">
      <h1>
        流量統計 Dashboard
        <small>UL.GG Analytics v2</small>
      </h1>
    </section>

    <section class="content">
      <div class="row">
        <div class="col-md-12">
          <div class="un-card" style="border-left:4px solid #90caf9;">
            <div class="stat-title">🛠 Watcher 狀態監控</div>

            <div style="font-size:15px; line-height:1.7;">
              <b>版本：</b> <?= htmlspecialchars($watcherVersion) ?><br>

              <b>systemd 狀態：</b>
              <?php if ($watcherActive === "active"): ?>
                <span style="color:#4caf50; font-weight:bold;">🟢 運行中</span>
              <?php else: ?>
                <span style="color:#f44336; font-weight:bold;">🔴 停止</span>
              <?php endif; ?>

              <br>
              <b>啟動時間：</b> <?= htmlspecialchars($watcherSince ?: "Unknown") ?><br>

              <hr style="border-color:rgba(255,255,255,0.1);">

              <b>各通道維修狀態：</b><br>

              <?php if (empty($maintData)): ?>
                <div class="stat-sub">尚無維修資訊（全部視為正常）。</div>
              <?php else: ?>
                <table class="un-table" style="margin-top:6px;">
                  <thead>
                    <tr>
                      <th>通道</th>
                      <th>狀態</th>
                      <th>開始時間</th>
                      <th>最後錯誤</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($maintData as $name => $info): ?>
                      <tr>
                        <td><?= htmlspecialchars($name) ?></td>
                        <td>
                          <?php if ($info["status"] === "normal"): ?>
                            <span style="color:#4caf50;">🟢 正常</span>
                          <?php else: ?>
                            <span style="color:#ffb74d;">🟡 維修模式</span>
                          <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($info["since"] ?? "-") ?></td>
                        <td><?= htmlspecialchars($info["last_error"] ?? "-") ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <div class="row">

        <div class="col-md-2 col-sm-6">
          <div class="un-card">
            <div class="stat-title"><i class="fa fa-eye un-icon"></i> 今日總瀏覽 (PV)</div>
            <div class="stat-number"><?= $todayTotal ?></div>
            <div class="stat-sub">
              <span class="stat-tag tag-human">👤 <?= $todayHuman ?></span>
              <span class="stat-tag tag-bot">🤖 <?= $todayBot ?></span>
              <span class="stat-tag tag-unknown">❓ <?= $todayUnknown ?></span>
            </div>
          </div>
        </div>

        <div class="col-md-2 col-sm-6">
          <div class="un-card">
            <div class="stat-title"><i class="fa fa-user un-icon"></i> 獨立 IP</div>
            <div class="stat-number"><?= $todayIP ?></div>
            <div class="stat-sub">今日不同 IP</div>
          </div>
        </div>

        <div class="col-md-2 col-sm-6">
          <div class="un-card">
            <div class="stat-title"><i class="fa fa-users un-icon"></i> Sessions</div>
            <div class="stat-number"><?= $todaySession ?></div>
            <div class="stat-sub">依 session_id</div>
          </div>
        </div>

        <div class="col-md-2 col-sm-6">
          <div class="un-card">
            <div class="stat-title"><i class="fa fa-bolt un-icon"></i> 真人比例</div>
            <div class="stat-number"><?= $humanRate ?>%</div>
            <div class="stat-sub">BOT：<?= $botRate ?>%</div>
          </div>
        </div>

        <!-- 新增：人均 PV -->
        <div class="col-md-2 col-sm-6">
          <div class="un-card">
            <div class="stat-title"><i class="fa fa-line-chart un-icon"></i> 人均 PV</div>
            <div class="stat-number"><?= $avgPV ?></div>
            <div class="stat-sub">PV / Sessions</div>
          </div>
        </div>

        <!-- 新增：人均真人 PV -->
        <div class="col-md-2 col-sm-6">
          <div class="un-card">
            <div class="stat-title"><i class="fa fa-user-circle un-icon"></i> 人均真人 PV</div>
            <div class="stat-number"><?= $avgHumanPV ?></div>
            <div class="stat-sub">真人 PV / 真人 Sessions</div>
          </div>
        </div>

      </div>


      <!-- 第二排：左 7 天線圖 / 右 Donut + 每小時 -->
      <div class="row">
        <div class="col-md-7">
          <div class="un-card">
            <div class="stat-title">📈 最近 7 天流量趨勢</div>
            <canvas id="chart7days" height="160"></canvas>
          </div>

          <div class="un-card">
            <div class="stat-title">⏱ 今日真人 vs BOT（各時段）</div>
            <canvas id="hourChart" height="150"></canvas>
          </div>


        </div>
        <div class="col-md-5">
          <div class="un-card">
            <div class="stat-title">🧭 今日流量分佈</div>
            <div class="donut-wrapper">
              <canvas id="pieToday"></canvas>
            </div>
          </div>
          <!-- 🌍 國家分佈 Donut Chart -->
          <div class="box box-primary">
            <div class="box-header with-border">
              <h3 class="box-title">🌍 真人訪客國家分佈（最近 30 天）</h3>
            </div>
            <div class="box-body">
              <div style="max-width: 380px; margin: auto;">
                <canvas id="countryDonut"></canvas>
              </div>
            </div>
          </div>


        </div>
      </div>

      <!-- 第三排：熱門頁面 / referrer -->
      <div class="row">
        <div class="col-md-6">
          <div class="un-card">
            <div class="stat-title">🔥 今日熱門頁面 Top 10</div>
            <table class="un-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>頁面</th>
                  <th>PV</th>
                  <th>真人</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($topPages)): ?>
                  <tr>
                    <td colspan="4">今日尚無資料。</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($topPages as $idx => $p): ?>
                    <tr>
                      <td><?= $idx + 1 ?></td>
                      <td><span class="page-chip"><?= htmlspecialchars($p['page']) ?></span></td>
                      <td><?= (int)$p['cnt'] ?></td>
                      <td><?= (int)$p['human_cnt'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="col-md-6">
          <div class="un-card">
            <div class="stat-title">🌐 今日來源網站 Top 10</div>
            <table class="un-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Referrer</th>
                  <th>次數</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($topReferrers)): ?>
                  <tr>
                    <td colspan="3">今日沒有 referrer（多為直接輸入網址或書籤）。</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($topReferrers as $idx => $r): ?>
                    <tr>
                      <td><?= $idx + 1 ?></td>
                      <td>
                        <span class="ref-chip"
                          title="<?= htmlspecialchars($r['referrer']) ?>">
                          <?= htmlspecialchars($r['referrer']) ?>
                        </span>
                      </td>
                      <td><?= (int)$r['cnt'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- 第四排：搜尋 & 角色 & BOT 類型 -->
      <div class="row">
        <div class="col-md-4">
          <div class="un-card">
            <div class="stat-title">🔍 今日熱門搜尋字 Top 5</div>
            <?php if (empty($topSearch)): ?>
              <div class="stat-sub">今日無 player_search 搜尋紀錄。</div>
            <?php else: ?>
              <table class="un-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>搜尋關鍵字</th>
                    <th>次數</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($topSearch as $idx => $s): ?>
                    <tr>
                      <td><?= $idx + 1 ?></td>
                      <td><span class="keyword-chip"><?= htmlspecialchars($s['search_term']) ?></span></td>
                      <td><?= (int)$s['cnt'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-md-4">
          <div class="un-card">
            <div class="stat-title">🎴 今日熱門角色頁 Top 5</div>
            <?php if (empty($topChar)): ?>
              <div class="stat-sub">今日尚未有人透過 character 參數瀏覽角色資料。</div>
            <?php else: ?>
              <table class="un-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>角色名稱</th>
                    <th>次數</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($topChar as $idx => $c): ?>
                    <tr>
                      <td><?= $idx + 1 ?></td>
                      <td><span class="char-chip"><?= htmlspecialchars($c['character_name']) ?></span></td>
                      <td><?= (int)$c['cnt'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-md-4">
          <div class="un-card">
            <div class="stat-title">🤖 今日 BOT 類型</div>
            <?php if (empty($botTypes)): ?>
              <div class="stat-sub">今日尚未偵測到 BOT 或已全部視為真人。</div>
            <?php else: ?>
              <table class="un-table">
                <thead>
                  <tr>
                    <th>BOT 類型</th>
                    <th>次數</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($botTypes as $b): ?>
                    <tr>
                      <td><span class="bot-chip"><?= htmlspecialchars($b['bot_type'] ?: 'Unknown') ?></span></td>
                      <td><?= (int)$b['cnt'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </section>
  </div>

  <!-- JS & Charts -->
  <script>
    // 7天資料
    const stats7d = <?= json_encode($stats7d, JSON_UNESCAPED_UNICODE) ?> || [];
    const labels7 = stats7d.map(x => x.date);
    const human7 = stats7d.map(x => x.human);
    const bot7 = stats7d.map(x => x.bot);
    const unk7 = stats7d.map(x => x.unknown);

    new Chart(document.getElementById('chart7days'), {
      type: 'line',
      data: {
        labels: labels7,
        datasets: [{
            label: '真人',
            data: human7,
            borderColor: '#4caf50',
            backgroundColor: 'rgba(76,175,80,0.2)',
            fill: true,
            tension: 0.25
          },
          {
            label: 'BOT',
            data: bot7,
            borderColor: '#f44336',
            backgroundColor: 'rgba(244,67,54,0.15)',
            fill: true,
            tension: 0.25
          },
          {
            label: 'Unknown',
            data: unk7,
            borderColor: '#ffb74d',
            backgroundColor: 'rgba(255,183,77,0.15)',
            fill: true,
            borderDash: [5, 5],
            tension: 0.25
          }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: '#e0e0e0'
            }
          }
        },
        scales: {
          x: {
            ticks: {
              color: '#b0bec5'
            },
            grid: {
              color: 'rgba(255,255,255,0.05)'
            }
          },
          y: {
            ticks: {
              color: '#b0bec5'
            },
            grid: {
              color: 'rgba(255,255,255,0.07)'
            }
          }
        }
      }
    });

    // 今日 donut
    new Chart(document.getElementById('pieToday'), {
      type: 'doughnut',
      data: {
        labels: ['真人', 'BOT', 'Unknown'],
        datasets: [{
          data: [<?= $todayHuman ?>, <?= $todayBot ?>, <?= $todayUnknown ?>],
          backgroundColor: ['#4caf50', '#f44336', '#ffb74d'],
          borderWidth: 1,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: '#e0e0e0'
            }
          }
        },
        cutout: '60%'
      }
    });

    // 每小時真人 vs BOT
    const hourStats = <?= json_encode($hourStats, JSON_UNESCAPED_UNICODE) ?> || [];
    const hourLabels = hourStats.map(x => x.hour);
    const hourHuman = hourStats.map(x => x.human);
    const hourBot = hourStats.map(x => x.bot);

    new Chart(document.getElementById('hourChart'), {
      type: 'bar',
      data: {
        labels: hourLabels,
        datasets: [{
            label: '真人',
            data: hourHuman,
            backgroundColor: 'rgba(76,175,80,0.7)'
          },
          {
            label: 'BOT',
            data: hourBot,
            backgroundColor: 'rgba(244,67,54,0.7)'
          }
        ]
      },
      options: {
        responsive: true,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: '#e0e0e0'
            }
          }
        },
        scales: {
          x: {
            stacked: true,
            ticks: {
              color: '#b0bec5'
            },
            grid: {
              color: 'rgba(255,255,255,0.05)'
            }
          },
          y: {
            stacked: true,
            ticks: {
              color: '#b0bec5'
            },
            grid: {
              color: 'rgba(255,255,255,0.07)'
            }
          }
        }
      }
    });
  </script>
  <script>
    const countryLabels = <?= json_encode($countryLabels) ?>;
    const countryCounts = <?= json_encode($countryCounts) ?>;

    new Chart(document.getElementById('countryDonut'), {
      type: 'bar',
      data: {
        labels: countryLabels,
        datasets: [{
          data: countryCounts,
          backgroundColor: [
            '#4caf50', '#ff9800', '#03a9f4', '#e91e63',
            '#9c27b0', '#009688', '#ff5722', '#8bc34a',
            '#ffc107', '#3f51b5', '#795548', '#607d8b'
          ],
          borderWidth: 1
        }]
      },
      options: {
        indexAxis: 'y', // ← ★ 這行讓它變成【水平長條圖】
        responsive: true,
        scales: {
          x: {
            ticks: {
              color: '#e0e0e0'
            },
            grid: {
              color: 'rgba(255,255,255,0.1)'
            }
          },
          y: {
            ticks: {
              color: '#e0e0e0'
            },
            grid: {
              display: false
            }
          }
        },
        plugins: {
          legend: {
            display: false // ★ 水平長條圖通常不需要 legend，更乾淨
          },
          tooltip: {
            callbacks: {
              label: (context) => {
                return `${context.label}: ${context.parsed.x}`;
              }
            }
          }
        }
      }
    });
  </script>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
