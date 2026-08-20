// PR STUDIO ONE-GUARD INVARIANT: Anti-Crash is the only mutation guard. Verification/risk/telemetry never block an executable action.
import { validateCdpCommand } from "./policy.js";
import { isSensitiveRuntimeContractAction } from "./runtime-capabilities.js";

export const TASK_STATUS = Object.freeze({
  QUEUED: "queued",
  LEASED: "leased",
  RUNNING: "running",
  COMPLETED: "completed",
  FAILED: "failed",
  CANCELLED: "cancelled",
  EXPIRED: "expired",
});

export function createRuntimeState(task) {
  return {
    taskId: task.task_uuid,
    leaseToken: task.lease_token,
    action: task.action,
    arguments: task.arguments || {},
    stepIndex: Number(task.step_index || 0),
    checkpoint: task.checkpoint || { last_completed_step: -1 },
    tabId: task.checkpoint?.last_result?.tabId || null,
    startedAt: Date.now(),
  };
}

export function resumableState(saved, serverTask) {
  if (!saved || saved.taskId !== serverTask.task_uuid) return createRuntimeState(serverTask);
  return {
    ...saved,
    leaseToken: serverTask.lease_token,
    stepIndex: Number(serverTask.step_index || saved.stepIndex || 0),
    checkpoint: serverTask.checkpoint || saved.checkpoint,
  };
}

function tabArg(args) {
  return Number(args.tab_id || args.tabId || 0) || undefined;
}

export function normalizeUrlForEvidence(value) {
  const raw = String(value || "").trim();
  if (!raw) return "";
  try {
    const url = new URL(raw);
    url.username = "";
    url.password = "";
    url.hostname = url.hostname.toLowerCase();
    if ((url.protocol === "https:" && url.port === "443") || (url.protocol === "http:" && url.port === "80")) url.port = "";
    return url.href;
  } catch {
    return raw;
  }
}

const MAX_URL_REGEX_PATTERN_LENGTH = 256;
const MAX_URL_REGEX_SUBJECT_LENGTH = 8192;
const MAX_URL_REGEX_QUANTIFIERS = 8;

export function assessUserUrlRegex(patternInput) {
  const pattern = String(patternInput || "");
  if (!pattern || pattern.length > MAX_URL_REGEX_PATTERN_LENGTH) return { safe: false, reason: pattern ? "pattern_too_large" : "empty_pattern" };
  let escaped = false;
  let inClass = false;
  let quantifiers = 0;
  let unbounded = 0;
  for (let i = 0; i < pattern.length; i += 1) {
    const ch = pattern[i];
    if (escaped) {
      if (!inClass && /[1-9]/.test(ch)) return { safe: false, reason: "backreference_forbidden" };
      escaped = false;
      continue;
    }
    if (ch === "\\") { escaped = true; continue; }
    if (inClass) { if (ch === "]") inClass = false; continue; }
    if (ch === "[") { inClass = true; continue; }
    if (ch === "(" || ch === ")" || ch === "|") return { safe: false, reason: "group_or_alternation_forbidden" };
    if (ch === "*" || ch === "+") {
      quantifiers += 1;
      unbounded += 1;
      if (unbounded > 1) return { safe: false, reason: "multiple_unbounded_quantifiers" };
    } else if (ch === "?") {
      quantifiers += 1;
    } else if (ch === "{") {
      const close = pattern.indexOf("}", i + 1);
      if (close < 0) return { safe: false, reason: "invalid_bounded_quantifier" };
      const body = pattern.slice(i + 1, close);
      const match = /^(\d{1,3})(?:,(\d{1,3}))?$/.exec(body);
      if (!match) return { safe: false, reason: "unbounded_or_invalid_brace_quantifier" };
      const min = Number(match[1]);
      const max = match[2] === undefined ? min : Number(match[2]);
      if (max < min || max > 128) return { safe: false, reason: "bounded_quantifier_too_large" };
      quantifiers += 1;
      i = close;
    }
    if (quantifiers > MAX_URL_REGEX_QUANTIFIERS) return { safe: false, reason: "too_many_quantifiers" };
  }
  if (escaped || inClass) return { safe: false, reason: "unterminated_escape_or_class" };
  try { new RegExp(pattern); } catch { return { safe: false, reason: "invalid_regular_expression" }; }
  return { safe: true, reason: "safe_subset" };
}

