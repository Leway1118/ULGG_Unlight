<?php
// ⚠️ 不要 session_start()，交給 config.php
require_once __DIR__ . '/../config.php';
$pdo = $db; // 若你 config.php 用 $pdo，這行改成 $pdo = $pdo;

$pageTitleText = 'Skill Tag 技術結構總覽';
$seoTitle = $pageTitleText . ' | UL.GG';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "tech_skill_tag";


$q = trim($_GET['q'] ?? '');

$tag = trim($_GET['tag'] ?? '');

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
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
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
    .skill-filter-grid {
      grid-template-columns: 1fr 1fr;
    }
  }

  @media (max-width: 600px) {
    .skill-filter-grid {
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
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container tech-wrapper">
      <div class="skill-page-wrap">

        <div class="skill-search-panel">
          <div class="skill-search-title">技能資料庫</div>
          <div class="skill-search-desc">
            可依角色名稱、技能名稱、效果文字、階段、距離與 Tag 搜尋技能。
          </div>

          <form id="skillSearchForm">
            <div class="skill-filter-grid">
              <div>
                <label for="q">關鍵字</label>
                <input
                  type="text"
                  id="q"
                  name="q"
                  value="<?= h($q) ?>"
                  placeholder="例：艾伯、精密、ATK、棄牌">
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

              <div>
                <label for="tag">Tag</label>
                <input
                  type="text"
                  id="tag"
                  name="tag"
                  value="<?= h($tag) ?>"
                  placeholder="例：ATK+">
              </div>
            </div>

            <div class="skill-filter-actions">
              <button type="submit" class="skill-search-btn">搜尋</button>
              <a href="/pages/skills.php" class="skill-reset-btn">重置</a>
            </div>
          </form>
        </div>
        <div class="skill-search-panel">
          <div class="skill-search-title">技能集中度排行</div>
          <div class="skill-search-desc">
            統計同一角色在同一距離，攻擊 / 防禦階段可使用的技能數。
          </div>

          <form id="skillFocusForm">
            <div class="skill-filter-grid">
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
                    <input type="checkbox" name="distance[]" value="0" checked>
                    <span>近</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="distance[]" value="1" checked>
                    <span>中</span>
                  </label>

                  <label class="skill-check">
                    <input type="checkbox" name="distance[]" value="2" checked>
                    <span>遠</span>
                  </label>
                </div>
              </div>

              <div>
                <label for="focus_min_count">最低技能數</label>
                <select id="focus_min_count" name="min_count">
                  <option value="2">2 招以上</option>
                  <option value="3" selected>3 招以上</option>
                  <option value="4">4 招以上</option>
                </select>
              </div>

              <div>
                <label for="focus_only_ge">條件</label>
                <select id="focus_only_ge" name="only_ge">
                  <option value="1" selected>只看 ≥ 條件</option>
                  <option value="0">不限條件型態</option>
                </select>
              </div>
            </div>

            <div class="skill-filter-actions">
              <button type="submit" class="skill-search-btn">查集中度</button>
            </div>
          </form>
        </div>

        <div id="skillFocusResult" class="skill-result-area"></div>

        <div id="skillResult" class="skill-result-area">
          <div class="ul-card">
            <div class="text-muted small">
              請輸入條件後按「搜尋」。
            </div>
          </div>
        </div>

      </div>
      <div class="row">
        <div class="col-md-12">
          <h1>🧠 Skill Tag × Dimension 技術結構</h1>
          <p class="text-muted">
            系統內部技術頁，用於檢視目前技能 Tag 的語意分群與使用密度（usage_count 即時計算）。
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
                  class="tech-tag"
                  href="/pages/skill_by_tag.php?tag=<?= urlencode($label) ?>"
                  title="<?= htmlspecialchars($tooltip) ?>">
                  <?= htmlspecialchars($label) ?>
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
  const SKILL_SEARCH_API = '/pages/ajax/skill_search.php';
  const SKILL_TAG_API = '/api/skill_add_tag.php';

  const form = document.getElementById('skillSearchForm');
  const result = document.getElementById('skillResult');

  function buildParams() {
    const fd = new FormData(form);
    const params = new URLSearchParams();

    for (const [key, value] of fd.entries()) {
      const v = String(value).trim();
      if (v !== '' && v !== 'all') {
        params.append(key, v);
      }
    }

    return params;
  }

  async function loadSkills(pushUrl = false) {
    const params = buildParams();
    result.innerHTML = '<div class="skill-loading">搜尋中...</div>';

    try {
      const res = await fetch(SKILL_SEARCH_API + '?' + params.toString(), {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      const html = await res.text();

      if (!res.ok) {
        result.innerHTML = '<div class="ul-card"><div class="text-danger small">搜尋失敗：HTTP ' + res.status + '</div></div>';
        return;
      }

      result.innerHTML = html;

      if (pushUrl) {
        const url = '/pages/skills.php' + (params.toString() ? '?' + params.toString() : '');
        history.pushState(null, '', url);
      }
    } catch (err) {
      result.innerHTML = '<div class="ul-card"><div class="text-danger small">搜尋失敗，請稍後再試。</div></div>';
    }
  }

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    loadSkills(true);
  });

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-add-tag');
    if (!btn) return;

    const skillCode = btn.dataset.skill;
    const tag = prompt('請輸入要新增的 tag（例：ATK+、棄牌、抽牌）');
    if (!tag) return;

    const cleanTag = tag.trim().toUpperCase();
    if (!cleanTag) return;

    const res = await fetch(SKILL_TAG_API, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        skill_code: skillCode,
        tag: cleanTag
      })
    });

    const data = await res.json();

    if (!data.ok) {
      alert(data.error || '新增失敗');
      return;
    }

    const wrap = document.querySelector(
      `.ul-skill-tags[data-skill="${skillCode}"]`
    );

    if (!wrap) return;

    if ([...wrap.children].some(el => el.textContent.trim() === cleanTag)) {
      return;
    }

    const a = document.createElement('a');
    a.className = 'skill-tag';
    a.href = '/pages/skills.php?tag=' + encodeURIComponent(cleanTag);
    a.textContent = cleanTag;
    wrap.appendChild(a);
  });

  //loadSkills(false);

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
</script>
<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
