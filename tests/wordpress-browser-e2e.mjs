import { spawn, spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';

const sleep = (ms) => new Promise((resolvePromise) => setTimeout(resolvePromise, ms));
const startedAt = new Date().toISOString();
const wpUrl = String(process.env.WP_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const adminUser = String(process.env.WP_ADMIN_USER || 'rpadmin');
const adminPassword = String(process.env.WP_ADMIN_PASSWORD || '');
const deviceName = String(process.env.E2E_DEVICE_NAME || 'GitHub H24 Chrome');
const artifactDir = resolve(process.env.E2E_ARTIFACT_DIR || 'artifacts/wordpress-browser-e2e');
const extensionDir = resolve('prstudio-unified-browser-agent');

if (!adminPassword) {
  console.error('FAIL WP_ADMIN_PASSWORD is required');
  process.exit(2);
}

await mkdir(artifactDir, { recursive: true });

const chromeCandidates = process.platform === 'darwin'
  ? ['/Applications/Google Chrome.app/Contents/MacOS/Google Chrome']
  : process.platform === 'win32'
    ? [
        process.env.CHROME_BIN,
        'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
        'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
      ]
    : [
        process.env.CHROME_BIN,
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
      ];

const chrome = chromeCandidates.filter(Boolean).find((candidate) => existsSync(candidate));
if (!chrome) {
  console.error('FAIL real Google Chrome binary not found');
  process.exit(2);
}
const versionProbe = spawnSync(chrome, ['--version'], { encoding: 'utf8' });
const binaryVersion = `${versionProbe.stdout || ''}${versionProbe.stderr || ''}`.trim();
if (versionProbe.status !== 0 || !/Google Chrome|Chrome for Testing/i.test(binaryVersion) || /Chromium/i.test(binaryVersion)) {
  console.error(`FAIL certification requires Google Chrome/Chrome for Testing: ${binaryVersion}`);
  process.exit(2);
}
if (!existsSync(extensionDir)) {
  console.error(`FAIL Browser Agent directory missing: ${extensionDir}`);
  process.exit(2);
}

const userDataDir = await mkdtemp(join(tmpdir(), 'rpconnector-real-e2e-'));
const debugPort = 9400 + (process.pid % 300);
const chromeArgs = [
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
];

const child = spawn(chrome, chromeArgs, { stdio: ['ignore', 'pipe', 'pipe'] });
let chromeStdout = '';
let chromeStderr = '';
child.stdout.on('data', (chunk) => {
  chromeStdout += chunk.toString();
  if (chromeStdout.length > 500000) chromeStdout = chromeStdout.slice(-500000);
});
child.stderr.on('data', (chunk) => {
  chromeStderr += chunk.toString();
  if (chromeStderr.length > 500000) chromeStderr = chromeStderr.slice(-500000);
});

async function fetchJson(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error(`${response.status} ${url}`);
  return response.json();
}

async function waitForChromeVersion() {
  let lastError;
  for (let i = 0; i < 150; i += 1) {
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
      this.ws.addEventListener('open', () => resolvePromise());
      this.ws.addEventListener('error', () => reject(new Error('CDP WebSocket failed to open')));
    });
    this.ws.addEventListener('message', (event) => {
      const message = JSON.parse(String(event.data));
      if (!message.id) return;
      const waiter = this.pending.get(message.id);
      if (!waiter) return;
      this.pending.delete(message.id);
      clearTimeout(waiter.timer);
      if (message.error) waiter.reject(new Error(`CDP ${waiter.method}: ${JSON.stringify(message.error)}`));
      else waiter.resolve(message.result);
    });
  }

  async send(method, params = {}, sessionId = undefined, timeoutMs = 10000) {
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
  const attached = await cdp.send('Target.attachToTarget', { targetId, flatten: true });
  const sessionId = attached.sessionId;
  await cdp.send('Page.enable', {}, sessionId);
  await cdp.send('Runtime.enable', {}, sessionId);
  return sessionId;
}

async function evaluate(cdp, sessionId, expression, { awaitPromise = true } = {}) {
  const result = await cdp.send('Runtime.evaluate', {
    expression,
    returnByValue: true,
    awaitPromise,
    userGesture: true,
  }, sessionId, 15000);
  if (result.exceptionDetails) {
    throw new Error(`Runtime.evaluate failed: ${result.exceptionDetails.text || 'exception'}`);
  }
  return result.result?.value;
}

async function waitForExpression(cdp, sessionId, expression, label, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  let lastValue;
  let lastError;
  while (Date.now() < deadline) {
    try {
      lastValue = await evaluate(cdp, sessionId, expression);
      if (lastValue) return lastValue;
    } catch (error) {
      lastError = error;
    }
    await sleep(200);
  }
  const suffix = lastError ? `; last error=${lastError.message}` : `; last value=${JSON.stringify(lastValue)}`;
  throw new Error(`Timed out waiting for ${label}${suffix}`);
}

async function navigate(cdp, sessionId, url) {
  await cdp.send('Page.navigate', { url }, sessionId, 15000);
  await waitForExpression(
    cdp,
    sessionId,
    'document.readyState === "complete"',
    `page load ${url}`,
    20000,
  );
}

async function screenshot(cdp, sessionId, filename) {
  const result = await cdp.send('Page.captureScreenshot', {
    format: 'png',
    captureBeyondViewport: true,
    fromSurface: true,
  }, sessionId, 15000);
  await writeFile(join(artifactDir, filename), Buffer.from(result.data, 'base64'));
}

async function findExtensionId(cdp) {
  const deadline = Date.now() + 15000;
  let lastTargets = [];
  while (Date.now() < deadline) {
    const result = await cdp.send('Target.getTargets');
    lastTargets = result.targetInfos || [];
    const worker = lastTargets.find((target) =>
      target.type === 'service_worker'
      && String(target.url || '').startsWith('chrome-extension://')
      && String(target.url || '').endsWith('/service-worker-bootstrap.js'));
    if (worker) {
      return String(worker.url).split('/')[2];
    }
    await sleep(200);
  }
  throw new Error(`Browser Agent MV3 bootstrap service worker not observed; targets=${JSON.stringify(lastTargets)}`);
}

const evidence = {
  ok: false,
  started_at: startedAt,
  wp_url: wpUrl,
  device_name: deviceName,
  chrome_binary: chrome,
  chrome_binary_version: binaryVersion,
  steps: [],
};

let cdp;
let wpSessionId;
let extensionSessionId;
let wpTargetId;

function pass(step, detail = {}) {
  evidence.steps.push({ step, ok: true, at: new Date().toISOString(), ...detail });
  console.log(`PASS ${step}${detail.message ? ` — ${detail.message}` : ''}`);
}

try {
  const version = await waitForChromeVersion();
  evidence.chrome_version = version.Browser || '';
  if (!/Chrome\//.test(evidence.chrome_version) || /Chromium/i.test(evidence.chrome_version)) {
    throw new Error(`Certification browser is not Google Chrome: ${evidence.chrome_version}`);
  }
  cdp = new Cdp(version.webSocketDebuggerUrl);
  pass('real Google Chrome started', { message: `${binaryVersion} / ${evidence.chrome_version}` });

  const extensionId = await findExtensionId(cdp);
  evidence.extension_id = extensionId;
  pass('Browser Agent MV3 bootstrap extension loaded', { message: `extension ${extensionId.slice(0, 8)}…` });

  const adminUrl = `${wpUrl}/wp-admin/`;
  const loginUrl = `${wpUrl}/wp-login.php?redirect_to=${encodeURIComponent(adminUrl)}&reauth=1`;
  const wpTarget = await cdp.send('Target.createTarget', { url: loginUrl });
  wpTargetId = wpTarget.targetId;
  wpSessionId = await attachPage(cdp, wpTargetId);
  await waitForExpression(
    cdp,
    wpSessionId,
    `location.pathname === '/wp-login.php'
      && document.readyState === 'complete'
      && Boolean(document.querySelector('#user_login')
        && document.querySelector('#user_pass')
        && document.querySelector('#wp-submit'))`,
    'WordPress login form',
    20000,
  );
  pass('WordPress login page rendered in Chrome');

  const loginResult = await evaluate(cdp, wpSessionId, `(() => {
    const user = document.querySelector('#user_login');
    const pass = document.querySelector('#user_pass');
    const submit = document.querySelector('#wp-submit');
    const form = submit?.form;
    if (!user || !pass || !submit || !form) return { submitted: false };
    user.value = ${JSON.stringify(adminUser)};
    pass.value = ${JSON.stringify(adminPassword)};
    user.dispatchEvent(new Event('input', { bubbles: true }));
    pass.dispatchEvent(new Event('input', { bubbles: true }));
    const redirect = form.querySelector('input[name="redirect_to"]');
    if (redirect) redirect.value = ${JSON.stringify(`${wpUrl}/wp-admin/`)};
    if (typeof form.requestSubmit === 'function') form.requestSubmit(submit);
    else form.submit();
    return { submitted: true, redirect: redirect?.value || '' };
  })()`);
  if (!loginResult?.submitted) throw new Error('Could not submit WordPress login form');

  // The POST may legally land outside wp-admin depending on WordPress redirect filters.
  // Prove authentication by entering a protected admin route with the browser session
  // created by the real login form. A failed login is redirected back to wp-login.php.
  await sleep(750);
  await navigate(cdp, wpSessionId, adminUrl);
  await waitForExpression(
    cdp,
    wpSessionId,
    `location.pathname.startsWith('/wp-admin/')
      && !location.pathname.endsWith('/wp-login.php')
      && document.body?.classList?.contains('wp-admin')
      && !document.querySelector('#loginform')`,
    'authenticated WordPress admin',
    20000,
  );
  evidence.wordpress_login = {
    form_submitted: true,
    requested_redirect: loginResult.redirect || adminUrl,
    protected_admin_verified: true,
  };
  pass('WordPress admin login completed through Chrome');

  const pluginAdminUrl = `${wpUrl}/wp-admin/tools.php?page=prstudio-unified-browser`;
  await navigate(cdp, wpSessionId, pluginAdminUrl);
  const adminState = await evaluate(cdp, wpSessionId, `(() => {
    const root = document.querySelector('.prstudio-connect');
    const title = root?.querySelector('h1')?.textContent?.trim() || '';
    return {
      title,
      hasChatGPT: Boolean([...root?.querySelectorAll('h2') || []].some((node) => node.textContent.includes('Collega ChatGPT'))),
      hasChrome: Boolean([...root?.querySelectorAll('h2') || []].some((node) => node.textContent.includes('Collega Chrome'))),
      hasPairingForm: Boolean([...document.querySelectorAll('input[name="action"]')].some((input) => input.value === 'prstudio_uc_pairing_code')),
      hasLegacyHistory: /Browser collegati|Attività recenti|Cronologia dispositivi revocati|Manutenzione runtime/.test(root?.textContent || ''),
    };
  })()`);
  if (!adminState?.title?.startsWith('PR STUDIO — Collegamenti') || !adminState.hasChatGPT || !adminState.hasChrome || !adminState.hasPairingForm || adminState.hasLegacyHistory) {
    throw new Error(`P32 PR STUDIO minimal admin did not render correctly: ${JSON.stringify(adminState)}`);
  }
  evidence.admin_title = adminState.title;
  await screenshot(cdp, wpSessionId, '01-prstudio-admin.png');
  pass('P32 minimal PR STUDIO connection page rendered in real Chrome', { message: adminState.title });

  const pairingSubmitted = await evaluate(cdp, wpSessionId, `(() => {
    const action = [...document.querySelectorAll('input[name="action"]')]
      .find((input) => input.value === 'prstudio_uc_pairing_code');
    if (!action?.form) return false;
    const submit = action.form.querySelector('input[type="submit"], button[type="submit"]');
    if (!submit) return false;
    submit.click();
    return true;
  })()`);
  if (!pairingSubmitted) throw new Error('Pairing-code form was not found in WordPress admin');
  const pairingCode = await waitForExpression(
    cdp,
    wpSessionId,
    `document.querySelector('#prstudio-pair-code')?.textContent?.trim() || ''`,
    'WordPress pairing code',
    15000,
  );
  if (!/^[A-Za-z0-9_-]{4,}$/.test(pairingCode)) {
    throw new Error(`Pairing code has unexpected format (length ${String(pairingCode).length})`);
  }
  evidence.pairing_code_length = String(pairingCode).length;
  await evaluate(cdp, wpSessionId, `(() => {
    const code = document.querySelector('#prstudio-pair-code');
    if (code) code.textContent = '[REDACTED]';
    return true;
  })()`);
  await screenshot(cdp, wpSessionId, '02-pairing-code-redacted.png');
  pass('Pairing code generated through P32 WordPress UI', { message: `length ${String(pairingCode).length}, screenshot redacted` });

  const extensionTarget = await cdp.send('Target.createTarget', { url: `chrome-extension://${extensionId}/sidepanel.html` });
  extensionSessionId = await attachPage(cdp, extensionTarget.targetId);
  await waitForExpression(
    cdp,
    extensionSessionId,
    `document.readyState === 'complete' && document.querySelector('#commandSuggestions')?.childElementCount > 0`,
    'Browser Agent sidepanel module ready',
    15000,
  );
  await waitForExpression(cdp, extensionSessionId, 'Boolean(document.querySelector("#pairButton") && !document.querySelector("#pairButton").disabled)', 'Browser Agent pairing UI');

  const pairClicked = await evaluate(cdp, extensionSessionId, `(() => {
    const site = document.querySelector('#siteUrl');
    const code = document.querySelector('#pairCode');
    const name = document.querySelector('#deviceName');
    const button = document.querySelector('#pairButton');
    if (!site || !code || !name || !button) return false;
    site.value = ${JSON.stringify(wpUrl)};
    code.value = ${JSON.stringify(pairingCode)};
    name.value = ${JSON.stringify(deviceName)};
    site.dispatchEvent(new Event('input', { bubbles: true }));
    code.dispatchEvent(new Event('input', { bubbles: true }));
    name.dispatchEvent(new Event('input', { bubbles: true }));
    button.click();
    return true;
  })()`);
  if (!pairClicked) throw new Error('Browser Agent pairing controls were not usable');

  const pairingMessage = await waitForExpression(
    cdp,
    extensionSessionId,
    `(() => {
      const message = document.querySelector('#message')?.textContent?.trim() || '';
      if (/Errore:/i.test(message)) throw new Error(message);
      return /Associazione completata|Chiave rinnovata/i.test(message) ? message : '';
    })()`,
    'Browser Agent pairing success',
    20000,
  );
  evidence.pairing_message = pairingMessage;
  await waitForExpression(
    cdp,
    extensionSessionId,
    `document.querySelector('#connected')?.hidden === false`,
    'Browser Agent connected panel',
    10000,
  );
  const remoteConnection = await waitForExpression(
    cdp,
    extensionSessionId,
    `(() => {
      const text = document.querySelector('#connectionText')?.textContent?.trim() || '';
      return text === 'Connessa' ? text : '';
    })()`,
    'Browser Agent remote poll connection',
    20000,
  );
  evidence.remote_connection = remoteConnection;
  await cdp.send('Target.activateTarget', { targetId: extensionTarget.targetId });
  await screenshot(cdp, extensionSessionId, '03-browser-agent-connected.png');
  pass('Browser Agent paired with real WordPress', { message: remoteConnection });

  await cdp.send('Target.activateTarget', { targetId: wpTargetId });
  const auditClicked = await evaluate(cdp, extensionSessionId, `(() => {
    const button = document.querySelector('#healthButton');
    if (!button) return false;
    button.click();
    return true;
  })()`);
  if (!auditClicked) throw new Error('Browser Agent Analyze page control was unavailable');
  const auditMessage = await waitForExpression(
    cdp,
    extensionSessionId,
    `(() => {
      const message = document.querySelector('#message')?.textContent?.trim() || '';
      if (/Errore:/i.test(message)) throw new Error(message);
      return /^Audit completato:/i.test(message) ? message : '';
    })()`,
    'Browser Agent real-page audit',
    20000,
  );
  const auditText = await evaluate(cdp, extensionSessionId, `document.querySelector('#healthResult')?.textContent?.replace(/\\s+/g, ' ').trim() || ''`);
  if (!/\d+\/100/.test(auditText)) throw new Error(`Browser Agent audit produced no score: ${auditText}`);
  evidence.browser_agent_audit = { message: auditMessage, result: auditText.slice(0, 1000) };
  await cdp.send('Target.activateTarget', { targetId: extensionTarget.targetId });
  await screenshot(cdp, extensionSessionId, '04-browser-agent-audit.png');
  pass('Browser Agent executed a real audit against WordPress', { message: auditMessage });

  await cdp.send('Target.activateTarget', { targetId: wpTargetId });
  await navigate(cdp, wpSessionId, pluginAdminUrl);
  const adminAfterPair = await evaluate(cdp, wpSessionId, `(() => ({
    title: document.querySelector('.prstudio-connect h1')?.textContent?.trim() || '',
    hasDeviceGrid: Boolean(document.querySelector('table.prstudio-grid')),
    text: document.querySelector('.prstudio-connect')?.textContent || '',
  }))()`);
  if (!adminAfterPair?.title?.startsWith('PR STUDIO — Collegamenti') || adminAfterPair.hasDeviceGrid || /Browser collegati|Cronologia dispositivi revocati|Attività recenti/.test(adminAfterPair.text || '')) {
    throw new Error(`P32 admin exposed operational history after pairing: ${JSON.stringify(adminAfterPair)}`);
  }
  evidence.wordpress_admin_after_pair = { title: adminAfterPair.title, history_visible: false };
  await screenshot(cdp, wpSessionId, '05-wordpress-pairing-only.png');
  pass('WordPress admin remains pairing-only after Chrome connection');

  evidence.ok = true;
  evidence.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'evidence.json'), JSON.stringify(evidence, null, 2));
  console.log('PASS real WordPress + Google Chrome + Browser Agent end-to-end');
} catch (error) {
  evidence.error = {
    message: error?.message || String(error),
    stack: error?.stack || '',
  };
  evidence.finished_at = new Date().toISOString();

  if (cdp && wpSessionId) {
    try {
      evidence.wordpress_failure_state = await evaluate(cdp, wpSessionId, `(() => ({
        href: location.href,
        pathname: location.pathname,
        title: document.title,
        login_error: document.querySelector('#login_error')?.textContent?.replace(/\\s+/g, ' ').trim() || '',
        has_login_form: Boolean(document.querySelector('#loginform')),
        is_wp_admin: Boolean(document.body?.classList?.contains('wp-admin')),
      }))()`);
    } catch {}
  }

  for (const [sessionId, name] of [
    [wpSessionId, 'failure-wordpress.png'],
    [extensionSessionId, 'failure-browser-agent.png'],
  ]) {
    if (!cdp || !sessionId) continue;
    try { await screenshot(cdp, sessionId, name); } catch {}
  }

  await writeFile(join(artifactDir, 'evidence.json'), JSON.stringify(evidence, null, 2));
  console.error(`FAIL real WordPress + Chrome + Browser Agent end-to-end: ${error?.stack || error}`);
  process.exitCode = 1;
} finally {
  await writeFile(join(artifactDir, 'chrome-stdout.log'), chromeStdout);
  await writeFile(join(artifactDir, 'chrome-stderr.log'), chromeStderr);
  try { cdp?.close(); } catch {}
  try { child.kill('SIGKILL'); } catch {}
  await sleep(150);
  await rm(userDataDir, { recursive: true, force: true });
}
