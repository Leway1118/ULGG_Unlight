import {
  CHARACTER_DATA,
  DISTANCES,
  POINT_SKILL_DATA,
  SKILL_DATA,
  STRATEGIES,
} from './config.js';
import { SeededRandom } from './random.js';

export const RACE_TICK_SECONDS = 2.4;
export const MAX_RACE_TICKS = 72;

const ALL_SKILLS = [...SKILL_DATA, ...POINT_SKILL_DATA];
const PHASE_LABELS = ['序盤', '中盤', '終盤', '衝刺'];
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
const round = (value, digits = 4) => Number(value.toFixed(digits));

function phaseIndex(position) {
  if (position < 0.2) return 0;
  if (position < 0.65) return 1;
  if (position < 0.85) return 2;
  return 3;
}

function targetRankRange(strategy) {
  return {
    sprint: [1, 2], charge: [2, 4], stalk: [4, 6], chase: [6, 8],
  }[strategy] ?? [3, 6];
}

function buildRuntimeRacer(racer, index, random) {
  const staminaMax = 64 + racer.stats.shield * 0.76;
  const aptitude = racer.isPlayer ? CHARACTER_DATA.cc001.strategyAptitude[racer.strategy] : 'A';
  return {
    ...racer,
    position: random.next() * 0.004,
    speed: 0,
    desiredSpeed: 0,
    rank: index + 1,
    previousRank: index + 1,
    stamina: staminaMax,
    staminaMax,
    aptitudeFactor: { A: 1, B: 0.992, C: 0.978 }[aptitude] ?? 0.97,
    phase: 0,
    lane: index % 3,
    blocked: false,
    blockedTicks: 0,
    contesting: null,
    overtaking: null,
    beingOvertakenTicks: 0,
    activeSkills: {},
    skillRuntime: {},
    finished: false,
    finishTime: null,
    sprintStarted: false,
    staminaLowEmitted: false,
  };
}

function rankRacers(racers) {
  const sorted = [...racers].sort((left, right) => {
    if (left.finished !== right.finished) return left.finished ? -1 : 1;
    if (left.finished && right.finished) return left.finishTime - right.finishTime;
    return right.position - left.position || left.id.localeCompare(right.id);
  });
  sorted.forEach((racer, index) => { racer.rank = index + 1; });
  return sorted;
}

function emit(events, type, racer, tick, extra = {}) {
  events.push({
    type, tick, elapsedSeconds: round(tick * RACE_TICK_SECONDS, 1),
    racerId: racer.id, racerName: racer.name, progress: round(clamp(racer.position, 0, 1)),
    phase: PHASE_LABELS[racer.phase], ...extra,
  });
}

function relationshipContext(racer, racers) {
  const ahead = racers
    .filter((other) => !other.finished && other.id !== racer.id && other.position > racer.position)
    .sort((left, right) => left.position - right.position);
  const nearestAhead = ahead[0] ?? null;
  const sameLaneAhead = ahead.find((other) => other.lane === racer.lane) ?? null;
  const nearest = racers
    .filter((other) => !other.finished && other.id !== racer.id)
    .map((other) => ({ racer: other, gap: Math.abs(other.position - racer.position) }))
    .sort((left, right) => left.gap - right.gap)[0] ?? null;
  return {
    nearestAhead,
    aheadGap: nearestAhead ? nearestAhead.position - racer.position : Infinity,
    sameLaneAhead,
    sameLaneGap: sameLaneAhead ? sameLaneAhead.position - racer.position : Infinity,
    contestTarget: nearest && nearest.gap <= 0.014 ? nearest.racer : null,
  };
}

function raceTriggerMet(skill, racer, context) {
  const trigger = skill.effect.trigger;
  if (trigger === 'precisionTarget') {
    return racer.phase === 1 && context.nearestAhead
      && context.aheadGap >= 0.006 && context.aheadGap <= 0.055
      && !racer.blocked && context.stability >= 0.68;
  }
  if (trigger === 'actualOvertake') {
    return racer.phase === 2 && Boolean(racer.overtaking);
  }
  if (trigger === 'contestOrBeingOvertaken') {
    return Boolean(racer.contesting) || racer.beingOvertakenTicks > 0;
  }
  if (trigger === 'tacticalCorrection') {
    const [minimum, maximum] = targetRankRange(racer.strategy);
    return racer.phase === 1 && (racer.blocked || racer.rank < minimum || racer.rank > maximum);
  }
  return false;
}

