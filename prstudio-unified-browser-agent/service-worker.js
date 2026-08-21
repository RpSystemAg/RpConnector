const CONFIG_KEY = "prstudioConfig";
const ACTIVE_KEY = "prstudioActiveTask";
const STATE_KEY = "prstudioMcpBridgeState";
const BRIDGE_BASE = "http://127.0.0.1:8765";
const EXECUTOR_PROTOCOL_VERSION = "3.0.0";
const LONG_POLL_SECONDS = 20;
const API_TIMEOUT_MS = 30_000;
const TASK_HEARTBEAT_MS = 10_000;

let polling = false;
let pollingAbort = null;
let currentTask = null;

chrome.runtime.onInstalled.addListener(() => {
  chrome.sidePanel?.setPanelBehavior?.({ openPanelOnActionClick: true }).catch(() => {});
  chrome.alarms.create("prstudio-reconnect", { periodInMinutes: 0.5 }).catch(() => {});
  chrome.alarms.create("prstudio-device-heartbeat", { periodInMinutes: 0.5 }).catch(() => {});
  recoverInterruptedTask().finally(() => startPolling().catch(recordError));
});

chrome.runtime.onStartup.addListener(() => {
  chrome.sidePanel?.setPanelBehavior?.({ openPanelOnActionClick: true }).catch(() => {});
  chrome.alarms.create("prstudio-reconnect", { periodInMinutes: 0.5 }).catch(() => {});
  chrome.alarms.create("prstudio-device-heartbeat", { periodInMinutes: 0.5 }).catch(() => {});
  recoverInterruptedTask().finally(() => startPolling().catch(recordError));
});

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === "prstudio-reconnect") startPolling().catch(recordError);
  if (alarm.name === "prstudio-device-heartbeat") heartbeatDevice().catch(recordError);
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  (async () => {
    switch (message?.type) {
      case "pair":
        return pairDevice(message.siteUrl, message.code, message.name);
      case "status":
        return statusPayload();
      case "unpair":
        return forgetPairing();
      case "start":
        await startPolling();
        return { ok: true };
      case "cancel":
        return cancelActiveTask();
      case "bridge_health":
        return bridgeHealth();
      default:
        return { ok: false, error: "unsupported_message" };
    }
  })().then(sendResponse).catch((error) => sendResponse({ ok: false, error: serializeError(error) }));
  return true;
});

function capabilities(bridge = null) {
  return {
    executorProtocolVersion: EXECUTOR_PROTOCOL_VERSION,
    runtimeOperationCount: 8,
    wordpressCapabilityCatalog: false,
    browserBackend: "official_mcp_bridge",
    browserControlCustom: false,
    mcpBridge: {
      endpoint: BRIDGE_BASE,
      online: Boolean(bridge?.ok),
      version: bridge?.version || null,
      providers: bridge?.providers || [
        "chrome_devtools",
        "chrome_webmcp",
        "puppeteer",
        "selenium"
      ]
    },
    nativeMcpCompatibility: [
      "chrome-devtools-mcp",
      "webmcp",
      "puppeteer-via-chrome-devtools-mcp",
      "selenium-webdriver"
    ]
  };
}

function normalizeSiteUrl(value) {
  const url = new URL(String(value || "").trim());
  if (!["https:", "http:"].includes(url.protocol)) throw new Error("URL WordPress non valido.");
  if (url.username || url.password) throw new Error("L'URL WordPress non può contenere credenziali.");
  url.hash = "";
  url.search = "";
  url.pathname = url.pathname.replace(/\/+$/, "");
  return url.href.replace(/\/$/, "");
}

async function getConfig() {
  const stored = await chrome.storage.local.get(CONFIG_KEY);
  return stored?.[CONFIG_KEY] || null;
}

