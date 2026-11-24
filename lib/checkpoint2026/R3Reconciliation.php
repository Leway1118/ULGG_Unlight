<?php
declare(strict_types=1);
namespace Ulgg\Checkpoint2026;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use PDO;

/** Synthetic-only R3 trust contract. No IO, secrets, game, or production adapter. */
final class R3Reconciliation
{
    public static function time(mixed $x): bool {
        if(!is_string($x))return false;
        $d=DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$x,new DateTimeZone('Asia/Taipei'));
        return $d!==false && $d->format('Y-m-d H:i:s')===$x;
    }

    public static function digest(array $x): string {
        return hash('sha256',SourceProvenance::canonical($x));
    }

    /** Strict fixture-reader wire adapter: old PDO can return SQLite INTs as
     * strings. Normalize only canonical, lossless integer representations
     * before the observation issuer signs; never repair bad source facts. */
    public static function normalizePvpFact(array $fact): array {
        foreach(['match_id','ack1','ack2','win','lose','tie','leader_id','back1_id','back2_id','cost','is_win'] as $field){
            if(!array_key_exists($field,$fact))throw new InvalidArgumentException('R3_MISSING_INTEGER_FIELD:'.$field);
            $value=$fact[$field];
            if($field==='is_win' && $value===null)continue;
            if(is_int($value))continue;
            if(!is_string($value) || !preg_match('/^-?(0|[1-9][0-9]*)$/D',$value) ||
               filter_var($value,FILTER_VALIDATE_INT)===false || (string)(int)$value!==$value)
                throw new InvalidArgumentException('R3_NONCANONICAL_INTEGER:'.$field);
            $fact[$field]=(int)$value;
        }
        return $fact;
    }

    /** Fixture issuers sign independently. The verifier never signs missing evidence. */
    public static function sign(string $role,array $claims,string $key): array {
        if(strlen($key)<32 || !in_array($role,['upstream','receipt','history'],true)){
            throw new InvalidArgumentException('R3_INVALID_KEY_OR_ROLE');
        }
        return ['claims'=>$claims,'signature'=>hash_hmac('sha256',
            "checkpoint2026/r3/".$role."\n".SourceProvenance::canonical($claims),$key)];
    }

    private static function valid(string $role,array $proof,string $key): bool {
        return is_array($proof['claims']??null) && is_string($proof['signature']??null)
            && hash_equals(self::sign($role,$proof['claims'],$key)['signature'],$proof['signature']);
    }

    /** The independent upstream fixture supplies expected exact event IDs/content,
     * not a count derived from the observed reader. Ingestion supplies receipts. */
    public static function reconcile(string $dataset,string $scope,string $asOf,string $cutoff,
        int $revision,array $upstream,array $receipt,array $observed,
        string $upstreamKey,string $receiptKey):array {
        $unknown=static fn(string $reason):array=>[
            'status'=>'UNKNOWN','source_verified'=>false,'reason'=>$reason,'evidence_sha256'=>null
        ];
        if($upstreamKey===$receiptKey || strlen($upstreamKey)<32 || strlen($receiptKey)<32)
            return $unknown('ISSUERS_NOT_INDEPENDENT');
        if(!in_array($dataset,['PVP_MATCH_FACT','CUP_PUBLIC'],true) || $scope==='' ||
           !self::time($asOf) || !self::time($cutoff) || $asOf<'2026-01-01 00:00:00' ||
           $asOf>='2027-01-01 00:00:00' || $cutoff<$asOf || $revision<1)
            return $unknown('INVALID_CONTEXT');
        if(!self::valid('upstream',$upstream,$upstreamKey) || !self::valid('receipt',$receipt,$receiptKey))
            return $unknown('BAD_SIGNATURE');
        $u=$upstream['claims'];$r=$receipt['claims'];
        $context=['mode'=>'SYNTHETIC_STAGING','dataset'=>$dataset,'scope'=>$scope,
            'period_start'=>'2026-01-01 00:00:00','as_of'=>$asOf,'cutoff'=>$cutoff,
            'revision'=>$revision,'timezone'=>'Asia/Taipei','schema_fingerprint'=>SourceProvenance::SCHEMA_FINGERPRINT];
        foreach($context as $k=>$v){
            if(($u[$k]??null)!==$v || ($r[$k]??null)!==$v)return $unknown('CONTEXT_MISMATCH');
        }
        if(($u['issuer']??null)!=='SYNTH_UPSTREAM_'.$dataset ||
           ($r['issuer']??null)!=='SYNTH_INGESTION_'.$dataset)
            return $unknown('ISSUER_MISMATCH');
        if(!self::time($u['watermark']??null) || !self::time($r['watermark']??null) ||
           $u['watermark']<$asOf || $r['watermark']<$asOf ||
           $u['watermark']>$cutoff || $r['watermark']>$cutoff)
            return $unknown('STALE_OR_INVALID_WATERMARK');
        if(!is_array($u['expected']??null) || !is_int($u['expected_count']??null) ||
           $u['expected_count']!==count($u['expected']) ||
           !is_string($r['observed_sha256']??null) ||
           !hash_equals(self::digest($observed),$r['observed_sha256']) ||
           ($r['observed_count']??null)!==count($observed))
            return $unknown('RECEIPT_OR_COUNT_MISMATCH');

        $expected=[];$seen=[];
        foreach($u['expected'] as $row){
            $id=$row['id']??null;
            if(!is_string($id)||!preg_match('/^[A-Z][A-Z0-9:_-]{0,95}$/D',$id) ||
               isset($expected[$id]) || !is_string($row['sha256']??null) ||
               !preg_match('/^[a-f0-9]{64}$/D',$row['sha256']) ||
               !self::time($row['event_at']??null) ||
               $row['event_at']<$context['period_start'] || $row['event_at']>$asOf)
                return $unknown('INVALID_OR_DUPLICATE_EXPECTED_EVENT');
            $expected[$id]=$row;
        }
        $late=0;
        foreach($observed as $row){
            $id=$row['id']??null;
            if(!is_string($id) || isset($seen[$id]))return $unknown('DUPLICATE_OBSERVED_EVENT');
            if(!isset($expected[$id]))return $unknown('UNEXPECTED_EVENT');
            if(!is_array($row['payload']??null) || !self::time($row['event_at']??null) ||
               !self::time($row['ingested_at']??null) ||
               $row['ingested_at']<$row['event_at'] ||
               $row['event_at']!==$expected[$id]['event_at'] ||
               !hash_equals($expected[$id]['sha256'],self::digest($row['payload'])))
                return $unknown('EVENT_CONTENT_OR_TIME_MISMATCH');
            if($row['ingested_at']>$cutoff)return $unknown('NOT_INGESTED_BY_CUTOFF');
            if($row['ingested_at']>$asOf)$late++;
            $seen[$id]=true;
        }
        if(count($seen)!==count($expected))return $unknown('MISSING_EVENT');
        return ['status'=>'VERIFIED','source_verified'=>true,'reason'=>null,
            'dataset'=>$dataset,'count'=>count($seen),'late_arrivals'=>$late,
            'watermark'=>min($u['watermark'],$r['watermark']),
            'evidence_sha256'=>self::digest(['upstream'=>$upstream,'receipt'=>$receipt])];
    }

    /** Immutable event actor identifier plus independently signed effective-time
     * history. Never infer ownership from a current Steam/display name. */
    public static function historicalOwner(array $event,array $history,string $historyKey):array {
        $unknown=['status'=>'UNKNOWN','scope'=>null];
        if(strlen($historyKey)<32 || !self::valid('history',$history,$historyKey))return $unknown;
        $claims=$history['claims'];
        if(($claims['mode']??null)!=='SYNTHETIC_STAGING' ||
           ($claims['issuer']??null)!=='SYNTH_IDENTITY_REGISTRY' ||
           !is_array($claims['bindings']??null) ||
           !in_array($event['dataset']??null,['PVP_MATCH_FACT','CUP_PUBLIC'],true) ||
           !is_string($event['actor_id']??null) || $event['actor_id']==='' ||
           !is_string($event['alias']??null) || $event['alias']==='' ||
           !in_array($event['region']??null,['TW','JP'],true) ||
           !self::time($event['event_at']??null))return $unknown;
        $candidates=[];
        foreach($claims['bindings'] as $b){
            if(!is_array($b) || !self::time($b['from']??null) || !self::time($b['until']??null) ||
               $b['from']>=$b['until'] ||
               !is_string($b['scope']??null) || !preg_match('/^[a-f0-9]{64}$/D',$b['scope']))
                return $unknown;
            if(($b['dataset']??null)===$event['dataset'] &&
               ($b['actor_id']??null)===$event['actor_id'] &&
               ($b['region']??null)===$event['region'] &&
               $event['event_at']>=$b['from'] && $event['event_at']<$b['until']){
                $candidates[]=$b;
            }
        }
        if(count($candidates)!==1 || ($candidates[0]['alias']??null)!==$event['alias'])return $unknown;
        return ['status'=>'VERIFIED','scope'=>$candidates[0]['scope']];
    }
}

