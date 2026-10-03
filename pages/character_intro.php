<?php
/* upload_card_art.php */
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定
/* config內define('IMG_BASE', '/assets/uploads/');
config內define('APP_ROOT', __DIR__); */
$pdo = $db; // ⭐ 一定要這行
//require_once __DIR__ . '/../lib/checkpoint_data.php';
//$stages = require __DIR__ . '/../lib/stage_map.php';
//$year = 2025;
$username = $_SESSION['username'] ?? '';
//$checkpoint = getCheckpointData($pdo, $username, $year);
/* require_once __DIR__ . '/../lib/_analysis_base.php'; */ // ⭐ 必須包含時間區間設定
/* require_once __DIR__ . '/_admin_gate.php'; */ //確認是否為管理員
// ⭐ 全站常數 define('IMG_BASE', '/assets/uploads/');

$pageTitleText = '角色介紹 Intro';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網'; //桌機
$activeMenu = "character_intro"; //.php

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
    content: "UL.GG • UNLIGHT CARD INTRO";
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


  /* 左上角等級 */
  .card-level {
    position: absolute;
    z-index: 5;
    top: 7px;
    left: 7px;
    /* ✅ 關鍵，蓋過 card-name */

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

  @media (max-width: 992px) {
    .card-level {
      left: 6px;
      width: 25px;
      height: 25px;
    }
  }


  /* 上方角色名稱條 */
  /* 上方角色名稱條（背景滿版） */
  .card-name {
    position: absolute;
    top: 0;
    left: -17px;
    right: 0;
    padding: 6px 6px;

    font-size: 12px;
    font-weight: 600;
    letter-spacing: .06em;

    background: linear-gradient(180deg,
        rgba(0, 0, 0, 0.95) 0%,
        rgba(0, 0, 0, 0.82) 45%,
        rgba(0, 0, 0, 0.45) 70%,
        rgba(0, 0, 0, 0.0) 100%);

    pointer-events: none;
    /* 不影響翻卡 */
  }

  /* 文字本體：扣掉 LV Tag 後置中 */
  .card-name-text {
    display: block;

    margin-left: 40px;
    /* LV 32px + 間距 */
    margin-right: 10px;

    text-align: center;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-shadow:
      0 1px 2px rgba(0, 0, 0, 0.95),
      0 2px 6px rgba(0, 0, 0, 0.85);
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
   Character Bookmark Tabs – Responsive
========================================= */

  .char-bookmarks {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px 10px;

    padding: 6px 8px;
  }

  /* Bookmark button */
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

    text-align: center;

    transition: background .15s ease, border-color .15s ease;
  }

  .char-bookmark.active {
    background: rgba(34, 197, 94, .85);
    border-color: rgba(34, 197, 94, .95);
    color: #052e16;
  }

  /* ===============================
   📱 Mobile Optimization
================================ */
  @media (max-width: 768px) {
    .char-bookmarks {
      display: flex;
      flex-wrap: wrap;
      justify-content: center;

      gap: 8px;
      padding: 4px 6px;
    }

    .char-bookmark {
      font-size: 18px;
      padding: 5px 12px;
    }

    .card-level {
      top: 8px;
      left: 3px;
      width: 32px;
      height: 32px;
    }
  }

  /* ===============================
   📱 Extra Small Devices
================================ */
  @media (max-width: 420px) {
    .char-bookmark {
      font-size: 15px;
      padding: 4px 10px;
    }
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


  /* =========================
   Back To Index Button
========================= */

  .back-to-index {
    position: absolute;
    top: 16px;
    left: 16px;
    z-index: 30;

    padding: 6px 14px;
    border-radius: 999px;

    font-size: 13px;
    font-weight: 600;
    letter-spacing: .06em;

    background: rgba(15, 23, 42, 0.75);
    border: 1px solid rgba(255, 255, 255, .2);
    color: #e5e7eb;

    cursor: pointer;
    user-select: none;

    transition:
      background .2s ease,
      border-color .2s ease,
      color .2s ease,
      transform .15s ease;
  }

  .back-to-index:hover {
    background: rgba(34, 197, 94, .85);
    border-color: rgba(34, 197, 94, .95);
    color: #052e16;
    transform: translateX(-2px);
  }

  .back-to-index:active {
    transform: translateX(-4px);
  }

  /* Mobile */
  @media (max-width: 768px) {
    .back-to-index {
      font-size: 12px;
      padding: 5px 12px;
    }
  }

  .admin-card-tools {
    position: absolute;
    top: -12px;
    /* ⬅ 移出卡面 */
    right: -10px;
    /* ⬅ 移出卡面 */
    z-index: 30;

    display: flex;
    gap: 4px;
  }

  @media (max-width: 768px) {
    .admin-card-tools {
      top: -8px;
      right: -6px;
      transform: scale(0.9);
    }
  }


  .admin-btn {
    padding: 2px 6px;
    font-size: 11px;
    border-radius: 999px;
    /* 改成膠囊 */
    border: 1px solid rgba(34, 197, 94, .9);

    cursor: pointer;
    font-weight: 700;

    color: #052e16;
    background: rgba(34, 197, 94, .85);

    box-shadow:
      0 2px 6px rgba(0, 0, 0, .35),
      inset 0 1px 0 rgba(255, 255, 255, .35);
  }

  .admin-btn:hover {
    background: rgba(34, 197, 94, 1);
  }

  .admin-file {
    display: none !important;
  }

  .char-bookmarks.is-empty::after {
    content: "找不到符合的角色";
    color: rgba(255, 255, 255, .5);
    font-size: 13px;
    padding: 12px;
  }

  .admin-check-btn {
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: .05em;

    background: rgba(239, 68, 68, .85);
    /* red-500 */
    border: 1px solid rgba(239, 68, 68, 1);
    color: #fff;

    cursor: pointer;
    transition: background .15s ease, transform .1s ease;
  }

  .admin-check-btn:hover {
    background: rgba(239, 68, 68, 1);
    transform: translateY(-1px);
  }

  /* 🔍 檢查缺少背面 */
  .missing-panel {
    margin-top: 14px;
    padding: 12px;
    border-radius: 12px;
    background: rgba(239, 68, 68, .08);
    border: 1px solid rgba(239, 68, 68, .35);
  }

  .missing-title {
    font-size: 13px;
    color: #fecaca;
    margin-bottom: 8px;
  }

  .missing-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .missing-item {
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;

    background: rgba(239, 68, 68, .85);
    color: #fff;
  }

  /* =========================================================
   Card Slot (Upload Ready)
========================================================= */

  .card-slot {
    position: relative;
    width: 100%;
    aspect-ratio: 0.7;

    overflow: visible;

    background: linear-gradient(180deg,
        rgba(255, 255, 255, .06),
        rgba(255, 255, 255, .02));

    border: 1px dashed rgba(255, 255, 255, .18);
    cursor: pointer;
    perspective: 1200px;
  }

  .card-slot:hover {
    border-color: rgba(34, 197, 94, .9);
    box-shadow: 0 0 0 2px rgba(34, 197, 94, .35);
  }

  .card-slot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
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

  @media (max-width: 768px) {
    .card-slot {
      aspect-ratio: 2 / 3;
    }
  }

  /* =========================================================
   Flip Card Core（唯一穩定版本）
========================================================= */

  .card-inner {
    position: relative;
    width: 100%;
    height: 100%;
    transform-style: preserve-3d;
    transition: transform .6s cubic-bezier(.4, .2, .2, 1);
  }

  .card-slot.is-flipped .card-inner {
    transform: rotateY(180deg);
  }

  .card-face {
    position: absolute;
    inset: 0;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
    border-radius: 14px;
    overflow: hidden;
  }

  /* 正面 */
  .card-front {
    z-index: 2;
  }

  /* 背面 */
  .card-back {
    transform: rotateY(180deg);
    background: #0f172a;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .card-back img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .card-back-placeholder {
    color: rgba(255, 255, 255, .6);
    font-size: 14px;
    letter-spacing: .1em;
  }
</style>


<?php
$sql = "
  SELECT id, name, level, ico, ico_back
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
                  PAGE 0｜角色索引
              ========================== -->
              <section class="checkpoint-page" id="page-index">
                <div class="cp-card">

                  <div class="card-binder-toolbar">
                    <?php if (!empty($_SESSION['ack']) && $_SESSION['ack'] >= 2): ?>
                      <button class="admin-check-btn" id="checkMissingBack">
                        🔍 檢查缺少背面
                      </button>
                    <?php endif; ?>

                    <input class="card-search" placeholder="搜尋角色名稱…" />

                    <div class="char-bookmarks">
                      <?php foreach ($charNames as $i => $name): ?>
                        <div class="char-bookmark" data-index="<?= $i ?>">
                          <?= htmlspecialchars($name) ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <!-- 管理員：缺少背面檢查結果 -->
                  <div id="missingBackPanel" class="missing-panel" style="display:none;">
                    <div class="missing-title">
                      缺少背面的角色：
                      <span id="exitCheckMode"
                        style="cursor:pointer; margin-left:12px; color:#86efac;">
                        ⟲ 回到列表
                      </span>
                    </div>

                    <div class="missing-list"></div>
                  </div>


                </div>
              </section>



              <!-- =========================
                  PAGE 1｜角色卡冊
              ========================== -->
              <section class="checkpoint-page" id="page-character">
                <div class="cp-card">
                  <div class="back-to-index" onclick="backToIndex()">
                    ← 返回角色列表
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


  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script>
  window.CHARACTERS = <?= json_encode($characters, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script>
  const CHAR_LIST = Object.keys(window.CHARACTERS);
  let currentCharIndex = -1;
</script>
<script>
  function switchCharacter(next = true) {
    if (currentCharIndex === -1) return; // 還在索引頁不動

    currentCharIndex += next ? 1 : -1;
    currentCharIndex = Math.max(0, Math.min(currentCharIndex, CHAR_LIST.length - 1));

    renderCharacter(CHAR_LIST[currentCharIndex]);
  }
</script>

<script>
  (function() {
    const slider = document.querySelector('.checkpoint-slider');

    let startX = 0;
    let endX = 0;
    let isSwiping = false;
    let swipeDisabled = false;

    slider.addEventListener('touchmove', e => {
      if (!isSwiping) return;
      endX = e.touches[0].clientX;
    }, {
      passive: true
    });

    // 初始頁
    goToPage(0);
  })();
</script>
<script>
  const sliderTrack = document.querySelector('.checkpoint-track');
  const pages = document.querySelectorAll('.checkpoint-page');

  let currentPageIndex = 0;

  function goToPage(index) {
    currentPageIndex = index;
    sliderTrack.style.transform = `translateX(-${index * 100}%)`;

    // 索引頁隱藏箭頭
    document.getElementById('cpPrev').style.display = index === 0 ? 'none' : '';
    document.getElementById('cpNext').style.display = '';
  }
</script>



<script>
  const slider = document.querySelector('.checkpoint-slider');

  let startX = 0;
  let startY = 0;
  let isTouching = false;

  slider.addEventListener('touchstart', e => {
    const t = e.touches[0];
    startX = t.clientX;
    startY = t.clientY;
    isTouching = true;
  }, {
    passive: true
  });

  slider.addEventListener('touchend', e => {
    if (!isTouching) return;

    const t = e.changedTouches[0];
    const dx = t.clientX - startX;
    const dy = t.clientY - startY;

    isTouching = false;

    // 1️⃣ 垂直滑動 → 交給瀏覽器捲動
    if (Math.abs(dy) > Math.abs(dx)) return;

    // 2️⃣ 位移太小 → 視為點擊（翻卡）
    if (Math.abs(dx) < 60) return;

    // 3️⃣ 只在角色頁允許換角色
    if (currentPageIndex !== 1) return;

    if (dx < 0) {
      switchCharacter(true); // 向左 → 下一角色
    } else {
      switchCharacter(false); // 向右 → 上一角色
    }
  }, {
    passive: true
  });
</script>


<script>
  document.addEventListener('click', e => {
    const card = e.target.closest('.card-slot');
    if (!card) return;

    // ❌ 點 admin 工具不翻卡、不換頁
    if (e.target.closest('.admin-card-tools')) {
      e.stopPropagation();
      return;
    }

    // ❌ 點箭頭不翻卡
    if (e.target.closest('.cp-arrow')) {
      e.stopPropagation();
      return;
    }

    // ✅ 點卡片（正 / 背）只做翻卡，禁止往上冒泡
    e.stopPropagation();
    card.classList.toggle('is-flipped');
  });
  document.addEventListener('click', e => {
    if (e.target.closest('.card-slot')) {
      e.stopPropagation();
    }
  });
</script>

<script>
  function initLazyLoad() {
    const io = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (!e.isIntersecting) return;
        const img = e.target;

        if (img._lazyKilled) {
          io.unobserve(img);
          return;
        }

        if (!img.dataset.src) {
          io.unobserve(img);
          return;
        }

        img.src = img.dataset.src;
        io.unobserve(img);
      });
    });

    document.querySelectorAll('.lazy-img')
      .forEach(img => io.observe(img));
  }
