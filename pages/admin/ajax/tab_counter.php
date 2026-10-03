<!-- /**
 * tab_counter.php
 *
 * 單卡分析｜剋星 / 對位分析模組（AJAX 載入）
 *
 * 使用資料來源：
 * - arena_unlight（歷史對戰結果）
 * - v_arena_player_match_result（正規化對戰視圖）
 *
 * 分析邏輯說明：
 * 1. 當指定角色（char_id）出現在「敵方」時：
 *    - 統計敵方角色（u1/u2/u3）的勝負結果（不反轉）
 *
 * 2. 當指定角色（char_id）出現在「我方」時：
 *    - 統計敵方角色（e1/e2/e3），並反轉勝負（lose → win）
 *
 * 3. 所有統計僅計算「已確認結果」的對戰：
 *    - 條件：(win + lose + tie = 1)
 *
 * 4. 分析期間由 GET 參數 days 控制（允許值：14 / 30 / 90）：
 *    - 若未提供或不合法，預設使用 90 天
 *
 * 5. 剋星排序依據：
 *    - 敵方勝率 × 樣本數加權（LOG10 + 樣本比例修正）
 *
 * 注意事項：
 * - 本檔案為 AJAX 載入頁，所有跳轉連結需「正確傳遞 days」
 * - URL 中 `&days=` 前不可有空白，否則分析期間會失效
 */
 -->
<style>
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
</style>
<?php
/* ini_set('display_errors', 1);
error_reporting(E_ALL); */

session_start();
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../lib/_analysis_base.php';
$pdo = $db;

$charId = (int)($_GET['char_id'] ?? 0);
$days   = (int)($_GET['days'] ?? 90);

if ($charId <= 0) {
  echo '<div class="text-danger small">參數錯誤</div>';
  exit;
}
$charParam = $_GET['char'] ?? '';
$charName = '';

if (preg_match('/^(\d+)(?:-(.+))?$/', $charParam, $m)) {
  $charName = urldecode($m[2] ?? '');
}

/* ⏱ debug timer */
$__t0 = microtime(true);




$costRanges = [
  'ALL' => null,
  'C1'  => [50, 60],
  'C2'  => [61, 70],
  'C3'  => [71, 80],
  'C4'  => [90, 999],
];

$costKey = $_GET['cost'] ?? 'ALL';
$costRange = $costRanges[$costKey] ?? null;


// ===============================
// META 分析範圍設定（統一使用 days）
// ===============================
$days = (int)($_GET['days'] ?? 90);
if (!in_array($days, [14, 30, 90], true)) {
  $days = 90;
}

$metaDays = $days; // ⭐⭐⭐ 關鍵這行

echo "<!-- DEBUG tab_counter days={$metaDays} -->";
// SQL 時間條件
$sqlMetaWhere = '';
$sqlMetaParam = [];

if ($metaDays !== null) {
  $sqlMetaWhere = ' AND update_time >= NOW() - INTERVAL ? DAY ';
  $sqlMetaParam[] = $metaDays;
}


$t1 = microtime(true);
$counterList = getCounterCharacters(
  $pdo,
  $charId,
  $metaDays,
  $costRanges[$costKey] ?? null
);
$ms1 = round((microtime(true) - $t1) * 1000, 1);
echo "<!-- ⏱ getCounterCharacters {$ms1} ms -->";


$t2 = microtime(true);
$worstAgainstComboList = getWorstEnemyCombos(
  $pdo,
  $charId,
  $metaDays,
  $costRanges[$costKey] ?? null
);
$ms2 = round((microtime(true) - $t2) * 1000, 1);
echo "<!-- ⏱ getWorstEnemyCombos {$ms2} ms -->";


?>

