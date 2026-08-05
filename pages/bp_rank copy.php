<?php
/* session_start(); */
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定

$seoTitle = 'BP 排行 Rank | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "bp_rank";
$pageTitleFull = 'BP 排行 Rank | UL.GG 戰績網'; //桌機
$pageTitleText = 'BP 排行 Rank'; //手機

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* =========================
   Rank Badges
========================= */
  .badge-up {
    background: #28a745;
  }

  .badge-new {
    background: #17a2b8;
  }

  .badge-top {
    background: #ffc107;
    color: #212529;
  }

  .badge-down {
    background: #dc3545;
  }

  .text-sm {
    font-size: .85rem;
  }

  


  /* =========================
   Milestone Table (Dark)
========================= */
  .milestone-table {
    background: #0b0c10;
  }

  .milestone-table th {
    background: #1f2937;
    color: #e5e7eb;
  }

  .milestone-table td {
    color: #e5e7eb;
  }

  .milestone-table tbody tr {
    background-color: #111827 !important;
  }

  .milestone-table tbody tr:nth-child(odd) {
    background-color: #0f172a !important;
  }


  /* =========================
   Milestone Table Border
========================= */
  .milestone-table {
    border: 1px solid rgba(255, 255, 255, 0.22);
  }

  .milestone-table th,
  .milestone-table td {
    border: 1px solid rgba(255, 255, 255, 0.18) !important;
  }

  /* 表頭再加一條亮線 */
  .milestone-table thead th {
    border-bottom: 1px solid rgba(120, 120, 255, 0.5) !important;
  }

  .datatable-update-time {
    text-align: right;
    margin-bottom: 8px;
    font-size: 0.85rem;
    color: #9fa8da;
  }

  /* 表頭不換行 */
  table.dataTable thead th {
    white-space: nowrap;
  }

  /* 角色 / 名稱欄位不斷行 */
  td.character-col {
    white-space: nowrap;
    max-width: 180px;
    /* 建議加，避免過長 */
    overflow: hidden;
    text-overflow: ellipsis;
  }

  /* 表格寬度計算穩定 */
  table.dataTable {
    table-layout: fixed;
  }

  /* 外層撐滿 card */
  .table-full {
    width: 100%;
    overflow-x: auto;
    /* 只有真的超寬才會出現 */
  }

  /* 表格一定吃滿 */
  .table-full table.dataTable {
    width: 100% !important;
    table-layout: auto;
    /* ⭐ 不要 fixed */
  }

  /* =========================
   BP Rank 手機縮排
========================= */
  @media (max-width: 768px) {

    /* 隱藏次要欄位 */
    .hide-mobile {
      display: none !important;
    }

    /* 整體字體略縮 */
    #bpTable {
      font-size: 13px;
    }

    /* Rank */
    .col-rank {
      width: 36px;
      text-align: center;
      color: #aaa;
    }

    /* 名稱：單行省略 */
    .col-name {
      max-width: 140px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .col-name a {
      display: inline-block;
      max-width: 100%;
      vertical-align: middle;
    }

    /* BP */
    .col-bp {
      width: 72px;
      text-align: right;
      font-weight: bold;
    }

    /* 勝率 */
    .col-rate {
      width: 64px;
      text-align: right;
    }

    .bp-change {
      display: none !important;
    }
  }

  .h3 {
    color: #dfdfdf;
  }
</style>

<?php
/* 放入你的 PHP程式    */

//$ack = $_SESSION["ack"] ?? 0;
$permission = $_SESSION["ack"] ?? 0;

// -----------------------------------------------------------
//  TW / JP 每月榜單匯入工具：初始化變數
// -----------------------------------------------------------
$dataPath = __DIR__ . "/steam/data";

// 安全初始化
$tw_updated_bp  = "";
$tw_updated_qp  = "";
$tw_last_import = "";

// --- 將 UTC 時間字串轉換成本地台灣時間 ---
function formatTWTime($utcString)
{
  if (empty($utcString)) return "（無資料）";

  try {
    $dt = new DateTime($utcString, new DateTimeZone("UTC"));
    $dt->setTimezone(new DateTimeZone("Asia/Taipei"));
    return $dt->format("Y-m-d H:i:s");
  } catch (Exception $e) {
    return "（格式錯誤）";
  }
}

// -----------------------------------------------------------
// 讀取 TW JSON 檔 updatedAt（⚠ 必須在 formatTWTime() 之前）
// -----------------------------------------------------------

$tw_bp_file = "$dataPath/ranking_bp_TW.json";
$tw_qp_file = "$dataPath/ranking_qp_TW.json";

