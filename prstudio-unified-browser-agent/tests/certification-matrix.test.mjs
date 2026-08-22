import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

import {
  candidateTabUrl,
  provisionalOwnershipState,
  migrateTabReplacementRecord,
  migrateTabReplacementState,
  tabBindingCompatibility,
} from "../lib/tab-ownership.js";
import { parseUserUrl } from "../lib/url-input.js";
import {
  canonicalBrowserAction,
  actionToSteps,
  validateCustomSteps,
  normalizeUrlForEvidence,
  assessUserUrlRegex,
  testUserUrlRegex,
  matchLiteralWildcard,
  compareUrlEvidence,
} from "../lib/protocol.js";
import { DEBUGGER_PROTOCOL_CANDIDATES, attachWithProtocolFallback } from "../lib/cdp-protocol.js";

const here = path.dirname(fileURLToPath(import.meta.url));
const manifest = JSON.parse(fs.readFileSync(path.join(here, "..", "manifest.json"), "utf8"));

// Certification principle: every generated subtest below has a unique runtime
// input/state tuple and an explicit oracle. The matrices are intentionally over
// production modules, not a fake Chrome API, and complement (not replace) the
// real-Chrome remote gates.

const manifestChecks = [
  ["manifest v3", () => manifest.manifest_version === 3],
  ["semantic version present", () => /^\d+\.\d+\.\d+$/.test(manifest.version)],
  ["minimum Chrome version numeric", () => /^\d+$/.test(manifest.minimum_chrome_version)],
  ["minimum Chrome supports MV3 production floor", () => Number(manifest.minimum_chrome_version) >= 120],
  ["module service worker", () => manifest.background?.type === "module"],
  ["bootstrap service worker", () => manifest.background?.service_worker === "service-worker-bootstrap.js"],
  ["action title", () => typeof manifest.action?.default_title === "string" && manifest.action.default_title.length > 0],
  ["side panel path", () => manifest.side_panel?.default_path === "sidepanel.html"],
  ["all urls host permission", () => manifest.host_permissions?.includes("<all_urls>")],
  ["content script all urls", () => manifest.content_scripts?.[0]?.matches?.includes("<all_urls>")],
  ["content script document start", () => manifest.content_scripts?.[0]?.run_at === "document_start"],
  ["content script all frames", () => manifest.content_scripts?.[0]?.all_frames === true],
  ["content script match about blank", () => manifest.content_scripts?.[0]?.match_about_blank === true],
  ["content script isolated world", () => manifest.content_scripts?.[0]?.world === "ISOLATED"],
  ["content script reconnect first", () => manifest.content_scripts?.[0]?.js?.[0] === "lib/reconnect-backoff.js"],
  ["content script dirty notifier second", () => manifest.content_scripts?.[0]?.js?.[1] === "lib/runtime-dirty-notifier.js"],
  ["content script page runtime last", () => manifest.content_scripts?.[0]?.js?.at(-1) === "page-runtime.js"],
  ["no optional permission drift", () => !Array.isArray(manifest.optional_permissions) || manifest.optional_permissions.length === 0],
  ["no legacy background scripts", () => !Array.isArray(manifest.background?.scripts)],
  ["no unsafe inline background page", () => !manifest.background?.page],
];
for (const [label, predicate] of manifestChecks) {
  test(`manifest invariant: ${label}`, () => assert.equal(Boolean(predicate()), true));
}
for (const permission of ["storage", "alarms", "tabs", "scripting", "debugger", "activeTab", "downloads", "webNavigation", "sidePanel", "tabGroups", "system.display", "notifications"]) {
  test(`manifest permission declared: ${permission}`, () => assert.equal(manifest.permissions.includes(permission), true));
}
for (const relative of [manifest.background.service_worker, manifest.side_panel.default_path, ...manifest.content_scripts[0].js]) {
  test(`manifest referenced resource exists: ${relative}`, () => assert.equal(fs.existsSync(path.join(here, "..", relative)), true));
}

