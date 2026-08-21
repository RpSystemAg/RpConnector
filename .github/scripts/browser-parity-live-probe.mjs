import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const sleep = (ms) => new Promise((resolvePromise) => setTimeout(resolvePromise, ms));
const chrome = String(process.env.CHROME_BIN || '/usr/bin/chromium');
const baseUrl = String(process.env.WP_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const extensionDir = resolve('prstudio-unified-browser-agent');
const artifactDir = resolve('artifacts/browser-parity');
const debuggerProtocolVersion = '0.1';

if (!existsSync(chrome)) throw new Error(`Chrome/Chromium binary missing: ${chrome}`);
if (!existsSync(extensionDir)) throw new Error(`Browser Agent directory missing: ${extensionDir}`);
await mkdir(artifactDir, { recursive: true });

const userDataDir = await mkdtemp(join(tmpdir(), 'rpconnector-browser-parity-'));
const debugPort = 10100 + (process.pid % 300);
const child = spawn(chrome, [
  '--headless=new',
  '--no-sandbox',
  '--disable-gpu',
  '--disable-dev-shm-usage',
  '--disable-background-networking',
  '--disable-default-apps',
  '--disable-sync',
  '--no-first-run',
  `--remote-debugging-port=${debugPort}`,
  `--user-data-dir=${userDataDir}`,
  `--disable-extensions-except=${extensionDir}`,
  `--load-extension=${extensionDir}`,
  'about:blank',
], { stdio: ['ignore', 'pipe', 'pipe'] });

let stderr = '';
child.stderr.on('data', (chunk) => {
  stderr += chunk.toString();
  if (stderr.length > 200000) stderr = stderr.slice(-200000);
});

async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`${response.status} ${url}`);
  return response.json();
}

async function waitForBrowser() {
  let lastError;
  for (let i = 0; i < 300; i += 1) {
    try {
      const version = await fetchJson(`http://127.0.0.1:${debugPort}/json/version`);
      if (version?.webSocketDebuggerUrl) return version;
    } catch (error) { lastError = error; }
    await sleep(100);
  }
  throw lastError || new Error('Chrome DevTools endpoint unavailable');
}

class Cdp {
  constructor(wsUrl) {
    this.ws = new WebSocket(wsUrl);
    this.nextId = 1;
    this.pending = new Map();
    this.opened = new Promise((resolvePromise, reject) => {
      this.ws.addEventListener('open', resolvePromise);
      this.ws.addEventListener('error', () => reject(new Error('CDP WebSocket failed to open')));
    });
    this.ws.addEventListener('message', (event) => {
      const message = JSON.parse(String(event.data));
      if (!message.id) return;
      const pending = this.pending.get(message.id);
      if (!pending) return;
      this.pending.delete(message.id);
      clearTimeout(pending.timer);
      if (message.error) pending.reject(new Error(`CDP ${pending.method}: ${JSON.stringify(message.error)}`));
      else pending.resolve(message.result);
    });
  }

  async send(method, params = {}, sessionId = undefined, timeoutMs = 20000) {
    await this.opened;
    const id = this.nextId++;
    return new Promise((resolvePromise, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error(`CDP timeout: ${method}`));
      }, timeoutMs);
      this.pending.set(id, { resolve: resolvePromise, reject, timer, method });
      const payload = { id, method, params };
      if (sessionId) payload.sessionId = sessionId;
      this.ws.send(JSON.stringify(payload));
    });
  }

  close() { try { this.ws.close(); } catch {} }
}

async function waitForServiceWorker(cdp) {
  let lastTargets = [];
  for (let i = 0; i < 300; i += 1) {
    const { targetInfos = [] } = await cdp.send('Target.getTargets');
    lastTargets = targetInfos;
    const worker = targetInfos.find((target) =>
      target.type === 'service_worker'
      && String(target.url || '').startsWith('chrome-extension://'));
    if (worker) return worker;
    await sleep(100);
  }
  throw new Error(`Browser Agent service worker not observed: ${JSON.stringify(lastTargets)}`);
}

