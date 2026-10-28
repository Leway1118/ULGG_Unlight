<?php
declare(strict_types=1);

function ulggQuestStarPlayerCopy(string $html, string $view): string
{
    if ($view !== 'regions') {
        return $html;
    }

    $rules = [
        '依公開匿名紀錄的貝氏估計；下方 duration 可單獨篩選。'
            => '根據公開遊玩紀錄，估計各星數的出現機率。',
        '全期間貝氏彙總'
            => '全期間統計',
        '表格數值為貝氏估計百分比（estimated_percent）。'
            => '表格顯示各星數的估計機率。',
        '查看模型與完整樣本資訊'
            => '查看詳細統計資訊',
        '查看機率估算說明'
            => '查看星數機率說明',
    ];

    foreach ($rules as $old => $new) {
        $count = substr_count($html, $old);

        if ($count < 1) {
            throw new RuntimeException(
                'STOP=STAR_COPY_SOURCE_DRIFT'
            );
        }

        $html = str_replace($old, $new, $html);
    }

    $html = str_replace(
        '（persistent_aggregate）',
        '',
        $html,
        $sourceCount
    );

    if ($sourceCount !== 1) {
        throw new RuntimeException(
            'STOP=STAR_SOURCE_LABEL_DRIFT'
        );
    }

    // Remove technical metadata cards from both
    // desktop cards and mobile expandable details.
    $pattern = '~<div class="qpub-card">\s*'
        .'<span class="qpub-card__label">'
        .'(?:模型|先驗強度 / 基準 duration)'
        .'</span>.*?</div>~su';

    $html = preg_replace(
        $pattern,
        '',
        $html,
        -1,
        $removed
    );

    if (!is_string($html) || $removed !== 4) {
        throw new RuntimeException(
            'STOP=STAR_METADATA_CARD_DRIFT'
        );
    }

    $html = str_replace(
        '先驗基準',
        '參考基準',
        $html
    );

    return $html;
}