</script>

<!-- buildCard（產生單張卡） -->
<script>
  function buildCard(card, charName, level) {
    const bust = Date.now();
    const isAdmin = <?= (!empty($_SESSION['ack']) && $_SESSION['ack'] >= 2) ? 'true' : 'false' ?>;

    if (!card) {
      return `
      <div class="card-slot empty" data-level="${level}">
        <div class="card-level">${level}</div>
      </div>
    `;
    }

    return `
    <div class="card-slot" data-id="${card.id}" data-level="${level}">

  ${isAdmin ? `
    <div class="admin-card-tools outside">
      <button class="admin-btn" data-side="front">正</button>
      <button class="admin-btn" data-side="back">反</button>
      <input type="file" class="admin-file" name="image" accept="image/*">
    </div>
  ` : ''}

  <div class="card-inner">
    <!-- 正面 -->
    <div class="card-face card-front">
      <img data-src="<?= IMG_BASE ?>${card.ico}" class="lazy-img">

      <div class="card-name">
        <span class="card-name-text">${charName}</span>
      </div>

      <div class="card-level">${level}</div>
    </div>

    <!-- 背面 -->
    <div class="card-face card-back">
      ${
        card.ico_back
          ? `<img data-src="<?= IMG_BASE ?>${card.ico_back}" class="lazy-img">`
          : `<div class="card-back-placeholder">尚無背面</div>`
      }
    </div>
  </div>
</div>

  `;
  }
