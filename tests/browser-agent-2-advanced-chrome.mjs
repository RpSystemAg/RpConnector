import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { basename, join, resolve } from 'node:path';

const sleep = (ms) => new Promise((resolvePromise) => setTimeout(resolvePromise, ms));
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

let childOrigin = '';
function htmlResponse(response, html) {
  response.writeHead(200, { 'content-type': 'text/html; charset=utf-8', 'cache-control': 'no-store' });
  response.end(html);
}
function fixtureB() {
  const server = createServer((request, response) => {
    const url = new URL(request.url || '/', 'http://fixture.invalid');
    if (url.pathname === '/child') {
      return htmlResponse(response, `<!doctype html><meta charset="utf-8"><title>child-shadow</title>
        <child-shell id="child-host"></child-shell>
        <script>
          setTimeout(() => {
            customElements.define('child-shell', class extends HTMLElement {
              connectedCallback() {
                if (this.shadowRoot) return;
                const root = this.attachShadow({mode:'open'});
                root.innerHTML = '<button aria-label="Child Shadow Action" id="child-shadow-button">Child Shadow Action</button>';
              }
            });
          }, 180);
        </script>`);
    }
    response.writeHead(404); response.end('not found');
  });
  return new Promise((resolvePromise, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      resolvePromise({ server, origin: `http://127.0.0.1:${address.port}` });
    });
  });
}
function fixtureA() {
  const server = createServer((request, response) => {
    const url = new URL(request.url || '/', 'http://fixture.invalid');
    if (url.pathname === '/wp-json/prstudio-unified/v1/pair') {
      response.writeHead(200, { 'content-type': 'application/json', 'cache-control': 'no-store' });
      response.end(JSON.stringify({
        device_id: 'mismatch-device', token: 'mismatch-token', api_base: `${server.__origin}/wp-json/prstudio-unified/v1`,
        browser_executor_protocol: '999.0.0', browser_executor_protocols_accepted: ['999.0.0'], server_capabilities: {},
      }));
      return;
    }
    if (url.pathname === '/api') {
      response.writeHead(200, { 'content-type': 'application/json', 'cache-control': 'no-store' });
      response.end(JSON.stringify({ ok: true, marker: 'browser2-network-marker' }));
      return;
    }
    if (url.pathname === '/download') {
      const body = 'PR STUDIO Browser Agent 2 download certification\n';
      response.writeHead(200, {
        'content-type': 'text/plain; charset=utf-8',
        'content-disposition': 'attachment; filename="browser2-download.txt"',
        'content-length': Buffer.byteLength(body),
        'cache-control': 'no-store',
      });
      response.end(body);
      return;
    }
    if (url.pathname === '/auth') {
      return htmlResponse(response, `<!doctype html><meta charset="utf-8"><title>auth-handoff</title>
        <main><label>Verification code <input id="otp" autocomplete="one-time-code" aria-label="Verification code"></label></main>
        <script>setTimeout(() => document.querySelector('#otp')?.remove(), 1650);</script>`);
    }
    if (url.pathname === '/advanced') {
      const auto = url.searchParams.get('autodownload') === '1';
      return htmlResponse(response, `<!doctype html><meta charset="utf-8"><title>advanced-browser2</title>
        <main-shell id="main-host"></main-shell>
        <input id="upload" type="file">
        <a id="download" download="browser2-download.txt" href="/download">Download fixture</a>
        <iframe id="child-frame" src="${childOrigin}/child" style="width:600px;height:220px"></iframe>
        <script>
          setTimeout(() => {
            customElements.define('main-shell', class extends HTMLElement {
              connectedCallback() {
                if (this.shadowRoot) return;
                const root = this.attachShadow({mode:'open'});
                root.innerHTML = '<button aria-label="Main Shadow Action" id="main-shadow-button">Main Shadow Action</button><input aria-label="Main Shadow Name" id="main-shadow-input">';
              }
            });
          }, 120);
          fetch('/api?run=' + Date.now()).then(r => r.json()).then(() => console.info('browser2-fetch-complete'));
          setTimeout(() => { throw new Error('browser2-console-marker'); }, 280);
          ${auto ? "setTimeout(() => document.querySelector('#download')?.click(), 1100);" : ''}
        </script>`);
    }
    return htmlResponse(response, '<!doctype html><title>fixture-home</title>fixture-home');
  });
  return new Promise((resolvePromise, reject) => {
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
      const address = server.address();
      server.__origin = `http://127.0.0.1:${address.port}`;
      resolvePromise({ server, origin: server.__origin });
    });
  });
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

