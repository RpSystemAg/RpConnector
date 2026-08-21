import test from 'node:test';
import assert from 'node:assert/strict';

import {
  HARD_TASK_WATCHDOG_ALARM,
  HARD_TASK_WATCHDOG_PERIOD_MINUTES,
  installHardTaskWatchdog,
  noProgressExceeded,
  stepWatchdogMs,
} from '../lib/remote-recovery.js';

test('interactive browser steps have bounded no-progress budgets', () => {
  assert.equal(stepWatchdogMs({ type: 'open_tab' }), 30000);
  assert.equal(stepWatchdogMs({ type: 'click' }), 30000);
  assert.equal(stepWatchdogMs({ type: 'navigate' }), 50000);
  assert.equal(stepWatchdogMs({ type: 'screenshot' }), 9500);
  assert.ok(stepWatchdogMs({ type: 'contract_action' }) <= 60000);

  const now = Date.now();
  assert.equal(noProgressExceeded({ inFlight: { type: 'click', startedAt: now - 31000 } }, now), true);
  assert.equal(noProgressExceeded({ inFlight: { type: 'click', startedAt: now - 1000, lastProgressAt: now - 500 } }, now), false);
});

test('hard watchdog reloads a stalled MV3 worker once and persists recovery evidence', async () => {
  const created = [];
  const listeners = [];
  const writes = [];
  let reloads = 0;
  const now = Date.now();
  const state = {
    taskId: 'task-stalled',
    inFlight: {
      type: 'navigate',
      attemptId: 'attempt-1',
      startedAt: now - 60000,
    },
  };
  let marker = null;

  const fakeChrome = {
    alarms: {
      create(name, spec) { created.push({ name, spec }); },
      onAlarm: { addListener(fn) { listeners.push(fn); } },
    },
    storage: {
      local: {
        async get() {
          return { prstudioActiveTask: state, prstudioHardRecovery: marker };
        },
        async set(value) {
          writes.push(value);
          marker = value.prstudioHardRecovery;
        },
      },
    },
    runtime: { reload() { reloads += 1; } },
  };

  assert.equal(installHardTaskWatchdog(fakeChrome), true);
  assert.deepEqual(created, [{
    name: HARD_TASK_WATCHDOG_ALARM,
    spec: {
      delayInMinutes: HARD_TASK_WATCHDOG_PERIOD_MINUTES,
      periodInMinutes: HARD_TASK_WATCHDOG_PERIOD_MINUTES,
    },
  }]);
  assert.equal(listeners.length, 1);

  await listeners[0]({ name: HARD_TASK_WATCHDOG_ALARM });
  assert.equal(reloads, 1);
  assert.equal(writes.length, 1);
  assert.equal(writes[0].prstudioHardRecovery.taskId, 'task-stalled');
  assert.equal(writes[0].prstudioHardRecovery.attemptId, 'attempt-1');
  assert.equal(writes[0].prstudioHardRecovery.reason, 'hard_no_progress_reload');

  // Same task attempt cannot cause a reload loop inside the cooldown window.
  await listeners[0]({ name: HARD_TASK_WATCHDOG_ALARM });
  assert.equal(reloads, 1);
});
