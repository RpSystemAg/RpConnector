import { execFileSync, spawn } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const sleep = (ms) => new Promise((resolvePromise) => setTimeout(resolvePromise, ms));
const wpUrl = String(process.env.WP_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
// Trimmed and asserted here rather than discovered later. WP-CLI rejects an
// empty --path with "The --path parameter cannot be empty when provided",
// which names the parameter and not the environment variable behind it, and
// that message arrives after WordPress, Chrome and the whole study have
// already run.
const wpPath = String(process.env.RP_WP_PATH || '/tmp/rpconnector-h24-wp').trim();
if (!wpPath) { throw new Error('RP_WP_PATH resolved to an empty path; wp-cli cannot be pointed at a WordPress install'); }
const adminUser = String(process.env.WP_ADMIN_USER || 'rpadmin');
const adminPassword = String(process.env.WP_ADMIN_PASSWORD || '');
const accessToken = String(process.env.MCP_ACCESS_TOKEN || '');
const deviceName = String(process.env.E2E_DEVICE_NAME || 'GitHub WordPress Study Chrome');
const artifactDir = resolve(process.env.E2E_ARTIFACT_DIR || 'artifacts/real-e2e/wordpress-study');
const extensionDir = resolve('prstudio-unified-browser-agent');
const fixturePath = String(process.env.WORDPRESS_STUDY_FIXTURE || '/tmp/wordpress-study-fixture.json');

if (!adminPassword || !accessToken) throw new Error('WP_ADMIN_PASSWORD and MCP_ACCESS_TOKEN are required');
await mkdir(artifactDir, { recursive: true });

const chromeCandidates = process.platform === 'darwin'
  ? ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome']
  : [process.env.CHROME_BIN, '/usr/bin/chromium', '/usr/bin/google-chrome', '/usr/bin/google-chrome-stable'].filter(Boolean);
const chrome = chromeCandidates.find((candidate) => existsSync(candidate));
if (!chrome) throw new Error('real Chrome/Chromium binary not found');
if (!existsSync(extensionDir)) throw new Error(`Browser Agent directory missing: ${extensionDir}`);

const evidence = {
  ok: false,
  started_at: new Date().toISOString(),
  wp_url: wpUrl,
  device_name: deviceName,
  prompts: [],
  public_calls: [],
  snapshots: {},
};
const userDataDir = await mkdtemp(join(tmpdir(), 'rpconnector-wordpress-study-'));
const debugPort = 9700 + (process.pid % 200);
const child = spawn(chrome, [
  // --window-size is not cosmetic here. Headless Chrome started on about:blank
  // with no window size reports a 0x0 viewport until something lays out, and
  // Page.captureScreenshot answers "Cannot take screenshot with 0 width" -- an
  // error that describes the page and blames the page, while the missing piece
  // is this flag. --hide-scrollbars keeps the captured width equal to the
  // viewport width so screenshots taken before and after a scroll compare.
  '--headless=new','--window-size=1280,900','--hide-scrollbars',
  '--no-sandbox','--disable-gpu','--disable-dev-shm-usage','--disable-background-networking',
  '--disable-default-apps','--disable-sync','--no-first-run',`--remote-debugging-port=${debugPort}`,
  `--user-data-dir=${userDataDir}`,`--disable-extensions-except=${extensionDir}`,`--load-extension=${extensionDir}`,'about:blank',
], { stdio: ['ignore', 'pipe', 'pipe'] });
let chromeStdout = '';
let chromeStderr = '';
child.stdout.on('data', (chunk) => { chromeStdout = (chromeStdout + chunk.toString()).slice(-500000); });
child.stderr.on('data', (chunk) => { chromeStderr = (chromeStderr + chunk.toString()).slice(-500000); });

class Cdp {
  constructor(wsUrl) {
    this.ws = new WebSocket(wsUrl); this.nextId = 1; this.pending = new Map();
    this.opened = new Promise((resolvePromise, reject) => {
      this.ws.addEventListener('open', resolvePromise);
      this.ws.addEventListener('error', () => reject(new Error('CDP websocket failed to open')));
    });
    this.ws.addEventListener('message', (event) => {
      const message = JSON.parse(String(event.data)); if (!message.id) return;
      const waiter = this.pending.get(message.id); if (!waiter) return;
      this.pending.delete(message.id); clearTimeout(waiter.timer);
      if (message.error) waiter.reject(new Error(`CDP ${waiter.method}: ${JSON.stringify(message.error)}`));
      else waiter.resolve(message.result);
    });
  }
  async send(method, params = {}, sessionId = undefined, timeoutMs = 15000) {
    await this.opened; const id = this.nextId++;
    return new Promise((resolvePromise, reject) => {
      const timer = setTimeout(() => { this.pending.delete(id); reject(new Error(`CDP timeout: ${method}`)); }, timeoutMs);
      this.pending.set(id, { resolve: resolvePromise, reject, timer, method });
      const payload = { id, method, params }; if (sessionId) payload.sessionId = sessionId; this.ws.send(JSON.stringify(payload));
    });
  }
  close() { try { this.ws.close(); } catch {} }
}

/**
 * Every request opens its own connection, and one retry survives a reset.
 *
 * Node's fetch (undici) keeps sockets in a pool and reuses them. The PHP
 * built-in server that hosts WordPress here closes a connection as soon as it
 * has answered, so undici writes the next request into a socket the server has
 * already shut, and the reset surfaces as the entirely uninformative
 * "TypeError: fetch failed".
 *
 * That is exactly what happened: in the same second, curl got HTTP 200 from
 * this endpoint in 0s while this script could not complete a single call.
 * curl opens a fresh connection per invocation and never meets the race.
 *
 * `Connection: close` opts out of pooling. The single retry covers the reset
 * that can still arrive on a connection closed between the DNS answer and the
 * first byte -- these calls are reads or idempotent MCP requests, so repeating
 * one is safe.
 */
const NO_KEEPALIVE = { Connection: 'close' };

async function fetchOnce(url, init) {
  const options = { ...init, headers: { ...NO_KEEPALIVE, ...(init?.headers || {}) } };
  try {
    return await fetch(url, options);
  } catch (error) {
    if (String(error?.message || '') !== 'fetch failed') throw error;
    await new Promise((resolve) => setTimeout(resolve, 250));
    return fetch(url, options);
  }
}

async function fetchJson(url) { const response = await fetchOnce(url); if (!response.ok) throw new Error(`${response.status} ${url}`); return response.json(); }
async function waitForChrome() {
  let last;
  for (let i = 0; i < 150; i += 1) { try { const v = await fetchJson(`http://127.0.0.1:${debugPort}/json/version`); if (v?.webSocketDebuggerUrl) return v; } catch (e) { last = e; } await sleep(100); }
  throw last || new Error('Chrome DevTools endpoint unavailable');
}
async function attachPage(cdp, targetId) { const a = await cdp.send('Target.attachToTarget', { targetId, flatten: true }); await cdp.send('Page.enable', {}, a.sessionId); await cdp.send('Runtime.enable', {}, a.sessionId); return a.sessionId; }
async function evaluate(cdp, sessionId, expression) {
  const result = await cdp.send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true, userGesture: true }, sessionId, 20000);
  if (result.exceptionDetails) throw new Error(`Runtime.evaluate failed: ${result.exceptionDetails.text || 'exception'}`);
  return result.result?.value;
}
async function waitForExpression(cdp, sessionId, expression, label, timeoutMs = 20000) {
  const deadline = Date.now() + timeoutMs; let lastValue; let lastError;
  while (Date.now() < deadline) { try { lastValue = await evaluate(cdp, sessionId, expression); if (lastValue) return lastValue; } catch (e) { lastError = e; } await sleep(200); }
  throw new Error(`Timed out waiting for ${label}; last=${lastError?.message || JSON.stringify(lastValue)}`);
}
async function navigate(cdp, sessionId, url) { await cdp.send('Page.navigate', { url }, sessionId); await waitForExpression(cdp, sessionId, 'document.readyState === "complete"', `load ${url}`, 25000); }
/**
 * Capture the page the way the extension already learned to.
 *
 * `fromSurface: true` photographs the compositor surface, and a headless tab
 * that is not the visible one has no surface: CDP answers "Cannot take
 * screenshot with 0 width", which reads as a fact about the page and is a fact
 * about the capture mode. This job failed on it twice, once after four
 * successful MCP calls and once before any -- the same run, intermittently,
 * because whether a surface exists depends on what else Chrome is doing.
 *
 * prstudio-unified-browser-agent/lib/screenshot-candidates.js solved this
 * inside the product: its chain ends with a renderer capture precisely because
 * every surface variant photographs nothing at all when there is no surface.
 * The test harness was still demanding one. It now pins an explicit viewport
 * and walks the same fallback, so a screenshot is evidence rather than a
 * coin toss.
 */
