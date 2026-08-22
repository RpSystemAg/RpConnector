import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const source = await readFile(new URL('../page-runtime.js', import.meta.url), 'utf8');

test('page-runtime DOM click defaults to one dispatch and reports truthful evidence', () => {
  assert.match(source, /const clickCount = Number\(args\.clickCount \?\? args\.click_count \?\? 1\);/);
  assert.match(source, /Number\.isSafeInteger\(clickCount\).*clickCount < 1.*clickCount > 3/s);
  assert.match(source, /for \(let i = 0; i < clickCount; i \+= 1\) HTMLElement\.prototype\.click\.call\(element\);/);
  assert.match(source, /dispatch: \{ transport: "dom_click", dispatched: clickCount, trusted: false \}/);
  assert.doesNotMatch(source, /for \(let i = 0; i < args\.clickCount; i \+= 1\) element\.click\(\);/);
});
