<?php
// ⚠️ 不要 session_start()，交給 config.php
require_once __DIR__ . '/../config.php';
$pdo = $db; // 若你 config.php 用 $pdo，這行改成 $pdo = $pdo;

$pageTitleText = '技能戰術搜尋';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "skills";


$q = trim($_GET['q'] ?? '');

function h($v): string
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

ob_start();
ob_start();

/* =====================================================
 *  Skill Tag × Dictionary × usage_count（即時計算）
 * ===================================================== */
$sql = "SELECT
  d.dimension,
  d.tag,
  d.label_zh,
  d.label_ja,
  d.description,
  COUNT(st.skill_code) AS usage_count
FROM unlight_skill_tag_dictionary d
LEFT JOIN unlight_skill_tag st
  ON st.tag = d.tag
GROUP BY
  d.dimension,
  d.tag,
  d.label_zh,
  d.label_ja,
  d.description
HAVING
  COUNT(st.skill_code) > 0
ORDER BY
  FIELD(d.dimension,'effect','mechanic','status','form','condition','target'),
  tag ASC,
  d.tag;
";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* 依 dimension 分組 */
$tagsByDim = [];
foreach ($rows as $r) {
  $dim = $r['dimension'] ?: 'other';
  $tagsByDim[$dim][] = $r;
}
?>

<style>
  /* ===============================
 * Tech Tag Overview Styles
 * =============================== */

  .tech-wrapper {
    max-width: 1100px;
    margin: 0 auto;
  }

  .tech-tag-box {
    border: 1px solid #2a2f3a;
    border-left-width: 6px;
    border-radius: 8px;
    padding: 16px 18px;
    margin-bottom: 28px;
    background: #12151c;
  }

  .tech-tag-box h3 {
    margin: 0 0 12px 0;
    font-size: 18px;
    font-weight: 700;
  }

  .tech-tag {
    display: inline-block;
    padding: 6px 10px;
    margin: 4px 6px 4px 0;
    border-radius: 6px;
    font-size: 13px;
    background: #1e2330;
    color: #d7ddff;
    cursor: help;
  }

  .tech-tag small {
    opacity: .6;
    margin-left: 6px;
  }

  /* Dimension color */
  .dim-effect {
    border-left-color: #4aa3ff;
  }

  .dim-mechanic {
    border-left-color: #57d38c;
  }

  .dim-status {
    border-left-color: #ff6b6b;
  }


  .dim-form {
    border-left-color: #ff6bd5;
  }

  .dim-condition {
    border-left-color: #ffffff;
  }

  .dim-target {
    border-left-color: #f3c969;
  }

  .dim-other {
    border-left-color: #999;
  }

  .dim-desc {
    font-size: 13px;
    opacity: .7;
    margin-bottom: 10px;
  }
</style>

