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

const report = { ok: false, started_at: new Date().toISOString(), base_url: baseUrl, extension_dir: extensionDir, checks: [], timings_ms: {} };
const record = (name, ok, detail = {}) => {
  report.checks.push({ name, ok: Boolean(ok), ...detail });
  if (!ok) throw new Error(`${name}: ${JSON.stringify(detail)}`);
};
const withTimeout = async (label, promise, timeoutMs = 15000) => {
  const started = performance.now();
  let timer;
  try {
    const value = await Promise.race([
      promise,
      new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(`${label}_timeout_${timeoutMs}ms`)), timeoutMs); }),
    ]);
    report.timings_ms[label] = Math.round((performance.now() - started) * 100) / 100;
    return value;
  } finally { clearTimeout(timer); }
};

let browser;
let commandPage;
let controlledTabIds = [];
let workerTarget;
let extensionId = '';
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
      if (response?.ok) {
        const result = response.result;
        const expected = String(body.expectedUrlIncludes || '');
        const hasUrl = Boolean(result && typeof result === 'object' && Object.prototype.hasOwnProperty.call(result, 'url'));
        if (!expected || !hasUrl || String(result.url || '').includes(expected)) return result;
        last = { error: 'page_runtime_stale_document', expected, actual: String(result.url || '') };
      } else last = response || null;
    } catch (error) { last = { error: String(error?.message || error) }; }
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 100));
  }
  throw new Error(`page_runtime_unavailable:${JSON.stringify(last)}`);
}, { tabId, payload, timeoutMs }, label, timeoutMs + 2000);

const waitReloadBoundary = (tabId, marker, label, timeoutMs = 12000) => extEval(async ({ tabId: id, marker: oldMarker, timeoutMs: bounded }) => {
  const deadline = Date.now() + bounded;
  let last = null;
  while (Date.now() < deadline) {
    try {
      const rows = await chrome.scripting.executeScript({
        target: { tabId: id, allFrames: false },
        func: () => ({ marker: globalThis.__rpstudioReloadMarker || '', readyState: document.readyState, url: location.href }),
      });
      last = rows?.[0]?.result || null;
      if (last && last.marker !== oldMarker && last.readyState === 'complete') return last;
    } catch (error) { last = { error: String(error?.message || error) }; }
    await new Promise((resolvePromise) => setTimeout(resolvePromise, 100));
  }
  throw new Error(`reload_boundary_timeout:${JSON.stringify(last)}`);
}, { tabId, marker, timeoutMs }, label, timeoutMs + 2000);

const createTab = async (url, active, label) => {
  const tab = await extEval(async ({ url: targetUrl, active: makeActive }) => {
    const win = await chrome.windows.getLastFocused({ windowTypes: ['normal'] });
    if (!Number.isInteger(win?.id)) throw new Error('normal_window_missing');
    const created = await chrome.tabs.create({ windowId: win.id, url: targetUrl, active: makeActive });
    return { id: created.id, windowId: created.windowId, active: created.active };
  }, { url, active }, `${label}_create`, 10000);
  if (!Number.isInteger(tab.id)) throw new Error(`${label}_missing_id`);
  controlledTabIds.push(tab.id);
  const ping = await runtimeCall(tab.id, { kind: 'ping', expectedUrlIncludes: new URL(url).pathname }, `${label}_ping`, 12000);
  if (!ping?.pong) throw new Error(`${label}_runtime_not_ready`);
  return tab;
};

const activate = (tabId, label) => extEval(async (id) => { await chrome.tabs.update(id, { active: true }); return true; }, tabId, label, 5000);