export function testUserUrlRegex(actualInput, patternInput) {
  const actual = String(actualInput || "");
  const pattern = String(patternInput || "");
  if (actual.length > MAX_URL_REGEX_SUBJECT_LENGTH) return { matched: false, safe: false, reason: "subject_too_large" };
  const assessment = assessUserUrlRegex(pattern);
  if (!assessment.safe) return { matched: false, ...assessment };
  return { matched: new RegExp(pattern).test(actual), ...assessment };
}

export function matchLiteralWildcard(actualInput, patternInput) {
  const actual = String(actualInput || "");
  const pattern = String(patternInput || "");
  if (!pattern.includes("*")) return actual === pattern;
  const parts = pattern.split("*");
  let cursor = 0;
  if (parts[0] && !actual.startsWith(parts[0])) return false;
  cursor = parts[0].length;
  for (let i = 1; i < parts.length - 1; i += 1) {
    if (!parts[i]) continue;
    const at = actual.indexOf(parts[i], cursor);
    if (at < 0) return false;
    cursor = at + parts[i].length;
  }
  const last = parts.at(-1) || "";
  if (last) {
    const at = actual.indexOf(last, cursor);
    if (at < 0 || (!pattern.endsWith("*") && !actual.endsWith(last))) return false;
  }
  return true;
}

export function compareUrlEvidence(actualInput, expectedInput) {
  const actual = String(actualInput || "").trim();
  const expected = String(expectedInput || "").trim();
  const normalizedActual = normalizeUrlForEvidence(actual);
  const normalizedExpected = normalizeUrlForEvidence(expected);
  if (!expected) return { actual, expected, normalizedActual, normalizedExpected, matched: false, matchStrategy: "missing_expected_url" };
  if (expected.startsWith("/") && expected.endsWith("/") && expected.length > 2) {
    const regex = testUserUrlRegex(actual, expected.slice(1, -1));
    return { actual, expected, normalizedActual, normalizedExpected, matched: regex.matched, matchStrategy: regex.safe ? "regular_expression" : "unsafe_regular_expression", regexSafetyReason: regex.reason };
  }
  if (expected.includes("*")) {
    const matched = matchLiteralWildcard(actual, expected) || matchLiteralWildcard(normalizedActual, expected);
    return { actual, expected, normalizedActual, normalizedExpected, matched, matchStrategy: "anchored_wildcard" };
  }
  let expectedIsAbsolute = false;
  try { expectedIsAbsolute = ["http:", "https:"].includes(new URL(expected).protocol); } catch { /* substring contract below */ }
  const matched = expectedIsAbsolute ? normalizedActual === normalizedExpected : actual.includes(expected);
  return { actual, expected, normalizedActual, normalizedExpected, matched, matchStrategy: expectedIsAbsolute ? "normalized_exact" : "literal_substring" };
}


export function canonicalBrowserAction(action) {
  const value = String(action || "").trim();
  if (!value) return value;
  if (value === "puppeteer_screenshot") return "playwright_screenshot_page";
  if (value === "puppeteer_screenshot_element") return "playwright_screenshot_element";
  if (value === "puppeteer_new_page") return "playwright_new_page";
  if (value === "puppeteer_page_screenshot") return "playwright_screenshot_page";
  if (value.startsWith("puppeteer_")) return `playwright_${value.slice("puppeteer_".length)}`;
  return value;
}

function selectorArgs(args) {
  return {
    tabId: tabArg(args),
    targetRef: args.target_ref || args.targetRef || "",
    selector: args.selector || "",
    selectorType: args.selector_type || args.selectorType || "auto",
    text: args.text || "",
    role: args.role || "",
    name: args.name || args.accessible_name || "",
    label: args.label || "",
    xpath: args.xpath || "",
    coordinates: args.coordinates || null,
    expectedUrl: args.expected_url || args.expectedUrl || "",
    expectedOrigin: args.expected_origin || args.expectedOrigin || "",
    postcondition: args.postcondition || args.verify || null,
  };
}

const CUSTOM_STEP_TYPES = new Set([
  "agent_status", "ensure_page", "open_tab", "close_tab", "list_tabs", "navigate", "reload", "history",
  "wait_load", "wait_url", "wait_selector", "click", "hover", "focus", "blur", "fill", "type_text", "press",
  "select", "check", "extract_text", "dom_snapshot", "page_snapshot", "computed_styles", "scroll",
  "accessibility_snapshot", "screenshot", "screenshot_element", "pdf", "network_report", "console_report", "page_errors", "headers",
  "cdp", "emulation", "service_workers", "accessibility_scan", "core_web_vitals", "capture_baseline",
  "compare_baseline", "visual_assert", "verify_pixel", "verify_dom", "dialog", "ocr", "search_console",
  "verify_url", "contract_action", "native_input", "observation_bundle", "find_elements", "social_snapshot", "adopt_tabs", "release_lane_tabs", "local_studio", "wait",
  "perception_frame",
  "webmcp_discover",
  "webmcp_invoke",
  "design_audit",
  "motion_audit",
  "javascript_exec",
]);


