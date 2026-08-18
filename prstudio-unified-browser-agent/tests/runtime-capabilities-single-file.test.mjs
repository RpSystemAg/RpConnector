import assert from "node:assert/strict";
import test from "node:test";
import {
  RUNTIME_CONTRACT_ACTIONS,
  SENSITIVE_RUNTIME_CONTRACT_ACTIONS,
  hasRuntimeContractAction,
  isSensitiveRuntimeContractAction,
} from "../lib/runtime-capabilities.js";

test("runtime capability registries are exact, unique and sensitive is a subset", () => {
  assert.equal(new Set(RUNTIME_CONTRACT_ACTIONS).size, RUNTIME_CONTRACT_ACTIONS.length);
  assert.equal(new Set(SENSITIVE_RUNTIME_CONTRACT_ACTIONS).size, SENSITIVE_RUNTIME_CONTRACT_ACTIONS.length);
  for (const action of SENSITIVE_RUNTIME_CONTRACT_ACTIONS) {
    assert.equal(RUNTIME_CONTRACT_ACTIONS.includes(action), true, action);
    assert.equal(hasRuntimeContractAction(action), true, action);
    assert.equal(isSensitiveRuntimeContractAction(action), true, action);
  }
});

test("valid supported runtime actions remain executable classifications", () => {
  for (const action of ["fetch", "playwright_start_trace", "playwright_evaluate", "visual_diff"]) {
    assert.equal(hasRuntimeContractAction(action), true, action);
  }
  assert.equal(isSensitiveRuntimeContractAction("playwright_evaluate"), true);
  assert.equal(isSensitiveRuntimeContractAction("fetch"), false);
});

test("non-string action values cannot widen into registered capability names", () => {
  assert.equal(hasRuntimeContractAction(new String("fetch")), false);
  assert.equal(isSensitiveRuntimeContractAction(new String("playwright_evaluate")), false);
  assert.equal(hasRuntimeContractAction({ toString: () => "fetch" }), false);
  assert.equal(isSensitiveRuntimeContractAction({ toString: () => "playwright_evaluate" }), false);
});

test("malformed hostile values return a technical failure without raw coercion exceptions", () => {
  const hostile = Object.create(null);
  const throwing = { toString() { throw new Error("coercion_executed"); } };
  for (const value of [undefined, null, false, 0, 1, 1n, Symbol("fetch"), hostile, throwing, [], ["fetch"]]) {
    assert.doesNotThrow(() => hasRuntimeContractAction(value));
    assert.doesNotThrow(() => isSensitiveRuntimeContractAction(value));
    assert.equal(hasRuntimeContractAction(value), false);
    assert.equal(isSensitiveRuntimeContractAction(value), false);
  }
});
