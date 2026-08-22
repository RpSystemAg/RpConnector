import { createRequire } from 'node:module';
import { mkdir, writeFile } from 'node:fs/promises';
import { existsSync } from 'node:fs';
import { join, resolve } from 'node:path';

const extensionDir = resolve('prstudio-unified-browser-agent');
const artifactDir = resolve('artifacts/browser-e2e');
const baseUrl = String(process.env.WP_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const moduleRoot = String(process.env.PRSTUDIO_PUPPETEER_ROOT || '/tmp/prstudio-puppeteer');
const require = createRequire(join(moduleRoot, 'package.json'));
const puppeteer = require('puppeteer');

if (!existsSync(extensionDir)) throw new Error(`extension_missing:${extensionDir}`);
await mkdir(artifactDir, { recursive: true });

const report = {
  ok: false,
  started_at: new Date().toISOString(),
  base_url: baseUrl,
  extension_dir: extensionDir,
  checks: [],
  timings_ms: {},
};

const record = (name, ok, detail = {}) => {
  report.checks.push({ name, ok: Boolean(ok), ...detail });
  if (!ok) throw new Error(`${name}: ${JSON.stringify(detail)}`);
};

const withTimeout = async (label, promise, timeoutMs = 15000) => {
  const started = performance.now();
  let timer;
  try {
    const result = await Promise.race([
      promise,
      new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(`${label}_timeout_${timeoutMs}ms`)), timeoutMs); }),
    ]);
    report.timings_ms[label] = Math.round((performance.now() - started) * 100) / 100;
    return result;
  } finally {
    clearTimeout(timer);
  }
};

let browser;
let commandPage;
let extensionId = '';
let controlledTabIds = [];

const extEval = (fn, arg, label = 'extension_eval', timeoutMs = 15000) => withTimeout(label, commandPage.evaluate(fn, arg), timeoutMs);

const runtimeCall = (tabId, payload, label, timeoutMs = 10000) => extEval(async ({ tabId: id, payload: body, timeoutMs: bounded }) => {
  const deadline = Date.now() + bounded;
  let last = null;
  while (Date.now() < deadline) {
    try {
      const response = await Promise.race([
        chrome.tabs.sendMessage(id, { channel: 'prstudio-page-runtime', ...body }),
        new Promise((_, reject) => setTimeout(() => reject(new Error('tabs_sendMessage_timeout')), 2500)),
      ]);
      if (response?.ok) return response.result;
      last = response || null;
    } catch (error) {
      last = { error: String(error?.message || error) };
    }
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 100));
  }
  throw new Error(`page_runtime_unavailable:${JSON.stringify(last)}`);
}, { tabId, payload, timeoutMs }, label, timeoutMs + 2000);

const createControlledTab = async (url, label, active = false) => {
  const tab = await extEval(async ({ url: targetUrl, active: makeActive }) => {
    const win = await chrome.windows.getLastFocused({ windowTypes: ['normal'] });
    if (!Number.isInteger(win?.id)) throw new Error('normal_window_missing');
    const created = await chrome.tabs.create({ windowId: win.id, url: targetUrl, active: makeActive });
    return { id: created.id, windowId: created.windowId, url: created.url || '', active: created.active };
  }, { url, active }, `${label}_create`, 10000);
  record(`${label}_created`, Number.isInteger(tab.id), tab);
  controlledTabIds.push(tab.id);
  const ping = await runtimeCall(tab.id, { kind: 'ping' }, `${label}_runtime_ping`, 12000);
  record(`${label}_page_runtime_ready`, ping?.pong === true && String(ping?.url || '').startsWith(baseUrl), { ping });
  return tab;
};

