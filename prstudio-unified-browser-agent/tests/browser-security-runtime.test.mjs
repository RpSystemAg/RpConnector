import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import test from "node:test";
import path from "node:path";
import { fileURLToPath } from "node:url";

async function dataModule(relativePath, transform = (value) => value) {
  const source = transform(await readFile(new URL(relativePath, import.meta.url), "utf8"));
  return import(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);
}

const policy = await import(new URL("../lib/policy.js", import.meta.url));
const protocol = await import(new URL("../lib/protocol.js", import.meta.url));
const runtimeCapabilities = await import(new URL("../lib/runtime-capabilities.js", import.meta.url));
const nativeInput = await dataModule("../lib/native-input.js");
const recovery = await dataModule("../lib/recovery.js");
const tabOwnership = await import(new URL("../lib/tab-ownership.js", import.meta.url));
const resilience = await dataModule("../lib/resilience.js");
const enterpriseRuntime = await import(new URL("../lib/enterprise-runtime.js", import.meta.url));


test("agent-created tabs keep provisional ownership across about:blank and pendingUrl", () => {
  const initial = tabOwnership.provisionalOwnershipState(
    { id: 25, url: "about:blank", pendingUrl: "https://idealmarket1987.com/checkout/" },
    { url: "https://idealmarket1987.com/checkout/", provisional: true },
  );
  assert.equal(initial.candidateUrl, "https://idealmarket1987.com/checkout/");
  assert.equal(initial.provisional, true);
  assert.equal(initial.candidateHttp, true);
  const committed = tabOwnership.provisionalOwnershipState(
    { id: 25, url: "https://idealmarket1987.com/checkout/", pendingUrl: "" },
    { url: "https://idealmarket1987.com/checkout/", provisional: true },
  );
  assert.equal(committed.committedHttp, true);
  assert.equal(committed.provisional, false);
  assert.equal(tabOwnership.candidateTabUrl({ url: "about:blank" }, "https://example.com/"), "https://example.com/");
  assert.equal(tabOwnership.isHttpCandidate("chrome://settings/"), false);
});

test("raw CDP uses an exact read-only allowlist and separate internal privilege", () => {
  assert.equal(policy.validateCdpCommand("Page.getLayoutMetrics", {}, "raw").ok, true);
  assert.equal(policy.validateCdpCommand("Input.dispatchMouseEvent", {}, "raw").ok, false);
  assert.equal(policy.validateCdpCommand("Input.dispatchMouseEvent", { type: "mouseMoved", x: 1, y: 2 }, "internal").ok, true);
  for (const method of ["Runtime.evaluate", "Runtime.evaluate.extra", "Page.navigate", "DOM.setOuterHTML", "Network.setCookie", "Browser.getVersion"]) {
    assert.equal(policy.validateCdpCommand(method, {}, "raw").ok, false, method);
  }
  assert.equal(policy.validateCdpCommand("DOM.getOuterHTML", {}, "raw").ok, false);
  assert.equal(policy.validateCdpCommand("Target.getTargets", {}, "raw").ok, false);
  assert.equal(policy.validateCdpCommand("Page.getNavigationHistory", {}, "raw").ok, false);
  assert.equal(policy.validateCdpCommand("Page.getLayoutMetrics.evil", {}, "raw").ok, false);
  assert.equal(policy.validateCdpCommand("Page.getLayoutMetrics", [], "raw").ok, false);
});

test("sensitive runtime actions are operational with telemetry classification and no approval gate", () => {
  const evaluate = protocol.actionToSteps("playwright_evaluate", { expression: "document.title" });
  assert.equal(evaluate.at(-1).type, "javascript_exec");
  assert.equal(evaluate.at(-1).risk, "identity");
  assert.equal(runtimeCapabilities.hasRuntimeContractAction("playwright_evaluate"), true);
  assert.equal(runtimeCapabilities.isSensitiveRuntimeContractAction("playwright_get_cookies"), true);
  assert.equal(policy.isCriticalAction(evaluate.at(-1)), true);
  const custom = protocol.validateCustomSteps([{ type: "contract_action", action: "playwright_get_cookies", args: {} }]);
  assert.equal(custom[0].risk, "identity");
  assert.throws(() => protocol.actionToSteps("playwright_status", {
    steps: [{ type: "cdp", method: "Runtime.evaluate", params: { expression: "document.cookie" } }],
  }), /custom_steps_disabled_v10/);
  assert.throws(() => protocol.actionToSteps("playwright_status", {
    steps: [{ type: "contract_action", action: "playwright_get_cookies", args: {} }],
  }), /custom_steps_disabled_v10/);
  assert.throws(() => protocol.actionToSteps("playwright_status", {
    steps: [{ type: "cdp", method: "Page.getLayoutMetrics", params: {} }],
  }), /custom_steps_disabled_v10/);
});