</script>

<!-- renderCharacter（一次只生 10 張） -->
<script>
  function renderCharacter(charName) {
    const data = window.CHARACTERS[charName];
    if (!data) return;

    const binder = document.getElementById('cardBinder');
    const title = document.getElementById('charTitle');

    title.textContent = charName;
    binder.innerHTML = '';

    let html = '';

    ['L', 'R'].forEach(type => {
      html += `
        <div class="card-binder-row">
          <div class="card-binder-label">LEVEL ${type}</div>
          <div class="card-binder">
      `;

      for (let i = 1; i <= 5; i++) {
        const lvl = type + i;
        html += buildCard(data[lvl], charName, lvl);
      }

      html += `
          </div>
        </div>
      `;
    });

    binder.innerHTML = html;

    initLazyLoad();
  }
</script>


<!-- 書籤點擊切換角色 -->
<script>
  document.querySelectorAll('.char-bookmark').forEach((btn, i) => {
    btn.addEventListener('click', () => {

      currentCharIndex = i;
      renderCharacter(CHAR_LIST[i]);

      // 👉 切到角色頁（第二頁）
      goToPage(1);
    });
  });

  // 初始只顯示索引頁，不 render 任何角色
  //let currentCharIndex = -1;
</script>
<script>
  const btnPrev = document.getElementById('cpPrev');
  const btnNext = document.getElementById('cpNext');

  btnNext.onclick = () => {
    // 索引頁 → 進第一個角色
    if (currentPageIndex === 0) {
      currentCharIndex = 0;
      renderCharacter(CHAR_LIST[0]);
      goToPage(1);
      return;
    }

    // 角色頁 → 下一角色
    switchCharacter(true);
  };

  btnPrev.onclick = () => {
    // 第一隻角色 → 回索引
    if (currentPageIndex === 1 && currentCharIndex === 0) {
      currentCharIndex = -1;
      goToPage(0);
      return;
    }

    // 角色頁 → 上一角色
    switchCharacter(false);
  };
