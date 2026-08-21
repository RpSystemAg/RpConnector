import test from "node:test";
import assert from "node:assert/strict";

function eventApi() {
  const listeners = new Set();
  return {
    addListener(listener) { listeners.add(listener); },
    removeListener(listener) { listeners.delete(listener); },
    async emit(...args) { for (const listener of [...listeners]) await listener(...args); },
  };
}
function createStorageArea() {
  const store = new Map();
  return {
    async get(keys) {
      if (keys == null) return Object.fromEntries(store);
      const out = {};
      const list = Array.isArray(keys) ? keys : typeof keys === "string" ? [keys] : Object.keys(keys || {});
      for (const key of list) if (store.has(key)) out[key] = store.get(key);
      return out;
    },
    async set(value) { for (const [key, item] of Object.entries(value || {})) store.set(key, item); },
    async remove(keys) { for (const key of (Array.isArray(keys) ? keys : [keys])) store.delete(key); },
  };
}

const local = createStorageArea();
const session = createStorageArea();
const noop = async () => undefined;
globalThis.chrome = {
  runtime: { onInstalled: eventApi(), onStartup: eventApi(), onMessage: eventApi(), onConnect: eventApi(), getURL: (path) => `chrome-extension://test/${path}` },
  alarms: { onAlarm: eventApi(), create: noop, clear: async () => true, get: async () => null },
  tabs: {
    onCreated: eventApi(), onReplaced: eventApi(), onRemoved: eventApi(), onActivated: eventApi(), onUpdated: eventApi(),
    query: async () => [], get: async () => { throw new Error("tab_missing"); }, update: noop, create: async () => ({ id: 1, windowId: 1, url: "about:blank" }), remove: noop, captureVisibleTab: async () => "",
  },
  windows: { onRemoved: eventApi(), onFocusChanged: eventApi(), getAll: async () => [], get: async () => { throw new Error("window_missing"); }, create: async () => ({ id: 1, tabs: [] }), update: noop, remove: noop, WINDOW_ID_NONE: -1 },
  debugger: { onDetach: eventApi(), onEvent: eventApi(), getTargets: async () => [], attach: noop, detach: noop, sendCommand: noop },
  storage: { local, session },
  action: { setBadgeText: noop, setBadgeBackgroundColor: noop },
  scripting: { executeScript: async () => [] },
  notifications: { create: noop },
};

const { __test } = await import(`../service-worker.js?runtime-timeout=${Date.now()}`);

function after(ms, value) { return new Promise((resolve) => setTimeout(() => resolve(value), ms)); }

test("operation timeout rejects even when timeout cleanup never settles", async () => {
  const never = new Promise(() => {});
  const operation = __test.promiseWithTimeout(
    never,
    25,
    () => __test.codedError("EXPECTED_TIMEOUT", "expected timeout"),
    () => new Promise(() => {}),
  ).then(
    () => ({ kind: "resolved" }),
    (error) => ({ kind: "rejected", code: error?.code || "" }),
  );

  const observed = await Promise.race([operation, after(700, { kind: "outer_guard" })]);
  assert.deepEqual(observed, { kind: "rejected", code: "EXPECTED_TIMEOUT" });
});

test("debugger detach wrapper is itself bounded", async () => {
  chrome.debugger.detach = () => new Promise(() => {});
  const operation = __test.debuggerDetachWithTimeout(77, 25).then(
    () => ({ kind: "resolved" }),
    (error) => ({ kind: "rejected", code: error?.code || "" }),
  );
  const observed = await Promise.race([operation, after(700, { kind: "outer_guard" })]);
  assert.deepEqual(observed, { kind: "rejected", code: "CDP_DETACH_TIMEOUT" });
});

test("tab-replacement-interrupted runtime sessions are not considered active", async () => {
  await chrome.storage.local.set({
    prstudioRuntimeSessions: {
      "rt_trace_regression": {
        id: "rt_trace_regression",
        tabId: 44,
        type: "trace",
        expiresAt: Date.now() + 60_000,
        interruptedByWorkerRestart: false,
        interruptedByTabReplacement: true,
      },
    },
  });
  const types = await __test.runtimeSessionTypesForTab(44);
  assert.deepEqual([...types], []);
});


