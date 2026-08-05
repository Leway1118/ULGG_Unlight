<?php
// ⚠️ 不要 session_start()，交給 config.php
require_once __DIR__ . '/../config.php';
$pdo = $db; // 若你 config.php 用 $pdo，這行改成 $pdo = $pdo;

$pageTitleText = 'Skill Tag 技術結構總覽';
$seoTitle = $pageTitleText . ' | UL.GG';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = "tech_skill_tag";


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

<div class="content-wrapper">
    <section class="content ul-container-nopad">
        <div class="container tech-wrapper">

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

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
