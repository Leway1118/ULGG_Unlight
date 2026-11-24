<?php
declare(strict_types=1);
/**
 * Dedicated, READ-ONLY public Steam news reader for COST.
 * No debug dumps, no localSummarize(), no production DB dependencies.
 */
function weeklyCostBuildNewsUrl(int $count=100): string {
    if($count<1||$count>100)throw new InvalidArgumentException('COUNT_OUT_OF_RANGE');
    return 'https://api.steampowered.com/ISteamNews/GetNewsForApp/v2/?appid=3247080'.
        '&count='.$count.'&maxlength=0';
}
function weeklyCostFetchNews(int $count=100,?callable $transport=null): array {
    $url=weeklyCostBuildNewsUrl($count);
    if($transport!==null){
        $raw=$transport($url);
    }else{
        $ctx=stream_context_create([
            'http'=>['timeout'=>15,'ignore_errors'=>false,
                'header'=>"Accept: application/json\r\n"],
            'ssl'=>['verify_peer'=>true,'verify_peer_name'=>true]
        ]);
        $raw=@file_get_contents($url,false,$ctx,0,6000001);
    }
    if(!is_string($raw)||$raw===''||strlen($raw)>6000000)
        throw new RuntimeException('STEAM_RESPONSE_EMPTY_OR_TOO_LARGE');
    $doc=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(!isset($doc['appnews']['newsitems'])||!is_array($doc['appnews']['newsitems']))
        throw new RuntimeException('STEAM_NEWS_SCHEMA_INVALID');
    $out=[];
    foreach($doc['appnews']['newsitems'] as $n){
        if(!is_array($n))continue;
        if(!in_array((string)($n['feedname']??''),[
            'steam_community','steam_community_announcements','steam_updates'],true))continue;
        $out[]=$n;
        if(count($out)>$count)throw new RuntimeException('STEAM_NEWS_ITEM_LIMIT');
    }
    return $out;
}
