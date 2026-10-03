import { writeFile } from 'node:fs/promises';

const debugEndpoint = process.argv[2] ?? 'http://127.0.0.1:9231';
const screenshotPath = process.argv[3];
const expectedPageFragment = process.argv[4] ?? '127.0.0.1:8878/pages/raising/';
const pages = await (await fetch(`${debugEndpoint}/json/list`)).json();
const page = pages.find((candidate) => candidate.type === 'page' && candidate.url.includes(expectedPageFragment));
if (!page) throw new Error('找不到 Edge page target');

const socket = new WebSocket(page.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, { once: true });
  socket.addEventListener('error', reject, { once: true });
});

let nextId = 1;
const pending = new Map();
socket.addEventListener('message', (message) => {
  const payload = JSON.parse(message.data);
  if (!payload.id || !pending.has(payload.id)) return;
  const { resolve, reject } = pending.get(payload.id);
  pending.delete(payload.id);
  if (payload.error) reject(new Error(payload.error.message));
  else resolve(payload.result);
});

function command(method, params = {}) {
  const id = nextId;
  nextId += 1;
  socket.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}

const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
async function evaluate(expression) {
  const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
  return result.result.value;
}
async function click(action) {
  const clicked = await evaluate(`(() => { const element = document.querySelector('[data-action="${action}"]'); if (!element) return false; element.click(); return true; })()`);
  if (!clicked) {
    const pageState = await evaluate(`({ url: location.href, title: document.title, readyState: document.readyState, body: document.body.innerText.slice(0, 500) })`);
    throw new Error(`無法點擊 ${action}: ${JSON.stringify(pageState)}`);
  }
  await wait(120);
}

await wait(1500);
await click('enter-darkroom');
await click('select-evarist');
await click('event-choice');
await click('train');
await click('train');
await click('train');
await click('run-race');
await wait(900);

const evidence = await evaluate(`(() => ({
  lanes: document.querySelectorAll('.raising-playback-lane').length,
  phase: document.querySelector('#race-phase')?.textContent,
  clock: document.querySelector('#race-clock')?.textContent,
  controls: [...document.querySelectorAll('.raising-playback-controls button')].map((button) => button.textContent.trim()),
  movedRacers: [...document.querySelectorAll('.raising-runner')].filter((runner) => parseFloat(runner.style.left) > 0).length,
  liveCall: document.querySelector('#race-live-call')?.textContent,
}))()`);

if (evidence.lanes !== 8 || evidence.movedRacers !== 8 || evidence.controls.length !== 2) {
  throw new Error(`Race Viewer smoke 驗證失敗：${JSON.stringify(evidence)}`);
}
if (screenshotPath) {
  const screenshot = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  await writeFile(screenshotPath, Buffer.from(screenshot.data, 'base64'));
}
await click('toggle-race-speed');
const speedText = await evaluate(`document.querySelector('[data-action="toggle-race-speed"]')?.textContent.trim()`);
if (speedText !== '速度 x2') throw new Error('x2 控制未生效');
await click('skip-race');
const resultVisible = await evaluate(`Boolean(document.querySelector('.raising-race-stage'))`);
if (!resultVisible) throw new Error('Skip 後未進入 raceResult');

console.log(JSON.stringify({ ...evidence, speedText, resultVisible }));
socket.close();
