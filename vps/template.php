<?php
// 使用方式：
// 1. 設定 $pageTitle = "頁面標題";
// 2. 用 ob_start() 包住你的 HTML → 存到 $pageContent
// 3. include template.php，即可呈現新 UI

if (!isset($pageTitle)) $pageTitle = "UL.GG - New Page";
if (!isset($activeMenu)) $activeMenu = ""; // ex: ranking / queue / fight / statics
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Google Font: Inter -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- 全站主題 CSS -->
    <link rel="stylesheet" href="assets/css/theme.css">

    <!-- Chart.js（如頁面不需要可移除） -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body class="un-body">

<!-- =======================
     NAVBAR
======================= -->
<header class="un-navbar">
    <button class="nav-toggle" type="button" data-toggle="sidebar">☰</button>

    <div class="nav-brand">
        <span class="nav-logo">UL.GG</span>
        <span class="nav-subtitle">Unlight Analytics</span>
    </div>

    <div class="nav-right">
        <?php if (!empty($_SESSION['username'])): ?>
            <span class="nav-user">👤 <?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></span>
        <?php else: ?>
            <span class="nav-user nav-user-guest">未登入訪客</span>
        <?php endif; ?>
    </div>
</header>

<!-- 手機遮罩（Sidebar 彈出時顯示） -->
<div class="un-overlay"></div>

<!-- =======================
     SIDEBAR
======================= -->
<div class="un-layout">
    <aside class="un-sidebar">

        <div class="sidebar-section sidebar-title">
            <span class="sidebar-game">UNLIGHT</span>
            <span class="sidebar-server">TW / JP / CR</span>
        </div>

        <nav class="sidebar-nav">

            <a href="index.php"
               class="sidebar-link <?= $activeMenu === 'dashboard' ? 'is-active' : '' ?>">
                <span class="sidebar-icon">🏠</span>
                <span class="sidebar-text">總覽 Dashboard</span>
            </a>

            <a href="ranking.php"
               class="sidebar-link <?= $activeMenu === 'ranking' ? 'is-active' : '' ?>">
                <span class="sidebar-icon">🏆</span>
                <span class="sidebar-text">角色排行 Ranking</span>
            </a>

            <a href="queue.php"
               class="sidebar-link <?= $activeMenu === 'queue' ? 'is-active' : '' ?>">
                <span class="sidebar-icon">📥</span>
                <span class="sidebar-text">排隊佇列 Queue</span>
            </a>

            <a href="fight.php"
               class="sidebar-link <?= $activeMenu === 'fight' ? 'is-active' : '' ?>">
                <span class="sidebar-icon">⚔️</span>
                <span class="sidebar-text">對戰紀錄 Fight</span>
            </a>

            <a href="statics.php"
               class="sidebar-link <?= $activeMenu === 'statics' ? 'is-active' : '' ?>">
                <span class="sidebar-icon">📊</span>
                <span class="sidebar-text">流量統計 Statics</span>
            </a>

        </nav>
    </aside>

    <!-- ======================
         MAIN CONTENT
    ======================= -->
    <main class="un-main">
        <div class="un-main-inner">

            <?php if (!empty($pageTitle)): ?>
                <div class="page-header">
                    <h1 class="page-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                </div>
            <?php endif; ?>

            <!-- ⭐⭐⭐ 這裡是頁面內容（每頁只要準備 $pageContent） -->
            <?= $pageContent ?? "<p>（此頁沒有內容）</p>" ?>
            <!-- ⭐⭐⭐ -->

        </div>

        <footer class="un-footer">
            <span>© <?= date('Y') ?> UL.GG • Fan-made Analytics System</span>
        </footer>
    </main>
</div>

<!-- 全站 JS（含 Sidebar 滑動 + 遮罩） -->
<script src="assets/js/main.js"></script>
</body>
</html>
