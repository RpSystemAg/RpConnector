import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

await import(`../lib/reconnect-backoff.js?test=${Date.now()}`);
await import(`../lib/runtime-dirty-notifier.js?test=${Date.now()}`);
const create = globalThis.__PRSTUDIO_RECONNECT_BACKOFF_V1__?.create;
const createDirtyNotifier = globalThis.__PRSTUDIO_RUNTIME_DIRTY_NOTIFIER_V1__?.create;

test('page-runtime reconnects are single-flight and exponentially bounded', () => {
  let now = 1_000;
  let nextTimerId = 1;
  const jobs = new Map();
  const policy = create({
    baseDelayMs: 250,
    maxDelayMs: 10_000,
    stableConnectionMs: 5_000,
    jitterRatio: 0,
    now: () => now,
    random: () => 0,
    scheduleTimer(fn, delayMs) {
      const id = nextTimerId++;
      jobs.set(id, { fn, delayMs });
      return id;
    },
    clearTimer(id) { jobs.delete(id); },
  });

  const delays = [];
  for (let attempt = 0; attempt < 8; attempt += 1) {
    policy.markConnected();
    now += 10;
    const scheduled = policy.schedule(() => {});
    delays.push(scheduled.delayMs);
    assert.equal(policy.schedule(() => {}).scheduled, false, 'a second pending reconnect must not be queued');
    const [id, job] = jobs.entries().next().value;
    jobs.delete(id);
    job.fn();
  }
  assert.deepEqual(delays, [250, 500, 1000, 2000, 4000, 8000, 10000, 10000]);
  assert.equal(jobs.size, 0);
});

test('a stable page-runtime connection resets reconnect delay', () => {
  let now = 2_000;
  const jobs = [];
  const policy = create({
    jitterRatio: 0,
    now: () => now,
    scheduleTimer(fn, delayMs) { jobs.push({ fn, delayMs }); return jobs.length; },
    clearTimer() {},
  });
  policy.markConnected();
  now += 10;
  assert.equal(policy.schedule(() => {}).delayMs, 250);
  jobs.shift().fn();
  policy.markConnected();
  now += 5_100;
  assert.equal(policy.schedule(() => {}).delayMs, 250);
});

test('manifest loads reconnect policy before the isolated page runtime', async () => {
  const manifest = JSON.parse(await readFile(new URL('../manifest.json', import.meta.url), 'utf8'));
  const isolated = manifest.content_scripts.find((entry) => entry.world === 'ISOLATED');
  assert.deepEqual(isolated.js.slice(-3), ['lib/reconnect-backoff.js', 'lib/runtime-dirty-notifier.js', 'page-runtime.js']);
});

test('install manifest keeps the 1.0.0 MV3 identity and every referenced file exists', async () => {
  const manifest = JSON.parse(await readFile(new URL('../manifest.json', import.meta.url), 'utf8'));
  const build = JSON.parse(await readFile(new URL('../BUILD-INFO.json', import.meta.url), 'utf8'));
  assert.equal(manifest.manifest_version, 3);
  assert.equal(manifest.version, '1.0.0');
  assert.equal(build.version, manifest.version);
  assert.equal(build.product_version, manifest.version);
  assert.ok(Number(manifest.minimum_chrome_version) >= 120);
  assert.equal(manifest.permissions.includes('desktopCapture'), false, 'tab-only LIVE must not regain desktop capture');

  const referenced = new Set([
    manifest.background?.service_worker,
    manifest.side_panel?.default_path,
    ...Object.values(manifest.icons || {}),
    ...Object.values(manifest.action?.default_icon || {}),
    ...manifest.content_scripts.flatMap((entry) => entry.js || []),
  ]);
  referenced.delete(undefined);
  for (const relative of referenced) {
    const bytes = await readFile(new URL(`../${relative}`, import.meta.url));
    assert.ok(bytes.length > 0, `${relative} is missing or empty`);
  }
});

test('DOM invalidation sends once per dirty epoch and resynchronizes before a request', () => {
  const messages = [];
  const notifier = createDirtyNotifier((message) => messages.push(message));
  assert.equal(notifier.notify(2, 'https://example.test/'), true);
  for (let version = 3; version <= 1_000; version += 1) {
    assert.equal(notifier.notify(version, 'https://example.test/'), false);
  }
  assert.equal(messages.length, 1, 'animation-heavy mutation bursts must not emit one Port message per mutation');
  assert.equal(notifier.synchronize(1_000, 'https://example.test/'), true);
  assert.equal(notifier.notify(1_001, 'https://example.test/'), true);
  assert.deepEqual(messages.map((message) => message.domVersion), [2, 1_000, 1_001]);
});
