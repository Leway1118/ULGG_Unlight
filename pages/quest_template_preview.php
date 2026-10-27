<?php
declare(strict_types=1);

/* Same pattern as pages/template.php */
require_once __DIR__ . '/../config.php';

$pdo = $db;
$activeMenu = 'quest_public';

$pageTitleText = 'Quest 版型測試';
$pageTitleFull = 'Quest 版型測試 | UL.GG';
$seoTitle = $pageTitleFull;
$hidePageHeader = true;

header('Cache-Control: private, no-store');

ob_start();
?>
<section style="padding:24px">
  <h1>Quest 共用版型測試</h1>
  <p>此頁直接引用主站 layout/base.php。</p>
  <p>請比對 Header、Steam 頭像、Sidebar 與資料狀態。</p>
  <p>
    <a href="/pages/quest_public/v1.4.1/index.php">
      返回原 Quest 地域排行
    </a>
  </p>
</section>
<?php
$pageContent = ob_get_clean();

require __DIR__ . '/../layout/base.php';
