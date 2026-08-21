export const AUTH_CHALLENGE_SELECTORS = [
  "iframe[src*='recaptcha']",
  "iframe[src*='hcaptcha']",
  "iframe[src*='challenges.cloudflare.com']",
  ".g-recaptcha",
  ".h-captcha",
  "[data-sitekey]",
  "input[autocomplete='one-time-code']",
  "input[name*='otp' i]",
  "input[name*='verification' i]",
  "input[aria-label*='verification code' i]",
];

export const AUTH_CHALLENGE_TEXT = [
  "verify you are human",
  "verifica che sei umano",
  "i'm not a robot",
  "non sono un robot",
  "security key",
  "chiave di sicurezza",
  "use your passkey",
  "usa la passkey",
  "verification code",
  "codice di verifica",
  "check your phone",
  "controlla il telefono",
  "two-step verification",
  "verifica in due passaggi",
];

export const CRITICAL_TEXT = [
  "delete", "elimina", "remove account", "chiudi account",
  "refund", "rimborso", "cancel order", "annulla ordine",
  "publish", "pubblica", "send", "invia",
  "purchase", "acquista", "pay", "paga",
  "bank account", "conto bancario", "payment method", "metodo di pagamento",
  "budget", "password", "security settings", "impostazioni di sicurezza",
];

// Raw CDP is deliberately read-only. Internal executors use a distinct exact
// allowlist so a task cannot obtain an internal privilege merely by supplying
// the same method name in playwright_cdp_send or args.steps.
export const RAW_CDP_METHODS = Object.freeze([
  "Accessibility.getFullAXTree",
  "DOM.getBoxModel",
  "Page.getFrameTree",
  "Page.getLayoutMetrics",
  "Performance.getMetrics",
]);

export const INTERNAL_CDP_METHODS = Object.freeze([
  ...RAW_CDP_METHODS,
  "Browser.setDownloadBehavior",
  "CSS.enable",
  "CSS.disable",
  "CSS.startRuleUsageTracking",
  "CSS.stopRuleUsageTracking",
  "DOM.enable",
  "DOM.disable",
  "DOM.getDocument",
  "DOM.querySelector",
  "DOM.focus",
  "DOM.getNodeForLocation",
  "DOM.setFileInputFiles",
  "Emulation.setCPUThrottlingRate",
  "Emulation.setDeviceMetricsOverride",
  "Emulation.clearDeviceMetricsOverride",
  "Emulation.setEmulatedMedia",
  "Emulation.setGeolocationOverride",
  "Emulation.setLocaleOverride",
  "Emulation.setTimezoneOverride",
  "Emulation.setUserAgentOverride",
  "Fetch.continueRequest",
  "Fetch.disable",
  "Fetch.enable",
  "Fetch.failRequest",
  "Fetch.fulfillRequest",
  "Input.dispatchDragEvent",
  "Input.dispatchKeyEvent",
  "Input.dispatchMouseEvent",
  "Input.dispatchTouchEvent",
  "Input.insertText",
  "Log.enable",
  "Network.emulateNetworkConditions",
  "Network.enable",
  "Network.getResponseBody",
  "Network.setExtraHTTPHeaders",
  "Page.captureScreenshot",
  "Page.enable",
  "Page.handleJavaScriptDialog",
  "Page.getNavigationHistory",
  "Page.navigateToHistoryEntry",
  "Page.printToPDF",
  "Page.screencastFrameAck",
  "Page.startScreencast",
  "Page.stopScreencast",
  "Performance.enable",
  "Profiler.enable",
  "Profiler.disable",
  "Profiler.startPreciseCoverage",
  "Profiler.stopPreciseCoverage",
  "Profiler.takePreciseCoverage",
  "Runtime.enable",
  "Target.getTargets",
  "Target.setAutoAttach",
  "Tracing.end",
  "Tracing.start",
]);