test("critical and mutating classification inspects nested custom actions", () => {
  assert.equal(policy.isCriticalAction({ type: "contract_action", action: "fetch", args: { request: { method: "DELETE" } } }), true);
  assert.equal(policy.isCriticalAction({ type: "contract_action", action: "playwright_route", args: { body: { safe: true } } }), true);
  assert.equal(policy.isMutatingStep({ type: "native_input", mode: "pointer_sequence" }), true);
  assert.equal(policy.isCriticalAction({ type: "native_input", mode: "pointer_sequence", events: [{ type: "click", x: 1, y: 2 }] }), false);
  assert.equal(policy.isCriticalAction({ type: "native_input", mode: "keyboard_sequence", target: { text: "Pubblica" }, events: [{ type: "key", key: "Enter" }] }), true);
  assert.equal(policy.isCriticalAction({ type: "emulation", command: "headers", value: { "X-Test": "1" } }), true);
  assert.equal(policy.isCriticalAction({ type: "emulation", command: "geolocation", value: { latitude: 1, longitude: 2 } }), true);
  assert.equal(policy.isCriticalAction({ type: "contract_action", action: "playwright_upload_file", args: { path: "C:/private.txt" } }), true);
  assert.equal(policy.isMutatingStep({ type: "page_snapshot" }), false);
});

test("native pointer, drag, touch, wheel and keyboard build bounded exact CDP commands", () => {
  const pointer = nativeInput.pointerSequence([
    { type: "move", x: 10, y: 20 },
    { type: "down", x: 10, y: 20 },
    { type: "move", x: 30, y: 40 },
    { type: "up", x: 30, y: 40 },
    { type: "wheel", x: 30, y: 40, deltaY: 500 },
    { type: "touch_start", x: 2, y: 3 },
    { type: "touch_end", x: 2, y: 3 },
  ]);
  assert.deepEqual(new Set(pointer.map((item) => item.method)), new Set(["Input.dispatchMouseEvent", "Input.dispatchTouchEvent"]));
  assert.equal(pointer.find((item) => item.params.type === "mouseWheel").params.deltaY, 500);
  const drag = nativeInput.dragSequence({ x: 0, y: 0 }, { x: 100, y: 50 }, { steps: 4 });
  assert.equal(drag.at(1).params.type, "mousePressed");
  assert.equal(drag.at(-1).params.type, "mouseReleased");
  const keys = nativeInput.keyboardSequence([{ type: "press", key: "Control+A" }, { type: "text", text: "Canva" }]);
  assert.equal(keys[0].params.modifiers, 2);
  assert.equal(keys.at(-1).method, "Input.insertText");
});

test("mutating in-flight or committing work never replays after restart", async () => {
  const step = { type: "click", selector: "#publish" };
  const digest = await recovery.digestStep(step);
  const state = recovery.beginInFlightState({ taskId: "task-1" }, step, 2, digest, true);
  assert.equal(state.inFlight.digest, digest);
  assert.equal(recovery.recoveryDisposition(state).action, "uncertain_side_effect");
  const committing = recovery.markCommittingState(state, "result-digest");
  assert.equal(recovery.recoveryDisposition(committing).action, "uncertain_side_effect");
  const readOnly = recovery.beginInFlightState({ taskId: "task-2" }, { type: "page_snapshot" }, 0, "read", false);
  assert.equal(recovery.recoveryDisposition(readOnly).action, "resume_readonly");
});

