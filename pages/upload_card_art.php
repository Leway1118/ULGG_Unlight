<?php
/* upload_card_art.php */
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定
/* config內define('IMG_BASE', '/assets/uploads/');
config內define('APP_ROOT', __DIR__); */
$pdo = $db; // ⭐ 一定要這行
//require_once __DIR__ . '/../lib/checkpoint_data.php';
//$stages = require __DIR__ . '/../lib/stage_map.php';
//$year = 2025;
$username = $_SESSION['username'] ?? '';
//$checkpoint = getCheckpointData($pdo, $username, $year);
/* require_once __DIR__ . '/../lib/_analysis_base.php'; */ // ⭐ 必須包含時間區間設定
/* require_once __DIR__ . '/_admin_gate.php'; */ //確認是否為管理員
// ⭐ 全站常數 define('IMG_BASE', '/assets/uploads/');

$pageTitleText = '自製卡面 Fan ART';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網'; //桌機
$activeMenu = "upload_card_art"; //.php

ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* =========================================================
   Checkpoint – Center Layout (BS3 Friendly)
  ========================================================= */

  .checkpoint-slider {
    width: 100%;
    display: flex;
    justify-content: center;
    overflow: hidden;
    position: relative;
  }

  .checkpoint-track {
    display: flex;
    width: 100%;
  }

  .checkpoint-page {
    flex: 0 0 100%;
    max-width: 100%;
    padding: 10px 0;
    display: flex;
    justify-content: center;
  }

  .checkpoint-page h1,
  .checkpoint-page h2,
  .checkpoint-page p {
    text-align: center;
  }

  /* =========================================================
   Arrow Navigation
  ========================================================= */

  .cp-arrow {
    position: absolute;
    top: 50%;
    transform: translateY(-50%);
    z-index: 20;

    width: 44px;
    height: 44px;
    border-radius: 50%;

    background: rgba(15, 23, 42, 0.75);
    border: 1px solid rgba(255, 255, 255, .15);
    color: #fff;

    font-size: 28px;
    line-height: 42px;
    text-align: center;

    cursor: pointer;
    transition: background .2s ease, border-color .2s ease, opacity .2s ease;
  }

  .cp-arrow:hover {
    background: rgba(34, 197, 94, 0.85);
    border-color: rgba(34, 197, 94, 0.9);
  }

  .cp-arrow-left {
    left: 12px;
  }

  .cp-arrow-right {
    right: 12px;
  }

  .cp-arrow.disabled {
    opacity: 0.25;
    pointer-events: none;
  }

  @media (max-width: 768px) {
    .cp-arrow {
      display: none !important;
    }
  }

  /* =========================================================
   Card Container
  ========================================================= */

  .cp-card {
    width: 100%;
    max-width: 900px;
    min-height: 83vh;

    display: flex;
    flex-direction: column;
    justify-content: flex-start;

    padding: 32px;

    background:
      radial-gradient(1200px 600px at top center, rgba(34, 197, 94, .06), transparent 60%),
      linear-gradient(180deg, rgba(15, 23, 42, .96), rgba(15, 23, 42, .92));

    border-radius: 16px;
    border: 1px solid rgba(255, 255, 255, .08);

    box-shadow:
      0 20px 60px rgba(0, 0, 0, .55),
      inset 0 1px 0 rgba(255, 255, 255, .04);

    position: relative;
    overflow: hidden;
  }

  .cp-card::before {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: 16px;
    pointer-events: none;
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, .05);
  }

  .cp-card::after {
    content: "UL.GG • UNLIGHT FAN ART";
    position: absolute;
    bottom: 12px;
    right: 16px;
    font-size: 11px;
    color: rgba(255, 255, 255, .35);
  }

  /* =========================================================
   Global Layout Helpers
========================================================= */

  .content-header {
    display: none;
  }

  .un-main {
    margin-left: 240px;
    padding: 0 24px;
  }

  @media (max-width: 992px) {
    .un-main {
      margin-left: 0;
      padding: 0 16px;
    }
  }

  .checkpoint-username {
    font-size: 17px;
    font-weight: 600;
    color: #b5b5b5;
    letter-spacing: .04em;
    margin-top: 4px;
  }

  /* =========================================================
   Card Slot (Upload Ready)
========================================================= */




  .card-slot .slot-hint {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;

    font-weight: 600;
    letter-spacing: .04em;
    color: rgba(255, 255, 255, .75);
    text-shadow: 0 2px 8px rgba(0, 0, 0, .65);
  }

  .card-slot:hover .slot-hint {
    color: #22c55e;
  }

  /* 左上角等級 */
  .card-level {
    position: absolute;
    top: 7px;
    left: 7px;
    z-index: 9;
    /* ⭐ 一定要 > 6 */

    width: 32px;
    height: 32px;
    border-radius: 50%;

    background: rgba(15, 23, 42, .85);
    border: 1px solid rgba(255, 255, 255, .25);

    font-size: 13px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
  }


  /* 上方角色名稱條 */
  /* 上方角色名稱條（背景滿版） */
  .card-name {
    position: absolute;
    top: 0;
    left: -17px;
    right: 0;
    padding: 6px 6px;

    font-size: 12px;
    font-weight: 600;
    letter-spacing: .06em;

    background: linear-gradient(180deg,
        rgba(0, 0, 0, 0.95) 0%,
        rgba(0, 0, 0, 0.82) 45%,
        rgba(0, 0, 0, 0.45) 70%,
        rgba(0, 0, 0, 0.0) 100%);

    pointer-events: none;
    /* 不影響翻卡 */
  }

  /* 文字本體：扣掉 LV Tag 後置中 */
  .card-name-text {
    display: block;

    margin-left: 40px;
    /* LV 32px + 間距 */
    margin-right: 10px;

    text-align: center;

    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    text-shadow:
      0 1px 2px rgba(0, 0, 0, 0.95),
      0 2px 6px rgba(0, 0, 0, 0.85);
  }



  @media (max-width: 768px) {
    .card-slot {
      aspect-ratio: 0.7;
    }

    .col-xs-6 {
      margin-bottom: 14px;
    }

    .card-level {
      top: 8px;
      left: 3px;
      width: 32px;
      height: 32px;
    }

    .card-name {

      padding: 6px 6px;
    }
  }

  /* =========================================
   Card Binder – Toolbar
========================================= */

  .card-binder-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    justify-content: center;
    margin-bottom: 24px;
  }

  .card-search {
    width: 260px;
    max-width: 90%;
    padding: 8px 12px;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, .2);
    background: rgba(15, 23, 42, .85);
    color: #e5e7eb;
    outline: none;
  }

  .card-search::placeholder {
    color: rgba(255, 255, 255, .45);
  }

  /* =========================================
   Character Bookmark Tabs – Responsive
========================================= */

  .char-bookmarks {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: 8px 10px;

    padding: 6px 8px;
  }

  /* Bookmark button */
  .char-bookmark {
    padding: 6px 14px;
    border-radius: 999px;

    font-size: 13px;
    font-weight: 600;
    letter-spacing: .04em;

    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .15);
    color: #e5e7eb;

    cursor: pointer;
    white-space: nowrap;

    text-align: center;

    transition: background .15s ease, border-color .15s ease;
  }

  .char-bookmark.active {
    background: rgba(34, 197, 94, .85);
    border-color: rgba(34, 197, 94, .95);
    color: #052e16;
  }

  /* ===============================
   📱 Mobile Optimization
================================ */
  @media (max-width: 768px) {
    .char-bookmarks {
      display: flex;
      /* 🔥 改用 flex */
      flex-wrap: wrap;
      /* 🔥 自動換行 */
      justify-content: center;

      gap: 8px;
      padding: 4px 6px;
    }

    .char-bookmark {
      font-size: 12px;
      padding: 5px 12px;
    }
  }

  /* ===============================
   📱 Extra Small Devices
================================ */
  @media (max-width: 420px) {
    .char-bookmark {
      font-size: 11px;
      padding: 4px 10px;
    }
  }


  /* =========================================
   Card Binder Grid
========================================= */

  .card-binder {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 14px;
    margin-top: 16px;
  }

  .card-binder-row {
    margin-bottom: 18px;
  }

  .card-binder-label {
    text-align: center;
    font-size: 13px;
    letter-spacing: .12em;
    color: rgba(255, 255, 255, .5);
    margin-bottom: 6px;
  }

  /* Mobile */
  @media (max-width: 768px) {
    .card-binder {
      grid-template-columns: repeat(2, 1fr);
    }
  }

  /* Upload Card Art 不使用進場動畫 */
  .cp-animate {
    opacity: 1 !important;
    transform: none !important;
    transition: none !important;
  }

  /* =========================
   Flip Card Core
========================= */

  .card-slot {
    perspective: 1200px;
  }

  .card-inner {
    position: relative;
    width: 100%;
    height: 100%;
    transform-style: preserve-3d;
    transition: transform .6s cubic-bezier(.4, .2, .2, 1);
  }

  .card-slot.is-flipped .card-inner {
    transform: rotateY(180deg);
  }

  .card-face {
    position: absolute;
    inset: 0;
    backface-visibility: hidden;
    border-radius: 14px;
    overflow: hidden;
  }

  .card-front {
    z-index: 2;
  }

  .card-back {
    transform: rotateY(180deg);
    background: #0f172a;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .card-back img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  .card-back-placeholder {
    color: rgba(255, 255, 255, .6);
    font-size: 14px;
    letter-spacing: .1em;
  }

  /* =========================
   Back To Index Button
========================= */

  .back-to-index {
    position: absolute;
    top: 16px;
    left: 16px;
    z-index: 30;

    padding: 6px 14px;
    border-radius: 999px;

    font-size: 13px;
    font-weight: 600;
    letter-spacing: .06em;

    background: rgba(15, 23, 42, 0.75);
    border: 1px solid rgba(255, 255, 255, .2);
    color: #e5e7eb;

    cursor: pointer;
    user-select: none;

    transition:
      background .2s ease,
      border-color .2s ease,
      color .2s ease,
      transform .15s ease;
  }

  .back-to-index:hover {
    background: rgba(34, 197, 94, .85);
    border-color: rgba(34, 197, 94, .95);
    color: #052e16;
    transform: translateX(-2px);
  }

  .back-to-index:active {
    transform: translateX(-4px);
  }

  /* Mobile */
  @media (max-width: 768px) {
    .back-to-index {
      font-size: 12px;
      padding: 5px 12px;
    }
  }

  .admin-card-tools {
    position: absolute;
    top: 6px;
    right: 6px;
    z-index: 10;
    display: flex;
    gap: 4px;
  }


  .admin-file {
    display: none !important;
  }

  .char-bookmarks.is-empty::after {
    content: "找不到符合的角色";
    color: rgba(255, 255, 255, .5);
    font-size: 13px;
    padding: 12px;
  }

  .admin-check-btn {
    padding: 8px 16px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: .05em;

    background: rgba(239, 68, 68, .85);
    /* red-500 */
    border: 1px solid rgba(239, 68, 68, 1);
    color: #fff;

    cursor: pointer;
    transition: background .15s ease, transform .1s ease;
  }

  .admin-check-btn:hover {
    background: rgba(239, 68, 68, 1);
    transform: translateY(-1px);
  }

  /* 🔍 檢查缺少背面 */
  .missing-panel {
    margin-top: 14px;
    padding: 12px;
    border-radius: 12px;
    background: rgba(239, 68, 68, .08);
    border: 1px solid rgba(239, 68, 68, .35);
  }

  .missing-title {
    font-size: 13px;
    color: #fecaca;
    margin-bottom: 8px;
  }

  .missing-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .missing-item {
    padding: 6px 14px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;

    background: rgba(239, 68, 68, .85);
    color: #fff;
  }

  /* =========================================================
   Empty Card Slot – Default (V1 全未上傳)
========================================================= */

  .card-slot {
    position: relative;
    overflow: hidden;
    width: 100%;
    aspect-ratio: 0.7;
    /* border-radius: 14px; */
    overflow: hidden;

    background: linear-gradient(180deg,
        rgba(255, 255, 255, .06),
        rgba(255, 255, 255, .02));

    border: 1px dashed rgba(255, 255, 255, .18);
    cursor: pointer;
    transition: border-color .25s ease, box-shadow .25s ease;
    height: auto;
    overflow: visible;
    /* ⭐ 讓下方工具列顯示 */
  }

  .card-slot .edge-left,
  .card-slot .edge-right {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 5px;
    z-index: 6;
    pointer-events: none;
  }

  .card-slot .edge-left {
    left: 0;
  }

  .card-slot .edge-right {
    right: 0;
  }




  /* Hover 外框 */
  .card-slot:hover {
    border-color: rgba(34, 197, 94, .9);
    box-shadow: 0 0 0 2px rgba(34, 197, 94, .35);
  }

  /* 圖片：預設未上傳 → 灰化 */
  .card-slot img {
    width: 100%;
    height: 100%;
    object-fit: cover;

    filter: grayscale(100%) contrast(0.85);
    opacity: 0.35;

    transition: opacity .25s ease, filter .25s ease;
  }

  /* Hover：圖片醒來 */
  .card-slot:hover img {
    opacity: 0.6;
    filter: grayscale(40%) contrast(1);
  }



  /* Hover：遮罩加強 */
  /* .card-slot:hover::after {
    opacity: 1;
    background:
      radial-gradient(circle at center,
        rgba(34, 197, 94, .25),
        rgba(15, 23, 42, .55));
  } */


  @keyframes pulseGlow {
    0% {
      box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
    }

    50% {
      box-shadow: 0 0 0 2px rgba(34, 197, 94, .15);
    }

    100% {
      box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
    }
  }

  /* =========================================
   Future-safe：已上傳卡面（先留）
========================================= */

  .card-slot.has-art img {
    filter: none;
    opacity: 1;
  }


  /* =========================
   Center Upload Front Button
========================= */

  .upload-front-btn {
    position: absolute;
    inset: 0;
    z-index: 8;

    display: flex;
    align-items: center;
    justify-content: center;

    pointer-events: none;
    /* 預設不吃事件 */
  }

  .upload-front-btn button {
    pointer-events: auto;
    /* 只讓按鈕能點 */

    padding: 2px 9px;
    border-radius: 999px;

    font-size: 14px;
    font-weight: 700;
    letter-spacing: .08em;

    background: rgb(0 255 93 / 90%);
    border: 1px solid rgba(34, 197, 94, 1);
    color: #052e16;

    box-shadow:
      0 4px 14px rgba(34, 197, 94, .45),
      inset 0 1px 0 rgba(255, 255, 255, .25);

    cursor: pointer;
    transition: transform .15s ease, box-shadow .15s ease, background .15s ease;
  }

  .upload-front-btn button:hover {
    background: rgba(34, 197, 94, 1);
    box-shadow:
      0 6px 18px rgba(34, 197, 94, .6),
      inset 0 1px 0 rgba(255, 255, 255, .35);
    transform: translateY(-1px);
  }

  /* Hover 時弱化原本 + 上傳卡面文字 */
  /* .card-slot:hover::after {
    opacity: 0.25;
  } */

  /* =========================
   Upload Back (Spell) Button
========================= */

  .upload-back-btn {
    position: absolute;
    bottom: 8px;
    right: 8px;
    z-index: 9;

    pointer-events: none;
  }

  .upload-back-btn button {
    pointer-events: auto;

    padding: 2px 6px;
    border-radius: 999px;

    font-size: 11px;
    font-weight: 700;
    letter-spacing: .08em;

    background: rgba(59, 130, 246, .85);
    /* blue-500 */
    border: 1px solid rgba(59, 130, 246, 1);
    color: #e0f2fe;

    cursor: pointer;
    transition: background .15s ease, transform .1s ease;
  }

  .upload-back-btn button:hover {
    background: rgba(59, 130, 246, 1);
    transform: translateY(-1px);
  }

  .spell-mask {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .6);
    z-index: 200;
  }

  .spell-box {
    position: fixed;
    inset: 0;
    margin: auto;
    width: 90%;
    max-width: 420px;
    height: 280px;

    background: #0f172a;
    border-radius: 14px;
    padding: 16px;
    z-index: 201;
  }

  .spell-box textarea {
    width: 100%;
    height: 140px;
    resize: none;
    background: #020617;
    color: #e5e7eb;
    border: 1px solid rgba(255, 255, 255, .15);
    border-radius: 8px;
    padding: 8px;
  }

  .spell-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 12px;
  }

  #spellCancel,
  #spellSave {
    padding: 6px 16px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 700;
    letter-spacing: .05em;
    cursor: pointer;
    border: 1px solid transparent;
    transition: all .15s ease;
  }

  /* 取消：低調、安全 */
  #spellCancel {
    background: rgba(255, 255, 255, .08);
    border-color: rgba(255, 255, 255, .2);
    color: #e5e7eb;
  }

  #spellCancel:hover {
    background: rgba(255, 255, 255, .15);
  }

  /* 儲存：主行動 */
  #spellSave {
    background: rgba(34, 197, 94, .9);
    border-color: rgba(34, 197, 94, 1);
    color: #052e16;
    box-shadow: 0 4px 14px rgba(34, 197, 94, .45);
  }

  #spellSave:hover {
    background: rgba(34, 197, 94, 1);
    box-shadow: 0 6px 18px rgba(34, 197, 94, .6);
    transform: translateY(-1px);
  }



  /* =========================
   Card Level Borders – L
========================= */

  .card-slot.level-L1 {
    box-shadow:
      0 0 0 2px rgba(148, 163, 184, .6),
      /* gray */
      0 0 12px rgba(148, 163, 184, .25);
  }

  .card-slot.level-L2 {
    box-shadow:
      0 0 0 2px rgba(34, 197, 94, .65),
      /* green */
      0 0 14px rgba(34, 197, 94, .35);
  }

  .card-slot.level-L3 {
    box-shadow:
      0 0 0 2px rgba(59, 130, 246, .7),
      /* blue */
      0 0 16px rgba(59, 130, 246, .4);
  }

  .card-slot.level-L4 {
    box-shadow:
      0 0 0 2px rgba(239, 68, 68, .7),
      /* red */
      0 0 18px rgba(239, 68, 68, .45);
  }

  .card-slot.level-L5 {
    box-shadow:
      0 0 0 2px rgba(234, 179, 8, .85),
      /* gold */
      0 0 22px rgba(234, 179, 8, .6);
  }

  /* =========================
   Card Level Borders – R
  ========================= */

  .card-slot.level-R1 {
    box-shadow:
      0 0 0 2px rgba(203, 213, 225, .75),
      0 0 16px rgba(203, 213, 225, .5);
  }

  .card-slot.level-R2 {
    box-shadow:
      0 0 0 2px rgba(74, 222, 128, .8),
      0 0 20px rgba(74, 222, 128, .55);
  }

  .card-slot.level-R3 {
    box-shadow:
      0 0 0 2px rgba(96, 165, 250, .85),
      0 0 22px rgba(96, 165, 250, .6);
  }

  .card-slot.level-R4 {
    box-shadow:
      0 0 0 2px rgba(248, 113, 113, .85),
      0 0 26px rgba(248, 113, 113, .65);
  }

  .card-slot.level-R5 {
    box-shadow:
      0 0 0 2px rgba(250, 204, 21, .95),
      0 0 32px rgba(250, 204, 21, .85);
  }



  /* =========================
   Card Edge Bars（唯一用途）
========================= */

  /* 左邊色條 */
  .card-slot::before {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    left: 0;

    width: 10px;
    z-index: 6;
    pointer-events: none;

    background: var(--edge-color);
    box-shadow: inset -1px 0 0 rgba(0, 0, 0, .35);
  }

  /* 右邊色條 */
  .card-slot::after {
    content: "";
    position: absolute;
    top: 0;
    bottom: 0;
    right: 0;

    width: 10px;
    z-index: 6;
    pointer-events: none;

    background: var(--edge-color);
    box-shadow: inset 1px 0 0 rgba(0, 0, 0, .35);
  }

  .card-slot .pulse-layer {
    position: absolute;
    inset: 0;
    border-radius: 14px;
    pointer-events: none;
    z-index: 4;

    box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
    animation: pulseGlow 3s ease-in-out infinite;
  }

  .card-slot.level-L1::before,
  .card-slot.level-L1::after {
    background: rgba(148, 163, 184, .9);
  }

  .card-slot.level-L2::before,
  .card-slot.level-L2::after {
    background: rgba(34, 197, 94, .95);
  }

  .card-slot.level-L3::before,
  .card-slot.level-L3::after {
    background: rgba(59, 130, 246, .95);
  }

  .card-slot.level-L4::before,
  .card-slot.level-L4::after {
    background: rgba(239, 68, 68, .95);
  }

  .card-slot.level-L5::before,
  .card-slot.level-L5::after {
    background: rgba(234, 179, 8, .95);
  }

  .card-slot.level-R1::before,
  .card-slot.level-R1::after {
    background: rgba(203, 213, 225, 1);
  }

  .card-slot.level-R2::before,
  .card-slot.level-R2::after {
    background: rgba(74, 222, 128, 1);
  }

  .card-slot.level-R3::before,
  .card-slot.level-R3::after {
    background: rgba(96, 165, 250, 1);
  }

  .card-slot.level-R4::before,
  .card-slot.level-R4::after {
    background: rgba(248, 113, 113, 1);
  }

  .card-slot.level-R5::before,
  .card-slot.level-R5::after {
    background: rgba(250, 204, 21, 1);
  }


  .card-slot:hover {
    filter: brightness(1.05);
  }



  @keyframes shine {
    from {
      transform: translateX(-100%);
    }

    to {
      transform: translateX(100%);
    }
  }

  .upload-back-btn.tool-row {
    display: flex;
    gap: 6px;
  }

  .upload-back-btn.tool-row button {
    font-size: 11px;
    padding: 2px 8px;
  }


  .upload-mask {
    position: absolute;
    inset: 0;
    z-index: 7;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 14px;
    font-weight: 700;
    letter-spacing: .12em;

    color: rgba(255, 255, 255, .85);
    background:
      radial-gradient(circle at center,
        rgba(34, 197, 94, .12),
        rgba(15, 23, 42, .75));

    pointer-events: none;
    transition: opacity .25s ease;
  }

  /* 🔑 有圖 → 完全不顯示 */
  .card-slot.has-art .upload-mask {
    opacity: 0;
  }

  /* =========================
   未上傳卡：整體降亮度
========================= */
  .card-slot:not(.has-art) {
    filter: brightness(0.75) saturate(0.85);
  }

  .card-slot.has-art {
    filter: none;
  }

  /* 卡片下方工具列 */
  .card-tools {
    display: flex;
    justify-content: center;
    gap: 8px;

    margin-top: 6px;

    pointer-events: auto;
  }

  /* 確保不吃 hover 灰階 */
  .card-slot .card-tools {
    filter: none !important;
  }

  /* 工具列按鈕微型化 */
  .card-tools .admin-btn {
    font-size: 11px;
    padding: 2px 10px;
    border-radius: 999px;
  }

  /* 卡片下方工具列：恢復原本色調 */
  .card-tools .admin-btn[data-side="front"] {
    background: rgba(34, 197, 94, .9);
    /* 綠色 */
    border: 1px solid rgba(34, 197, 94, 1);
    color: #052e16;
  }

  .card-tools .admin-btn.spell-btn {
    background: rgba(59, 130, 246, .9);
    /* 藍色 */
    border: 1px solid rgba(59, 130, 246, 1);
    color: #e0f2fe;
  }

  .card-tools .admin-btn:hover {
    filter: brightness(1.1);
    transform: translateY(-1px);
  }