function updateSkillRuntime(racer, race, context, random, tick, events) {
  const combined = { speed: 0, acceleration: 0, staminaSave: 0, stability: 0, defense: 0, positioning: 0 };
  racer.learnedSkills.forEach((skillId) => {
    const skill = ALL_SKILLS.find((candidate) => candidate.id === skillId);
    if (!skill || !skill.ranges.includes(race.distance)) return;
    const runtime = racer.skillRuntime[skillId] ?? {
      remainingTicks: 0, cooldownRemaining: 0, activations: 0,
    };
    racer.skillRuntime[skillId] = runtime;
    const phaseMatches = (skill.effect.phase === 'middle' && racer.phase === 1)
      || (skill.effect.phase === 'late' && racer.phase >= 2);
    const mayActivate = runtime.remainingTicks === 0 && runtime.cooldownRemaining === 0
      && runtime.activations < skill.effect.maxActivations;
    if (phaseMatches && mayActivate && raceTriggerMet(skill, racer, context)) {
      const activationChance = clamp(0.78 + racer.stats.special * 0.0014, 0.8, 1);
      if (random.next() <= activationChance) {
        runtime.remainingTicks = skill.effect.durationTicks;
        runtime.activations += 1;
        emit(events, 'skillActivated', racer, tick, {
          skillId, skillName: skill.name, trigger: skill.effect.trigger,
          durationTicks: skill.effect.durationTicks,
        });
      }
    }
    if (runtime.remainingTicks > 0) {
      Object.keys(combined).forEach((key) => { combined[key] += Number(skill.effect[key] ?? 0); });
      racer.activeSkills[skillId] = { remainingTicks: runtime.remainingTicks, activations: runtime.activations };
    }
  });
  return combined;
}

function settleSkillRuntime(racer, tick, events) {
  Object.entries(racer.skillRuntime).forEach(([skillId, runtime]) => {
    if (runtime.remainingTicks > 0) {
      runtime.remainingTicks -= 1;
      if (runtime.remainingTicks === 0) {
        const skill = ALL_SKILLS.find((candidate) => candidate.id === skillId);
        runtime.cooldownRemaining = skill?.effect.cooldownTicks ?? 0;
        delete racer.activeSkills[skillId];
        emit(events, 'skillExpired', racer, tick, { skillId, skillName: skill?.name ?? skillId });
      } else if (racer.activeSkills[skillId]) {
        racer.activeSkills[skillId].remainingTicks = runtime.remainingTicks;
      }
    } else if (runtime.cooldownRemaining > 0) {
      runtime.cooldownRemaining -= 1;
    }
  });
}

function laneIsOpen(racer, racers, lane) {
  return !racers.some((other) => other.id !== racer.id && !other.finished
    && other.lane === lane && Math.abs(other.position - racer.position) < 0.022);
}

function activeEffectValue(racer, key) {
  if (!racer) return 0;
  return Object.keys(racer.activeSkills).reduce((sum, skillId) => {
    const skill = ALL_SKILLS.find((candidate) => candidate.id === skillId);
    return sum + Number(skill?.effect[key] ?? 0);
  }, 0);
}

function snapshotRacer(racer) {
  return {
    id: racer.id, name: racer.name, isPlayer: racer.isPlayer,
    position: round(clamp(racer.position, 0, 1)), speed: round(racer.speed, 5), rank: racer.rank,
    stamina: round(Math.max(0, racer.stamina), 1), staminaRatio: round(Math.max(0, racer.stamina) / racer.staminaMax, 3),
    phase: PHASE_LABELS[racer.phase], strategy: racer.strategy, lane: racer.lane,
    blocked: racer.blocked, contesting: racer.contesting, overtaking: racer.overtaking?.targetId ?? null,
    activeSkills: Object.fromEntries(Object.entries(racer.activeSkills).map(([id, runtime]) => [id, { ...runtime }])),
    cooldowns: Object.fromEntries(Object.entries(racer.skillRuntime).map(([id, runtime]) => [id, runtime.cooldownRemaining])),
    finished: racer.finished,
  };
}

