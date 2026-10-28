<?php
/**
 * TEMPORARY PREVIEW BRIDGE ONLY (QUEST-NATIVE-01)
 * Public URL: /pages/quest_native_preview.php?view=regions
 *            /pages/quest_native_preview.php?view=quest&region=3%3A7
 *
 * Does not replace or edit the original Quest entries. The original Quest
 * renderer provides the data section; the genuine shared base.php provides
 * the only visible navigation and footer. No external fetch and no new SQL.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
$pdo = $db;

$view = (string) ($_GET['view'] ?? 'regions');
if (!in_array($view, ['regions', 'quest'], true)) {
    http_response_code(404);
    exit('找不到指定的 Quest 檢視。');
}

if ($view === 'quest') {
    $region = (string) ($_GET['region'] ?? '');
    // The original detail controller handles an unknown but well-formed ID.
    if (!preg_match('/\A[0-9]{1,3}:[0-9]{1,3}\z/D', $region)) {
        http_response_code(400);
        exit('請從地域排行選擇有效地域。');
    }
}

$questDir = __DIR__ . '/quest_public/v1.4.1';
$sourceFile = $questDir . '/' . ($view === 'regions' ? 'index.php' : 'quest.php');
if (!is_file($sourceFile) || !is_file(__DIR__ . '/../layout/base.php')) {
    http_response_code(503);
    exit('Quest 或共用版型暫時無法使用。');
}

// Existing bootstrap uses SCRIPT_NAME to infer asset routes. In this bridge
// the public Quest base is explicit, while its data source remains unchanged.
if (!defined('QPUB_BASE_PATH')) {
    define('QPUB_BASE_PATH', '/pages/quest_public/v1.4.1');
}

ob_start();
try {
    require $sourceFile;
    $originalHtml = (string) ob_get_clean();
} catch (Throwable $e) {
    ob_end_clean();
    error_log('[QUEST-NATIVE-01] original Quest render failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Quest 內容暫時無法顯示。');
}

/** Strip only the duplicated Quest shell, not any source Quest stats. */
function ulggQuestNativeExtract(string $html): ?string
{
    $marker = '<main class="un-main">';
    $main = strpos($html, $marker);
    if ($main === false) {
        return null;
    }
    $inner = strpos($html, '<div class="un-main-inner">', $main);
    $head = $inner === false ? false : strpos($html, '<div class="page-header">', $inner);
    $h1End = $head === false ? false : strpos($html, '</h1>', $head);
    $start = $h1End === false ? false : strpos($html, '</div>', $h1End);
    $footer = $start === false ? false : strpos($html, '<footer', $start);
    if ($footer === false) {
        return null;
    }
    // Immediately before shared footer is the closing div of un-main-inner.
    $end = strrpos(substr($html, 0, $footer), '</div>');
    if ($start === false || $end === false || $end <= $start + 6) {
        return null;
    }
    $part = trim(substr($html, $start + 6, $end - $start - 6));
    return $part === '' ? null : $part;
}

$content = ulggQuestNativeExtract($originalHtml);
if ($content === null) {
    error_log('[QUEST-NATIVE-01] Quest layout markers changed; preview refused');
    http_response_code(503);
    exit('Quest 版型結構已變更，為避免顯示錯誤已停止預覽。');
}

/* QUEST-UI-VAL-LABEL-CLEANUP-20261027 */
if ($view === 'quest') {
    $content = preg_replace(
        '~\s*數值取自已發布 JSON；未觀測效率顯示為「—」。Quest 名稱僅為標籤，識別以\s*<code>zone_key#quest_id</code>\s*為準。~u',
        '',
        $content,
        1
    );

    if (!is_string($content)) {
        http_response_code(503);
        exit('Quest 說明文字處理失敗');
    }

    $content = preg_replace(
        '~(<th\b[^>]*>)\s*平均 VAL\s*(</th>)~u',
        '$1VAL$2',
        $content,
        1
    );

    if (!is_string($content)) {
        http_response_code(503);
        exit('Quest VAL 欄位處理失敗');
    }
}