async function screenshot(cdp, sessionId, filename) {
  await cdp.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false }, sessionId).catch(() => {});
  const candidates = [
    { format: 'png', captureBeyondViewport: true, fromSurface: true },
    { format: 'png', fromSurface: true },
    { format: 'png', fromSurface: false },
  ];
  let lastError = null;
  for (const params of candidates) {
    try {
      const result = await cdp.send('Page.captureScreenshot', params, sessionId);
      if (result?.data) {
        await writeFile(join(artifactDir, filename), Buffer.from(result.data, 'base64'));
        return;
      }
    } catch (error) { lastError = error; }
  }
  throw lastError || new Error(`screenshot produced no data: ${filename}`);
}
async function findExtensionId(cdp) {
  const deadline = Date.now() + 15000;
  while (Date.now() < deadline) { const infos = (await cdp.send('Target.getTargets')).targetInfos || []; const worker = infos.find((t) => t.type === 'service_worker' && String(t.url || '').startsWith('chrome-extension://') && String(t.url || '').endsWith('/service-worker.js')); if (worker) return String(worker.url).split('/')[2]; await sleep(200); }
  throw new Error('Browser Agent service worker not observed');
}

function findKey(value, key) {
  const queue = [value]; const seen = new Set();
  while (queue.length) { const current = queue.shift(); if (!current || typeof current !== 'object' || seen.has(current)) continue; seen.add(current); if (Object.prototype.hasOwnProperty.call(current, key)) return current[key]; for (const childValue of Object.values(current)) if (childValue && typeof childValue === 'object') queue.push(childValue); }
  return undefined;
}
function allValuesForKey(value, key) {
  const out = []; const queue = [value]; const seen = new Set();
  while (queue.length) { const current = queue.shift(); if (!current || typeof current !== 'object' || seen.has(current)) continue; seen.add(current); if (Object.prototype.hasOwnProperty.call(current, key)) out.push(current[key]); for (const childValue of Object.values(current)) if (childValue && typeof childValue === 'object') queue.push(childValue); }
  return out;
}

