<?php
// ⚠️ 不要 session_start()，交給 config.php
require_once __DIR__ . '/../config.php';
$pdo = $db;

$pageTitleText = '技能資料庫';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "skills";

$q = trim($_GET['q'] ?? '');
$phase = $_GET['phase'] ?? 'all';
$range = $_GET['range'] ?? 'all';
$tag = trim($_GET['tag'] ?? '');

function h($v): string
{
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

ob_start();
?>

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
    grid-template-columns: 2fr repeat(3, 1fr);
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
</style>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
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
                <label for="phase">階段</label>
                <select id="phase" name="phase">
                  <option value="all" <?= $phase === 'all' ? 'selected' : '' ?>>全部</option>
                  <option value="0" <?= $phase === '0' ? 'selected' : '' ?>>攻擊</option>
                  <option value="1" <?= $phase === '1' ? 'selected' : '' ?>>防禦</option>
                  <option value="2" <?= $phase === '2' ? 'selected' : '' ?>>移動</option>
                </select>
              </div>

              <div>
                <label for="range">距離</label>
                <select id="range" name="range">
                  <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>全部</option>
                  <option value="0" <?= $range === '0' ? 'selected' : '' ?>>近</option>
                  <option value="1" <?= $range === '1' ? 'selected' : '' ?>>中</option>
                  <option value="2" <?= $range === '2' ? 'selected' : '' ?>>遠</option>
                </select>
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
          <div class="skill-loading">載入中...</div>
        </div>

      </div>
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
        params.set(key, v);
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

  loadSkills(false);

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
?>