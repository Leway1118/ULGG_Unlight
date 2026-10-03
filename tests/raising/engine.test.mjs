import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

import { POINT_SKILL_DATA, ROSTER, SKILL_DATA, STAT_KEYS, SUPPORT_DATA } from '../../assets/js/raising/config.js';
import {
  applyEventChoice,
  assignSupports,
  buyPointSkill,
  checkSkillAwakenings,
  completeRacePlayback,
  continueAfterRace,
  createGame,
  failureRate,
  getTrainingPreview,
  performTraining,
  rest,
  runCurrentRace,
} from '../../assets/js/raising/engine.js';
import { RaisingRepository } from '../../assets/js/raising/persistence.js';
import { simulateRace } from '../../assets/js/raising/race-engine.js';
import { extractCharacterSkillEvolution } from '../../assets/js/raising/ul-skill-adapter.js';

function clearEvent(state, choice = 0) {
  return state.pendingEvent ? applyEventChoice(state, choice) : state;
}

const fixtureStats = (sword, gun, shield, move, special) => ({ sword, gun, shield, move, special });

function sharedRaceFixture({
  playerStats = fixtureStats(150, 140, 140, 125, 200),
  learnedSkills = [], strategy = 'charge', opponentMove = 124,
} = {}) {
  return [
    { id: 'player', name: '艾伯李斯特', stats: playerStats, learnedSkills, strategy, isPlayer: true },
    ...Array.from({ length: 7 }, (_, index) => ({
      id: `fixture-${index}`, name: `對手 ${index + 1}`,
      stats: fixtureStats(120, 120, 130, opponentMove + (index % 2), 120),
      learnedSkills: [], strategy: ['sprint', 'charge', 'stalk', 'chase'][index % 4], isPlayer: false,
    })),
  ];
}

test('首發角色 ID 與 charaProfile mapping 一致', () => {
  assert.deepEqual(
    ROSTER.map(({ id, name }) => [id, name]),
    [
      ['cc001', '艾伯李斯特'], ['cc003', '古魯瓦爾多'], ['cc004', '阿貝爾'],
      ['cc002', '艾依查庫'], ['cc011', '雪莉'], ['cc012', '艾茵'],
    ],
  );
  assert.ok(SUPPORT_DATA.some((support) => support.id === 'cc011' && support.name === '雪莉'));
  assert.ok(SUPPORT_DATA.some((support) => support.id === 'cc012' && support.name === '艾茵'));
});

test('cc_asset adapter 正規化艾伯 R5 原作 requirement 與進化順序', async () => {
  const raw = JSON.parse(await readFile(new URL('../../scripts/import/cc_asset.json', import.meta.url), 'utf8'));
  const evolution = extractCharacterSkillEvolution(raw.frames, 'cc001');
  assert.equal(evolution.length, 10);
  assert.deepEqual(evolution.map((stage) => stage.filename), [
    'cc001_01', 'cc001_02', 'cc001_03', 'cc001_04', 'cc001_05',
    'cc001_r01', 'cc001_r02', 'cc001_r03', 'cc001_r04', 'cc001_r05',
  ]);
  const r5 = evolution.at(-1);
  assert.deepEqual(r5.skills.map((skill) => skill.name), ['Ex精密射擊', 'Ex雷擊', 'Ex茨林', 'Ex智略']);
  assert.deepEqual(r5.skills[0].requirement.abilities, { gun: 4 });
  assert.deepEqual(r5.skills[1].requirement.abilities, { sword: 4, special: 2 });
  assert.deepEqual(r5.skills[2].requirement.abilities, { shield: 3, special: 2 });
  assert.deepEqual(r5.skills[3].requirement.ranges, ['short', 'middle', 'long']);
  assert.equal(r5.skills[3].requirement.phase, 'move');
});

test('同一 seed 產生相同 Final 距離與支援配置', () => {
  const first = createGame('replay-seed');
  const second = createGame('replay-seed');
  assert.equal(first.finalDistance, second.finalDistance);
  assert.deepEqual(first.supportPlacements, second.supportPlacements);
  assert.equal(first.rngState, second.rngState);
});

test('失敗率符合體力區間及移盾修正', () => {
  assert.equal(failureRate(70, 'sword'), 0);
  assert.equal(failureRate(69, 'sword'), 0.03);
  assert.equal(failureRate(49, 'sword'), 0.08);
  assert.equal(failureRate(29, 'sword'), 0.18);
  assert.equal(failureRate(14, 'sword'), 0.35);
  assert.equal(failureRate(40, 'move'), 0.11);
  assert.equal(failureRate(40, 'shield'), 0.06);
});