if (file_exists($tw_bp_file)) {
  $json = json_decode(file_get_contents($tw_bp_file), true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $tw_updated_bp = $json["updatedAt"] ?? "";
  }
}

if (file_exists($tw_qp_file)) {
  $json = json_decode(file_get_contents($tw_qp_file), true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $tw_updated_qp = $json["updatedAt"] ?? "";
  }
}

// -----------------------------------------------------------
// 這裡才轉換成台灣時間（順序不能錯）
// -----------------------------------------------------------
$tw_bp_local = formatTWTime($tw_updated_bp);
$tw_qp_local = formatTWTime($tw_updated_qp);

// -----------------------------------------------------------
// 讀取 TW 最後匯入記錄
// -----------------------------------------------------------
$tw_last_import_file = "$dataPath/tw_last_import.txt";
if (file_exists($tw_last_import_file)) {
  $tw_last_import = trim(file_get_contents($tw_last_import_file));
}



// -----------------------------------------------------------
// JP 每月榜單匯入工具（重構後版本）
// -----------------------------------------------------------

// 安全初始化
$jp_updated_bp  = "";
$jp_updated_qp  = "";
$jp_last_import = "";

// JP JSON 檔案路徑
$jp_bp_file = "$dataPath/ranking_bp_JP.json";
$jp_qp_file = "$dataPath/ranking_qp_JP.json";

// 讀取 JP BP JSON
if (file_exists($jp_bp_file)) {
  $json = json_decode(file_get_contents($jp_bp_file), true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $jp_updated_bp = $json["updatedAt"] ?? "";
  }
}

// 讀取 JP QP JSON
if (file_exists($jp_qp_file)) {
  $json = json_decode(file_get_contents($jp_qp_file), true);
  if (json_last_error() === JSON_ERROR_NONE) {
    $jp_updated_qp = $json["updatedAt"] ?? "";
  }
}

// JP updatedAt → 轉換為台灣時間
$jp_bp_local = formatTWTime($jp_updated_bp);
$jp_qp_local = formatTWTime($jp_updated_qp);

// 讀 JP 最後匯入時間
$jp_last_import_file = "$dataPath/jp_last_import.txt";
if (file_exists($jp_last_import_file)) {
  $jp_last_import = trim(file_get_contents($jp_last_import_file));
}






$sql2 = "WITH latest_snapshot AS (
            -- 取最新一次的月結快照
            SELECT *
            FROM ranking_bp_TW_history
            WHERE ts = (
              SELECT MAX(ts)
              FROM ranking_bp_TW_history
            )
          ),
          ranked AS (
            -- 給最新快照裡每位玩家，依 BP 排序編上名次
            SELECT
              ts,
              name,
              level,
              bp,
              ROW_NUMBER() OVER (ORDER BY bp DESC) AS rn
            FROM latest_snapshot
          )
          -- 篩出第 5、30、100 名
          SELECT
            rn    AS `rn`,
            ts    AS `ts`,
            name  AS `name`,
            level AS `level`,
            bp    AS `bp`
          FROM ranked
          WHERE rn IN (5, 30, 100)
          ORDER BY rn DESC;
        ";
$stmt2 = $db->query($sql2);

if (!$stmt2) {
  $err = $db->errorInfo();
  die("查詢失敗：{$err[2]}");
}
// 一定要先初始化
$ms_rank = [];
$ms_bp   = [];
$ms_ts   = [];
// 三種底線樣式：紅／藍／綠
$ms_style = [
  'style="background-color: #c1ffc1;"',
  'style="background-color: #c3c3ff;"',
  'style="background-color: #ffaeae;"'
];
while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
  $ms_rank[] = (int)$row['rn'];
  $ms_bp[]   = (int)$row['bp'];
  $ms_ts[]   = $row['ts'];
}

