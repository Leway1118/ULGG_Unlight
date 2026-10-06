<?php
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
                                            // ⚠️ 不要 session_start()，交給 config.php
    require_once __DIR__ . '/../../config.php'; // ⭐ 必須包含資料庫設定
    $pdo = $db;                                 // ⭐ 一定要這行

    // ⭐ 全站常數 define('IMG_BASE', '/assets/uploads/');

    $pageTitleText = 'ULGG 杯';
    $seoTitle      = 'Unlight：Revive非官方 ULGG 杯 | UL.GG 戰績網 UNLIGHT 戰術研究中心';
    $pageTitleFull = 'Unlight：Revive非官方 ULGG 杯 | UL.GG 戰績網';
    $activeMenu    = "ulgg_cup";

    ob_start(); // ⭐ 開始收集本頁 HTML
?>

<style>
  /* ===============================
     ULGG Cup Page
  =============================== */

  .ul-cup-page {
    padding: 18px 14px 28px;
    color: #e8edf2;
  }

  .ul-cup-hero {
    position: relative;
    overflow: hidden;
    border-radius: 18px;
    padding: 24px 24px;
    margin-bottom: 18px;
    background:
      radial-gradient(circle at 20% 0%, rgba(177, 52, 70, .28), transparent 32%),
      radial-gradient(circle at 90% 20%, rgba(80, 116, 170, .22), transparent 34%),
      linear-gradient(135deg, #171b22 0%, #232832 48%, #15181f 100%);
    border: 1px solid rgba(255, 255, 255, .08);
    box-shadow: 0 12px 32px rgba(0, 0, 0, .28);
  }

  .ul-cup-hero::before {
    content: "";
    position: absolute;
    inset: 0;
    background-image:
      linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px);
    background-size: 22px 22px;
    opacity: .25;
    pointer-events: none;
  }

  .ul-cup-hero-inner {
    position: relative;
    z-index: 1;
  }

  .ul-cup-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 10px;
    border-radius: 999px;
    background: rgba(255, 215, 120, .12);
    border: 1px solid rgba(255, 215, 120, .25);
    color: #ffd98a;
    font-size: 13px;
    margin-bottom: 10px;
  }

  .ul-cup-title {
    margin: 0;
    font-size: 30px;
    font-weight: 800;
    letter-spacing: .04em;
    color: #fff;
  }

  .ul-cup-subtitle {
    margin-top: 8px;
    color: #bfc7d2;
    font-size: 15px;
    line-height: 1.7;
  }

  .ul-cup-links {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 16px;
  }

  .ul-cup-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: 7px 13px;
    border-radius: 10px;
    color: #eaf1f8;
    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .12);
    text-decoration: none;
    transition: .15s ease;
  }

  .ul-cup-btn:hover,
  .ul-cup-btn:focus {
    color: #fff;
    background: rgba(255, 255, 255, .14);
    text-decoration: none;
    transform: translateY(-1px);
  }

  .ul-cup-btn-primary {
    background: rgba(199, 64, 80, .26);
    border-color: rgba(255, 110, 128, .35);
  }

  .ul-cup-info-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 12px;
    margin-bottom: 18px;
  }

  .ul-cup-info-card {
    border-radius: 16px;
    padding: 15px 16px;
    background: linear-gradient(180deg, rgba(33, 38, 48, .98), rgba(24, 28, 36, .98));
    border: 1px solid rgba(255, 255, 255, .08);
    box-shadow: 0 8px 18px rgba(0, 0, 0, .18);
  }

  .ul-cup-info-label {
    color: #99a4b3;
    font-size: 12px;
    margin-bottom: 6px;
  }

  .ul-cup-info-value {
    color: #fff;
    font-size: 18px;
    font-weight: 800;
    line-height: 1.35;
  }

  .ul-cup-info-note {
    color: #b9c2cf;
    font-size: 12px;
    margin-top: 4px;
  }

  .ul-cup-section {
    border-radius: 18px;
    background: rgba(23, 27, 35, .96);
    border: 1px solid rgba(255, 255, 255, .08);
    padding: 18px;
    margin-bottom: 18px;
    box-shadow: 0 10px 26px rgba(0, 0, 0, .22);
  }

  .ul-cup-section-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
  }

  .ul-cup-section-title {
    margin: 0;
    font-size: 20px;
    color: #fff;
    font-weight: 800;
  }

  .ul-cup-section-desc {
    color: #9faabb;
    font-size: 13px;
    margin-top: 5px;
  }

  .ul-cup-status-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 999px;
    font-size: 12px;
    white-space: nowrap;
    border: 1px solid rgba(255, 255, 255, .12);
  }

  .status-signup {
    color: #ffd98a;
    background: rgba(255, 210, 90, .12);
    border-color: rgba(255, 210, 90, .24);
  }

  .status-waiting {
    color: #9ed5ff;
    background: rgba(90, 160, 230, .12);
    border-color: rgba(90, 160, 230, .24);
  }

  .status-finished {
    color: #9effb5;
    background: rgba(90, 220, 120, .12);
    border-color: rgba(90, 220, 120, .24);
  }

  .status-dispute {
    color: #ff9c9c;
    background: rgba(255, 90, 90, .12);
    border-color: rgba(255, 90, 90, .24);
  }

  /* ===============================
     Timeline
  =============================== */

  .ul-cup-timeline {
    display: grid;
    grid-template-columns: repeat(7, minmax(120px, 1fr));
    gap: 10px;
    overflow-x: auto;
    padding-bottom: 4px;
  }

  .timeline-item {
    position: relative;
    min-height: 88px;
    padding: 12px;
    border-radius: 14px;
    background: rgba(255, 255, 255, .045);
    border: 1px solid rgba(255, 255, 255, .08);
  }

  .timeline-item.is-current {
    background: rgba(199, 64, 80, .16);
    border-color: rgba(255, 110, 128, .35);
  }

  .timeline-date {
    padding-top: 18px;
    color: #ffd98a;
    font-weight: 800;
    font-size: 13px;
  }

  .timeline-title {
    color: #fff;
    font-weight: 800;
    margin-top: 5px;
  }

  .timeline-note {
    color: #aeb8c6;
    font-size: 12px;
    margin-top: 4px;
  }

  /* ===============================
     Bracket
  =============================== */

  .ul-cup-bracket-wrap {
    overflow-x: auto;
    padding-bottom: 8px;
  }

  .ul-cup-bracket {
    display: grid;
    grid-template-columns: repeat(5, minmax(230px, 1fr));
    gap: 14px;
    min-width: 1180px;
  }

  .bracket-round {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .bracket-round-title {
    position: sticky;
    top: 0;
    z-index: 1;
    padding: 8px 10px;
    border-radius: 12px;
    background: rgba(0, 0, 0, .28);
    border: 1px solid rgba(255, 255, 255, .08);
    color: #fff;
    font-weight: 800;
    text-align: center;
  }

  .match-card {
    display: block;
    color: inherit;
    text-decoration: none;
    border-radius: 14px;
    background: linear-gradient(180deg, rgba(38, 43, 54, .96), rgba(27, 31, 40, .96));
    border: 1px solid rgba(255, 255, 255, .08);
    overflow: hidden;
    transition: .15s ease;
  }

  .match-card:hover,
  .match-card:focus {
    color: inherit;
    text-decoration: none;
    transform: translateY(-2px);
    border-color: rgba(255, 215, 120, .35);
    box-shadow: 0 10px 22px rgba(0, 0, 0, .24);
  }

  .match-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 11px;
    border-bottom: 1px solid rgba(255, 255, 255, .07);
    background: rgba(255, 255, 255, .035);
  }

  .match-no {
    color: #ffd98a;
    font-size: 12px;
    font-weight: 800;
  }

  .match-deadline {
    color: #9faabb;
    font-size: 11px;
  }

  .match-body {
    padding: 10px 11px 11px;
  }

  .match-player {
    display: grid;
    grid-template-columns: 1fr auto;
    align-items: center;
    gap: 8px;
    padding: 8px 8px;
    border-radius: 10px;
    background: rgba(255, 255, 255, .04);
    margin-bottom: 6px;
  }

  .match-player.is-winner {
    background: rgba(255, 215, 120, .12);
    border: 1px solid rgba(255, 215, 120, .23);
  }

  .player-name {
    color: #edf3fa;
    font-weight: 700;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .player-seed {
    display: inline-block;
    color: #798495;
    font-size: 11px;
    margin-right: 5px;
  }

  .player-score {
    min-width: 24px;
    text-align: center;
    color: #fff;
    font-weight: 900;
    font-size: 16px;
  }

  .match-foot {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-top: 8px;
    color: #9faabb;
    font-size: 12px;
  }

  .match-more {
    color: #ffd98a;
  }

  .match-placeholder {
    color: #6f7a89;
    font-style: italic;
  }

  /* ===============================
     Schedule Table
  =============================== */

  .cup-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0 8px;
  }

  .cup-table th {
    color: #9faabb;
    font-size: 12px;
    font-weight: 700;
    padding: 0 10px 4px;
  }

  .cup-table td {
    background: rgba(255, 255, 255, .045);
    border-top: 1px solid rgba(255, 255, 255, .07);
    border-bottom: 1px solid rgba(255, 255, 255, .07);
    padding: 11px 10px;
    color: #e8edf2;
  }

  .cup-table td:first-child {
    border-left: 1px solid rgba(255, 255, 255, .07);
    border-radius: 12px 0 0 12px;
  }

  .cup-table td:last-child {
    border-right: 1px solid rgba(255, 255, 255, .07);
    border-radius: 0 12px 12px 0;
  }

  .cup-table a {
    color: #ffd98a;
  }

  /* ===============================
     Mobile
  =============================== */

  @media (max-width: 991px) {
    .ul-cup-info-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .ul-cup-title {
      font-size: 24px;
    }

    .ul-cup-bracket {
      display: block;
      min-width: 0;
    }

    .bracket-round {
      margin-bottom: 16px;
    }

    .bracket-round-title {
      position: relative;
      text-align: left;
    }

    .match-card {
      margin-bottom: 10px;
    }
  }

  @media (max-width: 575px) {
    .ul-cup-page {
      padding: 12px 10px 22px;
    }

    .ul-cup-hero {
      padding: 18px 16px;
      border-radius: 16px;
    }

    .ul-cup-info-grid {
      grid-template-columns: 1fr;
    }

    .ul-cup-section {
      padding: 14px;
    }

    .ul-cup-section-head {
      display: block;
    }

    .ul-cup-status-pill {
      margin-top: 8px;
    }

    .cup-table,
    .cup-table thead,
    .cup-table tbody,
    .cup-table tr,
    .cup-table th,
    .cup-table td {
      display: block;
      width: 100%;
    }

    .cup-table thead {
      display: none;
    }

    .cup-table tr {
      margin-bottom: 10px;
      border-radius: 12px;
      background: rgba(255, 255, 255, .045);
      border: 1px solid rgba(255, 255, 255, .07);
      overflow: hidden;
    }

    .cup-table td {
      border: 0;
      border-radius: 0 !important;
      display: flex;
      justify-content: space-between;
      gap: 10px;
      padding: 9px 10px;
    }

    .cup-table td::before {
      content: attr(data-label);
      color: #9faabb;
      font-size: 12px;
      flex: 0 0 82px;
    }
  }

  .ul-cup-rules-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px 36px;
    align-items: stretch;
  }

  .ul-cup-rules-grid .ul-cup-info-card {
    height: 100%;
    margin-bottom: 0 !important;
  }

  @media (max-width: 767px) {
    .ul-cup-rules-grid {
      grid-template-columns: 1fr;
      gap: 12px;
    }
  }

  .ulgg-players-section {
    margin-top: 24px;
  }

  .ulgg-section-head {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 14px;
  }

  .ulgg-section-head h2 {
    margin: 0;
    color: #fff;
    font-size: 24px;
    font-weight: 900;
  }

  .ulgg-section-head p {
    margin: 6px 0 0;
    color: #aeb8cc;
    font-size: 14px;
  }

  .ulgg-player-count {
    flex: 0 0 auto;
    color: #ffd479;
    font-size: 20px;
    font-weight: 900;
    background: rgba(0, 0, 0, .22);
    border: 1px solid rgba(230, 199, 122, .28);
    border-radius: 10px;
    padding: 8px 12px;
  }

  .ulgg-alert {
    margin: 12px 0;
    border-radius: 10px;
    padding: 10px 12px;
    font-weight: 800;
  }

  .ulgg-alert.success {
    color: #b8ffd0;
    background: rgba(39, 140, 73, .18);
    border: 1px solid rgba(90, 220, 130, .35);
  }

  .ulgg-alert.error {
    color: #ffd0d0;
    background: rgba(160, 50, 50, .18);
    border: 1px solid rgba(240, 110, 110, .35);
  }

  .ulgg-import-form {
    margin: 12px 0 16px;
    background: linear-gradient(180deg, rgba(48, 54, 67, .95), rgba(36, 41, 53, .95));
    border: 1px solid rgba(111, 168, 255, .25);
    border-radius: 12px;
    padding: 14px;
  }

  .ulgg-import-title {
    color: #fff;
    font-size: 16px;
    font-weight: 900;
    margin-bottom: 8px;
  }

  .ulgg-import-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
  }

  .ulgg-import-row input[type="file"] {
    color: #dbe6ff;
  }

  .ulgg-import-row button {
    border: 1px solid rgba(111, 168, 255, .45);
    background: linear-gradient(180deg, #263044, #1c2333);
    color: #e6ecff;
    border-radius: 8px;
    padding: 8px 12px;
    font-weight: 900;
    cursor: pointer;
  }

  .ulgg-import-row button:hover {
    color: #fff;
    box-shadow: 0 0 12px rgba(111, 168, 255, .35);
  }

  .ulgg-import-note {
    margin-top: 8px;
    color: #9faabd;
    font-size: 12px;
    line-height: 1.6;
  }

  .ulgg-empty {
    color: #b8c0d4;
    background: rgba(0, 0, 0, .18);
    border: 1px dashed rgba(255, 255, 255, .22);
    border-radius: 12px;
    padding: 18px;
    text-align: center;
  }

  .ulgg-player-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
  }

  .ulgg-player-card {
    display: grid;
    grid-template-columns: 46px minmax(0, 1fr);
    gap: 10px;
    align-items: center;
    background: linear-gradient(180deg, #303643, #242935);
    border: 1px solid rgba(111, 168, 255, .22);
    border-radius: 12px;
    /* padding: 10px;
    min-height: 74px; */
  }

  .ulgg-player-seed {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #ffd479;
    background: rgba(0, 0, 0, .28);
    border: 1px solid rgba(230, 199, 122, .28);
    font-weight: 900;
  }

  .ulgg-player-main {
    min-width: 0;
  }

  .ulgg-player-name {
    color: #fff;
    font-size: 15px;
    font-weight: 900;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ulgg-player-sub {
    margin-top: 3px;
    color: #9fb3d6;
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ulgg-player-meta {
    margin-top: 4px;
    color: #9faabd;
    font-size: 12px;
  }

  @media (max-width: 1100px) {
    .ulgg-player-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }

  @media (max-width: 780px) {
    .ulgg-section-head {
      align-items: flex-start;
      flex-direction: column;
    }

    .ulgg-player-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 520px) {
    .ulgg-player-grid {
      grid-template-columns: 1fr;
    }
  }

  .ulgg-import-form {
    margin-top: 16px;
    padding: 16px;
    border-radius: 18px;
    background:
      radial-gradient(circle at top left, rgba(255, 212, 121, .14), transparent 34%),
      linear-gradient(135deg, rgba(18, 22, 34, .92), rgba(9, 12, 20, .94));
    border: 1px solid rgba(255, 212, 121, .28);
    box-shadow:
      0 16px 36px rgba(0, 0, 0, .28),
      inset 0 1px 0 rgba(255, 255, 255, .05);
  }

  .ulgg-import-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
    margin-bottom: 12px;
  }

  .ulgg-import-title {
    color: #ffd479;
    font-size: 14px;
    font-weight: 900;
    letter-spacing: .08em;
  }

  .ulgg-import-subtitle {
    margin-top: 3px;
    color: rgba(226, 232, 240, .68);
    font-size: 12px;
  }

  .ulgg-import-row {
    display: flex;
    gap: 10px;
    align-items: stretch;
    flex-wrap: wrap;
  }

  .ulgg-file-picker {
    position: relative;
    flex: 1 1 280px;
    min-height: 52px;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 14px;
    border-radius: 14px;
    cursor: pointer;
    background:
      linear-gradient(135deg, rgba(255, 255, 255, .08), rgba(255, 255, 255, .03));
    border: 1px dashed rgba(255, 212, 121, .42);
    color: #f8fafc;
    transition:
      transform .16s ease,
      border-color .16s ease,
      background .16s ease,
      box-shadow .16s ease;
    max-width: 550px;
  }

  .ulgg-file-picker:hover {
    transform: translateY(-1px);
    border-color: rgba(255, 212, 121, .72);
    background:
      linear-gradient(135deg, rgba(255, 212, 121, .13), rgba(255, 255, 255, .04));
    box-shadow: 0 10px 24px rgba(255, 159, 67, .12);
  }

  .ulgg-file-input {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    opacity: 0;
    cursor: pointer;
  }

  .ulgg-file-icon {
    width: 34px;
    height: 34px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: rgba(255, 212, 121, .14);
    border: 1px solid rgba(255, 212, 121, .34);
    flex: 0 0 auto;
  }

  .ulgg-file-text {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
  }

  .ulgg-file-text strong {
    font-size: 13px;
    font-weight: 900;
    color: #fff5d6;
  }

  .ulgg-file-text em {
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: rgba(226, 232, 240, .64);
    font-size: 12px;
    font-style: normal;
  }

  .ulgg-import-btn {
    flex: 0 0 auto;
    min-height: 52px;
    padding: 0 18px;
    border: 0;
    border-radius: 14px;
    cursor: pointer;
    background: linear-gradient(135deg, #ffd479, #ff9f43);
    color: #211509;
    font-size: 13px;
    font-weight: 950;
    letter-spacing: .04em;
    box-shadow:
      0 12px 24px rgba(255, 159, 67, .23),
      inset 0 1px 0 rgba(255, 255, 255, .35);
    transition:
      transform .16s ease,
      filter .16s ease,
      box-shadow .16s ease;
  }

  .ulgg-import-btn:hover {
    transform: translateY(-1px);
    filter: brightness(1.05);
    box-shadow:
      0 16px 30px rgba(255, 159, 67, .32),
      inset 0 1px 0 rgba(255, 255, 255, .42);
  }

  .ulgg-import-note {
    margin-top: 10px;
    color: rgba(226, 232, 240, .58);
    font-size: 12px;
    line-height: 1.6;
  }

  @media (max-width: 640px) {
    .ulgg-import-form {
      padding: 14px;
    }

    .ulgg-import-row {
      flex-direction: column;
    }

    .ulgg-file-picker,
    .ulgg-import-btn {
      width: 100%;
    }

    .ulgg-import-btn {
      min-height: 46px;
    }
  }

  .ulgg-force-import {
    margin-top: 10px;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    color: rgba(226, 232, 240, .72);
    font-size: 12px;
    cursor: pointer;
  }

  .ulgg-force-import input {
    accent-color: #ffd479;
  }

  .ulgg-force-import span {
    user-select: none;
  }

  .timeline-item.is-finished {
    opacity: .55;
  }

  .timeline-item.is-finished::after {
    content: "已完成";
    position: absolute;
    right: 10px;
    top: 10px;
    color: #9effb5;
    font-size: 11px;
  }

  .timeline-item.is-upcoming::after {
    content: "未開始";
    position: absolute;
    right: 10px;
    top: 10px;
    color: #9ed5ff;
    font-size: 11px;
  }

  .timeline-item.is-current::after {
    content: "進行中";
    position: absolute;
    right: 10px;
    top: 10px;
    color: #ffd98a;
    font-size: 11px;
  }

  .cup-deck-hint {
    margin-top: 6px;
    color: #ffd479;
    font-size: 12px;
    font-weight: 700;
    opacity: .9;
  }



  .ulgg-player-profile-link {
    color: inherit;
    text-decoration: none;
    border-bottom: 1px dashed rgba(255, 212, 121, .55);
    transition: color .18s ease, border-color .18s ease;
  }

  .ulgg-player-profile-link:hover {
    color: #ffd479;
    border-bottom-color: #ffd479;
  }

  .cup-deck-hint {
    margin-top: 8px;
    color: #ffd479;
    font-size: 13px;
    font-weight: 800;
  }

  .ul-cup-report-links {
    margin-top: 16px;
    padding: 16px;
    border-radius: 18px;
    background: rgba(255, 255, 255, .045);
    border: 1px solid rgba(255, 255, 255, .08);
  }

  .ul-cup-report-title {
    font-size: 15px;
    font-weight: 900;
    color: #fff;
    margin-bottom: 12px;
    letter-spacing: .04em;
  }

  .ul-cup-report-list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }

  .ul-cup-report-card {
    display: flex;
    flex-direction: column;
    gap: 5px;
    min-height: 92px;
    padding: 14px 16px;
    border-radius: 16px;
    text-decoration: none;
    color: inherit;
    background: rgba(15, 23, 42, .72);
    border: 1px solid rgba(255, 255, 255, .09);
    transition: transform .16s ease, border-color .16s ease, background .16s ease;
  }

  .ul-cup-report-card:hover {
    transform: translateY(-2px);
    background: rgba(30, 41, 59, .88);
    border-color: rgba(250, 204, 21, .45);
  }

  .ul-cup-report-card-new {
    background: linear-gradient(135deg, rgba(250, 204, 21, .14), rgba(15, 23, 42, .78));
    border-color: rgba(250, 204, 21, .28);
  }

  .ul-cup-report-badge {
    width: fit-content;
    font-size: 11px;
    font-weight: 900;
    color: #facc15;
    background: rgba(250, 204, 21, .12);
    border: 1px solid rgba(250, 204, 21, .22);
    padding: 3px 8px;
    border-radius: 999px;
  }

  .ul-cup-report-main {
    font-size: 17px;
    font-weight: 900;
    color: #fff;
  }

  .ul-cup-report-sub {
    font-size: 13px;
    line-height: 1.5;
    color: #cbd5e1;
  }

  @media (max-width: 720px) {
    .ul-cup-report-list {
      grid-template-columns: 1fr;
    }

    .ul-cup-report-card {
      min-height: auto;
    }
  }

  .ulgg-player-card.is-eliminated {
    opacity: .45;
    filter: grayscale(.65);
    background: linear-gradient(180deg, rgba(70, 74, 84, .62), rgba(42, 46, 56, .62));
    border-color: rgba(180, 190, 210, .12);
    box-shadow: none;
  }

  .ulgg-player-card.is-eliminated .ulgg-player-seed,
  .ulgg-player-card.is-eliminated .ulgg-player-name {
    color: rgba(226, 232, 240, .58);
  }

  .ulgg-player-card.is-eliminated:hover {
    opacity: .68;
    filter: grayscale(.35);
  }

  .ulgg-player-status.eliminated {
    margin-top: 3px;
    color: rgba(226, 232, 240, .48);
    font-size: 11px;
    font-weight: 800;
  }

  /* ===============================
   Latest Results
=============================== */

  .ul-cup-latest-section {
    margin-bottom: 18px;
    border-radius: 18px;
    padding: 16px;
    background:
      radial-gradient(circle at top left, rgba(255, 212, 121, .16), transparent 34%),
      linear-gradient(135deg, rgba(23, 27, 35, .98), rgba(15, 19, 27, .98));
    border: 1px solid rgba(255, 212, 121, .20);
    box-shadow: 0 10px 26px rgba(0, 0, 0, .22);
  }

  .ul-cup-latest-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 12px;
    margin-bottom: 12px;
  }

  .ul-cup-latest-title {
    margin: 0;
    color: #fff;
    font-size: 20px;
    font-weight: 900;
  }

  .ul-cup-latest-desc {
    margin-top: 4px;
    color: #9faabb;
    font-size: 13px;
  }

  .ul-cup-latest-list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
  }

  .ul-cup-latest-card {
    display: block;
    min-width: 0;
    border-radius: 16px;
    padding: 13px 14px;
    color: inherit;
    text-decoration: none;
    background: linear-gradient(180deg, rgba(38, 43, 54, .96), rgba(27, 31, 40, .96));
    border: 1px solid rgba(255, 255, 255, .09);
    transition: transform .16s ease, border-color .16s ease, background .16s ease;
  }

  .ul-cup-latest-card:hover {
    color: inherit;
    text-decoration: none;
    transform: translateY(-2px);
    border-color: rgba(255, 212, 121, .42);
    background: linear-gradient(180deg, rgba(48, 54, 67, .96), rgba(30, 36, 48, .96));
  }

  .ul-cup-latest-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
  }

  .ul-cup-latest-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    border-radius: 999px;
    padding: 4px 8px;
    color: #9effb5;
    background: rgba(90, 220, 120, .12);
    border: 1px solid rgba(90, 220, 120, .24);
    font-size: 11px;
    font-weight: 900;
    white-space: nowrap;
  }

  .ul-cup-latest-badge.forfeit {
    color: #ffd98a;
    background: rgba(255, 210, 90, .12);
    border-color: rgba(255, 210, 90, .24);
  }

  .ul-cup-latest-time {
    color: #7f8a9b;
    font-size: 11px;
    white-space: nowrap;
  }

  .ul-cup-latest-match {
    color: #ffd98a;
    font-size: 12px;
    font-weight: 900;
    margin-bottom: 6px;
  }

  .ul-cup-latest-scoreline {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    color: #fff;
    font-size: 15px;
    font-weight: 900;
  }

  .ul-cup-latest-player {
    min-width: 0;
    flex: 1 1 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ul-cup-latest-player.is-winner {
    color: #fff5d6;
  }

  .ul-cup-latest-score {
    flex: 0 0 auto;
    color: #ffd479;
    background: rgba(0, 0, 0, .24);
    border: 1px solid rgba(255, 212, 121, .26);
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 14px;
    font-weight: 950;
  }

  .ul-cup-latest-note {
    margin-top: 8px;
    color: #b9c2cf;
    font-size: 12px;
    line-height: 1.5;
  }

  .ul-cup-latest-more {
    margin-top: 8px;
    color: #ffd98a;
    font-size: 12px;
    font-weight: 900;
  }

  .ul-cup-latest-empty {
    color: #9faabb;
    padding: 14px;
    text-align: center;
    border-radius: 12px;
    background: rgba(0, 0, 0, .16);
    border: 1px dashed rgba(255, 255, 255, .14);
  }

  @media (max-width: 900px) {
    .ul-cup-latest-list {
      grid-template-columns: 1fr;
    }

    .ul-cup-latest-head {
      display: block;
    }
  }

  .ul-cup-push-btn {
    flex: 0 0 auto;
    border: 1px solid rgba(255, 212, 121, .38);
    border-radius: 999px;
    padding: 8px 12px;
    cursor: pointer;
    color: #211509;
    background: linear-gradient(135deg, #ffd479, #ff9f43);
    font-size: 13px;
    font-weight: 950;
    box-shadow:
      0 10px 20px rgba(255, 159, 67, .18),
      inset 0 1px 0 rgba(255, 255, 255, .32);
  }

  .ul-cup-push-btn:hover {
    filter: brightness(1.06);
    transform: translateY(-1px);
  }

  .ul-cup-push-btn.is-subscribed {
    color: #bfffe3;
    background: rgba(77, 213, 153, .14);
    border-color: rgba(77, 213, 153, .36);
  }

  .ul-cup-push-btn.is-disabled {
    opacity: .58;
    cursor: not-allowed;
  }

  .bracket-round-collapse {
    display: flex;
    flex-direction: column;
    gap: 10px;
    min-width: 0;
  }

  .bracket-round-collapse>summary {
    list-style: none;
  }

  .bracket-round-collapse>summary::-webkit-details-marker {
    display: none;
  }

  .bracket-round-summary {
    display: flex;
    align-items: stretch;
    justify-content: space-between;
    gap: 8px;
    cursor: pointer;
  }

  .bracket-round-summary .bracket-round-title {
    flex: 1 1 auto;
    min-width: 0;
    margin-bottom: 0;
  }

  .round-collapse-icon {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 26px;
    min-height: 38px;
    border-radius: 12px;
    color: #ffd479;
    background: rgba(255, 212, 121, .12);
    border: 1px solid rgba(255, 212, 121, .25);
    font-size: 12px;
    font-weight: 900;
    transition: transform .18s ease;
  }

  .bracket-round-collapse[open] .round-collapse-icon {
    transform: rotate(180deg);
  }

  .bracket-round-body {
    display: flex;
    flex-direction: column;
    gap: 10px;
  }

  .round-finished-badge {
    display: inline-flex;
    align-items: center;
    margin-left: 6px;
    padding: 2px 7px;
    border-radius: 999px;
    color: #bfffe3;
    background: rgba(77, 213, 153, .13);
    border: 1px solid rgba(77, 213, 153, .28);
    font-size: 11px;
    font-weight: 900;
  }

  @media (max-width: 991px) {
    .bracket-round-collapse {
      margin-bottom: 16px;
    }

    .bracket-round-summary .bracket-round-title {
      position: relative;
      text-align: left;
    }
  }

  @media (max-width: 768px) {
    .bracket-round-summary {
      align-items: stretch;
    }

    .round-collapse-icon {
      min-height: 40px;
    }

    .round-finished-badge {
      display: inline-flex;
      margin-top: 4px;
      margin-left: 0;
    }
  }

  .ul-cup-report-card-live {
    background: linear-gradient(135deg, rgba(77, 213, 153, .16), rgba(15, 23, 42, .78));
    border-color: rgba(77, 213, 153, .30);
  }
  .ul-cup-report-card-final {
  background:
    radial-gradient(
      circle at top left,
      rgba(250, 204, 21, .24),
      transparent 42%
    ),
    linear-gradient(
      135deg,
      rgba(120, 77, 15, .42),
      rgba(15, 23, 42, .88)
    );
  border-color: rgba(250, 204, 21, .42);
  box-shadow:
    0 14px 30px rgba(0, 0, 0, .22),
    inset 0 1px 0 rgba(255, 255, 255, .06);
}

.ul-cup-report-card-final .ul-cup-report-badge {
  color: #211509;
  background: linear-gradient(135deg, #ffe49a, #facc15);
  border-color: rgba(255, 229, 138, .72);
}

.ul-cup-report-card-final:hover {
  border-color: rgba(250, 204, 21, .72);
  background:
    radial-gradient(
      circle at top left,
      rgba(250, 204, 21, .32),
      transparent 44%
    ),
    linear-gradient(
      135deg,
      rgba(146, 94, 18, .48),
      rgba(30, 41, 59, .92)
    );
}

  .ul-cup-report-card-live .ul-cup-report-badge {
    color: #bfffe3;
    background: rgba(77, 213, 153, .12);
    border-color: rgba(77, 213, 153, .25);
  }

  .ul-cup-report-card-live:hover {
    border-color: rgba(77, 213, 153, .55);
    background: linear-gradient(135deg, rgba(77, 213, 153, .22), rgba(30, 41, 59, .88));
  }

  .ul-cup-upcoming-section {
    margin-top: 18px;
    margin-bottom: 22px;
  }

  .ul-cup-upcoming-list {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
  }

  .ul-cup-upcoming-card {
    display: flex;
    flex-direction: column;
    min-height: 136px;
    position: relative;
    padding: 14px 15px;
    border-radius: 16px;
    border: 1px solid rgba(96, 165, 250, .28);
    background:
      radial-gradient(circle at top left, rgba(96, 165, 250, .16), transparent 38%),
      rgba(15, 23, 42, .76);
    color: inherit;
    text-decoration: none;
    box-shadow: 0 10px 28px rgba(0, 0, 0, .22);
    transition: transform .16s ease, border-color .16s ease, background .16s ease;
  }

  .ul-cup-upcoming-card:hover {
    transform: translateY(-2px);
    border-color: rgba(96, 165, 250, .55);
    background:
      radial-gradient(circle at top left, rgba(96, 165, 250, .23), transparent 40%),
      rgba(15, 23, 42, .86);
  }

  .ul-cup-upcoming-card.is-soon,
  .ul-cup-upcoming-card.is-live-like {
    border-color: rgba(255, 212, 121, .55);
    background:
      radial-gradient(circle at top left, rgba(255, 212, 121, .20), transparent 40%),
      rgba(15, 23, 42, .82);
  }

  .ul-cup-upcoming-badge {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: 4px 9px;
    font-size: 12px;
    font-weight: 900;
    color: #bfdbfe;
    background: rgba(96, 165, 250, .12);
    border: 1px solid rgba(96, 165, 250, .22);
  }

  .ul-cup-upcoming-card.is-soon .ul-cup-upcoming-badge,
  .ul-cup-upcoming-card.is-live-like .ul-cup-upcoming-badge {
    color: #111827;
    background: #ffd479;
    border-color: rgba(255, 212, 121, .8);
  }

  .ul-cup-upcoming-vs {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
    gap: 10px;
    align-items: center;
    margin-top: 10px;
    font-size: 15px;
    font-weight: 900;
  }

  .ul-cup-upcoming-vs span {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .ul-cup-upcoming-vs span:last-child {
    text-align: right;
  }

  .ul-cup-upcoming-vs strong {
    color: #ffd479;
    font-size: 13px;
    letter-spacing: .08em;
  }

  .ul-cup-upcoming-card .ul-cup-latest-more {
    margin-top: auto;
  }

  @media (max-width: 1180px) {
    .ul-cup-upcoming-list {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }

  @media (max-width: 720px) {
    .ul-cup-upcoming-list {
      grid-template-columns: 1fr;
    }

    .ul-cup-upcoming-card {
      min-height: 0;
      padding: 13px;
    }

    .ul-cup-upcoming-vs {
      font-size: 14px;
    }
  }
</style>

<?php
    /* 匯入CSV */
    function h($value): string
    {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    function normalizeCsvHeader(string $s): string
    {
    $s = trim($s);
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s); // remove UTF-8 BOM
    $s = preg_replace('/\s+/u', ' ', $s);
    return $s;
    }

    function currentLoginName(): string
    {
    return trim((string) (
        $_SESSION['username'] ?? $_SESSION['nickname'] ?? $_SESSION['user_name'] ?? ''
    ));
    }

    function isTournamentAdmin(PDO $pdo): bool
    {
    $ack = (int) (
        $_SESSION['ack'] ?? $_SESSION['permission'] ?? $_SESSION['check_ack'] ?? 0
    );

    if ($ack >= 2) {
        return true;
    }

    $loginName = currentLoginName();

    if ($loginName === '') {
        return false;
    }

    $stmt = $pdo->prepare("
    SELECT ack
    FROM game_user
    WHERE username = :name
       OR nickname = :name
    ORDER BY ack DESC
    LIMIT 1
  ");

    $stmt->execute([
        ':name' => $loginName,
    ]);

    $dbAck = $stmt->fetchColumn();

    return $dbAck !== false && (int) $dbAck >= 2;
    }

    function mapEntryStatus(string $status): string
    {
    $status = trim($status);

    if ($status === '') {
        return 'registered';
    }

    if (mb_strpos($status, '完成') !== false) {
        return 'registered';
    }

    if (
        mb_strpos($status, '取消') !== false ||
        mb_strpos($status, '退出') !== false ||
        mb_strpos($status, '棄權') !== false
    ) {
        return 'withdrawn';
    }

    if (
        mb_strpos($status, '無效') !== false ||
        mb_strpos($status, '失格') !== false
    ) {
        return 'invalid';
    }

    if (
        mb_strpos($status, '等待') !== false ||
        mb_strpos($status, '候補') !== false
    ) {
        return 'waiting';
    }

    return 'registered';
    }

    function normalizeDateTimeValue($value): ?string
    {
    $value = trim((string) $value);

    if ($value === '') {
        return null;
    }

    $ts = strtotime($value);

    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
    }
    function importTournamentPlayersCsv(
    PDO $pdo,
    string $tmpPath,
    string $originalName,
    int $tournamentId = 1,
    bool $forceImport = false
    ): array {
    if (! is_uploaded_file($tmpPath) && ! file_exists($tmpPath)) {
        return [
            'ok'      => false,
            'message' => '找不到上傳檔案。',
        ];
    }

    $fileHash = sha1_file($tmpPath);

    $stmtCheck = $pdo->prepare("
    SELECT id, created_at
    FROM tournament_import_logs
    WHERE tournament_id = :tournament_id
      AND import_type = 'players_csv'
      AND file_hash = :file_hash
    LIMIT 1
  ");

    $stmtCheck->execute([
        ':tournament_id' => $tournamentId,
        ':file_hash'     => $fileHash,
    ]);

    $oldLog = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ($oldLog && ! $forceImport) {
        return [
            'ok'      => false,
            'message' => '這份 CSV 已匯入過，匯入時間：' . $oldLog['created_at'] . '。為避免重複上傳，本次未處理。',
        ];
    }

    $fh = fopen($tmpPath, 'rb');

    if (! $fh) {
        return [
            'ok'      => false,
            'message' => 'CSV 無法開啟。',
        ];
    }

    $header = fgetcsv($fh);

    if (! $header) {
        fclose($fh);
        return [
            'ok'      => false,
            'message' => 'CSV 沒有標題列。',
        ];
    }

    $headerMap = [];

    foreach ($header as $idx => $col) {
        $headerMap[normalizeCsvHeader((string) $col)] = $idx;
    }

    $get = function (array $row, string $key) use ($headerMap): string {
        $key = normalizeCsvHeader($key);

        if (! isset($headerMap[$key])) {
            return '';
        }

        $idx = $headerMap[$key];

        return trim((string) ($row[$idx] ?? ''));
    };

    $required = ['帳號 ID', '帳號名稱', '遊戲名稱', '報名順序', '狀況'];

    foreach ($required as $key) {
        if (! isset($headerMap[normalizeCsvHeader($key)])) {
            fclose($fh);
            return [
                'ok'      => false,
                'message' => 'CSV 缺少必要欄位：' . $key,
            ];
        }
    }

    $total    = 0;
    $inserted = 0;
    $updated  = 0;
    $skipped  = 0;

    try {
        $pdo->beginTransaction();

        $stmtExists = $pdo->prepare("
      SELECT id
      FROM tournament_players
      WHERE tournament_id = :tournament_id
        AND seed_no = :seed_no
      LIMIT 1
    ");

        $stmtUpsert = $pdo->prepare("
      INSERT INTO tournament_players (
        tournament_id,
        seed_no,
        tonamel_entry_name,
        tonamel_user_name,
        game_name,
        display_name,
        discord_name,
        note,
        entry_status,
        player_status,
        entry_order,
        entry_time,
        is_public
      ) VALUES (
        :tournament_id,
        :seed_no,
        :tonamel_entry_name,
        :tonamel_user_name,
        :game_name,
        :display_name,
        :discord_name,
        :note,
        :entry_status,
        'active',
        :entry_order,
        :entry_time,
        1
      )
      ON DUPLICATE KEY UPDATE
        tonamel_entry_name = VALUES(tonamel_entry_name),
        tonamel_user_name = VALUES(tonamel_user_name),
        game_name = VALUES(game_name),
        display_name = VALUES(display_name),
        discord_name = VALUES(discord_name),
        note = VALUES(note),
        entry_status = VALUES(entry_status),
        entry_order = VALUES(entry_order),
        entry_time = VALUES(entry_time),
        is_public = VALUES(is_public),
        updated_at = CURRENT_TIMESTAMP
    ");

        while (($row = fgetcsv($fh)) !== false) {
            $total++;

            // 只匯入 Tonamel 狀況 = 報名完成 的資料
            $rawEntryStatus = trim($get($row, '狀況'));

            if (! ($rawEntryStatus == '報名完成' || $rawEntryStatus == '參賽資格')) {
                $skipped++;
                continue;
            }

            $entryOrderRaw = $get($row, '報名順序');
            $entryOrder    = (int) $entryOrderRaw;

            if ($entryOrder <= 0) {
                $skipped++;
                continue;
            }

            $tonamelUserName  = $get($row, '帳號 ID');
            $tonamelEntryName = $get($row, '帳號名稱');
            $gameName         = $get($row, '遊戲名稱');
            $discordName      = $get($row, 'DC 帳號');

            if ($discordName === '') {
                $discordName = $get($row, 'DC  帳號');
            }

            $note        = $get($row, '其他想補充的');
            $entryTime   = normalizeDateTimeValue($get($row, '報名日期與時間'));
            $entryStatus = mapEntryStatus($rawEntryStatus);

            $displayName = $gameName !== ''
                ? $gameName
                : ($tonamelEntryName !== '' ? $tonamelEntryName : $tonamelUserName);

            if ($displayName === '') {
                $skipped++;
                continue;
            }

            $stmtExists->execute([
                ':tournament_id' => $tournamentId,
                ':seed_no'       => $entryOrder,
            ]);

            $exists = $stmtExists->fetchColumn() !== false;

            $stmtUpsert->execute([
                ':tournament_id'      => $tournamentId,
                ':seed_no'            => $entryOrder,
                ':tonamel_entry_name' => $tonamelEntryName,
                ':tonamel_user_name'  => $tonamelUserName,
                ':game_name'          => $gameName,
                ':display_name'       => $displayName,
                ':discord_name'       => $discordName,
                ':note'               => $note,
                ':entry_status'       => $entryStatus,
                ':entry_order'        => $entryOrder,
                ':entry_time'         => $entryTime,
            ]);

            if ($exists) {
                $updated++;
            } else {
                $inserted++;
            }
        }

        fclose($fh);

        $stmtLog = $pdo->prepare("
  INSERT INTO tournament_import_logs (
    tournament_id,
    import_type,
    file_name,
    file_hash,
    total_rows,
    inserted_rows,
    updated_rows,
    skipped_rows,
    message,
    created_by
  ) VALUES (
    :tournament_id,
    'players_csv',
    :file_name,
    :file_hash,
    :total_rows,
    :inserted_rows,
    :updated_rows,
    :skipped_rows,
    :message,
    :created_by
  )
  ON DUPLICATE KEY UPDATE
    file_name = VALUES(file_name),
    total_rows = VALUES(total_rows),
    inserted_rows = VALUES(inserted_rows),
    updated_rows = VALUES(updated_rows),
    skipped_rows = VALUES(skipped_rows),
    message = VALUES(message),
    created_by = VALUES(created_by),
    created_at = CURRENT_TIMESTAMP
");

        $message = $forceImport
            ? '參賽者 CSV 強制重新匯入完成'
            : '參賽者 CSV 匯入完成';

        $stmtLog->execute([
            ':tournament_id' => $tournamentId,
            ':file_name'     => $originalName,
            ':file_hash'     => $fileHash,
            ':total_rows'    => $total,
            ':inserted_rows' => $inserted,
            ':updated_rows'  => $updated,
            ':skipped_rows'  => $skipped,
            ':message'       => $message,
            ':created_by'    => currentLoginName(),
        ]);

        $pdo->commit();

        return [
            'ok'      => true,
            'message' => ($forceImport ? '強制重新匯入完成：' : '匯入完成：') .
            "僅匯入「報名完成」資料。新增 {$inserted} 筆，更新 {$updated} 筆，略過 {$skipped} 筆。",
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (is_resource($fh)) {
            fclose($fh);
        }

        return [
            'ok'      => false,
            'message' => '匯入失敗：' . $e->getMessage(),
        ];
    }
    }
    $tournamentId  = 1;
    $importMessage = '';
    $importOk      = null;
    $isAdmin       = isTournamentAdmin($pdo);

    if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'import_players_csv'
    ) {
    if (! isTournamentAdmin($pdo)) {
        $importOk      = false;
        $importMessage = '你沒有權限匯入參賽者名單。';
    } elseif (
        ! isset($_FILES['players_csv']) ||
        ($_FILES['players_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
    ) {
        $importOk      = false;
        $importMessage = '請選擇 CSV 檔案。';
    } else {
        $fileName = (string) ($_FILES['players_csv']['name'] ?? '');
        $tmpPath  = (string) ($_FILES['players_csv']['tmp_name'] ?? '');

        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($ext !== 'csv') {
            $importOk      = false;
            $importMessage = '請上傳 CSV 檔案。';
        } else {
            $forceImport = isset($_POST['force_import']) && $_POST['force_import'] === '1';

            $result = importTournamentPlayersCsv(
                $pdo,
                $_FILES['players_csv']['tmp_name'],
                $_FILES['players_csv']['name'],
                1,
                $forceImport
            );
            $importOk      = $result['ok'];
            $importMessage = $result['message'];
        }
    }
    }
    $stmtPlayers = $pdo->prepare("
  SELECT
    p.id,
    p.seed_no,
    p.tonamel_entry_name,
    p.tonamel_user_name,
    p.game_name,
    p.display_name,
    p.entry_status,
    p.player_status,
    p.entry_order,
    p.entry_time,
    p.is_public,
    CASE
      WHEN EXISTS (
        SELECT 1
        FROM tournament_matches m
        WHERE m.tournament_id = p.tournament_id
          AND m.winner_player_id IS NOT NULL
          AND m.winner_player_id <> p.id
          AND (m.player1_id = p.id OR m.player2_id = p.id)
          AND m.status IN ('confirmed', 'finished')
      )
      THEN 1
      ELSE 0
    END AS is_eliminated
  FROM tournament_players p
  WHERE p.tournament_id = :tournament_id
    AND p.is_public = 1
  ORDER BY p.seed_no ASC
");

    $stmtPlayers->execute([
    ':tournament_id' => $tournamentId,
    ]);

    $tournamentPlayers = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);

    date_default_timezone_set('Asia/Taipei');

    $now = new DateTime('now');

    function cupDt(string $datetime): DateTime
    {
    return new DateTime($datetime);
    }

    function cupDateRangeText(string $start, ?string $end = null, bool $showYear = false): string
    {
    $s = cupDt($start);

    if ($end === null || $end === '') {
        return $showYear ? $s->format('Y/n/j') : $s->format('n/j');
    }

    $e = cupDt($end);

    if ($s->format('Y-m-d') === $e->format('Y-m-d')) {
        return ($showYear ? $s->format('Y/n/j') : $s->format('n/j')) . ' ' . $s->format('H:i') . ' - ' . $e->format('H:i');
    }

    return ($showYear ? $s->format('Y/n/j H:i') : $s->format('n/j H:i')) . ' - ' . ($showYear ? $e->format('Y/n/j H:i') : $e->format('n/j H:i'));
    }

    function cupDateText(string $start, ?string $end = null): string
    {
    if ($end === null || $end === '') {
        return cupDt($start)->format('Y/n/j');
    }

    return cupDateRangeText($start, $end, true);
    }

    function cupStageStatus(DateTime $now, string $start, ?string $end = null): string
    {
    $s = cupDt($start);
    $e = $end ? cupDt($end) : $s;

    if ($now < $s) {
        return 'upcoming';
    }

    if ($now > $e) {
        return 'finished';
    }

    return 'current';
    }

    $cupSchedule = [
    [
        'key'        => 'signup',
        'title'      => '報名期間',
        'start'      => '2026-06-23 21:00:00',
        'end'        => '2026-06-30 23:59:00',
        'note'       => '先到先得，額滿截止',
        'form'       => 'Tonamel 報名',
        'table_note' => '額滿則關閉報名',
    ],
    [
        'key'        => 'deck_open',
        'title'      => '牌組公開',
        'start'      => '2026-07-01 00:00:00',
        'end'        => '2026-07-05 23:59:59',
        'note'       => '公開選手 A/B/C 牌組',
        'form'       => '公開 A/B/C 三副牌組',
        'table_note' => '依主辦整理資料公開',
    ],
    [
        'key'         => 'round32',
        'title'       => '第一輪 32 強',
        'short_title' => '第一輪',
        'start'       => '2026-07-06 00:00:00',
        'end'         => '2026-07-12 23:59:00',
        'note'        => '32強，自行約戰',
        'form'        => '線上自行約戰',
        'table_note'  => '勝方回報截圖',
    ],
    [
        'key'         => 'round16',
        'title'       => '第二輪 16 強',
        'short_title' => '第二輪',
        'start'       => '2026-07-13 00:00:00',
        'end'         => '2026-07-19 23:59:00',
        'note'        => '16強，自行約戰',
        'form'        => '線上自行約戰',
        'table_note'  => '勝方回報截圖',
    ],
    [
        'key'        => 'quarterfinal',
        'title'      => '八強',
        'start'      => '2026-07-25 00:00:00',
        'end'        => '2026-07-26 23:59:00',
        'note'       => '暫定直播賽程',
        'form'       => '主辦安排 / 直播',
        'table_note' => '時間待公告',
    ],
    [
        'key'        => 'semifinal',
        'title'      => '準決賽',
        'start'      => '2026-08-01 00:00:00',
        'end'        => '2026-08-01 23:59:00',
        'note'       => '暫定直播賽程',
        'form'       => '主辦安排 / 直播',
        'table_note' => '時間待公告',
    ],
    [
        'key'        => 'final',
        'title'      => '決賽',
        'start'      => '2026-08-02 00:00:00',
        'end'        => '2026-08-02 23:59:00',
        'note'       => '冠亞軍戰',
        'form'       => '主辦安排 / 直播',
        'table_note' => '冠亞軍戰',
    ],
    ];

    $currentStage = null;
    $nextStage    = null;

    foreach ($cupSchedule as $stage) {
    $status = cupStageStatus($now, $stage['start'], $stage['end'] ?? null);

    if ($status === 'current') {
        $currentStage = $stage;
        break;
    }

    if ($status === 'upcoming' && $nextStage === null) {
        $nextStage = $stage;
    }
    }

    if ($currentStage === null) {
    $currentStage = $nextStage;
    }

    $currentStageTitle = $currentStage['short_title'] ?? $currentStage['title'] ?? '賽事已結束';
    $currentStageNote  = $currentStage
    ? cupDateRangeText($currentStage['start'], $currentStage['end'] ?? null, true)
    : '所有賽程已完成';

    $timeline = [];

    foreach ($cupSchedule as $stage) {
    $status = cupStageStatus($now, $stage['start'], $stage['end'] ?? null);

    $timeline[] = [
        'date'    => cupDateRangeText($stage['start'], $stage['end'] ?? null, false),
        'title'   => $stage['short_title'] ?? $stage['title'],
        'note'    => $stage['note'],
        'status'  => $status,
        'current' => $status === 'current',
    ];
    }

    $eventCards = [
    [
        'label' => '目前階段',
        'value' => $currentStageTitle,
        'note'  => $currentStageNote,
    ],
    [
        'label' => '賽制',
        'value' => '32人單淘汰',
        'note'  => '三副牌組征服制',
    ],
    [
        'label' => 'COST 限制',
        'value' => '74',
        'note'  => '全賽局牌組 COST 74',
    ],
    [
        'label' => '牌組公開',
        'value' => cupDt('2026-07-01 00:00:00')->format('Y/n/j'),
        'note'  => '依 Tonamel 種子順序公開',
    ],
    ];

    $rounds = [
    'round1'    => [
        'title'    => '第 1 回合',
        'deadline' => '7/12 23:59',
        'matches'  => [],
    ],
    'round2'    => [
        'title'    => '第 2 回合',
        'deadline' => '7/19 23:59',
        'matches'  => [],
    ],
    'round3'    => [
        'title'    => '第 3 回合',
        'deadline' => '7/26 23:59',
        'matches'  => [],
    ],
    'semifinal' => [
        'title'    => '準決賽',
        'deadline' => '8/1',
        'matches'  => [],
    ],

    'final_day' => [
        'title'    => '季軍賽／決賽',
        'deadline' => '8/2',
        'matches'  => [],
    ],
    ];

    $roundKeyMap = [
    'round1'      => 'round1',
    '第 1 回合'      => 'round1',
    '第1回合'        => 'round1',
    '1回合'         => 'round1',
    '32強'         => 'round1',

    'round2'      => 'round2',
    '第 2 回合'      => 'round2',
    '第2回合'        => 'round2',
    '2回合'         => 'round2',
    '16強'         => 'round2',

    'round3'      => 'round3',
    '第 3 回合'      => 'round3',
    '第3回合'        => 'round3',
    '3回合'         => 'round3',
    '八強'          => 'round3',
    '8強'          => 'round3',

    'semifinal'   => 'semifinal',
    '準決賽'         => 'semifinal',
    '四強'          => 'semifinal',
    '4強'          => 'semifinal',

    'third_place' => 'final_day',
    '季軍賽'         => 'final_day',
    '季軍'          => 'final_day',

    'final'       => 'final_day',
    '決賽'          => 'final_day',
    ];

    $stmtMatches = $pdo->prepare("
  SELECT
    m.id,
    m.round_key,
    m.round_name,
    m.match_no,
    m.match_code,
    m.player1_seed_no,
    m.player2_seed_no,
    m.player1_id,
    m.player2_id,
    m.player1_score,
    m.player2_score,
    m.winner_player_id,
    m.status,
    m.deadline_text,
    m.scheduled_at,
    m.note,
    m.source_match1_code,
    m.source_match2_code,

    p1.display_name AS p1_display_name,
    p1.game_name AS p1_game_name,
    p1.tonamel_entry_name AS p1_tonamel_entry_name,

    p2.display_name AS p2_display_name,
    p2.game_name AS p2_game_name,
    p2.tonamel_entry_name AS p2_tonamel_entry_name,

    w.display_name AS winner_display_name,
    w.game_name AS winner_game_name,
    w.tonamel_entry_name AS winner_tonamel_entry_name

  FROM tournament_matches m
  LEFT JOIN tournament_players p1
    ON p1.id = m.player1_id
  LEFT JOIN tournament_players p2
    ON p2.id = m.player2_id
  LEFT JOIN tournament_players w
    ON w.id = m.winner_player_id
  WHERE m.tournament_id = :tournament_id
  ORDER BY m.match_no ASC, m.id ASC
");

    $stmtMatches->execute([
    ':tournament_id' => $tournamentId,
    ]);

    $matchRows         = $stmtMatches->fetchAll(PDO::FETCH_ASSOC);
    $stmtLatestMatches = $pdo->prepare("
  SELECT
    m.id,
    m.round_name,
    m.match_code,
    m.player1_id,
    m.player2_id,
    m.player1_score,
    m.player2_score,
    m.winner_player_id,
    m.status,
    m.note,
    m.updated_at,

    p1.display_name AS p1_display_name,
    p1.game_name AS p1_game_name,
    p1.tonamel_entry_name AS p1_tonamel_entry_name,

    p2.display_name AS p2_display_name,
    p2.game_name AS p2_game_name,
    p2.tonamel_entry_name AS p2_tonamel_entry_name,

    w.display_name AS winner_display_name,
    w.game_name AS winner_game_name,
    w.tonamel_entry_name AS winner_tonamel_entry_name

  FROM tournament_matches m
  LEFT JOIN tournament_players p1
    ON p1.id = m.player1_id
  LEFT JOIN tournament_players p2
    ON p2.id = m.player2_id
  LEFT JOIN tournament_players w
    ON w.id = m.winner_player_id
  WHERE m.tournament_id = :tournament_id
    AND m.status IN ('confirmed', 'finished')
    AND m.winner_player_id IS NOT NULL
  ORDER BY m.updated_at DESC, m.id DESC
  LIMIT 3
");

    $stmtLatestMatches->execute([
    ':tournament_id' => $tournamentId,
    ]);

    $latestMatchRows = $stmtLatestMatches->fetchAll(PDO::FETCH_ASSOC);

    $stmtUpcomingMatches = $pdo->prepare("
  SELECT
    m.*,

    p1.seed_no AS p1_seed_no_real,
    p1.display_name AS p1_display_name,
    p1.game_name AS p1_game_name,
    p1.tonamel_entry_name AS p1_tonamel_entry_name,
    p1.tonamel_user_name AS p1_tonamel_user_name,
    p1.tonamel_icon_url AS p1_icon,

    p2.seed_no AS p2_seed_no_real,
    p2.display_name AS p2_display_name,
    p2.game_name AS p2_game_name,
    p2.tonamel_entry_name AS p2_tonamel_entry_name,
    p2.tonamel_user_name AS p2_tonamel_user_name,
    p2.tonamel_icon_url AS p2_icon

  FROM tournament_matches m
  LEFT JOIN tournament_players p1 ON p1.id = m.player1_id
  LEFT JOIN tournament_players p2 ON p2.id = m.player2_id
  WHERE m.tournament_id = :tournament_id
    AND m.scheduled_at IS NOT NULL
    AND m.status NOT IN ('confirmed', 'finished')
    AND m.winner_player_id IS NULL
    AND m.player1_id IS NOT NULL
    AND m.player2_id IS NOT NULL
  ORDER BY
    m.scheduled_at ASC,
    m.id ASC
  LIMIT 5
");
    $stmtUpcomingMatches->execute([
    ':tournament_id' => $tournamentId,
    ]);
    $upcomingMatchRows = $stmtUpcomingMatches->fetchAll(PDO::FETCH_ASSOC);
    function cupLatestPlayerName(array $row, string $side): string
    {
    if ($side === 'p1') {
        return $row['p1_display_name']
            ?: ($row['p1_game_name'] ?: ($row['p1_tonamel_entry_name'] ?: 'P1'));
    }

    if ($side === 'p2') {
        return $row['p2_display_name']
            ?: ($row['p2_game_name'] ?: ($row['p2_tonamel_entry_name'] ?: 'P2'));
    }

    return $row['winner_display_name']
        ?: ($row['winner_game_name'] ?: ($row['winner_tonamel_entry_name'] ?: '勝者'));
    }

    function cupLatestIsForfeit(array $row): bool
    {
    $note = (string) ($row['note'] ?? '');

    if (mb_strpos($note, '棄賽') !== false) {
        return true;
    }

    return
    (int) ($row['player1_score'] ?? 0) === 0 &&
    (int) ($row['player2_score'] ?? 0) === 0 &&
    (int) ($row['winner_player_id'] ?? 0) > 0;
    }
    function cupPlayerNameFromMatch(array $row, string $side): string
    {
    $sourceLabel = function (?string $matchCode): string {
        $matchCode = trim((string) $matchCode);

        if ($matchCode === '') {
            return '待定';
        }

        if (preg_match('/^R1-(\d+)$/', $matchCode, $m)) {
            return '#1-' . $m[1] . '勝者';
        }

        if (preg_match('/^R2-(\d+)$/', $matchCode, $m)) {
            return '#2-' . $m[1] . '勝者';
        }

        if (preg_match('/^R3-(\d+)$/', $matchCode, $m)) {
            return '#3-' . $m[1] . '勝者';
        }

        if (preg_match('/^SF-(\d+)$/', $matchCode, $m)) {
            return '準決賽' . $m[1] . '勝者';
        }

        if ($matchCode === 'FINAL') {
            return '決賽勝者';
        }

        return $matchCode . '勝者';
    };

    if ($side === 'p1') {
        if (! empty($row['p1_display_name'])) {
            return $row['p1_display_name'];
        }
        if (! empty($row['p1_game_name'])) {
            return $row['p1_game_name'];
        }
        if (! empty($row['p1_tonamel_entry_name'])) {
            return $row['p1_tonamel_entry_name'];
        }
        if (! empty($row['player1_seed_no'])) {
            return 'Seed ' . (int) $row['player1_seed_no'];
        }

        return $sourceLabel($row['source_match1_code'] ?? '');
    }

    if (! empty($row['p2_display_name'])) {
        return $row['p2_display_name'];
    }
    if (! empty($row['p2_game_name'])) {
        return $row['p2_game_name'];
    }
    if (! empty($row['p2_tonamel_entry_name'])) {
        return $row['p2_tonamel_entry_name'];
    }
    if (! empty($row['player2_seed_no'])) {
        return 'Seed ' . (int) $row['player2_seed_no'];
    }

    return $sourceLabel($row['source_match2_code'] ?? '');
    }

    foreach ($matchRows as $row) {
    $rawRoundKey = (string) ($row['round_key'] ?: $row['round_name']);
    $roundKey    = $roundKeyMap[$rawRoundKey] ?? null;

    if ($roundKey === null || ! isset($rounds[$roundKey])) {
        continue;
    }

    $winner = null;
    if (! empty($row['winner_player_id'])) {
        if ((int) $row['winner_player_id'] === (int) $row['player1_id']) {
            $winner = 'p1';
        } elseif ((int) $row['winner_player_id'] === (int) $row['player2_id']) {
            $winner = 'p2';
        }
    }

    $rounds[$roundKey]['matches'][] = [
        'id'     => (int) $row['id'],
        'no'     => (string) $row['match_code'],
        'p1'     => cupPlayerNameFromMatch($row, 'p1'),
        'p2'     => cupPlayerNameFromMatch($row, 'p2'),
        's1'     => $row['player1_score'] === null ? null : (int) $row['player1_score'],
        's2'     => $row['player2_score'] === null ? null : (int) $row['player2_score'],
        'winner' => $winner,
        'status' => (string) $row['status'],
    ];
    }

    $matchCount = count($matchRows);

    function cupStatusLabel($status)
    {
    switch ($status) {
        case 'waiting':
            return ['class' => 'status-waiting', 'text' => '等待比賽'];
        case 'playing':
            return ['class' => 'status-signup', 'text' => '比賽中'];
        case 'reported':
            return ['class' => 'status-signup', 'text' => '等待審核'];
        case 'confirmed':
        case 'finished':
            return ['class' => 'status-finished', 'text' => '已確認'];
        case 'dispute':
            return ['class' => 'status-dispute', 'text' => '爭議中'];
        case 'pending':
        default:
            return ['class' => 'status-waiting', 'text' => '未開始'];
    }
    }
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="ul-cup-page">

      <!-- Hero -->
      <div class="ul-cup-hero">
        <div class="ul-cup-hero-inner">
          <div class="ul-cup-kicker">🏟️ ULGG Tournament</div>
          <h1 class="ul-cup-title">Unlight：Revive非官方 ULGG 杯</h1>
          <div class="ul-cup-subtitle">
            32 人單淘汰賽｜COST 74｜三副牌組征服制｜雙方互 Ban 一副牌組。<br>
            本頁為觀眾用即時戰況追蹤頁，賽果以主辦方審核後資訊為準。
          </div>

          <div class="ul-cup-links">
            <a class="ul-cup-btn ul-cup-btn-primary" href="https://tonamel.com/competition/t4iFb" target="_blank" rel="noopener">
              Tonamel 官方頁
            </a>
            <a class="ul-cup-btn" href="https://discord.com/invite/E4jbWgExbp" target="_blank" rel="noopener">
              Discord 群組
            </a>
            <a class="ul-cup-btn" href="#cup-bracket">
              查看賽程表
            </a>
            <a class="ul-cup-btn" href="#cup-rules">
              規則摘要
            </a>
          </div>

          <div class="ul-cup-report-links">
            <div class="ul-cup-report-title">賽事戰報</div>

            <div class="ul-cup-report-list">
              <a
                class="ul-cup-report-card ul-cup-report-card-live"
                href="/pages/tournament/report.php?tid=1&issue=live">
                <span class="ul-cup-report-badge">Live Meta</span>
                <span class="ul-cup-report-main">即時 Meta 統計</span>
                <span class="ul-cup-report-sub">實戰角色勝率 / BAN 熱度 / 牌組表現</span>
              </a>

              <a
  class="ul-cup-report-card ul-cup-report-card-final"
  href="/pages/tournament/report.php?tid=1&issue=final">
  <span class="ul-cup-report-badge">FINAL REPORT</span>
  <span class="ul-cup-report-main">第一屆 UL.GG 杯收官報導</span>
  <span class="ul-cup-report-sub">
    十六夜蝶登頂奪冠／決賽戰報／冠軍之路
  </span>
</a>

              <a
                class="ul-cup-report-card ul-cup-report-card-new"
                href="/pages/tournament/report.php?tid=1&issue=top8">
                <span class="ul-cup-report-badge">TOP 8</span>
                <span class="ul-cup-report-main">八強戰前狀態觀察</span>
                <span class="ul-cup-report-sub">實戰戰績 / 三副牌組 / 四場對戰焦點</span>
              </a>

              <a
                class="ul-cup-report-card"
                href="/pages/tournament/report.php?tid=1&issue=deck_open">
                <span class="ul-cup-report-badge">Deck Reveal</span>
                <span class="ul-cup-report-main">牌組公開特輯</span>
                <span class="ul-cup-report-sub">提交牌組環境觀察 / 首輪焦點戰</span>
              </a>

              <a
                class="ul-cup-report-card"
                href="/pages/tournament/report.php?tid=1&issue=pre">
                <span class="ul-cup-report-badge">Preview</span>
                <span class="ul-cup-report-main">戰前情報</span>
                <span class="ul-cup-report-sub">COST 74 Meta Analysis</span>
              </a>
            </div>
          </div>
        </div>
      </div>

      <section class="ul-cup-latest-section" id="cup-latest-section">
        <div class="ul-cup-latest-head">
          <div>
            <h2 class="ul-cup-latest-title">⏰ 預計開賽</h2>
            <div class="ul-cup-latest-desc">
              顯示已排定時間、尚未確認賽果的場次。
            </div>
          </div>
          <?php if (! empty($_SESSION['user_id']) || ! empty($_SESSION['username'])): ?>
            <button
              type="button"
              class="ul-cup-push-btn"
              id="ulggPushSubscribeBtn">
              🔔 訂閱賽事消息推播
            </button>
          <?php endif; ?>
        </div>

        <?php if (empty($upcomingMatchRows)): ?>
          <div class="ul-cup-latest-empty">
            目前尚無已排定時間的待賽場次。
          </div>
        <?php else: ?>
          <div class="ul-cup-upcoming-list">
            <?php foreach ($upcomingMatchRows as $upcoming): ?>
              <?php
                  $p1Name = cupLatestPlayerName($upcoming, 'p1');
                  $p2Name = cupLatestPlayerName($upcoming, 'p2');

                  $scheduledText = ! empty($upcoming['scheduled_at'])
                      ? date('m/d H:i', strtotime($upcoming['scheduled_at']))
                      : '-';

                  $scheduledTs = ! empty($upcoming['scheduled_at'])
                      ? strtotime($upcoming['scheduled_at'])
                      : 0;

                  $nowTs         = time();
                  $isSoon        = $scheduledTs > 0 && $scheduledTs >= $nowTs && ($scheduledTs - $nowTs) <= 3600;
                  $isLiveLike    = $scheduledTs > 0 && abs($nowTs - $scheduledTs) <= 1800;
                  $isPastWaiting = $scheduledTs > 0 && $scheduledTs < $nowTs && ! $isLiveLike;

                  $badgeText = '已排定';
                  if ($isSoon) {
                      $badgeText = '即將開賽';
                  } elseif ($isLiveLike) {
                      $badgeText = '可能進行中';
                  } elseif ($isPastWaiting) {
                      $badgeText = '待回報';
                  }

                  $upcomingUrl = '/pages/tournament/ulgg_cup_match.php?match=' . urlencode((string) $upcoming['match_code']);
              ?>

              <a class="ul-cup-upcoming-card <?php echo $isSoon ? 'is-soon' : '' ?> <?php echo $isLiveLike ? 'is-live-like' : '' ?>" href="<?php echo h($upcomingUrl) ?>">
                <div class="ul-cup-latest-top">
                  <span class="ul-cup-upcoming-badge">
                    <?php echo h($badgeText) ?>
                  </span>

                  <span class="ul-cup-latest-time">
                    <?php echo h($scheduledText) ?>
                  </span>
                </div>

                <div class="ul-cup-latest-match">
                  <?php echo h($upcoming['round_name']) ?>｜<?php echo h($upcoming['match_code']) ?>
                </div>

                <div class="ul-cup-upcoming-vs">
                  <span><?php echo h($p1Name) ?></span>
                  <strong>VS</strong>
                  <span><?php echo h($p2Name) ?></span>
                </div>

                <div class="ul-cup-latest-note">
                  預計 <?php echo h($scheduledText) ?> 開賽，點擊查看場次與房間資訊。
                </div>

                <div class="ul-cup-latest-more">
                  查看場次 ›
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
      <!-- Latest Results -->
      <section class="ul-cup-latest-section" id="cup-latest-results-section">
        <div class="ul-cup-latest-head">
          <div>
            <h2 class="ul-cup-latest-title">最新賽況</h2>
            <div class="ul-cup-latest-desc">
              顯示最近三場已確認賽果，點擊可查看該場詳細資訊。
            </div>
          </div>


        </div>

        <?php if (empty($latestMatchRows)): ?>
          <div class="ul-cup-latest-empty" id="cup-latest-results-empty">
            目前尚未有已確認賽果。
          </div>
        <?php else: ?>
          <div
            class="ul-cup-latest-list"
            id="cup-latest-results-list"
            data-latest-key="<?php echo h(sha1(json_encode($latestMatchRows, JSON_UNESCAPED_UNICODE))) ?>">
            <?php foreach ($latestMatchRows as $latest): ?>
              <?php
                  $p1Name     = cupLatestPlayerName($latest, 'p1');
                  $p2Name     = cupLatestPlayerName($latest, 'p2');
                  $winnerName = cupLatestPlayerName($latest, 'winner');

                  $p1Score = (int) ($latest['player1_score'] ?? 0);
                  $p2Score = (int) ($latest['player2_score'] ?? 0);

                  $winnerId   = (int) ($latest['winner_player_id'] ?? 0);
                  $p1IsWinner = $winnerId > 0 && $winnerId === (int) ($latest['player1_id'] ?? 0);
                  $p2IsWinner = $winnerId > 0 && $winnerId === (int) ($latest['player2_id'] ?? 0);

                  $isForfeit = cupLatestIsForfeit($latest);
                  $scoreText = $p1Score . ' - ' . $p2Score;

                  $createdText = ! empty($latest['updated_at'])
                      ? date('m/d H:i', strtotime($latest['updated_at']))
                      : '-';

                  $latestUrl = '/pages/tournament/ulgg_cup_match.php?match=' . urlencode((string) $latest['match_code']);
              ?>

              <a class="ul-cup-latest-card" href="<?php echo h($latestUrl) ?>">
                <div class="ul-cup-latest-top">
                  <span class="ul-cup-latest-badge <?php echo $isForfeit ? 'forfeit' : '' ?>">
                    <?php echo $isForfeit ? '棄賽晉級' : '賽果確認' ?>
                  </span>

                  <span class="ul-cup-latest-time">
                    <?php echo h($createdText) ?>
                  </span>
                </div>

                <div class="ul-cup-latest-match">
                  <?php echo h($latest['round_name']) ?>｜<?php echo h($latest['match_code']) ?>
                </div>

                <div class="ul-cup-latest-scoreline">
                  <span class="ul-cup-latest-player <?php echo $p1IsWinner ? 'is-winner' : '' ?>">
                    <?php echo $p1IsWinner ? '🏆 ' : '' ?><?php echo h($p1Name) ?>
                  </span>

                  <span class="ul-cup-latest-score">
                    <?php echo h($scoreText) ?>
                  </span>

                  <span class="ul-cup-latest-player <?php echo $p2IsWinner ? 'is-winner' : '' ?>" style="text-align:right;">
                    <?php echo $p2IsWinner ? '🏆 ' : '' ?><?php echo h($p2Name) ?>
                  </span>
                </div>

                <div class="ul-cup-latest-note">
                  <?php if ($isForfeit): ?>
                    <?php echo h($p1IsWinner ? $p2Name : $p1Name) ?> 棄賽，<?php echo h($winnerName) ?> 晉級。
                  <?php else: ?>
                    <?php echo h($winnerName) ?> 以 <?php echo h($scoreText) ?> 晉級。
                  <?php endif; ?>
                </div>

                <div class="ul-cup-latest-more">
                  查看場次 ›
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>


      <!-- Timeline -->
      <div class="ul-cup-section">
        <div class="ul-cup-section-head">
          <div>
            <h2 class="ul-cup-section-title">賽事時程</h2>
            <div class="ul-cup-section-desc">依主辦公告時程整理，若有變更以主辦公告為準。</div>
          </div>
          <span class="ul-cup-status-pill status-signup">
            ● 目前：<?php echo htmlspecialchars($currentStageTitle) ?>
          </span>
        </div>

        <div class="ul-cup-timeline">
          <?php foreach ($timeline as $item): ?>
            <div class="timeline-item <?php echo ! empty($item['current']) ? 'is-current' : '' ?> is-<?php echo htmlspecialchars($item['status']) ?>">
              <div class="timeline-date"><?php echo htmlspecialchars($item['date']) ?></div>
              <div class="timeline-title"><?php echo htmlspecialchars($item['title']) ?></div>
              <div class="timeline-note"><?php echo htmlspecialchars($item['note']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>



      <!-- Bracket -->
      <section class="ul-cup-section" id="cup-bracket">
        <div class="ul-cup-section-head">
          <div>
            <div class="ul-cup-eyebrow">Tournament Bracket</div>
            <h2>賽事對戰表</h2>
            <p>依 Tonamel 報名順序產生種子序。</p>
          </div>

          <div class="ul-cup-head-actions">
            <?php if ($isAdmin): ?>
              <a
                href="/pages/tournament/admin_init_matches.php"
                class="ul-cup-admin-btn">
                <span class="ul-cup-admin-btn-icon">⚙</span>
                <span>初始化賽程</span>
              </a>
            <?php endif; ?>

            <?php if ($matchCount > 0): ?>
              <span class="ul-cup-status-pill status-waiting">
                已建立 <?php echo (int) $matchCount ?> 場賽程
              </span>
            <?php else: ?>
              <span class="ul-cup-status-pill status-dispute">
                尚未建立賽程
              </span>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($matchCount <= 0): ?>
          <div style="padding:16px;border-radius:12px;background:rgba(255,255,255,.05);color:#cfd6df;">
            尚未建立賽程。請管理員先執行初始化賽程。
          </div>
        <?php else: ?>
          <div class="ul-cup-bracket-wrap">
            <div class="ul-cup-bracket">
              <?php foreach ($rounds as $roundKey => $round): ?>
                <?php
                    $roundMatchCount    = count($round['matches']);
                    $roundFinishedCount = 0;

                    foreach ($round['matches'] as $roundMatchCheck) {
                        if (in_array((string) ($roundMatchCheck['status'] ?? ''), ['confirmed', 'finished'], true)) {
                            $roundFinishedCount++;
                        }
                    }

                    $roundFinished = $roundMatchCount > 0 && $roundFinishedCount >= $roundMatchCount;
                ?>

                <details class="bracket-round bracket-round-collapse" <?php echo $roundFinished ? '' : 'open' ?>>
                  <summary class="bracket-round-summary">
                    <div class="bracket-round-title">
                      <?php echo htmlspecialchars($round['title']) ?>
                      <small style="color:#9faabb;font-weight:400;">
                        <?php echo htmlspecialchars($round['deadline']) ?>
                      </small>

                      <?php if ($roundFinished): ?>
                        <span class="round-finished-badge">已結束</span>
                      <?php endif; ?>
                    </div>

                    <span class="round-collapse-icon">▼</span>
                  </summary>

                  <div class="bracket-round-body">
                    <?php foreach ($round['matches'] as $match): ?>
                      <?php
                          $statusInfo = cupStatusLabel($match['status']);
                          $p1Winner   = ($match['winner'] === 'p1');
                          $p2Winner   = ($match['winner'] === 'p2');
                      ?>

                      <a class="match-card"
                        href="/pages/tournament/ulgg_cup_match.php?match=<?php echo urlencode($match['no']) ?>">
                        <div class="match-card-head">
                          <span class="match-no"><?php echo htmlspecialchars($match['no']) ?></span>
                          <span class="match-deadline">截止 <?php echo htmlspecialchars($round['deadline']) ?></span>
                        </div>

                        <div class="match-body">
                          <div class="match-player <?php echo $p1Winner ? 'is-winner' : '' ?>">
                            <div class="player-name">
                              <span class="player-seed">P1</span>
                              <span class="<?php echo ! empty($match['p1_is_source']) ? 'match-source-player' : '' ?>">
                                <?php echo htmlspecialchars($match['p1']) ?>
                              </span>
                            </div>
                            <div class="player-score">
                              <?php echo $match['s1'] === null ? '-' : (int) $match['s1'] ?>
                            </div>
                          </div>

                          <div class="match-player <?php echo $p2Winner ? 'is-winner' : '' ?>">
                            <div class="player-name">
                              <span class="player-seed">P2</span>
                              <span class="<?php echo ! empty($match['p2_is_source']) ? 'match-source-player' : '' ?>">
                                <?php echo htmlspecialchars($match['p2']) ?>
                              </span>
                            </div>
                            <div class="player-score">
                              <?php echo $match['s2'] === null ? '-' : (int) $match['s2'] ?>
                            </div>
                          </div>

                          <div class="match-foot">
                            <span class="ul-cup-status-pill <?php echo htmlspecialchars($statusInfo['class']) ?>">
                              <?php echo htmlspecialchars($statusInfo['text']) ?>
                            </span>
                            <span class="match-more">詳細 ›</span>
                          </div>
                        </div>
                      </a>
                    <?php endforeach; ?>
                  </div>
                </details>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </section>

      <!-- player list -->
      <section class="ul-cup-section ulgg-players-section">
        <div class="ulgg-section-head">
          <div>
            <h2>參賽者名單</h2>
            <p>依 Tonamel 報名順序顯示，名單以主辦審核與實際賽程安排為準。</p>
            <div class="cup-deck-hint">
              → 點擊選手名稱獲取牌組資訊
            </div>
          </div>

          <div class="ulgg-player-count">
            <?php echo h(count($tournamentPlayers)) ?> / 32
          </div>
        </div>



        <?php if ($importMessage !== ''): ?>
          <div class="ulgg-alert <?php echo $importOk ? 'success' : 'error' ?>">
            <?php echo h($importMessage) ?>
          </div>
        <?php endif; ?>

        <?php
            $csvImportDeadline = new DateTime('2026-07-01 00:00:00', new DateTimeZone('Asia/Taipei'));
            $now               = new DateTime('now', new DateTimeZone('Asia/Taipei'));
            $csvImportEnabled  = $now < $csvImportDeadline;
        if ($isAdmin && $csvImportEnabled): ?>
          <form class="ulgg-import-form"
            method="post"
            enctype="multipart/form-data">
            <input type="hidden" name="action" value="import_players_csv">

            <div class="ulgg-import-head">
              <div>
                <div class="ulgg-import-title">管理員 CSV 匯入</div>
                <div class="ulgg-import-subtitle">
                  Tonamel EntryInfo 名單同步｜僅匯入「報名完成」狀態
                </div>
              </div>
            </div>

            <div class="ulgg-import-row">
              <label class="ulgg-file-picker">
                <input
                  class="ulgg-file-input"
                  type="file"
                  name="players_csv"
                  accept=".csv,text/csv"
                  required
                  onchange="this.closest('.ulgg-file-picker').querySelector('.ulgg-file-name').textContent = this.files[0] ? this.files[0].name : '尚未選擇檔案';">

                <span class="ulgg-file-icon">📄</span>

                <span class="ulgg-file-text">
                  <strong>選擇 CSV 檔案</strong>
                  <em class="ulgg-file-name">尚未選擇檔案</em>
                </span>
              </label>

              <button
                class="ulgg-import-btn"
                type="submit"
                onclick="return confirm('確定要匯入參賽者 CSV？同一份檔案不可重複匯入，但相同報名順序會更新資料。');">
                匯入參賽者名單
              </button>
            </div>
            <label class="ulgg-force-import">
              <input type="checkbox" name="force_import" value="1">
              <span>強制重新匯入，即使 CSV 已匯入過</span>
            </label>

            <div class="ulgg-import-note">
              支援 Tonamel EntryInfo CSV。系統會依「報名順序」寫入 seed_no，並避免同一份 CSV 重複匯入。
            </div>
          </form>
        <?php endif; ?>

        <?php if (! $tournamentPlayers): ?>
          <div class="ulgg-empty">
            目前尚未匯入公開參賽者名單。
          </div>
        <?php else: ?>
          <div class="ulgg-player-grid">
            <?php foreach ($tournamentPlayers as $p): ?>
              <a class="ulgg-player-card ulgg-player-card-link <?php echo ! empty($p['is_eliminated']) ? 'is-eliminated' : '' ?>"
                href="/pages/tournament/report.php?tid=1&issue=pre#player-seed-<?php echo (int) $p['seed_no'] ?>">
                <div class="ulgg-player-seed">
                  #<?php echo h($p['seed_no']) ?>
                </div>

                <div class="ulgg-player-main">
                  <div class="ulgg-player-name">
                    <?php echo h($p['display_name']) ?>
                  </div>
                  <?php if (! empty($p['is_eliminated'])): ?>
                    <div class="ulgg-player-status eliminated">已淘汰</div>
                  <?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <!-- Info Cards -->
      <div class="ul-cup-info-grid">

        <?php foreach ($eventCards as $card): ?>
          <div class="ul-cup-info-card">
            <div class="ul-cup-info-label"><?php echo htmlspecialchars($card['label']) ?></div>
            <div class="ul-cup-info-value"><?php echo htmlspecialchars($card['value']) ?></div>
            <div class="ul-cup-info-note"><?php echo htmlspecialchars($card['note']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>



      <!-- Rules -->
      <div class="ul-cup-section" id="cup-rules">
        <div class="ul-cup-section-head">
          <div>
            <h2 class="ul-cup-section-title">規則摘要</h2>
            <div class="ul-cup-section-desc">此處只放觀眾需要快速理解的版本，完整規則請看 Tonamel。</div>
          </div>
        </div>

        <div class="ul-cup-rules-grid">

          <div class="ul-cup-info-card">
            <div class="ul-cup-info-label">牌組規則</div>
            <div class="ul-cup-info-value" style="font-size:16px;">三副牌組，COST 74</div>
            <div class="ul-cup-info-note">
              選手提交 A/B/C 三副牌組，三副牌組角色不可重複。比賽時角色與等級需與提交資料一致。
            </div>
          </div>

          <div class="ul-cup-info-card">
            <div class="ul-cup-info-label">對戰方式</div>
            <div class="ul-cup-info-value" style="font-size:16px;">征服制 Conquest</div>
            <div class="ul-cup-info-note">
              雙方互 Ban 對手一副牌組，剩下兩副牌組皆須取得勝利才可晉級。
            </div>
          </div>

          <div class="ul-cup-info-card">
            <div class="ul-cup-info-label">勝方回報</div>
            <div class="ul-cup-info-value" style="font-size:16px;">截圖回報，主辦審核</div>
            <div class="ul-cup-info-note">
              需由勝方截圖回報並經管理員確認。
            </div>
          </div>

          <div class="ul-cup-info-card">
            <div class="ul-cup-info-label">八強後</div>
            <div class="ul-cup-info-value" style="font-size:16px;">主辦安排直播</div>
            <div class="ul-cup-info-note">
              八強、準決賽、決賽將由主辦安排時間，並邀請雙方選手進行直播。
            </div>
          </div>

        </div>
      </div>

    </div>
  </section>
</div>
<!-- /.content-wrapper -->

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const latestList = document.getElementById('cup-latest-results-list');
    const latestSection = document.getElementById('cup-latest-results-section');

    if (!latestList || !latestSection) {
      return;
    }

    const apiUrl = '/pages/tournament/api_latest_matches.php?tid=1';
    const refreshMs = 60000;

    function escapeHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function renderLatestCard(item) {
      const badgeClass = item.is_forfeit ? 'forfeit' : '';
      const p1WinnerClass = item.p1_is_winner ? 'is-winner' : '';
      const p2WinnerClass = item.p2_is_winner ? 'is-winner' : '';

      const p1Trophy = item.p1_is_winner ? '🏆 ' : '';
      const p2Trophy = item.p2_is_winner ? '🏆 ' : '';

      return `
  <a class="ul-cup-latest-card" href="${escapeHtml(item.url)}">
    <div class="ul-cup-latest-top">
      <span class="ul-cup-latest-badge ${badgeClass}">
        ${escapeHtml(item.badge)}
      </span>

      <span class="ul-cup-latest-time">
        ${escapeHtml(item.time_text)}
      </span>
    </div>

    <div class="ul-cup-latest-match">
      ${escapeHtml(item.round_name)}｜${escapeHtml(item.match_code)}
    </div>

    <div class="ul-cup-latest-scoreline">
      <span class="ul-cup-latest-player ${p1WinnerClass}">
        ${p1Trophy}${escapeHtml(item.p1_name)}
      </span>

      <span class="ul-cup-latest-score">
        ${escapeHtml(item.score_text)}
      </span>

      <span class="ul-cup-latest-player ${p2WinnerClass}" style="text-align:right;">
        ${p2Trophy}${escapeHtml(item.p2_name)}
      </span>
    </div>

    <div class="ul-cup-latest-note">
      ${escapeHtml(item.message)}
    </div>

    <div class="ul-cup-latest-more">
      查看場次 ›
    </div>
  </a>
  `;
    }

    function renderLatestResults(items) {
      if (!items || items.length === 0) {
        latestList.innerHTML = `
  <div class="ul-cup-latest-empty">
    目前尚未有已確認賽果。
  </div>
  `;
        return;
      }

      latestList.innerHTML = items.map(renderLatestCard).join('');
    }

    async function refreshLatestResults() {
      if (document.hidden) {
        return;
      }

      try {
        const response = await fetch(apiUrl, {
          method: 'GET',
          cache: 'no-store',
          headers: {
            'Accept': 'application/json'
          }
        });

        if (!response.ok) {
          return;
        }

        const data = await response.json();

        if (!data || !data.ok) {
          return;
        }

        const oldKey = latestList.dataset.latestKey || '';
        const newKey = data.latest_key || '';

        if (newKey !== '' && newKey === oldKey) {
          return;
        }

        latestList.dataset.latestKey = newKey;
        renderLatestResults(data.items || []);
      } catch (error) {
        // 避免前台因 AJAX 失敗出錯，這裡不顯示錯誤。
      }
    }

    setInterval(refreshLatestResults, refreshMs);

    document.addEventListener('visibilitychange', function() {
      if (!document.hidden) {
        refreshLatestResults();
      }
    });
  });
</script>
<script>
  document.addEventListener('DOMContentLoaded', function() {
    const pushBtn = document.getElementById('ulggPushSubscribeBtn');

    if (!pushBtn) {
      return;
    }

    const tournamentId = 1;

    function urlBase64ToUint8Array(base64String) {
      const padding = '='.repeat((4 - base64String.length % 4) % 4);
      const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');

      const rawData = window.atob(base64);
      const outputArray = new Uint8Array(rawData.length);

      for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
      }

      return outputArray;
    }

    function applicationServerKeyMatches(subscription, publicKey) {
      const currentKey = subscription?.options?.applicationServerKey;

      if (!currentKey) {
        return false;
      }

      const expectedKey = urlBase64ToUint8Array(publicKey);
      const actualKey = new Uint8Array(currentKey);

      if (actualKey.length !== expectedKey.length) {
        return false;
      }

      for (let i = 0; i < actualKey.length; i++) {
        if (actualKey[i] !== expectedKey[i]) {
          return false;
        }
      }

      return true;
    }

    async function ensureCurrentVapidSubscription(registration) {
      const publicKey = await getPublicKey();
      let subscription = await registration.pushManager.getSubscription();

      if (subscription && applicationServerKeyMatches(subscription, publicKey)) {
        return subscription;
      }

      if (subscription) {
        const oldSubscription = subscription;
        const removed = await subscription.unsubscribe();

        if (!removed) {
          throw new Error('無法移除舊的推播訂閱。');
        }

        try {
          await saveSubscription(oldSubscription.toJSON(), 'unsubscribe');
        } catch (error) {
          console.warn('Failed to deactivate old tournament push subscription:', error);
        }

        subscription = null;
      }

      if (Notification.permission !== 'granted') {
        return null;
      }

      return registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey)
      });
    }

    async function getPublicKey() {
      const response = await fetch('/pages/tournament/api_push_public_key.php', {
        cache: 'no-store',
        headers: {
          'Accept': 'application/json'
        }
      });

      const data = await response.json();

      if (!data.ok || !data.publicKey) {
        throw new Error('無法取得推播 public key');
      }

      return data.publicKey;
    }

    async function saveSubscription(subscription, action) {
      const response = await fetch('/pages/tournament/api_push_subscribe.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          action: action || 'subscribe',
          tournament_id: tournamentId,
          subscription: subscription
        })
      });

      const data = await response.json();

      if (!data.ok) {
        throw new Error(data.message || '訂閱失敗');
      }

      return data;
    }

    async function initPushButton() {
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        pushBtn.textContent = '此瀏覽器不支援推播';
        pushBtn.classList.add('is-disabled');
        pushBtn.disabled = true;
        return;
      }

      const registration = await navigator.serviceWorker.register('/service-worker.js');
      const subscription = await ensureCurrentVapidSubscription(registration);

      if (!subscription) {
        pushBtn.textContent = '🔔 訂閱賽事消息推播';
        pushBtn.classList.remove('is-subscribed');
        return;
      }

      await saveSubscription(subscription.toJSON(), 'subscribe');
      pushBtn.textContent = '✅ 已訂閱賽事推播';
      pushBtn.classList.add('is-subscribed');
    }

    pushBtn.addEventListener('click', async function() {
      if (pushBtn.disabled) {
        return;
      }

      try {
        pushBtn.disabled = true;
        pushBtn.textContent = '處理中...';

        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
          throw new Error('此瀏覽器不支援 Web Push。');
        }

        const permission = await Notification.requestPermission();

        if (permission !== 'granted') {
          throw new Error('你尚未允許通知權限。');
        }

        const registration = await navigator.serviceWorker.register('/service-worker.js');
        let subscription = await ensureCurrentVapidSubscription(registration);

        if (!subscription) {
          throw new Error('無法建立推播訂閱，請確認瀏覽器通知權限。');
        }

        await saveSubscription(subscription.toJSON(), 'subscribe');

        pushBtn.textContent = '✅ 已訂閱賽事推播';
        pushBtn.classList.add('is-subscribed');
        alert('已訂閱 ULGG 杯賽事消息推播。');
      } catch (error) {
        pushBtn.textContent = '🔔 訂閱賽事消息推播';
        alert(error.message || '訂閱推播失敗。');
      } finally {
        pushBtn.disabled = false;
      }
    });

    initPushButton().catch(function() {
      pushBtn.textContent = '🔔 訂閱賽事消息推播';
    });
  });
</script>
<?php
    // ⭐ 最後統一輸出成 pageContent 給 template/base.php
    $pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>