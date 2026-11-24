<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Pure annual PvP aggregation over pre-authorized, normalized per-player rows.
 * No SQL, IO, environment, session, or network. The caller must resolve a
 * verified subject with IdentityGate before ever reading private facts.
 */
final class PvPAnnualAggregator
{
    private static function parseTime(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            return null;
        }
        $tz = new DateTimeZone('Asia/Taipei');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $tz);
        return $date && $date->format('Y-m-d H:i:s') === $value ? $date : null;
    }

    private static function rank(array $items, string $key): array
    {
        $result = [];
        foreach ($items as $id=>$number) {
            $result[] = [$key => $key === 'char_id' ? (int)$id : (string)$id,
                         'usage' => (int)$number];
        }
        usort($result, static fn($a,$b):int =>
            ($b['usage'] <=> $a['usage']) ?: strcmp((string)$a[$key], (string)$b[$key]));
        return $result;
    }

    public static function aggregate(array $rows, array $options): array
    {
        $year = $options['year'] ?? null;
        $region = $options['region'] ?? null;
        $player = $options['player_name'] ?? null;
        $asOf = self::parseTime($options['as_of'] ?? null);
        if ($year !== 2026 || !in_array($region,['TW','JP'],true)
            || !is_string($player) || $player === '' || $asOf === null) {
            throw new InvalidArgumentException('Invalid verified subject or snapshot parameters');
        }
        $tz = new DateTimeZone('Asia/Taipei');
        $start = new DateTimeImmutable('2026-01-01 00:00:00',$tz);
        $end = new DateTimeImmutable('2027-01-01 00:00:00',$tz);
        if ($asOf < $start || $asOf >= $end) {
            throw new InvalidArgumentException('as_of outside 2026 preview window');
        }
        $months = [];
        for($m=1;$m<=12;$m++){
            $key = sprintf('2026-%02d',$m);
            $monthStart = new DateTimeImmutable($key.'-01 00:00:00',$tz);
            $future = $monthStart > $asOf;
            $months[$key] = [
                'status' => $future ? 'NOT_APPLICABLE' : 'VERIFIED',
                'matches' => $future ? null : 0,
                'wins' => $future ? null : 0,
                'losses' => $future ? null : 0,
                'draws' => $future ? null : 0,
                'undecided' => $future ? null : 0,
            ];
            if (!$future && $monthStart->format('Y-m') === $asOf->format('Y-m')) {
                $months[$key]['status']='PARTIAL';
            }
        }

        $sourced = $options['source_verified'] ?? false;
        $base = [
            'status' => 'UNKNOWN', 'year' => $year, 'region' => $region,
            'as_of' => $options['as_of'], 'definition_version' => 'checkpoint2026/1.0.0-draft',
            'observed_count' => count($rows),
            'verified_matches' => null,'wins' => null,'losses' => null,'draws' => null,
            'undecided' => null,'win_rate_pct'=>null,'active_months'=>null,
            'top_characters'=>[], 'top_teams'=>[], 'monthly_trend'=>$months,
            'excluded_count'=>0,'excluded_reasons'=>[],
            'team_data_excluded'=>0,'duplicate_rows_ignored'=>0,
            'average_cost'=>null,'cost_sample_size'=>0,
        ];
        if ($sourced !== true) {
            foreach($base['monthly_trend'] as &$v){
                if($v['status']!=='NOT_APPLICABLE'){
                    $v=['status'=>'UNKNOWN','matches'=>null,'wins'=>null,'losses'=>null,'draws'=>null,'undecided'=>null];
                }
            }
            unset($v);
            return $base;
        }

        $groups=[];
        foreach($rows as $row){
            if (!is_array($row) || !isset($row['match_id']) || !is_numeric($row['match_id']) || (int)$row['match_id']<=0) {
                $groups['invalid:'.count($groups)][]=$row;
            }else{
                $groups['match:'.(int)$row['match_id']][]=$row;
            }
        }
        $win=0;$loss=0;$draw=0;$undecided=0;
        $excluded=[];$chars=[];$teams=[];$active=[];$costs=[];
        $duplicateIgnored=0;$teamInvalid=0;
        foreach($groups as $group){
            if(count($group)>1){
                $keys=array_map('serialize',$group);
                if(count(array_unique($keys))>1){
                    $excluded['DUPLICATE_CONFLICT']=($excluded['DUPLICATE_CONFLICT']??0)+1;
                    continue;
                }
                $duplicateIgnored+=count($group)-1;
            }
            $r=$group[0];
            $reason=null;
            if (!is_array($r) || !isset($r['match_id']) || (int)$r['match_id']<=0) {
                $reason='INVALID_MATCH_ID';
            }
            $time=self::parseTime($r['update_time']??null);
            $truthTime=self::parseTime($r['truth_update_time']??null);
            if($reason===null && (!$time||!$truthTime||$time->format('Y-m-d H:i:s')!==$truthTime->format('Y-m-d H:i:s'))) {
                $reason='INVALID_TIME_OR_TRUTH';
            }
            if($reason===null && ($time<$start || $time>=$end || $time>$asOf)){
                $reason='OUTSIDE_OBSERVATION_WINDOW';
            }
            if($reason===null && ($r['region']??null)!==$region){
                $reason=($r['region']??null)==='MATCH'?'EXCLUDED_MATCH_REGION':'UNKNOWN_OR_OTHER_REGION';
            }
            if($reason===null && ((int)($r['ack1']??0)!==1 || (int)($r['ack2']??0)!==1)){
                $reason='UNCONFIRMED_ACK';
            }
            $side=$r['side']??null;
            if($reason===null && (!in_array($side,['P1','P2'],true)
                || ($r['player_name']??null)!==$player
                || ($side==='P1'?($r['name_p1']??null):($r['name_p2']??null))!==$player)){
                $reason='PLAYER_IDENTITY_MISMATCH';
            }
            $fWin=$r['win']??null;$fLose=$r['lose']??null;$fTie=$r['tie']??null;
            if($reason===null && (!in_array((string)$fWin,['0','1'],true)
                || !in_array((string)$fLose,['0','1'],true)
                || !in_array((string)$fTie,['0','1'],true)
                || (int)$fWin+(int)$fLose+(int)$fTie>1)){
                $reason='AMBIGUOUS_RESULT_FLAGS';
            }
            $state=null;
            if($reason===null){
                if((int)$fTie===1){$state='draw';$expected=null;}
                elseif((int)$fWin===1){$state=$side==='P1'?'loss':'win';$expected=$side==='P1'?0:1;}
                elseif((int)$fLose===1){$state=$side==='P1'?'win':'loss';$expected=$side==='P1'?1:0;}
                else{$state='undecided';$expected=null;}
                $actual=$r['is_win']??null;
                if ($actual!==null && in_array((string)$actual,['0','1'],true)) {
                    $actual=(int)$actual;
                }
                if($actual!==$expected){$reason='RESULT_MISMATCH';}
            }
            if($reason!==null){$excluded[$reason]=($excluded[$reason]??0)+1;continue;}
            $month=$time->format('Y-m');$active[$month]=true;
            if($state==='win'){$win++;$months[$month]['wins']++;$months[$month]['matches']++;}
            elseif($state==='loss'){$loss++;$months[$month]['losses']++;$months[$month]['matches']++;}
            elseif($state==='draw'){$draw++;$months[$month]['draws']++;$months[$month]['matches']++;}
            else{$undecided++;$months[$month]['undecided']++;}
            $ids = [(int)($r['leader_id']??0),(int)($r['back1_id']??0),(int)($r['back2_id']??0)];
            foreach(array_unique(array_filter($ids,static fn($x)=>$x>0)) as $char){
                $chars[$char]=($chars[$char]??0)+1;
            }
            $backs=[$ids[1],$ids[2]];
            sort($backs,SORT_NUMERIC);
            $expectedKey=$ids[0].'-'.$backs[0].'-'.$backs[1];
            if(min($ids)>0 && ($r['team_key']??null)===$expectedKey){
                $teams[$expectedKey]=($teams[$expectedKey]??0)+1;
            }else{$teamInvalid++;}
            if(isset($r['cost']) && is_numeric($r['cost']) && (float)$r['cost']>=0){
                $costs[]=(float)$r['cost'];
            }
        }
        ksort($excluded);
        $verified=$win+$loss+$draw;
        $base['status']= count($excluded)>0 || $undecided>0 || $teamInvalid>0 ? 'PARTIAL' : 'VERIFIED';
        $base['verified_matches']=$verified;
        $base['wins']=$win;$base['losses']=$loss;$base['draws']=$draw;
        $base['undecided']=$undecided;
        $base['win_rate_pct']=$verified>0?round($win/$verified*100,1):null;
        $base['active_months']=count($active);
        $base['top_characters']=self::rank($chars,'char_id');
        $base['top_teams']=array_map(static fn($r)=>['team_key'=>$r['team_key'],'matches'=>$r['usage']],self::rank($teams,'team_key'));
        $base['monthly_trend']=$months;
        $base['excluded_count']=array_sum($excluded);
        $base['excluded_reasons']=$excluded;
        $base['duplicate_rows_ignored']=$duplicateIgnored;
        $base['team_data_excluded']=$teamInvalid;
        $base['average_cost']=$costs!==[]?round(array_sum($costs)/count($costs),1):null;
        $base['cost_sample_size']=count($costs);
        return $base;
    }
}
