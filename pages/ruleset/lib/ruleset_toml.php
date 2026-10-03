<?php

declare(strict_types=1);

/**
 * 讀取第一版 Demo 使用的 flat TOML COST 表。
 *
 * 支援格式：
 * cc001_01 = 99 # 艾伯李斯特L1
 *
 * 不支援：
 * - TOML section
 * - array
 * - string value
 * - nested table
 */
function loadRulesetCostMap(string $filePath): array
{
    if (!is_file($filePath)) {
        throw new RuntimeException('Ruleset TOML file not found.');
    }

    if (!is_readable($filePath)) {
        throw new RuntimeException('Ruleset TOML file is not readable.');
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new RuntimeException('Failed to read Ruleset TOML file.');
    }

    $costMap = [];

    foreach ($lines as $lineNumber => $rawLine) {
        $line = trim($rawLine);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $lineWithoutComment = preg_replace('/\s+#.*$/u', '', $line);

        if ($lineWithoutComment === null) {
            throw new RuntimeException(
                sprintf('Failed to parse line %d.', $lineNumber + 1)
            );
        }

        $lineWithoutComment = trim($lineWithoutComment);

        if ($lineWithoutComment === '') {
            continue;
        }

        if (!preg_match(
            '/^(cc\d{3}_(?:\d{2}|r\d{2}))\s*=\s*(\d+)$/',
            $lineWithoutComment,
            $matches
        )) {
            throw new InvalidArgumentException(
                sprintf(
                    'Invalid TOML entry at line %d: %s',
                    $lineNumber + 1,
                    $rawLine
                )
            );
        }

        $cardId = $matches[1];
        $cost = (int)$matches[2];

        if (array_key_exists($cardId, $costMap)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Duplicate card ID at line %d: %s',
                    $lineNumber + 1,
                    $cardId
                )
            );
        }

        $costMap[$cardId] = $cost;
    }

    if ($costMap === []) {
        throw new RuntimeException('Ruleset TOML does not contain any COST entries.');
    }

    return $costMap;
}