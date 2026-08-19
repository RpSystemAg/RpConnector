import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const extensionDir = resolve('prstudio-unified-browser-agent');
const candidates = [
  process.env.CHROME_BIN,
  '/usr/bin/google-chrome',
  '/usr/bin/google-chrome-stable',
  '/usr/bin/chromium',
].filter(Boolean);
const chrome = candidates.find((p) => existsSync(p));
if (!chrome) {
  console.error('FAIL CHROME_BIN/Chrome executable not found');
  process.exit(1);
}

const profile = await mkdtemp(join(tmpdir(), 'rp-mv3-restart-'));
const port = 9700 + (process.pid % 200);
const child = spawn(chrome, [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${profile}`,
  `--disable-extensions-except=${extensionDir}`,
  `--load-extension=${extensionDir}`,
  'about:blank',
], { stdio: ['ignore', 'pipe', 'pipe'] });
let stderr = '';
child.stderr.on('data', (d) => { stderr += String(d); if (stderr.length > 250000) stderr = stderr.slice(-250000); });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`${response.status} ${url}`);
  return response.json();
}
async function waitBrowser() {
  let last;
  for (let i = 0; i < 120; i++) {
    try { return await fetchJson(`http://127.0.0.1:${port}/json/version`); }
    catch (e) { last = e; await sleep(100); }
  }
  throw last ?? new Error('browser DevTools endpoint never became ready');
}

let nextId = 1;
function rpc(ws, method, params = {}, sessionId = undefined, timeoutMs = 8000) {
  return new Promise((resolvePromise, reject) => {
    const id = nextId++;
    const timer = setTimeout(() => reject(new Error(`CDP timeout ${method}`)), timeoutMs);
    const listener = (event) => {
      let msg;
      try { msg = JSON.parse(String(event.data)); } catch { return; }
      if (msg.id !== id) return;
      clearTimeout(timer);
      ws.removeEventListener('message', listener);
      if (msg.error) reject(new Error(`${method}: ${JSON.stringify(msg.error)}`));
      else resolvePromise(msg.result ?? {});
    };
    ws.addEventListener('message', listener);
    const message = { id, method, params };
    if (sessionId) message.sessionId = sessionId;
    ws.send(JSON.stringify(message));
  });
}

async function waitForWorker(ws, extensionId, forbiddenTargetId = '') {
  for (let i = 0; i < 120; i++) {
    const { targetInfos = [] } = await rpc(ws, 'Target.getTargets');
    const worker = targetInfos.find((t) =>
      t.type === 'service_worker' &&
      String(t.url ?? '').startsWith(`chrome-extension://${extensionId}/`) &&
      t.targetId !== forbiddenTargetId
    );
    if (worker) return worker;
    await sleep(100);
  }
  throw new Error(`MV3 worker did not appear for extension ${extensionId}`);
}

let exitCode = 1;
try {
  const version = await waitBrowser();
  if (!version.webSocketDebuggerUrl) throw new Error('missing browser WebSocket debugger URL');
  const ws = new WebSocket(version.webSocketDebuggerUrl);
  await new Promise((resolvePromise, reject) => {
    ws.addEventListener('open', resolvePromise, { once: true });
    ws.addEventListener('error', () => reject(new Error('browser WebSocket failed')), { once: true });
  });

  const { targetInfos = [] } = await rpc(ws, 'Target.getTargets');
  const initial = targetInfos.find((t) => t.type === 'service_worker' && String(t.url ?? '').startsWith('chrome-extension://'));
  if (!initial) throw new Error('initial MV3 service worker not observed');
  const match = String(initial.url).match(/^chrome-extension:\/\/([^/]+)\//);
  if (!match) throw new Error(`cannot derive extension id from ${initial.url}`);
  const extensionId = match[1];

  const closed = await rpc(ws, 'Target.closeTarget', { targetId: initial.targetId });
  if (closed.success === false) throw new Error('Chrome refused to terminate MV3 worker target');

  // Prove the original target really disappeared rather than accepting a no-op.
  let disappeared = false;
  for (let i = 0; i < 80; i++) {
    const { targetInfos: infos = [] } = await rpc(ws, 'Target.getTargets');
    if (!infos.some((t) => t.targetId === initial.targetId)) { disappeared = true; break; }
    await sleep(100);
  }
  if (!disappeared) throw new Error('terminated MV3 target remained observable');

  // Opening an extension page then sending a runtime message is an extension
  // event. Chrome must be able to recreate the non-persistent MV3 worker.
  const created = await rpc(ws, 'Target.createTarget', { url: `chrome-extension://${extensionId}/sidepanel.html` });
  if (!created.targetId) throw new Error('could not create extension page target');
  const attached = await rpc(ws, 'Target.attachToTarget', { targetId: created.targetId, flatten: true });
  if (!attached.sessionId) throw new Error('could not attach to extension page');
  await rpc(ws, 'Runtime.enable', {}, attached.sessionId);
  await rpc(ws, 'Runtime.evaluate', {
    expression: "typeof chrome !== 'undefined' && chrome.runtime ? chrome.runtime.sendMessage({type:'rp_ci_mv3_wake'}).catch(()=>null) : null",
    awaitPromise: false,
  }, attached.sessionId);

  const restarted = await waitForWorker(ws, extensionId, initial.targetId);
  if (restarted.targetId === initial.targetId) throw new Error('worker target identity did not change after forced termination');

  const fatal = stderr.split(/\r?\n/).filter((line) => /extension.*(failed|error)|uncaught|syntaxerror|referenceerror/i.test(line));
  if (fatal.length) throw new Error(`Chrome extension errors after restart:\n${fatal.slice(0, 20).join('\n')}`);

  console.log(`PASS MV3 worker restarted; browser=${version.Browser}; old=${initial.targetId}; new=${restarted.targetId}`);
  ws.close();
  exitCode = 0;
} catch (error) {
  console.error(`FAIL forced MV3 worker restart: ${error?.stack ?? error}`);
  if (stderr) console.error(`--- chrome stderr tail ---\n${stderr.slice(-12000)}`);
} finally {
  child.kill('SIGKILL');
  await sleep(100);
  await rm(profile, { recursive: true, force: true });
}
process.exit(exitCode);
