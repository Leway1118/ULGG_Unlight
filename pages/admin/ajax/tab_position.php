<?php

/**
 * 單卡分析｜定位分析（Position Analysis）
 *
 * 本頁目的：
 * 1️⃣ 分析此卡作為「前排 / 後排」時的實際勝率差異
 * 2️⃣ 判斷此卡是否適合作為隊伍「大將」（三卡中 COST 唯一最高）
 * 3️⃣ 分析此卡在不同「隊伍總 COST 區間」下的表現穩定度
 *
 * 所有數據僅統計「實際對戰結果」，並套用上方 days / cost 篩選條件。
 */

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../lib/_analysis_base.php';

$pdo = $db;
ini_set('display_errors', 1);
error_reporting(E_ALL);

/* ===============================
 * 參數
 * =============================== */
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

/* ===============================
 * COST 條件（安全處理 1=1）
 * =============================== */
$costCond = buildCostCondition($costKey);
$costWhere = '';
if (!empty($costCond['sql']) && trim($costCond['sql']) !== '1=1') {
  $costWhere = ' AND ' . $costCond['sql'];
}

/* ===============================
 * 取得此卡 COST（顯示用）
 * =============================== */
$stmt = $pdo->prepare("SELECT cost FROM unlight WHERE id = :cid LIMIT 1");
$stmt->execute([':cid' => $charId]);
$charCost = (int)$stmt->fetchColumn();

/* ======================================================
 * (1) 前排 / 後排 勝率
 * ====================================================== */
$sqlPos = "
SELECT
  SUM(m.leader_id = :cid) AS front_cnt,
  (SUM(back1_id = :cid) + SUM(back2_id = :cid)) AS back_cnt,

  SUM(leader_id = :cid AND is_win = 1) AS front_win,
  (SUM(back1_id = :cid AND is_win = 1)
   + SUM(back2_id = :cid AND is_win = 1)) AS back_win
FROM v_arena_player_match_result m
WHERE
  :cid IN (m.leader_id, m.back1_id, m.back2_id)
  AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
  {$costWhere}
";
$stmt = $pdo->prepare($sqlPos);
$stmt->execute([':cid' => $charId, ':days' => $days]);
$pos = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$frontCnt = (int)$pos['front_cnt'];
$backCnt  = (int)$pos['back_cnt'];
$frontWin = (int)$pos['front_win'];
$backWin  = (int)$pos['back_win'];

$frontWR = $frontCnt > 0 ? round($frontWin / $frontCnt * 100, 1) : 0;
$backWR  = $backCnt  > 0 ? round($backWin  / $backCnt  * 100, 1) : 0;

/* ======================================================
 * (2) 大將 / 非大將 勝率（唯一最高 COST）
 * ====================================================== */
$sqlAce = "
SELECT
  COUNT(*) AS ace_cnt,
  SUM(m.is_win = 1) AS ace_win
FROM v_arena_player_match_result m
JOIN unlight u1 ON u1.id = m.leader_id
JOIN unlight u2 ON u2.id = m.back1_id
JOIN unlight u3 ON u3.id = m.back2_id
WHERE
  :cid IN (m.leader_id, m.back1_id, m.back2_id)
  AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
  {$costWhere}
  AND (
    CASE
      WHEN :cid = m.leader_id THEN u1.cost
      WHEN :cid = m.back1_id  THEN u2.cost
      WHEN :cid = m.back2_id  THEN u3.cost
    END
  ) = GREATEST(u1.cost, u2.cost, u3.cost)
";
$stmt = $pdo->prepare($sqlAce);
$stmt->execute([
  ':cid'  => $charId,
  ':days' => $days
]);

$ace = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$aceCnt = (int)($ace['ace_cnt'] ?? 0);
$aceWin = (int)($ace['ace_win'] ?? 0);
$aceWR  = $aceCnt > 0 ? round($aceWin / $aceCnt * 100, 1) : 0.0;


/* 非大將 */
$sqlNonAce = "
SELECT
  COUNT(*) AS cnt,
  SUM(m.is_win = 1) AS win
FROM v_arena_player_match_result m
JOIN unlight u1 ON u1.id = m.leader_id
JOIN unlight u2 ON u2.id = m.back1_id
JOIN unlight u3 ON u3.id = m.back2_id
WHERE
  :cid IN (m.leader_id, m.back1_id, m.back2_id)
  AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
  {$costWhere}
  AND (
    CASE
      WHEN :cid = m.leader_id THEN u1.cost
      WHEN :cid = m.back1_id  THEN u2.cost
      WHEN :cid = m.back2_id  THEN u3.cost
    END
  ) < GREATEST(u1.cost, u2.cost, u3.cost)
