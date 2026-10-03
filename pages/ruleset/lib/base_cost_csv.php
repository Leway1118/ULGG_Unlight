<?php

declare(strict_types=1);

function loadBaseCostMapFromCsv(string $filePath): array
{
    if (!is_file($filePath)) {
        throw new RuntimeException('Base COST CSV file not found.');
    }

    if (!is_readable($filePath)) {
        throw new RuntimeException('Base COST CSV file is not readable.');
    }

    $handle = fopen($filePath, 'rb');

    if ($handle === false) {
        throw new RuntimeException('Failed to open Base COST CSV file.');
    }

    try {
        $header = fgetcsv($handle);

        if ($header === false) {
            throw new RuntimeException('Base COST CSV is empty.');
        }

        if (isset($header[0])) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        }

        $expectedHeader = [
            'chara_id',
            'name_ja',
            'name_tcn',
            'L1',
            'L2',
            'L3',
            'L4',
            'L5',
            'R1',
            'R2',
            'R3',
            'R4',
            'R5',
        ];

        if ($header !== $expectedHeader) {
            throw new RuntimeException('Unexpected Base COST CSV header.');
        }

        $costMap = [];
        $lineNumber = 1;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if ($row === [null] || $row === []) {
                continue;
            }

            if (count($row) !== count($expectedHeader)) {
                throw new RuntimeException(
                    sprintf('Invalid CSV column count at line %d.', $lineNumber)
                );
            }

            $charaId = trim((string)$row[0]);

            if (!preg_match('/^cc\d{3}$/', $charaId)) {
                throw new RuntimeException(
                    sprintf('Invalid chara_id at line %d.', $lineNumber)
                );
            }

            $levels = [
                '01',
                '02',
                '03',
                '04',
                '05',
                'r01',
                'r02',
                'r03',
                'r04',
                'r05',
            ];

            foreach ($levels as $index => $suffix) {
                $rawCost = trim((string)$row[$index + 3]);

                if ($rawCost === '') {
                    continue;
                }

                if (!ctype_digit($rawCost)) {
                    throw new RuntimeException(
                        sprintf(
                            'Invalid COST for %s_%s at line %d.',
                            $charaId,
                            $suffix,
                            $lineNumber
                        )
                    );
                }

                $cardId = $charaId . '_' . $suffix;

                if (array_key_exists($cardId, $costMap)) {
                    throw new RuntimeException(
                        sprintf('Duplicate card ID: %s.', $cardId)
                    );
                }

                $costMap[$cardId] = (int)$rawCost;
            }
        }
    } finally {
        fclose($handle);
    }

    if ($costMap === []) {
        throw new RuntimeException('Base COST CSV contains no usable entries.');
    }

    return $costMap;
}