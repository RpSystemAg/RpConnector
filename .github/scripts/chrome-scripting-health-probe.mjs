import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const sleep = (ms) => new Promise((resolvePromise) => setTimeout(resolvePromise, ms));
const wpUrl = String(process.env.WP_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const chrome = String(process.env.CHROME_BIN || '/usr/bin/chromium');
const extensionDir = resolve('prstudio-unified-browser-agent');
const artifactDir = resolve('artifacts/real-e2e');

if (!existsSync(chrome)) throw new Error(`Chrome/Chromium binary missing: ${chrome}`);
if (!existsSync(extensionDir)) throw new Error(`Browser Agent directory missing: ${extensionDir}`);
await mkdir(artifactDir, { recursive: true });

const manifest = JSON.parse(await readFile(join(extensionDir, 'manifest.json'), 'utf8'));
const expectedWorkerPath = String(manifest?.background?.service_worker || '').replace(/^\/+/, '');
if (!expectedWorkerPath) throw new Error('Browser Agent manifest does not declare background.service_worker');
const expectedWorkerSuffix = `/${expectedWorkerPath}`;

const userDataDir = await mkdtemp(join(tmpdir(), 'rpconnector-scripting-probe-'));
const debugPort = 9700 + (process.pid % 200);
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

let chromeStderr = '';
child.stderr.on('data', (chunk) => {
  chromeStderr += chunk.toString();
  if (chromeStderr.length > 200000) chromeStderr = chromeStderr.slice(-200000);
});

async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`${response.status} ${url}`);
  return response.json();
}

