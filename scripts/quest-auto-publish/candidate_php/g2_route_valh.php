<?php
/** ULGG G2 complete missing values: static map route + historical group models.
 * Existing observed source fields and official ranking remain authoritative.
 */
declare(strict_types=1);
if (!defined('QPUB_VERSION')) { http_response_code(404); exit; }

function g2_route_candidate_path(): string
{
    if (in_array(PHP_SAPI,['cli','cli-server'],true) && getenv('QA_TEST_MODEL') !== false) return (string)getenv('QA_TEST_MODEL');
    $test = (string)getenv('ULGG_G2_STAGE_PATH');
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true)
        && $test !== ''
        && str_starts_with($test, '/root/ulgg-g1/g2-private/')
    ) return $test;
    return '/var/lib/ulgg/quest-g2/route_valh.json';
}
function g2_route_load(): array
{
    $path = g2_route_candidate_path();
    if (!is_file($path) || !is_readable($path)
        || !hash_equals('90593c6e7de3d9fc2e8753f8a22e4a9a68e5d1535fe14c06fa8e09572b09216f', (string)hash_file('sha256', $path))) return [];
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 800000) return [];
    $doc = json_decode($raw, true);
    if (!is_array($doc)
        || ($doc['schema'] ?? '') !== 'ULGG_G2_FULL_CATALOG_SIMULATION_V3'
        || ($doc['policy'] ?? '') !== 'model_simulation_only_missing_no_observed_override') return [];
    // Release approval comes from a separately controlled registry.
    require_once __DIR__.'/release_trust.php';
    $root = qpub_data_dir();
    $pin = '';
    if (in_array(PHP_SAPI,['cli','cli-server'],true) && getenv('QA_TEST_MANIFEST_SHA') !== false) {
        $pin = (string)getenv('QA_TEST_MANIFEST_SHA');
    } else {
        $digest = hash_file('sha256', $root.'/manifest.json');
        if (!is_string($digest)) return [];
        $registry = '/var/lib/ulgg/quest-g2/approved/'.$digest.'.json';
        if (!is_file($registry) || is_link($registry)) return [];
        $approval = json_decode((string)file_get_contents($registry), true);
        $pin = (string)($approval['manifest_sha256'] ?? '');
    }
    $required = ['overview.json','quality.json','quest_catalog.json','teams_all.json'];
    foreach (['today','7d','30d','all'] as $w)
        foreach (['quests','regions','quests_duration','star_matrix'] as $k)
            $required[] = "windows/$w/$k.json";
    try { qa_verified_release($root, $pin, $required); }
    catch (Throwable $e) { return []; }
    $catalog = qpub_read_json('quest_catalog.json');
    $published = qpub_read_json('windows/all/quests.json');
    if (!is_array($catalog['rows'] ?? null) || !is_array($published['rows'] ?? null)) return [];
    $allKeys = [];
    foreach ($catalog['rows'] as $cat) {
        $key = $cat['quest_key'] ?? null;
        if (!is_string($key) || $key === '' || isset($allKeys[$key])) return [];
        $allKeys[$key] = $cat;
    }
    $seen = [];
    foreach ($published['rows'] as $r) {
        $key = $r['quest_key'] ?? null;
        if (!is_string($key) || !isset($allKeys[$key]) || isset($seen[$key])) return [];
        $c = $allKeys[$key];
        foreach (['zone_key','quest_id','quest_name'] as $f)
            if (($r[$f] ?? null) !== ($c[$f] ?? null)) return [];
        $seen[$key] = true;
    }
    // Catalog identity is independently pinned, ignoring only observation flags.
    $identity = [];
    foreach ($catalog['rows'] as $cat) {
        unset($cat['observed_all_period']);
        ksort($cat);
        $identity[$cat['quest_key']] = $cat;
    }
    ksort($identity);
    if (!hash_equals('9d747f4676dd6a3167f59ea24b2aa59774ace42a319d947d9952f38620aed4e5', hash('sha256',json_encode($identity, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)))) return [];
    $entries = $doc['estimates'] ?? null;
    if (!is_array($entries)) return [];
    $index = [];
    foreach ($entries as $e) {
        if (!is_array($e)) return [];
        $key = $e['quest_key'] ?? null;
        if (!is_string($key) || !isset($allKeys[$key]) || isset($index[$key])
            || ($e['model'] ?? '') !== 'QUEST_ROUTE_MAP_DIFFICULTY_QP_V4') return [];
        foreach (['estimated_sec','estimated_qp','estimated_qph','estimated_valh'] as $field) {
            if (!is_numeric($e[$field] ?? null) || !is_finite((float)$e[$field])
                || (float)$e[$field] < 0) return [];
        }
        $t = (float)$e['estimated_sec'];
        $qp = (float)$e['estimated_qp'];
        $v = $e['known_value'] ?? null;
        $difficulty = $e['map_difficulty_qp'] ?? null;
        $ap = $e['ap_cost'] ?? null;
        if (!is_int($difficulty) || $difficulty < 0 || $difficulty > 200
            || !is_int($ap) || $ap < 0 || $ap > 100
            || $qp !== (float)$difficulty
            || ($ap > 0
               && (abs((float)($e['estimated_qp_ap'] ?? -1) - $qp/$ap) > 0.00001
                   || abs((float)($e['estimated_val_ap'] ?? -1) - $v/$ap) > 0.00001))
            || ($ap === 0 && (($e['estimated_qp_ap'] ?? null) !== null
                             || ($e['estimated_val_ap'] ?? null) !== null))
            || $t < 5 || $t > 3600 || !is_int($v) || $v < 0 || $v > 1000000
            || abs((float)$e['estimated_valh'] - $v*3600/$t) > 0.1
            || abs((float)$e['estimated_qph'] - $qp*3600/$t) > 0.1
            || !is_int($e['hops'] ?? null) || $e['hops'] < 1 || $e['hops'] > 5
            || !is_int($e['battles'] ?? null) || $e['battles'] < 0 || $e['battles'] > $e['hops']
            || !is_array($e['route'] ?? null) || count($e['route']) !== $e['hops']
            || !is_int($e['unknown_rewards'] ?? null) || $e['unknown_rewards'] < 0
            || !is_int($e['time_reference_n'] ?? null) || $e['time_reference_n'] < 0
            || !is_int($e['qp_reference_n'] ?? null) || $e['qp_reference_n'] < 0) return [];
        $index[$key] = $e;
    }
    return $index;
}
function g2_route_rows(array $rows, array $model, bool $visible): array
{
    foreach ($rows as &$r) {
        $r['g2_sort_qp'] = $r['qp'] ?? null;
        $r['g2_sort_qph'] = $r['qph'] ?? null;
        $r['g2_sort_valh'] = $r['valh'] ?? null;
        // QA_G09_METRIC_V1. Preserve native stats; select the rendered metric independently.
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
    }
    unset($r);
    return $rows;
}
function g2_route_cell(array $row, string $type): string
{
    $metric=$row['g2_metrics'][$type]??null;
    if (is_array($metric)) {
        $v=$metric['value'];
        if ($v===null) return '&mdash;';
        $value=$type==='time' ? intdiv((int)round($v),60).'m '.((int)round($v)%60).'s' : qpub_num($v,$type==='valh'?0:2);
        if ($metric['source']==='observed') return $value;
        if ($metric['source']==='low_sample') return $value.' <sup title="Low sample / 實測樣本不足">†</sup>';
        $tip=($metric['basis']??'')==='observed_run_valuation'?'Observed-run valuation / 實際遊玩價值估算':'Model estimate / 模型推估';
        return '<span class="p4c-estimate g2-estimate" title="'.qpub_h($tip).'">'.$value.'<sup>*</sup></span>';
    }
    $e = $row['g2_estimate'] ?? null;
    $actualField = ['qp'=>'qp','qph'=>'qph','valh'=>'valh','value'=>'average_value','time'=>'median_sec'][$type] ?? null;
    if (!is_array($e) || ($actualField !== null && (($row[$actualField] ?? null) !== null || ($type==='time' && !empty($row['median_text']))))) {
        return match ($type) {
          'qp' => qpub_int($row['qp'] ?? null),
          'qph' => qpub_num($row['qph'] ?? null),
          'valh' => qpub_num($row['valh'] ?? null),
          'value' => qpub_num($row['average_value'] ?? null),
          'time' => qpub_text($row['median_text'] ?? null),
          default => '—',
        };
    }
    if (!empty($e['time_unverified']) && in_array($type,['qph','time'],true)) return '&mdash;';
    $unknown = (int)$e['unknown_rewards'];
    if (!empty($e['reward_unpriced']) && in_array($type, ['qph', 'valh', 'value', 'time'], true)) {
        $why = 'R1 記憶任務是可領取角色卡的特殊任務；其獎勵尚無可核對的 VALUE 計價。'
             . '缺少同類任務的充分通關時間實測，因此不顯示估算效率、時間或 VALUE。'
             . 'QP 仍以原始地圖 difficulty 為準。';
        return '<span class="p4c-estimate g2-estimate" title="'.qpub_h($why).'">'
            .'—<sup aria-label="模型資料不足" style="font-size:.7em;margin-left:1px">*</sup></span>';
    }
    $tip = '模型模擬（不是實測）；Quest '.$e['quest_key'].'；地圖路線 '
        .$e['hops'].' 格、'.$e['battles'].' 戰；'
        .'已知路線 VALUE '.$e['known_value'].'；未定價獎勵 '.$unknown.' 個暫按 0；'
        .'QP '.$e['map_difficulty_qp'].' 取自原始地圖 difficulty（以三人隊伍為基準）；'
        .'總 AP '.$e['ap_cost'].'；時間參照 Quest '.$e['time_reference_n'].' 張。'
        .'QP 為原始地圖固定值；時間、效率與路線 VALUE 仍屬模型模擬。';
    if (($e['supplement_source'] ?? '') === 'wiki_hexrealm_v5') {
        $tip .= '戰鬥格依 Unlight 繁中 Wiki 逐階任務表補正；預測時間不是人狼家園實測。';
    }
    $value = match ($type) {
        'qp' => number_format((float)$e['estimated_qp'], 1, '.', ','),
        'qph' => number_format((float)$e['estimated_qph'], 1, '.', ','),
        'valh' => number_format((float)$e['estimated_valh'], 0, '.', ','),
        'value' => number_format((float)$e['known_value'], 0, '.', ','),
        'time' => intdiv((int)round((float)$e['estimated_sec']), 60).'m '
                  .((int)round((float)$e['estimated_sec']) % 60).'s',
        default => '—',
    };
    // Compact cell; a single explanatory legend above the table owns the label.
    return '<span class="p4c-estimate g2-estimate" style="white-space:nowrap" title="'.qpub_h($tip).'">'
        .qpub_h($value).'<sup aria-label="模型模擬" style="font-size:0.7em;margin-left:1px">*</sup></span>';
}
