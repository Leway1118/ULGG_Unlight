<?php
declare(strict_types=1);
// Offline candidate. Caller must supply independently authenticated release/model.
function qa_metric($actual, int $samples, int $minimum, $estimate, bool $modelValid): array {
    $numeric = static fn($v) => (is_int($v)||is_float($v)) && is_finite((float)$v) && $v>=0;
    if ($minimum<1 || $samples<0) throw new InvalidArgumentException('sample policy');
    if ($samples>0 && $numeric($actual))
        return ['value'=>$actual,'source'=>$samples >= $minimum ? 'observed':'low_sample','samples'=>$samples];
    if ($modelValid && $numeric($estimate))
        return ['value'=>$estimate,'source'=>'estimated','samples'=>0];
    return ['value'=>null,'source'=>'unavailable','samples'=>0];
}
function qa_coverage(array $catalog, array $rows, array $policy): array {
    $out=[];
    foreach($policy as $metric=>$rule){
        $counts=['observed'=>0,'low_sample'=>0,'estimated'=>0,'unavailable'=>0];
        foreach($catalog as $key){
            $r=$rows[$key][$metric]??['source'=>'unavailable'];
            if(!isset($counts[$r['source']])) throw new RuntimeException('source');
            $counts[$r['source']]++;
        }
        $out[$metric]=$counts+['total'=>count($catalog)];
    }
    return $out;
}
function qa_error(array $prior, array $current): array {
    $errors=[];
    foreach($prior as $key=>$metrics) foreach($metrics as $metric=>$p){
        $c=$current[$key][$metric]??[];
        if(($p['source']??null)==='estimated' && ($c['source']??null)==='observed')
            $errors[$metric][]=$c['value']-$p['value'];
    }
    $out=[];
    foreach($errors as $metric=>$e)
        $out[$metric]=['n'=>count($e),'mae'=>array_sum(array_map('abs',$e))/count($e),'bias'=>array_sum($e)/count($e)];
    return $out;
}
