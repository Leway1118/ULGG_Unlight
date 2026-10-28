<?php
declare(strict_types=1);

/** Quest player-facing copy only; statistics remain unchanged. */
function ulggQuestPlayerCopy(string $html, string $view): string {
    if ($view !== 'regions') return $html;

    $html = preg_replace(
        '~<div class="ulgg-mobile-condensed" id="ulggMobileRankRules">.*?</details></div>~su',
        '<span id="ulggMobileRankRules" hidden></span>',
        $html, 1, $n
    );
    if (!is_string($html) || $n !== 1) {
        throw new RuntimeException('STOP=MOBILE_NOTE_DRIFT');
    }

    foreach ([
        '總樣本數依全期間公開來源紀錄計算',
        '綜合分 V2-A ='
    ] as $needle) {
        $pattern = '~<div class="ulgg-mobile-desktop-note">\s*<p\b[^>]*>.*?'
            .preg_quote($needle, '~')
            .'.*?</p>\s*</div>~su';

        $html = preg_replace($pattern, '', $html, 1, $count);
        if (!is_string($html) || $count !== 1) {
            throw new RuntimeException('STOP=DESKTOP_NOTE_DRIFT');
        }
    }

    $html = str_replace('綜合分 V2', '綜合分', $html, $renamed);
    if ($renamed < 2) {
        throw new RuntimeException('STOP=SCORE_LABEL_DRIFT');
    }

    $thCount = 0;
    $html = preg_replace_callback(
        '~<th\b[^>]*>.*?</th>~su',
        static function (array $m) use (&$thCount): string {
            if (!str_contains($m[0], '綜合分')) return $m[0];

            ++$thCount;
            $th = str_replace(
                'QP/h 50%、VAL/h 30%、QP/AP 10%、VAL/AP 10%（各指標以百分位計算）',
                '點擊 ⓘ 查看綜合分計算方式',
                $m[0]
            );

            return substr_replace(
                $th,
                ' <button class="ulgg-composite-info" type="button" popovertarget="ulggCompositeHelpPanel" aria-label="查看綜合分計算說明">ⓘ</button>',
                strrpos($th, '</th>'),
                0
            );
        },
        $html
    );

    if (!is_string($html) || $thCount < 1) {
        throw new RuntimeException('STOP=SCORE_TH_DRIFT');
    }

    $html .= <<<'HTML'
<style id="ulggCompositeHelpStyle">
.quest-native-content .ulgg-composite-info{
  margin-left:5px;padding:1px 4px;border:0;
  background:transparent;color:inherit;
  cursor:pointer;font:inherit
}
.quest-native-content .ulgg-composite-info:focus-visible{
  outline:2px solid currentColor;outline-offset:2px
}
#ulggCompositeHelpPanel[popover]{
  width:min(92vw,460px);max-height:80vh;
  overflow:auto;padding:20px;border:1px solid #64748b;
  border-radius:12px;background:#182233;color:#f8fafc;
  box-shadow:0 15px 45px #0008
}
#ulggCompositeHelpPanel::backdrop{background:#0009}
#ulggCompositeHelpPanel table{
  width:100%;border-collapse:collapse;margin:10px 0
}
#ulggCompositeHelpPanel td,#ulggCompositeHelpPanel th{
  padding:7px;border-bottom:1px solid #ffffff33;
  text-align:left
}
</style>
<div id="ulggCompositeHelpPanel" popover="auto"
     role="dialog" aria-labelledby="ulggCompositeHelpTitle">
<h3 id="ulggCompositeHelpTitle">綜合分怎麼計算？</h3>
<p>綜合分比較一般地圖的整體收益效率，分數越高代表效率越好。</p>
<table>
<thead><tr><th>項目</th><th>權重</th></tr></thead>
<tbody>
<tr><td>QP 每小時收益</td><td>50%</td></tr>
<tr><td>VAL 每小時收益</td><td>30%</td></tr>
<tr><td>QP 每 AP 收益</td><td>10%</td></tr>
<tr><td>VAL 每 AP 收益</td><td>10%</td></tr>
</tbody>
</table>
<p>先計算各項的百分位，再按權重加總。只有資料完整的一般地圖參與評分；需至少 30 筆有效及 QP/VAL 樣本。同值採平均名次，樣本不足顯示「—」，特殊地圖不評分。</p>
</div>
HTML;

    return $html;
}