test("expired runtime session performs bounded CDP cleanup before metadata disappears", async () => {
  const calls = [];
  chrome.debugger.getTargets = async () => [{ tabId: 55, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => { calls.push(method); return {}; };
  chrome.debugger.detach = async ({ tabId }) => { calls.push(`detach:${tabId}`); };
  await chrome.storage.local.set({
    prstudioRuntimeSessions: {
      "rt_trace_expired": {
        id: "rt_trace_expired", tabId: 55, type: "trace",
        expiresAt: Date.now() - 1, interruptedByWorkerRestart: false,
      },
    },
  });
  const remaining = await __test.pruneRuntimeSessions();
  assert.deepEqual(Object.keys(remaining), []);
  assert.ok(calls.includes("Tracing.end"), `missing Tracing.end in ${JSON.stringify(calls)}`);
  assert.ok(calls.includes("detach:55"), `missing debugger detach in ${JSON.stringify(calls)}`);
});


test("runtime timeout cap rejects unbounded remote durations", () => {
  assert.equal(__test.boundedRuntimeTimeout(9_999_999, 30_000, 120_000), 120_000);
  assert.equal(__test.boundedRuntimeTimeout(Infinity, 30_000, 120_000), 30_000);
  assert.equal(__test.boundedRuntimeTimeout(-1, 30_000, 120_000), 250);
});


test("trace stop returns a technical failure when CDP Tracing.end fails", async () => {
  const tabId = 66;
  const agentWindowId = 7;
  const sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  chrome.tabs.get = async (id) => {
    if (Number(id) === tabId) return { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Owned test tab" };
    if (Number(id) === sentinelId) return { id: sentinelId, windowId: agentWindowId, url: sentinelUrl, title: "Agent" };
    throw new Error("tab_missing");
  };
  chrome.windows.get = async (id) => {
    if (Number(id) !== agentWindowId) throw new Error("window_missing");
    return { id: agentWindowId, tabs: [
      { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
      { id: tabId, windowId: agentWindowId, url: "https://example.test/" },
    ] };
  };
  await chrome.storage.local.set({
    prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId },
    prstudioRuntimeSessions: {
      "rt_trace_stop_failure": {
        id: "rt_trace_stop_failure", tabId, type: "trace",
        expiresAt: Date.now() + 60_000, interruptedByWorkerRestart: false,
      },
    },
  });
  await __test.registerOwnedTab(tabId, { taskId: "test-task", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => {
    if (method === "Tracing.end") throw Object.assign(new Error("injected stop failure"), { code: "INJECTED_STOP_FAILURE" });
    return {};
  };
  const outcome = await __test.executeKnownContractAction(
    { tabId, taskId: "test-task" },
    { action: "playwright_stop_trace", args: { tab_id: tabId, session_id: "rt_trace_stop_failure", settle_ms: 0 } },
  ).then(
    (value) => ({ kind: "resolved", value }),
    (error) => ({ kind: "rejected", code: error?.code || "", message: error?.message || "" }),
  );
  assert.equal(outcome.kind, "rejected", `stop trace misleadingly resolved: ${JSON.stringify(outcome)}`);
  assert.equal(outcome.code, "INJECTED_STOP_FAILURE", `wrong rejection path: ${JSON.stringify(outcome)}`);
  const sessions = (await chrome.storage.local.get("prstudioRuntimeSessions")).prstudioRuntimeSessions || {};
  assert.ok(sessions.rt_trace_stop_failure, "failed stop must retain session evidence for retry/recovery");
});

test("video stop returns a technical failure when CDP Page.stopScreencast fails", async () => {
  const tabId = 67, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Owned test tab" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [{ id: sentinelId, windowId: agentWindowId, url: sentinelUrl }, { id: tabId, windowId: agentWindowId, url: "https://example.test/" }] });
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId }, prstudioRuntimeSessions: { rt_video_stop_failure: { id: "rt_video_stop_failure", tabId, type: "video", expiresAt: Date.now() + 60_000 } } });
  await __test.registerOwnedTab(tabId, { taskId: "test-task", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => { if (method === "Page.stopScreencast") throw Object.assign(new Error("injected video stop failure"), { code: "INJECTED_VIDEO_STOP_FAILURE" }); return {}; };
  const outcome = await __test.executeKnownContractAction({ tabId, taskId: "test-task" }, { action: "playwright_stop_video", args: { tab_id: tabId, session_id: "rt_video_stop_failure" } }).then(value => ({ kind: "resolved", value }), error => ({ kind: "rejected", code: error?.code || "" }));
  assert.deepEqual(outcome, { kind: "rejected", code: "INJECTED_VIDEO_STOP_FAILURE" });
  const sessions = (await chrome.storage.local.get("prstudioRuntimeSessions")).prstudioRuntimeSessions || {};
  assert.ok(sessions.rt_video_stop_failure);
});

test("route disable returns a technical failure when CDP Fetch.disable fails", async () => {
  const tabId = 68, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  chrome.tabs.get = async (id) => Number(id) === tabId ? { id: tabId, windowId: agentWindowId, url: "https://example.test/" } : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [{ id: sentinelId, windowId: agentWindowId, url: sentinelUrl }, { id: tabId, windowId: agentWindowId, url: "https://example.test/" }] });
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId }, prstudioRuntimeSessions: { rt_route_stop_failure: { id: "rt_route_stop_failure", tabId, type: "route", expiresAt: Date.now() + 60_000 } } });
  await __test.registerOwnedTab(tabId, { taskId: "test-task", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => { if (method === "Fetch.disable") throw Object.assign(new Error("injected route stop failure"), { code: "INJECTED_ROUTE_STOP_FAILURE" }); return {}; };
  const outcome = await __test.executeKnownContractAction({ tabId, taskId: "test-task" }, { action: "playwright_unroute", args: { tab_id: tabId, session_id: "rt_route_stop_failure" } }).then(value => ({ kind: "resolved", value }), error => ({ kind: "rejected", code: error?.code || "" }));
  assert.deepEqual(outcome, { kind: "rejected", code: "INJECTED_ROUTE_STOP_FAILURE" });
  const sessions = (await chrome.storage.local.get("prstudioRuntimeSessions")).prstudioRuntimeSessions || {};
  assert.ok(sessions.rt_route_stop_failure);
});

test("JS coverage stop returns a technical failure when CDP stopPreciseCoverage fails", async () => {
  const tabId = 69, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  chrome.tabs.get = async (id) => Number(id) === tabId ? { id: tabId, windowId: agentWindowId, url: "https://example.test/" } : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [{ id: sentinelId, windowId: agentWindowId, url: sentinelUrl }, { id: tabId, windowId: agentWindowId, url: "https://example.test/" }] });
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId }, prstudioRuntimeSessions: { rt_js_stop_failure: { id: "rt_js_stop_failure", tabId, type: "js_coverage", expiresAt: Date.now() + 60_000 } } });
  await __test.registerOwnedTab(tabId, { taskId: "test-task", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => { if (method === "Profiler.takePreciseCoverage") return { result: [] }; if (method === "Profiler.stopPreciseCoverage") throw Object.assign(new Error("injected coverage stop failure"), { code: "INJECTED_COVERAGE_STOP_FAILURE" }); return {}; };
  const outcome = await __test.executeKnownContractAction({ tabId, taskId: "test-task" }, { action: "playwright_stop_js_coverage", args: { tab_id: tabId, session_id: "rt_js_stop_failure" } }).then(value => ({ kind: "resolved", value }), error => ({ kind: "rejected", code: error?.code || "" }));
  assert.deepEqual(outcome, { kind: "rejected", code: "INJECTED_COVERAGE_STOP_FAILURE" });
  const sessions = (await chrome.storage.local.get("prstudioRuntimeSessions")).prstudioRuntimeSessions || {};
  assert.ok(sessions.rt_js_stop_failure);
});

test("responsive matrix returns a technical failure when viewport restore fails", async () => {
  const tabId = 70, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Owned responsive test tab" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/" },
  ] });
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "test-task", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => {
    if (method === "Page.getLayoutMetrics") return {
      cssVisualViewport: { clientWidth: 375, clientHeight: 812 },
      cssContentSize: { width: 375, height: 812 },
    };
    if (method === "Page.captureScreenshot") return { data: "aGVsbG8=" };
    if (method === "Emulation.clearDeviceMetricsOverride") {
      throw Object.assign(new Error("injected viewport restore failure"), { code: "INJECTED_VIEWPORT_RESTORE_FAILURE" });
    }
    return {};
  };
  const outcome = await __test.executeKnownContractAction(
    { tabId, taskId: "test-task" },
    { action: "playwright_responsive_matrix", args: { tab_id: tabId, viewports: [{ width: 375, height: 812, name: "mobile" }] } },
  ).then(
    (value) => ({ kind: "resolved", value }),
    (error) => ({ kind: "rejected", code: error?.code || "", message: error?.message || "" }),
  );
  assert.equal(outcome.kind, "rejected", `responsive matrix misleadingly resolved: ${JSON.stringify(outcome)}`);
  assert.equal(outcome.code, "INJECTED_VIEWPORT_RESTORE_FAILURE", `wrong rejection path: ${JSON.stringify(outcome)}`);
});

test("responsive matrix never requests a single runtime timeout above the interactive envelope", async () => {
  const tabId = 701, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Responsive timeout bound" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/" },
  ] });
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "responsive-timeout-bound", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => {
    if (method === "Emulation.setDeviceMetricsOverride") return new Promise(() => {});
    return {};
  };
  const previousSetTimeout = globalThis.setTimeout;
  const requestedDelays = [];
  globalThis.setTimeout = (fn, ms, ...args) => {
    requestedDelays.push(Number(ms || 0));
    return previousSetTimeout(fn, Math.min(25, Number(ms || 0)), ...args);
  };
  try {
    const outcome = await __test.executeKnownContractAction(
      { tabId, taskId: "responsive-timeout-bound", inFlight: { type: "contract_action", action: "playwright_responsive_matrix", startedAt: Date.now(), attemptId: "matrix-attempt" } },
      { action: "playwright_responsive_matrix", args: { tab_id: tabId, viewports: [{ width: 375, height: 812, name: "mobile" }] } },
    ).then(
      (value) => ({ kind: "resolved", value }),
      (error) => ({ kind: "rejected", code: error?.code || "" }),
    );
    assert.equal(outcome.kind, "rejected");
    assert.ok(requestedDelays.length > 0);
    assert.ok(Math.max(...requestedDelays) <= 10_000, `responsive matrix requested excessive timeout(s): ${JSON.stringify(requestedDelays)}`);
  } finally {
    globalThis.setTimeout = previousSetTimeout;
  }
});

