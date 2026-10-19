<?php
/**
 * Table sorting helpers. Sorting is presentation-only: it reorders rows that
 * were published by the exporter and never changes a value.
 */

declare(strict_types=1);

if (!defined('QPUB_VERSION')) {
    // Reached by direct web access to an include file: refuse instead of
    // leaking internals or fataling.
    http_response_code(404);
    exit;
}

/**
 * Null-safe, stable-ish comparison for one row field.
 *
 * Missing / null values always sort last, and text uses a byte-wise comparison
 * so the ordering stays deterministic across machines.
 */
function qpub_sort_value(array $row, string $field): array
{
    $value = $row[$field] ?? null;
    if (qpub_is_number($value)) {
        return [0, (float)$value, ''];
    }
    if (is_string($value) && trim($value) !== '') {
        return [1, 0.0, trim($value)];
    }
    return [2, 0.0, ''];
}

function qpub_rows_sort(array $rows, string $sortKey, string $dir, array $map): array
{
    $field = $map[$sortKey] ?? null;
    if ($field === null) {
        return $rows;
    }
    $ascending = strtolower($dir) === 'asc';
    $decorated = [];
    foreach ($rows as $index => $row) {
        $decorated[] = ['row' => $row, 'index' => $index, 'key' => qpub_sort_value($row, $field)];
    }
    usort($decorated, static function (array $a, array $b) use ($ascending): int {
        if ($a['key'][0] !== $b['key'][0]) {
            return $a['key'][0] <=> $b['key'][0];
        }
        if ($a['key'][0] === 0) {
            $cmp = $a['key'][1] <=> $b['key'][1];
            if ($cmp !== 0) {
                return $ascending ? $cmp : -$cmp;
            }
        } elseif ($a['key'][0] === 1) {
            $cmp = strcmp($a['key'][2], $b['key'][2]);
            if ($cmp !== 0) {
                return $ascending ? $cmp : -$cmp;
            }
        }
        return $a['index'] <=> $b['index'];
    });
    return array_map(static fn(array $item): array => $item['row'], $decorated);
}

/** Sortable table header cell. */
function qpub_th(
    string $file,
    array $params,
    string $sortKey,
    string $label,
    string $currentSort,
    string $currentDir,
    string $extraClass = '',
    string $hint = ''
): void {
    $isActive = $currentSort === $sortKey;
    $nextDir = ($isActive && $currentDir === 'desc') ? 'asc' : 'desc';
    $target = qpub_url($file, array_merge($params, ['sort' => $sortKey, 'dir' => $nextDir, 'page' => 1]));
    $arrow = $isActive ? ($currentDir === 'desc' ? ' ▾' : ' ▴') : '';
    $classes = trim('qpub-th-sortable ' . $extraClass);
    echo '<th scope="col" class="' . qpub_h($classes) . '">';
    echo '<a href="' . qpub_hu($target) . '" rel="nofollow"';
    if ($hint !== '') {
        echo ' title="' . qpub_h($hint) . '"';
    }
    echo '>' . qpub_h($label) . qpub_h($arrow) . '</a>';
    echo '</th>';
}

/** Static (non-sortable) header cell. */
function qpub_th_plain(string $label, string $extraClass = ''): void
{
    echo '<th scope="col" class="' . qpub_h(trim($extraClass)) . '">' . qpub_h($label) . '</th>';
}
