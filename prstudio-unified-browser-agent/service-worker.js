import {
  AUTH_CHALLENGE_SELECTORS,
  AUTH_CHALLENGE_TEXT,
  isCriticalAction,
  computeBackoff,
  shouldCheckAuthChallengeBefore,
  shouldCheckAuthChallengeAfter,
  validateCdpCommand,
  isMutatingStep,
} from "./lib/policy.js";
import {
  resumableState,
  actionToSteps,
  normalizeUrlForEvidence,
  compareUrlEvidence,
  testUserUrlRegex,
  matchLiteralWildcard,
} from "./lib/protocol.js";
import { interruptionReason, digestStep, beginInFlightState, markCommittingState, clearInFlightState, recoveryDisposition } from "./lib/recovery.js";
import {
  SUITE_VERSION,
  EXECUTOR_PROTOCOL_VERSION,
  GSC_DIMENSION_SESSION_VERSION,
  OBSERVATION_SECURITY_VERSION,
  CAPABILITY_CONTRACT_SHA256,
  EXECUTOR_BUILD_TIMESTAMP,
  EXECUTOR_BUILD_ID,
} from "./lib/executor-meta.js";
import { adaptivePollDelay, failingTaskBackoffMs } from "./lib/enterprise-runtime.js";
import { selectOwnedTabCandidate, normalizeMetricGrid, extractMetricRowsFromPayload, dedupeMetricRows, boundedCrawlerOptions, extractSearchConsoleInspection } from "./lib/resilience.js";
import { RUNTIME_CONTRACT_ACTIONS, hasRuntimeContractAction, isSensitiveRuntimeContractAction } from "./lib/runtime-capabilities.js";
import { normalizeGscDimensions, unsupportedGscDimensions, gscDimensionAliases, headerMatchesGscDimension, labelMatchesGscDimension, inferGscDimensionFromHeaders, validateGscDimensionRows, shouldNavigateSearchConsole, mergeGscDimensionCollections, normalizeGscProperty, gscPropertyMatches, gscPropertyLabels } from "./lib/gsc-session.js";
import { DEBUGGER_PROTOCOL_CANDIDATES, attachWithProtocolFallback } from "./lib/cdp-protocol.js";
import { pointerSequence, dragSequence, keyboardSequence } from "./lib/native-input.js";
import { bestSemanticTarget, rankSemanticTargets } from "./lib/semantic-ranking.js";
import { candidateTabUrl, provisionalOwnershipState, migrateTabReplacementState, tabBindingCompatibility } from "./lib/tab-ownership.js";
import { REMOTE_MAX_STEP_ATTEMPTS, canFreshRestart, isRetrySafeFailure, stepWatchdogMs, noProgressExceeded } from "./lib/remote-recovery.js";
import { createObservationEnvelope, redactObservation } from "./lib/observation-security.js";
import { migrateOneGuardLegacyState } from "./lib/legacy-one-guard-migration.js";
import { parseUserUrl, describeUrlInput } from "./lib/url-input.js";
import { agentCursorPainter, isDrawablePoint, cursorModeForEvent, CURSOR_ELEMENT_ID } from "./lib/agent-cursor.js";
import { buildScreenshotCandidates } from "./lib/screenshot-candidates.js";
import { AGENT_GROUP_TITLE, AGENT_GROUP_COLOR, isReusableGroup } from "./lib/agent-tab-group.js";
import {
  LOCAL_STUDIO_VERSION,
  LOCAL_STUDIO_FEATURES,
  featureAdvertisement,
  isRestrictedLocalUrl,
  normalizeOriginProfile,
  pageHealthScore,
  validateLocalWorkflow,
  normalizeWorkflowStep,
  localStepMutates,
} from "./lib/local-studio.js";
import {
  collectLocalPageHealth,
  collectLocalSemanticSnapshot,
  collectLocalResponsiveSnapshot,
  installLocalInspector,
  installLocalRecorder,
  uninstallLocalRecorder,
  resolveLocalWorkflowTarget,
  executeLocalWorkflowStep,
} from "./lib/local-page-functions.js";
import { hardenStepsForUntrustedPage, containmentDecision, containmentFallbackStep } from "./lib/trap-page-policy.js";
import { createHorizonSession, planHorizonStep } from "./lib/horizon-stability.js";

const STORAGE_KEYS = {
  CONFIG: "prstudioConfig",
  ACTIVE: "prstudioActiveTask",
  LOGS: "prstudioLogs",
  LOG_QUEUE: "prstudioLogQueue",
  AGENT_WINDOW: "prstudioAgentWindow",
  AGENT_TAB_GROUP: "prstudioAgentTabGroup",
  TAB_REGISTRY: "prstudioTabRegistry",
  TAB_AFFINITY: "prstudioTabAffinity",
  LAST_AGENT_TAB: "prstudioLastAgentTab",
  BASELINES: "prstudioVisualBaselines",
  GSC_SESSION: "prstudioGscSession",
  LOCAL_WORKFLOWS: "prstudioLocalWorkflows",
  LOCAL_RECORDER: "prstudioLocalRecorder",
  LOCAL_INSPECTOR: "prstudioLocalInspector",
  LOCAL_FLIGHT: "prstudioLocalFlightRecorder",
  LOCAL_BASELINES: "prstudioLocalBaselines",
  LOCAL_WORKSPACES: "prstudioLocalWorkspaces",
  LOCAL_SCHEDULES: "prstudioLocalSchedules",
  LOCAL_RESULTS: "prstudioLocalScheduledResults",
  LOCAL_ACTIVE: "prstudioLocalActive",
  LOCAL_PROFILES: "prstudioLocalOriginProfiles",
  SENSITIVE_STATES: "prstudioSensitiveBrowserStates",
  SUITE_MIGRATION: "prstudioSuiteMigration",
  RUNTIME_SESSIONS: "prstudioRuntimeSessions",
};

const API_VERSION = SUITE_VERSION;
const HEARTBEAT_MS = 10000;
const SCREENSHOT_MAX_OUTPUT_BYTES = 11_500_000;
const SCREENSHOT_MAX_PIXELS = 28_000_000;
const SCREENSHOT_MAX_DIMENSION = 16_384;
const PERCEPTION_MAX_PIXELS = 1_200_000;
const PERCEPTION_MAX_DIMENSION = 1440;
const PERCEPTION_CACHE_TTL_MS = 1_500;
const PERCEPTION_ZOOM_MIN_TARGET_PX = 18;
const SCREENSHOT_LARGE_PIXEL_THRESHOLD = 8_000_000;
const SCREENSHOT_STORAGE_CACHE_MS = 60_000;
// Screenshot work uses bounded technical timeouts. Timeout telemetry never authorizes or vetoes a valid action before execution.
const SCREENSHOT_STEP_TIMEOUT_MS = 9_500;
const SCREENSHOT_PREFLIGHT_TIMEOUT_MS = 1_200;
const SCREENSHOT_CAPTURE_TIMEOUT_MS = 4_800;
const SCREENSHOT_ATTACH_TIMEOUT_MS = 1_200;
const SCREENSHOT_CDP_TIMEOUT_MS = 1_600;
const SCREENSHOT_VISIBLE_TIMEOUT_MS = 1_800;
const SCREENSHOT_MEASURE_TIMEOUT_MS = 400;
const SCREENSHOT_SCROLL_TIMEOUT_MS = 1_200;
const SCREENSHOT_UPLOAD_TIMEOUT_MS = 2_400;
const RESPONSIVE_MATRIX_VIEWPORT_TIMEOUT_MS = 8_500;
const RESPONSIVE_MATRIX_CDP_TIMEOUT_MS = 1_800;
const RESPONSIVE_MATRIX_RESTORE_TIMEOUT_MS = 2_500;
const RESPONSIVE_MATRIX_MAX_TIMEOUT_MS = 60_000;
const API_DEFAULT_TIMEOUT_MS = 30_000;
const CDP_DEFAULT_TIMEOUT_MS = 20_000;
const LOCAL_CAPTURE_TIMEOUT_MS = 30_000;
const RUNTIME_SESSION_TTL_MS = 30 * 60 * 1_000;
const EVENT_BUFFER_MAX_BYTES = 4_000_000;
const TRACE_BUFFER_MAX_BYTES = 6_000_000;
const SCREENCAST_BUFFER_MAX_BYTES = 16_000_000;
const GSC_PAYLOAD_ITEM_MAX_BYTES = 2_000_000;
const GSC_PAYLOAD_TOTAL_MAX_BYTES = 8_000_000;
const USER_INTERACTION_DEBOUNCE_MS = 350;
const AUTOMATION_INPUT_SUPPRESSION_MS = 1_500;
const INTENTIONAL_DETACH_TTL_MS = 10_000;
// Long-poll dispatch (control plane 16.0+). Held below the 25 s server ceiling
// and well under common proxy read timeouts.
const LONG_POLL_SECONDS = 20;
const LONG_POLL_COOLDOWN_MS = 60_000;
let longPollSupported = true;
let longPollCooldownUntil = 0;
let lastWaitMode = "";
let pollLoopRunning = false;
const perceptionFrameCache = new Map();
const screenshotCoordinateContexts = new Map();
const displayGeometryCache = new Map();
const webMcpToolsByTab = new Map();
const webMcpInvocationResults = new Map();
let pollGeneration = 0;
let pollLoopDonePromise = Promise.resolve();
let resolvePollLoopDone = null;
let abortController = null;
const networkBuffers = new Map();
const consoleBuffers = new Map();
const cdpEventBuffers = new Map();
const traceBuffers = new Map();
const screencastBuffers = new Map();
const routeRules = new Map();
const downloadBuffers = new Map();
const structuredNetworkPayloads = new Map();
const gscCollectionGenerations = new Map();
const gscRequestGenerations = new Map();
const debuggerProtocolByTab = new Map();
const intentionalDebuggerDetaches = new Map();
const automationInputUntilByTab = new Map();
const bufferSizeState = new WeakMap();
const pendingUserInteractionTimers = new Map();
const semanticTargetCache = new Map(); // tabId -> {url,generation,targets}; reusable snapshot interaction map.
const pageRuntimePorts = new Map(); // tabId -> main-frame entry; kept for hot-path compatibility.
const pageRuntimeFrames = new Map(); // tabId -> Map(frameId -> {port,domVersion,url,documentId,parentFrameId});
const pageRuntimePending = new Map(); // requestKey -> {resolve,reject,timer};
const cdpChildSessionsByTab = new Map(); // tabId -> Map(sessionId -> target metadata) for OOPIF recursion.
const cdpAutoAttachTabs = new Set();
let pageRuntimeRequestSequence = 0;
let lastVisibleCaptureAt = 0;
let screenshotStorageCache = null;
let screenshotStorageCacheAt = 0;
let remoteLogFlushTimer = null;
let remoteLogFlushRunning = false;
let taskAbortController = null;
// Tasks that failed in a row. The polling loop uses this to keep a
// deterministically-failing task from being re-claimed with no delay: see
// failingTaskBackoffMs. Reset by a task that completes, so a healthy run keeps
// the zero-delay fast path.
let consecutiveTaskFailures = 0;
let taskExecutionGeneration = 0;
let localExecutionGeneration = 0;

chrome.runtime.onConnect.addListener((port) => {
  if (port?.name !== "prstudio-page-runtime") return;
  const tabId = Number(port.sender?.tab?.id || 0);
  if (!tabId) return;
  const frameId = Number(port.sender?.frameId ?? 0);
  const documentId = String(port.sender?.documentId || "");
  let frames = pageRuntimeFrames.get(tabId);
  if (!frames) { frames = new Map(); pageRuntimeFrames.set(tabId, frames); }
  const previous = frames.get(frameId);
  if (previous?.port && previous.port !== port) {
    try { previous.port.disconnect(); } catch { /* replaced by fresher document in the same frame */ }
  }
  const entry = {
    port,
    frameId,
    documentId,
    domVersion: 0,
    url: String(port.sender?.url || port.sender?.tab?.url || ""),
    connectedAt: Date.now(),
  };
  frames.set(frameId, entry);
  if (frameId === 0) pageRuntimePorts.set(tabId, entry);
  port.onMessage.addListener((message = {}) => {
    if (message?.type === "runtime_ready" || message?.type === "dom_mutation") {
      entry.domVersion = Number(message.domVersion || entry.domVersion || 0);
      if (message.url) entry.url = String(message.url);
      if (message.documentId) entry.documentId = String(message.documentId);
      return;
    }
    if (message?.type !== "runtime_response" || !message.id) return;
    const key = `${tabId}:${frameId}:${String(message.id)}`;
    const pending = pageRuntimePending.get(key);
    if (!pending) return;
    pageRuntimePending.delete(key);
    clearTimeout(pending.timer);
    if (message.ok === false) pending.reject(codedError(message.error || "page_runtime_error", message.message || "Page runtime request failed."));
    else pending.resolve(message.result);
  });
  port.onDisconnect.addListener(() => {
    const currentFrames = pageRuntimeFrames.get(tabId);
    if (currentFrames?.get(frameId)?.port === port) currentFrames.delete(frameId);
    if (currentFrames && currentFrames.size === 0) pageRuntimeFrames.delete(tabId);
    if (frameId === 0 && pageRuntimePorts.get(tabId)?.port === port) pageRuntimePorts.delete(tabId);
    const prefix = `${tabId}:${frameId}:`;
    for (const [key, pending] of [...pageRuntimePending.entries()]) {
      if (!key.startsWith(prefix)) continue;
      pageRuntimePending.delete(key);
      clearTimeout(pending.timer);
      pending.reject(codedError("page_runtime_disconnected", "Il runtime pagina persistente si è disconnesso."));
    }
  });
});

chrome.runtime.onInstalled.addListener(async (details = {}) => {
  await migrateOneGuardLegacyState(chrome.storage.local, SUITE_VERSION, details).catch(logError);
  if (chrome.sidePanel?.setPanelBehavior) {
    await chrome.sidePanel.setPanelBehavior({ openPanelOnActionClick: true }).catch(() => {});
  }
  await chrome.alarms.create("prstudio-reconnect", { periodInMinutes: 0.5 });
  await chrome.alarms.create("prstudio-task-heartbeat", { periodInMinutes: 0.5 });
  await chrome.alarms.create("prstudio-device-heartbeat", { periodInMinutes: 0.5 });
  await chrome.storage.local.setAccessLevel?.({ accessLevel: "TRUSTED_CONTEXTS" }).catch(() => {});
  await ensureLocalScheduleAlarms().catch(logError);
  await recoverLocalExecution().catch(logError);
  await reconcileAgentOwnership().catch((error) => appendLog("tab.reconcile.error", serializeError(error)));
  const config = await getConfig();
  await setBadge(config?.token && !config.authExpired ? "ON" : (config?.authExpired ? "KEY" : "OFF"), config?.token && !config.authExpired ? "#176b32" : (config?.authExpired ? "#a32020" : "#666666"));
  startPolling().catch(logError);
  flushRemoteLogs().catch(() => {});
});


chrome.runtime.onStartup.addListener(() => {
  chrome.sidePanel?.setPanelBehavior?.({ openPanelOnActionClick: true }).catch(() => {});
  chrome.alarms.create("prstudio-reconnect", { periodInMinutes: 0.5 }).catch(() => {});
  chrome.alarms.create("prstudio-task-heartbeat", { periodInMinutes: 0.5 }).catch(() => {});
  chrome.alarms.create("prstudio-device-heartbeat", { periodInMinutes: 0.5 }).catch(() => {});
  ensureLocalScheduleAlarms().catch(logError);
  recoverLocalExecution().catch(logError);
  reconcileAgentOwnership().catch(logError);
  reconcileRuntimeSessionsAfterRestart().catch(logError);
  startPolling().catch(logError);
  flushRemoteLogs().catch(() => {});
});




chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === "prstudio-reconnect") {
    startPolling().catch(logError);
    flushRemoteLogs().catch(() => {});
  }
  if (alarm.name === "prstudio-task-heartbeat") {
    heartbeatActiveTask().catch(logError);
  }
  if (alarm.name === "prstudio-device-heartbeat") {
    heartbeatDevice().catch(logError);
    pruneRuntimeSessions().catch(logError);
  }
  if (String(alarm.name || "").startsWith("prstudio-local-check:")) {
    runLocalScheduledCheck(String(alarm.name).slice("prstudio-local-check:".length)).catch(logError);
  }
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
  (async () => {
    switch (message?.type) {
      case "pair":
        sendResponse(await pairDevice(message.siteUrl, message.code, message.name));
        break;
      case "status":
        sendResponse(await statusPayload());
        break;
      case "cancel":
        sendResponse(await cancelActive(message.taskId));
        break;
      case "unpair":
        sendResponse(await forgetPairing());
        break;
      case "start":
        await startPolling();
        sendResponse({ ok: true });
        break;
      case "logs":
        sendResponse({ logs: await getLogs() });
        break;
      case "manual_cleanup_runtime":
        sendResponse(await manualCleanupRuntime());
        break;
      case "agent_user_interaction_evidence":
        sendResponse(await handleUserInteractionEvidence(message, sender));
        break;
      case "local_status":
        sendResponse(await localStatusPayload());
        break;
      case "local_page_health":
        sendResponse(await localPageHealth());
        break;
      case "local_debug_capture":
        sendResponse(await localDebugCapture(Boolean(message.reload)));
        break;
      case "local_bug_report":
        sendResponse(await buildLocalBugReport(Boolean(message.includeScreenshot)));
        break;
      case "local_responsive_matrix":
        sendResponse(await localResponsiveMatrix());
        break;
      case "local_site_scan":
        sendResponse(await localSiteScan(Number(message.limit || 8)));
        break;
      case "local_inspector_start":
        sendResponse(await startLocalInspector());
        break;
      case "local_inspector_result":
        sendResponse(await receiveLocalInspectorResult(message.result, sender));
        break;
      case "local_recorder_start":
        sendResponse(await startLocalRecorder(message.name));
        break;
      case "local_recorder_stop":
        sendResponse(await stopLocalRecorder(message.name));
        break;
      case "local_recorder_event":
        sendResponse(await receiveLocalRecorderEvent(message, sender));
        break;
      case "local_recorder_pointer":
        sendResponse(await receiveLocalRecorderPointer(message, sender));
        break;
      case "local_recorder_narration":
        sendResponse(await receiveLocalRecorderNarration(message.text, sender));
        break;
      case "local_workflow_list":
        sendResponse({ ok: true, workflows: await getLocalWorkflows() });
        break;
      case "local_workflow_delete":
        sendResponse(await deleteLocalWorkflow(message.id));
        break;
      case "local_workflow_run":
        sendResponse(await runLocalWorkflow(message.id));
        break;
      case "local_workflow_import":
        sendResponse(await importLocalWorkflow(message.workflow));
        break;
      case "local_workspace_save":
        sendResponse(await saveLocalWorkspace(message.name));
        break;
      case "local_workspace_list":
        sendResponse({ ok: true, workspaces: await getLocalWorkspaces() });
        break;
      case "local_workspace_restore":
        sendResponse(await restoreLocalWorkspace(message.id));
        break;
      case "local_workspace_delete":
        sendResponse(await deleteLocalWorkspace(message.id));
        break;
      case "local_baseline_capture":
        sendResponse(await captureLocalBaseline(message.name));
        break;
      case "local_baseline_compare":
        sendResponse(await compareLocalBaseline(message.id));
        break;
      case "local_schedule_upsert":
        sendResponse(await upsertLocalSchedule(message.schedule));
        break;
      case "local_schedule_list":
        sendResponse({ ok: true, schedules: await getLocalSchedules(), results: await getLocalScheduledResults() });
        break;
      case "local_schedule_delete":
        sendResponse(await deleteLocalSchedule(message.id));
        break;
      case "local_set_origin_profile":
        sendResponse(await setLocalOriginProfile(message.origin, message.mode));
        break;
      case "local_cancel":
        sendResponse(await cancelLocalExecution());
        break;
      case "local_export_state":
        sendResponse(await exportLocalStudioState());
        break;
      case "warm_index":
        sendResponse({ ok: true, deprecated: true, executorProtocolVersion: EXECUTOR_PROTOCOL_VERSION, runtimeOperationCount: RUNTIME_CONTRACT_ACTIONS.length });
        break;
      default:
        sendResponse({ ok: false, error: "unknown_message" });
    }
  })().catch((error) => sendResponse({ ok: false, error: serializeError(error) }));
  return true;
});

async function inheritOwnedPopup(tab) {
  const tabId = Number(tab?.id || 0);
  const openerTabId = Number(tab?.openerTabId || 0);
  const url = String(tab?.url || "");
  if (!tabId || !openerTabId || !/^https?:/i.test(url) || isRestrictedLocalUrl(url)) return null;
  if (await ownedTab(tabId).catch(() => null)) return ownedTab(tabId);
  const opener = await ownedTab(openerTabId).catch(() => null);
  if (!opener) return null;
  const origin = (() => { try { return new URL(url).origin; } catch { return ""; } })();
  const record = await registerOwnedTab(tabId, { taskId: opener.taskId || "", expectedOrigin: origin, url, laneId: opener.laneId || "" });
  await updateOwnedTab(tabId, { affinityReason: "popup_inherited_from_owned_opener", inheritedFromTabId: openerTabId });
  await appendLog("tab.popup_ownership_inherited", { tabId, openerTabId, taskId: opener.taskId || "", laneId: opener.laneId || "", origin }).catch(() => {});
  return record;
}

chrome.tabs.onCreated.addListener((tab) => { inheritOwnedPopup(tab).catch((error) => appendLog("tab.popup_inherit_failed", { tabId: tab?.id || null, openerTabId: tab?.openerTabId || null, error: serializeError(error) })); });

async function handleTabReplacement(addedTabId, removedTabId) {
  const addedId = Number(addedTabId || 0);
  const removedId = Number(removedTabId || 0);
  if (!addedId || !removedId || addedId === removedId) return { migrated: false, reason: "invalid_ids" };
  const registry = await getTabRegistry();
  const previous = registry[String(removedId)] || null;
  if (!previous) return { migrated: false, reason: "unowned_replaced_tab" };
  const addedTab = await chrome.tabs.get(addedId).catch(() => null);
  if (!addedTab) return { migrated: false, reason: "replacement_tab_missing" };
  const candidate = candidateTabUrl(addedTab, previous.url || "");
  const validTarget = /^https?:/i.test(candidate)
    && !isRestrictedLocalUrl(candidate)
    && Boolean(previous.ownershipNonce);
  if (!validTarget) {
    delete registry[String(removedId)];
    await saveTabRegistry(registry);
    await clearTabAffinityForTab(removedId).catch(() => {});
    await appendLog("tab.replacement_rejected", { addedTabId: addedId, removedTabId: removedId, url: candidate, adoptedExternal: Boolean(previous.adoptedExternal) }).catch(() => {});
    return { migrated: false, reason: "replacement_target_not_ownable" };
  }

  const [affinity, activeTask, runtimeSessions] = await Promise.all([
    getTabAffinity(), getActiveTask(), getRuntimeSessions(),
  ]);
  const migrated = migrateTabReplacementState({
    registry, affinityTasks: affinity.tasks, lastTabId: affinity.lastTabId, activeTask, runtimeSessions,
  }, addedTab, removedId);
  if (!migrated) return { migrated: false, reason: "replacement_state_migration_failed" };

  await saveTabRegistry(migrated.registry);
  const affinityPatch = { [STORAGE_KEYS.TAB_AFFINITY]: migrated.affinityTasks };
  if (Number(affinity.lastTabId || 0) === removedId) affinityPatch[STORAGE_KEYS.LAST_AGENT_TAB] = { tabId: addedId, at: Date.now(), reason: "tab_replaced" };
  await chrome.storage.local.set(affinityPatch);
  if (activeTask && Number(activeTask.tabId || 0) === removedId) await saveActiveTask(migrated.activeTask);
  await saveRuntimeSessions(migrated.runtimeSessions);

  networkBuffers.delete(removedId); consoleBuffers.delete(removedId); cdpEventBuffers.delete(removedId);
  traceBuffers.delete(removedId); screencastBuffers.delete(removedId); downloadBuffers.delete(removedId);
  structuredNetworkPayloads.delete(removedId); routeRules.delete(removedId);
  gscCollectionGenerations.delete(removedId); gscRequestGenerations.delete(removedId);
  debuggerProtocolByTab.delete(removedId); intentionalDebuggerDetaches.delete(removedId); automationInputUntilByTab.delete(removedId); semanticTargetCache.delete(removedId); pageRuntimePorts.delete(removedId); pageRuntimeFrames.delete(removedId); cdpChildSessionsByTab.delete(removedId); cdpAutoAttachTabs.delete(removedId);
  const interactionTimer = pendingUserInteractionTimers.get(removedId);
  if (interactionTimer) clearTimeout(interactionTimer);
  pendingUserInteractionTimers.delete(removedId);
  await installHumanInteractionProbe(addedId).catch(() => {});
  await appendLog("tab.ownership_replaced", { addedTabId: addedId, removedTabId: removedId, taskId: migrated.record.taskId || "", laneId: migrated.record.laneId || "", runtimeSessionsInterrupted: Object.values(migrated.runtimeSessions).filter((row) => Number(row?.tabId || 0) === addedId && row?.interruptedByTabReplacement).length }).catch(() => {});
  return { migrated: true, addedTabId: addedId, removedTabId: removedId };
}

chrome.tabs.onReplaced.addListener((addedTabId, removedTabId) => {
  handleTabReplacement(addedTabId, removedTabId).catch((error) => appendLog("tab.replacement_failed", { addedTabId, removedTabId, error: serializeError(error) }));
});

chrome.tabs.onRemoved.addListener(async (tabId) => {
  await forceCloseRuntimeSessions(tabId).catch(() => {});
  networkBuffers.delete(Number(tabId)); consoleBuffers.delete(Number(tabId)); cdpEventBuffers.delete(Number(tabId));
  traceBuffers.delete(Number(tabId)); screencastBuffers.delete(Number(tabId)); downloadBuffers.delete(Number(tabId));
  structuredNetworkPayloads.delete(Number(tabId)); routeRules.delete(Number(tabId));
  const registry = await getTabRegistry();
  const record = registry[String(Number(tabId))] || null;
  if (!record) return;
  await unregisterOwnedTab(tabId);
  await clearTabAffinityForTab(tabId).catch(() => {});
  const active = await getActiveTask();
  const reason = interruptionReason("tab_removed", active, { tabId });
  if (reason && active?.leaseToken) {
    // A physically removed tab cannot be resumed. Abort the local execution,
    // release ACTIVE immediately, and terminalize the durable child remotely.
    if (taskAbortController) taskAbortController.abort("tab_removed");
    taskExecutionGeneration += 1;
    stopHeartbeat();
    const taskId = String(active.taskId || "");
    const leaseToken = active.leaseToken;
    const evidence = { ...active, leaseToken: null, phase: "ownership_lost", ownershipLostAt: Date.now(), ownershipLossReason: reason, tabId: Number(tabId) };
    await clearActiveTask();
    let remoteFailureDeferred = false;
    if (taskId && leaseToken) {
      try {
        await api(`/tasks/${encodeURIComponent(taskId)}/fail`, {
          method: "POST",
          body: { lease_token: leaseToken, error: { code: "CONTROLLED_TAB_CLOSED", message: "The controlled tab was closed and the operation can no longer execute.", evidence } },
          timeoutMs: 10_000,
        });
      } catch (error) {
        logError(error);
        remoteFailureDeferred = true;
      }
    }
    await appendLog("task.technical_error.tab_closed", { taskId, tabId: Number(tabId), remoteFailureDeferred }).catch(() => {});
    await setBadge(remoteFailureDeferred ? "RETRY" : "ERR", remoteFailureDeferred ? "#6f42c1" : "#a32020").catch(() => {});
  }
});

chrome.windows.onRemoved.addListener(async (windowId) => {
  const stored = (await chrome.storage.local.get(STORAGE_KEYS.AGENT_WINDOW))?.[STORAGE_KEYS.AGENT_WINDOW];
  if (Number(stored?.windowId || stored || 0) === Number(windowId)) {
    await chrome.storage.local.remove(STORAGE_KEYS.AGENT_WINDOW);
    await appendLog("browser_host_window.removed", { windowId }).catch(() => {});
  }
});

chrome.windows.onFocusChanged.addListener(async (windowId) => {
  if (windowId === chrome.windows.WINDOW_ID_NONE) return;
  const [focusedTab] = await chrome.tabs.query({ active: true, windowId: Number(windowId) }).catch(() => []);
  if (!focusedTab?.id || !await ownedTab(focusedTab.id).catch(() => null)) return;
  await installHumanInteractionProbe(focusedTab.id).catch(() => {});
  await appendLog("controlled_tab.focus_observed", { windowId, tabId: focusedTab.id }).catch(() => {});
});

chrome.tabs.onActivated.addListener(async ({ tabId, windowId }) => {
  const recorder=(await chrome.storage.local.get(STORAGE_KEYS.LOCAL_RECORDER).catch(()=>({})))?.[STORAGE_KEYS.LOCAL_RECORDER];
  if(recorder?.active&&Number(recorder.windowId||0)===Number(windowId||0)){ await chrome.scripting.executeScript({target:{tabId,allFrames:true},func:installLocalRecorder,args:[recorder.sessionId]}).catch(()=>{}); }
  if (!await ownedTab(tabId).catch(() => null)) return;
  await installHumanInteractionProbe(tabId).catch(() => {});
  await appendLog("controlled_tab.activation_observed", { windowId, tabId }).catch(() => {});
});

chrome.tabs.onUpdated.addListener(async (tabId, changeInfo, tab) => {
  const recorder=(await chrome.storage.local.get(STORAGE_KEYS.LOCAL_RECORDER).catch(()=>({})))?.[STORAGE_KEYS.LOCAL_RECORDER];
  if(recorder?.active&&Number(recorder.windowId||0)===Number(tab?.windowId||0)&&changeInfo.url){
    recorder.tabKeys=recorder.tabKeys||{};if(!recorder.tabKeys[String(tabId)])recorder.tabKeys[String(tabId)]=`tab_${Number(recorder.nextTabKey||2)}`,recorder.nextTabKey=Number(recorder.nextTabKey||2)+1;
    recorder.steps=[...(recorder.steps||[]),{...normalizeWorkflowStep({type:"navigate",url:changeInfo.url,label:"Navigazione registrata"}),tabKey:recorder.tabKeys[String(tabId)],recordedAt:Date.now()}].slice(-200);recorder.updatedAt=Date.now();await chrome.storage.local.set({[STORAGE_KEYS.LOCAL_RECORDER]:recorder});
  }
  let record = await ownedTab(tabId);
  if (!record && tab?.openerTabId && /^https?:/i.test(String(changeInfo.url || tab.url || ""))) {
    record = await inheritOwnedPopup({ ...tab, id: tabId, url: changeInfo.url || tab.url || "" }).catch(() => null);
  }
  if (!record) return;
  await updateOwnedTab(tabId, { url: changeInfo.url || tab.url || record.url, title: tab.title || record.title || "", windowId: tab.windowId });
  if (changeInfo.url && (gscCollectionGenerations.has(Number(tabId)) || /(^|\.)search\.google\.com$/i.test((() => { try { return new URL(changeInfo.url).hostname; } catch { return ""; } })()))) {
    structuredNetworkPayloads.delete(Number(tabId));
    gscCollectionGenerations.delete(Number(tabId));
    gscRequestGenerations.delete(Number(tabId));
  }
  if (changeInfo.status === "complete") await installHumanInteractionProbe(tabId).catch(() => {});
});

chrome.debugger.onDetach.addListener(async (source, reason) => {
  debuggerProtocolByTab.delete(Number(source.tabId || 0));
  cdpChildSessionsByTab.delete(Number(source.tabId || 0));
  cdpAutoAttachTabs.delete(Number(source.tabId || 0));
  const intentional = consumeIntentionalDebuggerDetach(source.tabId);
  if (intentional) {
    await appendLog("debugger.intentional_detach", { tabId: source.tabId, reason, intent: intentional.reason }).catch(() => {});
    return;
  }
  const active = await getActiveTask();
  if (active?.intentionalDetach && Number(active?.tabId || 0) === Number(source.tabId || 0)) return;
  if (active?.leaseToken && Number(active?.tabId || 0) === Number(source.tabId || 0)) {
    await appendLog("debugger.unexpected_detach_recovery", { tabId: source.tabId, reason, taskId: active.taskId, technicalRecovery: true }).catch(() => {});
    debuggerProtocolByTab.delete(Number(source.tabId || 0));
    // Keep ownership/task alive; the next CDP operation re-attaches through the normal hot-path recovery.
  }
});

chrome.debugger.onEvent.addListener((source, method, params) => {
  if (!source.tabId) return;
  const tabId = Number(source.tabId || 0);
  if (method === "Target.attachedToTarget" && params?.sessionId) {
    let sessions = cdpChildSessionsByTab.get(tabId);
    if (!sessions) { sessions = new Map(); cdpChildSessionsByTab.set(tabId, sessions); }
    sessions.set(String(params.sessionId), {
      sessionId: String(params.sessionId),
      parentSessionId: String(source.sessionId || ""),
      targetId: String(params.targetInfo?.targetId || ""),
      type: String(params.targetInfo?.type || ""),
      url: String(params.targetInfo?.url || ""),
      attachedAt: Date.now(),
    });
    // Auto-attach is not recursive by itself: arm the child immediately so A→B→C is covered.
    armFrameAutoAttach(tabId, String(params.sessionId)).catch(() => {});
  } else if (method === "Target.detachedFromTarget" && params?.sessionId) {
    cdpChildSessionsByTab.get(tabId)?.delete(String(params.sessionId));
  }
  const at = Date.now();
  const isNetworkEvent = ["Network.requestWillBeSent", "Network.responseReceived", "Network.loadingFinished", "Network.loadingFailed"].includes(method);
  const isConsoleEvent = method === "Log.entryAdded" || method === "Runtime.consoleAPICalled" || method === "Runtime.exceptionThrown";
  const isDownloadEvent = ["Browser.downloadWillBegin", "Browser.downloadProgress", "Page.downloadWillBegin"].includes(method);
  // High-volume domains have dedicated bounded buffers; do not duplicate them in the generic CDP buffer.
  if (!isNetworkEvent && !isConsoleEvent && !isDownloadEvent) pushBuffer(cdpEventBuffers, source.tabId, { method, params, at }, 2000);
  if (isNetworkEvent) {
    pushBuffer(networkBuffers, source.tabId, { method, params, at }, 3000);
  }
  if (method === "Network.requestWillBeSent" && params?.requestId) {
    const generation = gscCollectionGenerations.get(Number(source.tabId));
    if (generation?.generationId) {
      const requests = gscRequestGenerations.get(Number(source.tabId)) || new Map();
      requests.set(String(params.requestId), generation.generationId);
      if (requests.size > 2_000) requests.delete(requests.keys().next().value);
      gscRequestGenerations.set(Number(source.tabId), requests);
    }
  }
  if (method === "Network.loadingFinished") {
    captureStructuredNetworkPayload(source.tabId, params?.requestId).catch(() => {});
  }
  if (isConsoleEvent) {
    pushBuffer(consoleBuffers, source.tabId, { method, params, at }, 2000);
  }
  if (method === "Tracing.dataCollected") {
    const current = traceBuffers.get(source.tabId) || [];
    current.push(...(params?.value || []));
    traceBuffers.set(source.tabId, trimArrayByApproxBytes(current, 10_000, TRACE_BUFFER_MAX_BYTES));
  }
  if (method === "Page.screencastFrame") {
    const frames = screencastBuffers.get(source.tabId) || [];
    frames.push({ data: params?.data || "", metadata: params?.metadata || {}, sessionId: params?.sessionId, at });
    screencastBuffers.set(source.tabId, trimArrayByApproxBytes(
      frames, 60, SCREENCAST_BUFFER_MAX_BYTES,
      (frame) => Math.ceil(String(frame?.data || "").length * 0.75) + 512,
    ));
    cdp(source.tabId, "Page.screencastFrameAck", { sessionId: params.sessionId }).catch(() => {});
  }
  if (isDownloadEvent) {
    pushBuffer(downloadBuffers, source.tabId, { method, params, at }, 500);
  }
  if (method === "WebMCP.toolsAdded") {
    const current = webMcpToolsByTab.get(tabId) || new Map();
    for (const tool of params?.tools || []) if (tool?.name) current.set(String(tool.name), { ...tool, frameId: String(params?.frameId || tool?.frameId || "") });
    webMcpToolsByTab.set(tabId, current);
  } else if (method === "WebMCP.toolsRemoved") {
    const current = webMcpToolsByTab.get(tabId) || new Map();
    for (const name of params?.toolNames || params?.names || []) current.delete(String(name));
    webMcpToolsByTab.set(tabId, current);
  } else if (method === "WebMCP.toolResponded" && params?.invocationId) {
    webMcpInvocationResults.set(`${tabId}:${String(params.invocationId)}`, { ...params, at });
  }
  if (method === "Fetch.requestPaused") {
    handlePausedRequest(source.tabId, params).catch((error) => appendLog("fetch.route.error", serializeError(error)));
  }
});

function markAutomationInput(tabId, durationMs = AUTOMATION_INPUT_SUPPRESSION_MS) {
  const id = Number(tabId || 0);
  if (id) automationInputUntilByTab.set(id, Date.now() + Math.max(250, Number(durationMs || 0)));
}

function markIntentionalDebuggerDetach(tabId, reason = "runtime_cleanup") {
  const id = Number(tabId || 0);
  if (!id) return;
  intentionalDebuggerDetaches.set(id, { reason: String(reason || "runtime_cleanup"), expiresAt: Date.now() + INTENTIONAL_DETACH_TTL_MS });
}

function consumeIntentionalDebuggerDetach(tabId) {
  const id = Number(tabId || 0);
  const record = intentionalDebuggerDetaches.get(id) || null;
  intentionalDebuggerDetaches.delete(id);
  return record && Number(record.expiresAt || 0) >= Date.now() ? record : null;
}

async function installHumanInteractionProbe(tabId) {
  const id = Number(tabId || 0);
  if (!id || !(await ownedTab(id))) return { installed: false, reason: "tab_not_owned" };
  await chrome.scripting.executeScript({
    target: { tabId: id, allFrames: false },
    func: () => {
      if (globalThis.__prstudioHumanInteractionProbe) return;
      globalThis.__prstudioHumanInteractionProbe = true;
      let lastSentAt = 0;
      const sendEvidence = (event) => {
        if (!event?.isTrusted || document.visibilityState !== "visible") return;
        const now = Date.now();
        if (now - lastSentAt < 250) return;
        lastSentAt = now;
        chrome.runtime.sendMessage({
          type: "agent_user_interaction_evidence",
          eventType: String(event.type || "interaction"),
          observedAt: now,
        }).catch(() => {});
      };
      for (const type of ["pointerdown", "touchstart", "keydown"]) {
        addEventListener(type, sendEvidence, { capture: true, passive: true });
      }
    },
  });
  return { installed: true, tabId: id };
}

async function handleUserInteractionEvidence(message, sender) {
  const tabId = Number(sender?.tab?.id || 0);
  const observedAt = Number(message?.observedAt || 0);
  if (!tabId || !observedAt || Math.abs(Date.now() - observedAt) > 5_000) return { ok: false, ignored: "stale_or_unbound" };
  const record = await ownedTab(tabId);
  if (!record) return { ok: false, ignored: "tab_not_owned" };
  // Observer-only: focusing/clicking the controlled tab is observer telemetry only and never changes ownership or execution state.
  await appendLog("controlled_tab.user_interaction_observed", {
    tabId, eventType: String(message?.eventType || "interaction"), observedAt, observerOnly: true,
    automationInputWindow: Date.now() <= Number(automationInputUntilByTab.get(tabId) || 0),
  }).catch(() => {});
  return { ok: true, observerOnly: true };
}

// ---- PR STUDIO Local Studio -------------------------------------------------
// This lane is deliberately independent from WordPress leases/tasks. It may use
// only normal user tabs and never a tab currently owned by a remote Agent lane. All state required
// for crash recovery is persisted in chrome.storage.local.


async function appendLocalFlight(event, details = {}) {
  const stored = await chrome.storage.local.get(STORAGE_KEYS.LOCAL_FLIGHT);
  const rows = Array.isArray(stored[STORAGE_KEYS.LOCAL_FLIGHT]) ? stored[STORAGE_KEYS.LOCAL_FLIGHT] : [];
  const safe = redactObservation(details, { console: true, limits: { maxStringLength: 2000, maxArrayLength: 40, maxObjectKeys: 60 } }).value;
  rows.push({ at: Date.now(), event: String(event || "local.event"), details: safe });
  if (rows.length > 250) rows.splice(0, rows.length - 250);
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_FLIGHT]: rows });
}

async function getLocalFlight() {
  const value = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_FLIGHT))[STORAGE_KEYS.LOCAL_FLIGHT];
  return Array.isArray(value) ? value.slice(-120) : [];
}

async function localLaneContext({ executionContext = null } = {}) {
  if (executionContext?.remote) {
    const tab = await getEligibleLocalTab(executionContext);
    const profile = await getLocalOriginProfile(tab.url);
    return { tab, profile, remote: true, laneId: executionContext.laneId || "" };
  }
  const tab = await getEligibleLocalTab(executionContext);
  const profile = await getLocalOriginProfile(tab.url);
  return { tab, profile };
}

async function getEligibleLocalTab(executionContext = null) {
  if (executionContext?.remote) {
    const tabId = Number(executionContext.tabId || 0);
    if (!tabId) throw codedError("remote_local_tab_required", "La funzione Local Studio remota richiede una scheda esplicitamente adottata/posseduta.");
    const record = await assertOwnedTab(tabId);
    const tab = await chrome.tabs.get(tabId);
    if (record.laneId && executionContext.laneId && record.laneId !== executionContext.laneId) {
      throw codedError("remote_local_lane_mismatch", "La scheda Local Studio appartiene a un'altra lane ChatGPT.", { tabId, ownerLane: record.laneId, requestedLane: executionContext.laneId });
    }
    return tab;
  }
  const [tab] = await chrome.tabs.query({ active: true, lastFocusedWindow: true });
  if (!tab?.id) throw codedError("local_tab_missing", "Nessuna scheda attiva utilizzabile.");
  if (isRestrictedLocalUrl(tab.url)) throw codedError("local_tab_restricted", "Le pagine interne di Chrome/file non sono automatizzabili.");
  if (await ownedTab(tab.id)) throw codedError("local_owned_tab_forbidden", "La scheda appartiene a un task remoto.");
  return tab;
}

async function getLocalOriginProfile(url) {
  let origin = "";
  try { origin = new URL(String(url || "")).origin; } catch { return "automation"; }
  const stored = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_PROFILES))[STORAGE_KEYS.LOCAL_PROFILES] || {};
  return stored[origin] === "debug" ? "debug" : "automation";
}

async function setLocalOriginProfile(originInput, modeInput, executionContext = null) {
  const mode = normalizeOriginProfile(modeInput);
  let origin;
  try { origin = new URL(String(originInput || (await getEligibleLocalTab(executionContext)).url)).origin; }
  catch { throw codedError("local_origin_invalid", "Origine non valida."); }
  const stored = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_PROFILES))[STORAGE_KEYS.LOCAL_PROFILES] || {};
  stored[origin] = mode;
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_PROFILES]: stored });
  await appendLocalFlight("profile.updated", { origin, mode });
  return { ok: true, origin, mode };
}

async function localStatusPayload(executionContext = null) {
  const [workflows, workspaces, schedules, flight, recorderStored, inspectorStored, activeStored, profilesStored, baselinesStored, results] = await Promise.all([
    getLocalWorkflows(), getLocalWorkspaces(), getLocalSchedules(), getLocalFlight(),
    chrome.storage.local.get(STORAGE_KEYS.LOCAL_RECORDER), chrome.storage.local.get(STORAGE_KEYS.LOCAL_INSPECTOR),
    chrome.storage.local.get(STORAGE_KEYS.LOCAL_ACTIVE), chrome.storage.local.get(STORAGE_KEYS.LOCAL_PROFILES),
    chrome.storage.local.get(STORAGE_KEYS.LOCAL_BASELINES), getLocalScheduledResults(),
  ]);
  let tab = null;
  try { tab = await getEligibleLocalTab(executionContext); } catch (error) { tab = { unavailable: true, error: serializeError(error) }; }
  const origin = tab?.url && !tab.unavailable ? (() => { try { return new URL(tab.url).origin; } catch { return ""; } })() : "";
  const recorder = recorderStored[STORAGE_KEYS.LOCAL_RECORDER] || null;
  const inspector = inspectorStored[STORAGE_KEYS.LOCAL_INSPECTOR] || null;
  const active = activeStored[STORAGE_KEYS.LOCAL_ACTIVE] || null;
  const baselines = baselinesStored[STORAGE_KEYS.LOCAL_BASELINES] || {};
  return {
    ok: true,
    version: LOCAL_STUDIO_VERSION,
    features: [...LOCAL_STUDIO_FEATURES],
    advertisement: featureAdvertisement(),
    tab: tab?.unavailable ? tab : { id: tab?.id || null, windowId: tab?.windowId || null, url: tab?.url || "", title: tab?.title || "" },
    origin,
    originProfile: origin ? ((profilesStored[STORAGE_KEYS.LOCAL_PROFILES] || {})[origin] || "automation") : "automation",
    recorder: recorder ? { active: true, sessionId: recorder.sessionId, name: recorder.name, stepCount: recorder.steps?.length || 0, tabId: recorder.tabId } : { active: false },
    inspector: inspector || null,
    active,
    recoveryRequired: Boolean(active?.recoveryRequired),
    workflowCount: workflows.length,
    workflows,
    workspaceCount: workspaces.length,
    workspaces,
    scheduleCount: schedules.length,
    schedules,
    scheduledResults: results.slice(-10),
    baselineCount: Object.keys(baselines).length,
    baselines: Object.values(baselines).map((item) => ({ id: item.id, name: item.name, url: item.url, createdAt: item.createdAt })).sort((a, b) => Number(b.createdAt || 0) - Number(a.createdAt || 0)),
    flight,
  };
}

async function localPageHealthForTab(tabId) {
  const rows = await chrome.scripting.executeScript({ target: { tabId, allFrames: false }, func: collectLocalPageHealth });
  const result = rows?.[0]?.result || null;
  if (!result) throw codedError("local_health_unavailable", "Audit pagina non disponibile.");
  result.score = pageHealthScore(result);
  return result;
}

async function localPageHealth(executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  const result = await localPageHealthForTab(tab.id);
  await appendLocalFlight("health.completed", { tabId: tab.id, url: result.url, score: result.score });
  return { ok: true, result };
}

async function localSemanticSnapshotForTab(tabId) {
  const rows = await chrome.scripting.executeScript({ target: { tabId, allFrames: false }, func: collectLocalSemanticSnapshot });
  const raw = rows?.[0]?.result || null;
  if (!raw) throw codedError("local_semantic_snapshot_unavailable", "Snapshot semantico non disponibile.");
  const safe = redactObservation(raw, { limits: { maxStringLength: 120000, maxArrayLength: 600, maxObjectKeys: 100 } }).value;
  const structure = JSON.stringify({ headings: safe.headings || [], links: safe.links || [], controls: safe.controls || [], landmarks: safe.landmarks || [], counts: safe.counts || {} });
  return { ...safe, textSha256: await sha256Text(safe.text || ""), structureSha256: await sha256Text(structure) };
}

async function startLocalInspector(executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  await chrome.scripting.executeScript({ target: { tabId: tab.id, allFrames: false }, func: installLocalInspector });
  const state = { active: true, tabId: tab.id, url: tab.url || "", startedAt: Date.now(), result: null };
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_INSPECTOR]: state });
  await appendLocalFlight("inspector.started", { tabId: tab.id, url: tab.url });
  return { ok: true, state };
}

async function receiveLocalInspectorResult(result, sender) {
  const stored = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_INSPECTOR))[STORAGE_KEYS.LOCAL_INSPECTOR];
  if (!stored?.active || !sender?.tab?.id || Number(sender.tab.id) !== Number(stored.tabId)) return { ok: false, error: "local_inspector_sender_mismatch" };
  const safe = redactObservation(result, { limits: { maxStringLength: 3000, maxArrayLength: 50, maxObjectKeys: 80 } }).value;
  const state = { ...stored, active: false, completedAt: Date.now(), result: safe };
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_INSPECTOR]: state });
  await appendLocalFlight("inspector.completed", { tabId: stored.tabId, locator: safe?.locator || {} });
  return { ok: true };
}

async function startLocalRecorder(nameInput = "", executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  const existing = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_RECORDER))[STORAGE_KEYS.LOCAL_RECORDER];
  if (existing?.active) throw codedError("local_recorder_already_active", "È già attiva una registrazione locale.");
  // Fall back to getRandomValues rather than Math.random. The fallback should
  // never fire -- randomUUID exists in every Chrome this extension supports --
  // but a predictable session id is not the thing to degrade to when it does,
  // and getRandomValues is available wherever randomUUID is not.
  const sessionId = `rec_${crypto.randomUUID?.() || Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) => b.toString(16).padStart(2, "0")).join("")}_${Date.now().toString(36)}`;
  const recorder = {
    active: true,
    sessionId,
    tabId: tab.id,
    windowId: tab.windowId,
    name: String(nameInput || tab.title || "Workflow locale").replace(/\s+/g, " ").trim().slice(0, 160),
    startedAt: Date.now(),
    tabKeys: { [String(tab.id)]: "tab_1" }, nextTabKey: 2,
    tracks: { pointer: [], visual: [], narration: [], semantic: [], effects: [] },
    steps: [{ ...normalizeWorkflowStep({ type: "navigate", url: tab.url, label: "Apri pagina iniziale" }), tabKey: "tab_1" }],
  };
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_RECORDER]: recorder });
  await chrome.scripting.executeScript({ target: { tabId: tab.id, allFrames: true }, func: installLocalRecorder, args: [sessionId] }).catch(() => {});
  await localDebuggerAttach(tab.id).then(() => localDebuggerCommand(tab.id, "Page.startScreencast", { format: "jpeg", quality: 55, maxWidth: 1280, maxHeight: 900, everyNthFrame: 2 })).catch(() => {});
  await appendLocalFlight("recorder.started", { sessionId, tabId: tab.id, url: tab.url, tracks: ["behavior","pointer","visual","narration","semantic","application_effect"] });
  return { ok: true, recorder: { sessionId, tabId: tab.id, stepCount: 1 } };
}

async function receiveLocalRecorderEvent(message, sender) {
  const recorder = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_RECORDER))[STORAGE_KEYS.LOCAL_RECORDER];
  if (!recorder?.active || message.sessionId !== recorder.sessionId || !sender?.tab?.id || Number(sender.tab.windowId || 0) !== Number(recorder.windowId || 0)) {
    return { ok: false, error: "local_recorder_sender_mismatch" };
  }
  const senderTabId=Number(sender.tab.id); recorder.tabKeys=recorder.tabKeys||{}; if(!recorder.tabKeys[String(senderTabId)]) recorder.tabKeys[String(senderTabId)]=`tab_${Number(recorder.nextTabKey||2)}`, recorder.nextTabKey=Number(recorder.nextTabKey||2)+1;
  const tabKey=recorder.tabKeys[String(senderTabId)];
  const beforeFrame=(screencastBuffers.get(senderTabId)||[]).at(-1)||null;
  const step = { ...normalizeWorkflowStep(message.step || {}), tabKey, frameId:Number(sender?.frameId||0), beforeVisualHash: beforeFrame?.data ? await sha256Text(beforeFrame.data) : "" };
  const steps = Array.isArray(recorder.steps) ? recorder.steps : [];
  if (steps.length >= 200) {
    recorder.limitReached = true;
    await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_RECORDER]: recorder });
    return { ok: false, error: "local_recorder_step_limit" };
  }
  const previous = steps.at(-1);
  const fingerprint = JSON.stringify({ type: step.type, locator: step.locator, value: step.value, checked: step.checked, key: step.key });
  const previousFingerprint = previous ? JSON.stringify({ type: previous.type, locator: previous.locator, value: previous.value, checked: previous.checked, key: previous.key }) : "";
  if (fingerprint !== previousFingerprint || Date.now() - Number(previous?.recordedAt || 0) > 500) steps.push(step);
  await abortableSleep(120, taskAbortController?.signal).catch(() => {});
  const afterFrame=(screencastBuffers.get(senderTabId)||[]).at(-1)||null;
  if(steps.length){ const last=steps[steps.length-1]; last.afterVisualHash=afterFrame?.data?await sha256Text(afterFrame.data):""; }
  recorder.tracks=recorder.tracks||{pointer:[],visual:[],narration:[],semantic:[],effects:[]};
  recorder.tracks.semantic.push({tabKey,index:steps.length-1,locator:step.locator||{},at:Date.now()});
  recorder.tracks.visual.push({tabKey,index:steps.length-1,before:step.beforeVisualHash||"",after:steps.at(-1)?.afterVisualHash||""});
  recorder.steps = steps; recorder.updatedAt = Date.now();
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_RECORDER]: recorder });
  await appendLocalFlight("recorder.step", { sessionId: recorder.sessionId, index: steps.length - 1, type: step.type, label: step.label, valuePolicy: step.valuePolicy || null });
  return { ok: true, stepCount: steps.length };
}

async function receiveLocalRecorderPointer(message, sender) {
  const recorder=(await chrome.storage.local.get(STORAGE_KEYS.LOCAL_RECORDER))[STORAGE_KEYS.LOCAL_RECORDER];
  if(!recorder?.active||message.sessionId!==recorder.sessionId||Number(sender?.tab?.windowId||0)!==Number(recorder.windowId||0))return{ok:false,ignored:true};
  recorder.tracks=recorder.tracks||{pointer:[],visual:[],narration:[],semantic:[],effects:[]};
  const rows=recorder.tracks.pointer||[];rows.push({...message.point,tabId:Number(sender?.tab?.id||0)});recorder.tracks.pointer=rows.slice(-3000);await chrome.storage.local.set({[STORAGE_KEYS.LOCAL_RECORDER]:recorder});return{ok:true};
}
async function receiveLocalRecorderNarration(text, sender) {
  const recorder=(await chrome.storage.local.get(STORAGE_KEYS.LOCAL_RECORDER))[STORAGE_KEYS.LOCAL_RECORDER];
  if(!recorder?.active)return{ok:false,error:"local_recorder_not_active"};
  recorder.tracks=recorder.tracks||{pointer:[],visual:[],narration:[],semantic:[],effects:[]};
  recorder.tracks.narration.push({text:String(text||"").replace(/\s+/g," ").trim().slice(0,2000),at:Date.now(),tabId:Number(sender?.tab?.id||0)});recorder.tracks.narration=recorder.tracks.narration.slice(-200);await chrome.storage.local.set({[STORAGE_KEYS.LOCAL_RECORDER]:recorder});return{ok:true};
}

async function stopLocalRecorder(nameInput = "") {
  const recorder = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_RECORDER))[STORAGE_KEYS.LOCAL_RECORDER];
  if (!recorder?.active) return { ok: false, error: "local_recorder_not_active" };
  for (const tabId of Object.keys(recorder.tabKeys || { [String(recorder.tabId)]: "tab_1" }).map(Number)) {
    await chrome.scripting.executeScript({ target: { tabId, allFrames: true }, func: uninstallLocalRecorder }).catch(() => {});
    await localDebuggerCommand(tabId, "Page.stopScreencast", {}).catch(() => {});
    await chrome.debugger.detach({ tabId }).catch(() => {});
  }
  const workflow = validateLocalWorkflow({ name: nameInput || recorder.name, steps: recorder.steps || [], createdAt: recorder.startedAt });
  const workflows = await getLocalWorkflows();
  workflows.unshift(workflow);
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_WORKFLOWS]: workflows.slice(0, 100) });
  await chrome.storage.local.remove(STORAGE_KEYS.LOCAL_RECORDER);
  await appendLocalFlight("recorder.saved", { workflowId: workflow.id, name: workflow.name, stepCount: workflow.steps.length });
  return { ok: true, workflow };
}

async function getLocalWorkflows() {
  const value = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_WORKFLOWS))[STORAGE_KEYS.LOCAL_WORKFLOWS];
  return Array.isArray(value) ? value.slice(0, 100) : [];
}

async function importLocalWorkflow(workflowInput) {
  const workflow = validateLocalWorkflow(workflowInput || {});
  const workflows = (await getLocalWorkflows()).filter((item) => item.id !== workflow.id);
  workflows.unshift(workflow);
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_WORKFLOWS]: workflows.slice(0, 100) });
  await appendLocalFlight("workflow.imported", { id: workflow.id, name: workflow.name, stepCount: workflow.steps.length });
  return { ok: true, workflow };
}

async function deleteLocalWorkflow(idInput) {
  const id = String(idInput || "");
  const workflows = await getLocalWorkflows();
  const next = workflows.filter((item) => item.id !== id);
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_WORKFLOWS]: next });
  await appendLocalFlight("workflow.deleted", { id, removed: next.length !== workflows.length });
  return { ok: true, removed: next.length !== workflows.length };
}

function waitForLocalTab(tabId, timeoutMs = 45000) {
  return new Promise((resolve, reject) => {
    let done = false;
    const finish = (error, tab) => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      chrome.tabs.onUpdated.removeListener(listener);
      if (error) reject(error); else resolve(tab);
    };
    const listener = (updatedId, changeInfo, tab) => {
      if (Number(updatedId) === Number(tabId) && changeInfo.status === "complete") finish(null, tab);
    };
    const timer = setTimeout(() => finish(codedError("local_navigation_timeout", "Timeout navigazione locale.")), timeoutMs);
    chrome.tabs.onUpdated.addListener(listener);
    chrome.tabs.get(tabId).then((tab) => { if (tab.status === "complete") finish(null, tab); }).catch((error) => finish(error));
  });
}

async function dispatchLocalNativeCommands(tabId, commands=[]) {
  await localDebuggerAttach(tabId);
  let dispatched=0; for(const command of commands){await localDebuggerCommand(tabId,command.method,command.params||{});dispatched+=1;if(command.delayMs)await sleep(command.delayMs);} return{executed:true,dispatched,transport:"local_hot_cdp"};
}

async function runLocalWorkflow(idInput, executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  const workflows = await getLocalWorkflows();
  const workflow = workflows.find((item) => item.id === String(idInput || ""));
  if (!workflow) return { ok: false, error: "local_workflow_missing" };
  const validated = validateLocalWorkflow(workflow);
  const generation = ++localExecutionGeneration;
  const executionId = `local_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`;
  await appendLocalFlight("workflow.started", { executionId, workflowId: validated.id, tabId: tab.id, stepCount: validated.steps.length });
  for (let index = 0; index < validated.steps.length; index += 1) {
    if (generation !== localExecutionGeneration) return { ok: false, error: "local_execution_cancelled", executionId };
    const step = validated.steps[index];
    const mutating = localStepMutates(step);
    const state = { executionId, workflowId: validated.id, workflowName: validated.name, tabId: tab.id, stepIndex: index, step, phase: "in_flight", mutating, startedAt: Date.now(), recoveryRequired: false };
    await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_ACTIVE]: state });
    await appendLocalFlight("workflow.step.start", { executionId, index, type: step.type, label: step.label, mutating });
    let result;
    try {
      if (step.type === "navigate") {
        await chrome.tabs.update(tab.id, { url: step.url });
        await waitForLocalTab(tab.id);
        result = { ok: true, url: step.url };
      } else if (step.type === "wait") {
        await sleep(step.ms);
        result = { ok: true, waitedMs: step.ms };
      } else if (["click","fill","check","press"].includes(step.type)) {
        const rows=await chrome.scripting.executeScript({target:{tabId:tab.id,allFrames:false},func:resolveLocalWorkflowTarget,args:[step]});const target=rows?.[0]?.result||{ok:false,error:"local_target_no_result"};
        if(!target.ok||!target.hitPoint){result={ok:false,error:target.error||"safe_interaction_point_unavailable",requiresRegrounding:true,blocking:false};}
        else if(step.demonstrateOnly){result={ok:true,demonstrated:true,executed:false,hitPoint:target.hitPoint};}
        else {
          const point=target.hitPoint; let dispatched={executed:true,dispatched:0,transport:"local_hot_cdp"};
          if(step.type==="click"||step.type==="check")dispatched=await dispatchLocalNativeCommands(tab.id,pointerSequence([{type:"click",...point}]));
          else if(step.type==="press"){await dispatchLocalNativeCommands(tab.id,pointerSequence([{type:"click",...point}]));dispatched=await dispatchLocalNativeCommands(tab.id,keyboardSequence([{type:"press",key:String(step.key||"Enter")}]))}
          else if(step.type==="fill"){if(step.valuePolicy==="redacted"||step.value==null){result={ok:false,error:"local_value_parameter_required",requiresInputParameter:true,blocking:false};}else{await dispatchLocalNativeCommands(tab.id,pointerSequence([{type:"click",...point}]));const platform=await chrome.runtime.getPlatformInfo().catch(()=>({os:""}));const selectAll=String(platform.os||"").toLowerCase()==="mac"?"Meta+A":"Control+A";await dispatchLocalNativeCommands(tab.id,keyboardSequence([{type:"press",key:selectAll},{type:"press",key:"Backspace"}]));dispatched=await dispatchLocalNativeCommands(tab.id,keyboardSequence([{type:"text",text:String(step.value)}]));}}
          if(!result)result={ok:true,hitPoint:point,...dispatched};
        }
      } else {
        const rows = await chrome.scripting.executeScript({ target: { tabId: tab.id, allFrames: false }, func: executeLocalWorkflowStep, args: [step] });
        result = rows?.[0]?.result || { ok: false, error: "local_step_no_result" };
      }
      if (!result?.ok) {
        const uncertain = mutating && result?.error !== "local_target_not_found" && result?.error !== "local_value_redacted";
        await appendLocalFlight("workflow.step.failed_nonreplayable", { executionId, index, result, uncertain });
        await chrome.storage.local.remove(STORAGE_KEYS.LOCAL_ACTIVE);
        return { ok: false, error: result?.error || "local_step_failed", externalAuthOnly: Boolean(result?.requiresHumanInput), recoveryRequired: false, nonReplayable: uncertain, executionId, stepIndex: index };
      }
      if (generation !== localExecutionGeneration) {
        if (mutating) {
          const interrupted = { ...state, phase: "interrupted", recoveryRequired: false, reason: "local_stop_during_mutating_step", result, interruptedAt: Date.now() };
          await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_ACTIVE]: interrupted });
          await appendLocalFlight("workflow.step.interrupted_uncertain", { executionId, index, type: step.type });
          return { ok: false, error: "local_execution_cancelled", recoveryRequired: false, executionId, stepIndex: index };
        }
        await chrome.storage.local.remove(STORAGE_KEYS.LOCAL_ACTIVE);
        return { ok: false, error: "local_execution_cancelled", recoveryRequired: false, executionId, stepIndex: index };
      }
      await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_ACTIVE]: { ...state, phase: "completed_step", completedAt: Date.now(), result } });
      await appendLocalFlight("workflow.step.completed", { executionId, index, result });
    } catch (error) {
      const recoveryRequired = false;
      await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_ACTIVE]: { ...state, phase: "failed", recoveryRequired, reason: String(error?.message || error), error: serializeError(error) } });
      await appendLocalFlight("workflow.step.exception", { executionId, index, recoveryRequired, error: serializeError(error) });
      return { ok: false, error: serializeError(error), recoveryRequired, executionId, stepIndex: index };
    }
  }
  await chrome.storage.local.remove(STORAGE_KEYS.LOCAL_ACTIVE);
  await appendLocalFlight("workflow.completed", { executionId, workflowId: validated.id });
  return { ok: true, executionId, workflowId: validated.id, stepCount: validated.steps.length };
}

async function recoverLocalExecution() {
  const state = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_ACTIVE))[STORAGE_KEYS.LOCAL_ACTIVE];
  if (!state?.executionId) return { ok: true, action: "none" };
  const phase = String(state.phase || "");
  const ambiguousMutation = state.mutating === true && ["in_flight", "completed_step", "failed_nonreplayable"].includes(phase);
  await appendLocalFlight(ambiguousMutation ? "recovery.terminal_nonreplayable" : "recovery.auto_cleared", { executionId: state.executionId, stepIndex: state.stepIndex, type: state.step?.type, previousPhase: phase });
  await chrome.storage.local.remove(STORAGE_KEYS.LOCAL_ACTIVE);
  return { ok: true, action: ambiguousMutation ? "terminal_nonreplayable" : "auto_cleared", recoveryRequired: false, nonReplayable: ambiguousMutation };
}

async function cancelLocalExecution() {
  localExecutionGeneration += 1;
  const active = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_ACTIVE))[STORAGE_KEYS.LOCAL_ACTIVE] || null;
  if (active?.mutating && active?.phase === "in_flight") {
    await appendLocalFlight("execution.cancelled_nonreplayable", { executionId: active.executionId, stepIndex: active.stepIndex, type: active.step?.type });
    await chrome.storage.local.remove(STORAGE_KEYS.LOCAL_ACTIVE);
    return { ok: true, cancelled: true, recoveryRequired: false, nonReplayable: true };
  }
  await chrome.storage.local.remove(STORAGE_KEYS.LOCAL_ACTIVE);
  await appendLocalFlight("execution.cancelled", { executionId: active?.executionId || null });
  return { ok: true, cancelled: Boolean(active), recoveryRequired: false };
}

async function getLocalWorkspaces() {
  const value = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_WORKSPACES))[STORAGE_KEYS.LOCAL_WORKSPACES];
  return Array.isArray(value) ? value.slice(0, 30) : [];
}

async function saveLocalWorkspace(nameInput = "", executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  const tabs = await chrome.tabs.query({ windowId: tab.windowId });
  const items = tabs.filter((item) => !isRestrictedLocalUrl(item.url)).slice(0, 30).map((item) => ({ url: item.url || "", title: item.title || "", pinned: Boolean(item.pinned) }));
  if (!items.length) return { ok: false, error: "local_workspace_empty" };
  const workspace = { id: `ws_${Date.now().toString(36)}`, name: String(nameInput || `Workspace ${new Date().toLocaleDateString()}`).replace(/\s+/g, " ").trim().slice(0, 160), createdAt: Date.now(), tabs: items, localOnly: true };
  const workspaces = await getLocalWorkspaces();
  workspaces.unshift(workspace);
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_WORKSPACES]: workspaces.slice(0, 30) });
  await appendLocalFlight("workspace.saved", { id: workspace.id, tabCount: items.length });
  return { ok: true, workspace };
}

async function restoreLocalWorkspace(idInput, executionContext = null) {
  await localLaneContext({ executionContext });
  const workspace = (await getLocalWorkspaces()).find((item) => item.id === String(idInput || ""));
  if (!workspace) return { ok: false, error: "local_workspace_missing" };
  if (!workspace.tabs?.length) return { ok: false, error: "local_workspace_empty" };
  const created = await chrome.windows.create({ url: workspace.tabs[0].url, focused: true });
  for (const item of workspace.tabs.slice(1, 30)) await chrome.tabs.create({ windowId: created.id, url: item.url, active: false, pinned: Boolean(item.pinned) });
  await appendLocalFlight("workspace.restored", { id: workspace.id, windowId: created.id, tabCount: workspace.tabs.length });
  return { ok: true, windowId: created.id, tabCount: workspace.tabs.length };
}

async function deleteLocalWorkspace(idInput) {
  const id = String(idInput || "");
  const workspaces = await getLocalWorkspaces();
  const next = workspaces.filter((item) => item.id !== id);
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_WORKSPACES]: next });
  await appendLocalFlight("workspace.deleted", { id, removed: next.length !== workspaces.length });
  return { ok: true, removed: next.length !== workspaces.length };
}

async function captureExactVisibleTab(tab, timeoutMs = LOCAL_CAPTURE_TIMEOUT_MS) {
  if (!tab?.id || !tab?.windowId) throw codedError("local_tab_missing", "Scheda locale non disponibile per la cattura.");
  const activeTabs = await chrome.tabs.query({ windowId: tab.windowId, active: true }).catch(() => []);
  const previousActive = Number(activeTabs?.[0]?.id || 0);
  if (previousActive !== Number(tab.id)) {
    markAutomationInput(tab.id, 2_500);
    await chrome.tabs.update(tab.id, { active: true });
  }
  try {
    return await promiseWithTimeout(
      chrome.tabs.captureVisibleTab(tab.windowId, { format: "png" }),
      timeoutMs,
      () => codedError("LOCAL_CAPTURE_TIMEOUT", `Cattura locale oltre ${timeoutMs} ms.`, { tabId: tab.id, timeoutMs })
    );
  } finally {
    if (previousActive && previousActive !== Number(tab.id)) await chrome.tabs.update(previousActive, { active: true }).catch(() => {});
  }
}

function dataUrlApproxBytes(dataUrl = "") {
  const payload = String(dataUrl || "").replace(/^data:[^,]+,/, "");
  return Math.ceil(payload.length * 0.75);
}

async function captureLocalBaseline(nameInput = "", executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  const dataUrl = await captureExactVisibleTab(tab);
  const bytes = dataUrlApproxBytes(dataUrl);
  if (bytes > SCREENSHOT_MAX_OUTPUT_BYTES) throw codedError("local_baseline_too_large", "Screenshot oltre il limite massimo della baseline locale.", { bytes, maxBytes: SCREENSHOT_MAX_OUTPUT_BYTES });
  const [health, semantic, sha256] = await Promise.all([
    localPageHealthForTab(tab.id).catch(() => null),
    localSemanticSnapshotForTab(tab.id).catch(() => null),
    sha256Text(dataUrl),
  ]);
  const id = `base_${Date.now().toString(36)}`;
  const baseline = { id, name: String(nameInput || tab.title || "Baseline").replace(/\s+/g, " ").trim().slice(0, 160), url: tab.url || "", origin: new URL(tab.url).origin, createdAt: Date.now(), sha256, bytes, health, semantic, imagePersisted: false };
  const stored = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_BASELINES))[STORAGE_KEYS.LOCAL_BASELINES] || {};
  const entries = Object.values(stored).sort((a, b) => Number(b.createdAt || 0) - Number(a.createdAt || 0)).slice(0, 29);
  const next = Object.fromEntries(entries.map((item) => [item.id, item]));
  next[id] = baseline;
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_BASELINES]: next });
  await appendLocalFlight("baseline.captured", { id, url: baseline.url, bytes, sha256 });
  return { ok: true, baseline };
}

async function compareLocalBaseline(idInput, executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  const stored = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_BASELINES))[STORAGE_KEYS.LOCAL_BASELINES] || {};
  const baseline = stored[String(idInput || "")];
  if (!baseline) return { ok: false, error: "local_baseline_missing" };
  const currentDataUrl = await captureExactVisibleTab(tab);
  const currentBytes = dataUrlApproxBytes(currentDataUrl);
  if (currentBytes > SCREENSHOT_MAX_OUTPUT_BYTES) return { ok: false, error: "local_capture_too_large", bytes: currentBytes, maxBytes: SCREENSHOT_MAX_OUTPUT_BYTES };
  const baselineSha256 = String(baseline.sha256 || (baseline.dataUrl ? await sha256Text(baseline.dataUrl) : ""));
  if (!baselineSha256) return { ok: false, error: "local_baseline_hash_missing" };
  const [currentHealth, currentSemantic, currentSha256] = await Promise.all([
    localPageHealthForTab(tab.id).catch(() => null),
    localSemanticSnapshotForTab(tab.id).catch(() => null),
    sha256Text(currentDataUrl),
  ]);
  const semanticDiff = {
    textChanged: Boolean(baseline.semantic?.textSha256 && currentSemantic?.textSha256 && baseline.semantic.textSha256 !== currentSemantic.textSha256),
    structureChanged: Boolean(baseline.semantic?.structureSha256 && currentSemantic?.structureSha256 && baseline.semantic.structureSha256 !== currentSemantic.structureSha256),
    baselineTextSha256: baseline.semantic?.textSha256 || "", currentTextSha256: currentSemantic?.textSha256 || "",
    baselineStructureSha256: baseline.semantic?.structureSha256 || "", currentStructureSha256: currentSemantic?.structureSha256 || "",
  };
  await appendLocalFlight("baseline.compare", { id: baseline.id, baselineUrl: baseline.url, currentUrl: tab.url, baselineSha256, currentSha256, semanticDiff });
  return {
    ok: true, equal: baselineSha256 === currentSha256,
    baseline: { id: baseline.id, name: baseline.name, url: baseline.url, sha256: baselineSha256, bytes: Number(baseline.bytes || 0), health: baseline.health || null, semantic: baseline.semantic || null },
    current: { url: tab.url || "", sha256: currentSha256, bytes: currentBytes, health: currentHealth, semantic: currentSemantic },
    semanticDiff, payloadImagesOmitted: true,
  };
}

async function localDebuggerAttach(tabId) {
  if (await debuggerAttached(tabId)) throw codedError("local_debugger_busy", "DevTools o un altro debugger è già collegato alla scheda.");
  try {
    const negotiated = await promiseWithTimeout(
      attachWithProtocolFallback(chrome.debugger, { tabId }, () => debuggerAttached(tabId)),
      15_000,
      () => codedError("CDP_ATTACH_TIMEOUT", "Negoziazione debugger locale oltre il limite.", { tabId, timeoutMs: 15_000 })
    );
    return negotiated.protocolVersion;
  } catch (error) {
    try {
      if (await debuggerAttached(tabId)) await detachDebugger(tabId, "local_debugger_attach_failed");
    } catch (cleanupError) {
      throw codedError("LOCAL_DEBUGGER_ATTACH_CLEANUP_FAILED", "Attach debugger locale fallito e cleanup CDP non verificato.", {
        operation: serializeError(error),
        cleanup: serializeError(cleanupError),
      });
    }
    throw error;
  }
}

async function localDebuggerCommand(tabId, method, params = {}) {
  const decision = validateCdpCommand(method, params, "internal");
  if (!decision.ok) throw codedError(decision.code, `Comando diagnostico bloccato: ${method}`);
  return debuggerCommandWithTimeout(tabId, method, params, CDP_DEFAULT_TIMEOUT_MS, "local_debugger");
}

function publicUrl(value) {
  try { const url = new URL(String(value || "")); url.username = ""; url.password = ""; url.search = ""; url.hash = ""; return url.href; }
  catch { return String(value || "").slice(0, 1000); }
}

async function localDebugCapture(reload = false, executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  networkBuffers.delete(tab.id); consoleBuffers.delete(tab.id); cdpEventBuffers.delete(tab.id);
  let attached = false;
  let result = null;
  let operationError = null;
  let cleanupError = null;
  try {
    const protocol = await localDebuggerAttach(tab.id); attached = true;
    for (const method of ["Page.enable", "Runtime.enable", "Network.enable", "Log.enable", "Performance.enable"]) await localDebuggerCommand(tab.id, method, {}).catch(() => {});
    if (reload) { await chrome.tabs.reload(tab.id, { bypassCache: false }); await waitForLocalTab(tab.id, 45000); }
    await sleep(reload ? 1200 : 500);
    const metrics = await localDebuggerCommand(tab.id, "Performance.getMetrics", {}).catch(() => ({ metrics: [] }));
    const network = networkBuffers.get(tab.id) || [];
    const consoleEvents = consoleBuffers.get(tab.id) || [];
    const responses = network.filter((item) => item.method === "Network.responseReceived").slice(-300).map((item) => ({
      url: publicUrl(item.params?.response?.url), status: Number(item.params?.response?.status || 0), mimeType: item.params?.response?.mimeType || "", fromDiskCache: Boolean(item.params?.response?.fromDiskCache),
    }));
    const failures = network.filter((item) => item.method === "Network.loadingFailed").slice(-100).map((item) => ({ errorText: item.params?.errorText || "", canceled: Boolean(item.params?.canceled), blockedReason: item.params?.blockedReason || "" }));
    const page = await localPageHealthForTab(tab.id).catch(() => null);
    const output = redactObservation({
      tabId: tab.id, url: publicUrl(tab.url), protocol, reloaded: reload, page,
      performanceMetrics: Object.fromEntries((metrics.metrics || []).map((item) => [item.name, item.value])),
      network: { responseCount: responses.length, errorResponses: responses.filter((item) => item.status >= 400), failures, slowOrLargeResources: page?.slowResources || [] },
      console: consoleEvents.filter((item) => ["Runtime.exceptionThrown", "Log.entryAdded", "Console.messageAdded"].includes(item.method)).slice(-100),
      capturedAt: Date.now(),
    }, { console: true, limits: { maxStringLength: 4000, maxArrayLength: 300, maxObjectKeys: 200 } }).value;
    await appendLocalFlight("debug.completed", { tabId: tab.id, reload, responseCount: output.network?.responseCount || 0, errorCount: output.network?.errorResponses?.length || 0 });
    result = { ok: true, result: output };
  } catch (error) {
    operationError = error;
  } finally {
    if (attached) {
      try { await detachDebugger(tab.id, "local_debug_capture_complete"); }
      catch (error) { cleanupError = error; }
    }
  }
  if (operationError && cleanupError) throw codedError("LOCAL_DEBUG_CAPTURE_CLEANUP_FAILED", "Debug locale e cleanup debugger sono entrambi falliti.", { operation: serializeError(operationError), cleanup: serializeError(cleanupError) });
  if (cleanupError) throw cleanupError;
  if (operationError) throw operationError;
  return result;
}

async function buildLocalBugReport(includeScreenshot = false, executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  const [health, semantic, flight, inspector] = await Promise.all([
    localPageHealthForTab(tab.id).catch((error) => ({ error: serializeError(error) })),
    localSemanticSnapshotForTab(tab.id).catch((error) => ({ error: serializeError(error) })),
    getLocalFlight(),
    chrome.storage.local.get(STORAGE_KEYS.LOCAL_INSPECTOR).then((row) => row[STORAGE_KEYS.LOCAL_INSPECTOR] || null),
  ]);
  const debug = await localDebugCapture(false, executionContext).then((row) => row.result).catch((error) => ({ error: serializeError(error) }));
  let screenshot = null;
  if (includeScreenshot) {
    const dataUrl = await captureExactVisibleTab(tab).catch(() => null);
    screenshot = dataUrl && String(dataUrl).length <= 1_500_000 ? dataUrl : null;
  }
  const report = redactObservation({
    schemaVersion: LOCAL_STUDIO_VERSION,
    suiteVersion: SUITE_VERSION,
    generatedAt: new Date().toISOString(),
    localOnly: true,
    noExternalUpload: true,
    tab: { id: tab.id, windowId: tab.windowId, url: publicUrl(tab.url), title: tab.title || "" },
    health,
    semantic: semantic ? { ...semantic, text: String(semantic.text || "").slice(0, 30000) } : null,
    debug,
    lastInspector: inspector?.result || null,
    flight: flight.slice(-50),
    screenshotIncluded: Boolean(screenshot),
    screenshot,
  }, { console: true, limits: { maxStringLength: 120000, maxArrayLength: 300, maxObjectKeys: 250 } }).value;
  await appendLocalFlight("diagnostic.report.created", { tabId: tab.id, screenshotIncluded: Boolean(screenshot) });
  return { ok: true, report };
}

async function localResponsiveMatrix(executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  const sizes = [
    { name: "Mobile", width: 375, height: 812, deviceScaleFactor: 1, mobile: true },
    { name: "Tablet", width: 768, height: 1024, deviceScaleFactor: 1, mobile: true },
    { name: "Desktop", width: 1440, height: 900, deviceScaleFactor: 1, mobile: false },
  ];
  let attached = false;
  let result = null;
  let operationError = null;
  const cleanupFailures = [];
  const results = [];
  try {
    const protocol = await localDebuggerAttach(tab.id); attached = true;
    await localDebuggerCommand(tab.id, "Page.enable", {}).catch(() => {});
    for (const size of sizes) {
      await localDebuggerCommand(tab.id, "Emulation.setDeviceMetricsOverride", size);
      await sleep(250);
      const rows = await chrome.scripting.executeScript({ target: { tabId: tab.id, allFrames: false }, func: collectLocalResponsiveSnapshot });
      const snapshot = rows?.[0]?.result || null;
      results.push({ name: size.name, requested: { width: size.width, height: size.height, mobile: size.mobile }, snapshot });
    }
    await appendLocalFlight("responsive.completed", { tabId: tab.id, results: results.map((row) => ({ name: row.name, overflow: row.snapshot?.horizontalOverflow, overflowElements: row.snapshot?.horizontalOverflowElements })) });
    result = { ok: true, protocol, url: publicUrl(tab.url), results };
  } catch (error) {
    operationError = error;
  } finally {
    if (attached) {
      try { await localDebuggerCommand(tab.id, "Emulation.clearDeviceMetricsOverride", {}); }
      catch (error) { cleanupFailures.push({ stage: "restore_viewport", error }); }
      try { await detachDebugger(tab.id, "local_responsive_complete"); }
      catch (error) { cleanupFailures.push({ stage: "debugger_detach", error }); }
    }
  }
  if (operationError || cleanupFailures.length) {
    if (!operationError && cleanupFailures.length === 1) throw cleanupFailures[0].error;
    if (operationError && !cleanupFailures.length) throw operationError;
    throw codedError("LOCAL_RESPONSIVE_CLEANUP_FAILED", "Responsive matrix locale non completata con ripristino verificato.", {
      operation: operationError ? serializeError(operationError) : null,
      cleanup: cleanupFailures.map(({ stage, error }) => ({ stage, error: serializeError(error) })),
    });
  }
  return result;
}

async function localSiteScan(limitInput = 8, executionContext = null) {
  const { tab } = await localLaneContext({ executionContext });
  const limit = Math.max(1, Math.min(15, Number(limitInput || 8)));
  const semantic = await localSemanticSnapshotForTab(tab.id);
  const origin = new URL(tab.url).origin;
  const urls = [tab.url];
  for (const link of semantic.links || []) {
    if (urls.length >= limit) break;
    try {
      const url = new URL(link.href, tab.url); url.hash = "";
      if (url.origin !== origin || !/^https?:$/i.test(url.protocol)) continue;
      if (/\.(?:pdf|zip|rar|7z|jpg|jpeg|png|gif|webp|svg|mp4|mp3)(?:$|\?)/i.test(url.pathname)) continue;
      if (!urls.includes(url.href)) urls.push(url.href);
    } catch { /* ignore malformed link */ }
  }
  const executionId = `scan_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 7)}`;
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_ACTIVE]: { executionId, kind: "site_scan", phase: "in_flight", mutating: false, startedAt: Date.now(), tabId: tab.id } });
  const results = [];
  try {
    for (const url of urls) {
      if (url === tab.url) {
        const health = await localPageHealthForTab(tab.id);
        results.push({ url, score: health.score, health });
        continue;
      }
      const workerTab = await chrome.tabs.create({ windowId: tab.windowId, url, active: false });
      try {
        await waitForLocalTab(workerTab.id, 45000);
        const health = await localPageHealthForTab(workerTab.id);
        results.push({ url, score: health.score, health });
      } catch (error) {
        results.push({ url, error: serializeError(error) });
      } finally {
        await promiseWithTimeout(
          chrome.tabs.remove(workerTab.id),
          5_000,
          () => codedError("LOCAL_SITE_SCAN_TAB_CLOSE_TIMEOUT", "Timeout nella chiusura della tab worker della scansione locale."),
        );
      }
    }
    await appendLocalFlight("site_scan.completed", { executionId, origin, pages: results.length, failures: results.filter((row) => row.error).length });
    return { ok: true, origin, results };
  } finally {
    const active = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_ACTIVE))[STORAGE_KEYS.LOCAL_ACTIVE];
    if (active?.executionId === executionId) await chrome.storage.local.remove(STORAGE_KEYS.LOCAL_ACTIVE);
  }
}

async function getLocalSchedules() {
  const value = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_SCHEDULES))[STORAGE_KEYS.LOCAL_SCHEDULES];
  return Array.isArray(value) ? value.slice(0, 30) : [];
}

async function getLocalScheduledResults() {
  const value = (await chrome.storage.local.get(STORAGE_KEYS.LOCAL_RESULTS))[STORAGE_KEYS.LOCAL_RESULTS];
  return Array.isArray(value) ? value.slice(-30) : [];
}

async function upsertLocalSchedule(input = {}, executionContext = null) {
  const rawUrl = String(input.url || (await getEligibleLocalTab(executionContext)).url || "").trim();
  if (isRestrictedLocalUrl(rawUrl) || !/^https?:\/\//i.test(rawUrl)) return { ok: false, error: "local_schedule_url_invalid" };
  const minutes = Math.max(1, Math.min(10080, Number(input.minutes || 60)));
  const id = String(input.id || `check_${Date.now().toString(36)}`).replace(/[^a-zA-Z0-9_-]/g, "").slice(0, 80);
  const schedule = { id, name: String(input.name || "Controllo pagina").replace(/\s+/g, " ").trim().slice(0, 160), url: rawUrl, minutes, enabled: input.enabled !== false, createdAt: Number(input.createdAt || Date.now()), updatedAt: Date.now(), localOnly: true };
  const schedules = (await getLocalSchedules()).filter((item) => item.id !== id);
  schedules.unshift(schedule);
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_SCHEDULES]: schedules.slice(0, 30) });
  if (schedule.enabled) await chrome.alarms.create(`prstudio-local-check:${id}`, { delayInMinutes: minutes, periodInMinutes: minutes });
  else await chrome.alarms.clear(`prstudio-local-check:${id}`);
  await appendLocalFlight("schedule.upsert", { id, url: rawUrl, minutes, enabled: schedule.enabled });
  return { ok: true, schedule };
}

async function deleteLocalSchedule(idInput) {
  const id = String(idInput || "");
  const schedules = await getLocalSchedules();
  const next = schedules.filter((item) => item.id !== id);
  await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_SCHEDULES]: next });
  await chrome.alarms.clear(`prstudio-local-check:${id}`);
  await appendLocalFlight("schedule.deleted", { id, removed: next.length !== schedules.length });
  return { ok: true, removed: next.length !== schedules.length };
}

async function ensureLocalScheduleAlarms() {
  for (const schedule of await getLocalSchedules()) {
    if (!schedule?.enabled) continue;
    const name = `prstudio-local-check:${schedule.id}`;
    if (!await chrome.alarms.get(name)) await chrome.alarms.create(name, { delayInMinutes: Math.max(1, Number(schedule.minutes || 60)), periodInMinutes: Math.max(1, Number(schedule.minutes || 60)) });
  }
}

async function lastNonAgentWindowId() {
  const windows = await chrome.windows.getAll({ populate: false, windowTypes: ["normal"] });
  const candidate = windows.find((item) => item.focused) || windows[0];
  return candidate?.id || null;
}

async function runLocalScheduledCheck(idInput) {
  const schedule = (await getLocalSchedules()).find((item) => item.id === String(idInput || ""));
  if (!schedule?.enabled) return { ok: false, error: "local_schedule_missing" };
  const results = await getLocalScheduledResults();
  let tab = null;
  let ephemeralWorkerWindowId = null;
  try {
    const windowId = await lastNonAgentWindowId();
    if (windowId) {
      tab = await chrome.tabs.create({ url: schedule.url, active: false, windowId });
    } else {
      const workerWindow = await chrome.windows.create({ url: schedule.url, focused: false, type: "normal", state: "minimized" });
      ephemeralWorkerWindowId = Number(workerWindow?.id || 0) || null;
      tab = workerWindow?.tabs?.[0] || (ephemeralWorkerWindowId ? (await chrome.tabs.query({ windowId: ephemeralWorkerWindowId }))[0] : null);
      if (!tab?.id) throw codedError("local_worker_window_failed", "Impossibile creare una finestra di lavoro locale separata dalla finestra Agent.");
    }
    await waitForLocalTab(tab.id, 45000);
    const health = await localPageHealthForTab(tab.id);
    results.push({ id: schedule.id, at: Date.now(), status: "completed", url: health.url, score: health.score, summary: { h1Count: health.h1Count, imagesMissingAlt: health.imagesMissingAlt, unlabeledControls: health.unlabeledControls, badLinkCount: health.badLinkCount, schemaParseErrors: health.schemaParseErrors, mixedContentCount: health.mixedContentCount } });
    await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_RESULTS]: results.slice(-30) });
    await appendLocalFlight("schedule.completed", { id: schedule.id, score: health.score });
    if (health.score < 75) await chrome.notifications.create(`prstudio-local-${schedule.id}-${Date.now()}`, { type: "basic", iconUrl: "icons/icon128.png", title: "PR STUDIO — controllo pagina", message: `${schedule.name}: salute ${health.score}/100` }).catch(() => {});
    return { ok: true, health };
  } catch (error) {
    results.push({ id: schedule.id, at: Date.now(), status: "failed", url: schedule.url, error: String(error?.message || error).slice(0, 500) });
    await chrome.storage.local.set({ [STORAGE_KEYS.LOCAL_RESULTS]: results.slice(-30) });
    await appendLocalFlight("schedule.failed", { id: schedule.id, error: serializeError(error) });
    return { ok: false, error: serializeError(error) };
  } finally {
    const cleanupFailures = [];
    if (ephemeralWorkerWindowId) {
      try {
        await promiseWithTimeout(
          chrome.windows.remove(ephemeralWorkerWindowId),
          5_000,
          () => codedError("LOCAL_SCHEDULE_WINDOW_CLOSE_TIMEOUT", "Timeout nella chiusura della finestra worker del controllo pianificato."),
        );
      } catch (error) { cleanupFailures.push({ stage: "worker_window_close", error }); }
    } else if (tab?.id) {
      try {
        await promiseWithTimeout(
          chrome.tabs.remove(tab.id),
          5_000,
          () => codedError("LOCAL_SCHEDULE_TAB_CLOSE_TIMEOUT", "Timeout nella chiusura della tab worker del controllo pianificato."),
        );
      } catch (error) { cleanupFailures.push({ stage: "worker_tab_close", error }); }
    }
    if (cleanupFailures.length === 1) throw cleanupFailures[0].error;
    if (cleanupFailures.length > 1) {
      throw codedError("LOCAL_SCHEDULE_CLEANUP_FAILED", "Cleanup del controllo locale pianificato non completato.", {
        cleanup: cleanupFailures.map(({ stage, error }) => ({ stage, error: serializeError(error) })),
      });
    }
  }
}

async function exportLocalStudioState() {
  const [workflows, workspaces, schedules, results, profiles] = await Promise.all([
    getLocalWorkflows(), getLocalWorkspaces(), getLocalSchedules(), getLocalScheduledResults(), chrome.storage.local.get(STORAGE_KEYS.LOCAL_PROFILES),
  ]);
  return { ok: true, export: { schemaVersion: LOCAL_STUDIO_VERSION, suiteVersion: SUITE_VERSION, exportedAt: new Date().toISOString(), localOnly: true, workflows, workspaces, schedules, scheduledResults: results, originProfiles: profiles[STORAGE_KEYS.LOCAL_PROFILES] || {} } };
}
// ---- End PR STUDIO Local Studio ---------------------------------------------

async function pairDevice(siteUrl, code, name = "Chrome personale") {
  const normalized = normalizeSiteUrl(siteUrl);
  const pairingCode = String(code || "").trim();
  if (!pairingCode || pairingCode.length > 512) throw codedError("pairing_code_invalid", "Codice pairing mancante o non valido.");
  const previous = await getConfig();
  const pairingController = new AbortController();
  const pairingTimeout = setTimeout(() => pairingController.abort("pairing_timeout"), 15000);
  let response;
  try {
    response = await fetch(`${normalized}/wp-json/prstudio-unified/v1/pair`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        code: pairingCode,
        name: String(name || "Chrome personale").slice(0, 160),
        previous_device_id: previous?.deviceId || "",
        capabilities: capabilities(),
      }),
      cache: "no-store",
      credentials: "omit",
      redirect: "error",
      signal: pairingController.signal,
    });
  } finally {
    clearTimeout(pairingTimeout);
  }
  const data = await response.json().catch(() => ({}));
  if (response.redirected || new URL(response.url || `${normalized}/wp-json/prstudio-unified/v1/pair`).origin !== new URL(normalized).origin) {
    throw codedError("pairing_redirect_forbidden", "Il pairing non consente redirect o cambi di origine.");
  }
  if (!response.ok) throw new Error(data?.message || "Pairing non riuscito");
  if (!data || typeof data !== "object" || typeof data.api_base !== "string" || typeof data.token !== "string" || typeof data.device_id !== "string") {
    throw codedError("pairing_response_invalid", "La risposta di pairing non contiene api_base, token e device_id validi.");
  }
  if (!data.token || data.token.length > 8192 || !data.device_id || data.device_id.length > 191) throw codedError("pairing_credentials_invalid", "Credenziali di pairing non valide.");
  const apiBaseUrl = new URL(data.api_base, normalized);
  if (apiBaseUrl.username || apiBaseUrl.password) throw codedError("pairing_api_credentials_forbidden", "api_base non può contenere credenziali.");
  if (apiBaseUrl.origin !== new URL(normalized).origin) throw codedError("pairing_api_origin_mismatch", "api_base deve appartenere alla stessa origine WordPress.");
  normalizeSiteUrl(apiBaseUrl.origin);
  const serverProtocols = new Set(Array.isArray(data.browser_executor_protocols_accepted) ? data.browser_executor_protocols_accepted.map(String) : []);
  if (data.browser_executor_protocol) serverProtocols.add(String(data.browser_executor_protocol));
  if (serverProtocols.size && !serverProtocols.has(EXECUTOR_PROTOCOL_VERSION)) {
    throw codedError("executor_protocol_mismatch", `Nessun protocollo executor comune: server ${[...serverProtocols].join(", ")}, estensione ${EXECUTOR_PROTOCOL_VERSION}.`);
  }
  await stopPolling("pairing_refresh");
  const config = {
    siteUrl: normalized,
    apiBase: apiBaseUrl.href.replace(/\/$/, ""),
    token: data.token,
    deviceId: data.device_id,
    pairedAt: Date.now(),
    authExpired: false,
    lastAuthError: null,
    executorProtocolVersion: EXECUTOR_PROTOCOL_VERSION,
    negotiatedExecutorProtocol: EXECUTOR_PROTOCOL_VERSION,
    suiteVersion: SUITE_VERSION,
    serverCapabilities: data.server_capabilities || previous?.serverCapabilities || null,
  };
  await chrome.storage.local.set({ [STORAGE_KEYS.CONFIG]: config });
  await setBadge("ON", "#176b32");
  startPolling().catch(logError);
  return { ok: true, deviceId: config.deviceId, apiBase: config.apiBase, renewed: Boolean(previous?.deviceId) };
}

async function forgetPairing() {
  if (taskAbortController) taskAbortController.abort("unpaired");
  taskExecutionGeneration += 1;
  await stopPolling("unpaired");
  const active = await getActiveTask();
  if (active) await cleanupTaskRuntime(active, { force: true }).catch(() => {});
  await clearActiveTask();
  await chrome.storage.local.remove([
    STORAGE_KEYS.CONFIG, STORAGE_KEYS.TAB_REGISTRY,
    STORAGE_KEYS.TAB_AFFINITY, STORAGE_KEYS.LAST_AGENT_TAB, STORAGE_KEYS.LOGS, STORAGE_KEYS.LOG_QUEUE,
    STORAGE_KEYS.GSC_SESSION, STORAGE_KEYS.LOCAL_ACTIVE, STORAGE_KEYS.LOCAL_RECORDER, STORAGE_KEYS.LOCAL_INSPECTOR,
    STORAGE_KEYS.LOCAL_FLIGHT, STORAGE_KEYS.LOCAL_RESULTS, STORAGE_KEYS.SENSITIVE_STATES, STORAGE_KEYS.RUNTIME_SESSIONS,
    STORAGE_KEYS.AGENT_WINDOW,
  ]);
  networkBuffers.clear(); consoleBuffers.clear(); cdpEventBuffers.clear(); traceBuffers.clear(); screencastBuffers.clear();
  routeRules.clear(); downloadBuffers.clear(); structuredNetworkPayloads.clear(); gscCollectionGenerations.clear(); gscRequestGenerations.clear();
  debuggerProtocolByTab.clear(); intentionalDebuggerDetaches.clear(); automationInputUntilByTab.clear();
  await setBadge("OFF", "#666666");
  return { ok: true };
}

function capabilities(serverCapabilities = null) {
  const serverOcr = serverCapabilities && typeof serverCapabilities === "object"
    ? (serverCapabilities.ocr && typeof serverCapabilities.ocr === "object" ? serverCapabilities.ocr : null)
    : null;
  const ocrServerAvailableNow = Boolean(serverOcr?.server_available === true);
  // TextDetector constructor presence is not sufficient availability evidence.
  // Chrome documents that the underlying platform detector can still be unavailable;
  // browser-native OCR is therefore probed only inside an owned page at execution time.
  const ocrBrowserNativeAvailableNow = false;
  const ocrAvailableNow = ocrServerAvailableNow;
  const ocrProvider = ocrServerAvailableNow ? "server_tesseract" : "none";
  return {
    version: API_VERSION,
    component: "browser_agent",
    componentVersion: SUITE_VERSION,
    suiteVersion: SUITE_VERSION,
    executorProtocolVersion: EXECUTOR_PROTOCOL_VERSION,
    agentImplementationVersion: SUITE_VERSION,
    agentBuild: EXECUTOR_BUILD_ID,
    buildTimestamp: EXECUTOR_BUILD_TIMESTAMP,
    capabilityHash: CAPABILITY_CONTRACT_SHA256,
    gscDimensionSessionVersion: GSC_DIMENSION_SESSION_VERSION,
    runtimeOperationCount: RUNTIME_CONTRACT_ACTIONS.length,
    wordpressCapabilityCatalog: false,
    manifest: 3,
    debugger: true,
    scripting: true,
    screenshot: true,
    fullPageScreenshot: true,
    backgroundTabs: true,
    isolatedAgentWindow: false,
    sameProfileExistingWindow: true,
    strictTabOwnership: true,
    explicitUserTabAdoption: true,
    multiTabAdoption: true,
    multiLaneTabIsolation: true,
    noActiveUserTabFallback: true,
    screenshotRetention: "bounded_server_authoritative",
    screenshotRetentionPolicy: { mode: "bounded", serverAuthoritative: true },
    // Legacy booleans now mean current availability, not theoretical support.
    ocr: ocrAvailableNow,
    ocrSupported: true,
    ocrAvailableNow,
    ocrProvider,
    ocrExecutionScope: "owned_http_https_tab",
    ocrPrerequisites: ["paired_server_for_server_tesseract_or_per_owned_tab_TextDetector_probe", "owned_http_https_tab"],
    ocrServer: ocrServerAvailableNow,
    ocrServerSupported: true,
    ocrServerAvailableNow,
    ocrServerCapabilityOnly: !ocrServerAvailableNow,
    ocrAvailabilitySource: serverOcr ? "server_status_plus_per_owned_tab_probe_required" : "per_owned_tab_probe_required_no_server_status",
    ocrBrowserNative: ocrBrowserNativeAvailableNow,
    ocrBrowserNativeSupported: true,
    ocrBrowserNativeAvailableNow,
    ocrBrowserNativeAvailability: "per_owned_tab_probe_required",
    ocrDomAccessibility: false,
    ocrDomAccessibilitySupported: true,
    ocrDomAccessibilityAvailableNow: false,
    ocrDomAccessibilityAvailability: "per_owned_tab_probe_required",
    ocrModes: ["server_tesseract", "browser_text_detector", "browser_page_text"],
    pairingRenewal: true,
    externalAuthChallenge: true,
    externalAuthChallengeAutoResume: true,
    observerInteractionTelemetryOnly: true,
    screenshotStoragePreflight: true,
    screenshotUploadTimeoutMs: SCREENSHOT_UPLOAD_TIMEOUT_MS,
    screenshotTruthfulFallbackEvidence: true,
    gscCollectionGenerationBound: true,
    localScheduleExecution: "independent_worker_window",
    crashRecovery: true,
    durableJobs: true,
    idempotentCompletion: true,
    verificationReceipts: true,
    recoveryVersion: "1.0.0",
    selectorStrategies: ["target_ref", "accessibility", "label", "text", "css", "xpath", "coordinates"],
    nativeInput: true,
    pointerSequence: true,
    keyboardSequence: true,
    canvasCoordinateInput: true,
    observationBundle: true,
    socialSnapshot: "best-effort-browser-observation",
    observationSecurityVersion: OBSERVATION_SECURITY_VERSION,
    observationTrust: "untrusted_web_content",
    rawCdp: "exact-read-only-allowlist",
    noInfiniteRetry: true,
    remoteQueueSelfHealing: true,
    remoteAutoFreshRestartAfterAttempts: REMOTE_MAX_STEP_ATTEMPTS,
    remoteNoProgressWatchdog: true,
    remoteInternalScrollFallback: true,
    nativePointerAliasNormalization: true,
    localStudio: featureAdvertisement(),
    localStudioVersion: LOCAL_STUDIO_VERSION,
    localStandaloneMode: true,
    localNoExternalAccounts: true,
    localNoApiKeys: true,
    localFeatures: [...LOCAL_STUDIO_FEATURES],
    localRemoteInvocation: true,
    localExecutionScope: "standalone_local_plus_lane_bound_remote_allowlist",
    localRemoteFeatureCount: LOCAL_STUDIO_FEATURES.length,
    localCrashRecovery: "anti_crash_nonreplayable_mutation",
    serverMcpToolchainAware: true,
    nativeMcpCompatibility: ["wordpress_mcp", "playwright", "chrome_devtools", "accessibility", "image_optimizer"],
    optionalMcpSidecarsAware: true,
  };
}

async function heartbeatDevice(signal = undefined) {
  const config = await getConfig();
  if (!config?.token || config.authExpired) return { skipped: true, reason: "not_paired" };
  const heartbeat = await api("/device/heartbeat", {
    method: "POST",
    body: { capabilities: capabilities(config.serverCapabilities) },
    ...(signal ? { signal } : {}),
  });
  const latestConfig = await getConfig();
  if (latestConfig) {
    if (heartbeat?.server_capabilities) latestConfig.serverCapabilities = heartbeat.server_capabilities;
    latestConfig.serverCapabilitiesAt = Date.now();
    latestConfig.lastDeviceHeartbeatAt = Date.now();
    latestConfig.lastDeviceHeartbeatError = "";
    await chrome.storage.local.set({ [STORAGE_KEYS.CONFIG]: latestConfig });
  }
  return { ok: true, serverCapabilities: Boolean(heartbeat?.server_capabilities) };
}

function requestPollingStop(reason = "runtime_stop") {
  if (abortController && !abortController.signal.aborted) abortController.abort(reason);
  pollGeneration += 1;
}

async function stopPolling(reason = "runtime_stop", timeoutMs = 10_000) {
  requestPollingStop(reason);
  if (!pollLoopRunning) return { stopped: true, reason };
  const pending = pollLoopDonePromise;
  await promiseWithTimeout(
    pending,
    timeoutMs,
    () => codedError("POLL_STOP_TIMEOUT", `Il loop remoto non si è arrestato entro ${timeoutMs} ms.`, { reason, timeoutMs }),
  );
  return { stopped: true, reason };
}

async function startPolling() {
  if (pollLoopRunning) return;
  const config = await getConfig();
  if (!config?.token || config.authExpired) return;
  const generation = ++pollGeneration;
  pollLoopRunning = true;
  pollLoopDonePromise = new Promise((resolve) => { resolvePollLoopDone = resolve; });
  abortController = new AbortController();
  const controller = abortController;
  let errorCount = 0;
  let idleCount = 0;
  let heartbeatAt = 0;
  try {
    await recoverSavedTask();
    while (!controller.signal.aborted && generation === pollGeneration) {
      try {
        const timestamp = Date.now();
        if (timestamp - heartbeatAt >= HEARTBEAT_MS) {
          await heartbeatDevice(controller.signal);
          heartbeatAt = timestamp;
        }
        // Long poll releases immediately when work is enqueued. Remote work is
        // never parked behind local UI activity.
        const useWait = longPollSupported && Date.now() >= longPollCooldownUntil;
        const payload = await api(
          useWait ? `/tasks/next?wait=${LONG_POLL_SECONDS}` : "/tasks/next",
          { method: "GET", signal: controller.signal, timeoutMs: useWait ? (LONG_POLL_SECONDS + 10) * 1000 : undefined },
        );
        if (payload && payload.wait_supported === undefined) longPollSupported = false;
        else if (useWait && ["disabled", "saturated"].includes(String(payload?.wait_mode || ""))) longPollCooldownUntil = Date.now() + LONG_POLL_COOLDOWN_MS;
        lastWaitMode = String(payload?.wait_mode || "");
        if (payload?.task) {
          await saveActiveTask(resumableState(null, payload.task));
          errorCount = 0;
          idleCount = 0;
          await executeTask(payload.task);
          // Without this the loop had no floor. executeTask reports its own
          // failures rather than throwing, the server requeues, and
          // adaptivePollDelay returns 0 whenever work is available -- so a task
          // that fails deterministically was re-claimed instantly, forever.
          // A task that succeeds leaves the counter at zero and stays fast.
          const failureBackoff = failingTaskBackoffMs(consecutiveTaskFailures);
          if (failureBackoff > 0) {
            await appendLog("poll.failing_task_backoff", { consecutiveTaskFailures, backoffMs: failureBackoff }).catch(() => {});
            await abortableSleep(failureBackoff, controller.signal);
          }
        } else {
          idleCount += 1;
          // A held request already provided the wait. Sleeping again on top of
          // it would only add latency to the next dispatch.
          const heldByServer = ["signalled", "timeout", "immediate"].includes(lastWaitMode) && payload?.waited_ms >= 1000;
          if (heldByServer) {
            idleCount = 1;
          } else {
            await abortableSleep(adaptivePollDelay({ idleCount, errorCount: 0 }), controller.signal);
          }
        }
      } catch (error) {
        if (controller.signal.aborted || generation !== pollGeneration) break;
        if (error?.code === "AUTH_EXPIRED") {
          await appendLog("auth.expired", serializeError(error));
          break;
        }
        errorCount += 1;
        idleCount = 0;
        await setBadge("ERR", "#a32020");
        await appendLog("poll.error", serializeError(error));
        try {
          await abortableSleep(Math.max(computeBackoff(errorCount), adaptivePollDelay({ errorCount })), controller.signal);
        } catch (sleepError) {
          if (controller.signal.aborted || generation !== pollGeneration) break;
          throw sleepError;
        }
      }
    }
  } finally {
    pollLoopRunning = false;
    if (abortController === controller) abortController = null;
    const resolveDone = resolvePollLoopDone;
    resolvePollLoopDone = null;
    resolveDone?.();
  }
}

async function recoverSavedTask() {
  const saved = await getActiveTask();
  if (!saved?.taskId) return { action: "none" };
  const disposition = recoveryDisposition(saved);
  if (disposition.action === "uncertain_side_effect") {
    const leaseToken = saved.leaseToken;
    const evidence = { taskId: saved.taskId, inFlight: saved.inFlight, tabId: saved.tabId || null, stepIndex: saved.stepIndex, reason: disposition.reason, nonReplayable: true };
    await appendLog("task.recover.anti_crash_nonreplayable", evidence).catch(() => {});
    if (leaseToken) {
      await api(`/tasks/${saved.taskId}/fail`, { method: "POST", body: { lease_token: leaseToken, error: { code: "ANTI_CRASH_NONREPLAYABLE_MUTATION", message: "A durable mutation was interrupted by a process crash; it will not be replayed blindly.", evidence } }, timeoutMs: 10_000 })
        .catch((error) => appendLog("task.recover.technical_terminal_deferred", serializeError(error)));
    }
    await clearActiveTask();
    await setBadge("CRASH", "#a32020");
    return { ...disposition, terminalized: true, antiCrash: true };
  }
  if (["preserve_cancel", "anti_crash_stopped", "lease_lost"].includes(disposition.action)) {
    return disposition;
  }
  if (["resume_readonly", "resume"].includes(disposition.action)) {
    const resumed = clearInFlightState(saved);
    await saveActiveTask(resumed);
  }
  try {
    const serverTask = await api(`/tasks/${saved.taskId}`, { method: "GET" });
    const terminal = new Set(["completed", "technical_error", "failed", "cancelled", "expired"]);
    if (terminal.has(String(serverTask?.status || ""))) {
      await appendLog("task.recover.terminal", { taskId: saved.taskId, status: serverTask.status });
      await clearActiveTask();
      return { action: "terminal" };
    }
    await appendLog("task.recover.local", { taskId: saved.taskId, stepIndex: saved.stepIndex, serverStatus: serverTask?.status || "unknown" });
    return { action: disposition.action };
  } catch (error) {
    const serialized = serializeError(error);
    await appendLog("task.recover.lookup_retry_deferred", { taskId: saved.taskId, error: serialized, retryable: true, degraded: true, blocking: false });
    return { action: "lookup_retry_deferred", retryable: true, degraded: true, error: serialized };
  }
}

async function executeTask(serverTask) {
  let state = resumableState(await getActiveTask(), serverTask);
  const correlationId = /^corr_[a-f0-9]{32}$/.test(String(state.arguments?.correlation_id || ""))
    ? String(state.arguments.correlation_id)
    : "";
  if (taskAbortController) taskAbortController.abort("superseded_task");
  taskAbortController = new AbortController();
  const executionController = taskAbortController;
  const executionGeneration = ++taskExecutionGeneration;
  let runtimeCleaned = false;
  state.phase = state.phase === "in_flight" ? state.phase : "ready";
  await saveActiveTask(state);
  if (serverTask.status !== "running") {
    await api(`/tasks/${state.taskId}/running`, {
      method: "POST",
      body: { lease_token: state.leaseToken },
      signal: executionController.signal,
    });
  }
  await setBadge("RUN", "#1a5fb4");
  startHeartbeat(state);

  try {
    const steps = actionToSteps(state.action, state.arguments);
    const compactFlow = String(state.action || "") === "playwright_flow" || String(state.action || "") === "browser_batch";
    const flowResults = [];
    // 2026-08-19 hardening (MobileWorldSafety / Wuying-Browser-Agent, arXiv
    // week 2026-08-13..19): page content is untrusted input, and long-horizon
    // flows degrade to a single step when the page mutated under the plan
    // evidence. The plan evidence is the checkpoint the task resumed from
    // (Law 5 retry case); fresh tasks without evidence never force a fallback.
    const planEvidence = state.checkpoint?.last_result || null;
    const hardenedSurface = hardenStepsForUntrustedPage(steps, {
      previousEvidence: planEvidence,
      taskArguments: state.arguments,
    });
    for (let i = 0; i < steps.length; i += 1) {
      steps[i] = hardenedSurface.steps[i] || steps[i];
    }
    const horizonSession = createHorizonSession({ previousEvidence: planEvidence });
    let liveEvidence = planEvidence;
    if (hardenedSurface.containedCount > 0) {
      await appendLog("trap_page.hardening", {
        taskId: state.taskId,
        contained: hardenedSurface.containedCount,
        directives: hardenedSurface.directives.length,
      });
    }
    for (let index = state.stepIndex; index < steps.length; index += 1) {
      throwIfTaskAborted(executionGeneration);
      state.stepIndex = index;
      await saveActiveTask(state);
      let step = steps[index];

      let tabId = null;
      if (stepRequiresOwnedTab(step)) {
        tabId = await resolveTabId(state, step);
        if (tabId) state.tabId = tabId;
        await assertTargetBinding(tabId, step, state, { allowNavigationTransition: ["navigate", "history"].includes(step.type) });
      }

      // Trap-page containment: a page-derived step needs a live auth
      // challenge; without one it is replaced by read-only observation, so no
      // action generated from untrusted page text escapes the sandbox.
      if (step?.page_derived && step?.requires_auth_challenge) {
        const challenge = tabId ? await detectExternalAuthChallenge(tabId) : null;
        const decision = containmentDecision(step, { challengePresent: challenge?.reason === "captcha_or_mfa" });
        if (!decision.execute) {
          const trapClass = String(step._prstudio_trap || "page_derived");
          steps[index] = containmentFallbackStep(tabId);
          step = steps[index];
          await appendLog("trap_page.contained", {
            taskId: state.taskId,
            stepIndex: index,
            trap: trapClass,
          });
        }
      }

      // Horizon stability: when the freshest observed page state no longer
      // matches the evidence the plan was built on, degrade to a single-step
      // refresh instead of replaying stale selectors.
      const horizonPlan = planHorizonStep(step, {
        session: horizonSession,
        liveEvidence,
        multiStepRemaining: Math.max(0, steps.length - index - 1),
      });
      if (horizonPlan.singleStepFallback) {
        steps[index] = horizonPlan.step;
        step = steps[index];
        await appendLog("horizon.fallback", {
          taskId: state.taskId,
          stepIndex: index,
          reason: horizonPlan.reason,
        });
      }

      const challengeBefore = tabId && shouldCheckAuthChallengeBefore(step) ? await detectExternalAuthChallenge(tabId) : null;
      if (challengeBefore?.reason === "captcha_or_mfa") {
        await waitForExternalAuthChallenge(state, challengeBefore.reason, challengeBefore);
      }

      const stepHash = await digestStep(step);
      const durableMutation = isMutatingStep(step) && String(step.type || "") !== "scroll";
      state = beginInFlightState(state, step, index, stepHash, durableMutation);
      await saveActiveTask(state);
      const result = await executeStepWithRetry(state, step);
      throwIfTaskAborted(executionGeneration);
      const rawSecuredResult = await secureResultForCheckpoint(state, step, result);
      const securedResult = correlationId && rawSecuredResult && typeof rawSecuredResult === "object" && !Array.isArray(rawSecuredResult)
        ? { ...rawSecuredResult, correlation_id: correlationId }
        : rawSecuredResult;
      state.checkpoint = {
        ...(state.checkpoint || {}),
        last_completed_step: index,
        last_result: securedResult,
        ...(correlationId ? { correlation_id: correlationId } : {}),
      };
      if (result?.tabId) state.tabId = result.tabId;
      // Horizon stability: keep the freshest observed page state so the next
      // step can be checked against it (dense evidence states, Wuying).
      if (securedResult && typeof securedResult === "object") {
        liveEvidence = securedResult;
      }
      state = markCommittingState(state, await sha256Text(JSON.stringify(securedResult)));
      await saveActiveTask(state);
      if (compactFlow) flowResults.push({ index, result: securedResult, step_digest: stepHash });
      if (!compactFlow || index === steps.length - 1) {
        const checkpointResult = compactFlow ? { ok: true, flow: true, stepCount: steps.length, results: flowResults } : securedResult;
        await api(`/tasks/${state.taskId}/checkpoint`, {
          method: "POST", signal: executionController.signal,
          body: { lease_token: state.leaseToken, step_index: index, result: checkpointResult, step_digest: stepHash, attempt_id: state.inFlight?.attemptId || "" },
        });
        if (compactFlow) state.checkpoint.last_result = checkpointResult;
      }
      state = clearInFlightState(state);
      state.stepIndex = index + 1;
      await saveActiveTask(state);

      const challengeAfter = state.tabId && shouldCheckAuthChallengeAfter(step) ? await detectExternalAuthChallenge(state.tabId) : null;
      if (challengeAfter?.reason === "captcha_or_mfa") {
        await waitForExternalAuthChallenge(state, challengeAfter.reason, challengeAfter);
      }
    }

    await cleanupTaskRuntime(state);
    runtimeCleaned = true;
    await api(`/tasks/${state.taskId}/complete`, {
      method: "POST",
      signal: executionController.signal,
      body: {
        lease_token: state.leaseToken,
        result: state.checkpoint?.last_result || { ok: true },
      },
    });
    await appendLog("task.completed", { taskId: state.taskId, correlationId });
    state.phase = "completed";
    await clearActiveTask();
    consecutiveTaskFailures = 0;
    await setBadge("ON", "#176b32");
  } catch (error) {
    if (String(error?.code || "") === "FRESH_RESTART_REQUESTED" && state.leaseToken) {
      const restarted = await requestFreshTaskRestart(state, error).catch(async (restartError) => {
        await appendLog("task.fresh_restart.failed", { taskId: state.taskId, error: serializeError(restartError) });
        return false;
      });
      if (restarted) {
        await clearActiveTask();
        await setBadge("RETRY", "#6f42c1");
        await appendLog("task.fresh_restart.queued", { taskId: state.taskId, stepIndex: state.stepIndex, reason: error?.details?.cause || serializeError(error) });
        return;
      }
    }
    const leaseInvalid = [401, 403, 409].includes(Number(error?.status)) || ["AUTH_EXPIRED", "FORBIDDEN", "LEASE_LOST"].includes(String(error?.code || ""));
    if (leaseInvalid) await handleLeaseLoss(state, error).catch(() => {});
    const signalReason = String(taskAbortController?.signal?.reason || "");
    const aborted = leaseInvalid || ["TASK_ABORTED", "TASK_CANCELLED", "LEASE_LOST"].includes(String(error?.code || ""))
      || ["cancelled_by_user", "tab_removed", "lease_lost", "unpaired"].includes(signalReason);
    if (!aborted && state.leaseToken) {
      await api(`/tasks/${state.taskId}/fail`, {
        method: "POST",
        body: { lease_token: state.leaseToken, error: serializeError(error) },
      }).catch(() => {});
    }
    await appendLog(aborted ? "task.interrupted" : "task.failed", { taskId: state.taskId, correlationId, error: serializeError(error) });
    if (!aborted) {
      await clearActiveTask();
      // An abort is a decision, not a failing task, so it must not throttle.
      consecutiveTaskFailures += 1;
    }
    await setBadge(leaseInvalid || String(error?.code || "") === "LEASE_LOST" ? "LEASE" : aborted ? "STOP" : "ERR", "#a32020");
  } finally {
    stopHeartbeat();
    if (taskAbortController === executionController) taskAbortController = null;
    if (!runtimeCleaned) {
      await cleanupTaskRuntime(state).catch((cleanupError) => appendLog("task.runtime_cleanup_failed", { taskId: state.taskId, error: serializeError(cleanupError) }).catch(() => {}));
    }
  }
}

async function requestFreshTaskRestart(state, error) {
  if (!state?.taskId || !state?.leaseToken) return false;
  stopHeartbeat();
  await cleanupTaskRuntime(state).catch(() => {});
  await clearTabAffinityForTask(state.taskId).catch(() => {});
  const response = await api(`/tasks/${state.taskId}/cancel`, {
    method: "POST",
    body: {
      mode: "restart_fresh",
      reason: "two_attempts_without_progress",
      evidence: {
        step_index: Number(state.stepIndex || 0),
        in_flight: state.inFlight || null,
        checkpoint_last_completed_step: Number(state?.checkpoint?.last_completed_step ?? -1),
        error: serializeError(error),
      },
    },
  });
  return Boolean(response?.fresh_restart || response?.status === "queued");
}

function stepRequiresOwnedTab(step = {}) {
  return !new Set(["agent_status", "open_tab", "ensure_page", "list_tabs", "search_console", "contract_action", "adopt_tabs", "release_lane_tabs", "local_studio", "wait"]).has(step.type);
}

async function executeStepWithWatchdog(state, step, tabId) {
  const watched = new Set(["scroll", "native_input", "screenshot", "screenshot_element", "ocr", "capture_baseline", "compare_baseline", "visual_assert", "verify_pixel", "observation_bundle"]);
  if (!watched.has(String(step?.type || ""))) return executeStep(state, step);
  const timeoutMs = stepWatchdogMs(step);
  let timer = null;
  try {
    return await Promise.race([
      executeStep(state, step),
      new Promise((_, reject) => {
        timer = setTimeout(() => {
          if (tabId) detachDebugger(tabId, "step_watchdog_timeout").catch(() => {});
          reject(codedError("STEP_STALLED_TIMEOUT", `Lo step ${String(step?.type || "unknown")} non ha prodotto un esito entro ${timeoutMs} ms.`, { type: step?.type || "", tabId, timeoutMs }));
        }, timeoutMs);
      }),
    ]);
  } finally {
    if (timer) clearTimeout(timer);
  }
}

async function executeStepWithRetry(state, step) {
  const candidateRetry = new Set(["wait_selector", "wait_url", "wait_load", "reload", "scroll", "native_input"]);
  const attempts = candidateRetry.has(step.type) ? REMOTE_MAX_STEP_ATTEMPTS : 1;
  let lastError;
  let attemptsUsed = 0;
  for (let attempt = 1; attempt <= attempts; attempt += 1) {
    attemptsUsed = attempt;
    throwIfTaskAborted();
    let url = String(step.url || "");
    const tabId = step.tabId || state.tabId || null;
    if (!url && tabId) {
      try { url = String((await chrome.tabs.get(tabId)).url || ""); } catch { /* verified later */ }
    }
    try {
      throwIfTaskAborted();
      if (tabId && stepRequiresOwnedTab(step)) await assertTargetBinding(tabId, step, state, { allowNavigationTransition: ["navigate", "history"].includes(step.type) });
      const result = await executeStepWithWatchdog(state, step, tabId);
      throwIfTaskAborted();
      const verified = await verifyStepResult(state, step, result);
      return verified;
    } catch (error) {
      lastError = error;
      await appendLog("step.attempt.failed", { type: step.type, attempt, error: serializeError(error), tabId, retrySafe: isRetrySafeFailure(step, error) });
      if (attempt >= attempts || !isRetrySafeFailure(step, error)) break;
      await cleanupTaskRuntime({ tabId }).catch(() => {});
      await abortableSleep(500, taskAbortController?.signal);
    }
  }
  if (canFreshRestart(state, step, lastError, attemptsUsed)) {
    throw codedError("FRESH_RESTART_REQUESTED", "Due tentativi tecnici non hanno prodotto progresso; richiesta ripartenza pulita del task.", {
      attempts: attemptsUsed,
      stepType: step.type,
      cause: serializeError(lastError),
      safeToRestart: true,
    });
  }
  throw lastError;
}

async function verifyPostcondition(tabId, condition = {}) {
  if (!condition || typeof condition !== "object") return { checked: false, accepted: null, reason: "none" };
  const timeoutMs = Math.max(250, Math.min(60000, Number(condition.timeoutMs || condition.timeout || 5000)));
  const expectedUrl = String(condition.url || condition.expectedUrl || "");
  const expectedPresent = condition.present === undefined ? true : Boolean(condition.present);
  if (expectedUrl) {
    try {
      const result = await waitForUrlEvent(tabId, expectedUrl, timeoutMs);
      return { checked: true, accepted: true, kind: "url", url: result.url };
    } catch (error) {
      const tab = await chrome.tabs.get(tabId).catch(() => null);
      return { checked: true, accepted: false, reason: "postcondition_timeout", last: { url: tab?.url || "" }, error: serializeError(error) };
    }
  }
  try {
    if (!expectedPresent) {
      const current = await runDomAction(tabId, "locate", condition).catch(() => null);
      if (!current?.matched) return { checked: true, accepted: true, kind: "absent" };
      // Absence after a mutation is handled by the page runtime without a polling interval.
      const result = await pageRuntimeRequest(tabId, { kind: "wait_absent", args: domRuntimeArgs(condition, "locate"), timeoutMs }, timeoutMs);
      if (result?.absent) return { checked: true, accepted: true, kind: "absent" };
    } else {
      const located = await pageRuntimeRequest(tabId, { kind: "wait_selector", args: domRuntimeArgs(condition, "locate"), timeoutMs }, timeoutMs);
      if (located?.matched) {
        const el = located.element || {};
        const textOk = condition.text === undefined || normalizeVerificationText(el.text || el.accessibleName || "").includes(normalizeVerificationText(condition.text));
        const checkedOk = condition.checked === undefined || Boolean(el.checked) === Boolean(condition.checked);
        const lengthOk = condition.valueLength === undefined || Number(el.valueLength) === Number(condition.valueLength);
        if (textOk && checkedOk && lengthOk) return { checked: true, accepted: true, kind: "element", element: el };
        return { checked: true, accepted: false, reason: "postcondition_mismatch", last: { element: el, textOk, checkedOk, lengthOk } };
      }
    }
  } catch (error) {
    return { checked: true, accepted: false, reason: "postcondition_timeout", error: serializeError(error) };
  }
  return { checked: true, accepted: false, reason: "postcondition_timeout" };
}

function normalizeVerificationText(value = "") {
  return String(value || "").replace(/\s+/g, " ").trim().toLowerCase();
}

async function verifyStepResult(state, step, result) {
  const tabId = result?.tabId || step.tabId || state.tabId || null;
  if (tabId && step.type !== "close_tab") {
    await assertTargetBinding(tabId, step, state, { allowNavigationTransition: false });
    const tab = await chrome.tabs.get(tabId);
    const url = String(tab.url || result?.url || "");
    if (!url || url === "about:blank") throw codedError("blank_tab_detected", "Il Browser Agent ha rilevato about:blank e ha interrotto il task senza retry.");
    if (step.expectedOrigin) {
      const actual = new URL(url).origin;
      if (actual !== new URL(step.expectedOrigin).origin) throw codedError("origin_mismatch", `Origine inattesa: ${actual}`);
    }
    result = { ...result, tabId, url, title: tab.title || result?.title || "" };
  }
  if (["screenshot", "screenshot_element"].includes(step.type) && !result?.artifact?.sha256) {
    throw codedError("screenshot_not_verified", "Screenshot non verificato o artefatto mancante.");
  }

  let applicationAccepted = null;
  let verificationStrength = "transport_or_ui_dispatch";
  if (["fill", "type_text"].includes(step.type) && result?.after && Number.isFinite(Number(result.after.valueLength))) {
    const inserted = String(step.value ?? "").length;
    const beforeLength = Number(result?.element?.valueLength || 0);
    const expectedLength = step.append ? beforeLength + inserted : inserted;
    applicationAccepted = Number(result.after.valueLength) === expectedLength;
    verificationStrength = "dom_readback";
  } else if (step.type === "check" && result?.after && result.after.checked !== undefined) {
    applicationAccepted = Boolean(result.after.checked) === Boolean(step.checked ?? true);
    verificationStrength = "dom_readback";
  } else if (["wait_url", "wait_selector", "wait_load", "navigate", "reload", "verify_url"].includes(step.type)) {
    applicationAccepted = true;
    verificationStrength = "observed_ui_state";
  } else if (["screenshot", "screenshot_element"].includes(step.type)) {
    applicationAccepted = true;
    verificationStrength = "artifact_integrity";
  }

  if (tabId && step.postcondition) {
    const post = await verifyPostcondition(tabId, step.postcondition);
    applicationAccepted = post.accepted;
    verificationStrength = "explicit_postcondition";
    result = { ...result, postcondition: post };
    if (step.postcondition.required && !post.accepted) {
      result = { ...result, effectWarning: "effect_unverified", degraded: true, blocking: false };
    }
  }

  const effectObserved = applicationAccepted !== false;
  const effectKnown = applicationAccepted !== null;
  return {
    ...result,
    verified: effectKnown ? effectObserved : Boolean(result?.verified ?? true),
    effectVerified: effectKnown ? effectObserved : null,
    degraded: Boolean(result?.degraded) || (effectKnown && !effectObserved),
    blocking: false,
    applicationAccepted,
    verificationStrength,
    stepType: step.type,
  };
}

function runtimeFramesForTab(tabId) {
  return pageRuntimeFrames.get(Number(tabId || 0)) || new Map();
}

function aggregateRuntimeDomVersion(tabId) {
  let fingerprint = 0;
  for (const [frameId, entry] of runtimeFramesForTab(tabId)) {
    fingerprint = (fingerprint + ((Number(frameId) + 1) * 2654435761) + Number(entry?.domVersion || 0) * 1315423911) >>> 0;
  }
  return fingerprint;
}

async function ensurePageRuntime(tabId, frameId = 0) {
  const id = Number(tabId || 0);
  const fid = Number(frameId || 0);
  if (!id) return false;
  if (runtimeFramesForTab(id).get(fid)?.port) return true;
  try {
    const pong = await chrome.tabs.sendMessage(id, { channel: "prstudio-page-runtime", kind: "ping" }, { frameId: fid });
    if (pong?.ok) return true;
  } catch { /* content script may predate this extension build */ }
  try {
    await chrome.scripting.executeScript({
      target: { tabId: id, frameIds: [fid] },
      files: ["lib/reconnect-backoff.js", "lib/runtime-dirty-notifier.js", "page-runtime.js"],
    });
    const pong = await chrome.tabs.sendMessage(id, { channel: "prstudio-page-runtime", kind: "ping" }, { frameId: fid });
    return Boolean(pong?.ok);
  } catch {
    return false;
  }
}

async function pageRuntimeRequestFrame(tabId, frameId, payload = {}, timeoutMs = 30000) {
  const id = Number(tabId || 0);
  const fid = Number(frameId || 0);
  const bounded = Math.max(250, Math.min(120000, Number(timeoutMs || 30000)));
  const available = await ensurePageRuntime(id, fid);
  if (!available) throw codedError("page_runtime_unavailable", "Runtime pagina persistente non disponibile.", { tabId: id, frameId: fid });
  const entry = runtimeFramesForTab(id).get(fid) || (fid === 0 ? pageRuntimePorts.get(id) : null);
  if (!entry?.port) {
    const response = await promiseWithTimeout(
      chrome.tabs.sendMessage(id, { channel: "prstudio-page-runtime", ...payload }, { frameId: fid }),
      bounded,
      () => codedError("page_runtime_timeout", "Runtime pagina oltre il timeout.", { tabId: id, frameId: fid, kind: payload.kind || "" }),
    );
    if (!response?.ok) throw codedError(response?.error || "page_runtime_error", response?.message || "Runtime pagina fallito.");
    return response.result;
  }
  const requestId = `p${++pageRuntimeRequestSequence}_${Date.now().toString(36)}`;
  const key = `${id}:${fid}:${requestId}`;
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => {
      pageRuntimePending.delete(key);
      reject(codedError("page_runtime_timeout", "Runtime pagina oltre il timeout.", { tabId: id, frameId: fid, kind: payload.kind || "" }));
    }, bounded);
    pageRuntimePending.set(key, { resolve, reject, timer });
    try {
      entry.port.postMessage({ type: "runtime_request", id: requestId, payload });
    } catch (error) {
      pageRuntimePending.delete(key);
      clearTimeout(timer);
      reject(error);
    }
  });
}

async function pageRuntimeRequest(tabId, payload = {}, timeoutMs = 30000) {
  return pageRuntimeRequestFrame(tabId, 0, payload, timeoutMs);
}

async function pageRuntimeRequestAll(tabId, payload = {}, timeoutMs = 30000) {
  const id = Number(tabId || 0);
  await ensurePageRuntime(id, 0).catch(() => false);
  const frames = [...runtimeFramesForTab(id).entries()];
  if (!frames.length) return [];
  const settled = await Promise.all(frames.map(async ([frameId, entry]) => {
    try {
      const result = await pageRuntimeRequestFrame(id, frameId, payload, timeoutMs);
      return { ok: true, frameId, frameUrl: String(entry?.url || result?.url || ""), result };
    } catch (error) {
      return { ok: false, frameId, frameUrl: String(entry?.url || ""), error: serializeError(error) };
    }
  }));
  return settled;
}

function annotateFrameResult(row) {
  if (!row?.result) return row;
  const result = { ...row.result, frameId: Number(row.frameId || 0), frameUrl: String(row.frameUrl || row.result?.url || "") };
  if (result.element) result.element = { ...result.element, frameId: result.frameId, frameUrl: result.frameUrl };
  if (Array.isArray(result.interactive)) result.interactive = result.interactive.map((item) => ({ ...item, frameId: result.frameId, frameUrl: result.frameUrl }));
  return { ...row, result };
}

async function locateAcrossRuntimeFrames(tabId, action, args, timeoutMs) {
  const rows = (await pageRuntimeRequestAll(tabId, { kind: "dom_action", action, args }, timeoutMs)).filter((row) => row.ok).map(annotateFrameResult);
  const matches = rows.filter((row) => row.result?.ok && row.result?.matched && row.result?.element);
  if (!matches.length) return null;
  if (matches.length === 1) return matches[0].result;
  const query = {
    targetRef: args.targetRef || "", selector: args.selector || "", role: args.role || "", name: args.name || "",
    text: args.text || "", label: args.label || "", intendedAction: args.intendedAction || action,
  };
  const ranked = bestSemanticTarget(matches.map((row) => row.result.element), query);
  if (ranked?.target) {
    const selected = matches.find((row) => row.result.element === ranked.target || (row.result.element.targetRef === ranked.target.targetRef && row.result.element.frameId === ranked.target.frameId));
    if (selected) return { ...selected.result, crossFrameSelection: { score: ranked.score, semanticStrength: ranked.semanticStrength, reasons: ranked.reasons, candidates: matches.length } };
  }
  matches.sort((a, b) => Number(b.result?.match?.score || 0) - Number(a.result?.match?.score || 0));
  return { ...matches[0].result, crossFrameSelection: { score: Number(matches[0].result?.match?.score || 0), candidates: matches.length } };
}

async function snapshotAcrossRuntimeFrames(tabId, args, timeoutMs) {
  const rows = (await pageRuntimeRequestAll(tabId, { kind: "dom_action", action: "page_snapshot", args }, timeoutMs)).filter((row) => row.ok && row.result?.ok).map(annotateFrameResult);
  if (!rows.length) return null;
  const main = rows.find((row) => Number(row.frameId) === 0)?.result || rows[0].result;
  const interactive = rows.flatMap((row) => row.result.interactive || []);
  const frameTexts = rows.map((row) => String(row.result.text || "").trim()).filter(Boolean);
  return {
    ...main,
    text: frameTexts.join("\n\n").slice(0, Math.max(32_768, Math.min(1_000_000, Number(args.maxChars || 200_000)))),
    interactive,
    frames: rows.map((row) => ({ frameId: row.result.frameId, url: row.result.frameUrl, title: row.result.title || "", domVersion: row.result.runtime?.domVersion || 0, interactiveCount: row.result.interactive?.length || 0 })),
    runtime: { mode: "persistent_incremental_multiframe", domVersion: aggregateRuntimeDomVersion(tabId), indexedInteractive: interactive.length, frameCount: rows.length },
    interactionMap: main.interactionMap ? { ...main.interactionMap, count: interactive.length, frameCount: rows.length } : null,
  };
}

function waitForUrlEvent(tabId, expectedUrl, timeoutMs = 30000) {
  const id = Number(tabId || 0);
  const timeout = Math.max(250, Math.min(120000, Number(timeoutMs || 30000)));
  return new Promise((resolve, reject) => {
    let done = false;
    const finish = (error, tab) => {
      if (done) return;
      done = true;
      clearTimeout(timer);
      chrome.tabs.onUpdated.removeListener(onUpdated);
      chrome.tabs.onRemoved.removeListener(onRemoved);
      if (error) reject(error); else resolve({ tabId: id, url: String(tab?.url || "") });
    };
    const onUpdated = (updatedId, changeInfo, tab) => {
      if (Number(updatedId) !== id) return;
      const url = String(changeInfo.url || tab?.url || "");
      if (url && urlMatches(url, expectedUrl || "")) finish(null, { ...tab, url });
    };
    const onRemoved = (removedId) => {
      if (Number(removedId) === id) finish(codedError("tab_removed", "Scheda rimossa durante wait_url."));
    };
    const timer = setTimeout(() => finish(codedError("wait_url_timeout", `URL non raggiunto: ${expectedUrl}`)), timeout);
    chrome.tabs.onUpdated.addListener(onUpdated);
    chrome.tabs.onRemoved.addListener(onRemoved);
    chrome.tabs.get(id).then((tab) => {
      if (urlMatches(tab?.url || "", expectedUrl || "")) finish(null, tab);
    }).catch((error) => finish(error));
  });
}

async function waitForSpaReady(tabId, timeoutMs = 30000, selector = "") {
  try {
    const result = await pageRuntimeRequest(tabId, {
      kind: "wait_ready",
      selector: String(selector || ""),
      timeoutMs: Math.max(250, Math.min(60000, Number(timeoutMs || 30000))),
    }, timeoutMs);
    return { ready: true, strategy: "event_driven_page_runtime", ...result };
  } catch (error) {
    // One-shot fallback for pages where content scripts cannot run; no polling loop.
    const rows = await chrome.scripting.executeScript({
      target: { tabId, allFrames: false },
      func: (readySelector) => {
        const ready = document.readyState === "interactive" || document.readyState === "complete";
        let selectorReady = true;
        if (readySelector) { try { selectorReady = Boolean(document.querySelector(readySelector)); } catch { selectorReady = false; } }
        return { ready, selectorReady, bodyLength: String(document.body?.innerText || document.body?.textContent || "").length, href: location.href };
      },
      args: [String(selector || "")],
    }).catch(() => []);
    const sample = rows?.[0]?.result;
    if (sample?.ready && sample?.selectorReady) return { ready: true, strategy: "one_shot_fallback", ...sample };
    throw error;
  }
}

async function executeJavascriptSource(tabId, sourceInput, timeoutMs = 30000) {
  const source = String(sourceInput || "");
  if (!source.trim()) throw codedError("javascript_source_required", "JavaScript source mancante.");
  if (source.length > 262_144) throw codedError("javascript_source_too_large", "JavaScript source oltre 256 KiB.", { bytes: source.length });
  await assertOwnedTab(tabId);
  await attachDebuggerIfNeeded(tabId);
  // Runtime.evaluate is deliberately not exposed through raw CDP. This typed
  // executor fixes the method and the safe return options, preserving a fast
  // direct-JS primitive without opening an arbitrary CDP command surface.
  const response = await debuggerCommandWithTimeout(
    tabId,
    "Runtime.evaluate",
    { expression: source, awaitPromise: true, returnByValue: true, userGesture: true, silent: false },
    Math.max(250, Math.min(120000, Number(timeoutMs || 30000))),
    "javascript_exec_timeout",
  );
  if (response?.exceptionDetails) {
    const text = String(response.exceptionDetails?.exception?.description || response.exceptionDetails?.text || "JavaScript execution failed");
    throw codedError("javascript_exec_failed", text.slice(0, 4000), { lineNumber: response.exceptionDetails?.lineNumber ?? null, columnNumber: response.exceptionDetails?.columnNumber ?? null });
  }
  const remote = response?.result || {};
  const value = Object.prototype.hasOwnProperty.call(remote, "value") ? remote.value : (remote.unserializableValue ?? null);
  let serialized = "";
  try { serialized = JSON.stringify(value); } catch { serialized = String(value ?? ""); }
  if (serialized.length > 1_000_000) {
    return { tabId, ok: true, type: remote.type || typeof value, subtype: remote.subtype || "", truncated: true, preview: serialized.slice(0, 1_000_000), bytes: serialized.length };
  }
  return { tabId, ok: true, type: remote.type || typeof value, subtype: remote.subtype || "", value, bytes: serialized.length };
}

async function executeStep(state, step) {
  switch (step.type) {
    case "agent_status": {
      const config = await getConfig();
      return { capabilities: capabilities(config?.serverCapabilities || null), agentWindowId: await getAgentWindowId(), tabs: await listOwnedTabs(), tabAffinity: await getTabAffinity() };
    }
    case "adopt_tabs":
      return adoptUserTabs(state, step);
    case "release_lane_tabs":
      return releaseLaneTabs(step.laneId || state?.arguments?._prstudio_lane_id || "");
    case "local_studio":
      return executeRemoteLocalStudio(state, step);
    case "ensure_page":
      return ensureOwnedPage(state, step);
    case "open_tab": {
      return createOwnedAgentTab(state, step.url, {
        taskId: state?.taskId || "",
        laneId: String(state?.arguments?._prstudio_lane_id || ""),
        expectedOrigin: step.expectedOrigin || "",
        waitUntil: step.waitUntil || "interactive",
        timeoutMs: Number(step.timeoutMs || 45000),
        reason: "open_tab",
      });
    }
    case "close_tab": {
      const tabId = await resolveTabId(state, step);
      const record = await assertOwnedTab(tabId);
      await unregisterOwnedTab(tabId);
      try {
        await chrome.tabs.remove(tabId);
      } catch (error) {
        await registerOwnedTab(tabId, record).catch(() => {});
        throw error;
      }
      return { tabId, closed: true };
    }
    case "list_tabs": {
      // Bringing a tab to the front is listed with the tabs rather than given a
      // tool of its own, the way the reference groups it. It matters more than
      // it looks: a tab that is not in the foreground has no compositor
      // surface, so this is the direct answer when a capture needs one.
      if (step.activate) {
        const target = await resolveTabId(state, step);
        await assertOwnedTab(target);
        await chrome.tabs.update(target, { active: true });
        const tab = await chrome.tabs.get(target);
        await chrome.windows.update(tab.windowId, { focused: false }).catch(() => {});
        return { tabId: target, activated: true, windowId: tab.windowId };
      }
      const laneId = String(state?.arguments?._prstudio_lane_id || "");
      const all = await listOwnedTabs();
      const tabs = laneId ? all.filter((tab) => String(tab?.laneId || "") === laneId) : all;
      return { tabs, count: tabs.length, laneId: laneId || null, isolated: Boolean(laneId) };
    }
    case "navigate": {
      const tabId = await resolveTabId(state, step);
      const url = validateNavigationUrl(step.url);
      await chrome.tabs.update(tabId, { url });
      await waitForTab(tabId, step.waitUntil || "interactive", 45000);
      const tab = await chrome.tabs.get(tabId);
      await updateOwnedTab(tabId, { url: tab.url || url, title: tab.title || "", expectedOrigin: step.expectedOrigin || new URL(url).origin, taskId: state.taskId });
      await bindTabAffinity(state, tabId, "navigate");
      return { tabId, url: tab.url || url, title: tab.title || "", background: true };
    }
    case "reload": {
      const tabId = await resolveTabId(state, step);
      await chrome.tabs.reload(tabId);
      await waitForTab(tabId, "interactive", 45000);
      return { tabId, reloaded: true };
    }
    case "history": {
      const tabId = await resolveTabId(state, step);
      await attachDebugger(tabId);
      const history = await cdp(tabId, "Page.getNavigationHistory", {});
      const delta = step.direction === "back" ? -1 : 1;
      const target = history.entries?.find((entry) => Number(entry.id) === Number(history.currentIndex + delta));
      if (!target) throw codedError("history_entry_missing", `Nessuna navigazione ${step.direction} disponibile.`);
      await cdp(tabId, "Page.navigateToHistoryEntry", { entryId: target.id });
      await waitForTab(tabId, "interactive", 45000).catch(() => {});
      const loaded = await chrome.tabs.get(tabId);
      const loadedUrl = validateNavigationUrl(loaded.url || target.url || "");
      await updateOwnedTab(tabId, { url: loadedUrl, title: loaded.title || "", expectedOrigin: new URL(loadedUrl).origin, taskId: state.taskId });
      return { tabId, direction: step.direction, url: loadedUrl, title: loaded.title || "" };
    }
    case "wait_load": {
      const tabId = await resolveTabId(state, step);
      const requestedState = String(step.state || "complete").toLowerCase();
      const effectiveState = ["networkidle", "network_idle", "interactive", "domcontentloaded"].includes(requestedState) ? "interactive" : requestedState;
      await waitForTab(tabId, effectiveState, step.timeoutMs || 30000);
      const tab = await chrome.tabs.get(tabId);
      const host = (() => { try { return new URL(tab.url || "").hostname; } catch { return ""; } })();
      const spaStrategy = ["networkidle", "network_idle", "interactive", "domcontentloaded"].includes(requestedState) || /(^|\.)search\.google\.com$/.test(host);
      let readiness = null;
      if (spaStrategy) readiness = await waitForSpaReady(tabId, step.timeoutMs || 30000, step.selector || "");
      return { tabId, state: effectiveState, requestedState, effectiveState, strategy: spaStrategy ? "dom_ready_selector_readiness" : "tab_load_state", readiness };
    }
    case "wait_url": {
      const tabId = await resolveTabId(state, step);
      return waitForUrlEvent(tabId, step.url || "", step.timeoutMs || 30000);
    }
    case "verify_url": {
      const tabId = await resolveTabId(state, step);
      const ownership = await assertOwnedTab(tabId);
      const tab = await chrome.tabs.get(tabId);
      const evidence = compareUrlEvidence(tab.url || "", step.url || "");
      const result = {
        tabId,
        url: String(tab.url || ""),
        title: String(tab.title || ""),
        matched: evidence.matched,
        expectedUrl: evidence.expected,
        normalizedActual: evidence.normalizedActual,
        normalizedExpected: evidence.normalizedExpected,
        matchStrategy: evidence.matchStrategy,
        evidence: {
          observedAt: new Date().toISOString(),
          ownedTab: true,
          ownershipType: ownership.adoptedExternal ? "explicitly_adopted" : "agent_created",
          ...evidence,
        },
      };
      if (!evidence.matched) {
        return { ...result, verified: false, degraded: true, blocking: false, reason: "url_effect_unverified" };
      }
      return { ...result, verified: true, degraded: false, blocking: false };
    }
    case "wait_selector": {
      const tabId = await resolveTabId(state, step);
      const runtimeArgs = domRuntimeArgs(step, "locate");
      try {
        const result = await pageRuntimeRequest(tabId, { kind: "wait_selector", args: runtimeArgs, timeoutMs: Number(step.timeoutMs || 30000) }, step.timeoutMs || 30000);
        if (result?.ok && result?.matched) return { tabId, ...result };
      } catch (error) {
        const located = await runDomAction(tabId, "locate", step).catch(() => null);
        if (located?.matched) return located;
        throw error;
      }
      throw codedError("element_not_found", "Elemento non trovato entro il timeout.");
    }
    case "click":
    case "hover":
    case "fill":
    case "type_text":
    case "press": {
      const tabId = await resolveTabId(state, step);
      return runNativeElementAction(tabId, step.type, step);
    }
    case "focus":
    case "blur":
    case "select":
    case "check":
    case "extract_text":
    case "dom_snapshot":
    case "page_snapshot":
    case "computed_styles": {
      const tabId = await resolveTabId(state, step);
      let result = await runDomAction(tabId, step.type, step);
      if (["extract_text", "page_snapshot"].includes(step.type)) {
        const currentUrl = String(result?.url || (await chrome.tabs.get(tabId)).url || "");
        const sparse = String(result?.text || "").trim().length < 800;
        if (sparse || /(^|\.)instagram\.com|(^|\.)facebook\.com|(^|\.)linkedin\.com/i.test((() => { try { return new URL(currentUrl).hostname; } catch { return ""; } })())) {
          result = { ...result, publicFallback: await extractPublicPageFallback(tabId).catch(() => null) };
        }
      }
      return result;
    }
    case "native_input": {
      const tabId = await resolveTabId(state, step);
      const commands = step.mode === "pointer_sequence" ? pointerSequence(step.events || [])
        : step.mode === "keyboard_sequence" ? keyboardSequence(step.events || [])
        : (() => { throw codedError("native_input_mode_invalid", `Modalità input nativo non valida: ${step.mode}`); })();
      return { tabId, mode: step.mode, ...(await dispatchNativeCommands(tabId, commands)) };
    }
    case "scroll": {
      const tabId = await resolveTabId(state, step);
      // A reference means "put this element where I can see it", which is a
      // different request from "move the page by this much". The click path has
      // always scrolled its target into view before dispatching; nothing let a
      // caller ask for that on its own, so reading or photographing something
      // below the fold had no reliable way to bring it up.
      if (step.targetRef || step.target_ref || step.selector || step.role || step.name || step.label) {
        await attachDebugger(tabId);
        const located = await locateViaAccessibilityCdp(tabId, domRuntimeArgs(step, "scroll"));
        if (located?.element) {
          return { tabId, scrolledIntoView: true, element: located.element, point: located.point || null };
        }
        return { tabId, scrolledIntoView: false, reason: "target_not_located" };
      }
      if (step.progressive || step.to === "bottom") return progressiveScroll(tabId, { restore: false });
      const dimensions = await pageDimensions(tabId);
      const commands = pointerSequence([{ type: "wheel", x: Math.round(dimensions.viewportWidth / 2), y: Math.round(dimensions.viewportHeight / 2), deltaX: Number(step.x || 0), deltaY: Number(step.y || 0) }]);
      return { tabId, scroll: { x: Number(step.x || 0), y: Number(step.y || 0) }, ...(await dispatchNativeCommands(tabId, commands)) };
    }
    case "accessibility_snapshot": {
      const tabId = await resolveTabId(state, step);
      await attachDebugger(tabId);
      const tree = await cdp(tabId, "Accessibility.getFullAXTree", {});
      return { tabId, nodes: tree.nodes || [], nodeCount: tree.nodes?.length || 0 };
    }
    case "find_elements": {
      // Ask what matches, instead of being handed one silent guess. The refs
      // returned here are the same targetRef the click path resolves, so the
      // model reads the candidates, picks, and acts on that exact element.
      const tabId = await resolveTabId(state, step);
      await attachDebugger(tabId);
      return { tabId, ...(await findElementCandidates(tabId, step)) };
    }
    case "screenshot": {
      const tabId = await resolveTabId(state, step);
      const deadlineAt = Date.now() + SCREENSHOT_STEP_TIMEOUT_MS;
      const captureDeadlineAt = Math.min(deadlineAt - SCREENSHOT_UPLOAD_TIMEOUT_MS, Date.now() + SCREENSHOT_CAPTURE_TIMEOUT_MS);
      const image = await captureScreenshot(tabId, Boolean(step.fullPage), Boolean(step.lazyLoad), { region: step.region || null, scale: Number(step.scale || 0) || 0, format: step.format, quality: step.quality, maxPixels: step.maxPixels || step.max_pixels, deadlineAt: captureDeadlineAt });
      return {
        tabId, artifact: await storeScreenshotArtifact(image, state, null, { deadlineAt }), width: image.width, height: image.height,
        requestedWidth: image.requestedWidth, requestedHeight: image.requestedHeight, fullPage: Boolean(step.fullPage),
        fullPageComplete: image.fullPageComplete, truncatedForSafety: image.truncatedForSafety, format: image.format,
        captureMode: image.captureMode, downgradeLevel: image.downgradeLevel, scroll: image.scroll || null
      };
    }
    case "screenshot_element": {
      const tabId = await resolveTabId(state, step);
      const deadlineAt = Date.now() + SCREENSHOT_STEP_TIMEOUT_MS;
      const located = await runDomAction(tabId, "locate", step);
      const captureDeadlineAt = Math.min(deadlineAt - SCREENSHOT_UPLOAD_TIMEOUT_MS, Date.now() + SCREENSHOT_CAPTURE_TIMEOUT_MS);
      const image = await captureElementScreenshot(tabId, located.element?.boundingBox, { deadlineAt: captureDeadlineAt });
      return { tabId, element: located.element, artifact: await storeScreenshotArtifact(image, state, null, { deadlineAt }), width: image.width, height: image.height };
    }
    case "pdf": {
      const tabId = await resolveTabId(state, step);
      await attachDebugger(tabId);
      const printed = await cdp(tabId, "Page.printToPDF", { landscape: Boolean(step.landscape), printBackground: step.printBackground !== false });
      return { tabId, available: true, format: "pdf", bytes: Math.floor((printed.data?.length || 0) * 0.75), sha256: await sha256Text(printed.data || ""), contentOmitted: true };
    }
    case "network_report": {
      const tabId = await resolveTabId(state, step);
      await attachDebugger(tabId);
      return { tabId, events: networkBuffers.get(tabId) || [] };
    }
    case "console_report": {
      const tabId = await resolveTabId(state, step);
      await attachDebugger(tabId);
      return { tabId, events: consoleBuffers.get(tabId) || [] };
    }
    case "page_errors": {
      const tabId = await resolveTabId(state, step);
      await attachDebugger(tabId);
      const events = (consoleBuffers.get(tabId) || []).filter((item) => ["Runtime.exceptionThrown", "Log.entryAdded"].includes(item.method));
      return { tabId, events, count: events.length };
    }
    case "headers": {
      const tabId = await resolveTabId(state, step);
      await attachDebugger(tabId);
      const events = (networkBuffers.get(tabId) || []).filter((item) => item.method === "Network.responseReceived");
      const latest = events.at(-1)?.params?.response || null;
      return { tabId, response: latest ? { url: latest.url || "", status: latest.status, mimeType: latest.mimeType || "", headers: latest.headers || {} } : null };
    }
    case "cdp": {
      const tabId = await resolveTabId(state, step);
      return { tabId, result: await cdp(tabId, step.method, step.params || {}, "raw") };
    }
    case "emulation": {
      const tabId = await resolveTabId(state, step);
      return { tabId, ...(await applyEmulation(tabId, step.command, step.value)) };
    }
    case "service_workers": {
      const tabId = await resolveTabId(state, step);
      const tab = await chrome.tabs.get(tabId);
      const ownedOrigin = new URL(tab.url || "").origin;
      const rows = await chrome.scripting.executeScript({
        target: { tabId, allFrames: false },
        func: async () => {
          if (!("serviceWorker" in navigator)) return { available: false, controller: null, registrations: [] };
          const registrations = await navigator.serviceWorker.getRegistrations();
          const worker = (value) => value ? { scriptURL: value.scriptURL || "", state: value.state || "" } : null;
          return {
            available: true,
            controller: worker(navigator.serviceWorker.controller),
            registrations: registrations.slice(0, 100).map((registration) => ({
              scope: registration.scope || "", updateViaCache: registration.updateViaCache || "",
              installing: worker(registration.installing), waiting: worker(registration.waiting), active: worker(registration.active),
            })),
          };
        },
      });
      return { tabId, origin: ownedOrigin, ...(rows?.[0]?.result || { available: false, registrations: [] }) };
    }
    case "accessibility_scan": {
      const tabId = await resolveTabId(state, step);
      return runDomAction(tabId, "accessibility_scan", step);
    }
    case "core_web_vitals": {
      const tabId = await resolveTabId(state, step);
      const rows = await chrome.scripting.executeScript({
        target: { tabId, frameIds: [0] },
        world: "MAIN",
        func: () => {
          const runtime = globalThis.__PRSTUDIO_WEB_VITALS__;
          if (!runtime || typeof runtime.snapshot !== "function") {
            return {
              source: "performance_observer_runtime_unavailable",
              metrics: { LCP: null, CLS: null, INP: null },
              supported: { lcp: false, cls: false, inp: false, softNavigation: false },
            };
          }
          return runtime.snapshot();
        },
      });
      const vitals = rows?.[0]?.result || null;
      return {
        tabId,
        provider: "web_vitals_6_0_1_semantics",
        provenance: "GoogleChrome/web-vitals v6.0.1 algorithm-compatible MAIN-world PerformanceObserver collector",
        ...(vitals || { source: "collector_no_result", metrics: { LCP: null, CLS: null, INP: null } }),
      };
    }
    case "capture_baseline": {
      const tabId = await resolveTabId(state, step);
      const image = await captureScreenshot(tabId, true, true);
      let hash;
      try { hash = await sha256Text(image.dataUrl); }
      finally { releaseScreenshotBuffer(image); }
      const baselines = await getBaselines();
      baselines[step.name || "default"] = { hash, tabId, url: (await chrome.tabs.get(tabId)).url, at: Date.now() };
      await chrome.storage.local.set({ [STORAGE_KEYS.BASELINES]: baselines });
      return { tabId, baseline: step.name || "default", hash };
    }
    case "compare_baseline":
    case "visual_assert":
    case "verify_pixel": {
      const tabId = await resolveTabId(state, step);
      const image = await captureScreenshot(tabId, true, true);
      let hash;
      try { hash = await sha256Text(image.dataUrl); }
      finally { releaseScreenshotBuffer(image); }
      const baselines = await getBaselines();
      const base = baselines[step.name || "default"] || null;
      return { tabId, baseline: step.name || "default", currentHash: hash, baselineHash: base?.hash || "", equal: Boolean(base && base.hash === hash) };
    }
    case "verify_dom": {
      const tabId = await resolveTabId(state, step);
      const snapshot = await runDomAction(tabId, "page_snapshot", { ...step, includeInteractive: true });
      const expectedText = String(step.expected?.text || step.expected?.contains || "");
      return { tabId, matched: expectedText ? snapshot.text.includes(expectedText) : true, snapshot };
    }
    case "dialog": {
      const tabId = await resolveTabId(state, step);
      await attachDebugger(tabId);
      await cdp(tabId, "Page.handleJavaScriptDialog", { accept: Boolean(step.accept), promptText: step.promptText || undefined });
      return { tabId, accepted: Boolean(step.accept) };
    }
    case "ocr": {
      const tabId = await resolveTabId(state, step);
      const image = await captureScreenshot(tabId, false, false);
      try {
        const artifact = await storeScreenshotArtifact(image, state, null, { releaseData: false });
        const ocr = await runOcr(image, step.language || "ita+eng");
        return { tabId, artifact, ocr };
      } finally {
        releaseScreenshotBuffer(image);
      }
    }
    case "perception_frame": {
      const tabId = await resolveTabId(state, step);
      return capturePerceptionFrame(tabId, state, { viewerOnly: Boolean(step.viewerOnly || step.viewer_only), maxPixels: step.maxPixels || step.max_pixels });
    }
    case "javascript_exec": {
      const tabId = await resolveTabId(state, step);
      return executeJavascriptSource(tabId, step.script || step.expression || "", step.timeoutMs || step.timeout || 30000);
    }
    case "webmcp_discover": {
      const tabId = await resolveTabId(state, step);
      return { tabId, ...(await discoverWebMcpTools(tabId)) };
    }
    case "webmcp_invoke": {
      const tabId = await resolveTabId(state, step);
      return { tabId, ...(await invokeWebMcpTool(tabId, step.toolName || step.tool_name, step.input || {})) };
    }
    case "design_audit": {
      const tabId = await resolveTabId(state, step);
      return captureDesignAudit(tabId);
    }
    case "motion_audit": {
      const tabId = await resolveTabId(state, step);
      return captureMotionAudit(tabId);
    }
    case "observation_bundle": {
      const tabId = await resolveTabId(state, step);
      return captureObservationBundle(tabId, { ...step, includeScreenshot: step.includeScreenshot !== false }, state);
    }
    case "social_snapshot": {
      const tabId = await resolveTabId(state, step);
      return captureSocialSnapshot(tabId, step, state);
    }
    case "search_console":
      return executeSearchConsoleStep(state, step);
    case "contract_action":
      return executeKnownContractAction(state, step);
    case "wait":
      await sleep(Number(step.ms || 1000));
      return { waitedMs: Number(step.ms || 1000) };
    default:
      throw codedError("contract_executor_missing", `Passaggio ${step.type} non presente nel runtime Browser Agent 1.0.0.`);
  }
}

function domRuntimeArgs(step = {}, action = "") {
  return {
    targetRef: step.targetRef || step.target_ref || "",
    selector: step.selector || "",
    selectorType: step.selectorType || "auto",
    text: step.text || "",
    role: step.role || "",
    name: step.name || "",
    label: step.label || "",
    xpath: step.xpath || "",
    coordinates: step.coordinates || null,
    value: step.value ?? step.checked ?? "",
    key: step.key || "",
    append: Boolean(step.append),
    clickCount: Number(step.clickCount || 1),
    x: Number(step.x || 0),
    y: Number(step.y || 0),
    to: step.to || "",
    properties: Array.isArray(step.properties) ? step.properties : [],
    includeInteractive: Boolean(step.includeInteractive),
    maxChars: Number(step.maxChars || step.max_chars || 0),
    keyDelayMs: Number(step.keyDelayMs ?? 0),
    intendedAction: step.intendedAction || action,
  };
}

function axProp(node, name) {
  const item = (node?.properties || []).find((prop) => String(prop?.name || "") === String(name || ""));
  return item?.value?.value;
}

function quadBox(model = {}) {
  const quad = model?.border || model?.content || model?.padding || [];
  if (!Array.isArray(quad) || quad.length < 8) return null;
  const xs = [quad[0], quad[2], quad[4], quad[6]].map(Number);
  const ys = [quad[1], quad[3], quad[5], quad[7]].map(Number);
  if (![...xs, ...ys].every(Number.isFinite)) return null;
  const left = Math.min(...xs), right = Math.max(...xs), top = Math.min(...ys), bottom = Math.max(...ys);
  return { x: left, y: top, pageX: left, pageY: top, width: Math.max(0, right - left), height: Math.max(0, bottom - top) };
}

function axDescriptor(node, sessionId = "") {
  const role = String(node?.role?.value || "").toLowerCase();
  const name = String(node?.name?.value || "").replace(/\s+/g, " ").trim();
  const value = String(node?.value?.value ?? "").replace(/\s+/g, " ").trim();
  const disabled = Boolean(axProp(node, "disabled"));
  const focusable = Boolean(axProp(node, "focusable"));
  const editable = Boolean(axProp(node, "editable")) || ["textbox", "searchbox", "combobox", "spinbutton"].includes(role);
  const clickable = ["button", "link", "menuitem", "tab", "checkbox", "radio", "switch", "option"].includes(role) || Boolean(axProp(node, "hasPopup"));
  return {
    targetRef: `ax_${String(sessionId || "root")}_${String(node?.nodeId || node?.backendDOMNodeId || "")}`,
    role,
    accessibleName: name,
    text: name || value,
    label: name,
    context: String(node?.description?.value || ""),
    clickable,
    focusable,
    contentEditable: editable,
    disabled,
    ariaHidden: Boolean(axProp(node, "hidden")),
    pointerEventsNone: false,
    inViewport: true,
    occluded: undefined,
    inDialog: Boolean(axProp(node, "modal")),
    centerDistance: 0.5,
    selector: "",
    frameId: String(node?.frameId || ""),
    backendDOMNodeId: Number(node?.backendDOMNodeId || 0),
    cdpSessionId: String(sessionId || ""),
    source: "cdp_accessibility",
  };
}

async function cdpAxNodes(tabId, sessionId = "", frameId = "") {
  const params = frameId ? { frameId: String(frameId) } : {};
  try {
    const response = sessionId
      ? await debuggerSessionCommandWithTimeout(tabId, sessionId, "Accessibility.getFullAXTree", params, 5000)
      : await debuggerCommandWithTimeout(tabId, "Accessibility.getFullAXTree", params, 5000, "ax_tree_timeout");
    return Array.isArray(response?.nodes) ? response.nodes : [];
  } catch { return []; }
}

// Every addressable element on the page, across every frame, as scoreable
// descriptors each carrying a stable targetRef.
//
// Extracted from locateViaAccessibilityCdp so the resolver and the candidate
// listing share one collection. Two independent walks of the accessibility
// tree would eventually disagree about what exists, and offering the model an
// element that the click path cannot then resolve is worse than offering none.
async function collectAxCandidates(tabId) {
  const hasSemantic = Boolean(args.role || args.name || args.text || args.label);
  if (!hasSemantic) return null;
  await attachDebuggerIfNeeded(tabId);
  await ensureFrameAutoAttach(tabId).catch(() => {});
  const candidates = [];
  let frameIds = [];
  try {
    const tree = await debuggerCommandWithTimeout(tabId, "Page.getFrameTree", {}, 5000, "frame_tree_timeout");
    const walk = (row) => { if (!row?.frame) return; frameIds.push(String(row.frame.id || "")); for (const child of row.childFrames || []) walk(child); };
    walk(tree?.frameTree);
  } catch { frameIds = []; }
  if (!frameIds.length) frameIds = [""];
  for (const frameId of frameIds.slice(0, 64)) {
    const nodes = await cdpAxNodes(tabId, "", frameId);
    for (const node of nodes) if (!node?.ignored && node?.backendDOMNodeId) candidates.push(axDescriptor(node, ""));
  }
  for (const [sessionId] of [...(cdpChildSessionsByTab.get(Number(tabId)) || new Map()).entries()].slice(0, 64)) {
    const nodes = await cdpAxNodes(tabId, sessionId, "");
    for (const node of nodes) if (!node?.ignored && node?.backendDOMNodeId) candidates.push(axDescriptor(node, sessionId));
  }
  return candidates;
}


/**
 * List the elements that match a description, instead of silently choosing one.
 *
 * WHY THIS EXISTS
 * ---------------
 * locateViaAccessibilityCdp ranks every element on the page and returns the top
 * one, and returns null when its score falls below 75. The model never sees the
 * alternatives and never sees the score. So a resolver that picked the wrong
 * element and a resolver that found nothing are indistinguishable from outside,
 * and neither is correctable: there is nothing to correct it with.
 *
 * The reference tooling works the other way round. You ask for "the add to cart
 * button for the first product", you get back the candidates with their roles
 * and accessible names, you read them, you pick, and you act on that reference.
 * On a real catalogue page that returns two dozen buttons whose names differ
 * only by product, and choosing correctly is trivial for a reader and a coin
 * flip for a scorer.
 *
 * The pieces were already here. axDescriptor emits a stable targetRef,
 * scoreTarget gives an exact targetRef match 1000 points, and
 * rankSemanticTargets already takes a limit. What was missing was any way to
 * ask.
 *
 * The 75-point floor is deliberately NOT applied. A weak match the model can
 * see and reject is strictly better than a null it cannot interpret.
 *
 * @param {number} tabId Tab to search.
 * @param {object} args query/role/name/text/label/limit.
 * @returns {Promise<object>} Candidates with refs, ordered best first.
 */
async function findElementCandidates(tabId, args = {}) {
  const phrase = String(args.query || "").trim().slice(0, 400);
  const limit = Math.max(1, Math.min(20, Number(args.limit || 20)));

  // A natural-language phrase is matched against every lexical field, because a
  // person describing "the add to cart button" may be naming the accessible
  // name, the visible text or the label, and does not know which.
  const query = {
    role: String(args.role || "").toLowerCase(),
    name: String(args.name || phrase || ""),
    text: String(args.text || phrase || ""),
    label: String(args.label || phrase || ""),
    intendedAction: String(args.intended_action || args.intendedAction || "click"),
  };

  const candidates = await collectAxCandidates(tabId);
  // Rank one past the limit so "there are more" is a fact rather than a guess.
  const ranked = rankSemanticTargets(candidates, query, { limit: limit + 1 });
  const truncated = ranked.length > limit;
  const shown = ranked.slice(0, limit);

  return {
    query: phrase,
    scanned: candidates.length,
    returned: shown.length,
    truncated,
    // Mirrors what the reference tool tells a caller: narrow the description
    // rather than paging, because the ranking is only meaningful near the top.
    guidance: truncated
      ? "More elements matched than are shown. Describe the target more specifically rather than asking for more results."
      : "",
    elements: shown.map((row) => ({
      target_ref: String(row?.target?.targetRef || ""),
      role: String(row?.target?.role || ""),
      name: String(row?.target?.accessibleName || ""),
      text: String(row?.target?.text || "").slice(0, 200),
      label: String(row?.target?.label || "").slice(0, 200),
      clickable: Boolean(row?.target?.clickable),
      editable: Boolean(row?.target?.contentEditable),
      disabled: Boolean(row?.target?.disabled),
      score: Number(row?.score || 0),
    })),
  };
}

async function locateViaAccessibilityCdp(tabId, args = {}) {
  const candidates = await collectAxCandidates(tabId);
  if (!candidates.length) return null;
  const query = { role: args.role || "", name: args.name || "", text: args.text || "", label: args.label || "", intendedAction: args.intendedAction || "click" };
  const best = bestSemanticTarget(candidates, query);
  if (!best?.target || best.score < 75) return null;
  const target = { ...best.target };
  try {
    const model = target.cdpSessionId
      ? await debuggerSessionCommandWithTimeout(tabId, target.cdpSessionId, "DOM.getBoxModel", { backendNodeId: target.backendDOMNodeId }, 3000)
      : await debuggerCommandWithTimeout(tabId, "DOM.getBoxModel", { backendNodeId: target.backendDOMNodeId }, 3000, "ax_box_timeout");
    target.boundingBox = quadBox(model?.model || {});
  } catch { target.boundingBox = null; }
  if (!target.boundingBox) return null;
  return { ok: true, matched: true, element: target, match: { strategy: "cdp_accessibility", score: best.score, semanticStrength: best.semanticStrength, reasons: best.reasons } };
}

async function runDomAction(tabId, action, step) {
  await assertOwnedTab(tabId);
  const requestedRef = String(step.targetRef || step.target_ref || "");
  const runtimeState = { domVersion: aggregateRuntimeDomVersion(tabId) };
  let cachedMap = semanticTargetCache.get(Number(tabId)) || null;
  if (cachedMap) {
    const currentTab = await chrome.tabs.get(Number(tabId)).catch(() => null);
    const staleUrl = !currentTab || String(currentTab.url || "") !== String(cachedMap.url || "");
    const staleDom = runtimeState && Number(cachedMap.domVersion ?? -1) !== Number(runtimeState.domVersion ?? -2);
    if (staleUrl || staleDom) {
      if (staleUrl) semanticTargetCache.delete(Number(tabId));
      cachedMap = staleDom ? null : cachedMap;
    }
  }

  let cachedTarget = requestedRef ? cachedMap?.targets?.get(requestedRef) || null : null;
  let semanticSelection = null;
  if (!cachedTarget && cachedMap?.targets?.size) {
    const query = {
      targetRef: requestedRef,
      selector: step.selector || "",
      role: step.role || "",
      name: step.name || "",
      text: step.text || "",
      label: step.label || "",
      intendedAction: step.intendedAction || action,
    };
    const hasSpecificSignal = Boolean(query.targetRef || query.selector || query.name || query.text || query.label);
    const sameRole = query.role
      ? [...cachedMap.targets.values()].filter((item) => String(item?.role || "").toLowerCase() === String(query.role).toLowerCase())
      : [];
    if (hasSpecificSignal || sameRole.length === 1) {
      const best = bestSemanticTarget([...cachedMap.targets.values()], query);
      if (best && (best.score >= 80 || sameRole.length === 1)) {
        cachedTarget = best.target;
        semanticSelection = { score: best.score, semanticStrength: best.semanticStrength, reasons: best.reasons };
      }
    }
  }

  const effective = cachedTarget ? {
    ...cachedTarget,
    ...step,
    targetRef: requestedRef || cachedTarget.targetRef || "",
    selector: step.selector || cachedTarget.selector || "",
    role: step.role || cachedTarget.role || "",
    name: step.name || cachedTarget.accessibleName || "",
    text: step.text || cachedTarget.text || "",
    label: step.label || cachedTarget.label || "",
  } : step;
  const args = domRuntimeArgs(effective, action);
  let result = null;
  let usedPersistentRuntime = false;
  const requestTimeout = Math.max(1000, Number(step.timeoutMs || 30000));
  const requestedFrameId = Number(effective.frameId ?? cachedTarget?.frameId ?? step.frameId ?? 0);
  try {
    if (action === "page_snapshot" && args.includeInteractive) result = await snapshotAcrossRuntimeFrames(tabId, args, requestTimeout);
    else if (action === "locate" && !effective.frameId && !cachedTarget?.frameId && runtimeFramesForTab(tabId).size > 1) result = await locateAcrossRuntimeFrames(tabId, action, args, requestTimeout);
    else result = await pageRuntimeRequestFrame(tabId, requestedFrameId, { kind: "dom_action", action, args }, requestTimeout);
    usedPersistentRuntime = Boolean(result);
  } catch {
    const target = requestedFrameId ? { tabId, frameIds: [requestedFrameId] } : { tabId, allFrames: false };
    const results = await chrome.scripting.executeScript({ target, func: domExecutor, args: [action, args] });
    result = results?.[0]?.result;
    if (result && requestedFrameId) {
      result = { ...result, frameId: requestedFrameId };
      if (result.element) result.element = { ...result.element, frameId: requestedFrameId };
    }
  }
  if ((!result?.ok || !result?.matched) && action === "locate") {
    const axResult = await locateViaAccessibilityCdp(tabId, args).catch(() => null);
    if (axResult?.ok) result = axResult;
  }
  if (!result?.ok) throw codedError(result?.error || "dom_action_failed", result?.message || `DOM action ${action} fallita`);
  if (action === "page_snapshot" && Array.isArray(result.interactive)) {
    const targets = new Map();
    for (const item of result.interactive) if (item?.targetRef) targets.set(String(item.targetRef), item);
    semanticTargetCache.set(Number(tabId), {
      url: String(result.url || ""),
      generation: Number(result.interactionMap?.generation || 0),
      domVersion: Number(result.runtime?.domVersion ?? aggregateRuntimeDomVersion(tabId) ?? 0),
      targets,
      capturedAt: Date.now(),
    });
  }
  return { tabId, ...result, runtimePath: usedPersistentRuntime ? "persistent_port" : "execute_script_fallback", ...(semanticSelection ? { semanticSelection } : {}) };
}


// Chrome paints no pointer for CDP-dispatched input, so an agent that is working
// perfectly looks identical to one doing nothing. This draws where the input
// actually went, using the same coordinates the event carried.
//
// Every failure is swallowed on purpose. The overlay is decoration over a real
// action; a page that refuses injection (a restricted origin, a frame that just
// navigated) must never turn a successful click into a failed step.
async function paintAgentCursor(tabId, x, y, mode) {
  if (!mode || !isDrawablePoint(x, y)) return;
  try {
    await chrome.scripting.executeScript({
      target: { tabId: Number(tabId) },
      func: agentCursorPainter,
      args: [Number(x), Number(y), mode, CURSOR_ELEMENT_ID],
      world: "ISOLATED",
    });
  } catch {
    /* drawing is never allowed to fail an action */
  }
}

// Called before a capture: a pointer burned into a perception screenshot or a
// visual baseline is a diff this suite inflicted on itself.
async function hideAgentCursor(tabId) {
  try {
    await chrome.scripting.executeScript({
      target: { tabId: Number(tabId) },
      func: agentCursorPainter,
      args: [0, 0, "hide", CURSOR_ELEMENT_ID],
      world: "ISOLATED",
    });
  } catch {
    /* nothing to hide, or nowhere to hide it */
  }
}

async function dispatchNativeCommands(tabId, commands = [], sessionId = "") {
  await assertOwnedTab(tabId);
  await attachDebuggerIfNeeded(tabId);
  if (commands.length) markAutomationInput(tabId);
  let dispatched = 0;
  for (const command of commands) {
    throwIfTaskAborted();
    const decision = validateCdpCommand(command.method, command.params || {}, "internal");
    if (!decision.ok) throw codedError(decision.code, `Metodo CDP bloccato: ${String(command.method || "")}`, { method: command.method, scope: "internal" });
    if (sessionId) await debuggerSessionCommandWithTimeout(tabId, sessionId, command.method, command.params || {}, CDP_DEFAULT_TIMEOUT_MS);
    else await debuggerCommandWithTimeout(tabId, command.method, command.params || {}, CDP_DEFAULT_TIMEOUT_MS, "cdp_command_timeout");
    dispatched += 1;
    if (String(command.method || "") === "Input.dispatchMouseEvent") {
      await paintAgentCursor(tabId, command.params?.x, command.params?.y, cursorModeForEvent(command.params?.type));
    }
    if (command.delayMs) await abortableSleep(command.delayMs, taskAbortController?.signal);
  }
  return { executed: true, dispatched, transport: sessionId ? "persistent_cdp_child_session" : "persistent_cdp_hot_path", ...(sessionId ? { cdpSessionId: sessionId } : {}) };
}

async function dispatchScreenshotScrollCommands(tabId, commands = [], deadlineAt = 0) {
  await assertOwnedTab(tabId);
  const deadline = Number(deadlineAt || 0);
  if (!deadline) return dispatchNativeCommands(tabId, commands);
  await attachDebuggerIfNeeded(tabId, screenshotTimeoutRemainingMs(deadline, SCREENSHOT_ATTACH_TIMEOUT_MS, "scroll_cdp_attach"));
  if (commands.length) markAutomationInput(tabId);
  let dispatched = 0;
  for (const command of commands) {
    throwIfTaskAborted();
    await screenshotCdp(tabId, command.method, command.params || {}, deadline, "internal");
    dispatched += 1;
    if (command.delayMs) {
      const delayMs = Math.min(Number(command.delayMs || 0), Math.max(0, screenshotTimeoutRemainingMs(deadline, Number(command.delayMs || 0) + 100, "scroll_command_delay") - 50));
      if (delayMs > 0) await abortableSleep(delayMs, taskAbortController?.signal);
    }
  }
  return { executed: true, dispatched, boundedForScreenshot: true };
}

async function displayGeometryForTab(tabId) {
  const id = Number(tabId || 0);
  const cached = displayGeometryCache.get(id);
  if (cached && Date.now() - cached.at < 5000) return cached.value;
  const tab = await chrome.tabs.get(id).catch(() => null);
  const win = tab?.windowId ? await chrome.windows.get(tab.windowId).catch(() => null) : null;
  const displays = await chrome.system?.display?.getInfo?.().catch?.(() => []) || [];
  const wx = Number(win?.left || 0), wy = Number(win?.top || 0), ww = Number(win?.width || 0), wh = Number(win?.height || 0);
  const centerX = wx + ww / 2, centerY = wy + wh / 2;
  const display = displays.find((d) => centerX >= Number(d.bounds?.left || 0) && centerX < Number(d.bounds?.left || 0) + Number(d.bounds?.width || 0)
    && centerY >= Number(d.bounds?.top || 0) && centerY < Number(d.bounds?.top || 0) + Number(d.bounds?.height || 0)) || displays.find((d) => d.isPrimary) || displays[0] || null;
  const metrics = await pageDimensions(id).catch(() => ({ viewportWidth: 0, viewportHeight: 0, source: "unavailable" }));
  const value = {
    available: Boolean(display), displayId: display?.id || null, displayBounds: display?.bounds || null, workArea: display?.workArea || null,
    windowBounds: win ? { left: wx, top: wy, width: ww, height: wh, state: win.state || "" } : null,
    viewport: { width: Number(metrics.viewportWidth || 0), height: Number(metrics.viewportHeight || 0) },
    coordinateModel: "screenshot_pixel_to_css_cdp_explicit", source: display ? "chrome.system.display+CDP" : "CDP_only",
  };
  displayGeometryCache.set(id, { at: Date.now(), value });
  return value;
}

function screenshotCoordinateContext(image = {}, geometry = null) {
  const cssWidth = Math.max(1, Number(image.capturedCssWidth || image.requestedWidth || image.width || 1));
  const cssHeight = Math.max(1, Number(image.capturedCssHeight || image.requestedHeight || image.height || 1));
  const bitmapWidth = Math.max(1, Number(image.width || cssWidth));
  const bitmapHeight = Math.max(1, Number(image.height || cssHeight));
  return {
    version: "1.0.0", cssWidth, cssHeight, bitmapWidth, bitmapHeight,
    screenshotToCss: { scaleX: cssWidth / bitmapWidth, scaleY: cssHeight / bitmapHeight, offsetX: 0, offsetY: 0 },
    cssToScreenshot: { scaleX: bitmapWidth / cssWidth, scaleY: bitmapHeight / cssHeight, offsetX: 0, offsetY: 0 },
    displayGeometry: geometry || null,
  };
}

function mapCssBoxToScreenshot(box = {}, context = null) {
  if (!context?.cssToScreenshot) return null;
  const sx = Number(context.cssToScreenshot.scaleX || 1), sy = Number(context.cssToScreenshot.scaleY || 1);
  return { x: Number(box.x || 0) * sx, y: Number(box.y || 0) * sy, width: Number(box.width || 0) * sx, height: Number(box.height || 0) * sy };
}

function mapCssPointToScreenshot(point = {}, context = null) {
  if (!context?.cssToScreenshot) return null;
  return { x: Number(point.x || 0) * Number(context.cssToScreenshot.scaleX || 1), y: Number(point.y || 0) * Number(context.cssToScreenshot.scaleY || 1) };
}

async function capturePerceptionFrame(tabId, state = {}, options = {}) {
  const id = Number(tabId || 0);
  const page = await runDomAction(id, "page_snapshot", { includeInteractive: true, maxChars: Number(options.maxChars || 100000) }).catch(() => null);
  const domVersion = Number(page?.runtime?.domVersion || 0);
  const tab = await chrome.tabs.get(id).catch(() => ({}));
  const cacheKey = `${String(tab?.url || "")}|${domVersion}|${Number(page?.viewport?.width || 0)}x${Number(page?.viewport?.height || 0)}`;
  const cached = perceptionFrameCache.get(id);
  if (options.viewerOnly && cached && cached.key === cacheKey && Date.now() - cached.at < PERCEPTION_CACHE_TTL_MS) return { ...cached.value, cached: true };
  const maxPixels = Math.max(250000, Math.min(PERCEPTION_MAX_PIXELS, Number(options.maxPixels || PERCEPTION_MAX_PIXELS)));
  const image = await captureScreenshot(id, false, false, { maxPixels, format: "jpeg", quality: 76, perception: true });
  const geometry = await displayGeometryForTab(id).catch(() => null);
  const context = screenshotCoordinateContext(image, geometry);
  screenshotCoordinateContexts.set(id, context);
  const storage = null;
  let artifact = null;
  if (storage && state?.taskId) artifact = await storeScreenshotArtifact(image, state, storage).catch(() => null);
  const interactive = Array.isArray(page?.interactive) ? page.interactive.map((item) => ({
    ...item,
    screenshotBox: mapCssBoxToScreenshot(item.boundingBox, context),
    screenshotHitPoint: mapCssPointToScreenshot(item.hitPoint || centerOfBox(item.boundingBox || {}), context),
  })) : [];
  const value = {
    tabId: id, url: String(tab?.url || ""), title: String(tab?.title || ""), artifact,
    image: artifact ? null : { dataUrl: image.dataUrl, mimeType: image.mimeType, width: image.width, height: image.height },
    coordinateContext: context, displayGeometry: geometry, page: page ? { ...page, interactive } : null,
    perception: { bounded: true, maxPixels, modelFrame: true, evidenceFrameSeparate: true },
    capturedAt: Date.now(), cached: false,
  };
  perceptionFrameCache.set(id, { key: cacheKey, at: Date.now(), value });
  if (!artifact) releaseScreenshotBuffer(image);
  return value;
}

async function enableWebMcp(tabId) {
  const id = Number(tabId || 0);
  await attachDebuggerIfNeeded(id);
  try { await debuggerCommandWithTimeout(id, "WebMCP.enable", {}, 3000, "webmcp_enable_timeout"); return { available: true }; }
  catch (error) { return { available: false, error: serializeError(error) }; }
}

async function discoverWebMcpTools(tabId) {
  const enabled = await enableWebMcp(tabId);
  return { ...enabled, tools: [...(webMcpToolsByTab.get(Number(tabId || 0)) || new Map()).values()].slice(0, 200) };
}

async function invokeWebMcpTool(tabId, toolName, input = {}) {
  const enabled = await enableWebMcp(tabId);
  if (!enabled.available) return { executed: false, available: false, degraded: true, blocking: false, error: enabled.error };
  const registry = webMcpToolsByTab.get(Number(tabId || 0)) || new Map();
  const registered = registry.get(String(toolName || "")) || null;
  if (!registered) return { executed: false, available: true, degraded: true, blocking: false, reason: "webmcp_tool_not_registered", toolName: String(toolName || "") };
  const response = await debuggerCommandWithTimeout(Number(tabId), "WebMCP.invokeTool", { frameId: String(registered.frameId || ""), toolName: String(toolName || ""), input }, 5000, "webmcp_invoke_timeout");
  const invocationId = String(response?.invocationId || "");
  const deadline = Date.now() + 10000;
  while (invocationId && Date.now() < deadline) {
    const row = webMcpInvocationResults.get(`${Number(tabId)}:${invocationId}`);
    if (row) { webMcpInvocationResults.delete(`${Number(tabId)}:${invocationId}`); return { executed: true, available: true, invocationId, response: row }; }
    await abortableSleep(50, taskAbortController?.signal);
  }
  return { executed: true, available: true, invocationId, verified: false, degraded: true, blocking: false, reason: "webmcp_response_not_observed" };
}


async function captureDesignAudit(tabId) {
  const rows = await chrome.scripting.executeScript({ target: { tabId: Number(tabId), allFrames: false }, func: () => {
    const q=(s)=>Array.from(document.querySelectorAll(s));
    const visible=(el)=>{const r=el.getBoundingClientRect();const c=getComputedStyle(el);return r.width>0&&r.height>0&&c.display!=="none"&&c.visibility!=="hidden";};
    const els=q("body *").filter(visible).slice(0,2500);
    const count=(values,limit=24)=>Object.entries(values.reduce((a,v)=>{if(v)a[v]=(a[v]||0)+1;return a;},{})).sort((a,b)=>b[1]-a[1]).slice(0,limit).map(([value,n])=>({value,count:n}));
    const css=els.map(el=>getComputedStyle(el));
    const root=getComputedStyle(document.documentElement); const vars={};
    for(const name of Array.from(root)){ if(String(name).startsWith("--")){ const v=root.getPropertyValue(name).trim(); if(v)vars[name]=v; if(Object.keys(vars).length>=160)break; }}
    const frameworks={
      wordpress:Boolean(document.body?.className?.match(/\bwp-|wordpress/i)||document.querySelector(".wp-site-blocks,[class*='wp-block-']")),
      tailwind:Boolean(q("[class*='md:'],[class*='lg:'],[class*='sm:']").length),
      react:Boolean(document.querySelector("[data-reactroot],[data-reactid],#__next,#root")),
      vue:Boolean(document.querySelector("[data-v-app],[data-v-]")),
      shadcn:Boolean(document.querySelector("[data-radix-collection-item],[data-slot]")),
    };
    return {url:location.href,title:document.title,viewport:{width:innerWidth,height:innerHeight,dpr:devicePixelRatio},frameworks,
      typography:{families:count(css.map(c=>c.fontFamily),12),sizes:count(css.map(c=>c.fontSize),16),weights:count(css.map(c=>c.fontWeight),12),lineHeights:count(css.map(c=>c.lineHeight),12)},
      palette:{text:count(css.map(c=>c.color),20),background:count(css.map(c=>c.backgroundColor).filter(v=>v&&!/rgba\(0, 0, 0, 0\)|transparent/.test(v)),20),border:count(css.map(c=>c.borderColor),16)},
      surfaces:{radius:count(css.map(c=>c.borderRadius),16),shadow:count(css.map(c=>c.boxShadow).filter(v=>v&&v!=="none"),16)},
      spacing:{gaps:count(css.map(c=>c.gap).filter(v=>v&&v!=="normal"),16),paddings:count(css.map(c=>`${c.paddingTop} ${c.paddingRight} ${c.paddingBottom} ${c.paddingLeft}`),16)},
      cssVariables:vars,elementCount:els.length};
  }}).catch(()=>[]);
  return { tabId:Number(tabId), designDNA: rows?.[0]?.result || null, evidenceClass:"MEASURED_LIVE", blocking:false };
}

async function captureMotionAudit(tabId) {
  const rows = await chrome.scripting.executeScript({ target:{tabId:Number(tabId),allFrames:false}, func:()=>{
    const reduced=matchMedia("(prefers-reduced-motion: reduce)").matches;
    const animations=(document.getAnimations?.()||[]).slice(0,500).map(a=>{const e=a.effect;const t=e?.getTiming?.()||{};const target=e?.target;const r=target?.getBoundingClientRect?.();return {playState:a.playState,currentTime:a.currentTime,duration:t.duration,delay:t.delay,iterations:t.iterations,fill:t.fill,direction:t.direction,target:target?`${target.tagName?.toLowerCase()||""}${target.id?"#"+target.id:""}`:"",offscreen:r? r.bottom<0||r.top>innerHeight||r.right<0||r.left>innerWidth:null};});
    let infinite=0,offscreenRunning=0; for(const a of animations){if(a.iterations===Infinity)infinite++;if(a.playState==="running"&&a.offscreen)offscreenRunning++;}
    const animated=[]; for(const el of Array.from(document.querySelectorAll("body *")).slice(0,2500)){const c=getComputedStyle(el);if(c.animationName!=="none"||c.transitionDuration.split(",").some(v=>parseFloat(v)>0)){animated.push({tag:el.tagName.toLowerCase(),animationName:c.animationName,animationDuration:c.animationDuration,transitionProperty:c.transitionProperty,transitionDuration:c.transitionDuration,willChange:c.willChange});if(animated.length>=300)break;}}
    return {url:location.href,reducedMotion:reduced,webAnimations:{count:animations.length,infinite,offscreenRunning,items:animations.slice(0,80)},cssAnimations:{count:animated.length,items:animated.slice(0,120)}};
  }}).catch(()=>[]);
  return {tabId:Number(tabId),motionAudit:rows?.[0]?.result||null,evidenceClass:"MEASURED_LIVE",blocking:false};
}

function centerOfBox(box = {}) {
  const x = Number(box.x ?? box.pageX ?? 0) + Number(box.width || 0) / 2;
  const y = Number(box.y ?? box.pageY ?? 0) + Number(box.height || 0) / 2;
  if (![x, y].every(Number.isFinite)) throw codedError("element_box_invalid", "Coordinate elemento non valide per input nativo.");
  return { x, y };
}

async function readDomElementValue(tabId, selector, targetRef = "", frameId = 0) {
  try {
    const result = await pageRuntimeRequestFrame(tabId, Number(frameId || 0), { kind: "read_value", selector: String(selector || ""), targetRef: String(targetRef || "") }, 3000);
    if (result?.supported) return result;
  } catch { /* one-shot fallback below */ }
  if (!selector) return { supported: false, value: null, valueLength: null };
  const rows = await chrome.scripting.executeScript({
    target: Number(frameId || 0) ? { tabId, frameIds: [Number(frameId || 0)] } : { tabId, allFrames: false },
    func: (css) => {
      try {
        const element = document.querySelector(css);
        if (!element) return { supported: false, value: null, valueLength: null };
        const supported = "value" in element;
        const value = supported ? String(element.value ?? "") : String(element.textContent ?? "");
        return { supported: true, value, valueLength: value.length, tag: String(element.tagName || "").toLowerCase() };
      } catch (error) {
        return { supported: false, value: null, valueLength: null, error: String(error?.message || error) };
      }
    },
    args: [selector],
  }).catch(() => []);
  return rows?.[0]?.result || { supported: false, value: null, valueLength: null };
}

async function runNativeElementAction(tabId, action, step = {}) {
  await assertOwnedTab(tabId);
  const hasLocator = Boolean(step.targetRef || step.target_ref || step.selector || step.text || step.role || step.name || step.label || step.xpath || step.coordinates);
  let located = null;
  if (hasLocator || action !== "press") located = await runDomAction(tabId, "locate", { ...step, intendedAction: action });
  if (action === "press") {
    const pressSessionId = String(located?.element?.cdpSessionId || "");
    if (located?.element?.boundingBox) {
      const point = centerOfBox(located.element.boundingBox);
      await dispatchNativeCommands(tabId, pointerSequence([{ type: "click", ...point }]), pressSessionId);
    }
    const dispatched = await dispatchNativeCommands(tabId, keyboardSequence([{ type: "press", key: String(step.key || "") }]), pressSessionId);
    return { tabId, action, element: located?.element || null, key: String(step.key || ""), ...dispatched };
  }
  let point = located?.element?.hitPoint || null;
  if (!point && located?.element?.boundingBox) {
    const box = located.element.boundingBox;
    const pixelW = Number(box.width || 0) * Number(screenshotCoordinateContexts.get(Number(tabId))?.cssToScreenshot?.scaleX || 1);
    const pixelH = Number(box.height || 0) * Number(screenshotCoordinateContexts.get(Number(tabId))?.cssToScreenshot?.scaleY || 1);
    return { tabId, action, element: located.element, executed: false, requiresRegrounding: true, zoomRecommended: Math.min(pixelW, pixelH) < PERCEPTION_ZOOM_MIN_TARGET_PX, degraded: true, blocking: false, reason: "safe_interaction_point_unavailable" };
  }
  const nativeSessionId = String(located?.element?.cdpSessionId || "");
  const locatedFrameId = Number(located?.element?.frameId || 0);
  // Child/OOPIF targets carry their CDP child session: use native input in that session; never blind HTMLElement.click() fallback.
  if (action === "hover") {
    const dispatched = await dispatchNativeCommands(tabId, pointerSequence([{ type: "move", ...point }]), nativeSessionId);
    return { tabId, action, element: located.element, point, ...dispatched };
  }
  if (action === "click") {
    const clickCount = Math.max(1, Math.min(3, Number(step.clickCount || step.click_count || 1)));
    // The input layer has always supported a mouse button and a click count of
    // one to three; nothing carried them here, so a right click and a triple
    // click were unreachable despite being implemented. A right click is how a
    // context menu opens and a triple click is how a field's whole value is
    // selected before being replaced -- both are ordinary operator actions.
    const button = String(step.button || "left").trim().toLowerCase();
    const dispatched = await dispatchNativeCommands(tabId, pointerSequence([{ type: "click", ...point, clickCount, button }]), nativeSessionId);
    return { tabId, action, element: located.element, point, clickCount, button, ...dispatched };
  }
  if (["fill", "type_text"].includes(action)) {
    const text = String(step.value ?? "");
    // Native-first: focus/click, clear and type through CDP. DOM setter is bounded recovery only.
    await dispatchNativeCommands(tabId, pointerSequence([{ type: "click", ...point }]), nativeSessionId);
    if (action === "fill" && !step.append) {
      const platform = await chrome.runtime.getPlatformInfo().catch(() => ({ os: "" }));
      const selectAll = String(platform?.os || "").toLowerCase() === "mac" ? "Meta+A" : "Control+A";
      await dispatchNativeCommands(tabId, keyboardSequence([{ type: "press", key: selectAll }, { type: "press", key: "Backspace" }]), nativeSessionId);
    }
    let dispatched = await dispatchNativeCommands(tabId, keyboardSequence([{ type: "text", text }]), nativeSessionId);
    let observed = located?.element?.selector ? await readDomElementValue(tabId, located.element.selector, located.element.targetRef || "", Number(located.element.frameId || 0)) : { supported: false };
    let verified = action !== "fill" || Boolean(step.append) || (observed.supported && observed.value === text);
    let replacementStrategy = "native_keyboard_platform_aware";
    let recoveryUsed = false;
    if (action === "fill" && !step.append && observed.supported && !verified && located?.element?.selector) {
      const domResult = await runDomAction(tabId, "fill", { ...step, frameId: Number(located.element.frameId || 0), targetRef: located.element.targetRef || step.targetRef || step.target_ref || "", selector: located.element.selector, selectorType: "css", append: false }).catch(() => null);
      observed = await readDomElementValue(tabId, located.element.selector, located.element.targetRef || "", Number(located.element.frameId || 0));
      verified = Boolean(domResult?.matched && observed.supported && observed.value === text);
      replacementStrategy = verified ? "native_keyboard_then_bounded_dom_recovery" : "native_keyboard_recovery_unverified";
      recoveryUsed = true;
    }
    return {
      tabId, action, element: located.element, point, inserted: true, insertedLength: text.length,
      replacementStrategy, recoveryUsed, event_dispatched: true,
      application_effect_observed: Boolean(observed.supported), application_effect_verified: Boolean(verified),
      degraded: Boolean(action === "fill" && observed.supported && !verified), blocking: false,
      observedValueLength: observed.valueLength ?? null, ...dispatched,
    };
  }
  throw codedError("native_element_action_invalid", `Azione input nativo non valida: ${action}`);
}

async function domExecutor(action, args) {
  const normalize = (value) => String(value || "")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .toLowerCase()
    .replace(/[^\p{L}\p{N}]+/gu, " ")
    .replace(/\s+/g, " ")
    .trim();
  const controlConcepts = {
    save:["save","salva","salvare"], cancel:["cancel","annulla","annullare"],
    add:["add","aggiungi","aggiungere","inserisci","inserire"], cart:["cart","basket","carrello"],
    buy:["buy","purchase","acquista","acquistare","compra","comprare"], checkout:["checkout","cassa"],
    submit:["submit","send","invia","inviare","manda","mandare"], continue:["continue","continua","continuare","prosegui","proseguire"],
    confirm:["confirm","conferma","confermare"], next:["next","avanti","successivo","successiva"], back:["back","previous","indietro","precedente"],
    close:["close","chiudi","chiudere"], open:["open","apri","aprire"], login:["login","signin","accedi","accedere","entra","entrare"],
    logout:["logout","signout","esci","uscire","disconnetti"], search:["search","find","cerca","cercare","trova","trovare"],
    select:["select","choose","seleziona","selezionare","scegli","scegliere"], check:["check","tick","spunta","spuntare"],
    fill:["fill","enter","compila","compilare","riempi","riempire"], write:["type","write","digita","digitare","scrivi","scrivere"],
    click:["click","press","clicca","cliccare","premi","premere"], upload:["upload","carica","caricare"], download:["download","scarica","scaricare"],
    remove:["remove","delete","rimuovi","rimuovere","elimina","eliminare"], edit:["edit","modify","modifica","modificare"],
    apply:["apply","applica","applicare"], coupon:["coupon","voucher","buono","sconto"], filter:["filter","filtra","filtrare"],
    sort:["sort","ordina","ordinare"], details:["details","detail","dettagli","dettaglio"], more:["more","altro","altri","piu"],
    name:["name","nome"], address:["address","indirizzo"], phone:["phone","telephone","telefono"], company:["company","business","azienda","societa"],
    quantity:["quantity","qty","quantita"], payment:["payment","pagamento"], shipping:["shipping","delivery","spedizione","consegna"],
  };
  const conceptByWord = new Map();
  for (const [concept, forms] of Object.entries(controlConcepts)) for (const form of [concept, ...forms]) conceptByWord.set(normalize(form), concept);
  const stopWords = new Set(["a","an","the","to","of","for","in","on","and","or","your","al","allo","alla","ai","agli","alle","il","lo","la","i","gli","le","un","uno","una","di","del","della","dei","delle","per","nel","nella","e","o"]);
  const tokenSet = (value) => [...new Set(normalize(value).split(" ").filter((part) => part.length > 1 && !stopWords.has(part)).map((part) => conceptByWord.get(part) || `raw:${part}`))];
  const similarity = (actual, expected) => {
    const a = normalize(actual), b = normalize(expected);
    if (!a || !b) return 0;
    if (a === b) return 1;
    if (a.startsWith(b) || b.startsWith(a)) return 0.9;
    if (a.includes(b) || b.includes(a)) return 0.82;
    const at = tokenSet(a), bt = tokenSet(b);
    if (!at.length || !bt.length) return 0;
    const intersection = bt.filter((token) => at.includes(token)).length;
    if (!intersection) return 0;
    const precision = intersection / at.length;
    const recall = intersection / bt.length;
    return (2 * precision * recall) / Math.max(0.0001, precision + recall);
  };
  const visible = (element) => {
    if (!(element instanceof Element) || !element.isConnected) return false;
    if (element.closest?.("[hidden],[inert],[aria-hidden='true']")) return false;
    const style = getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    return style.display !== "none" && style.visibility !== "hidden" && Number(style.opacity || 1) > 0.05
      && style.contentVisibility !== "hidden" && rect.width > 0 && rect.height > 0;
  };
  const deepQueryAll = (selector) => {
    const output = [];
    const seen = new Set();
    const roots = [document];
    for (let index = 0; index < roots.length && index < 64; index += 1) {
      const root = roots[index];
      let matches = [];
      try { matches = [...root.querySelectorAll(selector)]; } catch { matches = []; }
      for (const element of matches) if (!seen.has(element)) { seen.add(element); output.push(element); }
      let descendants = [];
      try { descendants = [...root.querySelectorAll("*")]; } catch { descendants = []; }
      for (const element of descendants) if (element.shadowRoot && !roots.includes(element.shadowRoot)) roots.push(element.shadowRoot);
    }
    return output;
  };
  const labelledByText = (element) => {
    const ids = String(element.getAttribute?.("aria-labelledby") || "").split(/\s+/).filter(Boolean);
    if (!ids.length) return "";
    const root = element.getRootNode?.() || document;
    return ids.map((id) => root.getElementById?.(id) || document.getElementById(id))
      .filter(Boolean).map((node) => node.innerText || node.textContent || "").join(" ");
  };
  const labelText = (element) => [
    labelledByText(element),
    element.labels ? [...element.labels].map((label) => label.innerText || label.textContent).join(" ") : "",
    element.closest?.("label")?.innerText || "",
  ].filter(Boolean).join(" ");
  const accessibleName = (element) => {
    const values = [
      element.getAttribute?.("aria-label"), labelledByText(element), labelText(element),
      element.getAttribute?.("alt"), element.getAttribute?.("title"), element.getAttribute?.("placeholder"),
      ["button", "submit", "reset"].includes(String(element.getAttribute?.("type") || "").toLowerCase()) ? element.value : "",
      element.innerText, element.textContent,
    ];
    const seen = new Set();
    const parts = [];
    for (const value of values) {
      const normalized = normalize(value);
      if (normalized && !seen.has(normalized)) { seen.add(normalized); parts.push(normalized); }
    }
    return parts.join(" ");
  };
  const inferredRole = (element) => {
    const explicit = normalize(element.getAttribute?.("role"));
    if (explicit) return explicit;
    if (element.tagName === "A" && element.hasAttribute("href")) return "link";
    if (element.tagName === "BUTTON") return "button";
    if (element.tagName === "TEXTAREA") return "textbox";
    if (element.tagName === "SELECT") return "combobox";
    if (element.tagName === "SUMMARY") return "button";
    if (element.tagName === "INPUT") {
      const type = String(element.type || "text").toLowerCase();
      if (type === "checkbox") return "checkbox";
      if (type === "radio") return "radio";
      if (["button", "submit", "reset", "image"].includes(type)) return "button";
      if (type === "range") return "slider";
      if (type === "search") return "searchbox";
      return "textbox";
    }
    return normalize(element.tagName);
  };
  const cssPath = (element) => {
    if (element.id) return `#${CSS.escape(element.id)}`;
    const testId = element.getAttribute?.("data-testid") || element.getAttribute?.("data-test") || element.getAttribute?.("data-qa");
    if (testId) {
      const attr = element.hasAttribute("data-testid") ? "data-testid" : element.hasAttribute("data-test") ? "data-test" : "data-qa";
      return `[${attr}="${CSS.escape(testId)}"]`;
    }
    const parts = [];
    let current = element;
    while (current && current.nodeType === 1 && parts.length < 6) {
      let part = current.tagName.toLowerCase();
      const stableClasses = [...current.classList].filter((value) => /^[a-zA-Z][\w-]{1,50}$/.test(value) && !/^(active|selected|focus|hover|open|closed)$/i.test(value)).slice(0, 2);
      if (stableClasses.length) part += `.${stableClasses.map((value) => CSS.escape(value)).join(".")}`;
      const parent = current.parentElement;
      const siblings = parent ? [...parent.children].filter((item) => item.tagName === current.tagName) : [];
      if (siblings.length > 1) part += `:nth-of-type(${siblings.indexOf(current) + 1})`;
      parts.unshift(part);
      current = parent;
    }
    return parts.join(" > ");
  };
  const isDisabled = (element) => Boolean(element.matches?.(":disabled,[aria-disabled='true']"));
  const isFocusable = (element) => !isDisabled(element) && (
    element.matches?.("a[href],button,input,textarea,select,summary,[contenteditable='true']")
    || Number(element.getAttribute?.("tabindex")) >= 0
    || ["button", "link", "textbox", "combobox", "checkbox", "radio", "switch", "menuitem", "option", "tab"].includes(inferredRole(element))
  );
  const isClickable = (element) => {
    const role = inferredRole(element);
    const type = String(element.getAttribute?.("type") || "").toLowerCase();
    return ["button", "link", "menuitem", "option", "tab", "checkbox", "radio", "switch"].includes(role)
      || element.matches?.("a[href],button,summary,[onclick]")
      || (element.tagName === "INPUT" && ["button", "submit", "reset", "checkbox", "radio", "image"].includes(type));
  };
  const interactionPoint = (element) => {
    const rect = element.getBoundingClientRect();
    if (rect.bottom <= 0 || rect.right <= 0 || rect.top >= innerHeight || rect.left >= innerWidth) return null;
    const fractions = [0.5, 0.35, 0.65, 0.2, 0.8];
    for (const fy of fractions) for (const fx of fractions) {
      const x = Math.min(innerWidth - 1, Math.max(0, rect.left + rect.width * fx));
      const y = Math.min(innerHeight - 1, Math.max(0, rect.top + rect.height * fy));
      const top = document.elementFromPoint(x, y);
      if (top && (top === element || element.contains(top) || top.contains?.(element))) return { x, y, fx, fy, verified: true };
    }
    return null;
  };
  const topmostState = (element) => Boolean(interactionPoint(element));
  const contextText = (element) => {
    const parts = [labelText(element)];
    let current = element.parentElement;
    for (let depth = 0; current && depth < 3; depth += 1, current = current.parentElement) {
      const heading = current.querySelector?.(":scope > h1,:scope > h2,:scope > h3,:scope > [role='heading']");
      if (heading) parts.push(heading.innerText || heading.textContent || "");
      if (current.matches?.("[role='dialog'],dialog,form,li,tr,section,article")) parts.push(current.getAttribute("aria-label") || current.innerText || "");
    }
    return normalize(parts.join(" ")).slice(0, 700);
  };
  const semanticRegistry = () => {
    const key = "__PRSTUDIO_SEMANTIC_TARGETS_V2__";
    const current = globalThis[key];
    if (current && current.url === location.href && current.document === document) return current;
    const created = {
      url: location.href,
      document,
      token: (globalThis.crypto?.randomUUID?.() || `${Date.now().toString(36)}_${Math.random().toString(36).slice(2)}`).replace(/[^a-zA-Z0-9_-]/g, ""),
      generation: 0,
      next: 1,
      refs: new WeakMap(),
      targets: new Map(),
    };
    globalThis[key] = created;
    return created;
  };
  const resetSemanticRegistry = () => {
    const registry = semanticRegistry();
    registry.generation += 1;
    for (const [ref, element] of registry.targets) if (!element?.isConnected) registry.targets.delete(ref);
    return registry;
  };
  const targetRefFor = (element) => {
    const registry = semanticRegistry();
    let ref = registry.refs.get(element);
    if (!ref) {
      ref = `prst_${registry.token}_e${registry.next++}`;
      registry.refs.set(element, ref);
    }
    registry.targets.set(ref, element);
    return ref;
  };
  const describe = (element) => {
    const rect = element.getBoundingClientRect();
    const inViewport = rect.bottom > 0 && rect.right > 0 && rect.top < innerHeight && rect.left < innerWidth;
    const topmost = topmostState(element);
    const dialog = element.closest?.("dialog[open],[role='dialog'],[aria-modal='true']");
    const style = getComputedStyle(element);
    const dx = (rect.left + rect.width / 2 - innerWidth / 2) / Math.max(1, innerWidth);
    const dy = (rect.top + rect.height / 2 - innerHeight / 2) / Math.max(1, innerHeight);
    return {
      targetRef: targetRefFor(element),
      tag: element.tagName.toLowerCase(),
      inputType: String(element.getAttribute?.("type") || "").toLowerCase(),
      fieldName: String(element.getAttribute?.("name") || "").slice(0, 160),
      role: inferredRole(element),
      accessibleName: accessibleName(element).slice(0, 500),
      label: normalize(labelText(element)).slice(0, 400),
      text: normalize(element.innerText || element.textContent).slice(0, 500),
      context: contextText(element),
      clickable: isClickable(element),
      focusable: isFocusable(element),
      contentEditable: Boolean(element.isContentEditable),
      disabled: isDisabled(element),
      ariaHidden: element.getAttribute?.("aria-hidden") === "true",
      pointerEventsNone: style.pointerEvents === "none",
      inViewport,
      occluded: inViewport ? !topmost : undefined,
      hitPoint: interactionPoint(element),
      inDialog: Boolean(dialog && visible(dialog)),
      centerDistance: Math.sqrt(dx * dx + dy * dy),
      hasValue: "value" in element ? String(element.value || "").length > 0 : undefined,
      valueLength: "value" in element ? String(element.value || "").length : undefined,
      checked: "checked" in element ? Boolean(element.checked) : undefined,
      selector: cssPath(element),
      boundingBox: { x: rect.x, y: rect.y, pageX: rect.x + scrollX, pageY: rect.y + scrollY, width: rect.width, height: rect.height },
    };
  };
  const allInteractive = () => {
    const indexed = globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__?.interactiveElements?.();
    if (Array.isArray(indexed) && indexed.length) return indexed.filter((element) => element?.isConnected && visible(element)).slice(0, 1500);
    const selector = "a[href],button,input:not([type='hidden']),textarea,select,summary,[role],[tabindex],[contenteditable='true'],[onclick]";
    return deepQueryAll(selector).filter(visible).slice(0, 1500);
  };
  const rankCandidate = (element) => {
    const descriptor = describe(element);
    let score = 0;
    const expectedRole = normalize(args.role);
    const actualRole = normalize(descriptor.role);
    const nameStrength = args.name ? Math.max(similarity(descriptor.accessibleName, args.name), similarity(descriptor.text, args.name)) : 0;
    const textStrength = args.text ? Math.max(similarity(descriptor.text, args.text), similarity(descriptor.accessibleName, args.text)) : 0;
    const labelStrength = args.label ? Math.max(
      similarity(descriptor.label, args.label),
      similarity(descriptor.context, args.label),
      similarity(descriptor.accessibleName, args.label),
    ) : 0;
    if (args.selector && descriptor.selector === args.selector) score += 320;
    if (expectedRole) score += actualRole === expectedRole ? 150 : -95;
    if (args.name) {
      score += similarity(descriptor.accessibleName, args.name) * 300;
      score += similarity(descriptor.text, args.name) * 95;
    }
    if (args.text) {
      score += similarity(descriptor.text, args.text) * 245;
      score += similarity(descriptor.accessibleName, args.text) * 110;
    }
    if (args.label) score += labelStrength * 270;
    const intended = normalize(args.intendedAction || action);
    const intendedConcepts = tokenSet(intended);
    const editable = ["input", "textarea", "select"].includes(descriptor.tag)
      || ["textbox", "combobox", "searchbox", "spinbutton"].includes(descriptor.role)
      || descriptor.contentEditable;
    if (["fill", "write", "select", "check"].some((name) => intendedConcepts.includes(name)) || ["fill", "type text", "type_text", "select", "check"].includes(intended)) score += editable ? 80 : -140;
    else if (intendedConcepts.includes("click") || ["click", "double click", "double_click", "hover"].includes(intended)) score += descriptor.clickable ? 55 : (editable ? 8 : -20);
    else if (intended === "press") score += (descriptor.focusable || descriptor.clickable || editable) ? 30 : 0;
    if (descriptor.disabled) score -= 500;
    if (descriptor.ariaHidden) score -= 300;
    if (descriptor.pointerEventsNone) score -= 180;
    if (descriptor.occluded === false) score += 35;
    if (descriptor.occluded === true) score -= 120;
    if (descriptor.inDialog) score += 35;
    if (descriptor.inViewport) score += 22;
    if (descriptor.focusable) score += 10;
    const area = Number(descriptor.boundingBox?.width || 0) * Number(descriptor.boundingBox?.height || 0);
    if (area > 0 && area < 144) score -= 45;
    score -= Math.min(20, Number(descriptor.centerDistance || 0) * 8);
    return { element, descriptor, score, semanticStrength: Math.max(nameStrength, textStrength, labelStrength) };
  };
  let lastMatch = null;
  const find = () => {
    if (args.targetRef) {
      const target = semanticRegistry().targets.get(String(args.targetRef));
      if (target?.isConnected && visible(target)) {
        lastMatch = { strategy: "target_ref", score: 1000 };
        return target;
      }
    }

    let selectorCandidate = null;
    if (args.selector && !/:has-text\(|:text\(|text=|>>/.test(args.selector)) {
      try {
        selectorCandidate = deepQueryAll(args.selector).find(visible) || null;
        const semanticSignals = Boolean(args.role || args.name || args.text || args.label);
        if (selectorCandidate && !semanticSignals) {
          lastMatch = { strategy: "css", score: 320 };
          return selectorCandidate;
        }
      } catch (error) {
        if (!args.role && !args.name && !args.text && !args.label && !args.xpath) throw new Error(`selector_invalid_css:${error.message}`);
      }
    }

    const candidates = allInteractive();
    const hasSemantic = Boolean(args.role || args.name || args.text || args.label);
    if (hasSemantic) {
      let ranked = candidates.map(rankCandidate).sort((a, b) => b.score - a.score);
      const hasLexicalSignal = Boolean(args.name || args.text || args.label);
      if (hasLexicalSignal) ranked = ranked.filter((row) => row.semanticStrength >= 0.32);
      const roleOnly = Boolean(args.role && !args.name && !args.text && !args.label && !args.selector);
      if (roleOnly) {
        const matchingRole = ranked.filter((row) => normalize(row.descriptor.role) === normalize(args.role));
        const dialogMatches = matchingRole.filter((row) => row.descriptor.inDialog);
        ranked = dialogMatches.length === 1 ? dialogMatches : matchingRole.length === 1 ? matchingRole : [];
      }
      if (ranked[0] && ranked[0].score >= 75) {
        lastMatch = { strategy: "semantic_rank", score: ranked[0].score, runnerUp: ranked[1]?.score ?? null };
        return ranked[0].element;
      }
    }

    if (args.label) {
      const wanted = normalize(args.label);
      const label = deepQueryAll("label").find((item) => visible(item) && similarity(item.innerText || item.textContent, wanted) >= 0.82);
      const control = label?.control || (label?.htmlFor ? document.getElementById(label.htmlFor) : label?.querySelector("input,textarea,select,button"));
      if (control && visible(control)) { lastMatch = { strategy: "label_control", score: 210 }; return control; }
    }

    if (selectorCandidate) { lastMatch = { strategy: "css_fallback", score: 200 }; return selectorCandidate; }

    if (args.xpath || args.selectorType === "xpath") {
      const xpath = args.xpath || args.selector;
      try {
        const found = document.evaluate(xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;
        if (found && visible(found)) { lastMatch = { strategy: "xpath", score: 160 }; return found; }
      } catch { /* coordinate fallback below */ }
    }

    if (args.text) {
      const wanted = normalize(args.text);
      const exactText = deepQueryAll("body *").find((element) => visible(element) && normalize(element.innerText || element.textContent) === wanted);
      if (exactText) {
        const interactiveAncestor = exactText.closest?.("a[href],button,summary,[role],[tabindex],[onclick],[contenteditable='true']");
        if (interactiveAncestor && visible(interactiveAncestor)) { lastMatch = { strategy: "text_interactive_ancestor", score: 140 }; return interactiveAncestor; }
        if (getComputedStyle(exactText).cursor === "pointer") { lastMatch = { strategy: "text_pointer", score: 120 }; return exactText; }
      }
    }

    if (args.coordinates && Number.isFinite(Number(args.coordinates.x)) && Number.isFinite(Number(args.coordinates.y))) {
      const found = document.elementFromPoint(Number(args.coordinates.x), Number(args.coordinates.y));
      if (found && visible(found)) { lastMatch = { strategy: "coordinates", score: 100 }; return found; }
    }
    return null;
  };
  const pageSnapshot = () => {
    const registry = args.includeInteractive ? resetSemanticRegistry() : semanticRegistry();
    const interactive = args.includeInteractive ? allInteractive().slice(0, 700).map(describe) : [];
    return {
      ok: true,
      url: location.href,
      title: document.title,
      text: String(document.body?.innerText || "").replace(/\s+/g, " ").trim().slice(0, Math.max(32_768, Math.min(1_000_000, Number(args.maxChars || 200_000)))),
      viewport: { width: innerWidth, height: innerHeight },
      scroll: { x: scrollX, y: scrollY, maxY: Math.max(0, document.documentElement.scrollHeight - innerHeight) },
      page: { width: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight },
      runtime: globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__ ? {
        mode: "persistent_incremental",
        domVersion: Number(globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__.domVersion || 0),
        indexedInteractive: Number(globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__.indexSize?.() || 0),
      } : { mode: "injected_fallback", domVersion: 0, indexedInteractive: 0 },
      interactionMap: args.includeInteractive ? {
        version: "3.0.0",
        generation: registry.generation,
        count: interactive.length,
        strategyOrder: ["target_ref", "semantic_rank", "css", "label", "xpath", "text_ancestor", "coordinates"],
        descriptors: ["role", "accessibleName", "label", "text", "context", "clickable", "focusable", "disabled", "inViewport", "occluded", "hitPoint", "inDialog", "boundingBox"],
      } : null,
      interactive,
    };
  };
  try {
    if (action === "dom_snapshot") {
      const clone = document.documentElement.cloneNode(true);
      for (const script of clone.querySelectorAll("script,noscript,template")) script.remove();
      for (const control of clone.querySelectorAll("input,textarea,select,option")) {
        control.removeAttribute("value");
        control.removeAttribute("checked");
        control.removeAttribute("selected");
        if (["TEXTAREA", "OPTION"].includes(control.tagName)) control.textContent = "";
      }
      for (const element of clone.querySelectorAll("[name],[id],[autocomplete],meta[name]")) {
        const marker = [element.getAttribute("name"), element.id, element.getAttribute("autocomplete")].filter(Boolean).join(" ");
        if (/password|passwd|pwd|passcode|otp|verification|token|secret|csrf|xsrf/i.test(marker)) {
          if (element.hasAttribute("content")) element.setAttribute("content", "[REDACTED]");
          if (element.hasAttribute("value")) element.setAttribute("value", "[REDACTED]");
          element.textContent = "";
        }
      }
      return { ok: true, html: clone.outerHTML.slice(0, 1000000), scriptsOmitted: true, formValuesOmitted: true, url: location.href, title: document.title };
    }
    if (action === "page_snapshot") return pageSnapshot();
    if (action === "scroll") {
      if (args.to === "top") scrollTo({ top: 0, left: 0, behavior: "instant" });
      else if (args.to === "bottom") scrollTo({ top: document.documentElement.scrollHeight, left: 0, behavior: "instant" });
      else scrollBy({ left: args.x, top: args.y, behavior: "instant" });
      return { ok: true, action, scroll: { x: scrollX, y: scrollY, maxY: Math.max(0, document.documentElement.scrollHeight - innerHeight) } };
    }
    if (action === "accessibility_scan") {
      const issues = [];
      for (const image of deepQueryAll("img")) if (visible(image) && !image.alt) issues.push({ type: "image_missing_alt", selector: cssPath(image) });
      for (const input of deepQueryAll("input,textarea,select")) {
        if (!visible(input)) continue;
        const labelled = input.labels?.length || input.getAttribute("aria-label") || input.getAttribute("aria-labelledby") || input.title;
        if (!labelled) issues.push({ type: "control_missing_label", selector: cssPath(input) });
      }
      return { ok: true, issues: issues.slice(0, 1000), count: issues.length, url: location.href };
    }
    const element = find() || (action === "extract_text" && !args.selector ? document.body : null);
    if (!element || !visible(element)) return { ok: false, error: "element_not_found", message: "Nessun elemento visibile corrisponde al target richiesto." };
    const before = describe(element);
    const beforeRect = element.getBoundingClientRect();
    const needsScroll = beforeRect.bottom <= 0 || beforeRect.right <= 0 || beforeRect.top >= innerHeight || beforeRect.left >= innerWidth;
    if (needsScroll) {
      element.scrollIntoView({ block: "center", inline: "center", behavior: "instant" });
      await new Promise((resolve) => requestAnimationFrame(() => resolve()));
    }
    if (action === "locate") return { ok: true, matched: true, element: describe(element), match: lastMatch };
    if (action === "click") {
      for (let i = 0; i < args.clickCount; i += 1) element.click();
    } else if (action === "hover") {
      element.dispatchEvent(new MouseEvent("mouseover", { bubbles: true }));
      element.dispatchEvent(new MouseEvent("mouseenter", { bubbles: true }));
    } else if (action === "focus") element.focus();
    else if (action === "blur") element.blur();
    else if (action === "type_text") {
      element.focus();
      const text = String(args.value ?? "");
      const proto = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      const setter = Object.getOwnPropertyDescriptor(proto, "value")?.set;
      let currentValue = args.append ? String(element.value || "") : "";
      if (!args.append) {
        if (setter) setter.call(element, ""); else element.value = "";
        element.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "deleteContentBackward", data: null }));
      }
      const delay = Math.max(0, Math.min(100, Number(args.keyDelayMs || 0)));
      for (const character of text) {
        currentValue += character;
        if (setter) setter.call(element, currentValue); else element.value = currentValue;
        element.dispatchEvent(new KeyboardEvent("keydown", { key: character, bubbles: true }));
        element.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertText", data: character }));
        element.dispatchEvent(new KeyboardEvent("keyup", { key: character, bubbles: true }));
        if (delay) await new Promise((resolve) => setTimeout(resolve, delay));
      }
      element.dispatchEvent(new Event("change", { bubbles: true }));
    } else if (action === "fill") {
      element.focus();
      const next = args.append ? String(element.value || "") + String(args.value) : String(args.value);
      const proto = element instanceof HTMLTextAreaElement ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
      const setter = Object.getOwnPropertyDescriptor(proto, "value")?.set;
      if (setter) setter.call(element, next); else element.value = next;
      element.dispatchEvent(new InputEvent("input", { bubbles: true, inputType: "insertText", data: String(args.value) }));
      element.dispatchEvent(new Event("change", { bubbles: true }));
    } else if (action === "press") {
      element.focus();
      const options = { key: args.key, code: args.key, bubbles: true, cancelable: true };
      element.dispatchEvent(new KeyboardEvent("keydown", options));
      element.dispatchEvent(new KeyboardEvent("keypress", options));
      element.dispatchEvent(new KeyboardEvent("keyup", options));
    } else if (action === "select") {
      const values = Array.isArray(args.value) ? args.value.map(String) : [String(args.value)];
      for (const option of element.options || []) option.selected = values.includes(option.value) || values.includes(option.label);
      element.dispatchEvent(new Event("input", { bubbles: true }));
      element.dispatchEvent(new Event("change", { bubbles: true }));
    } else if (action === "check") {
      if (Boolean(element.checked) !== Boolean(args.value ?? true)) element.click();
    } else if (action === "extract_text") {
      return { ok: true, matched: true, action, element: before, match: lastMatch, text: String(element.innerText || element.textContent || "").slice(0, 1000000), url: location.href };
    } else if (action === "computed_styles") {
      const style = getComputedStyle(element);
      const properties = args.properties.length ? args.properties : ["display", "visibility", "position", "color", "background-color", "font-size", "width", "height"];
      return { ok: true, matched: true, element: before, match: lastMatch, styles: Object.fromEntries(properties.map((name) => [name, style.getPropertyValue(name)])) };
    } else return { ok: false, error: "contract_dom_action_missing", message: `Azione DOM ${action} non implementata.` };
    return { ok: true, matched: true, action, element: before, after: describe(element), match: lastMatch, url: location.href, title: document.title };
  } catch (error) {
    return { ok: false, error: String(error?.message || error), message: String(error?.message || error) };
  }
}

async function detectExternalAuthChallenge(tabId) {
  try {
    const results = await chrome.scripting.executeScript({
      target: { tabId, allFrames: false },
      func: gateDetector,
      args: [AUTH_CHALLENGE_SELECTORS, AUTH_CHALLENGE_TEXT],
    });
    return results?.[0]?.result || null;
  } catch (error) {
    // Detector availability is diagnostic only. Real CAPTCHA/MFA/login challenges
    // are awaited inline; a host-permission miss never parks an executable task.
    return { reason: "auth_challenge_detection_unavailable", tabId: Number(tabId || 0), error: serializeError(error), nonBlocking: true };
  }
}

function gateDetector(selectors, markers) {
  const isVisible = (element, selector) => {
    const style = getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    const inViewport = rect.bottom > 0 && rect.right > 0 && rect.top < innerHeight && rect.left < innerWidth;
    if (style.display === "none" || style.visibility === "hidden" || Number(style.opacity || 1) <= 0.05 || !inViewport) return false;
    const isIframe = element.tagName === "IFRAME" && /recaptcha|hcaptcha|challenges\.cloudflare/i.test(element.src || selector);
    const isOtp = element.matches?.("input[autocomplete='one-time-code'],input[name*='otp' i],input[name*='verification' i],input[aria-label*='verification code' i]");
    if (isIframe) return rect.width >= 120 && rect.height >= 60;
    if (isOtp) return rect.width >= 80 && rect.height >= 20 && !element.disabled;
    return rect.width >= 40 && rect.height >= 20;
  };
  const candidates = [];
  for (const selector of selectors) {
    for (const element of document.querySelectorAll(selector)) {
      if (isVisible(element, selector)) candidates.push({ element, selector });
    }
  }
  if (candidates.length) {
    const { selector, element } = candidates[0];
    return { reason: "captcha_or_mfa", selector, tag: element.tagName, url: location.href, title: document.title };
  }
  const bodyText = String(document.body?.innerText || "").toLowerCase();
  const marker = markers.find((item) => bodyText.includes(item));
  if (!marker) return null;
  const interaction = [...document.querySelectorAll([
    "iframe[src*='recaptcha']", "iframe[src*='hcaptcha']", "iframe[src*='challenges.cloudflare.com']",
    "input[autocomplete='one-time-code']", "input[name*='otp' i]", "input[name*='verification' i]",
    "input[type='password']", "[role='dialog'] input", "[role='dialog'] iframe"
  ].join(","))].find((element) => isVisible(element, "text-marker"));
  return interaction ? { reason: "captcha_or_mfa", marker, url: location.href, title: document.title } : null;
}

async function externalAuthChallengeStillPresent(tabId, reason = "") {
  const id = Number(tabId || 0);
  if (!id) return false;
  const detected = await detectExternalAuthChallenge(id).catch(() => null);
  if (detected?.reason === "captcha_or_mfa") return true;
  if (!/login/i.test(String(reason || ""))) return false;
  const tab = await chrome.tabs.get(id).catch(() => null);
  if (!tab) throw codedError("controlled_tab_missing", "The controlled tab disappeared while waiting for authentication.", { tabId: id });
  if (/accounts\.google\.com|\/(?:signin|login)(?:[/?#]|$)/i.test(String(tab.url || ""))) return true;
  const probe = await chrome.scripting.executeScript({
    target: { tabId: id, allFrames: false },
    func: () => Boolean([...document.querySelectorAll("input[type='password'],input[autocomplete='one-time-code'],input[name*='otp' i]")].find((element) => {
      const style = getComputedStyle(element); const rect = element.getBoundingClientRect();
      return style.display !== "none" && style.visibility !== "hidden" && rect.width > 0 && rect.height > 0;
    })),
  }).catch(() => []);
  return Boolean(probe?.[0]?.result);
}

async function waitForExternalAuthChallenge(state, reason, snapshot = {}) {
  if (!state?.taskId || !state?.tabId) throw codedError("auth_challenge_context_missing", "Authentication challenge detected without an active controlled tab.");
  state.authChallenge = { active: true, reason: String(reason || "external_auth"), at: Date.now(), tabId: Number(state.tabId), snapshot: redactObservation(snapshot).value };
  await saveActiveTask(state);
  await injectAuthChallengeBanner(state.tabId, state.authChallenge.reason).catch(() => {});
  await setBadge("AUTH", "#c45d00");
  await appendLog("auth_challenge.waiting", { taskId: state.taskId, tabId: state.tabId, reason: state.authChallenge.reason, autoResume: true }).catch(() => {});
  await chrome.notifications.create(`prstudio-auth-${state.taskId}`, {
    type: "basic", iconUrl: "icons/icon128.png", title: "PR STUDIO: autenticazione richiesta",
    message: authChallengeMessage(state.authChallenge.reason), priority: 1,
  }).catch(() => {});

  while (await externalAuthChallengeStillPresent(state.tabId, state.authChallenge.reason)) {
    throwIfTaskAborted(taskExecutionGeneration);
    await sleep(1000);
  }
  state.authChallenge = null;
  await saveActiveTask(state);
  await removeAuthChallengeBanner(state.tabId).catch(() => {});
  await chrome.notifications.clear(`prstudio-auth-${state.taskId}`).catch(() => {});
  await setBadge("RUN", "#1a5fb4");
  await appendLog("auth_challenge.resolved", { taskId: state.taskId, tabId: state.tabId, autoResumed: true }).catch(() => {});
  return { resolved: true, autoResumed: true };
}

async function cancelActive(taskIdInput = "") {
  const active = await getActiveTask();
  const taskId = String(taskIdInput || "").trim();
  if (!active?.taskId || (taskId && String(active.taskId) !== taskId)) return { ok: false, error: "no_active_task" };
  if (taskAbortController) taskAbortController.abort("cancelled_by_user");
  taskExecutionGeneration += 1;
  stopHeartbeat();
  const leaseToken = active.leaseToken;
  active.phase = "cancel_requested"; active.cancelRequestedAt = Date.now(); active.leaseToken = null;
  await saveActiveTask(active);
  let serverCancelled = false;
  try { await api(`/tasks/${active.taskId}/cancel`, { method: "POST", body: { lease_token: leaseToken } }); serverCancelled = true; } catch (error) { logError(error); }
  await cleanupTaskRuntime(active).catch(() => {});
  await removeAuthChallengeBanner(active.tabId).catch(() => {});
  await clearActiveTask();
  await setBadge("ON", "#176b32");
  return { ok: true, taskId: active.taskId, serverCancelled };
}

async function injectAuthChallengeBanner(tabId, reason) {
  if (!tabId) return;
  await chrome.scripting.executeScript({
    target: { tabId },
    func: (message) => {
      let banner = document.getElementById("prstudio-auth-challenge");
      if (!banner) {
        banner = document.createElement("div");
        banner.id = "prstudio-auth-challenge";
        Object.assign(banner.style, {
          position: "fixed", top: "0", left: "0", right: "0", zIndex: "2147483647",
          padding: "12px 18px", background: "#8a3b00", color: "#fff",
          font: "600 14px/1.4 system-ui,sans-serif", textAlign: "center",
          boxShadow: "0 2px 8px rgba(0,0,0,.35)"
        });
        document.documentElement.appendChild(banner);
      }
      banner.textContent = message;
    },
    args: [authChallengeMessage(reason)],
  });
}

async function removeAuthChallengeBanner(tabId) {
  if (!tabId) return;
  await chrome.scripting.executeScript({
    target: { tabId },
    func: () => document.getElementById("prstudio-auth-challenge")?.remove(),
  });
}

function authChallengeMessage(reason) {
  if (String(reason).includes("captcha") || String(reason).includes("mfa")) {
    return "Completa CAPTCHA, MFA o passkey. PR STUDIO riprenderà automaticamente appena la challenge scompare.";
  }
  return `Completa l’autenticazione interattiva richiesta (${reason}). PR STUDIO continuerà automaticamente.`;
}

function boundedRuntimeTimeout(value, fallback = 30_000, max = 120_000, min = 250) {
  const candidate = Number(value);
  const safe = Number.isFinite(candidate) ? candidate : Number(fallback);
  return Math.max(Number(min), Math.min(Number(max), safe));
}

function promiseWithTimeout(promise, timeoutMs, errorFactory, onTimeout = null) {
  const bounded = Math.max(250, Number(timeoutMs || 0));
  let timer = null;
  return Promise.race([
    Promise.resolve(promise),
    new Promise((_, reject) => {
      timer = setTimeout(() => {
        const timeoutError = typeof errorFactory === "function"
          ? errorFactory()
          : codedError("OPERATION_TIMEOUT", `Operazione oltre ${bounded} ms.`, { timeoutMs: bounded });
        // The deadline must settle independently of best-effort cleanup.
        reject(timeoutError);
        if (typeof onTimeout === "function") Promise.resolve().then(() => onTimeout()).catch(() => {});
      }, bounded);
    }),
  ]).finally(() => { if (timer) clearTimeout(timer); });
}

async function debuggerDetachWithTimeout(tabId, timeoutMs = 5_000) {
  const id = Number(tabId || 0);
  if (!id) return { detached: false, reason: "tab_missing" };
  const bounded = Math.max(250, Math.min(15_000, Number(timeoutMs || 5_000)));
  await promiseWithTimeout(
    chrome.debugger.detach({ tabId: id }),
    bounded,
    () => codedError("CDP_DETACH_TIMEOUT", `Detach Chrome DevTools Protocol oltre ${bounded} ms.`, { tabId: id, timeoutMs: bounded }),
  );
  return { detached: true, tabId: id };
}

async function debuggerCommandWithTimeout(tabId, method, params = {}, timeoutMs = CDP_DEFAULT_TIMEOUT_MS, reason = "cdp_timeout") {
  const id = Number(tabId || 0);
  return promiseWithTimeout(
    chrome.debugger.sendCommand({ tabId: id }, method, params || {}),
    timeoutMs,
    () => codedError("CDP_TIMEOUT", `Comando CDP ${String(method || "")} oltre ${timeoutMs} ms.`, { tabId: id, method, timeoutMs }),
    async () => {
      if (!id) return;
      markIntentionalDebuggerDetach(id, reason);
      await debuggerDetachWithTimeout(id).catch(() => { intentionalDebuggerDetaches.delete(id); });
      debuggerProtocolByTab.delete(id);
    }
  );
}

function codedError(code, message, details = {}) {
  const error = new Error(message || code);
  error.code = code;
  error.details = details;
  return error;
}

function screenshotTimeoutRemainingMs(deadlineAt, capMs, phase = "screenshot") {
  const deadline = Number(deadlineAt || 0);
  const remaining = deadline > 0 ? Math.floor(deadline - Date.now()) : Number(capMs || SCREENSHOT_CAPTURE_TIMEOUT_MS);
  if (!Number.isFinite(remaining) || remaining < 250) {
    throw codedError("SCREENSHOT_TIMEOUT", `Timeout screenshot raggiunto durante ${phase}.`, { phase, deadlineAt: deadline || null, remainingMs: Number.isFinite(remaining) ? remaining : null });
  }
  return Math.max(250, Math.min(Number(capMs || remaining), remaining));
}

function responsiveMatrixRemainingMs(deadlineAt, capMs, phase = "responsive_matrix") {
  const deadline = Number(deadlineAt || 0);
  const remaining = deadline > 0 ? Math.floor(deadline - Date.now()) : Number(capMs || RESPONSIVE_MATRIX_VIEWPORT_TIMEOUT_MS);
  if (!Number.isFinite(remaining) || remaining < 250) {
    throw codedError("RESPONSIVE_MATRIX_TIMEOUT", `Timeout responsive matrix raggiunto durante ${phase}.`, {
      phase, deadlineAt: deadline || null, remainingMs: Number.isFinite(remaining) ? remaining : null,
    });
  }
  return Math.max(250, Math.min(Number(capMs || remaining), remaining));
}

async function responsiveMatrixCdp(tabId, method, params = {}, deadlineAt = 0) {
  const decision = validateCdpCommand(method, params || {}, "internal");
  if (!decision.ok) throw codedError(decision.code, `Metodo CDP bloccato: ${String(method || "")}`, { method, scope: "internal" });
  await attachDebuggerIfNeeded(tabId, responsiveMatrixRemainingMs(deadlineAt, SCREENSHOT_ATTACH_TIMEOUT_MS, "cdp_attach"));
  return debuggerCommandWithTimeout(
    tabId,
    method,
    params || {},
    responsiveMatrixRemainingMs(deadlineAt, RESPONSIVE_MATRIX_CDP_TIMEOUT_MS, "cdp_command"),
    "responsive_matrix_cdp_timeout",
  );
}

async function markInFlightProgress(state, progress = {}) {
  if (!state?.inFlight) return null;
  const at = Date.now();
  state.inFlight = { ...state.inFlight, lastProgressAt: at, progress: { ...progress, at } };
  const latest = await getActiveTask().catch(() => null);
  if (latest?.taskId === state?.taskId && latest?.inFlight?.attemptId === state?.inFlight?.attemptId) {
    latest.inFlight = { ...latest.inFlight, lastProgressAt: at, progress: { ...progress, at } };
    await saveActiveTask(latest);
  }
  return state.inFlight;
}

function validateNavigationUrl(value) {
  const raw = String(value || "").trim();
  if (!raw) throw codedError("url_required", "È richiesto un URL esplicito; non verrà aperta una scheda vuota.");
  if (raw === "about:blank" || raw.startsWith("about:blank?")) {
    throw codedError("about_blank_forbidden", "about:blank non è un fallback valido per il Browser Agent.");
  }
  // A bare host is what people and models actually write. "google.com" used to
  // die in new URL() with a raw TypeError, surfaced to the operator as an
  // undifferentiated technical_error, which is how browser_open appeared to be
  // broken while the connection itself was fine.
  const input = parseUserUrl(raw);
  if (!input) {
    throw codedError("url_invalid", `URL non valido: ${raw}`);
  }
  const parsed = input.url;
  if (!["http:", "https:"].includes(parsed.protocol)) {
    throw codedError("url_protocol_forbidden", `Protocollo non consentito per una scheda agente: ${parsed.protocol}`);
  }
  return parsed.href;
}

function urlMatches(actual, expected) {
  const target = String(expected || "").trim();
  if (!target) return Boolean(actual);
  if (target.startsWith("/") && target.endsWith("/") && target.length > 2) {
    return testUserUrlRegex(actual, target.slice(1, -1)).matched;
  }
  if (target.includes("*")) return matchLiteralWildcard(actual, target);
  return String(actual || "").includes(target);
}

async function selectExistingChromeWindow() {
  const stored = (await chrome.storage.local.get(STORAGE_KEYS.AGENT_WINDOW))?.[STORAGE_KEYS.AGENT_WINDOW] || null;
  const storedId = Number(stored?.windowId || stored || 0);
  const legacySentinelId = Number(stored?.sentinelTabId || 0);
  const legacyWindowId = legacySentinelId ? storedId : 0;

  if (storedId && !legacySentinelId) {
    const existing = await chrome.windows.get(storedId, { populate: false }).catch(() => null);
    if (existing?.id && existing.type === "normal") return Number(existing.id);
    await chrome.storage.local.remove(STORAGE_KEYS.AGENT_WINDOW);
  }

  if (legacySentinelId) {
    await chrome.tabs.remove(legacySentinelId).catch(() => {});
    await chrome.storage.local.remove(STORAGE_KEYS.AGENT_WINDOW);
  }

  const windows = await chrome.windows.getAll({ populate: true, windowTypes: ["normal"] });
  if (!windows.length) throw codedError("chrome_window_missing", "Nessuna finestra Chrome normale disponibile. Apri una finestra Chrome e riprova.");

  const alternatives = legacyWindowId ? windows.filter((item) => Number(item.id) !== legacyWindowId) : windows;
  const pool = alternatives.length ? alternatives : windows;
  const registry = await getTabRegistry();
  const score = (win) => {
    let value = win.focused ? 100 : 0;
    for (const tab of win.tabs || []) {
      const record = registry[String(Number(tab?.id || 0))] || null;
      if (record?.adoptedExternal) value += 25;
      else if (!record && /^https?:/i.test(String(tab?.url || ""))) value += 10;
    }
    return value;
  };
  const selected = [...pool].sort((a, b) => score(b) - score(a))[0];
  if (!selected?.id) throw codedError("chrome_window_missing", "Nessuna finestra Chrome normale disponibile.");

  if (legacyWindowId && Number(selected.id) !== legacyWindowId) {
    for (const [key, record] of Object.entries(registry)) {
      if (record?.adoptedExternal || Number(record?.windowId || 0) !== legacyWindowId) continue;
      const tabId = Number(record?.tabId || key || 0);
      if (!tabId) continue;
      const moved = await chrome.tabs.move(tabId, { windowId: Number(selected.id), index: -1 }).catch(() => null);
      if (moved?.id) registry[key] = { ...record, windowId: Number(selected.id), updatedAt: Date.now(), affinityReason: record.affinityReason || "legacy_agent_window_migrated" };
    }
    await saveTabRegistry(registry);
  }

  await chrome.storage.local.set({ [STORAGE_KEYS.AGENT_WINDOW]: { windowId: Number(selected.id), mode: "existing_normal_window", selectedAt: Date.now() } });
  await appendLog("browser_host_window.selected", { windowId: Number(selected.id), migratedLegacyWindowId: legacyWindowId || null }).catch(() => {});
  return Number(selected.id);
}

async function getAgentWindowId() {
  return selectExistingChromeWindow();
}

async function ensureAgentWindow() {
  return selectExistingChromeWindow();
}

async function getTabRegistry() {
  const stored = await chrome.storage.local.get(STORAGE_KEYS.TAB_REGISTRY);
  const value = stored?.[STORAGE_KEYS.TAB_REGISTRY];
  return value && typeof value === "object" && !Array.isArray(value) ? value : {};
}

async function saveTabRegistry(registry) {
  await chrome.storage.local.set({ [STORAGE_KEYS.TAB_REGISTRY]: registry || {} });
}

async function registerOwnedTab(tabId, meta = {}) {
  const id = Number(tabId || 0);
  if (!id) throw codedError("tab_id_required", "tabId mancante durante la registrazione della scheda agente.");
  const tab = await chrome.tabs.get(id).catch(() => null);
  if (!tab) throw codedError("tab_not_found", `Scheda ${id} non trovata.`);
  const committedUrl = String(tab.url || "");
  const tabUrl = candidateTabUrl(tab, meta.url || "");
  if (committedUrl.startsWith(chrome.runtime.getURL("agent.html")) || !/^https?:/i.test(tabUrl) || isRestrictedLocalUrl(tabUrl)) {
    throw codedError("tab_url_not_ownable", `La scheda ${id} non è una pagina HTTP(S) possedibile dal Browser Agent.`);
  }
  const registry = await getTabRegistry();
  registry[String(id)] = {
    tabId: id,
    windowId: tab.windowId,
    taskId: meta.taskId || "",
    laneId: meta.laneId || registry[String(id)]?.laneId || "",
    expectedOrigin: meta.expectedOrigin || "",
    url: tabUrl,
    provisional: !/^https?:/i.test(committedUrl),
    title: meta.title || tab.title || "",
    owner: "prstudio-agent",
    ownershipNonce: registry[String(id)]?.ownershipNonce || crypto.randomUUID(),
    createdAt: registry[String(id)]?.createdAt || Date.now(),
    updatedAt: Date.now(),
  };
  await saveTabRegistry(registry);
  await installHumanInteractionProbe(id).catch(() => {});
  return registry[String(id)];
}


// File the agent's tabs into their own group, so the operator and the agent can
// work in the same Chrome without colliding. Attaching to the real browser is
// the point of this product; interleaving tabs with the operator's own was not.
//
// Best-effort by construction. A Chrome that will not group a tab must still run
// the task in it: under LAW 1 nothing here may stop a mutation, and filing a tab
// is bookkeeping, not execution.
//
// The group is deliberately left expanded. Collapsing it would hide the work,
// and invisible work is the defect this suite has been removing all week.
async function fileTabIntoAgentGroup(tabId, windowId) {
  if (!chrome.tabGroups || !chrome.tabs?.group) return null;
  const id = Number(tabId || 0);
  if (!id) return null;
  try {
    const stored = (await chrome.storage.local.get(STORAGE_KEYS.AGENT_TAB_GROUP))?.[STORAGE_KEYS.AGENT_TAB_GROUP];
    const storedId = Number(stored?.groupId || 0);
    const live = storedId ? await chrome.tabGroups.get(storedId).catch(() => null) : null;

    const groupId = isReusableGroup(storedId, live, windowId)
      ? await chrome.tabs.group({ groupId: storedId, tabIds: [id] })
      : await chrome.tabs.group({ tabIds: [id], createProperties: { windowId: Number(windowId) } });

    await chrome.tabGroups.update(groupId, { title: AGENT_GROUP_TITLE, color: AGENT_GROUP_COLOR, collapsed: false }).catch(() => {});
    await chrome.storage.local.set({ [STORAGE_KEYS.AGENT_TAB_GROUP]: { groupId: Number(groupId), windowId: Number(windowId), updatedAt: Date.now() } });
    return Number(groupId);
  } catch {
    return null;
  }
}

async function createOwnedAgentTab(state, urlInput, meta = {}) {
  const url = validateNavigationUrl(urlInput);
  const windowId = await ensureAgentWindow();
  // Claim the tab before target navigation starts. This removes the race where
  // an ownership verifier can observe the new HTTP tab before the registry is
  // committed. about:blank never becomes a user-facing navigation target; the
  // intended HTTP(S) URL is stored as provisional ownership metadata first.
  const tab = await chrome.tabs.create({ windowId, url: "about:blank", active: false });
  const tabId = Number(tab?.id || 0);
  if (!tabId) throw codedError("agent_tab_create_failed", "Chrome non ha restituito l'identificativo della nuova scheda agente.");
  await fileTabIntoAgentGroup(tabId, windowId);
  const laneId = String(meta.laneId || state?.arguments?._prstudio_lane_id || "");
  const taskId = String(meta.taskId || state?.taskId || "");
  const expectedOrigin = String(meta.expectedOrigin || (() => { try { return new URL(url).origin; } catch { return ""; } })());
  try {
    // PR STUDIO ONE-GUARD INVARIANT: claim controlled session ownership before
    // navigation; no verifier/policy/task-id gate may interpose here.
    await registerOwnedTab(tabId, { windowId, taskId, laneId, expectedOrigin, url, provisionalClaim: true });
    await bindTabAffinity(state, tabId, meta.reason || "pre_navigation_claim");
    await attachDebugger(tabId);
    await chrome.tabs.update(tabId, { url, active: false });
    await waitForTab(tabId, meta.waitUntil || "interactive", Number(meta.timeoutMs || 45000)).catch(() => {});
    const loaded = await chrome.tabs.get(tabId);
    await updateOwnedTab(tabId, {
      provisional: false,
      url: String(loaded?.url || url),
      title: String(loaded?.title || ""),
      expectedOrigin,
      taskId,
      laneId,
      affinityReason: meta.reason || "owned_navigation",
    });
    return { tabId, windowId, url: String(loaded?.url || url), title: String(loaded?.title || ""), created: true, background: true, ownedBeforeNavigation: true, laneId: laneId || null };
  } catch (error) {
    await unregisterOwnedTab(tabId).catch(() => {});
    await clearTabAffinityForTab(tabId).catch(() => {});
    await chrome.tabs.remove(tabId).catch(() => {});
    throw error;
  }
}

async function registerAdoptedTab(tabId, meta = {}) {
  const id = Number(tabId || 0);
  if (!id) throw codedError("tab_id_required", "tabId mancante durante l'adozione della scheda utente.");
  const tab = await chrome.tabs.get(id).catch(() => null);
  if (!tab || !/^https?:/i.test(String(tab.url || "")) || isRestrictedLocalUrl(tab.url)) {
    throw codedError("tab_not_adoptable", `La scheda ${id} non è una pagina HTTP(S) adottabile.`);
  }
  const registry = await getTabRegistry();
  const current = registry[String(id)] || {};
  const requestedLane = String(meta.laneId || "");
  if (current.laneId && requestedLane && current.laneId !== requestedLane) {
    throw codedError("tab_lane_conflict", `La scheda ${id} è già adottata da un'altra lane ChatGPT.`, { tabId: id, ownerLane: current.laneId, requestedLane });
  }
  registry[String(id)] = {
    ...current,
    tabId: id,
    windowId: tab.windowId,
    originalWindowId: current.originalWindowId || tab.windowId,
    taskId: meta.taskId || current.taskId || "",
    laneId: requestedLane || current.laneId || "",
    expectedOrigin: meta.expectedOrigin || current.expectedOrigin || (() => { try { return new URL(tab.url).origin; } catch { return ""; } })(),
    url: tab.url || "",
    title: tab.title || "",
    owner: "prstudio-agent",
    adoptedExternal: true,
    ownershipNonce: current.ownershipNonce || crypto.randomUUID(),
    createdAt: current.createdAt || Date.now(),
    updatedAt: Date.now(),
    affinityReason: "explicit_user_tab_adoption",
  };
  await saveTabRegistry(registry);
  await installHumanInteractionProbe(id).catch(() => {});
  return registry[String(id)];
}

async function adoptUserTabs(state, step = {}) {
  const laneId = String(step.laneId || state?.arguments?._prstudio_lane_id || "");
  if (!laneId) throw codedError("tab_adoption_lane_required", "L'adozione di schede utente richiede una lane ChatGPT esplicita.");
  const requested = new Set((Array.isArray(step.tabIds) ? step.tabIds : []).map((v) => Number(v || 0)).filter(Boolean));
  const origin = String(step.origin || "").trim().toLowerCase();
  const urlContains = String(step.urlContains || "").trim().toLowerCase();
  const titleContains = String(step.titleContains || "").trim().toLowerCase();
  const limit = Math.max(1, Math.min(12, Number(step.limit || 5)));
  const all = await chrome.tabs.query({});
  const candidates = [];
  for (const tab of all) {
    if (!tab?.id || !/^https?:/i.test(String(tab.url || "")) || isRestrictedLocalUrl(tab.url)) continue;
    if (requested.size && !requested.has(Number(tab.id))) continue;
    let tabOrigin = ""; try { tabOrigin = new URL(tab.url).origin.toLowerCase(); } catch { continue; }
    if (origin && tabOrigin !== origin) continue;
    if (urlContains && !String(tab.url || "").toLowerCase().includes(urlContains)) continue;
    if (titleContains && !String(tab.title || "").toLowerCase().includes(titleContains)) continue;
    const current = await ownedTab(tab.id).catch(() => null);
    if (current?.laneId && current.laneId !== laneId) continue;
    let score = 0;
    if (requested.has(Number(tab.id))) score += 100;
    if (origin && tabOrigin === origin) score += 50;
    if (urlContains) score += 20;
    if (titleContains) score += 10;
    if (tab.active) score += 5;
    candidates.push({ tab, score });
  }
  candidates.sort((a, b) => b.score - a.score || Number(b.tab.lastAccessed || 0) - Number(a.tab.lastAccessed || 0));
  const adopted = [];
  for (const row of candidates.slice(0, limit)) {
    const record = await registerAdoptedTab(row.tab.id, { laneId, taskId: state?.taskId || "" });
    adopted.push({ tabId: record.tabId, windowId: record.windowId, url: record.url, title: record.title, laneId: record.laneId, adoptedExternal: true });
  }
  if (!adopted.length) throw codedError("adoptable_tabs_not_found", "Nessuna scheda utente corrisponde ai filtri richiesti o le schede sono già assegnate ad altre lane.", { origin, urlContains, titleContains, requested: [...requested] });
  await bindTabAffinity(state, adopted[0].tabId, "explicit_user_tab_adoption");
  return { adopted, count: adopted.length, selectedTabId: adopted[0].tabId, laneId };
}

async function releaseLaneTabs(laneIdValue) {
  const laneId = String(laneIdValue || "").trim();
  if (!laneId) throw codedError("lane_release_id_required", "Il rilascio schede richiede una lane esplicita.");
  const registry = await getTabRegistry();
  const candidates = [];
  for (const [key, record] of Object.entries(registry)) {
    if (String(record?.laneId || "") !== laneId) continue;
    const tabId = Number(record?.tabId || key || 0);
    if (!tabId) continue;
    candidates.push({ key, tabId, adoptedExternal: Boolean(record?.adoptedExternal) });
  }

  const agentRows = candidates.filter((row) => !row.adoptedExternal);
  const closeOutcomes = new Map();
  await Promise.all(agentRows.map(async (row) => {
    try {
      await promiseWithTimeout(
        chrome.tabs.remove(row.tabId),
        5_000,
        () => codedError("AGENT_TAB_CLOSE_TIMEOUT", "Chiusura scheda Agent oltre 5000 ms.", { tabId: row.tabId, laneId }),
      );
      closeOutcomes.set(row.tabId, { ok: true });
    } catch (error) {
      closeOutcomes.set(row.tabId, { ok: false, error });
    }
  }));

  const failures = [];
  const released = [];
  for (const row of candidates) {
    const closeOutcome = row.adoptedExternal ? { ok: true } : closeOutcomes.get(row.tabId);
    if (!closeOutcome?.ok) {
      failures.push({ stage: "close_tab", tabId: row.tabId, error: closeOutcome?.error || codedError("agent_tab_close_failed", "Chiusura scheda Agent fallita.", { tabId: row.tabId }) });
      continue;
    }
    try {
      await clearTabAffinityForTab(row.tabId);
    } catch (error) {
      failures.push({ stage: "clear_affinity", tabId: row.tabId, error });
      if (row.adoptedExternal) continue;
    }
    delete registry[row.key];
    released.push(row);
  }
  await saveTabRegistry(registry);
  await appendLog("lane.tabs_released", {
    laneId,
    released: released.length,
    closedAgentTabs: released.filter((row) => !row.adoptedExternal).length,
    failedTabs: failures.map((item) => ({ tabId: item.tabId, stage: item.stage, code: String(item.error?.code || "lane_release_failed") })),
  }).catch(() => {});
  if (failures.length) {
    if (failures.length === 1) throw failures[0].error;
    throw codedError("LANE_RELEASE_CLEANUP_FAILED", "Una o più schede della lane non sono state rilasciate correttamente.", {
      laneId,
      failures: failures.map(({ stage, tabId, error }) => ({ stage, tabId, code: String(error?.code || "lane_release_failed"), message: String(error?.message || error) })),
    });
  }
  return {
    ok: true,
    laneId,
    releasedTabs: released.length,
    releasedAdoptedTabs: released.filter((row) => row.adoptedExternal).length,
    closedAgentTabs: released.filter((row) => !row.adoptedExternal).length,
  };
}

async function executeRemoteLocalStudio(state, step = {}) {
  const operation = String(step.operation || "status");
  const laneId = String(step.laneId || state?.arguments?._prstudio_lane_id || "");
  const tabId = Number(step.tabId || state?.tabId || 0) || null;
  if (!laneId) throw codedError("remote_local_lane_required", "Local Studio remoto richiede una lane ChatGPT.");
  if (tabId) {
    const record = await assertOwnedTab(tabId);
    if (record.laneId && record.laneId !== laneId) throw codedError("remote_local_lane_mismatch", "La scheda appartiene a un'altra lane ChatGPT.", { tabId, ownerLane: record.laneId, requestedLane: laneId });
  }
  const payload = step.payload && typeof step.payload === "object" ? step.payload : {};
  const allowed = new Set([
    "status","page_health","debug_capture","bug_report","responsive_matrix","site_scan",
    "workflow_list","workflow_run","workflow_import","workflow_delete","recorder_start","recorder_stop",
    "workspace_save","workspace_list","workspace_restore","workspace_delete","baseline_capture","baseline_compare",
    "schedule_upsert","schedule_list","schedule_delete","set_origin_profile","export_state","cancel"
  ]);
  if (!allowed.has(operation)) throw codedError("remote_local_operation_forbidden", `Operazione Local Studio remota non consentita: ${operation}`);
  const executionContext = Object.freeze({ remote: true, laneId, tabId });
  switch (operation) {
    case "status": return localStatusPayload(executionContext);
    case "page_health": return localPageHealth(executionContext);
    case "debug_capture": return localDebugCapture(Boolean(payload.reload), executionContext);
    case "bug_report": return buildLocalBugReport(Boolean(payload.includeScreenshot), executionContext);
    case "responsive_matrix": return localResponsiveMatrix(executionContext);
    case "site_scan": return localSiteScan(Number(payload.limit || 8), executionContext);
    case "workflow_list": return { ok: true, workflows: await getLocalWorkflows() };
    case "workflow_run": return runLocalWorkflow(payload.id, executionContext);
    case "workflow_import": return importLocalWorkflow(payload.workflow);
    case "workflow_delete": return deleteLocalWorkflow(payload.id);
    case "recorder_start": return startLocalRecorder(payload.name, executionContext);
    case "recorder_stop": return stopLocalRecorder(payload.name);
    case "workspace_save": return saveLocalWorkspace(payload.name, executionContext);
    case "workspace_list": return { ok: true, workspaces: await getLocalWorkspaces() };
    case "workspace_restore": return restoreLocalWorkspace(payload.id, executionContext);
    case "workspace_delete": return deleteLocalWorkspace(payload.id);
    case "baseline_capture": return captureLocalBaseline(payload.name, executionContext);
    case "baseline_compare": return compareLocalBaseline(payload.id, executionContext);
    case "schedule_upsert": return upsertLocalSchedule(payload.schedule || payload, executionContext);
    case "schedule_list": return { ok: true, schedules: await getLocalSchedules(), results: await getLocalScheduledResults() };
    case "schedule_delete": return deleteLocalSchedule(payload.id);
    case "set_origin_profile": return setLocalOriginProfile(payload.origin, payload.mode, executionContext);
    case "export_state": return exportLocalStudioState();
    case "cancel": return cancelLocalExecution();
    default: throw codedError("remote_local_operation_forbidden", operation);
  }
}

async function updateOwnedTab(tabId, patch = {}) {
  const registry = await getTabRegistry();
  const key = String(Number(tabId || 0));
  if (!registry[key]) return null;
  registry[key] = { ...registry[key], ...patch, tabId: Number(tabId), owner: "prstudio-agent", updatedAt: Date.now() };
  await saveTabRegistry(registry);
  return registry[key];
}

async function unregisterOwnedTab(tabId) {
  const registry = await getTabRegistry();
  delete registry[String(Number(tabId || 0))];
  await saveTabRegistry(registry);
}

async function clearTabRegistry() {
  await chrome.storage.local.set({ [STORAGE_KEYS.TAB_REGISTRY]: {} });
}

async function removeAgentCreatedTabsFromRegistry(windowId) {
  const registry = await getTabRegistry();
  const removed = [];
  for (const [key, record] of Object.entries(registry)) {
    if (record?.adoptedExternal || Number(record?.windowId || 0) !== Number(windowId)) continue;
    removed.push(Number(key));
    delete registry[key];
  }
  await saveTabRegistry(registry);
  for (const tabId of removed) await clearTabAffinityForTab(tabId).catch(() => {});
  return { removed, preservedAdopted: Object.values(registry).filter((record) => record?.adoptedExternal).length };
}

async function getTabAffinity() {
  const stored = await chrome.storage.local.get([STORAGE_KEYS.TAB_AFFINITY, STORAGE_KEYS.LAST_AGENT_TAB]);
  const tasks = stored?.[STORAGE_KEYS.TAB_AFFINITY];
  return {
    tasks: tasks && typeof tasks === "object" && !Array.isArray(tasks) ? tasks : {},
    lastTabId: Number(stored?.[STORAGE_KEYS.LAST_AGENT_TAB]?.tabId || stored?.[STORAGE_KEYS.LAST_AGENT_TAB] || 0) || null,
  };
}

async function bindTabAffinity(state, tabId, reason = "runtime") {
  const id = Number(tabId || 0);
  if (!id) return null;
  const record = await assertOwnedTab(id);
  const affinity = await getTabAffinity();
  const taskId = String(state?.taskId || "");
  const laneId = String(state?.arguments?._prstudio_lane_id || "");
  if (record.laneId && laneId && record.laneId !== laneId) {
    throw codedError("tab_lane_conflict", `La scheda ${id} appartiene a un'altra lane ChatGPT.`, { tabId: id, ownerLane: record.laneId, requestedLane: laneId });
  }
  if (taskId) {
    affinity.tasks[taskId] = { tabId: id, at: Date.now(), reason };
    const entries = Object.entries(affinity.tasks).sort((a, b) => Number(b[1]?.at || 0) - Number(a[1]?.at || 0)).slice(0, 100);
    affinity.tasks = Object.fromEntries(entries);
  }
  await chrome.storage.local.set({
    [STORAGE_KEYS.TAB_AFFINITY]: affinity.tasks,
    [STORAGE_KEYS.LAST_AGENT_TAB]: { tabId: id, at: Date.now(), reason },
  });
  await updateOwnedTab(id, { taskId: taskId || record.taskId || "", laneId: laneId || record.laneId || "", affinityReason: reason });
  if (state) state.tabId = id;
  return id;
}

async function clearTabAffinityForTab(tabId) {
  const id = Number(tabId || 0);
  const affinity = await getTabAffinity();
  const tasks = Object.fromEntries(Object.entries(affinity.tasks).filter(([, value]) => Number(value?.tabId || 0) !== id));
  const patch = { [STORAGE_KEYS.TAB_AFFINITY]: tasks };
  if (Number(affinity.lastTabId || 0) === id) patch[STORAGE_KEYS.LAST_AGENT_TAB] = null;
  await chrome.storage.local.set(patch);
}

async function clearAllTabAffinity() {
  await chrome.storage.local.set({ [STORAGE_KEYS.TAB_AFFINITY]: {}, [STORAGE_KEYS.LAST_AGENT_TAB]: null });
}

async function clearTabAffinityForTask(taskId) {
  const wanted = String(taskId || "");
  if (!wanted) return;
  const affinity = await getTabAffinity();
  const record = affinity.tasks?.[wanted] || null;
  const tasks = { ...(affinity.tasks || {}) };
  delete tasks[wanted];
  const patch = { [STORAGE_KEYS.TAB_AFFINITY]: tasks };
  if (record?.tabId && Number(affinity.lastTabId || 0) === Number(record.tabId)) patch[STORAGE_KEYS.LAST_AGENT_TAB] = null;
  await chrome.storage.local.set(patch);
  for (const tab of await listOwnedTabs()) {
    if (String(tab?.taskId || "") === wanted) {
      await updateOwnedTab(tab.tabId, { taskId: "", affinityReason: "fresh_restart_cleanup", updatedAt: Date.now() }).catch(() => {});
    }
  }
}

async function reconcileAgentOwnership() {
  const registry = await getTabRegistry();
  const liveTabs = await chrome.tabs.query({}).catch(() => []);
  const liveById = new Map(liveTabs.map((tab) => [Number(tab.id || 0), tab]).filter(([id]) => Boolean(id)));
  let changed = false;
  let rehydrated = 0;
  for (const key of Object.keys(registry)) {
    const tab = liveById.get(Number(key));
    const record = registry[key];
    const liveUrl = String(tab?.url || "");
    const ownership = provisionalOwnershipState(tab || {}, record || {});
    const candidateUrl = ownership.candidateUrl;
    const provisionalOkay = ownership.provisional && !isRestrictedLocalUrl(candidateUrl);
    const invalid = !tab
      || !record?.ownershipNonce
      || liveUrl.startsWith(chrome.runtime.getURL("agent.html"))
      || (!/^https?:/i.test(candidateUrl) && !provisionalOkay);
    if (invalid) { delete registry[key]; changed = true; continue; }
    if (Number(record.windowId || 0) !== Number(tab.windowId || 0)) {
      registry[key] = { ...record, windowId: Number(tab.windowId || 0), updatedAt: Date.now() };
      changed = true;
    }
    rehydrated += 1;
  }
  if (changed) await saveTabRegistry(registry);
  const affinity = await getTabAffinity();
  if (affinity.lastTabId && !registry[String(affinity.lastTabId)]) await clearTabAffinityForTab(affinity.lastTabId);
  return { windowId: await getAgentWindowId().catch(() => null), tabs: Object.keys(registry).length, rehydrated };
}

async function ownedTab(tabId) {
  const id = Number(tabId || 0);
  if (!id) return null;
  let registry = await getTabRegistry();
  let record = registry[String(id)] || null;
  const tab = await chrome.tabs.get(id).catch(() => null);
  if (!tab) {
    if (record) { delete registry[String(id)]; await saveTabRegistry(registry); }
    return null;
  }
  const ownership = provisionalOwnershipState(tab, record || {});
  const committedUrl = ownership.committedUrl;
  const candidateUrl = ownership.candidateUrl;
  const isSentinel = committedUrl.startsWith(chrome.runtime.getURL("agent.html"));
  const isWebPage = ownership.candidateHttp && !isRestrictedLocalUrl(candidateUrl);
  const provisionalOkay = ownership.provisional && !isRestrictedLocalUrl(candidateUrl);
  if (isSentinel || (!isWebPage && !provisionalOkay) || !record?.ownershipNonce) {
    if (record) {
      delete registry[String(id)];
      await saveTabRegistry(registry);
      await clearTabAffinityForTab(id).catch(() => {});
    }
    return null;
  }
  if (record?.provisional && /^https?:/i.test(committedUrl)) {
    record = await updateOwnedTab(id, { provisional: false, url: committedUrl, windowId: tab.windowId }) || record;
  } else if (record && Number(record.windowId || 0) !== Number(tab.windowId || 0)) {
    record = await updateOwnedTab(id, { windowId: tab.windowId }) || record;
  }
  return { ...record, url: candidateUrl || record.url || "", title: tab.title || record.title || "", windowId: tab.windowId };
}

async function assertOwnedTab(tabId) {
  const record = await ownedTab(tabId);
  if (!record) {
    throw codedError("technical_tab_not_controlled", `La scheda ${Number(tabId || 0) || "richiesta"} non è registrata come proprietà del Browser Agent. Nessuna scheda utente verrà utilizzata come fallback.`);
  }
  return record;
}

async function listOwnedTabs() {
  const registry = await getTabRegistry();
  const rows = [];
  for (const key of Object.keys(registry)) {
    const record = await ownedTab(Number(key));
    if (record) rows.push(record);
  }
  return rows.sort((a, b) => Number(a.createdAt || 0) - Number(b.createdAt || 0));
}

async function resolveTabId(state, step = {}) {
  const explicit = Number(step.tabId || step.tab_id || 0);
  if (explicit) {
    const explicitRecord = await ownedTab(explicit);
    if (explicitRecord) {
      await bindTabAffinity(state, explicit, "explicit_tab");
      return explicit;
    }
    throw codedError(
      "explicit_tab_not_owned",
      `La scheda esplicita ${explicit} non è registrata come proprietà del Browser Agent.`,
      { tabId: explicit }
    );
  }
  const stateTab = Number(state?.tabId || 0);
  if (stateTab) {
    const stateRecord = await ownedTab(stateTab);
    if (stateRecord) {
      await bindTabAffinity(state, stateTab, "state_tab");
      return stateTab;
    }
  }

  const expectedOrigin = String(step.expectedOrigin || step.expected_origin || "").trim();
  const expectedUrl = String(step.expectedUrl || step.expected_url || step.urlMatch || "").trim();
  const taskId = String(state?.taskId || "");
  const laneId = String(state?.arguments?._prstudio_lane_id || "");
  const affinity = await getTabAffinity();
  const taskBound = Number(affinity.tasks?.[taskId]?.tabId || 0);
  if (taskBound && await ownedTab(taskBound)) {
    const chosen = selectOwnedTabCandidate([await ownedTab(taskBound)], { taskId, laneId, expectedOrigin, expectedUrl, lastTabId: affinity.lastTabId });
    if (chosen.tabId) {
      await bindTabAffinity(state, chosen.tabId, "task_affinity");
      return chosen.tabId;
    }
  }

  const tabs = await listOwnedTabs();
  const chosen = selectOwnedTabCandidate(tabs, { taskId, laneId, expectedOrigin, expectedUrl, lastTabId: affinity.lastTabId });
  if (chosen.tabId) {
    await bindTabAffinity(state, chosen.tabId, chosen.reason || "owned_tab_affinity");
    return chosen.tabId;
  }
  // Never fall back to a user tab. If affinity is ambiguous or absent, create
  // a fresh Agent-owned page using the requested URL or the paired WordPress
  // site as deterministic bootstrap.
  const requestedUrl = String(step.url || step.expectedUrl || step.expected_url || "").trim();
  const config = await getConfig().catch(() => ({}));
  const bootstrapCandidate = requestedUrl || String(config?.siteUrl || config?.site_url || "").trim();
  if (bootstrapCandidate) {
    const url = validateNavigationUrl(bootstrapCandidate);
    const created = await createOwnedAgentTab(state, url, {
      taskId,
      laneId,
      expectedOrigin: (() => { try { return new URL(url).origin; } catch { return ""; } })(),
      waitUntil: step.waitUntil || step.wait_until || "interactive",
      timeoutMs: Number(step.timeoutMs || step.timeout_ms || 45000),
      reason: chosen.ambiguous ? "auto_disambiguated_new_tab" : "auto_bootstrap_tab",
    });
    return created.tabId;
  }
  throw codedError("agent_bootstrap_url_unavailable", "Nessuna scheda agente è disponibile e il pairing non espone un URL sito valido per crearne una in sicurezza.");
}

async function assertTargetBinding(tabId, step = {}, state = {}, options = {}) {
  const id = Number(tabId || 0);
  const record = await assertOwnedTab(id);
  const tab = await chrome.tabs.get(id).catch(() => null);
  if (!tab) throw codedError("target_tab_missing", `La scheda agente ${id} non esiste più.`, { tabId: id });
  const url = validateNavigationUrl(tab.url || record.url || "");
  const actualOrigin = new URL(url).origin;
  const expectedOrigin = String(step.expectedOrigin || step.expected_origin || record.expectedOrigin || "").trim();
  const allowedOrigins = Array.isArray(state?.arguments?.allowed_origins)
    ? state.arguments.allowed_origins.map((value) => String(value || "").trim()).filter(Boolean)
    : Array.isArray(state?.arguments?.allowedOrigins)
      ? state.arguments.allowedOrigins.map((value) => String(value || "").trim()).filter(Boolean)
      : [];
  if (allowedOrigins.length && !allowedOrigins.includes(actualOrigin)) {
    throw codedError("target_origin_not_allowed", `L'origine ${actualOrigin} non è inclusa nelle origini consentite dal task.`, { tabId: id, actualOrigin, allowedOrigins });
  }
  if (!options.allowNavigationTransition && expectedOrigin && actualOrigin !== expectedOrigin) {
    if (allowedOrigins.includes(actualOrigin)) {
      await updateOwnedTab(id, { expectedOrigin: actualOrigin, url, updatedAt: Date.now() });
      await appendLog("tab.allowed_origin_transition", { tabId: id, fromOrigin: expectedOrigin, toOrigin: actualOrigin, taskId: state?.taskId || "" }).catch(() => {});
    } else {
      throw codedError("target_origin_mismatch", `La scheda agente ha cambiato origine: attesa ${expectedOrigin}, trovata ${actualOrigin}.`, { tabId: id, expectedOrigin, actualOrigin });
    }
  }
  const requestedLane = String(state?.arguments?._prstudio_lane_id || "");
  const binding = tabBindingCompatibility(record, { taskId: state?.taskId || "", laneId: requestedLane });
  if (!binding.ok) {
    throw codedError("tab_lane_conflict", `La scheda ${id} appartiene a un'altra lane ChatGPT.`, { tabId: id, ownerLane: binding.ownerLane, requestedLane: binding.requestedLane });
  }
  let effectiveRecord = record;
  if (["lane_task_rebind", "session_task_rebind"].includes(binding.mode)) {
    effectiveRecord = await updateOwnedTab(id, { taskId: String(state?.taskId || ""), laneId: requestedLane || record.laneId || "", affinityReason: "lane_session_rebind" }) || record;
    await appendLog("tab.lane_task_rebound", { tabId: id, laneId: requestedLane || record.laneId || "", previousTaskId: record.taskId || "", taskId: state?.taskId || "" }).catch(() => {});
  }
  return { tabId: id, url, origin: actualOrigin, title: tab.title || "", record: effectiveRecord, binding: { mode: binding.mode, laneId: requestedLane || record.laneId || "" } };
}

async function ensureOwnedPage(state, step = {}) {
  const requested = String(step.url || step.expectedUrl || step.expected_url || "").trim();
  if (!requested) {
    const tabId = await resolveTabId(state, step);
    return { tabId, reused: true, url: (await chrome.tabs.get(tabId)).url || "" };
  }
  const url = validateNavigationUrl(requested);
  const expectedOrigin = step.expectedOrigin || step.expected_origin || new URL(url).origin;
  let tabId = null;
  try {
    tabId = await resolveTabId(state, { ...step, expectedOrigin, expectedUrl: url });
  } catch (error) {
    if (!['technical_tab_not_controlled', 'technical_tab_ambiguous'].includes(String(error?.code || ''))) throw error;
  }
  if (!tabId) {
    return createOwnedAgentTab(state, url, {
      taskId: state?.taskId || "",
      laneId: String(state?.arguments?._prstudio_lane_id || ""),
      expectedOrigin,
      waitUntil: step.waitUntil || step.wait_until || "interactive",
      timeoutMs: Number(step.timeoutMs || step.timeout_ms || 45000),
      reason: "auto_open_url",
    });
  }
  const current = await chrome.tabs.get(tabId);
  if (!urlMatches(String(current.url || ""), url)) {
    await chrome.tabs.update(tabId, { url });
    await waitForTab(tabId, step.waitUntil || step.wait_until || "interactive", Number(step.timeoutMs || step.timeout_ms || 45000)).catch(() => {});
  }
  const loaded = await chrome.tabs.get(tabId);
  await updateOwnedTab(tabId, { url: loaded.url || url, title: loaded.title || "", expectedOrigin, taskId: state?.taskId || "" });
  await bindTabAffinity(state, tabId, "auto_navigate_url");
  return { tabId, url: loaded.url || url, title: loaded.title || "", reused: true, background: true };
}

async function progressiveScroll(tabId, options = {}) {
  await assertOwnedTab(tabId);
  const deadlineAt = Number(options.deadlineAt || 0);
  const timeRemaining = () => deadlineAt > 0 ? Math.max(0, deadlineAt - Date.now()) : Number.POSITIVE_INFINITY;
  const readScrollState = async () => {
    const operation = chrome.scripting.executeScript({
      target: { tabId, allFrames: false },
      func: () => {
        const root = document.scrollingElement || document.documentElement;
        return {
          x: scrollX,
          y: scrollY,
          pageWidth: Math.max(root.scrollWidth, document.body?.scrollWidth || 0),
          pageHeight: Math.max(root.scrollHeight, document.body?.scrollHeight || 0),
          viewportWidth: innerWidth,
          viewportHeight: innerHeight,
        };
      },
    });
    const rows = deadlineAt > 0
      ? await promiseWithTimeout(
          operation,
          screenshotTimeoutRemainingMs(deadlineAt, 800, "scroll_state_read"),
          () => codedError("SCREENSHOT_SCROLL_TIMEOUT", "Lettura stato scroll oltre il timeout full-page screenshot.", { tabId, phase: "scroll_state_read" }),
        )
      : await operation;
    return rows?.[0]?.result || {};
  };
  const scrollInternalContainer = async () => {
    const operation = chrome.scripting.executeScript({
      target: { tabId, allFrames: false },
      func: async (budgetMs) => {
        const deadline = Date.now() + Math.max(100, Math.min(1200, Number(budgetMs || 900)));
        const visible = (el) => {
          const r = el.getBoundingClientRect();
          const s = getComputedStyle(el);
          return r.width > 80 && r.height > 80 && r.bottom > 0 && r.right > 0 && r.top < innerHeight && r.left < innerWidth && s.display !== 'none' && s.visibility !== 'hidden';
        };
        const candidates = [...document.querySelectorAll('main,section,div,article,[role="main"],[role="grid"],[role="table"]')]
          .filter((el) => {
            if (!visible(el)) return false;
            const style = getComputedStyle(el);
            return el.scrollHeight > el.clientHeight + 12 && /(auto|scroll|overlay)/i.test(style.overflowY || '');
          })
          .map((el) => {
            const r = el.getBoundingClientRect();
            return { el, score: Math.max(1, r.width * r.height) + Math.max(0, el.scrollHeight - el.clientHeight) };
          })
          .sort((a, b) => b.score - a.score);
        const target = candidates[0]?.el;
        if (!target) return { found: false };
        const original = target.scrollTop;
        let steps = 0;
        let stable = 0;
        let last = target.scrollTop;
        const maxSteps = 40;
        while (steps < maxSteps && stable < 2 && Date.now() < deadline) {
          const delta = Math.max(240, target.clientHeight * 0.82);
          target.scrollTop = Math.min(target.scrollHeight, target.scrollTop + delta);
          await new Promise((resolve) => setTimeout(resolve, 80));
          steps += 1;
          const atBottom = target.scrollTop + target.clientHeight >= target.scrollHeight - 4;
          stable = atBottom && Math.abs(target.scrollTop - last) < 1 ? stable + 1 : 0;
          last = target.scrollTop;
          if (atBottom && stable >= 2) break;
        }
        return {
          found: true,
          original,
          y: target.scrollTop,
          clientHeight: target.clientHeight,
          scrollHeight: target.scrollHeight,
          steps,
          stableBottom: target.scrollTop + target.clientHeight >= target.scrollHeight - 4,
          tag: target.tagName,
          role: target.getAttribute('role') || '',
          id: target.id || '',
        };
      },
      args: [Math.max(100, Math.min(650, timeRemaining()))],
    });
    const rows = deadlineAt > 0
      ? await promiseWithTimeout(
          operation,
          screenshotTimeoutRemainingMs(deadlineAt, 800, "scroll_internal_container"),
          () => codedError("SCREENSHOT_SCROLL_TIMEOUT", "Ricerca contenitore scroll oltre il timeout full-page screenshot.", { tabId, phase: "scroll_internal_container" }),
        )
      : await operation;
    return rows?.[0]?.result || { found: false };
  };

  const original = await readScrollState();
  const pause = Math.max(100, Math.min(1500, Number(options.pauseMs || 300)));
  const maxSteps = Math.max(4, Math.min(60, Number(options.maxSteps || 40)));
  let current = original;
  let lastHeight = Number(current.pageHeight || 0);
  let lastY = Number(current.y || 0);
  let stable = 0;
  let noProgress = 0;
  let steps = 0;
  let timeoutLimited = false;
  for (; steps < maxSteps && stable < 3; steps += 1) {
    throwIfTaskAborted();
    if (timeRemaining() < 250) { timeoutLimited = true; break; }
    const deltaY = Math.max(Number(current.viewportHeight || 720) * 0.82, 500);
    await (deadlineAt > 0 ? dispatchScreenshotScrollCommands : dispatchNativeCommands)(tabId, pointerSequence([{
      type: "wheel",
      x: Math.max(1, Number(current.viewportWidth || 1280) / 2),
      y: Math.max(1, Number(current.viewportHeight || 720) / 2),
      deltaY,
    }]), deadlineAt);
    const pauseBudget = Math.min(pause, Math.max(0, timeRemaining() - 150));
    if (pauseBudget < 50) { timeoutLimited = true; break; }
    await abortableSleep(pauseBudget, taskAbortController?.signal);
    current = await readScrollState();
    const atBottom = Number(current.y || 0) + Number(current.viewportHeight || 0) >= Number(current.pageHeight || 0) - 4;
    const moved = Math.abs(Number(current.y || 0) - lastY) > 2 || Number(current.pageHeight || 0) !== lastHeight;
    noProgress = !atBottom && !moved ? noProgress + 1 : 0;
    stable = atBottom && Number(current.pageHeight || 0) === lastHeight ? stable + 1 : 0;
    lastY = Number(current.y || 0);
    lastHeight = Number(current.pageHeight || 0);
    if (noProgress >= 2) {
      if (timeRemaining() < 250) { timeoutLimited = true; break; }
      const internal = await scrollInternalContainer();
      if (internal?.found) {
        await appendLog("scroll.internal_container_fallback", { tabId, rootState: current, internal });
        return { tabId, ...current, steps, stableBottom: Boolean(internal.stableBottom), internalContainer: internal, strategy: "dom_internal_scroll_container", restored: false, original, timeoutLimited: timeRemaining() < 250 };
      }
      throw codedError("STEP_NO_PROGRESS", "Lo scroll della pagina non produce progresso e non è stato trovato un contenitore interno scrollabile.", { tabId, steps, current });
    }
  }
  const finalState = { ...current, steps, stableBottom: stable >= 3, timeoutLimited };
  let restored = options.restore === false;
  if (options.restore !== false && timeRemaining() >= 250 && Math.abs(Number(current.y || 0) - Number(original.y || 0)) > 1) {
    await (deadlineAt > 0 ? dispatchScreenshotScrollCommands : dispatchNativeCommands)(tabId, pointerSequence([{
      type: "wheel",
      x: Math.max(1, Number(current.viewportWidth || 1280) / 2),
      y: Math.max(1, Number(current.viewportHeight || 720) / 2),
      deltaY: Number(original.y || 0) - Number(current.y || 0),
    }]), deadlineAt);
    await abortableSleep(Math.min(100, Math.max(25, timeRemaining() - 50)), taskAbortController?.signal);
    restored = true;
  } else if (options.restore !== false && Math.abs(Number(current.y || 0) - Number(original.y || 0)) <= 1) {
    restored = true;
  }
  return { tabId, ...finalState, restored, original, input: "cdp_native_mouse_wheel", strategy: "page_scroll" };
}

async function pageDimensions(tabId, options = {}) {
  try {
    const metrics = options.screenshotDeadlineAt
      ? await screenshotCdp(tabId, "Page.getLayoutMetrics", {}, options.screenshotDeadlineAt)
      : await cdp(tabId, "Page.getLayoutMetrics", {});
    const viewport = metrics?.cssVisualViewport || metrics?.visualViewport || {};
    const content = metrics?.cssContentSize || metrics?.contentSize || {};
    return {
      viewportWidth: Number(viewport.clientWidth || content.width || 1280),
      viewportHeight: Number(viewport.clientHeight || 720),
      contentWidth: Number(content.width || viewport.clientWidth || 1280),
      contentHeight: Number(content.height || viewport.clientHeight || 720),
      source: "cdp_layout_metrics",
    };
  } catch {
    const rows = await chrome.scripting.executeScript({
      target: { tabId, allFrames: false },
      func: () => {
        const root = document.scrollingElement || document.documentElement;
        return {
          viewportWidth: innerWidth || 1280,
          viewportHeight: innerHeight || 720,
          contentWidth: Math.max(root?.scrollWidth || 0, document.body?.scrollWidth || 0, innerWidth || 0),
          contentHeight: Math.max(root?.scrollHeight || 0, document.body?.scrollHeight || 0, innerHeight || 0),
        };
      },
    });
    return { ...(rows?.[0]?.result || { viewportWidth: 1280, viewportHeight: 720, contentWidth: 1280, contentHeight: 720 }), source: "dom_dimensions_fallback" };
  }
}

async function captureVisibleTabRateLimited(tabId, timeoutMs = LOCAL_CAPTURE_TIMEOUT_MS, deadlineAt = 0) {
  const record = await assertOwnedTab(tabId);
  const now = Date.now();
  const floorMs = 650;
  const waitMs = Math.max(0, floorMs - (now - lastVisibleCaptureAt));
  if (deadlineAt && waitMs >= screenshotTimeoutRemainingMs(deadlineAt, Math.max(250, waitMs + 1), "visible_capture_rate_limit")) {
    throw codedError("SCREENSHOT_TIMEOUT", "Tempo residuo insufficiente per il limite tecnico di captureVisibleTab.", { tabId, waitMs });
  }
  if (waitMs) await sleep(waitMs);
  const tabs = await chrome.tabs.query({ windowId: record.windowId, active: true }).catch(() => []);
  const previousActive = Number(tabs?.[0]?.id || 0);
  markAutomationInput(tabId, 2_500);
  if (previousActive !== Number(tabId)) await chrome.tabs.update(tabId, { active: true });
  let captureError = null;
  try {
    const dataUrl = await promiseWithTimeout(
      chrome.tabs.captureVisibleTab(record.windowId, { format: "png" }),
      deadlineAt ? screenshotTimeoutRemainingMs(deadlineAt, timeoutMs, "visible_capture") : timeoutMs,
      () => codedError("VISIBLE_CAPTURE_TIMEOUT", `captureVisibleTab oltre ${timeoutMs} ms.`, { tabId, timeoutMs })
    );
    lastVisibleCaptureAt = Date.now();
    const data = String(dataUrl || "").replace(/^data:image\/png;base64,/, "");
    if (!data) throw codedError("screenshot_visible_empty", "captureVisibleTab non ha restituito dati immagine.");
    return { data, via: "tabs.captureVisibleTab" };
  } catch (error) {
    captureError = error;
    throw error;
  } finally {
    // captureVisibleTab requires an active tab. Restore exactly the tab that was
    // active before the capture even when it is a user-owned tab; this avoids
    // a screenshot fallback stealing the operator's active-tab state.
    if (previousActive && previousActive !== Number(tabId)) {
      try {
        await chrome.tabs.update(previousActive, { active: true });
      } catch (restoreError) {
        if (!captureError) throw restoreError;
        await appendLog("screenshot.active_tab_restore_failed", { tabId, previousActive, captureError: serializeError(captureError), restoreError: serializeError(restoreError) }).catch(() => {});
      }
    }
  }
}

async function measureCapturedImage(base64Data, format = "png") {
  if (!base64Data || typeof createImageBitmap !== "function") return { width: null, height: null, verified: false };
  let bitmap = null;
  try {
    const response = await fetch(`data:image/${format === "jpeg" ? "jpeg" : "png"};base64,${base64Data}`);
    const blob = await response.blob();
    bitmap = await promiseWithTimeout(
      createImageBitmap(blob),
      SCREENSHOT_MEASURE_TIMEOUT_MS,
      () => codedError("SCREENSHOT_MEASURE_TIMEOUT", "Decodifica dimensioni screenshot oltre il timeout tecnico locale.", { timeoutMs: SCREENSHOT_MEASURE_TIMEOUT_MS }),
    );
    return { width: Number(bitmap.width || 0) || null, height: Number(bitmap.height || 0) || null, verified: Boolean(bitmap.width && bitmap.height) };
  } catch {
    return { width: null, height: null, verified: false };
  } finally {
    bitmap?.close?.();
  }
}

async function capturedShotEvidence(shot, details = {}) {
  const actualFormat = String(details.actualFormat || "png") === "jpeg" ? "jpeg" : "png";
  const dimensions = await measureCapturedImage(shot?.data || "", actualFormat);
  return { shot, ...details, actualFormat, actualWidth: dimensions.width, actualHeight: dimensions.height, actualDimensionsVerified: dimensions.verified };
}

function isScreenshotProtocolCompatibilityError(error) {
  const code = Number(error?.code);
  const message = String(error?.message || error || "");
  return code === -32602 || /invalid parameters?|unknown parameters?|unexpected parameters?|unsupported (?:parameter|option)|cannot deserialize|failed to deserialize/i.test(message);
}

async function captureScreenshotWithFallback(tabId, candidates = [], options = {}) {
  // The agent pointer is drawn into the page, so it would be captured with it.
  // Perception screenshots and visual baselines compare pixels; an overlay this
  // suite drew itself would read as a real change on the page.
  await hideAgentCursor(tabId);
  const deadlineAt = Number(options.deadlineAt || (Date.now() + SCREENSHOT_CAPTURE_TIMEOUT_MS));
  let lastError = null;
  let attempts = 0;
  for (let index = 0; index < candidates.length; index += 1) {
    screenshotTimeoutRemainingMs(deadlineAt, SCREENSHOT_CDP_TIMEOUT_MS, "cdp_capture");
    attempts += 1;
    try {
      const shot = await screenshotCdp(tabId, "Page.captureScreenshot", candidates[index], deadlineAt);
      if (shot?.data) return capturedShotEvidence(shot, { downgradeLevel: index, params: candidates[index], via: "cdp", actualFormat: String(candidates[index]?.format || "png") });
      lastError = codedError("screenshot_empty", "Chrome DevTools Protocol non ha restituito dati immagine.");
      const alternate = candidates.findIndex((candidate, candidateIndex) => candidateIndex > index && (candidate?.fromSurface === false) !== (candidates[index]?.fromSurface === false));
      if (alternate > index && deadlineAt - Date.now() >= 500) {
        await appendLog("screenshot.capture_source_fallback", { tabId, level: index, nextLevel: alternate, error: serializeError(lastError) }).catch(() => {});
        index = alternate - 1;
        continue;
      }
      await appendLog("screenshot.protocol_fast_fallback", { tabId, level: index, error: serializeError(lastError) }).catch(() => {});
      break;
    } catch (error) {
      lastError = error;
      if (isScreenshotProtocolCompatibilityError(error) && index + 1 < candidates.length && deadlineAt - Date.now() >= 500) {
        await appendLog("screenshot.protocol_downgrade", { tabId, level: index, error: serializeError(error) }).catch(() => {});
        continue;
      }
      const alternate = candidates.findIndex((candidate, candidateIndex) => candidateIndex > index && (candidate?.fromSurface === false) !== (candidates[index]?.fromSurface === false));
      if (alternate > index && deadlineAt - Date.now() >= 500) {
        await appendLog("screenshot.capture_source_fallback", { tabId, level: index, nextLevel: alternate, error: serializeError(error) }).catch(() => {});
        index = alternate - 1;
        continue;
      }
      await appendLog("screenshot.protocol_fast_fallback", { tabId, level: index, error: serializeError(error) }).catch(() => {});
      break;
    }
  }
  try {
    const visibleTimeout = screenshotTimeoutRemainingMs(deadlineAt, SCREENSHOT_VISIBLE_TIMEOUT_MS, "visible_capture");
    const shot = await captureVisibleTabRateLimited(tabId, visibleTimeout, deadlineAt);
    return capturedShotEvidence(shot, { downgradeLevel: attempts, params: {}, via: shot.via, actualFormat: "png" });
  } catch (fallbackError) {
    throw codedError("screenshot_failed", "Screenshot non disponibile né via CDP né via captureVisibleTab.", {
      cdp: serializeError(lastError),
      fallback: serializeError(fallbackError),
    });
  }
}

async function captureScreenshot(tabId, fullPage = false, lazyLoad = false, options = {}) {
  await assertOwnedTab(tabId);
  const deadlineAt = Number(options.deadlineAt || (Date.now() + SCREENSHOT_CAPTURE_TIMEOUT_MS));
  let scroll = null;
  if (fullPage && lazyLoad) {
    const scrollDeadlineAt = Math.min(deadlineAt - 2_500, Date.now() + SCREENSHOT_SCROLL_TIMEOUT_MS);
    if (scrollDeadlineAt - Date.now() >= 250) scroll = await progressiveScroll(tabId, { restore: true, maxSteps: 30, pauseMs: 160, deadlineAt: scrollDeadlineAt });
    else scroll = { skipped: true, timeoutLimited: true, restored: true, strategy: "screenshot_timeout" };
  }
  // Screenshot fast path needs an attached CDP session, not the full
  // Runtime/Network/Log observability stack initialized by attachDebugger().
  await attachDebuggerIfNeeded(tabId, screenshotTimeoutRemainingMs(deadlineAt, SCREENSHOT_ATTACH_TIMEOUT_MS, "cdp_attach"));
  const metrics = await pageDimensions(tabId, { screenshotDeadlineAt: deadlineAt });
  const requestedWidth = Math.max(1, Math.ceil(fullPage ? metrics.contentWidth : metrics.viewportWidth));
  const requestedHeight = Math.max(1, Math.ceil(fullPage ? metrics.contentHeight : metrics.viewportHeight));
  const maxPixels = Math.max(1_000_000, Math.min(SCREENSHOT_MAX_PIXELS, Number(options.maxPixels || options.max_pixels || SCREENSHOT_MAX_PIXELS)));
  const plannedWidth = Math.min(SCREENSHOT_MAX_DIMENSION, requestedWidth);
  const maxHeightByPixels = Math.max(1, Math.floor(maxPixels / Math.max(1, plannedWidth)));
  const plannedHeight = Math.min(SCREENSHOT_MAX_DIMENSION, requestedHeight, maxHeightByPixels);
  const requestedFormat = String(options.format || "auto").toLowerCase();
  const pixels = plannedWidth * plannedHeight;
  const format = requestedFormat === "jpeg" || requestedFormat === "jpg"
    ? "jpeg"
    : requestedFormat === "png"
      ? "png"
      : (fullPage && pixels >= SCREENSHOT_LARGE_PIXEL_THRESHOLD ? "jpeg" : "png");
  const quality = Math.max(35, Math.min(92, Number(options.quality || 82)));
  // An explicit region is the reference "zoom": photograph this rectangle,
  // optionally magnified, instead of the whole viewport. It wins over the
  // full-page and perception clips because the caller named it.
  const requestedRegion = options.region && Number.isFinite(Number(options.region.width)) && Number.isFinite(Number(options.region.height))
    ? {
        x: Math.max(0, Number(options.region.x || 0)),
        y: Math.max(0, Number(options.region.y || 0)),
        width: Math.max(1, Number(options.region.width)),
        height: Math.max(1, Number(options.region.height)),
        scale: Math.max(0.1, Math.min(4, Number(options.scale || 1))),
      }
    : null;
  const base = { format, fromSurface: true, captureBeyondViewport: Boolean(fullPage) };
  if (format === "jpeg") base.quality = quality;
  const perception = Boolean(options.perception);
  const scale = perception ? Math.min(1, PERCEPTION_MAX_DIMENSION / Math.max(plannedWidth, plannedHeight), Math.sqrt(maxPixels / Math.max(1, plannedWidth * plannedHeight))) : 1;
  if (requestedRegion) base.clip = requestedRegion;
  else if (fullPage || perception) base.clip = { x: 0, y: 0, width: plannedWidth, height: plannedHeight, scale };
  // Built by lib/screenshot-candidates.js so the chain and the test that
  // guards it cannot drift. The property that matters is that the chain ends
  // with a renderer capture: a tab created with active:false has no compositor
  // surface, and every surface variant photographs nothing at all.
  const captureTab = await chrome.tabs.get(Number(tabId)).catch(() => null);
  const preferRenderer = captureTab?.active === false;
  const compatible = buildScreenshotCandidates({
    format,
    quality,
    fullPage: Boolean(fullPage),
    clip: requestedRegion || ((fullPage || perception) ? { x: 0, y: 0, width: plannedWidth, height: plannedHeight, scale } : null),
    preferRenderer,
  });
  const captured = await captureScreenshotWithFallback(tabId, compatible, { deadlineAt });
  const actualFormat = captured.actualFormat === "jpeg" ? "jpeg" : "png";
  const capturedCssWidth = Number(captured.params?.clip?.width || metrics.viewportWidth || plannedWidth);
  const capturedCssHeight = Number(captured.params?.clip?.height || metrics.viewportHeight || plannedHeight);
  const bitmapCoversRequestedClip = !captured.actualDimensionsVerified
    || (Number(captured.actualWidth || 0) >= Math.ceil(capturedCssWidth) && Number(captured.actualHeight || 0) >= Math.ceil(capturedCssHeight));
  const fullPageComplete = !fullPage || (captured.via === "cdp" && Boolean(captured.params?.clip)
    && capturedCssWidth >= requestedWidth && capturedCssHeight >= requestedHeight && bitmapCoversRequestedClip);
  const width = Number(captured.actualWidth || 0) || Math.ceil(capturedCssWidth);
  const height = Number(captured.actualHeight || 0) || Math.ceil(capturedCssHeight);
  return {
    tabId,
    dataUrl: `data:image/${actualFormat};base64,${captured.shot.data}`,
    mimeType: actualFormat === "jpeg" ? "image/jpeg" : "image/png",
    format: actualFormat,
    quality: actualFormat === "jpeg" ? quality : null,
    width,
    height,
    requestedWidth,
    requestedHeight,
    capturedCssWidth,
    capturedCssHeight,
    bitmapCoversRequestedClip,
    actualDimensionsVerified: Boolean(captured.actualDimensionsVerified),
    fullPageRequested: Boolean(fullPage),
    fullPageComplete,
    truncatedForSafety: Boolean(fullPage && !fullPageComplete),
    maxPixels,
    scroll,
    background: true,
    focusChanged: false,
    protocolVersion: debuggerProtocolByTab.get(Number(tabId)) || DEBUGGER_PROTOCOL_CANDIDATES[0],
    captureMode: captured.via === "tabs.captureVisibleTab" ? "tabs_capture_fallback" : (captured.downgradeLevel === 0 ? "cdp_native" : "cdp_compatible_downgrade"),
    downgradeLevel: captured.downgradeLevel,
    dimensionsSource: captured.actualDimensionsVerified ? "decoded_image_bitmap" : `${metrics.source}_estimated`,
  };
}

async function captureElementScreenshot(tabId, boundingBox, options = {}) {
  await assertOwnedTab(tabId);
  const deadlineAt = Number(options.deadlineAt || (Date.now() + SCREENSHOT_CAPTURE_TIMEOUT_MS));
  const box = boundingBox || {};
  const x = Number(box.pageX ?? box.x);
  const y = Number(box.pageY ?? box.y);
  const width = Number(box.width);
  const height = Number(box.height);
  if (![x, y, width, height].every(Number.isFinite) || width <= 0 || height <= 0) {
    throw codedError("element_box_invalid", "Bounding box dell’elemento non valida.");
  }
  await attachDebuggerIfNeeded(tabId, screenshotTimeoutRemainingMs(deadlineAt, SCREENSHOT_ATTACH_TIMEOUT_MS, "cdp_attach"));
  const clip = { x, y, width, height, scale: 1 };
  const captureTab = await chrome.tabs.get(Number(tabId)).catch(() => null);
  const captured = await captureScreenshotWithFallback(tabId, buildScreenshotCandidates({
    format: "png",
    fullPage: true,
    clip,
    preferRenderer: captureTab?.active === false,
  }), { deadlineAt });
  const actualFormat = captured.actualFormat === "jpeg" ? "jpeg" : "png";
  const elementComplete = captured.via === "cdp" && Boolean(captured.params?.clip)
    && (!captured.actualDimensionsVerified || (Number(captured.actualWidth || 0) >= Math.ceil(width) && Number(captured.actualHeight || 0) >= Math.ceil(height)));
  return {
    tabId,
    dataUrl: `data:image/${actualFormat};base64,${captured.shot.data}`,
    mimeType: actualFormat === "jpeg" ? "image/jpeg" : "image/png",
    format: actualFormat,
    width: Number(captured.actualWidth || 0) || Math.ceil(elementComplete ? width : 0) || null,
    height: Number(captured.actualHeight || 0) || Math.ceil(elementComplete ? height : 0) || null,
    requestedWidth: Math.ceil(width),
    requestedHeight: Math.ceil(height),
    elementComplete,
    actualDimensionsVerified: Boolean(captured.actualDimensionsVerified),
    background: true,
    focusChanged: false,
    protocolVersion: debuggerProtocolByTab.get(Number(tabId)) || DEBUGGER_PROTOCOL_CANDIDATES[0],
    captureMode: captured.via === "tabs.captureVisibleTab" ? "tabs_capture_fallback" : (captured.downgradeLevel === 0 ? "cdp_native" : "cdp_compatible_downgrade"),
    downgradeLevel: captured.downgradeLevel,
  };
}

async function sha256Text(value) {
  const bytes = new TextEncoder().encode(String(value || ""));
  const digest = await crypto.subtle.digest("SHA-256", bytes);
  return [...new Uint8Array(digest)].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

async function getBaselines() {
  const stored = await chrome.storage.local.get(STORAGE_KEYS.BASELINES);
  const value = stored?.[STORAGE_KEYS.BASELINES];
  return value && typeof value === "object" && !Array.isArray(value) ? value : {};
}

async function applyEmulation(tabId, command, value) {
  await assertOwnedTab(tabId);
  await attachDebugger(tabId);
  switch (command) {
    case "geolocation":
      await cdp(tabId, "Emulation.setGeolocationOverride", value || {}); break;
    case "locale":
      await cdp(tabId, "Emulation.setLocaleOverride", { locale: String(value || "") }); break;
    case "timezone":
      await cdp(tabId, "Emulation.setTimezoneOverride", { timezoneId: String(value || "") }); break;
    case "user_agent":
      await cdp(tabId, "Emulation.setUserAgentOverride", { userAgent: String(value || "") }); break;
    case "viewport":
    case "device": {
      const v = value || {};
      await cdp(tabId, "Emulation.setDeviceMetricsOverride", {
        width: Number(v.width || 1280), height: Number(v.height || 720),
        deviceScaleFactor: Number(v.deviceScaleFactor || v.device_scale_factor || 1), mobile: Boolean(v.mobile),
      });
      break;
    }
    case "media": {
      const v = value || {};
      const features = [];
      if (v.colorScheme || v.color_scheme) features.push({ name: "prefers-color-scheme", value: v.colorScheme || v.color_scheme });
      if (v.reducedMotion || v.reduced_motion) features.push({ name: "prefers-reduced-motion", value: v.reducedMotion || v.reduced_motion });
      await cdp(tabId, "Emulation.setEmulatedMedia", { media: v.media || "", features });
      break;
    }
    case "offline":
      await cdp(tabId, "Network.enable", {});
      await cdp(tabId, "Network.emulateNetworkConditions", { offline: Boolean(value), latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
      break;
    case "headers":
      await cdp(tabId, "Network.enable", {});
      await cdp(tabId, "Network.setExtraHTTPHeaders", { headers: value || {} });
      break;
    case "cpu":
      await cdp(tabId, "Emulation.setCPUThrottlingRate", { rate: Math.max(1, Number(value || 1)) });
      break;
    case "network": {
      const v = value || {};
      await cdp(tabId, "Network.enable", {});
      await cdp(tabId, "Network.emulateNetworkConditions", {
        offline: Boolean(v.offline), latency: Number(v.latency || 0),
        downloadThroughput: Number(v.downloadThroughput ?? v.download_throughput ?? -1),
        uploadThroughput: Number(v.uploadThroughput ?? v.upload_throughput ?? -1),
        connectionType: v.connectionType || v.connection_type || undefined,
      });
      break;
    }
    default:
      throw codedError("emulation_command_invalid", `Comando di emulazione non riconosciuto: ${command}`);
  }
  return { command, applied: true, value };
}

async function captureStructuredNetworkPayload(tabId, requestId) {
  if (!requestId) return;
  const generation = gscCollectionGenerations.get(Number(tabId)) || null;
  const requests = gscRequestGenerations.get(Number(tabId)) || new Map();
  const requestGenerationId = requests.get(String(requestId)) || "";
  requests.delete(String(requestId));
  if (!generation?.generationId || requestGenerationId !== generation.generationId) return;
  const events = networkBuffers.get(tabId) || [];
  const responseEvent = [...events].reverse().find((item) => item.method === "Network.responseReceived" && item.params?.requestId === requestId);
  const url = String(responseEvent?.params?.response?.url || "");
  const mime = String(responseEvent?.params?.response?.mimeType || "");
  let host = "";
  try { host = new URL(url).hostname; } catch { return; }
  if (!/(^|\.)search\.google\.com$|(^|\.)google\.com$/i.test(host)) return;
  if (!/json|javascript|text/i.test(mime) && !/search-console|webmasters|performance|analytics/i.test(url)) return;
  const body = await cdp(tabId, "Network.getResponseBody", { requestId }).catch(() => null);
  if (!body?.body || body.base64Encoded) return;
  const raw = String(body.body || "").trim();
  if (!raw) return;
  const rawBytes = new TextEncoder().encode(raw).length;
  if (rawBytes > GSC_PAYLOAD_ITEM_MAX_BYTES) return;
  const normalizedRaw = raw.replace(/^\)\]\}'\s*(?:\r?\n)?/, "");
  let payload = null;
  try { payload = JSON.parse(normalizedRaw); } catch {
    const candidate = normalizedRaw.match(/^[^(]*\((\{[\s\S]*\}|\[[\s\S]*\])\)\s*;?$/);
    if (candidate) { try { payload = JSON.parse(candidate[1]); } catch { /* ignored */ } }
  }
  if (!payload) return;
  const list = structuredNetworkPayloads.get(tabId) || [];
  list.push({ url, requestId, payload, bytes: rawBytes, at: Date.now(), generationId: generation.generationId, collectionFingerprint: generation.collectionFingerprint });
  const retained = [];
  let retainedBytes = 0;
  for (const item of [...list].reverse()) {
    const itemBytes = Math.max(1, Number(item?.bytes || approximateJsonBytes(item?.payload || null)));
    if (retained.length >= 24 || retainedBytes + itemBytes > GSC_PAYLOAD_TOTAL_MAX_BYTES) continue;
    retained.push(item);
    retainedBytes += itemBytes;
  }
  structuredNetworkPayloads.set(tabId, retained.reverse());
}

async function extractMetricTablesFromPage(tabId) {
  const results = await chrome.scripting.executeScript({
    target: { tabId, allFrames: false },
    func: () => {
      const visible = (el) => {
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.display !== "none" && style.visibility !== "hidden";
      };
      const text = (el) => String(el?.innerText || el?.textContent || "").replace(/\s+/g, " ").trim();
      const tables = [];
      const roots = [...document.querySelectorAll("table,[role='table'],[role='grid'],[role='treegrid']")].filter(visible).slice(0, 30);
      for (const root of roots) {
        let headers = [...root.querySelectorAll("thead th,[role='columnheader']")].filter(visible).map(text).filter(Boolean);
        const rowNodes = [...root.querySelectorAll("tbody tr,[role='row']")].filter(visible).slice(0, 5000);
        const rows = [];
        for (const row of rowNodes) {
          const cells = [...row.querySelectorAll(":scope > td,:scope > th,:scope > [role='gridcell'],:scope > [role='cell']")].filter(visible).map(text);
          if (!headers.length) {
            const headerCells = [...row.querySelectorAll(":scope > [role='columnheader'],:scope > th")].filter(visible).map(text).filter(Boolean);
            if (headerCells.length) { headers = headerCells; continue; }
          }
          if (cells.length) rows.push(cells);
        }
        if (headers.length && rows.length) tables.push({ headers, rows });
      }
      const meta = {};
      for (const name of ["description", "og:title", "og:description", "twitter:title", "twitter:description"]) {
        const selector = name.startsWith("og:") || name.startsWith("twitter:") ? `meta[property="${name}"],meta[name="${name}"]` : `meta[name="${name}"]`;
        const value = document.querySelector(selector)?.getAttribute("content") || "";
        if (value) meta[name] = value;
      }
      return { tables, meta, url: location.href, title: document.title };
    },
  });
  return results?.[0]?.result || { tables: [], meta: {}, url: "", title: "" };
}

async function extractStructuredSearchConsoleInspection(tabId, generation = null) {
  const dom = await chrome.scripting.executeScript({
    target: { tabId, allFrames: false },
    func: () => {
      const visible = (el) => {
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.display !== "none" && style.visibility !== "hidden";
      };
      const normalize = (value) => String(value || "").replace(/\s+/g, " ").trim();
      const labelRe = /(canonical|canonic|ultima scansione|last crawl|crawled as|scansione eseguita come|crawl allowed|scansione consentita|page fetch|recupero pagina|indexing allowed|indicizzazione consentita|coverage|copertura|page indexing|indicizzazione della pagina)/i;
      const pairs = [];
      const nodes = [...document.querySelectorAll("div,span,dt,th,label,[role='rowheader'],[role='heading']")].filter(visible).slice(0, 12000);
      for (const node of nodes) {
        const label = normalize(node.innerText || node.textContent);
        if (!label || label.length > 120 || !labelRe.test(label)) continue;
        let parent = node.parentElement;
        for (let depth = 0; parent && depth < 3; depth += 1, parent = parent.parentElement) {
          const whole = normalize(parent.innerText || parent.textContent);
          if (!whole || whole.length > 1200 || whole === label) continue;
          const value = normalize(whole.replace(label, ""));
          if (value && value.length <= 1000) { pairs.push({ label, value }); break; }
        }
        if (pairs.length >= 100) break;
      }
      return { text: String(document.body?.innerText || "").slice(0, 200000), pairs, url: location.href, title: document.title };
    },
  }).catch(() => []);
  const page = dom?.[0]?.result || { text: "", pairs: [], url: "", title: "" };
  const networkPayloads = (structuredNetworkPayloads.get(tabId) || [])
    .filter((item) => generation?.generationId && item.generationId === generation.generationId && item.collectionFingerprint === generation.collectionFingerprint)
    .map((item) => item.payload).filter(Boolean);
  return { ...extractSearchConsoleInspection({ text: page.text, pairs: page.pairs, networkPayloads }), url: page.url, title: page.title, sources: { dom_pairs: page.pairs.length, network_payloads: networkPayloads.length } };
}

async function readSearchConsoleDimensionState(tabId) {
  const result = await chrome.scripting.executeScript({
    target: { tabId, allFrames: false },
    func: () => {
      const visible = (el) => {
        const rect = el.getBoundingClientRect();
        const style = getComputedStyle(el);
        return rect.width > 0 && rect.height > 0 && style.display !== "none" && style.visibility !== "hidden";
      };
      const clean = (value) => String(value || "").replace(/\s+/g, " ").trim();
      const controls = [...document.querySelectorAll('[role="tab"],button,[role="button"]')]
        .filter(visible)
        .map((el) => ({
          label: clean(el.getAttribute("aria-label") || el.innerText || el.textContent),
          selected: el.getAttribute("aria-selected") === "true" || el.getAttribute("aria-pressed") === "true" || /selected|active/i.test(String(el.className || "")),
        }))
        .filter((item) => item.label)
        .slice(0, 300);
      const tables = [];
      for (const table of [...document.querySelectorAll('table,[role="table"],[role="grid"]')].filter(visible).slice(0, 40)) {
        const header = table.querySelector('thead th,[role="columnheader"],th');
        const value = clean(header?.innerText || header?.textContent || header?.getAttribute?.("aria-label"));
        if (value) tables.push(value);
      }
      return { controls, tableHeaders: tables, url: location.href, title: document.title };
    },
  }).catch(() => []);
  const state = result?.[0]?.result || { controls: [], tableHeaders: [], url: "", title: "" };
  const selected = (state.controls || []).filter((item) => item.selected).map((item) => item.label);
  const inferred = (state.tableHeaders || []).map((header) => inferGscDimensionFromHeaders([header])).find(Boolean) || "";
  return { ...state, selected, inferred_dimension: inferred };
}

async function switchSearchConsoleDimension(tabId, dimension) {
  const dim = normalizeGscDimensions([dimension])[0];
  const aliases = gscDimensionAliases(dim);
  let state = await readSearchConsoleDimensionState(tabId);
  const selectedMatch = state.selected.some((label) => labelMatchesGscDimension(label, dim));
  const headerMatch = state.tableHeaders.some((header) => headerMatchesGscDimension(header, dim));
  if (selectedMatch && headerMatch) return { ok: true, dimension: dim, state, changed: false };

  const snapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true }).catch(() => ({ interactive: [] }));
  const wanted = new Set(aliases.map((value) => String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, " ").trim()));
  const target = (snapshot.interactive || []).find((item) => {
    const role = String(item?.role || "").toLowerCase();
    if (!["tab", "button"].includes(role)) return false;
    const label = String(item?.accessibleName || item?.text || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, " ").trim();
    return wanted.has(label);
  });
  if (!target) return { ok: false, dimension: dim, state, error: "dimension_control_missing" };
  await runNativeElementAction(tabId, "click", {
    targetRef: target.targetRef || "",
    role: target.role || "",
    name: target.accessibleName || target.text || "",
    selector: target.selector || "",
  });

  const deadline = Date.now() + 7000;
  while (Date.now() < deadline) {
    await sleep(300);
    state = await readSearchConsoleDimensionState(tabId);
    const selectedNow = state.selected.some((label) => labelMatchesGscDimension(label, dim));
    const headerNow = state.tableHeaders.some((header) => headerMatchesGscDimension(header, dim));
    if (selectedNow && headerNow) return { ok: true, dimension: dim, state, changed: true };
  }
  return { ok: false, dimension: dim, state, error: "dimension_not_verified" };
}

async function extractStructuredSearchConsoleMetrics(tabId, args = {}, expectedDimension = "query", generation = null) {
  const dimension = normalizeGscDimensions([expectedDimension])[0];
  const dimensionState = await readSearchConsoleDimensionState(tabId);
  const matchingHeaders = (dimensionState.tableHeaders || []).filter((header) => headerMatchesGscDimension(header, dimension));
  if (!matchingHeaders.length) {
    return {
      dimension,
      rows: [], row_count: 0, verified: false,
      extraction_status: "dimension_header_unverified",
      dimension_integrity: { status: "rejected", expected: dimension, observed: dimensionState.inferred_dimension || "", reason: "active_table_header_mismatch" },
      no_data_reason: "La dimensione GSC attiva non è verificabile dalla tabella; i dati non vengono etichettati né restituiti.",
    };
  }

  const page = await extractMetricTablesFromPage(tabId).catch(() => ({ tables: [], meta: {} }));
  const domRows = [];
  let acceptedTables = 0;
  for (const table of page.tables || []) {
    if (!headerMatchesGscDimension((table.headers || [])[0] || "", dimension)) continue;
    acceptedTables += 1;
    const normalized = normalizeMetricGrid(table.headers || [], table.rows || []);
    for (const row of normalized) {
      const firstHeader = String((table.headers || [])[0] || "");
      const firstValue = row[Object.keys(row)[0]];
      if (typeof row[dimension] !== "string" && firstValue != null) row[dimension] = String(firstValue);
      if (!row[dimension] && firstValue != null && headerMatchesGscDimension(firstHeader, dimension)) row[dimension] = String(firstValue);
      domRows.push(row);
    }
  }
  const networkRows = [];
  const generationPayloads = (structuredNetworkPayloads.get(tabId) || [])
    .filter((item) => generation?.generationId && item.generationId === generation.generationId && item.collectionFingerprint === generation.collectionFingerprint);
  for (const item of generationPayloads) {
    networkRows.push(...extractMetricRowsFromPayload(item.payload, [dimension]));
  }
  const validation = validateGscDimensionRows(dedupeMetricRows([...networkRows, ...domRows]), dimension);
  const rows = validation.rows.map(({ _verified_dimension, ...row }) => row);
  return {
    dimension,
    schema: { dimensions: [dimension], metrics: ["clicks", "impressions", "ctr", "position"], ctr_unit: "ratio" },
    rows,
    row_count: rows.length,
    sources: {
      network_payloads: generationPayloads.length,
      network_rows: networkRows.length,
      dom_tables: (page.tables || []).length,
      dimension_bound_dom_tables: acceptedTables,
      dom_rows: domRows.length,
    },
    extraction_status: rows.length ? "structured_dimension_verified" : "no_verified_rows",
    collection_completeness: "bounded_by_search_console_and_observed_ui",
    row_exhaustiveness: "not_guaranteed",
    totals_scope: "active_search_console_report_and_dimension",
    collector_verified: true,
    data_verified: rows.length > 0,
    no_data_reason: rows.length ? "" : "La dimensione è verificata ma non sono state esposte righe metriche verificabili; nessun dato è stato inventato.",
    dimension_integrity: {
      status: "verified",
      expected: dimension,
      observed: dimension,
      binding: "active_tab_and_first_table_header",
      rejected_rows: validation.rejected,
      cross_dimension_join: false,
      collection_generation: generation ? { generationId: generation.generationId, collectionFingerprint: generation.collectionFingerprint } : null,
    },
    verified: rows.length > 0,
  };
}

async function collectSearchConsoleDimensions(tabId, args = {}) {
  const unsupported = unsupportedGscDimensions(args.dimensions);
  if (unsupported.length) {
    throw codedError(
      "search_console_dimension_unsupported",
      `Dimensione GSC non verificabile dal collector Browser: ${unsupported.join(", ")}. La dimensione hour resta API-only e richiede dataState=hourly_all.`,
    );
  }
  const requested = normalizeGscDimensions(args.dimensions);
  const collections = [];
  for (const dimension of requested) {
    const generation = await beginGscCollectionGeneration(tabId, args, dimension);
    const switched = await switchSearchConsoleDimension(tabId, dimension);
    if (!switched.ok) {
      collections.push({
        dimension,
        rows: [], row_count: 0, verified: false,
        dimension_integrity: { status: "rejected", expected: dimension, observed: switched.state?.inferred_dimension || "", reason: switched.error || "dimension_switch_failed", collection_generation: { generationId: generation.generationId, collectionFingerprint: generation.collectionFingerprint } },
      });
      continue;
    }
    await sleep(Math.max(250, Math.min(1500, Number(args.dimension_settle_ms || 600))));
    collections.push(await extractStructuredSearchConsoleMetrics(tabId, args, dimension, generation));
  }
  const merged = mergeGscDimensionCollections(collections, requested);
  return {
    ...merged,
    schema: { dimensions: requested, metrics: ["clicks", "impressions", "ctr", "position"], ctr_unit: "ratio" },
    collector_contract: "gsc_dimension_session_v4",
    extraction_status: merged.verified ? "structured_dimension_verified" : "partial_or_unverified_dimensions",
    sources: Object.fromEntries(collections.map((item) => [item.dimension, item.sources || {}])),
    no_data_reason: merged.verified ? "" : `Dimensioni non verificate: ${merged.missing_dimensions.join(", ")}. Nessun dato è stato inventato.`,
  };
}

async function extractPublicPageFallback(tabId) {
  const page = await extractMetricTablesFromPage(tabId).catch(() => ({ meta: {}, url: "", title: "" }));
  const result = await chrome.scripting.executeScript({
    target: { tabId, allFrames: false },
    func: () => {
      const jsonLd = [...document.querySelectorAll('script[type="application/ld+json"]')]
        .map((node) => String(node.textContent || "").trim()).filter(Boolean).slice(0, 20);
      const canonical = document.querySelector('link[rel="canonical"]')?.href || "";
      const headings = [...document.querySelectorAll("h1,h2")].map((node) => String(node.innerText || "").replace(/\s+/g, " ").trim()).filter(Boolean).slice(0, 20);
      return { canonical, jsonLd, headings };
    },
  }).catch(() => []);
  return {
    provider: "public_document_metadata_fallback",
    url: page.url,
    title: page.title,
    meta: page.meta || {},
    ...(result?.[0]?.result || {}),
    verified: true,
  };
}

function searchConsoleUrl(mode, args = {}) {
  const site = String(args.site_url || args.siteUrl || "").trim();
  const resource = site ? `?resource_id=${encodeURIComponent(site)}` : "";
  const paths = {
    sites: "/",
    status: "/",
    search_analytics: "/performance/search-analytics",
    sitemaps: "/sitemaps",
    url_inspection: "/inspect",
    request_indexing: "/inspect",
  };
  return `https://search.google.com/search-console${paths[mode] || "/"}${resource}`;
}

function gscLaneKey(step = {}) {
  const lane = String(step._prstudio_lane_id || step.laneId || "").trim();
  return lane || "legacy";
}

function stableCollectionValue(value) {
  if (Array.isArray(value)) return value.map(stableCollectionValue);
  if (!value || typeof value !== "object") return value ?? null;
  return Object.fromEntries(Object.keys(value).sort().map((key) => [key, stableCollectionValue(value[key])]));
}

function collectionFingerprint(step = {}, dimension = "", navigationUrl = "") {
  let navigation = String(navigationUrl || "");
  try {
    const url = new URL(navigation);
    navigation = `${url.origin}${url.pathname}?resource_id=${encodeURIComponent(url.searchParams.get("resource_id") || "")}`;
  } catch { /* retain the observed navigation string */ }
  return JSON.stringify(stableCollectionValue({
    lane: gscLaneKey(step),
    property: normalizeGscProperty(step.site_url || step.siteUrl || ""),
    dateStart: String(step.start_date || step.startDate || step.date_from || step.dateFrom || ""),
    dateEnd: String(step.end_date || step.endDate || step.date_to || step.dateTo || ""),
    dimension: String(dimension || ""),
    filters: step.filters || step.filter || step.dimension_filter || step.dimensionFilter || null,
    navigation,
    mode: String(step.mode || ""),
  }));
}

async function beginGscCollectionGeneration(tabId, step = {}, dimension = "") {
  const tab = await chrome.tabs.get(Number(tabId));
  const generation = {
    generationId: `gsc_${Date.now().toString(36)}_${crypto.randomUUID?.() || Math.random().toString(36).slice(2)}`,
    collectionFingerprint: collectionFingerprint(step, dimension, tab.url || ""),
    laneId: gscLaneKey(step),
    property: normalizeGscProperty(step.site_url || step.siteUrl || ""),
    dimension: String(dimension || ""),
    navigationUrl: String(tab.url || ""),
    startedAt: Date.now(),
  };
  structuredNetworkPayloads.delete(Number(tabId));
  gscRequestGenerations.delete(Number(tabId));
  gscCollectionGenerations.set(Number(tabId), generation);
  return generation;
}

function clearGscCollectionGeneration(tabId, generation = null) {
  const id = Number(tabId || 0);
  const current = gscCollectionGenerations.get(id) || null;
  if (!generation || current?.generationId === generation.generationId) gscCollectionGenerations.delete(id);
  structuredNetworkPayloads.delete(id);
  gscRequestGenerations.delete(id);
}

async function getSearchConsoleSession(step = {}) {
  const data = await chrome.storage.local.get(STORAGE_KEYS.GSC_SESSION).catch(() => ({}));
  const stored = data?.[STORAGE_KEYS.GSC_SESSION] || null;
  const laneKey = gscLaneKey(step);
  const session = stored?.sessions ? (stored.sessions[laneKey] || null) : (laneKey === "legacy" ? stored : null);
  if (!session?.tabId || !(await ownedTab(Number(session.tabId)))) return null;
  const owned = await ownedTab(Number(session.tabId));
  const requestedLane = String(step._prstudio_lane_id || step.laneId || "").trim();
  if (requestedLane && owned?.laneId && owned.laneId !== requestedLane) return null;
  try {
    const tab = await chrome.tabs.get(Number(session.tabId));
    if (new URL(String(tab.url || "")).origin !== "https://search.google.com") return null;
    return { ...session, tabId: Number(session.tabId), tab };
  } catch { return null; }
}

async function saveSearchConsoleSession(tabId, step = {}, tab = null) {
  const current = tab || await chrome.tabs.get(tabId);
  const laneKey = gscLaneKey(step);
  const session = {
    tabId: Number(tabId),
    siteUrl: String(step.site_url || step.siteUrl || ""),
    mode: String(step.mode || ""),
    laneId: laneKey === "legacy" ? "" : laneKey,
    url: String(current.url || ""),
    updatedAt: new Date().toISOString(),
  };
  const data = await chrome.storage.local.get(STORAGE_KEYS.GSC_SESSION).catch(() => ({}));
  const previous = data?.[STORAGE_KEYS.GSC_SESSION] || null;
  const sessions = previous?.sessions && typeof previous.sessions === "object" ? { ...previous.sessions } : {};
  if (previous?.tabId && !previous?.sessions) sessions.legacy = previous;
  sessions[laneKey] = session;
  const entries = Object.entries(sessions).sort((a,b) => String(b[1]?.updatedAt || "").localeCompare(String(a[1]?.updatedAt || ""))).slice(0, 32);
  await chrome.storage.local.set({ [STORAGE_KEYS.GSC_SESSION]: { version: 2, sessions: Object.fromEntries(entries) } }).catch(() => {});
  return session;
}

async function resolveSearchConsoleSessionTab(step = {}) {
  const explicit = Number(step.tabId || 0);
  if (explicit) {
    if (!await ownedTab(explicit)) throw codedError("explicit_tab_not_owned", `La scheda esplicita ${explicit} non appartiene al Browser Agent.`, { tabId: explicit });
    return { tabId: explicit, record: await chrome.tabs.get(explicit), source: "explicit_owned_tab" };
  }
  const session = await getSearchConsoleSession(step);
  const requestedSite = String(step.site_url || step.siteUrl || "").trim();
  if (session && gscPropertyMatches(session.siteUrl || "", requestedSite)) {
    return { tabId: session.tabId, record: session.tab, source: "persisted_gsc_session" };
  }
  const desired = searchConsoleUrl(step.mode, step);
  const wantedResource = (() => { try { return new URL(desired).searchParams.get("resource_id") || ""; } catch { return ""; } })();
  const tabs = await listOwnedTabs();
  const requestedLane = String(step._prstudio_lane_id || step.laneId || "").trim();
  const candidates = [];
  for (const tab of tabs) {
    if (requestedLane && tab?.laneId && tab.laneId !== requestedLane) continue;
    try {
      const url = new URL(String(tab.url || ""));
      if (url.origin !== "https://search.google.com") continue;
      const tabResource = url.searchParams.get("resource_id") || "";
      if (wantedResource && tabResource && !gscPropertyMatches(tabResource, wantedResource)) continue;
      let score = 1;
      if (url.pathname.includes("/search-console/performance/search-analytics")) score += 20;
      if (step.mode === "search_analytics" && url.pathname.includes("/search-console/performance/search-analytics")) score += 40;
      if (wantedResource && gscPropertyMatches(tabResource, wantedResource)) score += 80;
      candidates.push({ tab, score });
    } catch { /* ignore malformed URL */ }
  }
  candidates.sort((a, b) => b.score - a.score || Number(b.tab.lastAccessed || 0) - Number(a.tab.lastAccessed || 0));
  if (candidates.length) return { tabId: candidates[0].tab.tabId || candidates[0].tab.id, record: candidates[0].tab, source: "owned_gsc_candidate" };
  return null;
}

async function ensureSearchConsoleProperty(tabId, step = {}) {
  const requested = String(step.site_url || step.siteUrl || "").trim();
  if (!requested) return { verified: true, requested: "", source: "no_property_required" };
  const current = await chrome.tabs.get(tabId);
  let currentResource = "";
  try { currentResource = new URL(String(current.url || "")).searchParams.get("resource_id") || ""; } catch { /* ignore */ }
  if (gscPropertyMatches(currentResource, requested)) return { verified: true, requested, selected: currentResource, source: "url_resource_id" };

  let snapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true }).catch(() => ({ text: "", interactive: [] }));
  const body = String(snapshot.text || "").toLowerCase();
  const accessDenied = /non hai accesso|you don(?:'|’)t have access|you do not have access|accesso a questa proprietà|access to this property/.test(body);
  if (accessDenied || currentResource) {
    await chrome.tabs.update(tabId, { url: "https://search.google.com/search-console/" });
    await waitForTab(tabId, "interactive", 45000).catch(() => {});
    snapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true }).catch(() => ({ text: "", interactive: [] }));
  }

  const labels = gscPropertyLabels(requested);
  const hostHint = labels.find((label) => !label.startsWith("http") && !label.startsWith("sc-domain:")) || labels[0] || "";
  const interactive = Array.isArray(snapshot.interactive) ? snapshot.interactive : [];
  const selectorCandidate = interactive.find((item) => {
    const role = String(item?.role || "").toLowerCase();
    const name = String(item?.accessibleName || item?.text || "").toLowerCase();
    return ["button", "combobox"].includes(role) && (/propriet|property/.test(name) || (hostHint && name.includes(String(hostHint).toLowerCase())));
  });
  let opened = false;
  if (selectorCandidate) {
    opened = Boolean(await runNativeElementAction(tabId, "click", {
      targetRef: selectorCandidate.targetRef || "",
      role: selectorCandidate.role || "",
      name: selectorCandidate.accessibleName || selectorCandidate.text || "",
      selector: selectorCandidate.selector || "",
    }).catch(() => null));
  }
  if (!opened) {
    for (const selector of ["[aria-label*='propriet' i]", "[aria-label*='property' i]"]) {
      if (await runNativeElementAction(tabId, "click", { selector, selectorType: "css" }).catch(() => null)) { opened = true; break; }
    }
  }
  if (!opened) throw codedError("search_console_property_selector_missing", "Il selettore della proprietà Search Console non è stato individuato; l’operazione non è tecnicamente eseguibile con il DOM corrente.", { requested });

  await sleep(500);
  let selected = false;
  const propertyMenu = await runDomAction(tabId, "page_snapshot", { includeInteractive: true }).catch(() => ({ interactive: [] }));
  const menuItems = Array.isArray(propertyMenu.interactive) ? propertyMenu.interactive : [];
  const normalizedRequested = normalizeGscProperty(requested);
  const isDomainProperty = /^sc-domain:/i.test(normalizedRequested);
  const exactHost = isDomainProperty ? normalizedRequested.replace(/^sc-domain:/i, "") : "";
  const exactPrefix = !isDomainProperty ? normalizedRequested : "";
  const ranked = menuItems.map((item) => {
    const raw = String(item?.accessibleName || item?.text || "").trim();
    const lower = raw.toLowerCase();
    let score = 0;
    if (isDomainProperty) {
      if (lower === exactHost) score = 100;
      else if (!/^https?:/i.test(raw) && lower.includes(exactHost)) score = 70;
      else if (/^https?:/i.test(raw)) score = -100;
    } else {
      if (normalizeGscProperty(raw) === exactPrefix) score = 100;
      else if (lower.includes(exactPrefix.toLowerCase())) score = 70;
    }
    return { item, score, raw };
  }).filter((row) => row.score > 0).sort((a, b) => b.score - a.score);
  for (const row of ranked) {
    if (await runNativeElementAction(tabId, "click", {
      targetRef: row.item?.targetRef || "",
      role: row.item?.role || "",
      name: row.item?.accessibleName || row.item?.text || "",
      selector: row.item?.selector || "",
    }).catch(() => null)) { selected = true; break; }
  }
  if (!selected) {
    for (const label of labels) {
      if (isDomainProperty && /^https?:/i.test(label)) continue;
      if (await runNativeElementAction(tabId, "click", { text: label }).catch(() => null)) { selected = true; break; }
    }
  }
  if (!selected) throw codedError("search_console_property_not_found", "La proprietà Search Console richiesta non è comparsa nell'elenco della sessione autenticata.", { requested, labels });
  await sleep(900);
  const after = await chrome.tabs.get(tabId);
  let afterResource = "";
  try { afterResource = new URL(String(after.url || "")).searchParams.get("resource_id") || ""; } catch { /* ignore */ }
  if (!gscPropertyMatches(afterResource, requested)) {
    const afterSnapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true }).catch(() => ({ text: "" }));
    const normalizedText = String(afterSnapshot.text || "").toLowerCase();
    const labelVerified = labels.some((label) => normalizedText.includes(String(label).toLowerCase()));
    if (!labelVerified) return { selected: true, verified: false, degraded: true, blocking: false, reason: "search_console_property_unverified", requested, currentUrl: after.url, resource_id: afterResource };
  }
  return { verified: true, requested, selected: afterResource || requested, source: "property_selector" };
}

async function requestSearchConsoleIndexing(tabId, step = {}, inspection = null) {
  const body = String((await runDomAction(tabId, "page_snapshot", { includeInteractive: true })).text || "");
  const statusText = `${String(inspection?.verdict || "")} ${String(inspection?.coverage_state || inspection?.index_status || "")} ${body}`.toLowerCase();
  if (/url is on google|l'url è su google|url è su google|indexed, not submitted|submitted and indexed/.test(statusText) && step.force_request !== true) {
    return { requested: false, verified: true, reason: "already_indexed" };
  }
  const buttons = ["Richiedi indicizzazione", "Request indexing", "Richiedi l'indicizzazione", "Request Indexing"];
  let clicked = false;
  for (const text of buttons) {
    if (await runNativeElementAction(tabId, "click", { text }).catch(() => null)) { clicked = true; break; }
  }
  if (!clicked) throw codedError("search_console_request_indexing_control_missing", "Il controllo Richiedi indicizzazione non è disponibile per l'URL ispezionato.");
  const timeoutMs = Math.max(5000, Math.min(90000, Number(step.request_timeout_ms || 60000)));
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    await sleep(1200);
    const snapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true }).catch(() => ({ text: "" }));
    const text = String(snapshot.text || "").toLowerCase();
    if (/indicizzazione richiesta|indexing requested|url added to a priority crawl queue|aggiunto a una coda di scansione prioritaria|request submitted/.test(text)) {
      return { requested: true, verified: true, confirmation: "search_console_ui_confirmation" };
    }
    if (/quota|limite giornaliero|daily limit|try again later|riprova più tardi/.test(text)) {
      throw codedError("search_console_request_indexing_quota", "Search Console non ha accettato la richiesta di indicizzazione per limite/quota temporanea.");
    }
  }
  return { requested: true, verified: false, degraded: true, blocking: false, reason: "search_console_request_indexing_unverified" };
}

function gscDateVariants(isoDate) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(isoDate || ""));
  if (!match) return [];
  const [, year, month, day] = match;
  const shortYear = year.slice(-2);
  const d = String(Number(day));
  const m = String(Number(month));
  const italianMonths = ["", "gennaio", "febbraio", "marzo", "aprile", "maggio", "giugno", "luglio", "agosto", "settembre", "ottobre", "novembre", "dicembre"];
  const englishMonths = ["", "january", "february", "march", "april", "may", "june", "july", "august", "september", "october", "november", "december"];
  return [...new Set([
    `${day}/${month}/${year}`, `${day}/${month}/${shortYear}`, `${d}/${m}/${year}`, `${d}/${m}/${shortYear}`,
    `${year}-${month}-${day}`, `${d} ${italianMonths[Number(month)]} ${year}`, `${d} ${italianMonths[Number(month)]} ${shortYear}`,
    `${d} ${englishMonths[Number(month)]} ${year}`, `${d} ${englishMonths[Number(month)]} ${shortYear}`,
  ].map((value) => value.toLowerCase()))];
}

async function verifySearchConsoleDateRange(tabId, step = {}) {
  const start = String(step.start_date || step.startDate || "").trim();
  const end = String(step.end_date || step.endDate || "").trim();
  if (!start || !end) return { requested: false, verified: true, start: start || null, end: end || null };
  const snapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true, maxChars: 250000 });
  const haystack = String(snapshot.text || "").toLowerCase();
  const startVariants = gscDateVariants(start);
  const endVariants = gscDateVariants(end);
  const startMatch = startVariants.find((value) => haystack.includes(value)) || "";
  const endMatch = endVariants.find((value) => haystack.includes(value)) || "";
  return {
    requested: true,
    verified: Boolean(startMatch && endMatch),
    start,
    end,
    observed: { startMatch: startMatch || null, endMatch: endMatch || null },
    source: "search_console_live_ui",
  };
}

async function applySearchConsoleDateRange(tabId, step = {}) {
  const start = String(step.start_date || step.startDate || "").trim();
  const end = String(step.end_date || step.endDate || "").trim();
  if (!start || !end) return { requested: false, applied: false, verified: true, start: start || null, end: end || null };

  const already = await verifySearchConsoleDateRange(tabId, step).catch(() => ({ verified: false }));
  if (already.verified) return { ...already, applied: false, reusedExistingRange: true };

  const snapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true });
  const controls = Array.isArray(snapshot.interactive) ? snapshot.interactive : [];
  const clean = (value) => String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/\s+/g, " ").trim();
  const more = controls.find((item) => {
    const role = clean(item?.role);
    const name = clean(item?.accessibleName || item?.text);
    return role === "button" && (/\baltro\b/.test(name) || /more time ranges|custom date|custom range|intervallo personalizzato/.test(name));
  });
  if (!more) throw codedError("search_console_date_range_control_missing", "Il controllo del periodo Search Console non è stato individuato nella snapshot semantica.", { start, end });
  await runNativeElementAction(tabId, "click", { targetRef: more.targetRef || "", role: more.role || "button", name: more.accessibleName || more.text || "", selector: more.selector || "" });
  await sleep(300);

  const popup = await runDomAction(tabId, "page_snapshot", { includeInteractive: true });
  const items = (popup.interactive || []).filter((item) => ["textbox", "combobox"].includes(clean(item?.role)) || ["date", "text"].includes(clean(item?.inputType)));
  const dateLike = items.filter((item) => !/url|ispezion|inspect|search|cerca/.test(clean(`${item?.accessibleName || ""} ${item?.fieldName || ""}`)));
  const startRe = /(data.*iniz|inizio|dal\b|start|from\b)/;
  const endRe = /(data.*fin|fine|\bal\b|end|to\b)/;
  let startInput = dateLike.find((item) => startRe.test(clean(`${item?.accessibleName || ""} ${item?.fieldName || ""}`))) || null;
  let endInput = dateLike.find((item) => endRe.test(clean(`${item?.accessibleName || ""} ${item?.fieldName || ""}`)) && item !== startInput) || null;
  const typedDates = dateLike.filter((item) => clean(item?.inputType) === "date");
  if (!startInput && typedDates.length >= 2) startInput = typedDates[0];
  if (!endInput && typedDates.length >= 2) endInput = typedDates[1];
  if ((!startInput || !endInput) && dateLike.length === 2) [startInput, endInput] = dateLike;
  if (!startInput || !endInput) throw codedError("search_console_custom_date_fields_missing", "I campi data personalizzati di Search Console non sono stati individuati semanticamente.", { start, end, candidates: dateLike.length });

  const localDate = (iso) => { const [y,m,d] = String(iso).split("-"); return `${d}/${m}/${y}`; };
  const fillDate = async (item, iso) => runNativeElementAction(tabId, "fill", {
    targetRef: item.targetRef || "", role: item.role || "", name: item.accessibleName || "", selector: item.selector || "",
    value: clean(item.inputType) === "date" ? iso : localDate(iso),
  });
  await fillDate(startInput, start);
  await fillDate(endInput, end);
  await sleep(150);

  const applySnapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true });
  const applyButton = (applySnapshot.interactive || []).find((item) => {
    const role = clean(item?.role);
    const name = clean(item?.accessibleName || item?.text);
    return role === "button" && (/^applica(?:\b|$)/.test(name) || /^apply(?:\b|$)/.test(name));
  });
  if (!applyButton) throw codedError("search_console_date_apply_missing", "Il pulsante Applica del periodo Search Console non è stato individuato semanticamente.", { start, end });
  await runNativeElementAction(tabId, "click", { targetRef: applyButton.targetRef || "", role: applyButton.role || "button", name: applyButton.accessibleName || applyButton.text || "", selector: applyButton.selector || "" });
  await sleep(Math.max(500, Math.min(2500, Number(step.date_settle_ms || 900))));
  await waitForSpaReady(tabId, Math.max(3000, Math.min(12000, Number(step.date_verify_timeout_ms || 7000))), "").catch(() => null);

  const verification = await verifySearchConsoleDateRange(tabId, step);
  if (!verification.verified) {
    return { ...verification, applied: true, reusedExistingRange: false, verified: false, degraded: true, blocking: false, warning: "search_console_date_range_not_applied", requested: { start, end }, observed: verification.observed || null };
  }
  return { ...verification, applied: true, reusedExistingRange: false, degraded: false, blocking: false };
}

async function executeSearchConsoleStep(state, step) {
  const origin = "https://search.google.com";
  const resolved = await resolveSearchConsoleSessionTab(step);
  let tabId = Number(resolved?.tabId || 0);
  if (tabId && !(await ownedTab(tabId))) tabId = 0;
  if (!tabId) {
    const url = searchConsoleUrl(step.mode, step);
    const created = await createOwnedAgentTab(state, url, {
      taskId: state.taskId, laneId: String(state?.arguments?._prstudio_lane_id || ""),
      expectedOrigin: origin, waitUntil: "interactive", timeoutMs: 45000, reason: `search_console_${step.mode}_open`,
    });
    tabId = created.tabId;
  } else {
    await assertOwnedTab(tabId);
    const desired = searchConsoleUrl(step.mode, step);
    const current = await chrome.tabs.get(tabId);
    if (shouldNavigateSearchConsole(String(current.url || ""), desired, step.mode)) {
      await chrome.tabs.update(tabId, { url: desired });
      await waitForTab(tabId, "interactive", 45000).catch(() => {});
    }
  }
  await bindTabAffinity(state, tabId, `search_console_${step.mode}_target`);
  await assertTargetBinding(tabId, { ...step, expectedOrigin: origin }, state, { allowNavigationTransition: true });

  const tab = await chrome.tabs.get(tabId);
  const gate = await detectExternalAuthChallenge(tabId).catch(() => null);
  const snapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true }).catch(() => ({ tabId, text: "", interactive: [] }));
  const loginRequired = /accounts\.google\.com|\/signin/i.test(String(tab.url || ""))
    || /\b(sign in|accedi|verifica la tua identità|verify it(?:'|’)s you)\b/i.test(String(snapshot.text || "").slice(0, 12000));
  if (gate || loginRequired) {
    state.tabId = tabId;
    await waitForExternalAuthChallenge(state, gate?.reason || "login_required", { tabId, url: tab.url, title: tab.title, selector: gate?.selector || "", mode: step.mode });
  }
  const propertySelection = await ensureSearchConsoleProperty(tabId, step);
  await saveSearchConsoleSession(tabId, step).catch(() => {});

  // Property selection can route the SPA back to Overview/Performance. Restore
  // the exact requested surface before collecting or verifying it.
  if (["sitemaps", "search_analytics"].includes(step.mode)) {
    const desiredSurface = searchConsoleUrl(step.mode, step);
    const activeSurface = await chrome.tabs.get(tabId);
    if (shouldNavigateSearchConsole(String(activeSurface.url || ""), desiredSurface, step.mode)) {
      await chrome.tabs.update(tabId, { url: desiredSurface });
      await waitForTab(tabId, "interactive", 45000).catch(() => {});
      await sleep(500);
    }
  }
  await assertTargetBinding(tabId, { ...step, expectedOrigin: origin }, state, { allowNavigationTransition: false });

  let dateRange = null;
  if (step.mode === "search_analytics") dateRange = await applySearchConsoleDateRange(tabId, step);

  let structuredInspection = null;
  let inspectionGeneration = null;
  if ((["url_inspection", "request_indexing"].includes(step.mode) || step.request_indexing === true) && (step.inspection_url || step.inspectionUrl)) {
    const target = String(step.inspection_url || step.inspectionUrl);
    const inspectionStep = { ...step, filter: { ...(step.filter && typeof step.filter === "object" ? step.filter : {}), inspectionUrl: target } };
    inspectionGeneration = await beginGscCollectionGeneration(tabId, inspectionStep, "url_inspection");
    const submitInspection = async () => {
      const candidates = [
        { role: "textbox", name: "inspect" }, { role: "textbox", name: "controlla" },
        { role: "textbox", name: "ispeziona" },
        { selector: "input[placeholder*='URL' i]" }, { selector: "input[aria-label*='URL' i]" },
      ];
      for (const candidate of candidates) {
        try {
          await runNativeElementAction(tabId, "fill", { ...candidate, value: target });
          await runNativeElementAction(tabId, "press", { ...candidate, key: "Enter" });
          return true;
        } catch { /* next deterministic selector */ }
      }
      return false;
    };
    if (!(await submitInspection())) throw codedError("search_console_inspection_field_missing", "Campo Ispezione URL non individuato nella pagina Search Console.");
    const timeoutMs = Math.max(5000, Math.min(30000, Number(step.inspection_timeout_ms || 18000)));
    let deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
      await sleep(900);
      structuredInspection = await extractStructuredSearchConsoleInspection(tabId, inspectionGeneration);
      if (structuredInspection.data_verified) break;
    }
    if (!structuredInspection?.data_verified) {
      // One deterministic recovery only. Do not silently accept the generic
      // Introduction panel as a successful URL Inspection result.
      const desired = searchConsoleUrl("url_inspection", step);
      await chrome.tabs.update(tabId, { url: desired });
      await waitForTab(tabId, "interactive", 45000).catch(() => {});
      inspectionGeneration = await beginGscCollectionGeneration(tabId, inspectionStep, "url_inspection");
      if (await submitInspection()) {
        deadline = Date.now() + Math.min(timeoutMs, 12000);
        while (Date.now() < deadline) {
          await sleep(900);
          structuredInspection = await extractStructuredSearchConsoleInspection(tabId, inspectionGeneration);
          if (structuredInspection.data_verified) break;
        }
      }
    }
  }

  let indexingRequest = null;
  if (step.mode === "request_indexing" || step.request_indexing === true) indexingRequest = await requestSearchConsoleIndexing(tabId, step, structuredInspection);
  if (step.mode === "search_analytics") await sleep(Math.max(500, Math.min(3500, Number(step.settle_ms || 1500))));
  const finalSnapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true });
  const currentTab = await chrome.tabs.get(tabId);
  await updateOwnedTab(tabId, { url: currentTab.url, title: currentTab.title, taskId: state.taskId, expectedOrigin: origin });
  await saveSearchConsoleSession(tabId, step, currentTab).catch(() => {});
  await bindTabAffinity(state, tabId, `search_console_${step.mode}`);
  const structuredMetrics = step.mode === "search_analytics"
    ? await collectSearchConsoleDimensions(tabId, step)
    : null;
  const completedGeneration = gscCollectionGenerations.get(Number(tabId)) || inspectionGeneration || null;
  const result = {
    tabId,
    mode: step.mode,
    provider: "prstudio_browser_agent_same_profile",
    collector: structuredMetrics ? "gsc_dimension_session_v4" : "browser_composite",
    loginManagedByUser: true,
    url: finalSnapshot.url,
    title: finalSnapshot.title,
    text: finalSnapshot.text,
    interactive: finalSnapshot.interactive,
    structuredMetrics,
    propertySelection,
    dateRange,
    structuredInspection,
    collectionGeneration: completedGeneration ? { generationId: completedGeneration.generationId, collectionFingerprint: completedGeneration.collectionFingerprint } : null,
    inspection: structuredInspection || undefined,
    indexingRequest: indexingRequest || undefined,
    rows: structuredMetrics?.rows || undefined,
    row_count: structuredMetrics?.row_count ?? undefined,
    verified: (["url_inspection", "request_indexing"].includes(step.mode) || step.request_indexing === true)
      ? Boolean(structuredInspection?.data_verified) && (!(step.mode === "request_indexing" || step.request_indexing === true) || Boolean(indexingRequest?.verified))
      : (step.mode === "search_analytics"
        ? Boolean(structuredMetrics?.dimension_integrity?.status === "verified") && Boolean(dateRange?.verified ?? true)
        : (step.mode === "sitemaps"
          ? Boolean(propertySelection?.verified ?? true) && /\/search-console\/sitemaps(?:[/?#]|$)/i.test(String(finalSnapshot.url || "")) && /sitemap/i.test(`${finalSnapshot.title || ""} ${String(finalSnapshot.text || "").slice(0, 12000)}`)
          : Boolean(propertySelection?.verified ?? true))),
  };
  clearGscCollectionGeneration(tabId, completedGeneration);
  return result;
}

function wildcardMatch(value, pattern) {
  return matchLiteralWildcard(String(value || "").toLowerCase(), String(pattern || "*").toLowerCase());
}

function cdpHeaders(headers = {}) {
  if (Array.isArray(headers)) return headers.map((item) => ({ name: String(item.name || ""), value: String(item.value || "") }));
  return Object.entries(headers || {}).map(([name, value]) => ({ name, value: String(value) }));
}

function base64Utf8(value) {
  const bytes = new TextEncoder().encode(String(value || ""));
  let binary = "";
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary);
}

async function handlePausedRequest(tabId, params) {
  const rules = routeRules.get(tabId) || [];
  const requestUrl = String(params?.request?.url || "");
  const rule = [...rules].reverse().find((candidate) => wildcardMatch(requestUrl, candidate.pattern || "*"));
  if (!rule) {
    await cdp(tabId, "Fetch.continueRequest", { requestId: params.requestId });
    return;
  }
  if (rule.mode === "abort") {
    await cdp(tabId, "Fetch.failRequest", { requestId: params.requestId, errorReason: rule.errorReason || "BlockedByClient" });
    return;
  }
  if (rule.mode === "mock") {
    await cdp(tabId, "Fetch.fulfillRequest", {
      requestId: params.requestId,
      responseCode: Number(rule.status || 200),
      responsePhrase: rule.statusText || undefined,
      responseHeaders: cdpHeaders(rule.headers || { "content-type": "application/json" }),
      body: base64Utf8(rule.body || ""),
    });
    return;
  }
  if (rule.mode === "modify") {
    const request = params.request || {};
    await cdp(tabId, "Fetch.continueRequest", {
      requestId: params.requestId,
      url: rule.url || request.url,
      method: rule.method || request.method,
      postData: rule.postData ?? request.postData,
      headers: cdpHeaders(rule.headers || Object.fromEntries((request.headers || []).map?.((item) => [item.name, item.value]) || Object.entries(request.headers || {}))),
    });
    return;
  }
  await cdp(tabId, "Fetch.continueRequest", { requestId: params.requestId });
}

function harFromEvents(events = []) {
  const byId = new Map();
  for (const event of events) {
    const id = event?.params?.requestId;
    if (!id) continue;
    const current = byId.get(id) || { requestId: id };
    if (event.method === "Network.requestWillBeSent") {
      current.startedDateTime = new Date((event.at || Date.now())).toISOString();
      current.request = event.params.request || {};
      current.documentURL = event.params.documentURL || "";
      current.type = event.params.type || "";
    } else if (event.method === "Network.responseReceived") {
      current.response = event.params.response || {};
    } else if (event.method === "Network.loadingFinished") {
      current.encodedDataLength = event.params.encodedDataLength || 0;
      current.finished = true;
    } else if (event.method === "Network.loadingFailed") {
      current.failure = { errorText: event.params.errorText || "", canceled: Boolean(event.params.canceled), blockedReason: event.params.blockedReason || "" };
    }
    byId.set(id, current);
  }
  return [...byId.values()].slice(-1000);
}

async function fetchInOwnedPage(tabId, args = {}) {
  await assertOwnedTab(tabId);
  const url = validateNavigationUrl(args.url);
  const tab = await chrome.tabs.get(tabId);
  const tabOrigin = new URL(validateNavigationUrl(tab.url || "")).origin;
  if (new URL(url).origin !== tabOrigin) {
    throw codedError("fetch_cross_origin_forbidden", "Il fetch autenticato è limitato all'origine della scheda agente.", { tabId, tabOrigin, requestOrigin: new URL(url).origin });
  }
  const timeoutMs = boundedRuntimeTimeout(args.timeout_ms ?? args.timeout, 8_000, 10_000);
  const injection = chrome.scripting.executeScript({
    target: { tabId, allFrames: false },
    func: async (request) => {
      const timeoutMs = Math.max(250, Math.min(10_000, Number(request.timeoutMs || 8_000)));
      const controller = new AbortController();
      let timedOut = false;
      const timer = setTimeout(() => { timedOut = true; controller.abort("prstudio_page_fetch_timeout"); }, timeoutMs);
      try {
        const response = await fetch(request.url, {
          method: request.method || "GET",
          headers: request.headers || {},
          body: ["GET", "HEAD"].includes(String(request.method || "GET").toUpperCase()) ? undefined : request.body,
          credentials: "same-origin",
          cache: request.cache || "no-store",
          redirect: "error",
          signal: controller.signal,
        });
        const text = await response.text();
        return {
          ok: response.ok,
          status: response.status,
          statusText: response.statusText,
          url: response.url,
          redirected: response.redirected,
          headers: Object.fromEntries(response.headers.entries()),
          text: text.slice(0, Number(request.maxBytes || 1000000)),
          truncated: text.length > Number(request.maxBytes || 1000000),
        };
      } catch (error) {
        if (timedOut || controller.signal.aborted) return { __prstudioError: "PAGE_FETCH_TIMEOUT", timeoutMs };
        throw error;
      } finally {
        clearTimeout(timer);
      }
    },
    args: [{
      url, method: args.method || "GET", headers: args.headers || {}, body: args.body ?? args.post_data,
      credentials: "same-origin", cache: args.cache || "no-store", redirect: "error",
      maxBytes: Math.max(1, Math.min(5000000, Number(args.max_bytes || 1000000))), timeoutMs,
    }],
  });
  const results = await promiseWithTimeout(
    injection,
    timeoutMs,
    () => codedError("PAGE_FETCH_TIMEOUT", `Fetch pagina oltre ${timeoutMs} ms.`, { tabId, timeoutMs, url }),
  );
  const result = results?.[0]?.result || {};
  if (result?.__prstudioError === "PAGE_FETCH_TIMEOUT") throw codedError("PAGE_FETCH_TIMEOUT", `Fetch pagina oltre ${timeoutMs} ms.`, { tabId, timeoutMs, url });
  return { tabId, ...result };
}

async function extractLinks(tabId) {
  await assertOwnedTab(tabId);
  const results = await chrome.scripting.executeScript({
    target: { tabId },
    func: () => [...document.querySelectorAll("a[href]")].map((anchor) => ({
      url: anchor.href,
      text: String(anchor.innerText || anchor.getAttribute("aria-label") || "").replace(/\s+/g, " ").trim().slice(0, 300),
      rel: anchor.rel || "",
      target: anchor.target || "",
    })).filter((item) => /^https?:/i.test(item.url)),
  });
  const links = results?.[0]?.result || [];
  const unique = [];
  const seen = new Set();
  for (const link of links) {
    if (seen.has(link.url)) continue;
    seen.add(link.url);
    unique.push(link);
  }
  return unique;
}

async function runSmokeAudit(tabId, action, args = {}) {
  const snapshot = await runDomAction(tabId, "page_snapshot", { includeInteractive: true });
  const links = await extractLinks(tabId);
  const forms = snapshot.interactive.filter((item) => ["textbox", "combobox", "button"].includes(item.role));
  const assertions = [];
  if (args.expected_url) assertions.push({ name: "url", passed: urlMatches(snapshot.url, args.expected_url), expected: args.expected_url, actual: snapshot.url });
  if (args.expected_text) assertions.push({ name: "text", passed: snapshot.text.includes(String(args.expected_text)), expected: args.expected_text });
  if (!assertions.length) assertions.push({ name: "page_loaded", passed: Boolean(snapshot.url && snapshot.title !== undefined && snapshot.page?.height > 0) });
  return {
    tabId, action, provider: "prstudio_browser_agent_same_profile", url: snapshot.url, title: snapshot.title,
    assertions, passed: assertions.every((item) => item.passed), interactiveCount: snapshot.interactive.length,
    formControlCount: forms.length, linkCount: links.length,
  };
}

const robotsCache = new Map();

async function robotsDecisionForUrl(url, requestedTimeoutMs = 5_000) {
  let parsed;
  try { parsed = new URL(url); } catch { return { allowed: false, reason: 'robots_invalid_url', source: 'invalid_url' }; }
  const key = parsed.origin;
  let rules = robotsCache.get(key);
  const cacheTtlMs = rules?.unreachable ? 60_000 : 300_000;
  if (!rules || Date.now() - Number(rules.at || 0) > cacheTtlMs) {
    const timeoutMs = boundedRuntimeTimeout(requestedTimeoutMs, 5_000, 10_000);
    const controller = new AbortController();
    const robotsUrl = new URL('/robots.txt', parsed.origin).href;
    try {
      const response = await promiseWithTimeout(
        fetch(robotsUrl, { cache: 'no-store', credentials: 'omit', redirect: 'follow', signal: controller.signal }),
        timeoutMs,
        () => codedError('ROBOTS_FETCH_TIMEOUT', `Fetch robots.txt oltre ${timeoutMs} ms.`, { robotsUrl, timeoutMs }),
        () => controller.abort('prstudio_robots_fetch_timeout'),
      );
      if (response.status >= 500 && response.status <= 599) {
        rules = { disallow: ['/'], at: Date.now(), unreachable: true, status: response.status };
      } else if (response.status >= 400 && response.status <= 499) {
        // RFC 9309 §2.3.1.3: unavailable (4xx) may be treated as no restrictions.
        rules = { disallow: [], at: Date.now(), unavailable: true, status: response.status };
      } else if (!response.ok) {
        rules = { disallow: ['/'], at: Date.now(), unreachable: true, status: response.status };
      } else {
        const text = await promiseWithTimeout(
          response.text(),
          timeoutMs,
          () => codedError('ROBOTS_BODY_TIMEOUT', `Lettura robots.txt oltre ${timeoutMs} ms.`, { robotsUrl, timeoutMs }),
          () => controller.abort('prstudio_robots_body_timeout'),
        );
        const disallow = [];
        let applies = false;
        for (const rawLine of text.split(/\r?\n/)) {
          const line = rawLine.replace(/#.*$/, '').trim();
          if (!line) continue;
          const [rawKey, ...rest] = line.split(':');
          const value = rest.join(':').trim();
          if (String(rawKey || '').trim().toLowerCase() === 'user-agent') applies = value === '*';
          else if (applies && String(rawKey || '').trim().toLowerCase() === 'disallow' && value) disallow.push(value);
        }
        rules = { disallow, at: Date.now(), status: response.status };
      }
    } catch (error) {
      rules = { disallow: ['/'], at: Date.now(), unreachable: true, error: serializeError(error) };
    }
    robotsCache.set(key, rules);
  }
  if (rules.unreachable) return { allowed: false, reason: 'robots_unreachable', source: 'robots', status: rules.status || null };
  const disallowed = (rules.disallow || []).includes('/') || (rules.disallow || []).some((prefix) => prefix !== '/' && parsed.pathname.startsWith(prefix));
  return { allowed: !disallowed, reason: disallowed ? 'robots_disallow' : '', source: 'robots', status: rules.status || null };
}

async function robotsAllowsUrl(url, requestedTimeoutMs = 5_000) {
  return Boolean((await robotsDecisionForUrl(url, requestedTimeoutMs)).allowed);
}

async function crawlWorkerPage(state, url, depth) {
  const startedAt = performance.now();
  const windowId = await ensureAgentWindow();
  const tab = await chrome.tabs.create({ windowId, url, active: false });
  const tabId = Number(tab.id || 0);
  if (!tabId) throw codedError('crawler_tab_create_failed', `Impossibile creare il worker crawler per ${url}.`);
  await registerOwnedTab(tabId, { windowId, taskId: state?.taskId || '', expectedOrigin: new URL(url).origin, url });
  try {
    await waitForTab(tabId, 'complete', 45000).catch(() => {});
    const gate = await detectExternalAuthChallenge(tabId).catch(() => null);
    if (gate) return { url, depth, accessible: false, degraded: true, reason: gate.reason || 'auth_challenge', links: [] };
    const [snapshot, links, metadata] = await Promise.all([
      runDomAction(tabId, 'page_snapshot', { includeInteractive: false }).catch(() => ({ url, title: '', text: '' })),
      extractLinks(tabId).catch(() => []),
      extractPublicPageFallback(tabId).catch(() => null),
    ]);
    const finalUrl = String(snapshot.url || (await chrome.tabs.get(tabId)).url || url);
    return {
      url: finalUrl,
      requested_url: url,
      depth,
      title: snapshot.title || metadata?.title || '',
      text_length: String(snapshot.text || '').length,
      metadata,
      links,
      accessible: true,
      duration_ms: Math.round(performance.now() - startedAt),
    };
  } finally {
    await promiseWithTimeout(
      chrome.tabs.remove(tabId),
      5_000,
      () => codedError("CRAWLER_TAB_CLOSE_TIMEOUT", `Timeout nella chiusura del worker crawler ${tabId}.`),
    );
    const cleanupFailures = [];
    try { await unregisterOwnedTab(tabId); }
    catch (error) { cleanupFailures.push({ stage: "ownership_unregister", error }); }
    try { await clearTabAffinityForTab(tabId); }
    catch (error) { cleanupFailures.push({ stage: "affinity_clear", error }); }
    if (cleanupFailures.length === 1) throw cleanupFailures[0].error;
    if (cleanupFailures.length > 1) {
      throw codedError("CRAWLER_TAB_CLEANUP_FAILED", `Cleanup metadata del worker crawler ${tabId} non completato.`, {
        cleanup: cleanupFailures.map(({ stage, error }) => ({ stage, error: serializeError(error) })),
      });
    }
  }
}

async function runAutonomousLinkCrawler(state, seedUrl, args = {}) {
  const seed = validateNavigationUrl(seedUrl);
  const nested = args?.browser && typeof args.browser === 'object' && !Array.isArray(args.browser) ? args.browser : {};
  const crawlerArgs = { ...nested, ...args };
  const inventoryFromSitemap = Boolean(crawlerArgs.inventory_from_sitemap || crawlerArgs.inventoryFromSitemap || crawlerArgs.definitive_kpi || crawlerArgs.definitiveKpi);
  if (inventoryFromSitemap && !crawlerArgs.max_pages && !crawlerArgs.maxPages && !crawlerArgs.limit) crawlerArgs.max_pages = 1500;
  if (inventoryFromSitemap && crawlerArgs.max_depth === undefined && crawlerArgs.maxDepth === undefined) crawlerArgs.max_depth = 0;
  const options = boundedCrawlerOptions(crawlerArgs);
  const seedOrigin = new URL(seed).origin;
  const inventory = new Set();
  let sitemapEvidence = null;
  let inventorySource = 'link_discovery';
  if (inventoryFromSitemap) {
    const candidates = [];
    if (crawlerArgs.sitemap_url || crawlerArgs.sitemapUrl) candidates.push(validateNavigationUrl(crawlerArgs.sitemap_url || crawlerArgs.sitemapUrl));
    else {
      for (const pathname of ['/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml']) candidates.push(new URL(pathname, seedOrigin).href);
    }
    for (const candidate of candidates) {
      try {
        const evidence = await runAutonomousSitemapCrawler(candidate, { max_urls: options.maxPages, max_sitemaps: crawlerArgs.max_sitemaps || 100 });
        if (Number(evidence?.count || 0) > 0) { sitemapEvidence = evidence; break; }
      } catch (error) {
        sitemapEvidence = { action: 'playwright_sitemap_crawl', seed_url: candidate, count: 0, urls: [], errors: [{ url: candidate, error: serializeError(error) }], verified: false };
      }
    }
    for (const raw of sitemapEvidence?.urls || []) {
      try {
        const parsed = new URL(raw); parsed.hash = '';
        if (parsed.origin === seedOrigin && inventory.size < options.maxPages) inventory.add(parsed.href);
      } catch {}
    }
    if (inventory.size) inventorySource = 'same_origin_sitemap';
  }
  if (!inventory.size) inventory.add(seed);
  const queue = [...inventory].map((url) => ({ url, depth: 0 }));
  const queued = new Set(inventory);
  const visited = new Set();
  const pages = [];
  const errors = [];
  const discovered = new Set();
  const edges = [];
  const edgeKeys = new Set();
  const edgeLimit = Math.min(50000, Math.max(1000, options.maxPages * 100));
  let edgesTruncated = false;
  let currentConcurrency = options.concurrency;
  const concurrencyHistory = [{ at: Date.now(), concurrency: currentConcurrency, reason: 'initial' }];

  while (queue.length && visited.size < options.maxPages) {
    const batch = [];
    while (queue.length && batch.length < currentConcurrency && visited.size + batch.length < options.maxPages) {
      const item = queue.shift();
      if (!item || visited.has(item.url)) continue;
      visited.add(item.url);
      batch.push(item);
    }
    if (!batch.length) break;
    const results = await Promise.all(batch.map(async (item) => {
      const robots = await robotsDecisionForUrl(item.url, crawlerArgs.robots_timeout_ms ?? crawlerArgs.timeout_ms ?? crawlerArgs.timeout ?? 5_000);
      if (!robots.allowed) return { ...item, accessible: false, degraded: true, reason: robots.reason || 'robots_disallow', robots, links: [] };
      try { return await crawlWorkerPage(state, item.url, item.depth); }
      catch (error) { return { ...item, error: serializeError(error), links: [] }; }
    }));
    const hardErrors = results.filter((page) => page?.error && page?.reason !== 'robots_disallow').length;
    const durations = results.map((page) => Number(page?.duration_ms || 0)).filter((value) => value > 0);
    const avgDuration = durations.length ? durations.reduce((sum, value) => sum + value, 0) / durations.length : 0;
    const errorRate = results.length ? hardErrors / results.length : 0;
    const previousConcurrency = currentConcurrency;
    if (errorRate >= 0.15 || avgDuration >= 5000) currentConcurrency = Math.max(1, currentConcurrency - 1);
    else if (errorRate === 0 && avgDuration > 0 && avgDuration < 1200 && currentConcurrency < options.concurrency) currentConcurrency += 1;
    if (currentConcurrency !== previousConcurrency) concurrencyHistory.push({ at: Date.now(), concurrency: currentConcurrency, reason: errorRate >= 0.15 ? 'error_backpressure' : (avgDuration >= 5000 ? 'latency_backpressure' : 'recovery'), error_rate: Number(errorRate.toFixed(3)), avg_duration_ms: Math.round(avgDuration) });
    for (const page of results) {
      const links = Array.isArray(page.links) ? page.links : [];
      const compact = { ...page, links: undefined, link_count: links.length };
      pages.push(compact);
      if (page.error) errors.push({ url: page.url, error: page.error });
      if (page.accessible === false || page.error) continue;
      for (const link of links) {
        let target;
        try {
          const parsed = new URL(String(link.url || ''), page.url || seed);
          parsed.hash = '';
          if (!['http:', 'https:'].includes(parsed.protocol)) continue;
          if (!options.allowCrossOrigin && parsed.origin !== seedOrigin) continue;
          target = parsed.href;
        } catch { continue; }
        discovered.add(target);
        const source = String(page.url || page.requested_url || seed);
        const edgeKey = `${source}\n${target}`;
        if (!edgeKeys.has(edgeKey)) {
          edgeKeys.add(edgeKey);
          if (edges.length < edgeLimit) {
            edges.push({ source, target, kind: 'rendered_dom' });
          } else {
            edgesTruncated = true;
          }
        }
        if (Number(page.depth || 0) < options.maxDepth && !queued.has(target) && !visited.has(target) && queued.size < options.maxPages * 20) {
          queued.add(target);
          queue.push({ url: target, depth: Number(page.depth || 0) + 1 });
        }
      }
    }
    if (queue.length && options.delayMs) await sleep(options.delayMs);
  }

  const inbound = new Map([...inventory].map((url) => [url, 0]));
  for (const edge of edges) {
    if (inbound.has(edge.target) && edge.source !== edge.target) inbound.set(edge.target, Number(inbound.get(edge.target) || 0) + 1);
  }
  const homeUrl = new URL('/', seedOrigin).href;
  const nodes = [...inventory].map((url) => ({ url, inbound_links: Number(inbound.get(url) || 0) }));
  const orphanUrls = nodes.filter((node) => node.url !== homeUrl && node.inbound_links === 0).map((node) => node.url);

  return {
    action: 'playwright_link_crawl',
    module: 'autonomous_crawler_v2_rendered_graph',
    provider: 'prstudio_browser_agent_same_profile',
    seed_url: seed,
    origin: seedOrigin,
    options: { ...options, adaptive_concurrency: true, final_concurrency: currentConcurrency },
    concurrency_history: concurrencyHistory,
    inventory_source: inventorySource,
    inventory_count: inventory.size,
    inventory_complete: inventorySource === 'same_origin_sitemap' ? pages.length >= inventory.size : false,
    sitemap: sitemapEvidence,
    pages,
    page_count: pages.length,
    visited_count: visited.size,
    discovered_links: [...discovered].slice(0, options.maxPages * 20),
    discovered_count: discovered.size,
    edges,
    edge_count: edgeKeys.size,
    edge_limit: edgeLimit,
    edges_truncated: edgesTruncated,
    graph_semantics: 'post_hydration_rendered_dom',
    nodes,
    orphan_urls: orphanUrls,
    orphan_count: orphanUrls.length,
    kpi_runtime_links_included: true,
    errors,
    bounded: true,
    verified: true,
  };
}

function xmlDecode(value) {
  // Decode &amp; LAST. Doing it first re-introduced an ampersand that the
  // remaining passes then treated as the start of a fresh entity, so a
  // legitimately double-escaped "&amp;lt;script&amp;gt;" came back as real
  // "<script>" markup. Resolving every entity in one pass makes the output a
  // faithful decode of the input rather than a decode of its own output.
  return String(value || '').replace(
    /&(amp|lt|gt|quot|apos|#39);/g,
    (_, entity) => ({ amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", '#39': "'" }[entity])
  );
}

async function readBoundedPublicText(response, maxBytes = 4 * 1024 * 1024) {
  const declared = Number(response.headers?.get?.('content-length') || 0);
  if (declared > maxBytes) throw codedError('public_document_too_large', `Documento pubblico oltre ${maxBytes} byte.`, { declared, maxBytes });
  if (!response.body?.getReader) {
    const text = await response.text();
    const bytes = new TextEncoder().encode(text).byteLength;
    if (bytes > maxBytes) throw codedError('public_document_too_large', `Documento pubblico oltre ${maxBytes} byte.`, { bytes, maxBytes });
    return text;
  }
  const reader = response.body.getReader();
  const chunks = [];
  let total = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      total += value?.byteLength || 0;
      if (total > maxBytes) {
        await reader.cancel('prstudio_public_document_limit').catch(() => {});
        throw codedError('public_document_too_large', `Documento pubblico oltre ${maxBytes} byte.`, { bytes: total, maxBytes });
      }
      chunks.push(value);
    }
  } finally {
    reader.releaseLock?.();
  }
  const merged = new Uint8Array(total);
  let offset = 0;
  for (const chunk of chunks) { merged.set(chunk, offset); offset += chunk.byteLength; }
  return new TextDecoder('utf-8', { fatal: false }).decode(merged);
}

async function runAutonomousSitemapCrawler(seedUrl, args = {}) {
  const seed = validateNavigationUrl(seedUrl);
  const seedOrigin = new URL(seed).origin;
  const maxSitemaps = Math.max(1, Math.min(100, Number(args.max_sitemaps || 25)));
  const maxUrls = Math.max(1, Math.min(10000, Number(args.limit || args.max_urls || 5000)));
  const maxDocumentBytes = 4 * 1024 * 1024;
  const fetchTimeoutMs = boundedRuntimeTimeout(args.fetch_timeout_ms ?? args.timeout_ms ?? args.timeout, 8_000, 10_000);
  const queue = [seed];
  const visited = new Set();
  const urls = new Set();
  const documents = [];
  const errors = [];
  while (queue.length && visited.size < maxSitemaps && urls.size < maxUrls) {
    const sitemapUrl = queue.shift();
    if (!sitemapUrl || visited.has(sitemapUrl)) continue;
    visited.add(sitemapUrl);
    try {
      const parsedSitemap = new URL(sitemapUrl);
      if (parsedSitemap.origin !== seedOrigin) throw codedError('sitemap_origin_mismatch', 'Sitemap fuori dall’origine iniziale.', { sitemapUrl, seedOrigin });
      const controller = new AbortController();
      const networkOperation = (async () => {
        const response = await fetch(parsedSitemap.href, { credentials: 'omit', cache: 'no-store', redirect: 'follow', signal: controller.signal });
        const responseUrl = new URL(response.url || parsedSitemap.href);
        if (responseUrl.origin !== seedOrigin) throw codedError('sitemap_redirect_origin_mismatch', 'Redirect sitemap fuori origine bloccato.', { sitemapUrl, responseUrl: responseUrl.href, seedOrigin });
        const xml = await readBoundedPublicText(response, maxDocumentBytes);
        return { response, responseUrl, xml };
      })();
      const { response, responseUrl, xml } = await promiseWithTimeout(
        networkOperation,
        fetchTimeoutMs,
        () => codedError('SITEMAP_FETCH_TIMEOUT', `Fetch sitemap oltre ${fetchTimeoutMs} ms.`, { sitemapUrl, timeoutMs: fetchTimeoutMs }),
        () => controller.abort('prstudio_sitemap_fetch_timeout'),
      );
      const locs = [...xml.matchAll(/<loc[^>]*>\s*([^<]+?)\s*<\/loc>/gi)].map((match) => xmlDecode(match[1].trim()));
      const isIndex = /<sitemapindex[\s>]/i.test(xml);
      documents.push({ url: responseUrl.href, status: response.status, type: isIndex ? 'index' : 'urlset', loc_count: locs.length, bytes: new TextEncoder().encode(xml).byteLength });
      for (const loc of locs) {
        let resolved;
        try { resolved = new URL(loc, responseUrl.href); } catch { continue; }
        if (!['http:', 'https:'].includes(resolved.protocol) || resolved.origin !== seedOrigin) continue;
        if (isIndex) {
          if (queue.length + visited.size < maxSitemaps * 4) queue.push(resolved.href);
        } else {
          if (urls.size >= maxUrls) break;
          urls.add(resolved.href);
        }
      }
    } catch (error) {
      errors.push({ url: sitemapUrl, error: serializeError(error) });
    }
  }
  return {
    action: 'playwright_sitemap_crawl',
    module: 'autonomous_sitemap_crawler_v2',
    seed_url: seed,
    documents,
    sitemap_count: documents.length,
    urls: [...urls],
    count: urls.size,
    errors,
    bounded: true,
    verified: documents.length > 0 && errors.length === 0,
    credentials_sent: false,
    same_origin_only: true,
    max_document_bytes: maxDocumentBytes,
  };
}

async function getRuntimeSessions() {
  const stored = await chrome.storage.local.get(STORAGE_KEYS.RUNTIME_SESSIONS).catch(() => ({}));
  const value = stored?.[STORAGE_KEYS.RUNTIME_SESSIONS];
  return value && typeof value === "object" && !Array.isArray(value) ? value : {};
}

async function saveRuntimeSessions(sessions) {
  await chrome.storage.local.set({ [STORAGE_KEYS.RUNTIME_SESSIONS]: sessions || {} });
}

async function cleanupExpiredRuntimeSessionTab(tabId, types = []) {
  const id = Number(tabId || 0);
  if (!id) return { cleaned: true, attached: false, commands: [] };
  const typeSet = new Set((types || []).map((value) => String(value || "")));
  const commands = [];
  if (typeSet.has("trace")) commands.push(["Tracing.end", {}]);
  if (typeSet.has("video")) commands.push(["Page.stopScreencast", {}]);
  if (typeSet.has("js_coverage")) commands.push(["Profiler.stopPreciseCoverage", {}], ["Profiler.disable", {}]);
  if (typeSet.has("css_coverage")) commands.push(["CSS.stopRuleUsageTracking", {}], ["CSS.disable", {}], ["DOM.disable", {}]);
  if (typeSet.has("route")) commands.push(["Fetch.disable", {}]);

  if (await debuggerAttached(id).catch(() => false)) {
    await Promise.allSettled(commands.map(([method, params]) =>
      debuggerCommandWithTimeout(id, method, params, 5_000, "runtime_session_ttl_cleanup")
    ));
    if (await debuggerAttached(id).catch(() => false)) {
      await detachDebugger(id, "runtime_session_ttl_expired");
    }
  }

  if (typeSet.has("route")) routeRules.delete(id);
  if (typeSet.has("har")) networkBuffers.delete(id);
  if (typeSet.has("trace")) traceBuffers.delete(id);
  if (typeSet.has("video")) screencastBuffers.delete(id);
  debuggerProtocolByTab.delete(id);
  return { cleaned: true, tabId: id, commands: commands.map(([method]) => method) };
}

async function pruneRuntimeSessions() {
  const sessions = await getRuntimeSessions();
  const now = Date.now();
  let changed = false;
  const expiredByTab = new Map();

  for (const [id, session] of Object.entries(sessions)) {
    const tabId = Number(session?.tabId || 0);
    if (!tabId) { delete sessions[id]; changed = true; continue; }
    if (Number(session.expiresAt || 0) > now) continue;
    const group = expiredByTab.get(tabId) || [];
    group.push({ id, session });
    expiredByTab.set(tabId, group);
  }

  const cleanupFailures = [];
  for (const [tabId, rows] of expiredByTab.entries()) {
    try {
      await cleanupExpiredRuntimeSessionTab(tabId, rows.map(({ session }) => session?.type));
      for (const { id } of rows) delete sessions[id];
      changed = true;
    } catch (error) {
      cleanupFailures.push({ tabId, error: serializeError(error) });
      for (const { id, session } of rows) {
        sessions[id] = { ...session, expiredCleanupPending: true, cleanupLastAttemptAt: now, cleanupError: serializeError(error) };
      }
      changed = true;
    }
  }

  if (changed) await saveRuntimeSessions(sessions);
  if (cleanupFailures.length) {
    throw codedError("RUNTIME_SESSION_TTL_CLEANUP_FAILED", "Cleanup delle sessioni runtime scadute non completato; verrà ritentato.", { failures: cleanupFailures });
  }
  return sessions;
}

async function openRuntimeSession(tabId, type, meta = {}) {
  const sessions = await pruneRuntimeSessions();
  const id = String(meta.sessionId || `rt_${String(type || "session")}_${crypto.randomUUID()}`);
  const now = Date.now();
  // One active session of each type per tab; renew/replace only that type.
  for (const [key, row] of Object.entries(sessions)) {
    if (Number(row?.tabId || 0) === Number(tabId) && String(row?.type || "") === String(type || "")) delete sessions[key];
  }
  sessions[id] = { id, tabId: Number(tabId), type: String(type || ""), startedAt: now, expiresAt: now + RUNTIME_SESSION_TTL_MS, interruptedByWorkerRestart: false, ...meta };
  await saveRuntimeSessions(sessions);
  return sessions[id];
}

async function findRuntimeSession(tabId, type, requestedId = "") {
  const sessions = await pruneRuntimeSessions();
  const wanted = String(requestedId || "");
  return Object.values(sessions).find((row) => Number(row?.tabId || 0) === Number(tabId)
    && String(row?.type || "") === String(type || "") && (!wanted || String(row.id) === wanted)) || null;
}

async function closeRuntimeSession(tabId, type, requestedId = "") {
  const sessions = await getRuntimeSessions();
  const wanted = String(requestedId || "");
  let closed = null;
  for (const [id, row] of Object.entries(sessions)) {
    if (Number(row?.tabId || 0) !== Number(tabId) || String(row?.type || "") !== String(type || "") || (wanted && String(id) !== wanted)) continue;
    closed = row; delete sessions[id];
  }
  await saveRuntimeSessions(sessions);
  return closed;
}

async function runtimeSessionTypesForTab(tabId) {
  const sessions = await pruneRuntimeSessions();
  return new Set(Object.values(sessions).filter((row) => Number(row?.tabId || 0) === Number(tabId)
    && !row.interruptedByWorkerRestart && !row.interruptedByTabReplacement).map((row) => String(row.type || "")));
}

async function forceCloseRuntimeSessions(tabId) {
  const id = Number(tabId || 0);
  if (!id) return { closed: 0, detached: false };
  const sessions = await getRuntimeSessions();
  const matching = Object.entries(sessions).filter(([, row]) => Number(row?.tabId || 0) === id);
  if (!matching.length) return { closed: 0, detached: false };

  let attached;
  try {
    attached = await debuggerAttached(id);
  } catch (error) {
    const now = Date.now();
    for (const [key, row] of matching) sessions[key] = {
      ...row, forceCleanupPending: true, cleanupLastAttemptAt: now, cleanupError: serializeError(error),
    };
    await saveRuntimeSessions(sessions);
    throw error;
  }

  const stopFailures = [];
  if (attached) {
    const types = new Set(matching.map(([, row]) => String(row?.type || "")));
    const commands = [];
    if (types.has("trace")) commands.push(["Tracing.end", {}]);
    if (types.has("video")) commands.push(["Page.stopScreencast", {}]);
    if (types.has("js_coverage")) commands.push(["Profiler.stopPreciseCoverage", {}], ["Profiler.disable", {}]);
    if (types.has("css_coverage")) commands.push(["CSS.stopRuleUsageTracking", {}], ["CSS.disable", {}], ["DOM.disable", {}]);
    if (types.has("route")) commands.push(["Fetch.disable", {}]);
    const settled = await Promise.allSettled(commands.map(([method, params]) =>
      debuggerCommandWithTimeout(id, method, params, 5_000, "force_session_cleanup")
    ));
    settled.forEach((result, index) => {
      if (result.status === "rejected") stopFailures.push({ method: commands[index][0], error: serializeError(result.reason) });
    });
    try {
      await detachDebugger(id, "force_session_cleanup");
    } catch (error) {
      const now = Date.now();
      for (const [key, row] of matching) sessions[key] = {
        ...row, forceCleanupPending: true, cleanupLastAttemptAt: now, cleanupError: serializeError(error), stopFailures,
      };
      await saveRuntimeSessions(sessions);
      throw error;
    }
  }

  for (const [key] of matching) delete sessions[key];
  await saveRuntimeSessions(sessions);
  if (stopFailures.length) await appendLog("runtime_sessions.force_stop_degraded", { tabId: id, failures: stopFailures }).catch(() => {});
  return { closed: matching.length, detached: Boolean(attached), stopFailures };
}

async function reconcileRuntimeSessionsAfterRestart() {
  const sessions = await pruneRuntimeSessions();
  const rows = Object.values(sessions);
  if (!rows.length) return { ok: true, sessions: 0 };
  const now = Date.now();
  for (const row of rows) {
    row.interruptedByWorkerRestart = true;
    row.interruptedAt = Number(row.interruptedAt || 0) || now;
  }
  await saveRuntimeSessions(Object.fromEntries(rows.map((row) => [row.id, row])));

  const cleanupFailures = [];
  for (const tabId of [...new Set(rows.map((row) => Number(row.tabId || 0)).filter(Boolean))]) {
    try {
      if (await debuggerAttached(tabId)) await detachDebugger(tabId, "service_worker_restart_session_reconcile");
      for (const row of rows) if (Number(row.tabId || 0) === tabId) {
        row.cleanupPending = false;
        delete row.cleanupLastErrorCode;
      }
    } catch (error) {
      cleanupFailures.push({ tabId, error });
      for (const row of rows) if (Number(row.tabId || 0) === tabId) {
        row.cleanupPending = true;
        row.cleanupLastAttemptAt = Date.now();
        row.cleanupLastErrorCode = String(error?.code || "runtime_session_cleanup_failed");
      }
    }
  }
  await saveRuntimeSessions(Object.fromEntries(rows.map((row) => [row.id, row])));
  await appendLog("runtime_sessions.interrupted_by_worker_restart", {
    count: rows.length, cleanupPendingTabs: cleanupFailures.map((item) => item.tabId),
  }).catch(() => {});
  if (cleanupFailures.length) {
    if (cleanupFailures.length === 1) throw cleanupFailures[0].error;
    throw codedError("RUNTIME_SESSION_RESTART_CLEANUP_FAILED", "Una o più sessioni CDP interrotte non sono state sganciate dopo il riavvio del service worker.", {
      failures: cleanupFailures.map(({ tabId, error }) => ({ tabId, code: String(error?.code || "runtime_session_cleanup_failed"), message: String(error?.message || error) })),
    });
  }
  return { ok: true, sessions: rows.length, interrupted: true, cleanupPendingTabs: [] };
}

async function assertRuntimeSessionUsable(tabId, type, requestedId = "") {
  const session = await findRuntimeSession(tabId, type, requestedId);
  if (!session) throw codedError("runtime_session_missing", `Sessione ${type} non trovata o scaduta.`, { tabId, type });
  if (session.interruptedByWorkerRestart || session.interruptedByTabReplacement) {
    await closeRuntimeSession(tabId, type, requestedId);
    const reason = session.interruptedByTabReplacement ? "tab_replaced" : "service_worker_restart";
    throw codedError("runtime_session_interrupted", `La sessione ${type} è stata interrotta (${reason}) e non può essere dichiarata completa.`, { tabId, type, sessionId: session.id, interruptedAt: session.interruptedAt || null, reason });
  }
  return session;
}

const SENSITIVE_CDP_METHODS = new Set([
  "Runtime.evaluate",
  "Page.addScriptToEvaluateOnNewDocument",
  "Storage.getCookies",
  "Network.setCookies",
  "Network.deleteCookies",
  "Network.clearBrowserCookies",
  "Browser.grantPermissions",
  "Browser.resetPermissions",
]);

async function sensitiveCdp(tabId, method, params = {}) {
  // Deliberately separate from cdp(...): raw/internal generic CDP stays denylisted.
  // This path is reachable only from a contract_action marked risk=identity and
  // Contract action allowlisting and authentication remain technical controls.
  const candidate = String(method || "");
  if (!SENSITIVE_CDP_METHODS.has(candidate)) throw codedError("sensitive_cdp_method_forbidden", `Metodo CDP sensibile non allowlisted: ${candidate}`);
  const serialized = JSON.stringify(params || {});
  if (serialized.length > 512_000) throw codedError("sensitive_cdp_params_too_large", "Parametri CDP sensibili oltre il limite.");
  await attachDebuggerIfNeeded(tabId);
  return debuggerCommandWithTimeout(tabId, candidate, params || {}, CDP_DEFAULT_TIMEOUT_MS, "sensitive_cdp_timeout");
}

async function currentTabOrigin(tabId) {
  const tab = await chrome.tabs.get(tabId);
  const url = new URL(String(tab?.url || ""));
  if (!["http:", "https:"].includes(url.protocol)) throw codedError("browser_origin_required", "L'azione richiede una pagina HTTP/HTTPS Agent-owned.");
  return { tab, origin: url.origin, url: url.href };
}

function cookieMatchesOrigin(cookie, originUrl) {
  try {
    const url = new URL(originUrl);
    const domain = String(cookie?.domain || "").replace(/^\./, "").toLowerCase();
    const host = url.hostname.toLowerCase();
    return Boolean(domain) && (host === domain || host.endsWith(`.${domain}`));
  } catch { return false; }
}

async function saveSensitiveBrowserState(state) {
  const stored = (await chrome.storage.local.get(STORAGE_KEYS.SENSITIVE_STATES))[STORAGE_KEYS.SENSITIVE_STATES] || {};
  const id = `state-${crypto.randomUUID()}`;
  const next = { ...stored, [id]: { ...state, createdAt: Date.now() } };
  const ordered = Object.entries(next).sort((a, b) => Number(b[1]?.createdAt || 0) - Number(a[1]?.createdAt || 0)).slice(0, 20);
  await chrome.storage.local.set({ [STORAGE_KEYS.SENSITIVE_STATES]: Object.fromEntries(ordered) });
  return id;
}

async function loadSensitiveBrowserState(id) {
  const stored = (await chrome.storage.local.get(STORAGE_KEYS.SENSITIVE_STATES))[STORAGE_KEYS.SENSITIVE_STATES] || {};
  return stored[String(id || "")] || null;
}

async function collectBrowserStorageState(tabId) {
  const { origin, url } = await currentTabOrigin(tabId);
  const cookieResult = await sensitiveCdp(tabId, "Storage.getCookies", {});
  const cookies = (cookieResult.cookies || []).filter((cookie) => cookieMatchesOrigin(cookie, url)).slice(0, 200);
  const storageResult = await chrome.scripting.executeScript({
    target: { tabId },
    func: () => ({
      localStorage: Object.fromEntries(Array.from({ length: localStorage.length }, (_, i) => localStorage.key(i)).filter(Boolean).map((key) => [key, localStorage.getItem(key)])),
      sessionStorage: Object.fromEntries(Array.from({ length: sessionStorage.length }, (_, i) => sessionStorage.key(i)).filter(Boolean).map((key) => [key, sessionStorage.getItem(key)])),
    }),
  });
  return { origin, url, cookies, storage: storageResult?.[0]?.result || { localStorage: {}, sessionStorage: {} } };
}

async function applyBrowserStorageState(tabId, supplied) {
  const { origin } = await currentTabOrigin(tabId);
  const state = supplied || {};
  if (state.origin && String(state.origin) !== origin) throw codedError("storage_state_origin_mismatch", "Lo storage state appartiene a un origin differente.", { expected: origin, supplied: state.origin });
  const cookies = Array.isArray(state.cookies) ? state.cookies.slice(0, 200) : [];
  if (cookies.length) {
    const safeCookies = cookies.filter((cookie) => cookie && typeof cookie === "object" && cookie.name && cookie.domain && cookieMatchesOrigin(cookie, origin));
    if (safeCookies.length !== cookies.length) throw codedError("storage_state_cookie_origin_mismatch", "Uno o più cookie non appartengono all'origin corrente.");
    await sensitiveCdp(tabId, "Network.setCookies", { cookies: safeCookies });
  }
  const localValues = state.storage?.localStorage && typeof state.storage.localStorage === "object" ? state.storage.localStorage : {};
  const sessionValues = state.storage?.sessionStorage && typeof state.storage.sessionStorage === "object" ? state.storage.sessionStorage : {};
  if (JSON.stringify({ localValues, sessionValues }).length > 512_000) throw codedError("storage_state_too_large", "Storage state oltre il limite.");
  await chrome.scripting.executeScript({
    target: { tabId },
    func: (localMap, sessionMap) => {
      for (const [key, value] of Object.entries(localMap)) localStorage.setItem(key, String(value ?? ""));
      for (const [key, value] of Object.entries(sessionMap)) sessionStorage.setItem(key, String(value ?? ""));
      return { localStorageKeys: Object.keys(localMap).length, sessionStorageKeys: Object.keys(sessionMap).length };
    },
    args: [localValues, sessionValues],
  });
  return collectBrowserStorageState(tabId);
}

async function executeKnownContractAction(state, step) {
  const action = String(step.action || "");
  const args = step.args || {};
  const gscModes = {
    search_console_status: "status",
    search_console_sites: "sites",
    search_console_search_analytics: "search_analytics",
    search_console_sitemaps: "sitemaps",
    search_console_url_inspection: "url_inspection",
    search_console_request_indexing: "request_indexing",
  };
  if (gscModes[action]) {
    return executeSearchConsoleStep(state, { type: "search_console", mode: gscModes[action], ...args });
  }
  if (!hasRuntimeContractAction(action)) {
    throw codedError("contract_executor_not_registered", `Azione avanzata non registrata nel Browser Agent 1.0.0: ${action}`, { action, executorProtocolVersion: EXECUTOR_PROTOCOL_VERSION });
  }
  let requestedTab = Number(args.tab_id || args.tabId || state.tabId || 0);

  if (isSensitiveRuntimeContractAction(action)) {
    const tabId = requestedTab || await resolveTabId(state, { type: "contract_action", action, tabId: requestedTab, args });
    await assertTargetBinding(tabId, { type: "contract_action", action, tabId }, state, { allowNavigationTransition: false });
    const { origin, url } = await currentTabOrigin(tabId);
    if (action === "playwright_evaluate") {
      const expression = String(args.expression ?? args.script ?? "");
      if (!expression.trim()) throw codedError("evaluate_expression_required", "expression/script obbligatorio.");
      if (expression.length > 65_536) throw codedError("evaluate_expression_too_large", "Expression oltre 64 KiB.");
      const result = await sensitiveCdp(tabId, "Runtime.evaluate", { expression, awaitPromise: true, returnByValue: true, userGesture: true });
      if (result.exceptionDetails) throw codedError("evaluate_runtime_exception", result.exceptionDetails.text || "Runtime.evaluate ha generato un'eccezione.", { exceptionDetails: result.exceptionDetails });
      return { tabId, action, origin, value: result.result?.value, type: result.result?.type || null, verified: true };
    }
    if (action === "playwright_add_init_script") {
      const source = String(args.script ?? args.source ?? "");
      if (!source.trim()) throw codedError("init_script_required", "script/source obbligatorio.");
      if (source.length > 65_536) throw codedError("init_script_too_large", "Init script oltre 64 KiB.");
      const result = await sensitiveCdp(tabId, "Page.addScriptToEvaluateOnNewDocument", { source });
      return { tabId, action, origin, identifier: result.identifier || "", verified: Boolean(result.identifier) };
    }
    if (action === "playwright_get_cookies") {
      const cookies = ((await sensitiveCdp(tabId, "Storage.getCookies", {})).cookies || []).filter((cookie) => cookieMatchesOrigin(cookie, url)).slice(0, 200);
      return { tabId, action, origin, cookies, count: cookies.length, verified: true };
    }
    if (action === "playwright_set_cookies") {
      const cookies = Array.isArray(args.cookies) ? args.cookies.slice(0, 200) : [];
      if (!cookies.length) throw codedError("cookies_required", "cookies[] obbligatorio.");
      if (cookies.some((cookie) => !cookie?.name || !cookie?.domain || !cookieMatchesOrigin(cookie, url))) throw codedError("cookie_origin_mismatch", "Tutti i cookie devono appartenere all'origin corrente.");
      await sensitiveCdp(tabId, "Network.setCookies", { cookies });
      const after = ((await sensitiveCdp(tabId, "Storage.getCookies", {})).cookies || []).filter((cookie) => cookieMatchesOrigin(cookie, url));
      const wanted = new Set(cookies.map((cookie) => `${cookie.name}|${String(cookie.domain).replace(/^\./, "")}|${cookie.path || "/"}`));
      const seen = new Set(after.map((cookie) => `${cookie.name}|${String(cookie.domain).replace(/^\./, "")}|${cookie.path || "/"}`));
      const verified = [...wanted].every((key) => seen.has(key));
      return { tabId, action, origin, requested: cookies.length, presentAfter: after.length, verified };
    }
    if (action === "playwright_clear_cookies") {
      const all = Boolean(args.all);
      if (all) {
        await sensitiveCdp(tabId, "Network.clearBrowserCookies", {});
        const remaining = ((await sensitiveCdp(tabId, "Storage.getCookies", {})).cookies || []).length;
        return { tabId, action, scope: "all_profile_cookies", remaining, verified: remaining === 0 };
      }
      const current = ((await sensitiveCdp(tabId, "Storage.getCookies", {})).cookies || []).filter((cookie) => cookieMatchesOrigin(cookie, url));
      for (const cookie of current.slice(0, 200)) await sensitiveCdp(tabId, "Network.deleteCookies", { name: cookie.name, domain: cookie.domain, path: cookie.path || "/" });
      const remaining = ((await sensitiveCdp(tabId, "Storage.getCookies", {})).cookies || []).filter((cookie) => cookieMatchesOrigin(cookie, url));
      return { tabId, action, origin, deleted: current.length, remaining: remaining.length, verified: remaining.length === 0 };
    }
    if (action === "playwright_get_storage_state") {
      const browserState = await collectBrowserStorageState(tabId);
      const stateId = await saveSensitiveBrowserState(browserState);
      return { tabId, action, origin, stateId, cookies: browserState.cookies, storage: browserState.storage, cookieCount: browserState.cookies.length, localStorageKeys: Object.keys(browserState.storage.localStorage || {}).length, sessionStorageKeys: Object.keys(browserState.storage.sessionStorage || {}).length, verified: true };
    }
    if (action === "playwright_set_storage_state" || action === "playwright_login_with_storage_state") {
      const stateId = String(args.state_id || args.stateId || "");
      const supplied = stateId ? await loadSensitiveBrowserState(stateId) : (args.state || args.storage_state || null);
      if (!supplied) throw codedError("storage_state_required", "state_id oppure state/storage_state obbligatorio.");
      const after = await applyBrowserStorageState(tabId, supplied);
      if (action === "playwright_login_with_storage_state") {
        await chrome.tabs.reload(tabId);
        await waitForTab(tabId, "complete", boundedRuntimeTimeout(args.timeout, 45_000, 120_000));
      }
      return { tabId, action, origin, stateId: stateId || null, cookieCount: after.cookies.length, localStorageKeys: Object.keys(after.storage.localStorage || {}).length, sessionStorageKeys: Object.keys(after.storage.sessionStorage || {}).length, verified: true };
    }
    if (action === "playwright_clear_storage") {
      await chrome.scripting.executeScript({ target: { tabId }, func: () => { localStorage.clear(); sessionStorage.clear(); return true; } });
      if (args.cookies === true) {
        const current = ((await sensitiveCdp(tabId, "Storage.getCookies", {})).cookies || []).filter((cookie) => cookieMatchesOrigin(cookie, url));
        for (const cookie of current.slice(0, 200)) await sensitiveCdp(tabId, "Network.deleteCookies", { name: cookie.name, domain: cookie.domain, path: cookie.path || "/" });
      }
      const after = await collectBrowserStorageState(tabId);
      const verified = Object.keys(after.storage.localStorage || {}).length === 0 && Object.keys(after.storage.sessionStorage || {}).length === 0 && (args.cookies !== true || after.cookies.length === 0);
      return { tabId, action, origin, clearedCookies: args.cookies === true, verified };
    }
    if (action === "playwright_set_permissions") {
      const permissions = Array.isArray(args.permissions) ? args.permissions.map(String).filter(Boolean).slice(0, 50) : [];
      if (!permissions.length) throw codedError("permissions_required", "permissions[] obbligatorio.");
      await sensitiveCdp(tabId, "Browser.grantPermissions", { permissions, origin: String(args.origin || origin) });
      return { tabId, action, origin: String(args.origin || origin), permissions, verified: true };
    }
    if (action === "playwright_clear_permissions") {
      await sensitiveCdp(tabId, "Browser.resetPermissions", {});
      return { tabId, action, scope: "browser_context", reset: true, verified: true };
    }
  }

  const contextActions = new Set([
    "playwright_launch_chromium", "playwright_launch_chrome", "playwright_connect_browser", "playwright_connect_over_cdp",
    "playwright_close_browser", "playwright_new_context", "playwright_close_context", "playwright_list_contexts",
  ]);
  if (contextActions.has(action)) {
    if (["playwright_close_browser", "playwright_close_context"].includes(action)) {
      const owned = await listOwnedTabs();
      const agentTabs = owned.filter((row) => !row.adoptedExternal);
      const cleanupFailures = [];
      for (const row of agentTabs) {
        const tabId = Number(row.tabId || 0);
        try { await forceCloseRuntimeSessions(tabId); }
        catch (error) { cleanupFailures.push({ stage: "runtime_sessions", tabId, error: serializeError(error) }); }
        try { await chrome.tabs.remove(tabId); }
        catch (error) { cleanupFailures.push({ stage: "tab_remove", tabId, error: serializeError(error) }); }
        await unregisterOwnedTab(tabId).catch(() => {});
        await clearTabAffinityForTab(tabId).catch(() => {});
      }
      if (cleanupFailures.length) {
        const first = cleanupFailures[0]?.error || {};
        throw codedError(
          cleanupFailures.length === 1 && first?.code ? String(first.code) : "BROWSER_CONTEXT_CLEANUP_FAILED",
          `Chiusura browser/context non completata: ${String(first?.message || first?.code || "errore sconosciuto")}`,
          { action, agentTabs: agentTabs.map((row) => Number(row.tabId || 0)), failures: cleanupFailures },
        );
      }
      return { action, mode: "same_profile_existing_window", closedAgentWindow: false, closedAgentTabs: agentTabs.length, closedUserTabs: false, adoptedUserTabsPreserved: true, executed: true };
    }
    const hostWindowId = await ensureAgentWindow();
    return {
      action, mode: "same_profile_existing_window", hostWindowId, agentWindowId: hostWindowId, tabs: await listOwnedTabs(), executorProtocolVersion: EXECUTOR_PROTOCOL_VERSION,
      semanticMapping: { browser: "Chrome personale già in esecuzione", context: "stesso profilo e stessa finestra Chrome normale; isolamento per lane e registro delle schede" }, executed: true,
    };
  }

  if (action === "playwright_link_crawl") {
    let seedUrl = String(args.url || "").trim();
    if (!seedUrl) {
      if (!requestedTab) requestedTab = await resolveTabId(state, args);
      seedUrl = String((await chrome.tabs.get(requestedTab)).url || "");
    }
    return runAutonomousLinkCrawler(state, seedUrl, args);
  }
  if (action === "playwright_sitemap_crawl") {
    let sitemapUrl = String(args.url || args.sitemap_url || "").trim();
    if (!sitemapUrl) {
      if (!requestedTab) requestedTab = await resolveTabId(state, args);
      sitemapUrl = new URL("/sitemap.xml", String((await chrome.tabs.get(requestedTab)).url || "")).href;
    }
    return runAutonomousSitemapCrawler(sitemapUrl, args);
  }

  if (!requestedTab) requestedTab = await resolveTabId(state, args);
  const tabId = requestedTab;
  const targetOwnership = await assertOwnedTab(tabId);
  await bindTabAffinity(state, tabId, "contract_action");
  await assertTargetBinding(tabId, { ...args, type: "contract_action", action }, state, { allowNavigationTransition: false });

  if (action === "fetch") return { action, ...(await fetchInOwnedPage(tabId, args)) };
  if (action === "create_visual_baseline") {
    const image = await captureScreenshot(tabId, true, true);
    let hash;
    try { hash = await sha256Text(image.dataUrl); }
    finally { releaseScreenshotBuffer(image); }
    const name = String(args.name || "default");
    const baselines = await getBaselines();
    baselines[name] = { hash, tabId, url: (await chrome.tabs.get(tabId)).url, at: Date.now() };
    await chrome.storage.local.set({ [STORAGE_KEYS.BASELINES]: baselines });
    return { tabId, action, name, hash, captured: true };
  }
  if (action === "visual_diff") {
    const image = await captureScreenshot(tabId, true, true);
    let currentHash;
    try { currentHash = await sha256Text(image.dataUrl); }
    finally { releaseScreenshotBuffer(image); }
    const name = String(args.name || "default");
    const baseline = (await getBaselines())[name] || null;
    return { tabId, action, name, currentHash, baselineHash: baseline?.hash || "", equal: Boolean(baseline && baseline.hash === currentHash), method: "exact_png_sha256" };
  }
  if (action === "html_diff") {
    const current = await runDomAction(tabId, "dom_snapshot", {});
    const currentHash = await sha256Text(current.html || "");
    return { tabId, action, currentHash, expectedHash: String(args.expected_hash || ""), equal: Boolean(args.expected_hash && args.expected_hash === currentHash), htmlLength: current.html?.length || 0 };
  }

  if (["playwright_wait_for_response", "playwright_wait_for_request"].includes(action)) {
    const pattern = String(args.url || args.pattern || "*");
    const deadline = Date.now() + boundedRuntimeTimeout(args.timeout, 30_000, 120_000);
    const wantedMethod = action.endsWith("response") ? "Network.responseReceived" : "Network.requestWillBeSent";
    await attachDebugger(tabId);
    await cdp(tabId, "Network.enable", {});
    while (Date.now() < deadline) {
      const events = networkBuffers.get(tabId) || [];
      const match = [...events].reverse().find((item) => item.method === wantedMethod && wildcardMatch(item.params?.response?.url || item.params?.request?.url || "", pattern));
      if (match) return { tabId, action, event: match };
      await sleep(250);
    }
    throw codedError("network_wait_timeout", `Nessun evento di rete corrisponde a ${pattern}.`);
  }

  if (action === "playwright_drag_and_drop") {
    const source = await runDomAction(tabId, "locate", { selector: args.source || args.source_selector, text: args.source_text || "" });
    const target = await runDomAction(tabId, "locate", { selector: args.target || args.target_selector, text: args.target_text || "" });
    const from = centerOfBox(source.element.boundingBox);
    const to = centerOfBox(target.element.boundingBox);
    const dispatched = await dispatchNativeCommands(tabId, dragSequence(from, to, { steps: args.steps, stepDelayMs: args.step_delay_ms }));
    return { tabId, action, source: source.element, target: target.element, from, to, ...dispatched };
  }

  if (["playwright_set_input_files", "playwright_upload_file"].includes(action)) {
    const located = await runDomAction(tabId, "locate", { selector: args.selector || "input[type=file]" });
    const files = Array.isArray(args.files) ? args.files : [args.file || args.path].filter(Boolean);
    if (!files.length) throw codedError("file_required", "Nessun file locale indicato per l’upload.");
    await attachDebugger(tabId);
    const doc = await cdp(tabId, "DOM.getDocument", { depth: -1, pierce: true });
    const node = await cdp(tabId, "DOM.querySelector", { nodeId: doc.root.nodeId, selector: located.element.selector });
    if (!node?.nodeId) throw codedError("file_input_missing", "Input file non individuato via DOM CDP.");
    await cdp(tabId, "DOM.setFileInputFiles", { nodeId: node.nodeId, files });
    return { tabId, action, fileCount: files.length, element: located.element, executed: true };
  }

  if (action === "playwright_set_content") {
    const html = String(args.html ?? args.content ?? "");
    await chrome.scripting.executeScript({ target: { tabId }, func: (value) => { document.open(); document.write(value); document.close(); }, args: [html] });
    return {
      tabId, action, bytes: new TextEncoder().encode(html).length, executed: true,
      isolatedAgentTab: !Boolean(targetOwnership?.adoptedExternal),
      ownershipType: targetOwnership?.adoptedExternal ? "explicitly_adopted" : "agent_created",
    };
  }

  if (action === "playwright_start_trace") {
    traceBuffers.set(tabId, []);
    await attachDebugger(tabId);
    await cdp(tabId, "Tracing.start", { categories: String(args.categories || "devtools.timeline,v8.execute,blink.user_timing"), transferMode: "ReportEvents" });
    const session = await openRuntimeSession(tabId, "trace");
    return { tabId, action, tracing: true, sessionId: session.id, expiresAt: session.expiresAt };
  }
  if (["playwright_stop_trace", "playwright_export_trace"].includes(action)) {
    const session = await assertRuntimeSessionUsable(tabId, "trace", args.session_id || args.sessionId || "");
    await attachDebugger(tabId);
    if (action === "playwright_stop_trace") await cdp(tabId, "Tracing.end", {});
    await sleep(boundedRuntimeTimeout(args.settle_ms, 500, 10_000, 0));
    const events = traceBuffers.get(tabId) || [];
    const payload = JSON.stringify({ traceEvents: events });
    if (action === "playwright_stop_trace") await closeRuntimeSession(tabId, "trace", session.id);
    return { tabId, action, sessionId: session.id, eventCount: events.length, sha256: await sha256Text(payload), bytes: new TextEncoder().encode(payload).length, contentOmitted: true };
  }

  if (action === "playwright_start_video") {
    screencastBuffers.set(tabId, []);
    await attachDebugger(tabId);
    await cdp(tabId, "Page.startScreencast", { format: "png", everyNthFrame: Math.max(1, Number(args.every_nth_frame || 1)) });
    const session = await openRuntimeSession(tabId, "video");
    return { tabId, action, recording: true, mode: "cdp_screencast_frames", sessionId: session.id, expiresAt: session.expiresAt };
  }
  if (action === "playwright_stop_video") {
    const session = await assertRuntimeSessionUsable(tabId, "video", args.session_id || args.sessionId || "");
    await attachDebugger(tabId);
    await cdp(tabId, "Page.stopScreencast", {});
    const frames = screencastBuffers.get(tabId) || [];
    const hashes = [];
    for (const frame of frames.slice(-30)) hashes.push(await sha256Text(frame.data || ""));
    const last = frames.at(-1);
    let artifact = null;
    try {
      artifact = last?.data ? await storeScreenshotArtifact({ tabId, dataUrl: `data:image/png;base64,${last.data}` }, state, null) : null;
    } finally {
      screencastBuffers.delete(tabId);
      await closeRuntimeSession(tabId, "video", session.id);
    }
    return { tabId, action, sessionId: session.id, recording: false, mode: "cdp_screencast_frames", frameCount: frames.length, frameHashes: hashes, lastFrameArtifact: artifact, videoContainerProduced: false };
  }

  if (action === "playwright_start_har") {
    networkBuffers.set(tabId, []);
    await attachDebugger(tabId);
    await cdp(tabId, "Network.enable", {});
    const session = await openRuntimeSession(tabId, "har");
    return { tabId, action, recording: true, format: "har-compatible-event-log", sessionId: session.id, expiresAt: session.expiresAt };
  }
  if (action === "playwright_stop_har") {
    const session = await assertRuntimeSessionUsable(tabId, "har", args.session_id || args.sessionId || "");
    const entries = harFromEvents(networkBuffers.get(tabId) || []);
    const payload = JSON.stringify({ log: { version: "1.2", creator: { name: "PR STUDIO", version: EXECUTOR_PROTOCOL_VERSION }, entries } });
    await closeRuntimeSession(tabId, "har", session.id);
    return { tabId, action, sessionId: session.id, recording: false, entryCount: entries.length, sha256: await sha256Text(payload), bytes: new TextEncoder().encode(payload).length, entries: entries.slice(-100) };
  }

  if (["playwright_route", "playwright_mock_response", "playwright_modify_response", "playwright_abort_request", "playwright_continue_request", "playwright_replay_har", "playwright_unroute"].includes(action)) {
    await attachDebugger(tabId);
    if (action === "playwright_unroute") {
      await cdp(tabId, "Fetch.disable", {});
      routeRules.delete(tabId);
      const session = await closeRuntimeSession(tabId, "route", args.session_id || args.sessionId || "");
      return { tabId, action, sessionId: session?.id || null, rules: 0 };
    }
    if (args.request_id) {
      const method = action === "playwright_abort_request" ? "Fetch.failRequest" : "Fetch.continueRequest";
      const params = action === "playwright_abort_request"
        ? { requestId: args.request_id, errorReason: args.error_reason || "BlockedByClient" }
        : { requestId: args.request_id };
      await cdp(tabId, method, params);
      return { tabId, action, requestId: args.request_id, executed: true };
    }
    let rules = routeRules.get(tabId) || [];
    if (action === "playwright_replay_har") {
      const entries = args.entries || args.har?.log?.entries || [];
      for (const entry of entries) {
        const url = entry.request?.url;
        if (!url) continue;
        rules.push({ pattern: url, mode: "mock", status: entry.response?.status || 200, headers: entry.response?.headers || {}, body: entry.response?.content?.text || "" });
      }
    } else {
      const mode = action === "playwright_abort_request" ? "abort"
        : action === "playwright_mock_response" ? "mock"
        : action === "playwright_modify_response" ? "modify" : "continue";
      rules.push({
        pattern: args.url || args.pattern || "*", mode,
        status: args.status || args.status_code, statusText: args.status_text,
        headers: args.headers || {}, body: args.body || "", method: args.method, url: args.new_url,
        postData: args.post_data, errorReason: args.error_reason,
      });
    }
    routeRules.set(tabId, rules.slice(-200));
    await cdp(tabId, "Fetch.enable", { patterns: [{ urlPattern: "*", requestStage: "Request" }] });
    const session = await openRuntimeSession(tabId, "route");
    return { tabId, action, sessionId: session.id, expiresAt: session.expiresAt, rules: routeRules.get(tabId).length, executed: true };
  }

  if (action === "playwright_download_wait") {
    await attachDebugger(tabId);
    const deadline = Date.now() + boundedRuntimeTimeout(args.timeout, 30_000, 120_000);
    while (Date.now() < deadline) {
      const events = downloadBuffers.get(tabId) || [];
      const completed = [...events].reverse().find((event) => event.method === "Browser.downloadProgress" && event.params?.state === "completed")
        || [...events].reverse().find((event) => /downloadWillBegin/.test(event.method));
      if (completed) return { tabId, action, event: completed };
      await sleep(250);
    }
    throw codedError("download_wait_timeout", "Nessun download rilevato nella scheda agente entro il timeout.");
  }

  if (action === "playwright_start_js_coverage") {
    await attachDebugger(tabId);
    await cdp(tabId, "Profiler.enable", {});
    await cdp(tabId, "Profiler.startPreciseCoverage", { callCount: true, detailed: true });
    const session = await openRuntimeSession(tabId, "js_coverage");
    return { tabId, action, started: true, sessionId: session.id, expiresAt: session.expiresAt };
  }
  if (action === "playwright_stop_js_coverage") {
    const session = await assertRuntimeSessionUsable(tabId, "js_coverage", args.session_id || args.sessionId || "");
    const coverage = await cdp(tabId, "Profiler.takePreciseCoverage", {});
    // stopPreciseCoverage is the semantically important call: CDP documents
    // that it releases execution counters and permits optimized code again.
    // Only after it succeeds may the durable runtime session be closed.
    await cdp(tabId, "Profiler.stopPreciseCoverage", {});
    const domainCleanup = await cdp(tabId, "Profiler.disable", {}).then(() => true).catch(() => false);
    await closeRuntimeSession(tabId, "js_coverage", session.id);
    return { tabId, action, sessionId: session.id, scripts: coverage.result || [], count: coverage.result?.length || 0, profilerDisabled: domainCleanup };
  }
  if (action === "playwright_start_css_coverage") {
    await attachDebugger(tabId);
    await cdp(tabId, "DOM.enable", {});
    await cdp(tabId, "CSS.enable", {});
    await cdp(tabId, "CSS.startRuleUsageTracking", {});
    const session = await openRuntimeSession(tabId, "css_coverage");
    return { tabId, action, started: true, sessionId: session.id, expiresAt: session.expiresAt };
  }
  if (action === "playwright_stop_css_coverage") {
    const session = await assertRuntimeSessionUsable(tabId, "css_coverage", args.session_id || args.sessionId || "");
    const coverage = await cdp(tabId, "CSS.stopRuleUsageTracking", {});
    const cssDisabled = await cdp(tabId, "CSS.disable", {}).then(() => true).catch(() => false);
    const domDisabled = await cdp(tabId, "DOM.disable", {}).then(() => true).catch(() => false);
    await closeRuntimeSession(tabId, "css_coverage", session.id);
    return { tabId, action, sessionId: session.id, rules: coverage.ruleUsage || [], count: coverage.ruleUsage?.length || 0, cssDisabled, domDisabled };
  }

  if (action === "playwright_cdp_subscribe") {
    await attachDebugger(tabId);
    for (const domain of (args.domains || ["Network", "Runtime", "Log"])) await cdp(tabId, `${domain}.enable`, {}).catch(() => {});
    const methods = Array.isArray(args.methods) ? args.methods : [];
    const events = [
      ...(cdpEventBuffers.get(tabId) || []),
      ...(networkBuffers.get(tabId) || []),
      ...(consoleBuffers.get(tabId) || []),
      ...(downloadBuffers.get(tabId) || []),
    ].filter((event) => !methods.length || methods.includes(event.method))
      .sort((a, b) => Number(a?.at || 0) - Number(b?.at || 0));
    return { tabId, action, subscribed: true, methods, events: events.slice(-500) };
  }

  if (["playwright_generate_test", "playwright_run_test", "playwright_run_test_suite", "playwright_form_smoke_test", "playwright_checkout_smoke_test", "playwright_search_smoke_test", "playwright_navigation_smoke_test"].includes(action)) {
    return runSmokeAudit(tabId, action, args);
  }

  if (action === "playwright_lighthouse_audit") {
    await attachDebugger(tabId);
    await cdp(tabId, "Performance.enable", {});
    const [metrics, snapshot, issues] = await Promise.all([
      cdp(tabId, "Performance.getMetrics", {}),
      runDomAction(tabId, "page_snapshot", { includeInteractive: true }),
      runDomAction(tabId, "accessibility_scan", {}),
    ]);
    return {
      tabId, action, provider: "devtools_quality_audit", lighthouseBinaryUsed: false,
      metrics: Object.fromEntries((metrics.metrics || []).map((item) => [item.name, item.value])),
      accessibilityIssues: issues.issues || [], page: { url: snapshot.url, title: snapshot.title, width: snapshot.page?.width, height: snapshot.page?.height },
    };
  }

  if (action === "playwright_responsive_matrix") {
    const sizes = (Array.isArray(args.viewports) && args.viewports.length ? args.viewports : [
      { width: 375, height: 812, name: "mobile" }, { width: 768, height: 1024, name: "tablet" }, { width: 1440, height: 900, name: "desktop" },
    ]).slice(0, 12);
    const requestedBudget = Number(args.timeout_ms || args.timeout || 0);
    const defaultBudget = Math.max(10_000, Math.min(RESPONSIVE_MATRIX_MAX_TIMEOUT_MS, sizes.length * RESPONSIVE_MATRIX_VIEWPORT_TIMEOUT_MS));
    const matrixBudgetMs = requestedBudget > 0
      ? Math.max(5_000, Math.min(RESPONSIVE_MATRIX_MAX_TIMEOUT_MS, requestedBudget))
      : defaultBudget;
    const matrixDeadlineAt = Date.now() + matrixBudgetMs;
    const results = [];
    let matrixError = null;
    let restoreError = null;
    try {
      for (let index = 0; index < sizes.length; index += 1) {
        const size = sizes[index];
        const viewportDeadlineAt = Math.min(matrixDeadlineAt, Date.now() + RESPONSIVE_MATRIX_VIEWPORT_TIMEOUT_MS);
        await responsiveMatrixCdp(tabId, "Emulation.setDeviceMetricsOverride", {
          width: Number(size.width || 1280),
          height: Number(size.height || 720),
          deviceScaleFactor: Number(size.deviceScaleFactor || size.device_scale_factor || 1),
          mobile: Boolean(size.mobile),
        }, viewportDeadlineAt);
        const settleMs = Math.min(150, Math.max(0, responsiveMatrixRemainingMs(viewportDeadlineAt, 250, "viewport_settle") - 75));
        if (settleMs > 0) await abortableSleep(settleMs, taskAbortController?.signal);
        const captureDeadlineAt = Math.min(viewportDeadlineAt, Date.now() + SCREENSHOT_CAPTURE_TIMEOUT_MS);
        const image = await captureScreenshot(tabId, false, false, { deadlineAt: captureDeadlineAt });
        try {
          results.push({ name: size.name || `${size.width}x${size.height}`, width: size.width, height: size.height, sha256: await sha256Text(image.dataUrl) });
        } finally {
          releaseScreenshotBuffer(image);
        }
        await markInFlightProgress(state, {
          action,
          completedViewports: results.length,
          totalViewports: sizes.length,
          viewport: size.name || `${size.width}x${size.height}`,
        });
      }
    } catch (error) {
      matrixError = error;
    } finally {
      try {
        const cleanupDeadlineAt = Date.now() + RESPONSIVE_MATRIX_RESTORE_TIMEOUT_MS;
        await responsiveMatrixCdp(tabId, "Emulation.clearDeviceMetricsOverride", {}, cleanupDeadlineAt);
      } catch (error) {
        restoreError = error;
        await appendLog("responsive_matrix.viewport_restore_failed", { tabId, matrixError: serializeError(matrixError), restoreError: serializeError(restoreError) }).catch(() => {});
      }
    }
    if (matrixError && restoreError) {
      throw codedError("RESPONSIVE_MATRIX_OPERATION_AND_CLEANUP_FAILED", "Responsive matrix e ripristino viewport sono falliti; nessun successo viene dichiarato.", {
        operation: serializeError(matrixError), cleanup: serializeError(restoreError), completedViewports: results.length, totalViewports: sizes.length,
      });
    }
    if (restoreError) throw restoreError;
    if (matrixError) throw matrixError;
    return { tabId, action, results, count: results.length, viewportRestored: true, matrixBudgetMs };
  }

  if (action === "playwright_mask_dynamic_regions") {
    const selectors = Array.isArray(args.selectors) ? args.selectors : [args.selector].filter(Boolean);
    const restore = args.restore === true;
    if (restore) {
      const restoredCount = await restoreDynamicMasks(tabId);
      const marker = await updateOwnedTab(tabId, { maskPendingRestore: false, maskRestoredAt: Date.now() });
      if (!marker) throw codedError("mask_restore_state_missing", "Impossibile aggiornare lo stato persistente del ripristino maschere.", { tabId });
      return { tabId, action, selectors, restored: true, masked: 0, restoredCount, autoRestoreAtTaskEnd: false };
    }
    const results = await chrome.scripting.executeScript({
      target: { tabId },
      func: (items) => {
        let count = 0;
        for (const selector of items) {
          for (const element of document.querySelectorAll(selector)) {
            if (!("prstudioOriginalVisibility" in element.dataset)) element.dataset.prstudioOriginalVisibility = element.style.visibility || "";
            element.style.visibility = "hidden"; count += 1;
          }
        }
        return count;
      },
      args: [selectors],
    });
    const masked = Number(results?.[0]?.result || 0);
    if (masked > 0) {
      try {
        const marker = await updateOwnedTab(tabId, { maskPendingRestore: true, maskAppliedAt: Date.now() });
        if (!marker) throw codedError("mask_restore_state_missing", "Impossibile persistere lo stato di ripristino maschere.", { tabId });
      } catch (markerError) {
        let restoreError = null;
        try { await restoreDynamicMasks(tabId); } catch (error) { restoreError = error; }
        throw codedError("mask_restore_state_persist_failed", "Maschera applicata ma stato di ripristino non persistito; è stato tentato il rollback immediato.", {
          tabId, markerError: serializeError(markerError), restoreError: serializeError(restoreError),
        });
      }
    }
    return { tabId, action, selectors, restored: false, masked, restoredCount: 0, autoRestoreAtTaskEnd: masked > 0 };
  }

  throw codedError("contract_executor_unreachable", `Azione registrata ma non raggiunta dal Browser Agent 1.0.0: ${action}`, { action, executorProtocolVersion: EXECUTOR_PROTOCOL_VERSION });
}

async function screenshotStorageStatus(force = false, timeoutMs = SCREENSHOT_PREFLIGHT_TIMEOUT_MS) {
  const now = Date.now();
  if (!force && screenshotStorageCache && now - screenshotStorageCacheAt < SCREENSHOT_STORAGE_CACHE_MS) return screenshotStorageCache;
  const status = await api("/artifact/screenshot", { method: "GET", timeoutMs: Math.min(SCREENSHOT_PREFLIGHT_TIMEOUT_MS, Math.max(250, Number(timeoutMs || SCREENSHOT_PREFLIGHT_TIMEOUT_MS))) });
  screenshotStorageCache = status && typeof status === "object" ? status : { ok: false, writable: false, reason: "invalid_storage_status" };
  screenshotStorageCacheAt = now;
  return screenshotStorageCache;
}

async function screenshotStorageProbe(timeoutMs = SCREENSHOT_PREFLIGHT_TIMEOUT_MS) {
  try {
    const storage = await screenshotStorageStatus(false, timeoutMs);
    if (!storage?.writable || storage?.headroom_ok === false) {
      return { ...(storage || {}), writable: false, verified: false, degraded: true, blocking: false, reason: storage?.reason || "screenshot_storage_unavailable" };
    }
    return { ...storage, verified: true, degraded: false, blocking: false };
  } catch (error) {
    return { writable: false, verified: false, degraded: true, blocking: false, reason: "screenshot_storage_probe_failed", technical_error: serializeError(error) };
  }
}

function screenshotPayloadBytes(dataUrl = "") {
  const comma = String(dataUrl || "").indexOf(",");
  if (comma < 0) return 0;
  const encoded = String(dataUrl).slice(comma + 1).replace(/\s+/g, "");
  return Math.max(0, Math.floor(encoded.length * 3 / 4) - (encoded.endsWith("==") ? 2 : encoded.endsWith("=") ? 1 : 0));
}

function releaseScreenshotBuffer(image) {
  if (!image || typeof image !== "object") return;
  if (typeof image.dataUrl === "string") image.dataUrl = "";
}

async function storeScreenshotArtifact(image, state, preflight = null, options = {}) {
  if (!image?.dataUrl) throw codedError("screenshot_data_missing", "Screenshot privo di dati.");
  const deadlineAt = Number(options.deadlineAt || 0);
  let storage;
  storage = preflight || await screenshotStorageProbe(deadlineAt ? screenshotTimeoutRemainingMs(deadlineAt, SCREENSHOT_PREFLIGHT_TIMEOUT_MS, "storage_probe") : SCREENSHOT_PREFLIGHT_TIMEOUT_MS);
  if (!storage?.writable || storage?.headroom_ok === false) {
    const result = { tabId: image.tabId, executed: true, persisted: false, verified: false, degraded: true, blocking: false, width: image.width || null, height: image.height || null, persistence: storage };
    if (options.releaseData !== false) releaseScreenshotBuffer(image);
    return result;
  }
  const bytes = screenshotPayloadBytes(image.dataUrl);
  const serverMax = Math.max(1, Number(storage?.max_artifact_bytes || SCREENSHOT_MAX_OUTPUT_BYTES));
  const safeMax = Math.min(serverMax, SCREENSHOT_MAX_OUTPUT_BYTES);
  if (bytes > safeMax) {
    const result = { tabId: image.tabId, executed: true, persisted: false, verified: false, degraded: true, blocking: false, width: image.width || null, height: image.height || null, persistence: { code: "screenshot_artifact_too_large", bytes, maxBytes: safeMax } };
    if (options.releaseData !== false) releaseScreenshotBuffer(image);
    return result;
  }
  const uploadController = new AbortController();
  const uploadTimeoutMs = deadlineAt ? screenshotTimeoutRemainingMs(deadlineAt, SCREENSHOT_UPLOAD_TIMEOUT_MS, "artifact_upload") : SCREENSHOT_UPLOAD_TIMEOUT_MS;
  let uploadTimedOut = false;
  const parentSignal = taskAbortController?.signal;
  const relayAbort = () => uploadController.abort(parentSignal?.reason || "task_aborted");
  if (parentSignal?.aborted) relayAbort();
  else parentSignal?.addEventListener?.("abort", relayAbort, { once: true });
  const uploadTimeout = setTimeout(() => { uploadTimedOut = true; uploadController.abort("screenshot_upload_timeout"); }, uploadTimeoutMs);
  try {
    const artifact = await api("/artifact/screenshot", {
      method: "POST",
      signal: uploadController.signal,
      timeoutMs: uploadTimeoutMs,
      body: {
        image: image.dataUrl,
        task_id: state?.taskId || "",
        step_index: Number(state?.stepIndex || 0),
        capture_mode: image.captureMode || "",
        full_page: Boolean(image.fullPageRequested),
        full_page_complete: Boolean(image.fullPageComplete),
      },
    });
    screenshotStorageCacheAt = 0; // refresh capacity after the write on the next capture.
    return { tabId: image.tabId, width: image.width || null, height: image.height || null, ...artifact };
  } catch (error) {
    screenshotStorageCacheAt = 0;
    return {
      tabId: image.tabId, executed: true, persisted: false, verified: false, degraded: true, blocking: false,
      width: image.width || null, height: image.height || null,
      persistence: { code: uploadTimedOut ? "screenshot_upload_timeout" : String(error?.code || "screenshot_upload_failed"), timeoutMs: uploadTimeoutMs, error: serializeError(error) },
    };
  } finally {
    clearTimeout(uploadTimeout);
    parentSignal?.removeEventListener?.("abort", relayAbort);
    if (options.releaseData !== false) releaseScreenshotBuffer(image);
  }
}

async function runOcr(image, language) {
  try {
    const server = await api("/ocr", { method: "POST", body: { image: image.dataUrl, language } });
    if (server?.text !== undefined || server?.status === "completed") {
      return { available: true, optical: true, provider: "server_tesseract", ...server };
    }
  } catch (error) {
    await appendLog("ocr.server.unavailable", serializeError(error));
  }

  const native = await browserNativeOcr(image.tabId, image.dataUrl);
  if (native?.available) return { optical: true, ...native };

  const pageText = await browserPageText(image.tabId);
  if (pageText?.available) {
    return {
      optical: false,
      provider: "browser_page_text",
      notice: "Testo estratto da DOM e accessibilità della pagina; non è riconoscimento ottico dei pixel.",
      ...pageText,
    };
  }

  return {
    available: false,
    optical: false,
    provider: "none",
    text: "",
    reason: [native?.reason, pageText?.reason, "OCR non disponibile"].filter(Boolean).join("; "),
  };
}

async function browserNativeOcr(tabId, dataUrl) {
  if (!tabId) return { available: false, provider: "browser_text_detector", reason: "tab_missing" };
  try {
    const results = await chrome.scripting.executeScript({
      target: { tabId, allFrames: false },
      func: async (imageUrl) => {
        if (typeof TextDetector === "undefined") return { available: false, reason: "TextDetector non disponibile in questa versione di Chrome" };
        const blob = await (await fetch(imageUrl)).blob();
        const bitmap = await createImageBitmap(blob);
        try {
          const blocks = await new TextDetector().detect(bitmap);
          return {
            available: true,
            text: blocks.map((block) => block.rawValue || "").filter(Boolean).join("\n"),
            blocks: blocks.length,
          };
        } finally {
          bitmap.close?.();
        }
      },
      args: [dataUrl],
    });
    return { provider: "browser_text_detector", ...(results?.[0]?.result || { available: false, reason: "empty_result" }) };
  } catch (error) {
    return { available: false, provider: "browser_text_detector", reason: String(error?.message || error) };
  }
}

async function browserPageText(tabId) {
  if (!tabId) return { available: false, provider: "browser_page_text", reason: "tab_missing" };
  try {
    const results = await chrome.scripting.executeScript({
      target: { tabId, allFrames: false },
      func: () => {
        const visible = (element) => {
          const style = getComputedStyle(element);
          const rect = element.getBoundingClientRect();
          return style.display !== "none"
            && style.visibility !== "hidden"
            && Number(style.opacity || 1) > 0.05
            && rect.width > 0
            && rect.height > 0;
        };
        const chunks = [];
        const body = String(document.body?.innerText || "").trim();
        if (body) chunks.push(body);
        for (const element of document.querySelectorAll("[aria-label],[alt],[title],input,textarea,select")) {
          if (!visible(element)) continue;
          const value = [
            element.getAttribute("aria-label"), element.getAttribute("alt"), element.getAttribute("title"),
            element.placeholder,
          ].filter(Boolean).join(" ").trim();
          if (value) chunks.push(value);
        }
        const unique = [...new Set(chunks.map((value) => value.replace(/\s+/g, " ").trim()).filter(Boolean))];
        return { available: unique.length > 0, text: unique.join("\n").slice(0, 200000), blocks: unique.length, url: location.href, title: document.title };
      },
    });
    return { provider: "browser_page_text", ...(results?.[0]?.result || { available: false, reason: "empty_result" }) };
  } catch (error) {
    return { available: false, provider: "browser_page_text", reason: String(error?.message || error) };
  }
}

const OBSERVATION_STEP_TYPES = new Set([
  "extract_text", "dom_snapshot", "page_snapshot", "computed_styles", "accessibility_snapshot",
  "network_report", "console_report", "page_errors", "headers", "service_workers", "accessibility_scan",
  "core_web_vitals", "verify_dom", "ocr", "search_console", "observation_bundle", "social_snapshot", "cdp",
]);

function isObservationStep(step = {}) {
  if (OBSERVATION_STEP_TYPES.has(String(step.type || ""))) return true;
  if (step.type !== "contract_action") return false;
  return /(?:snapshot|content|text|network|console|har|cdp|download|accessibility|coverage|audit|metrics|wait_for_(?:request|response)|fetch|search_console|crawl|extract|get_|list_|inspect|report)/i.test(String(step.action || ""));
}

async function observationProvenance(state, step, result) {
  const tabId = Number(result?.tabId || step?.tabId || state?.tabId || 0) || null;
  const tab = tabId ? await chrome.tabs.get(tabId).catch(() => null) : null;
  const url = String(tab?.url || result?.url || "");
  let origin = "";
  try { origin = new URL(url).origin; } catch { /* non-page observation */ }
  return {
    source: "chrome_extension",
    taskId: String(state?.taskId || ""),
    stepIndex: Number(state?.stepIndex || 0),
    stepType: String(step?.type || ""),
    action: String(step?.action || state?.action || ""),
    tabId,
    frameId: 0,
    url,
    origin,
    title: String(tab?.title || result?.title || ""),
    capturedAt: new Date().toISOString(),
  };
}

async function secureResultForCheckpoint(state, step, result) {
  const provenance = await observationProvenance(state, step, result);
  const kind = String(step?.action || step?.type || "browser_observation");
  const consoleLike = /console|page_errors|network|har/i.test(kind);
  if (isObservationStep(step)) {
    const observation = createObservationEnvelope({ kind, data: result, provenance }, {
      console: consoleLike,
      limits: { maxDepth: 20, maxArrayLength: 500, maxObjectKeys: 500, maxStringLength: 32768 },
    });
    return {
      tabId: provenance.tabId,
      verified: Boolean(result?.verified),
      stepType: String(step?.type || ""),
      observation,
    };
  }
  const sanitized = redactObservation(result, { console: consoleLike });
  return {
    ...sanitized.value,
    observationSecurity: {
      version: OBSERVATION_SECURITY_VERSION,
      trust: "untrusted_web_content",
      redactionCount: sanitized.redactionCount,
      truncated: sanitized.truncated,
      truncationCount: sanitized.truncationCount,
    },
  };
}

async function captureObservationBundle(tabId, step, state) {
  await assertTargetBinding(tabId, step, state, { allowNavigationTransition: false });
  await attachDebugger(tabId);
  const detail = String(step.detail || "compact").toLowerCase();
  const [page, accessibility, tab] = await Promise.all([
    runDomAction(tabId, "page_snapshot", { includeInteractive: true, maxChars: detail === "full" ? 200_000 : 100_000 }).catch((error) => ({ available: false, error: serializeError(error) })),
    cdp(tabId, "Accessibility.getFullAXTree", {}).then((value) => {
      const nodes = Array.isArray(value.nodes) ? value.nodes : [];
      const limit = detail === "full" ? 1500 : 500;
      return { nodes: nodes.slice(0, limit), nodeCount: nodes.length, truncated: nodes.length > limit };
    }).catch((error) => ({ available: false, error: serializeError(error) })),
    chrome.tabs.get(tabId),
  ]);
  const text = (step.includePageText === true || page?.available === false)
    ? await browserPageText(tabId)
    : { available: false, omitted: true, reason: "semantic_snapshot_already_contains_page_text" };
  let screenshot = null;
  let perceptionFrame = null;
  if (step.includeScreenshot !== false) {
    perceptionFrame = await capturePerceptionFrame(tabId, state, { viewerOnly: Boolean(step.viewerOnly || step.viewer_only) });
    screenshot = perceptionFrame.artifact || perceptionFrame.image || null;
  }
  const networkLimit = detail === "full" ? 250 : 100;
  const payloadLimit = detail === "full" ? 40 : 20;
  const consoleLimit = detail === "full" ? 250 : 100;
  return {
    tabId, url: tab.url || "", title: tab.title || "", page, accessibility, pageText: text,
    network: { events: (networkBuffers.get(tabId) || []).slice(-networkLimit), payloads: (structuredNetworkPayloads.get(tabId) || []).slice(-payloadLimit) },
    console: (consoleBuffers.get(tabId) || []).slice(-consoleLimit), screenshot, perceptionFrame,
    completeness: {
      page: page?.available !== false, accessibility: accessibility?.available !== false, pageText: Boolean(text?.available),
      pageTextOmittedAsDuplicate: Boolean(text?.omitted), networkEventCount: (networkBuffers.get(tabId) || []).length, consoleEventCount: (consoleBuffers.get(tabId) || []).length,
    },
  };
}

async function captureSocialSnapshot(tabId, step, state) {
  await assertTargetBinding(tabId, step, state, { allowNavigationTransition: false });
  const rows = await chrome.scripting.executeScript({
    target: { tabId, allFrames: false },
    func: () => {
      const visible = (element) => {
        const style = getComputedStyle(element);
        const rect = element.getBoundingClientRect();
        return style.display !== "none" && style.visibility !== "hidden" && Number(style.opacity || 1) > 0.05 && rect.width > 0 && rect.height > 0;
      };
      const clean = (value, max = 4000) => String(value || "").replace(/\s+/g, " ").trim().slice(0, max);
      const hostname = location.hostname.toLowerCase();
      // Match the registrable domain, not a substring. hostname.includes("x.com")
      // is true for "x.com.attacker.net" and for "notx.com", so a page could
      // present itself as a platform it is not. Nothing security-critical hangs
      // off this label today -- it only tags a snapshot -- but a wrong host test
      // is the kind of thing that gets reused somewhere it does matter.
      const isHost = (domain) => hostname === domain || hostname.endsWith("." + domain);
      const platform = isHost("instagram.com") ? "instagram"
        : isHost("facebook.com") ? "facebook"
          : isHost("linkedin.com") ? "linkedin"
            : isHost("x.com") || isHost("twitter.com") ? "x"
              : isHost("tiktok.com") ? "tiktok"
                : isHost("youtube.com") ? "youtube"
                  : "generic_social_or_public_page";
      const meta = Object.fromEntries([...document.querySelectorAll("meta[property^='og:'],meta[name^='twitter:']")]
        .slice(0, 50)
        .map((node) => [node.getAttribute("property") || node.getAttribute("name"), clean(node.content, 2000)]));
      const candidates = [...document.querySelectorAll("article,[role='article'],main section")].filter(visible).slice(0, 30);
      const posts = candidates.map((node, index) => {
        const text = clean(node.innerText || node.textContent, 8000);
        const links = [...node.querySelectorAll("a[href]")].slice(0, 30).map((link) => ({ text: clean(link.innerText || link.getAttribute("aria-label"), 300), href: link.href }));
        const media = [...node.querySelectorAll("img[src],video[src],video[poster]")].slice(0, 20).map((item) => ({
          type: item.tagName.toLowerCase(),
          src: item.currentSrc || item.src || item.poster || "",
          alt: clean(item.alt || item.getAttribute("aria-label"), 500),
        }));
        const engagementLabels = [...node.querySelectorAll("[aria-label],[title]")].filter(visible).map((item) => clean(item.getAttribute("aria-label") || item.title, 300))
          .filter((value) => /like|comment|share|view|reaction|mi piace|comment|condivision|visualizz/i.test(value)).slice(0, 50);
        return { index, text, links, media, engagementLabels };
      }).filter((post) => post.text || post.media.length);
      return {
        platform,
        url: location.href,
        canonicalUrl: document.querySelector("link[rel='canonical']")?.href || "",
        title: document.title,
        description: clean(document.querySelector("meta[name='description']")?.content || meta["og:description"], 4000),
        meta,
        headings: [...document.querySelectorAll("h1,h2")].filter(visible).slice(0, 50).map((item) => clean(item.innerText, 1000)),
        posts,
        observedPostCount: posts.length,
        bestEffort: true,
        limitations: ["DOM pubblico/visibile soltanto", "metriche non normalizzate né inferite", "nessun endpoint privato o cookie esportato"],
      };
    },
  });
  let screenshot = null;
  if (step.includeScreenshot) {
    screenshot = await storeScreenshotArtifact(await captureScreenshot(tabId, false, false), state, null);
  }
  return { tabId, ...(rows?.[0]?.result || { bestEffort: true, available: false }), screenshot };
}

async function debuggerSessionCommandWithTimeout(tabId, sessionId, method, params = {}, timeoutMs = CDP_DEFAULT_TIMEOUT_MS) {
  const id = Number(tabId || 0);
  return promiseWithTimeout(
    chrome.debugger.sendCommand({ tabId: id, ...(sessionId ? { sessionId: String(sessionId) } : {}) }, method, params || {}),
    timeoutMs,
    () => codedError("CDP_TIMEOUT", `Comando CDP ${String(method || "")} oltre ${timeoutMs} ms.`, { tabId: id, sessionId: sessionId || null, method, timeoutMs }),
  );
}

async function armFrameAutoAttach(tabId, sessionId = "") {
  const id = Number(tabId || 0);
  if (!id) return false;
  await debuggerSessionCommandWithTimeout(id, sessionId, "Target.setAutoAttach", {
    autoAttach: true,
    waitForDebuggerOnStart: false,
    flatten: true,
    filter: [{ type: "iframe", exclude: false }],
  }, 5000);
  if (!sessionId) cdpAutoAttachTabs.add(id);
  return true;
}

async function ensureFrameAutoAttach(tabId) {
  const id = Number(tabId || 0);
  if (!id || cdpAutoAttachTabs.has(id)) return;
  await armFrameAutoAttach(id, "");
}

async function debuggerAttached(tabId, timeoutMs = 5_000) {
  const bounded = Math.max(250, Math.min(5_000, Number(timeoutMs || 5_000)));
  const targets = await promiseWithTimeout(
    chrome.debugger.getTargets(),
    bounded,
    () => codedError("CDP_TARGETS_TIMEOUT", "Lettura target debugger oltre il limite.", { tabId, timeoutMs: bounded })
  );
  return Boolean(targets.find((item) => Number(item.tabId) === Number(tabId))?.attached);
}

async function attachDebuggerIfNeeded(tabId, timeoutMs = 15_000) {
  await assertOwnedTab(tabId);
  const id = Number(tabId || 0);
  const hotProtocol = debuggerProtocolByTab.get(id);
  if (hotProtocol) { await ensureFrameAutoAttach(id).catch(() => {}); return hotProtocol; }
  const bounded = Math.max(500, Math.min(15_000, Number(timeoutMs || 15_000)));
  const deadlineAt = Date.now() + bounded;
  const remaining = (cap = bounded) => Math.max(250, Math.min(cap, deadlineAt - Date.now()));
  if (await debuggerAttached(tabId, remaining(Math.min(1_000, bounded)))) { await ensureFrameAutoAttach(Number(tabId)).catch(() => {}); return debuggerProtocolByTab.get(Number(tabId)) || "attached"; }
  try {
    const negotiated = await promiseWithTimeout(
      attachWithProtocolFallback(chrome.debugger, { tabId }, () => debuggerAttached(tabId, remaining(500))),
      remaining(bounded),
      () => codedError("CDP_ATTACH_TIMEOUT", "Negoziazione Chrome DevTools Protocol oltre il limite.", { tabId, timeoutMs: bounded })
    );
    debuggerProtocolByTab.set(Number(tabId), negotiated.protocolVersion);
    await ensureFrameAutoAttach(Number(tabId)).catch(() => {});
    return negotiated.protocolVersion;
  } catch (error) {
    if (deadlineAt - Date.now() >= 250 && await debuggerAttached(tabId, remaining(500)).catch(() => false)) await debuggerDetachWithTimeout(tabId, remaining(500)).catch(() => {});
    if (String(error?.code || "") === "CDP_ATTACH_TIMEOUT") throw error;
    throw codedError(
      "cdp_protocol_incompatible",
      "Impossibile negoziare una versione Chrome DevTools Protocol compatibile.",
      { tabId, candidates: [...DEBUGGER_PROTOCOL_CANDIDATES], errors: error?.details?.errors || [] }
    );
  }
}

async function attachDebugger(tabId) {
  await attachDebuggerIfNeeded(tabId);
  await cdp(tabId, "Page.enable", {}).catch(() => {});
  await cdp(tabId, "Runtime.enable", {}).catch(() => {});
  await cdp(tabId, "Network.enable", {}).catch(() => {});
  await cdp(tabId, "Log.enable", {}).catch(() => {});
}

async function detachDebugger(tabId, reason = "runtime_cleanup") {
  const id = Number(tabId || 0);
  if (await debuggerAttached(id)) {
    markIntentionalDebuggerDetach(id, reason);
    try {
      await debuggerDetachWithTimeout(id);
    } catch (error) {
      intentionalDebuggerDetaches.delete(id);
      throw error;
    }
  }
  debuggerProtocolByTab.delete(id);
  cdpChildSessionsByTab.delete(id);
  cdpAutoAttachTabs.delete(id);
}

async function screenshotCdp(tabId, method, params, deadlineAt, scope = "internal") {
  const decision = validateCdpCommand(method, params || {}, scope);
  if (!decision.ok) throw codedError(decision.code, `Metodo CDP bloccato: ${String(method || "")}`, { method, scope });
  await attachDebuggerIfNeeded(tabId, screenshotTimeoutRemainingMs(deadlineAt, SCREENSHOT_ATTACH_TIMEOUT_MS, "cdp_attach"));
  return debuggerCommandWithTimeout(
    tabId,
    method,
    params || {},
    screenshotTimeoutRemainingMs(deadlineAt, SCREENSHOT_CDP_TIMEOUT_MS, "cdp_command"),
    "screenshot_cdp_timeout",
  );
}

async function cdp(tabId, method, params, scope = "internal") {
  const decision = validateCdpCommand(method, params || {}, scope);
  if (!decision.ok) throw codedError(decision.code, `Metodo CDP bloccato: ${String(method || "")}`, { method, scope });
  await attachDebuggerIfNeeded(tabId);
  try {
    return await debuggerCommandWithTimeout(tabId, method, params || {}, CDP_DEFAULT_TIMEOUT_MS, "cdp_command_timeout");
  } catch (error) {
    const message = String(error?.message || error);
    if (/debugger is not attached|Detached while handling/i.test(message) && String(error?.code || "") !== "CDP_TIMEOUT") {
      await attachDebuggerIfNeeded(tabId);
      return debuggerCommandWithTimeout(tabId, method, params || {}, CDP_DEFAULT_TIMEOUT_MS, "cdp_retry_timeout");
    }
    throw error;
  }
}

async function tabDomReadiness(tabId) {
  const tab = await chrome.tabs.get(tabId);
  const url = String(tab?.url || "");
  const unusable = !url || url === "about:blank" || /^chrome-error:\/\//i.test(url);
  if (unusable) return { tab, readyState: "", usable: false, url };
  const rows = await chrome.scripting.executeScript({
    target: { tabId, allFrames: false },
    func: () => ({ readyState: document.readyState, href: location.href, hasDocumentElement: Boolean(document.documentElement) }),
  }).catch(() => []);
  const sample = rows?.[0]?.result || {};
  return { tab, readyState: String(sample.readyState || ""), usable: Boolean(sample.hasDocumentElement) && !/^chrome-error:\/\//i.test(String(sample.href || url)), url: String(sample.href || url) };
}

async function waitForTab(tabId, status = "complete", timeoutMs = 30000, signal = taskAbortController?.signal) {
  const requested = String(status || "complete").toLowerCase();
  if (["none", "no_wait", "nowait"].includes(requested)) {
    const tab = await chrome.tabs.get(tabId);
    return { readiness: "no_wait", requestedState: requested, tabStatus: tab.status || "" };
  }
  const deadline = Date.now() + boundedRuntimeTimeout(timeoutMs, 30_000, 120_000);
  const wantsInteractive = ["interactive", "domcontentloaded", "dom_content_loaded"].includes(requested);
  while (Date.now() < deadline) {
    if (signal?.aborted) {
      const reason = String(signal?.reason || "task_aborted");
      throw codedError(reason === "lease_lost" ? "LEASE_LOST" : reason === "cancelled_by_user" ? "TASK_CANCELLED" : "TASK_ABORTED", `Caricamento interrotto: ${reason}`);
    }
    const probe = await tabDomReadiness(tabId).catch(() => null);
    if (probe) {
      if (requested === "complete" && (probe.tab?.status === "complete" || probe.readyState === "complete")) {
        return { readiness: "complete", requestedState: requested, tabStatus: probe.tab?.status || "", readyState: probe.readyState };
      }
      if (wantsInteractive && probe.usable && ["interactive", "complete"].includes(probe.readyState)) {
        return { readiness: "dom_interactive", requestedState: requested, tabStatus: probe.tab?.status || "", readyState: probe.readyState };
      }
      if (!wantsInteractive && requested !== "complete" && probe.tab?.status === requested) {
        return { readiness: "tab_status", requestedState: requested, tabStatus: probe.tab?.status || "", readyState: probe.readyState };
      }
    }
    await sleep(80);
  }
  // Some SPAs keep Chrome's tab status in loading while the document is already
  // interactive and usable. Error/blank pages remain technical load failures, while a
  // healthy DOM is accepted instead of becoming a false runtime timeout.
  const finalProbe = await tabDomReadiness(tabId).catch(() => null);
  if (finalProbe?.usable && ["interactive", "complete"].includes(finalProbe.readyState)) {
    return { readiness: "dom_readiness_timeout_fallback", requestedState: requested, tabStatus: finalProbe.tab?.status || "", readyState: finalProbe.readyState, softReady: true };
  }
  throw new Error(`Timeout caricamento scheda ${tabId}`);
}

function startHeartbeat() {
  heartbeatActiveTask().catch(logError);
}

function stopHeartbeat() {}

async function heartbeatActiveTask() {
  const state = await getActiveTask();
  if (!state?.taskId || !state?.leaseToken || ["cancel_requested", "lease_lost"].includes(state.phase)) {
    return { skipped: "no_active_lease" };
  }
  if (noProgressExceeded(state)) {
    if (taskAbortController && !taskAbortController.signal.aborted) taskAbortController.abort("step_stalled");
    taskExecutionGeneration += 1;
    stopHeartbeat();
    await appendLog("task.heartbeat_stopped_for_stall", { taskId: state.taskId, inFlight: state.inFlight || null }).catch(() => {});
    return { skipped: "stalled_step" };
  }
  try {
    const heartbeat = await api(`/tasks/${state.taskId}/heartbeat`, {
      method: "POST",
      body: { lease_token: state.leaseToken },
    });
    if (!heartbeat?.ok) throw codedError("LEASE_LOST", "Il server ha rifiutato il rinnovo della lease del task.", { taskId: state.taskId });
    const latest = await getActiveTask();
    if (latest?.taskId === state.taskId && latest?.leaseToken === state.leaseToken) {
      latest.lastHeartbeatAt = Date.now();
      await saveActiveTask(latest);
    }
    return { ok: true, taskId: state.taskId };
  } catch (error) {
    if ([401, 403, 409].includes(Number(error?.status)) || ["AUTH_EXPIRED", "FORBIDDEN", "LEASE_LOST"].includes(String(error?.code || ""))) {
      await handleLeaseLoss(state, error);
      return { ok: false, leaseLost: true };
    }
    throw error;
  }
}

async function handleLeaseLoss(state, error) {
  if (taskAbortController) taskAbortController.abort("lease_lost");
  taskExecutionGeneration += 1;
  stopHeartbeat();
  const latest = await getActiveTask();
  const persisted = latest?.taskId === state?.taskId ? latest : null;
  if (persisted?.taskId) {
    persisted.phase = "lease_lost";
    persisted.leaseLostAt = Date.now();
    persisted.leaseLoss = { status: Number(error?.status || 0), code: String(error?.code || "LEASE_LOST") };
    persisted.leaseToken = null;
    await saveActiveTask(persisted);
    await cleanupTaskRuntime(persisted).catch(() => {});
  }
  await setBadge("LEASE", "#a32020");
}

function throwIfTaskAborted(expectedGeneration = taskExecutionGeneration) {
  const signal = taskAbortController?.signal;
  if (!signal?.aborted && Number(expectedGeneration) === Number(taskExecutionGeneration)) return;
  const reason = String(signal?.reason || "task_aborted");
  const code = reason === "lease_lost" ? "LEASE_LOST"
    : reason === "cancelled_by_user" ? "TASK_CANCELLED"
        : "TASK_ABORTED";
  throw codedError(code, `Esecuzione interrotta: ${reason}`, { reason });
}

function abortableSleep(ms, signal = taskAbortController?.signal) {
  const delay = Math.max(0, Number(ms || 0));
  if (signal?.aborted) return Promise.reject(codedError(String(signal.reason) === "lease_lost" ? "LEASE_LOST" : "TASK_ABORTED", "Esecuzione interrotta durante l'attesa."));
  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      signal?.removeEventListener?.("abort", onAbort);
      resolve();
    }, delay);
    const onAbort = () => {
      clearTimeout(timeout);
      signal?.removeEventListener?.("abort", onAbort);
      const reason = String(signal?.reason || "task_aborted");
      reject(codedError(reason === "lease_lost" ? "LEASE_LOST" : reason === "cancelled_by_user" ? "TASK_CANCELLED" : "TASK_ABORTED", `Esecuzione interrotta: ${reason}`));
    };
    signal?.addEventListener?.("abort", onAbort, { once: true });
  });
}

async function cleanupTaskRuntime(state = {}, options = {}) {
  const tabId = Number(state?.tabId || 0);
  if (!tabId) return;
  const force = options.force === true;
  const cleanupFailures = [];
  if (force) {
    try { await forceCloseRuntimeSessions(tabId); }
    catch (error) { cleanupFailures.push({ stage: "force_close_runtime_sessions", error: serializeError(error) }); }
  }
  const activeTypes = force ? new Set() : await runtimeSessionTypesForTab(tabId).catch(() => new Set());

  // Page masks are task-scoped. A persisted marker makes restoration mandatory,
  // while tabs that were never masked do not acquire a new cleanup dependency.
  const tabRecord = await ownedTab(tabId).catch(() => null);
  if (tabRecord?.maskPendingRestore) {
    try {
      const restoredCount = await restoreDynamicMasks(tabId);
      const marker = await updateOwnedTab(tabId, { maskPendingRestore: false, maskRestoredAt: Date.now(), maskRestoredCount: restoredCount });
      if (!marker) throw codedError("mask_restore_state_missing", "Ripristino maschere eseguito ma stato persistente non aggiornabile.", { tabId, restoredCount });
    } catch (error) {
      cleanupFailures.push({ stage: "restore_dynamic_masks", error: serializeError(error) });
    }
  }

  if (!activeTypes.has("route")) {
    routeRules.delete(tabId);
    if (await debuggerAttached(tabId).catch(() => false)) {
      try { await debuggerCommandWithTimeout(tabId, "Fetch.disable", {}, 5_000, "task_runtime_cleanup"); }
      catch (error) { cleanupFailures.push({ stage: "fetch_disable", error: serializeError(error) }); }
    }
  }
  if (!activeTypes.size && await debuggerAttached(tabId).catch(() => false)) {
    try { await detachDebugger(tabId, "task_runtime_cleanup"); }
    catch (error) { cleanupFailures.push({ stage: "debugger_detach", error: serializeError(error) }); }
  }
  if (!activeTypes.size) { debuggerProtocolByTab.delete(tabId); intentionalDebuggerDetaches.delete(tabId); }
  automationInputUntilByTab.delete(tabId);
  const interactionTimer = pendingUserInteractionTimers.get(tabId);
  if (interactionTimer) clearTimeout(interactionTimer);
  pendingUserInteractionTimers.delete(tabId);
  if (!activeTypes.has("har")) networkBuffers.delete(tabId);
  consoleBuffers.delete(tabId); cdpEventBuffers.delete(tabId);
  if (!activeTypes.has("trace")) traceBuffers.delete(tabId);
  if (!activeTypes.has("video")) screencastBuffers.delete(tabId);
  downloadBuffers.delete(tabId); structuredNetworkPayloads.delete(tabId);
  gscCollectionGenerations.delete(tabId); gscRequestGenerations.delete(tabId);

  if (cleanupFailures.length) {
    const first = cleanupFailures[0]?.error || {};
    const code = cleanupFailures.length === 1 && first?.code ? String(first.code) : "TASK_RUNTIME_CLEANUP_FAILED";
    throw codedError(
      code,
      `Cleanup runtime fallito: ${String(first?.message || first?.code || "errore sconosciuto")}`,
      { tabId, failures: cleanupFailures },
    );
  }
}

async function restoreDynamicMasks(tabId) {
  const results = await chrome.scripting.executeScript({
    target: { tabId },
    func: () => {
      let restored = 0;
      for (const element of document.querySelectorAll("[data-prstudio-original-visibility]")) {
        element.style.visibility = element.dataset.prstudioOriginalVisibility || "";
        delete element.dataset.prstudioOriginalVisibility; restored += 1;
      }
      return restored;
    },
  });
  return Number(results?.[0]?.result || 0);
}

async function api(path, options = {}) {
  const config = await getConfig();
  if (!config?.apiBase || !config?.token) throw new Error("Estensione non associata");
  const siteOrigin = normalizeSiteUrl(config.siteUrl || config.apiBase);
  const apiBase = new URL(String(config.apiBase));
  if (apiBase.origin !== new URL(siteOrigin).origin) throw codedError("api_origin_mismatch", "L'endpoint API memorizzato non appartiene all'origine associata.");
  normalizeSiteUrl(apiBase.origin);
  const requestUrl = new URL(`${apiBase.href.replace(/\/$/, "")}${String(path || "")}`);
  if (requestUrl.origin !== apiBase.origin) throw codedError("api_request_origin_mismatch", "La richiesta API tentava di uscire dall'origine associata.");
  const timeoutMs = Math.max(1_000, Math.min(120_000, Number(options.timeoutMs || API_DEFAULT_TIMEOUT_MS)));
  const controller = new AbortController();
  let timedOut = false;
  const parentSignal = options.signal;
  const onParentAbort = () => controller.abort(parentSignal?.reason || "parent_aborted");
  if (parentSignal?.aborted) onParentAbort();
  else parentSignal?.addEventListener?.("abort", onParentAbort, { once: true });
  const timer = setTimeout(() => { timedOut = true; controller.abort("api_timeout"); }, timeoutMs);
  let response;
  try {
    response = await fetch(requestUrl.href, {
      method: options.method || "GET",
      headers: { "Authorization": `Bearer ${config.token}`, "Content-Type": "application/json", ...(options.headers || {}) },
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: controller.signal, cache: "no-store", credentials: "omit", redirect: "error",
    });
  } catch (error) {
    if (timedOut) throw codedError("API_TIMEOUT", `Richiesta API oltre ${timeoutMs} ms.`, { path: String(path || ""), timeoutMs });
    throw error;
  } finally {
    clearTimeout(timer);
    parentSignal?.removeEventListener?.("abort", onParentAbort);
  }
  if (response.redirected || new URL(response.url || requestUrl.href).origin !== apiBase.origin) throw codedError("api_redirect_forbidden", "Redirect o cambio origine rifiutato dall'API Browser Agent.");
  const data = await promiseWithTimeout(
    response.json().catch(() => ({})),
    Math.min(15_000, timeoutMs),
    () => {
      controller.abort("api_body_timeout");
      return codedError("API_BODY_TIMEOUT", "Corpo risposta API oltre il limite.", { path: String(path || ""), timeoutMs: Math.min(15_000, timeoutMs) });
    }
  );
  if (!response.ok) {
    const error = new Error(data?.message || `HTTP ${response.status}`);
    error.status = response.status;
    const retryAfter = Number(response.headers.get("Retry-After") || 0);
    error.retryAfterMs = retryAfter > 0 ? retryAfter * 1000 : 0;
    if (response.status === 401) { error.code = "AUTH_EXPIRED"; await markAuthExpired(error.message); }
    else if (response.status === 403) { error.code = "FORBIDDEN"; }
    else if (response.status === 429) { error.code = "RATE_LIMITED"; }
    throw error;
  }
  return data;
}

async function manualCleanupRuntime() {
  requestPollingStop("manual_cleanup");
  try { taskAbortController?.abort("manual_cleanup"); } catch {}
  taskAbortController = null;
  taskExecutionGeneration += 1;
  localExecutionGeneration += 1;
  const transientKeys = [
    STORAGE_KEYS.ACTIVE, STORAGE_KEYS.LOGS, STORAGE_KEYS.LOG_QUEUE,
    STORAGE_KEYS.LOCAL_ACTIVE, STORAGE_KEYS.LOCAL_RECORDER,
    STORAGE_KEYS.LOCAL_INSPECTOR, STORAGE_KEYS.LOCAL_FLIGHT,
    STORAGE_KEYS.RUNTIME_SESSIONS, STORAGE_KEYS.SENSITIVE_STATES
  ].filter(Boolean);
  await chrome.storage.local.remove(transientKeys);
  networkBuffers.clear(); consoleBuffers.clear(); cdpEventBuffers.clear();
  traceBuffers.clear(); screencastBuffers.clear(); downloadBuffers.clear();
  structuredNetworkPayloads.clear(); routeRules.clear();
  await setBadge("OK", "#176b32").catch(() => {});
  startPolling().catch(logError);
  return { ok:true, cleared:{ actions:true, logs:true, loops:true }, preserved:{ pairing:true, workflows:true, schedules:true, workspaces:true, baselines:true, originProfiles:true } };
}

async function statusPayload() {
  const [config, active, logs, agentWindowId, tabs] = await Promise.all([getConfig(), getActiveTask(), getLogs(), getAgentWindowId(), listOwnedTabs()]);
  const safeActive = redactObservation(active, { console: true, limits: { maxStringLength: 8192, maxArrayLength: 100, maxObjectKeys: 100 } }).value;
  const safeTabs = redactObservation(tabs, { limits: { maxStringLength: 4096, maxArrayLength: 100, maxObjectKeys: 100 } }).value;
  return {
    paired: Boolean(config?.token),
    authExpired: Boolean(config?.authExpired),
    lastAuthError: config?.lastAuthError || null,
    deviceId: config?.deviceId || null,
    serverCapabilities: config?.serverCapabilities || null,
    localStudioVersion: LOCAL_STUDIO_VERSION,
    localStandaloneMode: true,
    siteUrl: config?.siteUrl || null,
    active: safeActive,
    pollLoopRunning,
    suiteVersion: SUITE_VERSION,
    executorProtocolVersion: EXECUTOR_PROTOCOL_VERSION,
    protocolVersion: EXECUTOR_PROTOCOL_VERSION,
    observationSecurityVersion: OBSERVATION_SECURITY_VERSION,
    observationTrust: "untrusted_web_content",
    taskPhase: active?.phase || null,
    heartbeatAt: active?.lastHeartbeatAt || null,
    authChallenge: safeActive?.authChallenge || null,
    agentWindowId,
    hostWindowId: agentWindowId,
    windowMode: "existing_normal_window",
    ownedTabs: safeTabs,
    logs: logs.slice(-20),
  };
}

async function markAuthExpired(message) {
  // Called from inside api()/the poll loop on HTTP 401: request stop without awaiting
  // the loop itself, otherwise the caller would deadlock waiting for its own finally.
  requestPollingStop("auth_expired");
  const config = await getConfig();
  if (config) {
    config.authExpired = true;
    config.lastAuthError = String(message || "Token scaduto");
    await chrome.storage.local.set({ [STORAGE_KEYS.CONFIG]: config });
  }
  await setBadge("KEY", "#a32020");
}

async function getConfig() {
  return (await chrome.storage.local.get(STORAGE_KEYS.CONFIG))[STORAGE_KEYS.CONFIG] || null;
}
async function getActiveTask() {
  return (await chrome.storage.local.get(STORAGE_KEYS.ACTIVE))[STORAGE_KEYS.ACTIVE] || null;
}
async function saveActiveTask(state) {
  await chrome.storage.local.set({ [STORAGE_KEYS.ACTIVE]: state });
}
async function clearActiveTask() {
  await chrome.storage.local.remove(STORAGE_KEYS.ACTIVE);
}

async function getLogs() {
  return (await chrome.storage.local.get(STORAGE_KEYS.LOGS))[STORAGE_KEYS.LOGS] || [];
}
async function appendLog(type, payload) {
  const safePayload = redactObservation(payload, { console: true, limits: { maxStringLength: 8192, maxArrayLength: 100, maxObjectKeys: 100 } });
  const entry = { type: String(type || 'extension.event').slice(0, 191), payload: safePayload.value, redactionCount: safePayload.redactionCount, truncated: safePayload.truncated, at: Date.now() };
  const stored = await chrome.storage.local.get([STORAGE_KEYS.LOGS, STORAGE_KEYS.LOG_QUEUE]);
  const logs = Array.isArray(stored?.[STORAGE_KEYS.LOGS]) ? stored[STORAGE_KEYS.LOGS] : [];
  const queue = Array.isArray(stored?.[STORAGE_KEYS.LOG_QUEUE]) ? stored[STORAGE_KEYS.LOG_QUEUE] : [];
  logs.push(entry);
  queue.push(entry);
  await chrome.storage.local.set({
    [STORAGE_KEYS.LOGS]: logs.slice(-200),
    [STORAGE_KEYS.LOG_QUEUE]: queue.slice(-500),
  });
  scheduleRemoteLogFlush();
}

function scheduleRemoteLogFlush() {
  if (remoteLogFlushTimer) return;
  remoteLogFlushTimer = setTimeout(() => {
    remoteLogFlushTimer = null;
    flushRemoteLogs().catch(() => {});
  }, 1500);
}

async function flushRemoteLogs() {
  if (remoteLogFlushRunning) return;
  const config = await getConfig().catch(() => null);
  if (!config?.apiBase || !config?.token || config?.authExpired) return;
  remoteLogFlushRunning = true;
  try {
    const stored = await chrome.storage.local.get(STORAGE_KEYS.LOG_QUEUE);
    const queue = Array.isArray(stored?.[STORAGE_KEYS.LOG_QUEUE]) ? stored[STORAGE_KEYS.LOG_QUEUE] : [];
    if (!queue.length) return;
    const batch = queue.slice(0, 50);
    try {
      await api('/logs', { method: 'POST', body: { events: batch } });
    } catch { return; }
    const latest = await chrome.storage.local.get(STORAGE_KEYS.LOG_QUEUE);
    const current = Array.isArray(latest?.[STORAGE_KEYS.LOG_QUEUE]) ? latest[STORAGE_KEYS.LOG_QUEUE] : [];
    await chrome.storage.local.set({ [STORAGE_KEYS.LOG_QUEUE]: current.slice(batch.length) });
    if (current.length > batch.length) scheduleRemoteLogFlush();
  } finally {
    remoteLogFlushRunning = false;
  }
}
async function setBadge(text, color) {
  await chrome.action.setBadgeText({ text: String(text).slice(0, 5) }).catch(() => {});
  await chrome.action.setBadgeBackgroundColor({ color }).catch(() => {});
}
function approximateJsonBytes(value) {
  try { return new TextEncoder().encode(JSON.stringify(value)).length; } catch { return 1024; }
}
function trimArrayByApproxBytes(values, maxItems, maxBytes, sizeOf = approximateJsonBytes) {
  const source = Array.isArray(values) ? values : [];
  const retained = [];
  let total = 0;
  for (const item of [...source].reverse()) {
    const size = Math.max(1, Number(sizeOf(item) || 1));
    if (retained.length >= Math.max(1, Number(maxItems || 1)) || total + size > Math.max(1, Number(maxBytes || 1))) continue;
    retained.push(item);
    total += size;
  }
  return retained.reverse();
}
function pushBuffer(map, key, value, limit = 1000, maxBytes = EVENT_BUFFER_MAX_BYTES) {
  const values = map.get(key) || [];
  let stateMap = bufferSizeState.get(map);
  if (!stateMap) { stateMap = new Map(); bufferSizeState.set(map, stateMap); }
  let state = stateMap.get(key);
  if (!state || state.count !== values.length) {
    const sizes = values.map(approximateJsonBytes);
    state = { sizes, total: sizes.reduce((sum, size) => sum + size, 0), count: values.length };
  }
  const size = approximateJsonBytes(value);
  values.push(value);
  state.sizes.push(size);
  state.total += size;
  const maxItems = Math.max(1, Number(limit || 1000));
  const byteBudget = Math.max(64_000, Number(maxBytes || EVENT_BUFFER_MAX_BYTES));
  while (values.length > maxItems || state.total > byteBudget) {
    values.shift();
    state.total -= Number(state.sizes.shift() || 0);
  }
  state.count = values.length;
  stateMap.set(key, state);
  map.set(key, values);
}
function normalizeSiteUrl(value) {
  // Same reason as validateNavigationUrl: "miosito.it" is what a person types.
  // It used to throw a bare TypeError, and the side panel showed the operator
  // "Errore: Invalid URL" for a missing "https://".
  const input = parseUserUrl(value);
  if (!input) {
    throw codedError("site_url_invalid", `Indirizzo del sito non valido: ${describeUrlInput(value, null)}. Esempio: miosito.it oppure https://miosito.it`);
  }
  const url = input.url;
  if (url.username || url.password) throw codedError("site_credentials_forbidden", "L'URL di pairing non può contenere credenziali.");
  const loopback = ["localhost", "127.0.0.1", "[::1]", "::1"].includes(String(url.hostname || "").toLowerCase());
  if (url.protocol !== "https:" && !(url.protocol === "http:" && loopback)) {
    throw codedError("pairing_https_required", "Il pairing richiede HTTPS; HTTP è consentito solo su localhost.");
  }
  return url.origin;
}
function serializeError(error) {
  return {
    name: error?.name || "Error",
    code: error?.code || "runtime_error",
    message: String(error?.message || error),
    details: error?.details || null,
    stack: String(error?.stack || "").slice(0, 4000),
  };
}
function logError(error) {
  appendLog("runtime.error", serializeError(error)).catch(() => {});
}
function sleep(ms) {
  return abortableSleep(ms, taskAbortController?.signal);
}

// MV3 evaluates this module again after an idle termination. onStartup is only a
// browser-profile startup event, so wake reconciliation must also run on every
// service-worker incarnation. The routines are idempotent and storage-backed.
queueMicrotask(() => {
  reconcileRuntimeSessionsAfterRestart().catch(logError);
  recoverLocalExecution().catch(logError);
  reconcileAgentOwnership().catch(logError);
  startPolling().catch(logError);
  flushRemoteLogs().catch(() => {});
});

// Test surface: pure/runtime primitives only. It is inert in Chrome and does not expose data to web pages.
export const __test = Object.freeze({
  capabilities,
  localDebugCapture,
  localResponsiveMatrix,
  localSiteScan,
  localDebuggerAttach,
  recoverSavedTask,
  runLocalScheduledCheck,
  crawlWorkerPage,
  validateNavigationUrl,
  urlMatches,
  stepRequiresOwnedTab,
  ensureAgentWindow,
  getAgentWindowId,
  registerOwnedTab,
  updateOwnedTab,
  unregisterOwnedTab,
  clearTabRegistry,
  releaseLaneTabs,
  ownedTab,
  assertOwnedTab,
  listOwnedTabs,
  resolveTabId,
  progressiveScroll,
  captureScreenshot,
  captureElementScreenshot,
  promiseWithTimeout,
  startPolling,
  stopPolling,
  debuggerDetachWithTimeout,
  runtimeSessionTypesForTab,
  reconcileRuntimeSessionsAfterRestart,
  pruneRuntimeSessions,
  boundedRuntimeTimeout,
  cleanupTaskRuntime,
  getActiveTask,
  waitForExternalAuthChallenge,
  executeKnownContractAction,
  runtimeFramesForTab,
  aggregateRuntimeDomVersion,
  snapshotAcrossRuntimeFrames,
  locateAcrossRuntimeFrames,
  armFrameAutoAttach,
  locateViaAccessibilityCdp,
  quadBox,
  codedError,
});