";
$stmt = $pdo->prepare($sqlNonAce);
$stmt->execute([':cid' => $charId, ':days' => $days]);
$nonAce = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

$nonAceCnt = (int)$nonAce['cnt'];
$nonAceWin = (int)$nonAce['win'];
$nonAceWR  = $nonAceCnt > 0 ? round($nonAceWin / $nonAceCnt * 100, 1) : 0.0;



/* ======================================================
 * (3) COST 區間（隊伍總 COST）
 * ====================================================== */
$sqlBucket = "SELECT
  CASE
  WHEN m.cost BETWEEN 50 AND 59 THEN '50–59'
  WHEN m.cost BETWEEN 60 AND 69 THEN '60–69'
  WHEN m.cost BETWEEN 70 AND 80 THEN '70–80'
  ELSE '90+'
END AS bucket,
  COUNT(*) AS matches,
  SUM(m.is_win = 1) AS wins
FROM v_arena_player_match_result m
WHERE
  :cid IN (m.leader_id, m.back1_id, m.back2_id)
  AND m.update_time >= DATE_SUB(NOW(), INTERVAL :days DAY)
  {$costWhere}
GROUP BY bucket
ORDER BY FIELD(bucket, '50–59','60–69','70–80','90+');

";
$stmt = $pdo->prepare($sqlBucket);
$stmt->execute([':cid' => $charId, ':days' => $days]);
$buckets = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
 * Render
 * =============================== */
$costScores = [];

foreach ($buckets as $b) {
  $matches = (int)$b['matches'];
  $wins    = (int)$b['wins'];
  $wr      = $matches > 0 ? ($wins / $matches * 100) : 0;

  $score = $matches > 0
    ? round($wr * log10($matches + 1), 2)
    : 0;

  $costScores[] = [
    'bucket'  => $b['bucket'],
    'matches' => $matches,
    'wr'      => round($wr, 1),
    'score'   => $score,
  ];
}
if (abs($frontWR - $backWR) >= 1.5) {
  $bestPosition = $frontWR > $backWR ? '前排' : '後排';
} else {
  $bestPosition = '前後排皆可';
}
if ($aceCnt > 0 || $nonAceCnt > 0) {
  if (abs($aceWR - $nonAceWR) >= 1.5) {
    $bestRole = $aceWR > $nonAceWR ? '大將' : '非大將';
  } else {
    $bestRole = '大將與否皆可';
  }
} else {
  $bestRole = '資料不足';
}
$bestCostBucket = '未知';
$maxScore = -1;

foreach ($costScores as $c) {
  if ($c['score'] > $maxScore) {
    $maxScore = $c['score'];
    $bestCostBucket = $c['bucket'];
  }
}
$summaryText = sprintf(
  '適合擔任「%s」、作為「%s」，並在「%s COST」區間下表現最穩定。',
  $bestPosition,
  $bestRole,
  $bestCostBucket
);

?>

<div class="ul-card">
  <h5 class="card-title">📍 定位分析</h5>

  <div class="mb-2 fw-bold text-warning">
    👉 總結判斷：<?= htmlspecialchars($summaryText) ?>
  </div>

  <div class="text-muted small">
    本頁從「站位」、「隊伍核心程度」與「總 COST 結構」三個角度，
    協助判斷此卡在實戰中最穩定、最合理的定位。
    <br>※大將為隊伍中COST並列或最高；輔助定義為非大將。
  </div>
</div>


<!-- 圖表區：定位視覺化 -->
<!-- <div class="ul-card mt-3">
  <h5 class="card-title">📊 定位視覺化</h5>
</div> -->

<div class="row">

  <!-- ① 前後排定位 -->
  <div class="col-md-6 mb-3">
    <div class="ul-card h-100">
      <h6 class="card-title mb-2">① 前後排定位</h6>

      <div class="chart-area" style="height:220px;">
        <canvas id="chart-position-frontback"></canvas>
      </div>

      <div class="text-muted small mt-2">
        比較此角色作為前排（Leader）與後排（Back）時的實際勝率差異。
      </div>
    </div>
  </div>

  <!-- ② 大將定位 -->
  <div class="col-md-6 mb-3">
    <div class="ul-card h-100">
      <h6 class="card-title mb-2">② 大將定位</h6>

      <div class="chart-area" style="height:220px;">
        <canvas id="chart-position-ace"></canvas>
      </div>

      <div class="text-muted small mt-2">
        判斷此角色在隊伍中擔任最高或並列 COST 核心（大將）時的表現穩定度。
      </div>
    </div>
  </div>