async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`${response.status} ${url}`);
  return response.json();
}
async function waitVersion(port) {
  let lastError;
  for (let i = 0; i < 250; i += 1) {
    try {
      const value = await fetchJson(`http://127.0.0.1:${port}/json/version`);
      if (value?.webSocketDebuggerUrl) return value;
    } catch (error) { lastError = error; }
    await sleep(100);
  }
  throw lastError || new Error('Chrome DevTools endpoint unavailable');
}
async function targets(cdp) { return (await cdp.send('Target.getTargets')).targetInfos || []; }
async function findWorker(cdp, extensionId = '') {
  const deadline = Date.now() + 30000;
  while (Date.now() < deadline) {
    const rows = await targets(cdp);
    const worker = rows.find((target) => target.type === 'service_worker'
      && String(target.url || '').startsWith(`chrome-extension://${extensionId || ''}`)
      && String(target.url || '').endsWith('/service-worker.js'));
    if (worker) return worker;
    await sleep(100);
  }
  throw new Error('Browser Agent service worker not found');
}
async function attach(cdp, targetId) {
  const row = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
  await cdp.send('Runtime.enable', {}, row.sessionId);
  return row.sessionId;
}
async function evaluate(cdp, sessionId, expression, timeoutMs = 30000) {
  const result = await cdp.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true }, sessionId, timeoutMs);
  if (result.exceptionDetails) throw new Error(`Runtime.evaluate: ${result.exceptionDetails.text || JSON.stringify(result.exceptionDetails)}`);
  return result.result?.value;
}
async function wakeExtension(cdp, extensionId) {
  const target = await cdp.send('Target.createTarget', { url: `chrome-extension://${extensionId}/sidepanel.html` });
  const session = await attach(cdp, target.targetId);
  await evaluate(cdp, session, 'chrome.runtime.sendMessage({type:"status"}).catch(() => null)', 15000).catch(() => null);
  return { targetId: target.targetId, session };
}

const fixtureChild = await fixtureB();
childOrigin = fixtureChild.origin;
const fixtureMain = await fixtureA();
const userDataDir = await mkdtemp(join(tmpdir(), 'prstudio-browser2-advanced-'));
const uploadPath = join(userDataDir, 'browser2-upload.txt');
await writeFile(uploadPath, 'PR STUDIO Browser Agent 2 upload certification\n');
const debugPort = 10000 + (process.pid % 500);
const baseArgs = [
  '--headless=new', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--disable-background-networking',
  '--disable-default-apps', '--disable-sync', '--no-first-run', `--remote-debugging-port=${debugPort}`,
  `--user-data-dir=${userDataDir}`, `--disable-extensions-except=${extensionDir}`, `--load-extension=${extensionDir}`,
];
let child = null;
let chromeStdout = '';
let chromeStderr = '';
function launch(extra = ['about:blank']) {
  const proc = spawn(chrome, [...baseArgs, ...extra], { stdio: ['ignore', 'pipe', 'pipe'] });
  proc.stdout.on('data', (chunk) => { chromeStdout = (chromeStdout + chunk.toString()).slice(-1_000_000); });
  proc.stderr.on('data', (chunk) => { chromeStderr = (chromeStderr + chunk.toString()).slice(-1_000_000); });
  child = proc;
  return proc;
}
async function waitExit(proc, timeoutMs = 15000) {
  if (!proc || proc.exitCode !== null) return;
  await Promise.race([
    new Promise((resolvePromise) => proc.once('exit', resolvePromise)),
    sleep(timeoutMs).then(() => { try { proc.kill('SIGKILL'); } catch {} }),
  ]);
}

const evidence = { ok: false, started_at: new Date().toISOString(), fixture_origins: [fixtureMain.origin, fixtureChild.origin], checks: [] };
function pass(name, detail = {}) {
  evidence.checks.push({ name, ok: true, at: new Date().toISOString(), ...detail });
  console.log(`PASS ${name}${detail.message ? ` — ${detail.message}` : ''}`);
}