</script>
<script>
  function backToIndex() {
    currentCharIndex = -1; // ⭐ 關鍵：回到索引模式
    goToPage(0); // 回到 PAGE 0
  }
</script>

<script>
  document.addEventListener('click', e => {
    const btn = e.target.closest('.admin-btn');
    if (!btn) return;

    e.stopPropagation(); // ❗避免翻卡

    const card = btn.closest('.card-slot');
    const side = btn.dataset.side;
    const fileInput = card.querySelector('.admin-file');

    fileInput.dataset.side = side;
    fileInput.click();
  });
</script>
<script>
  document.addEventListener('change', async e => {
    const input = e.target;
    if (!input.classList.contains('admin-file')) return;

    const card = input.closest('.card-slot');
    const cardId = card.dataset.id;
    if (!cardId) {
      alert('此卡尚未建立資料');
      return;
    }

    const side = input.dataset.side; // front / back
    const file = input.files[0];
    if (!file) return;

    const fd = new FormData();
    fd.append('card_id', cardId);
    fd.append('side', side);
    fd.append('image', file);

    try {
      const res = await fetch('/api/upload_card_image.php', {
        method: 'POST',
        body: fd
      });
      const json = await res.json();

      if (!json.ok) {
        alert(json.error || '上傳失敗');
        return;
      }

      // ⭐ 同步更新資料模型
      const charName = document.getElementById('charTitle').textContent;
      const level = card.dataset.level;

      if (window.CHARACTERS[charName] && window.CHARACTERS[charName][level]) {
        if (side === 'front') {
          window.CHARACTERS[charName][level].ico = json.url + '?t=' + Date.now();
        } else {
          window.CHARACTERS[charName][level].ico_back = json.url + '?t=' + Date.now();
        }
      }


      // ⭐ 不直接碰 img
      // ⭐ 直接交給 renderCharacter 重建 DOM

      setTimeout(() => {
        renderCharacter(charName);
      }, 100);


      // 2️⃣ 脫離 LazyLoad
      /* img.classList.remove('lazy-img');
      img.removeAttribute('data-src');
      img.loading = 'eager'; */

      // 3️⃣ 切換正式圖 → 改成「重新 render」
      setTimeout(() => {
        renderCharacter(charName); // ⭐ 唯一正解
      }, 300);

    } catch (err) {
      console.error(err);
      alert('上傳錯誤');
    } finally {
      input.value = '';
    }
  });
