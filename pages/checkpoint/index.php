<?php
/* define('IMG_BASE', '/assets/uploads/');
define('APP_ROOT', __DIR__); */
session_start();
require_once __DIR__ . '/../../config.php';   // ⭐ 必須包含資料庫設定
$pdo = $db; // ⭐ 一定要這行
require_once __DIR__ . '/../../lib/checkpoint_data.php';
$stages = require __DIR__ . '/../../lib/stage_map.php';
$year = 2025;
$username = $_SESSION['username'] ?? '';
$checkpoint = getCheckpointData($pdo, $username, $year);
/* require_once __DIR__ . '/../lib/_analysis_base.php'; */ // ⭐ 必須包含時間區間設定
/* require_once __DIR__ . '/_admin_gate.php'; */ //確認是否為管理員
// ⭐ 全站常數 define('IMG_BASE', '/assets/uploads/');

$pageTitleText = 'Yearly Checkpoint';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = '';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網'; //桌機

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* =========================================================
   Checkpoint – Center Layout (BS3 Friendly)
========================================================= */

  /* 外層 Slider：水平置中 */
  .checkpoint-slider {
    width: 100%;
    display: flex;
    justify-content: center;
  }

  /* Track：只負責橫向滑動 */
  .checkpoint-track {
    display: flex;
    width: 100%;
  }

  /* 每一頁：畫面主體 */
  .checkpoint-page {
    flex: 0 0 100%;
    max-width: 100%;
    padding: 10px 0;

    display: flex;
    justify-content: center;
  }



  /* 標題與文字統一置中 */
  .checkpoint-page h1,
  .checkpoint-page h2,
  .checkpoint-page p {
    text-align: center;
  }

  /* =========================================================
   Bottom Dots Navigation
========================================================= */

  .cp-dots {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 10px;
    z-index: 999;
  }

  .cp-dots .dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.25);
    cursor: pointer;
    transition: all 0.25s ease;
  }

  .cp-dots .dot:hover {
    background: rgba(255, 255, 255, 0.5);
  }

  .cp-dots .dot.active {
    width: 12px;
    height: 12px;
    background: #22c55e;
    /* UL.GG 綠 */
  }

  /* =========================================================
   Slider Viewport Fix
========================================================= */

  .checkpoint-slider {
    overflow: hidden;
    /* ⭐ 關鍵：裁切右側頁面 */
    position: relative;
  }

  /* =========================================================
   Arrow Navigation
========================================================= */

  .cp-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 20;

    width: 44px;
    height: 44px;
    border-radius: 50%;

    background: rgba(15, 23, 42, 0.75);
    border: 1px solid rgba(255, 255, 255, .15);
    color: #fff;

    font-size: 28px;
    line-height: 42px;
    text-align: center;

    cursor: pointer;
    transition: all .2s ease;
  }

  .cp-arrow:hover {
    background: rgba(34, 197, 94, 0.85);
    /* UL.GG 綠 */
    border-color: rgba(34, 197, 94, 0.9);
  }

  .cp-arrow-left {
    left: 12px;
  }

  .cp-arrow-right {
    right: 12px;
  }

  /* 第一頁 / 最後一頁可隱藏 */
  .cp-arrow.disabled {
    opacity: 0.25;
    pointer-events: none;
  }

  /* =========================================================
   Hide Arrow Navigation on Mobile
========================================================= */

  @media (max-width: 768px) {
    .cp-arrow {
      display: none !important;
    }
  }

  /* =========================================================
   Story-like Card Height
========================================================= */

  .cp-card {
    width: 100%;
    max-width: 900px;

    /* 高度仍保留沉浸感 */
    min-height: 83vh;

    /* 版面關鍵 */
    display: flex;
    flex-direction: column;
    justify-content: flex-start;
    /* ✅ 關鍵 */

    /* 上方呼吸感自己掌控 */
    padding: 80px 32px 32px;
    /* ⬅ 上方略小，其餘正常 */

    background:
      radial-gradient(1200px 600px at top center, rgba(34, 197, 94, .06), transparent 60%),
      linear-gradient(180deg, rgba(15, 23, 42, .96), rgba(15, 23, 42, .92));

    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, .08);
  }


  /* =========================================================
   Hide Page Header for Checkpoint Page
========================================================= */

  .content-header {
    display: none;
  }

  .char-ico-square {
    width: 96px;
    height: 96px;
    object-fit: cover;
    object-position: top;
    /* ⭐ 重點：保留頭部 */
    border-radius: 8px;
    border: 1px solid rgba(255, 255, 255, .15);
  }

  .char-ico-full {
    width: 100%;
    max-width: 110px;
    height: auto;
    border-radius: 10px;
    border: 1px solid rgba(255, 255, 255, .15);
  }

  /* P3｜隊伍卡片 */
  .team-card {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.02));
    border-radius: 14px;
    border: 1px solid rgba(255, 255, 255, .12);
    box-shadow: 0 6px 18px rgba(0, 0, 0, .35);
    transition: transform .25s ease, box-shadow .25s ease;
  }

  .team-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 26px rgba(0, 0, 0, .5);
  }

  .team-lineup {
    display: flex;
    justify-content: center;
    align-items: flex-end;
    gap: 10px;
  }

  /* 
  .team-lineup .col-xs-4:first-child img {
    width: 96px !important;
    height: 96px !important;
    border: 2px solid #22c55e;
    box-shadow: 0 0 12px rgba(34, 197, 94, .45);
  } */

  .team-lineup .small {
    font-size: 12px;
    opacity: .85;
    margin-top: 4px;
  }

  .team-meta {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px dashed rgba(255, 255, 255, .15);
    font-size: 13px;
  }

  /* =========================================================
   Checkpoint Global Page Animation
   每一頁：標題先出 → 內容再出
========================================================= */

  /* 初始狀態（尚未 active） */
  .checkpoint-page h1,
  .checkpoint-page h2,
  .checkpoint-page .cp-animate {
    opacity: 0;
    transform: translateY(14px);
  }

  /* 標題動畫 */
  .checkpoint-page h1,
  .checkpoint-page h2 {
    transition:
      opacity 2s ease,
      transform 1s ease;
    transition-delay: 0.3s;
  }

  /* 內容動畫（稍晚） */
  .checkpoint-page .cp-animate {
    transition:
      opacity 2s ease,
      transform 2s ease;
    transition-delay: 1.5s;
  }

  /* 進場狀態 */
  .checkpoint-page.active h1,
  .checkpoint-page.active h2,
  .checkpoint-page.active .cp-animate {
    opacity: 1;
    transform: translateY(0);
  }

  /* =========================================================
   Checkpoint Visual Enhancement (UL.GG Style)
========================================================= */

  /* 整體卡片質感提升 */
  .cp-card {
    background:
      radial-gradient(1200px 600px at top center, rgba(34, 197, 94, .06), transparent 60%),
      linear-gradient(180deg, rgba(15, 23, 42, .96), rgba(15, 23, 42, .92));
    box-shadow:
      0 20px 60px rgba(0, 0, 0, .55),
      inset 0 1px 0 rgba(255, 255, 255, .04);
    position: relative;
    overflow: hidden;
  }

  /* 卡片邊緣微光 */
  .cp-card::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 16px;
    pointer-events: none;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .05);
  }

  /* 標題層次 */
  .checkpoint-page h1 {
    font-weight: 800;
    letter-spacing: .04em;
    text-shadow: 0 4px 24px rgba(34, 197, 94, .25);
  }

  .checkpoint-page h2 {
    font-weight: 700;
    letter-spacing: .02em;
    margin-bottom: 24px;
  }

  /* =========================================
   小卡（數據、角色、對手）統一升級
========================================= */

  .cp-card .border {
    background: rgba(255, 255, 255, .03);
    border: 1px solid rgba(255, 255, 255, .08) !important;
    border-radius: 14px;
    box-shadow:
      0 6px 18px rgba(0, 0, 0, .35),
      inset 0 1px 0 rgba(255, 255, 255, .04);
    transition: transform .25s ease, box-shadow .25s ease;
    padding: 5px;
    margin: 5px;
  }

  .cp-card .border:hover {
    transform: translateY(-3px);
    box-shadow:
      0 12px 32px rgba(0, 0, 0, .55),
      inset 0 1px 0 rgba(255, 255, 255, .06);
  }

  /* =========================================
   角色卡片視覺加強
========================================= */

  .char-ico-square,
  .char-ico-full {
    background: #0b1220;
    box-shadow:
      0 8px 24px rgba(0, 0, 0, .55),
      0 0 0 1px rgba(255, 255, 255, .08);
    transition: transform .25s ease, box-shadow .25s ease;
  }

  .char-ico-square:hover,
  .char-ico-full:hover {
    transform: scale(1.04);
    box-shadow:
      0 14px 36px rgba(0, 0, 0, .75),
      0 0 0 1px rgba(34, 197, 94, .35);
  }

  /* =========================================
   P3 隊伍卡：更像「王牌組合」
========================================= */

  .team-card {
    position: relative;
  }

  .team-card::after {
    /* content: "MOST TRUSTED"; */
    position: absolute;
    top: 10px;
    right: 14px;
    font-size: 10px;
    letter-spacing: .12em;
    color: rgba(34, 197, 94, .85);
    opacity: .6;
  }

  /* =========================================
   P4 對手卡：溫度感
========================================= */

  #cp-page-4 .border {
    background:
      linear-gradient(180deg, rgba(255, 255, 255, .04), rgba(255, 255, 255, .02));
  }

  #cp-page-4 .h4 {
    font-weight: 700;
  }

  /* =========================================
   P6 年度總覽：證書感
========================================= */

  .checkpoint-summary {
    background:
      linear-gradient(180deg, rgba(255, 255, 255, .04), rgba(255, 255, 255, .02));
    border-radius: 16px;
    padding: 20px;
    box-shadow:
      inset 0 0 0 1px rgba(255, 255, 255, .06),
      0 12px 32px rgba(0, 0, 0, .45);
    max-width: 400px;
    /* ⭐ 限制寬度 */
    margin: 0 auto;
    /* ⭐ 水平置中 */
  }

  .summary-row {
    background: rgba(15, 23, 42, .6);

    padding: 5px 16px;
    /* ⭐ 上下間距關鍵 */
    margin-bottom: 5px;
    /* ⭐ 行與行之間的距離 */

    border-radius: 5px;
  }

  .summary-row strong {
    color: #2fa2d4;
  }

  /* 分享按鈕強化 */
  #btnShareCheckpoint {
    background: linear-gradient(135deg, #427e8c, #36724c);
    border: none;
  }

  #btnShareCheckpoint:hover {
    box-shadow:
      0 12px 32px rgba(34, 197, 94, .55),
      0 0 0 2px rgba(34, 197, 94, .25);
  }

  /* =========================================
   Arrow 與滑動提示更精緻
========================================= */

  .cp-arrow {
    box-shadow: 0 10px 30px rgba(0, 0, 0, .55);
  }

  .cp-arrow:hover {
    transform: translateY(-50%) scale(1.08);
  }

  @media (max-width: 768px) {
    .char-ico-square {
      width: 72px;
      height: 72px;
    }

    #cp-page-2 .h5 {
      font-size: 14px;
    }

    #cp-page-2 .small {
      font-size: 12px;
    }
  }

  /* =========================================
   UL.GG Checkpoint Share Button
========================================= */

  .btn-ulgg-share {
    position: relative;
    padding: 14px 28px;
    font-size: 15px;
    font-weight: 600;
    letter-spacing: .04em;

    color: #e5e7eb;
    background:
      linear-gradient(135deg, rgba(34, 197, 94, .95), rgba(22, 163, 74, .95));
    border: none;
    border-radius: 999px;

    box-shadow:
      0 12px 32px rgba(34, 197, 94, .35),
      inset 0 1px 0 rgba(255, 255, 255, .25);

    transition:
      transform .25s ease,
      box-shadow .25s ease,
      filter .25s ease;

    margin-top: -18px;
  }

  /* 微光邊框（不刺眼） */
  .btn-ulgg-share::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    pointer-events: none;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .25);
    opacity: .4;
  }

  /* Hover：浮起、有力量 */
  .btn-ulgg-share:hover {
    transform: translateY(-2px);
    filter: brightness(1.05);
    box-shadow:
      0 18px 46px rgba(34, 197, 94, .55),
      inset 0 1px 0 rgba(255, 255, 255, .35);
  }

  /* Active：按下的重量感 */
  .btn-ulgg-share:active {
    transform: translateY(0);
    box-shadow:
      0 10px 24px rgba(34, 197, 94, .35),
      inset 0 2px 6px rgba(0, 0, 0, .25);
  }

  /* Mobile：避免過大 */
  @media (max-width: 768px) {
    .btn-ulgg-share {
      font-size: 14px;
      padding: 12px 22px;
    }
  }

  .share-capture *,
  .share-capture {
    animation: none !important;
    transition: none !important;
    transform: none !important;
    opacity: 1 !important;
  }

  .cp-card::after {
    content: "UL.GG • UNLIGHT CHECKPOINT 2025";
    position: absolute;
    bottom: 12px;
    right: 16px;
    font-size: 11px;
    color: rgba(255, 255, 255, .35);
  }

  /* =========================================
   Checkpoint Username Style
========================================= */

  .checkpoint-username {
    font-size: 17px;
    /* 原本約 14–15，稍微放大 */
    font-weight: 600;
    color: #b5b5b5;
    letter-spacing: .04em;
    margin-top: 4px;
  }

  .share-capture .cp-card::after {
    content: "生成你的 ▶ ulgg.online/checkpoint/index.php";
    opacity: .25;
    font-size: 10px;
  }

  /* Desktop */
  .un-main {
    margin-left: 240px;
    /* sidebar 寬度 */
    padding: 0 24px;
  }

  /* Tablet / Mobile */
  @media (max-width: 992px) {
    .un-main {
      margin-left: 0;
      padding: 0 16px;
    }
  }
