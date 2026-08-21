import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const sleep = (ms) => new Promise((resolvePromise) => setTimeout(resolvePromise, ms));
const cycleCount = Math.max(10, Math.min(5000, Number(process.env.BROWSER2_REAL_CYCLES || 1000)));
const crossOriginCount = Math.max(10, Math.min(1000, Number(process.env.BROWSER2_CROSS_ORIGIN_CYCLES || 100)));
const artifactDir = resolve(process.env.BROWSER2_ARTIFACT_DIR || 'artifacts/browser-agent-2-certification');
const extensionDir = resolve('prstudio-unified-browser-agent');

const chromeCandidates = process.platform === 'darwin'
  ? ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome']
  : process.platform === 'win32'
    ? [process.env.CHROME_BIN, 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe']
    : [process.env.CHROME_BIN, '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable', '/usr/bin/chromium', '/usr/bin/chromium-browser'];
const chrome = chromeCandidates.filter(Boolean).find((candidate) => existsSync(candidate));
if (!chrome) throw new Error('real Chrome/Chromium binary not found');
if (!existsSync(extensionDir)) throw new Error(`Browser Agent directory missing: ${extensionDir}`);

await mkdir(artifactDir, { recursive: true });

function fixture(name) {
  const server = createServer((request, response) => {
    response.writeHead(200, { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' });
    response.end(`<!doctype html><title>${name}</title><main id="fixture">${name}:${request.url}</main>`);
  });
  return new Promise((resolvePromise, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      resolvePromise({ server, origin: `http://127.0.0.1:${address.port}` });
    });
  });
}

const fixtureA = await fixture('origin-a');
const fixtureB = await fixture('origin-b');
const userDataDir = await mkdtemp(join(tmpdir(), 'prstudio-browser2-'));
const debugPort = 9800 + (process.pid % 100);
const chromeArgs = [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage',
  '--disable-background-networking', '--disable-default-apps', '--disable-sync', '--no-first-run',
  `--remote-debugging-port=${debugPort}`, `--user-data-dir=${userDataDir}`,
  `--disable-extensions-except=${extensionDir}`, `--load-extension=${extensionDir}`, 'about:blank',
];
const child = spawn(chrome, chromeArgs, { stdio: ['ignore', 'pipe', 'pipe'] });
let chromeStdout = '';
let chromeStderr = '';
child.stdout.on('data', (chunk) => { chromeStdout = (chromeStdout + chunk.toString()).slice(-750000); });
child.stderr.on('data', (chunk) => { chromeStderr = (chromeStderr + chunk.toString()).slice(-750000); });

async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`${response.status} ${url}`);
  return response.json();
}

async function waitForChromeVersion() {
  let lastError;
  for (let i = 0; i < 200; i += 1) {
    try {
      const value = await fetchJson(`http://127.0.0.1:${debugPort}/json/version`);
      if (value?.webSocketDebuggerUrl) return value;
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
      this.ws.addEventListener('open', resolvePromise, { once: true });
      this.ws.addEventListener('error', () => reject(new Error('CDP websocket failed')), { once: true });
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
  async send(method, params = {}, sessionId = undefined, timeoutMs = 30000) {
    await this.opened;
    const id = this.nextId++;
    return new Promise((resolvePromise, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(id);
        reject(new Error(`CDP timeout ${method}`));
      }, timeoutMs);
      this.pending.set(id, { resolve: resolvePromise, reject, timer, method });
      const payload = { id, method, params };
      if (sessionId) payload.sessionId = sessionId;
      this.ws.send(JSON.stringify(payload));
    });
  }
  close() { try { this.ws.close(); } catch {} }
}

async function targetList(cdp) {
  return (await cdp.send('Target.getTargets')).targetInfos || [];
}

async function findWorker(cdp, extensionId = '') {
  const deadline = Date.now() + 30000;
  let last = [];
  while (Date.now() < deadline) {
    last = await targetList(cdp);
    const worker = last.find((target) => target.type === 'service_worker'
      && String(target.url || '').startsWith(`chrome-extension://${extensionId || ''}`)
      && String(target.url || '').endsWith('/service-worker.js'));
    if (worker) return worker;
    await sleep(100);
  }
  throw new Error(`Browser Agent service worker not found: ${JSON.stringify(last)}`);
}

async function attachTarget(cdp, targetId) {
  const attached = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
  await cdp.send('Runtime.enable', {}, attached.sessionId);
  return attached.sessionId;
}

async function evaluate(cdp, sessionId, expression, timeoutMs = 30000) {
  const result = await cdp.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
    userGesture: true,
  }, sessionId, timeoutMs);
  if (result.exceptionDetails) {
    throw new Error(`Runtime.evaluate: ${result.exceptionDetails.text || JSON.stringify(result.exceptionDetails)}`);
  }
  return result.result?.value;
}

