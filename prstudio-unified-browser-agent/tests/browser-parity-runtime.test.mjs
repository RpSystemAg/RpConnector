import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { installBrowserParityRuntime } from '../lib/browser-parity-runtime.js';

test('manifest loads the parity bootstrap and declares every Chrome API permission used by native input', () => {
  const manifest = JSON.parse(readFileSync(new URL('../manifest.json', import.meta.url), 'utf8'));
  assert.equal(manifest.background?.service_worker, 'service-worker-bootstrap.js');
  assert.ok(manifest.permissions.includes('debugger'));
  assert.ok(manifest.permissions.includes('scripting'));
  assert.ok(manifest.permissions.includes('tabs'));
  assert.ok(manifest.permissions.includes('tabGroups'));
  assert.ok(manifest.permissions.includes('system.display'));
});

test('parity runtime avoids all-window enumeration and recovers a stale window id', async () => {
  let enumerations = 0;
  let creates = 0;
  const createArgs = [];
  const fakeChrome = {
    windows: {
      async getAll() { enumerations += 1; return [{ id: 9 }]; },
      async getLastFocused() { return { id: 3, type: 'normal', tabs: [] }; },
      async get() { return { id: 3 }; },
    },
    tabs: {
      async create(props) {
        creates += 1;
        createArgs.push({ ...props });
        if ('windowId' in props) throw new Error('No window with id: 999');
        return { id: 41, windowId: 3, url: props.url };
      },
      async get(id) { return { id, windowId: 3 }; },
      async update(id, props) { return { id, ...props }; },
      async group() { return 7; },
    },
    scripting: { async executeScript() { return []; } },
    debugger: {
      async attach() {}, async detach() {}, async sendCommand() { return {}; },
    },
    storage: { local: { async set() {} } },
  };

  const installed = installBrowserParityRuntime(fakeChrome);
  assert.equal(installed.installed, true);

  const windows = await fakeChrome.windows.getAll({ populate: true, windowTypes: ['normal'] });
  assert.deepEqual(windows.map((row) => row.id), [3]);
  assert.equal(enumerations, 0, 'common path must not enumerate every Chrome window');

  const tab = await fakeChrome.tabs.create({ windowId: 999, url: 'https://example.com', active: false });
  assert.equal(tab.id, 41);
  assert.equal(tab.windowId, 3);
  assert.equal(creates, 2);
  assert.equal(createArgs[0].windowId, 999);
  assert.equal(Object.hasOwn(createArgs[1], 'windowId'), false, 'stale window retry must let Chrome choose the current window');
});

test('DOM/script observation failure captures visual evidence before surfacing the error', async () => {
  let attached = false;
  let stored = null;
  const fakeChrome = {
    windows: {
      async getAll() { return [{ id: 1 }]; },
      async getLastFocused() { return { id: 1, type: 'normal' }; },
      async get() { return { id: 1 }; },
    },
    tabs: {
      async create(props) { return { id: 5, windowId: 1, ...props }; },
      async get(id) { return { id, windowId: 1 }; },
      async update(id, props) { return { id, ...props }; },
      async group() { return 1; },
    },
    scripting: {
      async executeScript() { throw new Error('Cannot access contents of the page'); },
    },
    debugger: {
      async attach() { attached = true; },
      async detach() { attached = false; },
      async sendCommand(_target, method) {
        if (method === 'Page.enable' && !attached) throw new Error('Debugger is not attached');
        if (method === 'Page.captureScreenshot') return { data: Buffer.from('fake-png').toString('base64') };
        return {};
      },
    },
    storage: {
      local: {
        async set(value) { stored = value; },
      },
    },
  };

  installBrowserParityRuntime(fakeChrome);
  await assert.rejects(
    fakeChrome.scripting.executeScript({ target: { tabId: 5 }, func: () => document.title }),
    (error) => {
      assert.equal(error.details?.visualFallback?.captured, true);
      assert.equal(error.details?.visualFallback?.tabId, 5);
      assert.equal(error.details?.visualFallback?.storageKey, 'prstudioLastVisualFallback');
      return true;
    },
  );
  assert.ok(stored?.prstudioLastVisualFallback?.screenshot?.startsWith('data:image/png;base64,'));
  assert.equal(attached, false, 'debugger attached only for fallback must be detached afterwards');
});

test('successful CAPTCHA/MFA detection also stores a screenshot without bypassing the challenge', async () => {
  let attached = false;
  let stored = null;
  const fakeChrome = {
    windows: {
      async getAll() { return [{ id: 1 }]; },
      async getLastFocused() { return { id: 1, type: 'normal' }; },
      async get() { return { id: 1 }; },
    },
    tabs: {
      async create(props) { return { id: 6, windowId: 1, ...props }; },
      async get(id) { return { id, windowId: 1 }; },
      async update(id, props) { return { id, ...props }; },
      async group() { return 1; },
    },
    scripting: {
      async executeScript() {
        return [{ result: { reason: 'captcha_or_mfa', selector: "iframe[src*='recaptcha']", url: 'https://example.com/login' } }];
      },
    },
    debugger: {
      async attach() { attached = true; },
      async detach() { attached = false; },
      async sendCommand(_target, method) {
        if (method === 'Page.enable' && !attached) throw new Error('Debugger is not attached');
        if (method === 'Page.captureScreenshot') return { data: Buffer.from('captcha-shot').toString('base64') };
        return {};
      },
    },
    storage: {
      local: {
        async set(value) { stored = value; },
      },
    },
  };

  installBrowserParityRuntime(fakeChrome);
  const result = await fakeChrome.scripting.executeScript({ target: { tabId: 6 }, func: () => null });
  assert.equal(result[0].result.reason, 'captcha_or_mfa');
  assert.equal(stored?.prstudioLastVisualFallback?.captured, true);
  assert.equal(stored?.prstudioLastVisualFallback?.tabId, 6);
  assert.match(stored?.prstudioLastVisualFallback?.reason || '', /^captcha_or_mfa:/);
  assert.ok(stored?.prstudioLastVisualFallback?.screenshot?.startsWith('data:image/png;base64,'));
  assert.equal(attached, false);
});
