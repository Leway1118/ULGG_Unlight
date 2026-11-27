from pathlib import Path
import re,shutil
R=Path(__file__).resolve().parent
s=(R/'contract_reference/inc/g2_route_valh.php').read_text(encoding='utf-8')
out=R/'candidate_php';out.mkdir(exist_ok=True)
shutil.copy2(R/'release_trust.php',out/'release_trust.php')
# Preserve the existing approved model hash and all individual entry validations.
s=s.replace("    $test = (string)getenv('ULGG_G2_STAGE_PATH');","    if (PHP_SAPI === 'cli' && getenv('QA_TEST_MODEL') !== false) return (string)getenv('QA_TEST_MODEL');\n    $test = (string)getenv('ULGG_G2_STAGE_PATH');")
a=s.index('    // Static model:'); b=s.index('    $index = [];',a)
s=s[:a]+'''    // Release approval comes from a separately controlled registry.
    require_once __DIR__.'/release_trust.php';
    $root = qpub_data_dir();
    $pin = '';
    if (PHP_SAPI === 'cli' && getenv('QA_TEST_MANIFEST_SHA') !== false) {
        $pin = (string)getenv('QA_TEST_MANIFEST_SHA');
    } else {
        $registry = '/var/lib/ulgg/quest-g2/approved_release.json';
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
    if (!hash_equals('CATALOG_IDENTITY_PIN', hash('sha256',json_encode($identity, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)))) return [];
    $entries = $doc['estimates'] ?? null;
    if (!is_array($entries)) return [];
'''+s[b:]
s=s.replace('!isset($missing[$key])','!isset($allKeys[$key])')
s=s.replace('return count($index) === count($missing) ? $index : [];','return $index;')
a=s.index('        if (!$visible || !$model'); b=s.index('    }\n    unset($r);',a)
s=s[:a]+'''        if (!$visible || !$model) continue;
        $key = (string)($r['quest_key'] ?? '');
        if (!isset($model[$key])) continue;
        $e = $model[$key];
        $r['g2_estimate'] = $e;
        if (($r['qp'] ?? null) === null) $r['g2_sort_qp'] = (float)$e['estimated_qp'];
        if (($r['qph'] ?? null) === null && empty($e['time_unverified']) && empty($e['reward_unpriced'])) $r['g2_sort_qph'] = (float)$e['estimated_qph'];
        if (($r['valh'] ?? null) === null && empty($e['reward_unpriced'])) $r['g2_sort_valh'] = (float)$e['estimated_valh'];
'''+s[b:]
s=s.replace("    if (!is_array($e)) {","    $actualField = ['qp'=>'qp','qph'=>'qph','valh'=>'valh','value'=>'average_value','time'=>'median_sec'][$type] ?? null;\n    if (!is_array($e) || ($actualField !== null && ($row[$actualField] ?? null) !== null)) {",1)
s=s.replace("    $unknown = (int)$e['unknown_rewards'];","    if (!empty($e['time_unverified']) && in_array($type,['qph','time'],true)) return '&mdash;';\n    $unknown = (int)$e['unknown_rewards'];",1)
(out/'g2_route_valh.php').write_text(s,encoding='utf-8',newline='\n')
