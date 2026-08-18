import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const here = path.dirname(fileURLToPath(import.meta.url));
const source = fs.readFileSync(path.join(here, '..', 'lib', 'web-vitals-runtime.js'), 'utf8');

function harness() {
  const observers = new Map();
  class PerformanceObserver {
    static supportedEntryTypes = ['largest-contentful-paint', 'layout-shift', 'event', 'first-input'];
    constructor(callback) { this.callback = callback; }
    observe(options) { observers.set(options.type, this); }
    disconnect() {}
  }
  class PerformanceEventTiming {}
  Object.defineProperty(PerformanceEventTiming.prototype, 'interactionId', { value: 0, configurable: true });

  const context = vm.createContext({
    console,
    Date,
    Math,
    Map,
    Set,
    Object,
    Number,
    String,
    Infinity,
    PerformanceObserver,
    PerformanceEventTiming,
    performance: {
      interactionCount: 51,
      now: () => 10000,
      getEntriesByType: (type) => type === 'navigation' ? [{ type: 'navigate', activationStart: 100 }] : [],
    },
    document: {
      visibilityState: 'visible',
      addEventListener() {},
    },
    location: { href: 'https://example.test/' },
    addEventListener() {},
  });
  vm.runInContext('globalThis.top = globalThis;', context);
  vm.runInContext(source, context, { filename: 'web-vitals-runtime.js' });

  const emit = (type, entries) => {
    const observer = observers.get(type);
    assert.ok(observer, `observer for ${type}`);
    observer.callback({ getEntries: () => entries });
  };
  return { context, emit };
}

test('CWV runtime reports LCP/CLS/INP using web-vitals-compatible selection semantics', () => {
  const { context, emit } = harness();

  emit('largest-contentful-paint', [{ entryType: 'largest-contentful-paint', name: '', startTime: 2400, renderTime: 2400, duration: 0, size: 1000 }]);
  emit('layout-shift', [
    { entryType: 'layout-shift', name: '', startTime: 100, duration: 0, value: 0.08, hadRecentInput: false },
    { entryType: 'layout-shift', name: '', startTime: 900, duration: 0, value: 0.09, hadRecentInput: false },
    { entryType: 'layout-shift', name: '', startTime: 7000, duration: 0, value: 0.2, hadRecentInput: false },
  ]);
  emit('event', [
    { entryType: 'event', name: 'click', startTime: 3000, duration: 500, interactionId: 7 },
    { entryType: 'event', name: 'keydown', startTime: 4000, duration: 400, interactionId: 14 },
    { entryType: 'event', name: 'click', startTime: 5000, duration: 100, interactionId: 21 },
  ]);

  const snapshot = vm.runInContext('globalThis.__PRSTUDIO_WEB_VITALS__.snapshot()', context);
  assert.equal(snapshot.source, 'google_web_vitals_6.0.1_algorithm_port');
  assert.equal(snapshot.librarySemantics, 'web-vitals@6.0.1');
  assert.equal(snapshot.metrics.LCP.value, 2300); // LCP minus prerender activationStart.
  assert.equal(snapshot.metrics.CLS.value, 0.2); // max session window, not cumulative page sum.
  assert.equal(snapshot.metrics.INP.value, 400); // p98 selector at floor(51 / 50) => second-longest.
  assert.equal(snapshot.metrics.INP.interactionId, 14);
});

test('CWV runtime preserves interaction-count estimator and reports BFCache fallback semantics', () => {
  const observers = new Map();
  let pageShow;
  const rafQueue = [];
  class PerformanceObserver {
    static supportedEntryTypes = ['largest-contentful-paint', 'layout-shift', 'event', 'first-input'];
    constructor(callback) { this.callback = callback; }
    observe(options) { observers.set(options.type, this); }
  }
  class PerformanceEventTiming {}
  Object.defineProperty(PerformanceEventTiming.prototype, 'interactionId', { value: 0, configurable: true });
  let clock = 1000;
  const context = vm.createContext({
    console, Date, Math, Map, Set, Object, Number, String, Infinity, setTimeout,
    PerformanceObserver, PerformanceEventTiming,
    performance: { now: () => clock, getEntriesByType: (type) => type === 'navigation' ? [{ type: 'navigate', activationStart: 0 }] : [] },
    document: { visibilityState: 'visible', addEventListener() {} },
    location: { href: 'https://example.test/' },
    requestAnimationFrame: (cb) => { rafQueue.push(cb); return rafQueue.length; },
    addEventListener(type, callback) { if (type === 'pageshow') pageShow = callback; },
  });
  vm.runInContext('globalThis.top = globalThis;', context);
  vm.runInContext(source, context, { filename: 'web-vitals-runtime.js' });
  const eventObserver = observers.get('event');
  eventObserver.callback({ getEntries: () => [{ entryType:'event', name:'click', startTime:100, duration:8, interactionId:7 }] });
  pageShow({ persisted: true, timeStamp: 1000 });
  eventObserver.callback({ getEntries: () => [{ entryType:'event', name:'click', startTime:1010, duration:8, interactionId:14 }] });
  let snapshot = vm.runInContext('globalThis.__PRSTUDIO_WEB_VITALS__.snapshot()', context);
  assert.equal(snapshot.navigation.type, 'back-forward-cache');
  assert.equal(snapshot.interactionCount, 1);
  assert.equal(snapshot.metrics.INP.value, 8);
  clock = 1016;
  rafQueue.shift()();
  rafQueue.shift()();
  snapshot = vm.runInContext('globalThis.__PRSTUDIO_WEB_VITALS__.snapshot()', context);
  assert.equal(snapshot.metrics.LCP.value, 16);
  assert.equal(snapshot.metrics.LCP.syntheticReason, 'bfcache_restore_double_raf');
});
