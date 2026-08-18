import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

async function loadRecovery() {
  const source = await readFile(new URL("../lib/recovery.js", import.meta.url), "utf8");
  return import(`data:text/javascript;base64,${Buffer.from(source).toString("base64")}`);
}
const recovery = await loadRecovery();

test("malformed or mutating in-flight recovery is Anti-Crash nonreplayable", () => {
  for (const state of [
    { taskId: "t", phase: "in_flight" },
    { taskId: "t", phase: "committing", inFlight: null },
    { taskId: "t", phase: "in_flight", inFlight: { mutating: false } },
  ]) assert.equal(recovery.recoveryDisposition(state).action, "uncertain_side_effect");
});

test("canonical step allows shared acyclic references but rejects lossy values", async () => {
  const shared = { x: 1 };
  assert.equal(recovery.canonicalStep({ b: shared, a: shared }), '{"a":{"x":1},"b":{"x":1}}');
  assert.throws(() => recovery.canonicalStep({ x: NaN }), /step_canonicalization_non_finite_number/);
  const cycle = {}; cycle.self = cycle; assert.throws(() => recovery.canonicalStep(cycle), /step_canonicalization_cycle/);
  assert.equal(await recovery.digestStep({ z:[2,1], a:{y:true,x:"v"} }), await recovery.digestStep({ a:{x:"v",y:true}, z:[2,1] }));
});

test("tab interruption comparison does not alias unsafe integer IDs", () => {
  assert.equal(recovery.interruptionReason("tab_removed", { taskId:"t", tabId:"9007199254740993" }, { tabId:"9007199254740992" }), null);
  assert.equal(recovery.interruptionReason("tab_removed", { taskId:"t", tabId:"42" }, { tabId:42 }), "tab_closed");
});

test("in-flight attempt identity stays unique", () => {
  const original=Date.now; Date.now=()=>1700000000000;
  try { const a=recovery.beginInFlightState({taskId:"t"},{type:"page_snapshot"},0,"d",false); const b=recovery.beginInFlightState({taskId:"t"},{type:"page_snapshot"},0,"d",false); assert.notEqual(a.inFlight.attemptId,b.inFlight.attemptId); }
  finally { Date.now=original; }
});

test("restart and lease interruption stay technical", () => {
  const active={taskId:"t",tabId:7};
  assert.equal(recovery.interruptionReason("service_worker_restart", active), "recover_from_checkpoint");
  assert.equal(recovery.interruptionReason("lease_expired", active, {status:"running"}), "requeue");
  assert.equal(recovery.interruptionReason("debugger_detached", active, {tabId:7,reason:"target_closed"}), "debugger_detached:target_closed");
});

test("recovery disposition has no review/approval/takeover state", () => {
  assert.deepEqual(recovery.recoveryDisposition({}), {action:"none"});
  assert.deepEqual(recovery.recoveryDisposition({taskId:"t",phase:"cancel_requested"}), {action:"preserve_cancel"});
  assert.deepEqual(recovery.recoveryDisposition({taskId:"t",phase:"lease_lost"}), {action:"lease_lost"});
  const ro=recovery.beginInFlightState({taskId:"t"},{type:"page_snapshot"},1,"digest",false);
  assert.deepEqual(recovery.recoveryDisposition(ro), {action:"resume_readonly"});
  assert.deepEqual(recovery.recoveryDisposition(recovery.clearInFlightState(ro)), {action:"resume"});
});