test("visible-tab screenshot fallback returns a technical failure when prior active tab cannot be restored", async () => {
  const tabId = 72, previousTabId = 71, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Owned screenshot test tab" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl }
      : Number(id) === previousTabId ? { id: previousTabId, windowId: agentWindowId, url: "https://operator.test/" }
        : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: previousTabId, windowId: agentWindowId, url: "https://operator.test/", active: true },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/", active: false },
  ] });
  chrome.tabs.query = async () => [{ id: previousTabId, windowId: agentWindowId, active: true }];
  chrome.tabs.update = async (id, props) => {
    if (Number(id) === previousTabId && props?.active === true) {
      throw Object.assign(new Error("injected active tab restore failure"), { code: "INJECTED_ACTIVE_TAB_RESTORE_FAILURE" });
    }
    return { id: Number(id), windowId: agentWindowId, active: Boolean(props?.active) };
  };
  chrome.tabs.captureVisibleTab = async () => "data:image/png;base64,aGVsbG8=";
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "test-task", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => {
    if (method === "Page.getLayoutMetrics") return {
      cssVisualViewport: { clientWidth: 1280, clientHeight: 720 },
      cssContentSize: { width: 1280, height: 720 },
    };
    if (method === "Page.captureScreenshot") throw Object.assign(new Error("force visible capture fallback"), { code: "INJECTED_CDP_CAPTURE_FAILURE" });
    return {};
  };
  const outcome = await __test.captureScreenshot(tabId, false, false).then(
    (value) => ({ kind: "resolved", value }),
    (error) => ({ kind: "rejected", code: error?.code || "", details: error?.details || {} }),
  );
  assert.equal(outcome.kind, "rejected", `fallback misleadingly resolved: ${JSON.stringify(outcome)}`);
  assert.equal(outcome.code, "screenshot_failed", `wrong rejection path: ${JSON.stringify(outcome)}`);
  assert.equal(outcome.details?.fallback?.code, "INJECTED_ACTIVE_TAB_RESTORE_FAILURE", `restore failure was not preserved: ${JSON.stringify(outcome)}`);
});

test("screenshot transport failure does not burn compatibility retries before visible fallback", async () => {
  const tabId = 77, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  let captureCalls = 0;
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Fast screenshot fallback" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/", active: true },
  ] });
  chrome.tabs.query = async () => [{ id: tabId, windowId: agentWindowId, active: true }];
  chrome.tabs.update = async (id, props) => ({ id: Number(id), windowId: agentWindowId, ...props });
  chrome.tabs.captureVisibleTab = async () => "data:image/png;base64,aGVsbG8=";
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "fast-shot-task", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => {
    if (method === "Page.getLayoutMetrics") return { cssVisualViewport: { clientWidth: 1280, clientHeight: 720 }, cssContentSize: { width: 1280, height: 720 } };
    if (method === "Page.captureScreenshot") {
      captureCalls += 1;
      throw Object.assign(new Error("Target closed while capturing screenshot"), { code: "INJECTED_TARGET_CLOSED" });
    }
    return {};
  };
  const result = await __test.captureScreenshot(tabId, false, false);
  assert.equal(result.captureMode, "tabs_capture_fallback");
  assert.equal(captureCalls, 1, `non-compatibility failure burned ${captureCalls} CDP screenshot attempts`);
});

test("screenshot parameter incompatibility preserves one-step CDP downgrade", async () => {
  const tabId = 78, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  let captureCalls = 0;
  let visibleCalls = 0;
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Compatible screenshot downgrade" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/", active: true },
  ] });
  chrome.tabs.query = async () => [{ id: tabId, windowId: agentWindowId, active: true }];
  chrome.tabs.captureVisibleTab = async () => { visibleCalls += 1; return "data:image/png;base64,aGVsbG8="; };
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "compatible-shot-task", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => {
    if (method === "Page.getLayoutMetrics") return { cssVisualViewport: { clientWidth: 1280, clientHeight: 720 }, cssContentSize: { width: 1280, height: 720 } };
    if (method === "Page.captureScreenshot") {
      captureCalls += 1;
      if (captureCalls === 1) throw Object.assign(new Error("Invalid parameters: unsupported screenshot option"), { code: -32602 });
      return { data: "aGVsbG8=" };
    }
    return {};
  };
  const result = await __test.captureScreenshot(tabId, false, false);
  assert.equal(result.captureMode, "cdp_compatible_downgrade");
  assert.equal(captureCalls, 2);
  assert.equal(visibleCalls, 0);
});

test("simple screenshot fast path does not wait for unrelated observability domains", async () => {
  const tabId = 79, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  const calls = [];
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Screenshot fast path" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/", active: true },
  ] });
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "fast-path-shot", expectedOrigin: "https://example.test", url: "https://example.test/" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => {
    calls.push(method);
    if (method === "Runtime.enable") await after(900, null);
    if (method === "Page.getLayoutMetrics") return { cssVisualViewport: { clientWidth: 1280, clientHeight: 720 }, cssContentSize: { width: 1280, height: 720 } };
    if (method === "Page.captureScreenshot") return { data: "aGVsbG8=" };
    return {};
  };
  const started = Date.now();
  const result = await __test.captureScreenshot(tabId, false, false);
  const elapsedMs = Date.now() - started;
  assert.equal(result.captureMode, "cdp_native");
  assert.ok(elapsedMs < 500, `simple screenshot waited ${elapsedMs} ms for unrelated observability setup`);
  assert.equal(calls.includes("Runtime.enable"), false, `fast screenshot should not enable Runtime: ${JSON.stringify(calls)}`);
  assert.equal(calls.includes("Network.enable"), false, `fast screenshot should not enable Network: ${JSON.stringify(calls)}`);
  assert.equal(calls.includes("Log.enable"), false, `fast screenshot should not enable Log: ${JSON.stringify(calls)}`);
});

test("full-page screenshot lazy-load scroll stays on the bounded screenshot CDP fast path", async () => {
  const tabId = 790, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  const calls = [];
  const previousExecuteScript = chrome.scripting.executeScript;
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/long", title: "Full-page screenshot fast path" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/long", active: true },
  ] });
  chrome.tabs.query = async () => [{ id: tabId, windowId: agentWindowId, active: true, url: "https://example.test/long" }];
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "full-page-fast-path-shot", expectedOrigin: "https://example.test", url: "https://example.test/long" });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  let scrollRead = 0;
  chrome.scripting.executeScript = async () => {
    const y = [0, 800, 1600, 1600, 1600][Math.min(scrollRead++, 4)];
    return [{ result: { x: 0, y, pageWidth: 1280, pageHeight: 2400, viewportWidth: 1280, viewportHeight: 800 } }];
  };
  chrome.debugger.sendCommand = async (_target, method) => {
    calls.push(method);
    if (method === "Page.getLayoutMetrics") return {
      cssVisualViewport: { clientWidth: 1280, clientHeight: 800 },
      cssContentSize: { width: 1280, height: 2400 },
    };
    if (method === "Page.captureScreenshot") return { data: "aGVsbG8=" };
    return {};
  };
  try {
    const result = await __test.captureScreenshot(tabId, true, true, { deadlineAt: Date.now() + 4800 });
    assert.equal(result.captureMode, "cdp_native");
    assert.equal(calls.includes("Runtime.enable"), false, `full-page screenshot should not enable Runtime: ${JSON.stringify(calls)}`);
    assert.equal(calls.includes("Network.enable"), false, `full-page screenshot should not enable Network: ${JSON.stringify(calls)}`);
    assert.equal(calls.includes("Log.enable"), false, `full-page screenshot should not enable Log: ${JSON.stringify(calls)}`);
  } finally {
    chrome.scripting.executeScript = previousExecuteScript;
  }
});

