import { spawn, spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const sleep = (ms) => new Promise((resolvePromise) => setTimeout(resolvePromise, ms));
const startedAt = new Date().toISOString();
const wpUrl = String(process.env.WP_URL || 'http://127.0.0.1:8082').replace(/\/+$/, '');
const wpPath = String(process.env.WP_PATH || '/tmp/prstudio-wp');
const wpCli = String(process.env.WP_CLI || '/tmp/wp-cli.phar');
const chrome = String(process.env.CHROME_BIN || '/usr/bin/google-chrome-stable');
const originA = String(process.env.REMOTE_E2E_ORIGIN_A || 'http://127.0.0.1:8083').replace(/\/+$/, '');
const originB = String(process.env.REMOTE_E2E_ORIGIN_B || 'http://127.0.0.1:8084').replace(/\/+$/, '');
const extensionDir = resolve('prstudio-unified-browser-agent');
const artifactDir = resolve(process.env.E2E_ARTIFACT_DIR || 'artifacts/remote-wordpress-chrome');
const forbiddenCodes = [
  'technical_tab_not_controlled',
  'target_origin_mismatch',
  'contract_executor_missing',
  'cdp_protocol_incompatible',
];

for (const [label, path] of [['Chrome', chrome], ['WP-CLI', wpCli], ['WordPress', wpPath], ['extension', extensionDir]]) {
  if (!existsSync(path)) throw new Error(`${label}_missing:${path}`);
}
await mkdir(artifactDir, { recursive: true });

const versionProbe = spawnSync(chrome, ['--version'], { encoding: 'utf8' });
const binaryVersion = `${versionProbe.stdout || ''}${versionProbe.stderr || ''}`.trim();
if (versionProbe.status !== 0 || !/Google Chrome|Chrome for Testing/i.test(binaryVersion) || /Chromium/i.test(binaryVersion)) {
  throw new Error(`google_chrome_required:${chrome}:${binaryVersion}`);
}

function wpEval(code) {
  const result = spawnSync('php', [wpCli, 'eval', code, `--path=${wpPath}`], {
    encoding: 'utf8', timeout: 30000, maxBuffer: 8 * 1024 * 1024,
  });
  if (result.status !== 0) {
    throw new Error(`wp_eval_failed:${String(result.stderr || result.stdout).slice(0, 4000)}`);
  }
  return String(result.stdout || '').trim();
}

function wpJson(code) {
  const output = wpEval(code);
  const lines = output.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
  for (let index = lines.length - 1; index >= 0; index -= 1) {
    try { return JSON.parse(lines[index]); } catch {}
  }
  throw new Error(`wp_json_invalid:${output.slice(0, 4000)}`);
}

function phpJsonLiteral(value) {
  return Buffer.from(JSON.stringify(value), 'utf8').toString('base64');
}

function createRemoteTask(deviceId, action, args) {
  const encoded = phpJsonLiteral(args);
  const code = `$a=json_decode(base64_decode('${encoded}'),true);$r=PRSTUDIO_UC_Job_Engine::create_browser_task('${action}',$a,'${deviceId}','');echo wp_json_encode($r);`;
  const task = wpJson(code);
  if (!task?.task_uuid) throw new Error(`remote_task_create_failed:${JSON.stringify(task)}`);
  return task;
}

function readRemoteTask(taskId) {
  return wpJson(`$r=PRSTUDIO_UC_Store::get_task('${taskId}');echo wp_json_encode($r);`);
}

async function waitRemoteTask(taskId, timeoutMs = 60000) {
  const deadline = Date.now() + timeoutMs;
  let last = null;
  while (Date.now() < deadline) {
    last = readRemoteTask(taskId);
    const status = String(last?.status || '').toLowerCase();
    if (status === 'completed') return last;
    if (['failed', 'cancelled', 'expired', 'technical_error', 'dead_letter'].includes(status)) {
      throw new Error(`remote_task_terminal_failure:${taskId}:${status}:${JSON.stringify(last?.error || {})}`);
    }
    await sleep(300);
  }
  throw new Error(`remote_task_timeout:${taskId}:${JSON.stringify({status:last?.status,step_index:last?.step_index,error:last?.error})}`);
}

const userDataDir = await mkdtemp(join(tmpdir(), 'prstudio-remote-owned-'));
const debugPort = 9600 + (process.pid % 250);
const chromeArgs = [
  '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--disable-background-networking',
  '--disable-default-apps', '--disable-sync', '--no-first-run',
  `--remote-debugging-port=${debugPort}`,
  `--user-data-dir=${userDataDir}`,
  `--disable-extensions-except=${extensionDir}`,
  `--load-extension=${extensionDir}`,
  'about:blank',
];
const child = spawn(chrome, chromeArgs, { stdio: ['ignore', 'pipe', 'pipe'] });
let chromeStdout = '';
let chromeStderr = '';
child.stdout.on('data', (chunk) => { chromeStdout = (chromeStdout + chunk.toString()).slice(-500000); });
child.stderr.on('data', (chunk) => { chromeStderr = (chromeStderr + chunk.toString()).slice(-500000); });

async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`${response.status}:${url}`);
  return response.json();
}

