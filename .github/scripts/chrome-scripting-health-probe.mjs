import { spawn } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
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
  }

  async send(method, params = {}, sessionId = undefined, timeoutMs = 15000) {
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

  close() {
    try { this.ws.close(); } catch {}
  }
}

async function attachPage(cdp, targetId) {
  const { sessionId } = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
  await cdp.send('Page.enable', {}, sessionId);
  await cdp.send('Runtime.enable', {}, sessionId);
  return sessionId;
}

async function evaluate(cdp, sessionId, expression) {
  const result = await cdp.send('Runtime.evaluate', {
    expression,
    returnByValue: true,
    awaitPromise: true,
    userGesture: true,
  }, sessionId);
  if (result.exceptionDetails) {
    const detail = result.exceptionDetails.exception?.description || result.exceptionDetails.text || 'exception';
    throw new Error(`Runtime.evaluate failed: ${detail}`);
  }
  return result.result?.value;
}

async function waitForExpression(cdp, sessionId, expression, label, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  let last;
  while (Date.now() < deadline) {
    last = await evaluate(cdp, sessionId, expression).catch((error) => ({ __error: error.message }));
    if (last && !last.__error) return last;
    await sleep(200);
  }
  throw new Error(`Timed out waiting for ${label}; last=${JSON.stringify(last)}`);
}

async function findExtensionId(cdp) {
  const deadline = Date.now() + 20000;
  let lastTargets = [];
  while (Date.now() < deadline) {
    const { targetInfos = [] } = await cdp.send('Target.getTargets');
    lastTargets = targetInfos;
    const worker = targetInfos.find((target) =>
      target.type === 'service_worker'
      && String(target.url || '').startsWith('chrome-extension://')
      && String(target.url || '').endsWith('/service-worker.js'));
    if (worker) return String(worker.url).split('/')[2];
    await sleep(200);
  }
  throw new Error(`Browser Agent service worker not observed; targets=${JSON.stringify(lastTargets)}`);
}

let cdp;
const report = {
  ok: false,
  started_at: new Date().toISOString(),
  wp_url: wpUrl,
  chrome_binary: chrome,
};

try {
  const version = await waitForChromeVersion();
  report.chrome_version = version.Browser || '';
  cdp = new Cdp(version.webSocketDebuggerUrl);
  const extensionId = await findExtensionId(cdp);
  report.extension_id = extensionId;

  const { targetId: wpTargetId } = await cdp.send('Target.createTarget', { url: `${wpUrl}/wp-login.php` });
  const wpSessionId = await attachPage(cdp, wpTargetId);
  await waitForExpression(cdp, wpSessionId, 'document.readyState === "complete"', 'WordPress probe page');

  const { targetId: extensionTargetId } = await cdp.send('Target.createTarget', { url: `chrome-extension://${extensionId}/sidepanel.html` });
  const extensionSessionId = await attachPage(cdp, extensionTargetId);
  await waitForExpression(cdp, extensionSessionId, 'document.readyState === "complete"', 'Browser Agent probe page');
  await cdp.send('Target.activateTarget', { targetId: wpTargetId });
  await sleep(250);

  report.probe = await evaluate(cdp, extensionSessionId, `(async () => {
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
  console.log('PASS Chrome scripting probe: inline, imported health and imported responsive functions returned real WordPress results');
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
