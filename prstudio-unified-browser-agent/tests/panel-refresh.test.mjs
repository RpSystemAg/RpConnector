import test from 'node:test';
import assert from 'node:assert/strict';
import { createRefreshLoop } from '../lib/panel-refresh.js';

function deferred() {
  let resolve;
  const promise = new Promise((done) => { resolve = done; });
  return { promise, resolve };
}

test('panel refresh is single-flight and schedules only after completion', async () => {
  const timers = new Map();
  let nextTimerId = 1;
  let calls = 0;
  let pending = deferred();
  const loop = createRefreshLoop(() => {
    calls += 1;
    return pending.promise;
  }, {
    intervalMs: 4_000,
    scheduleTimer(fn, delayMs) { const id = nextTimerId++; timers.set(id, { fn, delayMs }); return id; },
    clearTimer(id) { timers.delete(id); },
  });

  const first = loop.start();
  const duplicate = loop.runNow();
  await Promise.resolve();
  assert.equal(calls, 1);
  assert.equal(loop.state().running, true);
  assert.equal(timers.size, 0, 'no next refresh is armed while the current request is unresolved');
  pending.resolve('ok');
  await Promise.all([first, duplicate]);
  assert.equal(timers.size, 1);
  assert.equal([...timers.values()][0].delayMs, 4_000);
});

test('hidden Side Panel pauses refreshes and resumes with one immediate refresh', async () => {
  const timers = new Map();
  let nextTimerId = 1;
  let calls = 0;
  const loop = createRefreshLoop(async () => { calls += 1; }, {
    intervalMs: 5_000,
    scheduleTimer(fn, delayMs) { const id = nextTimerId++; timers.set(id, { fn, delayMs }); return id; },
    clearTimer(id) { timers.delete(id); },
  });
  await loop.start();
  assert.equal(calls, 1);
  assert.equal(timers.size, 1);
  loop.pause();
  assert.equal(timers.size, 0);
  assert.equal(loop.state().paused, true);
  await loop.resume();
  assert.equal(calls, 2);
  assert.equal(timers.size, 1);
});