async function wakeExtension(cdp, extensionId) {
  const target = await cdp.send('Target.createTarget', { url: `chrome-extension://${extensionId}/sidepanel.html` });
  const session = await attachTarget(cdp, target.targetId);
  await evaluate(cdp, session, 'chrome.runtime.sendMessage({type:"status"}).catch(() => null)', 15000).catch(() => null);
  return target.targetId;
}

const evidence = {
  ok: false,
  started_at: new Date().toISOString(),
  cycles_requested: cycleCount,
  cross_origin_cycles_requested: crossOriginCount,
  fixture_origins: [fixtureA.origin, fixtureB.origin],
  steps: [],
};
function pass(step, detail = {}) {
  const row = { step, ok: true, at: new Date().toISOString(), ...detail };
  evidence.steps.push(row);
  console.log(`PASS ${step}${detail.message ? ` — ${detail.message}` : ''}`);
}

let cdp;
try {
  const version = await waitForChromeVersion();
  evidence.chrome_version = version.Browser || '';
  cdp = new Cdp(version.webSocketDebuggerUrl);
  pass('Chrome started', { message: evidence.chrome_version });

  let targets = await targetList(cdp);
  let worker = targets.find((target) => target.type === 'service_worker' && String(target.url || '').startsWith('chrome-extension://') && String(target.url || '').endsWith('/service-worker.js'));
  if (!worker) {
    // Opening chrome://extensions is unnecessary; the extension background is
    // normally started by install. Give it one bounded wake window first.
    for (let i = 0; i < 100 && !worker; i += 1) {
      await sleep(100);
      targets = await targetList(cdp);
      worker = targets.find((target) => target.type === 'service_worker' && String(target.url || '').startsWith('chrome-extension://') && String(target.url || '').endsWith('/service-worker.js'));
    }
  }
  if (!worker) throw new Error('extension service worker was not observed');
  const extensionId = String(worker.url).split('/')[2];
  evidence.extension_id = extensionId;
  let workerSession = await attachTarget(cdp, worker.targetId);
  pass('Browser Agent service worker attached', { message: extensionId });

  const identity = await evaluate(cdp, workerSession, `(() => ({
    kernel: chrome.storage.local.__prstudioBrowserControlKernelVersion || '',
    source: globalThis.EXECUTOR_SOURCE_SHA || '',
    storageWritable: chrome.storage.local.__prstudioAtomicTabRegistryInstalled === true,
  }))()`);
  if (identity?.kernel !== '2.0.0' || !identity?.storageWritable) {
    throw new Error(`Browser Agent 2.0 kernel shim not active in real Chrome: ${JSON.stringify(identity)}`);
  }
  pass('real Chrome accepted Browser Agent 2.0 storage integration', { message: `kernel=${identity.kernel}` });

  const primary = await evaluate(cdp, workerSession, `(async () => {
    const tab = await chrome.tabs.create({url:${JSON.stringify(`${fixtureA.origin}/primary`)}, active:false});
    await chrome.storage.local.set({prstudioTabRegistry:{[String(tab.id)]:{
      tabId:tab.id, windowId:tab.windowId, controllerSessionId:'cert-chat-a', laneId:'cert-chat-a',
      ownershipNonce:'cert-primary', expectedOrigin:${JSON.stringify(fixtureA.origin)}, createdAt:Date.now(), updatedAt:Date.now()
    }}});
    await chrome.debugger.attach({tabId:tab.id}, '1.3').catch(() => {});
    const current = await chrome.tabs.get(tab.id);
    const group = await chrome.tabGroups.get(current.groupId);
    const registry = (await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry || {};
    return {tabId:tab.id, groupId:current.groupId, groupTitle:group.title, record:registry[String(tab.id)]};
  })()`);
  if (!primary?.tabId || primary.groupTitle !== 'PR STUDIO Agent · cert-chat-a') throw new Error(`primary claim/group failed: ${JSON.stringify(primary)}`);
  if (primary.record?.expectedOrigin) throw new Error(`origin remained coupled to ownership: ${JSON.stringify(primary.record)}`);
  pass('claimTab compatibility path creates controller-specific Chrome group');

  const crossOrigin = await evaluate(cdp, workerSession, `(async () => {
    const tabId=${Number(primary.tabId)};
    let failures=0;
    for(let i=0;i<${crossOriginCount};i+=1){
      const url=(i%2===0?${JSON.stringify(fixtureA.origin)}:${JSON.stringify(fixtureB.origin)})+'/cross-'+i;
      await chrome.tabs.update(tabId,{url});
      const registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};
      const record=registry[String(tabId)];
      if(!record||record.controllerSessionId!=='cert-chat-a'||record.expectedOrigin) failures+=1;
    }
    const tab=await chrome.tabs.get(tabId);
    return {failures,url:tab.url,groupId:tab.groupId};
  })()`, 180000);
  if (crossOrigin.failures !== 0) throw new Error(`cross-origin ownership failures=${crossOrigin.failures}`);
  pass('cross-origin ownership remains stable', { message: `${crossOriginCount} real navigations, 0 ownership failures` });

  const stress = await evaluate(cdp, workerSession, `(async () => {
    let failures=0;
    for(let i=0;i<${cycleCount};i+=1){
      const tab=await chrome.tabs.create({url:'about:blank',active:false});
      const registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};
      registry[String(tab.id)]={tabId:tab.id,windowId:tab.windowId,controllerSessionId:'stress-chat',laneId:'stress-chat',ownershipNonce:'stress-'+i,expectedOrigin:'',createdAt:Date.now(),updatedAt:Date.now()};
      await chrome.storage.local.set({prstudioTabRegistry:registry});
      const controlled=await chrome.tabs.get(tab.id);
      const group=await chrome.tabGroups.get(controlled.groupId).catch(()=>null);
      if(!group||group.title!=='PR STUDIO Agent · stress-chat') failures+=1;
      await chrome.tabs.update(tab.id,{url:(i%2===0?${JSON.stringify(fixtureA.origin)}:${JSON.stringify(fixtureB.origin)})+'/stress-'+i});
      const after=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};
      if(after[String(tab.id)]?.controllerSessionId!=='stress-chat') failures+=1;
      await chrome.tabs.remove(tab.id);
      if(i%100===0) await new Promise(r=>setTimeout(r,0));
    }
    return {cycles:${cycleCount},failures};
  })()`, 1800000);
  if (stress.failures !== 0) throw new Error(`1000-cycle Chrome stress failures=${stress.failures}`);
  pass('open -> navigate -> close stress', { message: `${stress.cycles} real Chrome cycles, 0 failures` });

  const isolation = await evaluate(cdp, workerSession, `(async () => {
    const controllers=['cert-chat-a','cert-chat-b','cert-chat-c'];
    const ids=[];
    const registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};
    for(let i=0;i<10;i+=1){
      const tab=await chrome.tabs.create({url:${JSON.stringify(fixtureA.origin)}+'/iso-'+i,active:false});
      const controller=controllers[i%controllers.length];
      registry[String(tab.id)]={tabId:tab.id,windowId:tab.windowId,controllerSessionId:controller,laneId:controller,ownershipNonce:'iso-'+i,createdAt:Date.now(),updatedAt:Date.now()};
      ids.push({id:tab.id,controller});
    }
    await chrome.storage.local.set({prstudioTabRegistry:registry});
    const rows=[];
    for(const item of ids){
      const tab=await chrome.tabs.get(item.id); const group=await chrome.tabGroups.get(tab.groupId);
      rows.push({id:item.id,controller:item.controller,groupTitle:group.title,groupId:group.id});
    }
    return {ids,rows};
  })()`);
  const badIsolation = isolation.rows.filter((row) => row.groupTitle !== `PR STUDIO Agent · ${row.controller}`);
  if (badIsolation.length) throw new Error(`controller isolation failed: ${JSON.stringify(badIsolation)}`);
  if (new Set(isolation.rows.map((row) => row.groupId)).size !== 3) throw new Error(`expected exactly 3 controller groups: ${JSON.stringify(isolation.rows)}`);
  pass('three controllers isolate ten simultaneous tabs');

  const popup = await evaluate(cdp, workerSession, `(async () => {
    const openerId=${Number(primary.tabId)};
    const user=await chrome.tabs.create({url:${JSON.stringify(fixtureA.origin)}+'/user-owned',active:false});
    const child=await chrome.tabs.create({url:${JSON.stringify(fixtureB.origin)}+'/popup',active:false,openerTabId:openerId});
    const registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};
    const childTab=await chrome.tabs.get(child.id); const opener=await chrome.tabs.get(openerId);
    return {childId:child.id,userId:user.id,childRecord:registry[String(child.id)]||null,userRecord:registry[String(user.id)]||null,childGroup:childTab.groupId,openerGroup:opener.groupId};
  })()`);
  if (popup.childRecord?.controllerSessionId !== 'cert-chat-a' || popup.childGroup !== popup.openerGroup) throw new Error(`popup inheritance failed: ${JSON.stringify(popup)}`);
  if (popup.userRecord) throw new Error(`unrelated user tab was adopted: ${JSON.stringify(popup.userRecord)}`);
  pass('popup inherits controller; unrelated user tab remains unowned');

  const drag = await evaluate(cdp, workerSession, `(async () => {
    const tabId=${Number(primary.tabId)}; const before=await chrome.tabs.get(tabId); const groupId=before.groupId;
    // Keep another tab in the group so Chrome does not delete the group object.
    const keeper=await chrome.tabs.create({url:${JSON.stringify(fixtureA.origin)}+'/keeper',active:false});
    let registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};
    registry[String(keeper.id)]={tabId:keeper.id,windowId:keeper.windowId,controllerSessionId:'cert-chat-a',laneId:'cert-chat-a',ownershipNonce:'keeper',createdAt:Date.now(),updatedAt:Date.now()};
    await chrome.storage.local.set({prstudioTabRegistry:registry});
    await chrome.tabs.ungroup(tabId);
    registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};
    const released=!registry[String(tabId)];
    await chrome.tabs.group({groupId,tabIds:[tabId]});
    registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};
    return {released,readopted:registry[String(tabId)]?.controllerSessionId||'',groupId};
  })()`);
  if (!drag.released || drag.readopted !== 'cert-chat-a') throw new Error(`drag in/out semantics failed: ${JSON.stringify(drag)}`);
  pass('drag out releases; drag back in readopts deterministically');

  const beforeReloadWorkerTarget = worker.targetId;
  await evaluate(cdp, workerSession, `chrome.storage.local.remove('prstudioTabRegistry')`);
  await evaluate(cdp, workerSession, `(() => { setTimeout(() => chrome.runtime.reload(), 0); return true; })()`).catch(() => null);
  await sleep(750);
  await wakeExtension(cdp, extensionId).catch(() => null);
  worker = await findWorker(cdp, extensionId);
  workerSession = await attachTarget(cdp, worker.targetId);
  const recovered = await evaluate(cdp, workerSession, `(async () => {
    const registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};
    const record=registry[String(${Number(primary.tabId)})]||null;
    return {record,kernel:chrome.storage.local.__prstudioBrowserControlKernelVersion||'',workerTarget:${JSON.stringify(beforeReloadWorkerTarget)}!==${JSON.stringify('')}?${JSON.stringify(beforeReloadWorkerTarget)}:''};
  })()`);
  if (recovered.kernel !== '2.0.0' || recovered.record?.controllerSessionId !== 'cert-chat-a') {
    throw new Error(`worker reload/reconstruction failed: ${JSON.stringify(recovered)}`);
  }
  pass('service-worker/extension reload reconstructs ownership from Chrome topology', { message: `worker ${beforeReloadWorkerTarget} -> ${worker.targetId}` });

  const buildIdentity = await evaluate(cdp, workerSession, `(async () => {
    const module=await import(chrome.runtime.getURL('lib/executor-meta.js'));
    return {source:module.EXECUTOR_SOURCE_SHA,built:module.EXECUTOR_BUILD_TIMESTAMP,id:module.EXECUTOR_BUILD_ID,protocol:module.EXECUTOR_PROTOCOL_VERSION,contract:module.CAPABILITY_CONTRACT_SHA256};
  })()`);
  evidence.build_identity = buildIdentity;
  if (!buildIdentity?.source || buildIdentity.source === 'UNSTAMPED' || !buildIdentity?.built || buildIdentity.built === 'UNSTAMPED' || /unbound/i.test(String(buildIdentity.id || ''))) {
    throw new Error(`uncertifiable Browser Agent build identity: ${JSON.stringify(buildIdentity)}`);
  }
  pass('build identity is stamped', { message: `${buildIdentity.id} ${buildIdentity.source.slice(0, 12)}` });

  evidence.ok = true;
  evidence.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'evidence.json'), JSON.stringify(evidence, null, 2));
  console.log(`PASS Browser Agent 2.0 real-Chrome certification cycles=${cycleCount} crossOrigin=${crossOriginCount}`);
} catch (error) {
  evidence.error = { message: error?.message || String(error), stack: error?.stack || '' };
  evidence.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'evidence.json'), JSON.stringify(evidence, null, 2));
  console.error(`FAIL Browser Agent 2.0 real-Chrome certification: ${error?.stack || error}`);
  process.exitCode = 1;
} finally {
  await writeFile(join(artifactDir, 'chrome-stdout.log'), chromeStdout);
  await writeFile(join(artifactDir, 'chrome-stderr.log'), chromeStderr);
  try { cdp?.close(); } catch {}
  try { child.kill('SIGKILL'); } catch {}
  await sleep(100);
  fixtureA.server.close();
  fixtureB.server.close();
  await rm(userDataDir, { recursive: true, force: true });
}
