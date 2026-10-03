import {
  CHARACTER_DATA,
  DISTANCES,
  EVENT_DATA,
  POINT_SKILL_DATA,
  RACE_SCHEDULE,
  ROSTER,
  SKILL_DATA,
  STAT_KEYS,
  STAT_LABELS,
  STRATEGIES,
  SUPPORT_DATA,
  TRAINING_DATA,
} from './config.js';
import {
  applyEventChoice,
  buyPointSkill,
  completeRacePlayback,
  continueAfterRace,
  createGame,
  currentRace,
  getSkillRequirementProgress,
  getOverallRank,
  getTrainingPreview,
  performTraining,
  rest,
  runCurrentRace,
} from './engine.js';
import { RaisingRepository } from './persistence.js';

const root = document.getElementById('raising-game');
const assets = JSON.parse(root.dataset.assets || '{}');
const repository = new RaisingRepository(window.localStorage);
let state = repository.load();
let screen = state ? 'game' : 'landing';
let modal = null;
let notice = '';
let playback = { timer: null, index: 0, speed: 1 };

const escapeHtml = (value) => String(value)
  .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

function requestedGameSeed() {
  const requested = new URLSearchParams(window.location.search).get('seed')?.trim();
  return requested && requested.length <= 80
    ? requested
    : `${Date.now()}-${crypto.getRandomValues(new Uint32Array(1))[0]}`;
}

function imageMarkup(characterId, name, className = '') {
  const source = assets[characterId];
  return source
    ? `<img class="${className}" src="${escapeHtml(source)}" alt="${escapeHtml(name)}" data-fallback-image>`
    : `<span class="raising-avatar-fallback ${className}" aria-label="${escapeHtml(name)}">${escapeHtml(name.slice(0, 1))}</span>`;
}

function persist() {
  if (state) repository.save(state);
}

function setState(next) {
  state = next;
  persist();
  render();
}

function scheduleMarkup() {
  return RACE_SCHEDULE.map((race, index) => {
    const distance = race.id === 'final' && state ? state.finalDistance : race.distance;
    const complete = state?.completedRaces.includes(race.id);
    const current = state?.raceIndex === index && !complete;
    return `<li class="${complete ? 'is-complete' : ''} ${current ? 'is-current' : ''}">
      <span>${complete ? '✓' : index + 1}</span>
      <div><strong>${race.label}</strong><small>Turn ${race.afterTurn} 後 · ${DISTANCES[distance]?.label ?? '?'} · 前 ${race.targetRank}</small></div>
    </li>`;
  }).join('');
}

function landingMarkup() {
  const hasSave = Boolean(repository.load());
  return `<section class="raising-landing">
    <div class="raising-landing__shade"></div>
    <div class="raising-landing__content">
      <span class="raising-eyebrow">UNLIGHT · PLAYABLE PROTOTYPE v0.2</span>
      <h1>暗房育成競賽</h1>
      <p>門扉另一側，是由意志、智略與十六次選擇構成的戰場。</p>
      <div class="raising-braue">
        <span class="raising-braue__sigil">B</span>
        <div><strong>布勞</strong><p>「請進。這次的記憶，會通往怎樣的結局呢？」</p></div>
      </div>
      <div class="raising-actions">
        <button class="raising-btn raising-btn--primary" data-action="enter-darkroom">進入暗房</button>
        ${hasSave ? '<button class="raising-btn" data-action="continue-save">繼續育成</button>' : ''}
      </div>
    </div>
  </section>`;
}