async function pairDevice(siteUrl, code, name = "Chrome personale") {
  const normalized = normalizeSiteUrl(siteUrl);
  const pairingCode = String(code || "").trim();
  if (!pairingCode || pairingCode.length > 512) throw new Error("Codice pairing mancante o non valido.");
  const previous = await getConfig();
  const bridge = await bridgeHealth().catch(() => null);
  const response = await fetch(`${normalized}/wp-json/prstudio-unified/v1/pair`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      code: pairingCode,
      name: String(name || "Chrome personale").slice(0, 191),
      previous_device_id: previous?.deviceId || "",
      capabilities: capabilities(bridge)
    }),
    cache: "no-store",
    credentials: "omit",
    redirect: "error"
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data?.message || data?.error || "Pairing non riuscito.");
  if (!data?.api_base || !data?.token || !data?.device_id) throw new Error("Risposta pairing incompleta.");
  const apiBaseUrl = new URL(data.api_base, normalized);
  if (apiBaseUrl.origin !== new URL(normalized).origin) throw new Error("api_base fuori origine.");
  const accepted = new Set(Array.isArray(data.browser_executor_protocols_accepted) ? data.browser_executor_protocols_accepted.map(String) : []);
  if (data.browser_executor_protocol) accepted.add(String(data.browser_executor_protocol));
  if (accepted.size && !accepted.has(EXECUTOR_PROTOCOL_VERSION)) {
    throw new Error(`Protocollo executor incompatibile: ${[...accepted].join(", ")}`);
  }
  const config = {
    siteUrl: normalized,
    apiBase: apiBaseUrl.href.replace(/\/$/, ""),
    token: String(data.token),
    deviceId: String(data.device_id),
    pairedAt: Date.now(),
    authExpired: false,
    serverCapabilities: data.server_capabilities || previous?.serverCapabilities || null
  };
  await chrome.storage.local.set({ [CONFIG_KEY]: config });
  await setState({ paired: true, lastError: null, bridge });
  startPolling().catch(recordError);
  return { ok: true, deviceId: config.deviceId, apiBase: config.apiBase, bridge };
}

async function forgetPairing() {
  pollingAbort?.abort("unpaired");
  pollingAbort = null;
  polling = false;
  currentTask = null;
  await chrome.storage.local.remove([CONFIG_KEY, ACTIVE_KEY]);
  await setState({ paired: false, lastError: null });
  return { ok: true };
}

async function api(path, options = {}) {
  const config = await getConfig();
  if (!config?.apiBase || !config?.token || config.authExpired) throw new Error("Estensione non associata.");
  const base = new URL(config.apiBase);
  const requestUrl = new URL(`${config.apiBase.replace(/\/$/, "")}${String(path || "")}`);
  if (requestUrl.origin !== base.origin) throw new Error("Richiesta API fuori origine.");
  const controller = new AbortController();
  const parent = options.signal;
  const onAbort = () => controller.abort(parent?.reason || "aborted");
  parent?.addEventListener?.("abort", onAbort, { once: true });
  const timer = setTimeout(() => controller.abort("api_timeout"), Math.max(1000, Number(options.timeoutMs || API_TIMEOUT_MS)));
  let response;
  try {
    response = await fetch(requestUrl.href, {
      method: options.method || "GET",
      headers: {
        "Authorization": `Bearer ${config.token}`,
        "Content-Type": "application/json",
        ...(options.headers || {})
      },
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: controller.signal,
      cache: "no-store",
      credentials: "omit",
      redirect: "error"
    });
  } finally {
    clearTimeout(timer);
    parent?.removeEventListener?.("abort", onAbort);
  }
  const data = await response.json().catch(() => ({}));
  if (response.status === 401) {
    await chrome.storage.local.set({ [CONFIG_KEY]: { ...config, authExpired: true } });
  }
  if (!response.ok) {
    const error = new Error(data?.message || data?.error_description || data?.error || `HTTP ${response.status}`);
    error.code = data?.code || data?.error || `HTTP_${response.status}`;
    error.status = response.status;
    error.data = data;
    throw error;
  }
  return data;
}

async function bridgeHealth() {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort("bridge_timeout"), 2500);
  try {
    const response = await fetch(`${BRIDGE_BASE}/health`, { cache: "no-store", signal: controller.signal });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data?.error || `Bridge HTTP ${response.status}`);
    return data;
  } finally {
    clearTimeout(timer);
  }
}

async function bridgeCall(payload) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort("bridge_call_timeout"), 120_000);
  try {
    const response = await fetch(`${BRIDGE_BASE}/call`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      cache: "no-store",
      signal: controller.signal
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data?.error?.message || data?.error || `Bridge HTTP ${response.status}`);
      error.code = data?.error?.code || "MCP_BRIDGE_ERROR";
      error.data = data;
      throw error;
    }
    return data;
  } finally {
    clearTimeout(timer);
  }
}

