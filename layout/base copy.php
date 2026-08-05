<?php
// layout/base.php

$pageTitleFull = $pageTitleFull ?? 'UL.GG 戰績網 | UNLIGHT 戰術研究中心';
$pageTitleText = $pageTitleText ?? 'UL.GG 戰績網';


if (!isset($activeMenu)) {
  $activeMenu = ''; // e.g. 'dashboard', 'ranking', 'queue', 'fight', 'statics'
}
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$currentUrl = $_SERVER['REQUEST_URI'] ?? '';

if (
  empty($_SESSION['steam_id']) &&
  preg_match('#^/pages/#', $currentUrl) &&
  !preg_match('#/(login|logout)#', $currentUrl)
) {
  $_SESSION['login_redirect'] = $currentUrl;
}

$username = $_SESSION['username'] ?? null;


// ===== 支付 QR Code（全站共用）=====
$paymentQR = [
  'jkopay' => null,
  'pxpay'  => null,
];

try {
  $sql = "
    SELECT pay_type, file_path
    FROM payment_qr
    WHERE is_active = 1
  ";
  $stmt = $db->query($sql);

  foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $paymentQR[$row['pay_type']] = $row['file_path'];
  }
} catch (Throwable $e) {
  // ❌ 正式站台不 echo，避免影響前台
}


