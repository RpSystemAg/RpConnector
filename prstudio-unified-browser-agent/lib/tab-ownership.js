const TAB_REGISTRY_STORAGE_KEY = "prstudioTabRegistry";
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

/**
 * MV3 service-worker storage is shared by independent extension events. The
 * Browser Agent historically performed whole-registry read/modify/write saves;
 * an older event could therefore erase a just-created ownership claim between
 * registerOwnedTab() and assertOwnedTab(). Install a narrow compatibility shim
 * for this one key: reads carry a non-serialised baseline and writes merge only
 * the caller's delta onto the latest stored registry, with in-worker writes
 * serialized. All other chrome.storage.local keys retain native semantics.
 */
export function installAtomicTabRegistryStorageShim(storageArea = globalThis?.chrome?.storage?.local) {
  if (!storageArea || typeof storageArea.get !== "function" || typeof storageArea.set !== "function") return false;
  if (storageArea.__prstudioAtomicTabRegistryInstalled) return true;

  const originalGet = storageArea.get.bind(storageArea);
  const originalSet = storageArea.set.bind(storageArea);

  const wrappedGet = function (...args) {
    const query = args[0];
    const callbackIndex = typeof args[args.length - 1] === "function" ? args.length - 1 : -1;
    if (!wantsRegistry(query)) return originalGet(...args);
    if (callbackIndex >= 0) {
      const callback = args[callbackIndex];
      args[callbackIndex] = (result) => callback(decorateRegistryResult(result));
      return originalGet(...args);
    }
    const result = originalGet(...args);
    return result && typeof result.then === "function"
      ? result.then(decorateRegistryResult)
      : decorateRegistryResult(result);
  };

  const wrappedSet = function (items = {}, callback) {
    const desired = plainObject(items?.[TAB_REGISTRY_STORAGE_KEY]) ? items[TAB_REGISTRY_STORAGE_KEY] : null;
    const baseline = desired?.[TAB_REGISTRY_BASELINE];
    if (!desired || !plainObject(baseline)) return originalSet(items, callback);

    const apply = async () => {
      const stored = await originalGet(TAB_REGISTRY_STORAGE_KEY);
      const latest = plainObject(stored?.[TAB_REGISTRY_STORAGE_KEY]) ? stored[TAB_REGISTRY_STORAGE_KEY] : {};
      const merged = mergeTabRegistryDelta(latest, baseline, desired);
      await originalSet({ ...items, [TAB_REGISTRY_STORAGE_KEY]: merged });
      try {
        Object.defineProperty(desired, TAB_REGISTRY_BASELINE, {
          value: cloneValue(merged),
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
    return true;
  } catch {
    return false;
  }
}

// service-worker.js imports this module before it starts handling browser tasks.
// Install the narrow registry fix at module evaluation time; Node unit tests do
// not define chrome and therefore remain pure unless they opt into the shim.
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
 * (for example because of prerender activation). This is intentionally pure
 * so replacement semantics can be regression-tested without a fake Chrome.
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
    provisional: !isHttpCandidate(committed) && isHttpCandidate(url),
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
  const ownerLane = String(record?.laneId || "").trim();
  const requestedLane = String(context?.laneId || context?._prstudio_lane_id || "").trim();
  const ownerTask = String(record?.taskId || "").trim();
  const requestedTask = String(context?.taskId || "").trim();

  // PR STUDIO ONE-GUARD INVARIANT: ownership is controlled-session/lane scoped.
  // taskId is telemetry/affinity only and can never veto reuse of an owned tab.
  if (ownerLane && requestedLane && ownerLane !== requestedLane) {
    return { ok: false, code: "tab_lane_conflict", ownerLane, requestedLane, ownerTask, requestedTask };
  }
  const effectiveLane = ownerLane || requestedLane;
  const taskChanged = Boolean(ownerTask && requestedTask && ownerTask !== requestedTask);
  return {
    ok: true,
    mode: taskChanged ? (effectiveLane ? "lane_task_rebind" : "session_task_rebind") : (effectiveLane ? "lane_owned" : "session_owned"),
    ownerLane: effectiveLane,
    requestedLane,
    ownerTask,
    requestedTask,
  };
}
