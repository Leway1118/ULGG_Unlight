<?php
// ⚠️ 不要 session_start()，交給 config.php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/_analysis_base.php';
// ⭐ 全站常數 define('IMG_BASE', '/assets/uploads/');
$pdo = $db;

$pageTitleText = '最強牌組排名｜強度排行';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "strength_rank";

ob_start();


?>

<style>
  /* ================================
   Strength Rank – UL.GG Style
================================ */

  .str-block {
    background: #141414;
    border: 1px solid #1f1f1f;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 24px;
  }

  .str-title {
    font-size: 1.8rem;
    font-weight: 800;
    margin-bottom: 6px;
  }

  .str-desc {
    color: #9aa4b2;
    font-size: 13px;
    margin-bottom: 16px;
  }

  /* 表格 */
  .str-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
  }

  .str-table th,
  .str-table td {
    border: 1px solid #222;
    padding: 12px;
    vertical-align: top;
  }

  .str-table th {
    background: #0f172a;
    text-align: center;
    font-weight: 700;
    font-size: 13px;
    color: #e5e7eb;
  }

  .str-row-title {
    background: #0b1220;
    font-weight: 700;
    text-align: center;
    white-space: nowrap;
  }

  /* 牌組卡 */
  .deck-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .deck-card {
    background: rgba(15, 23, 42, .65);
    border-radius: 10px;
    padding: 8px 10px;
    font-size: 13px;
    border: 1px solid rgba(255, 255, 255, .05);
  }

  .deck-name {
    font-weight: 700;
    margin-bottom: 2px;
  }

  .deck-meta {
    font-size: 12px;
    color: #9aa4b2;
  }

  /* 響應式 */
  @media (max-width: 768px) {

    .str-table th,
    .str-table td {
      padding: 8px;
      font-size: 12px;
    }
  }

  /* ================================
   RWD – Tablet
================================ */
  @media (max-width: 1199px) {
    .str-title {
      font-size: 1.6rem;
    }

    .deck-card {
      padding: 6px 8px;
    }

    .deck-name {
      font-size: 13px;
    }

    .deck-meta {
      font-size: 11px;
    }
  }

  /* ================================
   RWD – Mobile (Table → Card)
================================ */
  @media (max-width: 991px) {

    /* 隱藏表頭 */
    .str-table thead {
      display: none;
    }

    .str-table,
    .str-table tbody,
    .str-table tr,
    .str-table td {
      display: block;
      width: 100%;
    }

    .str-table tr {
      margin-bottom: 20px;
      border: 1px solid #222;
      border-radius: 12px;
      overflow: hidden;
      background: #0b1220;
    }

    /* BP 區間標題 */
    .str-row-title {
      background: #020617;
      padding: 10px;
      font-size: 14px;
      text-align: left;
    }

    /* 每個勝率區塊 */
    .str-table td:not(.str-row-title) {
      padding: 10px;
      border: none;
      border-top: 1px solid #1f2937;
    }

    /* 勝率標籤 */
    .str-table td:not(.str-row-title)::before {
      content: attr(data-label);
      display: block;
      font-size: 12px;
      font-weight: 700;
      color: #93c5fd;
      margin-bottom: 6px;
    }

    .deck-list {
      gap: 6px;
    }

    .deck-card {
      background: rgba(15, 23, 42, .8);
    }


  }

  /* Tier Badge */
  .tier-badge {
    display: inline-block;
    padding: 2px 6px;
    font-size: 11px;
    font-weight: 800;
    border-radius: 6px;
    margin-right: 6px;
  }

  .tier-s {
    background: #dc2626;
    color: #fff;
  }

  .tier-a {
    background: #f59e0b;
    color: #111;
  }

  .tier-b {
    background: #2563eb;
    color: #fff;
  }

  .tier-c {
    background: #6b7280;
    color: #fff;
  }

  .deck-link {
    display: block;
    text-decoration: none;
    color: inherit;
    transition: transform .12s ease, box-shadow .12s ease;
  }

  .deck-link:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(0, 0, 0, .4);
  }

  .bp-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
  }

  .bp-tab {
    padding: 6px 14px;
    border-radius: 999px;
    background: #1f2937;
    color: #9ca3af;
    border: none;
    font-weight: 700;
    cursor: pointer;
  }

  .bp-tab.active {
    background: #2563eb;
    color: #fff;
  }

  /* 角色 ICO 列 */
  .deck-chars {
    display: flex;
    gap: 6px;
    margin-bottom: 4px;
  }

  .char-ico {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    background: #020617;
    border: 1px solid rgba(255, 255, 255, .15);
    object-fit: cover;
  }

  /* Mobile 微調 */
  @media (max-width: 991px) {
    .char-ico {
      width: 28px;
      height: 28px;
    }
  }

  /* ================================
   Deck Character Card Stack (70x70)
================================ */

  /* 容器 */
  .deck-chars {
    position: relative;
    height: 70px;
    margin-bottom: 6px;
  }

  /* 單張卡 */
  .char-card {
    position: absolute;
    top: 0;
    width: 70px;
    height: 70px;
    overflow: hidden;
    /* ⭐ 裁切 */
    border-radius: 9px;
    background: #020617;
    border: 1px solid rgba(255, 255, 255, .15);
    transition: transform .12s ease;
  }

  /* ⭐ 只顯示圖片上半部 */
  .char-card img {
    width: 100%;
    height: 120%;
    /* 放大，確保臉部 */
    object-fit: cover;
    object-position: top;
    /* 永遠取上方 */
  }

  /* ================================
   疊卡邏輯（右卡 → 往左疊 20%）
   70 × 0.8 = 56px
================================ */

  /* 最左（底層） */
  .char-card.c0 {
    left: 0;
    z-index: 1;
  }

  /* 中間 */
  .char-card.c1 {
    left: 56px;
    z-index: 2;
  }

  /* 最右（最上層） */
  .char-card.c2 {
    left: 112px;
    z-index: 3;
  }

  /* Hover 微浮起 */
  .deck-link:hover .char-card {
    transform: translateY(-2px);
  }

  /* ================================
   Mobile 微調 (56x56)
================================ */
  @media (max-width: 991px) {

    .deck-chars {
      height: 56px;
    }

    .char-card {
      width: 56px;
      height: 56px;
      border-radius: 7px;
    }

    .char-card img {
      height: 125%;
    }

    /* 56 × 0.8 = 44.8 ≈ 45 */
    .char-card.c1 {
      left: 45px;
    }

    .char-card.c2 {
      left: 90px;
    }
  }