?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
  <meta charset="UTF-8">
  <title>
    <?= isset($seoTitle)
      ? htmlspecialchars($seoTitle)
      : htmlspecialchars($pageTitleFull) ?>
  </title>

  <link rel="canonical" href="https://ulgg.online/">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Primary favicon (Google Search Results 最重要) -->
  <link rel="icon" href="/favicon.ico" sizes="48x48" type="image/x-icon">

  <!-- PNG icons -->
  <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
  <link rel="icon" type="image/png" sizes="192x192" href="/android-chrome-192x192.png">

  <!-- Apple -->
  <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">



  <!-- 字型：科技感乾淨字型 -->
  <link rel="stylesheet"
    href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap">

  <!-- 全站主題樣式 -->
  <link rel="stylesheet" href="/assets/css/theme.css?v=85">
  <link rel="stylesheet" href="/assets/css/layout.css?v=10">

  <!-- 圖表（給 statics / dashboard 用） -->
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

  <!-- Bootstrap -->
  <link rel="stylesheet" href="/assets/bower_components/bootstrap/dist/css/bootstrap.min.css">

  <!-- Font Awesome -->
  <link rel="stylesheet" href="/assets/bower_components/font-awesome/css/font-awesome.min.css">
  <link rel="stylesheet"
    href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">


  <!-- AdminLTE -->
  <link rel="stylesheet" href="/assets/dist/css/AdminLTE.min.css">
  <link rel="stylesheet" href="/assets/dist/css/skins/_all-skins.min.css">

  <!-- jQuery -->
  <script src="/assets/bower_components/jquery/dist/jquery.min.js"></script>

  <!-- Bootstrap JS -->
  <script src="/assets/bower_components/bootstrap/dist/js/bootstrap.min.js"></script>

  <!-- AdminLTE -->
  <script src="/assets/dist/js/adminlte.min.js"></script>



  <!-- Excel -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

  <!-- Screenshot -->
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>


  <style>
    .nav-user-info {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .nav-avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      border: 1px solid #555;
    }

    .nav-username {
      color: #fff;
      text-decoration: none;
      font-weight: bold;
    }

    .nav-username:hover {
      text-decoration: underline;
    }

    .nav-user-info {
      position: relative;
      margin-left: auto;
    }

    .user-box {
      display: flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      padding: 6px 10px;
      border-radius: 6px;
      transition: 0.2s;
    }

    .user-box:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    .user-box .avatar {
      width: 32px;
      height: 32px;
      border-radius: 50%;
    }

    .user-box .username {
      color: #fff;
      font-size: 14px;
      font-weight: 500;
    }

    .user-box .caret {
      color: #aaa;
      font-size: 12px;
    }

    .user-dropdown {
      position: absolute;
      top: 44px;
      right: 0;
      background: rgba(30, 30, 30, 0.95);
      backdrop-filter: blur(6px);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 6px;
      min-width: 160px;
      padding: 6px 0;
      display: none;
      z-index: 999;
    }

    .user-box:hover .user-dropdown {
      display: block;
    }

    .user-dropdown a {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 14px;
      font-size: 14px;
      color: #e0e0e0;
      text-decoration: none;
      transition: 0.15s;
    }

    .user-dropdown a:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    .user-dropdown a.logout {
      color: #ff6b6b;
    }

    .user-dropdown a.logout:hover {
      background: rgba(255, 0, 0, 0.15);
    }

    .nav-login-btn {
      padding: 8px 18px;
      background: rgba(255, 255, 255, 0.08);
      border: 1px solid rgba(255, 255, 255, 0.15);
      border-radius: 6px;
      color: #fff;
      font-size: 14px;
      font-weight: 500;
      text-decoration: none;
      transition: 0.15s;
    }

    .nav-login-btn:hover {
      background: rgba(255, 255, 255, 0.18);
      border-color: rgba(255, 255, 255, 0.25);
    }

    /* =========================
   UL.GG Footer (Final)
   Compact / Flat Version
========================= */

    /* Footer 淡入動畫 */
    .un-footer {
      padding: 28px 0 18px;
      opacity: 0;
      transform: translateY(20px);
      transition: opacity .6s ease, transform .6s ease;
    }

    .un-footer.footer-visible {
      opacity: 1;
      transform: translateY(0);
    }

    /* Footer 容器 */
    .footer-inner {
      text-align: center;
    }

    /* =========================
   Support 區塊
========================= */

    .footer-support {
      margin-bottom: 14px;
    }

    .footer-title {
      font-size: 14px;
      font-weight: 700;
      margin: 6px 0 4px;
      letter-spacing: 0.5px;
    }

    .footer-desc {
      font-size: 13px;
      line-height: 1.45;
      color: var(--ul-text-soft, #d0d0d0);
      margin-bottom: 6px;
    }

    .footer-note {
      font-size: 12px;
      line-height: 1.45;
      color: #aaa;
      margin-bottom: 10px;
    }

    .footer-note em {
      font-style: normal;
      color: #888;
    }

    /* =========================
   Ko-fi / Action Buttons
========================= */

    .kofi-btn,
    .report-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;

      padding: 6px 14px;
      border-radius: 999px;

      font-size: 13px;
      font-weight: 600;

      color: #fff;
      text-decoration: none;

      background: linear-gradient(180deg, #3a3f52, #2b2f40);
      border: 1px solid rgba(180, 200, 255, 0.35);

      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
      transition: all 0.2s ease;
    }

    .kofi-btn {
      background: linear-gradient(180deg, #2f7cf6, #1e5fd6);
      border: none;
      box-shadow: 0 2px 10px rgba(60, 120, 255, 0.35);
    }

    .kofi-btn:hover,
    .report-btn:hover {
      transform: translateY(-1px);
      box-shadow:
        0 0 10px rgba(120, 170, 255, 0.4),
        0 4px 10px rgba(0, 0, 0, 0.6);
    }

    /* =========================
   問題回報區
========================= */

    .footer-report {
      margin-top: 14px;
      padding-top: 10px;
      border-top: 1px dashed rgba(180, 200, 255, 0.25);
      text-align: center;
    }

    /* =========================
   Footer Copy
========================= */

    .footer-copy {
      margin-top: 12px;
      font-size: 11px;
      color: #777;
    }

    /* =========================
   Mobile Adjust
========================= */

    @media (max-width: 768px) {
      .un-footer {
        padding: 20px 12px 14px;
      }

      .footer-title {
        font-size: 14px;
      }

      .footer-desc,
      .footer-note {
        font-size: 12px;
      }

      .kofi-btn,
      .report-btn {
        position: relative;
        display: inline-flex;
        align-items: center;
        gap: 6px;

        padding: 6px 14px;
        border-radius: 999px;

        font-size: 13px;
        font-weight: 600;
        color: #fff;
        text-decoration: none;

        background: linear-gradient(180deg, #3a3f52, #2b2f40);
        border: 1px solid rgba(180, 200, 255, 0.35);

        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
        cursor: pointer;

        transition:
          transform .15s ease,
          box-shadow .15s ease,
          border-color .15s ease;

        /* 👀 微呼吸（很輕） */
        animation: btn-breathe 4s ease-in-out infinite;
      }

      @keyframes btn-breathe {

        0%,
        100% {
          box-shadow: 0 2px 8px rgba(0, 0, 0, .35);
        }

        50% {
          box-shadow: 0 2px 10px rgba(120, 170, 255, .15);
        }
      }


      .mobile-break {
        display: none;
      }
    }

    /* 手機浮動 Ko-fi */
    /* .kofi-float {
      display: none;
    } */

    @media (max-width: 768px) {
      /* .kofi-float {
        position: fixed;
        bottom: 14px;
        left: 50%;
        transform: translateX(-50%);
        background: linear-gradient(135deg, #7c4dff, #b388ff);
        border-radius: 999px;
        padding: 10px 16px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, .35);
        display: flex;
        align-items: center;
        gap: 10px;
        z-index: 999;
      }

      .kofi-float a {
        color: #fff;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
      }

      .kofi-close {
        background: rgba(255, 255, 255, .25);
        border: none;
        border-radius: 50%;
        width: 22px;
        height: 22px;
        color: #fff;
        font-size: 14px;
        cursor: pointer;
      } */
    }

    /* =========================
   Sidebar Divider
========================= */
    .sidebar-divider {
      height: 1px;
      margin: 14px 0;
      background: rgba(255, 255, 255, 0.08);
    }

    /* =========================
   Sidebar Data Status
========================= */
    .sidebar-status {
      padding: 10px 12px;
      font-size: 12px;
      color: #cfcfcf;
      line-height: 1.6;
    }

    .sidebar-status-title {
      font-weight: 600;
      font-size: 13px;
      margin-bottom: 8px;
      color: #ffffff;
      opacity: 0.9;
    }

    .status-item {
      display: flex;
      justify-content: space-between;
      margin-bottom: 4px;
    }

    .status-label {
      color: #9aa0a6;
    }

    .status-value {
      color: #e0e0e0;
      text-align: right;
    }

    /* =========================
   Status Dot
========================= */
    .status-dot {
      display: inline-block;
      width: 6px;
      height: 6px;
      border-radius: 50%;
      margin-right: 6px;
    }

    .status-green {
      background-color: #4caf50;
    }

    .status-yellow {
      background-color: #ffb300;
    }

    .status-red {
      background-color: #f44336;
    }

    /* =========================
   Sidebar Server Switcher (Refined)
========================= */

    .sidebar-server-switcher {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-top: 6px;
      position: relative;
      font-size: 12px;
    }

    .server-label {
      color: #9aa0a6;
      white-space: nowrap;
    }

    .server-btn {
      background: rgba(255, 255, 255, 0.04);
      border: 1px solid rgba(255, 255, 255, 0.12);
      color: #e0e0e0;
      font-size: 12px;
      padding: 4px 8px;
      border-radius: 6px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.15s ease;
    }

    .server-btn:hover {
      background: rgba(255, 255, 255, 0.12);
      border-color: rgba(255, 255, 255, 0.25);
    }

    .server-btn i {
      opacity: 0.9;
    }

    /* Dropdown menu */
    .server-menu {
      display: none;
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      min-width: 140px;
      background: #1b1c22;
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 8px;
      box-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
      overflow: hidden;
      z-index: 1000;
    }

    .server-option {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 12px;
      font-size: 13px;
      color: #e0e0e0;
      text-decoration: none;
      transition: background 0.15s ease;
    }

    .server-option:hover {
      background: rgba(255, 255, 255, 0.08);
    }

    .server-option.active {
      background: rgba(120, 120, 255, 0.22);
      font-weight: 600;
    }

    /* DMM icon */
    .server-icon.dmm {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 16px;
      height: 16px;
      font-size: 11px;
      font-weight: 700;
      border-radius: 4px;
      background: rgba(255, 255, 255, 0.18);
      color: #ffffff;
    }

    /* .footer-meta {
      margin-top: 18px;
      font-size: 12px;
      color: #888;
      line-height: 1.6;
    } */

    .title-mobile {
      display: none;
    }

    @media (max-width: 768px) {
      .title-desktop {
        display: none;
      }

      .title-mobile {
        display: inline;
      }
    }

    .status-sample {
      /* margin-top: 6px;
      padding-top: 6px;
      border-top: 1px dashed rgba(255, 255, 255, 0.15); */
      font-size: 13px;
      opacity: 0.9;
    }

    .status-label {
      color: #9aa4b2;
    }

    .status-value {
      font-weight: bold;
      color: #e6edf3;
    }

    .un-main-layout {
      display: flex;
      gap: 24px;
      align-items: flex-start;
    }

    /* 中央內容 */
    .un-main-content {
      flex: 1;
      min-width: 0;
      /* 防止表格爆版 */
    }

    /* 右上熱門 */
    .un-right-panel {
      width: 300px;
      flex-shrink: 0;
      position: sticky;
      top: 72px;
      /* 避開 navbar */
    }

    /* 手機隱藏右欄 */
    @media (max-width: 992px) {
      .un-right-panel {
        display: none;
      }
    }

    /* DMM icon */
    .server-icon.dmm {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 16px;
      height: 16px;
      font-size: 11px;
      font-weight: 700;
      border-radius: 4px;
      background: rgba(255, 255, 255, 0.18);
      color: #ffffff;
    }

    .un-main-inner {
      padding-top: 0;
    }

    /* =========================
   FIX: Mobile overlay blocks navbar dropdown
   Only for this page
========================= */

    @media (max-width: 768px) {

      /* 1️⃣ 讓 overlay 不吃點擊 */
      .un-overlay {
        pointer-events: none;
      }

      /* 2️⃣ 確保 navbar 本身可以被點 */
      .un-navbar {
        position: relative;
        z-index: 1001;
      }

      /* 3️⃣ dropdown 再拉高一層（保險） */
      .un-navbar .user-dropdown {
        z-index: 1002;
      }
    }

    .ulgg-modal.hidden {
      display: none;
    }

    .ulgg-modal {
      position: fixed;
      inset: 0;
      z-index: 20000;
    }

    .ulgg-modal-backdrop {
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, .75);
    }

    .ulgg-modal-card {
      position: relative;
      max-width: 420px;
      margin: 5vh auto;
      background: #111827;
      border-radius: 14px;
      padding: 10px 24px;
      border: 1px solid rgba(255, 255, 255, .08);
      color: #e5e7eb;
    }

    .ulgg-modal-close {
      position: absolute;
      top: 12px;
      right: 12px;
      background: none;
      border: none;
      color: #9aa4b2;
      font-size: 18px;
      cursor: pointer;
    }

    .jkopay-box {
      margin: 5px auto;
      padding: 5px;
      border-radius: 12px;
      border: 2px solid #e60012;
      text-align: center;
    }

    .jkopay-box img {
      max-width: 220px;
      width: 100%;
    }

    .ulgg-modal-note {
      font-size: 12px;
      color: #9aa4b2;
      margin-top: 2px;
    }

    .ulgg-modal-title {
      font-size: 20px;
      line-height: 1.4;
      color: #f9fafb;
      text-align: center;
      margin-top: 0px;
    }

    .ulgg-modal-title span {
      display: block;
      font-size: 14px;
      color: #facc15;
      /* 淡黃，咖啡感 */
      margin-top: 6px;
    }

    /* 疊卡容器 */
    .pay-stack {
      position: relative;
      width: 260px;
      margin: 12px auto;
      height: 320px;
    }

    /* 單張支付卡 */
    .pay-card {
      position: absolute;
      left: 0;
      right: 0;
      margin: auto;

      background: #0f172a;
      border-radius: 14px;
      padding: 10px;
      text-align: center;

      transition: transform .25s ease, box-shadow .25s ease;
      cursor: pointer;
    }

    /* QR */
    .pay-card img {
      width: 100%;
      height: 240px;
      object-fit: contain;
      /* ⭐ 關鍵 */
      background: #020617;
      border-radius: 8px;
    }


    /* 標籤 */
    .pay-label {
      margin-top: 6px;
      font-size: 13px;
      font-weight: 600;
      opacity: .9;
    }

    /* ===== 狀態控制 ===== */

    /* 上層（可掃描） */
    .pay-card.active {
      z-index: 2;
      transform: translateY(0);
      box-shadow: 0 12px 30px rgba(0, 0, 0, .45);
    }

    /* 下層（只露一點） */
    .pay-card:not(.active) {
      z-index: 1;
      transform: translateY(18px);
      opacity: .95;
    }

    /* 街口風 */
    .pay-jko {
      border: 2px solid #e60012;
    }

    /* 全支付風（黃） */
    .pay-pxp {
      border: 2px solid #facc15;
      background: #111;
      position: relative;
      /* 🔑 一定要 */
    }

    .pay-stack {
      position: relative;
      width: 260px;
      height: 360px;
      /* 🔑 控制整體高度 */
      margin: 16px auto 8px;
    }

    /* 共用卡片樣式 */
    .pay-card {
      position: absolute;
      left: 0;
      width: 100%;
      border-radius: 14px;
      transition: transform .25s ease, box-shadow .25s ease;
      cursor: pointer;
    }

    /* 主卡（街口） */
    .pay-card-main {
      top: 0;
      z-index: 2;
      box-shadow: 0 10px 30px rgba(0, 0, 0, .45);
    }

    /* 副卡（全支付）→ 露出一點點 */
    .pay-card-sub {
      /* top: 0px; */
      left: 28px;
      z-index: 1;
      transform: scale(.95);
      /* opacity: .85; */
    }

    /* Hover / 可點提示 */
    .pay-card-sub:hover {
      transform: scale(.97) translateY(-4px);
      opacity: 1;
    }

    /* =========================
   全支付 標示樣式
========================= */

    /* 黃色外框（已經有的話可保留） */
    .pay-pxp {
      border: 2px solid #facc15;
      background: #111;
      position: relative;
      /* 🔑 讓角標定位用 */
    }

    /* 右下角「全」圓章 */
    /* 右下角「全」圓章 */
    .pay-pxp::after {
      content: "全";
      position: absolute;
      right: 10px;
      bottom: 10px;

      width: 32px;
      height: 32px;
      border-radius: 50%;

      background: #facc15;
      color: #111;

      font-size: 16px;
      font-weight: 900;
      line-height: 32px;
      text-align: center;

      box-shadow: 0 2px 8px rgba(0, 0, 0, .45);
      pointer-events: none;
    }

    .pay-jko::after {
      content: "街";
      position: absolute;
      right: 10px;
      bottom: 10px;

      width: 32px;
      height: 32px;
      border-radius: 50%;

      background: #e60012;
      color: #fff;

      font-size: 16px;
      font-weight: 900;
      line-height: 32px;
      text-align: center;

      box-shadow: 0 2px 8px rgba(0, 0, 0, .45);
      pointer-events: none;
    }

    .sidebar-group {
      margin-bottom: 4px;
    }

    .sidebar-group-toggle {
      width: 100%;
      background: transparent;
      border: 0;
      padding: 10px 14px;
      display: flex;
      align-items: center;
      gap: 8px;
      color: #e5e7eb;
      font-weight: 600;
      cursor: pointer;
    }

    .sidebar-group-toggle:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .sidebar-group-items {
      display: none;
      padding-left: 8px;
    }

    .sidebar-group-items.open {
      display: block;
    }

    .sidebar-group-items .sidebar-link {
      padding-left: 28px;
    }

    .chevron {
      margin-left: auto;
      font-size: 0.75rem;
      opacity: 0.6;
    }

    /* =========================
   FIX: Sidebar double scrollbar
========================= */

    /* 1️⃣ sidebar 本體才可以捲 */
    .un-sidebar {
      height: 100vh;
      overflow-y: auto;
      overflow-x: hidden;
    }

    /* 2️⃣ 禁止內層自己產生捲軸（重點） */
    .un-sidebar nav,
    .sidebar-nav,
    .sidebar-group,
    .sidebar-group-items {
      overflow: visible !important;
    }

    /* Chrome / Edge */
    .un-sidebar::-webkit-scrollbar {
      width: 6px;
    }

    .un-sidebar::-webkit-scrollbar-track {
      background: transparent;
    }

    .un-sidebar::-webkit-scrollbar-thumb {
      background-color: #3b4252;
      border-radius: 6px;
    }

    /* Firefox */
    .un-sidebar {
      scrollbar-width: thin;
      scrollbar-color: #3b4252 transparent;
    }

    .footer-support {
      display: flex;
      justify-content: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 14px;
    }

    .footer-support .kofi-btn span {
      font-size: 11px;
      opacity: .85;
    }
  </style>
  <?php

  $isSteamLogin = !empty($_SESSION['steam_id']);

  $isVerifiedUL = $isSteamLogin && ($_SESSION['ack'] ?? 0) >= 1;
  $isApplyingUL = $isSteamLogin && ($_SESSION['ack'] ?? 0) == 0 && !empty($_SESSION['apply']);
  $isGuestSteam = $isSteamLogin && ($_SESSION['ack'] ?? 0) == 0 && empty($_SESSION['apply']);


  /**
   * Data Status (Simple Version)
   * 只支援 TW / JP
   */

  // 預設 TW
  $server =  'Steam';
  $tableName   = 'ranking_bp_TW';
  $serverLabel = 'Steam 服';

  // 撈最後更新時間
  $sql = "SELECT MAX(ts) AS last_update FROM {$tableName}";
  $stmt = $db->query($sql);
  $row  = $stmt->fetch(PDO::FETCH_ASSOC);

  $lastUpdate = $row['last_update'] ?? null;

  // 預設顯示
  $statusText = '尚無資料';
  $statusDot  = 'status-red';

  if ($lastUpdate) {
    $diffSeconds = time() - strtotime($lastUpdate);
    $diffMinutes = floor($diffSeconds / 60);

    if ($diffMinutes < 30) {
      $statusDot = 'status-green';
    } elseif ($diffMinutes < 60) {
      $statusDot = 'status-yellow';
    } else {
      $statusDot = 'status-red';
    }

    if ($diffMinutes < 1) {
      $statusText = '剛剛更新';
    } elseif ($diffMinutes < 60) {
      $statusText = "{$diffMinutes} 分鐘前";
    } else {
      $hours = floor($diffMinutes / 60);
      $statusText = "{$hours} 小時前";
    }
  }


  $server =  'JP';
  $tableName   = 'ranking_bp_JP';
  $serverLabelJP = 'DMM 服';

  // 撈最後更新時間
  $sql = "SELECT MAX(ts) AS last_update FROM {$tableName}";
  $stmt = $db->query($sql);
  $row  = $stmt->fetch(PDO::FETCH_ASSOC);

  $lastUpdate = $row['last_update'] ?? null;

  // 預設顯示
  $statusTextJP = '尚無資料';
  $statusDotJP  = 'status-red';

  if ($lastUpdate) {
    $diffSeconds = time() - strtotime($lastUpdate);
    $diffMinutes = floor($diffSeconds / 60);

    if ($diffMinutes < 30) {
      $statusDotJP = 'status-green';
    } elseif ($diffMinutes < 60) {
      $statusDotJP = 'status-yellow';
    } else {
      $statusDotJP = 'status-red';
    }

    if ($diffMinutes < 1) {
      $statusTextJP = '剛剛更新';
    } elseif ($diffMinutes < 60) {
      $statusTextJP = "{$diffMinutes} 分鐘前";
    } else {
      $hours = floor($diffMinutes / 60);
      $statusTextJP = "{$hours} 小時前";
    }
  }
  // arena_unlight 中實際用來分析的場次
  $sql = "SELECT COUNT(*)
  FROM arena_unlight
  WHERE ack1 = 1
    AND ack2 = 1
    AND (win = 1 OR lose = 1 OR tie = 1)";
  $totalSamples = (int)$db->query($sql)->fetchColumn();

  // 今日熱門搜尋
  $sql = "SELECT search_term, COUNT(*) AS cnt
  FROM visitors
  WHERE search_term IS NOT NULL
    AND search_term != ''
    AND is_bot = 0
    AND visited_at >= CURDATE()
  GROUP BY search_term
  ORDER BY cnt DESC
  LIMIT 5
";
  $topSearch = $db->query($sql)->fetchAll();

  // 今日熱門角色
  $sql = "SELECT character_name, COUNT(*) AS cnt
  FROM visitors
  WHERE character_name IS NOT NULL
    AND character_name != ''
    AND is_bot = 0
    AND visited_at >= CURDATE()
  GROUP BY character_name
  ORDER BY cnt DESC
  LIMIT 5
";
  $topChar = $db->query($sql)->fetchAll();

  ?>
</head>

<body class="un-body">
  <header class="un-navbar">

    <button class="nav-toggle" type="button" data-toggle="sidebar">
      ☰
    </button>
    <div class="nav-brand">
      <span class="nav-logo">UL.GG</span>
      <span class="nav-subtitle">Unlight Analytics</span>
    </div>
    <div class="nav-right">

      <?php if (!empty($_SESSION['steam_id'])): ?>

        <?php
        // 決定顯示名稱：UL 名優先，其次 Steam 名，再其次是 Steam ID
        $displayName = $_SESSION['username']
          ?? $_SESSION['steam_name']
          ?? $_SESSION['steam_id'];

        // 頭像 fallback
        $avatar = $_SESSION['steam_avatar_full']
          ?? "/assets/img/default_avatar.png";
        if (empty($avatar)) {
          $avatar = "/assets/img/default_avatar.png";
        }
        ?>

        <div class="nav-user-info">
          <div class="user-box" onclick="toggleUserMenu()">

            <img src="<?= htmlspecialchars($avatar) ?>" class="avatar">

            <span class="username"><?= htmlspecialchars($displayName) ?></span>

            <i class="fa fa-caret-down caret"></i>

            <div class="user-dropdown" id="userDropdown">

              <!-- UL.GG 個人頁面（有 UL ID 才顯示） -->
              <?php if ($isVerifiedUL): ?>
                <!-- ack = 1，已綁定 -->
                <a href="/pages/profile.php">
                  <i class="fa fa-user"></i> 我的 UL.GG
                </a>

              <?php elseif ($isApplyingUL): ?>
                <!-- apply = 1，審核中 -->
                <a href="/pages/bind_ulid.php" style="opacity:.7;">
                  <i class="fa fa-clock-o"></i> UL ID 審核中
                </a>

              <?php elseif ($isGuestSteam): ?>
                <!-- ack = 0 & apply = 0 -->
                <a href="/pages/bind_ulid.php">
                  <i class="fa fa-link"></i> 綁定 UL ID
                </a>
              <?php endif; ?>



              <!-- Steam Profile -->
              <a href="https://steamcommunity.com/profiles/<?= $_SESSION['steam_id'] ?>" target="_blank">
                <i class="fab fa-steam"></i> 我的 Steam
              </a>

              <!-- 登出 -->
              <a href="/pages/logout.php" class="logout">
                <i class="fa fa-sign-out"></i> 登出
              </a>
              <!-- 登出所有裝置 -->
              <a href="/pages/logout_all.php"
                class="logout-all"
                onclick="return confirm('這會讓所有裝置都登出，確定嗎？');">
                <i class="fa fa-power-off"></i> 登出所有裝置
              </a>

            </div>
          </div>
        </div>
      <?php else: ?>
        <div class="nav-login-area">
          <label class="remember-me">
            <input type="checkbox" id="rememberLogin" checked>
            <span>記住我</span>
          </label>

          <a href="/api/auth/steam_start.php"
            class="nav-login-btn"
            onclick="rememberSteamLogin(event)">
            登入
          </a>
        </div>
      <?php endif; ?>
    </div>
  </header>
  <div class="un-overlay"></div><!-- ⭐ 手機遮罩 -->
  <div class="un-layout">
    <aside class="un-sidebar">
      <div class="sidebar-section sidebar-title">
        <span class="sidebar-game">UNLIGHT</span>




      </div>


      <nav class="sidebar-nav">
        <!-- 系統 -->
        <a href="/pages/index.php"
          class="sidebar-link <?= $activeMenu === 'index' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">📢</span>
          <span class="sidebar-text">首頁情報 <small>Bulletin</small></span>
        </a>

        <!-- ⭐ 新增：ULGG 杯 -->
        <a href="/pages/tournament/ulgg_cup.php"
          class="sidebar-link <?= $activeMenu === 'ulgg_cup' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">🏟️</span>
          <span class="sidebar-text">ULGG 杯 <small>Cup</small></span>
        </a>

        <!-- 排行 -->
        <div class="sidebar-group">

          <button class="sidebar-group-toggle" data-target="group-team">
            <span class="sidebar-icon">🏆</span>
            <span class="sidebar-text">對戰組合 <small>Team</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-team">

            <a href="/pages/ranking_team.php"
              class="sidebar-link <?= $activeMenu === 'ranking_team' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🏆</span>
              <span class="sidebar-text">組合排行 <small>Ranking</small></span>
            </a>

            <a href="/pages/strength_rank.php"
              class="sidebar-link <?= $activeMenu === 'strength_rank' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🔥</span>
              <span class="sidebar-text">強度排行 <small>Strength</small></span>
            </a>

          </div>
        </div>



        <div class="sidebar-group">
          <button class="sidebar-group-toggle" data-target="group-character">
            <span class="sidebar-icon">🧙</span>
            <span class="sidebar-text">角色 <small>Character</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-character">
            <a href="/pages/ranking_char.php"
              class="sidebar-link <?= $activeMenu === 'ranking_char' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🧙</span>
              <span class="sidebar-text">角色排行 <small>Ranking</small></span>
            </a>

            <a href="/pages/character_analysis.php"
              class="sidebar-link <?= $activeMenu === 'character_analysis' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🧠</span>
              <span class="sidebar-text">角色分析 <small>Analysis</small></span>
            </a>

            <a href="/pages/character_intro.php"
              class="sidebar-link <?= $activeMenu === 'character_intro' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🃏</span>
              <span class="sidebar-text">集卡冊 <small>Intro</small></span>
            </a>




            <!-- ⭐ 新增：自製卡面 Fan ART -->
            <a href="/pages/upload_card_art.php"
              class="sidebar-link <?= $activeMenu === 'upload_card_art' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🎨</span>
              <span class="sidebar-text">自製卡面 <small>Fan Art</small></span>
            </a>
          </div>
        </div>


        <div class="sidebar-group">
          <button class="sidebar-group-toggle" data-target="group-rank">
            <span class="sidebar-icon">📊</span>
            <span class="sidebar-text">排行榜 <small>Rank</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items" id="group-rank">

            <a href="/pages/bp_rank.php"
              class="sidebar-link <?= $activeMenu === 'bp_rank' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">📈</span>
              <span class="sidebar-text">BP 排行</span>
            </a>

            <a href="/pages/qp_rank.php"
              class="sidebar-link <?= $activeMenu === 'qp_rank' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">📊</span>
              <span class="sidebar-text">QP 排行</span>
            </a>

            <!-- 🏆 歷史名人堂 -->
            <a href="/pages/hall_of_fame.php"
              class="sidebar-link <?= $activeMenu === 'hall_of_fame' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">🏆</span>
              <span class="sidebar-text">歷史名人堂</span>
            </a>

          </div>
        </div>

        <a href="/pages/quest_map.php"
          class="sidebar-link <?= $activeMenu === 'quest_map' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">🧭</span>
          <span class="sidebar-text">地圖任務查詢 <small>Quest</small></span>
        </a>

        <a href="/pages/skills.php"
          class="sidebar-link <?= $activeMenu === 'skills' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">🏷️</span>
          <span class="sidebar-text">技能戰術搜尋 <small>Skills</small></span>
        </a>

        <!-- 工具 -->
        <a href="/pages/calculator.php"
          class="sidebar-link <?= $activeMenu === 'calculator' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">⚔️</span>
          <span class="sidebar-text">計算Cost <small>Calculator</small></span>
        </a>
        <a href="/pages/queue.php"
          class="sidebar-link <?= $activeMenu === 'queue' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">👀</span>
          <span class="sidebar-text">觀察大廳 <small>Lobby</small></span>
        </a>

        <?php if (!empty($_SESSION['permission']) && $_SESSION['permission'] >= 2): ?>
          <div class="sidebar-divider"></div>

          <div class="sidebar-section-title">🛠 管理員</div>

          <!-- 👤 會員管理 -->
          <a href="/pages/admin/user_manage.php"
            class="sidebar-link <?= $activeMenu === 'user_manage' ? 'is-active' : '' ?>">
            <span class="sidebar-icon">👤</span>
            <span class="sidebar-text">會員管理 <small>Memberships</small></span>
          </a>




          <?php if (!empty($_SESSION['permission']) && $_SESSION['permission'] >= 3): ?>

            <!-- ☕ 上傳支付 -->
            <a href="/pages/admin/payment_qr.php"
              class="sidebar-link <?= $activeMenu === 'user_manage' ? 'is-active' : '' ?>">
              <span class="sidebar-icon">☕</span>
              <span class="sidebar-text">上傳紀錄 <small>Upload</small></span>
            </a>


          <?php endif; ?>
        <?php endif; ?>


        <!-- <a href="/pages/traffic_stats.php"
          class="sidebar-link <?= $activeMenu === 'statics' ? 'is-active' : '' ?>">
          <span class="sidebar-icon">📊</span>
          <span class="sidebar-text">流量統計 Statics</span>
        </a> -->


        <!-- =========================
              Data Status (Compact)
          ========================= -->
        <div class="sidebar-divider"></div>

        <div class="sidebar-status">

          <div class="sidebar-status-title">📡 資料狀態</div>

          <!-- <div class="status-source">
            來源：遊戲中可公開觀察之排行事件
          </div> -->

          <div class="status-row">
            <span class="status-server">
              <?= htmlspecialchars($serverLabel) ?>
            </span>
            <span class="status-update">
              <span class="status-dot <?= $statusDot ?>"></span>
              <?= htmlspecialchars($statusText) ?>
            </span>
          </div>

          <div class="status-row">
            <span class="status-server">
              <?= htmlspecialchars($serverLabelJP) ?>
            </span>
            <span class="status-update">
              <span class="status-dot <?= $statusDotJP ?>"></span>
              <?= htmlspecialchars($statusTextJP) ?>
            </span>
          </div>

          <!-- ⭐ 新增：分析樣本數 -->
          <div class="status-row status-sample">
            <span class="status-label">📊 分析樣本數</span>
            <span class="status-value">
              <?= number_format($totalSamples) ?> 場
            </span>
          </div>

        </div>


      </nav>
    </aside>

    <main class="un-main">
      <?php

      // ⭐ 錯誤顯示（正式環境可關）
      if (
        isset($_SESSION['username']) &&
        in_array($_SESSION['username'], ['way.lee', '咕嚕．挖2朵'], true)
      ) {
        ini_set('display_errors', 1);
        error_reporting(E_ALL);
        /* echo '<pre style="background:#111;color:#0f0;padding:12px;">';
        echo "SESSION ID: " . session_id() . "\n\n";
        echo "=== \$_POST ===\n";
        print_r($_POST);
        echo "=== \$_GET ===\n";
        print_r($_GET);
        echo "\n=== \$_SESSION ===\n";
        print_r($_SESSION);
        echo "\nREQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? '');
        echo "\nREFERER: " . ($_SERVER['HTTP_REFERER'] ?? '');
        echo '</pre>'; */

        /* echo '<pre>';
        foreach ($steamNews as $i => $n) {
          echo "[$i]\n";
          echo "title: {$n['title']}\n";
          echo "feed: {$n['feedname']}\n";
          echo "len(contents): " . strlen($n['contents'] ?? '') . "\n";
          echo "-----\n";
        }
        echo '</pre>';
        exit; */
      }
      ?>
      <div class="un-main-inner">
        <?php if (empty($hidePageHeader)): ?>
          <div class="page-header">
            <h1 class="page-title">
              <span class="title-desktop"><?= htmlspecialchars($pageTitleFull) ?></span>
              <span class="title-mobile"><?= htmlspecialchars($pageTitleText) ?></span>
            </h1>
          </div>
        <?php endif; ?>


        <!-- 頁面實際內容 -->
        <?= $pageContent ?? '' ?>


      </div>

      <footer class="un-footer">
        <div class="footer-inner container">

          <!-- 💜 支持 UL.GG Modal -->
          <div class="footer-support">

            <!-- 台灣支付（Modal） -->
            <a class="open-support-modal report-btn">
              💜 支持 UL.GG
              <span>街口 / 全支付</span>
            </a>

            <!-- 信用卡（Buy Me a Coffee） -->
            <a class="report-btn"
              href="https://www.buymeacoffee.com/ulgg"
              target="_blank"
              rel="noopener">
              ☕ Buy Me a Coffee
              <span>信用卡</span>
            </a>

          </div>


          <!-- 🐞 問題回報 -->
          <div class="footer-report">
            <!-- <div class="footer-title">🐞 問題回報</div>

            <div class="footer-desc">
              若您在使用本站時遇到錯誤、顯示異常或資料問題，<br>
              歡迎回報協助我們改善。
            </div> -->

            <a
              href="mailto:ulgg.online@gmail.com?subject=UL.GG 問題回報&body=請描述您遇到的問題：%0A%0A頁面網址：%0A使用裝置／瀏覽器：%0A問題發生時間：">
              回報問題
            </a>
            -
            <a href="/pages/about.php" class="footer-link">
              關於本站
            </a>
            -
            <a href="/pages/privacy.php" class="footer-link">
              隱私權政策
            </a>
          </div>

          <!-- © -->
          <div class="footer-copy">
            © <?= date('Y') ?> UL.GG • Unlight Fan-made Statistics
          </div>

        </div>
      </footer>

    </main>
  </div>
  <!-- <div class="kofi-float" id="kofiFloat">
    <a href="https://ko-fi.com/ulggs" target="_blank" rel="noopener">
      ☕ 支持 UL.GG
    </a>
    <button class="kofi-close" onclick="closeKofi()">×</button>
  </div> -->
</body>

<div id="supportModal" class="ulgg-modal hidden">
  <div class="ulgg-modal-backdrop"></div>

  <div class="ulgg-modal-card">
    <button class="ulgg-modal-close">✕</button>

    <h3 class="ulgg-modal-title">
      💜 支持 UL.GG
      <span class="subtitle">☕ 請站長喝杯咖啡</span>
    </h3>

    <p class="ulgg-modal-desc">
      UL.GG 是由玩家自發維護的<br>
      <strong>非官方 UNLIGHT 戰績分析網站</strong>。<br>
      所有功能皆免費提供，<br>
      若你覺得本站對你有幫助，<br>
      歡迎使用 <strong>街口支付、全支付</strong> 小額支持網站維運 🙏
    </p>

    <div class="pay-stack">
      <!-- 街口支付 -->
      <div class="pay-card pay-card-main pay-jko active" data-pay="jkopay">
        <?php if (!empty($paymentQR['jkopay'])): ?>
          <img src="<?= htmlspecialchars($paymentQR['jkopay']) ?>" alt="街口支付 QR">
        <?php else: ?>
          <div class="text-muted small">尚未設定街口支付</div>
        <?php endif; ?>
      </div>

      <!-- 全支付 -->
      <div class="pay-card pay-card-sub pay-pxp" data-pay="pxpay">
        <?php if (!empty($paymentQR['pxpay'])): ?>
          <img src="<?= htmlspecialchars($paymentQR['pxpay']) ?>" alt="全支付 QR">
        <?php else: ?>
          <div class="text-muted small">今日尚未設定全支付 QR</div>
        <?php endif; ?>
      </div>
    </div>

    <div class="pay-label">
      👉 點擊露出的卡片可切換支付方式
    </div>



    <div class="ulgg-modal-note">
      ＊非官方網站，非商品交易<br>
      ＊僅用於主機與網站維運費用
    </div>
  </div>
</div>

<!-- Go To Top Button -->
<button id="backToTop" onclick="scrollToTop()">▲</button>
<!-- Go To Bottom Button -->
<button id="goToBottom" onclick="scrollToBottom()">▼</button>

<script>
  // 監聽滾動事件，決定是否顯示按鈕
  window.onscroll = function() {
    let topButton = document.getElementById("backToTop");
    let bottomButton = document.getElementById("goToBottom");
    let scrollTop = document.documentElement.scrollTop;
    let scrollHeight = document.documentElement.scrollHeight;
    let clientHeight = document.documentElement.clientHeight;

    if (scrollTop > 200) {
      topButton.style.display = "flex"; // 顯示回到頂部按鈕
    } else {
      topButton.style.display = "none"; // 隱藏按鈕
    }

    if (scrollTop + clientHeight < scrollHeight - 200) {
      bottomButton.style.display = "flex"; // 顯示滾到底部按鈕
    } else {
      bottomButton.style.display = "none"; // 隱藏按鈕
    }
  };

  // 點擊按鈕回到頂部
  function scrollToTop() {
    window.scrollTo({
      top: 0,
      behavior: "smooth" // 平滑滾動效果
    });
  }

  // 點擊按鈕滾到底部
  function scrollToBottom() {
    window.scrollTo({
      top: document.documentElement.scrollHeight,
      behavior: "smooth" // 平滑滾動效果
    });
  }
</script>
<!-- <script src="/assets/js/main.js"></script> -->
<script>
  function toggleServerMenu() {
    const menu = document.getElementById('serverMenu');
    menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
  }

  document.addEventListener('click', function(e) {
    const switcher = document.querySelector('.sidebar-server-switcher');
    if (switcher && !switcher.contains(e.target)) {
      document.getElementById('serverMenu').style.display = 'none';
    }
  });
</script>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const footer = document.querySelector('.un-footer');
    if (!footer) return;

    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          footer.classList.add('footer-visible');
          observer.disconnect(); // 只觸發一次
        }
      });
    }, {
      threshold: 0.15
    });

    observer.observe(footer);
  });