let rpcId = 0;
async function mcpTool(name, args) {
  const payload = {
    jsonrpc: '2.0', id: `study-${++rpcId}`, method: 'tools/call',
    params: {
      name,
      arguments: args,
      _meta: {
        'io.modelcontextprotocol/protocolVersion': '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities': {},
        'io.modelcontextprotocol/clientInfo': { name: 'wordpress-study-real-e2e', version: '1.0' },
      },
    },
  };
  const response = await fetchOnce(`${wpUrl}/?rest_route=/prstudio-unified/v1/mcp`, {
    method: 'POST',
    headers: { Authorization: `Bearer ${accessToken}`, 'Content-Type': 'application/json', 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': 'tools/call', 'Mcp-Name': name },
    body: JSON.stringify(payload),
  });
  const body = await response.json();
  if (!response.ok || body.error || body.result?.isError) throw new Error(`MCP ${name} failed: ${response.status} ${JSON.stringify(body).slice(0, 4000)}`);
  const structured = body.result?.structuredContent ?? body.result?.structured_content ?? body.result ?? body;
  evidence.public_calls.push({ name, id: payload.id, response: structured });
  return structured;
}

function wpEval(code) {
  // execFileSync throws with the command in the message and the reason in
  // error.stderr, so an uncaught failure here reports the entire PHP snippet
  // and none of the PHP error -- which is how a run ended with fifteen lines
  // of the code that failed and nothing about why. Every diagnosis in this
  // job so far has come down to making the failure say the true thing, so
  // this one carries stderr with it.
  try {
    return execFileSync('wp', [`--path=${wpPath}`, 'eval', code], { encoding: 'utf8', env: process.env, maxBuffer: 16 * 1024 * 1024 }).trim();
  } catch (error) {
    const stderr = String(error?.stderr || '').trim();
    const stdout = String(error?.stdout || '').trim();
    throw new Error(`wp eval failed (status ${error?.status ?? '?'}) with --path=${wpPath}
-- stderr --
${stderr || '<empty>'}
-- stdout --
${stdout.slice(0, 2000) || '<empty>'}`);
  }
}
function moduleSnapshot() {
  const raw = wpEval(`
    $id=PRSTUDIO_UC_Site_Learning::module_id_for_url(admin_url('/'));
    $path=PRSTUDIO_UC_Memory::site_dir().'/site-modules/'.$id.'/module.json';
    $module=is_readable($path)?json_decode((string)file_get_contents($path),true):array();
    $twin=PRSTUDIO_UC_Operational_Twin::snapshot();
    $skills_path=PRSTUDIO_UC_Memory::site_dir().'/skills/procedural-skills-v1.json';
    $skills=is_readable($skills_path)?json_decode((string)file_get_contents($skills_path),true):array();
    echo wp_json_encode(array('module'=>$module,'twin'=>$twin,'skills'=>$skills),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  `);
  return JSON.parse(raw || '{}');
}
async function waitForJob(jobId, label) {
  const deadline = Date.now() + 12 * 60 * 1000; let last;
  while (Date.now() < deadline) {
    last = await mcpTool('prstudio_job_get', { job_id: jobId, wait_seconds: 20 });
    const status = String(findKey(last, 'status') || '').toUpperCase();
    if (['COMPLETED','TECHNICAL_ERROR','CANCELLED','DEAD_LETTER'].includes(status)) {
      if (status !== 'COMPLETED') throw new Error(`${label} terminal status ${status}: ${JSON.stringify(last).slice(0, 5000)}`);
      return last;
    }
    await sleep(500);
  }
  throw new Error(`${label} did not complete; last=${JSON.stringify(last).slice(0, 5000)}`);
}
async function naturalPrompt(intent) {
  evidence.prompts.push(intent);
  const routed = await mcpTool('prstudio_do', { intent });
  const matched = String(findKey(routed, 'matched') || '');
  if (intent === 'studia wordpress' || intent === 'study wordpress') {
    if (matched !== 'study_wordpress') throw new Error(`${intent} did not route to study_wordpress: ${JSON.stringify(routed).slice(0, 5000)}`);
    const jobId = String(findKey(routed, 'job_uuid') || '');
    if (!jobId) throw new Error(`${intent} produced no public mission job`);
    await waitForJob(jobId, intent);
    return { routed, jobId };
  }
  return { routed };
}
function assertStudySnapshot(snapshot, fixture, { requireIncremental = false } = {}) {
  const module = snapshot?.module || {}; const metrics = module.metrics || {}; const tables = Object.values(module.tables || {});
  // Record what the study actually learned before judging it.
  //
  // Every assertion below throws with a verdict and no data, so a failing run
  // produced an evidence file whose snapshots array was empty -- the one
  // question worth answering, "what DID it record?", was the one thing not
  // written down. The module goes into the evidence first, then the assertions
  // run against it.
  evidence.snapshots[requireIncremental ? 'restudy' : 'study'] = {
    mode: module.mode || null,
    state: module.state || null,
    revision: module.revision || null,
    surface_hash: module.surface_hash || null,
    // admin.sections / admin.menu is where the module keeps them; reading
    // module.sections reported an empty study three times while the study was
    // in fact traversing 49 sections. A report that looks in the wrong place
    // is indistinguishable from the thing it reports on being broken.
    sections: Object.keys(module.admin?.sections || {}),
    menus: (module.admin?.menu || []).map((row) => String(row?.id || '')),
    submenus: (module.admin?.submenus || []).length,
    coverage: module.coverage || null,
    last_observation_shape: module.last_observation_shape || null,
    last_stop: module.last_stop || null,
    study_log: module.study_log || null,
    table_sections: tables.map((table) => String(table.section || '')),
    table_summary: tables.map((table) => ({
      section: table.section || null,
      // The module stores them as `headers`; reading `columns` reported 0
      // for six captured tables that all had headers.
      columns: (table.headers || []).length,
      rows: (table.rows || []).length,
      pages_observed: table.page_count_observed ?? null,
      total_pages: table.total_pages ?? null,
    })),
    metrics,
    procedures: Object.keys(module.procedures || {}),
    evidence_refs: (module.evidence || []).length,
    // Present only when the probe failed; it says which of the three causes it was.
    last_probe_shape: module.last_probe_shape || null,
  };
  if (module.mode !== 'wordpress_admin' || !['ready','studied_degraded'].includes(String(module.state || ''))) throw new Error(`WordPress module not terminal/ready: ${JSON.stringify({mode:module.mode,state:module.state})}`);
  const posts = tables.find((table) => String(table.section || '') === 'menu-posts');
  if (!posts) throw new Error('Posts list table was not persisted from Chrome observation');
  if (Number(posts.total_pages || 0) < 2 || Number(posts.page_count_observed || 0) < 2 || !posts.coverage_complete) throw new Error(`real pagination coverage missing: ${JSON.stringify({total_pages:posts.total_pages,page_count_observed:posts.page_count_observed,coverage_complete:posts.coverage_complete})}`);
  const observedIds = new Set(Object.values(posts.rows || {}).map((row) => String(row?.key || '')).filter(Boolean));
  const missing = (fixture.post_ids || []).map(String).filter((id) => !observedIds.has(id));
  if (missing.length) throw new Error(`Chrome did not observe fixture posts across pagination; missing IDs=${missing.join(',')}`);
  const artifacts = (module.evidence || []).flatMap((row) => Array.isArray(row?.artifacts) ? row.artifacts : []);
  if (!artifacts.some((row) => row?.artifact_id || row?.sha256)) throw new Error('no Browser Agent screenshot artifact references persisted');
  const serialized = JSON.stringify(module);
  if (/data:image\//i.test(serialized) || /base64,[A-Za-z0-9+/=]{100,}/i.test(serialized) || /cookie|authorization|access_token|refresh_token|password/i.test(serialized)) throw new Error('site module persisted forbidden inline/sensitive material');
  if (Number(metrics.safe_clicks || 0) < 2 || Number(metrics.wordpress_pagination_clicks || 0) < 1 || Number(metrics.mutating_clicks || 0) !== 0) throw new Error(`click safety metrics invalid: ${JSON.stringify(metrics)}`);
  if (Number(snapshot?.twin?.types?.admin_section || 0) < 1 || Number(snapshot?.twin?.types?.list_table || 0) < 1 || Number(snapshot?.twin?.types?.column || 0) < 1) throw new Error(`Operational Twin lacks browser-observed WordPress graph: ${JSON.stringify(snapshot?.twin)}`);
  const skills = Object.values(snapshot?.skills?.skills || {});
  if (!skills.some((skill) => String(skill?.kind || '') === 'browser' && Number(skill?.success_count || 0) >= 1)) throw new Error('Procedural Skills contains no verified real Browser Agent procedure');
  if (requireIncremental && (!module.reuse?.memory_reused || !module.reuse?.incremental || Number(metrics.incremental_skips || 0) < 1)) throw new Error(`incremental learning/reuse not demonstrated: ${JSON.stringify(module.reuse)}`);
  return { module, posts, observedIds, artifacts, skills };
}

let cdp; let wpSessionId; let extensionSessionId; let wpTargetId;
try {
  const version = await waitForChrome(); cdp = new Cdp(version.webSocketDebuggerUrl); evidence.chrome_version = version.Browser || '';
  const extensionId = await findExtensionId(cdp); evidence.extension_id = extensionId;

  const wpTarget = await cdp.send('Target.createTarget', { url: `${wpUrl}/wp-login.php` }); wpTargetId = wpTarget.targetId; wpSessionId = await attachPage(cdp, wpTargetId);
  await waitForExpression(cdp, wpSessionId, `location.pathname === '/wp-login.php' && document.readyState === 'complete' && Boolean(document.querySelector('#user_login') && document.querySelector('#user_pass') && document.querySelector('#wp-submit'))`, 'WordPress login');
  const submitted = await evaluate(cdp, wpSessionId, `(() => { const u=document.querySelector('#user_login'),p=document.querySelector('#user_pass'),s=document.querySelector('#wp-submit'); if(!u||!p||!s)return false; u.value=${JSON.stringify(adminUser)}; p.value=${JSON.stringify(adminPassword)}; u.dispatchEvent(new Event('input',{bubbles:true})); p.dispatchEvent(new Event('input',{bubbles:true})); s.click(); return true; })()`);
  if (!submitted) throw new Error('WordPress login controls unavailable');
  await waitForExpression(cdp, wpSessionId, `location.pathname.startsWith('/wp-admin/') && !location.pathname.endsWith('/wp-login.php')`, 'authenticated wp-admin');
  await screenshot(cdp, wpSessionId, '01-authenticated-wp-admin.png');

  const pluginAdminUrl = `${wpUrl}/wp-admin/tools.php?page=prstudio-unified-browser`; await navigate(cdp, wpSessionId, pluginAdminUrl);
  const pairingSubmitted = await evaluate(cdp, wpSessionId, `(() => { const a=[...document.querySelectorAll('input[name="action"]')].find((n)=>n.value==='prstudio_uc_pairing_code'); const b=a?.form?.querySelector('input[type="submit"],button[type="submit"]'); if(!b)return false; b.click(); return true; })()`);
  if (!pairingSubmitted) throw new Error('pairing code form unavailable');
  const pairingCode = await waitForExpression(cdp, wpSessionId, `document.querySelector('#prstudio-pair-code')?.textContent?.trim() || ''`, 'pairing code');
  if (!/^[A-Za-z0-9_-]{4,}$/.test(pairingCode)) throw new Error('pairing code format invalid');

  const extensionTarget = await cdp.send('Target.createTarget', { url: `chrome-extension://${extensionId}/sidepanel.html` }); extensionSessionId = await attachPage(cdp, extensionTarget.targetId);
  await waitForExpression(cdp, extensionSessionId, `document.readyState === 'complete' && Boolean(document.querySelector('#pairButton') && !document.querySelector('#pairButton').disabled)`, 'Browser Agent pairing UI');
  const pairClicked = await evaluate(cdp, extensionSessionId, `(() => { const s=document.querySelector('#siteUrl'),c=document.querySelector('#pairCode'),n=document.querySelector('#deviceName'),b=document.querySelector('#pairButton'); if(!s||!c||!n||!b)return false; s.value=${JSON.stringify(wpUrl)}; c.value=${JSON.stringify(pairingCode)}; n.value=${JSON.stringify(deviceName)}; for(const e of [s,c,n])e.dispatchEvent(new Event('input',{bubbles:true})); b.click(); return true; })()`);
  if (!pairClicked) throw new Error('Browser Agent pairing controls unavailable');
  await waitForExpression(cdp, extensionSessionId, `(() => { const m=document.querySelector('#message')?.textContent?.trim()||''; if(/Errore:/i.test(m))throw new Error(m); return /Associazione completata|Chiave rinnovata/i.test(m)?m:''; })()`, 'Browser Agent pairing success');
  await waitForExpression(cdp, extensionSessionId, `document.querySelector('#connectionText')?.textContent?.trim() === 'Connessa'`, 'Browser Agent remote polling', 30000);
  await screenshot(cdp, extensionSessionId, '02-browser-agent-paired.png');

  // The fixture oracle is intentionally read only after the Browser Agent is
  // paired; fixture values are never supplied to Chrome or to the study prompt.
  const fixture = JSON.parse(readFileSync(fixturePath, 'utf8'));
  await cdp.send('Target.activateTarget', { targetId: wpTargetId });

  const first = await naturalPrompt('studia wordpress');
  const firstSnapshot = moduleSnapshot();
  const firstProof = assertStudySnapshot(firstSnapshot, fixture);
  evidence.snapshots.first = {
    job_id: first.jobId, module_id: firstProof.module.module_id, revision: firstProof.module.revision,
    surface_hash: firstProof.module.surface_hash, coverage: firstProof.module.coverage, metrics: firstProof.module.metrics,
    table: { id: firstProof.posts.id, headers: firstProof.posts.headers, row_count: firstProof.posts.row_count, page_count_observed: firstProof.posts.page_count_observed, total_pages: firstProof.posts.total_pages },
    screenshot_artifacts: firstProof.artifacts, twin: firstSnapshot.twin,
    procedural_skill_ids: firstProof.skills.map((s) => s.id).filter(Boolean), procedures: Object.values(firstProof.module.procedures || {}),
  };

  const reuse = await naturalPrompt('come arrivo alla gestione utenti in questo WordPress?');
  if (String(findKey(reuse.routed, 'matched') || '') !== 'wordpress_site_memory') throw new Error(`reuse request was not routed to learned WordPress memory: ${JSON.stringify(reuse.routed).slice(0, 5000)}`);
  const memoryItems = allValuesForKey(reuse.routed, 'items').find(Array.isArray) || [];
  if (!memoryItems.some((item) => String(item?.type || '') === 'site_procedure' && String(item?.id || '') === 'users')) throw new Error(`user-management procedure not returned from learned site memory: ${JSON.stringify(reuse.routed).slice(0, 5000)}`);
  evidence.memory_reuse = { prompt: evidence.prompts.at(-1), matched: 'wordpress_site_memory', items: memoryItems };

  await cdp.send('Target.activateTarget', { targetId: wpTargetId });
  const second = await naturalPrompt('studia wordpress');
  const secondSnapshot = moduleSnapshot(); const secondProof = assertStudySnapshot(secondSnapshot, fixture, { requireIncremental: true });
  if (secondProof.module.surface_hash !== firstProof.module.surface_hash) throw new Error('unchanged second study unexpectedly changed surface hash');
  if (Number(secondProof.module.metrics.wordpress_section_runs || 0) !== Number(firstProof.module.metrics.wordpress_section_runs || 0)) throw new Error('second unchanged study repeated the full section traversal');
  if (Number(secondProof.module.revision || 0) <= Number(firstProof.module.revision || 0)) throw new Error('incremental study did not advance module revision');
  evidence.snapshots.second = { job_id: second.jobId, revision: secondProof.module.revision, reuse: secondProof.module.reuse, metrics: secondProof.module.metrics, surface_hash: secondProof.module.surface_hash };

  await cdp.send('Target.activateTarget', { targetId: wpTargetId });
  const english = await naturalPrompt('study wordpress');
  const englishSnapshot = moduleSnapshot(); const englishProof = assertStudySnapshot(englishSnapshot, fixture, { requireIncremental: true });
  if (englishProof.module.surface_hash !== firstProof.module.surface_hash) throw new Error('English WordPress study did not preserve semantic surface identity');
  evidence.snapshots.english = { job_id: english.jobId, revision: englishProof.module.revision, reuse: englishProof.module.reuse, metrics: englishProof.module.metrics, surface_hash: englishProof.module.surface_hash };

  const liveTargets = (await cdp.send('Target.getTargets')).targetInfos || [];
  evidence.chrome_targets = liveTargets.filter((t) => String(t.url || '').startsWith(wpUrl)).map((t) => ({ type: t.type, url: t.url, title: t.title })).slice(0, 100);
  evidence.ok = true; evidence.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'wordpress-study-evidence.json'), JSON.stringify(evidence, null, 2));
  console.log(`PASS real WordPress study: module=${firstProof.module.module_id} pages=${firstProof.posts.page_count_observed}/${firstProof.posts.total_pages} rows=${firstProof.posts.row_count} safe_clicks=${firstProof.module.metrics.safe_clicks} mutation_clicks=${firstProof.module.metrics.mutating_clicks}`);
} catch (error) {
  evidence.error = { message: error?.message || String(error), stack: error?.stack || '' }; evidence.finished_at = new Date().toISOString();
  for (const [sessionId, name] of [[wpSessionId,'failure-wordpress.png'],[extensionSessionId,'failure-browser-agent.png']]) { if (!cdp || !sessionId) continue; try { await screenshot(cdp, sessionId, name); } catch {} }
  await writeFile(join(artifactDir, 'wordpress-study-evidence.json'), JSON.stringify(evidence, null, 2));
  console.error(`FAIL real WordPress study E2E: ${error?.stack || error}`); process.exitCode = 1;
} finally {
  await writeFile(join(artifactDir, 'chrome-stdout.log'), chromeStdout); await writeFile(join(artifactDir, 'chrome-stderr.log'), chromeStderr);
  try { cdp?.close(); } catch {} try { child.kill('SIGKILL'); } catch {} await sleep(150); await rm(userDataDir, { recursive: true, force: true });
}
