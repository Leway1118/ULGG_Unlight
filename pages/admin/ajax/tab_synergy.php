<!-- pages/admin/ajax/tab_synergy.php -->

<?php
// ===============================
// AJAX: 單卡分析｜最佳隊友
// ===============================

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../lib/_analysis_base.php';
$pdo = $db;
/* ini_set('display_errors', 1);
error_reporting(E_ALL); */
// -------------------------------
// 參數
// -------------------------------
$charId = (int)($_GET['char_id'] ?? 0);
$days   = (int)($_GET['days'] ?? 90);
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

// -------------------------------
// 單卡勝率（基準）
// -------------------------------
$stmt = $pdo->prepare("
  SELECT
    COUNT(*) AS total_cnt,
    SUM(is_win = 1) AS win_cnt
  FROM v_arena_player_match_result
  WHERE
    :cid IN (leader_id, back1_id, back2_id)
    AND update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
");
$stmt->execute([
  'cid'  => $charId,
  'days' => $days,
]);

$row = $stmt->fetch(PDO::FETCH_ASSOC);
$cardWinRate = ($row && $row['total_cnt'] > 0)
  ? round($row['win_cnt'] / $row['total_cnt'] * 100, 1)
  : 0.0;

// -------------------------------
// 資料查詢
// -------------------------------
$synergyList = getBestTeammates(
  $pdo,
  $charId,
  $days,
  $cardWinRate,
  $costCond['sql'],
  5,
  10
);

$bestComboList = getBestCombosIncludingCharacter(
  $pdo,
  $charId,
  $days,
  $cardWinRate,
  $costCond['sql'],
  5,
  5
);

$topPlayerList = getTopPlayersByCharacterWithBP(
  $pdo,
  $charId,
  $days,
  $cardWinRate,
  $costCond['sql'],
  10,
  10
);

?>

<div class="ul-card">
  <h5 class="card-title">
    最佳隊友（Top 5）
    <span class="text-muted small">｜近 <?= $metaDays ?> 天</span>
  </h5>

  <div class="table-responsive">
    <table class="table table-dark table-striped mb-0">
      <thead>
        <tr>
          <th>#</th>
          <th>角色</th>
          <th>綜合表現</th>
          <th>搭配勝率</th>
          <th>樣本數</th>
        </tr>
      </thead>
      <tbody>

        <?php foreach ($synergyList as $i => $row): ?>
          <?php
          $delta = null;
          $deltaClass = 'text-muted';
          $deltaSign  = '';
          $charSlug   = $row['char_id'] . '-' . urlencode($row['name']);
          ?>
          <tr>
            <td><?= $i + 1 ?></td>

            <td class="rank-player d-flex align-items-center gap-2">
              <img
                src="<?= IMG_BASE . $row['ico'] ?>"
                class="char-ico-sm"
                title="<?= htmlspecialchars($row['lv'] . ' ' . $row['name']) ?>">
              <a href="/pages/analysis_character_card.php?char_id=<?= $row['char_id'] ?>-<?= urlencode($row['name']) ?>&days=<?= (int)$days ?>&cost=<?= urlencode($costKey) ?>"
                class="text-decoration-none"
                style="color:inherit;">
                <?= htmlspecialchars($row['lv']) ?>
                <?= htmlspecialchars($row['name']) ?>
              </a>
            </td>

            <?php
            $score = $row['score'];
            ?>

            <td>
              <span>
                <?= $score !== null
                  ? '<strong>' . number_format($score, 1) . '</strong>'
                  : '<span class="text-muted">—</span>'
                ?>
              </span>
            </td>

            <td class="text-muted small">
              <?= number_format($row['win_rate'], 1) ?>%
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
    ※ 排名依「綜合分數」排序，
    綜合分數同時考量勝率提升幅度與出場樣本數，
    以避免少量對戰造成誤判。
  </div>

</div>
<div class="ul-card mt-3">
  <h5 class="card-title">
    🧩 最佳組合（排頭固定）
    <span class="text-muted small">｜近 <?= $metaDays ?> 天</span>
  </h5>

  <div class="table-responsive">
    <table class="table table-dark table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th>#</th>
          <th>組合角色</th>
          <th>綜合表現</th>
          <th>組合勝率</th>
          <th>樣本數</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bestComboList as $i => $row): ?>
          <?php
          $teamLink =
            '/pages/team_analysis.php'
            . '?id1=' . (int)$row['leader']['id']
            . '&id2=' . (int)$row['mates'][0]['id']
            . '&id3=' . (int)$row['mates'][1]['id']
            . '&from=synergy_combo';
          ?>

          <tr class="hof-clickable" data-link="<?= $teamLink ?>">
            <td><?= $i + 1 ?></td>

            <!-- 組合角色 -->
            <td>
              <div class="d-flex gap-2 align-items-center deck deck-clickable">

                <!-- 排頭角色 -->
                <img
                  src="<?= IMG_BASE . $row['leader']['ico'] ?>"
                  class="char-ico-sm border-warning"
                  title="<?= htmlspecialchars($row['leader']['lv'] . ' ' . $row['leader']['name']) ?>"
                  alt="<?= htmlspecialchars($row['leader']['name']) ?>">


                <span class="text-muted small">+</span>

                <!-- 後兩位（BC 無順序） -->
                <?php foreach ($row['mates'] as $c): ?>
                  <img
                    src="<?= IMG_BASE . $c['ico'] ?>"
                    class="char-ico-sm"
                    title="<?= htmlspecialchars($c['lv'] . ' ' . $c['name']) ?>"
                    alt="<?= htmlspecialchars($c['name']) ?>">
                <?php endforeach; ?>


              </div>
            </td>

            <!-- 綜合表現 -->
            <td>
              <span>
                <?= number_format($row['score'], 1) ?>
              </span>
            </td>

            <!-- 組合勝率 -->
            <td class="text-muted small"><?= number_format($row['win_rate'], 1) ?>%</td>



            <!-- 樣本數 -->
            <td><?= number_format($row['matches']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>

    </table>
  </div>

  <div class="text-muted small mt-2">
    ⚠️ 本區僅顯示「固定三人組合」達樣本門檻者，
    即使為最佳隊友，亦不一定形成穩定組合。<br>
    組合判定以<strong>隊伍角色構成</strong>為準：<strong>排頭角色不同</strong>視為不同組合；
    <strong>後排兩名角色不分站位</strong>，即使交換位置亦視為同一組合。<br>
  </div>

</div>

<div class="ul-card mt-3">
  <h5 class="card-title">
    🏅 TOP 10 玩家（加權表現）
    <span class="text-muted small">｜近 <?= $metaDays ?> 天</span>
  </h5>

  <div class="table-responsive">
    <table class="table table-dark table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th>#</th>
          <th>玩家</th>
          <th>BP 排名</th>
          <th>綜合表現</th>
          <th>對戰勝率</th>
          <th>樣本數</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($topPlayerList as $i => $row):
          $url = buildUrl('/pages/fight.php', [
            'player_name' => $row['player'],
            'days' => $metaDays,
          ]); ?>
          <tr>
            <td><?= $i + 1 ?></td>

            <td class="fw-bold">
              <a
                href="<?= $url ?>"
                class="text-decoration-none text-light"
                title="查看 <?= htmlspecialchars($row['player']) ?> 的對戰紀錄">
                <?= htmlspecialchars($row['player']) ?>
              </a>
            </td>


            <td class="text-muted small">
              <?php if ($row['bp_rank'] !== null): ?>
                <span class="d-inline-flex align-items-center gap-1">
                  <?= regionIcon($row['region']) ?>
                  <span>#<?= (int)$row['bp_rank'] ?></span>
                </span>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>


            <td class="fw-bold text-warning">
              <?= number_format($row['score'], 1) ?>
            </td>

            <td class="text-muted small">
              <?= number_format($row['win_rate'], 1) ?>%
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
    ※ 排名依「綜合表現分數」排序，
    綜合分數同時考量 <strong>對戰勝率</strong> 與 <strong>樣本數</strong>，
    並對低樣本進行加權修正，以避免偶發場次造成誤判。
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
            . '&tab=synergy';

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
  document.addEventListener('click', function(e) {
    const tr = e.target.closest('tr.hof-clickable');
    if (!tr) return;

    // 點到 a / button 不攔截（保留單卡分析）
    if (e.target.closest('a, button')) return;

    const link = tr.dataset.link;
    if (link) {
      window.location.href = link;
    }
  });
</script>