try {
  browser = await withTimeout('browser_launch', puppeteer.launch({
    headless: false,
    pipe: true,
    enableExtensions: [extensionDir],
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  }), 60000);
  report.browser_version = await browser.version();

  workerTarget = await withTimeout('service_worker_start', browser.waitForTarget(
    (target) => target.type() === 'service_worker' && target.url().startsWith('chrome-extension://') && target.url().endsWith('/service-worker-bootstrap.js'),
    { timeout: 30000 },
  ), 35000);
  report.worker_url = workerTarget.url();
  extensionId = workerTarget.url().split('/')[2] || '';
  record('exact_mv3_worker_loaded', Boolean(extensionId), { worker_url: report.worker_url, extension_id: extensionId });

  commandPage = await browser.newPage();
  await withTimeout('extension_page_open', commandPage.goto(`chrome-extension://${extensionId}/sidepanel.html`, { waitUntil: 'domcontentloaded', timeout: 15000 }), 20000);

  const api = await extEval(() => ({
    tabs: ['create','get','query','update','reload','goBack','goForward','remove','group','ungroup','sendMessage','captureVisibleTab'].filter((name) => typeof chrome.tabs?.[name] === 'function'),
    scripting: typeof chrome.scripting?.executeScript === 'function',
    debuggerApi: typeof chrome.debugger?.attach === 'function' && typeof chrome.debugger?.sendCommand === 'function',
    storage: typeof chrome.storage?.local?.get === 'function',
  }), null, 'api_surface');
  record('required_chrome_api_surface', api.tabs.length === 12 && api.scripting && api.debuggerApi && api.storage, api);

  const tabA = await createTab(`${baseUrl}/parity-a.html`, true, 'tab_a');
  const tabB = await createTab(`${baseUrl}/parity-b.html`, false, 'tab_b');
  const tabC = await createTab(`${baseUrl}/parity-c.html`, false, 'tab_c');
  record('three_tabs_same_window', tabA.windowId === tabB.windowId && tabB.windowId === tabC.windowId, { tabA, tabB, tabC });

  const queried = await extEval(async (ids) => {
    const first = await chrome.tabs.get(ids[0]);
    const rows = await chrome.tabs.query({ windowId: first.windowId });
    return rows.filter((tab) => ids.includes(tab.id)).map((tab) => ({ id: tab.id, url: tab.url || '', active: tab.active }));
  }, [tabA.id, tabB.id, tabC.id], 'tabs_query');
  record('tabs_query_sees_all_controlled_tabs', queried.length === 3, { queried });

  const ready = await runtimeCall(tabA.id, { kind: 'wait_ready', selector: '#parity-button', timeoutMs: 5000, expectedUrlIncludes: 'parity-a.html' }, 'wait_ready', 7000);
  record('wait_ready_real_page', ready?.ready === true && ready?.selectorReady === true, { ready });
  const fill = await runtimeCall(tabA.id, { kind: 'dom_action', action: 'fill', args: { selector: '#parity-input', value: 'rpstudio-live' }, expectedUrlIncludes: 'parity-a.html' }, 'fill');
  record('fill_through_page_runtime', fill?.ok === true, { fill });
  const click = await runtimeCall(tabA.id, { kind: 'dom_action', action: 'click', args: { selector: '#parity-button' }, expectedUrlIncludes: 'parity-a.html' }, 'click');
  record('click_through_page_runtime', click?.ok === true, { click });
  const read = await runtimeCall(tabA.id, { kind: 'read_value', selector: '#parity-input' }, 'read_value');
  record('read_value_after_fill', read?.supported === true && read?.value === 'rpstudio-live', { read });

  const mutation = await extEval(async (tabId) => {
    const rows = await chrome.scripting.executeScript({ target: { tabId }, func: () => ({ value: document.querySelector('#parity-input')?.value || '', clicked: document.body.dataset.clicked || '', url: location.href }) });
    return rows?.[0]?.result || null;
  }, tabA.id, 'scripting_mutation');
  record('chrome_scripting_observes_real_mutation', mutation?.value === 'rpstudio-live' && mutation?.clicked === 'yes', { mutation });

  await activate(tabC.id, 'activate_c');
  const backgroundFill = await runtimeCall(tabA.id, { kind: 'dom_action', action: 'fill', args: { selector: '#parity-input', value: 'background-controlled' }, expectedUrlIncludes: 'parity-a.html' }, 'background_fill');
  record('background_tab_remains_controllable', backgroundFill?.ok === true, { backgroundFill });

  const groupId = await extEval(async (ids) => chrome.tabs.group({ tabIds: ids }), [tabA.id, tabB.id, tabC.id], 'group');
  if (!Number.isInteger(groupId) || groupId < 0) throw new Error('tab_group_not_created');
  await extEval(async (ids) => { await chrome.tabs.ungroup(ids); return true; }, [tabA.id, tabB.id, tabC.id], 'ungroup');
  record('tab_group_roundtrip', true, { groupId });

  await extEval(async ({ id, url }) => { await chrome.tabs.update(id, { url }); return true; }, { id: tabA.id, url: `${baseUrl}/parity-b.html?nav=1` }, 'navigate_a');
  const navPing = await runtimeCall(tabA.id, { kind: 'ping', expectedUrlIncludes: 'parity-b.html?nav=1' }, 'navigate_ping', 12000);
  record('page_runtime_reconnects_after_navigation', navPing?.pong === true, { navPing });

  const marker = `reload-${Date.now()}-${Math.random()}`;
  await extEval(async ({ id, marker: value }) => {
    await chrome.scripting.executeScript({ target: { tabId: id }, func: (v) => { globalThis.__rpstudioReloadMarker = v; }, args: [value] });
    await chrome.tabs.reload(id);
    return true;
  }, { id: tabA.id, marker }, 'reload_a');
  const reloadBoundary = await waitReloadBoundary(tabA.id, marker, 'reload_boundary');
  const reloadPing = await runtimeCall(tabA.id, { kind: 'ping', expectedUrlIncludes: 'parity-b.html?nav=1' }, 'reload_ping', 12000);
  record('page_runtime_reconnects_after_reload', reloadBoundary?.readyState === 'complete' && reloadPing?.pong === true, { reloadBoundary, reloadPing });

  await extEval(async ({ id, url }) => { await chrome.tabs.update(id, { url }); return true; }, { id: tabA.id, url: `${baseUrl}/parity-c.html?history=1` }, 'navigate_history');
  await runtimeCall(tabA.id, { kind: 'ping', expectedUrlIncludes: 'parity-c.html?history=1' }, 'history_ping', 12000);
  await extEval(async (id) => { await chrome.tabs.goBack(id); return true; }, tabA.id, 'go_back');
  const backPing = await runtimeCall(tabA.id, { kind: 'ping', expectedUrlIncludes: 'parity-b.html?nav=1' }, 'back_ping', 12000);
  record('tab_go_back_keeps_runtime', backPing?.pong === true, { backPing });
  await extEval(async (id) => { await chrome.tabs.goForward(id); return true; }, tabA.id, 'go_forward');
  const forwardPing = await runtimeCall(tabA.id, { kind: 'ping', expectedUrlIncludes: 'parity-c.html?history=1' }, 'forward_ping', 12000);
  record('tab_go_forward_keeps_runtime', forwardPing?.pong === true, { forwardPing });

  await activate(tabC.id, 'activate_c_capture');
  const capture = await extEval(async ({ tabId, windowId }) => {
    const tab = await chrome.tabs.get(tabId);
    if (!tab.active || tab.windowId !== windowId) throw new Error(`capture_target_not_active:${JSON.stringify({ tabId: tab.id, windowId: tab.windowId, active: tab.active })}`);
    try {
      const dataUrl = await chrome.tabs.captureVisibleTab(windowId, { format: 'png' });
      return { dataUrl, transport: 'chrome.tabs.captureVisibleTab' };
    } catch (error) {
      const initialError = String(error?.message || error);
      if (!/image readback failed/i.test(initialError)) throw error;
      const debuggee = { tabId };
      let attached = false;
      try {
        await chrome.debugger.attach(debuggee, '1.3');
        attached = true;
        await chrome.debugger.sendCommand(debuggee, 'Page.enable');
        const result = await chrome.debugger.sendCommand(debuggee, 'Page.captureScreenshot', { format: 'png' });
        return {
          dataUrl: `data:image/png;base64,${String(result?.data || '')}`,
          transport: 'chrome.debugger/Page.captureScreenshot-surface',
          captureVisibleTabError: initialError,
        };
      } finally {
        if (attached) await chrome.debugger.detach(debuggee);
      }
    }
  }, { tabId: tabC.id, windowId: tabC.windowId }, 'capture', 15000);
  record('visible_tab_screenshot_real', typeof capture?.dataUrl === 'string' && capture.dataUrl.startsWith('data:image/png;base64,') && capture.dataUrl.length > 1000, { bytes: capture?.dataUrl?.length || 0, transport: capture?.transport || '', captureVisibleTabError: capture?.captureVisibleTabError || '' });

  const focus = await extEval(async () => {
    const win = await chrome.windows.getLastFocused({ windowTypes: ['normal'] });
    const active = Number.isInteger(win?.id) ? await chrome.tabs.query({ active: true, windowId: win.id }) : [];
    return { windowId: win?.id ?? null, focused: win?.focused ?? null, active: active.map((tab) => ({ id: tab.id, url: tab.url || '' })) };
  }, null, 'focus_preflight', 5000);
  record('focused_window_resolves_for_local_lane', Number.isInteger(focus.windowId) && focus.active.some((tab) => tab.id === tabC.id), { focus });

  const localStatus = await extEval(async () => chrome.runtime.sendMessage({ type: 'local_status' }), null, 'local_status', 7000);
  record('local_status_worker_message_real', Boolean(localStatus?.ok), { localStatus });
  const directHealth = await extEval(async (tabId) => {
    const module = await import(chrome.runtime.getURL('lib/local-page-functions.js'));
    const rows = await chrome.scripting.executeScript({ target: { tabId, allFrames: false }, func: module.collectLocalPageHealth });
    return rows?.[0]?.result || null;
  }, tabC.id, 'direct_health', 7000);
  record('direct_local_health_scripting_real', Boolean(directHealth?.url), { directHealth });
  const localHealth = await extEval(async () => chrome.runtime.sendMessage({ type: 'local_page_health' }), null, 'local_page_health', 15000);
  record('service_worker_local_health_real', localHealth?.ok === true && Boolean(localHealth?.result?.url), { localHealth });

  const oldWorker = await workerTarget.worker();
  await withTimeout('service_worker_terminate', oldWorker.close(), 10000);
  const restartedTarget = await withTimeout('service_worker_restart', browser.waitForTarget(
    (target) => target !== workerTarget && target.type() === 'service_worker' && target.url() === report.worker_url,
    { timeout: 15000 },
  ), 18000);
  record('mv3_worker_restarts_after_termination', Boolean(restartedTarget), { restarted_url: restartedTarget.url() });

  const postRestartStatus = await extEval(async () => {
    const deadline = Date.now() + 10000;
    let last = null;
    while (Date.now() < deadline) {
      try { last = await chrome.runtime.sendMessage({ type: 'status' }); if (last) return last; }
      catch (error) { last = { error: String(error?.message || error) }; }
      await new Promise((resolvePromise) => setTimeout(resolvePromise, 100));
    }
    throw new Error(`worker_status_timeout:${JSON.stringify(last)}`);
  }, null, 'status_after_restart', 12000);
  record('worker_accepts_messages_after_restart', Boolean(postRestartStatus), { keys: Object.keys(postRestartStatus || {}).slice(0, 20) });
  const postRestartPing = await runtimeCall(tabC.id, { kind: 'ping', expectedUrlIncludes: 'parity-c.html' }, 'existing_tab_after_restart', 12000);
  record('existing_page_runtime_survives_worker_restart', postRestartPing?.pong === true, { postRestartPing });
  const healthAfterRestart = await extEval(async () => chrome.runtime.sendMessage({ type: 'local_page_health' }), null, 'health_after_restart', 15000);
  record('service_worker_pipeline_recovers_after_restart', healthAfterRestart?.ok === true, { healthAfterRestart });

  await extEval(async (id) => { await chrome.tabs.remove(id); return true; }, tabB.id, 'close_b');
  controlledTabIds = controlledTabIds.filter((id) => id !== tabB.id);
  const closed = await extEval(async (id) => { try { await chrome.tabs.get(id); return false; } catch { return true; } }, tabB.id, 'verify_b_closed');
  record('closed_tab_is_really_gone', closed === true, { closed });
  const survivorPing = await runtimeCall(tabC.id, { kind: 'ping', expectedUrlIncludes: 'parity-c.html' }, 'survivor_ping');
  record('closing_one_tab_does_not_break_others', survivorPing?.pong === true, { survivorPing });

  const replacement = await createTab(`${baseUrl}/parity-a.html?replacement=1`, false, 'replacement');
  const replacementRead = await runtimeCall(replacement.id, { kind: 'dom_action', action: 'extract_text', args: { selector: '#parity-content' }, expectedUrlIncludes: 'parity-a.html?replacement=1' }, 'replacement_read');
  record('can_open_and_control_new_tab_after_close_restart', replacementRead?.ok === true && String(replacementRead?.text || '').includes('persistent browser control surface'), { replacementRead });

  const finalTabs = await extEval(async (ids) => Promise.all(ids.map(async (id) => {
    try { const tab = await chrome.tabs.get(id); return { id, exists: true, url: tab.url || '' }; }
    catch { return { id, exists: false }; }
  })), controlledTabIds, 'final_inventory');
  record('final_controlled_tabs_healthy', finalTabs.every((tab) => tab.exists), { finalTabs });

  report.ok = report.checks.every((check) => check.ok);
  report.finished_at = new Date().toISOString();
  await writeFile(join(artifactDir, 'browser-extension-e2e.json'), JSON.stringify(report, null, 2));
  console.log(JSON.stringify({ browser: report.browser_version, extensionId, checks: report.checks.length, timings_ms: report.timings_ms }, null, 2));
  console.log('PASS Browser Agent E2E: real tab lifecycle, page-runtime reconnect, scripting, screenshot and MV3 worker restart all passed');
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
