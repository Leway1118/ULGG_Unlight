<?php
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../lib/_analysis_base.php';

$pdo = $db;

$teamKey = trim((string)($_GET['team_key'] ?? ''));
$days = (int)($_GET['days'] ?? 30);

if (!preg_match('/^\d+-\d+-\d+$/', $teamKey)) {
  http_response_code(400);
  echo '<div class="ta-muted">隊伍參數錯誤</div>';
  exit;
}

if ($days < 1 || $days > 90) {
  $days = 30;
}

$cacheDir = __DIR__ . '/../../cache/team_counters';
if (!is_dir($cacheDir)) {
  mkdir($cacheDir, 0755, true);
}

$cacheFile = $cacheDir . '/' . $teamKey . '_' . $days . '.html';
$ttl = 600;

if (is_file($cacheFile) && time() - filemtime($cacheFile) < $ttl) {
  readfile($cacheFile);
  exit;
}

ob_start();

try {
  $counterTeams = getWorstEnemyCombosByTeam($pdo, $teamKey, $days, null, 5, 3);

  if (empty($counterTeams)) {
    echo '<div class="ta-muted">尚未觀測到明顯克制此隊伍的敵方組合</div>';
  } else {
    foreach ($counterTeams as $ct) {
      $counterLeader = (int)$ct['leader']['id'];
      $counterBack1 = (int)$ct['mates'][0]['id'];
      $counterBack2 = (int)$ct['mates'][1]['id'];
      $counterUrl = "/pages/team_analysis.php?id1={$counterLeader}&id2={$counterBack1}&id3={$counterBack2}&from=counter";
      ?>
      <a href="<?= htmlspecialchars($counterUrl) ?>"
        class="fight-card fight-card-counter fight-lose"
        style="text-decoration:none;color:inherit;"
        title="查看此克制隊伍的完整分析">

        <div class="fight-team fight-team-left fight-lose">
          <div class="fight-team-cards">
            <img src="<?= IMG_BASE . htmlspecialchars($ct['leader']['ico']) ?>" class="fight-char-ico">
            <img src="<?= IMG_BASE . htmlspecialchars($ct['mates'][0]['ico']) ?>" class="fight-char-ico">
            <img src="<?= IMG_BASE . htmlspecialchars($ct['mates'][1]['ico']) ?>" class="fight-char-ico">
          </div>

          <div class="fight-player">
            <?= htmlspecialchars(
              $ct['leader']['lv'] . $ct['leader']['name']
              . ' / ' .
              $ct['mates'][0]['lv'] . $ct['mates'][0]['name']
              . ' / ' .
              $ct['mates'][1]['lv'] . $ct['mates'][1]['name']
            ) ?>
          </div>

          <div class="fight-bp">
            敵方勝率 <?= htmlspecialchars((string)$ct['enemy_win_rate']) ?>%
          </div>
        </div>

        <div class="fight-center">
          <div class="fight-meta">
            對戰 <?= (int)$ct['matches'] ?> 場<br>
            我方勝率 <?= htmlspecialchars((string)$ct['my_win_rate']) ?>%<br>
            <span style="color:#ff6b6b;">
              風險 <?= htmlspecialchars((string)$ct['risk_score']) ?>
            </span>
          </div>
        </div>

        <div class="fight-team fight-team-right fight-lose">
          <div class="fight-player">我方劣勢</div>
          <div class="fight-bp">
            <?= (int)$ct['my_win'] ?> 勝 / <?= (int)$ct['my_lose'] ?> 敗
          </div>
        </div>
      </a>
      <?php
    }

    echo '<div class="ta-muted mt-2">※ 依敵方勝率 × 樣本數 × 風險權重排序，資料快取 10 分鐘</div>';
  }
} catch (Throwable $e) {
  http_response_code(500);
  echo '<div class="ta-muted">克制隊伍分析暫時無法載入</div>';
}

$html = ob_get_clean();

file_put_contents($cacheFile, $html);
echo $html;