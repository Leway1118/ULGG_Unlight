<?php

declare(strict_types=1);

/**
 * 將 Watcher 的 chara + charaIndex 轉成 Ruleset 卡片 ID。
 *
 * 範例：
 * cc001 + 0   => cc001_01
 * cc001 + 4   => cc001_05
 * cc001 + 5   => cc001_r01
 * cc013 + 129 => cc013_r05
 */
function resolveWatcherCharacterCardId(
    string $charaId,
    int $charaIndex
): string {
    if (!preg_match('/^cc(\d{3})$/', $charaId, $matches)) {
        throw new InvalidArgumentException(
            sprintf('Invalid character ID: %s.', $charaId)
        );
    }

    $characterNumber = (int)$matches[1];

    if ($characterNumber < 1) {
        throw new InvalidArgumentException(
            sprintf('Invalid character number: %s.', $charaId)
        );
    }

    $baseIndex = ($characterNumber - 1) * 10;
    $offset = $charaIndex - $baseIndex;

    if ($offset >= 0 && $offset <= 4) {
        return sprintf(
            'cc%03d_%02d',
            $characterNumber,
            $offset + 1
        );
    }

    if ($offset >= 5 && $offset <= 9) {
        return sprintf(
            'cc%03d_r%02d',
            $characterNumber,
            $offset - 4
        );
    }

    throw new InvalidArgumentException(
        sprintf(
            'Character index %d does not match %s.',
            $charaIndex,
            $charaId
        )
    );
}

/**
 * 評估單一 Watcher deck 的 Ruleset COST。
 *
 * 計算公式：
 * final_cost
 * = original_deck_cost
 * - base_character_cost
 * + ruleset_character_cost
 */
function evaluateWatcherDeckCost(
    array $deck,
    array $baseCostMap,
    array $rulesetCostMap,
    array $weaponCostMap,
    array $eventCostMap,
    int $costLimit
): array {
    if ($costLimit < 0) {
        throw new InvalidArgumentException(
            'COST limit must be zero or greater.'
        );
    }

    $validationErrors = validateWatcherDeck($deck);

    if ($validationErrors !== []) {
        return buildIncompleteResult(
            $costLimit,
            $validationErrors
        );
    }

    /** @var array<int, string> $chara */
    $chara = $deck['chara'];

    /** @var array<int, int> $charaIndexes */
    $charaIndexes = $deck['charaIndex'];

    $originalDeckCost = (int)$deck['cost'];

    $cards = [];
    $unknownBaseCards = [];
    $unknownRulesetCards = [];
    $baseCharacterCost = 0;
    $rulesetCharacterCost = 0;

    foreach ($chara as $index => $rawCharaId) {
        $charaId = trim($rawCharaId);
        $charaIndex = $charaIndexes[$index];

        try {
            $cardId = resolveWatcherCharacterCardId(
                $charaId,
                $charaIndex
            );
        } catch (InvalidArgumentException $exception) {
            return buildIncompleteResult(
                $costLimit,
                ['CHARACTER_ID_RESOLUTION_FAILED'],
                [
                    'message' => $exception->getMessage(),
                    'character_position' => $index,
                    'chara_id' => $charaId,
                    'chara_index' => $charaIndex,
                ]
            );
        }

        $hasBaseCost = array_key_exists(
            $cardId,
            $baseCostMap
        );

        $hasRulesetCost = array_key_exists(
            $cardId,
            $rulesetCostMap
        );

        if (!$hasBaseCost) {
            $unknownBaseCards[] = $cardId;
        }

        if (!$hasRulesetCost) {
            $unknownRulesetCards[] = $cardId;
        }

        if (!$hasBaseCost || !$hasRulesetCost) {
            $cards[] = [
                'position' => $index,
                'chara_id' => $charaId,
                'chara_index' => $charaIndex,
                'card_id' => $cardId,
                'base_cost' => $hasBaseCost
                    ? (int)$baseCostMap[$cardId]
                    : null,
                'ruleset_cost' => $hasRulesetCost
                    ? (int)$rulesetCostMap[$cardId]
                    : null,
                'cost_delta' => null,
            ];

            continue;
        }

        $baseCost = (int)$baseCostMap[$cardId];
        $rulesetCost = (int)$rulesetCostMap[$cardId];

        $baseCharacterCost += $baseCost;
        $rulesetCharacterCost += $rulesetCost;

        $cards[] = [
            'position' => $index,
            'chara_id' => $charaId,
            'chara_index' => $charaIndex,
            'card_id' => $cardId,
            'base_cost' => $baseCost,
            'ruleset_cost' => $rulesetCost,
            'cost_delta' => $rulesetCost - $baseCost,
        ];
    }

    if (
        $unknownBaseCards !== []
        || $unknownRulesetCards !== []
    ) {
        return [
            'status' => 'UNKNOWN_CARD',
            'original_deck_cost' => $originalDeckCost,
            'base_character_cost' => null,
            'ruleset_character_cost' => null,
            'non_character_cost' => null,
            'final_cost' => null,
            'cost_limit' => $costLimit,
            'over_cost' => null,
            'cards' => $cards,
            'unknown_base_cards' => array_values(
                array_unique($unknownBaseCards)
            ),
            'unknown_ruleset_cards' => array_values(
                array_unique($unknownRulesetCards)
            ),
            'violations' => ['UNKNOWN_CARD'],
            'errors' => [],
        ];
    }

    $weaponCost = 0;
    $eventCost = 0;

    foreach ($deck['weapon'] ?? [] as $weaponId) {
        if ($weaponId === null) {
            continue;
        }

        if (array_key_exists($weaponId, $weaponCostMap)) {
            $weaponCost += (int)$weaponCostMap[$weaponId];
        }
    }

    foreach ($deck['eventIndex'] ?? [] as $eventId) {
        if ($eventId === null) {
            continue;
        }

        if (array_key_exists($eventId, $eventCostMap)) {
            $eventCost += (int)$eventCostMap[$eventId];
        }
    }

    /*
 * Watcher 的原始 deck.cost 已包含：
 * 角色 + 武器 + 事件卡 + 遊戲額外懲罰。
 *
 * 自訂規則只替換角色 COST，
 * 其他部分必須原樣保留。
 */
    $differencePenalty =
        $originalDeckCost
        - $baseCharacterCost
        - $weaponCost
        - $eventCost;

    if ($differencePenalty < 0) {
        return [
            'status' => 'INSUFFICIENT_DATA',
            'original_deck_cost' => $originalDeckCost,
            'base_character_cost' => $baseCharacterCost,
            'ruleset_character_cost' => $rulesetCharacterCost,
            'weapon_cost' => $weaponCost,
            'event_cost' => $eventCost,
            'difference_penalty' => $differencePenalty,
            'non_character_cost' => null,
            'final_cost' => null,
            'cost_limit' => $costLimit,
            'over_cost' => null,
            'cards' => $cards,
            'unknown_base_cards' => [],
            'unknown_ruleset_cards' => [],
            'violations' => [
                'NEGATIVE_DIFFERENCE_PENALTY',
            ],
            'errors' => [
                [
                    'code' => 'BASE_COST_MISMATCH',
                    'message' =>
                    'Watcher deck.cost is lower than character, weapon and event COST total.',
                ],
            ],
        ];
    }

    $nonCharacterCost =
        $weaponCost
        + $eventCost
        + $differencePenalty;

    $finalCost =
        $rulesetCharacterCost
        + $nonCharacterCost;
    $isIllegal = $finalCost > $costLimit;

    return [
        'status' => $isIllegal
            ? 'ILLEGAL'
            : 'LEGAL',
        'original_deck_cost' => $originalDeckCost,
        'base_character_cost' => $baseCharacterCost,
        'ruleset_character_cost' => $rulesetCharacterCost,
        'weapon_cost' => $weaponCost,
        'event_cost' => $eventCost,
        'difference_penalty' => $differencePenalty,
        'non_character_cost' => $nonCharacterCost,
        'final_cost' => $finalCost,
        'cost_limit' => $costLimit,
        'over_cost' => max(
            0,
            $finalCost - $costLimit
        ),
        'cards' => $cards,
        'unknown_base_cards' => [],
        'unknown_ruleset_cards' => [],
        'violations' => $isIllegal
            ? ['COST_LIMIT_EXCEEDED']
            : [],
        'errors' => [],
    ];
}

