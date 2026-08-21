export const BROWSER_CONTROL_KERNEL_VERSION = "2.0.0";
export const CONTROL_GROUP_PREFIX = "PR STUDIO Agent";

function plainObject(value) {
  return value && typeof value === "object" && !Array.isArray(value);
}

function cleanString(value) {
  return String(value ?? "").trim();
}

function stableControllerId(value) {
  return cleanString(value).replace(/[^A-Za-z0-9._:-]+/g, "-").slice(0, 64);
}

export function normalizeControllerSessionId(record = {}, context = {}) {
  const candidates = [
    context.controllerSessionId,
    context.controller_session_id,
    context._prstudio_controller_session_id,
    context.laneId,
    context._prstudio_lane_id,
    record.controllerSessionId,
    record.controller_session_id,
    record.laneId,
  ];
  for (const value of candidates) {
    const normalized = stableControllerId(value);
    if (normalized) return normalized;
  }
  return "";
}

export function controllerGroupTitle(controllerSessionId = "") {
  const session = stableControllerId(controllerSessionId);
  return session ? `${CONTROL_GROUP_PREFIX} · ${session}` : CONTROL_GROUP_PREFIX;
}

export function parseControllerSessionFromGroupTitle(title = "") {
  const value = cleanString(title);
  if (value === CONTROL_GROUP_PREFIX) return "";
  const prefix = `${CONTROL_GROUP_PREFIX} · `;
  return value.startsWith(prefix) ? stableControllerId(value.slice(prefix.length)) : "";
}

export function isControllerGroup(group = {}) {
  const title = cleanString(group?.title);
  return title === CONTROL_GROUP_PREFIX || title.startsWith(`${CONTROL_GROUP_PREFIX} · `);
}

function originOf(value = "") {
  try { return new URL(String(value || "")).origin; }
  catch { return ""; }
}

export function sitePermissionDecision({
  url = "",
  requestedUrl = "",
  allowedOrigins = [],
  deniedOrigins = [],
  unknownPolicy = "deny",
} = {}) {
  const actualOrigin = originOf(requestedUrl || url);
  const allow = new Set((Array.isArray(allowedOrigins) ? allowedOrigins : []).map(cleanString).filter(Boolean));
  const deny = new Set((Array.isArray(deniedOrigins) ? deniedOrigins : []).map(cleanString).filter(Boolean));
  const unknown = ["allow", "ask", "deny"].includes(cleanString(unknownPolicy).toLowerCase())
    ? cleanString(unknownPolicy).toLowerCase()
    : "deny";
  if (!actualOrigin) return { decision: "deny", reason: "invalid_origin", actualOrigin };
  if (deny.has(actualOrigin)) return { decision: "deny", reason: "explicit_deny", actualOrigin };
  if (allow.has(actualOrigin)) return { decision: "allow", reason: "explicit_allow", actualOrigin };
  if (allow.size) {
    return {
      decision: unknown,
      reason: unknown === "ask" ? "unknown_origin_requires_handoff" : unknown === "allow" ? "unknown_origin_allowed_by_policy" : "not_in_allowlist",
      actualOrigin,
    };
  }
  return {
    decision: "allow",
    reason: "open_world",
    actualOrigin,
  };
}

function candidateTabUrl(tab = {}, fallback = "") {
  const committed = cleanString(tab?.url);
  if (/^https?:/i.test(committed)) return committed;
  const pending = cleanString(tab?.pendingUrl);
  if (/^https?:/i.test(pending)) return pending;
  return cleanString(fallback);
}

function debuggerAttachedTabIds(targets = []) {
  const out = new Set();
  for (const target of Array.isArray(targets) ? targets : []) {
    const tabId = Number(target?.tabId || target?.tab_id || 0);
    if (tabId && target?.attached) out.add(tabId);
  }
  return out;
}

function ownershipNonce(existing, groupId, tabId) {
  const current = cleanString(existing?.ownershipNonce);
  return current || `chrome-group:${Number(groupId || 0)}:tab:${Number(tabId || 0)}`;
}

