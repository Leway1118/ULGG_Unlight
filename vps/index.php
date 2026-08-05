<?php
session_start();
$pageTitle  = 'UL.GG — UNLIGHT 玩家入口';
$activeMenu = 'home';

ob_start();
?>

<style>
/* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */
/* UNLIGHT 哥德黑色主題 – Player Home */
/* ＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝＝ */

body {
    background: #0b0b0d;
    color: #e5e5e5;
    font-family: "Cinzel", "Noto Sans TC", serif;
}

.hero {
    text-align: center;
    padding: 60px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
    background: linear-gradient(180deg, #131316 0%, #0b0b0d 100%);
}

.hero-title {
    font-size: 44px;
    font-weight: 700;
    letter-spacing: 2px;
    color: #e8d19e; /* 金色 */
    text-shadow: 0 0 12px rgba(255, 215, 150, 0.35);
}

.hero-sub {
    margin-top: 10px;
    font-size: 18px;
    opacity: 0.85;
}

.hero-buttons {
    margin-top: 30px;
}

.btn-gothic {
    padding: 12px 25px;
    margin: 8px;
    border: 1px solid #e8d19e;
    color: #e8d19e;
    text-decoration: none;
    background: transparent;
    transition: 0.25s;
    display: inline-block;
    letter-spacing: 1px;
}

.btn-gothic:hover {
    background: #e8d19e;
    color: #000;
}

.search-box {
    width: 100%;
    max-width: 480px;
    margin: 35px auto;
    text-align: center;
}

.search-box input {
    width: 100%;
    padding: 14px 16px;
    border: 1px solid rgba(255,255,255,0.15);
    background: #16161a;
    color: #fff;
    border-radius: 4px;
}

.section-title {
    text-align: left;
    font-size: 22px;
    margin: 40px 0 15px;
    color: #e8d19e;
    border-left: 4px solid #e8d19e;
    padding-left: 10px;
}

.grid-3 {
    display: grid;
    gap: 20px;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
}

.card-gothic {
    background: #131316;
    padding: 18px;
    border: 1px solid rgba(255,255,255,0.12);
    position: relative;
    color: #dcdcdc;
    transition: 0.25s;
}

.card-gothic:hover {
    border-color: #e8d19e;
    box-shadow: 0 0 12px rgba(255,255,200,0.15);
}

.deck-title {
    font-size: 18px;
}

.deck-rate {
    margin-top: 8px;
    font-size: 26px;
    color: #e8d19e;
    font-weight: 600;
}

.deck-link {
    display: inline-block;
    margin-top: 10px;
    color: #e8d19e;
    text-decoration: underline;
}

.quick-link {
    padding: 18px;
    text-align: center;
    border: 1px solid rgba(255,255,255,0.12);
    color: #e8d19e;
    background: #17171a;
    text-decoration: none;
    transition: 0.25s;
}

.quick-link:hover {
    border-color: #e8d19e;
    background: #202024;
}

.live-box {
    margin-top: 30px;
    background: #19191c;
    padding: 20px;
    border: 1px solid rgba(255,255,255,0.1);
}
</style>

<!-- Hero -->
<div class="hero">
    <h1 class="hero-title">UNLIGHT 戰術研究中心</h1>
    <p class="hero-sub">查勝率．看組合．追蹤最新戰況 — UL.GG</p>

    <div class="hero-buttons">
        <a href="/ranking.php" class="btn-gothic">查看排行榜</a>
        <a href="/fight.php" class="btn-gothic">今日對戰組合</a>
    </div>
</div>

<!-- Search -->
<div class="search-box">
    <input type="text" placeholder="搜尋角色 / 組合 / 玩家名稱…" />
</div>

<!-- Hot Decks -->
<h2 class="section-title">🔥 今日熱門組合</h2>
<div class="grid-3">
    <div class="card-gothic">
        <div class="deck-title">本日勝率最高</div>
        <div class="deck-rate">67%</div>
        <a href="#" class="deck-link">查看詳情 →</a>
    </div>

    <div class="card-gothic">
        <div class="deck-title">新手最常使用</div>
        <div class="deck-rate">55%</div>
        <a href="#" class="deck-link">查看詳情 →</a>
    </div>

    <div class="card-gothic">
        <div class="deck-title">META 波動最大</div>
        <div class="deck-rate">-12%</div>
        <a href="#" class="deck-link">查看詳情 →</a>
    </div>
</div>

<!-- Ranking Quick Links -->
<h2 class="section-title">排行榜快速入口</h2>
<div class="grid-3">
    <a href="/ranking_bp.php" class="quick-link">BP 排行榜</a>
    <a href="/ranking_qp.php" class="quick-link">QP 排行榜</a>
    <a href="/fight.php" class="quick-link">即時對戰 Live Rooms</a>
</div>

<!-- Live Battle -->
<div class="live-box">
    <h2 class="section-title">🟢 即時對戰</h2>
    <p>目前有 <strong>12</strong> 間正在對戰（真人 10｜BOT 2）</p>
    <a href="/fight.php" class="btn-gothic">查看全部</a>
</div>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>