/** R3 synthetic-only writer. Accept source events, not a client-supplied DTO
 * or a caller-supplied "verified" flag; calculate the payload after independent
 * reconciliation, and seal independent evidence roots in the R1 ledger. */
final class R3SnapshotWriter
{
    public static function append(ImmutableSnapshotLedger $ledger,string $scope,int $revision,
        string $asOf,string $cutoff,PDO $identityDb,array $trustedSession,string $scopeKey,
        array $pvp,array $cup,array $keys,array $history):array {
        $identity=IdentityReader::resolve($identityDb,$trustedSession);
        $resolved=IdentityScopeResolver::fromSession($identityDb,$trustedSession,$scopeKey);
        if(($identity['status']??null)!=='VERIFIED' || ($resolved['status']??null)!=='VERIFIED' ||
           !is_string($identity['subject']['username']??null) ||
           !in_array($identity['subject']['region']??null,['TW','JP'],true))
            throw new RuntimeException('R3_IDENTITY_NOT_VERIFIED');
        if(!hash_equals($scope,$resolved['opaque_scope']))throw new RuntimeException('R3_SCOPE_MISMATCH');
        $required=['pvp_upstream','pvp_receipt','cup_upstream','cup_receipt','history','ledger_source'];
        foreach($required as $k){
            if(!is_string($keys[$k]??null)||strlen($keys[$k])<32)throw new RuntimeException('R3_KEYS_REQUIRED');
        }
        if(count(array_unique(array_merge([$scopeKey],array_map(fn($k)=>$keys[$k],$required))))!==count($required)+1)
            throw new RuntimeException('R3_TRUST_DOMAINS_NOT_DISTINCT');
        $p=R3Reconciliation::reconcile('PVP_MATCH_FACT',$scope,$asOf,$cutoff,$revision,
            $pvp['upstream'],$pvp['receipt'],$pvp['events'],$keys['pvp_upstream'],$keys['pvp_receipt']);
        $c=R3Reconciliation::reconcile('CUP_PUBLIC',AnnualSourceBundleGate::CUP_SCOPE,$asOf,$cutoff,$revision,
            $cup['upstream'],$cup['receipt'],$cup['events'],$keys['cup_upstream'],$keys['cup_receipt']);
        if(!$p['source_verified'] || !$c['source_verified'])throw new RuntimeException('R3_INDEPENDENT_RECONCILIATION_REQUIRED');
        $facts=[];
        foreach($pvp['events'] as $e){
            $payload=$e['payload'];
            $fact=$payload['fact']??null;
            if(!is_array($fact) || !in_array($fact['side']??null,['P1','P2'],true) ||
               ($fact['update_time']??null)!==$e['event_at'] ||
               !is_int($fact['match_id']??null) || $fact['match_id']<=0 ||
               $e['id']!=='PVP:'.$fact['match_id'].':'.$fact['side'] ||
               ($fact['region']??null)!==$identity['subject']['region'])
                throw new RuntimeException('R3_FACT_CONTEXT_MISMATCH');
            $owner=R3Reconciliation::historicalOwner([
                'dataset'=>'PVP_MATCH_FACT','actor_id'=>$payload['actor_id']??null,
                'alias'=>$fact['player_name']??null,'region'=>$fact['region'],'event_at'=>$e['event_at']
            ],$history,$keys['history']);
            if($owner['status']!=='VERIFIED' || !hash_equals($scope,$owner['scope']))
                throw new RuntimeException('R3_HISTORICAL_OWNERSHIP_UNKNOWN');
            // After authority verification, normalize historical alias only for
            // aggregation. Never repair an inconsistent source-side name.
            $nameField=$fact['side']==='P1'?'name_p1':'name_p2';
            if(($fact[$nameField]??null)!==($fact['player_name']??null))
                throw new RuntimeException('R3_SOURCE_ALIAS_CONFLICT');
            $fact['player_name']=$identity['subject']['username'];
            $fact[$nameField]=$identity['subject']['username'];
            $facts[]=$fact;
        }
        $reports=[];$games=[];$summary=null;
        foreach($cup['events'] as $e){
            $data=$e['payload'];
            if(($data['kind']??null)==='report'){
                if(!is_array($data['report']??null) || !is_int($data['report']['report_id']??null) ||
                   $e['id']!=='CUP:REPORT:'.$data['report']['report_id'] ||
                   ($data['report']['published_at']??null)!==$e['event_at'])
                    throw new RuntimeException('R3_INVALID_CUP_REPORT');
                $reports[]=$data['report'];
            }elseif(($data['kind']??null)==='game'){
                $tid=$data['tournament_id']??null;
                if(!is_int($tid)||$tid<=0 || !is_int($data['game_id']??null) ||
                   $e['id']!=='CUP:GAME:'.$data['game_id'] ||
                   ($data['result_status']??null)!=='verified' ||
                   ($data['created_at']??null)!==$e['event_at'] ||
                   !R3Reconciliation::time($data['updated_at']??null) || $data['updated_at']>$asOf ||
                   !is_int($data['player1_id']??null) || !is_int($data['player2_id']??null) ||
                   $data['player1_id']===$data['player2_id'] ||
                   !in_array($data['winner_player_id']??null,[$data['player1_id'],$data['player2_id']],true))
                    throw new RuntimeException('R3_INVALID_CUP_GAME');
                $games[$tid]=($games[$tid]??0)+1;
            }else throw new RuntimeException('R3_UNKNOWN_CUP_EVENT');
        }
        ksort($games,SORT_NUMERIC);
        usort($reports,static fn($a,$b)=>strcmp($a['published_at'],$b['published_at'])?:($a['report_id']<=>$b['report_id']));
        $published=[];
        foreach($reports as $r)$published[$r['tournament_id']]=true;
        foreach($games as $id=>$n)if(!isset($published[$id]))throw new RuntimeException('R3_CUP_GAME_WITHOUT_PUBLIC_REPORT');
        $summary=['status'=>'VERIFIED','definition_version'=>'checkpoint2026/1.0.0-draft',
            'year'=>2026,'as_of'=>$asOf,'reports'=>$reports,'published_report_count'=>count($reports),
            'verified_games'=>array_sum($games),'verified_games_by_tournament'=>$games,
            'personal_cup_status'=>'UNKNOWN','personal_cup_reason'=>'NO_AUTHORITY_IDENTITY_MAPPING'];
        $agg=PvPAnnualAggregator::aggregate($facts,['year'=>2026,'region'=>$identity['subject']['region'],
            'player_name'=>$identity['subject']['username'],'as_of'=>$asOf,'source_verified'=>true]);
        $watermark=min($p['watermark'],$c['watermark']);
        $coverage=['mode'=>'SYNTHETIC_STAGING','status'=>'VERIFIED','source_verified'=>true,
            'schema_fingerprint'=>SourceProvenance::SCHEMA_FINGERPRINT,'source_watermark'=>$watermark];
        $snapshot=ResponseSnapshot::personal($identity,$agg,$summary,$coverage);
        $snapshot['snapshot_revision']='M3BR3-'.$revision;
        $snapshot['r3_reconciliation']=[
            'contract'=>'checkpoint2026/r3-reconciliation-v1','revision'=>$revision,'cutoff'=>$cutoff,
            'pvp_evidence_sha256'=>$p['evidence_sha256'],'cup_evidence_sha256'=>$c['evidence_sha256'],
            'history_sha256'=>R3Reconciliation::digest($history),
            'pvp_late_arrivals'=>$p['late_arrivals'],'cup_late_arrivals'=>$c['late_arrivals'],
            'production_authority'=>false
        ];
        // Compatibility layer: legacy proof is generated ONLY after independent
        // source validation. The sealed envelope also retains both R3 roots.
        $key=$keys['ledger_source'];
        $make=static function(string $dataset,string $s,array $rows)use($key,$asOf,$watermark,$revision):array{
            $claims=['contract'=>'checkpoint2026/provenance-v1','dataset'=>$dataset,'mode'=>'SYNTHETIC_STAGING',
                'issuer'=>'M3B_ISOLATED_TEST_ISSUER','period_start'=>'2026-01-01 00:00:00',
                'as_of'=>$asOf,'timezone'=>'Asia/Taipei','schema_fingerprint'=>SourceProvenance::SCHEMA_FINGERPRINT,
                'source_watermark'=>$watermark,'source_revision'=>'r3-'.$revision,'source_complete'=>true,
                'source_rows'=>count($rows),'expected_rows'=>count($rows),'missing_slices'=>0,
                'fact_sha256'=>SourceProvenance::hashFacts($rows),'scope_hash'=>SourceProvenance::scopeHash($s,$key)];
            return ['claims'=>$claims,'signature'=>SourceProvenance::signForSyntheticFixture($claims,$key)];
        };
        return $ledger->appendAnnual($scope,$revision,$snapshot,$make('PVP_MATCH_FACT',$scope,$facts),
            $make('CUP_PUBLIC',AnnualSourceBundleGate::CUP_SCOPE,[$summary]),$key,$asOf,$facts,[$summary]);
    }
}
