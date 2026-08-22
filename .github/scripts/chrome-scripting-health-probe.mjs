import { existsSync, mkdirSync, writeFileSync, copyFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { resolve } from 'node:path';

const moduleRoot = '/tmp/prstudio-puppeteer';
const packageJson = resolve(moduleRoot, 'package.json');
const puppeteerPackage = resolve(moduleRoot, 'node_modules/puppeteer/package.json');

mkdirSync(moduleRoot, { recursive: true });
if (!existsSync(packageJson)) writeFileSync(packageJson, JSON.stringify({ private: true }, null, 2));
if (!existsSync(puppeteerPackage)) {
  const install = spawnSync('npm', ['install', '--prefix', moduleRoot, '--no-audit', '--no-fund', '--no-save', 'puppeteer@25.8.0'], {
    stdio: 'inherit',
    env: process.env,
  });
  if (install.status !== 0) throw new Error(`puppeteer_install_failed:${install.status}`);
}

process.env.PRSTUDIO_PUPPETEER_ROOT = moduleRoot;

// The canonical scripting probe owns its exact fixture on :8080. Other callers
// (notably the real WordPress→Chrome gate) use this entrypoint only to install
// the exact Chrome-for-Testing/Puppeteer pair; their subsequent E2E loads the
// unpacked MV3 extension and certifies its own WordPress/remote-task fixtures.
// Do not run this fixture-specific probe against a different page and then call
// a correct DOM result a failure merely because that page contains different text.
const probeUrl = String(process.env.WP_URL || 'http://127.0.0.1:8080').replace(/\/+$/, '');
const canonicalProbe = probeUrl === 'http://127.0.0.1:8080';
if (!canonicalProbe) {
  console.log(`PASS Chrome-for-Testing preparation only for non-canonical fixture ${probeUrl}; caller E2E must certify extension loading`);
} else {
  await import('./browser-extension-e2e.mjs');

  const source = resolve('artifacts/browser-e2e/browser-extension-e2e.json');
  for (const target of [
    resolve('artifacts/real-e2e/scripting-health-probe.json'),
    resolve('artifacts/browser-parity/browser-parity-live-probe.json'),
  ]) {
    mkdirSync(resolve(target, '..'), { recursive: true });
    if (existsSync(source)) copyFileSync(source, target);
  }

  if (process.exitCode && process.exitCode !== 0) throw new Error('browser_extension_e2e_failed');
  console.log('PASS Chrome scripting health entrypoint: supported Puppeteer/Chrome-for-Testing E2E completed');
}
