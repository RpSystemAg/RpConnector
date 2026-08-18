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

