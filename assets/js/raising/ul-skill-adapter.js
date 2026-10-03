export const UL_CARD_TYPE_TO_STAT = {
  0: 'sword',
  1: 'gun',
  2: 'shield',
  3: 'move',
  4: 'special',
  // type 5 是原作的特殊牌條件；育成層仍正規化為「特」，但保留 sourceType。
  5: 'special',
};

export const UL_RANGE_TO_DISTANCE = { 0: 'short', 1: 'middle', 2: 'long' };
export const UL_PHASE_TO_PHASE = { 0: 'attack', 1: 'defense', 2: 'move' };

export function normalizeUlSkillRequirement(rawRequirement = {}) {
  const abilities = {};
  const sourceRequirements = Array.isArray(rawRequirement.require) ? rawRequirement.require : [];
  sourceRequirements.forEach((requirement) => {
    const stat = UL_CARD_TYPE_TO_STAT[requirement.type];
    if (!stat) return;
    abilities[stat] = (abilities[stat] ?? 0) + Number(requirement.quantity || 0);
  });
  return {
    abilities,
    requirements: sourceRequirements.map((requirement) => ({
      sourceType: Number(requirement.type),
      stat: UL_CARD_TYPE_TO_STAT[requirement.type] ?? 'unknown',
      quantity: Number(requirement.quantity || 0),
      num: Number(requirement.num || 0),
    })),
    ranges: (rawRequirement.range ?? []).map((range) => UL_RANGE_TO_DISTANCE[range]).filter(Boolean),
    phase: UL_PHASE_TO_PHASE[rawRequirement.phase] ?? 'unknown',
  };
}

export function mapTrainingRequirements(normalizedRequirement, balanceOverrides) {
  return {
    source: normalizedRequirement,
    requirements: Object.entries(balanceOverrides.abilities ?? {}).map(([stat, value]) => ({
      type: 'ability', stat, value,
    })),
    skillPoints: Number(balanceOverrides.skillPoints ?? 0),
    eventFlag: balanceOverrides.eventFlag ?? null,
    characterRarity: Number(balanceOverrides.characterRarity ?? 10),
    prerequisiteSkill: balanceOverrides.prerequisiteSkill ?? null,
  };
}

export function extractCharacterSkillEvolution(frames, characterId) {
  return frames
    .filter((frame) => frame.chara === characterId)
    .sort((left, right) => Number(left.rarity) - Number(right.rarity))
    .map((frame) => ({
      filename: frame.filename,
      rarity: Number(frame.rarity),
      level: Number(frame.level),
      skills: [1, 2, 3, 4].map((index) => {
        const name = frame[`skill${index}_tcn`];
        if (!name) return null;
        return {
          slot: index,
          id: frame[`skill${index}`],
          baseId: frame[`base${index}`],
          name,
          requirement: normalizeUlSkillRequirement(frame[`skill${index}_require`] ?? {}),
        };
      }).filter(Boolean),
    }));
}
