import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (relative) => readFileSync(new URL(`../../${relative}`, import.meta.url), 'utf8');

test('browser execution cannot silently fall back to local Playwright/Python runtime', () => {
  const source = read('prstudio-unified-control/includes/class-prstudio-uc-backend-executability.php');
  assert.doesNotMatch(source, /PRSTUDIO_Browser_Runtime::instance\s*\(/);
  assert.match(source, /legacy_runtime_fallback'\s*=>\s*false/);
  assert.match(source, /richiede il Browser Agent Chrome associato/);
});

test('production Browser Agent is bootstrapped through Chrome-native runtime hardening', () => {
  const manifest = JSON.parse(read('prstudio-unified-browser-agent/manifest.json'));
  assert.equal(manifest.background?.service_worker, 'service-worker-bootstrap.js');
  const bootstrap = read('prstudio-unified-browser-agent/service-worker-bootstrap.js');
  assert.match(bootstrap, /installBrowserParityRuntime/);
  assert.match(bootstrap, /import\('\.\/service-worker\.js'\)/);
});