</style>

<?php
/* 放入你的 PHP程式    */
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <div class="col-md-12">
          <div class="checkpoint-slider">
            <div class="checkpoint-track">

              <!-- =========================
                  PAGE 0｜封面
              ========================== -->
              <section class="checkpoint-page active" id="cp-page-0">
                <div class="cp-card">
                  <div class="row  cp-animate">
                    <div class="col-md-12 text-center">
                      <h1 class="mb-2">UL.GG CHECKPOINT <?= $year ?></h1>
                      <p class="checkpoint-username">
                        <?= htmlspecialchars($username) ?> 的年度 UNLIGHT 回顧
                      </p>
                    </div>
                  </div>
                </div>
              </section>


              <!-- =========================
                  PAGE 1｜年度對戰概覽
              ========================== -->
              <section class="checkpoint-page" id="cp-page-1">
                <div class="cp-card">

                  <h2 class="mb-4">你今年踏上的戰場</h2>

                  <div class="row cp-animate">
                    <div class="col-md-4">
                      <div class="border p-3 text-center ">
                        <div class="text-muted">總對戰數</div>
                        <div class="h3">
                          <?= number_format($checkpoint['overview']['total']) ?>
                        </div>
                      </div>
                    </div>

                    <div class="col-md-4">
                      <div class="border p-3 text-center ">
                        <div class="text-muted">勝率</div>
                        <div class="h3">
                          <?= $checkpoint['overview']['win_rate'] ?>%
                        </div>
                      </div>
                    </div>

                    <div class="col-md-4">
                      <div class="border p-3 text-center ">
                        <div class="text-muted">活躍月份</div>
                        <div class="h3">
                          <?= $checkpoint['overview']['active_months'] ?> 個月
                        </div>
                      </div>
                    </div>
                  </div>

                </div>
              </section>


              <!-- =========================
                    PAGE 2｜核心角色
                ========================== -->
              <section class="checkpoint-page" id="cp-page-2">
                <div class="cp-card">

                  <h2 class="mb-4">你的核心愛將</h2>

                  <div class="row text-center  cp-animate">

                    <?php if (!empty($checkpoint['core_chars'])): ?>
                      <?php foreach ($checkpoint['core_chars'] as $char): ?>

                        <div class="col-xs-6 col-sm-4 col-md-3">

                          <div class="border p-3 text-center">

                            <?php if (!empty($char['ico'])): ?>
                              <img
                                src="<?= IMG_BASE . htmlspecialchars($char['ico']) ?>"
                                alt="<?= htmlspecialchars($char['name']) ?>"
                                class="char-ico-square">
                            <?php endif; ?>

                            <div class="h5 mb-1">
                              <?= htmlspecialchars($char['name']) ?>
                            </div>

                            <div class="small text-muted">
                              <div>出場 <?= (int)$char['usage'] ?> 場</div>
                            </div>

                          </div>
                        </div>

                      <?php endforeach; ?>
                    <?php else: ?>

                      <div class="col-md-12 text-muted text-center">
                        尚無足夠資料顯示核心角色
                      </div>

                    <?php endif; ?>

                  </div>

                </div>
              </section>




              <!-- =========================
                    PAGE 3｜常用隊伍組合
                ========================== -->
              <section class="checkpoint-page" id="cp-page-3">
                <div class="cp-card">

                  <h2 class="mb-4">你最信任的組合</h2>

                  <div class="row  cp-animate">

                    <?php foreach ($checkpoint['top_teams'] as $team): ?>
                      <div class="col-md-6">
                        <div class="border p-3 text-center team-card">

                          <div class="row team-lineup">
                            <?php foreach (['leader', 'back1', 'back2'] as $pos): ?>
                              <?php if (!empty($team[$pos])): ?>
                                <div class="col-xs-4">
                                  <img
                                    src="<?= IMG_BASE . htmlspecialchars($team[$pos]['ico']) ?>"
                                    class="char-ico-full">
                                  <div class="small mt-1">
                                    <?= htmlspecialchars($team[$pos]['name']) ?>
                                  </div>
                                </div>
                              <?php endif; ?>
                            <?php endforeach; ?>
                          </div>

                          <div class="text-muted small mt-2 team-meta">
                            <?= $team['matches'] ?> 場
                          </div>

                        </div>
                      </div>
                    <?php endforeach; ?>

                  </div>

                </div>
              </section>



              <!-- =========================
                    PAGE 4｜常見對手
                ========================== -->
              <section class="checkpoint-page" id="cp-page-4">
                <div class="cp-card">

                  <h2 class="mb-4">你今年最可敬的對手</h2>

                  <div class="row text-center  cp-animate">

                    <?php foreach ($checkpoint['opponents'] as $opp): ?>
                      <div class="col-md-4">
                        <div class="border p-4">
                          <div class="h4 mb-2">
                            <?= htmlspecialchars($opp['name']) ?>
                          </div>
                          <div class="text-muted">
                            對戰 <?= $opp['count'] ?> 次
                          </div>
                        </div>
                      </div>
                    <?php endforeach; ?>

                  </div>

                </div>
              </section>




              <!-- =========================
                  PAGE 5｜對戰風格輪廓
              ========================== -->
              <section class="checkpoint-page" id="cp-page-5">
                <div class="cp-card">

                  <h2 class="mb-4">你最常進入的戰場</h2>

                  <div class="row text-center  cp-animate">

                    <div class="col-md-6">
                      <div class="border p-3">
                        <div class="text-muted">平均 COST</div>
                        <div class="h3">
                          <?= htmlspecialchars($checkpoint['style']['avg_cost']) ?>
                        </div>
                      </div>
                    </div>

                    <div class="col-md-6">
                      <div class="border p-3">
                        <div class="text-muted">最常進入</div>
                        <div class="h3">
                          <?php
                          $stageId  = $checkpoint['style']['fav_stage'];
                          $stageCnt = $checkpoint['style']['fav_stage_cnt'];

                          if ($stageId !== null && isset($stages[$stageId])) {
                            /* echo htmlspecialchars($stages[$stageId]) . '（' . (int)$stageCnt . ' 場）'; */
                            echo htmlspecialchars($stages[$stageId]);
                          } else {
                            echo '未知地圖';
                          }
                          ?>

                        </div>
                      </div>
                    </div>

                  </div>

                </div>
              </section>




              <!-- =========================
                    PAGE 6｜總結 / 分享
                ========================== -->
              <!-- =========================
    PAGE 6｜年度總覽 / 分享