test("sensitive browser executor is exact, telemetry-classified, and raw CDP remains blocked", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.match(worker, /SENSITIVE_CDP_METHODS = new Set/);
  for (const method of ["Runtime.evaluate", "Page.addScriptToEvaluateOnNewDocument", "Storage.getCookies", "Network.setCookies", "Browser.grantPermissions", "Browser.resetPermissions"]) {
    assert.match(worker, new RegExp(method.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
    assert.equal(policy.validateCdpCommand(method, {}, "raw").ok, false, method);
  }
  assert.match(worker, /stateId = await saveSensitiveBrowserState/);
  assert.doesNotMatch(worker, /LOCAL_APPROVALS:\s*"prstudioLocalApprovals"/);
  assert.doesNotMatch(worker, /consumeLocalApproval/);
  assert.doesNotMatch(worker, /critical_action_approval_required/);
});

test("service worker statically preserves pairing/protocol and checkpoint security order", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  const manifest = JSON.parse(await readFile(new URL("../COMPONENT-MANIFEST.json", import.meta.url), "utf8"));
  assert.equal(manifest.version, "17.0.0");
  assert.match(worker, /CONFIG:\s*"prstudioConfig"/);
  assert.match(worker, /wp-json\/prstudio-unified\/v1\/pair/);
  assert.match(worker, /credentials:\s*"omit"/);
  assert.match(worker, /redirect:\s*"error"/);
  assert.match(worker, /explicit_tab_not_owned/);
  assert.doesNotMatch(worker, /deletePreviousScreenshot/);
  assert.ok(worker.indexOf("secureResultForCheckpoint(state, step, result)") < worker.indexOf("`/tasks/${state.taskId}/checkpoint`"));
});


test("lane ownership survives task boundaries without re-adoption", () => {
  const compatible = tabOwnership.tabBindingCompatibility(
    { tabId: 31, laneId: "lane-a", taskId: "task-open" },
    { laneId: "lane-a", taskId: "task-click" },
  );
  assert.equal(compatible.ok, true);
  assert.equal(compatible.mode, "lane_task_rebind");

  const conflict = tabOwnership.tabBindingCompatibility(
    { tabId: 31, laneId: "lane-a", taskId: "task-open" },
    { laneId: "lane-b", taskId: "task-click" },
  );
  assert.equal(conflict.ok, false);
  assert.equal(conflict.code, "tab_lane_conflict");

  const tabs = [
    { tabId: 31, laneId: "lane-a", taskId: "task-open", updatedAt: 4000, url: "https://search.google.com/search-console" },
    { tabId: 32, laneId: "lane-b", taskId: "task-other", updatedAt: 5000, url: "https://search.google.com/search-console" },
  ];
  assert.equal(resilience.selectOwnedTabCandidate(tabs, { laneId: "lane-a", taskId: "task-click" }).tabId, 31);
  assert.equal(resilience.selectOwnedTabCandidate(tabs, { laneId: "lane-b", taskId: "task-click" }).tabId, 32);
});

test("agent tab creation claims about:blank before target navigation", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  const start = worker.indexOf("async function createOwnedAgentTab");
  const end = worker.indexOf("async function registerAdoptedTab", start);
  const body = worker.slice(start, end);
  assert.ok(start >= 0 && end > start);
  assert.ok(body.indexOf('chrome.tabs.create({ windowId, url: "about:blank", active: false })') < body.indexOf("registerOwnedTab(tabId"));
  assert.ok(body.indexOf("registerOwnedTab(tabId") < body.indexOf("chrome.tabs.update(tabId, { url, active: false })"));
  assert.match(body, /ownedBeforeNavigation:\s*true/);
});

test("browser batch compiles action+arguments without a syntax discovery round-trip", () => {
  const steps = protocol.validateCustomSteps([
    { action: "playwright_click", arguments: { role: "tab", name: "Giorni" } },
    { action: "playwright_content", arguments: { selector: "table" } },
    { action: "playwright_evaluate", arguments: { script: "document.title" } },
  ]);
  assert.equal(steps[0].type, "click");
  assert.equal(steps[0].role, "tab");
  assert.equal(steps[1].type, "extract_text");
  assert.equal(steps[2].type, "javascript_exec");
  assert.equal(steps[2].script, "document.title");
  const direct = protocol.actionToSteps("playwright_evaluate", { script: "document.title", tab_id: 31 });
  assert.equal(direct[0].type, "javascript_exec");
  assert.equal(direct[0].tabId, 31);
});

