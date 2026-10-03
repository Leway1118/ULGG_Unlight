import { mapTrainingRequirements, normalizeUlSkillRequirement } from './ul-skill-adapter.js';

export const STAT_KEYS = ['sword', 'gun', 'shield', 'move', 'special'];

export const STAT_LABELS = {
  sword: '劍',
  gun: '槍',
  shield: '盾',
  move: '移',
  special: '特',
};

export const CHARACTER_DATA = {
  cc001: {
    id: 'cc001',
    name: '艾伯李斯特',
    title: 'ReichsRitter',
    rankLabel: 'R5',
    profile: '古朗德利尼亞帝國騎士。以意志和智謀鞏固其在帝國的地位。',
    theme: '帝國騎士／意志／智略／掌握戰場',
    stats: { sword: 95, gun: 100, shield: 90, move: 105, special: 110 },
    growth: { move: 0.1, special: 0.2 },
    distanceAptitude: { long: 'A', middle: 'A', short: 'A' },
    strategyAptitude: { sprint: 'B', charge: 'A', stalk: 'A', chase: 'C' },
  },
};

export const ROSTER = [
  { id: 'cc001', name: '艾伯李斯特', available: true },
  { id: 'cc003', name: '古魯瓦爾多', available: false },
  { id: 'cc004', name: '阿貝爾', available: false },
  { id: 'cc002', name: '艾依查庫', available: false },
  { id: 'cc011', name: '雪莉', available: false },
  { id: 'cc012', name: '艾茵', available: false },
];

export const TRAINING_DATA = {
  sword: { label: '劍訓練', gains: { sword: 14, move: 5 }, skillPoints: 2, hp: -18, failureModifier: 0 },
  gun: { label: '槍訓練', gains: { gun: 14, special: 5 }, skillPoints: 2, hp: -18, failureModifier: 0 },
  shield: { label: '盾訓練', gains: { shield: 14, gun: 5 }, skillPoints: 2, hp: -17, failureModifier: -0.02 },
  move: { label: '移訓練', gains: { move: 14, sword: 5 }, skillPoints: 2, hp: -19, failureModifier: 0.03 },
  special: { label: '特訓練', gains: { special: 12 }, skillPoints: 4, hp: 5, failureModifier: 0 },
};

export const SUPPORT_DATA = [
  { id: 'cc002', name: '艾依查庫', specialty: 'gun', statBonus: 4, skillBonus: 2, bondGain: 10, initialBond: 60 },
  { id: 'cc003', name: '古魯瓦爾多', specialty: 'sword', statBonus: 3, skillBonus: 1, bondGain: 9, initialBond: 25 },
  { id: 'cc004', name: '阿貝爾', specialty: 'move', statBonus: 3, skillBonus: 2, bondGain: 9, initialBond: 25 },
  { id: 'cc011', name: '雪莉', specialty: 'special', statBonus: 3, skillBonus: 2, bondGain: 8, initialBond: 30 },
  { id: 'cc012', name: '艾茵', specialty: 'shield', statBonus: 4, skillBonus: 1, bondGain: 8, initialBond: 30 },
];

export const SKILL_DATA = [
  {
    id: 'ex_precision', slot: 1, name: 'Ex精密射擊', ranges: ['middle', 'long'],
    description: '遠／中距離，提升一段時間競速速度。',
    effect: { phase: 'middle', trigger: 'precisionTarget', speed: 0.055, durationTicks: 5, cooldownTicks: 0, maxActivations: 1 },
    sourceRequirement: normalizeUlSkillRequirement({ require: [{ type: 1, quantity: 4, num: 0 }], range: [1, 2], phase: 0 }),
    unlock: mapTrainingRequirements(
      normalizeUlSkillRequirement({ require: [{ type: 1, quantity: 4, num: 0 }], range: [1, 2], phase: 0 }),
      { abilities: { gun: 180 }, characterRarity: 10 },
    ),
  },
  {
    id: 'ex_thunder', slot: 2, name: 'Ex雷擊', ranges: ['short'],
    description: '近距離，終盤超車時提高加速度。',
    effect: { phase: 'late', trigger: 'actualOvertake', acceleration: 0.22, durationTicks: 4, cooldownTicks: 0, maxActivations: 1 },
    sourceRequirement: normalizeUlSkillRequirement({ require: [{ type: 0, quantity: 4, num: 0 }, { type: 4, quantity: 2, num: 0 }], range: [0], phase: 0 }),
    unlock: mapTrainingRequirements(
      normalizeUlSkillRequirement({ require: [{ type: 0, quantity: 4, num: 0 }, { type: 4, quantity: 2, num: 0 }], range: [0], phase: 0 }),
      { abilities: { sword: 180, special: 130 }, characterRarity: 10 },
    ),
  },
  {
    id: 'ex_thorns', slot: 3, name: 'Ex茨林', ranges: ['middle', 'short'],
    description: '中／近距離，位置競爭時提升穩定度並短暫加速。',
    effect: { phase: 'late', trigger: 'contestOrBeingOvertaken', speed: 0.035, stability: 0.22, defense: 0.18, durationTicks: 5, cooldownTicks: 0, maxActivations: 1 },
    sourceRequirement: normalizeUlSkillRequirement({ require: [{ type: 2, quantity: 3, num: 0 }, { type: 4, quantity: 2, num: 0 }], range: [0, 1], phase: 1 }),
    unlock: mapTrainingRequirements(
      normalizeUlSkillRequirement({ require: [{ type: 2, quantity: 3, num: 0 }, { type: 4, quantity: 2, num: 0 }], range: [0, 1], phase: 1 }),
      { abilities: { shield: 160, special: 130 }, characterRarity: 10 },
    ),
  },
  {
    id: 'ex_strategy', slot: 4, name: 'Ex智略', ranges: ['short', 'middle', 'long'],
    description: '全距離，中盤改善跑位並降低額外體力消耗。',
    effect: { phase: 'middle', trigger: 'tacticalCorrection', speed: 0.025, staminaSave: 0.18, stability: 0.16, positioning: 0.22, durationTicks: 6, cooldownTicks: 0, maxActivations: 1 },
    sourceRequirement: normalizeUlSkillRequirement({ require: [{ type: 5, quantity: 1, num: 0 }, { type: 5, quantity: 1, num: 0 }], range: [0, 1, 2], phase: 2 }),
    unlock: mapTrainingRequirements(
      normalizeUlSkillRequirement({ require: [{ type: 5, quantity: 1, num: 0 }, { type: 5, quantity: 1, num: 0 }], range: [0, 1, 2], phase: 2 }),
      { abilities: { move: 150, special: 170 }, characterRarity: 10 },
    ),
  },
];

