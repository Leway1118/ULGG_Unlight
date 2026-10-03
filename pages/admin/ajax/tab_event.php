<style>
  /* ===============================
 * Event Card (統一尺寸)
 * =============================== */

  .event-card {
    width: 48px;
    height: 64px;
    border-radius: 6px;
    overflow: hidden;
    background: #0f1118;
    border: 1px solid rgba(255, 255, 255, .12);

    display: flex;
    align-items: center;
    justify-content: center;
  }

  .event-card img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  /* 構築用事件卡排列 */
  .event-build {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
  }

  /* 表格內小事件卡（排行用） */
  .event-card-sm {
    width: 36px;
    height: 48px;
  }

  /* 事件卡欄位：圖片＋文字左右並排、上下置中 */
  .event-cell {
    display: flex;
    align-items: center;
    /* ⬅️ 關鍵：上下置中 */
    gap: 10px;
    white-space: nowrap;
  }


  /* ===============================
 * Team Build Block
 * =============================== */

  .team-build {
    padding: 10px 12px;
    border-radius: 8px;
    background: rgba(255, 255, 255, .02);
    border: 1px solid rgba(255, 255, 255, .06);
    margin-bottom: 12px;
  }

  .team-chars {
    display: flex;
    gap: 6px;
    margin-bottom: 6px;
  }

  .team-meta {
    font-size: 13px;
    color: #9aa4b2;
    margin-bottom: 6px;
  }

  /* ===============================
 * Event Card with Usage Rate
 * =============================== */

  .event-card-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
    width: 48px;
  }

  .event-rate {
    font-size: 11px;
    color: #9aa4b2;
    line-height: 1;
    white-space: nowrap;
  }

  /* 核心事件卡（高出現率）可選強化 */
  .event-card-wrap.core .event-rate {
    color: #8fa3ff;
    font-weight: 600;
  }

  .event-card-empty {
    width: 56px;
    height: 78px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, .03);
    border: 1px dashed rgba(255, 255, 255, .2);
    border-radius: 6px;
    text-align: center;
  }

  /* ===============================
 * Event Card - No Data (Fight Style)
 * =============================== */

  .event-card.no-data {
    width: 48px;
    /* 跟正常事件卡一致 */
    height: 64px;
    border-radius: 6px;

    background:
      repeating-linear-gradient(45deg,
        #2b2f3a,
        #2b2f3a 6px,
        #1f2430 6px,
        #1f2430 12px);

    border: 1px dashed rgba(180, 200, 255, 0.25);

    display: flex;
    align-items: center;
    justify-content: center;

    color: rgba(200, 210, 230, 0.6);
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 1px;
  }

  /* 包外層一致 */
  .event-card-wrap.no-data {
    width: 48px;
    opacity: 0.9;
  }
</style>

<?php
// ===============================
// AJAX: 單卡分析｜事件卡構築分析
// ===============================


ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../lib/_analysis_base.php';
$pdo = $db;

ini_set('display_errors', 1);
error_reporting(E_ALL);

// -------------------------------
// 參數
// -------------------------------
if (!isset($_GET['char_id'])) {
  echo '<div class="text-muted small">未指定角色</div>';
  exit;
}
$charId  = (int)($_GET['char_id'] ?? 0);
$days    = (int)($_GET['days'] ?? 90);
$costKey = $_GET['cost'] ?? 'ALL';

if ($charId <= 0) {
  echo '<div class="text-danger small">Invalid character</div>';
  exit;
}

if (!in_array($days, [14, 30, 90], true)) {
  $days = 90;
}

$metaDays = $days;

// -------------------------------
// COST 條件
// -------------------------------
$costCond = buildCostCondition($costKey);

// =========================================================
// 一、角色事件卡「使用排行」
// =========================================================
// 回傳：
// event_id, name, ico, matches, win_rate
// ---------------------------------------------------------
$baseWinRate = getCharacterBaseWinRate(
  $pdo,
  $charId,
  $days,
  $costCond['sql']
);

$eventUsageList = getCharacterEventUsage(
  $pdo,
  $charId,
  $days,
  $costCond['sql'],
  $baseWinRate,
  15
);

// =========================================================
// 二、角色事件卡「帶 / 不帶」差異
// =========================================================
// 回傳：
// event_id, name, ico, win_with, win_without, delta, matches
// ---------------------------------------------------------
$eventDeltaList = getCharacterEventDelta(
  $pdo,
  $charId,
  $days,
  $costCond['sql'],
  10
);
?>
<div class="ul-card">
  <h5 class="card-title">
    🃏 事件卡構築使用排行
    <span class="text-muted small">｜近 <?= $metaDays ?> 天</span>
  </h5>

  <div class="table-responsive">
    <table class="table table-dark table-striped mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>事件卡</th>
          <th>表現分數</th>
          <th>構築勝率</th>
          <th>使用場次</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($eventUsageList as $i => $row): ?>
          <tr>
            <td><?= $i + 1 ?></td>

            <td>
              <div class="event-cell">
                <div class="event-card event-card-sm">
                  <img
                    src="<?= IMG_BASE . $row['ico'] ?>"
                    alt="<?= htmlspecialchars($row['name']) ?>">
                </div>
                <span><?= htmlspecialchars($row['name']) ?></span>
              </div>
            </td>
            <td>
              <strong><?= number_format($row['score'], 1) ?></strong>
            </td>

            <td><?= number_format($row['win_rate'], 1) ?>%</td>
            <td><?= number_format($row['matches']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="text-muted small mt-2">
    ※ 本區為「構築分析」，代表對戰前選擇攜帶該事件卡之整體勝率，
    不代表實戰實際使用狀況。
  </div>
</div>

<div class="ul-card mt-3">
  <h5 class="card-title">
    📊 構築影響分析（帶 / 不帶）
    <span class="text-muted small">｜近 <?= $metaDays ?> 天</span>
  </h5>

  <div class="table-responsive">
    <table class="table table-dark table-striped mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>事件卡</th>
          <th>帶卡勝率</th>
          <th>不帶勝率</th>
          <th>差異</th>
          <th>樣本</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($eventDeltaList as $i => $row): ?>
          <?php
          $deltaClass = $row['delta'] > 0
            ? 'text-success'
            : ($row['delta'] < 0 ? 'text-danger' : 'text-muted');
          ?>
          <tr>
            <td><?= $i + 1 ?></td>

            <td>
              <div class="event-cell">
                <div class="event-card event-card-sm">
                  <img
                    src="<?= IMG_BASE . $row['ico'] ?>"
                    alt="<?= htmlspecialchars($row['name']) ?>">
                </div>
                <span><?= htmlspecialchars($row['name']) ?></span>
              </div>
            </td>


            <td><?= number_format($row['win_with'], 1) ?>%</td>
            <td><?= number_format($row['win_without'], 1) ?>%</td>
            <td class="<?= $deltaClass ?>">
              <?= ($row['delta'] > 0 ? '+' : '') . number_format($row['delta'], 1) ?>%
            </td>
            <td><?= number_format($row['matches']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="text-muted small mt-2">
    ※ 差異為「攜帶 vs 未攜帶」勝率比較，僅供構築方向參考。
  </div>
</div>





<?php
// =========================================================
// 三、隊伍 × 事件卡（含該角色）
// =========================================================
// 回傳：
// team (leader, mates), event list, win_rate, matches
// ---------------------------------------------------------
$teamEventList = getBestTeamEventBuilds(
  $pdo,
  $charId,
  $days,
  $costCond['sql'],
  5
);
?>

<div class="ul-card mt-3">
  <h5 class="card-title">
    🧩 隊伍事件卡構築（僅顯示牌組中>=3張的事件卡）
    <span class="text-muted small">｜近 <?= $metaDays ?> 天</span>
  </h5>

  <?php foreach ($teamEventList as $team): ?>
    <div class="team-build">

      <!-- 角色 -->
      <div class="team-chars">
        <?php foreach ($team['chars'] as $c): ?>
          <img
            src="<?= IMG_BASE . $c['ico'] ?>"
            class="char-ico-sm"
            title="<?= htmlspecialchars($c['name']) ?>">
        <?php endforeach; ?>
      </div>

      <!-- 勝率 / 樣本 -->
      <div class="team-meta">
        勝率 <strong><?= number_format($team['win_rate'], 1) ?>%</strong>
        ｜樣本 <?= number_format($team['matches']) ?>
      </div>

      <!-- 事件卡構築（ICO） -->
      <!-- 事件卡構築（ICO） -->
      <div class="event-build">

        <?php
        // 🔑 判斷是否有任何有效事件卡
        $hasRealEvent = false;
        foreach ($team['events'] as $e) {
          if (($e['type'] ?? '') !== 'no-data') {
            $hasRealEvent = true;
            break;
          }
        }
        ?>

        <?php if (
          ($e['type'] ?? '') === 'no-data'
          || empty($e['ico'])
        ): ?>

          <!-- 🟦 整組都是無事件資料 -->
          <div class="event-card-wrap no-data">
            <div class="event-card no-data">
              <span class="no-data-text">
                無事件<br>資料
              </span>
            </div>
          </div>

        <?php else: ?>

          <!-- 🟩 有有效事件卡 -->
          <?php foreach ($team['events'] as $e): ?>
            <?php if (($e['type'] ?? '') === 'no-data') continue; ?>

            <?php
            $pcs = $team['matches'] > 0
              ? round($e['count'] / $team['matches'], 1)
              : 0;
            ?>

            <div class="event-card-wrap" title="<?= htmlspecialchars($e['name']) ?>">
              <div class="event-card">
                <img
                  src="<?= IMG_BASE . $e['ico'] ?>"
                  alt="<?= htmlspecialchars($e['name']) ?>">
              </div>
              <div class="event-rate"><?= $pcs ?>張</div>
            </div>

          <?php endforeach; ?>

        <?php endif; ?>

      </div>

    </div>
  <?php endforeach; ?>

  <div class="text-muted small mt-2">
    ※ 下方顯示該隊伍在歷史對戰中常見的事件卡構築，
    排序依出現率高低，用於判斷核心與輔助事件卡。
  </div>
</div>

<?php
$stmt = $pdo->prepare("
  SELECT name
  FROM unlight
  WHERE id = ?
  LIMIT 1
");
$stmt->execute([$charId]);
$char = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$char) {
  echo '<div class="text-muted small">角色不存在</div>';
  exit;
}

$stmt = $pdo->prepare("
  SELECT id, level, ico
  FROM unlight
  WHERE name = ?
  ORDER BY id ASC
");
$stmt->execute([$char['name']]);

$cardMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $cardMap[$row['level']] = $row;
}
$levelLabels = ['L1', 'L2', 'L3', 'L4', 'L5', 'R1', 'R2', 'R3', 'R4', 'R5'];
?>

<!-- 等級選擇 -->
<div class="row mb-4">
  <div class="col-md-12">
    <div class="ul-card">
      <h4 class="card-title">
        展開單卡分析
        <span class="text-muted small card-cta">｜選擇等級</span>
      </h4>

      <div class="d-flex flex-wrap justify-content-center gap-2 mt-3 card-album">

        <?php foreach ($levelLabels as $i => $label):

          // ⭐ 沒卡資料 or 沒 ico → 跳過
          if (
            empty($cardMap[$label]) ||
            empty($cardMap[$label]['ico'])
          ) {
            continue;
          }

          // ⭐ 單卡資料（核心）
          $cardId  = (int)$cardMap[$label]['id'];
          $ico     = $cardMap[$label]['ico'];
          $winRate = $levelWinRateData[$i] ?? null;

          // ⭐ SEO 組字（重點）
          $charName   = $char['name'];          // 史特靈
          $seoText    = $label . $charName;     // L5史特靈
          $seoEncoded = urlencode($seoText);    // URL safe

          $url = '/pages/analysis_character_card.php'
            . '?char_id=' . $cardId
            . '&char=' . $seoEncoded
            . '&days=' . (int)$metaDays
            . '&tab=event';

        ?>

          <?php
          $isActive = ($cardId === $charId);
          ?>

          <a href="<?= $url ?>" class="card-cta text-decoration-none <?= $isActive ? 'active' : '' ?>"
            title="<?= htmlspecialchars("{$seoText} 單卡分析") ?>">

            <img
              src="<?= IMG_BASE . htmlspecialchars($ico) ?>"
              alt="<?= htmlspecialchars("{$seoText} 卡片") ?>"
              class="card-cta-ico"
              loading="lazy">

            <div class="card-cta-level"><?= htmlspecialchars($label) ?></div>

          </a>

        <?php endforeach; ?>

      </div>

    </div>
  </div>
</div>