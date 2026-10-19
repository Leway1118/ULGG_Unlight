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
    content="UL.GG 任務公開分析：地域排行、Quest 效率、星數機率、隊伍比較。資料為公開匿名彙總，不含玩家識別資訊。">
  <meta name="robots" content="index,follow">
  <?php if ($canonical !== null): ?>
  <link rel="canonical" href="<?= qpub_hu($canonical) ?>">
  <?php endif; ?>
  <link rel="icon" href="<?= qpub_h($shared) ?>/favicon/favicon.ico" sizes="48x48" type="image/x-icon">
  <link rel="stylesheet" href="<?= qpub_h($shared) ?>/css/theme.css">
  <link rel="stylesheet" href="<?= qpub_h($shared) ?>/css/layout.css">
  <link rel="stylesheet" href="<?= qpub_h($self) ?>/quest_public.css">
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

      <nav class="sidebar-nav" aria-label="網站選單">
        <a href="/pages/index.php" class="sidebar-link">
          <span class="sidebar-icon">📰</span>
          <span class="sidebar-text">首頁情報 <small>Bulletin</small></span>
        </a>

        <a href="/pages/queue.php" class="sidebar-link">
          <span class="sidebar-icon">👀</span>
          <span class="sidebar-text">觀察大廳 <small>Lobby</small></span>
        </a>

        <div class="sidebar-group is-open">
          <button class="sidebar-group-toggle" type="button" data-target="group-tools">
            <span class="sidebar-icon">🧰</span>
            <span class="sidebar-text">工具 <small>Tools</small></span>
            <span class="chevron">▾</span>
          </button>

          <div class="sidebar-group-items is-open" id="group-tools">
            <a href="/pages/quest_map.php"
              class="sidebar-link <?= $activeTab === 'quest_map' ? 'is-active' : '' ?>">
              <span class="sidebar-text">地域任務查詢 <small>Map</small></span>
            </a>
            <a href="/pages/skills.php" class="sidebar-link">
              <span class="sidebar-text">技能戰術搜尋 <small>Skills</small></span>
            </a>
            <a href="/pages/calculator.php" class="sidebar-link">
              <span class="sidebar-text">計算 Cost <small>Calculator</small></span>
            </a>

            <!-- QUEST_PUBLIC_NAV_GROUP_V1 : staging preview of the Tools menu entry -->
            <a href="<?= qpub_hu(qpub_url(QPUB_TABS['regions']['file'], [])) ?>"
              class="sidebar-link sidebar-sub-link sidebar-sub-group-title
                <?= $activeTab === 'regions' ? 'is-active' : '' ?>">
              <span class="sidebar-text">任務公開分析 <small>Public</small></span>
            </a>
            <?php foreach (['regions', 'quests', 'stars', 'teams'] as $key): ?>
            <a href="<?= qpub_hu(qpub_url(QPUB_TABS[$key]['file'], [])) ?>"
              class="sidebar-link sidebar-sub-link sidebar-sub-link--deep
                <?= $activeTab === $key ? 'is-active' : '' ?>"
              <?= $activeTab === $key ? 'aria-current="page"' : '' ?>>
              <span class="sidebar-text">
                <?= qpub_h(QPUB_TABS[$key]['label']) ?>
                <small><?= qpub_h(QPUB_TABS[$key]['en']) ?></small>
              </span>
            </a>
            <?php endforeach; ?>
          </div>
        </div>

        <a href="/pages/raid_stats.php" class="sidebar-link">
          <span class="sidebar-icon">🌀</span>
          <span class="sidebar-text">公開渦 <small>Raid</small></span>
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
          <div class="footer-report">
            <a href="mailto:ulgg.online@gmail.com?subject=UL.GG 問題回報">回報問題</a>
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
</body>

</html>
    <?php
}

/** Four-tab switcher. `$carry` holds the parameters the tab may keep. */
function qpub_render_tabs(string $activeTab, array $carry = []): void
{
    ?>
  <nav class="qpub-tabs" aria-label="分析分頁">
    <?php foreach (QPUB_TABS as $key => $tab): ?>
    <a class="qpub-tab <?= $key === $activeTab ? 'is-active' : '' ?>"
      href="<?= qpub_hu(qpub_url($tab['file'], qpub_tab_params($key, $carry))) ?>"
      <?= $key === $activeTab ? 'aria-current="page"' : '' ?>>
      <?= qpub_h($tab['label']) ?>
      <small><?= qpub_h($tab['en']) ?></small>
    </a>
    <?php endforeach; ?>
  </nav>
    <?php
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