========================== -->
              <section class="checkpoint-page" id="cp-page-6">
                <div class="cp-card">

                  <div class="row cp-animate">
                    <div class="col-md-12 text-center">

                      <h2 class="mb-2">UL.GG CHECKPOINT <?= $year ?></h2>
                      <p class="checkpoint-username">
                        <?= htmlspecialchars($username) ?> 的UNLIGHT年度總結
                      </p>

                      <div class="checkpoint-summary">

                        <div class="summary-row">
                          <span>🎮 總對戰</span>
                          <strong><?= number_format($checkpoint['overview']['total']) ?> 場</strong>
                        </div>


                        <div class="summary-row">
                          <span>🧙 核心愛將</span>
                          <strong><?= htmlspecialchars($checkpoint['core_chars'][0]['name'] ?? '—') ?></strong>
                        </div>

                        <div class="summary-row">
                          <span>🤝 最常組合</span>
                          <strong>
                            <?= htmlspecialchars($checkpoint['top_teams'][0]['leader']['name'] ?? '') ?>
                            /
                            <?= htmlspecialchars($checkpoint['top_teams'][0]['back1']['name'] ?? '') ?>
                            /
                            <?= htmlspecialchars($checkpoint['top_teams'][0]['back2']['name'] ?? '') ?>
                          </strong>
                        </div>

                        <div class="summary-row">
                          <span>🔥 最可敬對手</span>
                          <strong><?= htmlspecialchars($checkpoint['opponents'][0]['name'] ?? '—') ?></strong>
                        </div>

                        <div class="summary-row">
                          <span>🗺 最常踏入的地圖</span>
                          <strong>
                            <?= htmlspecialchars($stages[$checkpoint['style']['fav_stage']] ?? '未知') ?>
                          </strong>
                        </div>

                      </div>

                      <button class="btn btn-lg btn-ulgg-share mt-4" id="btnShareCheckpoint">
                        🔗 分享我的 Checkpoint
                      </button>


                    </div>
                  </div>

                </div>
              </section>


            </div>
            <!-- =========================
                Arrow Navigation
            ========================== -->
            <button class="cp-arrow cp-arrow-left" id="cpPrev" aria-label="Previous">
              ‹
            </button>
            <button class="cp-arrow cp-arrow-right" id="cpNext" aria-label="Next">
              ›
            </button>
          </div>

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
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
  (function() {
    const slider = document.querySelector('.checkpoint-slider');
    const track = document.querySelector('.checkpoint-track');
    const pages = document.querySelectorAll('.checkpoint-page');

    const btnPrev = document.getElementById('cpPrev');
    const btnNext = document.getElementById('cpNext');

    let currentIndex = 0;

    function getPageWidth() {
      return pages[0].offsetWidth;
    }

    function updateArrows() {
      btnPrev.classList.toggle('disabled', currentIndex === 0);
      btnNext.classList.toggle('disabled', currentIndex === pages.length - 1);
    }

    function goToPage(index) {
      if (index < 0) index = 0;
      if (index >= pages.length) index = pages.length - 1;

      currentIndex = index;

      const offset = index * getPageWidth();
      track.style.transition = 'transform 0.6s ease';
      track.style.transform = `translateX(-${offset}px)`;

      pages.forEach(p => p.classList.remove('active'));
      if (pages[index]) pages[index].classList.add('active');

      updateArrows();
    }

    /* =========================
       Arrow Click
    ========================== */
    btnPrev.addEventListener('click', () => {
      goToPage(currentIndex - 1);
    });

    btnNext.addEventListener('click', () => {
      goToPage(currentIndex + 1);
    });

    /* =========================
       Resize 修正
    ========================== */
    window.addEventListener('resize', () => {
      goToPage(currentIndex);
    });

    /* =========================
       手機左右滑動（保留）
    ========================== */
    let startX = 0;
    let endX = 0;
    let isSwiping = false;

    slider.addEventListener('touchstart', e => {
      startX = e.touches[0].clientX;
      isSwiping = true;
    }, {
      passive: true
    });

    slider.addEventListener('touchmove', e => {
      if (!isSwiping) return;
      endX = e.touches[0].clientX;
    }, {
      passive: true
    });

    slider.addEventListener('touchend', () => {
      if (!isSwiping) return;

      const diffX = endX - startX;

      if (Math.abs(diffX) > 60) {
        if (diffX < 0) {
          goToPage(currentIndex + 1);
        } else {
          goToPage(currentIndex - 1);
        }
      }

      isSwiping = false;
      startX = endX = 0;
    });

    // 初始頁
    goToPage(0);
  })();