/**
 * 驗證 Watcher deck 最低必要欄位。
 */
function validateWatcherDeck(array $deck): array
{
    $errors = [];

    if (
        !array_key_exists('chara', $deck)
        || !is_array($deck['chara'])
    ) {
        $errors[] = 'CHARA_MISSING_OR_INVALID';
    }

    if (
        !array_key_exists('charaIndex', $deck)
        || !is_array($deck['charaIndex'])
    ) {
        $errors[] = 'CHARA_INDEX_MISSING_OR_INVALID';
    }

    if (
        !array_key_exists('cost', $deck)
        || !is_int($deck['cost'])
    ) {
        $errors[] = 'DECK_COST_MISSING_OR_INVALID';
    }

    if ($errors !== []) {
        return $errors;
    }

    if (count($deck['chara']) !== 3) {
        $errors[] = 'CHARA_COUNT_INVALID';
    }

    if (count($deck['charaIndex']) !== 3) {
        $errors[] = 'CHARA_INDEX_COUNT_INVALID';
    }

    if (
        count($deck['chara'])
        !== count($deck['charaIndex'])
    ) {
        $errors[] = 'CHARA_ARRAY_LENGTH_MISMATCH';
    }

    foreach ($deck['chara'] as $index => $value) {
        if (
            !is_string($value)
            || trim($value) === ''
        ) {
            $errors[] = sprintf(
                'CHARA_INVALID_AT_%d',
                $index
            );
        }
    }

    foreach ($deck['charaIndex'] as $index => $value) {
        if (!is_int($value)) {
            $errors[] = sprintf(
                'CHARA_INDEX_INVALID_AT_%d',
                $index
            );
        }
    }

    if (
        is_int($deck['cost'])
        && $deck['cost'] < 0
    ) {
        $errors[] = 'DECK_COST_NEGATIVE';
    }

    return $errors;
}

function buildIncompleteResult(
    int $costLimit,
    array $violations,
    array $errorContext = []
): array {
    $errors = [];

    if ($errorContext !== []) {
        $errors[] = $errorContext;
    }

    return [
        'status' => 'INSUFFICIENT_DATA',
        'original_deck_cost' => null,
        'base_character_cost' => null,
        'ruleset_character_cost' => null,
        'non_character_cost' => null,
        'final_cost' => null,
        'cost_limit' => $costLimit,
        'over_cost' => null,
        'cards' => [],
        'unknown_base_cards' => [],
        'unknown_ruleset_cards' => [],
        'violations' => $violations,
        'errors' => $errors,
    ];
}
