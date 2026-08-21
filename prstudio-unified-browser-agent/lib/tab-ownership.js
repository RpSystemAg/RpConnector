import {
  BROWSER_CONTROL_KERNEL_VERSION,
  controllerGroupTitle,
  normalizeControllerSessionId,
  reconcileControlState,
  sitePermissionDecision,
  controllerIsolationInvariant,
  kernelCertificationSnapshot,
} from "./browser-control-kernel.js";

const TAB_REGISTRY_STORAGE_KEY = "prstudioTabRegistry";
const CONTROLLER_GROUPS_STORAGE_KEY = "prstudioControllerGroups";
const TAB_REGISTRY_BASELINE = Symbol("prstudio.tabRegistryBaseline");
let tabRegistryWriteQueue = Promise.resolve();

function plainObject(value) {
  return value && typeof value === "object" && !Array.isArray(value);
}

function cloneValue(value) {
  if (value === undefined) return undefined;
  try { return JSON.parse(JSON.stringify(value)); }
  catch { return value; }
}

function sameValue(left, right) {
  try { return JSON.stringify(left) === JSON.stringify(right); }
  catch { return left === right; }
}

/**
 * Merge only fields changed by one read/modify/write participant onto the
 * newest stored record. This preserves unrelated ownership/affinity metadata
 * written after the participant took its snapshot.
 */
export function mergeTabRegistryRecordDelta(latest = {}, baseline = {}, desired = {}) {
  const out = plainObject(latest) ? cloneValue(latest) : {};
  const before = plainObject(baseline) ? baseline : {};
  const after = plainObject(desired) ? desired : {};
  const keys = new Set([...Object.keys(before), ...Object.keys(after)]);
  for (const key of keys) {
    const hadBefore = Object.prototype.hasOwnProperty.call(before, key);
    const hasAfter = Object.prototype.hasOwnProperty.call(after, key);
    if (hadBefore && !hasAfter) {
      delete out[key];
      continue;
    }
    if (!hasAfter) continue;
    if (!hadBefore || !sameValue(before[key], after[key])) {
      out[key] = cloneValue(after[key]);
    }
  }
  return out;
}

/**
 * Apply a stale registry writer as a delta instead of replacing the complete
 * registry. A newly-created owned tab must not disappear because another
 * concurrent event saves an older snapshot of a different tab.
 */
export function mergeTabRegistryDelta(latest = {}, baseline = {}, desired = {}) {
  const out = plainObject(latest) ? cloneValue(latest) : {};
  const before = plainObject(baseline) ? baseline : {};
  const after = plainObject(desired) ? desired : {};
  const keys = new Set([...Object.keys(before), ...Object.keys(after)]);

  for (const key of keys) {
    const hadBefore = Object.prototype.hasOwnProperty.call(before, key);
    const hasAfter = Object.prototype.hasOwnProperty.call(after, key);
    if (hadBefore && !hasAfter) {
      delete out[key];
      continue;
    }
    if (!hasAfter) continue;
    if (!hadBefore) {
      out[key] = cloneValue(after[key]);
      continue;
    }
    if (sameValue(before[key], after[key])) continue;
    out[key] = plainObject(after[key]) && plainObject(before[key])
      ? mergeTabRegistryRecordDelta(out[key], before[key], after[key])
      : cloneValue(after[key]);
  }
  return out;
}

function wantsRegistry(query) {
  if (query == null) return true;
  if (typeof query === "string") return query === TAB_REGISTRY_STORAGE_KEY;
  if (Array.isArray(query)) return query.includes(TAB_REGISTRY_STORAGE_KEY);
  return plainObject(query) && Object.prototype.hasOwnProperty.call(query, TAB_REGISTRY_STORAGE_KEY);
}

function decorateRegistryResult(result) {
  if (!plainObject(result)) return result;
  const registry = result[TAB_REGISTRY_STORAGE_KEY];
  if (!plainObject(registry)) return result;
  try {
    Object.defineProperty(registry, TAB_REGISTRY_BASELINE, {
      value: cloneValue(registry),
      enumerable: false,
      configurable: true,
    });
  } catch { /* non-extensible test doubles simply use legacy storage semantics */ }
  return result;
}

async function chromeAuthorityState(chromeApi, registry) {
  if (!chromeApi?.tabs?.query || !chromeApi?.tabGroups?.query) return null;
  try {
    const [tabs, groups, debuggerTargets] = await Promise.all([
      chromeApi.tabs.query({}),
      chromeApi.tabGroups.query({}),
      typeof chromeApi?.debugger?.getTargets === "function" ? chromeApi.debugger.getTargets().catch(() => []) : [],
    ]);
    return reconcileControlState({ registry, tabs, groups, debuggerTargets, now: Date.now() });
  } catch {
    return null;
  }
}

