#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
from datetime import datetime
import shutil
import subprocess
import sys

TARGET = Path(sys.argv[1]) if len(sys.argv) > 1 else Path('/var/www/html/unlight/pages/raid_stats.php')
MARKER = 'RAID_STATS_SELF_LOOKUP_V1'


def replace_once(s: str, old: str, new: str, label: str) -> str:
    count = s.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected 1 anchor, found {count}')
    return s.replace(old, new, 1)


def main() -> int:
    if not TARGET.is_file():
        print(f'[FAIL] target not found: {TARGET}')
        return 2

    original = TARGET.read_text(encoding='utf-8')
    if MARKER in original:
        print('[STOP] self lookup patch already installed')
        return 0

    ts = datetime.now().strftime('%Y%m%d_%H%M%S')
    backup = TARGET.with_name(TARGET.name + f'.before_self_lookup_{ts}.bak')
    shutil.copy2(TARGET, backup)
    print(f'[BACKUP] {backup}')

    try:
        s = original

        # -----------------------------------------------------
        # Preserve full Solo ranking before public Top20 slice.
        # -----------------------------------------------------
        s = replace_once(
            s,
            """$soloPlayers=\n    array_slice(\n        $soloPlayers,\n        0,\n        20\n    );\n""",
            """/* RAID_STATS_SELF_LOOKUP_SOLO_ALL_V1 */\n$soloPlayersAll=$soloPlayers;\n\n\n$soloPlayers=\n    array_slice(\n        $soloPlayers,\n        0,\n        20\n    );\n""",
            'solo full rows',
        )

        # -----------------------------------------------------
        # Keep full DPM rows for lookup.
        # -----------------------------------------------------
        s = replace_once(
            s,
            """$dpmBoards=[\n    'avg_dpm'=>[],\n    'median_dpm'=>[],\n    'max_dpm'=>[],\n];\n""",
            """$dpmBoards=[\n    'avg_dpm'=>[],\n    'median_dpm'=>[],\n    'max_dpm'=>[],\n];\n\n/* RAID_STATS_SELF_LOOKUP_DPM_ALL_V1 */\n$avgRows=[];\n$medianRows=[];\n$maxRows=[];\n$dpmBossAllRows=[];\n""",
            'DPM lookup init',
        )

        s = replace_once(
            s,
            """        $dpmBossBoards[\n            $bossName\n        ]=\n            array_slice(\n""",
            """        /* RAID_STATS_SELF_LOOKUP_DPM_BOSS_ALL_V1 */\n        $dpmBossAllRows[\n            $bossName\n        ]=$bossPlayerRows;\n\n\n        $dpmBossBoards[\n            $bossName\n        ]=\n            array_slice(\n""",
            'DPM boss full rows',
        )

        # -----------------------------------------------------
        # Backend lookup state + exact ranking lookup.
        # -----------------------------------------------------
        backend = r'''/*
 * RAID_STATS_SELF_LOOKUP_V1
 *
 * Shared read-only lookup for:
 * - 玩家排行榜
 * - 輸出效率排行榜
 *
 * Public boards remain Top20. Lookup uses full sorted rows,
 * so a player outside Top20 can still see the exact rank.
 */
$rankLookupPlayer=trim(
    (string)(
        $_GET['rank_player']
        ??''
    )
);


$rankLookupOptions=[];

foreach($players as $lookupOptionRow){
    $lookupOptionName=trim(
        (string)(
            $lookupOptionRow['player_name']
            ??''
        )
    );

    if($lookupOptionName!==''){
        $rankLookupOptions[$lookupOptionName]=true;
    }
}

$rankLookupOptions=array_keys($rankLookupOptions);
sort($rankLookupOptions,SORT_NATURAL|SORT_FLAG_CASE);


function raidStatsFindPlayerRank(
    array $rows,
    string $playerName
): ?array {

    if($playerName===''){
        return null;
    }

    foreach($rows as $index=>$row){
        if(
            (string)(
                $row['player_name']
                ??''
            ) === $playerName
        ){
            return [
                'rank'=>$index+1,
                'row'=>$row,
            ];
        }
    }

    return null;
}


function raidStatsLookupPlayerValue(
    string $metric,
    array $row
): string {

    if($metric==='solo_rate'){
        return
            n($row['player_damage']??0)
            .' 傷｜'
            .n($row['solo_rate']??0,1)
            .'%';
    }

    if($metric==='first_rate'){
        return n($row[$metric]??0,1).'%';
    }

    if(in_array(
        $metric,
        ['avg_damage','avg_point'],
        true
    )){
        return n($row[$metric]??0,1);
    }

    return n($row[$metric]??0);
}


function raidStatsLookupDpmValue(
    string $metric,
    array $row
): string {

    if(substr($metric,0,5)==='boss_'){
        $value=n($row['max_dpm']??0,1).' DPM';

        if(
            isset($row['avg_dpm'])
            && $row['avg_dpm']!==null
        ){
            $value.='｜平均 '.n($row['avg_dpm'],1);
        }

        $value.='｜'.n($row['effective_raids']??0).'場';
        return $value;
    }

    $value=n($row[$metric]??0,1).' DPM';

    if(isset($row['effective_raids'])){
        $value.='｜'.n($row['effective_raids']).'場';
    }

    return $value;
}


$rankLookupPlayerLabels=[
    'total_damage'=>'總傷害',
    'total_point'=>'總分',
    'avg_damage'=>'平均傷害',
    'avg_point'=>'平均分',
    'max_point'=>'單場最高分',
    'max_damage'=>'單場最高傷害',
    'first_rate'=>'第一名率',
    'solo_rate'=>'單吃王',
];


$rankLookupPlayerBoardsAll=[
    'total_damage'=>
        sortRows($players,'total_damage','player_name',0,PHP_INT_MAX),

    'total_point'=>
        sortRows($players,'total_point','player_name',0,PHP_INT_MAX),

    'avg_damage'=>
        sortRows($players,'avg_damage','player_name',10,PHP_INT_MAX),

    'avg_point'=>
        sortRows($players,'avg_point','player_name',10,PHP_INT_MAX),

    'max_point'=>
        sortRows($players,'max_point','player_name',0,PHP_INT_MAX),

    'max_damage'=>
        sortRows($players,'max_damage','player_name',0,PHP_INT_MAX),

    'first_rate'=>
        sortRows($players,'first_rate','player_name',10,PHP_INT_MAX),

    'solo_rate'=>
        $soloPlayersAll
        ??$soloPlayers,
];


$rankLookupPlayerStats=[];

if($rankLookupPlayer!==''){
    foreach(
        $rankLookupPlayerBoardsAll as
        $metric=>$lookupRows
    ){
        $rankLookupPlayerStats[$metric]=
            raidStatsFindPlayerRank(
                $lookupRows,
                $rankLookupPlayer
            );
    }
}


$rankLookupDpmLabels=[
    'avg_dpm'=>'平均 DPM',
    'median_dpm'=>'中位 DPM',
    'max_dpm'=>'單場最高 DPM',
    'boss_屠殺者'=>'屠殺者',
    'boss_龍鯰'=>'龍鯰',
    'boss_誘引之者'=>'誘引之者',
    'boss_靈龜'=>'靈龜',
    'boss_黑死獸'=>'黑死獸',
];


$rankLookupDpmBoardsAll=[
    'avg_dpm'=>$avgRows??[],
    'median_dpm'=>$medianRows??[],
    'max_dpm'=>$maxRows??[],
];

foreach(
    [
        '屠殺者',
        '龍鯰',
        '誘引之者',
        '靈龜',
        '黑死獸',
    ] as $lookupBossName
){
    $rankLookupDpmBoardsAll[
        'boss_'.$lookupBossName
    ]=
        $dpmBossAllRows[$lookupBossName]
        ??[];
}


$rankLookupDpmStats=[];

if($rankLookupPlayer!==''){
    foreach(
        $rankLookupDpmBoardsAll as
        $metric=>$lookupRows
    ){
        $rankLookupDpmStats[$metric]=
            raidStatsFindPlayerRank(
                $lookupRows,
                $rankLookupPlayer
            );
    }
}


$rankLookupRangeSuffix=
    $rankLookupPlayer!==''
    ? '&rank_player='.
      rawurlencode($rankLookupPlayer)
    : '';


ob_start();
?>
'''

        s = replace_once(
            s,
            "ob_start();\n?>\n",
            backend,
            'lookup backend',
        )

        # Preserve lookup target when switching 7/30/all range.
        s = replace_once(
            s,
            'href="<?=h(\'?range=\'.$k.$careerRangeSuffix)?>"',
            'href="<?=h(\'?range=\'.$k.$careerRangeSuffix.$rankLookupRangeSuffix)?>"',
            'range link',
        )

        # -----------------------------------------------------
        # Styles.
        # -----------------------------------------------------
        css = r'''/* RAID_STATS_SELF_LOOKUP_CSS_V1 */
.rs-selflookup{
  display:flex;
  flex-wrap:wrap;
  align-items:center;
  gap:7px;
  margin:11px 0 10px;
}
.rs-selflookup-form{
  display:flex;
  flex:1 1 320px;
  gap:7px;
  min-width:0;
}
.rs-selflookup-input{
  flex:1 1 220px;
  min-width:0;
  padding:8px 10px;
  border:1px solid rgba(255,255,255,.12);
  border-radius:8px;
  background:rgba(255,255,255,.05);
  color:#fff;
  outline:none;
}
.rs-selflookup-button,
.rs-selflookup-me{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  padding:8px 11px;
  border:1px solid rgba(184,190,255,.28);
  border-radius:8px;
  background:rgba(184,190,255,.09);
  color:#dfe3ff;
  text-decoration:none;
  font-size:12px;
  font-weight:800;
  white-space:nowrap;
}
.rs-selflookup-button{cursor:pointer}
.rs-selflookup-summary{
  margin:0 0 11px;
  padding:10px;
  border:1px solid rgba(118,215,170,.20);
  border-radius:10px;
  background:rgba(118,215,170,.045);
}
.rs-selflookup-title{
  margin-bottom:8px;
  font-size:12px;
  color:var(--muted);
}
.rs-selflookup-title strong{
  color:#fff;
  font-size:14px;
}
.rs-selflookup-grid{
  display:grid;
  grid-template-columns:repeat(4,minmax(0,1fr));
  gap:7px;
}
.rs-selflookup-item{
  min-width:0;
  padding:8px;
  border:1px solid rgba(255,255,255,.07);
  border-radius:8px;
  background:rgba(255,255,255,.025);
}
.rs-selflookup-item span,
.rs-selflookup-item small{
  display:block;
  color:var(--muted);
  font-size:10px;
}
.rs-selflookup-item strong{
  display:block;
  margin:2px 0;
  font-size:16px;
  color:#fff;
}
@media(max-width:991px){
  .rs-selflookup-grid{
    grid-template-columns:repeat(2,minmax(0,1fr));
  }
}
@media(max-width:767px){
  .rs-selflookup-form{flex:1 1 100%}
  .rs-selflookup-grid{grid-template-columns:minmax(0,1fr)}
}

</style>
<div class="content-wrapper">'''

        s = replace_once(
            s,
            '</style>\n<div class="content-wrapper">',
            css,
            'lookup CSS',
        )

        # -----------------------------------------------------
        # Player ranking lookup UI.
        # -----------------------------------------------------
        player_tabs = '<div class="rs-tabs" data-tabs>'
        idx = s.find(player_tabs)
        if idx < 0:
            raise RuntimeError('player ranking tabs not found')

        player_ui = r'''<!-- RAID_STATS_SELF_LOOKUP_PLAYER_UI_V1 -->
<div class="rs-selflookup">
<form method="get" action="" class="rs-selflookup-form">
<input type="hidden" name="range" value="<?=h($range)?>">
<?php if($careerRequested!==''):?>
<input type="hidden" name="career_player" value="<?=h($careerRequested)?>">
<?php endif;?>
<input
 type="search"
 name="rank_player"
 value="<?=h($rankLookupPlayer)?>"
 list="raid-rank-player-list"
 placeholder="🔎 輸入玩家名稱"
 autocomplete="off"
 spellcheck="false"
 class="rs-selflookup-input"
 aria-label="查詢玩家排行榜紀錄"
>
<button type="submit" class="rs-selflookup-button">查詢</button>
</form>

<?php if($me!==''):?>
<a
 class="rs-selflookup-me"
 href="<?=h('?range='.$range.$careerRangeSuffix.'&rank_player='.rawurlencode($me))?>"
>我的紀錄</a>
<?php endif;?>
</div>

<datalist id="raid-rank-player-list">
<?php foreach($rankLookupOptions as $lookupOption):?>
<option value="<?=h($lookupOption)?>"></option>
<?php endforeach;?>
</datalist>

<?php if($rankLookupPlayer!==''):?>
<div class="rs-selflookup-summary">
<div class="rs-selflookup-title">
玩家排行榜自查：<strong><?=h($rankLookupPlayer)?></strong>｜<?=h($rangeLabel)?>
</div>
<div class="rs-selflookup-grid">
<?php foreach($rankLookupPlayerLabels as $lookupMetric=>$lookupLabel):?>
<?php $lookupHit=$rankLookupPlayerStats[$lookupMetric]??null;?>
<div class="rs-selflookup-item">
<span><?=h($lookupLabel)?></span>
<strong><?=$lookupHit ? '#'.n($lookupHit['rank']) : '—'?></strong>
<small><?=
    $lookupHit
    ? h(raidStatsLookupPlayerValue(
        $lookupMetric,
        $lookupHit['row']
      ))
    : '未達門檻 / 無紀錄'
?></small>
</div>
<?php endforeach;?>
</div>
</div>
<?php endif;?>

'''
        s = s[:idx] + player_ui + s[idx:]

        # -----------------------------------------------------
        # DPM lookup UI.
        # -----------------------------------------------------
        dpm_title_pos = s.find('<h2>⚔️ 輸出效率排行榜</h2>')
        if dpm_title_pos < 0:
            raise RuntimeError('DPM section not found')

        dpm_anchor = '<?php if(!$dpmAvailable):?>'
        idx = s.find(dpm_anchor, dpm_title_pos)
        if idx < 0:
            raise RuntimeError('DPM availability anchor not found')

        dpm_ui = r'''<!-- RAID_STATS_SELF_LOOKUP_DPM_UI_V1 -->
<div class="rs-selflookup">
<form method="get" action="" class="rs-selflookup-form">
<input type="hidden" name="range" value="<?=h($range)?>">
<?php if($careerRequested!==''):?>
<input type="hidden" name="career_player" value="<?=h($careerRequested)?>">
<?php endif;?>
<input
 type="search"
 name="rank_player"
 value="<?=h($rankLookupPlayer)?>"
 list="raid-rank-player-list"
 placeholder="🔎 輸入玩家名稱"
 autocomplete="off"
 spellcheck="false"
 class="rs-selflookup-input"
 aria-label="查詢玩家 DPM 紀錄"
>
<button type="submit" class="rs-selflookup-button">查詢</button>
</form>

<?php if($me!==''):?>
<a
 class="rs-selflookup-me"
 href="<?=h('?range='.$range.$careerRangeSuffix.'&rank_player='.rawurlencode($me).'#raid-dpm-leaderboard')?>"
>我的紀錄</a>
<?php endif;?>
</div>

<?php if($rankLookupPlayer!==''):?>
<div class="rs-selflookup-summary">
<div class="rs-selflookup-title">
輸出效率自查：<strong><?=h($rankLookupPlayer)?></strong>｜<?=h($rangeLabel)?>
</div>
<div class="rs-selflookup-grid">
<?php foreach($rankLookupDpmLabels as $lookupMetric=>$lookupLabel):?>
<?php $lookupHit=$rankLookupDpmStats[$lookupMetric]??null;?>
<div class="rs-selflookup-item">
<span><?=h($lookupLabel)?></span>
<strong><?=$lookupHit ? '#'.n($lookupHit['rank']) : '—'?></strong>
<small><?=
    $lookupHit
    ? h(raidStatsLookupDpmValue(
        $lookupMetric,
        $lookupHit['row']
      ))
    : '未達門檻 / 無紀錄'
?></small>
</div>
<?php endforeach;?>
</div>
</div>
<?php endif;?>

'''
        s = s[:idx] + dpm_ui + s[idx:]

        TARGET.write_text(s, encoding='utf-8')

        lint = subprocess.run(
            ['php', '-l', str(TARGET)],
            text=True,
            capture_output=True,
        )

        print(lint.stdout.strip())
        if lint.returncode != 0:
            if lint.stderr.strip():
                print(lint.stderr.strip())
            shutil.copy2(backup, TARGET)
            print('[ROLLBACK] PHP lint failed; restored backup')
            return 3

        print('[OK] player + DPM self lookup installed')
        print('[OK] logged-in users get 我的紀錄')
        print('[OK] manual player input works for players outside Top20')
        print('[OK] lookup keeps current 7/30/all range')
        return 0

    except Exception as exc:
        shutil.copy2(backup, TARGET)
        print(f'[ROLLBACK] {exc}')
        return 4


if __name__ == '__main__':
    raise SystemExit(main())
