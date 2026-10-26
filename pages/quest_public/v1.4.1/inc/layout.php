<?php
/**
 * Page shell for the public analytics pages.
 *
 * The markup mirrors the UL.GG shell in layout/base.php (un-body / un-navbar /
 * un-sidebar / un-main / un-footer, theme.css + layout.css) so the staging site
 * is visually the real site - but this file starts NO session and opens NO
 * database connection. See README.md for why base.php/config.php cannot be
 * reused by a public page.
 */

declare(strict_types=1);

if (!defined('QPUB_VERSION')) {
    // Reached by direct web access to an include file: refuse instead of
    // leaking internals or fataling.
    http_response_code(404);
    exit;
}

/** Self assets (quest_public.css/js). */
function qpub_self_assets(): string
{
    return QPUB_BASE_PATH === '' ? 'assets' : QPUB_BASE_PATH . '/assets';
}

/** Shared UL.GG assets (theme.css / layout.css / main.js). */
function qpub_shared_assets(): string
{
    return QPUB_ASSET_BASE === '' ? '' : QPUB_ASSET_BASE;
}

function qpub_render_head(string $activeTab, string $pageTitle, array $opts = []): void
{
    $tab = QPUB_TABS[$activeTab] ?? ['label' => $pageTitle, 'en' => ''];
    $titleText = $opts['title_small'] ?? $tab['label'];
    $seoTitle = $pageTitle . ' | UL.GG 任務公開分析';
    $shared = qpub_shared_assets();
    $self = qpub_self_assets();
    $canonical = $opts['canonical'] ?? null;
    ?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= qpub_h($seoTitle) ?></title>
  <meta name="description"
    content="UL.GG 任務公開分析：地域排行與星數機率。資料為公開匿名彙總，不含玩家識別資訊。">
  <meta name="robots" content="index,follow">
  <?php if ($canonical !== null): ?>
  <link rel="canonical" href="<?= qpub_hu($canonical) ?>">
  <?php endif; ?>
  <link rel="icon" href="<?= qpub_h($shared) ?>/favicon/favicon.ico" sizes="48x48" type="image/x-icon">
  <link rel="stylesheet" href="<?= qpub_h($shared) ?>/css/theme.css?v=86">
  <link rel="stylesheet" href="<?= qpub_h($shared) ?>/css/layout.css?v=10">
  <link rel="stylesheet" href="<?= qpub_h($self) ?>/quest_public.css">
  <!-- QUEST_SIDEBAR_BEHAVIOR_FIX_20261026 : main-site sidebar-only presentation -->
  <style>
    .un-sidebar .sidebar-group-toggle {
      appearance: none;
      display: flex;
      align-items: center;
      gap: 8px;
      width: 100%;
      padding: 10px 14px;
      margin: 0;
      background: transparent;
      border: 0;
      border-radius: 0;
      color: #e5e7eb;
      font: inherit;
      font-weight: 600;
      text-align: left;
      cursor: pointer;
    }
    .un-sidebar .sidebar-group-toggle:hover { background: rgba(255,255,255,.05); }
    .un-sidebar .sidebar-group-toggle:focus-visible { outline: 2px solid #9ba5ea; outline-offset: -2px; }
    .un-sidebar .sidebar-group-items { display: none; padding-left: 8px; }
    .un-sidebar .sidebar-group-items.open { display: block; }
    .un-sidebar .sidebar-group-items .sidebar-link { padding-left: 28px; }
    .un-sidebar .sidebar-group-toggle .chevron { margin-left: auto; font-size: .75rem; opacity: .6; }
    .un-sidebar {
      overflow-y: auto;
      overflow-x: hidden;
      scrollbar-width: thin;
      scrollbar-color: #3b4252 transparent;
    }
    .un-sidebar::-webkit-scrollbar { width: 6px; }
    .un-sidebar::-webkit-scrollbar-track { background: transparent; }
    .un-sidebar::-webkit-scrollbar-thumb { background: #3b4252; border-radius: 6px; }
    .un-sidebar nav, .un-sidebar .sidebar-nav,
    .un-sidebar .sidebar-group, .un-sidebar .sidebar-group-items { overflow: visible !important; }

    /* Match main UL.GG footer layout without requiring the DB-backed support modal. */
    .un-footer .footer-support { display:flex; justify-content:center; flex-wrap:wrap; gap:12px; margin-bottom:14px; }
    .un-footer .report-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px; padding:8px 16px;
      border:1px solid rgba(180,200,255,.35); border-radius:999px; font-size:13px; font-weight:600;
      color:#fff; background:linear-gradient(180deg,#3a3f52,#2b2f40); text-decoration:none; }
    .un-footer .report-btn:hover { color:#fff; text-decoration:none; border-color:rgba(180,200,255,.7); }
    .un-footer .report-btn span { font-size:11px; opacity:.85; }
    .un-footer .footer-report { text-align:center; }
    .un-footer .footer-copy { text-align:center; margin-top:12px; font-size:11px; color:#777; }
  </style>
</head>

<body class="un-body">
  <header class="un-navbar">
    <button class="nav-toggle" type="button" data-toggle="sidebar" aria-label="開啟選單" aria-expanded="false">
      ☰
    </button>
    <div class="nav-brand">
      <span class="nav-logo">UL.GG</span>
      <span class="nav-subtitle">Unlight Analytics</span>
    </div>
    <div class="nav-right">
      <!--
        Deliberately NO authentication control and NO session read on the public
        pages. See README.md ("Public-page boundary").
      -->
      <a href="/pages/index.php" class="nav-login-btn" rel="nofollow">UL.GG 首頁</a>
    </div>
  </header>

  <div class="un-overlay"></div>

  <div class="un-layout">
    <aside class="un-sidebar" id="unSidebar">
      <div class="sidebar-section sidebar-title">
        <span class="sidebar-game">UNLIGHT</span>
      </div>

      <nav class="sidebar-nav">
        <!-- 系統 -->
        <a href="/pages/index.php"
          class="sidebar-link ">
          <span class="sidebar-icon">📢</span>
          <span class="sidebar-text">首頁情報 <small>Bulletin</small></span>
        </a>

        <!-- ⭐ 新增：ULGG 杯 -->
        <a href="/pages/tournament/ulgg_cup.php"
          class="sidebar-link ">
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
              class="sidebar-link ">
              <span class="sidebar-icon">🏆</span>
              <span class="sidebar-text">組合排行 <small>Ranking</small></span>
            </a>

            <a href="/pages/strength_rank.php"
              class="sidebar-link ">
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
              class="sidebar-link ">
              <span class="sidebar-icon">🧙</span>
              <span class="sidebar-text">角色排行 <small>Ranking</small></span>
            </a>

            <a href="/pages/character_analysis.php"
              class="sidebar-link ">
              <span class="sidebar-icon">🧠</span>
              <span class="sidebar-text">角色分析 <small>Analysis</small></span>
            </a>

            <a href="/pages/character_intro.php"
              class="sidebar-link ">
              <span class="sidebar-icon">🃏</span>
              <span class="sidebar-text">集卡冊 <small>Intro</small></span>
            </a>




            <!-- ⭐ 新增：自製卡面 Fan ART -->
            <a href="/pages/upload_card_art.php"
              class="sidebar-link ">
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
              class="sidebar-link ">
              <span class="sidebar-icon">📈</span>
              <span class="sidebar-text">BP 排行</span>
            </a>

            <a href="/pages/qp_rank.php"
              class="sidebar-link ">
              <span class="sidebar-icon">📊</span>
              <span class="sidebar-text">QP 排行</span>
            </a>

            <!-- 🏆 歷史名人堂 -->
            <a href="/pages/hall_of_fame.php"
              class="sidebar-link ">
              <span class="sidebar-icon">🏆</span>
              <span class="sidebar-text">歷史名人堂</span>
            </a>

          </div>
        </div>

        <!-- ⭐ 新增：ULGG 杯 -->
        

        <!-- 工具 -->
        <div class="sidebar-group">

          <button
            class="sidebar-group-toggle"
            type="button"
            data-target="group-tools">
            <span class="sidebar-icon">🧰</span>
            <span class="sidebar-text">
              實用工具 <small>Tools</small>
            </span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items open" id="group-tools">

            <a href="/pages/quest_map.php"
              class="sidebar-link ">
              <span class="sidebar-text">
                地圖任務查詢 <small>Map</small>
              </span>
            </a>

            <a href="/pages/skills.php"
              class="sidebar-link ">
              <span class="sidebar-text">
                技能戰術搜尋 <small>Skills</small>
              </span>
            </a>

            <a href="/pages/calculator.php"
              class="sidebar-link ">
              <span class="sidebar-text">
                計算 Cost <small>Calculator</small>
              </span>
            </a>

            <!-- QUEST_PUBLIC_SINGLE_ENTRY_20261027 -->
            <a href="<?= qpub_hu(qpub_url('index.php', [])) ?>"
              class="sidebar-link is-active" aria-current="page">
              <span class="sidebar-text">任務公開分析 <small>Quest Analytics</small></span>
            </a>





          </div>
        </div>
        <a href="/pages/queue.php"
          class="sidebar-link ">
          <span class="sidebar-icon">👀</span>
          <span class="sidebar-text">觀察大廳 <small>Lobby</small></span>
        </a>

        <!-- RAID_PUBLIC_NAV_GROUP_V1 -->
        <div class="sidebar-group ">

          <button
            class="sidebar-group-toggle"
            type="button"
            data-target="group-raid">
            <span class="sidebar-icon">🌀</span>
            <span class="sidebar-text">
              公開渦 <small>Raid</small>
            </span>
            <span class="chevron">▾</span>
          </button>

          <div
            class="sidebar-group-items"
            id="group-raid">

            <a href="/pages/raid_observer.php"
              class="sidebar-link sidebar-sub-link ">
              <span class="sidebar-icon">👁</span>
              <span class="sidebar-text">
                觀察渦 <small>Live</small>
              </span>
            </a>

            <a href="/pages/raid_stats.php"
              class="sidebar-link sidebar-sub-link ">
              <span class="sidebar-icon">📊</span>
              <span class="sidebar-text">
                Raid 統計 <small>Stats</small>
              </span>
            </a>

          </div>
        </div>

        <a href="/pages/raising/index.php"
          class="sidebar-link ">
          <span class="sidebar-icon">⚔️</span>
          <span class="sidebar-text">暗房育成 <small>Prototype</small></span>
        </a>
      </nav>
    </aside>

    <main class="un-main">
      <div class="un-main-inner">
        <div class="page-header">
          <h1 class="page-title">
            <span class="title-desktop"><?= qpub_h($pageTitle) ?></span>
            <span class="title-mobile"><?= qpub_h($titleText) ?></span>
          </h1>
        </div>
        <?php
}

function qpub_render_foot(): void
{
    $shared = qpub_shared_assets();
    $self = qpub_self_assets();
    ?>
      </div>

      <footer class="un-footer">
        <div class="footer-inner container">
          <div class="footer-support">
            <a class="report-btn" href="https://www.buymeacoffee.com/ulgg"
              target="_blank" rel="noopener noreferrer">
              ☕ Buy Me a Coffee <span>信用卡</span>
            </a>
          </div>
          <div class="footer-report">
            <a href="mailto:ulgg.online@gmail.com?subject=UL.GG%20%E5%95%8F%E9%A1%8C%E5%9B%9E%E5%A0%B1">回報問題</a>
            -
            <a href="/pages/about.php" class="footer-link">關於本站</a>
            -
            <a href="/pages/privacy.php" class="footer-link">隱私權政策</a>
          </div>
          <div class="footer-copy">
            © <?= qpub_h((string)date('Y')) ?> UL.GG • Unlight Fan-made Statistics
          </div>
        </div>
      </footer>
    </main>
  </div>

  <script src="<?= qpub_h($shared) ?>/js/main.js"></script>
  <script src="<?= qpub_h($self) ?>/quest_public.js"></script>
  <script>
    // The main site's sidebar-group JS lives in layout/base.php, not /assets/js/main.js.
    document.querySelectorAll('.un-sidebar .sidebar-group-toggle').forEach(btn => {
      const target = document.getElementById(btn.dataset.target);
      if (!target) return;
      btn.setAttribute('aria-expanded', String(target.classList.contains('open')));
      btn.addEventListener('click', () => {
        const expanded = target.classList.toggle('open');
        btn.setAttribute('aria-expanded', String(expanded));
      });
    });
  </script>
</body>

</html>
    <?php
}

/** Four-tab switcher. `$carry` holds the parameters the tab may keep. */
/** Navigation is now a single merged page; keep call compatibility for legacy endpoints. */
function qpub_render_tabs(string $activeTab, array $carry = []): void
{
    // No standalone tabs. The stars matrix lives below the Regions table.
}

/**
 * Observation / snapshot / publish metadata. Facts only - the site never shows a
 * "data may be stale" warning.
 */
function qpub_render_meta(string $window): void
{
    $meta = qpub_meta();
    $label = qpub_window_label($window);
    $cutoff = $meta['cutoffs_utc'][$window] ?? null;
    $observation = $window === 'all'
        ? '全期間（無下限）'
        : $label . '（起 ' . qpub_utc_short($cutoff) . ' 台北）';
    ?>
  <div class="qpub-meta" aria-label="資料時間資訊">
    <?php
        echo qpub_meta_chip('觀測窗', $observation);
        echo qpub_meta_chip('快照基準', qpub_utc_display($meta['as_of_utc']));
        echo qpub_meta_chip('發布時間', qpub_utc_short($meta['generated_at_utc']) . ' 台北');
        echo qpub_meta_chip('樣本紀錄', qpub_int($meta['records']));
        $panelVersion = $meta['panel_version'] === null ? '' : trim((string)$meta['panel_version']);
        echo qpub_meta_chip('面板版本', $panelVersion === '' ? '—' : $panelVersion);
        ?>
  </div>
    <?php
}

function qpub_window_label(string $window): string
{
    return match ($window) {
        'today' => '今日',
        '7d' => '近 7 天',
        '30d' => '近 30 天',
        default => '全期間',
    };
}

/** Contract-integrity notice (a defect signal, not a data-age warning). */
function qpub_render_notices(): void
{
    $warnings = qpub_contract_warnings();
    if ($warnings !== []) {
        echo '<div class="qpub-alert qpub-alert--error" role="alert">';
        echo '<strong>公開資料契約檢查未通過</strong><ul>';
        foreach ($warnings as $warning) {
            echo '<li>' . qpub_h($warning) . '</li>';
        }
        echo '</ul></div>';
    }
    $ignored = qpub_ignored_params();
    if ($ignored !== []) {
        echo '<div class="qpub-alert qpub-alert--note" role="status">已忽略無效參數：'
            . qpub_h(implode('、', $ignored)) . '</div>';
    }
}

/**
 * A GET form with one select and only whitelisted hidden fields.
 *
 * @param array<string,string|array{label:string,disabled?:bool}> $options
 */
function qpub_render_select(
    string $file,
    string $name,
    string $label,
    array $options,
    string $current,
    array $hiddens = [],
    bool $autoSubmit = true
): void {
    $action = qpub_url($file, $hiddens);
    ?>
  <form class="qpub-field" method="get" action="<?= qpub_hu($action) ?>">
    <?php foreach (QPUB_URL_KEYS as $key): ?>
    <?php if (array_key_exists($key, $hiddens) && $key !== $name && $hiddens[$key] !== null && $hiddens[$key] !== ''): ?>
    <input type="hidden" name="<?= qpub_h($key) ?>" value="<?= qpub_h((string)$hiddens[$key]) ?>">
    <?php endif; ?>
    <?php endforeach; ?>
    <label class="qpub-field__label" for="qpub-select-<?= qpub_h($name) ?>"><?= qpub_h($label) ?></label>
    <select class="qpub-select" id="qpub-select-<?= qpub_h($name) ?>" name="<?= qpub_h($name) ?>"
      <?= $autoSubmit ? 'data-qpub-autosubmit="1"' : '' ?>>
      <?php foreach ($options as $value => $option): ?>
      <?php
        $text = is_array($option) ? (string)($option['label'] ?? '') : (string)$option;
        $disabled = is_array($option) && !empty($option['disabled']);
        ?>
      <option value="<?= qpub_h((string)$value) ?>" <?= (string)$value === $current ? 'selected' : '' ?>
        <?= $disabled ? 'disabled' : '' ?>>
        <?= qpub_h($text) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <noscript><button class="qpub-btn" type="submit">套用</button></noscript>
  </form>
    <?php
}

function qpub_render_search(string $file, string $value, array $hiddens, string $placeholder = '搜尋 Quest 名稱'): void
{
    $action = qpub_url($file, $hiddens);
    ?>
  <form class="qpub-field qpub-field--search" method="get" action="<?= qpub_hu($action) ?>" role="search">
    <?php foreach (QPUB_URL_KEYS as $key): ?>
    <?php if (array_key_exists($key, $hiddens) && $key !== 'q' && $hiddens[$key] !== null && $hiddens[$key] !== ''): ?>
    <input type="hidden" name="<?= qpub_h($key) ?>" value="<?= qpub_h((string)$hiddens[$key]) ?>">
    <?php endif; ?>
    <?php endforeach; ?>
    <label class="qpub-field__label" for="qpub-search">搜尋</label>
    <input class="qpub-input" id="qpub-search" type="search" name="q" maxlength="64"
      value="<?= qpub_h($value) ?>" placeholder="<?= qpub_h($placeholder) ?>" autocomplete="off">
    <button class="qpub-btn" type="submit">查詢</button>
  </form>
    <?php
}

function qpub_render_reset(string $file, string $label = '重設篩選'): void
{
    echo '<a class="qpub-btn qpub-btn--ghost" href="'
        . qpub_hu(qpub_url($file, [])) . '">' . qpub_h($label) . '</a>';
}

function qpub_render_copy_button(): void
{
    ?>
  <button class="qpub-btn qpub-btn--ghost" type="button" data-qpub-copy="1"
    data-qpub-copy-label="複製此頁連結" aria-describedby="qpub-copy-status">複製此頁連結</button>
  <span class="qpub-copy-status" id="qpub-copy-status" role="status" aria-live="polite"></span>
    <?php
}

/** Render the "no published data" state and stop. */
function qpub_abort_no_data(string $activeTab, string $pageTitle): void
{
    qpub_render_head($activeTab, $pageTitle);
    qpub_render_tabs($activeTab, []);
    qpub_render_notices();
    echo '<div class="qpub-panel">';
    echo qpub_empty_state(
        '公開資料尚未發布',
        '此頁只讀取已發布的公開 JSON（manifest.json / overview.json / quality.json / '
        . 'teams_all.json / windows/*）。請先完成公開資料匯出（Phase 1）後再開啟本頁。'
    );
    echo '</div>';
    qpub_render_foot();
    exit;
}
