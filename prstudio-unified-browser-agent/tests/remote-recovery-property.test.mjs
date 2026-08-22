import assert from "node:assert/strict";
import test from "node:test";
import * as remote from "../lib/remote-recovery.js";

const SEED = 1376263;
const CASES = 80;
let rngState = SEED >>> 0;
function next() {
  rngState = (Math.imul(rngState, 1664525) + 1013904223) >>> 0;
  return rngState;
}
function pick(values) {
  return values[next() % values.length];
}

const types = [
  "scroll", "wait_selector", "wait_url", "wait_load", "reload", "native_input",
  "screenshot", "screenshot_element", "click", "type", "upload", "contract_action",
  "SCROLL", " scroll ", "", null, ["scroll"], 42,
];
const timeouts = [undefined, 0, -1, 0.5, 1, 5000, "5000", "", " ", NaN, Infinity, -Infinity, Number.MAX_SAFE_INTEGER, Number.MAX_SAFE_INTEGER + 1, 1e300, Symbol("bad")];
const errors = [
  undefined, null, 42, "pointer_event_invalid:pointerdown", ["pointer_event_invalid"],
  new Error("pointer_event_invalid:pointerdown"),
  { code: "pointer_event_invalid" }, { code: "POINTER_EVENT_INVALID" },
  { message: "keyboard_key_required:KeyA" }, { message: "not_pointer_event_invalidated" },
  { message: "xpointer_event_invalidsuffix" }, { code: "STEP_NO_PROGRESS" },
  { code: "STEP_STALLED_TIMEOUT" }, { code: Symbol("bad") }, { nested: { code: "pointer_event_invalid" } },
  { code: "technical_tab_not_controlled" }, { code: "controlled_tab_missing" },
  { message: "tab_ownership_nonce_mismatch" }, { code: "tab_lane_mismatch" },
];
const attemptsPool = [0, 1, 2, -1, 1.5, 2.5, "2", NaN, Infinity, -Infinity, Number.MAX_SAFE_INTEGER, Number.MAX_SAFE_INTEGER + 1];
const checkpoints = [
  { checkpoint: { last_completed_step: -1, fresh_restart_count: 0 } },
  { checkpoint: { last_completed_step: 0, fresh_restart_count: 0 } },
  { checkpoint: { last_completed_step: -1, fresh_restart_count: 1 } },
  { checkpoint: { last_completed_step: -1, fresh_restart_count: "0" } },
  { checkpoint: { last_completed_step: -1, fresh_restart_count: NaN } },
  { checkpoint: { last_completed_step: -1, fresh_restart_count: Infinity } },
  { checkpoint: { last_completed_step: -1, fresh_restart_count: -1 } },
  { checkpoint: { fresh_restart_count: 0 } },
  { checkpoint: [] },
  { freshRestartCount: 0 },
  { freshRestartCount: "0" },
  { freshRestartCount: NaN },
  null,
  [],
];

function typeOfStep(step) {
  return step && typeof step === "object" && !Array.isArray(step) && typeof step.type === "string" ? step.type : "";
}

test("ownership assertion failures are retry-safe only because they prove no effect was dispatched", () => {
  for (const code of [
    "technical_tab_not_controlled",
    "controlled_tab_missing",
    "tab_ownership_missing",
    "tab_ownership_nonce_mismatch",
    "tab_lane_mismatch",
    "tab_affinity_mismatch",
  ]) {
    assert.equal(remote.isPreEffectOwnershipError({ code }), true, `${code} must be classified before-effect`);
    assert.equal(remote.isRetrySafeFailure({ type: "click" }, { code }), true, `${code} may recover even for a future mutating step`);
    assert.equal(remote.canFreshRestart(
      { checkpoint: { last_completed_step: -1, fresh_restart_count: 0 } },
      { type: "click" },
      { code },
      remote.REMOTE_MAX_STEP_ATTEMPTS,
    ), true, `${code} may restart from zero before any checkpoint`);
    assert.equal(remote.canFreshRestart(
      { checkpoint: { last_completed_step: 0, fresh_restart_count: 0 } },
      { type: "click" },
      { code },
      remote.REMOTE_MAX_STEP_ATTEMPTS,
    ), false, `${code} must not replay after a completed step`);
  }

  for (const error of [{ code: "element_not_found" }, { code: "network_error" }, { message: "technical_tab_not_controlled_suffix" }]) {
    assert.equal(remote.isPreEffectOwnershipError(error), false);
    assert.equal(remote.isRetrySafeFailure({ type: "click" }, error), false, "ordinary mutating failures stay non-retryable");
  }
});

