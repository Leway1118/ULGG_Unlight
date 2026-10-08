<?php
session_start();
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定

$seoTitle = '觀察大廳 WatchingLobby | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "queue";
$pageTitleFull = '觀察大廳 WatchingLobby | UL.GG 戰績網'; //桌機
$pageTitleText = '觀察大廳 WatchingLobby'; //手機



ob_start();  // ⭐ 開始收集本頁 HTML
?>

<style>
  /* ======================================================
   UL 主題變數
====================================================== */
  :root {
    --ul-bg: #1c1f26;
    --ul-bg-soft: #252a34;
    --ul-panel: #2e3440;
    --ul-border: rgba(180, 200, 255, 0.18);

    --ul-blue: #6fa8ff;
    --ul-red: #ff6b6b;
    --ul-green: #4dd599;
    --ul-yellow: #e6c77a;

    --ul-text: #e6eaf2;
    --ul-text-soft: #b8c0d4;

    --ul-glow-blue: rgba(111, 168, 255, 0.6);
    --ul-shadow: 0 6px 18px rgba(0, 0, 0, 0.45);
  }

  /* ======================================================
   Fight 容器
====================================================== */
  .fight-all {
    display: flex;
    flex-direction: column-reverse;
    align-items: center;
    gap: 6px;
  }

  .fight-container {
    display: flex;
    background: var(--ul-panel);
    border: 1px solid var(--ul-border);
    border-radius: 10px;
    box-shadow: var(--ul-shadow);
  }

  .team {
    display: flex;
  }

  /* 偶奇列 */
  .gray-bg {
    background: linear-gradient(180deg, #2a2f3a, #242833);
  }

  .white-bg {
    background: linear-gradient(180deg, #303643, #2a303c);
  }

  /* ======================================================
   VS Panel
====================================================== */
  .vs-panel {
    width: 105px;
    padding: 2px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.04);
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: center;
  }

  /* 上方資訊 */
  .vs-top {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-direction: column;
  }

  .region-btn {
    background: rgba(255, 255, 255, 0.06);
    border: 1px solid rgba(255, 255, 255, 0.18);
    border-radius: 6px;
    padding: 6px 8px;
    cursor: pointer;
    color: #fff;
  }

  .region-btn i,
  .region-btn span {
    font-size: 14px;
    line-height: 1;
  }

  .timestamp {
    font-size: 12px;
    color: var(--ul-text-soft);
  }

  /* 操作列 */
  /* 操作列（你原本就有） */
  .vs-actions {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-direction: column;
  }

  /* 新增：群組 */
  .vs-group {
    display: flex;
    gap: 4px;
  }

  /* 🔥 關鍵：把刪除推到最右邊 */
  .vs-danger {
    margin-left: auto;
  }

  .vs-actions .action {
    font-size: 13px;
    padding: 4px 6px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
  }

  .action.win {
    background: #4caf50;
    color: #fff;
  }

  .action.tie {
    background: #ffc107;
    color: #000;
  }

  .action.read {
    background: #607d8b;
    color: #fff;
  }

  .action.del {
    background: #f44336;
    color: #fff;
  }


  /* ======================================================
   UL 按鈕系統（扁平卡牌風）
====================================================== */
  .cost-btn,
  .wr-btn {
    background: linear-gradient(180deg, #232836, #1a1f2b);
    color: var(--ul-text);

    border: 1px solid var(--ul-border);
    border-radius: 7px;

    font-weight: 600;
    letter-spacing: 0.5px;

    box-shadow:
      inset 0 0 0 1px rgba(255, 255, 255, 0.03),
      0 2px 6px rgba(0, 0, 0, 0.6);

    transition:
      background 0.25s ease,
      box-shadow 0.25s ease,
      transform 0.2s ease;
  }

  /* Hover：微浮起 + 冷光 */
  .cost-btn:hover,
  .wr-btn:hover {
    background: linear-gradient(180deg, #2c3345, #212739);
    box-shadow:
      inset 0 0 0 1px rgba(255, 255, 255, 0.05),
      0 0 10px rgba(120, 170, 255, 0.25),
      0 4px 10px rgba(0, 0, 0, 0.7);
    transform: translateY(-1px);
  }

  /* Active：按下 */
  .cost-btn:active,
  .wr-btn:active {
    transform: translateY(0);
    box-shadow:
      inset 0 0 6px rgba(0, 0, 0, 0.7);
  }

  /* ======================================================
   狀態按鈕（UL 對戰結果）
====================================================== */

  /* 敵方勝利（冷藍） */
  .enemy-win-btn {
    background: linear-gradient(180deg, #355fd6, #243f9f);
    color: #eef3ff;

    border: 1px solid rgba(140, 170, 255, 0.45);

    box-shadow:
      inset 0 0 10px rgba(80, 120, 255, 0.35),
      0 3px 10px rgba(0, 0, 0, 0.65);
  }

  /* 玩家勝利（深紅） */
  .player-win-btn {
    background: linear-gradient(180deg, #d04a4a, #992e2e);
    color: #fff;

    border: 1px solid rgba(255, 120, 120, 0.45);

    box-shadow:
      inset 0 0 10px rgba(255, 80, 80, 0.35),
      0 3px 10px rgba(0, 0, 0, 0.65);
  }

  /* 平手（翠綠） */
  .tie-btn {
    background: linear-gradient(180deg, #2f9c6d, #1f6f4d);
    color: #eafff6;

    border: 1px solid rgba(120, 255, 200, 0.45);

    box-shadow:
      inset 0 0 10px rgba(80, 255, 180, 0.35),
      0 3px 10px rgba(0, 0, 0, 0.65);
  }

  /* 未判 / Queue（古銅金） */
  .queue-btn {
    background: linear-gradient(180deg, #b89a56, #8a713a);
    color: #fff6dd;

    border: 1px solid rgba(255, 215, 120, 0.45);

    box-shadow:
      inset 0 0 10px rgba(255, 200, 80, 0.35),
      0 3px 10px rgba(0, 0, 0, 0.65);
  }

  /* ======================================================
   Player Card
====================================================== */
  .player-card {
    width: 120px;
    padding: 8px 6px;
    background: linear-gradient(180deg,
        rgba(255, 255, 255, 0.06),
        rgba(255, 255, 255, 0.02));
    border-radius: 10px;
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 6px;
    position: relative;
  }

  /* 勝負標記 */
  .result-pill {
    font-size: 14px;
    font-weight: 700;
    padding: 4px 0;
    border-radius: 6px;
    letter-spacing: 2px;
  }

  .result-win {
    background: rgba(76, 175, 80, 0.85);
    color: #fff;
  }

  .result-lose {
    background: rgba(244, 67, 54, 0.85);
    color: #fff;
  }

  .result-tie {
    background: rgba(255, 193, 7, 0.85);
    color: #000;
  }

  .result-unknown {
    background: rgba(158, 158, 158, 0.6);
    color: #fff;
  }

  /* 玩家名稱 */
  .player-name {
    color: var(--ul-text);
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
  }

  .player-name:hover {
    color: var(--ul-blue);
    text-shadow: 0 0 6px var(--ul-glow-blue);
  }

  /* 排名 */
  .rank-badge {
    font-size: 11px;
    background: rgba(0, 123, 255, 0.2);
    color: #9fc4ff;
    padding: 2px 5px;
    border-radius: 6px;
    margin-right: 4px;
  }

  /* 黑名單 */
  .blacklist-dot {
    background: #e52626;
    color: #fff;
    font-size: 11px;
    border-radius: 50%;
    padding: 0 5px;
    margin-left: 4px;
  }

  /* BP */
  .bp-box {
    font-size: 13px;
    background: rgba(255, 255, 255, 0.08);
    border-radius: 6px;
    padding: 4px 0;
  }

  .bp-label {
    color: var(--ul-text-soft);
    font-size: 11px;
  }

  .bp-value {
    font-weight: 600;
  }


  /* ======================================================
   角色卡片
====================================================== */
  .characters {
    display: flex;
    margin: auto;
    flex-direction: row;
    align-items: flex-start;
    height: 84px;
    overflow: hidden;
    display: inline-block;
    border-radius: 5px;
  }

  .characters {
    display: flex !important;
    flex-direction: row;
    align-items: flex-start;
    height: 84px;
    overflow: hidden;
    display: inline-block;
    border-radius: 5px;
  }

  .characters>.flip-container:not(:first-child) {
    margin-left: -18px;
  }

  .flip-container {
    width: 76px;
    height: 76px;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid var(--ul-border);
    background: #111;
  }

  .flip-container:hover {
    z-index: 20;
  }

  .flip-container img {
    width: 75px;
    height: 108px;
    object-fit: cover;
    border-radius: 5px;
    transition: box-shadow 0.3s ease, transform 0.3s ease;
  }

  .flip-container:hover img {
    box-shadow: 0 0 12px var(--ul-glow-blue);
    transform: scale(1.15);
  }

  /* ======================================================
   Event 卡
====================================================== */
  .event {
    max-width: 44px;
    border-radius: 5px;
    border: 1px solid var(--ul-border);
    background: #111;
    transition: box-shadow 0.3s ease;
    margin: 2px auto;
  }

  .event:hover {
    box-shadow: 0 0 10px var(--ul-glow-blue);
  }

  .event-slot-empty {
    width: 44px;
    height: 68px;
    border-radius: 5px;
    background:
      repeating-linear-gradient(45deg,
        #333,
        #333 6px,
        #222 6px,
        #222 12px);
    margin: 2px auto;
  }

  /* ======================================================
   Counter 區（UL 排名卡）
====================================================== */
  .counter-container {
    display: inline-flex;
    /* ⭐ 寬度只包內容 */
    gap: 10px;
    padding: 8px 6px;

    background: var(--ul-bg-soft);
    border-radius: 12px;
    border: 1px solid var(--ul-border);
  }

  .counter-wrapper {
    display: flex;
    justify-content: center;
    /* ⭐ 左右置中 */
  }

  .d-character {
    background: #1f2430;
    border: 1px solid var(--ul-border);
    border-radius: 10px;
    padding: 6px;
    min-width: 90px;
    box-shadow: var(--ul-shadow);
  }

  .weapon_attr {
    color: var(--ul-text-soft);
    font-size: 13px;
  }

  /* ======================================================
   UL 勝率能量條
====================================================== */
  .ul-rate-wrap {
    margin: 12px auto;
    /* ← 關鍵 */
    width: 100%;
    max-width: 520px;
    height: 34px;

    background: linear-gradient(180deg, #1b1f2a, #151922);
    border-radius: 18px;
    border: 1px solid rgba(180, 200, 255, 0.18);
    overflow: hidden;

    box-shadow:
      inset 0 0 6px rgba(0, 0, 0, 0.6),
      0 4px 12px rgba(0, 0, 0, 0.5);
  }

  /* 內部能量條 */
  .ul-rate-bar {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;

    font-weight: bold;
    font-size: 18px;
    color: #fff;

    transition: width 0.6s ease;
    position: relative;
  }

  /* 文字 */
  .ul-rate-text {
    position: relative;
    z-index: 2;
    text-shadow: 0 0 6px rgba(0, 0, 0, 0.8);
  }

  /* ===== 顏色階層（對應 progress_color） ===== */

  /* <25% */
  .ul-rate-red {
    background: linear-gradient(90deg, #5a1e1e, #b33434);
    box-shadow: inset 0 0 10px rgba(255, 80, 80, 0.6);
  }

  /* 25–49% */
  .ul-rate-yellow {
    background: linear-gradient(90deg, #5e4c1c, #c4a73a);
    box-shadow: inset 0 0 10px rgba(255, 215, 100, 0.6);
  }

  /* 50–74% */
  .ul-rate-green {
    background: linear-gradient(90deg, #1f4f3a, #3fbf88);
    box-shadow: inset 0 0 10px rgba(90, 255, 170, 0.6);
  }

  /* ≥75% */
  .ul-rate-aqua {
    background: linear-gradient(90deg, #1e3f5a, #6fa8ff);
    box-shadow: inset 0 0 12px rgba(120, 180, 255, 0.8);
  }

  /* ======================================================
   UL 詳細按鈕（skip2-btn）
====================================================== */
  .skip2-btn {
    background: linear-gradient(180deg, #263044, #1c2333);
    color: #e6ecff;

    border: 1px solid rgba(140, 180, 255, 0.35);
    border-radius: 8px;

    padding: 6px 12px;
    font-size: 15px;
    font-weight: 600;
    letter-spacing: 0.5px;

    display: inline-flex;
    align-items: center;
    gap: 6px;

    box-shadow:
      inset 0 0 0 1px rgba(255, 255, 255, 0.04),
      0 3px 10px rgba(0, 0, 0, 0.6);

    transition:
      background 0.25s ease,
      box-shadow 0.25s ease,
      transform 0.2s ease;
  }

  /* Hover：情報啟動感 */
  .skip2-btn:hover {
    background: linear-gradient(180deg, #2f4a7a, #243a5f);
    box-shadow:
      0 0 14px rgba(120, 170, 255, 0.6),
      0 4px 14px rgba(0, 0, 0, 0.7);
    transform: translateY(-1px);
    color: #ffffff;
  }

  /* Active：按下 */
  .skip2-btn:active {
    transform: translateY(0);
    box-shadow:
      inset 0 0 8px rgba(0, 0, 0, 0.7);
  }

  /* Icon（regionIcon）微調 */
  .skip2-btn svg,
  .skip2-btn img {
    width: 16px;
    height: 16px;
    filter: drop-shadow(0 0 4px rgba(120, 170, 255, 0.6));
  }

  .costEvent {
    display: flex;
    flex-direction: column;
  }


  /* ======================================================
   Privacy Mask Slot（權限遮罩）
====================================================== */

  .empty-container {
    display: flex;
    flex-direction: row;

  }

  .empty-container.privacy-mask {
    display: flex;
    margin: auto;
  }

  .empty-container.privacy-mask>.empty-slot:not(:first-child) {
    margin-left: -18px;
  }

  .empty-slot {
    width: 76px;
    height: 76px;
    border-radius: 6px;
    border: 1px dashed rgba(140, 160, 200, 0.35);
    background:
      repeating-linear-gradient(45deg,
        #111,
        #111 6px,
        #151515 6px,
        #151515 12px);
    display: flex;
    align-items: center;
    justify-content: center;
    color: rgba(200, 200, 200, 0.45);
    font-size: 22px;
    position: relative;
    opacity: 0.65;
    transition: all 0.25s ease;
  }

  .empty-slot i {
    pointer-events: none;
    user-select: none;
  }

  .empty-slot:hover {
    opacity: 0.9;
    border-color: var(--ul-blue);
    box-shadow: 0 0 8px rgba(100, 150, 255, 0.25);
    z-index: 20;
  }

  /* Tooltip（權限提示） */
  .empty-slot::after {
    content: "此對戰組合僅限本人或管理員查看";
    position: absolute;
    bottom: -32px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(20, 20, 30, 0.95);
    color: #ccc;
    font-size: 12px;
    padding: 4px 8px;
    border-radius: 6px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease;
  }

  .empty-slot:hover::after {
    opacity: 1;
  }

  /* =========================
   Blacklist Player — Prison Style
========================= */
  .blacklist-name {
    background: linear-gradient(180deg, #2b2b2b, #0f0f0f);
    color: #ffeb3b !important;
    /* 警示黃 */
    /* padding: 3px 8px; */
    border-radius: 4px;
    font-weight: 700;
    font-size: 12px;
    letter-spacing: 0.08em;
    /* text-transform: uppercase; */

    border: 1px solid rgba(255, 235, 59, 0.6);
    box-shadow:
      inset 0 0 6px rgba(0, 0, 0, 0.8),
      0 0 6px rgba(255, 235, 59, 0.35);

    text-decoration: none;
    /* ⭐⭐⭐ 關鍵修正 ⭐⭐⭐ */
    /* display: inline-flex; */
    align-items: center;
    gap: 6px;
    white-space: nowrap;
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

  .fight-form {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    /* background: var(--ul-panel); */
    border: 1px solid var(--ul-border);
    border-radius: 10px;
    box-shadow: var(--ul-shadow);
    display: inline-flex;
    width: fit-content;
    max-width: 100%;
  }


  /* =========================
   Fight – Mobile Patch v1
========================= */


  .imagecard {
    display: flex;
    flex-direction: row;
    margin: auto;
  }


  .fight-container {
    display: flex;
    align-items: center;
    gap: 8px;
    justify-content: center;
    padding: 3px 5px;
    border-radius: 11px;
  }

  .character-img {
    height: 120px;
    border-radius: 8px;
    border: 1px solid #ccc;
    object-fit: cover;
    margin-left: -20px;
    transition: transform 0.3s ease, margin 0.3s ease, z-index 0.3s;
  }

  .character-img:first-child {
    margin-left: 0;
  }

  .character-img:hover {
    transform: scale(1.3);
    z-index: 10;
  }

  .characters .character-img:hover {
    margin-left: 0px;
  }

  .player-input {
    /* margin-top: 10px; */
    display: flex;
    flex-direction: column;
    gap: 2px;
    width: 90px;
    align-items: center;
  }

  .vs-container {
    text-align: center;
    width: 100px;
  }

  .skip-btn {
    background: rgb(181, 181, 191);
    color: white;
    border-radius: 8px;
    font-weight: bold;
    padding: 3px 14px;
    margin: 0px 2px;
  }

  .skiped-btn {
    background: rgb(124, 105, 105);
    color: white;
    border-radius: 8px;
    font-weight: bold;
    margin: 2px 2px;
  }

  .team {
    display: flex;
    align-items: center;
    /* gap: 10px; */
    padding: 6px;
    border-radius: 12px;
    box-shadow: 2px 2px 8px rgba(0, 0, 0, 0.1);
    min-width: 200px;
  }

  @media (max-width: 768px) {

    /* 整場改直向 */
    .fight-container {
      flex-direction: column;
      align-items: stretch;
      padding: 6px;
    }

    /* 左右隊伍 → 上下 */
    .team {
      width: 100%;
      flex-direction: column;
      align-items: center;
      margin-bottom: 6px;
      padding: 2px 4px;
    }

    /* VS 區塊改橫條 */
    .vs-panel {
      width: 100%;
      /* margin: 6px 0; */
      flex-direction: row;
      justify-content: space-between;
      align-items: center;
    }

    /* 玩家卡不要固定寬 */
    .player-card {
      width: 100%;
      max-width: 360px;
      padding: 4px 0px 0px 3px;
    }

    .player-card1 {
      display: flex;
      flex-direction: row;
    }

    .player-card2 {
      display: flex;
      flex-direction: row-reverse;
    }

    /* 我方 */
    /* .team:first-child {
      order: 1;
    } */

    /* VS */
    .vs-panel {
      order: 2;
    }

    /* 對手 */
    .team:last-child {
      order: 3;
    }

    .vs-actions {
      display: none;
    }

    .characters>.flip-container:not(:first-child) {
      margin-left: -18px;
    }

    .characters {
      justify-content: center;
    }

    .characters>.flip-container:not(:first-child) {
      margin-left: -8px;
    }

    .vs-top {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-direction: row;
    }

    .imagecard1 {
      margin-right: 120px;
    }

    .imagecard2 {
      margin-left: 120px;
    }

    /* .fight-container .imagecard {
      margin: 0;
    } */
    .fight-form {
      display: flex;
      flex-direction: column;
    }

    .player-input {
      /* margin-top: 10px; */
      display: flex;
      flex-direction: row;
      align-items: center;
      width: 100%;
    }

    .player-input.player1 {
      justify-content: flex-start;
    }

    .player-input.player2 {
      justify-content: flex-end;
    }

    /* queue / fight 共用 */
    .team {
      display: flex;
      align-items: center;
      width: 100%;
    }

    .team .characters {
      display: flex;
    }

    /* P1 圖片靠左 */
    .player-input.player1~.characters,
    .team:has(.player-input.player1) .characters {
      justify-content: flex-start;
      margin-right: 120px;
    }

    /* P2 圖片靠右 */
    .team:has(.player-input.player2) .characters {
      justify-content: flex-end;
      margin-left: 106px;
    }


  }

  /* 所有 queue 區塊內的 btn 基底 */
  .queue-admin-panel {
    display: flex;
    flex-direction: row;
    gap: 8px;
  }

  .queue-admin-panel .btn {
    background: linear-gradient(180deg, #262b38, #1d2230);
    color: #e6eaf2;
    border: 1px solid rgba(180, 200, 255, 0.22);
    box-shadow:
      inset 0 0 0 1px rgba(255, 255, 255, 0.04),
      0 2px 6px rgba(0, 0, 0, 0.6);
    font-weight: 600;
  }

  /* Hover */
  .queue-admin-panel .btn:hover {
    background: linear-gradient(180deg, #2f3648, #242b3d);
    color: #ffffff;
  }

  /* Disabled（避免白掉） */
  .queue-admin-panel .btn:disabled,
  .queue-admin-panel .btn.disabled {
    background: linear-gradient(180deg, #1c202a, #161a22);
    color: #888;
    opacity: 0.6;
    box-shadow: none;
  }

  /* ======================================================
   行為層級顏色
====================================================== */

  /* 🔥 主行為：近期對戰 / 上傳 */
  .queue-admin-panel .btn-success {
    background: linear-gradient(180deg, #3fbf7f, #2e8f61);
    border-color: rgba(120, 255, 200, 0.45);
    color: #ffffff;
  }

  .queue-admin-panel .btn-success:hover {
    background: linear-gradient(180deg, #4fd89a, #35a572);
  }

  /* ⚡ 一般操作：手動更新 */
  .queue-admin-panel .btn-secondary {
    background: linear-gradient(180deg, #3a4050, #2a3040);
    color: #e6eaf2;
  }

  /* 🔍 狀態篩選 */
  .queue-admin-panel .btn-info {
    background: linear-gradient(180deg, #355fd6, #243f9f);
    color: #eef3ff;
    border-color: rgba(140, 170, 255, 0.45);
  }

  .queue-admin-panel .btn-primary {
    background: linear-gradient(180deg, #4a7dff, #2f5fd1);
    color: #ffffff;
  }

  /* ↕ 排序（次要） */
  .queue-admin-panel .btn-warning {
    background: linear-gradient(180deg, #b89a56, #8a713a);
    color: #fff6dd;
    border-color: rgba(255, 215, 120, 0.45);
  }

  .queue-admin-panel .btn-default,
  .queue-admin-panel .btn-light {
    background: linear-gradient(180deg, #2b3140, #1e222d);
    color: #dbe4ff;
  }

  /* ======================================================
   Mobile 微調（不改排版，只調可點感）
====================================================== */
  @media (max-width: 768px) {

    .queue-admin-panel .btn {
      padding: 8px 12px;
      font-size: 14px;
      border-radius: 10px;
    }

    .queue-admin-panel {
      flex-direction: column;
    }

  }

  .json-collapse {
    overflow: hidden;
    max-height: 0;
    opacity: 0;
    transition:
      max-height 0.35s ease,
      opacity 0.25s ease;
  }

  .json-collapse.is-open {
    max-height: 600px;
    /* 足夠大即可 */
    opacity: 1;
  }

  .fight-list {
    display: flex;
    flex-direction: column;
    align-items: center;
    /* ⭐ 水平置中所有 fight-form */
    gap: 12px;
  }

  /* UL Active Button */
  .queue-focus-link,
  .queue-focus-link:hover,
  .queue-focus-link:focus {
    color: inherit;
    text-decoration: none;
  }

  .queue-admin-panel .btn.active {
    background: linear-gradient(180deg, #4a7dff, #2f5fd1);
    color: #ffffff;
    border-color: rgba(140, 170, 255, 0.7);
    box-shadow:
      inset 0 0 8px rgba(120, 180, 255, 0.45),
      0 0 14px rgba(120, 170, 255, 0.45);
  }
</style>

<?php

function progress_color($rate)
{
  if ($rate < 25) {
    $color = 'red';
  } elseif ($rate < 50) {
    $color = 'yellow';
  } elseif ($rate < 75) {
    $color = 'green';
  } else {
    $color = 'aqua';
  }
  return $color;
}
function all_equal($x, $y, $z)
{
  if ($x === $y || $y === $z || $x === $z) {
    return 1;
  }
}

function cost_punish(int $id1_cost, int $id2_cost, int $id3_cost)
{
  $punish = 0;
  $dif_a = abs($id1_cost - $id2_cost);
  $dif_b = abs($id1_cost - $id3_cost);
  $dif_c = abs($id2_cost - $id3_cost);
  if ($dif_a > 6) {
    $punish += 5;
    if ($dif_a > 13) {
      $punish += 5;
    }
  }
  if ($dif_b > 6) {
    $punish += 5;
    if ($dif_b > 13) {
      $punish += 5;
    }
  }
  if ($dif_c > 6) {
    $punish += 5;
    if ($dif_c > 13) {
      $punish += 5;
    }
  }

  return $punish;
}


function regionIcon($region)
{
  switch ($region) {
    case 'TW':
      return '<i class="fab fa-steam"></i>';

    case 'JP':
      return '<span class="server-icon dmm">D</span>';

    case 'CR':
      return '<span class="region-globe">☯</span>'; //🌐

    default:
      return '';
  }
}

// ======================================================
// arena_player_match_result 同步 helper
// arena_unlight 為勝負 Source of Truth
// ======================================================
function ulggPickEventIndex($raw): ?int
{
  if ($raw === null || $raw === '') return null;

  $arr = json_decode($raw, true);
  if (!is_array($arr)) return null;

  $counts = [];
  foreach ($arr as $v) {
    if ($v === null || $v === 'null' || $v === '') continue;
    $id = (int)$v;
    if ($id <= 0) continue;
    $counts[$id] = ($counts[$id] ?? 0) + 1;
  }

  if (empty($counts)) return null;

  arsort($counts);
  return (int)array_key_first($counts);
}

function ulggTeamKey(int $leader, int $back1, int $back2): string
{
  $backs = [$back1, $back2];
  sort($backs, SORT_NUMERIC);
  return $leader . '-' . $backs[0] . '-' . $backs[1];
}

function syncArenaPlayerMatchResult(PDO $db, int $matchId): void
{
  $stmt = $db->prepare("
    SELECT id, update_time, cost,
           e1, e2, e3, u1, u2, u3,
           name_p1, name_p2, bp_p1, bp_p2,
           eventindex1, eventindex2,
           win, lose, tie
    FROM arena_unlight
    WHERE id = :id
    LIMIT 1
  ");
  $stmt->execute([':id' => $matchId]);
  $m = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$m) return;

  $win = (int)$m['win'];
  $lose = (int)$m['lose'];
  $tie = (int)$m['tie'];

  $p1Win = null;
  $p2Win = null;
  if ($tie === 1) {
    $p1Win = null;
    $p2Win = null;
  } elseif ($win === 1) {
    $p1Win = 0;
    $p2Win = 1;
  } elseif ($lose === 1) {
    $p1Win = 1;
    $p2Win = 0;
  }

  $rows = [
    [
      'side' => 'P1',
      'player_name' => (string)$m['name_p1'],
      'player_bp' => $m['bp_p1'],
      'leader_id' => (int)$m['e1'],
      'back1_id' => min((int)$m['e2'], (int)$m['e3']),
      'back2_id' => max((int)$m['e2'], (int)$m['e3']),
      'team_key' => ulggTeamKey((int)$m['e1'], (int)$m['e2'], (int)$m['e3']),
      'eventindex' => ulggPickEventIndex($m['eventindex1']),
      'is_win' => $p1Win,
    ],
    [
      'side' => 'P2',
      'player_name' => (string)$m['name_p2'],
      'player_bp' => $m['bp_p2'],
      'leader_id' => (int)$m['u1'],
      'back1_id' => min((int)$m['u2'], (int)$m['u3']),
      'back2_id' => max((int)$m['u2'], (int)$m['u3']),
      'team_key' => ulggTeamKey((int)$m['u1'], (int)$m['u2'], (int)$m['u3']),
      'eventindex' => ulggPickEventIndex($m['eventindex2']),
      'is_win' => $p2Win,
    ],
  ];

  $sql = "
    INSERT INTO arena_player_match_result
    (match_id, update_time, player_name, player_bp, side,
     leader_id, back1_id, back2_id, team_key, cost, eventindex, is_win)
    VALUES
    (:match_id, :update_time, :player_name, :player_bp, :side,
     :leader_id, :back1_id, :back2_id, :team_key, :cost, :eventindex, :is_win)
    ON DUPLICATE KEY UPDATE
      update_time = VALUES(update_time),
      player_name = VALUES(player_name),
      player_bp = VALUES(player_bp),
      leader_id = VALUES(leader_id),
      back1_id = VALUES(back1_id),
      back2_id = VALUES(back2_id),
      team_key = VALUES(team_key),
      cost = VALUES(cost),
      eventindex = VALUES(eventindex),
      is_win = VALUES(is_win)
  ";

  $upsert = $db->prepare($sql);
  foreach ($rows as $r) {
    if ($r['player_name'] === '') continue;
    $upsert->execute([
      ':match_id' => (int)$m['id'],
      ':update_time' => $m['update_time'],
      ':player_name' => $r['player_name'],
      ':player_bp' => $r['player_bp'] !== null ? (int)$r['player_bp'] : null,
      ':side' => $r['side'],
      ':leader_id' => $r['leader_id'],
      ':back1_id' => $r['back1_id'],
      ':back2_id' => $r['back2_id'],
      ':team_key' => $r['team_key'],
      ':cost' => $m['cost'] !== null ? (int)$m['cost'] : null,
      ':eventindex' => $r['eventindex'],
      ':is_win' => $r['is_win'],
    ]);
  }
}

function deleteArenaPlayerMatchResult(PDO $db, int $matchId): void
{
  $stmt = $db->prepare("DELETE FROM arena_player_match_result WHERE match_id = :match_id");
  $stmt->execute([':match_id' => $matchId]);
}

//找到下一筆去判斷此筆的勝負
function update_all_next_matches(PDO $db, array $dataList)
{
  $logs = [];
  $updated = 0;
  $processed_ids = [];
  $sql = '';
  foreach ($dataList as $data) {
    $id = $data['id'];
    if (in_array($id, $processed_ids)) continue;
    $processed_ids[] = $id;
    $update_time = $data['update_time'];
    // === 玩家1 ===
    $ack1 = $data['ack1'];
    if ($ack1 == 0) {
      $name_p1 = $data['name_p1'];
      $win_p1 = $data['win_p1'];
      $draw_p1 = $data['draw_p1'];
      $lose_p1 = $data['lose_p1'];

      $stmt1 = $db->prepare("
      SELECT * FROM arena_unlight
      WHERE (name_p1 = :name OR name_p2 = :name)
        AND update_time > :update_time
      ORDER BY update_time ASC LIMIT 1
      ");
      $stmt1->execute([':name' => $name_p1, ':update_time' => $update_time]);
      $next1 = $stmt1->fetch(PDO::FETCH_ASSOC);
      if ($next1) {
        $is_p1 = ($next1['name_p1'] === $name_p1);
        $win_after = $is_p1 ? $next1['win_p1'] : $next1['win_p2'];
        $draw_after = $is_p1 ? $next1['draw_p1'] : $next1['draw_p2'];
        $lose_after = $is_p1 ? $next1['lose_p1'] : $next1['lose_p2'];
        $dw = $win_after - $win_p1;
        $dl = $lose_after - $lose_p1;
        $dd = $draw_after - $draw_p1;
        $ack_field = 'ack1';
        if ($dw >= 1 && $dl === 0 && $dd === 0) {
          //$sql = "✅ 勝：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET win=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 勝：P1 $name_p1 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET lose=1, win=0, tie=0, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          syncArenaPlayerMatchResult($db, (int)$id);
          //$logs[] = "✅ 勝：P1 $name_p1 (ID $id)";
          $updated++;
        } elseif ($dl >= 1 && $dw === 0 && $dd === 0) {
          //$sql = "✅ 負：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET win=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 負：P1 $name_p1 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET win=1, lose=0, tie=0, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          syncArenaPlayerMatchResult($db, (int)$id);
          //$logs[] = "✅ 負：P1 $name_p1 (ID $id)";
          $updated++;
        } elseif ($dd >= 1 && $dw === 0 && $dl === 0) {
          //$sql = "✅ 平：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 平：P1 $name_p1 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET tie=1, win=0, lose=0, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          syncArenaPlayerMatchResult($db, (int)$id);
          //$logs[] = "✅ 平：P1 $name_p1 (ID $id)";
          $updated++;
        } elseif ($dd === 0 && $dw === 0 && $dl === 0) {
          //$sql = "✅ 平：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 前場無效：P1 $name_p1 (ID $id)";
          deleteArenaPlayerMatchResult($db, (int)$id);
          deleteArenaPlayerMatchResult($db, (int)$id);
          $db->prepare("DELETE FROM arena_unlight WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 平：P1 $name_p1 (ID $id)";
          $updated++;
        } else {
          $sql = "⚠️ 無法判斷 P1 $name_p1 勝負（已標 $ack_field(ID $id)<br>UPDATE arena_unlight SET $ack_field=1 WHERE id = $id";
          //$sql = "⚠️ 無法判斷 P1 $name_p1 勝負（已標 $ack_field(ID $id)";
          $db->prepare("UPDATE arena_unlight SET $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "⚠️ 無法判斷 P1 $name_p1 勝負（已標 $ack_field(ID $id)";
        }
        print $sql . '<br>';
      } else {
        //$sql = "🕵 無法比對 P1 $name_p1 ，找不到下一筆 (ID $id)";
        //$logs[] = "🕵 無法比對 P1 $name_p1 ，找不到下一筆 (ID $id)";
      }
    }




    // === 玩家2 ===
    $ack2 = $data['ack2'];
    if ($ack2 == 0) {
      $name_p2 = $data['name_p2'];
      $win_p2 = $data['win_p2'];
      $draw_p2 = $data['draw_p2'];
      $lose_p2 = $data['lose_p2'];

      $stmt2 = $db->prepare("
        SELECT * FROM arena_unlight
        WHERE (name_p1 = :name OR name_p2 = :name)
          AND update_time > :update_time
        ORDER BY update_time ASC LIMIT 1
        ");
      $stmt2->execute([':name' => $name_p2, ':update_time' => $update_time]);
      $next2 = $stmt2->fetch(PDO::FETCH_ASSOC);

      if ($next2) {
        $is_p2 = ($next2['name_p1'] === $name_p2);
        $win_after = $is_p2 ? $next2['win_p1'] : $next2['win_p2'];
        $draw_after = $is_p2 ? $next2['draw_p1'] : $next2['draw_p2'];
        $lose_after = $is_p2 ? $next2['lose_p1'] : $next2['lose_p2'];

        $dw = $win_after - $win_p2;
        $dl = $lose_after - $lose_p2;
        $dd = $draw_after - $draw_p2;

        $ack_field = 'ack2';

        if ($dw >= 1 && $dl === 0 && $dd === 0) {
          //$sql = "✅ 勝：P2 $name_p2 (ID $id)<br>UPDATE arena_unlight SET win=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 勝：P2 $name_p2 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET win=1, lose=0, tie=0, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          syncArenaPlayerMatchResult($db, (int)$id);
          //$logs[] = "✅ 勝：P2 $name_p2 (ID $id)";
          $updated++;
        } elseif ($dl >= 1 && $dw === 0 && $dd === 0) {
          //$sql = "✅ 負：P2 $name_p2 (ID $id)<br>UPDATE arena_unlight SET lose=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 負：P2 $name_p2 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET lose=1, win=0, tie=0, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          syncArenaPlayerMatchResult($db, (int)$id);
          //$logs[] = "✅ 負：P2 $name_p2 (ID $id)";
          $updated++;
        } elseif ($dd >= 1 && $dw === 0 && $dl === 0) {
          //$sql = "✅ 平：P2 $name_p2 (ID $id)<br>UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 平：P2 $name_p2 (ID $id)";
          $db->prepare("UPDATE arena_unlight SET tie=1, win=0, lose=0, $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          syncArenaPlayerMatchResult($db, (int)$id);
          //$logs[] = "✅ 平：P2 $name_p2 (ID $id)";
          $updated++;
        } elseif ($dd === 0 && $dw === 0 && $dl === 0) {
          //$sql = "✅ 平：P1 $name_p1 (ID $id)<br>UPDATE arena_unlight SET tie=1, $ack_field=1 WHERE id = $id";
          $sql = "✅ 前場無效：P2 $name_p2 (ID $id)";
          $db->prepare("DELETE FROM arena_unlight WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "✅ 平：P1 $name_p1 (ID $id)";
          $updated++;
        } else {
          //$sql = "⚠️ 無法判斷 P2 $name_p2 勝負（已標 $ack_field(ID $id)<br>UPDATE arena_unlight SET $ack_field=1 WHERE id = $id";
          $sql = "⚠️ 無法判斷 P2 $name_p2 勝負（已標 $ack_field(ID $id)";
          $db->prepare("UPDATE arena_unlight SET $ack_field=1 WHERE id = :id")->execute([':id' => $id]);
          //$logs[] = "⚠️ 無法判斷 P2 $name_p2 勝負（已標 $ack_field(ID $id)";
        }
        print $sql . '<br>';
      } else {
        //$sql = "🕵 無法比對 P2 $name_p2 ，找不到下一筆 (ID $id)";
        //$logs[] = "🕵 無法比對 P2 $name_p2 ，找不到下一筆 (ID $id)";
      }
    }
  }
  return ['updated' => $updated, 'logs' => $logs];
}

function updatePreviousMatchResult($db, $name, $winNow, $drawNow, $loseNow, $room_id, $update_time)
{
  $stmt_prev = $db->prepare("SELECT * FROM arena_unlight WHERE (name_p1 = :name OR name_p2 = :name) AND update_time<:update_time ORDER BY update_time DESC LIMIT 1");
  $stmt_prev->execute([':name' => $name, ':room_id' => $room_id, ':update_time' => $update_time]);
  $prev = $stmt_prev->fetch(PDO::FETCH_ASSOC);

  if (!$prev) return null;

  $log = '';
  $dw = $winNow - ($prev['name_p1'] === $name ? $prev['win_p1'] : $prev['win_p2']);
  $dl = $loseNow - ($prev['name_p1'] === $name ? $prev['lose_p1'] : $prev['lose_p2']);
  $dd = $drawNow - ($prev['name_p1'] === $name ? $prev['draw_p1'] : $prev['draw_p2']);

  if ($dw >= 1 && $dl === 0 && $dd === 0) {
    if ($prev['name_p2'] === $name) {
      $db->prepare("UPDATE arena_unlight SET win=1, lose=0, tie=0, ack1=1, ack2=1 WHERE id = :id")->execute([':id' => $prev['id']]);
      syncArenaPlayerMatchResult($db, (int)$prev['id']);
      $log = "✅ 更新前筆($name(勝) VS {$prev['name_p1']} ID {$prev['id']})";
    } else {
      $db->prepare("UPDATE arena_unlight SET lose=1, win=0, tie=0, ack1=1, ack2=1 WHERE id = :id")->execute([':id' => $prev['id']]);
      syncArenaPlayerMatchResult($db, (int)$prev['id']);
      $log = "✅ 更新前筆($name(勝) VS {$prev['name_p2']} ID {$prev['id']})";
    }
  } elseif ($dw === 0 && $dl >= 1 && $dd === 0) {
    if ($prev['name_p2'] === $name) {
      $db->prepare("UPDATE arena_unlight SET lose=1, win=0, tie=0, ack1=1, ack2=1 WHERE id = :id")->execute([':id' => $prev['id']]);
      syncArenaPlayerMatchResult($db, (int)$prev['id']);
      $log = "✅ 更新前筆($name(負) VS {$prev['name_p1']} ID {$prev['id']})";
    } else {
      $db->prepare("UPDATE arena_unlight SET win=1, lose=0, tie=0, ack1=1, ack2=1 WHERE id = :id")->execute([':id' => $prev['id']]);
      syncArenaPlayerMatchResult($db, (int)$prev['id']);
      $log = "✅ 更新前筆($name(負) VS {$prev['name_p2']} ID {$prev['id']})";
    }
  } elseif ($dd >= 1 && $dw === 0 && $dl === 0) {
    if ($prev['name_p2'] === $name) {
      $db->prepare("UPDATE arena_unlight SET tie=1, win=0, lose=0, ack1=1, ack2=1 WHERE id = :id")->execute([':id' => $prev['id']]);
      syncArenaPlayerMatchResult($db, (int)$prev['id']);
      $log = "✅ 更新前筆($name(平) VS {$prev['name_p1']} ID {$prev['id']})";
    } else {
      $db->prepare("UPDATE arena_unlight SET tie=1, win=0, lose=0, ack1=1, ack2=1 WHERE id = :id")->execute([':id' => $prev['id']]);
      syncArenaPlayerMatchResult($db, (int)$prev['id']);
      $log = "✅ 更新前筆($name(平) VS {$prev['name_p2']} ID {$prev['id']})";
    }
  } elseif ($dd === 0 && $dw === 0 && $dl === 0) {
    if ($prev['name_p2'] === $name) {
      $sql = "DELETE FROM arena_unlight WHERE id = " . $prev['id'] . ";";
      //print $sql;
      deleteArenaPlayerMatchResult($db, (int)$prev['id']);
      $db->prepare("DELETE FROM arena_unlight WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 前筆記錄失效，已刪除($name(平) VS {$prev['name_p1']} ID {$prev['id']})";
    } else {
      $sql = "DELETE FROM arena_unlight WHERE id = " . $prev['id'] . ";";
      //print $sql;
      deleteArenaPlayerMatchResult($db, (int)$prev['id']);
      $db->prepare("DELETE FROM arena_unlight WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "✅ 前筆記錄失效，已刪除($name(平) VS {$prev['name_p2']} ID {$prev['id']})";
    }
  } else {
    if ($prev['name_p1'] === $name) {
      $db->prepare("UPDATE arena_unlight SET ack1 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "⚠️ 無法判斷勝負($name VS {$prev['name_p2']} ID {$prev['id']})，已標記無效";
    } else {
      $db->prepare("UPDATE arena_unlight SET ack2 = 1 WHERE id = :id")->execute([':id' => $prev['id']]);
      $log = "⚠️ 無法判斷勝負($name VS {$prev['name_p1']} ID {$prev['id']})，已標記無效";
    }
  }

  return $log;
}
function renderPlayerName(
  string $playerName,
  int $permission,
  array $linkParams = []
): string {

  // 無權限 → 不顯示真名
  if ($permission <= 1) {
    return '<span class="player-name text-muted">匿名玩家</span>';
  }

  // 有權限 → 可點連結；focus_id 會直接定位到該場
  $focusId = isset($linkParams['focus_id']) ? (int)$linkParams['focus_id'] : 0;
  $url = 'fight.php?' . http_build_query($linkParams);
  if ($focusId > 0) {
    $url .= '#fight-' . $focusId;
  }

  return '<a href="' . htmlspecialchars($url) . '" class="player-name">'
    . htmlspecialchars($playerName)
    . '</a>';
}

if (isset($_SESSION['upload_success'])) {
  /* echo "<script>alert('" . $_SESSION['upload_success'] . "');</script>"; */
  unset($_SESSION['upload_success']); // 顯示後清除
}

$check_ack_sql = '';
$order = 0;
$order_sql = '';


if (isset($_POST['check_ack'])) {
  $_SESSION['updated_history'] = 0;
}
if (isset($_POST['updated_history'])) {
  $_SESSION['updated_history'] = 1;
  $_SESSION['check_ack'] = null; // ⭐ 清掉狀態
}

//手動更新
if (isset($_POST['manual_update'])) {
  $dataList = [];

  $sql = "SELECT id, room_id, update_time, name_p1, win_p1, draw_p1, lose_p1, name_p2, win_p2, draw_p2, lose_p2,ack1,ack2
        FROM arena_unlight
        WHERE (win+lose+tie<1)
        ORDER BY update_time ASC";

  $stmt = $db->query($sql);
  while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $dataList[] = [
      'id' => $row['id'],
      'update_time' => $row['update_time'],
      'name_p1' => $row['name_p1'],
      'win_p1' => $row['win_p1'],
      'draw_p1' => $row['draw_p1'],
      'lose_p1' => $row['lose_p1'],
      'enemy' => $row['name_p2'],
      'name_p2' => $row['name_p2'],
      'win_p2' => $row['win_p2'],
      'draw_p2' => $row['draw_p2'],
      'lose_p2' => $row['lose_p2'],
      'ack1' => $row['ack1'],
      'ack2' => $row['ack2']
    ];
  }

  $result = update_all_next_matches($db, $dataList);
  $cannot_deter = count($dataList) - $result['updated'];
  // 把提示推到 flash
  $_SESSION['flash'][] = [
    'type'    => 'success',
    'message' => "📦 共處理 " . count($dataList)
      . " 筆，{$cannot_deter} 筆無法比對，更新 {$result['updated']} 筆"
  ];
  // PRG：處理完立刻重導
  header('Location: queue.php');
  exit;
}

// 匯入 json (+更新前筆)
if (isset($_POST['upload_json'])) {

  $json_str = trim($_POST['ws_json'] ?? '');

  // 1️⃣ 先 decode 一次
  $dataRaw = json_decode($json_str, true);

  if (json_last_error() !== JSON_ERROR_NONE) {
    $_SESSION['flash'][] = [
      'type' => 'danger',
      'message' => '❌ JSON 格式錯誤：' . json_last_error_msg()
    ];
    header('Location: queue.php');
    exit;
  }

  if (!is_array($dataRaw) || empty($dataRaw)) {
    $_SESSION['flash'][] = [
      'type' => 'danger',
      'message' => '❌ JSON 為空或格式不正確'
    ];
    header('Location: queue.php');
    exit;
  }

  /**
   * 🔥 關鍵：支援兩種結構
   * 1) [ {...}, {...} ]
   * 2) { room_id : {...}, room_id : {...} }
   */
  $isList = array_keys($dataRaw) === range(0, count($dataRaw) - 1);

  $dataList = $isList
    ? $dataRaw
    : array_values($dataRaw);

  if (empty($dataList)) {
    $_SESSION['flash'][] = [
      'type' => 'danger',
      'message' => '❌ JSON 解析後沒有任何對戰資料'
    ];
    header('Location: queue.php');
    exit;
  }

  // 2️⃣ 依 date 由小到大排序（非常正確，保留）
  usort($dataList, function ($a, $b) {
    return ($a['date'] ?? 0) <=> ($b['date'] ?? 0);
  });

  // 👉 後面直接接你原本的 foreach 即可


  $logs = [];
  $updated = 0;
  $total = count($dataList);
  $short_room_id = '';
  $count_short_room = 0;
  foreach ($dataList as $data) {
    $room_id = $data['room_id'] ?? '';
    $cost = isset($data['cost']) && $data['cost'] !== null
      ? (int)$data['cost']
      : 0;
    $stage = $data['stage'] ?? '';
    /* $readyA = $data['readyA'] ?? '';
    $readyB = $data['readyB'] ?? ''; */
    //print "room_id=$room_id";
    $date = $data['date'] ?? 0;
    $datetime = date("Y-m-d H:i:s", $date / 1000);

    // 防止重複上傳
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM arena_unlight WHERE room_id = :room_id");
    $stmt_check->execute([':room_id' => $room_id]);
    $exists = $stmt_check->fetchColumn();

    if ($exists > 0) {
      $short_room_id .= substr($room_id, 0, 3) . '..';
      $count_short_room++;
      continue;
    }

    // Player A
    $nameA = $data['playerA']['name'] ?? '';
    $bpA = $data['playerA']['bp'] ?? 0;
    $winA = $data['playerA']['win'] ?? 0;
    $drawA = $data['playerA']['draw'] ?? 0;
    $loseA = $data['playerA']['lose'] ?? 0;
    $e1 = ($data['deckA']['charaIndex'][0] ?? -1) + 1;
    $e2 = ($data['deckA']['charaIndex'][1] ?? -1) + 1;
    $e3 = ($data['deckA']['charaIndex'][2] ?? -1) + 1;
    $w1 = $data['deckA']['weapon'][0] ?? NULL;
    $w2 = $data['deckA']['weapon'][1] ?? NULL;
    $w3 = $data['deckA']['weapon'][2] ?? NULL;
    $eventindex1 = json_encode($data['deckA']['eventIndex']) ?? [null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null];
    // Player B
    $nameB = $data['playerB']['name'] ?? '';
    $bpB = $data['playerB']['bp'] ?? 0;
    $winB = $data['playerB']['win'] ?? 0;
    $drawB = $data['playerB']['draw'] ?? 0;
    $loseB = $data['playerB']['lose'] ?? 0;
    $u1 = ($data['deckB']['charaIndex'][0] ?? -1) + 1;
    $u2 = ($data['deckB']['charaIndex'][1] ?? -1) + 1;
    $u3 = ($data['deckB']['charaIndex'][2] ?? -1) + 1;
    $v1 = $data['deckB']['weapon'][0] ?? NULL;
    $v2 = $data['deckB']['weapon'][1] ?? NULL;
    $v3 = $data['deckB']['weapon'][2] ?? NULL;
    $eventindex2 = json_encode($data['deckB']['eventIndex']) ?? [null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null, null];

    // 資料庫插入
    $sql = "INSERT INTO arena_unlight 
              (room_id,cost,stage, name_p1, bp_p1, win_p1, draw_p1, lose_p1, e1, e2, e3, 
               name_p2, bp_p2, win_p2, draw_p2, lose_p2, u1, u2, u3, update_time, username, w1,w2,w3,eventindex1,v1,v2,v3,eventindex2)
              VALUES 
              (:room_id,:cost,:stage, :name_p1, :bp_p1, :win_p1, :draw_p1, :lose_p1, :e1, :e2, :e3,
               :name_p2, :bp_p2, :win_p2, :draw_p2, :lose_p2, :u1, :u2, :u3, :update_time, :username,  :w1, :w2, :w3, :eventindex1, :v1, :v2, :v3, :eventindex2)";

    $stmt_insert = $db->prepare($sql);

    try {
      $success = $stmt_insert->execute([
        ':room_id' => $room_id,
        ':cost' => $cost,
        ':stage' => $stage,
        ':name_p1' => $nameA,
        ':bp_p1' => $bpA,
        ':win_p1' => $winA,
        ':draw_p1' => $drawA,
        ':lose_p1' => $loseA,
        ':e1' => $e1,
        ':e2' => $e2,
        ':e3' => $e3,
        ':name_p2' => $nameB,
        ':bp_p2' => $bpB,
        ':win_p2' => $winB,
        ':draw_p2' => $drawB,
        ':lose_p2' => $loseB,
        ':u1' => $u1,
        ':u2' => $u2,
        ':u3' => $u3,
        ':update_time' => $datetime,
        ':username' => $username ?? '',
        ':w1' => $w1,
        ':w2' => $w2,
        ':w3' => $w3,
        ':eventindex1' => $eventindex1,
        ':v1' => $v1,
        ':v2' => $v2,
        ':v3' => $v3,
        ':eventindex2' => $eventindex2
      ]);

      if ($success) {
        $newMatchId = (int)$db->lastInsertId();
        if ($newMatchId > 0) {
          syncArenaPlayerMatchResult($db, $newMatchId);
        }

        $_SESSION['flash'][] = [
          'type'    => 'success',
          'message' => "✅ 上傳成功！{$nameA} VS {$nameB} Room ID: {$room_id}"
        ];
        $updated++;
      } else {
        $_SESSION['flash'][] = [
          'type'    => 'danger',
          'message' => "❌ 插入失敗 ({$nameA} VS {$nameB} Room ID: {$room_id})"
        ];
      }
    } catch (Exception $e) {
      $_SESSION['flash'][] = [
        'type'    => 'danger',
        'message' => '❌ 發生例外錯誤：' . $e->getMessage()
      ];
    }

    $logA = updatePreviousMatchResult($db, $nameA, $winA, $drawA, $loseA, $room_id, $datetime);
    $logB = updatePreviousMatchResult($db, $nameB, $winB, $drawB, $loseB, $room_id, $datetime);
    // 處理 updatePreviousMatchResult 回傳的 $log 也一併推 flash
    if ($logA) {
      $_SESSION['flash'][] = ['type' => 'info', 'message' => $logA];
      $updated++;
    }
    if ($logB) {
      $_SESSION['flash'][] = ['type' => 'info', 'message' => $logB];
      $updated++;
    }
    $_SESSION['flash'][] = [
      'type'    => 'info',
      'message' => "📌 新增資料：$datetime $nameA vs $nameB"
    ];
    //$logs[] = "📌 新增資料：$datetime $nameA vs $nameB";
  }

  // 結束後再推一次整體 summary
  $_SESSION['flash'][] = [
    'type'    => 'warning',
    'message' => "⚠️ 已存在 {$count_short_room} 間 (Room ID: {$short_room_id})"
  ];
  $_SESSION['flash'][] = [
    'type'    => 'success',
    'message' => "📦 本次上傳共處理 {$total} 筆資料(更新 {$updated} 筆)"
  ];

  // PRG：完成後重導
  header('Location: queue.php');
  exit;
}
$username = $_SESSION['username'] ?? NULL;
/* ----------  處理「動作」按鈕  ---------- */
if (isset($_POST['set_win1'])) {
  $id          = intval($_POST['set_win1']);
  $player_name = trim($_POST['player_name1']);

  /* 1) 先抓資料 */
  $stmt = $db->prepare("SELECT name_p1, name_p2
      FROM arena_unlight
      WHERE id = :id
      LIMIT 1
  ");
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $_SESSION['upload_success'] = "找不到這筆對戰 (ID=$id)";
    exit();
  }

  /* 2) 判斷 P1 / P2 */
  if ($row['name_p1'] === $player_name) {
    // P1 勝 → lose = 1
    $sql = "UPDATE arena_unlight
      SET win = 0,
          lose = 1,
          tie = 0,
          ack1 = 1,
          ack2 = 1,
          judge_source = :judge_source
      WHERE id = :id
    ";
  } elseif ($row['name_p2'] === $player_name) {
    // P2 勝 → win = 1
    $sql = "UPDATE arena_unlight
      SET win = 1,
          lose = 0,
          tie = 0,
          ack1 = 1,
          ack2 = 1,
          judge_source = :judge_source
      WHERE id = :id
    ";
  } else {
    $_SESSION['upload_success'] = '名稱不符，請重新整理後再試';
    exit();
  }

  /* 3) 執行更新（含 debug SQL） */
  $params = [
    ':id'           => $id,
    ':judge_source' => $username,
  ];

  /* 🔍 DEBUG：組出實際 SQL（只用來 print / log） */
  $debug_sql = $sql;
  foreach ($params as $k => $v) {
    $v = $db->quote($v);   // PDO 安全轉義
    $debug_sql = str_replace($k, $v, $debug_sql);
  }

  // ② 寫進 PHP error log（推薦）
  error_log("[MANUAL JUDGE SQL] " . $debug_sql);

  // ③ 存到 session，導頁後顯示
  $_SESSION['debug_sql'] = $debug_sql;

  /* 正式執行 */
  $db->prepare($sql)->execute($params);
  syncArenaPlayerMatchResult($db, $id);

  $_SESSION['upload_success'] = "$player_name 勝！";
  header('Location: queue.php');      // ← 導回 queue.php?debug=1
  exit();
} elseif (isset($_POST['set_win2'])) {
  $id          = intval($_POST['set_win2']);
  $player_name = trim($_POST['player_name2']);

  $stmt = $db->prepare("SELECT name_p1, name_p2
      FROM arena_unlight
      WHERE id = :id
      LIMIT 1
  ");
  $stmt->execute([':id' => $id]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $_SESSION['upload_success'] = "找不到這筆對戰 (ID=$id)";
    exit();
  }

  if ($row['name_p1'] === $player_name) {
    $sql = "UPDATE arena_unlight
      SET win = 0,
          lose = 1,
          tie = 0,
          ack1 = 1,
          ack2 = 1,
          judge_source = :judge_source
      WHERE id = :id
    ";
  } elseif ($row['name_p2'] === $player_name) {
    $sql = "UPDATE arena_unlight
      SET win = 1,
          lose = 0,
          tie = 0,
          ack1 = 1,
          ack2 = 1,
          judge_source = :judge_source
      WHERE id = :id
    ";
  } else {
    $_SESSION['upload_success'] = '名稱不符，請重新整理後再試';
    exit();
  }

  $db->prepare($sql)->execute([
    ':id'           => $id,
    ':judge_source' => $username,
  ]);
  syncArenaPlayerMatchResult($db, $id);

  $_SESSION['upload_success'] = '感謝上傳！勝場已更新！';
  header('Location: queue.php');      // ← 導回 queue.php?debug=1
  exit();
} elseif (isset($_POST['tie'])) {
  $id = $_POST['tie'];
  $id = intval($_POST['tie']);

  $sql = "UPDATE arena_unlight
    SET
      win = 0,
      lose = 0,
      tie = 1,
      ack1 = 1,
      ack2 = 1,
      judge_source = :judge_source
    WHERE id = :id
  ";

  $stmt = $db->prepare($sql);
  $stmt->execute([
    ':id' => $id,
    ':judge_source' => $username,
  ]);
  syncArenaPlayerMatchResult($db, $id);

  $_SESSION['upload_success'] = '平手已更新';
  header('Location: queue.php');      // ← 導回 queue.php?debug=1
  exit();
} elseif (isset($_POST['is_read'])) {
  $id = intval($_POST['is_read']);

  $sql = "UPDATE arena_unlight
    SET
      is_read = 1,
      judge_source = :judge_source
    WHERE id = :id
  ";

  $params = [
    ':id'           => $id,
    ':judge_source' => $username,
  ];

  /* 🔍 DEBUG：還原實際 SQL（只記錄，不影響執行） */
  $debug_sql = $sql;
  foreach ($params as $k => $v) {
    $debug_sql = str_replace($k, $db->quote($v), $debug_sql);
  }

  // 寫入 PHP error log（伺服器端證據）
  $logfile = '/tmp/ulgg_debug_sql.log';

  error_log(
    '[MARK READ SQL] ' . $debug_sql . PHP_EOL,
    3,
    $logfile
  );

  // 如需前端顯示可打開
  $_SESSION['debug_sql'] = $debug_sql;

  /* 正式執行 */
  $stmt = $db->prepare($sql);
  $stmt->execute($params);

  $_SESSION['upload_success'] = '標示已讀';
  header('Location: queue.php');      // ← 導回 queue.php?debug=1
  exit();
} elseif (isset($_POST['del'])) {
  $id = intval($_POST['del']);

  deleteArenaPlayerMatchResult($db, $id);

  $sql = "DELETE FROM arena_unlight WHERE id = :id";
  $stmt = $db->prepare($sql);
  $stmt->execute([':id' => $id]);

  $_SESSION['upload_success'] = '大刀一砍！';
  header('Location: queue.php');
  exit();
}


// ✅ PRG：如果是 POST，處理完直接重導回自己，避免 F5 重新送出表單
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ✅ 一般欄位統一存進 SESSION
  $allowedKeys = [
    'check_ack',
    'order',
    'updated_history',
    'combined'
  ];

  foreach ($allowedKeys as $key) {
    if (isset($_POST[$key])) {
      $_SESSION[$key] = $_POST[$key];
    }
  }

  // ✅ 最後導回自己
  header('Location: queue.php');
  exit;
}

// 從 session 取得 combined，如果不存在則預設為 false
$combined = $_SESSION["combined"] ?? false;
$username = $_SESSION["username"] ?? '';
$permission = $_SESSION["ack"] ?? 0;
// 🔥 管理者進入 Queue 預設顯示「未讀」
if (
  $permission >= 2
  && !isset($_SESSION['check_ack'])
  && empty($_SESSION['updated_history'])   // ⭐ 關鍵：不是近期對戰
) {
  $_SESSION['check_ack'] = 3;
}

$check_ack = isset($_SESSION['check_ack'])
  ? (int)$_SESSION['check_ack']
  : null;
$updated_history = $_SESSION['updated_history'] ?? 0;
$order = $_SESSION['order'] ?? 0;




if ($order == 1) { //查詢已略
  $order_sql = "ASC";
} else { //查詢已略
  $order_sql = "DESC";
}
//$check_ack =0;

if ($check_ack == 1) { //查詢已略
  $check_ack_sql = "AND ((ack1=1) OR (ack2=1) )";
} elseif ($check_ack == 2) { // 雙略
  $check_ack_sql = "AND (ack1=1 AND ack2=1)";
} elseif ($check_ack == 3) { // 未讀
  $check_ack_sql = "AND ( (ack1=1 OR ack2=1) AND is_read=0)";
} elseif ($check_ack == 4) { //已讀
  $check_ack_sql = "AND ((ack1=1) OR (ack2=1)) AND (is_read=1)";
} elseif ($permission == 0) {
  $check_ack_sql = '';
} else {
  $check_ack_sql = '';
}

?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <div class="col-md-12">
          <?php


          if ($permission >= 2) {
            print '
  <form action="queue.php" method="POST" class="queue-admin-panel">

    <!-- 📥 管理工具 -->
    <div class="queue-section">
      <button
        type="button"
        class="btn btn-outline-primary w-100 js-toggle-json"
        aria-expanded="false">
        📥 貼上JSON
      </button>

      <div id="jsonInput" class="json-collapse">
        <textarea name="ws_json"
          class="form-control queue-json-textarea"
          placeholder="請貼上 WebSocket JSON 資料"></textarea>

        <div class="text-center mt-3">
          <button type="submit" name="upload_json"
            class="btn btn-success px-4">
            解析
          </button>
        </div>
      </div>
    </div>

    <!-- ⚡ 即時操作 -->
    <div class="queue-section queue-actions">
      <button type="submit" name="manual_update" value="1"
        class="btn btn-secondary">
        🔄 手動更新
      </button>

      <button type="submit" name="updated_history" value="1"
        class="btn btn-secondary ' . ($updated_history ? 'active' : '') . '">
        🕘 近期對戰
      </button>
    </div>

    <!-- 🔍 狀態篩選 -->
    <div class="queue-section queue-filters">
      <span class="queue-label">狀態：</span>

      <button type="submit" name="check_ack" value="2"
        class="btn btn-outline-info ' . ($check_ack == 2 ? 'active' : '') . '">
        雙略
      </button>

      <button type="submit" name="check_ack" value="3"
        class="btn btn-outline-info ' . ($check_ack == 3 ? 'active' : '') . '">
        未讀
      </button>

      <button type="submit" name="check_ack" value="4"
        class="btn btn-outline-primary ' . ($check_ack == 4 ? 'active' : '') . '">
        已讀
      </button>
    </div>

    <!-- ↕ 排序 -->
    <div class="queue-section queue-order">
      <span class="queue-label">排序：</span>

      <button type="submit" name="order" value="0"
        class="btn btn-outline-light ' . ($order == 0 ? 'active' : '') . '">
        新 ▼
      </button>

      <button type="submit" name="order" value="1"
        class="btn btn-outline-warning ' . ($order == 1 ? 'active' : '') . '">
        舊 ▼
      </button>
    </div>

  </form>
  ';
          }
          ?>

        </div>
        <!-- /.col -->
      </div>
      <!-- /.row -->
      <div class="row">
        <div class="col-md-12">
          <div class="fight-list">
            <?php
            if (isset($_POST['ch']) && !isset($_POST['up']) && !isset($_POST['show_all'])) {
              $_SESSION["combined"] = 0;
              $combined = 0;
              $status = "status1";
              for ($i = 0; $i < 3; $i++) {
                $j = $i + 1;
                ${"e" . $j} = $_POST['ch'][$i] ?? 0;
              }
            } elseif (isset($_POST["e1"])) {
              $status =  "state2";
              $e1 = $_POST['e1'];
              $e2 = $_POST['e2'];
              $e3 = $_POST['e3'];
            } elseif (isset($_SESSION["e1"])) {
              $status =  "state3";
              $e1 = $_SESSION['e1'];
              $e2 = $_SESSION['e2'];
              $e3 = $_SESSION['e3'];
            }
            $sql = "SELECT COUNT(*) FROM arena_unlight WHERE (ack1+ack2> 0) AND(win+lose+tie<1) AND update_time >= DATE_SUB(NOW(), INTERVAL 30 DAY);";/* (ack1+ack2> 0) AND(win+lose+tie<1) ack1=0 OR ack2=0*/
            $stmt = $db->prepare($sql);
            $stmt->execute();
            $count = $stmt->fetchColumn();
            if ($permission >= 2) {
              print '<div style="text-align:center;"><h4>佇列【Queue】- 已略共 ' . $count . ' 筆。</h4></div>';
            }

            $flag = 1;
            $use_flag = 1;
            $index = 0; // 追蹤行數
            $result = 0;

            $win_sum = 0;
            $lose_sum = 0;
            //$player_name = '';

            //print "updated_history=$updated_history";
            //近期對戰
            if ($permission == 0) {
              $sql = "SELECT *
                FROM arena_unlight
                ORDER BY update_time DESC
                LIMIT 5
            ";
            } elseif ($updated_history || $permission == 1) {
              $sql = "SELECT *
                FROM arena_unlight
                ORDER BY update_time DESC
                LIMIT 10
            ";
            } else {
              $sql = "SELECT *
                FROM arena_unlight
                WHERE win + lose + tie < 1
                  $check_ack_sql
                  AND update_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                ORDER BY update_time $order_sql
            ";
            }

            if ($sql === null) {
              // 顯示「請登入」或直接略過
            } else {

              // 執行你的 SQL
              $stmt2 = $db->query($sql);
              $stmt2->setFetchMode(PDO::FETCH_ASSOC);

              // 逐筆輸出
              while ($row = $stmt2->fetch()) {
                // 交替背景
                $bg_class = ($index % 2 === 0) ? "gray-bg" : "white-bg";
                $index++;

                // 基本欄位
                $id           = $row['id'];
                $rawDate      = $row['update_time'];
                $showDate     = date('m-d H:i', strtotime($rawDate));
                $admin        = $row['username'];
                $region       = $row['region'];
                $matchCost       = $row['cost'];
                $focusServer     = ($region === 'JP')
                  ? 'DMM'
                  : (($region === 'TW') ? 'STEAM' : 'ALL');

                // Player 1 資料
                $player_name1 = $row['name_p1'];
                $bp1          = $row['bp_p1'];
                $win1         = $row['win_p1'];
                $draw1        = $row['draw_p1'];
                $lose1        = $row['lose_p1'];

                // Player 2 資料
                $player_name2 = $row['name_p2'];
                $bp2          = $row['bp_p2'];
                $win2         = $row['win_p2'];
                $draw2        = $row['draw_p2'];
                $lose2        = $row['lose_p2'];

                $ack1         = $row['ack1'];
                $ack2         = $row['ack2'];
                $is_read      = $row['is_read'];

                $result_p2_win = $row['win'];
                $result_p2_lose = $row['lose'];
                $result_p2_tie = $row['tie'];

                if ($result_p2_win + $result_p2_lose + $result_p2_tie == 1) {
                  $result = 1;
                }

                print '<form action="queue.php" method="POST" class="fight-form white-bg">';
                print '<div class="team ' . $bg_class . '">';

                // ==== 組合1 (e1,e2,e3) ====
                $cost_sum = 0;
                $img_arr  = '';
                $cost_arr = [];   // ⭐ 必須

                print '<div class="team">';
                foreach (['e1', 'e2', 'e3'] as $col) {
                  $charId = $row[$col];
                  $stmt3 = $db->prepare("SELECT ico, cost FROM unlight WHERE id = :id");
                  $stmt3->execute([':id' => $charId]);
                  $stmt3->setFetchMode(PDO::FETCH_ASSOC);
                  if ($r = $stmt3->fetch()) {
                    $ico   = $r['ico'];
                    $cost  = $r['cost'];
                    $cost_sum += $cost;
                    $cost_arr[] = $cost;
                    $img_arr .= '<img class="character-img"
                        src="' . IMG_BASE . htmlspecialchars($ico, ENT_QUOTES, 'UTF-8') . '"
                        loading="lazy">';
                  }
                }
                // 收集 cost
                $cost_arr = array_filter($cost_arr, function ($c) {
                  return is_int($c) || ctype_digit((string)$c);
                });
                // 只有當剛好有三張卡的 cost 時，才計算懲罰
                if (count($cost_arr) === 3) {
                  // 強制轉型保險
                  $c1 = (int)$cost_arr[0];
                  $c2 = (int)$cost_arr[1];
                  $c3 = (int)$cost_arr[2];
                  $punishment = cost_punish(
                    (int)($cost_arr[0] ?? 0),
                    (int)($cost_arr[1] ?? 0),
                    (int)($cost_arr[2] ?? 0)
                  );
                } else {
                  $punishment = 0;
                }

                $cost_sum += $punishment;
                $cost_color = $punishment > 0 ? 'text-danger' : '';

                // 輸出 COST 與圖
                /* print '<button type="button" class="btn btn-light cost-btn ' . $cost_color . '">'
                . 'COST<br>' . $cost_sum
                . '</button>'; */
                print '<div class="characters">' . $img_arr . '</div>';
                print '</div>'; // .team

                // 如果有權限，顯示 player1 操作區

                if ($result == 1) {
                  print '<div class="player-input player1 ">';
                  if ($result_p2_lose == 1) {
                    print '<button type="button" class="btn btn-secondary player-win-btn">勝</button>';
                  } elseif ($result_p2_win == 1) {
                    print '<button type="button" class="btn btn-secondary enemy-win-btn">負</button>';
                  } elseif ($result_p2_tie == 1) {
                    print '<button type="button" class="btn btn-secondary tie-btn">平</button>';
                  }
                  $url1 = 'fight.php?' . http_build_query([
                    'player_name' => $player_name1
                  ]);
                  print renderPlayerName(
                    $player_name1,
                    $permission,
                    [
                      'player_name' => $player_name1,
                      'focus_id' => (int)$id,
                      'server' => $focusServer
                    ]
                  );
                  if ($permission >= 1) {
                    print '<button type="button" class="btn btn-light wr-btn">BP ' . $bp1 . '</button>';
                  } else {
                    print '<button type="button" class="btn btn-light wr-btn">BP ?</button>';
                  }
                  print '</div>';
                } else {
                  print '<div class="player-input player1 ">';
                  if ($permission) {

                    if ($ack1) {
                      print '<button type="button" class="btn btn-secondary skiped-btn">已略</button>';
                    } else {
                      print '<button type="button" class="btn btn-default skip-btn">未判</button>';
                    }
                  }
                  $url1 = 'fight.php?' . http_build_query([
                    'player_name' => $player_name1
                  ]);
                  print renderPlayerName(
                    $player_name1,
                    $permission,
                    [
                      'player_name' => $player_name1,
                      'focus_id' => (int)$id,
                      'server' => $focusServer
                    ]
                  );
                  if ($permission >= 1) {
                    print '<button type="button" class="btn btn-light wr-btn">BP ' . $bp1 . '</button>';
                  } else {
                    print '<button type="button" class="btn btn-light wr-btn">BP ?</button>';
                  }
                  print '</div>';
                }


                print '</div>'; // .team



                // ========== VS & 操作按鈕 ==========
                print '<div class="vs-panel">';
                $caseFocusUrl = 'fight.php?' . http_build_query([
                  'player_name' => $player_name1,
                  'focus_id' => (int)$id,
                  'server' => $focusServer,
                ]) . '#fight-' . (int)$id;
                print '<input type="hidden" name="player_name" value="' . htmlspecialchars($player_name1, ENT_QUOTES) . '">';
                print '<a href="' . htmlspecialchars($caseFocusUrl, ENT_QUOTES, 'UTF-8') . '"
                    class="region-btn queue-focus-link"
                    title="定位到此場對戰">
                      ' . regionIcon($region) . ' ' . $matchCost . '
                    </a>';
                print '<span class="timestamp">' . $showDate . '</span>';
                if ($permission >= 2) {
                  print '<span class="timestamp">' . htmlspecialchars($admin) . '</span><br>';
                }
                print '</div>'; //.vs-panel


                // ==== 組合2 (u1,u2,u3) ====
                $cost_sum = 0;
                $cost_arr = [];
                $img_arr  = '';

                print '<div class="team ' . $bg_class . '">';
                print '<div class="team">';
                foreach (['u1', 'u2', 'u3'] as $col) {
                  $charId = $row[$col];
                  $stmt4 = $db->prepare("SELECT ico, cost FROM unlight WHERE id = :id");
                  $stmt4->execute([':id' => $charId]);
                  $stmt4->setFetchMode(PDO::FETCH_ASSOC);
                  if ($r = $stmt4->fetch()) {
                    $ico  = $r['ico'];
                    $cost = $r['cost'];
                    $cost_sum += $cost;
                    $cost_arr[] = $cost;
                    $img_arr .= '<img class="character-img"
                        src="' . IMG_BASE . htmlspecialchars($ico, ENT_QUOTES, 'UTF-8') . '"
                        loading="lazy">';
                  }
                }
                $punishment = (count($cost_arr) === 3)
                  ? cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2])
                  : 0;
                $cost_sum += $punishment;
                $cost_color = $punishment > 0 ? 'text-danger' : '';

                /* print '<button type="button" class="btn btn-light cost-btn ' . $cost_color . '">'
                . 'COST<br>' . $cost_sum
                . '</button>'; */
                print '<div class="characters">' . $img_arr . '</div>';



                if ($result == 1) {

                  print '<div class="player-input player2">';
                  if ($result_p2_win == 1) {
                    print '<button type="button" class="btn btn-secondary player-win-btn">勝</button>';
                  } elseif ($result_p2_lose == 1) {
                    print '<button type="button" class="btn btn-secondary enemy-win-btn">負</button>';
                  } elseif ($result_p2_tie == 1) {
                    print '<button type="button" class="btn btn-secondary tie-btn">平</button>';
                  }
                  $url2 = 'fight.php?player_name=' . urlencode($player_name2);
                  print renderPlayerName(
                    $player_name2,
                    $permission,
                    [
                      'player_name' => $player_name2,
                      'focus_id' => (int)$id,
                      'server' => $focusServer
                    ]
                  );
                  if ($permission >= 1) {
                    print '<button type="button" class="btn btn-light wr-btn">BP ' . $bp2 . '</button>';
                  } else {
                    print '<button type="button" class="btn btn-light wr-btn">BP ?</button>';
                  }
                  print '</div>';
                } else {
                  print '<div class="player-input player2">';

                  if ($permission) {
                    if ($ack2) {
                      print '<button type="button" class="btn btn-secondary skiped-btn">已略</button>';
                    } else {
                      print '<button type="submit" name="skip2" value="' . $id . '" class="btn btn-default skip-btn">未判</button>';
                    }
                  }
                  $url2 = 'fight.php?player_name=' . urlencode($player_name2);
                  print renderPlayerName(
                    $player_name2,
                    $permission,
                    [
                      'player_name' => $player_name2,
                      'focus_id' => (int)$id,
                      'server' => $focusServer
                    ]
                  );
                  if ($permission >= 1) {
                    print '<button type="button" class="btn btn-light wr-btn">BP ' . $bp2 . '</button>';
                  } else {
                    print '<button type="button" class="btn btn-light wr-btn">BP ?</button>';
                  }
                  print '</div>';
                }


                print '</div>'; // .team
                print '</div>'; // .team
                print '</form>';
                $result = 0;
              } // end while
            }
            // … 先前程式不變 …

            ?>
          </div>
          <!-- /.fight-list -->
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
  document.addEventListener('DOMContentLoaded', function() {
    const toggleBtn = document.querySelector('.js-toggle-json');
    const target = document.getElementById('jsonInput');

    if (!toggleBtn || !target) return;

    toggleBtn.addEventListener('click', () => {
      const isOpen = target.classList.toggle('is-open');

      toggleBtn.setAttribute('aria-expanded', isOpen);

      toggleBtn.innerHTML = isOpen ?
        '📤 收起 JSON' :
        '📥 貼上 JSON';
    });
  });
</script>
<?php
// ⭐ 最後統一輸出成 pageContent 給 template/base.php
$pageContent = ob_get_clean();
include __DIR__ . '/../layout/base.php';
?>