test('事件不消耗 Turn，Skill Pt 保留為獨立長期資源', () => {
  let state = createGame('event-seed');
  state = applyEventChoice(state, 0);
  assert.equal(state.turn, 1);
  assert.equal(state.stats.special, 115);
  assert.equal(state.skillPoints, 20);

  assert.deepEqual(state.learnedSkills, []);
  assert.equal(state.turn, 1);
});

test('能力跨越門檻時自動覺醒，並加入一次性演出 queue', () => {
  let state = clearEvent(createGame('awakening-seed'));
  state.stats.gun = 179;
  state.supportPlacements = { sword: [], gun: [], shield: [], move: [], special: [] };
  state = performTraining(state, 'gun');
  assert.ok(state.stats.gun >= 180);
  assert.deepEqual(state.learnedSkills, ['ex_precision']);
  assert.deepEqual(state.awakeningQueue, ['ex_precision']);
  assert.match(state.history[0].text + state.history[1].text, /條件達成！Ex精密射擊 習得/);
});

test('複合條件必須全部達成，Skill Pt 不會解鎖角色原生技能', () => {
  let state = clearEvent(createGame('compound-seed'));
  state.skillPoints = 9999;
  state.stats.sword = 180;
  state.stats.special = 129;
  state = checkSkillAwakenings(state);
  assert.equal(state.learnedSkills.includes('ex_thunder'), false);
  state.stats.special = 130;
  state = checkSkillAwakenings(state);
  assert.equal(state.learnedSkills.includes('ex_thunder'), true);
});

test('Skill Pt 只購買 data-driven 泛用／支援技能且不消耗 Turn', () => {
  let state = clearEvent(createGame('point-skill-seed'));
  const skill = POINT_SKILL_DATA[0];
  state.skillPoints = skill.cost;
  const turn = state.turn;
  state = buyPointSkill(state, skill.id);
  assert.equal(state.turn, turn);
  assert.equal(state.skillPoints, 0);
  assert.ok(state.learnedSkills.includes(skill.id));
  assert.throws(() => buyPointSkill(state, skill.id), /已習得/);
});

test('友情訓練會提供額外能力、Skill Pt 並增加羈絆', () => {
  let state = clearEvent(createGame('friendship-seed'));
  state.turn = 8;
  state.bonds.cc002 = 80;
  state.supportPlacements = { sword: [], gun: ['cc002'], shield: [], move: [], special: [] };
  const preview = getTrainingPreview(state, 'gun');
  assert.equal(preview.friendships[0].id, 'cc002');
  assert.ok(preview.gains.gun > 14);
  assert.ok(preview.skillPoints > 2);
  const beforeGun = state.stats.gun;
  state.hp = 100;
  state = performTraining(state, 'gun');
  assert.ok(state.stats.gun > beforeGun + 14);
  assert.equal(state.bonds.cc002, 90);
});

test('正式支援配置不再保送艾依到槍訓練', () => {
  let state = createGame('no-force-0');
  state.turn = 8;
  state.bonds.cc002 = 80;
  state = assignSupports(state);
  assert.equal(state.supportPlacements.gun.includes('cc002'), false);
  assert.equal(state.supportPlacements.move.includes('cc002'), true);
});

test('固定 seed friendship-1 可在均衡 Build 的 Turn 8 看見友情槍訓練', () => {
  const actions = ['move', 'shield', 'sword', 'gun', 'special'];
  let state = createGame('friendship-1');
  let friendship = null;
  let guard = 0;
  while (!friendship && state.phase !== 'result' && guard < 60) {
    guard += 1;
    if (state.pendingEvent) state = applyEventChoice(state, 0);
    else if (state.phase === 'training') {
      const gun = getTrainingPreview(state, 'gun');
      if (gun.friendships.some((support) => support.id === 'cc002')) {
        friendship = { turn: state.turn, bond: state.bonds.cc002 };
        break;
      }
      state = state.hp < 35 ? rest(state) : performTraining(state, actions[(state.turn - 1) % 5]);
    } else if (state.phase === 'preRace') state = runCurrentRace(state, 'charge');
    else if (state.phase === 'racePlayback') state = completeRacePlayback(state);
    else if (state.phase === 'raceResult') state = continueAfterRace(state);
  }
  assert.deepEqual(friendship, { turn: 8, bond: 95 });
});

test('training preview 會計入成長與支援 bonus 預測可覺醒技能', () => {
  let state = clearEvent(createGame('preview-awakening'));
  state.stats.sword = 170;
  state.stats.special = 130;
  state.supportPlacements = { sword: ['cc003'], gun: [], shield: [], move: [], special: [] };
  const preview = getTrainingPreview(state, 'sword');
  assert.ok(preview.gains.sword > 14);
  assert.ok(preview.awakeningSkills.some((skill) => skill.id === 'ex_thunder'));
  assert.equal(state.learnedSkills.includes('ex_thunder'), false, 'preview 不得改動 state');
});

