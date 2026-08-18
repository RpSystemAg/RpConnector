import assert from "node:assert/strict";
import test from "node:test";
import * as remote from "../lib/remote-recovery.js";

const restartableState = { checkpoint: { last_completed_step: -1, fresh_restart_count: 0 } };
const noProgress = { code: "STEP_NO_PROGRESS" };

test("native_input pre-dispatch retry requires a semantic error token", () => {
  assert.equal(remote.isPreDispatchInputError({ code: "POINTER_EVENT_INVALID" }), true);
  assert.equal(remote.isPreDispatchInputError(new Error("pointer_event_invalid:pointerdown")), true);
  assert.equal(remote.isPreDispatchInputError("keyboard_key_required:KeyA"), true);
  assert.equal(remote.isPreDispatchInputError(null), false);
  assert.equal(remote.isPreDispatchInputError(42), false);
  assert.equal(remote.isPreDispatchInputError({ message: "not_pointer_event_invalidated" }), false);
  assert.equal(remote.isPreDispatchInputError(["pointer_event_invalid"]), false);
  assert.equal(remote.isRetrySafeFailure({ type: "native_input" }, { message: "xpointer_event_invalidsuffix" }), false);
  assert.equal(remote.isRetrySafeFailure({ type: ["scroll"] }, noProgress), false);
  assert.equal(remote.isRetrySafeFailure({ type: "native_input" }, { code: "STEP_STALLED_TIMEOUT" }), false);
});

test("fresh restart requires the exact bounded attempt count", () => {
  for (const type of ["scroll", "wait_selector", "wait_url", "wait_load", "reload"]) {
    assert.equal(remote.isRetrySafeFailure({ type }, { code: "network" }), true, type);
  }
  for (const type of ["click", "type", "upload", "contract_action", "SCROLL", " scroll "]) {
    assert.equal(remote.isRetrySafeFailure({ type }, noProgress), false, type);
  }
  assert.equal(remote.canFreshRestart(restartableState, { type: "scroll" }, noProgress, 2), true);
  for (const attempts of [0, -1, 1, 1.5, "2", 2.5, NaN, Infinity, Number.MAX_SAFE_INTEGER]) {
    assert.equal(remote.canFreshRestart(restartableState, { type: "scroll" }, noProgress, attempts), false, String(attempts));
  }
});

test("step watchdog keeps timeout precedence and is total for malformed numeric input", () => {
  const defaults = {
    screenshot: 9_500,
    screenshot_element: 9_500,
    scroll: 18_000,
    native_input: 15_000,
    wait_selector: 35_000,
    wait_url: 35_000,
    wait_load: 50_000,
    reload: 50_000,
  };
  for (const [type, expected] of Object.entries(defaults)) assert.equal(remote.stepWatchdogMs({ type }), expected, type);
  assert.equal(remote.stepWatchdogMs({ type: "unknown" }), 90_000);
  assert.equal(remote.stepWatchdogMs({ type: "scroll", timeoutMs: 0, timeout_ms: 120_000 }), 18_000);
  assert.doesNotThrow(() => remote.stepWatchdogMs({ type: "scroll", timeoutMs: Symbol("bad") }));
  assert.equal(remote.stepWatchdogMs({ type: "scroll", timeoutMs: -1 }), 18_000);
  assert.equal(remote.stepWatchdogMs({ type: "scroll", timeoutMs: NaN }), 18_000);
  assert.equal(remote.stepWatchdogMs({ type: "scroll", timeoutMs: "1000" }), 18_000);
  assert.equal(remote.stepWatchdogMs({ type: "unknown", timeoutMs: 1e300 }), 120_000);
  assert.equal(remote.stepWatchdogMs({ type: "screenshot", timeoutMs: 1e300 }), 9_500);
  assert.equal(remote.stepWatchdogMs({ type: "screenshot_element", timeout_ms: Infinity }), 9_500);
});

test("fresh restart counters and checkpoint semantics return a technical failure when malformed", () => {
  assert.equal(remote.freshRestartCount({ checkpoint: { fresh_restart_count: 0 }, freshRestartCount: 1 }), 0);
  assert.equal(remote.freshRestartCount({ checkpoint: { fresh_restart_count: "0" } }), 0);
  assert.equal(remote.freshRestartCount({ checkpoint: { fresh_restart_count: -1 } }), remote.REMOTE_MAX_FRESH_RESTARTS);
  assert.equal(remote.freshRestartCount({ checkpoint: { fresh_restart_count: NaN } }), remote.REMOTE_MAX_FRESH_RESTARTS);
  assert.equal(remote.freshRestartCount({ checkpoint: { fresh_restart_count: Infinity } }), remote.REMOTE_MAX_FRESH_RESTARTS);
  assert.equal(remote.freshRestartCount({ checkpoint: { fresh_restart_count: Number.MAX_SAFE_INTEGER + 1 } }), remote.REMOTE_MAX_FRESH_RESTARTS);
  for (const state of [
    { checkpoint: { last_completed_step: -1, fresh_restart_count: NaN } },
    { checkpoint: { last_completed_step: -1, fresh_restart_count: -1 } },
    { checkpoint: { fresh_restart_count: 0 } },
    { checkpoint: { last_completed_step: NaN, fresh_restart_count: 0 } },
    { checkpoint: [] },
  ]) {
    assert.equal(remote.canFreshRestart(state, { type: "scroll" }, noProgress, 2), false);
  }
  assert.equal(remote.canFreshRestart({ freshRestartCount: 0 }, { type: "scroll" }, noProgress, 2), true);
});

test("no-progress watchdog cannot be disabled by malformed or future timestamps", () => {
  const now = 1_000_000;
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now - 30_000 } }, now), false);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now - 30_001 } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "native_input", startedAt: now - 30_001 } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "screenshot", startedAt: now - 15_000 } }, now), false);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "screenshot_element", startedAt: now - 15_001 } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "contract_action", action: "playwright_responsive_matrix", startedAt: now - 30_001 } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "wait_url", startedAt: now - 120_000 } }, now), false);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "wait_url", startedAt: now - 120_001 } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now - 40_000, lastProgressAt: "bad" } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now - 40_000, lastProgressAt: Infinity } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now - 40_000, lastProgressAt: now + 1e12 } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: "bad" } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now + 1_000 } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now - 40_000 } }, NaN), true);
});