test("full-page screenshot bounds lazy-load DOM scroll reads inside its capture deadline", async () => {
  const tabId = 791, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  const previousExecuteScript = chrome.scripting.executeScript;
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/stalled-scroll", title: "Stalled full-page screenshot" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/stalled-scroll", active: true },
  ] });
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "full-page-stalled-scroll", expectedOrigin: "https://example.test", url: "https://example.test/stalled-scroll" });
  chrome.scripting.executeScript = () => new Promise(() => {});
  try {
    const operation = __test.captureScreenshot(tabId, true, true, { deadlineAt: Date.now() + 4000 }).then(
      () => ({ kind: "resolved" }),
      (error) => ({ kind: "rejected", code: error?.code || "" }),
    );
    const observed = await Promise.race([operation, after(1800, { kind: "outer_guard" })]);
    assert.deepEqual(observed, { kind: "rejected", code: "SCREENSHOT_SCROLL_TIMEOUT" });
  } finally {
    chrome.scripting.executeScript = previousExecuteScript;
  }
});

test("task runtime cleanup returns a technical failure when page-mask restoration injection fails", async () => {
  const tabId = 73, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Masked owned tab" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/" },
  ] });
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "mask-task", expectedOrigin: "https://example.test", url: "https://example.test/" });
  await __test.updateOwnedTab(tabId, { maskPendingRestore: true });
  chrome.scripting.executeScript = async () => {
    throw Object.assign(new Error("injected mask restore failure"), { code: "INJECTED_MASK_RESTORE_FAILURE" });
  };
  chrome.debugger.getTargets = async () => [];
  const outcome = await __test.cleanupTaskRuntime({ tabId }).then(
    (value) => ({ kind: "resolved", value }),
    (error) => ({ kind: "rejected", code: error?.code || "", message: error?.message || "" }),
  );
  assert.equal(outcome.kind, "rejected", `mask cleanup misleadingly resolved: ${JSON.stringify(outcome)}`);
  assert.equal(outcome.code, "INJECTED_MASK_RESTORE_FAILURE", `wrong cleanup failure: ${JSON.stringify(outcome)}`);
});

test("same-origin page fetch executor has a bounded outer timeout", async () => {
  const tabId = 80, agentWindowId = 7, sentinelId = 700;
  const sentinelUrl = chrome.runtime.getURL("agent.html") + "#test-nonce";
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: agentWindowId, url: "https://example.test/page", title: "Fetch timeout tab" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId: agentWindowId, url: sentinelUrl } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async () => ({ id: agentWindowId, tabs: [
    { id: sentinelId, windowId: agentWindowId, url: sentinelUrl },
    { id: tabId, windowId: agentWindowId, url: "https://example.test/page" },
  ] });
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId: agentWindowId, nonce: "test-nonce", sentinelTabId: sentinelId } });
  await __test.registerOwnedTab(tabId, { taskId: "fetch-timeout-task", expectedOrigin: "https://example.test", url: "https://example.test/page" });
  const previousExecute = chrome.scripting.executeScript;
  chrome.scripting.executeScript = () => new Promise(() => {});
  try {
    const operation = __test.executeKnownContractAction(
      { tabId, taskId: "fetch-timeout-task" },
      { action: "fetch", args: { tab_id: tabId, url: "https://example.test/api", timeout_ms: 250 } },
    ).then(
      () => ({ kind: "resolved" }),
      (error) => ({ kind: "rejected", code: error?.code || "" }),
    );
    const observed = await Promise.race([operation, after(800, { kind: "outer_guard" })]);
    assert.deepEqual(observed, { kind: "rejected", code: "PAGE_FETCH_TIMEOUT" });
  } finally {
    chrome.scripting.executeScript = previousExecute;
  }
});

test("autonomous sitemap fetch is bounded and does not claim verified on root timeout", async () => {
  const previousFetch = globalThis.fetch;
  globalThis.fetch = () => new Promise(() => {});
  try {
    const operation = __test.executeKnownContractAction(
      { taskId: "sitemap-timeout-task" },
      { action: "playwright_sitemap_crawl", args: { url: "https://example.test/sitemap.xml", fetch_timeout_ms: 250, max_sitemaps: 1, max_urls: 10 } },
    ).then(
      (value) => ({ kind: "resolved", code: value?.errors?.[0]?.error?.code || "", verified: value?.verified }),
      (error) => ({ kind: "rejected", code: error?.code || "", verified: false }),
    );
    const observed = await Promise.race([operation, after(800, { kind: "outer_guard" })]);
    assert.deepEqual(observed, { kind: "resolved", code: "SITEMAP_FETCH_TIMEOUT", verified: false });
  } finally {
    globalThis.fetch = previousFetch;
  }
});

test("robots 5xx is typed technical failure and does not open a crawler tab", async () => {
  const previousFetch = globalThis.fetch;
  const previousCreate = chrome.tabs.create;
  let created = 0;
  globalThis.fetch = async () => new Response("temporary failure", { status: 503 });
  chrome.tabs.create = async () => { created += 1; throw new Error("crawler_tab_should_not_open"); };
  try {
    const value = await __test.executeKnownContractAction(
      { taskId: "robots-503-task" },
      { action: "playwright_link_crawl", args: { url: "https://example.test/", max_pages: 1, max_depth: 0, concurrency: 1 } },
    );
    assert.equal(created, 0, "crawler opened a page despite unreachable robots.txt");
    assert.equal(value.pages?.[0]?.accessible, false);
    assert.equal(value.pages?.[0]?.reason, "robots_unreachable");
  } finally {
    globalThis.fetch = previousFetch;
    chrome.tabs.create = previousCreate;
  }
});

test("robots network hang is bounded and typed technical failure", async () => {
  const previousFetch = globalThis.fetch;
  const previousCreate = chrome.tabs.create;
  let created = 0;
  globalThis.fetch = () => new Promise(() => {});
  chrome.tabs.create = async () => { created += 1; throw new Error("crawler_tab_should_not_open"); };
  try {
    const operation = __test.executeKnownContractAction(
      { taskId: "robots-timeout-task" },
      { action: "playwright_link_crawl", args: { url: "https://timeout.test/", max_pages: 1, max_depth: 0, concurrency: 1, robots_timeout_ms: 250 } },
    ).then(value => ({ kind: "resolved", value }));
    const observed = await Promise.race([operation, after(800, { kind: "outer_guard" })]);
    assert.equal(observed.kind, "resolved");
    assert.equal(created, 0, "crawler opened a page after robots timeout");
    assert.equal(observed.value.pages?.[0]?.accessible, false);
    assert.equal(observed.value.pages?.[0]?.reason, "robots_unreachable");
  } finally {
    globalThis.fetch = previousFetch;
    chrome.tabs.create = previousCreate;
  }
});