/* QUEST-NATIVE-VAL-DATA-V1-20261027: value_per_quest from published panel snapshot. */
if ($view === 'quest') {
    $valPath = __DIR__ . '/quest_val_panel_v1.json';
    if (is_readable($valPath)) {
        $valDoc = json_decode((string) file_get_contents($valPath), true);
        $valRows = is_array($valDoc) && ($valDoc['schema'] ?? '') === 'ULGG_QUEST_VAL_PANEL_V1'
            ? ($valDoc['rows'] ?? null) : null;
        if (is_array($valRows)) {
            $valByKey = [];
            foreach ($valRows as $valRow) {
                if (is_array($valRow) && isset($valRow['quest_key'])
                    && isset($valRow['val']) && is_numeric($valRow['val'])) {
                    $valByKey[(string) $valRow['quest_key']] = (float) $valRow['val'];
                }
            }
            $valZone = (string) ($_GET['region'] ?? '');
            $content = preg_replace_callback(
                '~<tr\b[^>]*>.*?</tr>~si',
                static function (array $m) use ($valByKey, $valZone): string {
                    preg_match_all('~<td\b[^>]*>.*?</td>~si', $m[0], $cells, PREG_OFFSET_CAPTURE);
                    if (count($cells[0]) !== 13) {
                        return $m[0];
                    }
                    $questLabel = html_entity_decode(
                        strip_tags($cells[0][1][0]), ENT_QUOTES | ENT_HTML5, 'UTF-8'
                    );
                    if (!preg_match('~#\s*(\d+)\b~u', $questLabel, $id)) {
                        return $m[0];
                    }
                    $key = $valZone . '#' . (int) $id[1];
                    if (!array_key_exists($key, $valByKey)) {
                        return $m[0];
                    }
                    $cell = $cells[0][9][0];
                    $plain = trim(html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($plain !== '—') {
                        return $m[0]; // Do not overwrite future native VAL output.
                    }
                    $val = $valByKey[$key];
                    $formatted = abs($val - round($val)) < 0.000001
                        ? number_format($val, 0, '.', ',')
                        : number_format($val, 2, '.', ',');
                    if (!preg_match('~^(<td\b[^>]*>).*?(</td>)$~si', $cell, $parts)) {
                        return $m[0];
                    }
                    $newCell = $parts[1] . $formatted . $parts[2];
                    return substr_replace(
                        $m[0], $newCell, $cells[0][9][1], strlen($cell)
                    );
                },
                $content
            );
            if (!is_string($content)) {
                http_response_code(503);
                exit('Quest VAL 資料處理失敗。');
            }
        }
    }
}

/* QUEST-PRECISION-DISPLAY-V1: presentation only. */
function ulggCompactTd(string $cell, int $digits): string {
  if (!preg_match('~\A(<td\b[^>]*>)([^<]*)(</td>)\z~si',$cell,$m)) return $cell;
  $s=trim(html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8'));
  if (!preg_match('/\A-?(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)(?:\.[0-9]+)?\z/D',$s)) return $cell;
  $v=(float)str_replace(',','',$s);
  return is_finite($v) ? $m[1].number_format($v,$digits,'.',',').$m[3] : $cell;
}
$precisionCols=$view==='regions'
  ? [3=>1,4=>0,5=>1,6=>0,9=>1,10=>1,11=>1]
  : [6=>0,7=>1,8=>0,9=>0];
$expectedCols=$view==='regions' ? 12 : 13;
$content=preg_replace_callback(
  '~<table\b[^>]*class=["\'][^"\']*\bqpub-table\b[^"\']*["\'][^>]*>.*?</table>~si',
  static function($m) use($precisionCols,$expectedCols,$view) {
    $table=$m[0];
    if ($view==='regions' && (!str_contains($table,'平均 QP') || !str_contains($table,'VAL/AP'))) return $table;
    if ($view==='quest' && (!str_contains($table,'搜尋 AP') || !str_contains($table,'VAL/h'))) return $table;
    return (string)preg_replace_callback('~<tr\b[^>]*>.*?</tr>~si',
      static function($r) use($precisionCols,$expectedCols) {
        preg_match_all('~<td\b[^>]*>.*?</td>~si',$r[0],$td,PREG_OFFSET_CAPTURE);
        if (count($td[0])!==$expectedCols) return $r[0];
        $out=$r[0];
        $cols=$precisionCols; krsort($cols,SORT_NUMERIC);
        foreach ($cols as $index=>$digits) {
          [$before,$offset]=$td[0][$index];
          $out=substr_replace($out,ulggCompactTd($before,$digits),$offset,strlen($before));
        }
        return $out;
      },$table);
  },$content);
if (!is_string($content)) {http_response_code(503);exit('Quest 精度處理失敗');}
if ($view==='regions') {
  $content=preg_replace_callback('~<div\b[^>]*class="[^"]*\bqpub-card\b[^"]*"[^>]*>.*?</div>~si',
    static function($m) {
      $card=$m[0];
      $digits=match(true) {
        str_contains($card,'最高 QP 效率')=>1,
        str_contains($card,'最高 VAL 效率')=>0,
        str_contains($card,'最高綜合分')=>1,
        default=>null,
      };
      if ($digits===null) return $card;
      return (string)preg_replace_callback('~(<span\b[^>]*class="[^"]*\bqpub-card__value\b[^"]*"[^>]*>)([^<]*)(</span>)~si',
        static function($n) use($digits) {
          $s=trim($n[2]);
          return preg_match('/\A[0-9][0-9,]*(?:\.[0-9]+)?\z/D',$s)
            ? $n[1].number_format((float)str_replace(',','',$s),$digits,'.',',').$n[3]
            : $n[0];
        },$card,1);
    },$content);
  if (!is_string($content)) {http_response_code(503);exit('Quest 卡片精度處理失敗');}
}

/* ULGG-SORT-BRIDGE-V1: full-result sorting, source Quest files remain unchanged. */
require_once __DIR__ . '/quest_sort_public.php';
$content = ulggSortableTables($content, $view, [
    'all' => $allRows ?? [],
    'page' => $page ?? 1,
    'pageSize' => $pageSize ?? 50,
    'groups' => array_values(array_filter(
        [$ordinaryRows ?? [], $specialRows ?? []],
        static fn($g) => is_array($g) && count($g) > 0
    )),
]);

/* ULGG-VAL-INFO-MODAL-BRIDGE-V1: UI-only helper; original Quest pages remain untouched. */
require_once __DIR__ . '/quest_val_info_public.php';
$content = ulggValInfoModal($content);

/* ULGG-QUEST-TOOLBAR-GROUP-V1: presentation only. */
$content = '<style id="ulggQuestToolbarGroupedV1">
.quest-native-content .qpub-toolbar {
  display:flex;
  flex-flow:row wrap;
  align-items:flex-end !important;
  justify-content:flex-start;
  gap:12px;
  margin-bottom:12px;
}
.quest-native-content .qpub-toolbar > .qpub-field {
  margin:0;
  min-width:0;
  align-self:flex-end;
}
.quest-native-content .qpub-toolbar > .qpub-field:not(.qpub-field--search) {
  display:grid;
  grid-template-rows:14px 38px;
  gap:6px;
  flex:0 0 142px;
  width:142px;
  max-width:200px;
}
.quest-native-content .qpub-toolbar .qpub-field__label {
  display:block;
  margin:0;
  line-height:14px;
  height:14px;
  white-space:nowrap;
}
.quest-native-content .qpub-toolbar > .qpub-field:not(.qpub-field--search) > .qpub-select {
  display:block;
  box-sizing:border-box;
  width:100%;
  max-width:none;
  height:38px;
  min-height:38px;
  margin:0;
}
.quest-native-content .qpub-toolbar > .qpub-field--search {
  display:grid !important;
  grid-template-areas:"label label" "search submit";
  grid-template-columns:minmax(0,1fr) max-content;
  grid-template-rows:14px 38px;
  align-items:stretch;
  gap:6px 8px;
  flex:0 1 340px;
  width:340px;
  max-width:100%;
  min-width:min(260px,100%);
}
.quest-native-content .qpub-toolbar > .qpub-field--search > .qpub-field__label {
  grid-area:label;
}
.quest-native-content .qpub-toolbar > .qpub-field--search > .qpub-input {
  grid-area:search;
  display:block;
  box-sizing:border-box;
  width:100%;
  max-width:none;
  min-width:0;
  height:38px;
  min-height:38px;
  margin:0;
}
.quest-native-content .qpub-toolbar > .qpub-field--search > .qpub-btn {
  grid-area:submit;
  box-sizing:border-box;
  height:38px;
  min-height:38px;
  margin:0;
  white-space:nowrap;
  justify-content:center;
}
.quest-native-content .qpub-toolbar > .qpub-btn {
  box-sizing:border-box;
  display:inline-flex;
  flex:0 0 auto;
  align-self:flex-end;
  height:38px;
  min-height:38px;
  margin:0;
  white-space:nowrap;
}
@media (max-width:760px) {
  .quest-native-content .qpub-toolbar > .qpub-field:not(.qpub-field--search),
  .quest-native-content .qpub-toolbar > .qpub-field--search {
    flex:1 1 100%;
    width:100%;
    max-width:none;
    min-width:0;
  }
  .quest-native-content .qpub-toolbar > .qpub-btn {
    flex:1 1 auto;
    justify-content:center;
  }
}
</style>' . $content;

/* ULGG-QUEST-MOBILE-DENSITY-V1 */
require_once __DIR__ . '/quest_mobile_density_public.php';
$content = ulggQuestMobileDensity($content,$view);

/* ULGG-QUEST-PLAYER-COPY-P1 */
require_once __DIR__ . '/quest_player_copy_public.php';
$content = ulggQuestPlayerCopy($content,$view);

/* ULGG-QUEST-STAR-COPY-P2 */
require_once __DIR__ . '/quest_star_copy_public.php';
$content = ulggQuestStarPlayerCopy($content,$view);

/* ULGG-QUEST-DURATION-UI-P3 */
require_once __DIR__ . '/quest_duration_ui_public.php';
$content = ulggQuestDurationUi($content,$view);

// Remap only navigation within Quest; keep links to every other main-site page.
// This is a preview, so the source Quest entries and original routes remain intact.
$content = preg_replace_callback(
    '~\b(href|action)=("|\')(.*?)\2~is',
    static function (array $m): string {
        $original = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = parse_url($original);
        if ($parts === false || isset($parts['host'])) {
            return $m[0];
        }
        $path = $parts['path'] ?? '';
        $target = match ($path) {
            '/pages/quest_public/v1.4.1/index.php', 'index.php' => 'regions',
            '/pages/quest_public/v1.4.1/quest.php', 'quest.php' => 'quest',
            default => null,
        };
        if ($target === null) {
            return $m[0];
        }
        $params = [];
        parse_str((string) ($parts['query'] ?? ''), $params);
        unset($params['view']);
        $query = http_build_query(['view' => $target] + $params, '', '&', PHP_QUERY_RFC3986);
        $url = '/pages/quest_native_preview.php?' . $query;
        if (isset($parts['fragment'])) {
            $url .= '#' . rawurlencode($parts['fragment']);
        }
        return $m[1] . '=' . $m[2] . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . $m[2];
    },
    $content
);
if (!is_string($content)) {
    http_response_code(503);
    exit('Quest 連結處理失敗。');
}

// GET forms drop an action URL's query params on submission. Restore view.
$content = preg_replace_callback(
    '~<form\b[^>]*>~is',
    static function (array $m) use ($view): string {
        if (!str_contains($m[0], '/pages/quest_native_preview.php')) {
            return $m[0];
        }
        return $m[0] . '<input type="hidden" name="view" value="' . $view . '">';
    },
    $content
);

$questStylesheet = '/pages/quest_public/v1.4.1/assets/quest_public.css';
$questScript = '/pages/quest_public/v1.4.1/assets/quest_public.js';
$styleFile = $questDir . '/assets/quest_public.css';
$scriptFile = $questDir . '/assets/quest_public.js';
$cssVersion = is_file($styleFile) ? (string) filemtime($styleFile) : '1';
$jsVersion = is_file($scriptFile) ? (string) filemtime($scriptFile) : '1';

// Content-only styles and progressive enhancement; base.php owns the shell.
$pageContent = '<link rel="stylesheet" href="' . $questStylesheet . '?v=' . $cssVersion . '">'
    . '<div class="quest-native-content">' . $content . '</div>'
    . '<script src="' . $questScript . '?v=' . $jsVersion . '" defer></script>';

$pageTitleText = $view === 'regions' ? '地域排行' : '地域任務一覽';
$pageTitleFull = $pageTitleText . ' | UL.GG';
$seoTitle = $pageTitleFull;
$activeMenu = 'quest_public';
$hidePageHeader = false;

// The original Quest renderer has already loaded footer_public.php with
// require_once. Preserve its markup; PHP cannot include the same footer again
// inside base.php during this request. Insert it at base.php's exact footer
// location, outside un-main-inner, so it keeps the main-site layout geometry.
if (!preg_match('~<footer\b[^>]*class="[^"]*\bun-footer\b[^"]*"[^>]*>.*?</footer>~is', $originalHtml, $footerMatch)) {
    error_log('[QUEST-NATIVE-01] Quest shared footer marker missing');
    http_response_code(503);
    exit('Quest Footer 結構已變更，停止預覽。');
}
$sharedFooter = $footerMatch[0];
// The main base.php supplies supportModal, so link the support button to it.
$sharedFooter = str_replace(' href="/pages/index.php#supportModal"', '', $sharedFooter);

// The shared header shows login identity; never let a CDN cache that HTML.
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
ob_start();
require __DIR__ . '/../layout/base.php';
$mainHtml = (string) ob_get_clean();
// Should a future base.php stop using require_once, do not create duplicates.
if (!str_contains($mainHtml, '<footer class="un-footer"')) {
    $beforeCloseMain = strpos($mainHtml, '</main>');
    if ($beforeCloseMain === false) {
        error_log('[QUEST-NATIVE-01] base.php main close marker missing');
        http_response_code(503);
        exit('共用版型結構已變更，停止預覽。');
    }
    $mainHtml = substr_replace($mainHtml, $sharedFooter . "\n", $beforeCloseMain, 0);
}
echo $mainHtml;
