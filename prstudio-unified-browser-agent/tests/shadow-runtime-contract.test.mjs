import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';

test('dynamic Shadow DOM bridge is loaded in MAIN world before isolated runtime', async () => {
  const manifest = JSON.parse(await readFile(new URL('../manifest.json', import.meta.url), 'utf8'));
  const scripts = Array.isArray(manifest.content_scripts) ? manifest.content_scripts : [];
  const bridgeIndex = scripts.findIndex((row) => row?.world === 'MAIN' && row?.js?.includes('page-runtime-main.js'));
  const runtimeIndex = scripts.findIndex((row) => row?.world === 'ISOLATED' && row?.js?.includes('page-runtime.js'));
  assert.ok(bridgeIndex >= 0, 'page-runtime-main.js MAIN-world bridge missing');
  assert.ok(runtimeIndex >= 0, 'isolated page runtime missing');
  assert.ok(bridgeIndex < runtimeIndex, 'MAIN-world attachShadow bridge must be registered before isolated runtime');
  for (const index of [bridgeIndex, runtimeIndex]) {
    assert.equal(scripts[index].run_at, 'document_start');
    assert.equal(scripts[index].all_frames, true);
    assert.equal(scripts[index].match_about_blank, true);
    assert.deepEqual(scripts[index].matches, ['<all_urls>']);
  }
});

test('Shadow bridge emits a composed attach event consumed by deep runtime indexing', async () => {
  const bridge = await readFile(new URL('../page-runtime-main.js', import.meta.url), 'utf8');
  const runtime = await readFile(new URL('../page-runtime.js', import.meta.url), 'utf8');
  assert.match(bridge, /proto\.attachShadow\s*=\s*function/);
  assert.match(bridge, /__prstudio_shadow_root_attached/);
  assert.match(bridge, /bubbles:\s*true/);
  assert.match(bridge, /composed:\s*true/);
  assert.match(runtime, /document\.addEventListener\('__prstudio_shadow_root_attached'/);
  assert.match(runtime, /observeRoot\(host\.shadowRoot\)/);
  assert.match(runtime, /element\.shadowRoot/);
  assert.match(runtime, /deepQueryAll/);
});