const hosts = [
  "example.com", "www.example.com", "api.example.com", "sub.domain.example", "localhost",
  "127.0.0.1", "search.google.com", "trends.google.com", "idealmarket1987.com", "xn--bcher-kva.example",
];
const suffixes = [
  "", "/", "/alpha", "/a/b", "/?q=one", "/path?x=1&y=2#frag",
];
for (const host of hosts) {
  for (const suffix of suffixes) {
    for (const explicit of [false, true]) {
      const raw = `${explicit ? "https://" : ""}${host}${suffix}`;
      test(`url parse canonical ${explicit ? "explicit" : "bare"} ${host}${suffix || "<root>"}`, () => {
        const parsed = parseUserUrl(raw);
        assert.ok(parsed instanceof URL);
        assert.equal(parsed.protocol, "https:");
        assert.equal(parsed.hostname, host === "localhost" || host === "127.0.0.1" ? host : new URL(`https://${host}`).hostname);
      });
    }
  }
}
const portCases = [80, 81, 443, 444, 3000, 38889, 8080, 8082, 9000, 65535];
for (const port of portCases) {
  test(`url parse localhost explicit port ${port}`, () => {
    const parsed = parseUserUrl(`localhost:${port}/health`);
    assert.ok(parsed);
    assert.equal(parsed.hostname, "localhost");
    assert.equal(Number(parsed.port || (port === 443 ? 443 : port === 80 ? 80 : 0)), port);
  });
}
const invalidInputs = [
  "", "   ", "not a host", "two words.example", ".", "..", "...", "http://", "https://", "://bad",
  "exa mple.com", "example .com", " example .com ", "[", "]", "{}", "?query-only", "#fragment-only", "/relative-only",
  "\\windows\\path", "http://[::1", "https://?x", "https://#x", "localhost port", "host:bad-port", "host:-1", "host:99999",
  "\u0000", "\n", "\t",
];
for (const [index, raw] of invalidInputs.entries()) {
  test(`url invalid input ${index + 1}: ${JSON.stringify(raw)}`, () => assert.equal(parseUserUrl(raw), null));
}

const ownerLanes = ["", "lane-a", "lane-b"];
const requestedLanes = ["", "lane-a", "lane-b", "lane-c"];
const ownerTasks = ["", "task-a", "task-b"];
const requestedTasks = ["", "task-a", "task-c"];
for (const ownerLane of ownerLanes) {
  for (const requestedLane of requestedLanes) {
    for (const ownerTask of ownerTasks) {
      for (const requestedTask of requestedTasks) {
        const scenario = `ownerLane=${ownerLane || "none"},requestLane=${requestedLane || "none"},ownerTask=${ownerTask || "none"},requestTask=${requestedTask || "none"}`;
        test(`ownership compatibility ${scenario}`, () => {
          const result = tabBindingCompatibility({ laneId: ownerLane, taskId: ownerTask }, { laneId: requestedLane, taskId: requestedTask });
          const laneConflict = Boolean(ownerLane && requestedLane && ownerLane !== requestedLane);
          assert.equal(result.ok, !laneConflict);
          if (laneConflict) {
            assert.equal(result.code, "tab_lane_conflict");
            return;
          }
          const taskChanged = Boolean(ownerTask && requestedTask && ownerTask !== requestedTask);
          const effectiveLane = ownerLane || requestedLane;
          const expectedMode = taskChanged
            ? (effectiveLane ? "lane_task_rebind" : "session_task_rebind")
            : (effectiveLane ? "lane_owned" : "session_owned");
          assert.equal(result.mode, expectedMode);
        });
      }
    }
  }
}

const committedCandidates = ["about:blank", "chrome://newtab/", "", "https://example.com/a", "http://localhost:8080/a"];
const pendingCandidates = ["", "https://pending.example/a", "http://127.0.0.1:8080/p", "chrome://settings/", "data:text/plain,x"];
for (const committed of committedCandidates) {
  for (const pending of pendingCandidates) {
    test(`ownership candidate committed=${committed || "empty"} pending=${pending || "empty"}`, () => {
      const fallback = "https://fallback.example/path";
      const actual = candidateTabUrl({ url: committed, pendingUrl: pending }, fallback);
      const expected = /^https?:/i.test(committed) ? committed : (/^https?:/i.test(pending) ? pending : fallback);
      assert.equal(actual, expected);
      const state = provisionalOwnershipState({ url: committed, pendingUrl: pending }, { url: fallback, provisional: true });
      assert.equal(state.candidateUrl, expected);
      assert.equal(state.provisional, !/^https?:/i.test(committed) && /^https?:/i.test(expected));
    });
  }
}

