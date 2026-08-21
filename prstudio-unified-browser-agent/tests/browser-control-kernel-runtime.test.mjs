import assert from "node:assert/strict";
import test from "node:test";

function createStorageArea() {
  const store = new Map();
  const api = {
    async get(keys) {
      if (keys == null) return Object.fromEntries([...store.entries()].map(([key, value]) => [key, structuredClone(value)]));
      const out = {};
      const list = Array.isArray(keys) ? keys : typeof keys === "string" ? [keys] : Object.keys(keys || {});
      for (const key of list) if (store.has(key)) out[key] = structuredClone(store.get(key));
      return out;
    },
    async set(values = {}) {
      for (const [key, value] of Object.entries(values)) store.set(key, structuredClone(value));
    },
    async remove(keys) {
      for (const key of (Array.isArray(keys) ? keys : [keys])) store.delete(key);
    },
  };
  return { api, store };
}

function fakeChrome() {
  const storage = createStorageArea();
  const tabs = new Map();
  const groups = new Map();
  let nextGroupId = 100;
  const debuggerTargets = new Map();

  const chromeApi = {
    storage: { local: storage.api },
    tabs: {
      async query(query = {}) {
        return [...tabs.values()]
          .filter((tab) => query.groupId === undefined || Number(tab.groupId) === Number(query.groupId))
          .map((tab) => structuredClone(tab));
      },
      async get(id) {
        const tab = tabs.get(Number(id));
        if (!tab) throw new Error("tab_missing");
        return structuredClone(tab);
      },
      async group(options = {}) {
        const ids = Array.isArray(options.tabIds) ? options.tabIds.map(Number) : [Number(options.tabIds)];
        let groupId = Number(options.groupId || 0);
        if (!groupId) {
          groupId = nextGroupId++;
          groups.set(groupId, {
            id: groupId,
            windowId: Number(options.createProperties?.windowId || tabs.get(ids[0])?.windowId || 1),
            title: "",
            color: "grey",
            collapsed: false,
          });
        }
        for (const id of ids) {
          const current = tabs.get(id);
          if (!current) throw new Error("tab_missing");
          tabs.set(id, { ...current, groupId });
        }
        return groupId;
      },
    },
    tabGroups: {
      async query(query = {}) {
        return [...groups.values()]
          .filter((group) => query.windowId === undefined || Number(group.windowId) === Number(query.windowId))
          .filter((group) => query.title === undefined || String(group.title) === String(query.title))
          .map((group) => structuredClone(group));
      },
      async update(id, patch = {}) {
        const current = groups.get(Number(id));
        if (!current) throw new Error("group_missing");
        const next = { ...current, ...patch };
        groups.set(Number(id), next);
        return structuredClone(next);
      },
    },
    debugger: {
      async getTargets() {
        return [...debuggerTargets.entries()].map(([tabId, attached]) => ({ tabId, attached, type: "page" }));
      },
    },
  };

  return { chromeApi, storage, tabs, groups, debuggerTargets };
}

test("storage compatibility layer turns controller ownership into Chrome topology", async () => {
  const env = fakeChrome();
  globalThis.chrome = env.chromeApi;
  const module = await import(`../lib/tab-ownership.js?runtime-v2=${Date.now()}`);
  assert.equal(module.BROWSER_CONTROL_KERNEL_VERSION, "2.0.0");
  assert.equal(chrome.storage.local.__prstudioBrowserControlKernelVersion, "2.0.0");

  env.tabs.set(11, {
    id: 11,
    windowId: 1,
    groupId: -1,
    url: "about:blank",
    pendingUrl: "https://search.google.com/search-console",
    title: "",
  });
  env.debuggerTargets.set(11, true);

  await chrome.storage.local.set({
    prstudioTabRegistry: {
      "11": {
        tabId: 11,
        laneId: "chat-a",
        controllerSessionId: "chat-a",
        expectedOrigin: "https://search.google.com",
        ownershipNonce: "nonce-11",
        createdAt: 1,
        updatedAt: 1,
      },
    },
  });

  const groupedTab = env.tabs.get(11);
  assert.ok(Number(groupedTab.groupId) > 0);
  const controlGroup = env.groups.get(groupedTab.groupId);
  assert.equal(controlGroup.title, "PR STUDIO Agent · chat-a");
  assert.equal(controlGroup.color, "green");

  const persisted = (await chrome.storage.local.get("prstudioTabRegistry")).prstudioTabRegistry;
  assert.equal(persisted["11"].controllerSessionId, "chat-a");
  assert.equal(persisted["11"].controlGroupId, groupedTab.groupId);
  assert.equal(persisted["11"].expectedOrigin, "");
  assert.equal(persisted["11"].debuggerAttached, true);
});

test("empty registry after worker restart is reconstructed from controlled Chrome groups", async () => {
  const env = fakeChrome();
  globalThis.chrome = env.chromeApi;
  const moduleUrl = new URL("../lib/tab-ownership.js", import.meta.url);
  await import(`${moduleUrl.href}?restart-v2=${Date.now()}`);

  env.groups.set(201, { id: 201, windowId: 1, title: "PR STUDIO Agent · chat-restart", color: "green", collapsed: false });
  env.tabs.set(202, { id: 202, windowId: 1, groupId: 201, url: "https://trends.google.com/", pendingUrl: "", title: "Trends" });
  env.debuggerTargets.set(202, true);

  // Bypass the patched API to model persisted registry loss between workers.
  env.storage.store.set("prstudioTabRegistry", {});
  const recovered = (await chrome.storage.local.get("prstudioTabRegistry")).prstudioTabRegistry;
  assert.equal(recovered["202"].controllerSessionId, "chat-restart");
  assert.equal(recovered["202"].controlGroupId, 201);
  assert.equal(recovered["202"].debuggerAttached, true);
  assert.match(recovered["202"].ownershipNonce, /^chrome-group:/);
});

test("popup inherits controller and is filed into its controller group while user tabs stay untouched", async () => {
  const env = fakeChrome();
  globalThis.chrome = env.chromeApi;
  const moduleUrl = new URL("../lib/tab-ownership.js", import.meta.url);
  await import(`${moduleUrl.href}?popup-v2=${Date.now()}`);

  env.groups.set(301, { id: 301, windowId: 1, title: "PR STUDIO Agent · chat-popup", color: "green", collapsed: false });
  env.tabs.set(302, { id: 302, windowId: 1, groupId: 301, url: "https://shop.example/", pendingUrl: "", title: "Shop" });
  env.tabs.set(303, { id: 303, windowId: 1, groupId: -1, openerTabId: 302, url: "https://pay.example/", pendingUrl: "", title: "Pay" });
  env.tabs.set(304, { id: 304, windowId: 1, groupId: -1, url: "https://mail.example/", pendingUrl: "", title: "User mail" });
  env.storage.store.set("prstudioTabRegistry", {});

  const recovered = (await chrome.storage.local.get("prstudioTabRegistry")).prstudioTabRegistry;
  assert.equal(recovered["302"].controllerSessionId, "chat-popup");
  assert.equal(recovered["303"].controllerSessionId, "chat-popup");
  assert.equal(recovered["304"], undefined);
  assert.equal(env.tabs.get(303).groupId, 301);
  assert.equal(env.tabs.get(304).groupId, -1);
});
