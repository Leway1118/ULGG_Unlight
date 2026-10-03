import {
  CHARACTER_DATA,
  CONDITIONAL_EVENTS,
  DISTANCES,
  EVENT_DATA,
  EVENT_TURNS,
  FAILURE_BANDS,
  OPPONENT_PRESETS,
  POINT_SKILL_DATA,
  RACE_SCHEDULE,
  SKILL_DATA,
  STAT_KEYS,
  STRATEGIES,
  SUPPORT_DATA,
  TRAINING_DATA,
} from './config.js';
import { SeededRandom } from './random.js';
import { analyzeSharedRace, simulateRace } from './race-engine.js';

const clone = (value) => JSON.parse(JSON.stringify(value));
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

function stateRandom(state) {
  const random = new SeededRandom('state');
  random.state = state.rngState >>> 0;
  return random;
}

function saveRandom(state, random) {
  state.rngState = random.snapshot();
}

export function assignSupports(state) {
  const next = clone(state);
  const random = stateRandom(next);
  const placements = Object.fromEntries(STAT_KEYS.map((key) => [key, []]));

  SUPPORT_DATA.forEach((support) => {
    const training = random.pick(STAT_KEYS);
    placements[training].push(support.id);
  });

  next.supportPlacements = placements;
  saveRandom(next, random);
  return next;
}

export function createGame(seed = `${Date.now()}`) {
  const random = new SeededRandom(seed);
  const finalDistance = random.pick(Object.keys(DISTANCES));
  const state = {
    version: 3,
    seed: String(seed),
    rngState: random.snapshot(),
    characterId: 'cc001',
    characterRarity: 10,
    phase: 'training',
    turn: 1,
    hp: 100,
    stats: clone(CHARACTER_DATA.cc001.stats),
    skillPoints: 0,
    learnedSkills: [],
    awakeningQueue: [],
    skillHints: [],
    bonds: Object.fromEntries(SUPPORT_DATA.map((support) => [support.id, support.initialBond])),
    supportPlacements: {},
    completedRaces: [],
    raceIndex: 0,
    strategy: 'charge',
    finalDistance,
    pendingEvent: EVENT_TURNS[1],
    seenEvents: [],
    history: [],
    lastRace: null,
    result: null,
  };
  return assignSupports(state);
}

export function failureRate(hp, trainingKey) {
  const band = FAILURE_BANDS.find((item) => hp >= item.minHp) ?? FAILURE_BANDS.at(-1);
  return clamp(band.rate + (TRAINING_DATA[trainingKey]?.failureModifier ?? 0), 0, 0.95);
}

export function getTrainingPreview(state, trainingKey) {
  const training = TRAINING_DATA[trainingKey];
  if (!training) return null;
  const supports = (state.supportPlacements[trainingKey] ?? [])
    .map((id) => SUPPORT_DATA.find((support) => support.id === id))
    .filter(Boolean);
  const friendships = supports.filter(
    (support) => (state.bonds[support.id] ?? 0) >= 80 && support.specialty === trainingKey,
  );
  const gains = {};
  Object.entries(training.gains).forEach(([stat, amount]) => {
    const growth = CHARACTER_DATA.cc001.growth[stat] ?? 0;
    gains[stat] = Math.round(amount * (1 + growth));
  });
  const supportStatBonus = supports.reduce((sum, support) => sum + support.statBonus, 0);
  const friendshipStatBonus = friendships.length * 8;
  const primary = Object.keys(training.gains)[0];
  gains[primary] += supportStatBonus + friendshipStatBonus;

  const projectedState = clone(state);
  Object.entries(gains).forEach(([stat, amount]) => { projectedState.stats[stat] += amount; });
  const awakeningSkills = SKILL_DATA.filter((skill) => !state.learnedSkills.includes(skill.id)
    && !getSkillRequirementProgress(state, skill).met
    && getSkillRequirementProgress(projectedState, skill).met)
    .map((skill) => ({ id: skill.id, name: skill.name }));

  return {
    ...training,
    gains,
    supports,
    friendships,
    skillPoints: training.skillPoints
      + supports.reduce((sum, support) => sum + support.skillBonus, 0)
      + friendships.length * 3,
    failureRate: failureRate(state.hp, trainingKey),
    awakeningSkills,
  };
}

function conditionalRequirementsMet(state, event) {
  return event.requirements.every((requirement) => {
    if (requirement.type === 'ability') return (state.stats[requirement.stat] ?? 0) >= requirement.value;
    if (requirement.type === 'eventFlag') return state.seenEvents.includes(requirement.value);
    return false;
  });
}

function triggerTurnEvent(state) {
  const eventId = EVENT_TURNS[state.turn];
  if (eventId && !state.seenEvents.includes(eventId)) {
    state.pendingEvent = eventId;
    return;
  }
  const conditional = CONDITIONAL_EVENTS.find((event) => !state.seenEvents.includes(event.id)
    && conditionalRequirementsMet(state, event));
  if (conditional) state.pendingEvent = conditional.id;
}