test("set_content reports adopted-tab ownership truthfully", async () => {
  const tabId = 81;
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: 55, url: "https://example.test/adopted", title: "Adopted user tab" }
    : Promise.reject(new Error("tab_missing"));
  await chrome.storage.local.set({
    prstudioTabRegistry: {
      [String(tabId)]: {
        tabId, windowId: 55, originalWindowId: 55, taskId: "set-content-adopted-task", laneId: "lane-a",
        expectedOrigin: "https://example.test", url: "https://example.test/adopted", title: "Adopted user tab",
        owner: "prstudio-agent", adoptedExternal: true, ownershipNonce: "adopted-set-content-nonce",
        createdAt: Date.now() - 1000, updatedAt: Date.now(), affinityReason: "explicit_user_tab_adoption",
      },
    },
  });
  const previousExecute = chrome.scripting.executeScript;
  let executions = 0;
  chrome.scripting.executeScript = async () => { executions += 1; return [{ result: true }]; };
  try {
    const result = await __test.executeKnownContractAction(
      { tabId, taskId: "set-content-adopted-task", arguments: { _prstudio_lane_id: "lane-a" } },
      { action: "playwright_set_content", args: { tab_id: tabId, html: "<main>owned</main>" } },
    );
    assert.equal(executions, 1);
    assert.equal(result.isolatedAgentTab, false, `adopted user tab was falsely reported isolated: ${JSON.stringify(result)}`);
    assert.equal(result.ownershipType, "explicitly_adopted");
  } finally {
    chrome.scripting.executeScript = previousExecute;
    await chrome.storage.local.remove("prstudioTabRegistry");
  }
});

test("network wait wildcard matching cannot block past its bounded deadline", async () => {
  const tabId = 82;
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: 55, url: "https://example.test/wait", title: "Adopted wait tab" }
    : Promise.reject(new Error("tab_missing"));
  await chrome.storage.local.set({
    prstudioTabRegistry: {
      [String(tabId)]: {
        tabId, windowId: 55, originalWindowId: 55, taskId: "wildcard-wait-task", laneId: "lane-w",
        expectedOrigin: "https://example.test", url: "https://example.test/wait", title: "Adopted wait tab",
        owner: "prstudio-agent", adoptedExternal: true, ownershipNonce: "wildcard-wait-nonce",
        createdAt: Date.now() - 1000, updatedAt: Date.now(), affinityReason: "explicit_user_tab_adoption",
      },
    },
  });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async () => ({});
  await chrome.debugger.onEvent.emit(
    { tabId },
    "Network.requestWillBeSent",
    { requestId: "redos-wait", request: { url: "a".repeat(28), method: "GET" } },
  );
  const pattern = `${"*a".repeat(14)}*b`;
  const started = performance.now();
  const outcome = await __test.executeKnownContractAction(
    { tabId, taskId: "wildcard-wait-task", arguments: { _prstudio_lane_id: "lane-w" } },
    { action: "playwright_wait_for_request", args: { tab_id: tabId, pattern, timeout: 250 } },
  ).then(
    () => ({ kind: "resolved" }),
    (error) => ({ kind: "rejected", code: error?.code || "" }),
  );
  const elapsedMs = performance.now() - started;
  assert.deepEqual(outcome, { kind: "rejected", code: "network_wait_timeout" });
  assert.ok(elapsedMs < 700, `wildcard matching blocked the event loop for ${elapsedMs.toFixed(1)}ms`);
  await chrome.storage.local.remove("prstudioTabRegistry");
});

test("screenshot capture path never requests a sub-operation timeout above 10 seconds", async () => {
  const tabId = 83;
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId: 55, url: "https://example.test/screenshot", title: "Screenshot adopted tab" }
    : Promise.reject(new Error("tab_missing"));
  chrome.tabs.query = async () => [{ id: tabId, windowId: 55, active: true, url: "https://example.test/screenshot" }];
  await chrome.storage.local.set({ prstudioTabRegistry: { [String(tabId)]: {
    tabId, windowId: 55, originalWindowId: 55, taskId: "screenshot-budget-task", laneId: "lane-shot",
    expectedOrigin: "https://example.test", url: "https://example.test/screenshot", owner: "prstudio-agent",
    adoptedExternal: true, ownershipNonce: "screenshot-budget-nonce", createdAt: Date.now() - 1000, updatedAt: Date.now(),
  } } });
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => {
    if (method === "Page.getLayoutMetrics") return { cssVisualViewport: { clientWidth: 1200, clientHeight: 800 }, cssContentSize: { width: 1200, height: 800 } };
    if (method === "Page.captureScreenshot") return new Promise(() => {});
    return {};
  };
  const previousCapture = chrome.tabs.captureVisibleTab;
  const previousSetTimeout = globalThis.setTimeout;
  const requestedDelays = [];
  chrome.tabs.captureVisibleTab = () => new Promise(() => {});
  globalThis.setTimeout = (fn, ms, ...args) => {
    requestedDelays.push(Number(ms || 0));
    return previousSetTimeout(fn, Math.min(25, Number(ms || 0)), ...args);
  };
  try {
    const result = await __test.captureScreenshot(tabId, false, false).then(
      () => ({ kind: "resolved" }),
      (error) => ({ kind: "rejected", code: error?.code || "" }),
    );
    assert.equal(result.kind, "rejected");
    assert.ok(Math.max(...requestedDelays) <= 10_000, `screenshot requested excessive timeout(s): ${JSON.stringify(requestedDelays)}`);
  } finally {
    globalThis.setTimeout = previousSetTimeout;
    chrome.tabs.captureVisibleTab = previousCapture;
    await chrome.storage.local.remove("prstudioTabRegistry");
  }
});

test("task runtime cleanup propagates Fetch.disable failure instead of reporting success", async () => {
  const tabId = 991;
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async (_target, method) => {
    if (method === "Fetch.disable") throw Object.assign(new Error("injected cleanup Fetch.disable failure"), { code: "INJECTED_FETCH_DISABLE" });
    return {};
  };
  chrome.debugger.detach = async () => {};
  await assert.rejects(
    () => __test.cleanupTaskRuntime({ tabId }),
    (error) => /injected cleanup Fetch\.disable failure/.test(String(error?.message || error)),
  );
});

test("task runtime cleanup propagates debugger detach failure instead of reporting success", async () => {
  const tabId = 992;
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.sendCommand = async () => ({});
  chrome.debugger.detach = async () => { throw Object.assign(new Error("injected cleanup detach failure"), { code: "INJECTED_DETACH" }); };
  await assert.rejects(
    () => __test.cleanupTaskRuntime({ tabId }),
    (error) => /injected cleanup detach failure/.test(String(error?.message || error)),
  );
});

test("browser launch selects an existing normal Chrome window and never creates a dedicated one", async () => {
  const windowId = 177;
  const previous = { getAll: chrome.windows.getAll, getWindow: chrome.windows.get, createWindow: chrome.windows.create };
  await chrome.storage.local.remove(["prstudioAgentWindow", "prstudioTabRegistry"]);
  let createCalls = 0;
  chrome.windows.getAll = async () => [{ id: windowId, type: "normal", focused: true, tabs: [{ id: 1771, windowId, url: "https://chatgpt.com/" }] }];
  chrome.windows.get = async (id) => Number(id) === windowId ? { id: windowId, type: "normal" } : Promise.reject(new Error("window_missing"));
  chrome.windows.create = async () => { createCalls += 1; throw new Error("dedicated window forbidden"); };
  try {
    assert.equal(await __test.ensureAgentWindow(), windowId);
    assert.equal(createCalls, 0);
    const stored = (await chrome.storage.local.get("prstudioAgentWindow")).prstudioAgentWindow || {};
    assert.equal(stored.windowId, windowId);
    assert.equal(stored.mode, "existing_normal_window");
  } finally {
    chrome.windows.getAll = previous.getAll; chrome.windows.get = previous.getWindow; chrome.windows.create = previous.createWindow;
    await chrome.storage.local.remove(["prstudioAgentWindow", "prstudioTabRegistry"]);
  }
});