// 取得「當日第一筆（最早）快照」的 ts
$todayDate = date('Y-m-d');
$stmtFirstTs = $db->prepare("
  SELECT MIN(ts) AS first_ts
  FROM ranking_bp_TW
  WHERE ts >= :start AND ts < DATE_ADD(:start, INTERVAL 1 DAY)
");
$stmtFirstTs->execute([':start' => $todayDate . ' 00:00:00']);
$baselineTs = $stmtFirstTs->fetchColumn();

// 撈出「當日第一筆」的排名快照（若不存在則留空，後面會自動跳過差異計算）
$prevSnapshot = [];
if ($baselineTs) {
  $stmtPrev = $db->prepare("
    SELECT name, rank_num, bp
    FROM ranking_bp_TW
    WHERE ts = :ts
  ");
  $stmtPrev->execute([':ts' => $baselineTs]);
  $prevSnapshot = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
}

$prevRank = $prevBp = [];
foreach ($prevSnapshot as $p) {
  $prevRank[$p['name']] = (int)$p['rank_num'];
  $prevBp[$p['name']]   = (int)$p['bp'];
}


// 1. 準備與執行查詢台服BP榜
try {
  $stmt = $db->query("SELECT MAX(`ts`) FROM `ranking_bp_TW`");
  $lastUpdate = $stmt->fetchColumn();
} catch (PDOException $e) {
  $lastUpdate = null;
}

$sql = "SELECT
  r.rank_num,
  r.name,
  r.bp,
  r.win_ranked,
  r.lose_ranked,
  r.draw_ranked,
  r.ts,
  u.black_list,
  CASE
    WHEN u.black_list = 1 THEN 1
    ELSE 0
  END AS is_watch
FROM ranking_bp_TW AS r
LEFT JOIN game_user AS u
  ON u.username = r.name
 AND u.region = 'TW'
WHERE r.ts = (
  SELECT MAX(ts) FROM ranking_bp_TW
)
ORDER BY r.rank_num DESC;
        ";
$stmt = $db->query($sql);

if (!$stmt) {
  $err = $db->errorInfo();
  die("查詢失敗：{$err[2]}");
}


// ------------------------------------------------------------
// ⭐ 在這裡放匯入 TW / JP 的 PHP 功能（最安全）
// ------------------------------------------------------------

/* 匯入程式開始 */
if (isset($_POST['import_tw'])) {

  $cmd = "python3 /var/www/html/unlight/watcher/import_bpqp_TW_his.py 2>&1";
  $output = shell_exec($cmd);

  echo "<pre style='background:#111;color:#0f0;padding:10px;'>
========= 🇹🇼 TW 匯入結果 =========
$output
================================
</pre>";

  echo "<script>alert('🇹🇼 TW BP/QP 歷史已成功匯入！');</script>";
  //echo "<meta http-equiv='refresh' content='1'>";
}

if (isset($_POST['import_jp'])) {

  $cmd = "python3 /var/www/html/unlight/watcher/import_bpqp_JP_his.py 2>&1";
  $output = shell_exec($cmd);

  echo "<pre style='background:#111;color:#0f0;padding:10px;'>
========= 🇯🇵 JP 匯入結果 =========
$output
================================
</pre>";

  echo "<script>alert('🇯🇵 JP BP/QP 歷史已成功匯入！');</script>";
  //echo "<meta http-equiv='refresh' content='1'>";
}
/* 匯入程式結束 */
?>
<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <!-- left column -->
        <div class="col-md-6">
          <?php if ($permission >= 2): ?>

            <?php
            // 取得 TW 最新匯入時間
            try {
              $tw_last_import_ts = $db->query("SELECT ts FROM ranking_bp_TW_history ORDER BY ts DESC LIMIT 1")->fetchColumn();
              $tw_last_import_bp = $tw_last_import_ts ? date("Y-m-d H:i:s", strtotime($tw_last_import_ts)) : "（無資料）";
            } catch (Exception $e) {
              $tw_last_import_bp = "（讀取失敗）";
            }

            try {
              $tw_last_import_ts_qp = $db->query("SELECT ts FROM ranking_qp_TW_history ORDER BY ts DESC LIMIT 1")->fetchColumn();
              $tw_last_import_qp = $tw_last_import_ts_qp ? date("Y-m-d H:i:s", strtotime($tw_last_import_ts_qp)) : "（無資料）";
            } catch (Exception $e) {
              $tw_last_import_qp = "（讀取失敗）";
            }
            ?>

            <!-- 🔵 可伸縮按鈕 -->
            <button class="btn btn-primary btn-block" type="button" data-toggle="collapse"
              data-target="#twImportPanel" style="max-width: 260px;margin: 0 auto;">
              🇹🇼 TW 每月榜單匯入工具 ▼
            </button>

            <!-- ▼ 展開內容 -->
            <div id="twImportPanel" class="collapse">
              <div class="alert alert-secondary" style="padding:10px; margin-bottom:10px;">
                <h5>🇹🇼 TW 每月榜單匯入工具</h5>

                <div><strong>JSON 最新更新時間 / 最後匯入時間：</strong></div>

                <div>BP：<?= htmlspecialchars($tw_bp_local) ?> / <?= htmlspecialchars($tw_last_import_bp) ?></div>
                <div>QP：<?= htmlspecialchars($tw_qp_local) ?> / <?= htmlspecialchars($tw_last_import_qp) ?></div>

                <form method="post" style="margin-top:10px;">
                  <button class="btn btn-warning btn-sm" name="import_tw" value="1"
                    onclick="return confirm('確定要匯入 TW BP/QP 歷史嗎？');">
                    🇹🇼 一鍵匯入 TW（BP + QP）
                  </button>
                </form>
              </div>
            </div>

          <?php endif; ?>


          <div class="card-dark" style="padding: 5px;">
            <div class="card-gradient-header">本季Steam BP排行</div>
            <div class="table-full">
              <table id="example1" class="table datatable-init-hide">
                <thead>
                  <tr>
                    <th class="col-rank">#</th>
                    <th class="col-name">名稱</th>
                    <th class="col-bp">BP</th>
                    <th class="col-win hide-mobile">勝</th>
                    <th class="col-lose hide-mobile">敗</th>
                    <th class="col-total hide-mobile">總計</th>
                    <th class="col-rate">勝率</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $i = 0;
                  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rank_num  = (int) $row['rank_num'];
                    $name      = $row['name'];
                    $bp        = (int) $row['bp'];
                    $win       = (int) $row['win_ranked'];
                    $lose      = (int) $row['lose_ranked'];
                    $draw      = (int) $row['draw_ranked'];
                    $total     = $win + $lose + $draw;
                    $rate      = $total > 0 ? round($win / $total * 100, 1) . '%' : '0%';
                    // 計算名次變化

                    // 把時間字串取出時:分

                    if (!empty($prevRank)) {
                      // 找得到當日第一筆 → 計算與 baseline 差異（台服用台服對照表）
                      $beforeRank = $prevRank[$name] ?? null;

                      if ($beforeRank === null) {
                        $rankChange = '<span class="badge badge-new text-sm">新進榜</span>';
                      } elseif ($rank_num === 1 && $beforeRank > 1) {
                        $rankChange = '<span class="badge badge-top text-sm">登頂</span>';
                      } elseif ($rank_num < $beforeRank) {
                        $delta = $beforeRank - $rank_num;
                        $rankChange = "<span class=\"badge badge-up text-sm\">↑{$delta}</span>";
                      } elseif ($rank_num > $beforeRank) {
                        $delta = $rank_num - $beforeRank;
                        $rankChange = "<span class=\"badge badge-down text-sm\">↓{$delta}</span>";
                      } else {
                        $rankChange = '<span class="text-sm">—</span>';
                      }

                      $beforeBp = $prevBp[$name] ?? null;
                      if ($beforeBp !== null) {
                        $deltaBp = $bp - $beforeBp;
                        $sign    = $deltaBp >= 0 ? '+' : '';
                        $bpChange = "<small class=\"text-sm\">{$sign}{$deltaBp}</small>";
                      } else {
                        $bpChange = '';
                      }
                    } else {
                      // 當日完全沒有 baseline → 不顯示差異
                      $rankChange = '';
                      $bpChange   = '';
                    }




                    // 產生連結
                    $link = "fight.php?player_name=" . urlencode($name);
                    /* $link = "/pages/profile.php?player=" . urlencode($name); */

                    // 如果黑名單，把 class 換成 blacklist-name，否則就 player-name 或其他預設
                    $nameClass = 'player-name';

                    if (!empty($row['black_list']) && $row['black_list'] >= 2) {
                      // 黑名單
                      $nameClass .= ' blacklist-name';
                    } elseif (!empty($row['black_list']) && $row['black_list'] == 1) {
                      // 待觀察
                      $nameClass .= ' watchlist-name';
                    }

                    // ===== Tooltip（黑名單 / 待觀察）=====
                    $titleAttr = '';

                    if (!empty($row['black_list']) && $row['black_list'] >= 2) {
                      $titleAttr = '⚠ 此玩家為黑名單';
                    } elseif (!empty($row['is_watch'])) {
                      $titleAttr = '◉ 此玩家目前為待觀察狀態';
                    }



                    // 里程碑樣式
                    $style = '';
                    if (isset($ms_bp[$i]) && $bp >= $ms_bp[$i]) {
                      $style = $ms_style[$i];
                      $i++;
                    }
                  ?>
                    <tr <?= $style ?>>
                      <td class="col-rank"><?= $rank_num ?></td>

                      <td class="col-name">
                        <a href="<?= $link ?>" class="<?= $nameClass ?>" <?= $titleAttr ? 'title="' . htmlspecialchars($titleAttr, ENT_QUOTES) . '"' : '' ?>>
                          <?= htmlspecialchars($name, ENT_QUOTES) ?>
                        </a>
                        <?= $rankChange ?>
                      </td>

                      <td class="col-bp">
                        <?= $bp ?>
                        <span class="bp-change"><?= $bpChange ?></span>
                      </td>


                      <td class="col-win hide-mobile"><?= $win ?></td>
                      <td class="col-lose hide-mobile"><?= $lose ?></td>
                      <td class="col-total hide-mobile"><?= $total ?></td>

                      <td class="col-rate"><?= $rate ?></td>
                    </tr>

                  <?php } // end while 
                  ?>
                </tbody>
              </table>
              <div class="text-muted small mt-2">
                ※ 勝率計算方式：勝場 ÷（勝場 + 敗場 + 平場）
              </div>
            </div>
          </div>
          <!-- /.card style="display: none;"-->
          <div class="card-dark">
            <div class="box-header">
              <h3 class="box-title">上季Steam BP里程碑:</h3>
            </div>
            <!-- /.box-header -->
            <div class="box-body">
              <table class="table table-bordered table-striped milestone-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <th>BP</th>
                    <th>更新時間</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i = 2; $i >= 0; $i--): ?>
                    <tr <?= $ms_style[$i] ?>>
                      <td><?= htmlspecialchars($ms_rank[$i]) ?></td>
                      <td><?= htmlspecialchars($ms_bp[$i]) ?></td>
                      <td><?= htmlspecialchars($ms_ts[$i]) ?></td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>

            <!-- /.box-body -->
          </div>
          <!-- /.box -->
        </div>
        <!-- /.col -->
        <!-- right column -->
        <div class="col-md-6">
          <?php
          $sql2 = "WITH latest_snapshot AS (
            -- 取最新一次的月結快照
            SELECT *
            FROM ranking_bp_JP_history
            WHERE ts = (
              SELECT MAX(ts)
              FROM ranking_bp_JP_history
            )
          ),
          ranked AS (
            -- 給最新快照裡每位玩家，依 BP 排序編上名次
            SELECT
              ts,
              name,
              level,
              bp,
              ROW_NUMBER() OVER (ORDER BY bp DESC) AS rn
            FROM latest_snapshot
          )
          -- 篩出第 5、30、100 名
          SELECT
            rn    AS `rn`,
            ts    AS `ts`,
            name  AS `name`,
            level AS `level`,
            bp    AS `bp`
          FROM ranked
          WHERE rn IN (5, 30, 100)
          ORDER BY rn DESC;
        ";
          $stmt2 = $db->query($sql2);

          if (!$stmt2) {
            $err = $db->errorInfo();
            die("查詢失敗：{$err[2]}");
          }
          // 一定要先初始化
          $ms_rank_JP = [];
          $ms_bp_JP   = [];
          $ms_ts_JP = [];
          // 三種底線樣式：紅／藍／綠
          $ms_style_JP = [
            'style="background-color: #c1ffc1;"',
            'style="background-color: #c3c3ff;"',
            'style="background-color: #ffaeae;"'
          ];
          while ($row = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $ms_rank_JP[] = (int)$row['rn'];
            $ms_bp_JP[]   = (int)$row['bp'];
            $ms_ts_JP[]   = $row['ts'];
          }
          // 撈出 DMM「當日第一筆（最早）快照」
          $stmtFirstTsJP = $db->prepare("
            SELECT MIN(ts) AS first_ts
            FROM ranking_bp_JP
            WHERE ts >= :start AND ts < DATE_ADD(:start, INTERVAL 1 DAY)
          ");
          $stmtFirstTsJP->execute([':start' => $todayDate . ' 00:00:00']);
          $baselineTsJP = $stmtFirstTsJP->fetchColumn();

          $prevSnapshotJP = [];
          if ($baselineTsJP) {
            $stmtPrev = $db->prepare("
            SELECT name, rank_num, bp
            FROM ranking_bp_JP
            WHERE ts = :ts
          ");
            $stmtPrev->execute([':ts' => $baselineTsJP]);
            $prevSnapshotJP = $stmtPrev->fetchAll(PDO::FETCH_ASSOC);
          }

          $prevRankJP = $prevBpJP = [];
          foreach ($prevSnapshotJP as $p) {
            $prevRankJP[$p['name']] = (int)$p['rank_num'];
            $prevBpJP[$p['name']]   = (int)$p['bp'];
          }



          // 1. 準備與執行查詢日服BP榜
          try {
            $stmt = $db->query("SELECT MAX(`ts`) FROM `ranking_bp_JP`");
            $lastUpdate_JP = $stmt->fetchColumn();
          } catch (PDOException $e) {
            $lastUpdate_JP = null;
          }

          $sql = "SELECT
  r.rank_num,
  r.name,
  r.bp,
  r.win_ranked,
  r.lose_ranked,
  r.draw_ranked,
  r.ts,
  u.black_list,
  CASE
    WHEN u.black_list = 1 THEN 1
    ELSE 0
  END AS is_watch
FROM ranking_bp_JP AS r
LEFT JOIN game_user AS u
  ON u.username = r.name
 AND u.region = 'JP'
WHERE r.ts = (
  SELECT MAX(ts) FROM ranking_bp_JP
)
ORDER BY r.rank_num DESC;

        ";
          $stmt = $db->query($sql);

          if (!$stmt) {
            $err = $db->errorInfo();
            die("查詢失敗：{$err[2]}");
          }
          if ($permission >= 2):
          ?>

            <?php
            // 取得 JP 最新匯入時間
            try {
              $jp_last_import_ts = $db->query("SELECT ts FROM ranking_bp_JP_history ORDER BY ts DESC LIMIT 1")->fetchColumn();
              $jp_last_import_bp = $jp_last_import_ts ? date("Y-m-d H:i:s", strtotime($jp_last_import_ts)) : "（無資料）";
            } catch (Exception $e) {
              $jp_last_import_bp = "（讀取失敗）";
            }

            try {
              $jp_last_import_ts_qp = $db->query("SELECT ts FROM ranking_qp_JP_history ORDER BY ts DESC LIMIT 1")->fetchColumn();
              $jp_last_import_qp = $jp_last_import_ts_qp ? date("Y-m-d H:i:s", strtotime($jp_last_import_ts_qp)) : "（無資料）";
            } catch (Exception $e) {
              $jp_last_import_qp = "（讀取失敗）";
            }
            ?>

            <!-- ▼ JP 伸縮按鈕 -->
            <button class="btn btn-info btn-block" type="button" data-toggle="collapse"
              data-target="#jpImportPanel" style="max-width: 260px;margin: 0 auto;">
              🇯🇵 JP 每月榜單匯入工具 ▼
            </button>

            <!-- ▼ 展開內容 -->
            <div id="jpImportPanel" class="collapse">
              <div class="alert alert-secondary" style="padding:10px; margin-bottom:10px;">
                <h5>🇯🇵 JP 每月榜單匯入工具</h5>

                <div><strong>JSON 最新更新時間 / 最後匯入時間：</strong></div>

                <div>BP：<?= htmlspecialchars($jp_bp_local) ?> / <?= htmlspecialchars($jp_last_import_bp) ?></div>
                <div>QP：<?= htmlspecialchars($jp_qp_local) ?> / <?= htmlspecialchars($jp_last_import_qp) ?></div>

                <form method="post" style="margin-top:10px;">
                  <button class="btn btn-info btn-sm" name="import_jp" value="1"
                    onclick="return confirm('確定要匯入 JP BP/QP 歷史嗎？');">
                    🇯🇵 一鍵匯入 JP（BP + QP）
                  </button>
                </form>
              </div>
            </div>

          <?php endif; ?>



          <div class="card-dark" style="padding: 5px;">
            <div class="card-gradient-header">DMM BP排行</div>
            <div class="table-full">
              <table id="exampleJP" class="table datatable-init-hide">
                <thead>
                  <tr>
                    <th class="col-rank">#</th>
                    <th class="col-name">名稱</th>
                    <th class="col-bp">BP</th>
                    <th class="col-win hide-mobile">勝</th>
                    <th class="col-lose hide-mobile">敗</th>
                    <th class="col-total hide-mobile">總計</th>
                    <th class="col-rate">勝率</th>
                  </tr>
                </thead>
                <tbody>
                  <?php
                  $i = 0;
                  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $rank_num  = (int) $row['rank_num'];
                    $name      = $row['name'];
                    $bp        = (int) $row['bp'];
                    $win       = (int) $row['win_ranked'];
                    $lose      = (int) $row['lose_ranked'];
                    $draw      = (int) $row['draw_ranked'];
                    $total     = $win + $lose + $draw;
                    $rate      = $total > 0 ? round($win / $total * 100, 1) . '%' : '0%';
                    // 計算名次變化



                    if (!empty($prevRankJP)) {
                      $beforeRank = $prevRankJP[$name] ?? null;
                      if ($beforeRank === null) {
                        $rankChange = '<span class="badge badge-new text-sm">新進榜</span>';
                      } elseif ($rank_num === 1 && $beforeRank > 1) {
                        $rankChange = '<span class="badge badge-top text-sm">登頂</span>';
                      } elseif ($rank_num < $beforeRank) {
                        $delta = $beforeRank - $rank_num;
                        $rankChange = "<span class=\"badge badge-up text-sm\">↑{$delta}</span>";
                      } elseif ($rank_num > $beforeRank) {
                        $delta = $rank_num - $beforeRank;
                        $rankChange = "<span class=\"badge badge-down text-sm\">↓{$delta}</span>";
                      } else {
                        $rankChange = '<span class="text-sm">—</span>';
                      }

                      $beforeBp = $prevBpJP[$name] ?? null;
                      if ($beforeBp !== null) {
                        $deltaBp = $bp - $beforeBp;
                        $sign    = $deltaBp >= 0 ? '+' : '';
                        $bpChange = "<small class=\"text-sm\">{$sign}{$deltaBp}</small>";
                      } else {
                        $bpChange = '';
                      }
                    } else {
                      $rankChange = '';
                      $bpChange   = '';
                    }



                    // 產生連結
                    $link = "fight.php?player_name=" . urlencode($name);

                    // 如果黑名單，把 class 換成 blacklist-name，否則就 player-name 或其他預設
                    $nameClass = 'player-name';

                    if (!empty($row['black_list']) && $row['black_list'] >= 2) {
                      // 黑名單
                      $nameClass .= ' blacklist-name';
                    } elseif (!empty($row['black_list']) && $row['black_list'] == 1) {
                      // 待觀察
                      $nameClass .= ' watchlist-name';
                    }

                    // ===== Tooltip（黑名單 / 待觀察）=====
                    $titleAttr = '';

                    if (!empty($row['black_list']) && $row['black_list'] >= 2) {
                      $titleAttr = '⚠ 此玩家為黑名單';
                    } elseif (!empty($row['is_watch'])) {
                      $titleAttr = '◉ 此玩家目前為待觀察狀態';
                    }




                    // 里程碑樣式
                    $style = '';
                    if (isset($ms_bp_JP[$i]) && $bp >= $ms_bp_JP[$i]) {
                      $style = $ms_style_JP[$i];
                      $i++;
                    }
                  ?>
                    <tr <?= $style ?>>
                      <td class="col-rank"><?= $rank_num ?></td>

                      <td class="col-name">
                        <a href="<?= $link ?>" class="<?= $nameClass ?>" <?= $titleAttr ? 'title="' . htmlspecialchars($titleAttr, ENT_QUOTES) . '"' : '' ?>>
                          <?= htmlspecialchars($name, ENT_QUOTES) ?>
                        </a>
                        <?= $rankChange ?>
                      </td>

                      <td class="col-bp">
                        <?= $bp ?>
                        <span class="bp-change"><?= $bpChange ?></span>
                      </td>


                      <td class="col-win hide-mobile"><?= $win ?></td>
                      <td class="col-lose hide-mobile"><?= $lose ?></td>
                      <td class="col-total hide-mobile"><?= $total ?></td>

                      <td class="col-rate"><?= $rate ?></td>
                    </tr>
                  <?php } // end while 
                  ?>
                </tbody>
              </table>
              <div class="text-muted small mt-2">
                ※ 勝率計算方式：勝場 ÷（勝場 + 敗場 + 平場）
              </div>
            </div>

          </div>
          <!-- /.card style="display: none;"-->
          <div class="card-dark">
            <div class="box-header">
              <h3 class="box-title">上季DMM BP里程碑:</h3>
            </div>
            <!-- /.box-header -->
            <div class="box-body">
              <table class="table table-bordered table-striped milestone-table">
                <thead>
                  <tr>
                    <th>#</th>
                    <!-- <th>名稱</th> -->
                    <th>BP</th>
                    <!-- <th>上季-勝</th>
                  <th>上季-敗</th>
                  <th>上季-總計</th>
                  <th>勝率</th> -->
                    <th>更新時間</th>
                  </tr>
                </thead>
                <tbody>
                  <?php for ($i = 2; $i >= 0; $i--): ?>
                    <tr <?= $ms_style_JP[$i] ?>>
                      <td><?= htmlspecialchars($ms_rank_JP[$i]) ?></td>
                      <td><?= htmlspecialchars($ms_bp_JP[$i]) ?></td>
                      <td><?= htmlspecialchars($ms_ts_JP[$i]) ?></td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>
            </div>
            <!-- /.box-body -->
          </div>
          <!-- /.box -->
        </div>
        <!-- /.col -->
      </div>
      <!-- /.row -->
    </div>
    <!-- /.container -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<!-- DataTables -->
<link rel="stylesheet" href="../assets/bower_components/datatables.net-bs/css/dataTables.bootstrap.min.css">
<script src="../assets/bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="../assets/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>


<!-- page script -->
<script>
  const lastUpdateTW = <?= $lastUpdate ? json_encode($lastUpdate) : 'null' ?>;
  const lastUpdateJP = <?= $lastUpdate_JP ? json_encode($lastUpdate_JP) : 'null' ?>;
</script>

<script>
  $(function() {

    /* ===============================
       插入更新時間
    =============================== */
    function injectUpdateTime(tableSelector, lastUpdate) {
      if (!lastUpdate) return;

      const formatted = new Date(lastUpdate.replace(' ', 'T'))
        .toLocaleString('zh-TW', {
          hour12: false
        });

      const wrapper = $(tableSelector).closest('.dataTables_wrapper');

      if (wrapper.find('.datatable-update-time').length === 0) {
        wrapper.prepend(`
          <div class="datatable-update-time">
            最後更新：${formatted}
          </div>
        `);
      }
    }

    /* ===============================
       建立「兩排式工具列」
    =============================== */
    function buildTopToolbar(dtApi) {
      const $wrapper = $(dtApi.table().container()).closest('.dataTables_wrapper');

      // 防止重複建立
      if ($wrapper.find('.dt-top-toolbar').length) return;

      const buttons = $wrapper.find('.dt-buttons').get(0);
      const length = $wrapper.find('.dataTables_length').get(0);
      const filter = $wrapper.find('.dataTables_filter').get(0);

      if (!buttons || !length || !filter) return;

      const toolbar = document.createElement('div');
      toolbar.className = 'dt-top-toolbar';

      const rowTop = document.createElement('div');
      rowTop.className = 'dt-toolbar-row';

      const rowSearch = document.createElement('div');
      rowSearch.className = 'dt-toolbar-search';

      rowTop.appendChild(buttons);
      rowTop.appendChild(length);
      rowSearch.appendChild(filter);

      toolbar.appendChild(rowTop);
      toolbar.appendChild(rowSearch);

      // 插在最前面（更新時間會在它上面）
      $wrapper.prepend(toolbar);
    }

    /* ===============================
       DataTables 初始化
    =============================== */
    function initRankingTable(selector) {

      if ($.fn.DataTable.isDataTable(selector)) {
        $(selector).DataTable().destroy();
      }

      const table = $(selector).DataTable({
        dom: 'Blfrtip',

        buttons: [{
            extend: 'excelHtml5',
            text: 'Excel',
            className: 'btn btn-default'
          },
          {
            text: '匯出截圖',
            className: 'btn btn-default',
            action: function(e, dt) {
              const wrapper = dt.table().container().parentNode;
              html2canvas(wrapper).then(canvas => {
                canvas.toBlob(blob => {
                  const a = document.createElement('a');
                  a.href = URL.createObjectURL(blob);
                  a.download = 'datatable.png';
                  a.click();
                });
              });
            }
          }
        ],

        paging: true,
        lengthChange: true,
        pageLength: 10,
        lengthMenu: [
          [10, 25, 50, 100, -1],
          ['10 列', '25 列', '50 列', '100 列', '全部']
        ],

        searching: true,
        ordering: true,
        info: true,

        scrollCollapse: true,
        scrollX: false,
        autoWidth: false,
        deferRender: true,

        order: [
          [0, 'asc']
        ],

        language: {
          lengthMenu: '顯示 _MENU_',
          paginate: {
            previous: '‹',
            next: '›'
          },
          info: '顯示第 _START_ 到 _END_ 筆，共 _TOTAL_ 筆'
        },

        /* ⭐ 關鍵：初始化完成後再動工具列 */
        initComplete: function() {
          const api = this.api();
          buildTopToolbar(api);

          // 只調整自己這張表的搜尋框
          $(api.table().container())
            .closest('.dataTables_wrapper')
            .find('.dataTables_filter input')
            .css('width', '220px');
        }
      });

      return table;
    }

    /* ===============================
       啟動
    =============================== */
    initRankingTable('#example1');
    initRankingTable('#exampleJP');

    injectUpdateTime('#example1', lastUpdateTW);
    injectUpdateTime('#exampleJP', lastUpdateJP);

    /* 顯示表格（避免白閃） */
    $('#example1, #exampleJP, .dataTables_scrollHead table, .dataTables_scrollBody table')
      .removeClass('datatable-init-hide');
  });
</script>






<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>