export function simulateRace(racerInputs, race, seed) {
  if (!Array.isArray(racerInputs) || racerInputs.length !== 8) throw new Error('Race 必須正好有 8 名角色');
  const random = new SeededRandom(seed);
  const racers = racerInputs.map((racer, index) => buildRuntimeRacer(racer, index, random));
  rankRacers(racers);
  const timeline = [{
    tick: 0, elapsedSeconds: 0, phase: '序盤', leaderProgress: 0,
    racers: racers.map(snapshotRacer), events: [],
  }];
  const events = [];

  for (let tick = 1; tick <= MAX_RACE_TICKS && racers.some((racer) => !racer.finished); tick += 1) {
    const tickEvents = [];
    rankRacers(racers);
    const previousPositions = Object.fromEntries(racers.map((racer) => [racer.id, racer.position]));
    const previousRanks = Object.fromEntries(racers.map((racer) => [racer.id, racer.rank]));
    const contexts = new Map();

    racers.forEach((racer) => {
      if (racer.finished) return;
      racer.previousRank = racer.rank;
      racer.phase = phaseIndex(racer.position);
      if (racer.phase === 3 && !racer.sprintStarted) {
        racer.sprintStarted = true;
        emit(tickEvents, 'sprintStarted', racer, tick);
      }
      const relation = relationshipContext(racer, racers);
      const baseStability = clamp(0.44 + racer.stats.special / 300, 0.45, 0.96);
      const wasBlocked = racer.blocked;
      racer.blocked = Boolean(relation.sameLaneAhead && relation.sameLaneGap < 0.019
        && racer.speed >= relation.sameLaneAhead.speed * 0.94);
      if (racer.blocked) {
        const alternateLanes = [0, 1, 2].filter((lane) => lane !== racer.lane && laneIsOpen(racer, racers, lane));
        const repositionChance = clamp(0.04 + racer.stats.special * 0.00145, 0.08, 0.48);
        if (alternateLanes.length && random.next() < repositionChance) {
          racer.lane = alternateLanes[random.int(0, alternateLanes.length - 1)];
          racer.blocked = false;
        }
      }
      if (racer.blocked) {
        racer.blockedTicks += 1;
        if (!wasBlocked) emit(tickEvents, 'blocked', racer, tick, { targetId: relation.sameLaneAhead.id, targetName: relation.sameLaneAhead.name });
      }
      const previousContest = racer.contesting;
      racer.contesting = relation.contestTarget?.id ?? null;
      if (racer.contesting && previousContest !== racer.contesting) {
        emit(tickEvents, 'positionContest', racer, tick, { targetId: relation.contestTarget.id, targetName: relation.contestTarget.name });
      }

      const maxSpeed = 0.0178 + racer.stats.move * 0.000031;
      const pace = STRATEGIES[racer.strategy].pace[racer.phase];
      const lateChase = racer.phase >= 2 && racer.rank > 1 && relation.nearestAhead
        ? 1 + Math.max(0, racer.stats.gun - 75) * 0.00072 : 1;
      const preliminaryDesired = maxSpeed * pace * lateChase * racer.aptitudeFactor;
      const canAttemptOvertake = relation.nearestAhead && relation.aheadGap < 0.045
        && preliminaryDesired > relation.nearestAhead.speed * 1.015;
      if (canAttemptOvertake && (!racer.overtaking || racer.overtaking.targetId !== relation.nearestAhead.id)) {
        racer.overtaking = { targetId: relation.nearestAhead.id, age: 0, startTick: tick };
        emit(tickEvents, 'overtakeStarted', racer, tick, { targetId: relation.nearestAhead.id, targetName: relation.nearestAhead.name });
      }
      contexts.set(racer.id, { ...relation, stability: baseStability });
    });

    racers.forEach((racer) => {
      if (racer.finished) return;
      const context = contexts.get(racer.id);
      const skill = updateSkillRuntime(racer, race, context, random, tick, tickEvents);
      const stability = clamp(context.stability + skill.stability, 0.45, 1.18);
      const maxSpeed = 0.0178 + racer.stats.move * 0.000031;
      const pace = STRATEGIES[racer.strategy].pace[racer.phase];
      const chase = racer.phase >= 2 && racer.rank > 1 && context.nearestAhead
        ? 1 + Math.max(0, racer.stats.gun - 75) * 0.00072 : 1;
      const randomVariance = (random.next() - 0.5) * (0.055 - Math.min(1, stability) * 0.042);
      let desiredSpeed = maxSpeed * pace * chase * racer.aptitudeFactor * (1 + skill.speed + randomVariance);
      const staminaRatio = racer.stamina / racer.staminaMax;
      if (staminaRatio < 0.22) desiredSpeed *= clamp(0.72 + staminaRatio * 1.15, 0.7, 0.97);
      const acceleration = clamp(0.16 + racer.stats.sword * 0.0017 + skill.acceleration, 0.2, 0.72);
      racer.speed += (desiredSpeed - racer.speed) * acceleration;

      if (racer.blocked && context.sameLaneAhead) {
        const breakthroughChance = clamp(
          0.08 + (racer.stats.sword - context.sameLaneAhead.stats.sword) * 0.004
          + racer.stats.special * 0.0008 + skill.acceleration
          - activeEffectValue(context.sameLaneAhead, 'defense'),
          0.04, 0.88,
        );
        if (random.next() >= breakthroughChance) {
          racer.speed = Math.min(racer.speed, context.sameLaneAhead.speed * 0.985);
        } else {
          racer.blocked = false;
          const openLane = [0, 1, 2].find((lane) => lane !== racer.lane && laneIsOpen(racer, racers, lane));
          if (openLane !== undefined) racer.lane = openLane;
        }
      }
      racer.desiredSpeed = desiredSpeed;
      const staminaEfficiency = clamp(racer.stats.special * 0.00058 + skill.staminaSave, 0, 0.38);
      const staminaCost = 2.05 * DISTANCES[race.distance].staminaFactor
        * STRATEGIES[racer.strategy].stamina * (1 - staminaEfficiency);
      racer.stamina -= staminaCost;
      racer.position += Math.max(0.006, racer.speed);
      if (racer.stamina / racer.staminaMax < 0.16 && !racer.staminaLowEmitted) {
        racer.staminaLowEmitted = true;
        emit(tickEvents, 'staminaLow', racer, tick);
      }
    });

    rankRacers(racers);
    racers.forEach((racer) => {
      if (racer.finished) return;
      const overtake = racer.overtaking;
      if (overtake) {
        const target = racers.find((candidate) => candidate.id === overtake.targetId);
        if (target && previousPositions[racer.id] <= previousPositions[target.id]
          && racer.position > target.position) {
          emit(tickEvents, 'overtakeSucceeded', racer, tick, { targetId: target.id, targetName: target.name });
          target.beingOvertakenTicks = 2;
          racer.overtaking = null;
        } else {
          overtake.age += 1;
          if (!target || overtake.age >= 3 || target.position - racer.position > 0.055) {
            emit(tickEvents, 'overtakeFailed', racer, tick, { targetId: target?.id ?? overtake.targetId, targetName: target?.name ?? '' });
            racer.overtaking = null;
          }
        }
      }
      if (racer.rank !== previousRanks[racer.id]) {
        emit(tickEvents, 'rankChanged', racer, tick, { fromRank: previousRanks[racer.id], toRank: racer.rank });
      }
      if (racer.position >= 1) {
        const previous = previousPositions[racer.id];
        const fraction = clamp((1 - previous) / Math.max(0.0001, racer.position - previous), 0, 1);
        racer.finishTime = round(((tick - 1) + fraction) * RACE_TICK_SECONDS, 3);
        racer.position = 1;
        racer.finished = true;
        emit(tickEvents, 'finish', racer, tick, { finishTime: racer.finishTime });
      }
      racer.beingOvertakenTicks = Math.max(0, racer.beingOvertakenTicks - 1);
      settleSkillRuntime(racer, tick, tickEvents);
    });
    rankRacers(racers);
    events.push(...tickEvents);
    const leaderProgress = Math.max(...racers.map((racer) => racer.position));
    timeline.push({
      tick, elapsedSeconds: round(tick * RACE_TICK_SECONDS, 1),
      phase: PHASE_LABELS[phaseIndex(leaderProgress)], leaderProgress: round(clamp(leaderProgress, 0, 1)),
      racers: racers.map(snapshotRacer), events: tickEvents.map((event) => ({ ...event })),
    });
  }

  const ranked = rankRacers(racers);
  ranked.forEach((racer) => {
    if (!racer.finished) {
      racer.finishTime = round(MAX_RACE_TICKS * RACE_TICK_SECONDS + (1 - racer.position) / Math.max(0.006, racer.speed) * RACE_TICK_SECONDS, 3);
    }
  });
  ranked.sort((left, right) => left.finishTime - right.finishTime).forEach((racer, index) => { racer.rank = index + 1; });
  const results = ranked.map((racer) => ({
    id: racer.id, name: racer.name, isPlayer: racer.isPlayer, rank: racer.rank,
    finishTime: racer.finishTime, remainingStamina: round(Math.max(0, racer.stamina), 1),
    activatedSkills: Object.entries(racer.skillRuntime)
      .filter(([, runtime]) => runtime.activations > 0).map(([id]) => ALL_SKILLS.find((skill) => skill.id === id)?.name ?? id),
  }));
  return { tickSeconds: RACE_TICK_SECONDS, timeline, events, results };
}