test('能力條件達成後可觸發 data-driven conditional event', () => {
  let state = clearEvent(createGame('conditional-event'));
  state.turn = 2;
  state.stats.gun = 149;
  state.supportPlacements = { sword: [], gun: [], shield: [], move: [], special: [] };
  state = performTraining(state, 'gun');
  assert.equal(state.pendingEvent, 'precision_drill');
});

test('Race Engine 產生八人、時間線、續航、技能與分析且可重播', () => {
  let state = clearEvent(createGame('race-seed'));
  state.phase = 'preRace';
  state.raceIndex = 0;
  state.stats = { sword: 160, gun: 160, shield: 160, move: 160, special: 160 };
  state.learnedSkills = ['ex_thunder'];
  const first = runCurrentRace(state, 'charge').lastRace;
  const second = runCurrentRace(state, 'charge').lastRace;
  assert.equal(first.results.length, 8);
  assert.ok(first.timeline.length >= 40 && first.timeline.length <= 73);
  assert.deepEqual(first, second);
  assert.ok(Number.isFinite(first.finishTime));
  assert.ok(Array.isArray(first.analysisReasons) && first.analysisReasons.length > 0);
  assert.equal(first.timeline.every((frame) => frame.racers.length === 8), true);
  assert.ok(first.events.some((event) => event.type === 'rankChanged'));
});

test('shared Race 會產生 blocked 與基於實際換位的 overtake event', () => {
  const simulation = simulateRace(sharedRaceFixture(), { id: 'fixture', distance: 'short' }, 'shared-events');
  assert.ok(simulation.events.some((event) => event.type === 'blocked'));
  const success = simulation.events.find((event) => event.type === 'overtakeSucceeded');
  assert.ok(success);
  const previous = simulation.timeline[success.tick - 1];
  const current = simulation.timeline[success.tick];
  assert.ok(previous.racers.find((racer) => racer.id === success.racerId).position
    <= previous.racers.find((racer) => racer.id === success.targetId).position);
  assert.ok(current.racers.find((racer) => racer.id === success.racerId).position
    > current.racers.find((racer) => racer.id === success.targetId).position);
});

test('劍槍盾移特分別改變突破、追擊、續航、速度與跑位', () => {
  const base = fixtureStats(100, 100, 100, 100, 100);
  const compare = (stat, low, high, seed = 'dimension') => {
    const run = (value) => simulateRace(
      sharedRaceFixture({ playerStats: { ...base, [stat]: value }, strategy: 'chase', opponentMove: 115 }),
      { id: 'fixture', distance: 'long' }, seed,
    );
    return [run(low), run(high)];
  };
  const result = (simulation) => simulation.results.find((racer) => racer.id === 'player');
  const playerEvents = (simulation, type) => simulation.events.filter((event) => event.racerId === 'player' && event.type === type).length;
  const blockedTicks = (simulation) => simulation.timeline.filter((frame) => frame.racers.find((racer) => racer.id === 'player').blocked).length;

  const [lowMove, highMove] = compare('move', 50, 220);
  assert.ok(result(highMove).finishTime < result(lowMove).finishTime - 8);
  const [lowShield, highShield] = compare('shield', 50, 220);
  assert.ok(result(highShield).remainingStamina > result(lowShield).remainingStamina + 80);
  const [lowSword, highSword] = compare('sword', 50, 220);
  assert.ok(playerEvents(highSword, 'overtakeSucceeded') > playerEvents(lowSword, 'overtakeSucceeded'));
  const [lowGun, highGun] = compare('gun', 50, 220);
  assert.ok(result(highGun).finishTime < result(lowGun).finishTime - 2);
  const [lowSpecial, highSpecial] = compare('special', 30, 250, 'sp-0');
  assert.ok(blockedTicks(highSpecial) < blockedTicks(lowSpecial));
  assert.ok(result(highSpecial).remainingStamina > result(lowSpecial).remainingStamina);
});

test('Ex雷擊只有終盤實際超車狀態才可觸發', () => {
  const racers = sharedRaceFixture({
    playerStats: fixtureStats(190, 150, 140, 120, 200), learnedSkills: ['ex_thunder'], strategy: 'chase',
  });
  const simulation = simulateRace(racers, { id: 'fixture', distance: 'short' }, 'thunder-0');
  const activation = simulation.events.find((event) => event.racerId === 'player' && event.skillId === 'ex_thunder');
  assert.ok(activation);
  assert.equal(activation.phase, '終盤');
  const activationFrame = simulation.timeline[activation.tick];
  assert.ok(activationFrame.racers.find((racer) => racer.id === 'player').overtaking);

  const leader = sharedRaceFixture({
    playerStats: fixtureStats(200, 200, 200, 280, 200), learnedSkills: ['ex_thunder'], strategy: 'sprint', opponentMove: 80,
  });
  const noOvertake = simulateRace(leader, { id: 'fixture', distance: 'short' }, 'leader');
  assert.equal(noOvertake.events.some((event) => event.racerId === 'player' && event.skillId === 'ex_thunder'), false);
});