test("close_browser closes Agent-created tabs but never the user's Chrome window", async () => {
  const windowId = 188, tabId = 1881;
  const previous = { getTab: chrome.tabs.get, removeTab: chrome.tabs.remove, removeWindow: chrome.windows.remove };
  await chrome.storage.local.remove(["prstudioAgentWindow", "prstudioTabRegistry"]);
  chrome.tabs.get = async (id) => Number(id) === tabId ? { id: tabId, windowId, url: "https://example.test/", title: "Agent tab" } : Promise.reject(new Error("tab_missing"));
  await __test.registerOwnedTab(tabId, { taskId: "close-browser", expectedOrigin: "https://example.test", url: "https://example.test/" });
  let windowRemoveCalls = 0;
  chrome.windows.remove = async () => { windowRemoveCalls += 1; throw new Error("user window must not be removed"); };
  chrome.tabs.remove = async (id) => { if (Number(id) === tabId) throw Object.assign(new Error("injected agent tab close failure"), { code: "INJECTED_TAB_CLOSE" }); };
  try {
    await assert.rejects(
      () => __test.executeKnownContractAction({}, { action: "playwright_close_browser", args: {} }),
      (error) => /injected agent tab close failure/.test(String(error?.message || error)),
    );
    assert.equal(windowRemoveCalls, 0);
  } finally {
    chrome.tabs.get = previous.getTab; chrome.tabs.remove = previous.removeTab; chrome.windows.remove = previous.removeWindow;
    await chrome.storage.local.remove(["prstudioAgentWindow", "prstudioTabRegistry"]);
  }
});


test("service-worker restart reconciliation returns a technical failure when debugger detach fails", async () => {
  const tabId = 913;
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.detach = async () => {
    throw Object.assign(new Error("injected restart detach failure"), { code: "INJECTED_RESTART_DETACH" });
  };
  await chrome.storage.local.set({
    prstudioRuntimeSessions: {
      rt_restart_detach: {
        id: "rt_restart_detach", tabId, type: "trace",
        expiresAt: Date.now() + 60_000, interruptedByWorkerRestart: false, interruptedByTabReplacement: false,
      },
    },
  });
  await assert.rejects(
    () => __test.reconcileRuntimeSessionsAfterRestart(),
    (error) => error?.code === "INJECTED_RESTART_DETACH" || /injected restart detach failure/.test(String(error?.message || error)),
  );
});


test("lane release returns a technical failure and preserves ownership when agent tab close fails", async () => {
  const tabId = 201;
  await chrome.storage.local.set({
    prstudioTabRegistry: {
      [String(tabId)]: { tabId, windowId: 9, laneId: "lane-close-fail", owner: "prstudio-agent", adoptedExternal: false },
    },
  });
  chrome.tabs.remove = async () => {
    throw Object.assign(new Error("injected tab close failure"), { code: "INJECTED_TAB_CLOSE" });
  };
  await assert.rejects(
    () => __test.releaseLaneTabs("lane-close-fail"),
    (error) => error?.code === "INJECTED_TAB_CLOSE" || /injected tab close failure/.test(String(error?.message || error)),
  );
  const stored = await chrome.storage.local.get("prstudioTabRegistry");
  assert.equal(stored.prstudioTabRegistry?.[String(tabId)]?.laneId, "lane-close-fail");
  chrome.tabs.remove = noop;
});


test("polling stop interrupts error backoff instead of waiting behind sleep", async () => {
  const previousFetch = globalThis.fetch;
  const previousBadgeText = chrome.action.setBadgeText;
  const previousBadgeColor = chrome.action.setBadgeBackgroundColor;
  let errorBadgeResolve;
  const errorBadgeSeen = new Promise((resolve) => { errorBadgeResolve = resolve; });
  await chrome.storage.local.remove(["prstudioEmergencyStop", "prstudioActiveTask", "prstudioLocalActiveExecution"]);
  await chrome.storage.local.set({
    prstudioConfig: { apiBase: "https://example.test/wp-json/prstudio/v1", siteUrl: "https://example.test", token: "test-token", authExpired: false },
  });
  chrome.action.setBadgeText = async ({ text }) => { if (text === "ERR") errorBadgeResolve(); };
  chrome.action.setBadgeBackgroundColor = noop;
  globalThis.fetch = async (url) => {
    const href = String(url);
    if (href.includes("/device/heartbeat")) return {
      ok: true, status: 200, redirected: false, url: href, headers: { get: () => null }, json: async () => ({ ok: true }),
    };
    return {
      ok: false, status: 503, redirected: false, url: href, headers: { get: () => null }, json: async () => ({ message: "temporary polling failure" }),
    };
  };
  const loop = __test.startPolling();
  try {
    await Promise.race([errorBadgeSeen, after(800, "outer_guard")]).then((value) => {
      assert.notEqual(value, "outer_guard", "poll loop never reached error backoff");
    });
    const started = Date.now();
    const result = await __test.stopPolling("test_backoff_stop", 150);
    const elapsed = Date.now() - started;
    assert.equal(result.stopped, true);
    assert.ok(elapsed < 150, `poll stop waited ${elapsed}ms behind error backoff`);
    await loop;
  } finally {
    globalThis.fetch = previousFetch;
    chrome.action.setBadgeText = previousBadgeText;
    chrome.action.setBadgeBackgroundColor = previousBadgeColor;
    await chrome.storage.local.remove("prstudioConfig");
    await __test.stopPolling("test_cleanup", 1000).catch(() => {});
  }
});


test("forced runtime cleanup preserves retry evidence when debugger state cannot be read", async () => {
  const tabId = 914;
  const previousGetTargets = chrome.debugger.getTargets;
  await chrome.storage.local.set({
    prstudioRuntimeSessions: {
      rt_force_trace: { id: "rt_force_trace", tabId, type: "trace", expiresAt: Date.now() + 60_000, interruptedByWorkerRestart: false },
    },
  });
  chrome.debugger.getTargets = async () => {
    throw Object.assign(new Error("injected debugger target read failure"), { code: "INJECTED_TARGET_READ" });
  };
  try {
    await assert.rejects(
      () => __test.cleanupTaskRuntime({ tabId }, { force: true }),
      (error) => /injected debugger target read failure/.test(String(error?.message || error)) || error?.code === "INJECTED_TARGET_READ",
    );
    const stored = await chrome.storage.local.get("prstudioRuntimeSessions");
    assert.equal(stored.prstudioRuntimeSessions?.rt_force_trace?.tabId, tabId, "force cleanup erased retry evidence after debugger state failure");
  } finally {
    chrome.debugger.getTargets = previousGetTargets;
  }
});


test("local debug capture cleanup returns a technical failure when debugger detach fails", async () => {
  const tabId = 915;
  const previous = { query: chrome.tabs.query, getTargets: chrome.debugger.getTargets, attach: chrome.debugger.attach, detach: chrome.debugger.detach, sendCommand: chrome.debugger.sendCommand };
  let attached = false;
  await chrome.storage.local.remove(["prstudioEmergencyStop", "prstudioActiveTask", "prstudioLocalActiveExecution", "prstudioAgentWindow"]);
  await chrome.storage.local.set({ prstudioTabRegistry: {} });
  chrome.tabs.query = async () => [{ id: tabId, windowId: 51, active: true, url: "https://local-debug.test/page", title: "Local debug" }];
  chrome.debugger.getTargets = async () => attached ? [{ tabId, attached: true }] : [];
  chrome.debugger.attach = async () => { attached = true; };
  chrome.debugger.sendCommand = async (_target, method) => method === "Performance.getMetrics" ? { metrics: [] } : {};
  chrome.debugger.detach = async () => { throw Object.assign(new Error("injected local detach failure"), { code: "INJECTED_LOCAL_DETACH" }); };
  try {
    await assert.rejects(
      () => __test.localDebugCapture(false),
      (error) => error?.code === "INJECTED_LOCAL_DETACH" || /injected local detach failure/.test(String(error?.message || error)),
    );
  } finally {
    Object.assign(chrome.tabs, { query: previous.query });
    Object.assign(chrome.debugger, { getTargets: previous.getTargets, attach: previous.attach, detach: previous.detach, sendCommand: previous.sendCommand });
  }
});