</script>
<script>
  (function() {
    const searchInput = document.querySelector('.card-search');
    const container = document.querySelector('.char-bookmarks');
    const bookmarks = Array.from(document.querySelectorAll('.char-bookmark'));

    if (!searchInput || !container || bookmarks.length === 0) return;

    function updateFilter() {
      const keyword = searchInput.value.trim().toLowerCase();
      let anyVisible = false;

      bookmarks.forEach(btn => {
        const name = btn.textContent.toLowerCase();
        const visible = name.includes(keyword);
        btn.style.display = visible ? '' : 'none';
        if (visible) anyVisible = true;
      });

      container.classList.toggle('is-empty', !anyVisible);
    }

    searchInput.addEventListener('input', updateFilter);

    // ESC 快速清空
    searchInput.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        searchInput.value = '';
        updateFilter();
        searchInput.blur();
      }
    });
  })();
</script>
<script>
  document.getElementById('checkMissingBack')?.addEventListener('click', () => {
    const list = document.querySelector('.missing-list');
    const panel = document.getElementById('missingBackPanel');

    const searchInput = document.querySelector('.card-search');
    const bookmarks = document.querySelector('.char-bookmarks');

    list.innerHTML = '';
    panel.style.display = 'none';

    // ⭐ 進入檢查模式：隱藏搜尋與原書籤
    if (searchInput) searchInput.style.display = 'none';
    if (bookmarks) bookmarks.style.display = 'none';

    const missingChars = new Set();

    for (const [charName, levels] of Object.entries(window.CHARACTERS)) {
      for (const card of Object.values(levels)) {
        if (!card) continue;
        if (card.ico && !card.ico_back) {
          missingChars.add(charName);
          break; // ⭐ 只要這個角色有一張缺，就收
        }
      }
    }

    if (missingChars.size === 0) {
      alert('✅ 所有角色背面皆齊全');

      // 還原 UI
      if (searchInput) searchInput.style.display = '';
      if (bookmarks) bookmarks.style.display = '';
      return;
    }

    panel.style.display = '';

    [...missingChars].forEach(charName => {
      const btn = document.createElement('div');
      btn.className = 'missing-item';
      btn.textContent = charName;

      btn.onclick = () => {
        const idx = CHAR_LIST.indexOf(charName);
        if (idx !== -1) {
          currentCharIndex = idx;
          renderCharacter(charName);
          goToPage(1);
        }
      };

      list.appendChild(btn);
    });
  });
</script>
<script>
  document.getElementById('exitCheckMode')?.addEventListener('click', () => {
    const panel = document.getElementById('missingBackPanel');
    const searchInput = document.querySelector('.card-search');
    const bookmarks = document.querySelector('.char-bookmarks');

    panel.style.display = 'none';
    if (searchInput) searchInput.style.display = '';
    if (bookmarks) bookmarks.style.display = '';
  });
</script>
<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
$hidePageHeader = true;
include __DIR__ . '/../layout/base.php';
?>