test("native pointer aliases normalize before exact CDP dispatch", () => {
  const commands = nativeInput.pointerSequence([
    { type: "pointerMove", x: 5, y: 6 },
    { type: "pointerDown", x: 5, y: 6 },
    { type: "pointerUp", x: 5, y: 6 },
    { type: "tap", x: 10, y: 12 },
    { type: "mouseWheel", x: 10, y: 12, deltaY: 220 },
  ]);
  assert.equal(commands[0].params.type, "mouseMoved");
  assert.equal(commands[1].params.type, "mousePressed");
  assert.equal(commands[2].params.type, "mouseReleased");
  assert.equal(commands.at(-1).params.type, "mouseWheel");
  assert.throws(() => nativeInput.pointerSequence([{ type: "teleport", x: 1, y: 2 }]), /pointer_event_invalid/);
});

test("remote self-healing only restarts from zero after two safe failures", async () => {
  const remoteRecovery = await dataModule("../lib/remote-recovery.js");
  const baseState = { taskId: "task-r", checkpoint: { last_completed_step: -1, fresh_restart_count: 0 } };
  assert.equal(remoteRecovery.canFreshRestart(baseState, { type: "scroll" }, { code: "STEP_NO_PROGRESS" }, 1), false);
  assert.equal(remoteRecovery.canFreshRestart(baseState, { type: "scroll" }, { code: "STEP_NO_PROGRESS" }, 2), true);
  assert.equal(remoteRecovery.canFreshRestart(baseState, { type: "native_input" }, new Error("pointer_event_invalid:pointerdownx"), 2), true);
  assert.equal(remoteRecovery.canFreshRestart({ ...baseState, checkpoint: { last_completed_step: 0 } }, { type: "scroll" }, { code: "STEP_NO_PROGRESS" }, 2), false);
  assert.equal(remoteRecovery.canFreshRestart({ ...baseState, checkpoint: { last_completed_step: -1, fresh_restart_count: 1 } }, { type: "scroll" }, { code: "STEP_NO_PROGRESS" }, 2), false);
  assert.equal(remoteRecovery.canFreshRestart(baseState, { type: "click" }, new Error("network"), 2), false);
});

test("responsive matrix no-progress watchdog is progress-aware and bounded", async () => {
  const remoteRecovery = await dataModule("../lib/remote-recovery.js");
  const now = Date.now();
  const stalled = {
    inFlight: {
      type: "contract_action",
      action: "playwright_responsive_matrix",
      startedAt: now - 31_000,
    },
  };
  assert.equal(remoteRecovery.noProgressExceeded(stalled, now), true);
  const advancing = {
    inFlight: {
      ...stalled.inFlight,
      lastProgressAt: now - 5_000,
    },
  };
  assert.equal(remoteRecovery.noProgressExceeded(advancing, now), false);
});

test("service worker contains bounded queue self-healing and internal-scroll fallback", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.match(worker, /remoteQueueSelfHealing:\s*true/);
  assert.match(worker, /FRESH_RESTART_REQUESTED/);
  assert.match(worker, /mode:\s*"restart_fresh"/);
  assert.match(worker, /two_attempts_without_progress/);
  assert.match(worker, /scroll\.internal_container_fallback/);
  assert.match(worker, /dom_internal_scroll_container/);
  assert.match(worker, /if \(!heartbeat\?\.ok\) throw codedError\("LEASE_LOST"/);
});

test("native-input timeout remains typed technical failure even though parse errors can restart", async () => {
  const remoteRecovery = await dataModule("../lib/remote-recovery.js");
  const state = { taskId: "task-native", checkpoint: { last_completed_step: -1, fresh_restart_count: 0 } };
  assert.equal(remoteRecovery.canFreshRestart(state, { type: "native_input" }, { code: "STEP_STALLED_TIMEOUT" }, 2), false);
  assert.equal(remoteRecovery.canFreshRestart(state, { type: "native_input" }, new Error("pointer_event_invalid:pointerunknown"), 2), true);
});

