<?php
/** G1-P4C public experimental overlay. Independent enable flag; all fields unvalidated. */
declare(strict_types=1);
if (!defined('QPUB_VERSION')) { http_response_code(404); exit; }
function p4c_release_load(string $path): array {
    if (!is_file($path) || !is_readable($path) || hash_file('sha256', $path) !== '2b54c2fbbadd16aea797ff02fb958ec41e455cbd17a47a27ab24b6f38c4b8bd1') return [];
    $raw = file_get_contents($path);
    if ($raw === false || strlen($raw) > 300000) return [];
    $doc = json_decode($raw, true);
    if (!is_array($doc) || ($doc['schema'] ?? '') !== 'ULGG_QUEST_G1_P4C_EXPERIMENTAL_D1_V1'
        || ($doc['publication_allowed'] ?? null) !== false
        || ($doc['runtime_feature_flag'] ?? '') !== 'OFF') return [];
    try {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = new DateTimeImmutable((string)($doc['generated_at_utc'] ?? ''));
        $end = new DateTimeImmutable((string)($doc['expires_at_utc'] ?? ''));
    } catch (Throwable $e) { return []; }
    if ($now < $start || $now >= $end) return [];
    $root = qpub_data_dir();
    $refs = [
      'quest_catalog.json' => $root . '/quest_catalog.json',
      'windows/all/quests.json' => $root . '/windows/all/quests.json'
    ];
    foreach ($refs as $key => $file) {
        if (!is_file($file) || !hash_equals((string)($doc['source_sha256'][$key] ?? ''), hash_file('sha256', $file))) return [];
    }
    $expected = [
      '3:4#13'=>'E1','3:4#20'=>'E1',
      '7:3#51'=>'E2','7:3#52'=>'E2','7:3#57'=>'E2','7:3#58'=>'E2',
      '3:4#8'=>'E3','3:4#18'=>'E3','3:4#19'=>'E3','3:4#21'=>'E3',
      '3:4#22'=>'E3','3:4#23'=>'E3','3:4#26'=>'E3',
      '7:2#49'=>'E3','7:2#55'=>'E3','7:2#58'=>'E3','7:3#49'=>'E3'
    ];
    $entries = $doc['estimates'] ?? null;
    if (!is_array($entries) || count($entries) !== 34) return [];
    $map = [];
    foreach ($entries as $e) {
        if (!is_array($e)) return [];
        $key = $e['quest_key'] ?? null; $metric = $e['metric'] ?? null;
        if (!is_string($key) || !isset($expected[$key]) || !in_array($metric, ['qp','qph'], true)
            || ($e['risk_tier'] ?? '') !== $expected[$key]
            || ($e['model_tier'] ?? '') !== 'EXACT'
            || ($e['experimental_unvalidated'] ?? null) !== true
            || ($e['official_rank_eligible'] ?? null) !== false
            || !is_numeric($e['estimate']) || !is_finite((float)$e['estimate']) || (float)$e['estimate'] < 0
            || !is_int($e['donor_quests'] ?? null) || $e['donor_quests'] < 3
            || !is_int($e['donor_zones'] ?? null) || $e['donor_zones'] < 2) return [];
        $k = $key . '|' . $metric;
        if (isset($map[$k])) return [];
        $map[$k] = $e;
    }
    foreach ($expected as $key => $tier) {
        if (!isset($map[$key.'|qp'], $map[$key.'|qph'])) return [];
    }
    // Hard fail if any target is now observed, including real QP=0.
    $published = json_decode((string)file_get_contents($refs['windows/all/quests.json']), true);
    if (!is_array($published) || !is_array($published['rows'] ?? null)) return [];
    $byKey = [];
    foreach ($published['rows'] as $r) if (is_array($r)) $byKey[(string)($r['quest_key'] ?? '')] = $r;
    foreach ($expected as $key => $tier) {
        if (!isset($byKey[$key]) || !empty($byKey[$key]['observed'])
            || ($byKey[$key]['qp'] ?? null) !== null || ($byKey[$key]['qph'] ?? null) !== null) return [];
    }
    return $map;
}
function p4c_release_rows(array $rows, array $map, bool $visible): array {
    foreach ($rows as &$r) {
        $r['p4c_sort_qp'] = $r['qp'] ?? null;
        $r['p4c_sort_qph'] = $r['qph'] ?? null;
        if (!$visible || !$map || !empty($r['observed']) || ($r['qp'] ?? null) !== null || ($r['qph'] ?? null) !== null) continue;
        $key = (string)($r['quest_key'] ?? '');
        if (!isset($map[$key.'|qp'], $map[$key.'|qph'])) continue;
        $tier = $map[$key.'|qp']['risk_tier'];
        $r['p4c_tier'] = $tier;
        $r['p4c_qp'] = $map[$key.'|qp']['estimate'];
        $r['p4c_qph'] = $map[$key.'|qph']['estimate'];
        $r['p4c_risk'] = (string)$map[$key.'|qph']['risk_reason'];
        $r['p4c_donors'] = (int)$map[$key.'|qph']['donor_quests'];
        $r['p4c_zones'] = (int)$map[$key.'|qph']['donor_zones'];
        // E2/E3 stay null for mixed sortable metrics: viewable, not ranked.
        if ($tier === 'E1') {
            $r['p4c_sort_qp'] = (float)$r['p4c_qp'];
            $r['p4c_sort_qph'] = (float)$r['p4c_qph'];
        }
    }
    unset($r);
    return $rows;
}
function p4c_release_cell(array $r, string $metric): string {
    if (!isset($r['p4c_tier'])) return $metric === 'qp' ? qpub_int($r['qp'] ?? null) : qpub_num($r['qph'] ?? null);
    $tier = $r['p4c_tier'];
    $v = $r['p4c_'.$metric];
    $label = $tier === 'E3' ? '高風險實驗推估' : '實驗推估';
    $why = $tier === 'E2' ? '特殊地圖規則未驗證' : ($tier === 'E3' ? '參照地域存在風險' : '模型未經正式驗證');
    $tooltip = '模型 G1-P3；'.($r['quest_key'] ?? '').'；參照Quest '.$r['p4c_donors'].'；參照地域 '.$r['p4c_zones'].'；'.$why.'；'.($r['p4c_risk'] ?? '');
    $formatted = number_format((float)$v, 0, '.', ',');
    return '<span class="p4c-estimate" title="'.qpub_h($tooltip).'">約 '.qpub_h($formatted).' <small>'.qpub_h($label.' '.$tier).'</small></span>';
}
