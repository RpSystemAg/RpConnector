import test from "node:test";
import assert from "node:assert/strict";

function eventApi() { return { addListener() {}, removeListener() {} }; }
function storageArea() {
  const store = new Map();
  return {
    async get(keys) {
      if (keys == null) return Object.fromEntries(store);
      const out = {};
      const list = Array.isArray(keys) ? keys : typeof keys === "string" ? [keys] : Object.keys(keys || {});
      for (const key of list) if (store.has(key)) out[key] = store.get(key);
      return out;
    },
    async set(value) { for (const [key, item] of Object.entries(value || {})) store.set(key, item); },
    async remove(keys) { for (const key of (Array.isArray(keys) ? keys : [keys])) store.delete(key); },
  };
}
const local = storageArea();
const session = storageArea();
const noop = async () => undefined;
globalThis.chrome = {
  runtime: { onInstalled: eventApi(), onStartup: eventApi(), onMessage: eventApi(), onConnect: eventApi(), getURL: (path) => `chrome-extension://test/${path}` },
  alarms: { onAlarm: eventApi(), create: noop, clear: async () => true, get: async () => null },
  tabs: { onCreated: eventApi(), onReplaced: eventApi(), onRemoved: eventApi(), onActivated: eventApi(), onUpdated: eventApi(), query: async () => [], get: async () => { throw new Error("tab_missing"); }, update: noop, create: async () => ({ id: 1, windowId: 1, url: "about:blank" }), remove: noop, captureVisibleTab: async () => "" },
  windows: { onRemoved: eventApi(), onFocusChanged: eventApi(), getAll: async () => [], get: async () => { throw new Error("window_missing"); }, create: async () => ({ id: 1, tabs: [] }), update: noop, remove: noop, WINDOW_ID_NONE: -1 },
  debugger: { onDetach: eventApi(), onEvent: eventApi(), getTargets: async () => [], attach: noop, detach: noop, sendCommand: noop },
  storage: { local, session }, action: { setBadgeText: noop, setBadgeBackgroundColor: noop }, scripting: { executeScript: async () => [] }, notifications: { create: noop },
};
delete globalThis.TextDetector;
const { __test } = await import(`../service-worker.js?capability-truth=${Date.now()}`);

test("OCR capability separates support from current provider availability", () => {
  const cap = __test.capabilities(null);
  assert.equal(cap.ocrSupported, true);
  assert.equal(cap.ocrAvailableNow, false);
  assert.equal(cap.ocr, false, "legacy availability flag must not claim an unavailable optical provider");
  assert.equal(cap.ocrServer, false);
  assert.equal(cap.ocrServerAvailableNow, false);
  assert.equal(cap.ocrBrowserNative, false);
  assert.equal(cap.ocrProvider, "none");
  assert.equal(cap.ocrExecutionScope, "owned_http_https_tab");
  assert.ok(Array.isArray(cap.ocrPrerequisites) && cap.ocrPrerequisites.length > 0);
});

test("OCR reports a verified server provider when heartbeat capabilities prove it", () => {
  const cap = __test.capabilities({ ocr: { server_available: true, provider: "tesseract-cli" } });
  assert.equal(cap.ocrSupported, true);
  assert.equal(cap.ocrAvailableNow, true);
  assert.equal(cap.ocr, true);
  assert.equal(cap.ocrServer, true);
  assert.equal(cap.ocrServerAvailableNow, true);
  assert.equal(cap.ocrProvider, "server_tesseract");
});

test("OCR does not infer browser-native availability from TextDetector constructor presence alone", () => {
  globalThis.TextDetector = class TextDetector {};
  try {
    const cap = __test.capabilities(null);
    assert.equal(cap.ocrBrowserNativeSupported, true);
    assert.equal(cap.ocrBrowserNativeAvailableNow, false, "constructor presence is not provider availability evidence");
    assert.equal(cap.ocrAvailableNow, false);
    assert.equal(cap.ocrProvider, "none");
  } finally {
    delete globalThis.TextDetector;
  }
});