function finishTurn(state, summary) {
  state.history.unshift({ turn: state.turn, ...summary });
  const scheduledRaceIndex = RACE_SCHEDULE.findIndex((race) => race.afterTurn === state.turn);
  if (scheduledRaceIndex >= 0) {
    state.phase = 'preRace';
    state.raceIndex = scheduledRaceIndex;
    return state;
  }
  state.turn += 1;
  const withSupports = assignSupports(state);
  triggerTurnEvent(withSupports);
  return withSupports;
}

export function performTraining(state, trainingKey) {
  if (state.phase !== 'training' || state.pendingEvent) throw new Error('目前不能進行訓練');
  const preview = getTrainingPreview(state, trainingKey);
  if (!preview) throw new Error('未知的訓練');
  const next = clone(state);
  const random = stateRandom(next);
  const failed = random.next() < preview.failureRate;
  saveRandom(next, random);

  if (failed) {
    const cost = Math.max(0, -preview.hp);
    next.hp = clamp(next.hp - Math.ceil(cost * 0.5), 0, 100);
    return finishTurn(next, {
      type: 'training', key: trainingKey, success: false,
      text: `${preview.label}失敗，能力沒有提升。`,
    });
  }

  Object.entries(preview.gains).forEach(([stat, amount]) => { next.stats[stat] += amount; });
  next.skillPoints += preview.skillPoints;
  next.hp = clamp(next.hp + preview.hp, 0, 100);
  preview.supports.forEach((support) => {
    next.bonds[support.id] = clamp((next.bonds[support.id] ?? 0) + support.bondGain, 0, 100);
  });
  const friendshipText = preview.friendships.length ? '，友情訓練發動' : '';
  const awakened = checkSkillAwakenings(next);
  return finishTurn(awakened, {
    type: 'training', key: trainingKey, success: true,
    text: `${preview.label}成功${friendshipText}。`,
  });
}

export function rest(state) {
  if (state.phase !== 'training' || state.pendingEvent) throw new Error('目前不能休息');
  const next = clone(state);
  const random = stateRandom(next);
  const recovery = random.int(30, 50);
  next.hp = clamp(next.hp + recovery, 0, 100);
  saveRandom(next, random);
  return finishTurn(next, { type: 'rest', success: true, text: `休息恢復 ${recovery} 體力。` });
}

export function applyEventChoice(state, choiceIndex) {
  if (!state.pendingEvent) throw new Error('沒有待處理事件');
  const eventId = state.pendingEvent;
  const event = EVENT_DATA[eventId];
  const choice = event?.choices[choiceIndex];
  if (!choice) throw new Error('未知的事件選項');
  const next = clone(state);
  const effects = choice.effects;
  Object.entries(effects.stats ?? {}).forEach(([stat, amount]) => { next.stats[stat] += amount; });
  next.hp = clamp(next.hp + (effects.hp ?? 0), 0, 100);
  next.skillPoints += effects.skillPoints ?? 0;
  Object.entries(effects.bonds ?? {}).forEach(([id, amount]) => {
    next.bonds[id] = clamp((next.bonds[id] ?? 0) + amount, 0, 100);
  });
  if (effects.hint && !next.skillHints.includes(effects.hint)) next.skillHints.push(effects.hint);
  next.seenEvents.push(eventId);
  next.history.unshift({ turn: next.turn, type: 'event', success: true, text: choice.result });
  next.pendingEvent = null;
  const awakened = checkSkillAwakenings(next);
  return awakened;
}

export function getSkillRequirementProgress(state, skill) {
  const abilityProgress = skill.unlock.requirements.map((requirement) => {
    const current = Number(state.stats[requirement.stat] ?? 0);
    return { ...requirement, current, missing: Math.max(0, requirement.value - current), met: current >= requirement.value };
  });
  const extraProgress = [
    { type: 'skillPoints', current: state.skillPoints, value: skill.unlock.skillPoints, met: state.skillPoints >= skill.unlock.skillPoints },
    { type: 'characterRarity', current: state.characterRarity, value: skill.unlock.characterRarity, met: state.characterRarity >= skill.unlock.characterRarity },
    { type: 'eventFlag', value: skill.unlock.eventFlag, met: !skill.unlock.eventFlag || state.seenEvents.includes(skill.unlock.eventFlag) },
    { type: 'prerequisiteSkill', value: skill.unlock.prerequisiteSkill, met: !skill.unlock.prerequisiteSkill || state.learnedSkills.includes(skill.unlock.prerequisiteSkill) },
  ];
  return {
    abilityProgress,
    extraProgress,
    met: [...abilityProgress, ...extraProgress].every((requirement) => requirement.met),
  };
}