</script>

<!-- 切換 -->
<script>
  document.querySelectorAll('.open-support-modal').forEach(btn => {
    btn.addEventListener('click', () => {
      document.getElementById('supportModal').classList.remove('hidden');
    });
  });

  document.querySelector('.ulgg-modal-close').onclick = () => {
    document.getElementById('supportModal').classList.add('hidden');
  };

  document.querySelector('.ulgg-modal-backdrop').onclick = () => {
    document.getElementById('supportModal').classList.add('hidden');
  };
</script>

<script>
  document.querySelectorAll('.pay-card').forEach(card => {
    card.addEventListener('click', () => {
      document.querySelectorAll('.pay-card').forEach(c => {
        c.classList.remove('active');
      });
      card.classList.add('active');
    });
  });
</script>

<script>
  document.querySelectorAll('.sidebar-group-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = document.getElementById(btn.dataset.target);
      target.classList.toggle('open');
    });
  });

  // 若裡面有 is-active，自動展開
  document.querySelectorAll('.sidebar-group-items').forEach(group => {
    if (group.querySelector('.is-active')) {
      group.classList.add('open');
    }
  });
</script>

<!-- remember-me -->
<script>
  function rememberSteamLogin(e) {
    e.preventDefault();

    const remember = document.getElementById('rememberLogin')?.checked ? 1 : 0;

    fetch('/api/auth/remember_flag.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        remember
      })
    }).finally(() => {
      window.location.href = '/api/auth/steam_start.php';
    });
  }
</script>

<!-- 1️⃣ jQuery（只能一份） -->
<!-- <script src="/assets/bower_components/jquery/dist/jquery.min.js"></script> -->

<!-- 2️⃣ DataTables core（一定要在 Buttons 前） -->
<script src="/assets/bower_components/datatables.net/js/jquery.dataTables.min.js"></script>
<script src="/assets/bower_components/datatables.net-bs/js/dataTables.bootstrap.min.js"></script>

<!-- 3️⃣ Buttons（現在才可以） -->
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap.min.js"></script>

<!-- 4️⃣ 匯出相依 -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

<!-- 5️⃣ 最後才是你自己的 JS -->
<script src="/assets/js/main.js"></script>


</html>
<?php
require_once APP_ROOT . '/lib/visitor_logger.php';
?>