const RAW_CDP_SET = new Set(RAW_CDP_METHODS);
const INTERNAL_CDP_SET = new Set(INTERNAL_CDP_METHODS);
const ALWAYS_BLOCKED_CDP = /^(?:Runtime\.(?:evaluate|callFunctionOn|compileScript|runScript)|Debugger\.evaluateOnCallFrame|Page\.(?:addScriptToEvaluateOnNewDocument|addScriptToEvaluateOnLoad|setBypassCSP|setDocumentContent|navigate)|DOM\.(?:setAttributeValue|setAttributesAsText|setNodeValue|setOuterHTML|removeNode)|Network\.(?:getAllCookies|getCookies|setCookie|setCookies|deleteCookies)|Storage\.(?:getCookies|setCookies|clearCookies|clearDataForOrigin))$/;
const RAW_BROWSER_DOMAIN = /^Browser\./;

function validateCdpParams(method, params = {}, scope = "raw") {
  if (!params || typeof params !== "object" || Array.isArray(params)) return "cdp_params_object_required";
  const serialized = JSON.stringify(params);
  if (serialized.length > 512_000) return "cdp_params_too_large";
  if (scope === "raw" && /(?:expression|script|source|postData|body|files|headers)/i.test(Object.keys(params).join(" "))) {
    return "raw_cdp_sensitive_params_forbidden";
  }
  if (method === "DOM.getDocument" && Number(params.depth ?? 1) < -1) return "cdp_depth_invalid";
  if (method === "Target.getTargets" && Object.keys(params).length) return "cdp_params_forbidden";
  if (method === "Browser.setDownloadBehavior") {
    if (scope !== "internal") return "cdp_method_forbidden";
    if (!params || params.behavior !== "allow" || params.eventsEnabled !== true) return "download_behavior_params_invalid";
    if (typeof params.downloadPath !== "string" || !params.downloadPath.trim()) return "download_path_required";
    const allowedKeys = new Set(["behavior", "downloadPath", "eventsEnabled", "browserContextId"]);
    if (Object.keys(params).some((key) => !allowedKeys.has(key))) return "download_behavior_params_invalid";
  }
  return null;
}

export function validateCdpCommand(method, params = {}, scope = "raw") {
  const candidate = String(method || "");
  if (!candidate || ALWAYS_BLOCKED_CDP.test(candidate) || (scope === "raw" && RAW_BROWSER_DOMAIN.test(candidate))) {
    return { ok: false, code: "cdp_method_forbidden", method: candidate, scope };
  }
  const allowed = scope === "internal" ? INTERNAL_CDP_SET : RAW_CDP_SET;
  if (!allowed.has(candidate)) return { ok: false, code: "cdp_method_not_allowlisted", method: candidate, scope };
  const paramsError = validateCdpParams(candidate, params, scope);
  if (paramsError) return { ok: false, code: paramsError, method: candidate, scope };
  return { ok: true, method: candidate, scope };
}


export function isMeaningfulAuthChallengeCandidate(candidate = {}) {
  const width = Number(candidate.width || 0);
  const height = Number(candidate.height || 0);
  const opacity = Number(candidate.opacity ?? 1);
  const visible = candidate.display !== "none"
    && candidate.visibility !== "hidden"
    && opacity > 0.05
    && width >= 40
    && height >= 20
    && candidate.inViewport !== false;
  if (!visible) return false;
  if (candidate.kind === "captcha_iframe") return width >= 120 && height >= 60;
  if (candidate.kind === "otp_input") return width >= 80 && height >= 20;
  return true;
}

export function shouldCheckAuthChallengeBefore(step = {}) {
  return !["open_tab", "wait", "screenshot", "ocr"].includes(String(step.type || ""));
}


export function shouldCheckAuthChallengeAfter(step = {}) {
  return ["navigate", "reload", "click", "fill", "press", "cdp"].includes(String(step.type || ""));
}

export function normalizeText(value = "") {
  return String(value).replace(/\s+/g, " ").trim().toLowerCase();
}