test("local responsive matrix cleanup returns a technical failure when viewport restore fails", async () => {
  const tabId = 916;
  const previous = { query: chrome.tabs.query, getTargets: chrome.debugger.getTargets, attach: chrome.debugger.attach, detach: chrome.debugger.detach, sendCommand: chrome.debugger.sendCommand, executeScript: chrome.scripting.executeScript };
  let attached = false;
  await chrome.storage.local.remove(["prstudioEmergencyStop", "prstudioActiveTask", "prstudioLocalActiveExecution", "prstudioAgentWindow"]);
  await chrome.storage.local.set({ prstudioTabRegistry: {} });
  chrome.tabs.query = async () => [{ id: tabId, windowId: 52, active: true, url: "https://local-responsive.test/page", title: "Local responsive" }];
  chrome.debugger.getTargets = async () => attached ? [{ tabId, attached: true }] : [];
  chrome.debugger.attach = async () => { attached = true; };
  chrome.debugger.detach = async () => { attached = false; };
  chrome.debugger.sendCommand = async (_target, method) => {
    if (method === "Emulation.clearDeviceMetricsOverride") throw Object.assign(new Error("injected viewport restore failure"), { code: "INJECTED_VIEWPORT_RESTORE" });
    return {};
  };
  chrome.scripting.executeScript = async () => [{ result: { width: 375, height: 812, horizontalOverflow: false, horizontalOverflowElements: [] } }];
  try {
    await assert.rejects(
      () => __test.localResponsiveMatrix(),
      (error) => error?.code === "INJECTED_VIEWPORT_RESTORE" || /injected viewport restore failure/.test(String(error?.message || error)),
    );
  } finally {
    Object.assign(chrome.tabs, { query: previous.query });
    Object.assign(chrome.debugger, { getTargets: previous.getTargets, attach: previous.attach, detach: previous.detach, sendCommand: previous.sendCommand });
    chrome.scripting.executeScript = previous.executeScript;
  }
});

test("local site scan returns a technical failure when its worker tab cannot be removed", async () => {
  const activeTab = { id: 1001, windowId: 61, active: true, status: "complete", url: "https://site.test/root", title: "Root" };
  const workerTab = { id: 1002, windowId: 61, active: false, status: "complete", url: "https://site.test/other", title: "Other" };
  const previous = { query: chrome.tabs.query, create: chrome.tabs.create, get: chrome.tabs.get, remove: chrome.tabs.remove, executeScript: chrome.scripting.executeScript };
  await chrome.storage.local.remove(["prstudioEmergencyStop", "prstudioActiveTask", "prstudioLocalActiveExecution", "prstudioAgentWindow"]);
  await chrome.storage.local.set({ prstudioTabRegistry: {} });
  chrome.tabs.query = async () => [activeTab];
  chrome.tabs.create = async () => workerTab;
  chrome.tabs.get = async (id) => Number(id) === workerTab.id ? workerTab : activeTab;
  chrome.scripting.executeScript = async (options) => {
    if (options?.func?.name === "collectLocalSemanticSnapshot") return [{ result: {
      url: activeTab.url, title: activeTab.title, text: "", headings: [], controls: [], landmarks: [], counts: {},
      links: [{ href: workerTab.url }],
    } }];
    return [{ result: {
      url: Number(options?.target?.tabId) === workerTab.id ? workerTab.url : activeTab.url,
      title: "ok", description: "ok", canonical: "ok", viewport: "ok", h1Count: 1,
      imagesMissingAlt: 0, unlabeledControls: 0, duplicateIdCount: 0, schemaParseErrors: 0,
      mixedContentCount: 0, badLinkCount: 0,
    } }];
  };
  chrome.tabs.remove = async (id) => {
    if (Number(id) === workerTab.id) throw Object.assign(new Error("injected site scan close failure"), { code: "INJECTED_SITE_SCAN_CLOSE" });
  };
  try {
    await assert.rejects(
      () => __test.localSiteScan(2),
      (error) => error?.code === "INJECTED_SITE_SCAN_CLOSE" || /injected site scan close failure/.test(String(error?.message || error)),
    );
  } finally {
    Object.assign(chrome.tabs, { query: previous.query, create: previous.create, get: previous.get, remove: previous.remove });
    chrome.scripting.executeScript = previous.executeScript;
    await chrome.storage.local.remove(["prstudioLocalActiveExecution"]);
  }
});

test("scheduled local check returns a technical failure on worker window cleanup and still releases local lane", async () => {
  const workerWindowId = 71;
  const workerTab = { id: 1101, windowId: workerWindowId, status: "complete", url: "https://schedule.test/", title: "Scheduled" };
  const previous = { getAll: chrome.windows.getAll, createWindow: chrome.windows.create, removeWindow: chrome.windows.remove, getTab: chrome.tabs.get, query: chrome.tabs.query, executeScript: chrome.scripting.executeScript };
  await chrome.storage.local.remove(["prstudioEmergencyStop", "prstudioActiveTask", "prstudioLocalActiveExecution", "prstudioAgentWindow"]);
  await chrome.storage.local.set({
    prstudioTabRegistry: {},
    prstudioLocalSchedules: [{ id: "sched-close", enabled: true, url: workerTab.url, name: "Scheduled", minutes: 60 }],
    prstudioLocalScheduledResults: [],
  });
  chrome.windows.getAll = async () => [];
  chrome.windows.create = async () => ({ id: workerWindowId, tabs: [workerTab] });
  chrome.windows.remove = async () => { throw Object.assign(new Error("injected scheduled window close failure"), { code: "INJECTED_SCHEDULE_WINDOW_CLOSE" }); };
  chrome.tabs.get = async () => workerTab;
  chrome.tabs.query = async () => [workerTab];
  chrome.scripting.executeScript = async () => [{ result: {
    url: workerTab.url, title: "ok", description: "ok", canonical: "ok", viewport: "ok", h1Count: 1,
    imagesMissingAlt: 0, unlabeledControls: 0, duplicateIdCount: 0, schemaParseErrors: 0,
    mixedContentCount: 0, badLinkCount: 0,
  } }];
  try {
    await assert.rejects(
      () => __test.runLocalScheduledCheck("sched-close"),
      (error) => error?.code === "INJECTED_SCHEDULE_WINDOW_CLOSE" || /injected scheduled window close failure/.test(String(error?.message || error)),
    );
    const active = (await chrome.storage.local.get("prstudioLocalActiveExecution")).prstudioLocalActiveExecution;
    assert.equal(active, undefined, "scheduled cleanup failure left the local lane locked");
  } finally {
    Object.assign(chrome.windows, { getAll: previous.getAll, create: previous.createWindow, remove: previous.removeWindow });
    Object.assign(chrome.tabs, { get: previous.getTab, query: previous.query });
    chrome.scripting.executeScript = previous.executeScript;
    await chrome.storage.local.remove(["prstudioLocalSchedules", "prstudioLocalScheduledResults", "prstudioLocalActiveExecution"]);
  }
});

