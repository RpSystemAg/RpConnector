import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, writeFile, unlink, readdir } from 'node:fs/promises';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = dirname(HERE);
const html = await readFile(join(ROOT, 'sidepanel.html'), 'utf8');
const source = await readFile(join(ROOT, 'sidepanel.js'), 'utf8');

class FakeClassList {
  constructor(initial = '') { this.values = new Set(String(initial).split(/\s+/u).filter(Boolean)); }
  toggle(name, force) {
    const on = force === undefined ? !this.values.has(name) : Boolean(force);
    if (on) this.values.add(name); else this.values.delete(name);
    return on;
  }
  contains(name) { return this.values.has(name); }
  toString() { return [...this.values].join(' '); }
}

class FakeElement {
  constructor(id = '') {
    this.id = id;
    this.dataset = {};
    this.listeners = new Map();
    this.classList = new FakeClassList();
    this.style = {};
    this.value = '';
    this.textContent = '';
    this.innerHTML = '';
    this.hidden = false;
    this.checked = false;
    this.files = [];
    this.children = [];
  }
  addEventListener(type, handler) {
    const list = this.listeners.get(type) || [];
    list.push(handler);
    this.listeners.set(type, list);
  }
  replaceChildren(...children) { this.children = children; }
  click() { return this.dispatch('click'); }
  closest() { return null; }
  async dispatch(type, extra = {}) {
    const event = { target: this, key: extra.key, ...extra };
    for (const handler of this.listeners.get(type) || []) await handler(event);
  }
  set className(value) { this._className = String(value); this.classList = new FakeClassList(value); }
  get className() { return this._className ?? this.classList.toString(); }
}

