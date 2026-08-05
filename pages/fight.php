<?php
/* session_start(); */
require_once __DIR__ . '/../config.php';   // ⭐ 必須包含資料庫設定

$seoTitle = '對戰紀錄 history | UL.GG 戰績網 UNLIGHT 戰術研究中心'; //瀏覽器標題
$activeMenu = "fight"; //.php
$pageTitleFull = '對戰紀錄 history | UL.GG 戰績網'; //桌機
$pageTitleText = '對戰紀錄 history'; //手機

ob_start();  // ⭐ 開始收集本頁 HTML

// 顯示層：STEAM / DMM
$currentServer = strtoupper($_SESSION["server"] ?? 'ALL');
if (isset($_GET['server'])) {
  $currentServer = strtoupper($_GET['server']);
}
if (!in_array($currentServer, ['STEAM', 'DMM', 'ALL'], true)) {
  $currentServer = 'ALL';
}
$_SESSION['server'] = $currentServer;

// ⭐ DB 實際使用的 region
if ($currentServer === 'ALL') {
  // 不限制 region
  $regionCondition = '';
} else {
  $dbRegion = ($currentServer === 'DMM') ? 'JP' : 'TW';
  $regionCondition = "AND (
      temp.region = :region
      OR temp.region IS NULL
      OR temp.region = 'CR'
      OR temp.region = 'MATCH'
    )
  ";
}

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
    width: 120px;
    padding: 4px;
    border-radius: 10px;
    background: rgba(255, 255, 255, 0.04);
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: center;
    justify-content: center;
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
    flex-direction: row;
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

  .action {
    width: 50px;
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
    /* background: linear-gradient(180deg,
        rgba(255, 255, 255, 0.06),
        rgba(255, 255, 255, 0.02)); */
    border-radius: 10px;
    text-align: center;
    display: flex;
    flex-direction: column;
    gap: 6px;
    position: relative;
    align-items: center;
  }

  /* 勝負標記 */
  .result-pill {
    font-size: 14px;
    font-weight: 700;
    padding: 4px;
    border-radius: 6px;
    letter-spacing: 2px;
  }

  /* =========================
   Result Status (Display)
========================= */

  .result-win {
    background: linear-gradient(180deg, #3fbf7f, #2e8f61);
    color: #ffffff;
  }

  .result-lose {
    background: linear-gradient(180deg, #e05a5a, #b73c3c);
    color: #ffffff;
  }

  .result-tie {
    background: linear-gradient(180deg, #e6c15a, #b79534);
    color: #1a1a1a;
  }

  .result-unknown {
    background: linear-gradient(180deg, #9e9e9e, #6f6f6f);
    color: #ffffff;
  }

  .result-pill::before {
    margin-right: 6px;
    font-weight: bold;
  }

  .result-win::before {
    content: "✔";
  }

  .result-lose::before {
    content: "✖";
  }

  .result-tie::before {
    content: "＝";
  }

  .result-unknown::before {
    content: "？";
  }


  /* =========================
   Set Result (Action)
========================= */
  /* =========================
   Set Pills（操作用）
========================= */

  .set-pill {
    flex: 1;
    /* ⭐ 一行兩顆 */
    height: 38px;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 14px;
    font-weight: 700;
    letter-spacing: 1px;

    border-radius: 8px;
    /* 比藥丸穩 */
    border: 1px solid rgba(255, 255, 255, 0.25);

    background: #2f3542;
    color: #ffffff;

    cursor: pointer;
    user-select: none;

    transition: background 0.15s ease,
      transform 0.1s ease;
  }


  .set-win {
    background: linear-gradient(180deg, #3fbf7f, #2e8f61);
    color: #ffffff;
  }

  .set-lose {
    background: linear-gradient(180deg, #e05a5a, #b73c3c);
    color: #ffffff;
  }

  .set-tie {
    background: linear-gradient(180deg, #e6c15a, #b79534);
    color: #2a2a2a;
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
    padding: 4px;
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
    margin: auto 1px;
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
    margin: auto 2px;
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
    flex-wrap: wrap;
    /* ⭐ 寬度只包內容 */
    gap: 10px;
    padding: 8px 6px;

    /* background: var(--ul-bg-soft); */
    border-radius: 12px;
    /* border: 1px solid var(--ul-border); */
  }

  .counter-wrapper {
    display: flex;
    justify-content: center;
    flex-direction: column;
    align-items: center;
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
    justify-content: center;
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
    justify-content: center;
    align-items: center;
    margin: 0;
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
    /* bottom: -32px; */
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

  .imagecard {
    display: flex;
    flex-direction: row;
    /* margin: auto 5px; */
  }

  /* =========================
   Fight – Mobile Patch v1
========================= */
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
    }

    /* VS 區塊改橫條 */
    .vs-panel {
      width: 100%;
      /* margin: 6px 0; */
      flex-direction: row;
      justify-content: space-between;
      align-items: center;
      order: 2;
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
    .team:first-child {
      order: 1;
    }



    /* 對手 */
    .team:last-child {
      order: 3;
    }

    .vs-actions {
      display: flex;
      /* width: 100%; */
      justify-content: space-around;
      margin-top: 6px;
    }

    .vs-actions .btn {
      flex: 1;
      font-size: 14px;
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
      margin-right: 100px;
      display: flex;
      flex-direction: row-reverse;
    }

    .imagecard2 {
      margin-left: 100px;
    }

    /* .fight-container .imagecard {
      margin: 0;
    } */
    .empty-slot::after {
      bottom: auto;
      top: 100%;
      margin-top: 6px;
      white-space: normal;
      max-width: 220px;
      text-align: center;
    }

    /* .imagecard:has(.privacy-mask) {
      margin-left: 0 !important;
      margin-right: 0 !important;
    } */

  }


  .result-tag {
    display: inline-block;
    min-width: 28px;
    text-align: center;
    padding: 2px 6px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 700;
    line-height: 1.4;
    color: #111;
  }

  .set-tie-btn {
    background: rgb(181, 181, 191);
    border: solid 1px black;
    color: black;
    border-radius: 8px;
    font-weight: bold;
    padding: 3px 8px;
    /* margin: 2px 5px; */
    /* transform: scale(1.1); */
    width: 33px;
  }

  /* 設定標讀按鈕 */
  .skip-btn {
    background: rgb(181, 181, 191);
    border: solid 1px black;
    color: black;
    border-radius: 8px;
    font-weight: bold;
    padding: 3px 8px;
    /* margin: 2px 5px; */
    /* transform: scale(1.1); */
    width: 33px;
  }

  .set-del-btn {
    background: rgb(181, 181, 191);
    border: solid 1px black;
    color: black;
    border-radius: 8px;
    font-weight: bold;
    padding: 3px 8px;
    /* margin: 2px 5px; */
    /* transform: scale(1.1); */
    width: 33px;
  }

  .rank-tw {
    background: rgba(0, 123, 255, .25);
  }

  .rank-jp {
    background: rgba(255, 85, 85, .25);
  }

  .rank-cr {
    background: rgba(200, 200, 200, .25);
  }

  .match-tag {
    font-size: 11px;
    padding: 2px 6px;
    border-radius: 6px;
    background: linear-gradient(180deg, #caa84a, #8e732e);
    color: #111;
    font-weight: 700;
  }

  .vs-admin-row {
    display: flex;
    justify-content: center;
    align-items: center;
    flex-direction: column;
    gap: 6px;
    margin-top: 6px;
    width: 100%;
  }

  .vs-admin-row .vs-actions {
    margin: 0;
  }

  .vs-admin-row .btn-group {
    margin: 0;
  }

  .vs-mini-detail-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 34px;
    padding: 6px 10px;
    border-radius: 999px;
    background: rgba(111, 168, 255, .12);
    border: 1px solid rgba(111, 168, 255, .35);
    color: #e6ecff;
    font-size: 12px;
    font-weight: 800;
    text-decoration: none;
    line-height: 1;
  }

  .vs-mini-detail-btn:hover,
  .vs-mini-detail-btn:focus {
    color: #fff;
    text-decoration: none;
    box-shadow: 0 0 10px rgba(111, 168, 255, .35);
  }

  .vs-stage-wrap {
    display: flex;
    align-items: center;
    flex-direction: column;
  }

  .vs-time-edit-form {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    width: 100%;
  }

  .vs-time-input {
    width: 60px;
    max-width: 132px;
    height: 28px;
    border-radius: 6px;
    border: 1px solid rgba(180, 200, 255, 0.25);
    background: rgba(0, 0, 0, 0.35);
    color: #e6eaf2;
    font-size: 11px;
    padding: 2px 4px;
    box-sizing: border-box;
  }

  .vs-time-save-btn {
    height: 28px;
    min-width: 44px;
    border-radius: 6px;
    border: 1px solid rgba(111, 168, 255, 0.45);
    background: rgba(111, 168, 255, 0.18);
    color: #e6ecff;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    padding: 2px 6px;
    white-space: nowrap;
  }

  .vs-time-save-btn:hover {
    background: rgba(111, 168, 255, 0.32);
    color: #fff;
  }
</style>


<?php

function getShowPrivate(PDO $db, string $username): int
{
  $stmt = $db->prepare("
    SELECT show_private
    FROM game_user
    WHERE (username) = (:u)
    LIMIT 1
  ");
  $stmt->execute([':u' => $username]);
  $val = $stmt->fetchColumn();
  return $val !== false ? (int)$val : 0;
}

function isBlackList(PDO $db, string $username): int
{
  $stmt = $db->prepare("
    SELECT black_list
    FROM game_user
    WHERE (username) = (:u)
    LIMIT 1
  ");
  $stmt->execute([':u' => $username]);
  $val = $stmt->fetchColumn();
  return ((int)$val >= 2);
}

/* 狀態回傳用 */
function getUserWatchStatus(PDO $db, string $username): int
{
  $stmt = $db->prepare("
    SELECT black_list
    FROM game_user
    WHERE username = :u
    LIMIT 1
  ");
  $stmt->execute([':u' => $username]);
  return (int)($stmt->fetchColumn() ?? 0);
}

// ✅ 共用 function（你原本的 function 放下面）
function progress_color($rate)
{
  if ($rate < 25) return 'red';
  if ($rate < 50) return 'yellow';
  if ($rate < 75) return 'green';
  return 'aqua';
}
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

function ulggTeamKey(int $a, int $b, int $c): string
{
  return $a . '-' . $b . '-' . $c;
}

function syncArenaPlayerMatchResult(PDO $db, int $matchId): void
{
  $stmt = $db->prepare("
    SELECT
      id,
      update_time,
      cost,
      e1, e2, e3,
      u1, u2, u3,
      name_p1,
      name_p2,
      bp_p1,
      bp_p2,
      eventindex1,
      eventindex2,
      win,
      lose,
      tie
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
    // arena_unlight win = P2 勝
    $p1Win = 0;
    $p2Win = 1;
  } elseif ($lose === 1) {
    // arena_unlight lose = P2 敗 = P1 勝
    $p1Win = 1;
    $p2Win = 0;
  }

  $rows = [
    [
      'side' => 'P1',
      'player_name' => (string)$m['name_p1'],
      'player_bp' => $m['bp_p1'],
      'leader_id' => (int)$m['e1'],
      'back1_id' => (int)$m['e2'],
      'back2_id' => (int)$m['e3'],
      'team_key' => ulggTeamKey((int)$m['e1'], (int)$m['e2'], (int)$m['e3']),
      'eventindex' => ulggPickEventIndex($m['eventindex1']),
      'is_win' => $p1Win,
    ],
    [
      'side' => 'P2',
      'player_name' => (string)$m['name_p2'],
      'player_bp' => $m['bp_p2'],
      'leader_id' => (int)$m['u1'],
      'back1_id' => (int)$m['u2'],
      'back2_id' => (int)$m['u3'],
      'team_key' => ulggTeamKey((int)$m['u1'], (int)$m['u2'], (int)$m['u3']),
      'eventindex' => ulggPickEventIndex($m['eventindex2']),
      'is_win' => $p2Win,
    ],
  ];

  $sql = "
    INSERT INTO arena_player_match_result
    (
      match_id,
      update_time,
      player_name,
      player_bp,
      side,
      leader_id,
      back1_id,
      back2_id,
      team_key,
      cost,
      eventindex,
      is_win
    )
    VALUES
    (
      :match_id,
      :update_time,
      :player_name,
      :player_bp,
      :side,
      :leader_id,
      :back1_id,
      :back2_id,
      :team_key,
      :cost,
      :eventindex,
      :is_win
    )
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
  $stmt = $db->prepare("
    DELETE FROM arena_player_match_result
    WHERE match_id = :match_id
  ");
  $stmt->execute([':match_id' => $matchId]);
}
function adminUpdateMatchTime(PDO $db, int $matchId, string $newTimeRaw, ?string $judgeSource = null): void
{
  $newTimeRaw = trim($newTimeRaw);

  $dt = DateTime::createFromFormat('Y-m-d\TH:i', $newTimeRaw)
    ?: DateTime::createFromFormat('Y-m-d H:i:s', $newTimeRaw)
    ?: DateTime::createFromFormat('Y-m-d H:i', $newTimeRaw);

  if (!$dt) {
    throw new RuntimeException('時間格式錯誤');
  }

  $newTime = $dt->format('Y-m-d H:i:s');

  $db->beginTransaction();

  try {
    $stmt = $db->prepare("
      SELECT id
      FROM arena_unlight
      WHERE id = :id
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->execute([':id' => $matchId]);

    if (!$stmt->fetchColumn()) {
      throw new RuntimeException('找不到這筆對戰資料');
    }

    $stmt = $db->prepare("
      UPDATE arena_unlight
      SET
        update_time = :new_time,
        judge_source = :judge_source
      WHERE id = :id
    ");
    $stmt->execute([
      ':new_time' => $newTime,
      ':judge_source' => $judgeSource,
      ':id' => $matchId,
    ]);

    $stmt = $db->prepare("
      UPDATE arena_player_match_result
      SET update_time = :new_time
      WHERE match_id = :id
    ");
    $stmt->execute([
      ':new_time' => $newTime,
      ':id' => $matchId,
    ]);

    $db->commit();
  } catch (Throwable $e) {
    if ($db->inTransaction()) {
      $db->rollBack();
    }
    throw $e;
  }
}
function cost_punish(int $id1_cost, int $id2_cost, int $id3_cost)
{
  $punish = 0;
  $diffs = [abs($id1_cost - $id2_cost), abs($id1_cost - $id3_cost), abs($id2_cost - $id3_cost)];
  foreach ($diffs as $diff) {
    if ($diff > 6) $punish += 5;
    if ($diff > 13) $punish += 5;
  }
  return $punish;
}

function getMostUsedEvent(array $arr): array
{
  $counts = [];
  foreach ($arr as $v) {
    // null 單獨計入 'null' 這個 key
    if (is_null($v)) {
      $counts['null'] = ($counts['null'] ?? 0) + 1;
    }
    // 整數當成卡片 ID
    elseif (is_int($v)) {
      $counts[$v] = ($counts[$v] ?? 0) + 1;
    }
  }
  // 如果都沒資料，就回傳空
  if (empty($counts)) {
    return ['id' => null, 'count' => 0];
  }
  arsort($counts);
  $mostKey   = key($counts);
  $mostCount = current($counts);
  return ['id' => $mostKey, 'count' => $mostCount];
}

function regionIcon($region)
{
  switch ($region) {
    case 'TW':
      return '<i class="fab fa-steam" title="Steam 台服"></i>';

    case 'JP':
      return '<span class="server-icon dmm" title="DMM 日服">D</span>';

    case 'CR':
      return '<span class="region-globe" title="跨服">☯</span>';

    case 'MATCH':
      return '<i class="fas fa-trophy" title="比賽專用"></i>';

    default:
      return '';
  }
}


function getRankWithRegion(
  PDO $db,
  string $name,
  string $nextMidnight,
  ?string $region,
  PDOStatement $stmtTW,
  PDOStatement $stmtJP
): string {

  if (!$stmtTW || !$stmtJP) {
    return '';
  }

  // 明確 TW
  if ($region === 'TW') {
    $stmtTW->execute([
      ':next_midnight' => $nextMidnight,
      ':name' => $name
    ]);
    $r = $stmtTW->fetchColumn();
    return $r ? "ST#{$r}" : '';
  }

  // 明確 JP
  if ($region === 'JP') {
    $stmtJP->execute([
      ':next_midnight' => $nextMidnight,
      ':name' => $name
    ]);
    $r = $stmtJP->fetchColumn();
    return $r ? "JP#{$r}" : '';
  }

  // CR / NULL → fallback
  $stmtTW->execute([
    ':next_midnight' => $nextMidnight,
    ':name' => $name
  ]);
  $r = $stmtTW->fetchColumn();
  if ($r) return "ST#{$r}";

  $stmtJP->execute([
    ':next_midnight' => $nextMidnight,
    ':name' => $name
  ]);
  $r = $stmtJP->fetchColumn();
  return $r ? "JP#{$r}" : '';
}


$username = $_SESSION['username'] ?? null;

$permission = $_SESSION["ack"] ?? 0;
$disabled = ($permission >= 2) ? '' : 'disabled title="權限不足"';
$counterStats = [];
//$disabled = '';
/* print "permission=$permission"; */

// ─── ① 事件卡表只查一次，建立 map ───────────────────────────────────────
$eventMapIco     = [];  // [event_id] => ico.png
$eventMapName    = [];  // [event_id] => 卡片名稱
$eventMapSword   = [];  // [event_id] => sword 欄位數值
$eventMapGun     = [];  // [event_id] => gun 欄位數值
$eventMapShield  = [];  // [event_id] => shield 欄位數值
$eventMapShift   = [];  // [event_id] => shift 欄位數值
$eventMapSpecial = [];  // [event_id] => special 欄位數值

$sqlEvt = "SELECT id, ico, name, sword, gun, shield, `shift`, special 
           FROM unlight_eventindex";
foreach ($db->query($sqlEvt, PDO::FETCH_ASSOC) as $r) {
  $id                   = (int)$r['id'];
  $eventMapIco[$id]     = $r['ico']  ?: 'na_event.png';
  $eventMapName[$id]    = $r['name'] ?: '';
  $eventMapSword[$id]   = (int)$r['sword'];
  $eventMapGun[$id]     = (int)$r['gun'];
  $eventMapShield[$id]  = (int)$r['shield'];
  $eventMapShift[$id]   = (int)$r['shift'];
  $eventMapSpecial[$id] = (int)$r['special'];
}


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
  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) .
    '&player2=' . urlencode($_POST['player_name2']));
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
  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) .
    '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['tie'])) {
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
  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
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

  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) .
    '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['del'])) {
  $id = (int)$_POST['del'];

  deleteArenaPlayerMatchResult($db, $id);

  $sql_insert = "DELETE FROM `arena_unlight` WHERE `id` = :id";
  $stmt_insert = $db->prepare($sql_insert);
  $stmt_insert->execute([':id' => $id]);
  $_SESSION['upload_success'] = '大刀一砍！';
  //header('Location: queue.php');      // ← 導回 queue.php
  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1']) . '&player2=' . urlencode($_POST['player_name2']));
  exit();
} elseif (isset($_POST['update_match_time'])) {
  if ($permission < 2) {
    $_SESSION['upload_success'] = '權限不足，只有管理員可以修改時間';
    header('Location: fight.php?player_name=' . urlencode($_POST['player_name1'] ?? '') .
      '&player2=' . urlencode($_POST['player_name2'] ?? ''));
    exit();
  }

  $id = (int)$_POST['update_match_time'];
  $newTime = $_POST['new_update_time'] ?? '';

  try {
    adminUpdateMatchTime($db, $id, $newTime, $username);
    $_SESSION['upload_success'] = '對戰時間已修正，兩張表已同步';
  } catch (Throwable $e) {
    $_SESSION['upload_success'] = '時間修正失敗：' . $e->getMessage();
  }

  header('Location: fight.php?player_name=' . urlencode($_POST['player_name1'] ?? '') .
    '&player2=' . urlencode($_POST['player_name2'] ?? ''));
  exit();
}

// ✅ 讀取 Session 值
$get_player = 0;
$combined = $_SESSION['combined'] ?? 0;
$check = $_SESSION['check'] ?? false;
$check_rate = $_SESSION['check_rate'] ?? '';
$e1 = $_SESSION['e1'] ?? 0;
$e2 = $_SESSION['e2'] ?? 0;
$e3 = $_SESSION['e3'] ?? 0;
$player_name = $_SESSION['player_name'] ?? '';
$bp_min = $_SESSION['bp_min'] ?? 1000;
$bp_max = $_SESSION['bp_max'] ?? 2000;
$player2_name = '';
// ✅ 如果 GET 有 player_name，優先處理並切成單人模式
// -------- ① 處理 GET['player_name']，但是「先驗證權限」 --------
if (isset($_GET['player_name'])) {
  $requestedPlayer = trim($_GET['player_name']);

  $isSelf      = ($requestedPlayer === $username);
  $showPrivate = getShowPrivate($db, $requestedPlayer);
  $isBlack = isBlackList($db, $requestedPlayer);
  $isAdmin     = ($permission === 2);

  $player_name = $requestedPlayer;
  $combined    = 2;
  $_SESSION['player_name'] = $player_name;
  $_SESSION['check_rate']  = '';
  $check       = 1;
  $get_player  = 1;

  $player2_name = '';
  if (isset($_GET['player2'])) {
    $player2_name = trim($_GET['player2']);
  }
} // end if GET player_name
// ✅ 權限設定
/* $username = $_SESSION["username"];
$permission = $_SESSION["ack"] ?? 0;
$disabled = ($permission >= 2) ? '' : 'disabled title="權限不足"'; */

// ✅ PRG：如果是 POST，處理完直接重導回自己，避免 F5 重新送出表單
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // ✅ 一般欄位統一存進 SESSION
  foreach ($_POST as $key => $value) {
    $_SESSION[$key] = $value;
  }
  // ✅ 特別處理 set_team（組隊）
  if (isset($_POST['set_team'])) {
    if ($_POST['set_team'] === '組合1') {
      $_SESSION['e1'] = $_POST['e1'] ?? 0;
      $_SESSION['e2'] = $_POST['e2'] ?? 0;
      $_SESSION['e3'] = $_POST['e3'] ?? 0;
    } elseif ($_POST['set_team'] === '組合2') {
      $_SESSION['u1'] = $_POST['u1'] ?? 0;
      $_SESSION['u2'] = $_POST['u2'] ?? 0;
      $_SESSION['u3'] = $_POST['u3'] ?? 0;
    }
  }
  // ✅ 安全檢查：確保 bp_min / bp_max 一定存在
  $_SESSION['bp_min'] = isset($_POST['bp_min']) ? (int)$_POST['bp_min'] : 1000;
  $_SESSION['bp_max'] = isset($_POST['bp_max']) ? (int)$_POST['bp_max'] : 2000;
  // ✅ 最後導回自己
  header('Location: ' . $_SERVER['PHP_SELF']);
  exit();
}

//$count_en = 0;
// ✅ 只從 SESSION 讀，避免 POST / 空字串污染
$e1 = $_SESSION['e1'] ?? null;
$e2 = $_SESSION['e2'] ?? null;
$e3 = $_SESSION['e3'] ?? null;

// ✅ 僅在三個都存在時才轉型
if ($e1 !== null && $e2 !== null && $e3 !== null) {
  if (is_numeric($e1) && is_numeric($e2) && is_numeric($e3)) {
    $e1 = (int)$e1;
    $e2 = (int)$e2;
    $e3 = (int)$e3;

    // 回寫 SESSION（安全）
    $_SESSION['e1'] = $e1;
    $_SESSION['e2'] = $e2;
    $_SESSION['e3'] = $e3;
  }
}

$u1 = $_SESSION['u1'] ?? $_POST['u1'] ?? 0;
$u2 = $_SESSION['u2'] ?? $_POST['u2'] ?? 0;
$u3 = $_SESSION['u3'] ?? $_POST['u3'] ?? 0;

$u1 = is_numeric($u1) ? (int)$u1 : 0;
$u2 = is_numeric($u2) ? (int)$u2 : 0;
$u3 = is_numeric($u3) ? (int)$u3 : 0;

$_SESSION['u1'] = $u1;
$_SESSION['u2'] = $u2;
$_SESSION['u3'] = $u3;

$stages = [
  -1    => '全部',
  0    => '雷城',
  1    => '誘森',
  2    => '垃圾',
  3    => '冰封',
  4    => '人魂',
  5    => '盡村',
  6    => '風暴',
  7    => '峰亥盧',
  8    => '魔都',
  9    => '狂山',
  10   => '魔女山谷',
  11   => '隨機',
  12   => '烏波斯黑湖',
];
/* print ";e1=$e1";
print ";e2=$e2";
print ";e3=$e3";
print ";check=$check"; */
// 保留所有 GET 參數
$query = $_GET;

// 只替換 server
$query['server'] = 'STEAM';
$steamUrl = '?' . http_build_query($query);

$query['server'] = 'DMM';
$dmmUrl = '?' . http_build_query($query);

$query['server'] = 'ALL';
$allUrl = '?' . http_build_query($query);




$_SESSION['id1'] = (int)($_SESSION['e1'] ?? 0);
$_SESSION['id2'] = (int)($_SESSION['e2'] ?? 0);
$_SESSION['id3'] = (int)($_SESSION['e3'] ?? 0);

$isPlayerMode = ($combined == 2 && !empty($player_name));
?>
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

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container">
      <div class="row">
        <div class="col-md-12" style="float:none; margin:0 auto;">
          <div class="sidebar-server-switcher">
            <span class="server-label"> 伺服器</span>

            <button class="server-btn" onclick="toggleServerMenu()">
              <?php if ($currentServer === 'DMM'): ?>
                <span class="server-icon dmm">D</span> DMM ▾
              <?php elseif ($currentServer === 'ALL'): ?>
                🌐 全部 ▾
              <?php else: ?>
                <i class="fab fa-steam"></i> Steam ▾
              <?php endif; ?>
            </button>

            <div class="server-menu" id="serverMenu">
              <a href="<?= htmlspecialchars($steamUrl) ?>"
                class="server-option <?= $currentServer === 'STEAM' ? 'active' : '' ?>">
                <i class="fab fa-steam"></i> Steam
              </a>

              <a href="<?= htmlspecialchars($dmmUrl) ?>"
                class="server-option <?= $currentServer === 'DMM' ? 'active' : '' ?>">
                <span class="server-icon dmm">D</span> DMM
              </a>


              <a href="<?= htmlspecialchars($allUrl) ?>"
                class="server-option <?= $currentServer === 'ALL' ? 'active' : '' ?>">
                🌐 全部
              </a>

            </div>
            <?php if ($combined == 0): ?>
              <a href="/pages/team_analysis.php" class="skip2-btn">
                📊 隊伍分析
              </a>
            <?php endif; ?>

          </div>


          <?php
          $outPutString = '';
          if ($check == 1) {
            //print '<div class="main"></div>';
            if (!isset($_SESSION["daterange"])) {
              $today = date("Y-m-d");
              $_SESSION["daterange"] = "$today - $today"; // 預設值為今天
            }

            $current_daterange = $_SESSION["daterange"];
            list($start_date, $end_date) = explode(" - ", $current_daterange);
            $end_date = date("Y-m-d", strtotime($end_date)) . " 23:59:59";


            // 2. 其他地方如果要顯示勝率，記得判斷

            // 勝率顯示判斷
            if ($combined === 0 && isset($check_rate) && $check_rate !== '') {
              $rate_color = progress_color($check_rate);

              echo '
              <div class="ul-rate-wrap">
                <div class="ul-rate-bar ul-rate-' . $rate_color . '" style="width:' . (int)$check_rate . '%;">
                  <span class="ul-rate-text">勝率 ' . (int)$check_rate . '%</span>
                </div>
              </div>';
            }


            $flag = 1;
            $use_flag = 1;
            $index = 0; // 追蹤行數

            /* print "combined=$combined";
      print "player_name=$player_name"; */
            if ($permission >= 2) {
              $time_range = 0;
              $match_range = 100;
            } else {
              $time_range = 0; //延後x分鐘
              $match_range = 20; //顯示場數
            }
            // ✅ 黑名單玩家也享有不延遲顯示（等同管理員視角）
            if ($combined == 2 && !empty($player_name)) {
              $isTargetBlack = isBlackList($db, $player_name);
              if ($isTargetBlack) {
                $time_range = 0;     // 取消 60 分鐘延遲
                $match_range = 50;  // 放寬場數
              }
            }
            // 玩家搜尋
            if ($combined == 2) {
              // 這裡新增：目標玩家若在黑名單，放寬延遲與數量
              $isTargetBlack = isBlackList($db, $player_name);
              if ($isTargetBlack) {
                $time_range = 0;     // 取消 60 分鐘延遲
                $match_range = 50;  // 放寬場數
              }
              $player2Condition = '';

              if (!empty($player2_name)) {
                $player2Condition = "AND temp.player_name2 = :player2_name";
                $sql = "SELECT *
                  FROM (
                    SELECT * FROM (
                        SELECT 
                            id,cost,stage,region,e1, e2, e3, u1, u2, u3, win, lose, tie, update_time, username,
                            name_p1 AS player_name1, bp_p1 AS bp1,eventindex1 as eventindex1, eventindex2 as eventindex2,
                            name_p2 AS player_name2, bp_p2 AS bp2, ack1, ack2,win_p1,draw_p1,lose_p1
                        FROM arena_unlight
                        UNION ALL 
                        SELECT 
                            id,cost,stage,region,u1, u2, u3, e1, e2, e3, lose, win, tie, update_time, username,
                            name_p2 AS player_name1, bp_p2 AS bp1,eventindex2 as eventindex1, eventindex1 as eventindex2, 
                            name_p1 AS player_name2, bp_p1 AS bp2, ack1, ack2, win_p2,draw_p2,lose_p2
                        FROM arena_unlight
                    ) AS temp
                  WHERE temp.player_name1 = :player_name
                    $player2Condition
                    AND temp.update_time < (NOW() - INTERVAL :time_range MINUTE)
                    $regionCondition
                  ORDER BY temp.update_time DESC
                  LIMIT $match_range
                  ) AS recent
                -- 最後再把這 N 筆反向排序為 ASC
                ORDER BY recent.update_time ASC
                ";

                $params = [
                  ':player_name' => $player_name,
                  ':player2_name' => $player2_name,
                  ':time_range'  => $time_range,
                ];
              } else {
                $sql = "SELECT *
                  FROM (
                    SELECT * FROM (
                        SELECT 
                            id,cost,stage,region,e1, e2, e3, u1, u2, u3, win, lose, tie, update_time, username,
                            name_p1 AS player_name1, bp_p1 AS bp1,eventindex1 as eventindex1, eventindex2 as eventindex2,
                            name_p2 AS player_name2, bp_p2 AS bp2, ack1, ack2,win_p1,draw_p1,lose_p1
                        FROM arena_unlight
                        UNION ALL 
                        SELECT 
                            id,cost,stage,region,u1, u2, u3, e1, e2, e3, lose, win, tie, update_time, username,
                            name_p2 AS player_name1, bp_p2 AS bp1,eventindex2 as eventindex1, eventindex1 as eventindex2, 
                            name_p1 AS player_name2, bp_p1 AS bp2, ack1, ack2, win_p2,draw_p2,lose_p2
                        FROM arena_unlight
                    ) AS temp
                  WHERE temp.player_name1 = :player_name
                    AND temp.update_time < (NOW() - INTERVAL :time_range MINUTE)
                    $regionCondition
                  ORDER BY temp.update_time DESC
                  LIMIT $match_range
                  ) AS recent
                -- 最後再把這 N 筆反向排序為 ASC
                ORDER BY recent.update_time ASC
                ";

                $params = [
                  ':player_name' => $player_name,
                  ':time_range'  => $time_range,
                ];
              }


              if ($currentServer !== 'ALL') {
                $params[':region'] = $dbRegion;
              }
            } elseif ($combined == 0) { // 3人組
              $sql = "SELECT *
                FROM (SELECT * FROM (
                    SELECT 
                        id,cost,stage,region,e1, e2, e3, u1, u2, u3, win, lose, tie, update_time, username,
                        name_p1 AS player_name1, bp_p1 AS bp1,eventindex1 as eventindex1, eventindex2 as eventindex2,
                        name_p2 AS player_name2, bp_p2 AS bp2, ack1, ack2, win_p1,draw_p1,lose_p1
                    FROM arena_unlight
                    UNION ALL 
                    SELECT 
                        id,cost,stage,region,u1, u2, u3, e1, e2, e3, lose, win, tie, update_time, username,
                        name_p2 AS player_name1, bp_p2 AS bp1,eventindex2 as eventindex1, eventindex1 as eventindex2,
                        name_p1 AS player_name2, bp_p1 AS bp2, ack1, ack2, win_p2,draw_p2,lose_p2
                    FROM arena_unlight
                ) AS temp
                WHERE ((temp.e1 = :e1 AND temp.e2 = :e2 AND temp.e3 = :e3)
                OR(temp.e1 = :e1 AND temp.e2 = :e3 AND temp.e3 = :e2))
                  AND temp.update_time < (NOW() - INTERVAL :time_range MINUTE)
                  AND temp.update_time BETWEEN :start_date AND :end_date
                  AND temp.bp1 BETWEEN :bp_min AND :bp_max
                  $regionCondition
                ORDER BY temp.update_time DESC
                LIMIT $match_range) AS recent
              -- 最後再把這 N 筆反向排序為 ASC
              ORDER BY recent.update_time ASC
              ";

              $params = [
                ':e1' => $e1,
                ':e2' => $e2,
                ':e3' => $e3,
                ':time_range' => $time_range,
                ':start_date' => $start_date,
                ':end_date' => $end_date,
                ':bp_min' => $bp_min,
                ':bp_max' => $bp_max,
              ];

              if ($currentServer !== 'ALL') {
                $params[':region'] = $dbRegion;
              }
            }


            // --- 這裡不變 ---
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $bp1_pre = 0;
            $unknown_pre = 0;
            $opponent_bp = 0;
            $bp_after = 1500;
            $result_print = '';
            $wdl = '';
            // 準備一條 COALESCE + 兩個子查詢的 SQL
            // 在 while 迴圈外先準備好兩個預處理
            $stmtRank = $db->prepare(
              <<<'SQL'
              SELECT COALESCE(
                ( SELECT rank_num
                    FROM ranking_bp_TW
                  WHERE ts = :next_midnight AND name = :name
                  ORDER BY ts ASC
                  LIMIT 1
                )
              ) AS rank_num
            SQL
            );

            $stmtRank_JP = $db->prepare(
              <<<'SQL'
              SELECT COALESCE(
                ( SELECT rank_num
                    FROM ranking_bp_JP
                  WHERE ts = :next_midnight AND name = :name
                  ORDER BY ts ASC
                  LIMIT 1
                )
              ) AS rank_num
            SQL
            );
            $outPutString .= '<div class="fight-all">';
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
              $use_flag = 0;
              $flag = 0;
              $bg_class = ($index % 2 == 0) ? "gray-bg" : "white-bg"; // 設定背景顏色
              $index++; // 增加索引計數
              $id = $row['id'];
              $costValue = (int)$row['cost'];
              $costRule = $costValue . 'C';

              if ($costValue >= 90) {
                $costRule = '90+';
              }
              $stage = (int)$row['stage'];
              $showStage = isset($stages[$stage])
                ? $stages[$stage]
                : '未知場景';

              $win         = $row['win'];
              $lose        = $row['lose'];
              $tie         = $row['tie'];
              //$date = $row[8];
              $rawDate  = $row['update_time'];
              $nextMidnight = date('Y-m-d 00:00:00', strtotime($rawDate . ' +1 day'));

              $player_name1 = (string) ($row['player_name1'] ?? '');
              $bp1          = $row['bp1'];
              $player_name2 = (string) ($row['player_name2'] ?? '');
              $bp2          = $row['bp2'];
              $eventindex1 = $row['eventindex1'];
              $eventindex2 = $row['eventindex2'];
              $ack1 = $row['ack1'];
              $ack2 = $row['ack2'];
              $win_p1 = $row['win_p1'];
              $draw_p1 = $row['draw_p1'];
              $lose_p1 = $row['lose_p1'];
              $region = $row['region'];
              $isMatch = ($region === 'MATCH');
              //print $eventindex1.'<br>';

              // 查 P1 的排名
              $rankNumP1 = getRankWithRegion(
                $db,
                $player_name1,
                $nextMidnight,
                $region,
                $stmtRank,
                $stmtRank_JP
              );

              $rankNumP2 = getRankWithRegion(
                $db,
                $player_name2,
                $nextMidnight,
                $region,
                $stmtRank,
                $stmtRank_JP
              );


              // 事先給預設，避免未定義
              $primaryId  = null;
              $primaryId2 = null;

              // 解析 JSON
              $arr1 = json_decode($row['eventindex1'], true);
              $arr2 = json_decode($row['eventindex2'], true);
              if (!is_array($arr1)) $arr1 = [];
              if (!is_array($arr2)) $arr2 = [];

              // 取得最常用 event
              $evt1 = getMostUsedEvent($arr1);
              $evt2 = getMostUsedEvent($arr2);

              // ─── 處理 evt1 ────────────────────────────────────

              // 1. 統計
              $typeCounts = array_fill_keys(['sword', 'gun', 'shield', 'shift', 'special'], 0);
              $numCounts  = array_fill_keys(['sword', 'gun', 'shield', 'shift', 'special'], []);
              foreach ($arr1 as $raw) {
                if ($raw === 'null' || is_null($raw)) continue;
                $eid = (int)$raw;
                foreach (['Sword', 'Gun', 'Shield', 'Shift', 'Special'] as $T) {
                  $mapVar = 'eventMap' . $T;
                  $v      = ${$mapVar}[$eid] ?? 0;
                  if ($v > 0) {
                    $key = strtolower($T);
                    $typeCounts[$key]++;
                    $numCounts[$key][$v] = ($numCounts[$key][$v] ?? 0) + 1;
                    // break; // 如果一張卡只算一次屬性
                  }
                }
              }

              $maxCount  = 0;
              $maxType   = null;
              $maxSubKey = null;

              foreach ($numCounts as $type => $subCounts) {
                foreach ($subCounts as $subKey => $cnt) {
                  if ($cnt > $maxCount) {
                    $maxCount  = $cnt;
                    $maxType   = $type;
                    $maxSubKey = $subKey;
                  }
                }
              }

              $id1   = (int)$evt1['id'];
              $count1 = $evt1['count'];

              // 從 typeCounts 裡取這個 id 的次數，若不存在就當 0
              //$limit1 = $typeCounts[$id1] ?? 0;
              if ($count1 > 9 || $count1 > $maxCount) {
                // 出現夠多，直接顯示
                if ($evt1['id'] === 'null' || is_null($evt1['id'])) {
                  $ico1  = '';
                  $name1 = 'NULL';
                } else {
                  // 普通顯示
                  $ico1  = $eventMapIco[$id1]  ?? 'na_event.png';
                  $name1 = $eventMapName[$id1] ?? '?';
                }
              } else {
                // 2. 找 maxType / majorNum
                $keys = array_keys($typeCounts, max($typeCounts), true);
                $maxType = $keys[0] ?? null;
                $majorNum = null;

                if ($maxType !== null && !empty($numCounts[$maxType])) {
                  arsort($numCounts[$maxType]);
                  $majorNum = key($numCounts[$maxType]);
                }

                // 3. 收集符合「主屬性 = majorNum && 其他 = 0」
                if ($maxType !== null && $majorNum !== null) {
                  $allMaps = [
                    'sword' => $eventMapSword,
                    'gun' => $eventMapGun,
                    'shield' => $eventMapShield,
                    'shift' => $eventMapShift,
                    'special' => $eventMapSpecial,
                  ];
                  $mainMap   = $allMaps[$maxType];
                  $otherMaps = $allMaps;
                  unset($otherMaps[$maxType]);

                  $filtered = [];
                  foreach ($mainMap as $eid => $val) {
                    if ($val !== $majorNum) continue;
                    $ok = true;
                    foreach ($otherMaps as $map) {
                      if (($map[$eid] ?? 0) > 0) {
                        $ok = false;
                        break;
                      }
                    }
                    if ($ok) $filtered[] = $eid;
                  }

                  // 4. 計算出現頻率（含未出現＝0）並排序
                  $jsonCounts = [];
                  foreach ($arr1 as $r) {
                    if (is_int($r) || is_string($r)) {
                      $jsonCounts[(string)$r] = ($jsonCounts[(string)$r] ?? 0) + 1;
                    }
                  }
                  $fullCounts = [];
                  foreach ($filtered as $eid) {
                    $fullCounts[$eid] = $jsonCounts[(string)$eid] ?? 0;
                  }
                  arsort($fullCounts);
                  $primaryId = key($fullCounts) ?: null;
                }

                // 5. 設定 icon/name
                if ($primaryId) {
                  $ico1  = $eventMapIco[$primaryId];
                  $name1 = $eventMapName[$primaryId];
                } else {
                  $ico1  = '';
                  $name1 = '';
                }
              }

              // ─── 處理 evt2 ────────────────────────────────────
              // 1. 統計
              $typeCounts = array_fill_keys(['sword', 'gun', 'shield', 'shift', 'special'], 0);
              $numCounts  = array_fill_keys(['sword', 'gun', 'shield', 'shift', 'special'], []);
              //$numCounts[$key][$v]=0;
              foreach ($arr2 as $raw) {
                // 跳過 null
                if ($raw === 'null' || $raw === null || trim($raw) === '') {
                  continue;
                }
                $eid = (int)$raw;

                // 五個屬性的對映表都一樣的迴圈邏輯
                foreach (['Sword', 'Gun', 'Shield', 'Shift', 'Special'] as $T) {
                  $mapVar = 'eventMap' . $T;
                  $v      = ${$mapVar}[$eid] ?? 0;
                  if ($v > 0) {
                    $key = strtolower($T);

                    // 這裡保證 key 已經存在，所以++ 不會炸
                    $typeCounts[$key]++;

                    // 這裡保證 numCounts[$key] 已經是陣列
                    if (! isset($numCounts[$key][$v])) {
                      $numCounts[$key][$v] = 0;
                    }
                    $numCounts[$key][$v]++;

                    // 如果一張卡只想算一次屬性，就 break 掉
                    // break;
                  }
                }
              }
              $maxCount  = 0;
              $maxType   = null;
              $maxSubKey = null;

              foreach ($numCounts as $type => $subCounts) {
                foreach ($subCounts as $subKey => $cnt) {
                  if ($cnt > $maxCount) {
                    $maxCount  = $cnt;
                    $maxType   = $type;
                    $maxSubKey = $subKey;
                  }
                }
              }
              // 同理做第二組
              $id2    = $evt2['id'];
              $count2 = $evt2['count'];
              if ($count2 > 9 || $count2 > $maxCount) {
                // 出現夠多，直接顯示
                if ($evt2['id'] === 'null' || is_null($evt2['id'])) {
                  $ico2  = '';
                  $name2 = 'NULL';
                } else {
                  //$id2   = (int)$evt2['id'];
                  $ico2  = $eventMapIco[$id2]  ?? 'na_event.png';
                  $name2 = $eventMapName[$id2] ?? '?';
                }
              } else {
                // 2. 找 maxType / majorNum
                $keys = array_keys($typeCounts, max($typeCounts), true);
                $maxType = $keys[0] ?? null;
                $majorNum = null;

                if ($maxType !== null && !empty($numCounts[$maxType])) {
                  arsort($numCounts[$maxType]);
                  $majorNum = key($numCounts[$maxType]);
                }

                // 3. 收集符合「主屬性 = majorNum && 其他 = 0」
                if ($maxType !== null && $majorNum !== null) {
                  $allMaps = [
                    'sword' => $eventMapSword,
                    'gun' => $eventMapGun,
                    'shield' => $eventMapShield,
                    'shift' => $eventMapShift,
                    'special' => $eventMapSpecial,
                  ];
                  $mainMap   = $allMaps[$maxType];
                  $otherMaps = $allMaps;
                  unset($otherMaps[$maxType]);

                  $filtered = [];
                  foreach ($mainMap as $eid => $val) {
                    if ($val !== $majorNum) continue;
                    $ok = true;
                    foreach ($otherMaps as $map) {
                      if (($map[$eid] ?? 0) > 0) {
                        $ok = false;
                        break;
                      }
                    }
                    if ($ok) $filtered[] = $eid;
                  }

                  // 4. 計算出現頻率（含未出現＝0）並排序
                  $jsonCounts = [];
                  foreach ($arr2 as $r) {
                    if (is_int($r) || is_string($r)) {
                      $jsonCounts[(string)$r] = ($jsonCounts[(string)$r] ?? 0) + 1;
                    }
                  }
                  $fullCounts = [];
                  foreach ($filtered as $eid) {
                    $fullCounts[$eid] = $jsonCounts[(string)$eid] ?? 0;
                  }
                  arsort($fullCounts);
                  $primaryId = key($fullCounts) ?: null;
                }

                // 5. 設定 icon/name
                if ($primaryId) {
                  $ico2  = $eventMapIco[$primaryId];
                  $name2 = $eventMapName[$primaryId];
                } else {
                  $ico2  = '';
                  $name2 = '';
                }
              }

              $showDate = date('m-d H:i', strtotime($rawDate));
              $unknown = 0;
              if ($win + $lose + $tie == 0) { //未判定
                $unknown = 1;
              }
              if ($permission >= 2) {
                $wdl = $win_p1 . '/' . $draw_p1 . '/' . $lose_p1;
              }
              if ($unknown == 0 && $unknown_pre == 0) {
                $wdl = '';
              }
              if ($bp1_pre) {
                $result = ($bp1 - $bp1_pre >= 5) ? 1 : (($bp1 - $bp1_pre <= -5) ? 0 : 0.5);
                //$result_print = ($bp1 - $bp1_pre >= 10) ? "勝" : (($bp1 - $bp1_pre <= -10) ? "負" : "平");
                // 將結果套上顏色
                // 套用顏色
                switch ($result) {
                  case "1":
                    $result_print = '<span class="result-tag set-win" style="color:white;">勝</span>';
                    $result_print_p2 = '<span class="result-tag set-lose" style="color:white;">負</span>';
                    break;
                  case "0":
                    $result_print = '<span class="result-tag set-lose" style="color:white;">負</span>';
                    $result_print_p2 = '<span class="result-tag set-win" style="color:white;">勝</span>';
                    break;
                  default:
                    $result_print = '<span class="result-tag set-tie" style="color:white;">平</span>';
                    $result_print_p2 = '<span class="result-tag set-tie" style="color:white;">平</span>';
                    break;
                }
                $bp_after = round($bp1_pre + 32 * ($result - 1 / (1 + pow(10, (($opponent_bp - $bp1_pre) / 500)))), 0);
              }
              // 假設 $win==1 代表 Team2 勝，否則視為敗
              $teamWon = (((int)$win) === 1);
              //print "teamWon=$teamWon";
              $opponents = [$row['u1'], $row['u2'], $row['u3']];
              foreach ($opponents as $cid) {
                if (!isset($counterStats[$cid])) {
                  $counterStats[$cid] = ['wins' => 0, 'losses' => 0];
                }
                if ($teamWon) {
                  $counterStats[$cid]['wins']++;
                } else {
                  $counterStats[$cid]['losses']++;
                }
              }

              $show_private1 = getShowPrivate($db, $player_name1);
              $show_private2 = getShowPrivate($db, $player_name2);
              $watchStatus1 = getUserWatchStatus($db, $player_name1);
              $isBlackList1 = ($watchStatus1 >= 2);
              $isWatchList1 = ($watchStatus1 === 1);

              //$isBlackList2 = isBlackList($db, $player_name2);
              $watchStatus2 = getUserWatchStatus($db, $player_name2);
              $isBlackList2 = ($watchStatus2 >= 2);
              $isWatchList2 = ($watchStatus2 === 1);

              $cost_sum_team1 = 0;
              $cost_sum_team2 = 0;
              //$outPutString .= '<div class="fight-form">';
              if ($unknown == 0 || $permission >= 2) { //已判定 或 權限=admin
                $outPutString .= '<div class="fight-container ' . $bg_class . '">'; // ✅ 加入背景顏色

                $cost_sum = 0;
                $img_arr = '';
                $punishment = 0;
                $cost_color = '';
                unset($cost_arr);
                // 組合1組合

                $outPutString .= '<div class="team">';

                for ($i = 1; $i <= 3; $i++) {

                  // 安全取值，避免 Undefined index
                  $cid = (int)($row["e{$i}"] ?? 0);
                  if ($cid <= 0) {
                    continue;
                  }

                  // Prepared Statement
                  $stmtChar = $db->prepare("SELECT ico, cost FROM unlight WHERE id = ?");
                  $stmtChar->execute([$cid]);

                  $row1 = $stmtChar->fetch(PDO::FETCH_ASSOC);
                  if (!$row1) {
                    continue;
                  }

                  $ico  = $row1['ico'];
                  $cost = (int)$row1['cost'];

                  $cost_sum += $cost;
                  $cost_arr[] = $cost;

                  $img_arr .= '
                  <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
                    <img class="character-img"
                        src="' . IMG_BASE . htmlspecialchars($ico, ENT_QUOTES, 'UTF-8') . '"
                        loading="lazy">
                  </div>';
                }


                // ❗懲罰應該在 cost_arr 三張都抓完再計算
                if (count($cost_arr) === 3) {
                  $punishment = cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2]);
                  $cost_sum += $punishment;
                  $cost_color = ($punishment > 0) ? 'text-danger' : '';
                } else {
                  $punishment = 0;
                  $cost_color = '';
                }

                $outPutString .= '<div class="imagecard imagecard1">';

                $canViewPlayer1 =
                  $isMatch ||               // ⭐ 比賽專用 → 無條件可看
                  $permission >= 2 ||
                  $show_private1 ||
                  $player_name1 == $username ||
                  $isBlackList1;

                // ─────────────── 權限遮罩卡 ───────────────
                if ($combined == 2 && !$canViewPlayer1) {
                  $outPutString .= '<div class="costEvent">';
                  // === Event Card (Team 1) ===
                  $outPutString .= '<span class="event-slot-empty"></span>';
                  $outPutString .= '</div>';

                  $outPutString .= '<div class="characters">';
                  $outPutString .= '<div class="empty-container privacy-mask">';

                  for ($i = 0; $i < 3; $i++) {
                    $outPutString .= '
                        <div class="empty-slot">
                          <i class="fa fa-lock"></i>
                        </div>
                    ';
                  }

                  $outPutString .= '</div>'; // .empty-container
                  $outPutString .= '</div>'; // .characters
                } else {
                  $outPutString .= '<div class="costEvent">';
                  // === Event Card (Team 1) ===
                  if (!empty($ico1)) {
                    $outPutString .=
                      '<img src="' . IMG_BASE . htmlspecialchars($ico1, ENT_QUOTES, 'UTF-8') . '" ' .
                      'class="event" title="' . htmlspecialchars($name1 ?? '', ENT_QUOTES, 'UTF-8') . '" />';
                  } else {
                    $outPutString .= '<span class="event-slot-empty"></span>';
                  }
                  // 如果沒圖就不印
                  $outPutString .= '</div>';
                  $outPutString .= '<div class="characters">';
                  $outPutString .= $img_arr;
                  $outPutString .= '</div>'; // characters
                }
                $outPutString .= '</div>'; // class="imagecard"=event+character
                $outPutString .= '<div class="player-card player-card1">';

                /* ===== 玩家連結 / 名稱 ===== */
                $search_link = 'fight.php?player_name=' . rawurlencode($player_name1);
                $rank_text   = isset($rankNumP1) ? (string)$rankNumP1 : '';
                $rank_badge  = $rank_text !== ''
                  ? '<span class="rank-badge">' . htmlspecialchars($rank_text, ENT_QUOTES, 'UTF-8') . '</span> '
                  : '';

                if ($permission >= 2 || $show_private1 || $player_name1 == $username || $isBlackList1 || $combined == 2) {

                  if ($unknown == 1) {
                    $resultType = 'unknown';
                  } elseif ($tie == 1) {
                    $resultType = 'tie';
                  } elseif ($lose == 1) {
                    $resultType = 'win';
                  } else {
                    $resultType = 'lose';
                  }
                  if ($isBlackList1) {
                    $playerClass = 'player-name blacklist-name';
                    $playerTitle = '黑名單玩家';
                  } elseif ($isWatchList1) {
                    $playerClass = 'player-name watchlist-name';
                    $playerTitle = '此玩家為待觀察狀態';
                  } else {
                    $playerClass = 'player-name';
                    $playerTitle = '';
                  }
                  if ($permission >= 2 && ($win + $lose + $tie) < 1) {

                    // 狀態顯示（button）
                    $outPutString .= '<button type="button" class="btn btn-light wr-btn result-' . $resultType . '">' .
                      ['unknown' => '未判', 'tie' => '平', 'win' => '勝', 'lose' => '負'][$resultType] .
                      '<br>' . $wdl . '</button>';

                    // 判定表單
                    $outPutString .= '<form action="queue.php" method="POST" style="display:flex;">';
                    $outPutString .= '<button type="submit" name="set_win1" value="' . $id . '" class="action set-pill set-win">判勝</button>';
                    $outPutString .= '<button type="submit" name="set_win2" value="' . $id . '" class="action set-pill set-lose">判負</button>';
                    $outPutString .= '
                      <input type="hidden" name="player_name1" value="' . htmlspecialchars($player_name1, ENT_QUOTES) . '">
                      <input type="hidden" name="player_name2" value="' . htmlspecialchars($player_name2, ENT_QUOTES) . '">
                    ';
                    $outPutString .= '</form>';
                  } else {

                    // 一般顯示（div）
                    $outPutString .= '<div class="result-pill result-' . $resultType . '">' .
                      ['unknown' => '未判', 'tie' => '平', 'win' => '勝', 'lose' => '負'][$resultType] .
                      '<br>' . $wdl . '</div>';
                  }
                  $outPutString .=
                    '<a href="' . htmlspecialchars($search_link, ENT_QUOTES, 'UTF-8') . '" 
                    class="' . $playerClass . '" 
                    title="' . $playerTitle . '">' .
                    $rank_badge .
                    htmlspecialchars($player_name1, ENT_QUOTES, 'UTF-8') .
                    '</a>';



                  /* ===== BP 顯示（有權限） ===== */
                  if ($unknown_pre == 1 && $permission >= 2) {
                    if ($result == 1) {
                      $outPutString .=
                        '<div class="bp-box" style="color: #80c880;">'
                        . '<span class="bp-label">BP</span>'
                        . '<span class="bp-value">'
                        . htmlspecialchars($bp1, ENT_QUOTES, 'UTF-8')
                        . '</span><br>'
                        . '<span class="bp-delta">'
                        . $result_print . '(' . htmlspecialchars($bp_after, ENT_QUOTES, 'UTF-8') . ')'
                        . '</span>'
                        . '</div>';
                    } elseif ($result == 0) {
                      $outPutString .=
                        '<div class="bp-box"  style="color: red;">'
                        . '<span class="bp-label">BP</span>'
                        . '<span class="bp-value">'
                        . htmlspecialchars($bp1, ENT_QUOTES, 'UTF-8')
                        . '</span><br>'
                        . '<span class="bp-delta">'
                        . '(' . htmlspecialchars($bp_after, ENT_QUOTES, 'UTF-8') . ')' . $result_print
                        . '</span>'
                        . '</div>';
                    } else { //平手
                      $outPutString .=
                        '<div class="bp-box"  style="color: yellow;">'
                        . '<span class="bp-label">BP</span>'
                        . '<span class="bp-value">'
                        . htmlspecialchars($bp1, ENT_QUOTES, 'UTF-8')
                        . '</span><br>'
                        . '<span class="bp-delta">'
                        . '(' . htmlspecialchars($bp_after, ENT_QUOTES, 'UTF-8') . ')' . $result_print
                        . '</span>'
                        . '</div>';
                    }
                  } else {
                    $outPutString .=
                      '<div class="bp-box">'
                      . '<span class="bp-label">BP</span>'
                      . '<span class="bp-value">'
                      . htmlspecialchars($bp1, ENT_QUOTES, 'UTF-8')
                      . '</span>'
                      . '</div>';
                  }
                } else {
                  /* ===== 勝負狀態 ===== */
                  if ($unknown == 1) {
                    $outPutString .= '<div class="result-pill result-unknown">未判<br>' . $wdl . '</div>';
                  } elseif ($tie == 1) {
                    $outPutString .= '<div class="result-pill result-tie">平<br>' . $wdl . '</div>';
                  } elseif ($lose == 1) {
                    $outPutString .= '<div class="result-pill result-win">勝<br>' . $wdl . '</div>';
                  } else {
                    $outPutString .= '<div class="result-pill result-lose">負<br>' . $wdl . '</div>';
                  }

                  /* ===== BP 遮罩（無權限） ===== */
                  $bp_str = (string)$bp1;
                  $bp_masked = (strlen($bp_str) >= 2) ? substr($bp_str, 0, -2) . 'XX' : 'XX';

                  if (strlen($rank_text) > 0) {
                    $outPutString .= '<span class="player-name">' . $rank_badge . '</span>';
                  }

                  $outPutString .=
                    '<div class="bp-box">'
                    . '<span class="bp-label">BP</span>'
                    . '<span class="bp-value">' . htmlspecialchars($bp_masked, ENT_QUOTES, 'UTF-8') . '</span>'
                    . '</div>';
                }

                $outPutString .= '</div>'; //player-card


                $cost_sum = 0;
                $img_arr = '';
                $punishment = 0;
                $cost_color = '';
                unset($cost_arr);

                $outPutString .= '</div>';

                // ================================
                // VS 區塊（新視覺）
                // ================================
                $outPutString .= '<div class="vs-panel">';

                /* ===== 上方：區域 + 時間 ===== */
                $outPutString .= '<div class="vs-top">';

                $backUrl = $_SERVER['REQUEST_URI'] ?? '/pages/fight.php';

                // 只顯示區域 / COST，不連結詳細頁
                $outPutString .= '<span class="region-btn region-label">';
                $outPutString .= regionIcon($region) . ' ' . htmlspecialchars($costRule, ENT_QUOTES);
                $outPutString .= '</span>';

                if ($region === 'MATCH') {
                  $outPutString .= '<div class="match-tag">比賽專用</div>';
                }

                // 時間
                $outPutString .= '<span class="timestamp">' . htmlspecialchars($showDate, ENT_QUOTES) . '</span>';

                $outPutString .= '</div>'; // .vs-top


                /* ===== 管理操作區 ===== */
                if ($permission >= 2 && $win + $lose + $tie < 1) {

                  $outPutString .= '<div class="vs-admin-row">';

                  // 管理員判定操作
                  $outPutString .= '<form action="queue.php" method="POST" class="vs-actions">';

                  $outPutString .= '<div class="btn-group">';
                  $outPutString .= '<button type="submit" name="is_read" value="' . (int)$id . '" class="btn btn-default skip-btn">讀</button>';
                  $outPutString .= '<button type="submit" name="tie" value="' . (int)$id . '" class="btn btn-success set-tie-btn">平</button>';
                  $outPutString .= '<button type="submit" name="del" value="' . (int)$id . '" class="btn btn-danger set-del-btn">刪</button>';
                  $outPutString .= '</div>';

                  $outPutString .= '<input type="hidden" name="player_name1" value="' . htmlspecialchars($player_name1, ENT_QUOTES) . '">';
                  $outPutString .= '<input type="hidden" name="player_name2" value="' . htmlspecialchars($player_name2, ENT_QUOTES) . '">';
                  $outPutString .= '</form>';

                  // 未判定才顯示時間修正
                  $datetimeValue = date('Y-m-d\TH:i', strtotime($rawDate));

                  $outPutString .= '
                    <form action="fight.php" method="POST" class="vs-time-edit-form">
                      <input type="datetime-local"
                        name="new_update_time"
                        value="' . htmlspecialchars($datetimeValue, ENT_QUOTES, 'UTF-8') . '"
                        class="vs-time-input">

                      <button type="submit"
                        name="update_match_time"
                        value="' . (int)$id . '"
                        class="vs-time-save-btn"
                        onclick="return confirm(\'確定要修正這場對戰時間嗎？\');">
                        修正
                      </button>

                      <input type="hidden" name="player_name1" value="' . htmlspecialchars($player_name1, ENT_QUOTES, 'UTF-8') . '">
                      <input type="hidden" name="player_name2" value="' . htmlspecialchars($player_name2, ENT_QUOTES, 'UTF-8') . '">
                    </form>
                  ';

                  // 詳細連結獨立於 btn-group / form 之外，避免破壞原本樣式
                  $outPutString .= '</div>';
                }

                $outPutString .= '<a href="fight_detail.php?id=' . (int)$id
                  . '&p1=' . urlencode($player_name1)
                  . '&p2=' . urlencode($player_name2)
                  . '&back=' . urlencode($backUrl)
                  . '" class="vs-mini-detail-btn">詳細</a>';

                $outPutString .= '</div>'; // .vs-panel


                // 組合2組合
                $outPutString .= '<div class="team">';

                for ($i = 1; $i <= 3; $i++) {

                  // 安全取值
                  $cid = (int)($row["u{$i}"] ?? 0);
                  if ($cid <= 0) {
                    continue;
                  }

                  // 使用 prepared statement
                  $stmtChar = $db->prepare("
                    SELECT 
                      ico,
                      cost,
                      CONCAT(`level`, `name`) AS levelName,
                      ico_back
                    FROM unlight
                    WHERE id = ?
                  ");
                  $stmtChar->execute([$cid]);

                  $row1 = $stmtChar->fetch(PDO::FETCH_ASSOC);
                  if (!$row1) {
                    continue; // 查不到角色就跳過
                  }

                  $ico       = $row1['ico'];
                  $cost      = (int)$row1['cost'];
                  $levelName = $row1['levelName'];
                  $ico_back  = $row1['ico_back'];

                  $cost_sum += $cost;
                  $cost_arr[] = $cost;

                  // 角色圖片（改用 IMG_BASE）
                  $img_arr .= '
                    <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
                      <img class="character-img"
                          src="' . IMG_BASE . htmlspecialchars($ico, ENT_QUOTES, 'UTF-8') . '"
                          loading="lazy">
                    </div>';

                  // 初始化 counterStats（避免 Undefined array key）
                  if (!isset($counterStats[$cid])) {
                    $counterStats[$cid] = [
                      'wins'      => 0,
                      'losses'    => 0,
                      'levelName' => '',
                      'ico'       => '',
                      'ico_back'  => '',
                    ];
                  }

                  // 存角色資訊（供「難以應對角色」用）
                  $counterStats[$cid]['levelName'] = $levelName;
                  $counterStats[$cid]['ico']       = $ico;
                  $counterStats[$cid]['ico_back']  = $ico_back;
                }


                // ❗懲罰應該在 cost_arr 三張都抓完再計算
                if (count($cost_arr) === 3) {
                  $punishment = cost_punish($cost_arr[0], $cost_arr[1], $cost_arr[2]);
                  $cost_sum += $punishment;
                  $cost_color = ($punishment > 0) ? 'text-danger' : '';
                } else {
                  $punishment = 0;
                  $cost_color = '';
                }
                $outPutString .= '<div class="imagecard imagecard2">';
                // 如果沒圖就不印

                // 判斷是否可查看 Player 2 角色
                $canViewPlayer2 =
                  $isMatch ||               // ⭐ 比賽專用 → 無條件可看
                  $permission >= 2 ||
                  $show_private2 ||
                  $isBlackList2;

                // ─────────────── 權限遮罩角色卡 ───────────────
                if ($combined == 2 && !$canViewPlayer2) {


                  // === Event Card (Team 2) ===
                  $outPutString .= '<div class="costEvent">';
                  $outPutString .= '<span class="event-slot-empty"></span>';
                  $outPutString .= '</div>';

                  $outPutString .= '<div class="characters">';
                  $outPutString .= '<div class="empty-container privacy-mask">';
                  for ($i = 0; $i < 3; $i++) {
                    $outPutString .= '
                      <div class="empty-slot">
                        <i class="fa fa-lock"></i>
                      </div>
                  ';
                  }

                  $outPutString .= '</div>'; // .empty-container
                  $outPutString .= '</div>'; // .characters
                } else {

                  $outPutString .= '<div class="costEvent">';
                  // === Event Card (Team 2) ===
                  if (!empty($ico2)) {
                    $outPutString .=
                      '<img src="' . IMG_BASE . htmlspecialchars($ico2, ENT_QUOTES, 'UTF-8') . '" ' .
                      'class="event" title="' . htmlspecialchars($name2 ?? '', ENT_QUOTES, 'UTF-8') . '" />';
                  } else {
                    $outPutString .= '<span class="event-slot-empty"></span>';
                  }
                  $outPutString .= '</div>';
                  $outPutString .= '<div class="characters">';
                  $outPutString .= $img_arr;
                  $outPutString .= '</div>'; // characters

                }

                $outPutString .= '</div>'; // imagecard
                // ================================
                // Player 2（新視覺）
                // ================================
                $outPutString .= '<div class="player-card player-card2">';

                /* ===== 勝負狀態 ===== */
                if ($unknown == 1) {
                  $outPutString .= '<div class="result-pill result-unknown">未判</div>';
                } elseif ($tie == 1) {
                  $outPutString .= '<div class="result-pill result-tie">平</div>';
                } elseif ($win == 1) {
                  $outPutString .= '<div class="result-pill result-win">勝</div>';
                } else {
                  $outPutString .= '<div class="result-pill result-lose">負</div>';
                }

                /* ===== 名稱 / Rank ===== */
                $search_link2 = 'fight.php?player_name=' . rawurlencode($player_name2);
                $rank_text2   = isset($rankNumP2) ? (string)$rankNumP2 : '';
                $rank_badge2  = $rank_text2 !== ''
                  ? '<span class="rank-badge">' . htmlspecialchars($rank_text2, ENT_QUOTES, 'UTF-8') . '</span>'
                  : '';

                if ($permission >= 2 || $show_private2 || $isBlackList2 || $combined == 2) {
                  /* ===== Player 2：依狀態決定 class ===== */
                  $playerClass2 = 'player-name';

                  if ($isBlackList2) {
                    $playerClass2 .= ' blacklist-name';
                  } elseif ($isWatchList2) {
                    $playerClass2 .= ' watchlist-name';
                  }

                  /* ===== Player 2：輸出 ===== */
                  $outPutString .=
                    '<a href="' . htmlspecialchars($search_link2, ENT_QUOTES, 'UTF-8') . '" class="' . $playerClass2 . '">' .
                    $rank_badge2 .
                    htmlspecialchars($player_name2, ENT_QUOTES, 'UTF-8') .
                    '</a>';


                  // BP
                  if ($unknown_pre == 1 && $permission >= 2) {
                    $outPutString .= '<div class="bp-box">BP <span class="compare">'
                      . htmlspecialchars($bp2, ENT_QUOTES, 'UTF-8')
                      . '</span><br>' . $result_print_p2 . '↓</div>';
                  } else {
                    $outPutString .= '<div class="bp-box">BP '
                      . htmlspecialchars($bp2, ENT_QUOTES, 'UTF-8') . '</div>';
                  }
                } elseif ($player_name1 == $username) {

                  $outPutString .= '<span class="player-name">'
                    . $rank_badge2
                    . htmlspecialchars($player_name2, ENT_QUOTES, 'UTF-8')
                    . '</span>';

                  $outPutString .= '<div class="bp-box">BP '
                    . htmlspecialchars($bp2, ENT_QUOTES, 'UTF-8') . '</div>';
                } else {

                  // BP mask
                  $bp_str2    = (string)$bp2;
                  $bp_masked2 = (strlen($bp_str2) >= 2)
                    ? substr($bp_str2, 0, -2) . 'XX'
                    : 'XX';

                  if ($rank_text2 !== '') {
                    $outPutString .= '<span class="player-name">' . $rank_badge2 . '</span>';
                  }

                  $outPutString .= '<div class="bp-box">BP '
                    . htmlspecialchars($bp_masked2, ENT_QUOTES, 'UTF-8') . '</div>';
                }
                $outPutString .= '</div>'; // .player-card

                $outPutString .= '</div>'; //team
                $outPutString .= '</div>'; // fight-container
                //$outPutString .= '</div>'; // fight-form

                $bp1_pre = $bp1;
                $unknown_pre = $unknown;
                $opponent_bp = $bp2;
              }
            }

            $outPutString .= '</div>'; //fight-all
          }
          // 1. 先將關聯陣列轉為索引陣列，並計算每個角色的勝率（0～1 之間）
          $counterStats = $counterStats ?? [];
          $entries = [];
          // 1. 初始化
          //$counterStats = [];
          foreach ($counterStats as $cid => $data) {
            $total = $data['wins'] + $data['losses'];
            $rate  = $total ? ($data['wins'] / $total) : 0.0;
            $entries[] = [
              'cid'       => $cid,
              'ico'       => $data['ico']       ?? 'default.png',
              'ico_back'  => $data['ico_back']  ?? 'default_back.png',
              'levelName' => $data['levelName'] ?? '未知角色',
              'wins'      => $data['wins'],
              'losses'    => $data['losses'],
              'total'     => $total,
              'rate'      => $rate,
            ];
          }

          usort($entries, function ($a, $b) {
            // 先比較勝率（rate）降冪
            $cmp = $b['rate'] <=> $a['rate'];
            if ($cmp !== 0) {
              return $cmp;
            }
            // 如果勝率相同，再比較總場次（total）降冪
            return $b['total'] <=> $a['total'];
          });

          // 3. 只取前 6 名
          $top = array_slice($entries, 0, 10);
          if ($combined == 0 && count($top) >= 1) {            // 4. 輸出 HTML 表格
            echo '<div class="counter-wrapper">';
            echo '<h4>難以應對角色</h4>';
            echo '<div class="counter-container">';
            foreach ($top as $row) {
              $total = $row['wins'] + $row['losses'];
              if ($total > 1) {
                $rateDisplay = $row['rate'] > 0
                  ? round($row['rate'] * 100, 0) . '%'
                  : '0%';
                if ($row['rate'] > 0.5) {
                  $search_link = "ranking_team.php?character=" . urlencode($row['levelName']);

                  echo '<div class="d-character">';
                  echo '
                  <div class="flip-container" onclick="this.classList.toggle(\'flipped\');">
                    <img class="character-img"
                        src="' . IMG_BASE . htmlspecialchars($row['ico'], ENT_QUOTES, 'UTF-8') . '"
                        loading="lazy">
                  </div>
                  <a href="' . htmlspecialchars($search_link, ENT_QUOTES, 'UTF-8') . '" class="text-dark">'
                    . htmlspecialchars($row['levelName'], ENT_QUOTES, 'UTF-8') .
                    '</a>
                  <div class="weapon_attr">
                    <span style="color:blue">' . htmlspecialchars($rateDisplay, ENT_QUOTES, 'UTF-8') . '</span>
                    (' . (int)$row['wins'] . '/' . (int)$total . ')
                  </div>
                </div>';
                }
              }
            }
            echo '</div>'; //container
            echo '</div>'; //wrapper
          }
          echo $outPutString;

          ?>

        </div>
      </div>
    </div>



    <script>
      function confirmDelete() {
        return confirm("⚠️ 此操作將永久刪除資料，確定要刪除嗎？");
      }
    </script>

    <?php
    // ⭐ 最後統一輸出成 pageContent 給 template/base.php
    $pageContent = ob_get_clean();
    include __DIR__ . '/../layout/base.php';
    ?>