async function waitChrome() {
  let last;
  for (let i = 0; i < 200; i += 1) {
    try {
      const version = await fetchJson(`http://127.0.0.1:${debugPort}/json/version`);
      if (version?.webSocketDebuggerUrl) return version;
    } catch (error) { last = error; }
    await sleep(100);
  }
  throw last || new Error('chrome_devtools_unavailable');
}

class Cdp {
  constructor(url) {
    this.ws = new WebSocket(url);
    this.id = 1;
    this.pending = new Map();
    this.opened = new Promise((resolvePromise, reject) => {
      this.ws.addEventListener('open', resolvePromise, { once: true });
      this.ws.addEventListener('error', () => reject(new Error('browser_cdp_open_failed')), { once: true });
    });
    this.ws.addEventListener('message', (event) => {
      const msg = JSON.parse(String(event.data));
      if (!msg.id) return;
      const waiter = this.pending.get(msg.id);
      if (!waiter) return;
      this.pending.delete(msg.id);
      clearTimeout(waiter.timer);
      if (msg.error) waiter.reject(new Error(`CDP ${waiter.method}: ${JSON.stringify(msg.error)}`));
      else waiter.resolve(msg.result);
    });
  }
  async send(method, params = {}, sessionId = undefined, timeoutMs = 15000) {
    await this.opened;
    const id = this.id++;
    return new Promise((resolvePromise, reject) => {
      const timer = setTimeout(() => { this.pending.delete(id); reject(new Error(`cdp_timeout:${method}`)); }, timeoutMs);
      this.pending.set(id, { resolve: resolvePromise, reject, timer, method });
      const payload = { id, method, params };
      if (sessionId) payload.sessionId = sessionId;
      this.ws.send(JSON.stringify(payload));
    });
  }
  close() { try { this.ws.close(); } catch {} }
}

async function attachPage(cdp, targetId) {
  const attached = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
  await cdp.send('Runtime.enable', {}, attached.sessionId);
  await cdp.send('Page.enable', {}, attached.sessionId);
  return attached.sessionId;
}

async function evaluate(cdp, sessionId, expression, timeoutMs = 15000) {
  const result = await cdp.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true }, sessionId, timeoutMs);
  if (result.exceptionDetails) throw new Error(`runtime_evaluate_failed:${result.exceptionDetails.text || 'exception'}`);
  return result.result?.value;
}

async function waitExpression(cdp, sessionId, expression, label, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs;
  let last;
  while (Date.now() < deadline) {
    try {
      last = await evaluate(cdp, sessionId, expression, 5000);
      if (last) return last;
    } catch (error) { last = String(error?.message || error); }
    await sleep(150);
  }
  throw new Error(`wait_expression_timeout:${label}:${JSON.stringify(last)}`);
}

async function targets(cdp) { return (await cdp.send('Target.getTargets')).targetInfos || []; }

async function waitWorker(cdp, previousTargetId = '') {
  let last = [];
  for (let i = 0; i < 200; i += 1) {
    last = await targets(cdp);
    const worker = last.find((target) => target.type === 'service_worker'
      && String(target.url || '').startsWith('chrome-extension://')
      && String(target.url || '').endsWith('/service-worker-bootstrap.js')
      && (!previousTargetId || target.targetId !== previousTargetId));
    if (worker) return worker;
    await sleep(100);
  }
  throw new Error(`mv3_worker_missing:${JSON.stringify(last.slice(0, 30))}`);
}

function publicTask(row) {
  return {
    task_uuid: row?.task_uuid || '', status: row?.status || '', action: row?.action || '',
    step_index: row?.step_index ?? null, attempt_count: row?.attempt_count ?? null,
    checkpoint: row?.checkpoint || null, verification: row?.verification || null,
    has_result: Boolean(row?.result), error: row?.error || null,
  };
}

