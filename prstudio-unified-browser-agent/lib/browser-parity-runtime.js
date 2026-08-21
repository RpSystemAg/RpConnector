const DEFAULT_TIMEOUT_MS = 30000;
const VISUAL_FALLBACK_STORAGE_KEY = 'prstudioLastVisualFallback';
const TAB_REGISTRY_STORAGE_KEY = 'prstudioTabRegistry';
const MAX_VISUAL_FALLBACK_BYTES = 2_000_000;

function timeoutError(label, timeoutMs) {
  const error = new Error(`${label} timed out after ${timeoutMs}ms`);
  error.name = 'TimeoutError';
  error.code = 'CHROME_API_TIMEOUT';
  error.details = { label, timeoutMs };
  return error;
}

function withTimeout(promise, timeoutMs, label) {
  let timer;
  return Promise.race([
    Promise.resolve(promise),
    new Promise((_, reject) => {
      timer = setTimeout(() => reject(timeoutError(label, timeoutMs)), timeoutMs);
    }),
  ]).finally(() => clearTimeout(timer));
}

function replaceMethod(target, key, factory) {
  if (!target || typeof target[key] !== 'function') return false;
  const original = target[key].bind(target);
  const replacement = factory(original);
  try {
    target[key] = replacement;
    if (target[key] === replacement) return true;
  } catch {}
  try {
    Object.defineProperty(target, key, { configurable: true, writable: true, value: replacement });
    return target[key] === replacement;
  } catch {
    return false;
  }
}

function sameNormalWindowQuery(options = {}) {
  const types = Array.isArray(options?.windowTypes) ? options.windowTypes : [];
  return types.length === 0 || (types.length === 1 && types[0] === 'normal');
}

function staleWindowError(error) {
  if (String(error?.code || '') === 'CHROME_API_TIMEOUT') return false;
  const text = String(error?.message || error || '').toLowerCase();
  return /no window with id|invalid window id|window[^\n]{0,40}(not found|does not exist|closed)/i.test(text);
}

function authChallengeFromScriptResults(results) {
  if (!Array.isArray(results)) return null;
  for (const row of results) {
    const value = row?.result;
    if (value && typeof value === 'object' && value.reason === 'captcha_or_mfa') return value;
  }
  return null;
}

async function ownedWindows(chromeApi, originals, populate) {
  if (!populate || !chromeApi.storage?.local?.get || !originals.tabsGet || !originals.windowsGet) return [];
  try {
    const stored = await withTimeout(chromeApi.storage.local.get(TAB_REGISTRY_STORAGE_KEY), 5000, 'owned-windows.storage');
    const registry = stored?.[TAB_REGISTRY_STORAGE_KEY];
    if (!registry || typeof registry !== 'object' || Array.isArray(registry)) return [];
    const tabIds = Object.keys(registry).map(Number).filter((id) => Number.isInteger(id) && id > 0).slice(0, 250);
    const windowIds = new Set();
    for (const tabId of tabIds) {
      try {
        const tab = await withTimeout(originals.tabsGet(tabId), 3000, `owned-windows.tab:${tabId}`);
        if (Number.isInteger(tab?.windowId)) windowIds.add(tab.windowId);
      } catch {}
    }
    const windows = [];
    for (const windowId of windowIds) {
      try {
        const win = await withTimeout(originals.windowsGet(windowId, { populate: true }), 5000, `owned-windows.window:${windowId}`);
        if (win?.id) windows.push(win);
      } catch {}
    }
    return windows;
  } catch {
    return [];
  }
}

async function captureVisualFallback(chromeApi, originals, tabId, reason) {
  const id = Number(tabId || 0);
  if (!id || !originals.debuggerAttach || !originals.debuggerSendCommand) return null;
  let attachedHere = false;
  try {
    try {
      await withTimeout(originals.debuggerSendCommand({ tabId: id }, 'Page.enable', {}), 5000, 'visual-fallback.Page.enable');
    } catch {
      await withTimeout(originals.debuggerAttach({ tabId: id }, '1.3'), 8000, 'visual-fallback.debugger.attach');
      attachedHere = true;
      await withTimeout(originals.debuggerSendCommand({ tabId: id }, 'Page.enable', {}), 5000, 'visual-fallback.Page.enable');
    }
    const shot = await withTimeout(
      originals.debuggerSendCommand({ tabId: id }, 'Page.captureScreenshot', { format: 'png', fromSurface: false, captureBeyondViewport: false }),
      12000,
      'visual-fallback.Page.captureScreenshot',
    );
    const base64 = String(shot?.data || '');
    if (!base64) return null;
    const approxBytes = Math.ceil(base64.length * 0.75);
    const payload = {
      captured: true,
      tabId: id,
      at: Date.now(),
      reason: String(reason || 'browser_observation_failed').slice(0, 500),
      mime: 'image/png',
      bytes: approxBytes,
      screenshot: approxBytes <= MAX_VISUAL_FALLBACK_BYTES ? `data:image/png;base64,${base64}` : null,
      truncated: approxBytes > MAX_VISUAL_FALLBACK_BYTES,
    };
    await chromeApi.storage?.local?.set?.({ [VISUAL_FALLBACK_STORAGE_KEY]: payload });
    return { ...payload, screenshot: payload.screenshot ? '[stored]' : null, storageKey: VISUAL_FALLBACK_STORAGE_KEY };
  } catch {
    return null;
  } finally {
    if (attachedHere && originals.debuggerDetach) {
      await Promise.resolve(originals.debuggerDetach({ tabId: id })).catch(() => {});
    }
  }
}