</div>


<!-- ③ COST 結構 -->
<div class="ul-card mt-3">
  <h5 class="card-title">③ 隊伍總 COST 結構</h5>

  <div class="row">

    <!-- 左：勝率 / 出場數 -->
    <div class="col-md-6 mb-3">
      <div class="chart-area" style="height:260px;">
        <canvas id="chart-position-cost-usage"></canvas>
      </div>
      <div class="text-muted small mt-2">
        COST 區間 × 出場數 / 勝率
      </div>
    </div>

    <!-- 右：綜合表現 -->
    <div class="col-md-6 mb-3">
      <div class="chart-area" style="height:260px;">
        <canvas id="chart-position-cost-score"></canvas>
      </div>
      <div class="text-muted small mt-2">
        綜合表現（勝率 × 出場數穩定度）
      </div>
    </div>

  </div>

  <!-- 統一說明（與 character.php 一致） -->
  <div class="text-muted small mt-2">
    ※ 綜合表現 Score = 勝率 × LOG10(出場數 + 1)，
    用於衡量此角色在不同 COST 環境下的實戰穩定度。
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
            . '&tab=position';

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





<!-- 防呆 如果 base layout 沒有全域載 Chart.js，
 就會：Uncaught ReferenceError: Chart is not defined -->
<script>
  if (typeof Chart === 'undefined') {
    console.warn('[UL.GG] Chart.js not loaded');
  }
</script>



<!-- 前後排 -->
<script>
  (function() {
    const el = document.getElementById('chart-position-frontback');
    if (!el) return;

    const frontWR = <?= (float)$frontWR ?>;
    const backWR = <?= (float)$backWR ?>;
    const frontCnt = <?= (int)$frontCnt ?>;
    const backCnt = <?= (int)$backCnt ?>;

    const diff = Math.abs(frontWR - backWR);
    const DIFF_THRESHOLD = 1.5;

    const baseColor = '#265d87';
    const strongColor = '#f2c94c';

    let colors = [baseColor, baseColor];

    if (diff >= DIFF_THRESHOLD) {
      if (frontWR > backWR) {
        colors[0] = strongColor;
      } else {
        colors[1] = strongColor;
      }
    }

    new Chart(el.getContext('2d'), {
      type: 'bar',
      data: {
        labels: ['前排（Leader）', '後排（Back）'],
        datasets: [{
          label: '勝率 (%)',
          data: [frontWR, backWR],
          backgroundColor: colors,
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            min: 0,
            max: 100,
            ticks: {
              callback: v => v + '%',
              color: '#9aa4b2'
            }
          },
          x: {
            ticks: {
              color: '#cfd3dc'
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              afterLabel: ctx => {
                const isFront = ctx.dataIndex === 0;
                const cnt = isFront ? frontCnt : backCnt;

                return [
                  '樣本數：' + cnt,
                  diff >= DIFF_THRESHOLD ?
                  `差距：${diff.toFixed(1)}%` :
                  `差距 < ${DIFF_THRESHOLD}%`
                ];
              }
            }
          }
        }
      }
    });
  })();
</script>



