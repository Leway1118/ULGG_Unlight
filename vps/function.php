<?php
/**
 * 產生「角色＋武器」區塊 HTML
 *
 * @param int[] $chars      角色 id 陣列 (長度 3)
 * @param int[] $weapons    武器 id 陣列 (長度 3)
 * @param array $charMap    角色資料表：id → ['ico'=>'檔名.png','cost'=>數值]
 * @param array $weaponMap  武器資料表：id → 'ATK+1 / DEF-2' 文字描述
 * @return string           完整的 HTML 片段
 */
function build_team_block(array $chars, array $weapons, array $charMap, array $weaponMap): string
{
    $costArr = [];
    $costSum = 0;
    $html  = '<div class="team d-flex align-items-center">';
    $html .= '  <button type="button" class="btn btn-light cost-btn">';
    // 暫填 cost，後面再用 preg_replace 回寫
    $html .=    'COST _C_';
    $html .= '  </button>';
    $html .= '  <div class="characters d-flex">';
    foreach ($chars as $i => $cid) {
        $ico  = $charMap[$cid]['ico']  ?? 'na.png';
        $cc   = (int)($charMap[$cid]['cost'] ?? 0);
        $costArr[] = $cc;
        $costSum  += $cc;
        $wText    = $weaponMap[$weapons[$i]] ?? '';
        $html    .= '<div class="d-flex flex-column align-items-center me-2">';
        $html    .=   "<img src=\"uploads/{$ico}\" class=\"character-img\" loading=\"lazy\">";
        $html    .=   "<small class=\"weapon-desc\">{$wText}</small>";
        $html    .= '</div>';
    }
    $html .= '  </div>'; // .characters
    $html .= '</div>';   // .team

    // 懲罰計算
    if (count($costArr) === 3) {
        $costSum += cost_punish($costArr[0], $costArr[1], $costArr[2]);
    }
    $extraCls = $costSum > array_sum($costArr) ? ' text-danger' : '';

    // 回填 COST 與顏色
    return preg_replace(
        ['/_C_/', '/btn-light cost-btn/'],
        [$costSum, "btn-light cost-btn{$extraCls}"],
        $html
    );
}


/**
 * 產生「事件卡」區塊 HTML (共 18 個 slot，3 行×6 列)
 *
 * @param int[] $events    事件 id 陣列 (長度 ≤18)
 * @param array $eventMap  id → ico 檔名
 * @return string          完整的 HTML 片段
 */
function build_event_block(array $events, array $eventMap): string
{
    $html  = '<div class="event-group d-flex flex-wrap">';
    for ($i = 0; $i < 18; $i++) {
        $eid = $events[$i] ?? null;
        if ($eid && isset($eventMap[$eid])) {
            $ico = $eventMap[$eid];
            $html .= "<div class=\"event\"><img src=\"uploads/{$ico}\" class=\"evt-img\" title=\"Event #{$eid}\" loading=\"lazy\"></div>";
        } else {
            $html .= '<div class="event event-empty"></div>';
        }
    }
    $html .= '</div>'; // .event-group
    return $html;
}


/**
 * 產生「玩家名稱 ＋ BP ＋ 勝負」區塊 HTML
 *
 * @param string $name     玩家名稱
 * @param int    $bp       BP 值
 * @param bool   $unknown  是否未判 (true=未判)
 * @param string $status   'win'|'lose'|'tie'
 * @return string          完整的 HTML 片段
 */
function build_player_input(string $name, int $bp, bool $unknown, string $status): string
{
    if ($unknown) {
        $cls = 'queue-btn';  $txt = '未判';
    } else {
        switch ($status) {
            case 'tie':  $cls = 'tie-btn';        $txt = '平'; break;
            case 'win':  $cls = 'player-win-btn'; $txt = '勝'; break;
            case 'lose': $cls = 'enemy-win-btn';  $txt = '負'; break;
            default:     $cls = 'queue-btn';      $txt = '未判';
        }
    }

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<div class="player-input d-flex align-items-center">
  <button type="button" class="btn btn-light wr-btn {$cls}">{$txt}</button>
  <span class="player-name">{$safeName}</span>
  <button type="button" class="btn btn-light wr-btn">BP {$bp}</button>
</div>
HTML;
}