</script>



<!-- 分享截圖 -->
<script>
  document.getElementById('btnShareCheckpoint')?.addEventListener('click', async () => {

    const card = document.querySelector('.checkpoint-page.active .cp-card');
    if (!card) {
      alert('找不到可分享的內容');
      return;
    }

    card.classList.add('share-capture');

    const canvas = await html2canvas(card, {
      backgroundColor: '#0f172a',
      scale: 2,
      useCORS: true
    });

    card.classList.remove('share-capture');

    canvas.toBlob(async blob => {

      const file = new File(
        [blob],
        'ulgg-checkpoint-2025.png', {
          type: 'image/png'
        }
      );

      const shareText =
        '🎮 這是我的 UNLIGHT 年度 Checkpoint\n' +
        '👉 你也可以生成你自己的：\n' +
        'https://ulgg.online/checkpoint/index.php';

      // ✅ 手機原生分享
      if (navigator.canShare && navigator.canShare({
          files: [file]
        })) {
        try {
          await navigator.share({
            files: [file],
            title: 'UL.GG CHECKPOINT 2025',
            text: shareText
          });
        } catch (e) {
          console.log('Share cancelled');
        }
      } else {
        // 🖥 桌機 fallback：下載 + 複製提示
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'ulgg-checkpoint-2025.png';
        a.click();
        URL.revokeObjectURL(a.href);

        await navigator.clipboard.writeText(
          shareText.replace(/\n/g, ' ')
        );

        alert('已下載圖片，並複製引導連結：\n你也可以生成你自己的 Checkpoint');
      }

    });
  });
</script>



<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
$hidePageHeader = true;
include __DIR__ . '/../../layout/base.php';
?>