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

  <!-- UL.GG public footer: single stylesheet for main site and Quest. -->
  <link rel="stylesheet" href="/assets/css/footer_public.css?v=<?= (int)(@filemtime(dirname(__DIR__, 4) . '/assets/css/footer_public.css') ?: 1) ?>">
  <link rel="stylesheet" href="/assets/css/shell_public.css?v=9c9260a447f2">
</head>

<body class="un-body">
  <?php $ulggShellMode = 'quest'; $activeMenu = 'quest_public'; require dirname(__DIR__, 4) . '/layout/header_public.php'; ?>

  <div class="un-overlay"></div>

  <div class="un-layout">
    <?php require dirname(__DIR__, 4) . '/layout/sidebar_public.php'; ?>

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

<?php
      $supportUrl = '/pages/index.php#supportModal';
      require_once dirname(__DIR__, 4) . '/layout/footer_public.php';
?>
    </main>
  </div>

  <script src="<?= qpub_h($shared) ?>/js/main.js"></script>
  <script src="<?= qpub_h($self) ?>/quest_public.js"></script>
  <script defer src="/assets/js/shell_public.js?v=82e916307a00"></script>
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