function selectionMarkup() {
  return `<section class="raising-selection">
    <header><span class="raising-eyebrow">DARKROOM / CHARACTER MEMORY</span><h1>選擇育成角色</h1><p>v0.2 仍以艾伯李斯特驗證完整育成與同場競賽。</p></header>
    <div class="raising-roster">
      ${ROSTER.map((character) => `<article class="raising-character ${character.available ? 'is-available' : 'is-locked'}">
        <div class="raising-character__art">${imageMarkup(character.id, character.name)}</div>
        <div class="raising-character__meta"><span>${character.id.toUpperCase()}</span><h2>${character.name}</h2>
          <p>${character.available ? CHARACTER_DATA.cc001.theme : 'Scenario 開發中'}</p>
          <button class="raising-btn ${character.available ? 'raising-btn--primary' : ''}" ${character.available ? 'data-action="select-evarist"' : 'disabled'}>${character.available ? '選擇' : '開發中'}</button>
        </div>
      </article>`).join('')}
    </div>
    <button class="raising-text-btn" data-action="back-landing">← 返回暗房入口</button>
  </section>`;
}

function statsMarkup() {
  return STAT_KEYS.map((key) => `<div class="raising-stat raising-stat--${key}"><span>${STAT_LABELS[key]}</span><strong>${state.stats[key]}</strong></div>`).join('');
}

function trainingMarkup(key) {
  const preview = getTrainingPreview(state, key);
  const gains = Object.entries(preview.gains).map(([stat, value]) => `${STAT_LABELS[stat]} +${value}`).join(' · ');
  const supports = preview.supports.length
    ? preview.supports.map((support) => `<span class="raising-support-chip ${preview.friendships.some((item) => item.id === support.id) ? 'is-friendship' : ''}">${support.name}<small>${state.bonds[support.id]}</small></span>`).join('')
    : '<span class="raising-empty-support">無支援</span>';
  return `<button class="raising-training raising-training--${key}" data-action="train" data-key="${key}" ${state.pendingEvent ? 'disabled' : ''}>
    ${preview.friendships.length ? '<span class="raising-friendship-badge">友情訓練</span>' : ''}
    <span class="raising-training__name"><i>${STAT_LABELS[key]}</i>${TRAINING_DATA[key].label}</span>
    <span class="raising-training__gain">${gains}<br>Skill Pt +${preview.skillPoints} · 體力 ${preview.hp >= 0 ? '+' : ''}${preview.hp}</span>
    <span class="raising-training__supports">${supports}</span>
    ${preview.awakeningSkills.length ? `<span class="raising-training__awakening">⚡ 成功可覺醒：${preview.awakeningSkills.map((skill) => skill.name).join('、')}</span>` : ''}
    <span class="raising-training__risk ${preview.failureRate >= 0.18 ? 'is-danger' : ''}">失敗率 ${Math.round(preview.failureRate * 100)}%</span>
  </button>`;
}