test("crawler returns a technical failure and preserves tab ownership when worker close fails", async () => {
  const windowId = 80, tabId = 1201;
  const workerTab = { id: tabId, windowId, status: "complete", url: "https://crawl.test/", title: "Crawler" };
  const previous = { getWindow: chrome.windows.get, createTab: chrome.tabs.create, getTab: chrome.tabs.get, removeTab: chrome.tabs.remove, executeScript: chrome.scripting.executeScript };
  await chrome.storage.local.set({ prstudioAgentWindow: { windowId, mode: "existing_normal_window" }, prstudioTabRegistry: {} });
  chrome.windows.get = async (id) => {
    if (Number(id) !== windowId) throw new Error("window_missing");
    return { id: windowId, type: "normal", tabs: [] };
  };
  chrome.tabs.create = async () => workerTab;
  chrome.tabs.get = async (id) => Number(id) === tabId ? workerTab : Promise.reject(new Error("tab_missing"));
  chrome.tabs.remove = async (id) => {
    if (Number(id) === tabId) throw Object.assign(new Error("injected crawler close failure"), { code: "INJECTED_CRAWLER_CLOSE" });
  };
  chrome.scripting.executeScript = async (options) => options?.func?.name === "gateDetector" ? [{ result: null }] : [{ result: null }];
  try {
    await assert.rejects(
      () => __test.crawlWorkerPage({ taskId: "crawl-cleanup" }, workerTab.url, 0),
      (error) => error?.code === "INJECTED_CRAWLER_CLOSE" || /injected crawler close failure/.test(String(error?.message || error)),
    );
    const registry = (await chrome.storage.local.get("prstudioTabRegistry")).prstudioTabRegistry || {};
    assert.equal(registry[String(tabId)]?.owner, "prstudio-agent", "crawler erased ownership after failed tab close");
  } finally {
    Object.assign(chrome.windows, { get: previous.getWindow });
    Object.assign(chrome.tabs, { create: previous.createTab, get: previous.getTab, remove: previous.removeTab });
    chrome.scripting.executeScript = previous.executeScript;
    await chrome.storage.local.remove(["prstudioAgentWindow", "prstudioTabRegistry"]);
  }
});

test("restart recovery terminalizes uncertain mutation without human takeover even if debugger detach would fail", async () => {
  const tabId = 1301, windowId = 91, sentinelId = 1391, nonce = "recover-nonce";
  const sentinelUrl = `${chrome.runtime.getURL("agent.html")}#${nonce}`;
  const previous = { getTargets: chrome.debugger.getTargets, detach: chrome.debugger.detach, executeScript: chrome.scripting.executeScript, getTab: chrome.tabs.get, getWindow: chrome.windows.get };
  await chrome.storage.local.remove(["prstudioPendingTakeovers", "prstudioActiveTask", "prstudioTakeoverCleanupQueue"]);
  await chrome.storage.local.set({
    prstudioAgentWindow: { windowId, nonce, sentinelTabId: sentinelId },
    prstudioTabRegistry: { [String(tabId)]: { tabId, windowId, owner: "prstudio-agent", taskId: "recover-mutating", ownershipNonce: "owned-recover", url: "https://recover.test/" } },
    prstudioActiveTask: {
      taskId: "recover-mutating", tabId, phase: "in_flight", leaseToken: null, stepIndex: 4,
      inFlight: { stepIndex: 4, type: "click", action: "click", mutating: true, attemptId: "recover-mutating:4:test", startedAt: Date.now() - 1000 },
    },
  });
  chrome.tabs.get = async (id) => Number(id) === tabId
    ? { id: tabId, windowId, status: "complete", url: "https://recover.test/", title: "Recover" }
    : Number(id) === sentinelId ? { id: sentinelId, windowId, url: sentinelUrl, title: "Agent" } : Promise.reject(new Error("tab_missing"));
  chrome.windows.get = async (id) => Number(id) === windowId
    ? { id: windowId, tabs: [{ id: sentinelId, windowId, url: sentinelUrl }, { id: tabId, windowId, url: "https://recover.test/" }] }
    : Promise.reject(new Error("window_missing"));
  chrome.debugger.getTargets = async () => [{ tabId, attached: true }];
  chrome.debugger.detach = async () => { throw Object.assign(new Error("injected recovery detach failure"), { code: "INJECTED_RECOVERY_DETACH" }); };
  chrome.scripting.executeScript = async () => [];
  try {
    const result = await __test.recoverSavedTask();
    assert.equal(result.terminalized, true);
    assert.equal(result.antiCrash, true);
    assert.equal((await __test.getActiveTask()), null, "technical recovery left active lane occupied");
  } finally {
    Object.assign(chrome.debugger, { getTargets: previous.getTargets, detach: previous.detach });
    chrome.scripting.executeScript = previous.executeScript;
    chrome.tabs.get = previous.getTab; chrome.windows.get = previous.getWindow;
    await chrome.storage.local.remove(["prstudioPendingTakeovers", "prstudioActiveTask", "prstudioTakeoverCleanupQueue", "prstudioTabRegistry", "prstudioAgentWindow"]);
  }
});

test("local debugger attach reports cleanup failure when ambiguous attach leaves debugger attached", async () => {
  const tabId = 1302;
  const previous = { getTargets: chrome.debugger.getTargets, attach: chrome.debugger.attach, detach: chrome.debugger.detach };
  let attached = false;
  chrome.debugger.getTargets = async () => attached ? [{ tabId, attached: true }] : [];
  chrome.debugger.attach = async () => {
    attached = true;
    throw Object.assign(new Error("injected ambiguous attach failure"), { code: "INJECTED_ATTACH_FAILURE" });
  };
  chrome.debugger.detach = async () => { throw Object.assign(new Error("injected attach cleanup detach failure"), { code: "INJECTED_ATTACH_CLEANUP" }); };
  try {
    await assert.rejects(
      () => __test.localDebuggerAttach(tabId),
      (error) => error?.code === "LOCAL_DEBUGGER_ATTACH_CLEANUP_FAILED" && error?.details?.cleanup?.code === "INJECTED_ATTACH_CLEANUP",
    );
  } finally {
    Object.assign(chrome.debugger, { getTargets: previous.getTargets, attach: previous.attach, detach: previous.detach });
  }
});

test("transient saved-task lookup failure never parks the Browser Agent", async () => {
  const previousFetch = globalThis.fetch;
  await chrome.storage.local.set({
    prstudioConfig: { apiBase: "https://control.example.test/wp-json/prstudio/v1", token: "test-token", authExpired: false },
    prstudioActiveTask: {
      taskId: "recover-transient-lookup", tabId: null, phase: "pending", leaseToken: "lease-token", stepIndex: 0,
      action: "agent_status", arguments: {}, checkpoint: {}, inFlight: null,
    },
  });
  globalThis.fetch = async () => new Response(JSON.stringify({ message: "temporary upstream failure" }), { status: 503, headers: { "content-type": "application/json" } });
  try {
    const result = await __test.recoverSavedTask();
    assert.equal(result.retryable, true);
    assert.equal(result.action, "lookup_retry_deferred");
    assert.equal((await __test.getActiveTask())?.taskId, "recover-transient-lookup", "durable task state was discarded instead of retained for retry");
  } finally {
    globalThis.fetch = previousFetch;
    await chrome.storage.local.remove(["prstudioConfig", "prstudioActiveTask"]);
  }
});
