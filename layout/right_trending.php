<?php
/**
 * Right Trending Panel
 * 使用 base.php 提供的：
 * - $topSearch
 * - $topChar
 */
?>

<aside class="right-panel">

  <!-- =========================
       🔥 今日熱門搜尋
  ========================= -->
  <div class="hot-box">
    <div class="hot-header">
      <span class="hot-title">🔥 今日熱門搜尋</span>
      <span class="hot-sub">Trending</span>
    </div>

    <?php if (empty($topSearch)): ?>
      <div class="hot-empty">
        今日尚無搜尋紀錄
      </div>
    <?php else: ?>
      <div class="hot-list">
        <?php foreach ($topSearch as $idx => $row): ?>
          <?php
          $keyword = $row['search_term'];
          $cnt     = (int)$row['cnt'];
          $url     = '/pages/ranking_team.php?player_search=' . urlencode($keyword);
          ?>
          <div class="hot-row">
            <span class="hot-rank"><?= $idx + 1 ?></span>
            <a href="<?= $url ?>" class="hot-link">
              <?= htmlspecialchars($keyword) ?>
            </a>
            <span class="hot-cnt"><?= $cnt ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- =========================
       🎴 今日熱門角色
  ========================= -->
  <div class="hot-box">
    <div class="hot-header">
      <span class="hot-title">🎴 今日熱門角色</span>
      <span class="hot-sub">Characters</span>
    </div>

    <?php if (empty($topChar)): ?>
      <div class="hot-empty">
        今日尚無角色瀏覽
      </div>
    <?php else: ?>
      <div class="hot-list">
        <?php foreach ($topChar as $idx => $row): ?>
          <?php
          $char = $row['character_name'];
          $cnt  = (int)$row['cnt'];
          $url  = '/pages/ranking_team.php?character=' . urlencode($char);
          ?>
          <div class="hot-row">
            <span class="hot-rank"><?= $idx + 1 ?></span>
            <a href="<?= $url ?>" class="hot-link">
              <?= htmlspecialchars($char) ?>
            </a>
            <span class="hot-cnt"><?= $cnt ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</aside>