function gameMarkup() {
  const race = currentRace(state);
  const hpTone = state.hp < 30 ? 'is-low' : state.hp < 60 ? 'is-mid' : '';
  return `<section class="raising-dashboard">
    <header class="raising-topbar">
      <div class="raising-identity">${imageMarkup('cc001', CHARACTER_DATA.cc001.name, 'raising-identity__image')}
        <div><span>R5 · ${CHARACTER_DATA.cc001.title}</span><h1>${CHARACTER_DATA.cc001.name}</h1><p>${CHARACTER_DATA.cc001.theme}</p></div>
      </div>
      <div class="raising-turn"><small>TURN</small><strong>${state.turn}<em>/16</em></strong></div>
    </header>
    ${notice ? `<div class="raising-notice">${escapeHtml(notice)}</div>` : ''}
    <div class="raising-dashboard__grid">
      <aside class="raising-side-panel">
        <section class="raising-panel"><div class="raising-panel__title"><span>育成狀態</span><b>Skill Pt ${state.skillPoints}</b></div>
          <div class="raising-hp"><div><span>體力</span><strong>${state.hp}/100</strong></div><div class="raising-hp__bar"><i class="${hpTone}" style="width:${state.hp}%"></i></div></div>
          <div class="raising-stats">${statsMarkup()}</div>
        </section>
        <section class="raising-panel"><div class="raising-panel__title"><span>Race Schedule</span><b>Final：${DISTANCES[state.finalDistance].label}</b></div><ol class="raising-schedule">${scheduleMarkup()}</ol></section>
        <section class="raising-panel raising-next-race"><span>NEXT TARGET</span><h2>${race.label}</h2><p>${race.venue} · ${DISTANCES[race.distance].label}距離</p><strong>目標：前 ${race.targetRank}</strong></section>
      </aside>
      <div class="raising-main-panel">
        <div class="raising-section-heading"><div><span>TRAINING COMMAND</span><h2>選擇本 Turn 行動</h2></div><p>Race 不消耗 Turn；技能頁可隨時查看。</p></div>
        <div class="raising-training-grid">${STAT_KEYS.map(trainingMarkup).join('')}</div>
        <div class="raising-command-row">
          <button class="raising-command" data-action="rest" ${state.pendingEvent ? 'disabled' : ''}><b>休息</b><span>恢復 30～50 體力</span></button>
          <button class="raising-command" data-action="open-skills"><b>招式覺醒</b><span>${state.learnedSkills.length} / ${SKILL_DATA.length} 已習得</span></button>
          <button class="raising-command" data-action="open-supports"><b>支援羈絆</b><span>查看友情進度</span></button>
        </div>
        <div class="raising-history"><h3>行動紀錄</h3>${state.history.slice(0, 4).map((item) => `<p><b>T${item.turn}</b>${escapeHtml(item.text)}</p>`).join('') || '<p>艾伯正在等待你的第一道指令。</p>'}</div>
      </div>
    </div>
  </section>`;
}

function eventModalMarkup() {
  const event = EVENT_DATA[state.pendingEvent];
  if (!event) return '';
  return `<div class="raising-modal-backdrop"><section class="raising-dialog raising-dialog--story">
    <span class="raising-eyebrow">SCENARIO EVENT</span><h2>${event.title}</h2>
    <div class="raising-dialogue"><strong>${event.speaker}</strong>${event.lines.map((line) => `<p>${line}</p>`).join('')}</div>
    <div class="raising-choice-list">${event.choices.map((choice, index) => `<button class="raising-btn" data-action="event-choice" data-index="${index}">${choice.label}</button>`).join('')}</div>
  </section></div>`;
}

function skillsModalMarkup() {
  return `<div class="raising-modal-backdrop"><section class="raising-dialog raising-dialog--wide">
    <button class="raising-close" data-action="close-modal" aria-label="關閉">×</button>
    <span class="raising-eyebrow">SKILL AWAKENING</span><h2>艾伯李斯特 R5 招式覺醒</h2><p>角色原生招式由能力達標自動習得。持有 Skill Pt：<strong>${state.skillPoints}</strong>。</p>
    <div class="raising-skill-list">${SKILL_DATA.map((skill) => {
      const learned = state.learnedSkills.includes(skill.id);
      const hinted = state.skillHints.includes(skill.id);
      const progress = getSkillRequirementProgress(state, skill);
      const missing = progress.abilityProgress.filter((item) => !item.met).map((item) => `${STAT_LABELS[item.stat]} +${item.missing}`).join('、');
      return `<article class="raising-skill ${learned ? 'is-learned' : ''}"><div class="raising-skill__main"><span>${hinted ? 'HINT · ' : ''}適用：${skill.ranges.map((key) => DISTANCES[key].label).join('／')}</span><h3>${skill.name}</h3><p>${skill.description}</p>
        <div class="raising-skill-requirements">${progress.abilityProgress.map((item) => `<div class="${item.met ? 'is-met' : ''}"><b>${STAT_LABELS[item.stat]}</b><span>${item.current} / ${item.value}</span><i>${item.met ? '✓' : `尚差 ${item.missing}`}</i></div>`).join('')}</div>
        ${learned ? '<strong class="raising-awakened-label">已習得</strong>' : `<small class="raising-missing-label">${missing ? `還差：${missing}` : '條件已達成，等待覺醒'}</small>`}
      </div></article>`;
    }).join('')}</div>
    <h3 class="raising-shop-heading">Skill Pt 技能</h3>
    <div class="raising-point-skill-list">${POINT_SKILL_DATA.map((skill) => {
      const learned = state.learnedSkills.includes(skill.id);
      return `<article><div><span>${skill.type === 'support' ? 'SUPPORT' : 'GENERAL'} · ${skill.ranges.map((key) => DISTANCES[key].label).join('／')}</span><h4>${skill.name}</h4><p>${skill.description}</p></div><button class="raising-btn" data-action="buy-point-skill" data-key="${skill.id}" ${learned || state.skillPoints < skill.cost ? 'disabled' : ''}>${learned ? '習得済' : `${skill.cost} Pt`}</button></article>`;
    }).join('')}</div>
  </section></div>`;
}