export function analyzeSharedRace(simulation, playerId = 'player') {
  const playerResult = simulation.results.find((result) => result.id === playerId);
  const playerEvents = simulation.events.filter((event) => event.racerId === playerId);
  const playerFrames = simulation.timeline.map((frame) => frame.racers.find((racer) => racer.id === playerId)).filter(Boolean);
  const blockedTicks = playerFrames.filter((frame) => frame.blocked).length;
  const failedOvertakes = playerEvents.filter((event) => event.type === 'overtakeFailed').length;
  const successfulOvertakes = playerEvents.filter((event) => event.type === 'overtakeSucceeded').length;
  const contests = playerEvents.filter((event) => event.type === 'positionContest').length;
  const staminaLow = playerEvents.some((event) => event.type === 'staminaLow');
  const lateFrames = playerFrames.filter((frame) => frame.position >= 0.65);
  const lateStartRank = lateFrames[0]?.rank ?? playerResult.rank;
  const peakSpeed = Math.max(...playerFrames.map((frame) => frame.speed));
  const opponentPeakAverage = simulation.timeline.reduce((sum, frame) => {
    const opponents = frame.racers.filter((racer) => racer.id !== playerId);
    return sum + Math.max(...opponents.map((racer) => racer.speed));
  }, 0) / simulation.timeline.length;
  const observations = [];
  const suggestions = new Set();

  if (blockedTicks >= 3) {
    observations.push({ tone: 'warning', text: `被阻擋 ${blockedTicks} ticks，跑位與突破受到壓制。` });
    suggestions.add('special'); suggestions.add('sword');
  }
  if (failedOvertakes > 0) {
    observations.push({ tone: 'warning', text: `${failedOvertakes} 次超車失敗。` });
    suggestions.add('sword');
  }
  if (successfulOvertakes > 0) observations.push({ tone: 'good', text: `成功完成 ${successfulOvertakes} 次超車。` });
  if (contests > 0) observations.push({ tone: 'neutral', text: `發生 ${contests} 次位置競爭。` });
  if (staminaLow || playerResult.remainingStamina <= 2) {
    observations.push({ tone: 'warning', text: '最後衝刺時續航不足，出現明顯疲勞。' });
    suggestions.add('shield');
  }
  if (lateStartRank <= playerResult.rank && playerResult.rank > 1) {
    observations.push({ tone: 'warning', text: '終盤未能拉近與前方角色的距離。' });
    suggestions.add('gun');
  }
  if (peakSpeed < opponentPeakAverage * 0.96) {
    observations.push({ tone: 'warning', text: '全程最高速度低於領先集團。' });
    suggestions.add('move');
  }
  playerEvents.filter((event) => event.type === 'skillActivated').forEach((event) => {
    observations.push({ tone: 'good', text: `${event.skillName} 在${event.phase}成功發動。` });
  });
  if (!observations.length) observations.push({ tone: 'good', text: '整體跑位穩定，能力配置成功轉化為比賽表現。' });
  return {
    metrics: { blockedTicks, failedOvertakes, successfulOvertakes, contests, staminaLow, lateStartRank, finalRank: playerResult.rank },
    observations,
    suggestions: [...suggestions],
    reasons: observations.filter((item) => item.tone === 'warning').map((item) => item.text),
  };
}
