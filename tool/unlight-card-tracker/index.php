<?php
require_once __DIR__ . '/../../config.php';
$pdo = $db;

$pageTitleText = '公牌追蹤';
$seoTitle = $pageTitleText . ' | UL.GG 戰績網 UNLIGHT 戰術研究中心';
$pageTitleFull = $pageTitleText . ' | UL.GG 戰績網';
$activeMenu = 'card_tracker';

ob_start();
?>

<style>
  .card-tracker-app {
    --tracker-bg: #0f131a;
    --tracker-panel: #1a202b;
    --tracker-panel-2: #232b39;
    --tracker-line: #344055;
    --tracker-text: #f4f6f8;
    --tracker-muted: #9ba7b8;
    --tracker-blue: #3b82f6;
    --tracker-red: #b84a4a;
    --tracker-green: #2f8f63;
    --tracker-gold: #a97720;
    --tracker-purple: #7557c9;
    width: 100%;
    max-width: 1600px;
    margin: 0 auto;
    padding: 10px;
    border-radius: 14px;
    background: linear-gradient(#0b0f15, #141a24);
    color: var(--tracker-text);
    font-family: "Segoe UI", "Noto Sans TC", sans-serif;
  }

  .card-tracker-app,
  .card-tracker-app * {
    box-sizing: border-box;
  }

  .card-tracker-app button,
  .card-tracker-app select {
    font: inherit;
  }

  .card-tracker-app button {
    border: 0;
    border-radius: 9px;
    padding: 10px 14px;
    background: #2f394b;
    color: #fff;
    cursor: pointer;
  }

  .card-tracker-app button:hover {
    filter: brightness(1.12);
  }

  .card-tracker-app button:disabled {
    opacity: .4;
    cursor: default;
  }

  .card-tracker-app button.danger {
    background: var(--tracker-red);
  }

  .card-tracker-app button.shuffle {
    background: var(--tracker-purple);
  }

  .card-tracker-app .tracker-dashboard {
    display: grid;
    grid-template-columns:
      minmax(360px, 420px) minmax(0, 1fr) 340px;
    grid-template-areas:
      "controls enemy enemy"
      "public public public-side"
      "hand hand hand";
    gap: 12px;
    align-items: start;
  }

  .card-tracker-app .dashboard-controls {
    grid-area: controls;
  }

  .card-tracker-app .dashboard-enemy {
    grid-area: enemy;
    min-width: 0;
    padding: 10px;
  }

  .card-tracker-app .tracker-status-bar {
    display: grid;
    grid-template-columns:
      minmax(76px, .8fr) minmax(82px, .9fr) minmax(70px, .7fr) minmax(120px, 1.25fr) minmax(82px, .85fr) minmax(70px, .7fr) minmax(70px, .7fr) auto;
    gap: 6px;
    align-items: stretch;
  }

  .card-tracker-app .tracker-status-item {
    min-width: 0;
    padding: 7px 9px;
    border: 1px solid var(--tracker-line);
    border-radius: 9px;
    background: #111720;
    text-align: center;
  }

  .card-tracker-app .tracker-status-item small {
    display: block;
    margin-bottom: 2px;
    color: var(--tracker-muted);
    font-size: 10px;
    line-height: 1.2;
    white-space: nowrap;
  }

  .card-tracker-app .tracker-status-item strong {
    display: block;
    overflow: hidden;
    color: var(--tracker-text);
    font-size: 13px;
    line-height: 1.3;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .card-tracker-app .tracker-status-field strong {
    font-size: 14px;
  }

  .card-tracker-app .tracker-confirm-button {
    align-self: stretch;
    min-width: 120px;
    padding: 7px 11px;
    white-space: nowrap;
  }

  .card-tracker-app .tracker-status-errors {
    display: none;
    margin-top: 7px;
    padding: 7px 9px;
    border: 1px solid #8f3e46;
    border-radius: 8px;
    background: #2b171c;
  }

  .card-tracker-app .tracker-status-errors[data-status="warning"],
  .card-tracker-app .tracker-status-errors[data-status="offline"],
  .card-tracker-app .tracker-status-errors[data-status="paused"] {
    display: block;
  }

  .card-tracker-app .tracker-error-message {
    color: #ffb4b4;
    font-size: 11px;
    line-height: 1.4;
  }

  .card-tracker-app .tracker-error-message:empty {
    display: none;
  }

  .card-tracker-app .tracker-diagnostics {
    margin-top: 7px;
    padding-top: 7px;
    border-top: 1px solid var(--tracker-line);
  }

  .card-tracker-app .tracker-diagnostics summary {
    color: var(--tracker-muted);
    cursor: pointer;
    font-size: 12px;
    font-weight: 700;
  }

  .card-tracker-app .tracker-diagnostics .observation-grid {
    margin-top: 8px;
  }

  .card-tracker-app .enemy-section-head {
    margin-top: 10px;
    padding-top: 9px;
    border-top: 1px solid var(--tracker-line);
  }


  .card-tracker-app .dashboard-public {
    grid-area: public;
    min-width: 0;
  }

  .card-tracker-app .dashboard-public-side {
    grid-area: public-side;
    display: grid;
    align-content: start;
    gap: 12px;
    min-width: 0;
  }

  .card-tracker-app .dashboard-hand {
    grid-area: hand;
    min-width: 0;
  }

  .card-tracker-app .dashboard-history {
    min-width: 0;
  }


  .card-tracker-app .field-box,
  .card-tracker-app .compact-summary,
  .card-tracker-app .dashboard-controls>.actions {
    background: var(--tracker-panel);
    border: 1px solid var(--tracker-line);
    border-radius: 12px;
    box-shadow: 0 8px 20px #0004;
  }

  .card-tracker-app .field-box {
    padding: 10px 9px;
  }

  .card-tracker-app .field-box label {
    margin-bottom: 3px;
    font-size: 11px;
  }

  .card-tracker-app .field-box select {
    padding: 7px 9px;
  }

  .card-tracker-app .compact-summary {
    display: grid;
    grid-template-columns: repeat(5, minmax(54px, 1fr));
    margin-top: 8px;
    overflow: hidden;
    padding: 12px 10px;
  }

  .card-tracker-app .compact-stat {
    min-width: 0;
    padding: 6px 10px;
    text-align: center;
    border-right: 1px solid var(--tracker-line);
  }

  .card-tracker-app .compact-stat:last-child {
    border-right: 0;
  }

  .card-tracker-app .compact-stat small {
    display: block;
    color: var(--tracker-muted);
    font-size: 11px;
    white-space: nowrap;
  }

  .card-tracker-app .compact-stat strong {
    display: block;
    margin-top: 1px;
    font-size: 21px;
    line-height: 1.1;
  }

  .card-tracker-app .dashboard-controls>.actions {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-top: 8px;
    padding: 12px 10px;
  }

  .card-tracker-app .dashboard-controls>.actions button {
    padding: 8px 11px;
    white-space: nowrap;
  }

  .card-tracker-app .box,
  .card-tracker-app .panel {
    background: var(--tracker-panel);
    border: 1px solid var(--tracker-line);
    border-radius: 14px;
    box-shadow: 0 10px 28px #0005;
    padding: 10px 10px;
  }

  .card-tracker-app .box {
    padding: 8px 10px;
  }

  .card-tracker-app label {
    display: block;
    margin-bottom: 6px;
    color: var(--tracker-muted);
    font-size: 12px;
  }

  .card-tracker-app select {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--tracker-line);
    border-radius: 9px;
    background: #0f141d;
    color: #fff;
  }

  .card-tracker-app .actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .card-tracker-app .panel {
    padding: 10px;
  }

  .card-tracker-app .stack {
    display: grid;
    gap: 12px;
  }

  .card-tracker-app .head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
  }

  .card-tracker-app h2 {
    margin: 0;
    font-size: 18px;
  }

  .card-tracker-app .hint,
  .card-tracker-app .empty,
  .card-tracker-app .note {
    color: var(--tracker-muted);
  }

  .card-tracker-app .hint {
    font-size: 12px;
    text-align: right;
  }

  .card-tracker-app .note {
    margin-top: 10px;
    font-size: 12px;
    line-height: 1.5;
  }

  .card-tracker-app .grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(76px, 1fr));
    gap: 8px;
  }

  .card-tracker-app .zone-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(76px, 1fr));
    gap: 8px;
    min-height: 100px;
  }

  .card-tracker-app .zone-empty {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 96px;
    border: 1px dashed var(--tracker-line);
    border-radius: 10px;
    background: #111720;
    color: var(--tracker-muted);
  }

  .card-tracker-app .card {
    min-height: 76px;
    border: 1px solid var(--tracker-line);
    background: var(--tracker-panel-2);
    text-align: left;
    touch-action: manipulation;
    user-select: none;
    -webkit-user-select: none;
    -webkit-touch-callout: none;
  }

  .card-tracker-app .card.low {
    border-color: #8a6b2a;
    background: #2a2417;
  }

  .card-tracker-app .card.in-hand {
    border-color: var(--tracker-green);
    box-shadow: inset 0 0 0 1px #2f8f6388;
  }

  .card-tracker-app .card.enemy-candidate {
    border-color: var(--tracker-gold);
    box-shadow: inset 0 0 0 1px #a9772088;
  }

  .card-tracker-app .game-card-single {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    min-width: 0;
    min-height: auto;
    padding: 7px 5px;
    text-align: center;
  }

  .card-tracker-app .game-card-preview {
    display: flex;
    flex: none;
    justify-content: center;
  }

  .card-tracker-app .game-card {
    position: relative;
    width: 54px;
    height: 84px;
    overflow: hidden;
    border: 1px solid #000;
    background: #050505;
    box-shadow:
      0 0 0 1px #777,
      0 4px 10px rgba(0, 0, 0, .55);
    image-rendering: pixelated;
  }

  .card-tracker-app .game-card-half {
    position: absolute;
    left: 0;
    width: 54px;
    height: 42px;
    overflow: hidden;
  }

  .card-tracker-app .game-card-top {
    top: 0;
  }

  .card-tracker-app .game-card-bottom {
    bottom: 0;
    transform: rotate(180deg);
  }

  .card-tracker-app .game-card-half img {
    position: absolute;
    top: 0;
    left: 0;
    width: 54px;
    max-width: none;
    height: 84px;
    object-fit: fill;
    image-rendering: pixelated;
  }

  .card-tracker-app .game-card-divider {
    position: absolute;
    z-index: 10;
    top: 41px;
    right: 1px;
    left: 1px;
    height: 2px;
    border-top: 1px solid #000;
    border-bottom: 1px solid #000;
    background: rgba(255, 255, 255, .65);
  }

  .card-tracker-app .game-card-value {
    position: absolute;
    z-index: 6;
    top: 11px;
    left: 7px;
    color: #fff;
    font-family: Impact, "Arial Black", sans-serif;
    font-size: 18px;
    line-height: 1;
    text-shadow:
      -1px -1px 0 #000,
      1px -1px 0 #000,
      -1px 1px 0 #000,
      1px 1px 0 #000,
      0 2px 1px #000;
  }

  .card-tracker-app .game-card-probability {
    width: 100%;
    color: var(--tracker-muted);
    font-size: 11px;
    line-height: 1.2;
    text-align: center;
    white-space: nowrap;
  }

  .card-tracker-app .game-card-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 54px;
    height: 84px;
    border: 1px solid #777;
    background: #222;
    font-size: 11px;
  }

  .card-tracker-app .type-buttons {
    display: grid;
    grid-template-columns: repeat(5, minmax(48px, 1fr));
    gap: 7px;
  }

  .card-tracker-app .type-button {
    padding: 9px 6px;
    border: 1px solid var(--tracker-line);
    background: #202838;
    text-align: center;
  }

  .card-tracker-app .type-button strong,
  .card-tracker-app .type-button span {
    display: block;
  }

  .card-tracker-app .type-button strong {
    font-size: 18px;
  }

  .card-tracker-app .type-button span {
    margin-top: 3px;
    color: var(--tracker-muted);
    font-size: 12px;
  }

  .card-tracker-app .type-button.active {
    border-color: #8ebcff;
    background: var(--tracker-blue);
  }

  .card-tracker-app .type-button.active span {
    color: #fff;
  }

  .card-tracker-app .history {
    display: grid;
    gap: 7px;
    max-height: 540px;
    overflow: auto;
  }

  .card-tracker-app .hist {
    display: flex;
    justify-content: space-between;
    gap: 10px;
    padding: 8px;
    border: 1px solid var(--tracker-line);
    border-radius: 9px;
    background: var(--tracker-panel-2);
  }

  .card-tracker-app .hist small {
    color: var(--tracker-muted);
  }

  .card-tracker-app .type-panel {
    align-self: start;
    padding: 12px 10px;
  }

  .card-tracker-app .type-panel .head {
    margin-bottom: 4px;
  }

  .card-tracker-app .type-panel .type-buttons {
    gap: 4px;
  }

  .card-tracker-app .type-panel .type-button {
    padding: 4px 3px;
  }

  .card-tracker-app .type-panel .type-button strong {
    font-size: 16px;
  }

  .card-tracker-app .type-panel .type-button span {
    margin-top: 1px;
    font-size: 11px;
  }

  .card-tracker-app .type-panel .note {
    margin-top: 12px;
    line-height: 1.25;
  }

  .card-tracker-app .dashboard-history .history {
    max-height: 420px;
  }

  .card-tracker-app .tracker-diagnostics {
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid var(--tracker-line);
  }

  .card-tracker-app .tracker-diagnostics summary {
    color: var(--tracker-muted);
    cursor: pointer;
    font-weight: 700;
  }

  .card-tracker-app .observation-status {
    margin: 8px 0 0;
    padding: 12px 14px;
    border: 1px solid #344052;
    border-left: 4px solid #718096;
    border-radius: 8px;
    background: #171d27;
  }

  .card-tracker-app .observation-status[data-status="connected"] {
    border-left-color: #48bb78;
  }

  .card-tracker-app .observation-status[data-status="warning"],
  .card-tracker-app .observation-status[data-status="waiting"] {
    border-left-color: #ecc94b;
  }

  .card-tracker-app .observation-status[data-status="offline"],
  .card-tracker-app .observation-status[data-status="paused"] {
    border-left-color: #f56565;
  }

  .card-tracker-app .observation-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 8px 14px;
  }

  .card-tracker-app .observation-item {
    min-width: 0;
  }

  .card-tracker-app .observation-item small {
    display: block;
    color: #9aa6b6;
    font-size: 11px;
  }

  .card-tracker-app .observation-item strong {
    display: block;
    overflow-wrap: anywhere;
    font-size: 13px;
  }

  .card-tracker-app .observation-error {
    grid-column: 1 / -1;
  }

  @media (max-width: 1100px) {
    .card-tracker-app .tracker-dashboard {
      grid-template-columns: minmax(300px, 360px) minmax(0, 1fr);
      grid-template-areas:
        "controls enemy"
        "public public"
        "hand hand"
        "history history";
    }

    .card-tracker-app .type-buttons {
      grid-template-columns: repeat(5, minmax(48px, 1fr));
    }

    .card-tracker-app .tracker-status-bar {
      grid-template-columns:
        repeat(4, minmax(90px, 1fr));
    }

    .card-tracker-app .tracker-confirm-button {
      grid-column: span 2;
    }
  }

  @media (max-width: 700px) {
    .card-tracker-container {
      padding-right: 4px;
      padding-left: 4px;
    }

    .card-tracker-app {
      padding: 6px;
      border-radius: 8px;
    }

    .card-tracker-app .tracker-dashboard {
      grid-template-columns: 1fr;
      grid-template-areas:
        "controls"
        "enemy"
        "public"
        "hand"
        "history";
    }

    .card-tracker-app .head {
      align-items: flex-start;
      flex-direction: column;
    }

    .card-tracker-app .hint {
      text-align: left;
    }

    .card-tracker-app .grid,
    .card-tracker-app .zone-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .card-tracker-app .tracker-status-bar {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .card-tracker-app .tracker-confirm-button {
      grid-column: 1 / -1;
    }

    .card-tracker-app .tracker-status-item strong {
      white-space: normal;
      overflow-wrap: anywhere;
    }
  }
</style>

<div class="content-wrapper">
  <section class="content ul-container-nopad">
    <div class="container-fluid card-tracker-container">
      <div class="card-tracker-app">
        <div class="tracker-dashboard">
          <section class="panel dashboard-controls">
            <div class="field-box">
              <label for="field">場地</label>
              <select id="field"></select>
            </div>

            <div class="compact-summary">
              <div class="compact-stat">
                <small>初始牌庫</small>
                <strong id="initial">0</strong>
              </div>
              <div class="compact-stat">
                <small>公牌剩餘</small>
                <strong id="deckLeft">0</strong>
              </div>
              <div class="compact-stat">
                <small>我方手牌</small>
                <strong id="myHandCount">0</strong>
              </div>
              <div class="compact-stat">
                <small>敵方候選</small>
                <strong id="enemyCandidateCount">0</strong>
              </div>
              <div class="compact-stat">
                <small>已公開</small>
                <strong id="seen">0</strong>
              </div>
            </div>

            <div class="actions">
              <button id="undo" type="button">復原</button>
              <button id="shuffle" class="shuffle" type="button">洗牌</button>
              <button id="reset" class="danger" type="button">重設本局</button>
            </div>
          </section>

          <section class="panel dashboard-enemy">

            <!-- Tracker 常駐狀態列 -->
            <div class="tracker-status-bar" aria-live="polite">

              <div class="tracker-status-item">
                <small>連線</small>
                <strong id="observation-connection">connecting</strong>
              </div>

              <div class="tracker-status-item">
                <small>儲存</small>
                <strong id="observation-persistence">initializing</strong>
              </div>

              <div class="tracker-status-item">
                <small>場地代碼</small>
                <strong id="observation-stage-code">—</strong>
              </div>

              <div class="tracker-status-item tracker-status-field">
                <small>偵測場地</small>
                <strong id="observation-stage-field">—</strong>
              </div>

              <div class="tracker-status-item">
                <small>場地狀態</small>
                <strong id="observation-stage-status">missing</strong>
              </div>

              <div class="tracker-status-item">
                <small>暫存事件</small>
                <strong id="observation-pending-events">0</strong>
              </div>

              <div class="tracker-status-item">
                <small>暫存牌</small>
                <strong id="observation-pending-cards">0</strong>
              </div>

              <button
                id="confirm-pending-field"
                class="tracker-confirm-button"
                type="button"
                disabled>
                套用暫存收牌
              </button>

            </div>

            <!-- 錯誤常駐顯示 -->
            <div
              id="observation-status"
              class="tracker-status-errors"
              data-status="connecting">

              <div
                id="observation-stage-error"
                class="tracker-error-message">
              </div>

              <div
                id="observation-error"
                class="tracker-error-message">
              </div>

              <div
                id="observation-persistence-error"
                class="tracker-error-message">
              </div>

            </div>

            <!-- 次要資訊收合 -->
            <details class="tracker-diagnostics">
              <summary>Tracker 詳細診斷</summary>

              <div class="observation-grid">

                <div class="observation-item">
                  <small>Session</small>
                  <strong id="observation-session">—</strong>
                </div>

                <div class="observation-item">
                  <small>Cursor</small>
                  <strong id="observation-cursor">0</strong>
                </div>

                <div class="observation-item">
                  <small>Checkpoint session</small>
                  <strong id="observation-checkpoint-session">—</strong>
                </div>

                <div class="observation-item">
                  <small>Checkpoint cursor</small>
                  <strong id="observation-checkpoint-cursor">0</strong>
                </div>

                <div class="observation-item">
                  <small>Checkpoint commit</small>
                  <strong id="observation-checkpoint-token">—</strong>
                </div>

                <div class="observation-item">
                  <small>Loading</small>
                  <strong id="observation-loading">—</strong>
                </div>

                <div class="observation-item">
                  <small>Observation mode</small>
                  <strong id="observation-mode">—</strong>
                </div>

                <div class="observation-item">
                  <small>Confidence</small>
                  <strong id="observation-confidence">—</strong>
                </div>

                <div class="observation-item">
                  <small>Last event</small>
                  <strong id="observation-last-event">—</strong>
                </div>

              </div>
            </details>

            <div class="enemy-section-head">
              <div class="head">
                <h2>敵方手牌候選區</h2>

                <span class="hint">
                  點一下代表敵方已出牌；洗牌會重新建立候選清單
                </span>
              </div>
            </div>

            <div id="enemyCandidates" class="zone-grid"></div>

          </section>

          <section class="panel dashboard-public">
            <div class="head">
              <h2>公牌清單</h2>

              <span class="hint">
                左鍵／點一下扣除；手機長按或電腦右鍵加入我方手牌
              </span>
            </div>

            <div id="cards" class="grid"></div>

            
          </section>

          <aside class="dashboard-public-side">
            <section class="panel type-panel">
              <div class="head">
                <h2>屬性統計</h2>
              </div>

              <div id="types" class="type-buttons"></div>

              <div class="note">
                點擊屬性可將含有該屬性的公牌排列到前方；再次點擊可取消排序。
              </div>
            </section>

            <section class="panel dashboard-history">
              <div class="head">
                <h2>最近操作</h2>
                <button id="clear" type="button">清除紀錄</button>
              </div>

              <div id="history" class="history"></div>
            </section>
          </aside>

          <section class="panel dashboard-hand">
            <div class="head">
              <h2>我方手牌區</h2>
              <span class="hint">
                點一下代表我方已出牌，卡片會維持公牌扣除狀態
              </span>
            </div>
            <div id="myHand" class="zone-grid"></div>
          </section>


        </div>
      </div>
    </div>
  </section>
</div>

<script src="./field-decks.js"></script>
<script src="./tracker-stage-sync.js"></script>
<script src="./tracker.js"></script>
<script src="./tracker-domain-reducer.js"></script>
<script src="./tracker-db.js"></script>
<script src="./tracker-api.js"></script>
<script src="./tracker-auto-card-sync.js"></script>
<script src="./observation-poller.js"></script>

<?php
$pageContent = ob_get_clean();
include __DIR__ . '/../../layout/base.php';
?>