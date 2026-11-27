"""Offline patch for G2 display. Preserve existing raw statistics and P4C."""
from pathlib import Path
import shutil
R=Path(__file__).resolve().parent
p=R/'candidate_php/g2_route_valh.php'
s=p.read_text(encoding='utf-8')
if 'QA_G09_METRIC_V1' in s:raise SystemExit('G09 already patched')
# Stage only accepts loopback php built-in server's environment, not request input.
s=s.replace("PHP_SAPI === 'cli' && getenv('QA_TEST_", "in_array(PHP_SAPI,['cli','cli-server'],true) && getenv('QA_TEST_")
a=s.index("        if (!$visible || !$model) continue;")
b=s.index("    }\n    unset($r);",a)
s=s[:a]+'''        // QA_G09_METRIC_V1. Preserve native stats; select the rendered metric independently.
        if (!$visible || !$model) continue;
        $key = (string)($r['quest_key'] ?? '');
        $e = $model[$key] ?? [];
        if ($e) $r['g2_estimate'] = $e;
        if (!function_exists('qa_metric')) require_once __DIR__.'/dynamic_metrics.php';
        $spec = ['qp'=>'estimated_qp','qph'=>'estimated_qph','valh'=>'estimated_valh','time'=>'estimated_sec'];
        foreach ($spec as $metric=>$field) {
            $actual = $r['qa_metrics'][$metric] ?? null;
            if (!is_array($actual)) continue; // old callers retain legacy logic below
            $allowed = (bool)$e;
            if (in_array($metric,['qph','valh','time'],true) && !empty($e['reward_unpriced'])) $allowed=false;
            if (in_array($metric,['qph','time'],true) && !empty($e['time_unverified'])) $allowed=false;
            if ($metric==='valh' && ($actual['samples']??0)>0 && is_numeric($actual['value']??null)) {
                $m=['value'=>$actual['value'],'source'=>'estimated','basis'=>'observed_run_valuation','samples'=>$actual['samples']];
            } else {
                $m=qa_metric($actual['value']??null,(int)($actual['samples']??0),5,$e[$field]??null,$allowed);
            }
            $r['g2_metrics'][$metric]=$m;
            if ($metric!=='time') $r['g2_sort_'.$metric]=$m['value'];
        }
        if (!isset($r['g2_metrics']['qp']) && $e && ($r['qp'] ?? null) === null) $r['g2_sort_qp'] = (float)$e['estimated_qp'];
        if (!isset($r['g2_metrics']['qph']) && $e && ($r['qph'] ?? null) === null && empty($e['time_unverified']) && empty($e['reward_unpriced'])) $r['g2_sort_qph'] = (float)$e['estimated_qph'];
        if (!isset($r['g2_metrics']['valh']) && $e && ($r['valh'] ?? null) === null && empty($e['reward_unpriced'])) $r['g2_sort_valh'] = (float)$e['estimated_valh'];
'''+s[b:]
needle="    $e = $row['g2_estimate'] ?? null;"
s=s.replace(needle,'''    $metric=$row['g2_metrics'][$type]??null;
    if (is_array($metric)) {
        $v=$metric['value'];
        if ($v===null) return '&mdash;';
        $value=$type==='time' ? intdiv((int)round($v),60).'m '.((int)round($v)%60).'s' : qpub_num($v,$type==='valh'?0:2);
        if ($metric['source']==='observed') return $value;
        if ($metric['source']==='low_sample') return $value.' <sup title="Low sample / 實測樣本不足">†</sup>';
        $tip=($metric['basis']??'')==='observed_run_valuation'?'Observed-run valuation / 實際遊玩價值估算':'Model estimate / 模型推估';
        return '<span class="p4c-estimate g2-estimate" title="'.qpub_h($tip).'">'.$value.'<sup>*</sup></span>';
    }
'''+needle)
s=s.replace("($row[$actualField] ?? null) !== null)", "(($row[$actualField] ?? null) !== null || ($type==='time' && !empty($row['median_text']))))")
s=s.replace("$registry = '/var/lib/ulgg/quest-g2/approved_release.json';", "$digest = hash_file('sha256', $root.'/manifest.json');\n        if (!is_string($digest)) return [];\n        $registry = '/var/lib/ulgg/quest-g2/approved/'.$digest.'.json';")
p.write_text(s,encoding='utf-8',newline='\n')
data=(R/'contract_reference/inc/data.php').read_text(encoding='utf-8')
needle="        'median_text' => $pick('median_text'),"
if data.count(needle)!=1:raise ValueError('view row drift')
data=data.replace(needle,"        'median_sec' => $pick('median_sec'),\n        'qa_metrics' => $src['qa_metrics'] ?? [],\n"+needle)
# Request-level realpath means pointer changes cannot mix different releases.
# No production pointer is switched by this script.
data=data.replace('return $dir = $candidate;', 'return $dir = (realpath($candidate) ?: $candidate);')
data += "\n// G09: actual response identity for release verification.\nif (PHP_SAPI !== 'cli' && !headers_sent()) {\n    $manifestPath = qpub_data_dir().'/manifest.json';\n    if (is_file($manifestPath)) {\n        $releaseHash = hash_file('sha256', $manifestPath);\n        if (is_string($releaseHash)) header('X-ULGG-Quest-Release: '.$releaseHash);\n    }\n}\n"
(R/'candidate_php/data.php').write_text(data,encoding='utf-8',newline='\n')
shutil.copy2(R/'dynamic_metrics.php',R/'candidate_php/dynamic_metrics.php')
print('G09 PHP integrated')