export function checkSkillAwakenings(state) {
  const next = clone(state);
  if (!Array.isArray(next.awakeningQueue)) next.awakeningQueue = [];
  SKILL_DATA.forEach((skill) => {
    if (next.learnedSkills.includes(skill.id)) return;
    if (!getSkillRequirementProgress(next, skill).met) return;
    next.learnedSkills.push(skill.id);
    next.awakeningQueue.push(skill.id);
    next.history.unshift({ turn: next.turn, type: 'awakening', success: true, text: `條件達成！${skill.name} 習得。` });
  });
  return next;
}

export function buyPointSkill(state, skillId) {
  const skill = POINT_SKILL_DATA.find((item) => item.id === skillId);
  if (!skill) throw new Error('未知的泛用／支援技能');
  if (state.learnedSkills.includes(skillId)) throw new Error('已習得此技能');
  if (state.skillPoints < skill.cost) throw new Error('Skill Pt 不足');
  const next = clone(state);
  next.skillPoints -= skill.cost;
  next.learnedSkills.push(skill.id);
  next.history.unshift({ turn: next.turn, type: 'skill', success: true, text: `以 Skill Pt 習得 ${skill.name}。` });
  return next;
}

export function currentRace(state) {
  const race = clone(RACE_SCHEDULE[state.raceIndex]);
  if (race.id === 'final') race.distance = state.finalDistance;
  return race;
}

function makeOpponent(rating, index) {
  return {
    id: `npc-${index + 1}`,
    name: ['無名騎士', '影之侍從', '帝國斥候', '暮色獵手', '銀翼槍士', '荒野傭兵', '黑鎧先鋒'][index],
    stats: {
      sword: rating + (index % 3) * 2,
      gun: rating + ((index + 1) % 3) * 2,
      shield: rating + ((index + 2) % 3) * 2,
      move: rating + (index % 2) * 3,
      special: rating + ((index + 1) % 2) * 3,
    },
    learnedSkills: [],
    strategy: Object.keys(STRATEGIES)[index % 4],
    isPlayer: false,
  };
}

export function runCurrentRace(state, strategy = state.strategy) {
  if (state.phase !== 'preRace') throw new Error('目前沒有待進行的比賽');
  if (!STRATEGIES[strategy]) throw new Error('未知的跑法');
  const next = clone(state);
  const random = stateRandom(next);
  const raceSeed = random.snapshot();
  random.next();
  saveRandom(next, random);
  next.strategy = strategy;
  const race = currentRace(next);
  const racers = [
    {
      id: 'player', name: CHARACTER_DATA.cc001.name, stats: clone(next.stats),
      learnedSkills: [...next.learnedSkills], strategy, isPlayer: true,
    },
    ...OPPONENT_PRESETS[race.id].map(makeOpponent),
  ];
  const simulation = simulateRace(racers, race, String(raceSeed));
  const analysis = analyzeSharedRace(simulation, 'player');
  const player = simulation.results.find((result) => result.isPlayer);
  const passed = player.rank <= race.targetRank;
  const output = {
    race, rank: player.rank, finishTime: player.finishTime,
    tickSeconds: simulation.tickSeconds, timeline: simulation.timeline, events: simulation.events,
    remainingStamina: player.remainingStamina, activatedSkills: player.activatedSkills,
    analysis, analysisReasons: analysis.reasons.length ? analysis.reasons : analysis.observations.map((item) => item.text),
    results: simulation.results, passed,
  };
  next.lastRace = output;
  next.phase = 'racePlayback';
  return next;
}

export function completeRacePlayback(state) {
  if (state.phase !== 'racePlayback' || !state.lastRace) throw new Error('沒有可完成播放的比賽');
  const next = clone(state);
  next.phase = 'raceResult';
  return next;
}

export function continueAfterRace(state) {
  if (state.phase !== 'raceResult' || !state.lastRace) throw new Error('沒有可結算的比賽');
  const next = clone(state);
  const race = next.lastRace.race;
  if (!next.lastRace.passed) {
    next.phase = 'result';
    next.result = { success: false, title: '育成失敗', failedRace: race.label, analysis: next.lastRace.analysis, analysisReasons: next.lastRace.analysisReasons };
    return next;
  }
  next.completedRaces.push(race.id);
  next.skillPoints += race.rewardSkillPoints;
  if (race.id === 'final') {
    next.phase = 'result';
    next.result = { success: true, title: '育成成功', analysis: next.lastRace.analysis, analysisReasons: next.lastRace.analysisReasons };
    return checkSkillAwakenings(next);
  }
  next.turn = race.afterTurn + 1;
  next.raceIndex += 1;
  next.phase = 'training';
  next.lastRace = null;
  const withSupports = assignSupports(checkSkillAwakenings(next));
  triggerTurnEvent(withSupports);
  return withSupports;
}

export function getOverallRank(state) {
  const average = STAT_KEYS.reduce((sum, key) => sum + state.stats[key], 0) / STAT_KEYS.length;
  if (average >= 180) return 'S';
  if (average >= 155) return 'A';
  if (average >= 135) return 'B';
  if (average >= 115) return 'C';
  return 'D';
}
