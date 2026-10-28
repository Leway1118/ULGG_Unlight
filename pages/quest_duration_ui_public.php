<?php
declare(strict_types=1);

/**
 * Quest P3: replace redundant Duration select
 * with per-row detail links.
 * No statistical calculation or data modification.
 */
function ulggQuestDurationUi(string $html, string $view): string
{
    if ($view !== 'regions') {
        return $html;
    }

    $start = strpos($html, '<section id="stars"');
    if ($start === false) {
        throw new RuntimeException('STOP=STARS_SECTION_MISSING');
    }

    $end = strpos($html, '</section>', $start);
    if ($end === false) {
        throw new RuntimeException('STOP=STARS_SECTION_END');
    }
    $end += strlen('</section>');

    $section = substr($html, $start, $end - $start);

    if (substr_count($section, 'id="qpub-stars-duration"') !== 1) {
        throw new RuntimeException('STOP=DURATION_SELECT_DRIFT');
    }

    if (!preg_match(
        '~<option\b[^>]*\bvalue="(all|\d{1,2})"[^>]*\bselected(?:\s|=|>)~s',
        $section,
        $selectedMatch
    )) {
        throw new RuntimeException('STOP=SELECTED_DURATION_MISSING');
    }

    $selected = $selectedMatch[1];

    $section = preg_replace(
        '~<div class="qpub-toolbar">\s*<form\b[^>]*>.*?<select\b[^>]*\bid="qpub-stars-duration"[^>]*>.*?</select>.*?</form>\s*</div>~s',
        '',
        $section,
        1,
        $removed
    );

    if (!is_string($section) || $removed !== 1) {
        throw new RuntimeException('STOP=TOOLBAR_STRUCTURE_DRIFT');
    }

    $rowCount = 0;

    $section = preg_replace_callback(
        '~(<th\b[^>]*class="[^"]*\bqpub-heatmap__row-head\b[^"]*"[^>]*>)(.*?)(</th>)~su',
        static function (array $m) use ($selected, &$rowCount): string {
            if (!preg_match(
                '~<span\b[^>]*class="[^"]*\bqpub-sub\b[^"]*"[^>]*>\s*d(\d{1,2})\s*</span>~su',
                $m[2],
                $id
            )) {
                throw new RuntimeException('STOP=DURATION_ROW_ID');
            }

            ++$rowCount;

            if ($selected !== 'all') {
                return $m[0];
            }

            $index = (int)$id[1];
            if ($index < 0 || $index > 10) {
                throw new RuntimeException('STOP=DURATION_INDEX');
            }

            $link = ' <a class="ulgg-duration-detail-link"'
                .' href="?view=regions&amp;duration='.$index.'#stars"'
                .' aria-label="查看此 Duration 的詳細統計">'
                .'明細</a>';

            return $m[1].$m[2].$link.$m[3];
        },
        $section
    );

    $expectedRows = $selected === 'all' ? 11 : 1;

    if (!is_string($section) || $rowCount !== $expectedRows) {
        throw new RuntimeException('STOP=DURATION_ROW_COUNT');
    }

    if ($selected !== 'all') {
        $section = preg_replace(
            '~(<h2 class="qpub-panel__title">\s*星數機率矩陣.*?</h2>)~su',
            '$1<p class="ulgg-duration-back">'
            .'<a href="?view=regions#stars">返回全部 Duration</a></p>',
            $section,
            1,
            $backCount
        );

        if (!is_string($section) || $backCount !== 1) {
            throw new RuntimeException('STOP=BACK_LINK_DRIFT');
        }
    }

    $section .= <<<'CSS'
<style id="ulggQuestDurationUiP3">
.quest-native-content .ulgg-duration-detail-link {
    display:inline-block;
    margin-left:8px;
    font-size:12px;
    font-weight:500;
    color:#9ac7ff;
    text-decoration:underline;
    text-underline-offset:3px;
    white-space:nowrap;
}
.quest-native-content .ulgg-duration-back {
    margin:8px 0 12px;
    font-size:13px;
}
.quest-native-content .ulgg-duration-back a {
    color:#9ac7ff;
    text-decoration:underline;
}
</style>
CSS;

    return substr_replace(
        $html,
        $section,
        $start,
        $end - $start
    );
}
