<?php
// ===============================
// AJAX: 單卡分析｜技能（JOIN skill_code）
// ===============================

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../lib/_analysis_base.php';
$pdo = $db;
/* ini_set('display_errors', 1);
error_reporting(E_ALL); */
// -------------------------------
// 參數
// -------------------------------
$isAdmin = ($_SESSION['permission'] ?? 0) >= 2;
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

// -------------------------------
// 取得卡片技能 code
// -------------------------------
$stmt = $pdo->prepare("
  SELECT
    id,
    name,
    level,
    skill1_code,
    skill2_code,
    skill3_code,
    skill4_code
  FROM unlight
  WHERE id = :id
  LIMIT 1
");
$stmt->execute(['id' => $charId]);
$card = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$card) {
  echo '<div class="text-muted small">找不到卡片資料</div>';
  exit;
}

// -------------------------------
// 收集 skill_code（保持順序）
// -------------------------------
$skillCodes = [];
for ($i = 1; $i <= 4; $i++) {
  $code = $card["skill{$i}_code"] ?? null;
  if ($code) $skillCodes[] = $code;
}

if (empty($skillCodes)) {
  echo '<div class="ul-card"><div class="text-muted small">此卡片沒有技能</div></div>';
  exit;
}

// -------------------------------
// JOIN 技能表（依 skill_code）
// -------------------------------
$in = implode(',', array_fill(0, count($skillCodes), '?'));

$stmt = $pdo->prepare("SELECT
    skill_code,
    name_tcn,
    info_tcn,
    note_player_tcn,   -- ⭐ 玩家實測註解
    phase,
    range_mask,
    require_json
  FROM unlight_skill
  WHERE skill_code IN ($in)
");
$stmt->execute($skillCodes);

// map：skill_code → 技能資料
$skillMap = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
  $skillMap[$row['skill_code']] = $row;
}

// 一次抓所有 tag
$skillTags = [];
$stmt = $db->query(
  'SELECT skill_code, tag FROM unlight_skill_tag ORDER BY tag DESC'
);

foreach ($stmt as $row) {
  $skillTags[$row['skill_code']][] = $row['tag'];
}

?>

<div class="ul-card">
  <h5 class="card-title">
    🧠 技能一覽
    <span class="text-muted small">
      ｜<?= htmlspecialchars($card['level']) ?>
      <?= htmlspecialchars($card['name']) ?>
    </span>
  </h5>

  <div class="skill-summary">
    <?php foreach ($skillCodes as $idx => $code): ?>
      
      <?php
      $s = $skillMap[$code] ?? null;

      $phaseText  = $s ? ul_format_phase($s['phase']) : null;
      $ranges = ($s && $s['range_mask'] !== null && $s['range_mask'] !== '')
        ? explode(',', $s['range_mask'])
        : [];

      $requireTxt = $s ? ul_format_skill_require($s['require_json']) : null;

      ?>

      <div class="ul-skill-card">
        <div class="ul-skill-header">
          <div class="ul-skill-title-row">
            <div class="ul-skill-title">
              <?= htmlspecialchars($s['name_tcn']) ?>
            </div>

            <?php if ($isAdmin): ?>
              <button
                class="btn-add-tag"
                data-skill="<?= htmlspecialchars($code) ?>"
                title="新增技能 Tag">
                +Tag
              </button>
            <?php endif; ?>
          </div>

          <div class="ul-skill-tags" data-skill="<?= htmlspecialchars($code) ?>">
            <?php foreach ($skillTags[$code] ?? [] as $t): ?>
              <a
                href="/pages/skill_by_tag.php?tag=<?= urlencode($t) ?>"
                class="skill-tag"
                title="查看標籤：<?= htmlspecialchars($t) ?>">
                <?= htmlspecialchars($t) ?>
              </a>
            <?php endforeach; ?>
          </div>
        </div>




        <div class="ul-skill-body">



          <div class="ul-skill-meta">
            <!-- debug -->

            <?php
            $phase = isset($s['phase']) ? (int)$s['phase'] : null;

            $phaseClass = match ($phase) {
              0 => 'atk',
              1 => 'def',
              2 => 'mov',
              default => 'atk',
            };

            $phaseText = UL_PHASE_LABEL[$phase] ?? 'ATK';
            ?>

            <span class="skill-badge <?= $phaseClass ?>">
              <?= $phaseText ?>
            </span>

            <?php if ($ranges): ?>
              <div class="ul-range-row">
                <span class="range-badge range-far <?= in_array('2', $ranges) ? 'on' : '' ?>">▶</span>
                <span class="range-badge range-mid <?= in_array('1', $ranges) ? 'on' : '' ?>">▶</span>
                <span class="range-badge range-near <?= in_array('0', $ranges) ? 'on' : '' ?>">▶</span>
              </div>
            <?php endif; ?>

            <?php if ($requireTxt): ?>
              <div class="skill-require">
                <?= $requireTxt ?>
              </div>
            <?php endif; ?>

          </div>

          <div class="ul-skill-desc">
            <!-- 官方技能說明 -->
            <div class="skill-info-official">
              <?= nl2br(htmlspecialchars(trim($s['info_tcn'] ?? '（尚無技能說明）'))) ?>
            </div>

            <?php if (!empty($s['note_player_tcn'])): ?>
              <!-- 玩家實測註解 -->
              <div>
                <?= nl2br(htmlspecialchars($s['note_player_tcn'])) ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>


    <?php endforeach; ?>
  </div>


  <div class="text-muted small mt-3">
    ※ 技能資料依 <strong>技能代碼</strong> 關聯官方技能定義表，
    後續將補上技能標籤、分類與實戰影響分析。
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
            . '&tab=skill';

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