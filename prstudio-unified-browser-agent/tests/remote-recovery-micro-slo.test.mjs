import assert from "node:assert/strict";
import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname } from "node:path";
import { performance } from "node:perf_hooks";
import test from "node:test";

function eventApi() {
  const listeners = new Set();
  return {
    addListener(listener) { listeners.add(listener); },
    removeListener(listener) { listeners.delete(listener); },
    async emit(...args) { for (const listener of [...listeners]) await listener(...args); },
  };
}

function createMemoryStorageArea() {
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

function createFileStorageArea(path) {
  let queue = Promise.resolve();
  const transact = (fn) => {
    const operation = queue.then(fn, fn);
    queue = operation.catch(() => {});
    return operation;
  };
  async function readAll() {
    try { return JSON.parse(await readFile(path, "utf8")); }
    catch (error) { if (error?.code === "ENOENT") return {}; throw error; }
  }
  async function writeAll(value) {
    await mkdir(dirname(path), { recursive: true });
    await writeFile(path, `${JSON.stringify(value)}\n`, "utf8");
  }
  return {
    async get(keys) {
      return transact(async () => {
        const store = await readAll();
        if (keys == null) return store;
        const out = {};
        const list = Array.isArray(keys) ? keys : typeof keys === "string" ? [keys] : Object.keys(keys || {});
        for (const key of list) if (Object.prototype.hasOwnProperty.call(store, key)) out[key] = store[key];
        return out;
      });
    },
    async set(value) {
      return transact(async () => {
        const store = await readAll();
        Object.assign(store, value || {});
        await writeAll(store);
      });
    },
    async remove(keys) {
      return transact(async () => {
        const store = await readAll();
        for (const key of (Array.isArray(keys) ? keys : [keys])) delete store[key];
        await writeAll(store);
      });
    },
  };
}

const storagePath = "/mnt/data/handoff8-audit/evidence/remote-recovery-micro-storage.json";
const evidencePath = "/mnt/data/handoff8-audit/evidence/remote-recovery-micro-slo.json";
const local = createFileStorageArea(storagePath);
const session = createMemoryStorageArea();
const noop = async () => undefined;
const tabId = 77;
const agentWindowId = 7;
const sentinelId = 700;
let debuggerAttached = false;
const debuggerCalls = [];

const sentinelUrl = `chrome-extension://test/agent.html#micro-nonce`;
const ownedTab = { id: tabId, windowId: agentWindowId, url: "https://example.test/", title: "Micro SLO" };
const sentinelTab = { id: sentinelId, windowId: agentWindowId, url: sentinelUrl, title: "Agent" };

globalThis.chrome = {
  runtime: { onInstalled: eventApi(), onStartup: eventApi(), onMessage: eventApi(), onConnect: eventApi(), getURL: (path) => `chrome-extension://test/${path}` },
  alarms: { onAlarm: eventApi(), create: noop, clear: async () => true, get: async () => null },
  tabs: {
    onCreated: eventApi(), onReplaced: eventApi(), onRemoved: eventApi(), onActivated: eventApi(), onUpdated: eventApi(),
    query: async () => [sentinelTab, ownedTab],
    get: async (id) => Number(id) === tabId ? ownedTab : Number(id) === sentinelId ? sentinelTab : Promise.reject(new Error("tab_missing")),
    update: noop, create: async () => ownedTab, remove: noop, captureVisibleTab: async () => "",
  },
  windows: {
    onRemoved: eventApi(), onFocusChanged: eventApi(),
    getAll: async () => [{ id: agentWindowId, tabs: [sentinelTab, ownedTab] }],
    get: async (id) => Number(id) === agentWindowId ? { id: agentWindowId, tabs: [sentinelTab, ownedTab] } : Promise.reject(new Error("window_missing")),
    create: async () => ({ id: agentWindowId, tabs: [sentinelTab] }), update: noop, remove: noop, WINDOW_ID_NONE: -1,
  },
  debugger: {
    onDetach: eventApi(), onEvent: eventApi(),
    getTargets: async () => debuggerAttached ? [{ tabId, attached: true }] : [],
    attach: async () => { debuggerAttached = true; debuggerCalls.push("attach"); },
    detach: async () => { debuggerAttached = false; debuggerCalls.push("detach"); },
    sendCommand: async (_target, method) => { debuggerCalls.push(method); return {}; },
  },
  storage: { local, session },
  action: { setBadgeText: noop, setBadgeBackgroundColor: noop },
  scripting: { executeScript: async () => [] },
  notifications: { create: noop },
};

await local.set({
  prstudioAgentWindow: { windowId: agentWindowId, nonce: "micro-nonce", sentinelTabId: sentinelId },
  prstudioRuntimeSessions: {},
});

const first = await import(`../service-worker.js?remote-recovery-micro-first=${Date.now()}`);

await first.__test.registerOwnedTab(tabId, {
  taskId: "micro-task",
  expectedOrigin: "https://example.test",
  url: ownedTab.url,
});

test("remote recovery micro SLO 3s/10s/5s with durable restart readback", async () => {
  const tCall = performance.now();
  const actionPromise = first.__test.executeKnownContractAction(
    { tabId, taskId: "micro-task" },
    { action: "playwright_start_trace", args: { tab_id: tabId } },
  );
  await Promise.resolve();
  const tAck = performance.now();
  const result = await actionPromise;
  const tDone = performance.now();

  assert.equal(result.action, "playwright_start_trace");
  assert.equal(result.tracing, true);
  assert.ok(result.sessionId);

  const persistedBefore = (await local.get("prstudioRuntimeSessions")).prstudioRuntimeSessions || {};
  assert.ok(persistedBefore[result.sessionId], "runtime session was not durably persisted before restart boundary");

  const tRecoveryStart = performance.now();
  const fresh = await import(`../service-worker.js?remote-recovery-micro-fresh=${Date.now()}-${Math.random()}`);
  await new Promise((resolve) => setImmediate(resolve));
  let persistedAfter = (await local.get("prstudioRuntimeSessions")).prstudioRuntimeSessions || {};
  if (!persistedAfter[result.sessionId]?.interruptedByWorkerRestart) {
    await fresh.__test.reconcileRuntimeSessionsAfterRestart();
    persistedAfter = (await local.get("prstudioRuntimeSessions")).prstudioRuntimeSessions || {};
  }
  const tRecovered = performance.now();

  const callToAckMs = tAck - tCall;
  const ackToDoneMs = tDone - tAck;
  const recoveryMs = tRecovered - tRecoveryStart;
  const recovered = persistedAfter[result.sessionId];

  assert.equal(recovered?.interruptedByWorkerRestart, true, "fresh module did not recover persisted runtime-session state");
  assert.equal(recovered?.cleanupPending, false, "fresh module left runtime cleanup pending");
  assert.ok(callToAckMs <= 3000, `call->ACK ${callToAckMs} ms`);
  assert.ok(ackToDoneMs <= 10000, `ACK->done ${ackToDoneMs} ms`);
  assert.ok(recoveryMs <= 5000, `recovery ${recoveryMs} ms`);

  const evidence = {
    status: "PASS",
    action_id: "playwright_start_trace",
    task_id: "micro-task",
    terminal_status: "success",
    storage_key: "prstudioRuntimeSessions",
    session_id: result.sessionId,
    ack_definition: "local executor promise accepted and yielded one microtask; not a remote server ACK",
    clock: "performance.now() monotonic",
    t_call: tCall,
    t_ack: tAck,
    t_done: tDone,
    t_recovery_start: tRecoveryStart,
    t_recovered: tRecovered,
    call_to_ack_ms: callToAckMs,
    ack_to_done_ms: ackToDoneMs,
    recovery_ms: recoveryMs,
    limits_ms: { call_to_ack: 3000, ack_to_done: 10000, recovery: 5000 },
    durable_boundary: "file-backed chrome.storage.local mock plus fresh service-worker module instance readback/reconciliation",
    local_restart_boundary: "PASS",
    live_cross_session_acceptance: "LIVE_CROSS_SESSION_ACCEPTANCE_PENDING",
    debugger_calls: debuggerCalls,
  };
  await mkdir(dirname(evidencePath), { recursive: true });
  await writeFile(evidencePath, `${JSON.stringify(evidence, null, 2)}\n`, "utf8");
  console.log(`MICRO_SLO_EVIDENCE=${JSON.stringify(evidence)}`);
});