function augmentError(error, visualFallback) {
  if (!visualFallback) return error;
  const wrapped = error instanceof Error ? error : new Error(String(error));
  wrapped.code = wrapped.code || 'BROWSER_OBSERVATION_FAILED';
  wrapped.details = { ...(wrapped.details || {}), visualFallback };
  return wrapped;
}

/**
 * Chrome-native compatibility layer loaded before the main MV3 service worker.
 * It does not emulate Playwright/Puppeteer. It bounds extension API calls,
 * avoids full-window enumeration on the common path, retries only genuinely
 * stale window ids, preserves windows that contain owned tabs, and captures
 * visual evidence when semantic page observation cannot proceed.
 */
export function installBrowserParityRuntime(chromeApi = globalThis.chrome) {
  if (!chromeApi) return { installed: false, patches: [] };
  const patches = [];
  const originals = {
    debuggerAttach: chromeApi.debugger?.attach?.bind(chromeApi.debugger),
    debuggerDetach: chromeApi.debugger?.detach?.bind(chromeApi.debugger),
    debuggerSendCommand: chromeApi.debugger?.sendCommand?.bind(chromeApi.debugger),
    tabsGet: chromeApi.tabs?.get?.bind(chromeApi.tabs),
    windowsGet: chromeApi.windows?.get?.bind(chromeApi.windows),
    windowsGetLastFocused: chromeApi.windows?.getLastFocused?.bind(chromeApi.windows),
  };

  if (chromeApi.windows?.getAll && originals.windowsGetLastFocused) {
    const ok = replaceMethod(chromeApi.windows, 'getAll', (originalGetAll) => async (options = {}) => {
      if (sameNormalWindowQuery(options)) {
        // Recovery is allowed to locate already-owned tabs without enumerating
        // every Chrome window. This is what prevents a restart from "losing"
        // an agent tab living in a non-focused window.
        const known = await ownedWindows(chromeApi, originals, Boolean(options?.populate));
        if (known.length) return known;
        try {
          const current = await withTimeout(
            originals.windowsGetLastFocused({ populate: Boolean(options?.populate), windowTypes: ['normal'] }),
            8000,
            'chrome.windows.getLastFocused',
          );
          if (current?.id) return [current];
        } catch {}
      }
      return withTimeout(originalGetAll(options), 10000, 'chrome.windows.getAll.fallback');
    });
    if (ok) patches.push('windows.getAll->ownedOrLastFocused');
  }

  if (chromeApi.tabs?.create) {
    const ok = replaceMethod(chromeApi.tabs, 'create', (originalCreate) => async (properties = {}) => {
      try {
        return await withTimeout(originalCreate(properties), 15000, 'chrome.tabs.create');
      } catch (error) {
        // Only a proven stale window id gets a second create. Retrying after an
        // ambiguous timeout can duplicate a tab whose first create completed.
        if (Object.prototype.hasOwnProperty.call(properties || {}, 'windowId') && staleWindowError(error)) {
          const retry = { ...properties };
          delete retry.windowId;
          return withTimeout(originalCreate(retry), 15000, 'chrome.tabs.create.currentWindowFallback');
        }
        throw error;
      }
    });
    if (ok) patches.push('tabs.create');
  }

  for (const [target, key, timeoutMs] of [
    [chromeApi.tabs, 'get', 10000],
    [chromeApi.tabs, 'update', 30000],
    [chromeApi.tabs, 'group', 15000],
    [chromeApi.windows, 'get', 10000],
    [chromeApi.windows, 'getLastFocused', 10000],
  ]) {
    if (!target?.[key]) continue;
    const ok = replaceMethod(target, key, (original) => (...args) => withTimeout(original(...args), timeoutMs, `chrome.${key}`));
    if (ok) patches.push(key);
  }

  if (chromeApi.scripting?.executeScript) {
    const ok = replaceMethod(chromeApi.scripting, 'executeScript', (originalExecuteScript) => async (details) => {
      try {
        const results = await withTimeout(originalExecuteScript(details), 30000, 'chrome.scripting.executeScript');
        const authChallenge = authChallengeFromScriptResults(results);
        if (authChallenge && details?.target?.tabId) {
          await captureVisualFallback(
            chromeApi,
            originals,
            details.target.tabId,
            `captcha_or_mfa:${authChallenge.selector || authChallenge.marker || authChallenge.url || ''}`,
          );
        }
        return results;
      } catch (error) {
        const visual = await captureVisualFallback(chromeApi, originals, details?.target?.tabId, error?.message || error);
        throw augmentError(error, visual);
      }
    });
    if (ok) patches.push('scripting.executeScript+visualFallback');
  }

  if (chromeApi.debugger?.attach && originals.debuggerAttach) {
    const ok = replaceMethod(chromeApi.debugger, 'attach', () => (...args) => withTimeout(originals.debuggerAttach(...args), 15000, 'chrome.debugger.attach'));
    if (ok) patches.push('debugger.attach');
  }
  if (chromeApi.debugger?.sendCommand && originals.debuggerSendCommand) {
    const ok = replaceMethod(chromeApi.debugger, 'sendCommand', () => (target, method, params = {}) => {
      const timeoutMs = method === 'Page.captureScreenshot' ? 15000 : DEFAULT_TIMEOUT_MS;
      return withTimeout(originals.debuggerSendCommand(target, method, params), timeoutMs, `chrome.debugger.sendCommand:${method}`);
    });
    if (ok) patches.push('debugger.sendCommand');
  }

  return { installed: patches.length > 0, patches };
}

export const BROWSER_PARITY_RUNTIME = Object.freeze({
  version: '1.2.0',
  visualFallbackStorageKey: VISUAL_FALLBACK_STORAGE_KEY,
  defaultTimeoutMs: DEFAULT_TIMEOUT_MS,
});
