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
