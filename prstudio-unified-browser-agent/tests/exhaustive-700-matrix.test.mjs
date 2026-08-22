import test from 'node:test';
import assert from 'node:assert/strict';
import { runExhaustive700Matrix } from '../../.github/scripts/exhaustive-700-matrix.mjs';

test('cumulative 700 controls plus 700 tests for every required scope and every tracked file', async () => {
  const summary = await runExhaustive700Matrix();
  assert.equal(summary.ok, true);
  assert.equal(summary.fixed_scope_count, 6);
  assert.equal(summary.scope_count, summary.tracked_file_count + 6);
  assert.equal(summary.controls.static_actual, 350 * summary.scope_count);
  assert.equal(summary.controls.dynamic_actual, 350 * summary.scope_count);
  assert.equal(summary.controls.actual, 700 * summary.scope_count);
  assert.equal(summary.tests.actual, 700 * summary.scope_count);
  assert.equal(summary.total_actual, 1400 * summary.scope_count);
  assert.equal(summary.controls.failed, 0);
  assert.equal(summary.tests.failed, 0);
  assert.equal(summary.controls.unique_variables, summary.controls.actual);
  assert.equal(summary.tests.unique_variables, summary.tests.actual);
});
