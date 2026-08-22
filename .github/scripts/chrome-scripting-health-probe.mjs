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
    this.ws.addEventListener('close', () => {
      for (const pending of this.pending.values()) {
        clearTimeout(pending.timer);
        pending.reject(new Error(`CDP socket closed while waiting for ${pending.method}`));
      }
      this.pending.clear();
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

async function findBrowserAgentWorker(cdp) {
  const deadline = Date.now() + 30000;
  let lastTargets = [];
  while (Date.now() < deadline) {
    const { targetInfos = [] } = await cdp.send('Target.getTargets');
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

async function waitForPageTarget(cdp, targetId, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  let last = null;
  while (Date.now() < deadline) {
    const { targetInfos = [] } = await cdp.send('Target.getTargets');
    last = targetInfos.find((target) => String(target.targetId || '') === String(targetId)) || null;
    if (last && String(last.url || '').startsWith(`${wpUrl}/`)) return last;
    await sleep(150);
  }
  throw new Error(`WordPress page target did not navigate to ${wpUrl}; last=${JSON.stringify(last)}`);
}

async function evaluateWorker(cdp, sessionId, expression) {
  const result = await cdp.send('Runtime.evaluate', {
    expression,
    returnByValue: true,
    awaitPromise: true,
    userGesture: true,
  }, sessionId, 60000);
  if (result.exceptionDetails) {
    const detail = result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'exception';
    throw new Error(`Runtime.evaluate failed: ${detail}`);
  }
  return result.result?.value;
}

let cdp;
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
  cdp = new Cdp(version.webSocketDebuggerUrl);

  const worker = await findBrowserAgentWorker(cdp);
  const extensionId = String(worker.url).split('/')[2] || '';
  if (!extensionId) throw new Error(`Unable to derive Browser Agent extension id from ${worker.url}`);
  report.extension_id = extensionId;
  report.service_worker_url = worker.url;

  const { sessionId } = await cdp.send('Target.attachToTarget', { targetId: worker.targetId, flatten: true });
  if (!sessionId) throw new Error('Target.attachToTarget returned no Browser Agent worker session');

  const { targetId: wpTargetId } = await cdp.send('Target.createTarget', { url: `${wpUrl}/wp-login.php` });
  await waitForPageTarget(cdp, wpTargetId);
  await cdp.send('Target.activateTarget', { targetId: wpTargetId });
  await sleep(350);

  // Drive the actual extension from its installed MV3 service worker. Chromium
  // 151's page-target DevTools sockets are not used as an oracle here: the
  // production behavior under test is chrome.scripting from the extension into
  // the real HTTP tab, so that is the readiness and execution oracle itself.
  report.probe = await evaluateWorker(cdp, sessionId, `(async () => {
    const expectedBase = ${JSON.stringify(wpUrl)};
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
    const withTimeout = (promise, label, timeoutMs = 10000) => Promise.race([
      promise,
      new Promise((_, reject) => setTimeout(() => reject(new Error(label + '_timeout')), timeoutMs)),
    ]);

    let tab = null;
    for (let attempt = 0; attempt < 100; attempt += 1) {
      const tabs = await chrome.tabs.query({});
      tab = tabs.find((row) => typeof row?.url === 'string' && row.url.startsWith(expectedBase + '/')) || null;
      if (tab?.id && tab.status === 'complete') break;
      await new Promise((resolve) => setTimeout(resolve, 100));
    }

    const output = {
      tab: tab ? { id: tab.id, url: tab.url || '', title: tab.title || '', status: tab.status || '' } : null,
      inline: { ok: false, rows: [], error: null },
      importedHealth: { ok: false, rows: [], error: null },
      importedResponsive: { ok: false, rows: [], error: null },
    };
    if (!tab?.id) return output;

    await chrome.tabs.update(tab.id, { active: true });

    try {
      const rows = await withTimeout(chrome.scripting.executeScript({
        target: { tabId: tab.id, allFrames: false },
        func: () => ({ url: location.href, title: document.title || '', readyState: document.readyState }),
      }), 'inline_executeScript');
      output.inline.rows = summarizeRows(rows);
      output.inline.ok = Boolean(rows?.[0]?.result?.url) && rows?.[0]?.result?.readyState === 'complete';
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

  if (!tabMatchesWordPress) throw new Error(`Browser Agent did not select the WordPress probe tab: ${tabUrl || '[missing]'}`);
  if (!report.probe?.inline?.ok) throw new Error(`Minimal chrome.scripting probe failed: ${JSON.stringify(report.probe?.inline)}`);
  if (!report.probe?.importedHealth?.ok || !report.probe?.importedResponsive?.ok) {
    throw new Error(`Imported scripting functions failed: health=${JSON.stringify(report.probe?.importedHealth)} responsive=${JSON.stringify(report.probe?.importedResponsive)}`);
  }
  console.log('PASS Chrome scripting probe: exact installed MV3 worker executed inline scripting plus imported health/responsive functions against a real HTTP tab');
} catch (error) {
  report.error = { message: error?.message || String(error), stack: String(error?.stack || '').slice(0, 12000) };
  report.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'scripting-health-probe.json'), JSON.stringify(report, null, 2)).catch(() => {});
  console.error(`FAIL Chrome scripting health probe: ${error?.message || error}`);
  if (chromeStderr) console.error(chromeStderr.slice(-12000));
  process.exitCode = 1;
} finally {
  cdp?.close();
  try { child.kill('SIGTERM'); } catch {}
  await sleep(200);
  if (!child.killed) { try { child.kill('SIGKILL'); } catch {} }
  await rm(userDataDir, { recursive: true, force: true }).catch(() => {});
}