test(`remote recovery deterministic bounded properties seed=${SEED} cases=${CASES}`, () => {
  const mutative = new Set(["click", "type", "upload", "contract_action"]);
  for (let i = 0; i < CASES; i += 1) {
    const type = pick(types);
    const timeoutMs = pick(timeouts);
    const timeoutMsLegacy = pick(timeouts);
    const error = pick(errors);
    const attempts = pick(attemptsPool);
    const state = pick(checkpoints);
    const step = { type, timeoutMs, timeout_ms: timeoutMsLegacy, extra: i };

    let watchdog;
    assert.doesNotThrow(() => { watchdog = remote.stepWatchdogMs(step); }, `watchdog total case=${i}`);
    assert.equal(Number.isFinite(watchdog), true, `finite watchdog case=${i}`);
    assert.ok(watchdog >= 5000, `watchdog min case=${i}`);
    const normalizedType = typeOfStep(step);
    assert.ok(watchdog <= ((normalizedType === "screenshot" || normalizedType === "screenshot_element") ? 9500 : 120000), `watchdog max case=${i}`);
    assert.equal(remote.stepWatchdogMs(step), watchdog, `watchdog deterministic case=${i}`);

    const retryA = remote.isRetrySafeFailure(step, error);
    const retryB = remote.isRetrySafeFailure(step, error);
    assert.equal(retryA, retryB, `retry deterministic case=${i}`);
    if (mutative.has(normalizedType) && !remote.isPreEffectOwnershipError(error)) {
      assert.equal(retryA, false, `no implicit mutative retry case=${i}`);
    }

    const restartA = remote.canFreshRestart(state, step, error, attempts);
    const restartB = remote.canFreshRestart(state, step, error, attempts);
    assert.equal(restartA, restartB, `restart deterministic case=${i}`);
    if (restartA) {
      assert.equal(attempts, remote.REMOTE_MAX_STEP_ATTEMPTS, `restart exact attempt cap case=${i}`);
      assert.equal(retryA, true, `restart requires retry-safe case=${i}`);
      assert.ok(remote.freshRestartCount(state) < remote.REMOTE_MAX_FRESH_RESTARTS, `restart cap case=${i}`);
      if (state?.checkpoint != null) {
        assert.equal(Array.isArray(state.checkpoint), false, `checkpoint record case=${i}`);
        assert.equal(state.checkpoint.last_completed_step, -1, `restart only from zero case=${i}`);
      }
    }

    const now = 1_000_000 + i;
    const inFlightType = pick(["scroll", "native_input", "screenshot", "screenshot_element", "contract_action", "wait_url"]);
    const startedAt = now - pick([0, 1, 15000, 15001, 30000, 30001, 120000, 120001]);
    const progress = pick([undefined, now - 1, now - 5000, "bad", Infinity, now + 1e12]);
    const progressState = { inFlight: { type: inFlightType, action: inFlightType === "contract_action" ? "playwright_responsive_matrix" : "", startedAt, lastProgressAt: progress } };
    const progressA = remote.noProgressExceeded(progressState, now);
    const progressB = remote.noProgressExceeded(progressState, now);
    assert.equal(progressA, progressB, `no-progress deterministic case=${i}`);
  }

  const now = 2_000_000;
  for (const badProgress of ["bad", Infinity, now + 1e12]) {
    assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now - 40_000, lastProgressAt: badProgress } }, now), true);
  }
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now - 30_000 } }, now), false);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "scroll", startedAt: now - 30_001 } }, now), true);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "screenshot", startedAt: now - 15_000 } }, now), false);
  assert.equal(remote.noProgressExceeded({ inFlight: { type: "screenshot", startedAt: now - 15_001 } }, now), true);
});
