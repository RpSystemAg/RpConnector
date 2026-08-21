/**
 * A task that keeps failing must not be re-claimed with no delay.
 *
 * WHY THIS EXISTS
 * ---------------
 * Reported from watching the extension run: it opens tabs, they close again
 * almost immediately, and it keeps trying -- open, close, open, close -- with
 * the CPU climbing.
 *
 * The cause is two correct decisions with no floor between them.
 * adaptivePollDelay returns 0 whenever work is available, which is right: a
 * queued task should start immediately. The polling loop resets its error
 * counter the moment a task arrives, which is also right: arriving is evidence
 * the transport is healthy.
 *
 * But executeTask handles its own failures and reports them to the server
 * instead of throwing out of the loop. So a task that fails deterministically
 * produced: claim, open a tab, fail, close the tab (createOwnedAgentTab removes
 * the tab on any error), report, server requeues, poll with zero delay, claim
 * the same task again. Nothing was wrong with the transport, so the transport
 * backoff could never engage, and task failure had no throttle of its own.
 *
 * WHAT IS ASSERTED
 * ----------------
 * The property that makes the fix safe rather than merely slower: a healthy run
 * pays nothing. Zero failures means zero delay, because slowing down successful
 * work to protect against failing work would be charging the wrong party.
 *
 * And the property that makes it a throttle rather than a circuit breaker: it
 * is bounded and always finite. Under LAW 5 a transient failure retries and
 * does not park the mission, so this may delay the next attempt but must never
 * refuse one.
 */

import { test } from "node:test";
import assert from "node:assert/strict";

import { adaptivePollDelay, failingTaskBackoffMs } from "../lib/enterprise-runtime.js";

test("a healthy run pays nothing", () => {
  // The zero-delay fast path is the whole point of adaptivePollDelay returning
  // 0 when work is available. A task that succeeds must not be slowed down by
  // machinery that exists for tasks that fail.
  assert.equal(failingTaskBackoffMs(0), 0);
  assert.equal(adaptivePollDelay({ idleCount: 0, errorCount: 0 }), 0);
});

test("the first failure already introduces a floor", () => {
  // One second is enough to break the hot loop. The defect was not that the
  // delay was short, it was that there was no delay at all.
  assert.ok(failingTaskBackoffMs(1) >= 1000, "a failing task must not be re-claimed instantly");
});

test("repeated failure backs off, and stops growing", () => {
  const curve = [1, 2, 3, 4, 5, 6, 7, 20].map(failingTaskBackoffMs);
  for (let i = 1; i < curve.length; i += 1) {
    assert.ok(curve[i] >= curve[i - 1], `backoff went backwards at ${i}: ${curve.join(", ")}`);
  }
  assert.ok(Math.max(...curve) <= 30000, "an unbounded backoff would park the mission, which LAW 5 forbids");
});

test("it is a throttle, never a refusal", () => {
  // Every value has to be a finite number of milliseconds. Infinity, NaN or a
  // negative would either hang the loop or skip the wait entirely.
  for (const failures of [0, 1, 5, 6, 50, 1e6]) {
    const value = failingTaskBackoffMs(failures);
    assert.ok(Number.isFinite(value), `${failures} produced ${value}`);
    assert.ok(value >= 0, `${failures} produced a negative wait`);
  }
});

test("hostile counters do not produce a wait", () => {
  // A corrupted counter must fall back to the fast path rather than stalling
  // the agent: refusing to work is worse than working too eagerly.
  for (const value of [-1, -1000, NaN, Infinity, -Infinity, null, undefined, "", "many", {}, []]) {
    assert.equal(failingTaskBackoffMs(value), 0, `${JSON.stringify(value)} should not delay`);
  }
});

test("fractional counters are floored rather than producing odd waits", () => {
  assert.equal(failingTaskBackoffMs(1.9), failingTaskBackoffMs(1));
  assert.equal(failingTaskBackoffMs(3.2), failingTaskBackoffMs(3));
});

test("transport backoff and task backoff are separate concerns", () => {
  // The transport counter is reset the moment a task arrives, which is why it
  // could never throttle this loop: the transport was healthy the whole time.
  // The two must not be confused for one another.
  assert.equal(adaptivePollDelay({ idleCount: 0, errorCount: 0 }), 0);
  assert.ok(adaptivePollDelay({ errorCount: 3 }) > 0, "transport errors still back off on their own");
  assert.ok(failingTaskBackoffMs(3) > 0, "and so do failing tasks, independently");
});