// 預留普通 → 強化 → Ex 與複合條件；v0.1 僅啟用 Ex 的 Lv1 能力覺醒。
export const SKILL_EVOLUTION_MODEL = {
  characterId: 'cc001',
  source: 'scripts/import/cc_asset.json',
  activeRarity: 10,
  tiers: ['normal', 'enhanced', 'ex'],
  futureConditions: ['ability', 'skillPoints', 'eventFlag', 'characterRarity', 'prerequisiteSkill'],
};

export const POINT_SKILL_DATA = [
  {
    id: 'field_footwork', type: 'generic', name: '戰場步法', cost: 90,
    ranges: ['short', 'middle', 'long'], description: '中盤調整步幅，小幅改善速度與跑位穩定。',
    effect: { phase: 'middle', trigger: 'tacticalCorrection', speed: 0.018, stability: 0.08, durationTicks: 4, cooldownTicks: 8, maxActivations: 2 },
  },
  {
    id: 'izac_cover', type: 'support', supportId: 'cc002', name: '艾依查庫的掩護', cost: 110,
    ranges: ['middle', 'long'], description: '位置競爭時由盟友掩護，降低續航消耗並保持速度。',
    effect: { phase: 'late', trigger: 'contestOrBeingOvertaken', speed: 0.018, staminaSave: 0.12, defense: 0.08, durationTicks: 4, cooldownTicks: 0, maxActivations: 1 },
  },
];

export const STRATEGIES = {
  sprint: { label: '疾走', pace: [1.045, 1.02, 0.98, 0.97], stamina: 1.12 },
  charge: { label: '突進', pace: [1.015, 1.025, 1.01, 1], stamina: 1.04 },
  stalk: { label: '伺機', pace: [0.985, 1.005, 1.035, 1.025], stamina: 0.96 },
  chase: { label: '追擊', pace: [0.965, 0.985, 1.05, 1.055], stamina: 0.93 },
};

export const DISTANCES = {
  short: { label: '近', meters: 1200, staminaFactor: 0.9 },
  middle: { label: '中', meters: 1800, staminaFactor: 1 },
  long: { label: '遠', meters: 2400, staminaFactor: 1.13 },
};

export const RACE_SCHEDULE = [
  { id: 'race1', label: '目標賽 I', afterTurn: 3, distance: 'short', targetRank: 5, rewardSkillPoints: 45, venue: '鋪修過的道路' },
  { id: 'race2', label: '目標賽 II', afterTurn: 7, distance: 'middle', targetRank: 3, rewardSkillPoints: 55, venue: '森林小道' },
  { id: 'race3', label: '目標賽 III', afterTurn: 11, distance: 'long', targetRank: 3, rewardSkillPoints: 70, venue: '牧神的絕峰' },
  { id: 'final', label: 'Final', afterTurn: 16, distance: null, targetRank: 1, rewardSkillPoints: 0, venue: '碧空的尖塔' },
];

export const OPPONENT_PRESETS = {
  race1: [87, 90, 92, 94, 96, 99, 102],
  race2: [101, 104, 107, 110, 113, 116, 119],
  race3: [114, 117, 120, 123, 126, 129, 132],
  final: [130, 134, 138, 142, 146, 150, 154],
};