const replacementUrls = ["https://example.com/one", "https://search.google.com/two", "http://localhost:8080/three", "about:blank"];
for (const removedId of [7, 11, 23, 41]) {
  for (const addedId of [101, 202, 303]) {
    for (const [urlIndex, url] of replacementUrls.entries()) {
      test(`tab replacement removed=${removedId} added=${addedId} urlVariant=${urlIndex}`, () => {
        const now = 1700000000000 + removedId * 100 + addedId + urlIndex;
        const fallbackUrl = `https://fallback.example/${removedId}/${addedId}`;
        const record = { tabId: removedId, windowId: 2, url: fallbackUrl, title: "before", laneId: "lane-a", taskId: "task-old", provisional: false };
        const addedTab = { id: addedId, windowId: 9, url, pendingUrl: url === "about:blank" ? `https://pending.example/${addedId}` : "", title: `after-${urlIndex}` };
        const migrated = migrateTabReplacementRecord(record, addedTab, removedId, now);
        assert.equal(migrated.tabId, addedId);
        assert.equal(migrated.replacedFromTabId, removedId);
        assert.equal(migrated.laneId, "lane-a");
        assert.equal(migrated.taskId, "task-old");
        assert.equal(migrated.updatedAt, now);
        const state = migrateTabReplacementState({
          registry: { [removedId]: record, 999: { tabId: 999, laneId: "lane-z" } },
          affinityTasks: { a: { tabId: removedId }, b: { tabId: 999 } },
          lastTabId: removedId,
          activeTask: { taskId: "task-old", tabId: removedId },
          runtimeSessions: { s1: { tabId: removedId }, s2: { tabId: 999 } },
        }, addedTab, removedId, now);
        assert.equal(state.registry[String(removedId)], undefined);
        assert.equal(state.registry[String(addedId)].tabId, addedId);
        assert.equal(state.affinityTasks.a.tabId, addedId);
        assert.equal(state.affinityTasks.a.reason, "tab_replaced");
        assert.equal(state.affinityTasks.b.tabId, 999);
        assert.equal(state.lastTabId, addedId);
        assert.equal(state.activeTask.tabId, addedId);
        assert.equal(state.runtimeSessions.s1.interruptedByTabReplacement, true);
        assert.equal(state.runtimeSessions.s2.tabId, 999);
      });
    }
  }
}

const evidenceHosts = ["example.com", "search.google.com", "trends.google.com", "idealmarket1987.com", "localhost:8080"];
const evidencePaths = ["/", "/alpha", "/a/b", "/wp-admin/", "/search?q=term"];
for (const host of evidenceHosts) {
  for (const pathValue of evidencePaths) {
    const actual = `https://${host}${pathValue}`;
    test(`url evidence exact normalized ${host}${pathValue}`, () => {
      const result = compareUrlEvidence(actual, actual);
      assert.equal(result.matched, true);
      assert.equal(result.matchStrategy, "normalized_exact");
    });
    test(`url evidence wildcard host ${host}${pathValue}`, () => {
      const result = compareUrlEvidence(actual, `https://${host}*`);
      assert.equal(result.matched, true);
      assert.equal(result.matchStrategy, "anchored_wildcard");
    });
    test(`url evidence substring path ${host}${pathValue}`, () => {
      const token = pathValue === "/" ? host : pathValue.split(/[?]/)[0];
      const result = compareUrlEvidence(actual, token);
      assert.equal(result.matched, true);
      assert.equal(result.matchStrategy, "literal_substring");
    });
  }
}
const normalizationCases = [
  ["HTTPS://EXAMPLE.COM:443/a", "https://example.com/a"],
  ["http://EXAMPLE.COM:80/a", "http://example.com/a"],
  ["https://user:pass@example.com/a", "https://example.com/a"],
  ["https://Example.Com/a?x=1#z", "https://example.com/a?x=1#z"],
  ["not-a-url", "not-a-url"],
];
for (const [raw, expected] of normalizationCases) {
  test(`url evidence normalization ${raw}`, () => assert.equal(normalizeUrlForEvidence(raw), expected));
}
const safeRegexes = ["example", "^https://example\\.com", "path[0-9]", "a?b", "a{1}", "a{1,3}", "^[a-z]{1,8}$", "https://[^/]+/a"];
for (const pattern of safeRegexes) {
  test(`safe user regex accepted: ${pattern}`, () => assert.equal(assessUserUrlRegex(pattern).safe, true));
}
const unsafeRegexes = ["", "(a)", "a|b", "(a|b)", "a+b+", "a*b*", "(.*)", "a{1,999}", "a{3,1}", "a{1,}", "a\\1", "[abc", "abc\\", "a{", "a{foo}"];
for (const pattern of unsafeRegexes) {
  test(`unsafe user regex rejected: ${JSON.stringify(pattern)}`, () => assert.equal(assessUserUrlRegex(pattern).safe, false));
}
for (const [actual, pattern, expected] of [
  ["https://example.com/a", "https://*.com/*", true],
  ["https://example.com/a", "http://*.com/*", false],
  ["abcXYZdef", "abc*def", true],
  ["abcXYZ", "abc*def", false],
  ["prefix-middle-suffix", "prefix*middle*suffix", true],
  ["prefix-X-suffix", "prefix*middle*suffix", false],
  ["same", "same", true],
  ["same", "different", false],
]) {
  test(`wildcard evidence ${actual} vs ${pattern}`, () => assert.equal(matchLiteralWildcard(actual, pattern), expected));
}
for (const [actual, pattern, expected] of [
  ["https://example.com/a", "^https://example\\.com", true],
  ["https://example.com/a", "^http://example\\.com", false],
  ["abc123", "^[a-z]{1,8}[0-9]{1,3}$", true],
  ["123abc", "^[a-z]{1,8}[0-9]{1,3}$", false],
]) {
  test(`regex execution ${actual} vs ${pattern}`, () => assert.equal(testUserUrlRegex(actual, pattern).matched, expected));
}