const ids = new Set([...html.matchAll(/\bid=["']([^"']+)["']/gu)].map((match) => match[1]));
const elements = new Map([...ids].map((id) => [id, new FakeElement(id)]));
const tabNames = [...html.matchAll(/class=["'][^"']*tabButton[^"']*["'][^>]*data-tab=["']([^"']+)["']/gu)].map((match) => match[1]);
const tabButtons = tabNames.map((name) => {
  const el = new FakeElement(`tab-button-${name}`);
  el.dataset.tab = name;
  if (name === 'local') el.className = 'tabButton active'; else el.className = 'tabButton';
  return el;
});
const tabPanes = tabNames.map((name) => {
  const el = elements.get(`tab-${name}`);
  el.className = name === 'local' ? 'tabPane active' : 'tabPane';
  return el;
});

const documentStub = {
  getElementById(id) { return elements.get(id) || null; },
  querySelectorAll(selector) {
    if (selector === '.tabButton') return tabButtons;
    if (selector === '.tabPane') return tabPanes;
    return [];
  },
  createElement(tag) {
    const el = new FakeElement();
    el.tagName = String(tag).toUpperCase();
    if (tag === 'canvas') el.getContext = () => ({ drawImage() {}, getImageData: () => ({ data: new Uint8ClampedArray() }), clearRect() {} });
    return el;
  },
};

globalThis.document = documentStub;
globalThis.setInterval = () => 1;
globalThis.clearInterval = () => {};

const calls = [];
globalThis.chrome = {
  runtime: {
    async sendMessage(message) {
      calls.push(message);
      if (message.type === 'local_status') return { ok: true, version: '17.0.0', tab: {}, workflows: [], workspaces: [], schedules: [], scheduledResults: [], baselines: [], flight: [] };
      if (message.type === 'status') return { paired: false, logs: [] };
      if (message.type === 'local_page_health') return { ok: true, result: { score: 100, h1Count: 1, imagesMissingAlt: 0, unlabeledControls: 0, duplicateIdCount: 0, badLinkCount: 0, schemaParseErrors: 0, resourceCount: 1, mixedContentCount: 0, navigation: {} } };
      if (message.type === 'pair') return { ok: true };
      return { ok: true, results: [], result: {}, report: {}, workflow: { name: 'x', steps: [] }, workspace: { tabs: [] } };
    },
  },
};

const modulePath = join(tmpdir(), `prstudio-sidepanel-${process.pid}-${Date.now()}.mjs`);
await writeFile(modulePath, source, 'utf8');
try {
  await import(`${pathToFileURL(modulePath).href}?v=${Date.now()}`);
  await new Promise((resolve) => setTimeout(resolve, 0));
} finally {
  await unlink(modulePath).catch(() => {});
}


test('every Browser Agent JavaScript file parses as an ES module', async () => {
  async function walk(dir) {
    const out = [];
    for (const entry of await readdir(dir, { withFileTypes: true })) {
      if (entry.name === 'tests') continue;
      const path = join(dir, entry.name);
      if (entry.isDirectory()) out.push(...await walk(path));
      else if (entry.isFile() && entry.name.endsWith('.js')) out.push(path);
    }
    return out;
  }
  for (const file of await walk(ROOT)) {
    const body = await readFile(file, 'utf8');
    const tmp = join(tmpdir(), `prstudio-module-${process.pid}-${Math.random().toString(16).slice(2)}.mjs`);
    await writeFile(tmp, body, 'utf8');
    try {
      const run = spawnSync(process.execPath, ['--check', tmp], { encoding: 'utf8' });
      assert.equal(run.status, 0, `${file} failed ES-module parse: ${run.stderr || run.stdout}`);
    } finally {
      await unlink(tmp).catch(() => {});
    }
  }
});

test('all literal sidepanel element references exist in HTML', () => {
  const refs = new Set([...source.matchAll(/\$\(["']([^"']+)["']\)/gu)].map((match) => match[1]));
  assert.deepEqual([...refs].filter((id) => !ids.has(id)), []);
});

test('all primary controls register click/change handlers', () => {
  const clickIds = [
    'runCommandButton', 'healthButton', 'debugButton', 'debugReloadButton', 'inspectorButton',
    'responsiveButton', 'siteScanButton', 'reportButton', 'recordStartButton', 'recordStopButton',
    'localCancelButton', 'baselineCaptureButton', 'baselineCompareButton',
    'workspaceSaveButton', 'exportButton', 'importButton', 'scheduleSaveButton', 'pairButton',
    'forgetButton', 'refreshButton', 'cancelButton',
  ];
  for (const id of clickIds) assert.ok((elements.get(id)?.listeners.get('click') || []).length > 0, `${id} has no click handler`);
  assert.ok((elements.get('originProfile')?.listeners.get('change') || []).length > 0);
  assert.ok((elements.get('importFile')?.listeners.get('change') || []).length > 0);
  assert.equal(tabButtons.length, 2);
  for (const button of tabButtons) assert.ok((button.listeners.get('click') || []).length > 0, `tab ${button.dataset.tab} has no click handler`);
});

test('Automation/Log tabs are interactive and pairing controls remain exposed', async () => {
  const logs = tabButtons.find((button) => button.dataset.tab === 'logs');
  await logs.dispatch('click');
  assert.ok(elements.get('tab-logs').classList.contains('active'));
  assert.ok(!elements.get('tab-automation').classList.contains('active'));
  const automation = tabButtons.find((button) => button.dataset.tab === 'automation');
  await automation.dispatch('click');
  assert.ok(elements.get('tab-automation').classList.contains('active'));
  assert.ok(elements.has('siteUrl') && elements.has('pairCode') && elements.has('deviceName') && elements.has('pairButton'));
});

test('pairing button actually dispatches the pair message', async () => {
  elements.get('siteUrl').value = 'https://example.com';
  elements.get('pairCode').value = 'PAIR-CODE';
  elements.get('deviceName').value = 'Chrome personale';
  const before = calls.length;
  await elements.get('pairButton').dispatch('click');
  await new Promise((resolve) => setTimeout(resolve, 0));
  const sent = calls.slice(before).find((entry) => entry.type === 'pair');
  assert.deepEqual(sent, { type: 'pair', siteUrl: 'https://example.com', code: 'PAIR-CODE', name: 'Chrome personale' });
});

test('empty quick command does not silently run the first action', async () => {
  elements.get('commandPalette').value = '';
  const before = calls.length;
  await elements.get('runCommandButton').dispatch('click');
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.equal(calls.slice(before).some((entry) => entry.type === 'local_page_health'), false);
  assert.match(elements.get('message').textContent, /Scrivi|Scegli|comando/i);
});

test('local audit button dispatches a real local action', async () => {
  const before = calls.length;
  await elements.get('healthButton').dispatch('click');
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.ok(calls.slice(before).some((entry) => entry.type === 'local_page_health'));
});


test('dashboard exposes inline auth challenge without manual resume/takeover queue', () => {
  assert.equal(ids.has('takeoverList'), false);
  assert.doesNotMatch(source, /pendingTakeovers|data-takeover-action|Riprendi/);
  assert.match(source, /authChallenge/);
  assert.match(source, /auto-ripresa attiva/);
});
