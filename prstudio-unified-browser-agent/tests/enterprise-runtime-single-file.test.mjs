import test from "node:test";
import assert from "node:assert/strict";
import * as runtime from "../lib/enterprise-runtime.js";

test("enterprise runtime exposes transport retry cadence only", () => {
  assert.equal(typeof runtime.adaptivePollDelay, "function");
  for (const removed of ["taskBudget","assertTaskBudget","registerTaskAttempt","runtimeLimits","duplicateTaskKey"]) {
    assert.equal(typeof runtime[removed], "undefined", `${removed} must stay physically removed`);
  }
});

test("adaptive poll delay is bounded technical retry telemetry, never a mission veto", () => {
  for (const input of [0,1,2,5,20,100,Number.NaN,-1]) {
    const delay=runtime.adaptivePollDelay(input);
    assert.equal(Number.isFinite(delay), true);
    assert.ok(delay >= 0 && delay <= 30000);
  }
});