test("policy helper exports are operational and deterministic", async () => {
  const policy = await import("../lib/policy.js");
  assert.equal(policy.isMeaningfulAuthChallengeCandidate({ width: 200, height: 80, opacity: 1, display: "block", visibility: "visible", inViewport: true, kind: "captcha_iframe" }), true);
  assert.equal(policy.isMeaningfulAuthChallengeCandidate({ width: 20, height: 10, opacity: 1, display: "block", visibility: "visible", inViewport: true }), false);
  assert.equal(policy.isAllowedCdpMethod("Page.getLayoutMetrics", {}, "raw"), true);
  assert.equal(policy.isAllowedCdpMethod("Runtime.evaluate", { expression: "1+1" }, "raw"), false);
});

test("legacy interaction pacer and execution circuit are physically absent", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  const missingPacer = path.join(path.dirname(fileURLToPath(import.meta.url)), "..", "lib", "interaction" + "-pacer.js");
  await assert.rejects(access(missingPacer));
  assert.doesNotMatch(worker, /interaction-pacer|ORIGIN_ACTION_BUDGET|DUPLICATE_ACTION_LOOP|INTERACTION_CIRCUIT_OPEN/);
});

test("GSC property identity distinguishes Domain and URL-prefix properties", async () => {
  const gsc = await import(new URL("../lib/gsc-session.js", import.meta.url));
  assert.equal(gsc.normalizeGscProperty("sc-domain:IdealMarket1987.com"), "sc-domain:idealmarket1987.com");
  assert.equal(gsc.normalizeGscProperty("https://IdealMarket1987.com/"), "https://idealmarket1987.com/");
  assert.equal(gsc.gscPropertyMatches("https://idealmarket1987.com/", "sc-domain:idealmarket1987.com"), false);
  assert.equal(gsc.gscPropertyMatches("sc-domain:IDEALMARKET1987.COM", "sc-domain:idealmarket1987.com"), true);
  assert.ok(gsc.gscPropertyLabels("sc-domain:idealmarket1987.com").includes("idealmarket1987.com"));
});

test("fill is native-first and bad postconditions remain nonblocking evidence", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.match(worker, /native_keyboard_platform_aware/);
  assert.match(worker, /bounded_dom_recovery/);
  assert.match(worker, /Meta\+A/);
  assert.doesNotMatch(worker, /throw codedError\("fill_postcondition_failed"/);
  assert.match(worker, /application_effect_verified/);
  assert.match(worker, /blocking:\s*false/);
  const metaChord = nativeInput.keyboardSequence([{ type: "press", key: "Meta+A" }]);
  assert.equal(metaChord[0].params.modifiers, 4);
});

test("GSC inspection selects the exact property and request-indexing is visibly verified", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.match(worker, /ensureSearchConsoleProperty/);
  assert.match(worker, /search_console_property_not_found/);
  assert.match(worker, /reason:\s*"search_console_request_indexing_unverified"/);
  assert.doesNotMatch(worker, /throw codedError\("search_console_request_indexing_unverified"/);
  assert.match(worker, /indexingRequest/);
  assert.match(worker, /request_indexing === true/);
});

test("tab load semantics support none and DOM interactive without impossible Chrome tab states", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  const protocol = await readFile(new URL("../lib/protocol.js", import.meta.url), "utf8");
  assert.match(worker, /\["none", "no_wait", "nowait"\]\.includes\(requested\)/);
  assert.match(worker, /document\.readyState/);
  assert.match(worker, /dom_readiness_timeout_fallback/);
  assert.match(worker, /chrome-error:/);
  assert.match(protocol, /waitUntil: args\.wait_until \|\| "interactive"/);
});