<div class="ul-card">
  <h5 class="card-title">
    剋星角色（Top 5）
    <span class="text-muted small">｜近 <?= $metaDays ?> 天</span>
  </h5>

  <div class="table-responsive">
    <table class="table table-dark table-striped mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>角色</th>
          <th>剋制表現</th>
          <th>敵方勝率</th>
          <th>樣本數</th>
        </tr>
      </thead>
      <tbody>

        <?php foreach ($counterList as $i => $row): ?>
          <?php
          $enemyWin = $row['enemy_win'];   // 敵方勝率
          $matches  = $row['matches'];

          // 信心係數（防止小樣本）
          $confidence = min(1, $matches / 30);

          // 綜合分數
          $score = round(
            $enemyWin * log10($matches + 1) * $confidence,
            2
          );

          // 警告框條件（真剋星）
          $dangerBorder = ($enemyWin >= 60 && $matches >= 20)
            ? 'border-warning'
            : '';

          $charSlug = $row['char_id'] . '-' . urlencode($row['name']);
          ?>
          <tr>
            <td><?= $i + 1 ?></td>

            <td>
              <a
                href="/pages/analysis_character_card.php?char_id=<?= $charSlug ?>&days=<?= (int)$metaDays ?>"
                class="rank-player d-flex align-items-center gap-2 text-decoration-none"
                style="color:inherit;"
                title="<?= htmlspecialchars($row['level'] . ' ' . $row['name']) ?>">
                <img
                  src="<?= IMG_BASE . $row['ico'] ?>"
                  class="char-ico-sm <?= $dangerBorder ?>"
                  alt="<?= htmlspecialchars($row['name']) ?>">
                <span><?= htmlspecialchars($row['level'] . ' ' . $row['name']) ?></span>
              </a>
            </td>
            <td class="fw-bold text-warning">
              <?= number_format($row['score'], 2) ?>
            </td>

            <td>
              <?= number_format($row['enemy_win'], 1) ?>%
            </td>

            <td>
              <?= number_format($row['matches']) ?>
            </td>

          </tr>
        <?php endforeach; ?>

      </tbody>
    </table>
  </div>

  <div class="text-muted small mt-2">
    ※ 本表列出「對該單卡威脅最高」的敵方角色。<br>
    剋制表現係依據「敵方對戰勝率 × 對戰樣本數權重」計算。<br>
    本排行僅統計<strong>對戰隊伍中不含自身角色</strong>的對局，以避免鏡像或雙邊同卡影響結果。
  </div>

</div>

<div class="ul-card mt-3">
  <h5 class="card-title">
    ⚠️ 高風險對手組合
    <span class="text-muted small">｜近 <?= $metaDays ?> 天</span>
  </h5>

  <div class="table-responsive">
    <table class="table table-dark table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th>#</th>
          <th>敵方組合</th>
          <th>剋制表現</th>
          <th>敵方勝率</th>
          <th>樣本數</th>
        </tr>
      </thead>
      <tbody>

        <?php foreach ($worstAgainstComboList as $i => $row): ?>
          <tr>
            <td><?= $i + 1 ?></td>

            <td>
              <?php
              $teamLink =
                '/pages/team_analysis.php'
                . '?id1=' . (int)$row['leader']['id']
                . '&id2=' . (int)$row['mates'][0]['id']
                . '&id3=' . (int)$row['mates'][1]['id']
                . '&from=counter_combo';
              ?>

              <div
                class="deck-clickable d-flex gap-2 align-items-center"
                data-link="<?= htmlspecialchars($teamLink) ?>">
                <img
                  src="<?= IMG_BASE . $row['leader']['ico'] ?>"
                  class="char-ico-sm border-warning"
                  alt="<?= htmlspecialchars($row['leader']['name']) ?>"
                  title="<?= htmlspecialchars($row['leader']['lv'] . ' ' . $row['leader']['name']) ?>">

                <span class="text-muted small">+</span>

                <?php foreach ($row['mates'] as $c): ?>
                  <img
                    src="<?= IMG_BASE . $c['ico'] ?>"
                    class="char-ico-sm"
                    alt="<?= htmlspecialchars($c['name']) ?>"
                    title="<?= htmlspecialchars(trim(($c['lv'] ?? '') . ' ' . $c['name'])) ?>">
                <?php endforeach; ?>
              </div>
            </td>


            <td class="fw-bold text-warning">
              <?= number_format($row['risk_score'] ?? $row['score'] ?? 0, 2) ?>
            </td>

            <td>
              <?= number_format($row['enemy_win_rate'] ?? $row['enemy_win'] ?? 0, 1) ?>%
            </td>

            <td>
              <?= number_format($row['matches']) ?>
            </td>

          </tr>
        <?php endforeach; ?>

      </tbody>
    </table>
  </div>

  <div class="text-muted small mt-2">
    ※ 本表列出「對該單卡威脅最高」的敵方角色組合。<br>
    本排行僅統計<strong>對戰隊伍中不含自身角色</strong>的對局，以避免鏡像或雙邊同卡影響結果。<br>
    組合判定以<strong>隊伍角色構成</strong>為準：<strong>排頭角色不同</strong>視為不同組合；
    <strong>後排兩名角色不分站位</strong>，即使交換位置亦視為同一組合。<br>
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
            . '&tab=counter';

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

<script>
  document.getElementById('caDays')?.addEventListener('change', function() {
    const url = new URL(window.location.href);
    url.searchParams.set('days', this.value);
    window.location.href = url.toString();
  });
</script>
<script>
  document.addEventListener('click', function (e) {
    const deck = e.target.closest('.deck-clickable');
    if (!deck) return;

    const link = deck.dataset.link;
    if (link) {
      window.open(link, '_blank');
    }
  });
</script>