const BATCH_ACTION_TYPE_ALIASES = Object.freeze({
  playwright_new_page: "open_tab",
  playwright_goto: "navigate",
  playwright_reload: "reload",
  playwright_go_back: "history",
  playwright_go_forward: "history",
  playwright_click: "click",
  playwright_double_click: "click",
  playwright_hover: "hover",
  playwright_focus: "focus",
  playwright_blur: "blur",
  playwright_fill: "fill",
  playwright_type: "type_text",
  playwright_press: "press",
  playwright_select_option: "select",
  playwright_check: "check",
  playwright_uncheck: "check",
  playwright_content: "extract_text",
  playwright_dom_snapshot: "dom_snapshot",
  playwright_locator_snapshot: "observation_bundle",
  playwright_find_elements: "find_elements",
  playwright_accessibility_snapshot: "accessibility_snapshot",
  playwright_screenshot_page: "screenshot",
  playwright_screenshot_element: "screenshot_element",
  playwright_scroll: "scroll",
  playwright_wait_for_selector: "wait_selector",
  playwright_wait_for_url: "wait_url",
  playwright_wait_for_load_state: "wait_load",
  playwright_evaluate: "javascript_exec",
});

function normalizeCustomStepInput(input = {}) {
  if (!input || typeof input !== "object" || Array.isArray(input)) return input;
  if (input.type) return { ...input };
  const requested = canonicalBrowserAction(input.action || input.operation || "");
  if (!requested) return { ...input };
  const args = input.arguments && typeof input.arguments === "object" && !Array.isArray(input.arguments)
    ? input.arguments
    : input.args && typeof input.args === "object" && !Array.isArray(input.args)
      ? input.args
      : {};
  const directType = CUSTOM_STEP_TYPES.has(requested) ? requested : BATCH_ACTION_TYPE_ALIASES[requested];
  if (!directType) return { ...input };
  const step = { ...args, ...input, type: directType };
  delete step.action; delete step.operation; delete step.arguments; delete step.args;
  if (requested === "playwright_double_click") step.clickCount = Number(step.clickCount || 2);
  if (requested === "playwright_go_back") step.direction = "back";
  if (requested === "playwright_go_forward") step.direction = "forward";
  if (requested === "playwright_uncheck") step.checked = false;
  if (requested === "playwright_evaluate") step.risk = "identity";
  return step;
}

function boundedTimeout(value, fallback = 30000, max = 120000) {
  const candidate = Number(value ?? fallback);
  return Math.max(0, Math.min(max, Number.isFinite(candidate) ? candidate : fallback));
}

export function validateCustomSteps(steps = []) {
  if (!Array.isArray(steps) || !steps.length) throw new Error("custom_steps_required");
  if (steps.length > 250) throw new Error("custom_steps_limit_exceeded");
  const serialized = JSON.stringify(steps);
  if (serialized.length > 1_000_000) throw new Error("custom_steps_payload_too_large");
  return steps.map((input, index) => {
    if (!input || typeof input !== "object" || Array.isArray(input)) throw new Error(`custom_step_invalid:${index}`);
    const normalized = normalizeCustomStepInput(input);
    const step = { ...normalized, tabId: normalized.tabId || normalized.tab_id };
    if (!CUSTOM_STEP_TYPES.has(String(step.type || ""))) throw new Error(`custom_step_type_forbidden:${step.type || "missing"}`);
    if (step.type === "contract_action" && isSensitiveRuntimeContractAction(step.action)) {
      step.risk = "identity"; // identity-sensitive telemetry; callers cannot downgrade the classification.
    }
    if (step.type === "cdp") {
      const decision = validateCdpCommand(step.method, step.params || {}, "raw");
      if (!decision.ok) throw new Error(`${decision.code}:${decision.method}`);
    }
    if (["wait", "wait_load", "wait_url", "wait_selector"].includes(step.type)) {
      if (step.type === "wait") step.ms = boundedTimeout(step.ms, 1000, 60000);
      else step.timeoutMs = boundedTimeout(step.timeoutMs ?? step.timeout, 30000, 120000);
    }
    if (step.url && String(step.url).length > 8192) throw new Error(`custom_step_url_too_long:${index}`);
    return step;
  });
}