const aliasCases = [
  ["puppeteer_screenshot", "playwright_screenshot_page"],
  ["puppeteer_screenshot_element", "playwright_screenshot_element"],
  ["puppeteer_new_page", "playwright_new_page"],
  ["puppeteer_page_screenshot", "playwright_screenshot_page"],
  ["puppeteer_click", "playwright_click"],
  ["puppeteer_fill", "playwright_fill"],
  ["puppeteer_type", "playwright_type"],
  ["puppeteer_press", "playwright_press"],
  ["puppeteer_goto", "playwright_goto"],
  ["playwright_click", "playwright_click"],
];
for (const [input, expected] of aliasCases) {
  test(`canonical browser action ${input}`, () => assert.equal(canonicalBrowserAction(input), expected));
}

const directActions = [
  "playwright_status", "playwright_evaluate", "playwright_new_page", "playwright_close_page", "playwright_list_pages",
  "playwright_adopt_tabs", "playwright_release_lane_tabs", "local_studio_run", "playwright_goto", "playwright_reload",
  "playwright_go_back", "playwright_go_forward", "playwright_wait_for_load_state", "playwright_wait_for_url", "playwright_wait_for_selector",
  "playwright_click", "playwright_double_click", "playwright_hover", "playwright_focus", "playwright_blur", "playwright_fill",
  "playwright_type", "playwright_press", "playwright_pointer_sequence", "playwright_keyboard_sequence", "playwright_select_option",
  "playwright_check", "playwright_uncheck", "playwright_scroll", "playwright_tap", "playwright_content", "playwright_locator_snapshot",
  "playwright_dom_snapshot", "playwright_accessibility_snapshot", "playwright_screenshot_page", "playwright_screenshot_element", "playwright_pdf",
  "playwright_network_idle_report", "playwright_console_report", "playwright_page_errors", "playwright_find_elements", "playwright_observation_bundle",
  "browser_observation_bundle", "playwright_social_snapshot", "social_snapshot", "playwright_dialog_accept", "playwright_dialog_dismiss",
  "playwright_set_geolocation", "playwright_set_locale", "playwright_set_timezone", "playwright_set_user_agent", "playwright_set_viewport",
  "playwright_set_color_scheme", "playwright_set_reduced_motion", "playwright_set_offline", "playwright_set_extra_headers", "playwright_emulate_media",
  "playwright_emulate_device", "playwright_throttle_cpu", "playwright_throttle_network", "playwright_cdp_send", "playwright_service_workers",
  "playwright_accessibility_scan", "playwright_core_web_vitals", "playwright_visual_assert", "playwright_capture_baseline", "playwright_compare_baseline",
  "playwright_verify_dom_change", "playwright_verify_pixel_change", "screenshot", "accessibility_tree", "dom_snapshot", "query_selector",
  "computed_styles", "inspect", "headers", "network_log", "console_log", "verify_url", "verify_visual",
];
for (const [index, action] of directActions.entries()) {
  test(`protocol action maps to executable steps ${index + 1}: ${action}`, () => {
    const args = {
      tab_id: 44,
      url: "https://example.com/a",
      selector: "#target",
      text: "value",
      value: "value",
      key: "Enter",
      method: "Runtime.evaluate",
      params: { expression: "1+1" },
      latitude: 41.9,
      longitude: 12.5,
      locale: "it-IT",
      timezone: "Europe/Rome",
      width: 1280,
      height: 720,
      rate: 2,
    };
    const steps = actionToSteps(action, args);
    assert.ok(Array.isArray(steps));
    assert.ok(steps.length >= 1);
    assert.equal(typeof steps.at(-1).type, "string");
  });
}