async function waitForChromeVersion() {
  let lastError;
  for (let i = 0; i < 300; i += 1) {
    try {
      const version = await fetchJson(`http://127.0.0.1:${debugPort}/json/version`);
      if (version?.webSocketDebuggerUrl) return version;
    } catch (error) {
      lastError = error;
    }
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
      this.ws.addEventListener('error', () => reject(new Error(`CDP WebSocket failed to open: ${wsUrl}`)));
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

  async send(method, params = {}, timeoutMs = 15000) {
    await this.opened;
    const id = this.nextId++;
    return new Promise((resolvePromise, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error(`CDP timeout: ${method}`));
      }, timeoutMs);
      this.pending.set(id, { resolve: resolvePromise, reject, timer, method });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  close() {
    try { this.ws.close(); } catch {}
  }
}

async function waitForTargetDescriptor(targetId, label, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  let lastTargets = [];
  while (Date.now() < deadline) {
    try {
      const targets = await fetchJson(`http://127.0.0.1:${debugPort}/json/list`);
      lastTargets = Array.isArray(targets) ? targets : [];
      const found = lastTargets.find((target) => String(target?.id || target?.targetId || '') === String(targetId));
      if (found?.webSocketDebuggerUrl) return found;
    } catch {}
    await sleep(100);
  }
  throw new Error(`Timed out waiting for direct DevTools target ${label} (${targetId}); targets=${JSON.stringify(lastTargets)}`);
}

async function evaluate(targetCdp, expression) {
  const result = await targetCdp.send('Runtime.evaluate', {
    expression,
    returnByValue: true,
    awaitPromise: true,
    userGesture: true,
  });
  if (result.exceptionDetails) {
    const detail = result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'exception';
    throw new Error(`Runtime.evaluate failed: ${detail}`);
  }
  return result.result?.value;
}

async function waitForExpression(targetCdp, expression, label, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  let last;
  while (Date.now() < deadline) {
    last = await evaluate(targetCdp, expression).catch((error) => ({ __error: error.message }));
    if (last && !last.__error) return last;
    await sleep(200);
  }
  throw new Error(`Timed out waiting for ${label}; last=${JSON.stringify(last)}`);
}

async function findBrowserAgentWorker(browserCdp) {
  const deadline = Date.now() + 30000;
  let lastTargets = [];
  while (Date.now() < deadline) {
    const { targetInfos = [] } = await browserCdp.send('Target.getTargets');
    lastTargets = targetInfos;
    const worker = targetInfos.find((target) => {
      const url = String(target.url || '');
      return target.type === 'service_worker'
        && url.startsWith('chrome-extension://')
        && url.endsWith(expectedWorkerSuffix);
    });
    if (worker) return worker;
    await sleep(200);
  }
  throw new Error(`Browser Agent worker ${expectedWorkerPath} not observed; targets=${JSON.stringify(lastTargets)}`);
}

let browserCdp;
let wpCdp;
let extensionCdp;
const report = {
  ok: false,
  started_at: new Date().toISOString(),
  wp_url: wpUrl,
  chrome_binary: chrome,
  expected_extension: {
    name: String(manifest?.name || ''),
    version: String(manifest?.version || ''),
    service_worker: expectedWorkerPath,
  },
};

try {
  const version = await waitForChromeVersion();
  report.chrome_version = version.Browser || '';
  browserCdp = new Cdp(version.webSocketDebuggerUrl);
  const worker = await findBrowserAgentWorker(browserCdp);
  const extensionId = String(worker.url).split('/')[2] || '';
  if (!extensionId) throw new Error(`Unable to derive Browser Agent extension id from ${worker.url}`);
  report.extension_id = extensionId;
  report.service_worker_url = worker.url;

  // Chromium 151 can invalidate flattened Target.attachToTarget sessions between
  // attach and Runtime.evaluate. Use each page target's own DevTools websocket
  // as the independent oracle instead. This is not a weaker probe: it still
  // drives the real Chromium target and leaves chrome.scripting execution to the
  // installed Browser Agent extension itself.
  const { targetId: wpTargetId } = await browserCdp.send('Target.createTarget', { url: `${wpUrl}/wp-login.php` });
  const wpTarget = await waitForTargetDescriptor(wpTargetId, 'WordPress page');
  wpCdp = new Cdp(wpTarget.webSocketDebuggerUrl);
  await waitForExpression(wpCdp, 'document.readyState === "complete"', 'WordPress probe page');

  const { targetId: extensionTargetId } = await browserCdp.send('Target.createTarget', { url: `chrome-extension://${extensionId}/sidepanel.html` });
  const extensionTarget = await waitForTargetDescriptor(extensionTargetId, 'Browser Agent sidepanel');
  extensionCdp = new Cdp(extensionTarget.webSocketDebuggerUrl);
  await waitForExpression(extensionCdp, 'document.readyState === "complete"', 'Browser Agent probe page');
  await browserCdp.send('Target.activateTarget', { targetId: wpTargetId });
  await sleep(250);

  report.probe = await evaluate(extensionCdp, `(async () => {
    const summarizeRows = (rows) => (Array.isArray(rows) ? rows : []).map((row) => ({
      frameId: row?.frameId ?? null,
      documentId: row?.documentId || '',
      hasResultProperty: Boolean(row && Object.prototype.hasOwnProperty.call(row, 'result')),
      resultIsNull: row?.result === null,
      resultType: typeof row?.result,
      resultKeys: row?.result && typeof row.result === 'object' ? Object.keys(row.result).slice(0, 40) : [],
      url: typeof row?.result?.url === 'string' ? row.result.url : '',
      title: typeof row?.result?.title === 'string' ? row.result.title : '',
      readyState: typeof row?.result?.readyState === 'string' ? row.result.readyState : '',
    }));
    const withTimeout = (promise, label, timeoutMs = 5000) => Promise.race([
      promise,
      new Promise((_, reject) => setTimeout(() => reject(new Error(label + '_timeout')), timeoutMs)),
    ]);
    const [tab] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
    const output = {
      tab: tab ? { id: tab.id, url: tab.url || '', title: tab.title || '' } : null,
      inline: { ok: false, rows: [], error: null },
      importedHealth: { ok: false, rows: [], error: null },
      importedResponsive: { ok: false, rows: [], error: null },
    };
    if (!tab?.id) return output;
    try {
      const rows = await withTimeout(chrome.scripting.executeScript({
        target: { tabId: tab.id, allFrames: false },
        func: () => ({ url: location.href, title: document.title || '', readyState: document.readyState }),
      }), 'inline_executeScript');
      output.inline.rows = summarizeRows(rows);
      output.inline.ok = Boolean(rows?.[0]?.result?.url);
    } catch (error) {
      output.inline.error = { name: error?.name || '', message: error?.message || String(error), stack: String(error?.stack || '').slice(0, 4000) };
    }

    let module = null;
    try {
      module = await withTimeout(import(chrome.runtime.getURL('lib/local-page-functions.js')), 'module_import');
    } catch (error) {
      const serialized = { name: error?.name || '', message: error?.message || String(error), stack: String(error?.stack || '').slice(0, 4000) };
      output.importedHealth.error = serialized;
      output.importedResponsive.error = serialized;
      return output;
    }

    try {
      const rows = await withTimeout(chrome.scripting.executeScript({
        target: { tabId: tab.id, allFrames: false },
        func: module.collectLocalPageHealth,
      }), 'health_executeScript');
      output.importedHealth.rows = summarizeRows(rows);
      output.importedHealth.ok = Boolean(rows?.[0]?.result?.url);
    } catch (error) {
      output.importedHealth.error = { name: error?.name || '', message: error?.message || String(error), stack: String(error?.stack || '').slice(0, 4000) };
    }

    try {
      const rows = await withTimeout(chrome.scripting.executeScript({
        target: { tabId: tab.id, allFrames: false },
        func: module.collectLocalResponsiveSnapshot,
      }), 'responsive_executeScript');
      output.importedResponsive.rows = summarizeRows(rows);
      output.importedResponsive.ok = Boolean(rows?.[0]?.result?.url);
    } catch (error) {
      output.importedResponsive.error = { name: error?.name || '', message: error?.message || String(error), stack: String(error?.stack || '').slice(0, 4000) };
    }
    return output;
  })()`);

  const tabUrl = String(report.probe?.tab?.url || '');
  const tabMatchesWordPress = tabUrl.startsWith(`${wpUrl}/`);
  report.ok = Boolean(
    tabMatchesWordPress
    && report.probe?.inline?.ok
    && report.probe?.importedHealth?.ok
    && report.probe?.importedResponsive?.ok
  );
  report.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'scripting-health-probe.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify(report.probe, null, 2));

  if (!tabMatchesWordPress) throw new Error(`Active tab mismatch: ${tabUrl || '[missing]'}`);
  if (!report.probe?.inline?.ok) throw new Error(`Minimal chrome.scripting probe failed: ${JSON.stringify(report.probe?.inline)}`);
  if (!report.probe?.importedHealth?.ok || !report.probe?.importedResponsive?.ok) {
    throw new Error(`Imported scripting functions failed: health=${JSON.stringify(report.probe?.importedHealth)} responsive=${JSON.stringify(report.probe?.importedResponsive)}`);
  }
  console.log('PASS Chrome scripting probe: exact Browser Agent worker, direct target CDP oracle, inline scripting, imported health and imported responsive functions returned real WordPress results');
} catch (error) {
  report.error = { message: error?.message || String(error), stack: String(error?.stack || '').slice(0, 12000) };
  report.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'scripting-health-probe.json'), JSON.stringify(report, null, 2)).catch(() => {});
  console.error(`FAIL Chrome scripting health probe: ${error?.message || error}`);
  if (chromeStderr) console.error(chromeStderr.slice(-12000));
  process.exitCode = 1;
} finally {
  extensionCdp?.close();
  wpCdp?.close();
  browserCdp?.close();
  try { child.kill('SIGTERM'); } catch {}
  await sleep(200);
  if (!child.killed) { try { child.kill('SIGKILL'); } catch {} }
  await rm(userDataDir, { recursive: true, force: true }).catch(() => {});
}
