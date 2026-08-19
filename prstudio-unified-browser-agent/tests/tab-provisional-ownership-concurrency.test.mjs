import assert from "node:assert/strict";
import test from "node:test";

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
      for (const key of list) if (store.has(key)) out[key] = structuredClone(store.get(key));
      return out;
    },
    async set(value) { for (const [key, item] of Object.entries(value || {})) store.set(key, structuredClone(item)); },
    async remove(keys) { for (const key of (Array.isArray(keys) ? keys : [keys])) store.delete(key); },
  };
}

const local = createStorageArea();
const session = createStorageArea();
const noop = async () => undefined;
const tabs = new Map();
const tabEvents = {
  onCreated: eventApi(),
  onReplaced: eventApi(),
  onRemoved: eventApi(),
  onActivated: eventApi(),
  onUpdated: eventApi(),
};

globalThis.chrome = {
  runtime: {
    onInstalled: eventApi(), onStartup: eventApi(), onMessage: eventApi(), onConnect: eventApi(),
    getURL: (path) => `chrome-extension://test/${path}`,
  },
  alarms: { onAlarm: eventApi(), create: noop, clear: async () => true, get: async () => null },
  tabs: {
    ...tabEvents,
    query: async () => [...tabs.values()].map((tab) => structuredClone(tab)),
    get: async (id) => {
      const tab = tabs.get(Number(id));
      if (!tab) throw new Error("tab_missing");
      return structuredClone(tab);
    },
    update: async (id, patch = {}) => {
      const current = tabs.get(Number(id));
      if (!current) throw new Error("tab_missing");
      const next = { ...current, ...patch };
      tabs.set(Number(id), next);
      return structuredClone(next);
    },
    create: async ({ windowId = 7, url = "about:blank", active = false } = {}) => {
      const id = Math.max(300, ...tabs.keys()) + 1;
      const tab = { id, windowId, url, pendingUrl: "", active, status: "loading", title: "" };
      tabs.set(id, tab);
      await tabEvents.onCreated.emit(structuredClone(tab));
      return structuredClone(tab);
    },
    remove: async (id) => { tabs.delete(Number(id)); },
    captureVisibleTab: async () => "",
  },
  windows: {
    onRemoved: eventApi(), onFocusChanged: eventApi(), WINDOW_ID_NONE: -1,
    getAll: async () => [{ id: 7, tabs: [...tabs.values()].map((tab) => structuredClone(tab)) }],
    get: async (id) => ({ id: Number(id), tabs: [...tabs.values()].filter((tab) => Number(tab.windowId) === Number(id)).map((tab) => structuredClone(tab)) }),
    create: async () => ({ id: 7, tabs: [] }), update: noop, remove: noop,
  },
  debugger: { onDetach: eventApi(), onEvent: eventApi(), getTargets: async () => [], attach: noop, detach: noop, sendCommand: noop },
  storage: { local, session },
  action: { setBadgeText: noop, setBadgeBackgroundColor: noop },
  scripting: { executeScript: async () => [] },
  notifications: { create: noop },
};

const { __test } = await import(`../service-worker.js?tab-provisional-concurrency=${Date.now()}`);

function registrySnapshot() {
  return chrome.storage.local.get("prstudioTabRegistry").then((value) => value.prstudioTabRegistry || {});
}

test("provisional ownership survives navigation/storage event interleaving and never adopts an unowned tab", async () => {
  const tabId = 341;
  const target = "https://shop.example/checkout";
  tabs.set(tabId, {
    id: tabId,
    windowId: 7,
    url: "about:blank",
    pendingUrl: target,
    status: "loading",
    title: "",
  });

  const provisional = await __test.registerOwnedTab(tabId, {
    windowId: 7,
    taskId: "task-create",
    laneId: "lane-a",
    expectedOrigin: "https://shop.example",
    url: target,
    provisionalClaim: true,
  });
  assert.equal(provisional.tabId, tabId);
  assert.equal(provisional.url, target);
  assert.equal(provisional.provisional, true);
  assert.ok(provisional.ownershipNonce);

  const beforeNavigation = await __test.assertOwnedTab(tabId);
  assert.equal(beforeNavigation.url, target);
  assert.equal(beforeNavigation.ownershipNonce, provisional.ownershipNonce);

  // Simulate a concurrent storage writer touching affinity telemetry after the
  // provisional claim but before Chrome commits the target navigation.
  const mutatedRegistry = await registrySnapshot();
  mutatedRegistry[String(tabId)] = {
    ...mutatedRegistry[String(tabId)],
    affinityReason: "concurrent_storage_event",
    updatedAt: Date.now() + 1,
  };
  await chrome.storage.local.set({ prstudioTabRegistry: mutatedRegistry });

  // Chrome commits the navigation and emits onUpdated. The worker listener must
  // update the already-owned record, never manufacture ownership from the URL.
  const committed = {
    ...tabs.get(tabId),
    url: target,
    pendingUrl: "",
    status: "loading",
    title: "Checkout",
  };
  tabs.set(tabId, committed);
  await chrome.tabs.onUpdated.emit(tabId, { url: target, status: "loading" }, structuredClone(committed));

  const bound = await __test.assertOwnedTab(tabId);
  assert.equal(bound.provisional, false);
  assert.equal(bound.url, target);
  assert.equal(bound.laneId, "lane-a");
  assert.equal(bound.taskId, "task-create");
  assert.equal(bound.ownershipNonce, provisional.ownershipNonce);
  assert.equal(bound.affinityReason, "concurrent_storage_event");

  const persisted = await registrySnapshot();
  assert.equal(persisted[String(tabId)].ownershipNonce, provisional.ownershipNonce);
  assert.equal(persisted[String(tabId)].provisional, false);

  const foreignTabId = 342;
  tabs.set(foreignTabId, {
    id: foreignTabId,
    windowId: 7,
    url: target,
    pendingUrl: "",
    status: "complete",
    title: "Same URL but user-owned",
  });
  await chrome.tabs.onUpdated.emit(foreignTabId, { url: target, status: "complete" }, structuredClone(tabs.get(foreignTabId)));
  await assert.rejects(
    () => __test.assertOwnedTab(foreignTabId),
    (error) => error?.code === "technical_tab_not_controlled",
  );
  assert.equal((await registrySnapshot())[String(foreignTabId)], undefined);
});