</style>
<?php
$labels = ['50–59', '60–69', '70–80', '90+'];

$bpList = [
  1700 => '1700 區間以上',
  1600 => '1600 區間',
  1500 => '1500 區間',
  1400 => '1400 區間',
];

// ⭐ 天數切換（7/14 / 30）
$days = isset($_GET['days']) && in_array((int)$_GET['days'], [7, 14, 30], true)
  ? (int)$_GET['days']
  : 7;

$costMap = [
  '50–59' => 'C1',
  '60–69' => 'C2',
  '70–80' => 'C3',
  '90+'   => 'C4',
];

/**
 * 預先撈好所有資料：
 * $data[bp][label] = teams[]
 */
$data = [];

foreach ($bpList as $bpMin => $bpTitle) {
  foreach ($labels as $label) {

    $costKey = $costMap[$label];
    $costDef = buildCostCondition($costKey);

    $data[$bpMin][$label] = getTopTeamBuildsByBpAndCost(
      $pdo,
      $bpMin,
      $days,
      $costDef['sql'],
      3,   // 每格 3 組
    );
  }
}
?>
<!-- Content Wrapper -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">

      <div class="row">
        <div class="col-md-12">
          <div class="str-block">
            <div class="str-title">最強牌組排名｜強度排行</div>
            <div class="str-desc">
              依 BP 區間 × Cost 區間，列出各區間
              <strong><?= $days ?></strong> 天內表現最穩定的牌組
            </div>

            <div class="bp-tabs" style="margin-bottom:12px">
              <button
                class="bp-tab <?= $days === 7 ? 'active' : '' ?>"
                onclick="switchDays(7)">
                7 天
              </button>

              <button
                class="bp-tab <?= $days === 14 ? 'active' : '' ?>"
                onclick="switchDays(14)">
                14 天
              </button>

              <button
                class="bp-tab <?= $days === 30 ? 'active' : '' ?>"
                onclick="switchDays(30)">
                30 天
              </button>
            </div>


            <div class="bp-tabs">
              <button class="bp-tab active" data-bp="ALL">全部</button>
              <button class="bp-tab" data-bp="1700">1700</button>
              <button class="bp-tab" data-bp="1600">1600</button>
              <button class="bp-tab" data-bp="1500">1500</button>
              <button class="bp-tab" data-bp="1400">1400</button>
            </div>

            <table class="str-table">
              <thead>
                <tr>
                  <th>BP - Cost</th>
                  <th>50–59</th>
                  <th>60–69</th>
                  <th>70–80</th>
                  <th>90+</th>
                </tr>
              </thead>


              <tbody>

                <?php foreach ($bpList as $bpMin => $bpTitle): ?>
                  <tr data-bp-row="<?= $bpMin ?>">
                    <td class="str-row-title"><?= $bpTitle ?></td>

                    <?php foreach ($labels as $label): ?>
                      <td data-label="<?= $label ?>">
                        <div class="deck-list">

                          <?php
                          // 只保留 勝率 > 50% 的牌組
                          $teams = array_filter(
                            $data[$bpMin][$label],
                            fn($t) => isset($t['win_rate']) && (float)$t['win_rate'] > 50
                          );
                          ?><?php if (empty($teams)): ?>

                          <div class="deck-card">
                            <div class="deck-meta">
                              樣本不足
                            </div>
                          </div>

                        <?php else: ?>

                          <?php foreach ($teams as $team): ?>
                            <?php
                                [$tierText, $tierClass] = tierByScore(
                                  $team['score'],
                                  $team['matches'],
                                  $bpMin
                                );
                            ?>
                            <a
                              href="/pages/team_analysis.php?id1=<?= (int)$team['chars'][0]['id'] ?>&id2=<?= (int)$team['chars'][1]['id'] ?>&id3=<?= (int)$team['chars'][2]['id'] ?>"
                              class="deck-card deck-link">

                              <div class="deck-chars">
                                <?php foreach ($team['chars'] as $i => $ch): ?>
                                  <?php
                                  $icoFile = !empty($ch['ico'])
                                    ? IMG_BASE . $ch['ico']
                                    : IMG_BASE . 'ico/unknown.png';
                                  ?>
                                  <div class="char-card c<?= $i ?>">
                                    <img
                                      src="<?= htmlspecialchars($icoFile) ?>"
                                      alt="<?= htmlspecialchars($ch['name']) ?>">
                                  </div>
                                <?php endforeach; ?>
                              </div>

                              <div class="deck-name">
                                <span class="tier-badge <?= $tierClass ?>"><?= $tierText ?></span>
                                <?= implode(' / ', array_map(
                                  fn($c) => trim(($c['lv'] ?? '') . ' ' . $c['name']),
                                  $team['chars']
                                )) ?>
                              </div>

                              <div class="deck-meta">
                                勝率 <?= $team['win_rate'] ?>% · <?= $team['matches'] ?> 場
                              </div>
                            </a>
                          <?php endforeach; ?>

                        <?php endif; ?>


                        </div>
                      </td>
                    <?php endforeach; ?>

                  </tr>
                <?php endforeach; ?>

              </tbody>


            </table>

          </div>
        </div>
      </div>

    </div>
  </section>
</div>

<script>
  document.querySelectorAll('.bp-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      const bp = btn.dataset.bp;

      document.querySelectorAll('.bp-tab')
        .forEach(b => b.classList.remove('active'));
      btn.classList.add('active');

      document.querySelectorAll('[data-bp-row]')
        .forEach(row => {
          row.style.display =
            (bp === 'ALL' || row.dataset.bpRow === bp) ? '' : 'none';
        });
    });
  });
</script>

<script>
  function switchDays(days) {
    const url = new URL(window.location.href);
    url.searchParams.set('days', days);
    window.location.href = url.toString();
  }
</script>
<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>