try {
  browser = await withTimeout('browser_launch', puppeteer.launch({
    headless: false,
    pipe: true,
    enableExtensions: [extensionDir],
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  }), 60000);

  report.browser_version = await browser.version();
  const expectedSuffix = '/service-worker-bootstrap.js';
  const workerTarget = await withTimeout('service_worker_start', browser.waitForTarget(
    (target) => target.type() === 'service_worker' && target.url().startsWith('chrome-extension://') && target.url().endsWith(expectedSuffix),
    { timeout: 30000 },
  ), 35000);
  report.worker_url = workerTarget.url();
  extensionId = workerTarget.url().split('/')[2] || '';
  record('exact_mv3_worker_loaded', Boolean(extensionId), { worker_url: workerTarget.url(), extension_id: extensionId });

  commandPage = await browser.newPage();
  await withTimeout('extension_page_open', commandPage.goto(`chrome-extension://${extensionId}/sidepanel.html`, { waitUntil: 'domcontentloaded', timeout: 15000 }), 20000);
  record('extension_page_loaded', (await commandPage.title()).length >= 0, { url: commandPage.url() });

  const apiSurface = await extEval(() => ({
    tabs: ['create','get','query','update','reload','goBack','goForward','remove','group','ungroup','sendMessage','captureVisibleTab'].filter((name) => typeof chrome.tabs?.[name] === 'function'),
    scripting: typeof chrome.scripting?.executeScript === 'function',
    debuggerApi: typeof chrome.debugger?.attach === 'function' && typeof chrome.debugger?.sendCommand === 'function',
    storage: typeof chrome.storage?.local?.get === 'function',
  }), null, 'api_surface');
  record('required_chrome_api_surface', apiSurface.tabs.length === 12 && apiSurface.scripting && apiSurface.debuggerApi && apiSurface.storage, apiSurface);

  const tabA = await createControlledTab(`${baseUrl}/parity-a.html`, 'tab_a', true);
  const tabB = await createControlledTab(`${baseUrl}/parity-b.html`, 'tab_b');
  const tabC = await createControlledTab(`${baseUrl}/parity-c.html`, 'tab_c');
  record('three_tabs_same_window', tabA.windowId === tabB.windowId && tabB.windowId === tabC.windowId, { a: tabA, b: tabB, c: tabC });

  const queried = await extEval(async (ids) => {
    const rows = await chrome.tabs.query({ windowId: (await chrome.tabs.get(ids[0])).windowId });
    return rows.filter((tab) => ids.includes(tab.id)).map((tab) => ({ id: tab.id, url: tab.url || '', active: tab.active }));
  }, [tabA.id, tabB.id, tabC.id], 'tabs_query');
  record('tabs_query_sees_all_controlled_tabs', queried.length === 3, { queried });

  const ready = await runtimeCall(tabA.id, { kind: 'wait_ready', selector: '#parity-button', timeoutMs: 5000 }, 'tab_a_wait_ready', 7000);
  record('wait_ready_real_page', ready?.ready === true && ready?.selectorReady === true, { ready });

  const fill = await runtimeCall(tabA.id, { kind: 'dom_action', action: 'fill', args: { selector: '#parity-input', value: 'rpstudio-live' } }, 'tab_a_fill');
  record('fill_through_page_runtime', fill?.ok === true, { fill });
  const click = await runtimeCall(tabA.id, { kind: 'dom_action', action: 'click', args: { selector: '#parity-button', clickCount: 1 } }, 'tab_a_click');
  record('click_through_page_runtime', click?.ok === true, { click });
  const read = await runtimeCall(tabA.id, { kind: 'read_value', selector: '#parity-input' }, 'tab_a_read_value');
  record('read_value_after_fill', read?.supported === true && read?.value === 'rpstudio-live', { read });

  const scriptResult = await extEval(async (tabId) => {
    const rows = await chrome.scripting.executeScript({ target: { tabId }, func: () => ({ value: document.querySelector('#parity-input')?.value || '', clicked: document.body.dataset.clicked || '', url: location.href }) });
    return rows?.[0]?.result || null;
  }, tabA.id, 'scripting_execute');
  record('chrome_scripting_observes_real_mutation', scriptResult?.value === 'rpstudio-live' && scriptResult?.clicked === 'yes', { scriptResult });

  await extEval(async (tabId) => chrome.tabs.update(tabId, { active: true }), tabC.id, 'tab_switch_to_c');
  const backgroundFill = await runtimeCall(tabA.id, { kind: 'dom_action', action: 'fill', args: { selector: '#parity-input', value: 'background-controlled' } }, 'background_tab_fill');
  record('background_tab_remains_controllable', backgroundFill?.ok === true, { backgroundFill });

  const groupId = await extEval(async (ids) => chrome.tabs.group({ tabIds: ids }), [tabA.id, tabB.id, tabC.id], 'tab_group');
  record('tab_group_created', Number.isInteger(groupId) && groupId >= 0, { groupId });
  await extEval(async (ids) => { await chrome.tabs.ungroup(ids); return true; }, [tabA.id, tabB.id, tabC.id], 'tab_ungroup');
  record('tab_group_roundtrip', true, { groupId });

  await extEval(async ({ id, url }) => { await chrome.tabs.update(id, { url }); return true; }, { id: tabA.id, url: `${baseUrl}/parity-b.html?nav=1` }, 'tab_a_navigate');
  const navPing = await runtimeCall(tabA.id, { kind: 'ping' }, 'tab_a_after_navigation_ping', 12000);
  record('page_runtime_reconnects_after_navigation', navPing?.pong === true && String(navPing?.url || '').includes('parity-b.html'), { navPing });

  await extEval(async (id) => { await chrome.tabs.reload(id); return true; }, tabA.id, 'tab_a_reload');
  const reloadPing = await runtimeCall(tabA.id, { kind: 'ping' }, 'tab_a_after_reload_ping', 12000);
  record('page_runtime_reconnects_after_reload', reloadPing?.pong === true && String(reloadPing?.url || '').includes('parity-b.html'), { reloadPing });

  await extEval(async ({ id, url }) => { await chrome.tabs.update(id, { url }); return true; }, { id: tabA.id, url: `${baseUrl}/parity-c.html?history=1` }, 'tab_a_second_navigation');
  await runtimeCall(tabA.id, { kind: 'ping' }, 'tab_a_second_navigation_ping', 12000);
  await extEval(async (id) => { await chrome.tabs.goBack(id); return true; }, tabA.id, 'tab_a_go_back');
  const backPing = await runtimeCall(tabA.id, { kind: 'ping' }, 'tab_a_after_back_ping', 12000);
  record('tab_go_back_keeps_runtime', backPing?.pong === true && String(backPing?.url || '').includes('parity-b.html'), { backPing });
  await extEval(async (id) => { await chrome.tabs.goForward(id); return true; }, tabA.id, 'tab_a_go_forward');
  const forwardPing = await runtimeCall(tabA.id, { kind: 'ping' }, 'tab_a_after_forward_ping', 12000);
  record('tab_go_forward_keeps_runtime', forwardPing?.pong === true && String(forwardPing?.url || '').includes('parity-c.html'), { forwardPing });

  await extEval(async (tabId) => chrome.tabs.update(tabId, { active: true }), tabC.id, 'tab_c_activate_for_capture');
  const capture = await extEval(async (windowId) => chrome.tabs.captureVisibleTab(windowId, { format: 'png' }), tabC.windowId, 'capture_visible_tab', 15000);
  record('visible_tab_screenshot_real', typeof capture === 'string' && capture.startsWith('data:image/png;base64,') && capture.length > 1000, { bytes: typeof capture === 'string' ? capture.length : 0 });

  const focusPreflight = await extEval(async () => {
    const win = await chrome.windows.getLastFocused({ windowTypes: ['normal'] });
    const active = Number.isInteger(win?.id) ? await chrome.tabs.query({ active: true, windowId: win.id }) : [];
    return { windowId: win?.id ?? null, focused: win?.focused ?? null, active: active.map((tab) => ({ id: tab.id, url: tab.url || '', active: tab.active })) };
  }, null, 'focused_window_preflight', 5000);
  record('focused_window_resolves_for_local_lane', Number.isInteger(focusPreflight.windowId) && focusPreflight.active.some((tab) => tab.id === tabC.id), { focusPreflight });

  const localStatus = await extEval(async () => chrome.runtime.sendMessage({ type: 'local_status' }), null, 'local_status_preflight', 7000);
  record('local_status_worker_message_real', Boolean(localStatus?.ok), { localStatus });

  const directHealth = await extEval(async (tabId) => {
    const module = await import(chrome.runtime.getURL('lib/local-page-functions.js'));
    const rows = await chrome.scripting.executeScript({ target: { tabId, allFrames: false }, func: module.collectLocalPageHealth });
    return rows?.[0]?.result || null;
  }, tabC.id, 'direct_local_health_scripting', 7000);
  record('direct_local_health_scripting_real', Boolean(directHealth?.url) && String(directHealth.url).startsWith(baseUrl), { directHealth });

  const localHealth = await extEval(async () => {
    const response = await chrome.runtime.sendMessage({ type: 'local_page_health' });
    return response || null;
  }, null, 'local_page_health', 15000);
  record('service_worker_local_health_real', localHealth?.ok === true && Boolean(localHealth?.result?.url), { localHealth });

  const oldWorker = await workerTarget.worker();
  await withTimeout('service_worker_terminate', oldWorker.close(), 10000);
  const restartedTarget = await withTimeout('service_worker_restart', browser.waitForTarget(
    (target) => target !== workerTarget && target.type() === 'service_worker' && target.url() === report.worker_url,
    { timeout: 15000 },
  ), 18000);
  record('mv3_worker_restarts_after_termination', Boolean(restartedTarget), { old: report.worker_url, restarted: restartedTarget.url() });

  const postRestartStatus = await extEval(async () => {
    const deadline = Date.now() + 10000;
    let last = null;
    while (Date.now() < deadline) {
      try {
        last = await chrome.runtime.sendMessage({ type: 'status' });
        if (last) return last;
      } catch (error) { last = { error: String(error?.message || error) }; }
      await new Promise((resolvePromise) => setTimeout(resolvePromise, 100));
    }
    throw new Error(`worker_status_timeout:${JSON.stringify(last)}`);
  }, null, 'status_after_worker_restart', 12000);
  record('worker_accepts_messages_after_restart', Boolean(postRestartStatus), { status_keys: Object.keys(postRestartStatus || {}).slice(0, 20) });

  const postRestartPing = await runtimeCall(tabC.id, { kind: 'ping' }, 'existing_tab_after_worker_restart_ping', 12000);
  record('existing_page_runtime_survives_worker_restart', postRestartPing?.pong === true, { postRestartPing });
  const healthAfterRestart = await extEval(async () => chrome.runtime.sendMessage({ type: 'local_page_health' }), null, 'local_health_after_worker_restart', 15000);
  record('service_worker_pipeline_recovers_after_restart', healthAfterRestart?.ok === true, { healthAfterRestart });

  await extEval(async (id) => { await chrome.tabs.remove(id); return true; }, tabB.id, 'tab_b_close');
  controlledTabIds = controlledTabIds.filter((id) => id !== tabB.id);
  const closedState = await extEval(async (id) => {
    try { await chrome.tabs.get(id); return { exists: true }; } catch (error) { return { exists: false, error: String(error?.message || error) }; }
  }, tabB.id, 'tab_b_verify_closed');
  record('closed_tab_is_really_gone', closedState.exists === false, closedState);
  const survivorPing = await runtimeCall(tabC.id, { kind: 'ping' }, 'survivor_tab_ping');
  record('closing_one_tab_does_not_break_others', survivorPing?.pong === true, { survivorPing });

  const replacement = await createControlledTab(`${baseUrl}/parity-a.html?replacement=1`, 'replacement_tab', false);
  const replacementRead = await runtimeCall(replacement.id, { kind: 'dom_action', action: 'extract_text', args: { selector: '#parity-content' } }, 'replacement_tab_extract');
  record('can_open_and_control_new_tab_after_close_restart', replacementRead?.ok === true && String(replacementRead?.text || '').includes('persistent browser control surface'), { replacementRead });

  const finalTabs = await extEval(async (ids) => Promise.all(ids.map(async (id) => {
    try { const tab = await chrome.tabs.get(id); return { id, exists: true, url: tab.url || '' }; } catch { return { id, exists: false }; }
  })), controlledTabIds, 'final_tab_inventory');
  record('final_controlled_tabs_healthy', finalTabs.every((tab) => tab.exists), { finalTabs });

  report.ok = report.checks.every((check) => check.ok);
  report.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'browser-extension-e2e.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ browser: report.browser_version, extensionId, checks: report.checks.length, timings_ms: report.timings_ms }, null, 2));
  console.log('PASS Browser Agent E2E: extension load, tab create/query/update/navigation/reload/back/forward/group/ungroup/close/reopen, page-runtime actions, scripting, screenshot, worker termination/restart and post-restart control all passed');
} catch (error) {
  report.error = { message: String(error?.message || error), stack: String(error?.stack || '').slice(0, 12000) };
  report.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'browser-extension-e2e.json'), JSON.stringify(report, null, 2)).catch(() => {});
  console.error(`FAIL Browser Agent E2E: ${error?.message || error}`);
  process.exitCode = 1;
} finally {
  if (commandPage && controlledTabIds.length) {
    try { await commandPage.evaluate(async (ids) => { for (const id of ids) { try { await chrome.tabs.remove(id); } catch {} } }, controlledTabIds); } catch {}
  }
  try { await browser?.close(); } catch {}
}