test('Ex茨林只由 position contest／被超越觸發，並在 duration 後停止', () => {
  const simulation = simulateRace(
    sharedRaceFixture({ learnedSkills: ['ex_thorns'] }),
    { id: 'fixture', distance: 'short' }, 'thorns-0',
  );
  const activation = simulation.events.find((event) => event.racerId === 'player' && event.skillId === 'ex_thorns');
  const expiry = simulation.events.find((event) => event.racerId === 'player' && event.type === 'skillExpired' && event.skillId === 'ex_thorns');
  assert.ok(activation && expiry);
  const triggerFrame = simulation.timeline[activation.tick];
  const player = triggerFrame.racers.find((racer) => racer.id === 'player');
  assert.ok(player.contesting || simulation.events.some((event) => event.tick === activation.tick - 1
    && event.targetId === 'player' && event.type === 'overtakeSucceeded'));
  assert.equal(expiry.tick - activation.tick + 1, 5);
  assert.equal(simulation.timeline[expiry.tick].racers.find((racer) => racer.id === 'player').activeSkills.ex_thorns, undefined);
});

test('race result analysis 由 shared timeline/events 產生指標與建議', () => {
  const simulation = simulateRace(
    sharedRaceFixture({ playerStats: fixtureStats(70, 70, 30, 80, 40), strategy: 'chase' }),
    { id: 'fixture', distance: 'long' }, 'analysis-events',
  );
  let state = clearEvent(createGame('analysis-state'));
  state.phase = 'preRace'; state.raceIndex = 2;
  state.stats = fixtureStats(70, 70, 30, 80, 40);
  state = runCurrentRace(state, 'chase');
  assert.ok(state.lastRace.analysis.metrics.staminaLow || state.lastRace.analysis.metrics.blockedTicks > 0);
  assert.ok(state.lastRace.analysis.observations.length > 0);
  assert.ok(state.lastRace.analysis.suggestions.length > 0);
  assert.ok(simulation.events.some((event) => ['staminaLow', 'blocked', 'overtakeFailed'].includes(event.type)));
});

test('四場賽不消耗 Turn，能從 Turn 1 完整走到 Final 成功結算', () => {
  let state = createGame('vertical-slice');
  let guard = 0;
  while (state.phase !== 'result' && guard < 80) {
    guard += 1;
    state = clearEvent(state);
    if (state.phase === 'training') {
      STAT_KEYS.forEach((key) => { state.stats[key] = Math.max(state.stats[key], 260); });
      state = state.hp < 40 ? rest(state) : performTraining(state, 'special');
    } else if (state.phase === 'preRace') {
      const turnBeforeRace = state.turn;
      state = runCurrentRace(state, 'charge');
      assert.equal(state.turn, turnBeforeRace);
    } else if (state.phase === 'racePlayback') {
      state = completeRacePlayback(state);
    } else if (state.phase === 'raceResult') {
      state = continueAfterRace(state);
    }
  }
  assert.ok(guard < 80, '流程不應卡住');
  assert.equal(state.turn, 16);
  assert.equal(state.result.success, true);
  assert.deepEqual(state.completedRaces, ['race1', 'race2', 'race3', 'final']);
  assert.deepEqual(state.seenEvents, ['opening', 'precision_drill', 'battle_simulation', 'tactics', 'izac', 'final']);
});

test('未達目標會直接育成失敗並保留具體分析', () => {
  let state = clearEvent(createGame('failure-seed'));
  state.phase = 'preRace';
  state.raceIndex = 0;
  state.stats = { sword: 1, gun: 1, shield: 1, move: 1, special: 1 };
  state = runCurrentRace(state, 'chase');
  assert.equal(state.lastRace.passed, false);
  state = completeRacePlayback(state);
  state = continueAfterRace(state);
  assert.equal(state.phase, 'result');
  assert.equal(state.result.success, false);
  assert.match(state.result.analysisReasons.join('、'), /超車失敗|續航不足|最高速度低於/);
  assert.ok(state.result.analysis.suggestions.includes('shield'));
  assert.ok(state.result.analysis.suggestions.includes('move'));
});

test('Persistence adapter 可替換 storage 並處理損壞資料', () => {
  const values = new Map();
  const storage = {
    getItem: (key) => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: (key) => values.delete(key),
  };
  const repository = new RaisingRepository(storage, 'test');
  const state = createGame('storage-seed');
  repository.save(state);
  assert.deepEqual(repository.load(), state);
  values.set('test', '{broken');
  assert.equal(repository.load(), null);
  repository.clear();
  assert.equal(values.has('test'), false);
});