<style>
  .skill-page-wrap {
    max-width: 1180px;
    margin: 0 auto;
  }

  .skill-search-panel {
    background: linear-gradient(180deg, #1b1e27, #11131a);
    border: 1px solid rgba(120, 120, 255, 0.25);
    border-radius: 10px;
    padding: 14px;
    margin: 10px 0 14px;
    box-shadow: 0 0 12px rgba(0, 0, 0, 0.45);
  }

  .skill-search-title {
    font-size: 22px;
    font-weight: 700;
    color: #fff;
    text-shadow: 0 0 8px rgba(120, 120, 255, 0.6);
    margin-bottom: 4px;
  }

  .skill-search-desc {
    color: #b0bec5;
    font-size: 13px;
    margin-bottom: 14px;
  }

  .skill-filter-grid {
    display: grid;
    gap: 10px;
    align-items: end;
  }

  .skill-search-filter-grid {
    grid-template-columns: 1.2fr 1fr 1fr;
  }

  .skill-focus-filter-grid {
    grid-template-columns: 1fr 1fr 1fr 1fr;
  }

  .skill-focus-keyword {
    grid-column: 1 / -1;
  }

  .skill-filter-grid label {
    display: block;
    color: #cfd8dc;
    font-size: 12px;
    margin-bottom: 4px;
  }

  .skill-filter-grid input,
  .skill-filter-grid select {
    width: 100%;
    height: 34px;
    background: #0b1220;
    color: #e5e7eb;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 8px;
    padding: 6px 9px;
    font-size: 13px;
  }

  .skill-filter-grid input:focus,
  .skill-filter-grid select:focus {
    outline: none;
    border-color: rgba(120, 120, 255, 0.6);
    box-shadow: 0 0 0 2px rgba(120, 120, 255, 0.2);
  }

  .skill-filter-actions {
    display: flex;
    gap: 8px;
    margin-top: 12px;
    flex-wrap: wrap;
  }

  .skill-search-btn,
  .skill-reset-btn {
    border: 1px solid rgba(120, 150, 255, .35);
    border-radius: 8px;
    padding: 7px 14px;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
  }

  .skill-search-btn {
    background: #4dabf7;
    color: #fff;
    border-color: rgba(120, 180, 255, .8);
  }

  .skill-search-btn:hover {
    background: #6fbaff;
  }

  .skill-reset-btn {
    background: #2e3440;
    color: #cbd5f5;
  }

  .skill-reset-btn:hover {
    color: #fff;
    background: #3b4252;
    text-decoration: none;
  }

  .skill-result-area {
    margin-top: 14px;
  }

  .skill-loading {
    color: #b0bec5;
    font-size: 13px;
    padding: 12px;
  }

  @media (max-width: 900px) {
    .skill-search-filter-grid {
      grid-template-columns: 1fr;
    }

    .skill-focus-filter-grid {
      grid-template-columns: 1fr 1fr;
    }

    .skill-focus-keyword {
      grid-column: 1 / -1;
    }
  }

  @media (max-width: 600px) {
    .skill-focus-filter-grid {
      grid-template-columns: 1fr;
    }
  }

  .skill-check-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    min-height: 34px;
    align-items: center;
  }

  .skill-check {
    display: inline-flex !important;
    align-items: center;
    gap: 5px;
    margin: 0 !important;
    padding: 6px 9px;
    border-radius: 8px;
    background: #0b1220;
    border: 1px solid rgba(255, 255, 255, .18);
    color: #e5e7eb !important;
    font-size: 13px !important;
    cursor: pointer;
  }

  .skill-check input {
    width: auto !important;
    height: auto !important;
    margin: 0;
  }

  .skill-check:hover {
    border-color: rgba(120, 120, 255, 0.6);
    background: rgba(120, 120, 255, 0.12);
  }

  .skill-focus-table-wrap {
    background: linear-gradient(180deg, #141824, #0d1018);
    border: 1px solid rgba(120, 120, 255, 0.28);
    border-radius: 10px;
    padding: 12px;
    margin-top: 12px;
    overflow-x: auto;
    box-shadow: 0 0 14px rgba(0, 0, 0, 0.45);
  }

  .skill-focus-table {
    width: 100%;
    border-collapse: collapse;
    color: #e5e7eb;
    font-size: 14px;
    background: transparent;
  }

  .skill-focus-table thead th {
    background: linear-gradient(180deg, #25283a, #151824);
    color: #ffffff;
    font-size: 13px;
    font-weight: 700;
    padding: 10px 9px;
    border-bottom: 1px solid rgba(120, 120, 255, 0.35);
    white-space: nowrap;
    text-align: left;
  }

  .skill-focus-table tbody tr {
    background: rgba(255, 255, 255, 0.025);
  }

  .skill-focus-table tbody tr:nth-child(even) {
    background: rgba(120, 120, 255, 0.055);
  }

  .skill-focus-table tbody tr:hover {
    background: rgba(120, 120, 255, 0.16);
  }

  .skill-focus-table td {
    padding: 11px 9px;
    border-top: 1px solid rgba(255, 255, 255, 0.08);
    vertical-align: top;
    color: #e5e7eb;
  }

  .skill-focus-char {
    min-width: 90px;
    font-weight: 700;
    color: #ffffff;
  }

  .skill-focus-card {
    width: 74px;
    color: #fff6b8;
    font-weight: 800;
    white-space: nowrap;
  }

  .skill-focus-distance {
    width: 58px;
    color: #b7e4ff;
    font-weight: 700;
    white-space: nowrap;
  }

  .skill-focus-count {
    width: 64px;
    color: #a5d6a7;
    font-size: 16px;
    text-align: center;
    white-space: nowrap;
  }

  .skill-focus-skills {
    line-height: 1.8;
    color: #dbe4ff;
    word-break: break-word;
  }

  .skill-focus-sep {
    color: #7f8db5;
    margin: 0 3px;
  }

  @media (max-width: 768px) {
    .skill-focus-table {
      font-size: 13px;
      min-width: 760px;
    }

    .skill-focus-table td,
    .skill-focus-table th {
      padding: 9px 7px;
    }
  }

  .skill-focus-result-note {
    color: #9fa8da;
    font-size: 13px;
    margin: 12px 0 8px;
  }

  .skill-focus-char-link,
  .skill-focus-card-link {
    color: inherit;
    text-decoration: none;
  }

  .skill-focus-char-link:hover,
  .skill-focus-card-link:hover {
    color: #ffffff;
    text-decoration: underline;
    text-shadow: 0 0 6px rgba(120, 180, 255, 0.7);
  }

  .skill-search-list-table th:nth-child(1),
  .skill-search-list-table td:nth-child(1) {
    width: 90px;
  }

  .skill-search-list-table th:nth-child(2),
  .skill-search-list-table td:nth-child(2) {
    width: 78px;
  }

  .skill-search-list-table th:nth-child(3),
  .skill-search-list-table td:nth-child(3) {
    width: 64px;
  }

  .skill-search-list-table th:nth-child(4),
  .skill-search-list-table td:nth-child(4) {
    width: 72px;
  }

  .skill-search-list-table th:nth-child(5),
  .skill-search-list-table td:nth-child(5) {
    width: 110px;
  }

  .skill-search-phase {
    color: #ffd9a3;
    font-weight: 700;
    white-space: nowrap;
  }

  .skill-search-require {
    white-space: nowrap;
  }

  .skill-search-name {
    min-width: 150px;
    font-weight: 700;
    color: #ffffff;
  }

  .skill-search-skill-link {
    color: inherit;
    text-decoration: none;
  }

  .skill-search-skill-link:hover {
    color: #ffffff;
    text-decoration: underline;
    text-shadow: 0 0 6px rgba(120, 180, 255, 0.7);
  }

  .skill-search-info {
    min-width: 260px;
    line-height: 1.65;
    color: #dbe4ff;
    font-size: 13px;
  }

  .skill-search-tags {
    display: flex;
    gap: 4px;
    flex-wrap: wrap;
    margin-top: 5px;
  }

  @media (max-width: 768px) {
    .skill-search-list-table {
      min-width: 980px;
    }
  }

  .js-sort-table th[data-sort-type] {
    cursor: pointer;
    user-select: none;
  }

  .js-sort-table th[data-sort-type]::after {
    content: ' ↕';
    color: #7f8db5;
    font-size: 11px;
  }

  .js-sort-table th.sort-asc::after {
    content: ' ↑';
    color: #ffffff;
  }

  .js-sort-table th.sort-desc::after {
    content: ' ↓';
    color: #ffffff;
  }

  .skill-keyword-hit {
    color: #ffd166;
    font-weight: 800;
    text-shadow: 0 0 7px rgba(255, 209, 102, 0.55);
  }

  .skill-phase {
    display: inline-block;
    min-width: 28px;
    font-weight: 800;
    border-radius: 4px;
    padding: 0 4px;
    margin-right: 1px;
    font-size: 12px;
    line-height: 1.45;
  }

  .skill-phase-atk {
    color: #ffd6d6;
    background: rgba(255, 80, 80, 0.18);
    border: 1px solid rgba(255, 80, 80, 0.38);
  }

  .skill-phase-def {
    color: #d8ecff;
    background: rgba(80, 150, 255, 0.18);
    border: 1px solid rgba(80, 150, 255, 0.38);
  }

  .skill-phase-mov {
    color: #efd8ff;
    background: rgba(170, 90, 255, 0.18);
    border: 1px solid rgba(170, 90, 255, 0.38);
  }

  .skill-phase-slash {
    color: #7f8db5;
    margin-right: 2px;
  }

  .skill-focus-resource {
    min-width: 130px;
    color: #fff7cc;
    font-weight: 700;
    white-space: nowrap;
  }

  .skill-focus-raw-cost,
  .skill-focus-weighted-cost,
  .skill-focus-efficiency {
    text-align: center;
    white-space: nowrap;
    font-weight: 800;
  }

  .skill-focus-weighted-cost {
    color: #ffd166;
  }

  .skill-focus-efficiency {
    color: #a5d6a7;
  }

  @media (max-width: 768px) {
    .skill-focus-table {
      font-size: 13px;
      min-width: 1180px;
    }
  }

  .skill-th-help {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 15px;
    height: 15px;
    margin-left: 4px;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 800;
    color: #0d1018;
    background: #ffd166;
    cursor: help;
  }

  .skill-resource-filter-group {
    grid-column: span 3;
    padding: 12px;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.04);
  }

  .skill-resource-filter-title {
    font-weight: 800;
    color: #f7d27a;
    margin-bottom: 4px;
  }

  .skill-resource-filter-desc {
    font-size: 12px;
    color: #8f9bb8;
    margin-bottom: 10px;
  }

  .skill-resource-filter-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 10px;
  }

  @media (max-width: 768px) {
    .skill-resource-filter-group {
      grid-column: 1 / -1;
    }

    .skill-resource-filter-row {
      grid-template-columns: 1fr;
    }
  }

  .skill-export-btn {
    border: 1px solid rgba(120, 210, 140, 0.45);
    background: rgba(80, 180, 100, 0.14);
    color: #baf2c2;
    border-radius: 8px;
    padding: 9px 14px;
    font-weight: 800;
    cursor: pointer;
  }

  .skill-export-btn:hover {
    background: rgba(80, 180, 100, 0.24);
  }
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container tech-wrapper">
      <div class="skill-page-wrap">

        <!-- <div class="skill-search-panel">
          <div class="skill-search-title">技能資料庫</div>
          <div class="skill-search-desc">
            可依角色名稱、技能名稱、效果文字、階段、距離與 Tag 搜尋技能。
          </div>

          <form id="skillSearchForm">
            <div class="skill-filter-grid skill-search-filter-grid">
              <div>
                <label for="q">關鍵字 (可點擊下方標籤帶入搜尋)</label>
                <input
                  type="text"
                  id="q"
                  name="q"
                  value="<?= h($q) ?>"
                  placeholder="例：艾伯、精密、ATK+、手牌+">
              </div>

              <div>
                <label>階段</label>
                <div class="skill-check-row">
                  <label class="skill-check">
                    <input type="checkbox" name="phase[]" value="0" checked>
                    <span>攻擊</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="phase[]" value="1" checked>
                    <span>防禦</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="phase[]" value="2" checked>
                    <span>移動</span>
                  </label>
                </div>
              </div>

              <div>
                <label>距離</label>
                <div class="skill-check-row">
                  <label class="skill-check">
                    <input type="checkbox" name="range[]" value="0" checked>
                    <span>近</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="range[]" value="1" checked>
                    <span>中</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="range[]" value="2" checked>
                    <span>遠</span>
                  </label>
                </div>
              </div>


            </div>

            <div class="skill-filter-actions">
              <button type="submit" class="skill-search-btn">搜尋</button>
              <a href="/pages/skills.php" class="skill-reset-btn">重置</a>
            </div>
          </form>
        </div> -->
        <div class="skill-search-panel">
          <div class="skill-search-title">技能戰術搜尋</div>
          <div class="skill-search-desc">
            可依角色名稱、技能名稱、效果文字與 Tag 搜尋，並統計同一角色在同一距離、不同階段可使用的技能數。關鍵字可留空。
          </div>

          <form id="skillFocusForm">
            <div class="skill-filter-grid skill-focus-filter-grid">
              <div class="skill-focus-keyword">
                <label for="focus_q">關鍵字</label>
                <input
                  type="text"
                  id="focus_q"
                  name="q"
                  placeholder="例：手牌 (或點選下方的技能標籤)">

              </div>
              <div>
                <label>階段</label>
                <div class="skill-check-row">
                  <label class="skill-check">
                    <input type="checkbox" name="phase[]" value="0" checked>
                    <span>攻擊</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="phase[]" value="1" checked>
                    <span>防禦</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="phase[]" value="2">
                    <span>移動</span>
                  </label>
                </div>
              </div>

              <div>
                <label>距離</label>
                <div class="skill-check-row">
                  <label class="skill-check">
                    <input type="checkbox" name="distance[]" value="0">
                    <span>近</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="distance[]" value="1" checked>
                    <span>中</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="distance[]" value="2">
                    <span>遠</span>
                  </label>
                </div>
              </div>

              <div>
                <label for="focus_min_count">最低技能數</label>
                <select id="focus_min_count" name="min_count">
                  <option value="1" selected>1 招以上</option>
                  <option value="2">2 招以上</option>
                  <option value="3">3 招以上</option>
                  <option value="4">4 招以上</option>
                </select>
              </div>

              <div>
                <label for="focus_only_ge">條件</label>
                <select id="focus_only_ge" name="only_ge">
                  <option value="1">排除指定條件 例:劍=3,槍=3</option>
                  <option value="0" selected>含所有條件</option>
                </select>
              </div>
              <div>
                <label for="focus_card_pick">達成卡片</label>
                <select id="focus_card_pick" name="card_pick">
                  <option value="min" selected>取最低</option>
                  <option value="max">取最高</option>
                </select>
              </div>
              <div class="skill-resource-filter-group">
                <div class="skill-resource-filter-title">資源總和限制</div>
                <div class="skill-resource-filter-desc">篩選這組技能所需資源中，指定資源的總和上限，例如：特 ≤ 3。</div>

                <div class="skill-resource-filter-row">
                  <div>
                    <label for="focus_req_type">資源</label>
                    <select id="focus_req_type" name="req_type">
                      <option value="" selected>不限</option>
                      <option value="0">劍</option>
                      <option value="1">槍</option>
                      <option value="2">盾</option>
                      <option value="4">特</option>
                      <option value="3">移</option>
                      <option value="5">無</option>
                    </select>
                  </div>

                  <div>
                    <label for="focus_req_op">比較</label>
                    <select id="focus_req_op" name="req_op">
                      <option value="le" selected>&lt;=</option>
                      <option value="ge">&gt;=</option>
                      <option value="eq">=</option>
                    </select>
                  </div>

                  <div>
                    <label for="focus_req_qty">數值</label>
                    <select id="focus_req_qty" name="req_qty">
                      <option value="">不限</option>
                      <option value="0">0</option>
                      <option value="1">1</option>
                      <option value="2">2</option>
                      <option value="3" selected>3</option>
                      <option value="4">4</option>
                      <option value="5">5</option>
                      <option value="6">6</option>
                      <option value="7">7</option>
                      <option value="8">8</option>
                      <option value="9">9</option>
                    </select>
                  </div>
                </div>
              </div>

              <div class="skill-filter-actions">
                <button type="submit" class="skill-search-btn">查詢</button>
                <a href="/pages/skills.php" class="skill-reset-btn">重置</a>
                <button type="button" class="skill-export-btn" id="skillExportExcelBtn">匯出 Excel</button>
              </div>
          </form>
        </div>

        <div id="skillFocusResult" class="skill-result-area"></div>

        <!-- <div id="skillResult" class="skill-result-area">
          <div class="ul-card">
            <div class="text-muted small">
              請輸入條件後按「搜尋」。
            </div>
          </div>
        </div> -->

      </div>
      <div class="row">
        <div class="col-md-12">
          <h1>🧠 Skill Tag × Dimension 技術結構</h1>
          <p class="text-muted">
            系統內部技術頁，用於檢視目前技能 Tag 的語意分群。
          </p>
        </div>
      </div>

      <?php foreach ($tagsByDim as $dim => $list): ?>
        <?php
        $dimClass = 'dim-' . htmlspecialchars($dim);
        $dimLabel = strtoupper($dim);
        ?>
        <div class="row">
          <div class="col-md-12">
            <div class="tech-tag-box <?= $dimClass ?>">
              <h3>
                <?= $dimLabel ?>
                <small class="text-muted">
                  (<?= count($list) ?> tags)
                </small>
              </h3>

              <div class="dim-desc">
                <?php if ($dim === 'effect'): ?>
                  技能當下造成的結果（HP、數值、手牌等）
                <?php elseif ($dim === 'mechanic'): ?>
                  改變遊戲規則、流程或計算方式
                <?php elseif ($dim === 'status'): ?>
                  會掛在角色身上的持續狀態。UL語:會被伯恩大叔解放劍
                <?php elseif ($dim === 'form'): ?>
                  轉變型態，技能與行為規則改變
                <?php elseif ($dim === 'condition'): ?>
                  技能出牌條件滿足指定條件
                <?php elseif ($dim === 'target'): ?>
                  技能目標或指定方式
                <?php else: ?>
                  未分類
                <?php endif; ?>
              </div>

              <?php foreach ($list as $t): ?>
                <?php
                $label = $t['tag'] ?: $t['label_zh'];
                $tooltip = $t['description']
                  ? $t['description']
                  : "tag: {$t['tag']}";
                ?>
                <a
                  class="tech-tag js-skill-keyword-tag"
                  href="#skillFocusForm"
                  data-skill-keyword="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>"
                  title="<?= htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8') ?>">
                  <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                  <small><?= (int)$t['usage_count'] ?></small>
                </a>

              <?php endforeach; ?>

            </div>
          </div>
        </div>
      <?php endforeach; ?>

    </div>
  </section>