function awakeningModalMarkup() {
  const skillId = state.awakeningQueue?.[0];
  const skill = SKILL_DATA.find((item) => item.id === skillId);
  if (!skill) return '';
  return `<div class="raising-modal-backdrop raising-awakening-backdrop"><section class="raising-dialog raising-awakening-dialog">
    <span class="raising-awakening-sigil">✦</span><span class="raising-eyebrow">CONDITION CLEARED</span>
    <h2>條件達成！</h2><h3>${skill.name} 習得</h3><p>${skill.description}</p>
    <button class="raising-btn raising-btn--primary" data-action="dismiss-awakening">確認</button>
  </section></div>`;
}

function supportsModalMarkup() {
  return `<div class="raising-modal-backdrop"><section class="raising-dialog">
    <button class="raising-close" data-action="close-modal" aria-label="關閉">×</button><span class="raising-eyebrow">SUPPORT BOND</span><h2>支援角色</h2>
    <div class="raising-bond-list">${SUPPORT_DATA.map((support) => `<div><span>${imageMarkup(support.id, support.name)}</span><section><b>${support.name}</b><small>擅長：${STAT_LABELS[support.specialty]}訓練</small><div><i style="width:${state.bonds[support.id]}%"></i></div></section><strong>${state.bonds[support.id]}</strong></div>`).join('')}</div>
    <p class="raising-dialog__footnote">羈絆達 80，且出現在擅長訓練時，發動友情訓練。</p>
  </section></div>`;
}

function preRaceMarkup() {
  const race = currentRace(state);
  return `<div class="raising-modal-backdrop"><section class="raising-dialog raising-dialog--race">
    <span class="raising-eyebrow">TARGET RACE</span><h2>${race.label}</h2><p>${race.venue} · ${DISTANCES[race.distance].label}距離 · 目標前 ${race.targetRank}</p>
    <div class="raising-strategy-list">${Object.entries(STRATEGIES).map(([key, strategy]) => `<label><input type="radio" name="strategy" value="${key}" ${state.strategy === key ? 'checked' : ''}><span><b>${strategy.label}</b><small>適性 ${CHARACTER_DATA.cc001.strategyAptitude[key]}</small></span></label>`).join('')}</div>
    <button class="raising-btn raising-btn--primary raising-btn--block" data-action="run-race">出賽</button>
  </section></div>`;
}