async function synchronizeControllerGroups(chromeApi, originalGet, originalSet, registry) {
  if (!chromeApi?.tabs?.get || !chromeApi?.tabs?.group || !chromeApi?.tabGroups?.query || !chromeApi?.tabGroups?.update) {
    return registry;
  }
  const storedGroups = await originalGet(CONTROLLER_GROUPS_STORAGE_KEY).catch(() => ({}));
  const mapping = plainObject(storedGroups?.[CONTROLLER_GROUPS_STORAGE_KEY])
    ? cloneValue(storedGroups[CONTROLLER_GROUPS_STORAGE_KEY])
    : {};
  let groups = await chromeApi.tabGroups.query({}).catch(() => []);
  const nextRegistry = cloneValue(registry || {});
  let registryChanged = false;
  let mappingChanged = false;

  for (const [key, record] of Object.entries(nextRegistry)) {
    const tabId = Number(record?.tabId || key || 0);
    const controllerSessionId = normalizeControllerSessionId(record, {});
    if (!tabId || !controllerSessionId) continue;
    const tab = await chromeApi.tabs.get(tabId).catch(() => null);
    if (!tab) continue;
    const windowId = Number(tab.windowId || record?.windowId || 0);
    if (!windowId) continue;
    const title = controllerGroupTitle(controllerSessionId);
    const mapKey = `${controllerSessionId}@@${windowId}`;
    const mappedId = Number(mapping?.[mapKey]?.groupId || 0);
    let group = mappedId
      ? groups.find((candidate) => Number(candidate?.id || 0) === mappedId && Number(candidate?.windowId || 0) === windowId)
      : null;
    if (!group) {
      group = groups.find((candidate) => String(candidate?.title || "") === title && Number(candidate?.windowId || 0) === windowId) || null;
    }

    try {
      let groupId = Number(group?.id || 0);
      if (!groupId) {
        groupId = Number(await chromeApi.tabs.group({ tabIds: [tabId], createProperties: { windowId } }));
        await chromeApi.tabGroups.update(groupId, { title, color: "green", collapsed: false }).catch(() => {});
        group = { id: groupId, windowId, title };
        groups = [...groups, group];
      } else if (Number(tab.groupId ?? -1) !== groupId) {
        await chromeApi.tabs.group({ groupId, tabIds: [tabId] });
      }
      if (String(group?.title || "") !== title) {
        await chromeApi.tabGroups.update(groupId, { title, color: "green", collapsed: false }).catch(() => {});
        group = { ...group, title };
      }
      if (Number(record?.controlGroupId || 0) !== groupId || record?.controllerSessionId !== controllerSessionId || record?.expectedOrigin) {
        nextRegistry[String(tabId)] = {
          ...record,
          tabId,
          windowId,
          controllerSessionId,
          laneId: String(record?.laneId || controllerSessionId),
          controlGroupId: groupId,
          expectedOrigin: "",
          kernelVersion: BROWSER_CONTROL_KERNEL_VERSION,
          updatedAt: Date.now(),
        };
        registryChanged = true;
      }
      const mapped = mapping[mapKey];
      if (!mapped || Number(mapped.groupId || 0) !== groupId || mapped.title !== title) {
        mapping[mapKey] = { groupId, windowId, title, controllerSessionId, updatedAt: Date.now() };
        mappingChanged = true;
      }
    } catch {
      // Grouping is recoverable topology, not an authorization gate. A transient
      // Chrome UI failure must not turn an executable browser action into a deny.
    }
  }

  if (registryChanged) await originalSet({ [TAB_REGISTRY_STORAGE_KEY]: nextRegistry });
  if (mappingChanged) await originalSet({ [CONTROLLER_GROUPS_STORAGE_KEY]: mapping });
  return nextRegistry;
}

/**
 * Browser Agent 2.0 compatibility kernel.
 *
 * The service worker still calls getTabRegistry()/saveTabRegistry(), but those
 * reads/writes are now reconciled with live Chrome topology. This removes the
 * registry as a single point of failure while preserving the existing runtime
 * API during the 1.0 -> 2.0 migration.
 */
