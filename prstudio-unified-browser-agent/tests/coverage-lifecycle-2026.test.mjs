import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '..', 'service-worker.js'), 'utf8');
const policy = fs.readFileSync(path.join(here, '..', 'lib', 'policy.js'), 'utf8');

test('JS precise coverage is stopped before the Profiler domain is disabled', () => {
  const block = source.slice(source.indexOf('if (action === "playwright_stop_js_coverage")'), source.indexOf('if (action === "playwright_start_css_coverage")'));
  assert.ok(block.indexOf('Profiler.stopPreciseCoverage') >= 0);
  assert.ok(block.indexOf('Profiler.disable') > block.indexOf('Profiler.stopPreciseCoverage'));
  assert.ok(block.indexOf('closeRuntimeSession') > block.indexOf('Profiler.stopPreciseCoverage'));
  assert.match(policy, /"Profiler\.disable"/);
});

test('CSS coverage releases CSS and DOM domains without globally disabling Network', () => {
  const block = source.slice(source.indexOf('if (action === "playwright_stop_css_coverage")'), source.indexOf('if (action === "playwright_cdp_subscribe")'));
  assert.ok(block.indexOf('CSS.stopRuleUsageTracking') >= 0);
  assert.ok(block.indexOf('CSS.disable') > block.indexOf('CSS.stopRuleUsageTracking'));
  assert.ok(block.indexOf('DOM.disable') > block.indexOf('CSS.stopRuleUsageTracking'));
  assert.equal(/Network\.disable/.test(block), false);
});