function racePlaybackMarkup() {
  const output = state.lastRace;
  const firstFrame = output.timeline[0];
  return `<section class="raising-race-viewer">
    <header class="raising-race-viewer__header"><div><span class="raising-eyebrow">LIVE RACE · ${output.race.label}</span><h1>${output.race.venue}</h1><p>${DISTANCES[output.race.distance].label}距離 · 目標前 ${output.race.targetRank}</p></div>
      <div class="raising-race-clock"><span id="race-phase">序盤</span><strong id="race-clock">0.0s</strong></div></header>
    <div class="raising-race-progress"><i id="race-progress"></i><span>START</span><b>GOAL</b></div>
    <div class="raising-live-call" id="race-live-call">八名角色同時起跑——</div>
    <div class="raising-playback-track" id="race-playback-track">
      ${firstFrame.racers.map((racer) => `<div class="raising-playback-lane ${racer.isPlayer ? 'is-player' : ''}" data-racer-id="${racer.id}">
        <strong class="raising-playback-rank">${racer.rank}</strong><span class="raising-playback-avatar">${racer.isPlayer ? imageMarkup('cc001', racer.name) : escapeHtml(racer.name.slice(0, 1))}</span>
        <div class="raising-playback-name"><b>${escapeHtml(racer.name)}</b><small class="raising-playback-status">準備</small></div>
        <div class="raising-playback-course"><i></i><span class="raising-runner" style="left:${racer.position * 100}%"></span><em></em></div>
        <div class="raising-playback-stamina"><i style="width:100%"></i></div>
      </div>`).join('')}
    </div>
    <div class="raising-race-event-log" id="race-event-log"></div>
    <div class="raising-playback-controls"><button class="raising-btn" data-action="toggle-race-speed">速度 x1</button><button class="raising-btn" data-action="skip-race">Skip</button></div>
  </section>`;
}

function raceResultMarkup() {
  const output = state.lastRace;
  return `<section class="raising-race-stage">
    <header><span class="raising-eyebrow">${output.race.label} · ${DISTANCES[output.race.distance].label}距離</span><h1>${output.passed ? '目標達成' : '目標未達'}</h1><p>要求前 ${output.race.targetRank} · 艾伯第 ${output.rank} 名</p></header>
    <div class="raising-track">${output.results.map((result) => `<div class="raising-lane ${result.isPlayer ? 'is-player' : ''}" data-rank="${result.rank}"><span>${result.rank}</span><b>${result.name}</b><div><i class="is-running" style="--finish:${100 - (result.finishTime - output.results[0].finishTime) * 1.5}%"></i></div><small>${result.finishTime.toFixed(2)}s</small></div>`).join('')}</div>
    <div class="raising-race-report"><div><span>剩餘續航</span><strong>${output.remainingStamina}</strong></div><div><span>發動技能</span><strong>${output.activatedSkills.join('、') || '無'}</strong></div></div>
    <section class="raising-race-analysis"><h2>比賽分析</h2>${output.analysis.observations.map((item) => `<p class="is-${item.tone}">${item.tone === 'warning' ? '⚠' : item.tone === 'good' ? '✓' : '•'} ${item.text}</p>`).join('')}
      <div><strong>建議：</strong>${output.analysis.suggestions.length ? output.analysis.suggestions.map((stat) => `${STAT_LABELS[stat]} ↑`).join('　') : '維持目前配置'}</div></section>
    <button class="raising-btn raising-btn--primary" data-action="continue-race">${output.passed && output.race.id !== 'final' ? `領取 ${output.race.rewardSkillPoints} Pt 並繼續` : '前往結算'}</button>
  </section>`;
}

function clearPlaybackTimer() {
  if (playback.timer) window.clearTimeout(playback.timer);
  playback.timer = null;
}

function playbackEventText(event) {
  if (event.type === 'skillActivated') return `⚡ ${event.racerName}發動 ${event.skillName}`;
  if (event.type === 'blocked') return `⚠ ${event.racerName}被${event.targetName}阻擋`;
  if (event.type === 'overtakeStarted') return `${event.racerName}開始挑戰${event.targetName}`;
  if (event.type === 'overtakeSucceeded') return `↑ ${event.racerName}成功超越${event.targetName}`;
  if (event.type === 'overtakeFailed') return `× ${event.racerName}超車失敗`;
  if (event.type === 'positionContest') return `${event.racerName}陷入位置競爭`;
  if (event.type === 'staminaLow') return `⚠ ${event.racerName}續航告急`;
  if (event.type === 'sprintStarted') return `${event.racerName}進入最後衝刺`;
  return '';
}