export function reconcileControlState({
  registry = {},
  groups = [],
  tabs = [],
  debuggerTargets = [],
  now = Date.now(),
} = {}) {
  const safeRegistry = plainObject(registry) ? registry : {};
  const liveTabs = new Map((Array.isArray(tabs) ? tabs : [])
    .map((tab) => [Number(tab?.id || 0), tab])
    .filter(([id]) => Boolean(id)));
  const controlledGroups = new Map();
  for (const group of Array.isArray(groups) ? groups : []) {
    const groupId = Number(group?.id || 0);
    if (!groupId || !isControllerGroup(group)) continue;
    controlledGroups.set(groupId, {
      ...group,
      controllerSessionId: parseControllerSessionFromGroupTitle(group.title),
    });
  }
  const attachedTabs = debuggerAttachedTabIds(debuggerTargets);
  const next = {};
  const released = [];
  let adoptedFromGroups = 0;
  let inheritedPopups = 0;

  for (const [key, current] of Object.entries(safeRegistry)) {
    const tabId = Number(current?.tabId || key || 0);
    const tab = liveTabs.get(tabId);
    if (!tab) {
      released.push({ tabId, reason: "tab_closed" });
      continue;
    }
    const liveGroupId = Number(tab?.groupId ?? -1);
    const recordedGroupId = Number(current?.controlGroupId || 0);
    if (recordedGroupId && liveGroupId !== recordedGroupId) {
      released.push({ tabId, reason: "dragged_out_of_control_group", recordedGroupId, liveGroupId });
      continue;
    }
    const group = controlledGroups.get(liveGroupId) || null;
    const controllerSessionId = cleanString(group?.controllerSessionId)
      || normalizeControllerSessionId(current, {});
    next[String(tabId)] = {
      ...current,
      tabId,
      windowId: Number(tab?.windowId || current?.windowId || 0) || null,
      url: candidateTabUrl(tab, current?.url || ""),
      title: cleanString(tab?.title || current?.title),
      controllerSessionId,
      laneId: cleanString(current?.laneId) || controllerSessionId,
      expectedOrigin: "",
      controlGroupId: group && controllerSessionId ? liveGroupId : (recordedGroupId || null),
      debuggerAttached: attachedTabs.has(tabId),
      kernelVersion: BROWSER_CONTROL_KERNEL_VERSION,
      updatedAt: Number(now),
    };
  }

  for (const [groupId, group] of controlledGroups.entries()) {
    for (const tab of liveTabs.values()) {
      if (Number(tab?.groupId ?? -1) !== groupId) continue;
      const tabId = Number(tab.id);
      const existing = next[String(tabId)] || safeRegistry[String(tabId)] || {};
      const controllerSessionId = cleanString(group.controllerSessionId)
        || normalizeControllerSessionId(existing, {});
      if (!controllerSessionId) continue;
      if (!next[String(tabId)]) adoptedFromGroups += 1;
      next[String(tabId)] = {
        ...existing,
        tabId,
        windowId: Number(tab?.windowId || existing?.windowId || 0) || null,
        url: candidateTabUrl(tab, existing?.url || ""),
        title: cleanString(tab?.title || existing?.title),
        owner: "prstudio-agent",
        controllerSessionId,
        laneId: cleanString(existing?.laneId) || controllerSessionId,
        expectedOrigin: "",
        ownershipNonce: ownershipNonce(existing, groupId, tabId),
        controlGroupId: groupId,
        debuggerAttached: attachedTabs.has(tabId),
        kernelVersion: BROWSER_CONTROL_KERNEL_VERSION,
        createdAt: Number(existing?.createdAt || now),
        updatedAt: Number(now),
      };
    }
  }

  for (const tab of liveTabs.values()) {
    const tabId = Number(tab.id || 0);
    if (!tabId || next[String(tabId)]) continue;
    const openerTabId = Number(tab?.openerTabId || 0);
    if (!openerTabId) continue;
    const opener = next[String(openerTabId)];
    if (!opener?.controllerSessionId) continue;
    inheritedPopups += 1;
    next[String(tabId)] = {
      tabId,
      windowId: Number(tab?.windowId || 0) || null,
      url: candidateTabUrl(tab, ""),
      title: cleanString(tab?.title),
      owner: "prstudio-agent",
      controllerSessionId: opener.controllerSessionId,
      laneId: opener.laneId || opener.controllerSessionId,
      expectedOrigin: "",
      ownershipNonce: `popup:${openerTabId}:${tabId}`,
      controlGroupId: null,
      popupInherited: true,
      debuggerAttached: attachedTabs.has(tabId),
      kernelVersion: BROWSER_CONTROL_KERNEL_VERSION,
      createdAt: Number(now),
      updatedAt: Number(now),
    };
  }

  return {
    registry: next,
    controlledGroupIds: [...controlledGroups.keys()].sort((a, b) => a - b),
    attachedTabIds: [...attachedTabs].sort((a, b) => a - b),
    released,
    adoptedFromGroups,
    inheritedPopups,
  };
}

export function controllerIsolationInvariant(registry = {}) {
  const tabOwners = new Map();
  const conflicts = [];
  for (const [key, record] of Object.entries(plainObject(registry) ? registry : {})) {
    const tabId = Number(record?.tabId || key || 0);
    if (!tabId) continue;
    const controllerSessionId = normalizeControllerSessionId(record, {});
    if (!controllerSessionId) continue;
    const previous = tabOwners.get(tabId);
    if (previous && previous !== controllerSessionId) {
      conflicts.push({ tabId, controllers: [previous, controllerSessionId] });
    }
    tabOwners.set(tabId, controllerSessionId);
  }
  return { ok: conflicts.length === 0, conflicts, controlledTabs: tabOwners.size };
}

export function kernelCertificationSnapshot({ registry = {}, groups = [], tabs = [], debuggerTargets = [] } = {}) {
  const reconciled = reconcileControlState({ registry, groups, tabs, debuggerTargets, now: 1 });
  const isolation = controllerIsolationInvariant(reconciled.registry);
  return {
    kernelVersion: BROWSER_CONTROL_KERNEL_VERSION,
    controlledTabs: Object.keys(reconciled.registry).length,
    controlledGroups: reconciled.controlledGroupIds.length,
    debuggerAttachedTabs: reconciled.attachedTabIds.length,
    isolationOk: isolation.ok,
    released: reconciled.released.length,
    adoptedFromGroups: reconciled.adoptedFromGroups,
    inheritedPopups: reconciled.inheritedPopups,
  };
}