export function installAtomicTabRegistryStorageShim(
  storageArea = globalThis?.chrome?.storage?.local,
  chromeApi = globalThis?.chrome,
) {
  if (!storageArea || typeof storageArea.get !== "function" || typeof storageArea.set !== "function") return false;
  if (storageArea.__prstudioAtomicTabRegistryInstalled) return true;

  const originalGet = storageArea.get.bind(storageArea);
  const originalSet = storageArea.set.bind(storageArea);

  const reconcileResult = async (result) => {
    if (!plainObject(result)) return result;
    const storedRegistry = plainObject(result[TAB_REGISTRY_STORAGE_KEY])
      ? result[TAB_REGISTRY_STORAGE_KEY]
      : plainObject((await originalGet(TAB_REGISTRY_STORAGE_KEY).catch(() => ({})))?.[TAB_REGISTRY_STORAGE_KEY])
        ? (await originalGet(TAB_REGISTRY_STORAGE_KEY))[TAB_REGISTRY_STORAGE_KEY]
        : {};
    const authority = await chromeAuthorityState(chromeApi, storedRegistry);
    if (!authority) return decorateRegistryResult(result);
    let reconciledRegistry = authority.registry;
    if (!sameValue(storedRegistry, reconciledRegistry)) {
      await originalSet({ [TAB_REGISTRY_STORAGE_KEY]: reconciledRegistry });
    }
    reconciledRegistry = await synchronizeControllerGroups(chromeApi, originalGet, originalSet, reconciledRegistry);
    return decorateRegistryResult({ ...result, [TAB_REGISTRY_STORAGE_KEY]: reconciledRegistry });
  };

  const wrappedGet = function (...args) {
    const query = args[0];
    const callbackIndex = typeof args[args.length - 1] === "function" ? args.length - 1 : -1;
    if (!wantsRegistry(query)) return originalGet(...args);
    if (callbackIndex >= 0) {
      const callback = args[callbackIndex];
      args[callbackIndex] = (result) => {
        reconcileResult(result).then(callback, () => callback(decorateRegistryResult(result)));
      };
      return originalGet(...args);
    }
    const result = originalGet(...args);
    return result && typeof result.then === "function"
      ? result.then(reconcileResult)
      : reconcileResult(result);
  };

  const wrappedSet = function (items = {}, callback) {
    const desired = plainObject(items?.[TAB_REGISTRY_STORAGE_KEY]) ? items[TAB_REGISTRY_STORAGE_KEY] : null;
    if (!desired) return originalSet(items, callback);
    const baseline = desired?.[TAB_REGISTRY_BASELINE];

    const apply = async () => {
      const stored = await originalGet(TAB_REGISTRY_STORAGE_KEY);
      const latest = plainObject(stored?.[TAB_REGISTRY_STORAGE_KEY]) ? stored[TAB_REGISTRY_STORAGE_KEY] : {};
      const merged = plainObject(baseline) ? mergeTabRegistryDelta(latest, baseline, desired) : cloneValue(desired);
      let synchronized = await synchronizeControllerGroups(chromeApi, originalGet, originalSet, merged);
      const authority = await chromeAuthorityState(chromeApi, synchronized);
      if (authority) synchronized = authority.registry;
      await originalSet({ ...items, [TAB_REGISTRY_STORAGE_KEY]: synchronized });
      try {
        Object.defineProperty(desired, TAB_REGISTRY_BASELINE, {
          value: cloneValue(synchronized),
          enumerable: false,
          configurable: true,
        });
      } catch { /* ignore non-extensible caller objects */ }
      return undefined;
    };

    const queued = tabRegistryWriteQueue.then(apply, apply);
    tabRegistryWriteQueue = queued.catch(() => {});
    if (typeof callback === "function") {
      queued.then(() => callback(), () => callback());
      return undefined;
    }
    return queued;
  };

  try {
    storageArea.get = wrappedGet;
    storageArea.set = wrappedSet;
    Object.defineProperty(storageArea, "__prstudioAtomicTabRegistryInstalled", {
      value: true,
      enumerable: false,
      configurable: false,
    });
    Object.defineProperty(storageArea, "__prstudioBrowserControlKernelVersion", {
      value: BROWSER_CONTROL_KERNEL_VERSION,
      enumerable: false,
      configurable: false,
    });
    return true;
  } catch {
    return false;
  }
}

// service-worker.js imports this module before it starts handling browser tasks.
installAtomicTabRegistryStorageShim();

export function candidateTabUrl(tab = {}, fallback = "") {
  const committed = String(tab?.url || "");
  if (/^https?:/i.test(committed)) return committed;
  const pending = String(tab?.pendingUrl || "");
  if (/^https?:/i.test(pending)) return pending;
  return String(fallback || "");
}

export function isHttpCandidate(value = "") {
  try {
    const url = new URL(String(value || ""));
    return url.protocol === "http:" || url.protocol === "https:";
  } catch {
    return false;
  }
}

export function provisionalOwnershipState(tab = {}, record = {}) {
  const committed = String(tab?.url || "");
  const candidate = candidateTabUrl(tab, record?.url || "");
  return {
    committedUrl: committed,
    candidateUrl: candidate,
    committedHttp: isHttpCandidate(committed),
    candidateHttp: isHttpCandidate(candidate),
    provisional: Boolean(record?.provisional) && !isHttpCandidate(committed) && isHttpCandidate(candidate),
  };
}