export const FIXED_EVENTS = {
  opening: {
    speaker: '布勞', title: '暗房的邀請',
    lines: ['暗房的門扉在你面前開啟。布勞安靜地行了一禮。', '「這一次，請將那位帝國騎士的意志引向終點吧。」'],
    choices: [
      { label: '先掌握整體局勢', effects: { stats: { special: 5 }, skillPoints: 20 }, result: '艾伯先檢視了四場賽程。特 +5、Skill Pt +20。' },
      { label: '立刻確認進軍路線', effects: { stats: { move: 5 }, hp: 8 }, result: '行軍路線被迅速確定。移 +5、體力 +8。' },
    ],
  },
  tactics: {
    speaker: '艾伯李斯特', title: '艾伯的智略',
    lines: ['艾伯攤開地圖，逐一標記對手可能選擇的路線。', '「勝負在交鋒之前，就已經能夠被引導。」'],
    choices: [
      { label: '推演超車時機', effects: { stats: { sword: 6, special: 4 }, skillPoints: 25, hint: 'ex_thunder' }, result: '劍 +6、特 +4、Skill Pt +25，獲得 Ex雷擊 Hint。' },
      { label: '保留持久戰餘裕', effects: { stats: { shield: 8 }, hp: 15, hint: 'ex_strategy' }, result: '盾 +8、體力 +15，獲得 Ex智略 Hint。' },
    ],
  },
  izac: {
    speaker: '艾依查庫', title: '盟友的影子',
    lines: ['艾依查庫把武器扛上肩，像往常一樣站到艾伯身側。', '「你只管往前。我會把追上來的傢伙全部攔住。」'],
    choices: [
      { label: '並肩突破', effects: { stats: { gun: 7 }, skillPoints: 30, bonds: { cc002: 25 }, hint: 'ex_precision' }, result: '槍 +7、Skill Pt +30，艾依羈絆大幅上升。' },
      { label: '讓艾依掩護後方', effects: { stats: { shield: 6, move: 4 }, bonds: { cc002: 20 }, hint: 'ex_thorns' }, result: '盾 +6、移 +4，艾依羈絆上升。' },
    ],
  },
  final: {
    speaker: '艾伯李斯特', title: '決戰之前',
    lines: ['最後的戰場就在前方。艾伯扣緊手甲，目光沒有一絲游移。', '「意志決定方向，而智略讓我們抵達那裡。」'],
    choices: [
      { label: '以意志貫穿終點', effects: { stats: { sword: 7, move: 5 }, hp: -5, skillPoints: 25 }, result: '劍 +7、移 +5、Skill Pt +25、體力 -5。' },
      { label: '再確認一次全局', effects: { stats: { special: 9, shield: 4 }, hp: 8 }, result: '特 +9、盾 +4、體力 +8。' },
    ],
  },
};

export const EVENT_TURNS = { 1: 'opening', 4: 'tactics', 8: 'izac', 12: 'final' };

export const CONDITIONAL_EVENTS = [
  {
    id: 'precision_drill', requirements: [{ type: 'ability', stat: 'gun', value: 150 }],
    speaker: '艾伯李斯特', title: '精密射擊訓練',
    lines: ['艾伯反覆校準射線，直到每一道軌跡都落在預定位置。', '「精準並非天賦，而是排除所有多餘選擇後的結果。」'],
    choices: [
      { label: '縮短瞄準時間', effects: { stats: { gun: 6, special: 3 }, skillPoints: 15 }, result: '槍 +6、特 +3、Skill Pt +15。' },
      { label: '保持呼吸與節奏', effects: { stats: { gun: 4 }, hp: 12 }, result: '槍 +4、體力 +12。' },
    ],
  },
  {
    id: 'battle_simulation', requirements: [{ type: 'ability', stat: 'special', value: 150 }],
    speaker: '艾伯李斯特', title: '戰場推演',
    lines: ['棋子在地圖上幾度交換位置，艾伯仍能指出每一條被忽略的路線。', '「掌握戰場，不是預知結果，而是讓對手只剩下我們需要的選擇。」'],
    choices: [
      { label: '重演突破路線', effects: { stats: { sword: 5, move: 4 }, skillPoints: 20 }, result: '劍 +5、移 +4、Skill Pt +20。' },
      { label: '重新分配續航', effects: { stats: { shield: 6, special: 3 }, hp: 8 }, result: '盾 +6、特 +3、體力 +8。' },
    ],
  },
];

export const EVENT_DATA = {
  ...FIXED_EVENTS,
  ...Object.fromEntries(CONDITIONAL_EVENTS.map((event) => [event.id, event])),
};

export const FAILURE_BANDS = [
  { minHp: 70, rate: 0 },
  { minHp: 50, rate: 0.03 },
  { minHp: 30, rate: 0.08 },
  { minHp: 15, rate: 0.18 },
  { minHp: 0, rate: 0.35 },
];
