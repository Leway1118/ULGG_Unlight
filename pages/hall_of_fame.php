<?php
session_start();
require_once __DIR__ . '/../config.php';
$pdo = $db;

$pageTitleText = '歷史名人堂 Hall of Fame';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "hall_of_fame";

ob_start();
?>

<style>
  /* =========================================================
   Hall of Fame – Base Style
========================================================= */

  .hof-section {
    margin-bottom: 36px;
  }

  .hof-title {
    font-size: 1.6rem;
    font-weight: 800;
    margin-bottom: 12px;
  }

  .hof-desc {
    color: #9aa4b2;
    font-size: 13px;
    margin-bottom: 12px;
  }

  .hof-card {
    background: rgba(15, 23, 42, .65);
    border-radius: 14px;
    padding: 16px;
    margin-bottom: 16px;
    box-shadow:
      inset 0 0 0 1px rgba(255, 255, 255, .05),
      0 10px 30px rgba(0, 0, 0, .4);
  }

  .hof-card h4 {
    font-size: 17px;
    font-weight: 700;
    margin-bottom: 10px;
  }

  .hof-table {
    width: 100%;
    font-size: 0.95rem;
  }

  .hof-table th {
    color: #cbd5f5;
    font-weight: 600;
  }

  .hof-table td {
    color: #e5e7eb;
  }

  .hof-note {
    font-size: 13px;
    color: #94a3b8;
    margin-top: 6px;
  }

  .hof-table tr:nth-child(1) td {
    color: gold;
  }

  .hof-table tr:nth-child(2) td {
    color: silver;
  }

  .hof-table tr:nth-child(3) td {
    color: #cd7f32;
  }

  .hof-title {
    position: relative;
    padding-bottom: 6px;
  }

  .hof-title::after {
    content: '';
    display: block;
    margin-top: 6px;
    width: 64px;
    height: 2px;
    background: linear-gradient(90deg, #facc15, rgba(250, 204, 21, 0));
    border-radius: 2px;
  }

  .hof-history-note {
    background: rgba(30, 41, 59, .6);
    border-left: 4px solid #facc15;
    padding: 10px 14px;
    border-radius: 8px;
    font-size: 13px;
    color: #cbd5f5;
    margin-bottom: 18px;
  }

  .hof-table tr:nth-child(1) td:first-child::before {
    content: '👑 ';
  }

  .hof-table tr:nth-child(1) {
    background: linear-gradient(90deg,
        rgba(250, 204, 21, 0.08),
        transparent);
  }

  .hof-vacant {
    color: #64748b !important;
    font-style: italic;
    opacity: .8;
  }

  .btn.btn-xs.btn-primary {
    background: linear-gradient(180deg,
        #3b2f14,
        #1f1a0b);
    border: 1px solid #6b5a2a;
    color: #f5e6a8;

    box-shadow:
      inset 0 0 0 1px rgba(255, 220, 120, 0.12),
      inset 0 8px 16px rgba(0, 0, 0, 0.6),
      0 0 0 1px rgba(0, 0, 0, 0.8);

    text-shadow: 0 1px 2px rgba(0, 0, 0, .9);
  }


  .hof-table tbody tr:hover {
    background: rgba(148, 163, 184, .08);
  }

  /* =========================================
   🖼 Deck ICO：固定正方形，顯示上半部
========================================= */

  .deck img {
    width: 28px;
    height: 28px;

    object-fit: cover;
    /* 裁切多餘部分 */
    object-position: top center;
    /* 顯示上半部 */

    border-radius: 6px;
    margin-right: 4px;

    box-shadow: 0 0 0 1px rgba(255, 255, 255, .15);
    background: #111;
    /* 避免透明圖空白 */
  }


  .deck-name {
    margin-left: 6px;
    font-size: 13px;
    opacity: .85;
  }

  .char-ico {
    position: relative;
    cursor: help;
  }

  .char-ico::before,
  .char-ico::after {
    content: none !important;
    display: none !important;
  }

  /* ===== 全站字體放大 ===== */
  body {
    font-size: 15px;
  }

  .hof-title {
    font-size: 17px;
  }

  .hof-desc,
  .hof-note {
    font-size: 13px;
  }

  .hof-table {
    font-size: 15px;
  }

  .deck-name {
    margin-top: 6px;
    font-size: 15px;
    opacity: .9;
  }

  /* =========================================================
   📱 Mobile Optimization (≤768px)
========================================================= */
  @media (max-width: 768px) {



    /* =====================================================
     🐎 最強黑馬：Table → Card Layout
  ===================================================== */

    /* 隱藏 table header */
    #upsetTopBody tr {
      display: block;
      background: rgba(30, 41, 59, .75);
      border-radius: 14px;
      padding: 14px 16px;
      margin-bottom: 14px;
      box-shadow:
        inset 0 0 0 1px rgba(255, 255, 255, .05),
        0 6px 18px rgba(0, 0, 0, .35);
    }

    #upsetTopBody td {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 6px 0;
      border: none;
      font-size: 15px;
    }

    #upsetTopBody td::before {
      content: attr(data-label);
      color: #94a3b8;
      font-size: 13px;
      margin-right: 10px;
      flex-shrink: 0;
    }

    /* Rank 特殊處理 */
    #upsetTopBody td:first-child {
      justify-content: center;
      font-size: 1.2rem;
      font-weight: 800;
      color: #facc15;
      padding-bottom: 8px;
    }

    #upsetTopBody td:first-child::before {
      display: none;
    }

    /* =====================================================
     🔥 最長連勝牌組
  ===================================================== */

    #deckStreakMaxBody tr {
      display: block;
      padding: 12px;
      margin-bottom: 14px;
      border-radius: 12px;
      background: rgba(30, 41, 59, .75);
    }

    #deckStreakMaxBody td {
      display: block;
      padding: 6px 0;
      border: none;
    }

    /* 牌組區塊：直向 */
    .deck {
      display: flex;
      flex-direction: row;
      align-items: flex-start;
    }

    .deck img {
      width: 36px;
      height: 36px;

      object-fit: cover;
      object-position: top center;
    }



    /* =====================================================
     🖼 ICO 調整
  ===================================================== */

    .deck img {
      box-shadow: 0 0 0 1px rgba(255, 255, 255, .25);
    }

    /* 手機不顯示 hover tooltip（避免誤觸） */
    .char-ico::before,
    .char-ico::after {
      display: none !important;
      content: none !important;
    }

    /* 🚫 手機隱藏最強黑馬表頭（正確版本） */
    .hof-card table thead {
      display: none !important;
    }
  }

  /* =========================
   Clickable Deck (HoF)
========================= */
  .hof-deck-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
  }

  .hof-deck-link:hover {
    transform: translateY(-1px);
  }

  .hof-deck-link:hover .deck {
    box-shadow:
      0 0 0 1px rgba(250, 204, 21, .6),
      0 6px 18px rgba(0, 0, 0, .45);
    background: rgba(250, 204, 21, .05);
  }

  .hof-deck-link:hover .deck::after {
    content: '🔍 查看隊伍分析';
    margin-left: 8px;
    font-size: 12px;
    color: #facc15;
    opacity: .9;
  }

  .hof-clickable {
    cursor: pointer;
  }

  .hof-clickable:hover {
    background: rgba(250, 204, 21, .08);
  }

  /* 目前選中的連勝門檻（文青低調版） */
  .streak-btn.is-active {
    background: linear-gradient(180deg,
        rgba(250, 204, 21, 0.18),
        rgba(250, 204, 21, 0.08));
    border-color: rgba(250, 204, 21, 0.45);
    color: #f8fafc;
    /* 接近白，但不刺眼 */
    font-weight: 600;

    box-shadow:
      inset 0 1px 0 rgba(255, 255, 255, 0.12),
      0 2px 6px rgba(0, 0, 0, 0.35);
  }


  /* 未選中的門檻 */
  .streak-btn:not(.is-active) {
    opacity: .55;
  }

  /* =========================
   Monthly BP / QP Rank
  ========================= */
  .table-full {
    width: 100%;
    overflow-x: auto;
  }

  .hof-rank-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin: 10px 0 8px;
  }

  .hof-rank-toolbar-actions {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
  }

  .hof-rank-expand {
    text-align: center;
    margin-top: 8px;
  }

  .hof-rank-meta {
    font-size: 12px;
    color: #94a3b8;
  }

  .hof-monthly-table th,
  .hof-monthly-table td {
    white-space: nowrap;
  }

  .hof-monthly-table .col-rank { width: 38px; text-align: center; }
  .hof-monthly-table .col-score { text-align: right; font-weight: 700; }
  .hof-monthly-table .col-stat { text-align: right; }

  @media (max-width: 768px) {
    .hof-monthly-table { font-size: 13px; }
    .hof-monthly-table thead { display: table-header-group !important; }
    .hof-monthly-table .hide-mobile { display: none !important; }
    .hof-monthly-table .col-name {
      max-width: 140px;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .hof-rank-toolbar { align-items: flex-start; }
  }
</style>
<?php
$dominance = 5;
// === 來源標記（不參與 id 判斷）===
$from = $_GET['from'] ?? null;
?>
<!-- Content Wrapper -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">


      <div class="hof-section">

        <!-- ================= 歷史 BP 排行榜 ================= -->
        <div class="col-md-6">
          <div class="hof-section">
            <div class="hof-title">📈 歷史 BP 排行榜（每月結算）</div>
            <div class="hof-desc">回顧每個月份結算時的最強玩家</div>

            <div class="hof-card" id="monthlyRankCardBP">
              <div style="margin-bottom:10px;">
                <button class="btn btn-xs btn-primary" id="bpServerTW">TW</button>
                <button class="btn btn-xs btn-default" id="bpServerJP">JP</button>
              </div>

              <div class="hof-season-control" style="margin-bottom:12px;">
                <strong>月份：</strong>
                <span id="monthLabelBP">—</span>
                <input type="range" id="monthSliderBP" min="0" max="0" step="1"
                  style="width:100%; margin-top:8px;">
              </div>

              <div class="hof-rank-toolbar">
                <div class="hof-rank-meta" id="monthlyRankMetaBP">TOP 10</div>
                <div class="hof-rank-toolbar-actions">
                  <button type="button" class="btn btn-default btn-xs" id="exportExcelBP">Excel</button>
                  <button type="button" class="btn btn-default btn-xs" id="exportImageBP">匯出截圖</button>
                </div>
              </div>

              <div class="table-full">
                <table class="hof-table table hof-monthly-table" id="monthlyRankTableBP">
                  <thead>
                    <tr>
                      <th class="col-rank">#</th>
                      <th class="col-name">名稱</th>
                      <th class="col-score">BP</th>
                      <th class="col-stat hide-mobile">勝</th>
                      <th class="col-stat hide-mobile">敗</th>
                      <th class="col-stat hide-mobile">總計</th>
                      <th class="col-stat">勝率</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>

              <div class="hof-note">※ 勝率計算方式：勝場 ÷（勝場 + 敗場 + 平場）</div>
              <div class="hof-rank-expand">
                <button type="button" class="btn btn-xs btn-default" id="toggleTopBP">顯示 TOP 100 ↓</button>
              </div>
            </div>
          </div>
        </div>

        <!-- ================= 歷史 QP 排行榜 ================= -->
        <div class="col-md-6">
          <div class="hof-section">
            <div class="hof-title">📈 歷史 QP 排行榜（每月結算）</div>
            <div class="hof-desc">回顧每個月份結束時的最強玩家</div>

            <div class="hof-card" id="monthlyRankCardQP">
              <div style="margin-bottom:10px;">
                <button class="btn btn-xs btn-primary" id="qpServerTW">TW</button>
                <button class="btn btn-xs btn-default" id="qpServerJP">JP</button>
              </div>

              <div class="hof-season-control" style="margin-bottom:12px;">
                <strong>月份：</strong>
                <span id="monthLabelQP">—</span>
                <input type="range" id="monthSliderQP" min="0" max="0" step="1"
                  style="width:100%; margin-top:8px;">
              </div>

              <div class="hof-rank-toolbar">
                <div class="hof-rank-meta" id="monthlyRankMetaQP">TOP 10</div>
                <div class="hof-rank-toolbar-actions">
                  <button type="button" class="btn btn-default btn-xs" id="exportExcelQP">Excel</button>
                  <button type="button" class="btn btn-default btn-xs" id="exportImageQP">匯出截圖</button>
                </div>
              </div>

              <div class="table-full">
                <table class="hof-table table hof-monthly-table" id="monthlyRankTableQP">
                  <thead>
                    <tr>
                      <th class="col-rank">#</th>
                      <th class="col-name">名稱</th>
                      <th class="col-score">QP</th>
                    </tr>
                  </thead>
                  <tbody></tbody>
                </table>
              </div>

              <div class="hof-rank-expand">
                <button type="button" class="btn btn-xs btn-default" id="toggleTopQP">顯示 TOP 100 ↓</button>
              </div>
            </div>
          </div>
        </div>
        <div>
          <div class="hof-note">※ 月份顯示依照結算當下的月份</div>
        </div>
      </div>

      <!-- ================= 歷史瞬間 ================= -->
      <div class="hof-section">
        <div class="hof-title">📜 歷史瞬間</div>

        <div class="hof-card">
          <h4>
            🐎 最強黑馬（BP 差距 TOP 10）
            <span class="hof-note">（低 BP 勝高 BP｜勝者 BP &gt; 1500｜依 BP 差距排序）</span>
          </h4>
          <table class="hof-table table">
            <thead>
              <tr>
                <th>#</th>
                <th>勝者</th>
                <th>勝者 BP</th>
                <th>勝者牌組</th>
                <th>對方 BP</th>
                <th>對方牌組</th>
                <th>BP 差距</th>
                <th>日期</th>
              </tr>
            </thead>

            <tbody id="upsetTopBody"></tbody>
          </table>
        </div>


        <div class="hof-card">
          <h4>🔥 最長連勝牌組<span class="hof-note"> ( 不限玩家，只計算牌組 )</span></h4>
          <div class="hof-note">
            點擊牌組可查看完整隊伍分析
          </div>
          <table class="hof-table table">
            <thead>
              <tr>
                <th>#</th>
                <th>牌組</th>
                <th>連勝數</th>
                <th>玩家組成</th>
                <th>期間</th>
              </tr>
            </thead>
            <tbody id="deckStreakMaxBody"></tbody>
          </table>
          <div class="hof-note">※ 連勝紀錄將隨資料累積逐步開放</div>
        </div>


        <div class="hof-card">
          <div style="margin-bottom:10px;">
            <span class="hof-note">連勝門檻：</span>
            <button class="btn btn-xs btn-default streak-btn" data-streak="5">5+</button>
            <button class="btn btn-xs btn-default streak-btn" data-streak="10">10+</button>
            <button class="btn btn-xs btn-default streak-btn" data-streak="15">15+</button>
            <button class="btn btn-xs btn-default streak-btn" data-streak="20">20+</button>

          </div>
          <h4>
            👤 最常連勝玩家
            <span class="hof-note">（連勝 ≥ 5 場的累積次數）</span>
          </h4>

          <table class="hof-table table">
            <thead>
              <tr>
                <th>#</th>
                <th>玩家</th>
                <th><span class="streak-label">5+</span> 連勝次數</th>
                <th>最高連勝</th>
                <th>最後紀錄</th>
              </tr>
            </thead>
            <tbody id="playerStreakCountBody"></tbody>
          </table>

          <div class="hof-note">
            ※ 不顯示牌組，僅統計玩家歷史連勝表現
          </div>
        </div>

        <!-- <div class="hof-card">
          <h4>
            👤 最高連勝玩家
            <span class="hof-note">
              （排序：最高連勝 → 連勝次數｜僅統計 ≥ 5 連勝）
            </span>
          </h4>

          <table class="hof-table table">
            <thead>
              <tr>
                <th>#</th>
                <th>玩家</th>
                <th>最高連勝</th>
                <th><span class="streak-label">5+</span> 連勝次數</th>
                <th>最後紀錄</th>
              </tr>
            </thead>
            <tbody id="playerMaxStreakBody"></tbody>
          </table>
        </div> -->


      </div>
      <!-- ================= 永久紀錄 ================= -->
      <div class="hof-history-note">
        ⏳ 本 BQ & QP 排行榜自 <strong>2025-05-06</strong> 起開始記錄，早期資料未納入統計。
      </div>
      <div class="hof-section">
        <div class="hof-title">🏆 永久紀錄</div>
        <div class="hof-desc">歷史極值紀錄，僅會被刷新，不會被取代</div>
        <div style="margin-bottom:10px;">
          <button class="btn btn-xs btn-primary" id="peakServerTW">TW</button>
          <button class="btn btn-xs btn-default" id="peakServerJP">JP</button>
        </div>

        <div class="row">
          <div class="col-md-6">
            <div class="hof-card">
              <h4>BP 歷史最高分 TOP 10 ( 玩家去重 )</h4>
              <table class="hof-table table">
                <tbody id="bpPeakBody"></tbody>
              </table>

              <!-- ⏱ 更新頻率備註 -->
              <div class="hof-note text-right">
                ※ 排行資料每 <strong>30 分鐘</strong> 上傳一次，記錄時間點可能與實際即時狀態略有差異。
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="hof-card">
              <h4>QP 歷史最高分 TOP 10 ( 玩家去重 )</h4>
              <table class="hof-table table">
                <tbody id="qpPeakBody"></tbody>
              </table>

            </div>

          </div>
        </div>
      </div>

      <!-- ================= 長期統治者 ================= -->
      <div class="hof-section">
        <div class="hof-title">👑 長期統治者</div>
        <div class="hof-desc">
          累積時間的王者，而非單次爆發
        </div>
        <div style="margin-bottom:10px;">
          <button class="btn btn-xs btn-primary" id="domServerTW">TW</button>
          <button class="btn btn-xs btn-default" id="domServerJP">JP</button>
        </div>

        <div class="row">
          <div class="col-md-6">
            <div class="hof-card">
              <h4>BP 第一名累積天數 TOP <?php echo $dominance ?></h4>
              <table class="hof-table table">
                <tbody id="bpDominanceBody"></tbody>
              </table>
            </div>
          </div>

          <div class="col-md-6">
            <div class="hof-card">
              <h4>QP 第一名累積天數 TOP <?php echo $dominance ?></h4>
              <table class="hof-table table">
                <tbody id="qpDominanceBody"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>










    </div>
  </section>
</div>



<!-- 共用 debounce -->
<script>
  function debounce(fn, delay = 300) {
    let t = null;
    return (...args) => {
      clearTimeout(t);
      t = setTimeout(() => fn(...args), delay);
    };
  }
</script>


<!-- SheetJS：歷史 BP / QP Excel 匯出 -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<!-- Monthly Rank Controller -->
<script>
  const monthlyRankCache = new Map();

  async function fetchMonthlyRank({ type, region, ym, limit = 10, signal }) {
    const qs = new URLSearchParams({ type, region, limit: String(limit) });
    if (ym) qs.append('ym', ym);

    const key = `${type}|${region}|${ym || 'latest'}|${limit}`;
    if (monthlyRankCache.has(key)) return monthlyRankCache.get(key);

    const res = await fetch(`/api/hof_monthly_rank.php?${qs.toString()}`, { signal });
    if (!res.ok) throw new Error('API failed');

    const json = await res.json();
    monthlyRankCache.set(key, json);
    return json;
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function initMonthlyRank(config) {
    const slider = document.getElementById(config.sliderId);
    const label = document.getElementById(config.labelId);
    const tbody = document.querySelector(`#${config.tableId} tbody`);
    const toggleBtn = document.getElementById(config.toggleId);
    const excelBtn = document.getElementById(config.excelId);
    const imageBtn = document.getElementById(config.imageId);
    const meta = document.getElementById(config.metaId);
    const card = document.getElementById(config.cardId);

    let months = [];
    let currentType = config.type;
    let currentRegion = 'TW';
    let expanded = false;
    let currentJson = null;
    let controller = null;

    function currentLimit() {
      return expanded ? 100 : 10;
    }

    function setBusy(busy) {
      [toggleBtn, excelBtn, imageBtn].forEach(btn => {
        if (btn) btn.disabled = busy;
      });
    }

    function render(json) {
      currentJson = json;
      const rank = json?.rank || [];
      tbody.innerHTML = '';

      if (!rank.length) {
        const colspan = currentType === 'bp' ? 7 : 3;
        tbody.innerHTML = `<tr><td colspan="${colspan}">尚無資料</td></tr>`;
      } else if (currentType === 'bp') {
        rank.forEach(r => {
          const win = Number(r.win_ranked || 0);
          const lose = Number(r.lose_ranked || 0);
          const draw = Number(r.draw_ranked || 0);
          const total = win + lose + draw;
          const rate = total > 0 ? (win / total * 100).toFixed(1) + '%' : '0%';
          tbody.insertAdjacentHTML('beforeend', `
            <tr>
              <td class="col-rank">${r.rank}</td>
              <td class="col-name">${escapeHtml(r.name)}</td>
              <td class="col-score">${r.value}</td>
              <td class="col-stat hide-mobile">${win}</td>
              <td class="col-stat hide-mobile">${lose}</td>
              <td class="col-stat hide-mobile">${total}</td>
              <td class="col-stat">${rate}</td>
            </tr>
          `);
        });
      } else {
        rank.forEach(r => {
          tbody.insertAdjacentHTML('beforeend', `
            <tr>
              <td class="col-rank">${r.rank}</td>
              <td class="col-name">${escapeHtml(r.name)}</td>
              <td class="col-score">${r.value}</td>
            </tr>
          `);
        });
      }

      const shown = rank.length;
      meta.textContent = `TOP ${currentLimit()}${shown < currentLimit() ? `｜本月共收錄 ${shown} 名` : ''}`;
      toggleBtn.textContent = expanded ? '收合 TOP 10 ↑' : '顯示 TOP 100 ↓';
    }

    async function requestRank({ ym = null, limit = currentLimit() } = {}) {
      if (controller) controller.abort();
      controller = new AbortController();
      setBusy(true);
      try {
        const json = await fetchMonthlyRank({
          type: currentType,
          region: currentRegion,
          ym,
          limit,
          signal: controller.signal
        });
        return json;
      } finally {
        setBusy(false);
      }
    }

    async function load({ region }) {
      currentRegion = region;
      const json = await requestRank({ limit: currentLimit() });
      months = json.months || [];

      if (!months.length) {
        slider.max = 0;
        label.textContent = '—';
        render(json);
        return;
      }

      slider.max = months.length - 1;
      slider.value = months.length - 1;
      label.textContent = months[slider.value];
      render(json);
    }

    const onSlide = debounce(async () => {
      const ym = months[slider.value];
      if (!ym) return;
      label.textContent = ym;
      try {
        const json = await requestRank({ ym, limit: currentLimit() });
        render(json);
      } catch (e) {
        if (e.name !== 'AbortError') console.error(e);
      }
    }, 300);

    slider.addEventListener('input', onSlide);

    toggleBtn.addEventListener('click', async () => {
      expanded = !expanded;
      const ym = months[slider.value] || null;

      // 收合時若目前已有 TOP100，不重新打 API，直接切前10筆。
      if (!expanded && currentJson?.rank?.length > 10) {
        render({ ...currentJson, rank: currentJson.rank.slice(0, 10) });
        return;
      }

      try {
        const json = await requestRank({ ym, limit: currentLimit() });
        render(json);
      } catch (e) {
        expanded = !expanded;
        if (e.name !== 'AbortError') console.error(e);
      }
    });

    excelBtn.addEventListener('click', async () => {
      if (typeof XLSX === 'undefined') {
        alert('Excel 匯出元件尚未載入，請重新整理後再試。');
        return;
      }

      const ym = months[slider.value] || null;
      try {
        const json = await requestRank({ ym, limit: 100 });
        const rows = (json.rank || []).map(r => {
          if (currentType === 'bp') {
            const win = Number(r.win_ranked || 0);
            const lose = Number(r.lose_ranked || 0);
            const draw = Number(r.draw_ranked || 0);
            const total = win + lose + draw;
            return {
              '排名': Number(r.rank),
              '名稱': r.name || '',
              'BP': Number(r.value || 0),
              '勝': win,
              '敗': lose,
              '總計': total,
              '勝率': total > 0 ? +(win / total * 100).toFixed(1) / 100 : 0
            };
          }
          return { '排名': Number(r.rank), '名稱': r.name || '', 'QP': Number(r.value || 0) };
        });

        const ws = XLSX.utils.json_to_sheet(rows);
        if (currentType === 'bp') {
          for (let i = 2; i <= rows.length + 1; i++) {
            if (ws[`G${i}`]) ws[`G${i}`].z = '0.0%';
          }
          ws['!cols'] = [{wch:8},{wch:24},{wch:10},{wch:8},{wch:8},{wch:10},{wch:10}];
        } else {
          ws['!cols'] = [{wch:8},{wch:24},{wch:12}];
        }

        const wb = XLSX.utils.book_new();
        const month = json.meta?.ym || ym || 'unknown';
        const sheetName = `${currentType.toUpperCase()}_${currentRegion}_${month}`.slice(0, 31);
        XLSX.utils.book_append_sheet(wb, ws, sheetName);
        XLSX.writeFile(wb, `ULGG_${currentType.toUpperCase()}_${currentRegion}_${month}_TOP100.xlsx`);
      } catch (e) {
        if (e.name !== 'AbortError') {
          console.error(e);
          alert('Excel 匯出失敗');
        }
      }
    });

    imageBtn.addEventListener('click', async () => {
      if (typeof html2canvas === 'undefined') {
        alert('截圖元件尚未載入，請重新整理後再試。');
        return;
      }

      // 與 bp_rank.php 操作一致：匯出當下顯示內容；暫時隱藏操作按鈕避免截進圖片。
      const controls = card.querySelectorAll('.hof-rank-toolbar-actions, .hof-rank-expand, input[type="range"], #bpServerTW, #bpServerJP, #qpServerTW, #qpServerJP');
      const previous = [];
      controls.forEach(el => { previous.push([el, el.style.visibility]); el.style.visibility = 'hidden'; });

      try {
        const canvas = await html2canvas(card, { backgroundColor: '#0f172a', scale: 2 });
        const month = currentJson?.meta?.ym || months[slider.value] || 'unknown';
        const top = currentLimit();
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        if (!blob) throw new Error('PNG blob failed');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `ULGG_${currentType.toUpperCase()}_${currentRegion}_${month}_TOP${top}.png`;
        a.click();
        URL.revokeObjectURL(a.href);
      } catch (e) {
        console.error(e);
        alert('截圖匯出失敗');
      } finally {
        previous.forEach(([el, visibility]) => { el.style.visibility = visibility; });
      }
    });

    return { load };
  }
</script>

<!-- BP / QP 初始化 -->
<script>
  let bpRegion = 'TW';
  let qpRegion = 'TW';

  function updateServerUI(prefix, region) {
    document.getElementById(`${prefix}ServerTW`).className =
      'btn btn-xs ' + (region === 'TW' ? 'btn-primary' : 'btn-default');
    document.getElementById(`${prefix}ServerJP`).className =
      'btn btn-xs ' + (region === 'JP' ? 'btn-primary' : 'btn-default');
  }

  const bpRank = initMonthlyRank({
    type: 'bp', sliderId: 'monthSliderBP', labelId: 'monthLabelBP',
    tableId: 'monthlyRankTableBP', toggleId: 'toggleTopBP',
    excelId: 'exportExcelBP', imageId: 'exportImageBP',
    metaId: 'monthlyRankMetaBP', cardId: 'monthlyRankCardBP'
  });

  const qpRank = initMonthlyRank({
    type: 'qp', sliderId: 'monthSliderQP', labelId: 'monthLabelQP',
    tableId: 'monthlyRankTableQP', toggleId: 'toggleTopQP',
    excelId: 'exportExcelQP', imageId: 'exportImageQP',
    metaId: 'monthlyRankMetaQP', cardId: 'monthlyRankCardQP'
  });

  document.getElementById('bpServerTW').onclick = () => {
    bpRegion = 'TW'; updateServerUI('bp', bpRegion); bpRank.load({ region: bpRegion });
  };
  document.getElementById('bpServerJP').onclick = () => {
    bpRegion = 'JP'; updateServerUI('bp', bpRegion); bpRank.load({ region: bpRegion });
  };
  document.getElementById('qpServerTW').onclick = () => {
    qpRegion = 'TW'; updateServerUI('qp', qpRegion); qpRank.load({ region: qpRegion });
  };
  document.getElementById('qpServerJP').onclick = () => {
    qpRegion = 'JP'; updateServerUI('qp', qpRegion); qpRank.load({ region: qpRegion });
  };

  updateServerUI('bp', bpRegion);
  updateServerUI('qp', qpRegion);
  bpRank.load({ region: bpRegion });
  qpRank.load({ region: qpRegion });
</script>

<!-- 歷史最高分 TOP 10 -->
<script>
  async function loadPeakTable({
    type,
    region,
    tableBodyId,
    limit = 10
  }) {
    const tbody = document.getElementById(tableBodyId);
    tbody.innerHTML = '<tr><td colspan="4">載入中...</td></tr>';

    try {
      const res = await fetch(
        `/api/hof_bpqp_peak.php?type=${type}&region=${region}&limit=${limit}`
      );
      const json = await res.json();

      tbody.innerHTML = '';

      if (!json.items || !json.items.length) {
        tbody.innerHTML = '<tr><td colspan="4">尚無資料</td></tr>';
        return;
      }

      json.items.forEach(row => {
        tbody.insertAdjacentHTML('beforeend', `
        <tr>
          <td data-label="#">${row.rank}</td>
          <td>${row.name}</td>
          <td>${row.value}</td>
          <td>${row.date}</td>
        </tr>
      `);
      });

    } catch (e) {
      tbody.innerHTML = '<tr><td colspan="4">讀取失敗</td></tr>';
      console.error(e);
    }
  }
</script>



<!-- 加入「永久紀錄區域狀態」 -->
<script>
  // ===============================
  // 永久紀錄：區域狀態
  // ===============================
  let peakRegion = 'TW';

  function updatePeakServerUI() {
    document.getElementById('peakServerTW')
      .className = 'btn btn-xs ' + (peakRegion === 'TW' ? 'btn-primary' : 'btn-default');

    document.getElementById('peakServerJP')
      .className = 'btn btn-xs ' + (peakRegion === 'JP' ? 'btn-primary' : 'btn-default');
  }

  function reloadPeakTables() {
    loadPeakTable({
      type: 'bp',
      region: peakRegion,
      tableBodyId: 'bpPeakBody',
      limit: 10
    });

    loadPeakTable({
      type: 'qp',
      region: peakRegion,
      tableBodyId: 'qpPeakBody',
      limit: 10
    });
  }

  document.getElementById('peakServerTW').onclick = () => {
    peakRegion = 'TW';
    updatePeakServerUI();
    reloadPeakTables();
  };

  document.getElementById('peakServerJP').onclick = () => {
    peakRegion = 'JP';
    updatePeakServerUI();
    reloadPeakTables();
  };
</script>
<!--歷史最高分 TOP 10 初始化（頁面載入） -->
<script>
  updatePeakServerUI();
  reloadPeakTables();
</script>

<!-- 長期統治者 -->
<script>
  async function loadDominance({
    type,
    region,
    tbodyId,
    limit = <?php echo $dominance ?>
  }) {
    const tbody = document.getElementById(tbodyId);
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="3">載入中...</td></tr>';

    try {
      const res = await fetch(
        `/api/hof_dominance.php?type=${type}&region=${region}&limit=${limit}`
      );
      const json = await res.json();

      tbody.innerHTML = '';

      if (!json.items || !json.items.length) {
        tbody.innerHTML = '<tr><td colspan="3">尚無資料</td></tr>';
        return;
      }

      json.items.forEach(row => {
        const isVacant = row.name.includes('從缺');

        const range = (!isVacant && row.first_date && row.last_date) ?
          ` <span class="hof-note">（${row.first_date} ～ ${row.last_date}）</span>` :
          '';
        const nameClass = isVacant ? 'hof-vacant' : '';
        const daysText = isVacant ? '—' : `${row.days} 天`;
        tbody.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${row.rank}</td>
            <td>${row.name}</td>
            <td>
              ${daysText}
              ${range}
            </td>
          </tr>
        `);
      });
    } catch (e) {
      console.error(e);
      tbody.innerHTML = '<tr><td colspan="3">讀取失敗</td></tr>';
    }
  }
</script>



<!-- 長期統治者專用的 JS 狀態機 -->
<script>
  // ===============================
  // 長期統治者：區域狀態
  // ===============================
  let domRegion = 'TW';

  function updateDomServerUI() {
    document.getElementById('domServerTW').className =
      'btn btn-xs ' + (domRegion === 'TW' ? 'btn-primary' : 'btn-default');

    document.getElementById('domServerJP').className =
      'btn btn-xs ' + (domRegion === 'JP' ? 'btn-primary' : 'btn-default');
  }

  function reloadDominanceTables() {
    loadDominance({
      type: 'bp',
      region: domRegion,
      tbodyId: 'bpDominanceBody'
    });

    loadDominance({
      type: 'qp',
      region: domRegion,
      tbodyId: 'qpDominanceBody'
    });
  }

  document.getElementById('domServerTW').onclick = () => {
    domRegion = 'TW';
    updateDomServerUI();
    reloadDominanceTables();
  };

  document.getElementById('domServerJP').onclick = () => {
    domRegion = 'JP';
    updateDomServerUI();
    reloadDominanceTables();
  };

  // 初始化
  updateDomServerUI();
  reloadDominanceTables();
</script>

<script>
  const IMG_BASE = "<?= IMG_BASE ?>";
</script>



<!-- 最強黑馬（BP 差距 TOP） -->
<script>
  async function loadUpsetTop({
    region = 'TW',
    limit = 10
  } = {}) {
    const tbody = document.getElementById('upsetTopBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="8">載入中...</td></tr>';

    try {
      const res = await fetch(`/api/hof_upset_top.php?region=${region}&limit=${limit}`);
      const json = await res.json();

      tbody.innerHTML = '';

      if (!json.data || !json.data.length) {
        tbody.innerHTML = '<tr><td colspan="8">尚無資料</td></tr>';
        return;
      }

      const renderDeckICO = (deck) => `
        <div class="deck">
          ${deck.map(c => `
            <img
              src="${IMG_BASE}placeholder.png"
              data-src="${IMG_BASE}${c.ico}"
              class="char-ico lazy"
              loading="lazy"
              alt="${c.level} ${c.name}"
            >
          `).join('')}
        </div>
      `;

      json.data.forEach(row => {
        const d = row.winner_deck;

        // ⭐ 組 team_analysis 連結
        const link =
          `team_analysis.php` +
          `?id1=${d[0].id}` +
          `&id2=${d[1].id}` +
          `&id3=${d[2].id}` +
          `&from=hof_upset`;
        tbody.insertAdjacentHTML('beforeend', `
        <tr class="hof-clickable" data-link="${link}">
          <td>${row.rank}</td>
          <td><strong>${row.winner_id}</strong></td>
          <td data-label="勝者 BP">${row.winner_bp}</td>
          <td data-label="勝者牌組">${renderDeckICO(row.winner_deck)}</td>
          <td data-label="對方 BP">${row.loser_bp}</td>
          <td data-label="對方牌組">${renderDeckICO(row.loser_deck)}</td>
          <td data-label="BP 差距">+${row.bp_diff}</td>
          <td data-label="日期">${row.date}</td>
        </tr>
      `);
      });
      initLazyImages(); // ⭐⭐⭐ 就是這一行

    } catch (e) {
      console.error(e);
      tbody.innerHTML = '<tr><td colspan="8">讀取失敗</td></tr>';
    }
  }

  // 初始化
  loadUpsetTop({
    region: 'TW'
  });
</script>


<!-- 🔥 最長連勝牌組（tr 可點擊版） -->
<script>
  async function loadDeckStreakMax({
    limit = 10,
    minStreak = 5
  } = {}) {

    const tbody = document.getElementById('deckStreakMaxBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="5">載入中...</td></tr>';

    try {
      const res = await fetch(
        `/api/hof_deck_streak_max.php?limit=${limit}&min_streak=${minStreak}`
      );
      const json = await res.json();

      tbody.innerHTML = '';

      if (!json.data || !json.data.length) {
        tbody.innerHTML = '<tr><td colspan="5">尚無資料</td></tr>';
        return;
      }

      json.data.forEach(row => {
        const d = row.deck;

        const playersHTML = row.players && row.players.length ?
          row.players.join('、') :
          '<span class="hof-vacant">未知</span>';

        const deckHTML = `
        <div class="deck">
          <img src="${IMG_BASE}${d.leader.ico}" class="char-ico"
            title="${d.leader.level} ${d.leader.name}">
          <img src="${IMG_BASE}${d.back1.ico}" class="char-ico"
            title="${d.back1.level} ${d.back1.name}">
          <img src="${IMG_BASE}${d.back2.ico}" class="char-ico"
            title="${d.back2.level} ${d.back2.name}">
            <br>
          <span class="deck-name">
            ${d.leader.level}${d.leader.name}
            /
            ${d.back1.level}${d.back1.name}
            /
            ${d.back2.level}${d.back2.name}
          </span>
        </div>
      `;

        const link =
          `team_analysis.php` +
          `?id1=${d.leader.id}` +
          `&id2=${d.back1.id}` +
          `&id3=${d.back2.id}` +
          `&from=hof_streak`;

        tbody.insertAdjacentHTML('beforeend', `
        <tr class="hof-clickable" data-link="${link}">
          <td data-label="#">${row.rank}</td>
          <td data-label="牌組">${deckHTML}</td>
          <td data-label="連勝數">${row.streak}</td>
          <td data-label="玩家">${playersHTML}</td>
          <td data-label="期間">${row.period}</td>
        </tr>
      `);
      });

    } catch (e) {
      console.error(e);
      tbody.innerHTML = '<tr><td colspan="5">讀取失敗</td></tr>';
    }
  }

  // 初始化
  loadDeckStreakMax({
    limit: 10,
    minStreak: 5
  });
</script>
<script>
  document.addEventListener('click', function(e) {
    const tr = e.target.closest('tr.hof-clickable');
    if (!tr) return;

    // 點到 a / button 不攔截（保險）
    if (e.target.closest('a, button')) return;

    const link = tr.dataset.link;
    if (link) {
      window.location.href = link;
    }
  });
</script>

<!-- renderInChunks（避免一次塞爆 DOM） -->
<script>
  function initLazyImages() {
    const lazyImages = document.querySelectorAll('img.lazy');

    if (!lazyImages.length) return;

    // 舊瀏覽器 fallback
    if (!('IntersectionObserver' in window)) {
      lazyImages.forEach(img => {
        if (img.dataset.src) {
          img.src = img.dataset.src;
        }
      });
      return;
    }

    const io = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;

        const img = entry.target;
        img.src = img.dataset.src;
        img.classList.remove('lazy');
        io.unobserve(img);
      });
    }, {
      rootMargin: '200px'
    });

    lazyImages.forEach(img => io.observe(img));
  }
  document.addEventListener('DOMContentLoaded', initLazyImages);
</script>

<script>
  async function loadPlayerStreakCount({
    limit = 15,
    minStreak = 5
  } = {}) {
    const tbody = document.getElementById('playerStreakCountBody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="5">載入中...</td></tr>';

    try {
      const res = await fetch(
        `/api/hof_player_streak_count.php?limit=${limit}&min_streak=${minStreak}`
      );
      const json = await res.json();

      tbody.innerHTML = '';

      if (!json.data || !json.data.length) {
        tbody.innerHTML = '<tr><td colspan="5">尚無資料</td></tr>';
        return;
      }

      json.data.forEach(row => {
        const link = `fight.php?player_name=${encodeURIComponent(row.player)}`;
        tbody.insertAdjacentHTML('beforeend', `
        <tr class="hof-clickable" data-link="${link}">
      <td>${row.rank}</td>
      <td><strong>${row.player}</strong></td>
      <td>${row.streak_count} 次</td>
      <td>${row.max_streak}</td>
      <td>${row.last_date}</td>
    </tr>
      `);
      });

    } catch (e) {
      console.error(e);
      tbody.innerHTML = '<tr><td colspan="5">讀取失敗</td></tr>';
    }
  }

  // 初始化
  loadPlayerStreakCount({
    limit: 15,
    minStreak: 5
  });
</script>



<script>
  let currentMinStreak = 5;
</script>
<script>
  function updateStreakButtons() {
    document.querySelectorAll('.streak-btn').forEach(btn => {
      const v = parseInt(btn.dataset.streak, 10);

      btn.classList.remove('is-active');

      if (v === currentMinStreak) {
        btn.classList.add('is-active');
      }
    });
  }


  document.querySelectorAll('.streak-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      currentMinStreak = parseInt(btn.dataset.streak, 10);
      updateStreakButtons();
      updateStreakLabels(); // ⭐ 新增這行
      // 兩個榜單一起重載
      loadPlayerStreakCount({
        limit: 15,
        minStreak: currentMinStreak
      });

    });
  });
</script>
<script>
  updateStreakButtons();

  loadPlayerStreakCount({
    limit: 15,
    minStreak: currentMinStreak
  });

  function updateStreakLabels() {
    document.querySelectorAll('.streak-label').forEach(el => {
      el.textContent = currentMinStreak + '+';
    });
  }
</script>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>