</div>
<script>
  const SKILL_FOCUS_API = '/pages/ajax/skill_focus_rank.php';
  const focusForm = document.getElementById('skillFocusForm');
  const focusResult = document.getElementById('skillFocusResult');

  function buildFocusParams() {
    const fd = new FormData(focusForm);
    const params = new URLSearchParams();

    for (const [key, value] of fd.entries()) {
      const v = String(value).trim();
      if (v !== '') {
        params.append(key, v);
      }
    }

    return params;
  }

  async function loadSkillFocus() {
    const params = buildFocusParams();
    focusResult.innerHTML = '<div class="skill-loading">集中度分析中...</div>';

    try {
      const res = await fetch(SKILL_FOCUS_API + '?' + params.toString(), {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      const html = await res.text();

      if (!res.ok) {
        focusResult.innerHTML = '<div class="ul-card"><div class="text-danger small">集中度分析失敗：HTTP ' + res.status + '</div></div>';
        return;
      }

      focusResult.innerHTML = html;
    } catch (err) {
      focusResult.innerHTML = '<div class="ul-card"><div class="text-danger small">集中度分析失敗，請稍後再試。</div></div>';
    }
  }

  focusForm.addEventListener('submit', (e) => {
    e.preventDefault();
    loadSkillFocus();
  });

  document.addEventListener('click', (e) => {
    const tagBtn = e.target.closest('.js-skill-keyword-tag');
    if (!tagBtn) return;

    e.preventDefault();

    const keyword = (tagBtn.dataset.skillKeyword || '').trim();
    if (!keyword) return;

    const focusQInput = document.getElementById('focus_q');

    if (focusQInput) {
      focusQInput.value = keyword;
    }

    document
      .querySelectorAll('#skillFocusForm input[name="phase[]"], #skillFocusForm input[name="distance[]"]')
      .forEach(input => {
        input.checked = true;
      });

    focusForm.scrollIntoView({
      behavior: 'smooth',
      block: 'start'
    });

    loadSkillFocus();
  });

  function levelSortValue(level) {
    const text = String(level || '').trim().toUpperCase();
    const m = text.match(/^([LR])(\d+)$/);
    if (!m) return 999;

    const n = parseInt(m[2], 10) || 0;
    return m[1] === 'L' ? n : 5 + n;
  }

  document.addEventListener('click', (e) => {
    const th = e.target.closest('.js-sort-table th[data-sort-type]');
    if (!th) return;

    const table = th.closest('table');
    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    const index = Array.from(th.parentNode.children).indexOf(th);
    const type = th.dataset.sortType || 'text';
    const currentDir = th.dataset.sortDir === 'asc' ? 'asc' : 'desc';
    const nextDir = currentDir === 'asc' ? 'desc' : 'asc';

    table.querySelectorAll('th[data-sort-type]').forEach(el => {
      el.dataset.sortDir = '';
      el.classList.remove('sort-asc', 'sort-desc');
    });

    th.dataset.sortDir = nextDir;
    th.classList.add(nextDir === 'asc' ? 'sort-asc' : 'sort-desc');

    const rows = Array.from(tbody.querySelectorAll('tr'));

    rows.sort((a, b) => {
      const aCell = a.children[index];
      const bCell = b.children[index];

      const aRaw = aCell?.dataset.sort ?? aCell?.textContent.trim() ?? '';
      const bRaw = bCell?.dataset.sort ?? bCell?.textContent.trim() ?? '';

      let av = aRaw;
      let bv = bRaw;

      if (type === 'number') {
        av = parseFloat(aRaw) || 0;
        bv = parseFloat(bRaw) || 0;
      }

      if (type === 'level') {
        av = levelSortValue(aRaw);
        bv = levelSortValue(bRaw);
      }

      if (av < bv) return nextDir === 'asc' ? -1 : 1;
      if (av > bv) return nextDir === 'asc' ? 1 : -1;
      return 0;
    });

    rows.forEach(row => tbody.appendChild(row));
  });
  const exportExcelBtn = document.getElementById('skillExportExcelBtn');

  if (exportExcelBtn) {
    exportExcelBtn.addEventListener('click', () => {
      const table = document.querySelector('#skillFocusResult table');
      if (!table) {
        alert('目前沒有可匯出的查詢結果');
        return;
      }

      const html = `
      <html>
        <head>
          <meta charset="UTF-8">
        </head>
        <body>
          ${table.outerHTML}
        </body>
      </html>
    `;

      const blob = new Blob([html], {
        type: 'application/vnd.ms-excel;charset=utf-8;'
      });

      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      const now = new Date();
      const pad = n => String(n).padStart(2, '0');
      const filename = 'ulgg_skill_focus_' +
        now.getFullYear() +
        pad(now.getMonth() + 1) +
        pad(now.getDate()) + '_' +
        pad(now.getHours()) +
        pad(now.getMinutes()) +
        '.xls';

      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    });
  }
</script>
<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