const evidence = {
  schema_version: '1.0.0', ok: false, started_at: startedAt,
  chrome_binary: chrome, chrome_binary_version: binaryVersion,
  wp_url: wpUrl, origins: [originA, originB], checks: [], tasks: [],
};
function check(name, ok, detail = {}) {
  evidence.checks.push({ name, ok: Boolean(ok), at: new Date().toISOString(), ...detail });
  if (!ok) throw new Error(`${name}:${JSON.stringify(detail)}`);
  console.log(`PASS ${name}`);
}

let cdp;
let extensionId = '';
let extensionTargetId = '';
let extensionSessionId = '';
let workerTarget = null;
let deviceId = '';

async function extensionEval(expression, timeoutMs = 15000) {
  return evaluate(cdp, extensionSessionId, expression, timeoutMs);
}
async function storageSnapshot() {
  return extensionEval(`chrome.storage.local.get(['prstudioConfig','prstudioTabRegistry','prstudioTabAffinity','prstudioLastAgentTab','prstudioLogs','prstudioRuntimeSessions'])`);
}
async function assertNoForbiddenErrors(stage) {
  const snapshot = await storageSnapshot();
  const text = JSON.stringify(snapshot?.prstudioLogs || []).toLowerCase();
  const found = forbiddenCodes.filter((code) => text.includes(code));
  check(`no forbidden production error after ${stage}`, found.length === 0, { found });
}
function ownedRows(snapshot) {
  const raw = snapshot?.prstudioTabRegistry || {};
  return Object.values(raw).filter((row) => row && typeof row === 'object').map((row) => ({
    tabId: Number(row.tabId || 0), windowId: Number(row.windowId || 0), laneId: String(row.laneId || ''),
    taskId: String(row.taskId || ''), url: String(row.url || ''), expectedOrigin: String(row.expectedOrigin || ''),
    provisional: Boolean(row.provisional), ownership: String(row.ownership || row.owner || ''),
  }));
}