function updatePlaybackFrame(frame) {
  const phase = root.querySelector('#race-phase');
  const clock = root.querySelector('#race-clock');
  const progress = root.querySelector('#race-progress');
  if (!phase || !clock || !progress) return;
  phase.textContent = frame.phase;
  clock.textContent = `${frame.elapsedSeconds.toFixed(1)}s`;
  progress.style.width = `${frame.leaderProgress * 100}%`;
  frame.racers.forEach((racer) => {
    const lane = root.querySelector(`[data-racer-id="${racer.id}"]`);
    if (!lane) return;
    lane.querySelector('.raising-playback-rank').textContent = racer.rank;
    lane.querySelector('.raising-runner').style.left = `${Math.min(98, racer.position * 98)}%`;
    lane.querySelector('.raising-playback-course > i').style.width = `${racer.position * 100}%`;
    lane.querySelector('.raising-playback-stamina > i').style.width = `${racer.staminaRatio * 100}%`;
    const status = racer.finished ? 'GOAL' : racer.blocked ? 'BLOCKED' : racer.overtaking ? 'OVERTAKE' : racer.contesting ? 'CONTEST' : racer.activeSkills && Object.keys(racer.activeSkills).length ? 'SKILL' : `${Math.round(racer.speed * 10000)} spd`;
    lane.querySelector('.raising-playback-status').textContent = status;
    lane.classList.toggle('is-blocked', racer.blocked);
    lane.classList.toggle('is-contesting', Boolean(racer.contesting));
    lane.classList.toggle('is-overtaking', Boolean(racer.overtaking));
    lane.classList.toggle('is-finished', racer.finished);
  });
  const notable = frame.events.map(playbackEventText).filter(Boolean);
  if (notable.length) {
    const playerFirst = frame.events.find((event) => event.racerId === 'player' && playbackEventText(event));
    root.querySelector('#race-live-call').textContent = playerFirst ? playbackEventText(playerFirst) : notable[0];
    root.querySelector('#race-event-log').innerHTML = notable.slice(0, 3).map((text) => `<span>${escapeHtml(text)}</span>`).join('');
  }
}

function finishPlayback() {
  clearPlaybackTimer();
  state = completeRacePlayback(state);
  persist();
  render();
}

function startPlayback() {
  clearPlaybackTimer();
  playback.index = 0;
  playback.speed = 1;
  const frames = state.lastRace.timeline;
  const step = () => {
    if (state.phase !== 'racePlayback') return;
    updatePlaybackFrame(frames[playback.index]);
    playback.index += 1;
    if (playback.index >= frames.length) {
      playback.timer = window.setTimeout(finishPlayback, 650);
      return;
    }
    playback.timer = window.setTimeout(step, playback.speed === 2 ? 78 : 156);
  };
  step();
}

function resultMarkup() {
  const success = state.result.success;
  const learned = [...SKILL_DATA, ...POINT_SKILL_DATA].filter((skill) => state.learnedSkills.includes(skill.id));
  return `<section class="raising-result ${success ? 'is-success' : 'is-failure'}">
    <span class="raising-eyebrow">TRAINING RESULT</span><h1>${state.result.title}</h1>
    <div class="raising-result-card"><div class="raising-result-card__art">${imageMarkup('cc001', CHARACTER_DATA.cc001.name)}</div><div><span>R5 · ${CHARACTER_DATA.cc001.title}</span><h2>${CHARACTER_DATA.cc001.name}</h2><b class="raising-grade">${getOverallRank(state)}</b></div></div>
    <div class="raising-result-stats">${statsMarkup()}</div>
    <div class="raising-result-grid"><section><h3>距離適性</h3><p>遠 A　中 A　近 A</p><h3>跑法適性</h3><p>疾走 B　突進 A　伺機 A　追擊 C</p></section><section><h3>已習得技能</h3><p>${learned.map((skill) => skill.name).join('、') || '尚未習得技能'}</p><h3>${success ? '戰術評價' : `${state.result.failedRace}敗因`}</h3><p>${state.result.analysisReasons.join('；')}</p></section></div>
    <button class="raising-btn raising-btn--primary" data-action="restart">立即重新育成</button>
  </section>`;
}

