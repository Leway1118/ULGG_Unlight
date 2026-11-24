<?php
declare(strict_types=1);
/** D5-I: pure COST source policy. No DB, filesystem or network side effects. */
const ULGG_COST_PARSER_VERSION='steam-cost-v2-review-gate';

function weeklyCostParseProfiles(array $news): array {
    $body=html_entity_decode(strip_tags((string)($news['contents']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
    $body=str_replace("\xc2\xa0",' ',$body);
    $out=[];
    foreach (['ALEXANDRE'=>'Alexandre','FORNHEIL'=>'Fornheil'] as $zone=>$label) {
        $rx='/《'.preg_quote($label,'/').'》\s*Category\s*1:\s*COST\s*(\d+)\s*Category\s*2:\s*COST\s*(\d+)\s*Category\s*3:\s*COST\s*(\d+)\s*Category\s*4:\s*COST\s*(\d+)\+/iu';
        if(preg_match($rx,$body,$m)!==1)continue;
        $v=array_map('intval',array_slice($m,1,4));
        if(!($v[0]>0 && $v[0]<$v[1] && $v[1]<$v[2] && $v[2]<90 && $v[3]===90))continue;
        $out[$zone]=array_combine(['cost1','cost2','cost3','cost4'],$v);
    }
    return $out;
}
function weeklyCostSourceGidFromUrl(string $url): ?string {
    if(!filter_var($url,FILTER_VALIDATE_URL))return null;
    $u=parse_url($url);
    if(($u['scheme']??'')!=='https')return null;
    if(!in_array(strtolower((string)($u['host']??'')),
      ['steamstore-a.akamaihd.net','steamcommunity.com','store.steampowered.com'],true))return null;
    $path=(string)($u['path']??'');
    return preg_match('~(?:^|/)(\d{12,20})/?$~D',$path,$m)===1?$m[1]:null;
}
function weeklyCostClassifyNews(array $news): array {
    $out=['status'=>'SKIP_NO_COST','reasons'=>[],'source_gid'=>null,'candidate_date'=>null,
      'published_local'=>null,'profiles'=>weeklyCostParseProfiles($news),
      'source_title'=>(string)($news['title']??''),
      'source_url'=>(string)($news['url']??'')];
    if($out['profiles']===[]){
        $raw=(string)($news['contents']??'');
        if(preg_match('/COST|ＣＯＳＴ|コスト/u',$raw)===1){
            $out['status']='REVIEW_PARSE_INCOMPLETE';
            $out['reasons'][]='COST_KEYWORD_BUT_NO_VALID_FOUR_BUCKETS';
        }
        return $out;
    }
    $gid=(string)($news['gid']??'');
    if(preg_match('/^\d{12,20}$/D',$gid)!==1){
        $out['status']='REVIEW_SOURCE_CONFLICT';$out['reasons'][]='GID_INVALID';return $out;
    }
    $out['source_gid']=$gid;
    if(weeklyCostSourceGidFromUrl($out['source_url'])!==$gid){
        $out['status']='REVIEW_SOURCE_CONFLICT';$out['reasons'][]='URL_GID_MISMATCH';return $out;
    }
    $ts=filter_var($news['date']??null,FILTER_VALIDATE_INT);
    if(!is_int($ts)||$ts<=0){
        $out['status']='REVIEW_DATE_CONFLICT';$out['reasons'][]='PUBLICATION_TIMESTAMP_INVALID';return $out;
    }
    $published=(new DateTimeImmutable('@'.$ts))->setTimezone(new DateTimeZone('Asia/Taipei'));
    $out['published_local']=$published->format('Y-m-d H:i:s');
    $out['published_day']=$published->format('Y-m-d');
    if(preg_match('/\((\d{4})\/(\d{2})\/(\d{2})\)/',$out['source_title'],$m)!==1
      || !checkdate((int)($m[2]??0),(int)($m[3]??0),(int)($m[1]??0))){
        $out['status']='REVIEW_DATE_CONFLICT';$out['reasons'][]='TITLE_DATE_UNAVAILABLE';return $out;
    }
    $date=sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]);
    $out['candidate_date']=$date;
    if($date!==$out['published_day']){
        $out['status']='REVIEW_DATE_CONFLICT';$out['reasons'][]='TITLE_PUBLICATION_DAY_MISMATCH';return $out;
    }
    $out['status']='READY_DETECTED';
    $out['reasons'][]='EFFECTIVE_INSTANT_PENDING_HUMAN_CONFIRMATION';
    return $out;
}
/** Inspect both unique keys before inserting; never mutate any existing row. */
function weeklyCostDecide(array $candidate,string $zone,?array $byDate,?array $byGid): array {
    if(($candidate['status']??'')!=='READY_DETECTED')
        return ['action'=>'REVIEW','reason'=>$candidate['status']??'INVALID'];
    if(!isset($candidate['profiles'][$zone]))
        return ['action'=>'SKIP','reason'=>'ZONE_NOT_IN_ARTICLE'];
    if($byDate===null&&$byGid===null)
        return ['action'=>'INSERT_DETECTED','reason'=>'NEW_SOURCE_AND_DATE'];
    if($byDate===null||$byGid===null||(string)$byDate['id']!==(string)$byGid['id'])
        return ['action'=>'REVIEW_CONFLICT','reason'=>'DATE_OR_GID_UNIQUE_KEY_CONFLICT'];
    $cost=$candidate['profiles'][$zone];
    $same=$byDate['ladder_zone_code']===$zone &&
        $byDate['effective_date']===$candidate['candidate_date'] &&
        $byDate['source_gid']===$candidate['source_gid'] &&
        $byDate['source_url']===$candidate['source_url'] &&
        $byDate['source_title']===$candidate['source_title'] &&
        $byDate['source_published_at']===$candidate['published_local'] &&
        $byDate['cost4_operator']==='MIN';
    foreach($cost as $key=>$v)$same=$same && (int)$byDate[$key]===$v;
    if(!$same)return ['action'=>'REVIEW_CONFLICT','reason'=>'IMMUTABLE_SOURCE_OR_COST_MISMATCH'];
    return ['action'=>'UNCHANGED','reason'=>'EXACT_SOURCE_AND_VALUES_ALREADY_STORED'];
}