<!-- 大將（左右對調：非大將 → 大將） -->
<script>
  (function() {
    const el = document.getElementById('chart-position-ace');
    if (!el) return;

    const aceCnt = <?= (int)$aceCnt ?>;
    const nonAceCnt = <?= (int)$nonAceCnt ?? 0 ?>;
    const aceWR = <?= (float)$aceWR ?>;
    const nonAceWR = <?= (float)$nonAceWR ?>;

    if (aceCnt === 0 && nonAceCnt === 0) return;

    const diff = Math.abs(aceWR - nonAceWR);
    const DIFF_THRESHOLD = 1.5;

    const baseColor = '#265d87';
    const strongColor = '#f2c94c';

    let colors = [baseColor, baseColor];

    if (diff >= DIFF_THRESHOLD) {
      if (aceWR > nonAceWR) {
        colors[1] = strongColor; // 右邊（大將）
      } else {
        colors[0] = strongColor; // 左邊（非大將）
      }
    }

    new Chart(el.getContext('2d'), {
      type: 'bar',
      data: {
        labels: ['非大將', '大將'],
        datasets: [{
          label: '勝率 (%)',
          data: [
            nonAceCnt > 0 ? nonAceWR : null,
            aceCnt > 0 ? aceWR : null
          ],
          backgroundColor: colors,
          borderWidth: 0
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
          y: {
            min: 0,
            max: 100,
            ticks: {
              callback: v => v + '%',
              color: '#9aa4b2'
            }
          },
          x: {
            ticks: {
              color: '#cfd3dc'
            }
          }
        },
        plugins: {
          tooltip: {
            callbacks: {
              afterLabel: ctx => {
                const cnt = ctx.dataIndex === 0 ? nonAceCnt : aceCnt;
                return [
                  '樣本數：' + cnt,
                  diff >= DIFF_THRESHOLD ?
                  `差距：${diff.toFixed(1)}%` :
                  `差距 < ${DIFF_THRESHOLD}%`
                ];
              }
            }
          }
        }
      }
    });
  })();
</script>






<!-- COST區間 -->
<script>
  (function() {
    const el = document.getElementById('chart-position-cost-usage');
    if (!el) return;

    const labels = <?= json_encode(array_column($costScores, 'bucket')) ?>;
    const usage = <?= json_encode(array_column($costScores, 'matches'), JSON_NUMERIC_CHECK) ?>;
    const rates = <?= json_encode(array_column($costScores, 'wr'), JSON_NUMERIC_CHECK) ?>;

    new Chart(el.getContext('2d'), {
      data: {
        labels,
        datasets: [{
            type: 'bar',
            label: '出場次數',
            data: usage,
            yAxisID: 'yCount',
            borderWidth: 1
          },
          {
            type: 'line',
            label: '勝率 (%)',
            data: rates,
            yAxisID: 'yRate',
            tension: 0.3,
            pointRadius: 4,
            spanGaps: true
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
          mode: 'index',
          intersect: false
        },
        scales: {
          yCount: {
            position: 'left',
            title: {
              display: true,
              text: '出場次數'
            }
          },
          yRate: {
            position: 'right',
            min: 0,
            max: 100,
            grid: {
              drawOnChartArea: false
            },
            title: {
              display: true,
              text: '勝率 (%)'
            }
          }
        }
      }
    });
  })();
</script>


<!-- COST區間 綜合表現 -->
<script>
  (function() {
    const canvas = document.getElementById('chart-position-cost-score');
    if (!canvas) {
      console.warn('[UL.GG] chart-position-cost-score canvas not found');
      return;
    }

    const ctx = canvas.getContext('2d');

    const costLabels = <?= json_encode(array_column($costScores, 'bucket')) ?>;
    const costScoreData = <?= json_encode(array_column($costScores, 'score'), JSON_NUMERIC_CHECK) ?>;

    if (!costScoreData.length) {
      console.warn('[UL.GG] COST score chart: no data');
      return;
    }

    // 找最大值（高亮用）
    const maxScore = Math.max(...costScoreData);

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: costLabels,
        datasets: [{
          label: '綜合表現 Score',
          data: costScoreData,
          tension: 0.3,
          borderWidth: 2,
          borderColor: '#8fa3ff',
          backgroundColor: 'rgba(143,163,255,0.15)',
          fill: true,

          pointRadius: costScoreData.map(v => v === maxScore ? 6 : 4),
          pointBackgroundColor: costScoreData.map(v =>
            v === maxScore ? '#f2c94c' : '#8fa3ff'
          ),
          pointBorderColor: '#151822',
          pointHoverRadius: 7
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            labels: {
              color: '#e6e9ef'
            }
          },
          tooltip: {
            callbacks: {
              label: ctx => `綜合分數：${ctx.raw}`
            }
          }
        },
        scales: {
          x: {
            ticks: {
              color: '#cfd3dc'
            },
            grid: {
              color: 'rgba(255,255,255,0.05)'
            }
          },
          y: {
            beginAtZero: true,
            ticks: {
              color: '#9aa4b2'
            },
            grid: {
              color: 'rgba(255,255,255,0.05)'
            },
            title: {
              display: true,
              text: '綜合表現 Score',
              color: '#9aa4b2'
            }
          }
        }
      }
    });
  })();
</script>