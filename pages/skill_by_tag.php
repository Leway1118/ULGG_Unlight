<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/_analysis_base.php';
$pdo = $db;

ob_start();



$q = trim($_GET['tag'] ?? '');
$errorMsg = null;
$tagInfo  = null;
$skillCodes = [];

/* -------------------------------
 * 1. 參數檢查
 * ------------------------------- */
$isAdmin = ($_SESSION['permission'] ?? 0) >= 2;
if ($q === '') {
  $errorMsg = '未指定 Tag';
}

/* -------------------------------
 * 2. 查 Tag（支援 tag / label_zh）
 * ------------------------------- */
if (!$errorMsg) {
  $stmt = $pdo->prepare("
    SELECT tag, dimension, label_zh, description
    FROM unlight_skill_tag_dictionary
    WHERE tag = :q OR label_zh = :q
    LIMIT 1
  ");
  $stmt->execute([':q' => $q]);
  $tagInfo = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$tagInfo) {
    $errorMsg = '找不到此 Tag';
  }
}

/* -------------------------------
 * 3. 取得 skill_code（一定用 tag key）
 * ------------------------------- */
if (!$errorMsg) {
  $tagKey = $tagInfo['tag']; // ⭐ 真正的 tag key

  $stmt = $pdo->prepare("
    SELECT DISTINCT skill_code
    FROM unlight_skill_tag
    WHERE tag = ?
    ORDER BY skill_code
  ");
  $stmt->execute([$tagKey]);
  $skillCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);

  if (!$skillCodes) {
    $errorMsg = '尚無技能使用此 Tag';
  }
}


/* -------------------------------
 * JOIN 技能資料
 * ------------------------------- */
$in = implode(',', array_fill(0, count($skillCodes), '?'));
$stmt = $pdo->prepare("SELECT
  s.skill_code,
  s.name_tcn,
  s.info_tcn,
  s.note_player_tcn,   -- ⭐ 新增
  s.phase,
  s.range_mask,
  s.require_json,
  u.name AS char_name,  
  u.id AS char_id
FROM unlight_skill s
LEFT JOIN unlight u
  ON u.id = s.source_card_id
WHERE s.skill_code IN ($in)
ORDER BY
  u.name ASC,
  REPLACE(REPLACE(s.name_tcn, 'Ex', ''), 'EX', '') ASC,
  CASE
    WHEN s.skill_code LIKE '%_ex' THEN 1
    ELSE 0
  END ASC,
  s.phase ASC,
  s.skill_code ASC
");
$stmt->execute($skillCodes);

$skillMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $skillMap[$row['skill_code']] = $row;
}

/* -------------------------------
 * 一次抓所有 tag（顯示用）
 * ------------------------------- */
