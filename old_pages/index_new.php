<?php
include_once 'layout/head.php';
include_once 'layout/navbar.php';
include_once 'layout/sidebar.php';
?>

<div class="content-wrapper p-3">

  <!-- 標題 -->
  <div class="row mb-3">
    <div class="col-12">
      <h2 class="mb-1">UL.GG Analytics v2</h2>
      <small class="text-muted">Unlight 對戰分析・排行榜・資料統計平台</small>
    </div>
  </div>

  <!-- 今日重點統計 -->
  <div class="row">

    <div class="col-md-3 col-sm-6 mb-3">
      <div class="small-box bg-primary text-white">
        <div class="inner">
          <h3><?= $todayVisitors ?? '-' ?></h3>
          <p>今日訪客</p>
        </div>
        <div class="icon">
          <i class="fa fa-users"></i>
        </div>
      </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <div class="small-box bg-success text-white">
        <div class="inner">
          <h3><?= $todayBattles ?? '-' ?></h3>
          <p>今日對戰場數</p>
        </div>
        <div class="icon">
          <i class="fa fa-crosshairs"></i>
        </div>
      </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <div class="small-box bg-warning text-white">
        <div class="inner">
          <h3><?= $activePlayers ?? '-' ?></h3>
          <p>活躍玩家（最近 24h）</p>
        </div>
        <div class="icon">
          <i class="fa fa-user"></i>
        </div>
      </div>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <div class="small-box bg-danger text-white">
        <div class="inner">
          <h3><?= $totalRecords ?? '-' ?></h3>
          <p>累積對戰紀錄</p>
        </div>
        <div class="icon">
          <i class="fa fa-database"></i>
        </div>
      </div>
    </div>

  </div>

  <!-- 快速入口 -->
  <div class="row mt-4">
    <div class="col-12">
      <h4 class="mb-3">快速入口</h4>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <a href="pages/ranking/index.php" class="btn btn-outline-primary btn-block py-3">
        📊 組合統計
      </a>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <a href="pages/ranking/use_rate.php" class="btn btn-outline-info btn-block py-3">
        📈 使用率分析
      </a>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <a href="pages/cost.php" class="btn btn-outline-warning btn-block py-3">
        🧮 計算 C
      </a>
    </div>

    <div class="col-md-3 col-sm-6 mb-3">
      <a href="pages/traffic_stats.php" class="btn btn-outline-secondary btn-block py-3">
        🌐 流量統計 Dashboard
      </a>
    </div>

  </div>

  <!-- 最新公告區（可選） -->
  <div class="row mt-4">
    <div class="col-12">
      <h4 class="mb-3">最新公告</h4>
      <div class="card">
        <div class="card-body">
          <p class="mb-1"><strong>2025/12/10</strong> — UL.GG Analytics v2 上線 Beta 版本。</p>
          <p class="mb-0 text-muted">新增：對戰記錄自動解析、熱門角色統計、真人流量分析 Dashboard。</p>
        </div>
      </div>
    </div>
  </div>

</div>

<?php include_once 'layout/footer.php'; ?>
