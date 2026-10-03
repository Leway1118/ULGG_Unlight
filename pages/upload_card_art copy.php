<?php
/* upload_card_art.php */
/* define('IMG_BASE', '/assets/uploads/');
define('APP_ROOT', __DIR__); */
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定
$pdo = $db; // ⭐ 一定要這行
require_once __DIR__ . '/../lib/checkpoint_data.php';
$stages = require __DIR__ . '/../lib/stage_map.php';
$year = 2025;
$username = $_SESSION['username'] ?? '';
$checkpoint = getCheckpointData($pdo, $username, $year);
/* require_once __DIR__ . '/../lib/_analysis_base.php'; */ // ⭐ 必須包含時間區間設定
/* require_once __DIR__ . '/_admin_gate.php'; */ //確認是否為管理員
// ⭐ 全站常數 define('IMG_BASE', '/assets/uploads/');

$pageTitleText = '卡面上傳 Upload Card Art';
$seoTitle = '卡面上傳 | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = '卡面上傳 | UL.GG 戰績網';
$activeMenu = 'upload_card_art'; //.php;

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* =========================================================
   Checkpoint – Center Layout (BS3 Friendly)
========================================================= */

  .checkpoint-slider {
    width: 100%;
    display: flex;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }

  .checkpoint-track {
    display: flex;
    width: 100%;
  }

  .checkpoint-page {
    flex: 0 0 100%;
    max-width: 100%;
    padding: 10px 0;
    display: flex;
    justify-content: center;
  }

  .checkpoint-page h1,
  .checkpoint-page h2,
  .checkpoint-page p {
    text-align: center;
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
    transition: background .2s ease, border-color .2s ease, opacity .2s ease;
  }

  .cp-arrow:hover {
    background: rgba(34, 197, 94, 0.85);
    border-color: rgba(34, 197, 94, 0.9);
  }

  .cp-arrow-left {
    left: 12px;
  }

  .cp-arrow-right {
    right: 12px;
  }

  .cp-arrow.disabled {
    opacity: 0.25;
    pointer-events: none;
  }

  @media (max-width: 768px) {
    .cp-arrow {
      display: none !important;
    }
  }

  /* =========================================================
   Card Container
========================================================= */

  .cp-card {
    width: 100%;
    max-width: 900px;
    min-height: 83vh;

    display: flex;
    flex-direction: column;
    justify-content: flex-start;

    padding: 32px;

    background:
      radial-gradient(1200px 600px at top center, rgba(34, 197, 94, .06), transparent 60%),
      linear-gradient(180deg, rgba(15, 23, 42, .96), rgba(15, 23, 42, .92));

    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, .08);

    box-shadow:
      0 20px 60px rgba(0, 0, 0, .55),
      inset 0 1px 0 rgba(255, 255, 255, .04);

    position: relative;
    overflow: hidden;
  }

  .cp-card::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 16px;
    pointer-events: none;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .05);
  }

  .cp-card::after {
    content: "UL.GG • UNLIGHT CARD ART";
    position: absolute;
    bottom: 12px;
    right: 16px;
    font-size: 11px;
    color: rgba(255, 255, 255, .35);
  }

  /* =========================================================
   Global Layout Helpers
========================================================= */

  .content-header {
    display: none;
  }

  .un-main {
    margin-left: 240px;
    padding: 0 24px;
  }

  @media (max-width: 992px) {
    .un-main {
      margin-left: 0;
      padding: 0 16px;
    }
  }

  .checkpoint-username {
    font-size: 17px;
    font-weight: 600;
    color: #b5b5b5;
    letter-spacing: .04em;
    margin-top: 4px;
  }

  /* =========================================================
   Card Slot (Upload Ready)
========================================================= */

  .card-slot {
    position: relative;
    width: 100%;
    aspect-ratio: 3 / 4;
    border-radius: 14px;
    overflow: hidden;

    background: linear-gradient(180deg,
        rgba(255, 255, 255, .06),
        rgba(255, 255, 255, .02));

    border: 1px dashed rgba(255, 255, 255, .18);
    cursor: pointer;
    transition: border-color .25s ease, box-shadow .25s ease;
  }

  .card-slot:hover {
    border-color: rgba(34, 197, 94, .9);
    box-shadow: 0 0 0 2px rgba(34, 197, 94, .35);
  }

  .card-slot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    opacity: .45;
  }

  .card-slot .slot-hint {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;

    font-weight: 600;
    letter-spacing: .04em;
    color: rgba(255, 255, 255, .75);
    text-shadow: 0 2px 8px rgba(0, 0, 0, .65);
  }

  .card-slot:hover .slot-hint {
    color: #22c55e;
  }

  /* 左上角等級 */
  .card-level {
    position: absolute;
    /* top: 8px; */
    left: 2px;

    width: 32px;
    height: 32px;
    border-radius: 50%;

    background: rgba(15, 23, 42, .85);
    border: 1px solid rgba(255, 255, 255, .25);

    font-size: 13px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  /* 上方角色名稱條 */
  .card-name {
    position: absolute;
    top: 0;
    text-align: end;
    inset-inline: 0;

    padding: 6px 10px;
    font-size: 12px;
    font-weight: 600;
    letter-spacing: .06em;

    background: linear-gradient(180deg,
        rgba(0, 0, 0, .65),
        transparent);
  }

  @media (max-width: 768px) {
    .card-slot {
      aspect-ratio: 2 / 3;
    }

    .col-xs-6 {
      margin-bottom: 14px;
    }
  }

  /* =========================================
   Card Binder – Toolbar
========================================= */

  .card-binder-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-bottom: 24px;
  }

  .card-search {
    width: 260px;
    max-width: 90%;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, .2);
    background: rgba(15, 23, 42, .85);
    color: #e5e7eb;
    outline: none;
  }

  .card-search::placeholder {
    color: rgba(255, 255, 255, .45);
  }

  /* =========================================
   Character Bookmark Tabs
========================================= */

  .char-bookmarks {
    display: grid;
    grid-template-columns: repeat(7, max-content);
    gap: 8px 10px;

    justify-content: center;
    max-width: 100%;
    padding: 4px;

    /* 不再橫向捲動 */
    overflow: auto;
  }


  .char-bookmark {
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .04em;

    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .15);
    color: #e5e7eb;

    cursor: pointer;
    white-space: nowrap;
  }

  .char-bookmark.active {
    background: rgba(34, 197, 94, .85);
    border-color: rgba(34, 197, 94, .95);
    color: #052e16;
  }

  /* =========================================
   Card Binder Grid
========================================= */

  .card-binder {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-top: 16px;
  }

  .card-binder-row {
    margin-bottom: 18px;
  }

  .card-binder-label {
    text-align: center;
    font-size: 13px;
    letter-spacing: .12em;
    color: rgba(255, 255, 255, .5);
    margin-bottom: 6px;
  }

  /* Mobile */
  @media (max-width: 768px) {
    .card-binder {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  /* Upload Card Art 不使用進場動畫 */
  .cp-animate {
    opacity: 1 !important;
    transform: none !important;
    transition: none !important;
  }
</style>


<?php
$sql = "
  SELECT id, name, level, ico
  FROM unlight
  WHERE level REGEXP '^[LR][1-5]$'
  ORDER BY id,
           FIELD(level,'L1','L2','L3','L4','L5','R1','R2','R3','R4','R5')
";
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* 整理成：角色 → 等級 */
$characters = [];
foreach ($rows as $row) {
  $charName = $row['name'];
  $level    = $row['level'];

  if (!isset($characters[$charName])) {
    $characters[$charName] = [];
  }
  $characters[$charName][$level] = $row;
}

$charNames = array_keys($characters);

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
                  PAGE 0｜說明頁（取代原封面）
              ========================== -->
              <section class="checkpoint-page active" id="cp-page-0">
                <div class="cp-card text-center">

                  <h1 class="mb-3">玩家卡面上傳</h1>
                  <p class="checkpoint-username">
                    共創屬於你的 UNLIGHT 世界線
                  </p>

                  <div class="cp-animate" style="max-width:560px;margin:0 auto;">

                    <p class="text-muted">
                      上傳你為角色創作的卡面，<br>
                      由社群投票決定哪些作品能被看見。
                    </p>

                    <ul class="text-muted small" style="list-style:none;padding:0;">
                      <li>✔ 需登入才能上傳</li>
                      <li>✔ 每張圖每帳號一票</li>
                      <li>✔ 愛心 ≥ 10 才會顯示於主卡槽</li>
                      <li>✔ 低共鳴作品將定期封存</li>
                    </ul>

                  </div>

                </div>
              </section>



              <!-- =========================
                  PAGE 1｜角色卡冊頁（核心）
              ========================== -->
              <!-- 書籤＋搜尋（只需要一次） -->
              <section class="checkpoint-page">
                <div class="cp-card">

                  <div class="card-binder-toolbar">
                    <input class="card-search" placeholder="搜尋角色名稱…" />
                    <div class="char-bookmarks">
                      <?php foreach ($charNames as $i => $name): ?>
                        <div class="char-bookmark <?= $i === 0 ? 'active' : '' ?>">
                          <?= htmlspecialchars($name) ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>

                  <h2 id="charTitle"></h2>
                  <div id="cardBinder"></div>

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
    <script>
      window.CHARACTERS = <?= json_encode($characters, JSON_UNESCAPED_UNICODE) ?>;
    </script>
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
include __DIR__ . '/../layout/base.php';
?>