try {
  const chromeVersion = await waitChrome();
  evidence.chrome_product = chromeVersion.Browser || '';
  evidence.chrome_protocol_version = chromeVersion['Protocol-Version'] || '';
  cdp = new Cdp(chromeVersion.webSocketDebuggerUrl);
  check('Google Chrome process is real', /Chrome\//.test(evidence.chrome_product) && !/Chromium/i.test(evidence.chrome_product), { product: evidence.chrome_product });

  workerTarget = await waitWorker(cdp);
  extensionId = String(workerTarget.url).split('/')[2] || '';
  evidence.extension_id = extensionId;
  evidence.service_worker_target = workerTarget.url;
  check('exact MV3 bootstrap service worker is live', Boolean(extensionId) && workerTarget.url.endsWith('/service-worker-bootstrap.js'), { worker: workerTarget.url });

  const extensionTarget = await cdp.send('Target.createTarget', { url: `chrome-extension://${extensionId}/sidepanel.html` });
  extensionTargetId = extensionTarget.targetId;
  extensionSessionId = await attachPage(cdp, extensionTargetId);
  await waitExpression(cdp, extensionSessionId, `document.readyState === 'complete'`, 'extension page ready');
  const identity = await extensionEval(`(async()=>{const m=await import(chrome.runtime.getURL('lib/executor-meta.js'));return {manifest:chrome.runtime.getManifest().manifest_version,version:chrome.runtime.getManifest().version,build:m.EXECUTOR_BUILD_ID,source:m.EXECUTOR_SOURCE_SHA,builtAt:m.EXECUTOR_BUILD_TIMESTAMP,protocol:m.EXECUTOR_PROTOCOL_VERSION,capabilityHash:m.CAPABILITY_CONTRACT_SHA256,gsc:m.GSC_DIMENSION_SESSION_VERSION,ua:navigator.userAgent};})()`);
  evidence.browser_agent_identity = identity;
  check('Browser Agent build is bound to Git', /^prstudio-browser-1\.0\.0\+git\.[0-9a-f]{12}$/.test(identity?.build || '') && /^[0-9a-f]{40}$/.test(identity?.source || '') && identity?.builtAt !== 'UNSTAMPED', identity || {});
  check('Browser Agent contract identity is v4-compatible', identity?.protocol === '3.0.0' && identity?.gsc === '4.0.0' && /^[0-9a-f]{64}$/.test(identity?.capabilityHash || ''), identity || {});
  check('Chrome user agent is Chrome', /Chrome\//.test(identity?.ua || '') && !/Chromium/i.test(identity?.ua || ''), { ua: identity?.ua || '' });

  const pairingCode = wpEval(`echo PRSTUDIO_UC_Auth::create_pairing_code();`);
  if (!/^[A-Z0-9_-]{4,}$/.test(pairingCode)) throw new Error(`pairing_code_invalid_length:${pairingCode.length}`);
  const pairResult = await extensionEval(`chrome.runtime.sendMessage(${JSON.stringify({type:'pair', siteUrl:wpUrl, code:pairingCode, name:'CI Remote Ownership Certification'})})`, 25000);
  check('real WordPress pairing succeeds', pairResult?.ok === true && Boolean(pairResult?.deviceId), { deviceId: pairResult?.deviceId || '' });
  const paired = await storageSnapshot();
  deviceId = String(paired?.prstudioConfig?.deviceId || pairResult?.deviceId || '');
  evidence.device_uuid = deviceId;
  check('paired device UUID is persisted', /^[a-f0-9-]{20,64}$/i.test(deviceId), { deviceId });
  await assertNoForbiddenErrors('pairing');

  const laneA = 'ci-remote-lane-a';
  const firstArgs = {
    _prstudio_lane_id: laneA,
    steps: [
      { type: 'open_tab', url: `${originA}/parity-a.html`, expectedOrigin: originA },
      { type: 'wait_selector', selector: '#parity-button', timeoutMs: 10000 },
      { type: 'fill', selector: '#parity-input', value: 'remote-owned-one' },
      { type: 'press', selector: '#parity-input', key: 'End' },
      { type: 'click', selector: '#parity-button' },
      { type: 'javascript_exec', script: `console.log('prstudio-remote-console');document.body.dataset.remoteCdp='yes';fetch('/api.json').catch(()=>{});true`, risk: 'identity' },
      { type: 'dom_snapshot' },
      { type: 'accessibility_snapshot' },
      { type: 'screenshot' },
      { type: 'console_report' },
      { type: 'network_report' },
    ],
  };
  const firstCreated = createRemoteTask(deviceId, 'playwright_flow', firstArgs);
  const first = await waitRemoteTask(firstCreated.task_uuid, 75000);
  evidence.tasks.push(publicTask(first));
  check('remote task 1 claimed and completed through tasks/next', String(first.status).toLowerCase() === 'completed' && Number(first.attempt_count || 0) >= 1, publicTask(first));
  check('remote task 1 has checkpoint evidence', Number(first.step_index || 0) >= 1 && Boolean(first.checkpoint), { step_index:first.step_index, checkpoint:Boolean(first.checkpoint) });

  const afterFirst = await storageSnapshot();
  const firstOwned = ownedRows(afterFirst).filter((row) => row.laneId === laneA);
  check('Agent-created task tab is in owned registry', firstOwned.length === 1 && firstOwned[0].tabId > 0, { registry:firstOwned });
  const tabA = firstOwned[0].tabId;
  check('owned tab affinity is lane-scoped', JSON.stringify(afterFirst?.prstudioTabAffinity || {}).includes(laneA), { affinity: afterFirst?.prstudioTabAffinity || {} });
  const pageState = await extensionEval(`(async()=>{const r=await chrome.scripting.executeScript({target:{tabId:${tabA}},func:()=>({url:location.href,value:document.querySelector('#parity-input')?.value||'',clicked:document.body.dataset.clicked||'',remoteCdp:document.body.dataset.remoteCdp||''})});return r?.[0]?.result||null;})()`);
  check('remote fill/click/CDP mutation reached real page', pageState?.value === 'remote-owned-one' && pageState?.clicked === 'yes' && pageState?.remoteCdp === 'yes', pageState || {});

  const cdpEvidence = await extensionEval(`(async()=>{const d={tabId:${tabA}};let attached=false;try{try{await chrome.debugger.attach(d,'1.3');attached=true;}catch(e){if(!/already attached|another debugger/i.test(String(e?.message||e)))throw e;}const runtime=await chrome.debugger.sendCommand(d,'Runtime.evaluate',{expression:'document.title',returnByValue:true});const dom=await chrome.debugger.sendCommand(d,'DOM.getDocument',{depth:1});const shot=await chrome.debugger.sendCommand(d,'Page.captureScreenshot',{format:'png'});return {protocol:'1.3',title:runtime?.result?.value||'',rootNodeId:dom?.root?.nodeId||0,pngBytes:String(shot?.data||'').length,alreadyAttached:!attached};}finally{if(attached)await chrome.debugger.detach(d);}})()`, 20000);
  evidence.cdp = cdpEvidence;
  check('real Chrome CDP Runtime DOM Page commands succeed', cdpEvidence?.protocol === '1.3' && cdpEvidence?.rootNodeId > 0 && cdpEvidence?.pngBytes > 1000, cdpEvidence || {});
  await assertNoForbiddenErrors('remote task 1');

  const secondCreated = createRemoteTask(deviceId, 'playwright_flow', {
    _prstudio_lane_id: laneA,
    steps: [
      { type: 'navigate', url: `${originB}/parity-b.html`, expectedOrigin: originB },
      { type: 'wait_selector', selector: '#parity-button', timeoutMs: 10000 },
      { type: 'fill', selector: '#parity-input', value: 'remote-cross-origin-two' },
      { type: 'click', selector: '#parity-button' },
      { type: 'screenshot' },
    ],
  });
  const second = await waitRemoteTask(secondCreated.task_uuid, 75000);
  evidence.tasks.push(publicTask(second));
  check('second remote task same lane completes', String(second.status).toLowerCase() === 'completed', publicTask(second));
  const afterSecond = await storageSnapshot();
  const secondOwned = ownedRows(afterSecond).filter((row) => row.laneId === laneA);
  check('second task reuses same owned tab', secondOwned.length === 1 && secondOwned[0].tabId === tabA && secondOwned[0].taskId === second.task_uuid, { before:firstOwned, after:secondOwned });
  check('cross-origin navigation refreshes expected origin', secondOwned[0]?.url?.startsWith(originB) && (!secondOwned[0]?.expectedOrigin || secondOwned[0].expectedOrigin === originB), { record:secondOwned[0] || null });
  await assertNoForbiddenErrors('same-lane cross-origin rebind');

  const oldWorkerId = workerTarget.targetId;
  try { await extensionEval(`chrome.runtime.reload();true`, 5000); } catch {}
  workerTarget = await waitWorker(cdp, oldWorkerId);
  check('MV3 service worker restarts with same extension identity', String(workerTarget.url).startsWith(`chrome-extension://${extensionId}/`) && workerTarget.url.endsWith('/service-worker-bootstrap.js'), { worker:workerTarget.url });
  const newExtension = await cdp.send('Target.createTarget', { url: `chrome-extension://${extensionId}/sidepanel.html` });
  extensionTargetId = newExtension.targetId;
  extensionSessionId = await attachPage(cdp, extensionTargetId);
  await waitExpression(cdp, extensionSessionId, `document.readyState === 'complete'`, 'extension page after restart');
  const afterRestart = await storageSnapshot();
  check('ownership registry survives MV3 restart', ownedRows(afterRestart).some((row) => row.tabId === tabA && row.laneId === laneA), { registry:ownedRows(afterRestart) });

  const thirdCreated = createRemoteTask(deviceId, 'playwright_flow', {
    _prstudio_lane_id: laneA,
    steps: [
      { type: 'fill', selector: '#parity-input', value: 'after-worker-restart' },
      { type: 'press', selector: '#parity-input', key: 'End' },
      { type: 'click', selector: '#parity-button' },
      { type: 'screenshot' },
    ],
  });
  const third = await waitRemoteTask(thirdCreated.task_uuid, 75000);
  evidence.tasks.push(publicTask(third));
  check('remote task after MV3 restart completes', String(third.status).toLowerCase() === 'completed', publicTask(third));
  const postRestartState = await extensionEval(`(async()=>{const r=await chrome.scripting.executeScript({target:{tabId:${tabA}},func:()=>({value:document.querySelector('#parity-input')?.value||'',clicked:document.body.dataset.clicked||''})});return r?.[0]?.result||null;})()`);
  check('post-restart page runtime remains effective', postRestartState?.value === 'after-worker-restart' && postRestartState?.clicked === 'yes', postRestartState || {});
  await assertNoForbiddenErrors('MV3 restart recovery task');

  const laneB = 'ci-remote-lane-b';
  const fourthCreated = createRemoteTask(deviceId, 'playwright_flow', {
    _prstudio_lane_id: laneB,
    steps: [
      { type: 'open_tab', url: `${originA}/parity-a.html?second=1`, expectedOrigin: originA },
      { type: 'fill', selector: '#parity-input', value: 'second-owned-tab' },
      { type: 'screenshot' },
    ],
  });
  const fourth = await waitRemoteTask(fourthCreated.task_uuid, 75000);
  evidence.tasks.push(publicTask(fourth));
  const multi = await storageSnapshot();
  const rows = ownedRows(multi);
  const laneBRows = rows.filter((row) => row.laneId === laneB);
  check('multi-tab ownership is independent by lane', rows.some((row) => row.tabId === tabA && row.laneId === laneA) && laneBRows.length === 1 && laneBRows[0].tabId !== tabA, { registry:rows });
  const tabB = laneBRows[0].tabId;

  const closeCreated = createRemoteTask(deviceId, 'playwright_flow', {
    _prstudio_lane_id: laneB,
    steps: [{ type: 'close_tab' }],
  });
  const closedTask = await waitRemoteTask(closeCreated.task_uuid, 60000);
  evidence.tasks.push(publicTask(closedTask));
  const finalSnapshot = await storageSnapshot();
  const finalRows = ownedRows(finalSnapshot);
  const tabBExists = await extensionEval(`(async()=>{try{await chrome.tabs.get(${tabB});return true;}catch{return false;}})()`);
  check('remote close cleans owned tab and registry', tabBExists === false && !finalRows.some((row) => row.tabId === tabB), { tabB, registry:finalRows });
  check('primary owned tab remains controlled after other tab closes', finalRows.some((row) => row.tabId === tabA && row.laneId === laneA), { tabA, registry:finalRows });
  await assertNoForbiddenErrors('multi-tab close cleanup');

  const controlIdentity = wpJson(`echo wp_json_encode(json_decode(file_get_contents(PRSTUDIO_UC_DIR.'BUILD-INFO.json'),true));`);
  evidence.control_identity = controlIdentity;
  check('Control build identity is bound to same source SHA', /^prstudio-control-1\.0\.0\+git\.[0-9a-f]{12}$/.test(controlIdentity?.build_id || '') && controlIdentity?.source_commit === identity?.source && controlIdentity?.built_at_utc !== 'UNSTAMPED', { build_id:controlIdentity?.build_id, source_commit:controlIdentity?.source_commit });
  check('Browser and Control contract/protocol hashes are identical', controlIdentity?.contract_file_sha256 === (await extensionEval(`(async()=>{const b=await fetch(chrome.runtime.getURL('BUILD-INFO.json')).then(r=>r.json());return b.control_contract_sha256;})()`)) && controlIdentity?.protocol_file_sha256 === (await extensionEval(`(async()=>{const b=await fetch(chrome.runtime.getURL('BUILD-INFO.json')).then(r=>r.json());return b.control_protocol_sha256;})()`)), { contract_sha256:controlIdentity?.contract_file_sha256, protocol_sha256:controlIdentity?.protocol_file_sha256 });

  evidence.ownership_registry = finalRows;
  evidence.tab_affinity = finalSnapshot?.prstudioTabAffinity || {};
  evidence.total_remote_checks = evidence.checks.length;
  evidence.ok = evidence.checks.every((row) => row.ok) && evidence.tasks.every((row) => String(row.status).toLowerCase() === 'completed');
  evidence.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'remote-e2e.json'), JSON.stringify(evidence, null, 2));
  check('final remote E2E acceptance', evidence.ok, { checks:evidence.total_remote_checks, tasks:evidence.tasks.length });
  evidence.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'remote-e2e.json'), JSON.stringify(evidence, null, 2));
  console.log(`PASS remote WordPress→Chrome E2E: ${evidence.checks.length} checks, ${evidence.tasks.length} remote tasks`);
} catch (error) {
  evidence.error = { message:String(error?.message || error), stack:String(error?.stack || '').slice(0, 16000) };
  evidence.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'remote-e2e.json'), JSON.stringify(evidence, null, 2)).catch(() => {});
  console.error(`FAIL remote WordPress→Chrome E2E: ${error?.stack || error}`);
  process.exitCode = 1;
} finally {
  await writeFile(join(artifactDir, 'chrome-stdout.log'), chromeStdout).catch(() => {});
  await writeFile(join(artifactDir, 'chrome-stderr.log'), chromeStderr).catch(() => {});
  try { cdp?.close(); } catch {}
  try { child.kill('SIGKILL'); } catch {}
  await sleep(150);
  await rm(userDataDir, { recursive: true, force: true });
}