test("every step emitted by actionToSteps has a concrete Browser Agent executor", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  const emittedTypes = new Set([...protocol.actionToSteps.toString().matchAll(/\btype:\s*["'`]([^"'`]+)["'`]/g)].map((match) => match[1]));
  const executeStart = worker.indexOf("async function executeStep(state, step)");
  const executeEnd = worker.indexOf("\nasync function runDomAction", executeStart);
  assert.ok(executeStart > 0 && executeEnd > executeStart, "executeStep switch must remain statically inspectable");
  const executorTypes = new Set([...worker.slice(executeStart, executeEnd).matchAll(/case\s+["']([^"']+)["']\s*:/g)].map((match) => match[1]));
  assert.deepEqual([...emittedTypes].filter((type) => !executorTypes.has(type)).sort(), []);
});

test("verify_url uses an owned tab and returns truthful normalized/pattern evidence", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.deepEqual(protocol.actionToSteps("verify_url", { tab_id: 27, expected_url: "https://example.test/orders/*" }), [
    { type: "verify_url", tabId: 27, url: "https://example.test/orders/*" },
  ]);
  assert.match(worker, /case "verify_url":/);
  assert.match(worker, /normalizeUrlForEvidence/);
  assert.match(worker, /matchStrategy/);
  assert.match(worker, /url_effect_unverified/);
  assert.deepEqual(protocol.compareUrlEvidence("HTTPS://Example.TEST:443/orders/42#receipt", "https://example.test/orders/42#receipt"), {
    actual: "HTTPS://Example.TEST:443/orders/42#receipt",
    expected: "https://example.test/orders/42#receipt",
    normalizedActual: "https://example.test/orders/42#receipt",
    normalizedExpected: "https://example.test/orders/42#receipt",
    matched: true,
    matchStrategy: "normalized_exact",
  });
  assert.equal(protocol.compareUrlEvidence("https://example.test/orders/42", "https://example.test/orders/*").matched, true);
  assert.equal(protocol.compareUrlEvidence("https://example.test/profile", "/orders\\/\\d+$/").matched, false);
  assert.equal(protocol.compareUrlEvidence("https://example.test/profile", "").matchStrategy, "missing_expected_url");
});

test("user observation never becomes takeover and debugger detach stays technical recovery", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.match(worker, /agent_user_interaction_evidence/);
  assert.match(worker, /installHumanInteractionProbe/);
  assert.match(worker, /observerOnly:\s*true/);
  assert.match(worker, /markIntentionalDebuggerDetach/);
  assert.match(worker, /consumeIntentionalDebuggerDetach/);
  const interactionBlock = worker.slice(worker.indexOf("async function handleUserInteractionEvidence"), worker.indexOf("// ---- PR STUDIO Local Studio"));
  assert.doesNotMatch(interactionBlock, /enterHumanTakeover/);
  assert.match(interactionBlock, /observerOnly/);
    const focusBlock = worker.slice(worker.indexOf("chrome.windows.onFocusChanged"), worker.indexOf("chrome.tabs.onActivated"));
  const activationBlock = worker.slice(worker.indexOf("chrome.tabs.onActivated"), worker.indexOf("chrome.tabs.onUpdated"));
  assert.doesNotMatch(focusBlock, /enterHumanTakeover/);
  assert.doesNotMatch(activationBlock, /enterHumanTakeover/);
});

test("local schedules and remote polling do not park each other behind an application lane gate", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.match(worker, /async function runLocalScheduledCheck/);
  assert.match(worker, /chrome\.windows\.create\(\{ url: schedule\.url/);
  assert.doesNotMatch(worker, /withRuntimeLaneMutex|local_recovery_remote_forbidden|remoteLocalStudioContext/);
  assert.doesNotMatch(worker, /phase:\s*"needs_review"/);
});

test("screenshot perception captures first; persistence failure is degraded evidence only", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  const screenshotCase = worker.slice(worker.indexOf('case "screenshot":'), worker.indexOf('case "screenshot_element":'));
  assert.ok(screenshotCase.indexOf("captureScreenshot") >= 0);
  assert.ok(screenshotCase.indexOf("storeScreenshotArtifact") > screenshotCase.indexOf("captureScreenshot"));
  const storeStart = worker.indexOf("async function storeScreenshotArtifact");
  const storeEnd = worker.indexOf("function screenshotTimeoutRemainingMs", storeStart);
  const storeBlock = worker.slice(storeStart, storeEnd > storeStart ? storeEnd : storeStart + 8000);
  assert.match(storeBlock, /screenshotStorageProbe/);
  assert.doesNotMatch(worker, /screenshotCapturePreflight|storageCircuit/);
  assert.match(worker, /SCREENSHOT_UPLOAD_TIMEOUT_MS/);
  assert.match(worker, /measureCapturedImage/);
  assert.match(worker, /releaseScreenshotBuffer/);
});

test("GSC payload collection is generation-bound and stale buffers are cleared", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.match(worker, /gscCollectionGenerations/);
  assert.match(worker, /beginGscCollectionGeneration/);
  assert.match(worker, /collectionFingerprint/);
  assert.match(worker, /generationId/);
  assert.match(worker, /structuredNetworkPayloads\.delete\(tabId\)/);
});

test("same-profile existing-window ownership has no Sentinel or dedicated-window teardown", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  const tabRemoved = worker.slice(worker.indexOf("chrome.tabs.onRemoved"), worker.indexOf("chrome.windows.onRemoved"));
  const windowRemoved = worker.slice(worker.indexOf("chrome.windows.onRemoved"), worker.indexOf("chrome.windows.onFocusChanged"));
  const selectWindow = worker.slice(worker.indexOf("async function selectExistingChromeWindow"), worker.indexOf("async function getTabRegistry"));
  assert.doesNotMatch(tabRemoved, /repairAgentWindowSentinel|sentinel_repaired/);
  assert.doesNotMatch(windowRemoved, /removeAgentCreatedTabsFromRegistry|clearTabRegistry|clearAllTabAffinity/);
  assert.doesNotMatch(selectWindow, /chrome\.windows\.create\(/);
  assert.match(worker, /sameProfileExistingWindow:\s*true/);
  assert.match(worker, /isolatedAgentWindow:\s*false/);
  assert.match(worker, /prstudio-task-heartbeat/);
  assert.match(worker, /prstudio-device-heartbeat/);
  assert.doesNotMatch(worker, /heartbeatTimer|setInterval\(\(\) => \{\s*heartbeatActiveTask/);
});

test("GSC request indexing is a critical mutation while Search Console reads remain read-only", () => {
  assert.equal(policy.isCriticalAction({ type: "search_console", mode: "request_indexing" }), true);
  assert.equal(policy.isMutatingStep({ type: "search_console", mode: "request_indexing" }), true);
  assert.equal(policy.isCriticalAction({ type: "search_console", mode: "url_inspection" }), false);
  assert.equal(policy.isMutatingStep({ type: "search_console", mode: "url_inspection" }), false);
});

test("bounded MV3 runtime covers CDP/API, exact local baselines, popup ownership and durable start-stop sessions", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.match(worker, /API_DEFAULT_TIMEOUT_MS/);
  assert.match(worker, /CDP_DEFAULT_TIMEOUT_MS/);
  assert.match(worker, /debuggerCommandWithTimeout/);
  assert.match(worker, /API_TIMEOUT/);
  assert.match(worker, /CDP_TIMEOUT/);
  assert.match(worker, /async function stopPolling/);
  assert.match(worker, /pollLoopDonePromise/);
  assert.doesNotMatch(worker, /pollGeneration \+= 1;\n\s*pollLoopRunning = false/);
  assert.match(worker, /captureExactVisibleTab/);
  assert.match(worker, /payloadImagesOmitted:\s*true/);
  assert.match(worker, /chrome\.tabs\.onCreated\.addListener/);
  assert.match(worker, /popup_ownership_inherited/);
  assert.match(worker, /RUNTIME_SESSIONS/);
  assert.match(worker, /openRuntimeSession/);
  assert.match(worker, /runtime_session_interrupted/);
  assert.match(worker, /auth_challenge_detection_unavailable/);
  assert.match(worker, /challengeBefore\?\.reason === "captcha_or_mfa"/);
  assert.match(worker, /challengeAfter\?\.reason === "captcha_or_mfa"/);
  assert.match(worker, /waitForExternalAuthChallenge/);
  assert.match(worker, /EVENT_BUFFER_MAX_BYTES/);
  assert.match(worker, /GSC_PAYLOAD_TOTAL_MAX_BYTES/);
  assert.match(worker, /trimArrayByApproxBytes/);
  assert.match(worker, /ephemeralWorkerWindowId/);
  const cleanup = worker.slice(worker.indexOf("async function cleanupTaskRuntime"), worker.indexOf("async function api("));
  assert.match(cleanup, /runtimeSessionTypesForTab/);
  assert.match(cleanup, /prstudioOriginalVisibility/);
});


test("remote action timeouts are bounded before execution", () => {
  const wait = protocol.actionToSteps("playwright_wait_for_selector", { selector: "#x", timeout: 9_999_999 });
  assert.equal(wait.at(-1).timeoutMs, 120_000);
  const prep = protocol.actionToSteps("playwright_goto", { url: "https://example.test/", timeout: 9_999_999 });
  assert.equal(prep[0].timeoutMs, 120_000);
});


test("idle remote polling remains warm enough for sub-second task pickup", () => {
  const samples = Array.from({ length: 20 }, (_, index) => enterpriseRuntime.adaptivePollDelay({ idleCount: index + 1, errorCount: 0 }));
  assert.ok(Math.max(...samples) <= 750, `idle polling delay exceeded 750ms: ${JSON.stringify(samples)}`);
  assert.equal(enterpriseRuntime.adaptivePollDelay({ idleCount: 0, errorCount: 1 }), 1000, "error backoff must remain conservative");
  assert.ok(enterpriseRuntime.adaptivePollDelay({ idleCount: 0, errorCount: 5 }) >= 8000, "repeated errors must still back off");
});

test("remote URL regex rejects backtracking-prone patterns before evaluation", () => {
  const dangerous = protocol.compareUrlEvidence("aaaaaaaaaaaaaaaaX", "/(a+)+$/");
  assert.equal(dangerous.matched, false);
  assert.equal(dangerous.matchStrategy, "unsafe_regular_expression");
  const safe = protocol.compareUrlEvidence("https://example.test/orders/42", "/orders\\/\\d+$/");
  assert.equal(safe.matched, true);
  assert.equal(safe.matchStrategy, "regular_expression");
});

test("screenshot remote watchdog stays inside the interactive 10-second envelope", async () => {
  const remoteRecovery = await dataModule("../lib/remote-recovery.js");
  assert.ok(remoteRecovery.stepWatchdogMs({ type: "screenshot" }) <= 10_000);
  assert.ok(remoteRecovery.stepWatchdogMs({ type: "screenshot_element" }) <= 10_000);
});

test("screenshot watchdog cannot be expanded above the interactive envelope by caller timeout", async () => {
  const remoteRecovery = await dataModule("../lib/remote-recovery.js");
  assert.ok(remoteRecovery.stepWatchdogMs({ type: "screenshot", timeoutMs: 60_000 }) <= 10_000);
  assert.ok(remoteRecovery.stepWatchdogMs({ type: "screenshot_element", timeout_ms: 120_000 }) <= 10_000);
});


test("ONE_GUARD browser runtime has inline auth challenge and no parked takeover protocol", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  assert.match(worker, /async function waitForExternalAuthChallenge/);
  assert.match(worker, /auth_challenge\.resolved/);
  assert.match(worker, /startHeartbeat\(state\)/);
  for (const forbidden of ["enterHumanTakeover", "resumeActive", "prstudioPendingTakeovers", "reservedForTakeover", "target_task_binding_mismatch", "agent_tab_required"]) {
    assert.equal(worker.includes(forbidden), false, forbidden);
  }
});

test("controlled session reuse treats taskId as affinity only", () => {
  const crossTask = tabOwnership.tabBindingCompatibility({ tabId: 11, taskId: "task-a" }, { taskId: "task-b" });
  assert.equal(crossTask.ok, true);
  assert.equal(crossTask.mode, "session_task_rebind");
  const sameLane = tabOwnership.tabBindingCompatibility({ tabId: 12, laneId: "lane-a", taskId: "task-a" }, { laneId: "lane-a", taskId: "task-b" });
  assert.equal(sameLane.ok, true);
  assert.equal(sameLane.mode, "lane_task_rebind");
});

test("controlled tab close is a technical error, not an approval/review state", async () => {
  const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
  const block = worker.slice(worker.indexOf("chrome.tabs.onRemoved"), worker.indexOf("chrome.windows.onRemoved"));
  assert.match(block, /\/tasks\/\$\{encodeURIComponent\(taskId\)\}\/fail/);
  assert.match(block, /CONTROLLED_TAB_CLOSED/);
  assert.doesNotMatch(block, /discard_takeover|pendingTakeover|resumeActive|manual_resume/);
});
