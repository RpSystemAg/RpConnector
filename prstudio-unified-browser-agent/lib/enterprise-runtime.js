/** Technical transport retry cadence only. No mission budget or duplicate-action veto. */
function boundedCount(value, max) {
  if (typeof value !== "number" || !Number.isFinite(value) || value <= 0) return 0;
  return Math.min(max, Math.ceil(value));
}

export function adaptivePollDelay(input = {}) {
  const source = input && typeof input === "object" ? input : {};
  const errors = boundedCount(source.errorCount, 6);
  if (errors > 0) return Math.min(30000, 500 * (2 ** errors));
  const idle = boundedCount(source.idleCount, 7);
  if (idle <= 0) return 0;
  const schedule = [100, 150, 250, 400, 550, 700, 750];
  return schedule[idle - 1];
}

/**
 * How long to wait after a task that failed, before accepting the next one.
 *
 * WHY THIS EXISTS
 * ---------------
 * adaptivePollDelay returns 0 when work is available, which is correct and
 * deliberate: a queued task should be picked up immediately. The polling loop
 * also resets its error counter the moment a task arrives, because arriving is
 * evidence the transport is healthy.
 *
 * Both are right on their own, and together they had no floor. executeTask
 * handles its own failures and reports them to the server rather than throwing
 * out of the loop, so a task that fails deterministically produced: claim,
 * open a tab, fail, close the tab, report, server requeues, poll with zero
 * delay, claim the same task again. Observed from the operator's chair as tabs
 * flickering open and shut with the CPU pinned.
 *
 * The transport backoff could never engage, because nothing was wrong with the
 * transport. The task was failing, and task failure had no throttle at all.
 *
 * WHAT THIS PRESERVES
 * -------------------
 * Zero for a healthy run. Succeeding quickly must stay quick -- that is the
 * whole value of the zero-delay fast path, and slowing down successful work to
 * protect against failing work would be paying the wrong party.
 *
 * It is a throttle, not a circuit breaker. Under LAW 5 a transient failure
 * retries and does not park the mission, and under LAW 1 nothing here may stop
 * a mutation. This only decides how soon the next attempt starts.
 *
 * @param {unknown} consecutiveFailures Tasks that have failed in a row.
 * @returns {number} Milliseconds to wait. 0 while nothing is failing.
 */
export function failingTaskBackoffMs(consecutiveFailures) {
  const failures = Number(consecutiveFailures);
  if (!Number.isFinite(failures) || failures <= 0) return 0;
  const bounded = Math.min(6, Math.floor(failures));
  return Math.min(30000, 1000 * (2 ** (bounded - 1)));
}