async function heartbeatDevice() {
  const config = await getConfig();
  if (!config?.token || config.authExpired) return { skipped: true };
  const bridge = await bridgeHealth().catch((error) => ({ ok: false, error: serializeError(error) }));
  const result = await api("/device/heartbeat", {
    method: "POST",
    body: { capabilities: capabilities(bridge) },
    timeoutMs: 10_000
  });
  if (result?.server_capabilities) {
    await chrome.storage.local.set({ [CONFIG_KEY]: { ...config, serverCapabilities: result.server_capabilities } });
  }
  await setState({ lastHeartbeatAt: Date.now(), bridge, lastError: null });
  return result;
}

async function startPolling() {
  if (polling) return;
  const config = await getConfig();
  if (!config?.token || config.authExpired) return;
  polling = true;
  pollingAbort = new AbortController();
  const signal = pollingAbort.signal;
  try {
    while (!signal.aborted) {
      try {
        const payload = await api(`/tasks/next?wait=${LONG_POLL_SECONDS}`, {
          method: "GET",
          signal,
          timeoutMs: (LONG_POLL_SECONDS + 10) * 1000
        });
        if (payload?.task) await executeTask(payload.task);
        else await sleep(250);
      } catch (error) {
        if (signal.aborted) break;
        await recordError(error);
        await sleep(2000);
      }
    }
  } finally {
    polling = false;
    pollingAbort = null;
  }
}

async function executeTask(task) {
  const taskId = String(task?.task_uuid || "");
  const leaseToken = String(task?.lease_token || "");
  if (!taskId || !leaseToken) throw new Error("Task privo di task_uuid o lease_token.");
  currentTask = { taskId, leaseToken, action: String(task.action || ""), startedAt: Date.now() };
  await chrome.storage.local.set({ [ACTIVE_KEY]: currentTask });
  await api(`/tasks/${encodeURIComponent(taskId)}/running`, {
    method: "POST",
    body: { lease_token: leaseToken }
  });
  const heartbeat = setInterval(() => {
    api(`/tasks/${encodeURIComponent(taskId)}/heartbeat`, {
      method: "POST",
      body: { lease_token: leaseToken },
      timeoutMs: 8000
    }).catch(recordError);
  }, TASK_HEARTBEAT_MS);

  try {
    const request = normalizeTaskForBridge(task);
    const result = await bridgeCall(request);
    await api(`/tasks/${encodeURIComponent(taskId)}/complete`, {
      method: "POST",
      body: { lease_token: leaseToken, result }
    });
    await setState({ lastTask: { taskId, action: task.action, status: "completed", finishedAt: Date.now() }, lastError: null });
  } catch (error) {
    await api(`/tasks/${encodeURIComponent(taskId)}/fail`, {
      method: "POST",
      body: { lease_token: leaseToken, error: serializeError(error) },
      timeoutMs: 10_000
    }).catch(() => {});
    await recordError(error, { taskId, action: task.action });
  } finally {
    clearInterval(heartbeat);
    currentTask = null;
    await chrome.storage.local.remove(ACTIVE_KEY);
  }
}

function normalizeTaskForBridge(task) {
  const action = String(task?.action || "");
  const args = task?.arguments && typeof task.arguments === "object" ? task.arguments : {};
  if (action === "mcp_bridge_call") return {
    provider: String(args.provider || "chrome_devtools"),
    operation: String(args.operation || "tools/call"),
    tool: args.tool ? String(args.tool) : undefined,
    arguments: args.arguments && typeof args.arguments === "object" ? args.arguments : {}
  };

  const legacy = legacyTool(action, args);
  if (!legacy) {
    const error = new Error(`Azione browser legacy non supportata dopo la migrazione MCP: ${action}`);
    error.code = "LEGACY_BROWSER_ACTION_REMOVED";
    throw error;
  }
  return legacy;
}