const customStepCases = [
  [{ type: "wait", ms: -1 }, "wait", 0],
  [{ type: "wait", ms: 1 }, "wait", 1],
  [{ type: "wait", ms: 60000 }, "wait", 60000],
  [{ type: "wait", ms: 90000 }, "wait", 60000],
  [{ type: "wait_load", timeout: -1 }, "wait_load", 0],
  [{ type: "wait_load", timeout: 1 }, "wait_load", 1],
  [{ type: "wait_load", timeout: 120000 }, "wait_load", 120000],
  [{ type: "wait_load", timeout: 999999 }, "wait_load", 120000],
  [{ action: "playwright_double_click", selector: "#a" }, "click", null],
  [{ action: "playwright_go_back" }, "history", null],
  [{ action: "playwright_go_forward" }, "history", null],
  [{ action: "playwright_uncheck", selector: "#c" }, "check", null],
  [{ action: "playwright_evaluate", args: { script: "1" } }, "javascript_exec", null],
];
for (const [index, [input, expectedType, expectedTimeout]] of customStepCases.entries()) {
  test(`custom step normalization boundary ${index + 1}`, () => {
    const [step] = validateCustomSteps([input]);
    assert.equal(step.type, expectedType);
    if (expectedTimeout !== null) {
      assert.equal(step.type === "wait" ? step.ms : step.timeoutMs, expectedTimeout);
    }
  });
}
for (const [index, bad] of [null, [], 1, "x", {}, { type: "unknown" }].entries()) {
  test(`custom step invalid shape ${index + 1}`, () => assert.throws(() => validateCustomSteps([bad])));
}

// CDP negotiation is pure orchestration over chrome.debugger.attach; the real
// Chrome gate separately proves this against the installed browser target.
test("CDP protocol candidate is exactly production 1.3", () => assert.deepEqual([...DEBUGGER_PROTOCOL_CANDIDATES], ["1.3"]));
for (const index of Array.from({ length: 20 }, (_, i) => i + 1)) {
  test(`CDP incompatible attach preserves distinct error ${index}`, async () => {
    const message = `Protocol error variant ${index}`;
    const debuggerApi = { attach: async () => { throw new Error(message); } };
    await assert.rejects(
      () => attachWithProtocolFallback(debuggerApi, { tabId: 1000 + index }, async () => false),
      (error) => error?.code === "cdp_protocol_incompatible" && error?.details?.errors?.[0]?.message === message,
    );
  });
}
for (const [index, message] of [
  "Already attached", "already attached to this target", "Another debugger is already attached", "ANOTHER DEBUGGER IS ALREADY ATTACHED",
  "already ATTACHED", "Another debugger is already attached to the tab", "already attached: target", "Another debugger is already attached.",
].entries()) {
  test(`CDP existing attachment recovery ${index + 1}`, async () => {
    const debuggerApi = { attach: async () => { throw new Error(message); } };
    const result = await attachWithProtocolFallback(debuggerApi, { tabId: 2000 + index }, async () => true);
    assert.equal(result.ok, true);
    assert.equal(result.alreadyAttached, true);
    assert.equal(result.protocolVersion, "1.3");
  });
}
for (const index of Array.from({ length: 8 }, (_, i) => i + 1)) {
  test(`CDP ambiguous attached state hard-fails ${index}`, async () => {
    const debuggerApi = { attach: async () => { throw new Error(`permission-like-${index}`); } };
    await assert.rejects(
      () => attachWithProtocolFallback(debuggerApi, { tabId: 3000 + index }, async () => true),
      (error) => error?.code === "cdp_attach_state_ambiguous",
    );
  });
}
for (const index of Array.from({ length: 8 }, (_, i) => i + 1)) {
  test(`CDP unverifiable attach state hard-fails ${index}`, async () => {
    const debuggerApi = { attach: async () => { throw new Error(`attach-${index}`); } };
    await assert.rejects(
      () => attachWithProtocolFallback(debuggerApi, { tabId: 4000 + index }, async () => { throw new Error(`state-${index}`); }),
      (error) => error?.code === "cdp_attach_state_unverified" && error?.details?.stateError === `state-${index}`,
    );
  });
}