/**
 * Preserve the canonical ownership identity when Chrome replaces a tab ID
 * (for example because of prerender activation).
 */
export function migrateTabReplacementRecord(record, addedTab = {}, removedTabId, now = Date.now()) {
  if (!record || typeof record !== "object") return null;
  const removedId = Number(removedTabId || 0);
  const priorId = Number(record.tabId || 0);
  const addedId = Number(addedTab?.id || 0);
  if (!removedId || priorId !== removedId || !addedId) return null;
  const committed = String(addedTab?.url || "");
  const url = candidateTabUrl(addedTab, record.url || "");
  return {
    ...record,
    tabId: addedId,
    windowId: Number(addedTab?.windowId || record.windowId || 0) || record.windowId || null,
    url,
    title: String(addedTab?.title || record.title || ""),
    controllerSessionId: normalizeControllerSessionId(record, {}),
    expectedOrigin: "",
    provisional: !isHttpCandidate(committed) && isHttpCandidate(url),
    kernelVersion: BROWSER_CONTROL_KERNEL_VERSION,
    updatedAt: Number(now || Date.now()),
    replacedFromTabId: removedId,
  };
}

export function migrateTabReplacementState(state = {}, addedTab = {}, removedTabId, now = Date.now()) {
  const removedId = Number(removedTabId || 0);
  const addedId = Number(addedTab?.id || 0);
  if (!removedId || !addedId) return null;
  const registry = { ...(state.registry || {}) };
  const current = registry[String(removedId)] || null;
  const migrated = migrateTabReplacementRecord(current, addedTab, removedId, now);
  if (!migrated) return null;
  delete registry[String(removedId)];
  registry[String(addedId)] = migrated;

  const affinityTasks = Object.fromEntries(Object.entries(state.affinityTasks || {}).map(([taskId, value]) => [
    taskId,
    Number(value?.tabId || 0) === removedId ? { ...value, tabId: addedId, at: Number(now || Date.now()), reason: "tab_replaced" } : value,
  ]));
  const lastTabId = Number(state.lastTabId || 0) === removedId ? addedId : (Number(state.lastTabId || 0) || null);
  const activeTask = state.activeTask && Number(state.activeTask?.tabId || 0) === removedId
    ? { ...state.activeTask, tabId: addedId, tabReplacementAt: Number(now || Date.now()) }
    : state.activeTask || null;
  const runtimeSessions = Object.fromEntries(Object.entries(state.runtimeSessions || {}).map(([id, session]) => {
    if (Number(session?.tabId || 0) !== removedId) return [id, session];
    return [id, {
      ...session,
      tabId: addedId,
      interruptedByTabReplacement: true,
      interruptedAt: Number(now || Date.now()),
      interruptionReason: "tab_replaced",
    }];
  }));
  return { registry, affinityTasks, lastTabId, activeTask, runtimeSessions, record: migrated };
}

export function tabBindingCompatibility(record = {}, context = {}) {
  const ownerController = normalizeControllerSessionId(record, {});
  const requestedController = normalizeControllerSessionId({}, context);
  const ownerLane = String(record?.laneId || ownerController || "").trim();
  const requestedLane = String(context?.laneId || context?._prstudio_lane_id || requestedController || "").trim();
  const ownerTask = String(record?.taskId || "").trim();
  const requestedTask = String(context?.taskId || "").trim();

  // Browser Agent 2.0: task identity never owns a tab. Controller session does.
  // Legacy error/mode labels stay stable while callers migrate from laneId.
  if (ownerController && requestedController && ownerController !== requestedController) {
    return {
      ok: false,
      code: "tab_lane_conflict",
      codeV2: "tab_controller_conflict",
      ownerControllerSessionId: ownerController,
      requestedControllerSessionId: requestedController,
      ownerLane,
      requestedLane,
      ownerTask,
      requestedTask,
    };
  }
  const effectiveController = ownerController || requestedController;
  const effectiveLane = ownerLane || requestedLane || effectiveController;
  const taskChanged = Boolean(ownerTask && requestedTask && ownerTask !== requestedTask);
  return {
    ok: true,
    mode: taskChanged ? (effectiveController ? "lane_task_rebind" : "session_task_rebind") : (effectiveController ? "lane_owned" : "session_owned"),
    modeV2: taskChanged ? "controller_task_rebind" : "controller_owned",
    controllerSessionId: effectiveController,
    ownerControllerSessionId: ownerController,
    requestedControllerSessionId: requestedController,
    ownerLane: effectiveLane,
    requestedLane,
    ownerTask,
    requestedTask,
  };
}

export {
  BROWSER_CONTROL_KERNEL_VERSION,
  controllerGroupTitle,
  normalizeControllerSessionId,
  reconcileControlState,
  sitePermissionDecision,
  controllerIsolationInvariant,
  kernelCertificationSnapshot,
};