</style>


<?php
$sql = "SELECT
  u.id,
  u.name,
  u.level,
  u.ico,

  /* 是否為「目前使用者」上傳過正面 */
  CASE
    WHEN cau.id IS NOT NULL THEN 1
    ELSE 0
  END AS has_fanart_front,

  cau.image_path AS fanart_image,
  cau.spell_text

FROM unlight u

LEFT JOIN card_art_uploads cau
  ON cau.card_id = u.id
 AND cau.side = 'front'
 AND cau.content_type = 'image'
 AND cau.status IN ('pending','approved')
 AND cau.user_id = :uid   -- ⭐ 關鍵：限定目前玩家

WHERE u.level REGEXP '^[LR][1-5]$'
ORDER BY u.id,
         FIELD(u.level,'L1','L2','L3','L4','L5','R1','R2','R3','R4','R5');


";
$stmt = $pdo->prepare($sql);
$stmt->execute([
  ':uid' => $_SESSION['user_id'] ?? 0
]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* 整理成：角色 → 等級 */
$characters = [];
foreach ($rows as $row) {
  $charName = $row['name'];
  $level    = $row['level'];

  if (!isset($characters[$charName])) {
    $characters[$charName] = [];
  }
  $characters[$charName][$level] = $row;
}

$charNames = array_keys($characters);
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <div class="col-md-12">
          <div class="checkpoint-slider">
            <div class="checkpoint-track">
              <!-- =========================
                  PAGE 0｜說明頁（取代原封面）
              ========================== -->
              <section class="checkpoint-page active" id="cp-page-0">
                <div class="cp-card text-center">

                  <h1 class="mb-3">
                    自製卡面上傳<br>
                    <small class="text-muted">(投票功能製作中，現階段可先行上傳作品)</small>
                  </h1>

                  <p class="checkpoint-username">
                    共創屬於你的 UNLIGHT 世界線
                  </p>

                  <div class="cp-animate" style="max-width:620px;margin:0 auto;">

                    <p class="text-muted">
                      此區為玩家創作之卡面展示平台，<br>
                      提供社群分享、交流與未來票選使用。
                    </p>

                    <ul class="text-muted small" style="list-style:none;padding:0;line-height:1.8;">
                      <li>✔ 需登入帳號方可上傳作品</li>
                      <li>✔ 每位使用者，每張卡面（正面）僅能上傳一件作品，可隨時重新上傳覆蓋</li>
                      <li>✔ 投票系統上線後，作品將開放社群投票（每帳號每作品一票）</li>
                      <li>✔ 投票數達指定門檻（如 ♥ ≥ 10）之作品，將有機會顯示於主卡槽或推薦區</li>
                      <li>✔ 投票數過低或長期未獲共鳴之作品，系統可能定期封存或降低曝光</li>
                      <li>✔ 管理員保留隱藏、下架不適當內容之權利（含違規、侵權、惡意內容）</li>
                    </ul>

                    <hr style="border-color:rgba(255,255,255,.15);margin:14px 0;">

                    <p class="text-muted small" style="line-height:1.7;">
                      <strong>📌 版權與使用授權說明</strong><br>
                      上傳之圖像作品，其著作權仍歸原創作者所有。<br>
                      使用者於上傳作品時，即視為同意授權本網站
                      <strong>在非商業用途下</strong>，於 UNLIGHT 相關頁面中進行展示、投票、排名、
                      宣傳截圖與功能呈現使用。<br>
                      本站不會主張作品所有權，亦不會將作品用於任何商業販售用途。
                    </p>

                    <p class="text-muted small">
                      若您不同意上述規則與授權條款，請勿上傳作品。
                    </p>

                  </div>

                </div>

              </section>
              <!-- =========================
                  PAGE 1｜角色索引
              ========================== -->
              <section class="checkpoint-page" id="page-index">
                <div class="cp-card">

                  <div class="card-binder-toolbar">
                    <button class="admin-check-btn" id="checkMissingBack">
                      🔍 檢查尚未上傳
                    </button>

                    <input class="card-search" placeholder="搜尋角色名稱…" />

                    <div class="char-bookmarks">
                      <?php foreach ($charNames as $i => $name): ?>
                        <div class="char-bookmark" data-index="<?= $i ?>">
                          <?= htmlspecialchars($name) ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </div>
                  <!-- 管理員：缺少背面檢查結果 -->
                  <div id="missingBackPanel" class="missing-panel" style="display:none;">
                    <div class="missing-title">
                      尚未上傳的角色：
                      <span id="exitCheckMode"
                        style="cursor:pointer; margin-left:12px; color:#86efac;">
                        ⟲ 回到列表
                      </span>
                    </div>

                    <div class="missing-list"></div>
                  </div>


                </div>
              </section>



              <!-- =========================
                  PAGE 2-X｜角色卡冊
              ========================== -->
              <section class="checkpoint-page" id="page-character">
                <div class="cp-card">
                  <div class="back-to-index" onclick="backToIndex()">
                    ← 返回角色列表
                  </div>

                  <div class="text-muted small text-right">
                    圖片限制：JPG / PNG / WEBP，檔案大小 ≤ 1MB 建議尺寸: 168*240 以內
                  </div>
                  <h2 id="charTitle"></h2>
                  <div id="cardBinder"></div>

                </div>


              </section>



            </div>
            <!-- =========================
                Arrow Navigation
            ========================== -->
            <button class="cp-arrow cp-arrow-left" id="cpPrev" aria-label="Previous">
              ‹
            </button>
            <button class="cp-arrow cp-arrow-right" id="cpNext" aria-label="Next">
              ›
            </button>
          </div>

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

<!-- Model -->
<div id="spellEditor" style="display:none;">
  <div class="spell-mask"></div>
  <div class="spell-box">
    <h4>編輯咒語</h4>

    <textarea id="spellInput"
      placeholder="請輸入此卡的咒語內容…"></textarea>

    <div class="spell-actions">
      <button id="spellCancel">取消</button>
      <button id="spellSave">儲存</button>
    </div>
  </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
  const PAGE_INTRO = 0;
  const PAGE_INDEX = 1;
  const PAGE_CHARACTER = 2;
</script>

<script>
  function backToIndex() {
    currentCharIndex = -1;
    goToPage(PAGE_INDEX);
  }
</script>

<script>
  window.CHARACTERS = <?= json_encode($characters, JSON_UNESCAPED_UNICODE) ?>;
</script>

<script>
  const CHAR_LIST = Object.keys(window.CHARACTERS);
  let currentCharIndex = -1;
</script>

<script>
  function switchCharacter(next = true) {
    if (currentCharIndex === -1) return; // 還在索引頁不動

    currentCharIndex += next ? 1 : -1;
    currentCharIndex = Math.max(0, Math.min(currentCharIndex, CHAR_LIST.length - 1));

    renderCharacter(CHAR_LIST[currentCharIndex]);
  }
</script>

<script>
  (function() {
    const slider = document.querySelector('.checkpoint-slider');
    const track = document.querySelector('.checkpoint-track');
    const pages = document.querySelectorAll('.checkpoint-page');

    const btnPrev = document.getElementById('cpPrev');
    const btnNext = document.getElementById('cpNext');

    let currentIndex = 0;




    /* =========================
       Resize 修正
    ========================== */
    window.addEventListener('resize', () => {
      goToPage(currentIndex);
    });

    /* =========================
       手機左右滑動（保留）
    ========================== */
    let startX = 0;
    let endX = 0;
    let isSwiping = false;
    let swipeDisabled = false; // ⭐ 新增

    /* slider.addEventListener('touchstart', e => {
      startX = e.touches[0].clientX;
      isSwiping = true;
    }, {
      passive: true
    }); */

    slider.addEventListener('touchmove', e => {
      if (!isSwiping) return;
      endX = e.touches[0].clientX;
    }, {
      passive: true
    });


  })();
</script>
<script>
  const sliderTrack = document.querySelector('.checkpoint-track');
  const pages = document.querySelectorAll('.checkpoint-page');

  let currentPageIndex = 0;

  function goToPage(index) {
    currentPageIndex = index;
    sliderTrack.style.transform = `translateX(-${index * 100}%)`;

    // 索引頁隱藏箭頭
    document.getElementById('cpPrev').style.display = index === 0 ? 'none' : '';
    document.getElementById('cpNext').style.display = '';
  }
</script>



<script>
  const slider = document.querySelector('.checkpoint-slider');

  let startX = 0;
  let startY = 0;
  let isTouching = false;

  slider.addEventListener('touchstart', e => {
    const t = e.touches[0];
    startX = t.clientX;
    startY = t.clientY;
    isTouching = true;
  }, {
    passive: true
  });

  slider.addEventListener('touchend', e => {
    if (!isTouching) return;

    const t = e.changedTouches[0];
    const dx = t.clientX - startX;
    const dy = t.clientY - startY;

    isTouching = false;

    // 1️⃣ 垂直滑動 → 交給瀏覽器
    if (Math.abs(dy) > Math.abs(dx)) return;

    // 2️⃣ 位移太小 → 當點擊
    if (Math.abs(dx) < 60) return;

    // =========================
    // 🔁 PAGE 滑動（所有頁面）
    // =========================
    if (currentPageIndex !== PAGE_CHARACTER) {
      if (dx < 0) {
        // 👉 向左滑：往下一頁
        if (currentPageIndex < PAGE_CHARACTER) {
          goToPage(currentPageIndex + 1);
        }
      } else {
        // 👉 向右滑：往上一頁
        if (currentPageIndex > PAGE_INTRO) {
          goToPage(currentPageIndex - 1);
        }
      }
      return;
    }

    // =========================
    // 🎴 角色頁：切角色
    // =========================
    if (dx < 0) {
      switchCharacter(true);
    } else {
      switchCharacter(false);
    }
  }, {
    passive: true
  });
</script>


<script>
  document.addEventListener('click', e => {
    // 只允許點在卡面本體才翻
    const face = e.target.closest('.card-face');
    if (!face) return;

    // 卡面內的任何按鈕 → 不翻
    if (e.target.closest('.admin-btn')) return;
    if (e.target.closest('input[type="file"]')) return;

    const card = face.closest('.card-slot');
    if (!card) return;

    e.stopPropagation();
    card.classList.toggle('is-flipped');
  });
</script>

<script>
  function initLazyLoad() {
    const io = new IntersectionObserver(entries => {
      entries.forEach(e => {
        if (!e.isIntersecting) return;
        const img = e.target;

        if (img._lazyKilled) {
          io.unobserve(img);
          return;
        }

        if (!img.dataset.src) {
          io.unobserve(img);
          return;
        }

        img.src = img.dataset.src;
        io.unobserve(img);
      });
    });

    document.querySelectorAll('.lazy-img')
      .forEach(img => io.observe(img));
  }
</script>

<!-- buildCard（產生單張卡） -->
<script>
  function buildCard(card, charName, level) {

    // ❶ 沒有任何資料（理論上很少發生，但保留）
    if (!card) {
      return `
      <div class="card-slot level-${level}" data-level="${level}">
        <div class="pulse-layer"></div>
        <div class="card-level">${level}</div>
        <div class="card-inner">

          <div class="card-face card-front">
            <img src="<?= IMG_BASE ?>placeholder.png">

            <div class="card-name">
              <span class="card-name-text">${charName}</span>
            </div>

            <div class="upload-front-btn">
              <button class="admin-btn" data-side="front">＋ 上傳正面</button>
              <input type="file" class="admin-file" accept="image/*">
            </div>
          </div>

          <div class="card-face card-back">
            <div class="card-back-placeholder">尚未上傳</div>
          </div>

        </div>
      </div>
    `;
    }

    // ❷ 是否為「目前玩家」有上傳正面
    const hasFront = card.has_fanart_front == 1;

    // ❸ 圖片來源：玩家卡面 or 官方卡面
    const imgSrc = hasFront ?
      `<?= IMG_BASE ?>${card.fanart_image}` :
      `<?= IMG_BASE ?>${card.ico}`;

    return `
    <div class="card-item">
      <div class="card-slot level-${level} ${hasFront ? 'has-art' : ''}"
          data-id="${card.id}"
          data-level="${level}">
        <div class="pulse-layer"></div>
        <div class="card-level">${level}</div>

        <div class="card-inner">

          <!-- 正面 -->
          <div class="card-face card-front">
            <img data-src="${imgSrc}" class="lazy-img">

            <div class="card-name">
              <span class="card-name-text">${charName}</span>
            </div>

            <!-- 尚未上傳 → 顯示上傳提示 -->
            ${
              !hasFront
                ? `
                  <div class="upload-front-btn">
                    <div class="upload-mask"></div>
                    <button class="admin-btn" data-side="front">＋ 上傳卡面</button>
                  </div>
                `
                : ''
            }         

            <input type="file" class="admin-file" accept="image/*">
          </div>
          

          <!-- 背面（只顯示咒語，不處理圖） -->
          <div class="card-face card-back">
            ${
              card.spell_text
                ? `<div class="spell-text">${card.spell_text}</div>`
                : `<div class="card-back-placeholder">尚未填寫咒語</div>`
            }
          </div>

        </div>
        
      </div>
      <!-- 工具列（上傳圖片 / 咒語） -->
      ${hasFront ? `
  <div class="card-tools">
    <button class="admin-btn" data-side="front">＋圖</button>
    <button class="admin-btn spell-btn">＋咒語</button>
  </div>
` : ''}

    </div>
  `;
  }
</script>



<!-- renderCharacter（一次只生 10 張） -->
<script>
  function renderCharacter(charName) {
    const data = window.CHARACTERS[charName];
    if (!data) return;

    const binder = document.getElementById('cardBinder');
    const title = document.getElementById('charTitle');

    title.textContent = charName;
    binder.innerHTML = '';

    let html = '';

    ['L', 'R'].forEach(type => {
      html += `
        <div class="card-binder-row">
          <div class="card-binder-label">LEVEL ${type}</div>
          <div class="card-binder">
      `;

      for (let i = 1; i <= 5; i++) {
        const lvl = type + i;
        html += buildCard(data[lvl], charName, lvl);
      }

      html += `
          </div>
        </div>
      `;
    });

    binder.innerHTML = html;

    initLazyLoad();
  }
</script>


<!-- 書籤點擊切換角色 -->
<script>
  document.querySelectorAll('.char-bookmark').forEach((btn, i) => {
    btn.addEventListener('click', () => {

      currentCharIndex = i;
      renderCharacter(CHAR_LIST[i]);

      // 👉 切到角色頁（第二頁）
      goToPage(PAGE_CHARACTER); // ⭐ 必須明確指定
    });
  });

  // 初始只顯示索引頁，不 render 任何角色
  //let currentCharIndex = -1;
</script>

<script>
  const btnPrev = document.getElementById('cpPrev');
  const btnNext = document.getElementById('cpNext');

  btnNext.onclick = () => {
    // 說明頁 → 角色索引
    if (currentPageIndex === PAGE_INTRO) {
      goToPage(PAGE_INDEX);
      return;
    }

    // 索引頁 → 第一個角色
    if (currentPageIndex === PAGE_INDEX) {
      currentCharIndex = 0;
      renderCharacter(CHAR_LIST[0]);
      goToPage(PAGE_CHARACTER);
      return;
    }

    // 角色頁 → 下一角色
    if (currentPageIndex === PAGE_CHARACTER) {
      switchCharacter(true);
    }
  };


  btnPrev.onclick = () => {
    // 角色頁 + 第一隻 → 回索引
    if (currentPageIndex === PAGE_CHARACTER && currentCharIndex === 0) {
      currentCharIndex = -1;
      goToPage(PAGE_INDEX);
      return;
    }

    // 索引頁 → 回說明頁
    if (currentPageIndex === PAGE_INDEX) {
      goToPage(PAGE_INTRO);
      return;
    }

    // 角色頁 → 上一角色
    if (currentPageIndex === PAGE_CHARACTER) {
      switchCharacter(false);
    }
  };
</script>




<script>
  document.addEventListener('change', async e => {
    const input = e.target;
    if (!input.classList.contains('admin-file')) return;

    const file = input.files[0];
    if (!file) return;

    // =========================
    // ⭐ 檔案大小限制（10MB）
    // =========================
    const MAX_SIZE = 1 * 1024 * 1024; // 10MB
    if (file.size > MAX_SIZE) {
      alert('圖片大小不可超過 1MB');
      input.value = ''; // ⭐ 清掉，避免卡住
      return;
    }

    const card = input.closest('.card-slot');
    if (!card) return;

    const cardId = card.dataset.id;
    if (!cardId) {
      alert('此卡尚未建立資料');
      return;
    }

    const side = input.dataset.side; // front / back
    if (!side) {
      alert('未指定上傳面向');
      return;
    }

    const fd = new FormData();
    fd.append('card_id', cardId);
    fd.append('side', side);
    fd.append('image', file);
    fd.append('mode', 'fanart');

    try {
      const res = await fetch('/api/upload_card_image.php', {
        method: 'POST',
        body: fd
      });

      const json = await res.json();
      if (!json.ok) {
        alert(json.error || '上傳失敗');
        return;
      }

      // ⭐ 同步前端資料
      const charName = document.getElementById('charTitle')?.textContent;
      const level = card.dataset.level;

      if (charName && window.CHARACTERS?.[charName]?.[level]) {
        window.CHARACTERS[charName][level].has_fanart_front = 1;
        window.CHARACTERS[charName][level].fanart_image =
          json.url + '?t=' + Date.now();
      }

      renderCharacter(charName);

    } catch (err) {
      console.error(err);
      alert('上傳錯誤');
    } finally {
      input.value = '';
    }
  });
</script>

<script>
  (function() {
    const searchInput = document.querySelector('.card-search');
    const container = document.querySelector('.char-bookmarks');
    const bookmarks = Array.from(document.querySelectorAll('.char-bookmark'));

    if (!searchInput || !container || bookmarks.length === 0) return;

    function updateFilter() {
      const keyword = searchInput.value.trim().toLowerCase();
      let anyVisible = false;

      bookmarks.forEach(btn => {
        const name = btn.textContent.toLowerCase();
        const visible = name.includes(keyword);
        btn.style.display = visible ? '' : 'none';
        if (visible) anyVisible = true;
      });

      container.classList.toggle('is-empty', !anyVisible);
    }

    searchInput.addEventListener('input', updateFilter);

    // ESC 快速清空
    searchInput.addEventListener('keydown', e => {
      if (e.key === 'Escape') {
        searchInput.value = '';
        updateFilter();
        searchInput.blur();
      }
    });
  })();
</script>

<script>
  document.getElementById('checkMissingBack')?.addEventListener('click', () => {
    const list = document.querySelector('.missing-list');
    const panel = document.getElementById('missingBackPanel');

    const searchInput = document.querySelector('.card-search');
    const bookmarks = document.querySelector('.char-bookmarks');

    list.innerHTML = '';
    panel.style.display = 'none';

    // ⭐ 進入檢查模式：隱藏搜尋與原書籤
    if (searchInput) searchInput.style.display = 'none';
    if (bookmarks) bookmarks.style.display = 'none';

    const missingChars = new Set();

    for (const [charName, levels] of Object.entries(window.CHARACTERS)) {
      for (const card of Object.values(levels)) {
        if (!card) continue;
        if (card.ico && !card.ico_back) {
          missingChars.add(charName);
          break; // ⭐ 只要這個角色有一張缺，就收
        }
      }
    }

    if (missingChars.size === 0) {
      alert('✅ 所有角色背面皆齊全');

      // 還原 UI
      if (searchInput) searchInput.style.display = '';
      if (bookmarks) bookmarks.style.display = '';
      return;
    }

    panel.style.display = '';

    [...missingChars].forEach(charName => {
      const btn = document.createElement('div');
      btn.className = 'missing-item';
      btn.textContent = charName;

      btn.onclick = () => {
        const idx = CHAR_LIST.indexOf(charName);
        if (idx !== -1) {
          currentCharIndex = idx;
          renderCharacter(charName);
          goToPage(PAGE_CHARACTER);
        }
      };

      list.appendChild(btn);
    });
  });
</script>

<script>
  document.getElementById('exitCheckMode')?.addEventListener('click', () => {
    const panel = document.getElementById('missingBackPanel');
    const searchInput = document.querySelector('.card-search');
    const bookmarks = document.querySelector('.char-bookmarks');

    panel.style.display = 'none';
    if (searchInput) searchInput.style.display = '';
    if (bookmarks) bookmarks.style.display = '';
  });
</script>

<!-- 上傳咒語 -->
<script>
  let currentSpellCardId = null;

  document.addEventListener('click', e => {
    const btn = e.target.closest('.spell-btn');
    if (!btn) return;

    e.stopPropagation();

    const item = btn.closest('.card-item');
    if (!item) return;

    const card = item.querySelector('.card-slot');
    if (!card) return;

    const cardId = card.dataset.id;
    if (!cardId) return;

    const charName = document.getElementById('charTitle').textContent;
    const level = card.dataset.level;
    const cardData = window.CHARACTERS?.[charName]?.[level];

    currentSpellCardId = cardId;

    // 若尚未上傳正面 → 先觸發上傳
    if (!cardData?.has_fanart_front) {
      const fileInput = card.querySelector('.admin-file');
      if (fileInput) {
        fileInput.dataset.side = 'front';
        fileInput.click();
      }
    }

    document.getElementById('spellInput').value = cardData?.spell_text || '';
    document.getElementById('spellEditor').style.display = 'block';
  });
</script>


<!-- 咒語取消 / 儲存 -->
<script>
  document.getElementById('spellCancel').onclick = () => {
    document.getElementById('spellEditor').style.display = 'none';
  };

  document.getElementById('spellSave').onclick = async () => {
    const text = document.getElementById('spellInput').value.trim();
    if (!currentSpellCardId) return;

    const fd = new FormData();
    fd.append('card_id', currentSpellCardId);
    fd.append('spell_text', text);

    const res = await fetch('/api/save_spell_text.php', {
      method: 'POST',
      body: fd
    });
    const json = await res.json();

    if (!json.ok) {
      alert(json.error || '儲存失敗');
      return;
    }

    // 同步前端資料
    const charName = document.getElementById('charTitle').textContent;
    const level = document.querySelector(
      `.card-slot[data-id="${currentSpellCardId}"]`
    ).dataset.level;

    window.CHARACTERS[charName][level].spell_text = text;

    document.getElementById('spellEditor').style.display = 'none';
    renderCharacter(charName);
  };
</script>

<script>
  document.addEventListener('click', e => {
    const btn = e.target.closest('.admin-btn');
    if (!btn) return;

    const side = btn.dataset.side;
    if (!side) return;

    const item = btn.closest('.card-item');
    if (!item) return;

    const card = item.querySelector('.card-slot');
    if (!card) return;

    const fileInput = card.querySelector('.admin-file');
    if (!fileInput) return;

    e.stopPropagation();

    fileInput.dataset.side = side;
    fileInput.click();
  });
</script>
<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
$hidePageHeader = true;
include __DIR__ . '/../layout/base.php';
?>