export function isCriticalAction(step = {}) {
  if (["financial", "destructive", "identity", "publish"].includes(step.risk)) return true;

  const type = String(step.type || "").toLowerCase();
  const action = String(step.action || step.type || "").toLowerCase();
  const searchConsoleMode = String(step.mode || step.action || "").toLowerCase();
  if (type === "search_console" && ["request_indexing", "search_console_request_indexing"].includes(searchConsoleMode)) return true;
  const readOnlyTypes = new Set([
    "agent_status", "list_tabs", "wait", "wait_load", "wait_url", "wait_selector",
    "screenshot", "screenshot_element", "ocr", "extract_text", "dom_snapshot",
    "page_snapshot", "accessibility_snapshot", "computed_styles", "network_report",
    "console_report", "page_errors", "headers", "service_workers", "core_web_vitals",
    "accessibility_scan", "observation_bundle", "search_console"
  ]);
  // Risk classification is telemetry only; it does not request operator approval.
  if (readOnlyTypes.has(type)) return false;

  const seen = new WeakSet();
  const flatten = (value, depth = 0) => {
    if (depth > 8 || value === null || value === undefined) return "";
    if (["string", "number", "boolean"].includes(typeof value)) return String(value);
    if (typeof value !== "object" || seen.has(value)) return "";
    seen.add(value);
    if (Array.isArray(value)) return value.slice(0, 100).map((item) => flatten(item, depth + 1)).join(" ");
    return Object.entries(value).slice(0, 200).map(([key, item]) => `${key} ${flatten(item, depth + 1)}`).join(" ");
  };
  const haystack = normalizeText(flatten(step));
  const hasKey = (value, matcher, depth = 0, seenKeys = new WeakSet()) => {
    if (!value || typeof value !== "object" || depth > 8 || seenKeys.has(value)) return false;
    seenKeys.add(value);
    if (Array.isArray(value)) return value.some((item) => hasKey(item, matcher, depth + 1, seenKeys));
    return Object.entries(value).some(([key, item]) => (matcher.test(key) && item !== null && item !== undefined && item !== "")
      || hasKey(item, matcher, depth + 1, seenKeys));
  };
  const method = String(step.args?.request?.method || step.request?.method || step.args?.method || step.method || "GET").toUpperCase();
  if (["DELETE", "PATCH", "PUT"].includes(method)) return true;
  if (step.type === "emulation" && /^(?:headers|geolocation)$/.test(String(step.command || ""))) return true;
  if (/upload|set_input_files|set_content|set_extra_headers|set_geolocation|set_permissions|clear_permissions|route|mock_response|modify_response|replay_har|abort_request|continue_request/.test(action)) return true;
  if (hasKey(step, /^(?:file|files|filePath|file_path|path)$/i)) return true;
  if (/fetch/.test(action) && method !== "GET" && method !== "HEAD") return true;
  if (/cdp/.test(action) || step.type === "cdp") return true;

  // Native keyboard/pointer gestures are not intrinsically critical. Escalate only when
  // their target/effect is materially sensitive or explicitly described as such.
  const sensitiveEffect = /(?:delete|remove|destroy|refund|payment|checkout|purchase|buy|send|publish|credential|password|token|permission|administrator|admin user|bank|iban|price change|bulk edit|install code)/i;
  if ((type === "native_input" || ["click", "press", "fill", "type_text", "select", "check"].includes(type))
      && (sensitiveEffect.test(haystack) || CRITICAL_TEXT.some((marker) => haystack.includes(marker)))) return true;
  return false;
}

export function isMutatingStep(step = {}) {
  const type = String(step.type || "");
  if (["click", "fill", "type_text", "press", "select", "check", "scroll", "navigate", "reload", "history", "dialog", "emulation", "native_input", "javascript_exec"].includes(type)) return true;
  if (type === "search_console" && String(step.mode || step.action || "").toLowerCase() === "request_indexing") return true;
  if (type === "contract_action") return isCriticalAction(step) || !/^(?:playwright_(?:wait_for_request|wait_for_response|cdp_subscribe|download_wait|stop_har|stop_trace|export_trace|stop_video|lighthouse_audit|responsive_matrix)|html_diff|visual_diff)$/.test(String(step.action || ""));
  return false;
}

export function isAllowedCdpMethod(method, params = {}, scope = "raw") {
  return validateCdpCommand(method, params, scope).ok;
}

export function computeBackoff(attempt, baseMs = 500, maxMs = 30000) {
  const bounded = Math.min(Math.max(0, attempt), 10);
  return Math.min(maxMs, baseMs * (2 ** bounded));
}
