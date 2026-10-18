<?php
// 交由全站 config.php 與 layout/base.php 管理 DB、session 及共用版型。
require_once __DIR__ . '/../../config.php';

$pageTitleText = '暗房育成競賽';
$pageTitleFull = '暗房育成競賽 Prototype v0.2 | UL.GG 戰績網';
$activeMenu = 'raising';
$hidePageHeader = true;

$raisingRoster = [
  'cc001' => '艾伯李斯特',
  'cc003' => '古魯瓦爾多',
  'cc004' => '阿貝爾',
  'cc002' => '艾依查庫',
  'cc011' => '雪莉',
  'cc012' => '艾茵',
];
$raisingAssets = [];

try {
  $assetStatement = $db->prepare(
    "SELECT name, ico, stand_ico, level
       FROM unlight
      WHERE name = :name
      ORDER BY CASE level
        WHEN 'R5' THEN 1 WHEN 'L5' THEN 2 WHEN 'R4' THEN 3 WHEN 'L4' THEN 4
        ELSE 5 END ASC, id DESC
      LIMIT 1"
  );
  foreach ($raisingRoster as $characterId => $characterName) {
    $assetStatement->execute(['name' => $characterName]);
    $assetRow = $assetStatement->fetch(PDO::FETCH_ASSOC);
    if (!$assetRow) {
      continue;
    }
    $imageFile = trim((string)($assetRow['stand_ico'] ?: $assetRow['ico']));
    if ($imageFile !== '') {
      $raisingAssets[$characterId] = IMG_BASE . $imageFile;
    }
  }
} catch (Throwable $error) {
  error_log('raising prototype asset lookup failed: ' . $error->getMessage());
}

ob_start();
?>
<link rel="stylesheet" href="/assets/css/raising-game.css?v=0.2.0">

<main
  id="raising-game"
  class="raising-game"
  data-assets="<?= htmlspecialchars(
    json_encode($raisingAssets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ENT_QUOTES,
    'UTF-8'
  ) ?>"
  aria-live="polite"
>
  <noscript>
    <div class="raising-notice">此 Prototype 需要 JavaScript 才能遊玩。</div>
  </noscript>
</main>

<script type="module" src="/assets/js/raising/app.js?v=0.2.0"></script>
<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>