$skillTags = [];
$in = implode(',', array_fill(0, count($skillCodes), '?'));
$stmt = $pdo->prepare("  SELECT skill_code, tag
  FROM unlight_skill_tag
  WHERE skill_code IN ($in)
  ORDER BY tag DESC
");
$stmt->execute($skillCodes);

foreach ($stmt as $r) {
  $skillTags[$r['skill_code']][] = $r['tag'];
}

/* -------------------------------
 * Tag description map（tooltip 用）
 * ------------------------------- */
$tagDescMap = [];

$stmt = $pdo->query("
  SELECT tag, description
  FROM unlight_skill_tag_dictionary
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $tagDescMap[$row['tag']] = $row['description'];
}

?>

<style>
  .skill-tag-page-card {
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 14px;
    background: rgba(13, 16, 24, 0.92);
    box-shadow: 0 10px 28px rgba(0, 0, 0, 0.28);
    padding: 16px;
  }

  .skill-tag-page-title {
    font-size: 18px;
    font-weight: 900;
    color: #f7d27a;
    margin-bottom: 6px;
  }

  .skill-tag-page-desc {
    font-size: 13px;
    color: #8f9bb8;
    line-height: 1.7;
    margin-bottom: 14px;
  }

  .skill-tag-table-wrap {
    width: 100%;
    overflow-x: auto;
  }

  .skill-tag-table {
    width: 100%;
    min-width: 1080px;
    border-collapse: collapse;
    font-size: 14px;
  }

  .skill-tag-table th,
  .skill-tag-table td {
    border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    padding: 9px 10px;
    vertical-align: top;
  }

  .skill-tag-table th {
    color: #f7d27a;
    font-weight: 900;
    background: rgba(255, 255, 255, 0.05);
    white-space: nowrap;
  }

  .skill-tag-table td {
    color: #d8deef;
  }

  .skill-tag-char {
    min-width: 120px;
    font-weight: 800;
    white-space: nowrap;
  }

  .skill-tag-char a {
    color: #d8ecff;
    text-decoration: none;
  }

  .skill-tag-char a:hover {
    color: #ffffff;
    text-decoration: underline;
  }

  .skill-tag-skill-name {
    min-width: 150px;
    font-weight: 900;
    color: #ffffff;
  }



  .skill-tag-range {
    min-width: 86px;
    white-space: nowrap;
  }

  .skill-tag-range-row {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-wrap: nowrap;
    white-space: nowrap;
  }

  .skill-tag-range-row .range-badge {
    flex: 0 0 auto;
  }

  .skill-tag-table th:nth-child(4),
  .skill-tag-table td:nth-child(4) {
    min-width: 90px;
    white-space: nowrap;
  }

  .skill-tag-table th:nth-child(5),
  .skill-tag-table td:nth-child(5) {
    min-width: 130px;
    white-space: nowrap;
  }

  .skill-tag-table .skill-require {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    flex-wrap: nowrap;
    white-space: nowrap;
  }

  .skill-tag-table .req-chip {
    flex: 0 0 auto;
  }

  .skill-tag-effect {
    min-width: 360px;
    line-height: 1.65;
  }

  .skill-tag-note {
    margin-top: 6px;
    padding-top: 6px;
    border-top: 1px dashed rgba(255, 255, 255, 0.12);
    color: #aeb8d0;
    font-size: 13px;
  }

  .skill-tag-list {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    min-width: 180px;
  }

  .skill-tag-list .skill-tag {
    display: inline-flex;
    align-items: center;
    padding: 2px 7px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.12);
    color: #cdd6f4;
    font-size: 12px;
    text-decoration: none;
  }

  .skill-tag-list .skill-tag:hover {
    background: rgba(247, 210, 122, 0.16);
    color: #f7d27a;
  }

  @media (max-width: 768px) {
    .skill-tag-table {
      min-width: 1080px;
      font-size: 13px;
    }

    .skill-tag-page-card {
      padding: 12px;
    }
  }

  .skill-tag-page-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
  }

  .skill-tag-back-btn {
    flex: 0 0 auto;
    border: 1px solid rgba(120, 150, 210, 0.45);
    background: rgba(80, 110, 170, 0.16);
    color: #d8ecff;
    border-radius: 8px;
    padding: 7px 12px;
    font-size: 13px;
    font-weight: 800;
    cursor: pointer;
    white-space: nowrap;
  }

  .skill-tag-back-btn:hover {
    background: rgba(80, 110, 170, 0.28);
    color: #ffffff;
  }

  @media (max-width: 768px) {
    .skill-tag-page-head {
      flex-direction: column;
      align-items: stretch;
    }

    .skill-tag-back-btn {
      width: fit-content;
    }
  }
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">

      <!-- Tag Header -->
      <div class="row">
        <div class="col-md-12">
          <?php if (!empty($errorMsg)): ?>
            <div class="skill-tag-page-card">
              <div class="skill-tag-page-title">
                🏷 技能標籤分析
              </div>
              <div class="text-muted small"><?= htmlspecialchars($errorMsg) ?></div>
            </div>
          <?php else: ?>
            <div class="skill-tag-page-card">
              <div class="skill-tag-page-head">
                <div class="skill-tag-top-actions">
                  <button
                    type="button"
                    class="skill-tag-back-btn"
                    onclick="if (document.referrer) { history.back(); } else { location.href='/pages/skills.php'; }">
                    ← 回上一頁
                  </button>
                </div>

                <div class="skill-tag-page-title">
                  🏷 技能標籤分析
                  <span class="text-muted small">
                    ｜<?= htmlspecialchars($tagInfo['label_zh'] ?: $tagInfo['tag']) ?>
                    （<?= htmlspecialchars($tagInfo['dimension']) ?>）
                  </span>
                </div>

                <?php if (!empty($tagInfo['description'])): ?>
                  <div class="skill-tag-page-desc">
                    <?= nl2br(htmlspecialchars($tagInfo['description'])) ?>
                  </div>
                <?php endif; ?>
              </div>

              <div class="skill-tag-table-wrap">
                <table class="skill-tag-table">
                  <thead>
                    <tr>
                      <th>角色</th>
                      <th>技能</th>
                      <th>階段</th>
                      <th>距離</th>
                      <th>條件</th>
                      <th>效果</th>
                      <th>Tag</th>
                      <?php if ($isAdmin): ?>
                        <th>管理</th>
                      <?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($skillCodes as $code): ?>
                      <?php
                      $s = $skillMap[$code] ?? null;
                      if (!$s) continue;

                      $phase = (int)($s['phase'] ?? 0);
                      switch ($phase) {
                        case 1:
                          $phaseClass = 'def';
                          break;
                        case 2:
                          $phaseClass = 'mov';
                          break;
                        default:
                          $phaseClass = 'atk';
                      }

                      $phaseText = UL_PHASE_LABEL[$phase] ?? 'ATK';

                      $ranges = ($s['range_mask'] !== null && $s['range_mask'] !== '')
                        ? explode(',', $s['range_mask'])
                        : [];

                      $rangeText = [];
                      if (in_array('0', $ranges, true)) $rangeText[] = '近';
                      if (in_array('1', $ranges, true)) $rangeText[] = '中';
                      if (in_array('2', $ranges, true)) $rangeText[] = '遠';

                      $requireTxt = ul_format_skill_require($s['require_json']);



                      $charUrl = '';
                      if (!empty($s['char_id'])) {
                        $charUrl = '/pages/analysis_character_card.php?char_id=' . (int)$s['char_id'] . '&tab=skill';
                      }
                      ?>

                      <tr>
                        <td class="skill-tag-char">
                          <?php if ($charUrl): ?>
                            <a
                              href="<?= htmlspecialchars($charUrl) ?>"
                              target="_blank"
                              rel="noopener noreferrer"
                              title="查看 <?= htmlspecialchars($s['char_name']) ?> 的角色分析">
                              <?= htmlspecialchars($s['char_name']) ?>
                            </a>
                          <?php else: ?>
                            <?= htmlspecialchars($s['char_name'] ?? '未知角色') ?>
                          <?php endif; ?>
                        </td>

                        <td class="skill-tag-skill-name">
                          <?= htmlspecialchars($s['name_tcn']) ?>
                        </td>



                        <td>
                          <span class="skill-badge <?= $phaseClass ?>">
                            <?= htmlspecialchars($phaseText) ?>
                          </span>
                        </td>

                        <td class="skill-tag-range">
                          <?php if ($ranges): ?>
                            <div class="ul-range-row skill-tag-range-row">
                              <span class="range-badge range-far <?= in_array('2', $ranges, true) ? 'on' : '' ?>">▶</span>
                              <span class="range-badge range-mid <?= in_array('1', $ranges, true) ? 'on' : '' ?>">▶</span>
                              <span class="range-badge range-near <?= in_array('0', $ranges, true) ? 'on' : '' ?>">▶</span>
                            </div>
                          <?php else: ?>
                            <span class="text-muted small">-</span>
                          <?php endif; ?>
                        </td>

                        <td>
                          <?php if ($requireTxt): ?>
                            <div class="skill-require"><?= $requireTxt ?></div>
                          <?php else: ?>
                            <span class="text-muted small">-</span>
                          <?php endif; ?>
                        </td>

                        <td class="skill-tag-effect">
                          <div class="skill-info-official">
                            <?= nl2br(htmlspecialchars(trim($s['info_tcn'] ?? '（尚無技能說明）'))) ?>
                          </div>

                          <?php if (!empty($s['note_player_tcn'])): ?>
                            <div class="skill-tag-note">
                              <?= nl2br(htmlspecialchars($s['note_player_tcn'])) ?>
                            </div>
                          <?php endif; ?>
                        </td>

                        <td>
                          <div class="skill-tag-list ul-skill-tags" data-skill="<?= htmlspecialchars($code) ?>">
                            <?php foreach ($skillTags[$code] ?? [] as $t): ?>
                              <?php $desc = $tagDescMap[$t] ?? '（尚無標籤說明）'; ?>
                              <a
                                href="/pages/skill_by_tag.php?tag=<?= urlencode($t) ?>"
                                class="skill-tag"
                                title="<?= htmlspecialchars($desc) ?>"
                                data-bs-toggle="tooltip"
                                data-bs-placement="top">
                                <?= htmlspecialchars($t) ?>
                              </a>
                            <?php endforeach; ?>
                          </div>
                        </td>

                        <?php if ($isAdmin): ?>
                          <td>
                            <button
                              class="btn-add-tag"
                              data-skill="<?= htmlspecialchars($code) ?>"
                              title="新增技能 Tag">
                              +Tag
                            </button>
                          </td>
                        <?php endif; ?>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>


            </div>
        </div>

      </div>
  </section>
</div>
<script>
  const SKILL_TAG_API = '/api/skill_add_tag.php';

  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.btn-add-tag');
    if (!btn) return;

    const skillCode = btn.dataset.skill;

    const tag = prompt('請輸入要新增的 tag（例：effect:damage_up）');
    if (!tag) return;

    const cleanTag = tag.trim().toUpperCase();

    /* if (!/^[A-Z0-9:=Ø+_()\-\u4e00-\u9fff]+$/i.test(cleanTag)) {
        alert('Tag 格式錯誤（僅限 A-Z 0-9 : = + _ - ）');
        return;
    } */


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

    // ⬇⬇⬇ wrap 一定要在這裡宣告 ⬇⬇⬇
    const wrap = document.querySelector(
      `.ul-skill-tags[data-skill="${skillCode}"]`
    );
    if (!wrap) return;

    // 防止畫面重複顯示
    if ([...wrap.children].some(el => el.textContent === cleanTag)) {
      return;
    }

    const span = document.createElement('span');
    span.className = 'skill-tag';
    span.textContent = cleanTag;
    wrap.appendChild(span);
  });
</script>
<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