function render() {
  notice = notice || '';
  if (screen === 'landing') root.innerHTML = landingMarkup();
  else if (screen === 'selection') root.innerHTML = selectionMarkup();
  else if (state?.phase === 'racePlayback') root.innerHTML = racePlaybackMarkup();
  else if (state?.phase === 'raceResult') root.innerHTML = raceResultMarkup();
  else if (state?.phase === 'result') root.innerHTML = resultMarkup();
  else root.innerHTML = gameMarkup();

  const hasAwakening = screen === 'game' && Boolean(state?.awakeningQueue?.length);
  if (hasAwakening) root.insertAdjacentHTML('beforeend', awakeningModalMarkup());
  else if (screen === 'game' && state?.phase === 'training' && state.pendingEvent) root.insertAdjacentHTML('beforeend', eventModalMarkup());
  else if (screen === 'game' && state?.phase === 'preRace') root.insertAdjacentHTML('beforeend', preRaceMarkup());
  if (!hasAwakening && modal === 'skills') root.insertAdjacentHTML('beforeend', skillsModalMarkup());
  if (!hasAwakening && modal === 'supports') root.insertAdjacentHTML('beforeend', supportsModalMarkup());
  root.querySelectorAll('[data-fallback-image]').forEach((image) => image.addEventListener('error', () => {
    image.replaceWith(Object.assign(document.createElement('span'), { className: `raising-avatar-fallback ${image.className}`, textContent: image.alt.slice(0, 1) }));
  }, { once: true }));
  if (state?.phase === 'racePlayback') requestAnimationFrame(startPlayback);
  else clearPlaybackTimer();
}

root.addEventListener('click', (event) => {
  const button = event.target.closest('[data-action]');
  if (!button || button.disabled) return;
  const action = button.dataset.action;
  notice = '';
  try {
    if (action === 'enter-darkroom') { screen = 'selection'; render(); return; }
    if (action === 'back-landing') { screen = 'landing'; render(); return; }
    if (action === 'continue-save') { state = repository.load(); screen = 'game'; render(); return; }
    if (action === 'select-evarist' || action === 'restart') {
      repository.clear();
      state = createGame(requestedGameSeed());
      screen = 'game'; modal = null; persist(); render(); return;
    }
    if (action === 'event-choice') { setState(applyEventChoice(state, Number(button.dataset.index))); return; }
    if (action === 'train') { setState(performTraining(state, button.dataset.key)); return; }
    if (action === 'rest') { setState(rest(state)); return; }
    if (action === 'open-skills') { modal = 'skills'; render(); return; }
    if (action === 'open-supports') { modal = 'supports'; render(); return; }
    if (action === 'close-modal') { modal = null; render(); return; }
    if (action === 'dismiss-awakening') { state.awakeningQueue.shift(); persist(); render(); return; }
    if (action === 'buy-point-skill') { state = buyPointSkill(state, button.dataset.key); persist(); modal = 'skills'; render(); return; }
    if (action === 'run-race') {
      const selected = root.querySelector('input[name="strategy"]:checked')?.value ?? state.strategy;
      setState(runCurrentRace(state, selected)); return;
    }
    if (action === 'toggle-race-speed') { playback.speed = playback.speed === 1 ? 2 : 1; button.textContent = `速度 x${playback.speed}`; return; }
    if (action === 'skip-race') { finishPlayback(); return; }
    if (action === 'continue-race') { setState(continueAfterRace(state)); return; }
  } catch (error) {
    notice = error.message || '操作失敗，請再試一次。';
    modal = null;
    render();
  }
});

render();