let cdp = null;
try {
  launch();
  let version = await waitVersion(debugPort);
  evidence.chrome_version = version.Browser || '';
  cdp = new Cdp(version.webSocketDebuggerUrl);
  let worker = await findWorker(cdp);
  const extensionId = String(worker.url).split('/')[2];
  evidence.extension_id = extensionId;
  let workerSession = await attach(cdp, worker.targetId);
  pass('advanced Chrome service worker attached', { message: `${extensionId} ${evidence.chrome_version}` });

  const side = await wakeExtension(cdp, extensionId);
  const mismatch = await evaluate(cdp, side.session, `(async () => {
    const result = await chrome.runtime.sendMessage({type:'pair',siteUrl:${JSON.stringify(fixtureMain.origin)},code:'protocol-mismatch-cert',name:'Protocol mismatch certification'});
    const stored = await chrome.storage.local.get('prstudioConfig');
    return {result,config:stored.prstudioConfig||null};
  })()`);
  if (mismatch?.result?.ok !== false || mismatch?.result?.error?.code !== 'executor_protocol_mismatch' || mismatch.config) throw new Error(`protocol mismatch was not fail-fast before config/polling: ${JSON.stringify(mismatch)}`);
  pass('protocol mismatch fails before pairing/config dispatch', { message: mismatch.result.error.code });

  const claimed = await evaluate(cdp, workerSession, `(async () => {
    const tab=await chrome.tabs.create({url:${JSON.stringify(`${fixtureMain.origin}/advanced`)},active:false});
    await chrome.storage.local.set({prstudioTabRegistry:{[String(tab.id)]:{tabId:tab.id,windowId:tab.windowId,controllerSessionId:'advanced-chat',laneId:'advanced-chat',ownershipNonce:'advanced-cert',createdAt:Date.now(),updatedAt:Date.now()}}});
    for(let i=0;i<100;i+=1){const current=await chrome.tabs.get(tab.id);if(current.status==='complete') break;await new Promise(r=>setTimeout(r,50));}
    const current=await chrome.tabs.get(tab.id); const group=await chrome.tabGroups.get(current.groupId);
    return {tabId:tab.id,groupId:current.groupId,groupTitle:group.title};
  })()`);
  const tabId = Number(claimed.tabId);
  if (!tabId || claimed.groupTitle !== 'PR STUDIO Agent · advanced-chat') throw new Error(`advanced ownership claim failed: ${JSON.stringify(claimed)}`);
  pass('advanced fixture owned by controller-specific Chrome group');

  const runtimeReady = await evaluate(cdp, workerSession, `(async () => {
    const mod=await import(chrome.runtime.getURL('service-worker.js')); const t=mod.__test;
    for(let i=0;i<160;i+=1){const count=t.runtimeFramesForTab(${tabId}).size;if(count>=2)return {frames:count};await new Promise(r=>setTimeout(r,50));}
    return {frames:t.runtimeFramesForTab(${tabId}).size};
  })()`, 20000);
  if (Number(runtimeReady.frames) < 2) throw new Error(`persistent multi-frame runtime did not attach: ${JSON.stringify(runtimeReady)}`);
  pass('persistent runtime attached main + cross-origin iframe', { message: `frames=${runtimeReady.frames}` });

  const shadow = await evaluate(cdp, workerSession, `(async () => {
    const mod=await import(chrome.runtime.getURL('service-worker.js')); const t=mod.__test; await new Promise(r=>setTimeout(r,500));
    const snapshot=await t.snapshotAcrossRuntimeFrames(${tabId},{includeInteractive:true,maxChars:50000},10000);
    const compact=(snapshot.interactive||[]).map(x=>({targetRef:x.targetRef,role:x.role,name:x.accessibleName,text:x.text,frameId:x.frameId,frameUrl:x.frameUrl,occluded:x.occluded}));
    const main=await t.locateAcrossRuntimeFrames(${tabId},'locate',{role:'button',name:'Main Shadow Action',intendedAction:'click'},10000);
    const child=await t.locateAcrossRuntimeFrames(${tabId},'locate',{role:'button',name:'Child Shadow Action',intendedAction:'click'},10000);
    return {frameCount:snapshot.frames?.length||0,compact,main:main?{targetRef:main.element?.targetRef,frameId:main.element?.frameId,name:main.element?.accessibleName,matched:main.matched}:null,child:child?{targetRef:child.element?.targetRef,frameId:child.element?.frameId,name:child.element?.accessibleName,matched:child.matched}:null};
  })()`, 30000);
  const names = new Set((shadow.compact || []).map((x) => x.name));
  if (!names.has('main shadow action') || !names.has('child shadow action') || !shadow.main?.targetRef || !shadow.child?.targetRef) throw new Error(`Shadow DOM semantic discovery failed: ${JSON.stringify(shadow)}`);
  if (Number(shadow.child.frameId || 0) === 0) throw new Error(`child Shadow target was not attributed to iframe: ${JSON.stringify(shadow.child)}`);
  pass('Shadow DOM semantic targeting works in main frame and iframe', { message: `frames=${shadow.frameCount}` });

  const stale = await evaluate(cdp, workerSession, `(async () => {
    const mod=await import(chrome.runtime.getURL('service-worker.js')); const t=mod.__test;
    const before=await t.locateAcrossRuntimeFrames(${tabId},'locate',{role:'button',name:'Main Shadow Action',intendedAction:'click'},10000); const oldRef=before?.element?.targetRef||'';
    await chrome.scripting.executeScript({target:{tabId:${tabId},frameIds:[0]},func:()=>{const root=document.querySelector('#main-host')?.shadowRoot;const old=root?.querySelector('#main-shadow-button');old?.remove();const next=document.createElement('button');next.id='main-shadow-button-v2';next.setAttribute('aria-label','Main Shadow Action');next.textContent='Main Shadow Action';root?.append(next);}});
    await new Promise(r=>setTimeout(r,250));
    const oldLookup=oldRef?await t.locateAcrossRuntimeFrames(${tabId},'locate',{targetRef:oldRef,intendedAction:'click'},5000):null;
    const fresh=await t.locateAcrossRuntimeFrames(${tabId},'locate',{role:'button',name:'Main Shadow Action',intendedAction:'click'},10000);
    return {oldRef,oldMatched:Boolean(oldLookup?.matched),freshRef:fresh?.element?.targetRef||'',freshMatched:Boolean(fresh?.matched)};
  })()`, 30000);
  if (!stale.oldRef || stale.oldMatched || !stale.freshMatched || !stale.freshRef || stale.freshRef === stale.oldRef) throw new Error(`stale target_ref invalidation failed: ${JSON.stringify(stale)}`);
  pass('detached Shadow target_ref is invalidated and fresh ref is issued');

  const screenshot = await evaluate(cdp, workerSession, `(async () => {const mod=await import(chrome.runtime.getURL('service-worker.js'));const image=await mod.__test.captureScreenshot(${tabId},false,false);return {width:Number(image?.width||0),height:Number(image?.height||0),prefix:String(image?.dataUrl||'').slice(0,32),bytes:String(image?.dataUrl||'').length};})()`, 30000);
  if (screenshot.width <= 0 || screenshot.height <= 0 || !screenshot.prefix.startsWith('data:image/') || screenshot.bytes < 100) throw new Error(`screenshot capture depends on unavailable storage/upload: ${JSON.stringify(screenshot)}`);
  pass('screenshot capture succeeds without pairing or artifact upload backend', { message: `${screenshot.width}x${screenshot.height}` });
  const screenshotDetach = await evaluate(cdp, workerSession, `(async () => {const mod=await import(chrome.runtime.getURL('service-worker.js'));try{return await mod.__test.debuggerDetachWithTimeout(${tabId},5000);}catch(error){return {detached:false,error:{code:error?.code||'',message:error?.message||String(error)}};}})()`);
  if (screenshotDetach?.error) throw new Error(`post-screenshot debugger detach failed: ${JSON.stringify(screenshotDetach)}`);
  pass('screenshot debugger cleanup is bounded before diagnostic re-attach');

  const debugCapture = await evaluate(cdp, workerSession, `(async () => {const mod=await import(chrome.runtime.getURL('service-worker.js'));const result=await mod.__test.localDebugCapture(true,{remote:true,laneId:'advanced-chat',tabId:${tabId}});const owned=await mod.__test.ownedTab(${tabId});return {result,owned:Boolean(owned),controller:owned?.controllerSessionId||owned?.laneId||''};})()`, 60000);
  const responseCount = Number(debugCapture?.result?.result?.network?.responseCount || 0);
  const consoleRows = debugCapture?.result?.result?.console || [];
  if (!debugCapture.owned || debugCapture.controller !== 'advanced-chat' || responseCount < 1 || !consoleRows.length) throw new Error(`network/console diagnostic capture incomplete or ownership lost: ${JSON.stringify(debugCapture)}`);
  pass('network + console capture uses debugger without losing UI ownership', { message: `responses=${responseCount} console=${consoleRows.length}` });

  const upload = await evaluate(cdp, workerSession, `(async () => {
    const mod=await import(chrome.runtime.getURL('service-worker.js')); const t=mod.__test;
    const action=await t.executeKnownContractAction({tabId:${tabId},taskId:'advanced-upload',arguments:{_prstudio_lane_id:'advanced-chat'}},{type:'contract_action',action:'playwright_upload_file',args:{tab_id:${tabId},selector:'#upload',files:[${JSON.stringify(uploadPath)}]}});
    const observed=await chrome.scripting.executeScript({target:{tabId:${tabId},frameIds:[0]},func:()=>{const f=document.querySelector('#upload')?.files?.[0];return f?{name:f.name,size:f.size,type:f.type}:{name:'',size:0,type:''};}});
    return {action,observed:observed?.[0]?.result||null,owned:Boolean(await t.ownedTab(${tabId}))};
  })()`, 30000);
  if (!upload.action?.executed || upload.observed?.name !== basename(uploadPath) || Number(upload.observed?.size || 0) <= 0 || !upload.owned) throw new Error(`verified upload failed: ${JSON.stringify(upload)}`);
  pass('file upload is verified in live DOM', { message: `${upload.observed.name} ${upload.observed.size}B` });

  await evaluate(cdp, workerSession, `(async () => {await chrome.tabs.update(${tabId},{url:${JSON.stringify(`${fixtureMain.origin}/advanced?autodownload=1`)}});for(let i=0;i<100;i+=1){const t=await chrome.tabs.get(${tabId});if(t.status==='complete')break;await new Promise(r=>setTimeout(r,50));}return true;})()`);
  const download = await evaluate(cdp, workerSession, `(async () => {
    const mod=await import(chrome.runtime.getURL('service-worker.js')); const t=mod.__test;
    const result=await t.executeKnownContractAction({tabId:${tabId},taskId:'advanced-download',arguments:{_prstudio_lane_id:'advanced-chat'}},{type:'contract_action',action:'playwright_download_wait',args:{tab_id:${tabId},timeout:15000}});
    await new Promise(r=>setTimeout(r,300)); const items=await chrome.downloads.search({limit:20,orderBy:['-startTime']}); const item=items.find(x=>String(x.filename||'').endsWith('browser2-download.txt'))||null;
    return {result,item:item?{id:item.id,filename:item.filename,state:item.state,fileSize:item.fileSize,exists:item.exists}:null,owned:Boolean(await t.ownedTab(${tabId}))};
  })()`, 30000);
  if (!download.result?.event || !download.item?.filename || download.item.state !== 'complete' || !download.owned) throw new Error(`verified download failed: ${JSON.stringify(download)}`);
  const downloadedText = await readFile(download.item.filename, 'utf8');
  if (!downloadedText.includes('Browser Agent 2 download certification')) throw new Error(`downloaded file contents do not match fixture: ${download.item.filename}`);
  pass('download event and downloaded bytes are verified', { message: `${download.item.filename} ${download.item.fileSize}B` });

  await evaluate(cdp, workerSession, `(async () => {await chrome.tabs.update(${tabId},{url:${JSON.stringify(`${fixtureMain.origin}/auth`)}});for(let i=0;i<100;i+=1){const t=await chrome.tabs.get(${tabId});if(t.status==='complete')break;await new Promise(r=>setTimeout(r,50));}return true;})()`);
  const auth = await evaluate(cdp, workerSession, `(async () => {const mod=await import(chrome.runtime.getURL('service-worker.js'));const t=mod.__test;const started=Date.now();const resolved=await t.waitForExternalAuthChallenge({taskId:'advanced-auth',tabId:${tabId},leaseToken:null},'captcha_or_mfa',{reason:'captcha_or_mfa',source:'certification'});const active=await t.getActiveTask();const owned=await t.ownedTab(${tabId});return {resolved,elapsed:Date.now()-started,authChallenge:active?.authChallenge||null,owned:Boolean(owned),controller:owned?.controllerSessionId||owned?.laneId||''};})()`, 30000);
  if (!auth.resolved?.resolved || auth.authChallenge || !auth.owned || auth.controller !== 'advanced-chat' || auth.elapsed < 900 || auth.elapsed > 15000) throw new Error(`auth handoff/resume failed: ${JSON.stringify(auth)}`);
  pass('OTP/MFA human handoff auto-resumes without ownership loss', { message: `${auth.elapsed}ms` });

  const beforeRestart = await evaluate(cdp, workerSession, `(async () => {const registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};const groups=await chrome.tabGroups.query({});return {registryCount:Object.keys(registry).length,groups:groups.filter(g=>String(g.title||'').startsWith('PR STUDIO Agent')).map(g=>({id:g.id,title:g.title}))};})()`);
  cdp.send('Browser.close').catch(() => {}); await waitExit(child, 15000); cdp.close(); cdp = null; await sleep(500);

  launch(['--restore-last-session']);
  version = await waitVersion(debugPort); cdp = new Cdp(version.webSocketDebuggerUrl);
  worker = await findWorker(cdp, extensionId).catch(async () => {await wakeExtension(cdp, extensionId);return findWorker(cdp, extensionId);});
  workerSession = await attach(cdp, worker.targetId);
  const afterRestart = await evaluate(cdp, workerSession, `(async () => {
    const mod=await import(chrome.runtime.getURL('service-worker.js'));await new Promise(r=>setTimeout(r,800));
    const registry=(await chrome.storage.local.get('prstudioTabRegistry')).prstudioTabRegistry||{};const tabs=await chrome.tabs.query({});const live=new Map(tabs.map(t=>[String(t.id),t]));const groups=await chrome.tabGroups.query({});const byGroup=new Map(groups.map(g=>[g.id,g]));const stale=[];const valid=[];
    for(const [key,row] of Object.entries(registry)){const tab=live.get(key);const group=tab?byGroup.get(tab.groupId):null;const ok=Boolean(tab&&group&&String(group.title||'').startsWith('PR STUDIO Agent · ')&&(row.controllerSessionId||row.laneId));(ok?valid:stale).push({key,tabId:row.tabId,controller:row.controllerSessionId||row.laneId||'',groupTitle:group?.title||'',tabExists:Boolean(tab)});}
    return {registryCount:Object.keys(registry).length,stale,valid,owned:(await mod.__test.listOwnedTabs()).length};
  })()`, 30000);
  if (afterRestart.stale.length) throw new Error(`Chrome process restart left ghost/stale ownership: ${JSON.stringify(afterRestart)}`);
  pass('full Chrome process restart reconciles or cleanly closes every controlled tab', { message: `before=${beforeRestart.registryCount} after=${afterRestart.registryCount} valid=${afterRestart.valid.length}` });

  evidence.ok = true; evidence.finished_at = new Date().toISOString(); evidence.before_process_restart = beforeRestart; evidence.after_process_restart = afterRestart;
  await writeFile(join(artifactDir, 'advanced-evidence.json'), JSON.stringify(evidence, null, 2));
  console.log('PASS Browser Agent 2.0 advanced real-Chrome certification');
} catch (error) {
  evidence.error = { message: error?.message || String(error), stack: error?.stack || '' }; evidence.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'advanced-evidence.json'), JSON.stringify(evidence, null, 2));
  console.error(`FAIL Browser Agent 2.0 advanced real-Chrome certification: ${error?.stack || error}`); process.exitCode = 1;
} finally {
  await writeFile(join(artifactDir, 'advanced-chrome-stdout.log'), chromeStdout); await writeFile(join(artifactDir, 'advanced-chrome-stderr.log'), chromeStderr);
  try { cdp?.close(); } catch {}
  if (child && child.exitCode === null) { try { child.kill('SIGKILL'); } catch {} await waitExit(child, 3000); }
  fixtureMain.server.close(); fixtureChild.server.close(); await rm(userDataDir, { recursive: true, force: true });
}