function pagePreparation(args = {}) {
  const url = String(args.url || args.expected_url || args.expectedUrl || "").trim();
  if (!url || tabArg(args)) return [];
  return [{
    type: "ensure_page",
    url,
    expectedOrigin: args.expected_origin || args.expectedOrigin || "",
    expectedUrl: url,
    waitUntil: args.wait_until || "interactive",
    timeoutMs: boundedTimeout(args.timeout_ms ?? args.timeout, 45000, 120000),
  }];
}

export function actionToSteps(action, args = {}) {
  action = canonicalBrowserAction(action);
  // High-reasoning fast path: one model decision can hand a bounded sequence
  // to the resident Browser Agent. Execution remains local and sequential; the
  // model is re-entered only after the flow completes or genuinely needs new
  // interpretation. This reuses the existing step validator and executor.
  if (action === "playwright_flow") return validateCustomSteps(args.steps || []);
  if (Array.isArray(args.steps)) throw new Error("custom_steps_disabled_v10");
  if (action.startsWith("search_console_")) {
    return [{ type: "search_console", mode: action.replace("search_console_", ""), ...args }];
  }
  if ((action === "playwright_screenshot_page" || action === "screenshot") && args.ocr) {
    return [{ type: "ocr", tabId: tabArg(args), language: args.ocr_language || "ita+eng" }];
  }

  const s = selectorArgs(args);
  const direct = {
    playwright_status: [{ type: "agent_status" }],
    playwright_evaluate: [{ type: "javascript_exec", tabId: tabArg(args), script: String(args.script || args.expression || ""), timeoutMs: boundedTimeout(args.timeout_ms ?? args.timeout, 30000, 120000), risk: "identity" }],
    playwright_new_page: args.url
      ? [{ type: "open_tab", url: args.url, expectedOrigin: args.expected_origin || "", waitUntil: args.wait_until || "interactive" }]
      : null,
    playwright_close_page: [{ type: "close_tab", tabId: tabArg(args) }],
    playwright_list_pages: [{ type: "list_tabs" }],
    playwright_adopt_tabs: [{ type: "adopt_tabs", tabIds: args.tab_ids || [], origin: args.origin || "", urlContains: args.url_contains || "", titleContains: args.title_contains || "", limit: Number(args.limit || 5), laneId: args._prstudio_lane_id || args.lane_id || "" }],
    playwright_release_lane_tabs: [{ type: "release_lane_tabs", laneId: args._prstudio_lane_id || args.lane_id || "" }],
    local_studio_run: [{ type: "local_studio", tabId: tabArg(args), operation: args.operation || "status", payload: args.payload || args, laneId: args._prstudio_lane_id || args.lane_id || "" }],
    playwright_goto: [{ type: "navigate", tabId: tabArg(args), url: args.url, expectedOrigin: args.expected_origin || "", waitUntil: args.wait_until || "interactive" }],
    playwright_reload: [{ type: "reload", tabId: tabArg(args) }],
    playwright_go_back: [{ type: "history", direction: "back", tabId: tabArg(args) }],
    playwright_go_forward: [{ type: "history", direction: "forward", tabId: tabArg(args) }],
    playwright_wait_for_load_state: [{ type: "wait_load", tabId: tabArg(args), state: args.state || args.load_state || "complete", timeoutMs: boundedTimeout(args.timeout, 30000, 120000), selector: args.selector || args.ready_selector || "" }],
    playwright_wait_for_url: [{ type: "wait_url", tabId: tabArg(args), url: args.url || args.pattern || "", timeoutMs: boundedTimeout(args.timeout, 30000, 120000) }],
    playwright_wait_for_selector: [{ type: "wait_selector", ...s, timeoutMs: boundedTimeout(args.timeout, 30000, 120000) }],
    // button and clickCount reach the input layer, which has always supported
    // them. Without this a right click (context menu) and a triple click
    // (select a field's whole value before replacing it) were unreachable.
    playwright_click: [{ type: "click", ...s, risk: args.risk, button: args.button || "left", clickCount: args.click_count || args.clickCount || 1 }],
    playwright_double_click: [{ type: "click", ...s, clickCount: 2, risk: args.risk }],
    playwright_hover: [{ type: "hover", ...s }],
    playwright_focus: [{ type: "focus", ...s }],
    playwright_blur: [{ type: "blur", ...s }],
    playwright_fill: [{ type: "fill", ...s, value: args.value ?? args.text ?? "" }],
    playwright_type: [{ type: "type_text", ...s, value: args.text ?? "", append: true }],
    playwright_press: [{ type: "press", ...s, key: args.key }],
    playwright_pointer_sequence: [{ type: "native_input", mode: "pointer_sequence", tabId: tabArg(args), events: args.events || args.sequence || [] }],
    playwright_keyboard_sequence: [{ type: "native_input", mode: "keyboard_sequence", tabId: tabArg(args), events: args.events || args.sequence || [] }],
    playwright_select_option: [{ type: "select", ...s, value: args.value ?? args.values ?? args.label ?? "" }],
    playwright_check: [{ type: "check", ...s, checked: true }],
    playwright_uncheck: [{ type: "check", ...s, checked: false }],
    playwright_scroll: [{ type: "scroll", tabId: tabArg(args), x: Number(args.x || args.delta_x || 0), y: Number(args.y || args.delta_y || args.amount || 0), to: args.to || args.position || "", progressive: Boolean(args.progressive || args.to === "bottom") }],
    playwright_tap: [{ type: "click", ...s, pointerType: "touch", risk: args.risk }],
    playwright_content: [{ type: "extract_text", ...s, selector: args.selector || "body" }],
    playwright_locator_snapshot: [{ type: "page_snapshot", tabId: tabArg(args), selector: args.selector || "", includeInteractive: true }],
    playwright_dom_snapshot: [{ type: "dom_snapshot", tabId: tabArg(args) }],
    playwright_accessibility_snapshot: [{ type: "accessibility_snapshot", tabId: tabArg(args) }],
    playwright_screenshot_page: [{ type: "screenshot", tabId: tabArg(args), fullPage: Boolean(args.full_page), lazyLoad: args.lazy_load !== false, format: args.format || "auto", quality: Number(args.quality || 82), maxPixels: Number(args.max_pixels || args.maxPixels || 28000000) }],
    playwright_screenshot_element: [{ type: "screenshot_element", ...s }],
    playwright_pdf: [{ type: "pdf", tabId: tabArg(args), landscape: Boolean(args.landscape), printBackground: args.print_background !== false }],
    playwright_network_idle_report: [{ type: "network_report", tabId: tabArg(args) }],
    playwright_console_report: [{ type: "console_report", tabId: tabArg(args) }],
    playwright_page_errors: [{ type: "page_errors", tabId: tabArg(args) }],
    playwright_find_elements: [{ type: "find_elements", tabId: tabArg(args), query: args.query || "", role: args.role || "", name: args.name || "", text: args.text || "", label: args.label || "", limit: args.limit || 20 }],
    playwright_observation_bundle: [{ type: "observation_bundle", tabId: tabArg(args), includeScreenshot: (args.include_screenshot ?? args.includeScreenshot) !== false, viewerOnly: Boolean(args.viewer_only ?? args.viewerOnly) }],
    browser_observation_bundle: [{ type: "observation_bundle", tabId: tabArg(args), includeScreenshot: Boolean(args.include_screenshot ?? args.includeScreenshot) }],
    playwright_social_snapshot: [{ type: "social_snapshot", tabId: tabArg(args), includeScreenshot: Boolean(args.include_screenshot ?? args.includeScreenshot) }],
    social_snapshot: [{ type: "social_snapshot", tabId: tabArg(args), includeScreenshot: Boolean(args.include_screenshot ?? args.includeScreenshot) }],
    playwright_dialog_accept: [{ type: "dialog", tabId: tabArg(args), accept: true, promptText: args.prompt_text || "" }],
    playwright_dialog_dismiss: [{ type: "dialog", tabId: tabArg(args), accept: false }],
    playwright_set_geolocation: [{ type: "emulation", tabId: tabArg(args), command: "geolocation", value: { latitude: Number(args.latitude), longitude: Number(args.longitude), accuracy: Number(args.accuracy || 1) } }],
    playwright_set_locale: [{ type: "emulation", tabId: tabArg(args), command: "locale", value: args.locale }],
    playwright_set_timezone: [{ type: "emulation", tabId: tabArg(args), command: "timezone", value: args.timezone || args.timezone_id }],
    playwright_set_user_agent: [{ type: "emulation", tabId: tabArg(args), command: "user_agent", value: args.user_agent || args.value }],
    playwright_set_viewport: [{ type: "emulation", tabId: tabArg(args), command: "viewport", value: { width: Number(args.width || 1280), height: Number(args.height || 720), deviceScaleFactor: Number(args.device_scale_factor || 1), mobile: Boolean(args.mobile) } }],
    playwright_set_color_scheme: [{ type: "emulation", tabId: tabArg(args), command: "media", value: { colorScheme: args.color_scheme || args.value || "light" } }],
    playwright_set_reduced_motion: [{ type: "emulation", tabId: tabArg(args), command: "media", value: { reducedMotion: args.reduced_motion || args.value || "reduce" } }],
    playwright_set_offline: [{ type: "emulation", tabId: tabArg(args), command: "offline", value: Boolean(args.offline ?? args.value) }],
    playwright_set_extra_headers: [{ type: "emulation", tabId: tabArg(args), command: "headers", value: args.headers || {} }],
    playwright_emulate_media: [{ type: "emulation", tabId: tabArg(args), command: "media", value: args }],
    playwright_emulate_device: [{ type: "emulation", tabId: tabArg(args), command: "device", value: args }],
    playwright_throttle_cpu: [{ type: "emulation", tabId: tabArg(args), command: "cpu", value: Number(args.rate || 1) }],
    playwright_throttle_network: [{ type: "emulation", tabId: tabArg(args), command: "network", value: args }],
    playwright_cdp_send: [{ type: "cdp", tabId: tabArg(args), method: args.method, params: args.params || {} }],
    playwright_service_workers: [{ type: "service_workers", tabId: tabArg(args) }],
    playwright_accessibility_scan: [{ type: "accessibility_scan", tabId: tabArg(args) }],
    playwright_core_web_vitals: [{ type: "core_web_vitals", tabId: tabArg(args) }],
    playwright_visual_assert: [{ type: "visual_assert", tabId: tabArg(args), expected: args.expected || args.baseline || "" }],
    playwright_capture_baseline: [{ type: "capture_baseline", tabId: tabArg(args), name: args.name || "default" }],
    playwright_compare_baseline: [{ type: "compare_baseline", tabId: tabArg(args), name: args.name || "default" }],
    playwright_verify_dom_change: [{ type: "verify_dom", tabId: tabArg(args), expected: args.expected || args }],
    playwright_verify_pixel_change: [{ type: "verify_pixel", tabId: tabArg(args), expected: args.expected || args }],
    screenshot: [{ type: "screenshot", tabId: tabArg(args), fullPage: Boolean(args.full_page), lazyLoad: args.lazy_load !== false, format: args.format || "auto", quality: Number(args.quality || 82), maxPixels: Number(args.max_pixels || args.maxPixels || 28000000) }],
    accessibility_tree: [{ type: "accessibility_snapshot", tabId: tabArg(args) }],
    dom_snapshot: [{ type: "dom_snapshot", tabId: tabArg(args) }],
    query_selector: [{ type: "page_snapshot", tabId: tabArg(args), selector: args.selector || "", includeInteractive: false }],
    computed_styles: [{ type: "computed_styles", ...s, properties: args.properties || [] }],
    inspect: [{ type: "page_snapshot", tabId: tabArg(args), includeInteractive: true }],
    headers: [{ type: "headers", tabId: tabArg(args) }],
    network_log: [{ type: "network_report", tabId: tabArg(args) }],
    console_log: [{ type: "console_report", tabId: tabArg(args) }],
    verify_url: [{ type: "verify_url", tabId: tabArg(args), url: args.url || args.expected_url || "" }],
    verify_visual: [{ type: "page_snapshot", tabId: tabArg(args), includeInteractive: true }],
  };

  if (direct[action]) {
    if (action === "playwright_new_page") return direct[action];
    if (action === "playwright_goto" && !tabArg(args) && args.url) return pagePreparation(args);
    const prep = pagePreparation(args);
    return prep.length ? [...prep, ...direct[action]] : direct[action];
  }
  if (action.startsWith("playwright_") || ["fetch", "create_visual_baseline", "visual_diff", "html_diff"].includes(action)) {
    if (["playwright_link_crawl", "playwright_sitemap_crawl"].includes(action)) return [{ type: "contract_action", action, args }];
    const prep = pagePreparation(args);
    return [...prep, { type: "contract_action", action, args, risk: isSensitiveRuntimeContractAction(action) ? "identity" : args.risk }];
  }
  throw new Error(`browser_provider_action_unsupported:${action}`);
}
