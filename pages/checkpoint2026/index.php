<?php
declare(strict_types=1);
require_once __DIR__.'/../../lib/checkpoint2026/StagingGateway.php';
\Ulgg\Checkpoint2026\StagingGateway::requireStage();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'");
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>2026 年度回顧｜UL.GG 合成資料預覽</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="/pages/checkpoint2026/story.css">
<script src="/pages/checkpoint2026/story.js" defer></script>
</head>
<body>
<main class="app" id="app">
  <header class="top">
    <span class="brand">UL.GG <span class="brand-light">/ 2026 CHECKPOINT</span></span>
    <span class="staging">SYNTHETIC STAGING · NOT LIVE</span>
  </header>
  <h1>你的 2026 年度故事</h1>
  <p class="intro">以可驗證的戰績開始；資料不足的地方，我們會清楚標示。</p>
  <div id="live-state" class="live-state" role="status" aria-live="polite">正在載入合成資料預覽…</div>
  <section id="story" class="story" aria-label="年度回顧故事卡">
    <article class="slide" id="slide-0" data-slide="0" aria-labelledby="slide-title-0">
      <span class="eyebrow">01 / 回顧總覽</span>
      <h2 id="slide-title-0">今年，留下多少場對戰？</h2>
      <div class="metric" id="matches">—</div>
      <p class="metric-label">有效 PvP 場次 <span id="pvp-quality" class="quality">尚未驗證</span></p>
      <div class="triplet">
        <span>勝 <strong id="wins">—</strong></span>
        <span>敗 <strong id="losses">—</strong></span>
        <span>平 <strong id="draws">—</strong></span>
      </div>
      <p class="note">僅顯示你的登入帳號；未確認資料不當成零場。</p>
    </article>
    <article class="slide" id="slide-1" data-slide="1" aria-labelledby="slide-title-1" hidden>
      <span class="eyebrow">02 / 常用角色</span>
      <h2 id="slide-title-1">今年與你並肩的角色</h2>
      <div class="metric" id="character-count">—</div>
      <p class="metric-label">有紀錄的角色種類</p>
      <ol id="characters" class="rank-list"><li>等待資料驗證</li></ol>
    </article>
    <article class="slide" id="slide-2" data-slide="2" aria-labelledby="slide-title-2" hidden>
      <span class="eyebrow">03 / 隊伍與月份</span>
      <h2 id="slide-title-2">你最常用的隊伍</h2>
      <div class="metric small-metric" id="team">—</div>
      <p class="metric-label" id="team-matches">隊伍資料待驗證</p>
      <p class="note" id="active-months">活躍月份：—</p>
      <div class="month-line" id="months" aria-label="12 個月份的對戰場次"></div>
    </article>
    <article class="slide" id="slide-3" data-slide="3" aria-labelledby="slide-title-3" hidden>
      <span class="eyebrow">04 / ULGG CUP</span>
      <h2 id="slide-title-3">全站賽事大事記</h2>
      <div class="metric" id="cup-games">—</div>
      <p class="metric-label">公開資料中的 verified 小局</p>
      <ul id="cup-stories" class="rank-list"><li>尚未載入公開大事記</li></ul>
      <p class="note">個人 CUP：<strong id="personal-cup">UNKNOWN</strong> · 沒有權威綁定就不認作你的成績。</p>
    </article>
  </section>
  <nav class="story-nav" aria-label="故事卡切換">
    <button type="button" id="prev" class="secondary" disabled>上一頁</button>
    <span id="page-count" aria-live="polite">1 / 4</span>
    <button type="button" id="next">下一頁</button>
    <button type="button" id="skip" class="secondary">略過動畫／顯示摘要</button>
  </nav>
  <footer>資料契約 checkpoint2026/1.0.0-draft · 只限本機測試，不是正式年度回顧</footer>
</main>
</body>
</html>
