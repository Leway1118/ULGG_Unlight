<?php
/** Mobile-only compact disclosures; data, sorting, charts unchanged. */
declare(strict_types=1);

function ulggQuestMobileDensity(string $html, string $view): string {
    if ($view !== 'regions') return $html;

    $notes = [];
    if (preg_match_all('~<p\b[^>]*>.*?</p>~su', $html, $all, PREG_OFFSET_CAPTURE) === false) {
        throw new RuntimeException('MOBILE_NOTES_PARSE');
    }

    foreach ($all[0] as $pair) {
        [$text, $at] = $pair;
        if (str_contains($text, '總樣本數依全期間公開來源紀錄計算')) {
            $notes['samples'] = [$text, $at];
        }
        if (str_contains($text, '綜合分 V2-A')) {
            $notes['score'] = [$text, $at];
        }
        if (str_contains($text, '表格數值為貝氏估計百分比')) {
            $notes['matrix'] = [$text, $at];
        }
    }

    if (!isset($notes['samples'], $notes['score'], $notes['matrix'])) {
        throw new RuntimeException('MOBILE_NOTES_SOURCE_DRIFT');
    }

    $intro = $notes['samples'][0];
    $formula = $notes['score'][0];

    $replacements = [
        [
            $notes['samples'][1],
            strlen($intro),
            '<div class="ulgg-mobile-desktop-note">'.$intro.'</div>'
            .'<div class="ulgg-mobile-condensed" id="ulggMobileRankRules">'
            .'<span>依公開紀錄比較效率；樣本少的地圖請斟酌。</span>'
            .'<details><summary>查看排行榜與綜合分規則</summary>'
            .'<div class="ulgg-mobile-details-body">'.$intro.$formula.'</div>'
            .'</details></div>'
        ],
        [
            $notes['score'][1],
            strlen($formula),
            '<div class="ulgg-mobile-desktop-note">'.$formula.'</div>'
        ],
        [
            $notes['matrix'][1],
            strlen($notes['matrix'][0]),
            '<div class="ulgg-mobile-desktop-note">'.$notes['matrix'][0].'</div>'
            .'<div class="ulgg-mobile-condensed" id="ulggMobileStarRules">'
            .'<span>顏色越深，估計機率越高；表格可左右滑動。</span>'
            .'<details><summary>查看機率估算說明</summary>'
            .'<div class="ulgg-mobile-details-body">'
            .$notes['matrix'][0].'</div></details></div>'
        ]
    ];

    usort($replacements, static fn($a, $b) => $b[0] <=> $a[0]);

    foreach ($replacements as [$at, $len, $replacement]) {
        $html = substr_replace($html, $replacement, $at, $len);
    }

    $pattern = '~<div class="qpub-cards qpub-cards--tight">\s*(?:<div class="qpub-card">.*?</div>\s*){6}</div>~su';

    if (preg_match($pattern, $html, $found) !== 1) {
        throw new RuntimeException('MOBILE_STAR_CARDS_SOURCE_DRIFT');
    }

    $cards = $found[0];

    $extract = static function(string $label) use ($cards): string {
        $pattern = '~'.preg_quote($label, '~').'</span>\s*<span[^>]*>([^<]+)</span>~su';
        if (preg_match($pattern, $cards, $m) !== 1) {
            throw new RuntimeException('MOBILE_STAR_COUNT_DRIFT_'.$label);
        }
        return trim($m[1]);
    };

    $resolved = $extract('已解析樣本');
    $unknown = $extract('未知星數樣本');

    $new = '<div class="ulgg-mobile-desktop-note">'.$cards.'</div>'
       .'<div class="ulgg-mobile-star-meta" id="ulggMobileStarMeta">'
       .'<div class="ulgg-mobile-star-stats">'
       .'<span>已解析 <strong>'.$resolved.'</strong></span>'
       .'<span>未知星數 <strong>'.$unknown.'</strong></span>'
       .'</div>'
       .'<details><summary>查看模型與完整樣本資訊</summary>'
       .$cards.'</details></div>';

    $html = str_replace($cards, $new, $html);

    $css = <<<'CSS'
<style id="ulggMobileQuestDensityV1">
.quest-native-content .ulgg-mobile-condensed,
.quest-native-content .ulgg-mobile-star-meta {
  display:none;
}
@media (max-width:760px) {
  .quest-native-content .ulgg-mobile-desktop-note {
    display:none!important;
  }
  .quest-native-content .ulgg-mobile-condensed,
  .quest-native-content .ulgg-mobile-star-meta {
    display:block;
  }
  .quest-native-content .ulgg-mobile-condensed {
    font-size:12px;
    line-height:1.5;
    color:#bfc8e1;
    margin:7px 0 10px;
  }
  .quest-native-content .ulgg-mobile-condensed details,
  .quest-native-content .ulgg-mobile-star-meta details {
    margin-top:5px;
  }
  .quest-native-content .ulgg-mobile-condensed summary,
  .quest-native-content .ulgg-mobile-star-meta summary {
    cursor:pointer;
    color:#b4c9ff;
    font-size:12px;
    width:fit-content;
    padding:5px 1px;
    line-height:1.45;
  }
  .quest-native-content .ulgg-mobile-details-body {
    padding:8px 10px;
    border:1px solid #383d54;
    border-radius:8px;
    background:#1b1e2b;
    overflow-wrap:anywhere;
  }
  .quest-native-content .ulgg-mobile-details-body p {
    margin:5px 0;
    line-height:1.5;
  }
  .quest-native-content .ulgg-mobile-star-meta {
    margin:8px 0 12px;
  }
  .quest-native-content .ulgg-mobile-star-stats {
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    font-size:12px;
  }
  .quest-native-content .ulgg-mobile-star-stats span {
    flex:1 1 auto;
    border:1px solid #373b51;
    border-radius:8px;
    padding:8px 10px;
    background:#23253a;
    white-space:nowrap;
  }
  .quest-native-content .ulgg-mobile-star-meta .qpub-cards {
    display:grid;
    grid-template-columns:repeat(2,minmax(0,1fr));
    gap:6px;
    margin:6px 0 0;
  }
  .quest-native-content .ulgg-mobile-star-meta .qpub-card {
    padding:8px;
    min-width:0;
  }
  .quest-native-content .ulgg-mobile-star-meta .qpub-card__value--sm {
    font-size:12px;
    overflow-wrap:anywhere;
    word-break:break-word;
  }
  .quest-native-content .qpub-stars-section .qpub-panel__head {
    margin-bottom:6px;
  }
  .quest-native-content .qpub-stars-section .qpub-alert {
    padding:7px 9px;
    font-size:12px;
    line-height:1.45;
  }
  .quest-native-content .qpub-heatmap caption {
    display:none;
  }
}
</style>
CSS;

    return $css.$html;
}
