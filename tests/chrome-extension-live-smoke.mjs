import { spawn } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const extensionDir = resolve('prstudio-unified-browser-agent');
const candidates = process.platform === 'darwin'
  ? ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome']
  : process.platform === 'win32'
    ? [
        process.env.CHROME_BIN,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
      ]
    : [process.env.CHROME_BIN, '/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable'];

const { existsSync } = await import('node:fs');
const chrome = candidates.filter(Boolean).find((p) => existsSync(p));
if (!chrome) {
  console.error('FAIL real Chrome/Chromium binary not found');
  process.exit(1);
}

const userDataDir = await mkdtemp(join(tmpdir(), 'rpconnector-chrome-'));
const port = 9300 + (process.pid % 400);
const args = [
  '--headless=new',
  '--no-sandbox',
  '--disable-gpu',
  '--disable-dev-shm-usage',
  `--remote-debugging-port=${port}`,
  `--user-data-dir=${userDataDir}`,
  'about:blank',
];

const child = spawn(chrome, args, { stdio: ['ignore', 'pipe', 'pipe'] });
let stderr = '';
child.stderr.on('data', (d) => { stderr += d.toString(); if (stderr.length > 200000) stderr = stderr.slice(-200000); });

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function json(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`${response.status} ${url}`);
  return response.json();
}

async function waitVersion() {
  let last;
  for (let i = 0; i < 100; i++) {
    try { return await json(`http://127.0.0.1:${port}/json/version`); }
    catch (e) { last = e; await sleep(100); }
  }
  throw last ?? new Error('Chrome DevTools endpoint unavailable');
}

async function cdp(wsUrl, method, params = {}) {
  return new Promise((resolvePromise, reject) => {
    const ws = new WebSocket(wsUrl);
    const id = 1;
    const timer = setTimeout(() => { try { ws.close(); } catch {} reject(new Error(`CDP timeout: ${method}`)); }, 5000);
    ws.addEventListener('open', () => ws.send(JSON.stringify({ id, method, params })));
    ws.addEventListener('message', (event) => {
      const message = JSON.parse(String(event.data));
      if (message.id !== id) return;
      clearTimeout(timer);
      ws.close();
      if (message.error) reject(new Error(JSON.stringify(message.error)));
      else resolvePromise(message.result);
    });
    ws.addEventListener('error', () => { clearTimeout(timer); reject(new Error(`WebSocket error: ${method}`)); });
  });
}

let exitCode = 1;
try {
  const version = await waitVersion();
  if (!version.webSocketDebuggerUrl) throw new Error('Chrome returned no browser WebSocket debugger URL');

  const loaded = await cdp(version.webSocketDebuggerUrl, 'Extensions.loadUnpacked', { path: extensionDir });
  const loadedExtensionId = String(loaded?.id ?? '');
  if (!loadedExtensionId) throw new Error('Extensions.loadUnpacked returned no extension id');

  let extensionTargets = [];
  for (let i = 0; i < 50; i++) {
    const result = await cdp(version.webSocketDebuggerUrl, 'Target.getTargets');
    extensionTargets = (result.targetInfos ?? []).filter((t) => String(t.url ?? '').startsWith(`chrome-extension://${loadedExtensionId}/`));
    if (extensionTargets.some((t) => t.type === 'service_worker' && String(t.url ?? '').endsWith('/service-worker.js'))) break;
    await sleep(100);
  }

  if (!extensionTargets.length) {
    throw new Error(`Browser Agent ${loadedExtensionId} produced no chrome-extension:// target in a real Chrome process`);
  }

  const serviceWorkers = extensionTargets.filter((t) => t.type === 'service_worker' && String(t.url ?? '').endsWith('/service-worker.js'));
  if (!serviceWorkers.length) {
    throw new Error(`Browser Agent MV3 service worker /service-worker.js not observed: ${JSON.stringify(extensionTargets)}`);
  }

  const runtimeErrors = stderr
    .split(/\r?\n/)
    .filter((line) => /extension.*(error|failed)|uncaught|syntaxerror|referenceerror/i.test(line));
  if (runtimeErrors.length) {
    throw new Error(`Chrome reported extension runtime errors:\n${runtimeErrors.slice(0, 20).join('\n')}`);
  }

  console.log(`PASS real Chrome/Chromium loaded Browser Agent; browser=${version.Browser}; extensionId=${loadedExtensionId}; extensionTargets=${extensionTargets.length}; prstudioServiceWorkers=${serviceWorkers.length}`);
  exitCode = 0;
} catch (error) {
  console.error(`FAIL real Chrome Browser Agent smoke: ${error?.stack ?? error}`);
  if (stderr) console.error(`--- chrome stderr tail ---\n${stderr.slice(-12000)}`);
} finally {
  child.kill('SIGKILL');
  await sleep(100);
  await rm(userDataDir, { recursive: true, force: true });
}
process.exit(exitCode);