function legacyTool(action, args) {
  const map = {
    playwright_new_page: "new_page",
    playwright_close_page: "close_page",
    playwright_goto: "navigate_page",
    playwright_go_back: "navigate_page",
    playwright_go_forward: "navigate_page",
    playwright_reload: "navigate_page",
    playwright_list_pages: "list_pages",
    playwright_locator_snapshot: "take_snapshot",
    playwright_dom_snapshot: "take_snapshot",
    playwright_accessibility_snapshot: "take_snapshot",
    playwright_click: "click",
    playwright_fill: "fill",
    playwright_type: "type_text",
    playwright_press: "press_key",
    playwright_hover: "hover",
    playwright_screenshot_page: "take_screenshot",
    playwright_screenshot_element: "take_screenshot",
    playwright_evaluate: "evaluate_script"
  };
  const tool = map[action];
  if (!tool) return null;
  const normalized = { ...args };
  delete normalized.sync_wait_seconds;
  delete normalized.device_id;
  delete normalized.lane_token;
  delete normalized.tab_id;
  if (action === "playwright_goto") Object.assign(normalized, { type: "url", url: args.url });
  if (action === "playwright_go_back") Object.assign(normalized, { type: "back" });
  if (action === "playwright_go_forward") Object.assign(normalized, { type: "forward" });
  if (action === "playwright_reload") Object.assign(normalized, { type: "reload" });
  if (args.target_ref && !normalized.uid) normalized.uid = args.target_ref;
  if (action === "playwright_fill" && args.text !== undefined && normalized.value === undefined) normalized.value = args.text;
  if (action === "playwright_press" && args.text !== undefined && normalized.key === undefined) normalized.key = args.text;
  return { provider: "chrome_devtools", operation: "tools/call", tool, arguments: normalized };
}

async function cancelActiveTask() {
  if (!currentTask?.taskId || !currentTask?.leaseToken) return { ok: true, cancelled: false };
  const task = currentTask;
  await api(`/tasks/${encodeURIComponent(task.taskId)}/cancel`, {
    method: "POST",
    body: { lease_token: task.leaseToken }
  }).catch(recordError);
  return { ok: true, cancelled: true, taskId: task.taskId };
}

async function recoverInterruptedTask() {
  const stored = await chrome.storage.local.get(ACTIVE_KEY);
  const active = stored?.[ACTIVE_KEY];
  if (!active?.taskId || !active?.leaseToken) return;
  await api(`/tasks/${encodeURIComponent(active.taskId)}/fail`, {
    method: "POST",
    body: {
      lease_token: active.leaseToken,
      error: {
        code: "MCP_BRIDGE_EXECUTION_INTERRUPTED",
        message: "Il service worker è stato riavviato durante una chiamata MCP; il task non viene ripetuto alla cieca."
      }
    },
    timeoutMs: 10_000
  }).catch(() => {});
  await chrome.storage.local.remove(ACTIVE_KEY);
}

async function statusPayload() {
  const config = await getConfig();
  const bridge = await bridgeHealth().catch((error) => ({ ok: false, error: serializeError(error) }));
  const state = (await chrome.storage.local.get(STATE_KEY))?.[STATE_KEY] || {};
  return {
    ok: true,
    paired: Boolean(config?.token),
    deviceId: config?.deviceId || null,
    siteUrl: config?.siteUrl || null,
    apiBase: config?.apiBase || null,
    authExpired: Boolean(config?.authExpired),
    executorProtocolVersion: EXECUTOR_PROTOCOL_VERSION,
    backend: "official_mcp_bridge",
    polling,
    activeTask: currentTask,
    bridge,
    state
  };
}

async function setState(patch) {
  const stored = await chrome.storage.local.get(STATE_KEY);
  const current = stored?.[STATE_KEY] || {};
  await chrome.storage.local.set({ [STATE_KEY]: { ...current, ...patch, updatedAt: Date.now() } });
}

async function recordError(error, extra = {}) {
  const serialized = serializeError(error);
  await setState({ lastError: { ...serialized, ...extra, at: Date.now() } }).catch(() => {});
  console.error("[PR STUDIO MCP bridge]", serialized, extra);
}

function serializeError(error) {
  if (!error) return { code: "UNKNOWN", message: "Unknown error" };
  return {
    code: String(error.code || error.name || "ERROR"),
    message: String(error.message || error),
    status: Number(error.status || 0) || undefined,
    data: error.data || undefined
  };
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}
