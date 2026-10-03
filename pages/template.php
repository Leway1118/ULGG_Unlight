<?php
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定
$pdo = $db; // ⭐ 一定要這行
/* require_once __DIR__ . '/../lib/_analysis_base.php'; */// ⭐ 必須包含時間區間設定
/* require_once __DIR__ . '/_admin_gate.php'; */ //確認是否為管理員
// ⭐ 全站常數 define('IMG_BASE', '/assets/uploads/');

$pageTitleText = '範例 Template';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網'; //桌機
$activeMenu = "template"; //.php

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */
  /*   放入你的 style                                         */
  /* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */
</style>

<?php
/* 放入你的 PHP程式    */
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <div class="col-md-8" style="float:none; margin:0 auto; border:3px solid lime; height:100px;">
          置中 col-md-8 float:none;margin: 0 auto;
        </div>
        <!-- /.col -->
      </div>
      <!-- /.row -->
      <div class="row">
        <div class="col-md-6"
          style="margin:0 auto; border:3px solid lime; height:100px;">
          左 col-md-6
        </div>
        <!-- /.col -->
        <div class="col-md-6"
          style="margin:0 auto; border:3px solid lime; height:100px;">
          右 col-md-6
        </div>
        <!-- /.col -->
      </div>
      <!-- /.row -->
    </div>
    <!-- /.container -->
  </section>
  <!-- /.content -->
</div>
<!-- /.content-wrapper -->


<script>
  /* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */
  /*   放入你的 script                                         */
  /* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */
</script>

<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>