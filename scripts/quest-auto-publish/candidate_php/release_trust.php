<?php
declare(strict_types=1);
// The expected manifest digest must come from a separately authorized registry,
// never from the uploaded release itself. No production registry is configured here.
function qa_verified_release(string $root,string $trustedDigest,array $required): array {
    if(!preg_match('/^[a-f0-9]{64}$/D',$trustedDigest)) throw new RuntimeException('trust');
    $p=$root.'/manifest.json';
    $raw=file_get_contents($p);
    if($raw===false || !hash_equals($trustedDigest,hash('sha256',$raw))) throw new RuntimeException('manifest trust');
    $m=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
    if(($m['contract_version']??null)!=='ulgg.quest_public.v1.4') throw new RuntimeException('contract');
    $seen=[];
    foreach($m['files'] as $f){
        $path=$f['path']??'';
        if(!in_array($path,$required,true)||isset($seen[$path])) throw new RuntimeException('inventory');
        $full=$root.'/'.$path;
        if(!is_file($full)||is_link($full)||!hash_equals($f['sha256'],hash_file('sha256',$full))||filesize($full)!==$f['bytes']) throw new RuntimeException('file integrity');
        $doc=json_decode(file_get_contents($full),true,512,JSON_THROW_ON_ERROR);
        if(($doc['as_of_utc']??null)!==$m['as_of_utc']) throw new RuntimeException('anchor');
        $seen[$path]=true;
    }
    if(count($seen)!==count($required)) throw new RuntimeException('missing file');
    return $m;
}
function qa_model_index(array $doc,array $catalog): array {
    if(($doc['schema']??null)!=='ULGG_G2_FULL_CATALOG_SIMULATION_V3'||($doc['policy']??null)!=='model_simulation_only_missing_no_observed_override') throw new RuntimeException('model version');
    $keys=array_fill_keys($catalog,true); $out=[];
    foreach($doc['estimates'] as $e){
        $k=$e['quest_key']??'';
        if(!isset($keys[$k])||isset($out[$k])) throw new RuntimeException('model identity');
        foreach(['estimated_qp','estimated_qph','estimated_valh','estimated_sec','known_value'] as $f)
            if(!isset($e[$f])||!is_numeric($e[$f])||!is_finite((float)$e[$f])||$e[$f]<0) throw new RuntimeException('model metric');
        if($e['estimated_sec']<=0||abs($e['estimated_qph']-$e['estimated_qp']*3600/$e['estimated_sec'])>0.1||abs($e['estimated_valh']-$e['known_value']*3600/$e['estimated_sec'])>0.1) throw new RuntimeException('model arithmetic');
        $out[$k]=$e;
    }
    return $out;
}