async function evaluate(cdp, sessionId, expression) {
  const result = await cdp.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
    userGesture: true,
  }, sessionId, 60000);
  if (result.exceptionDetails) {
    const detail = result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'exception';
    throw new Error(`Runtime.evaluate failed: ${detail}`);
  }
  return result.result?.value;
}

const report = {
  ok: false,
  started_at: new Date().toISOString(),
  base_url: baseUrl,
  chrome_binary: chrome,
  debugger_protocol_version: debuggerProtocolVersion,
};
let cdp;

try {
  const version = await waitForBrowser();
  report.chrome_version = version.Browser || '';
  cdp = new Cdp(version.webSocketDebuggerUrl);
  const worker = await waitForServiceWorker(cdp);
  report.extension_id = String(worker.url).split('/')[2] || '';
  report.service_worker_url = worker.url;

  const { sessionId } = await cdp.send('Target.attachToTarget', { targetId: worker.targetId, flatten: true });
  await cdp.send('Runtime.enable', {}, sessionId);

  const result = await evaluate(cdp, sessionId, `(async () => {
    const base = ${JSON.stringify(baseUrl)};
    const debuggerProtocolVersion = ${JSON.stringify(debuggerProtocolVersion)};
    const parity = globalThis.__PRSTUDIO_BROWSER_PARITY__ || null;
    if (!parity?.installed) throw new Error('browser parity bootstrap is not installed');

    const beforeWindows = await chrome.windows.getAll({ populate: true, windowTypes: ['normal'] });
    const urls = ['/parity-a.html', '/parity-b.html', '/parity-c.html'].map((path) => base + path);
    const tabs = [];
    for (const url of urls) {
      tabs.push(await chrome.tabs.create({ url, active: false }));
    }
    if (tabs.some((tab) => !tab?.id)) throw new Error('one or more parity tabs were not created');
    const windowIds = [...new Set(tabs.map((tab) => tab.windowId))];
    if (windowIds.length !== 1) throw new Error('parity tabs escaped into multiple Chrome windows: ' + JSON.stringify(windowIds));

    const groupId = await chrome.tabs.group({ tabIds: tabs.map((tab) => tab.id) });
    if (!(Number.isInteger(groupId) && groupId >= 0)) throw new Error('tab group was not created');

    await chrome.tabs.update(tabs[2].id, { active: true });
    await new Promise((resolve) => setTimeout(resolve, 250));

    const interaction = await chrome.scripting.executeScript({
      target: { tabId: tabs[0].id, allFrames: false },
      func: () => {
        const input = document.querySelector('#parity-input');
        const button = document.querySelector('#parity-button');
        if (!input || !button) return { ok: false, reason: 'controls_missing', url: location.href };
        input.value = 'persisted-control';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        button.click();
        return {
          ok: document.body?.dataset?.clicked === 'yes' && input.value === 'persisted-control',
          clicked: document.body?.dataset?.clicked || '',
          value: input.value,
          url: location.href,
          title: document.title,
        };
      },
    });
    const interactionResult = interaction?.[0]?.result || null;
    if (!interactionResult?.ok) throw new Error('lost control of first tab after switching active tab: ' + JSON.stringify(interactionResult));

    await chrome.debugger.attach({ tabId: tabs[0].id }, debuggerProtocolVersion);
    let screenshotBytes = 0;
    let browserVersion = null;
    try {
      browserVersion = await chrome.debugger.sendCommand({ tabId: tabs[0].id }, 'Browser.getVersion', {});
      if (!browserVersion?.protocolVersion || !browserVersion?.product) throw new Error('Browser.getVersion did not return CDP identity');
      await chrome.debugger.sendCommand({ tabId: tabs[0].id }, 'Page.enable', {});
      const shot = await chrome.debugger.sendCommand({ tabId: tabs[0].id }, 'Page.captureScreenshot', {
        format: 'png', fromSurface: false, captureBeyondViewport: false,
      });
      screenshotBytes = Math.ceil(String(shot?.data || '').length * 0.75);
      if (screenshotBytes < 100) throw new Error('screenshot evidence is empty');
    } finally {
      await chrome.debugger.detach({ tabId: tabs[0].id }).catch(() => {});
    }

    let fallbackError = null;
    try {
      await chrome.scripting.executeScript({
        target: { tabId: tabs[0].id, allFrames: false },
        func: () => { throw new Error('forced-crawl-observation-failure'); },
      });
    } catch (error) {
      fallbackError = {
        message: error?.message || String(error),
        details: error?.details || null,
      };
    }
    const fallbackStore = (await chrome.storage.local.get('prstudioLastVisualFallback'))?.prstudioLastVisualFallback || null;
    if (!fallbackStore?.captured || !fallbackStore?.screenshot) {
      throw new Error('visual screenshot fallback was not persisted after observation failure: ' + JSON.stringify({ fallbackError, fallbackStore }));
    }

    const firstStillThere = await chrome.tabs.get(tabs[0].id);
    if (!firstStillThere?.id || firstStillThere.groupId !== groupId) {
      throw new Error('first tab ownership/group did not persist');
    }

    const afterWindows = await chrome.windows.getAll({ populate: true, windowTypes: ['normal'] });
    return {
      parity,
      tabIds: tabs.map((tab) => tab.id),
      groupId,
      windowIds,
      beforeWindowIds: beforeWindows.map((row) => row.id),
      afterWindowIds: afterWindows.map((row) => row.id),
      interaction: interactionResult,
      debuggerProtocolVersion,
      browserVersion,
      screenshotBytes,
      visualFallback: {
        captured: fallbackStore.captured,
        tabId: fallbackStore.tabId,
        bytes: fallbackStore.bytes,
        reason: fallbackStore.reason,
      },
    };
  })()`);

  // Independent CDP oracle: count physical Chrome windows containing our three
  // local parity pages. The extension's patched windows API is not trusted for
  // this assertion.
  const { targetInfos = [] } = await cdp.send('Target.getTargets');
  const parityTargets = targetInfos.filter((target) =>
    target.type === 'page' && String(target.url || '').startsWith(`${baseUrl}/parity-`));
  const physicalWindowIds = new Set();
  for (const target of parityTargets) {
    const row = await cdp.send('Browser.getWindowForTarget', { targetId: target.targetId });
    if (Number.isInteger(row?.windowId)) physicalWindowIds.add(row.windowId);
  }
  if (parityTargets.length !== 3) throw new Error(`expected 3 parity pages, observed ${parityTargets.length}`);
  if (physicalWindowIds.size !== 1) throw new Error(`extension created/used multiple physical windows: ${JSON.stringify([...physicalWindowIds])}`);

  report.result = { ...result, physicalWindowIds: [...physicalWindowIds] };
  report.ok = true;
  report.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'browser-parity-live-probe.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report.result, null, 2));
  console.log('PASS Browser parity: one Chrome window, persistent multi-tab control, click/fill after tab switch, chrome.debugger 0.1 Browser.getVersion, CDP screenshot, visual fallback');
} catch (error) {
  report.error = { message: error?.message || String(error), stack: String(error?.stack || '').slice(0, 16000) };
  report.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'browser-parity-live-probe.json'), JSON.stringify(report, null, 2)).catch(() => {});
  console.error(`FAIL Browser parity live probe: ${error?.message || error}`);
  if (stderr) console.error(stderr.slice(-16000));
  process.exitCode = 1;
} finally {
  cdp?.close();
  try { child.kill('SIGTERM'); } catch {}
  await sleep(300);
  if (!child.killed) { try { child.kill('SIGKILL'); } catch {} }
  await rm(userDataDir, { recursive: true, force: true }).catch(() => {});
}
