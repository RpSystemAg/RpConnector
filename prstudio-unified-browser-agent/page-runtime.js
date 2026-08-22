(() => {
  if (globalThis.__PRSTUDIO_PAGE_RUNTIME_V3_BOOTSTRAPPED__) return;
  globalThis.__PRSTUDIO_PAGE_RUNTIME_V3_BOOTSTRAPPED__ = true;
  const INTERACTIVE_SELECTOR = "a[href],button,input:not([type='hidden']),textarea,select,summary,[role],[tabindex],[contenteditable='true'],[onclick]";
  const interactive = new Set();
  const observedRoots = new WeakSet();
  const mutationSubscribers = new Set();
  let domVersion = 1;
  let port = null;
  const reconnectBackoff = globalThis.__PRSTUDIO_RECONNECT_BACKOFF_V1__?.create?.({
    baseDelayMs: 250,
    maxDelayMs: 10_000,
    stableConnectionMs: 5_000,
    jitterRatio: 0.2,
  });
  const dirtyNotifier = globalThis.__PRSTUDIO_RUNTIME_DIRTY_NOTIFIER_V1__?.create?.((message) => {
    if (!port) throw new Error('page_runtime_port_unavailable');
    port.postMessage(message);
  });

  const matchesInteractive = (node) => node?.nodeType === 1 && node.matches?.(INTERACTIVE_SELECTOR);
  const scanNode = (node) => {
    if (!node) return;
    if (matchesInteractive(node)) interactive.add(node);
    if (node.querySelectorAll) {
      for (const element of node.querySelectorAll(INTERACTIVE_SELECTOR)) interactive.add(element);
      for (const element of node.querySelectorAll('*')) if (element.shadowRoot) observeRoot(element.shadowRoot);
    }
    if (node.shadowRoot) observeRoot(node.shadowRoot);
  };
  const removeNode = (node) => {
    if (!node) return;
    if (node.nodeType === 1) interactive.delete(node);
    if (node.querySelectorAll) for (const element of node.querySelectorAll(INTERACTIVE_SELECTOR)) interactive.delete(element);
  };
  const emitMutation = () => {
    domVersion += 1;
    for (const listener of [...mutationSubscribers]) queueMicrotask(listener);
    if (dirtyNotifier) dirtyNotifier.notify(domVersion, location.href);
    else try { port?.postMessage({ type: 'dom_mutation', domVersion, url: location.href }); } catch { /* port reconnects below */ }
  };
  const observer = new MutationObserver((records) => {
    for (const record of records) {
      if (record.type === 'childList') {
        for (const node of record.addedNodes) scanNode(node);
        for (const node of record.removedNodes) removeNode(node);
      } else if (record.type === 'attributes') {
        if (matchesInteractive(record.target)) interactive.add(record.target); else interactive.delete(record.target);
      }
    }
    emitMutation();
  });
  function observeRoot(root) {
    if (!root || observedRoots.has(root)) return;
    observedRoots.add(root);
    try { observer.observe(root, { subtree: true, childList: true, attributes: true, characterData: true }); } catch { return; }
    scanNode(root);
  }
  document.addEventListener('__prstudio_shadow_root_attached', (event) => {
    const host = event?.target;
    if (host?.shadowRoot) { observeRoot(host.shadowRoot); emitMutation(); }
  }, true);
  observeRoot(document);
  scanNode(document);

  const runtime = {
    get domVersion() { return domVersion; },
    get connected() { return Boolean(port); },
    interactiveElements() {
      if (interactive.size > 2500) for (const element of [...interactive]) if (!element?.isConnected) interactive.delete(element);
      return [...interactive];
    },
    indexSize() { return interactive.size; },
    subscribe(listener) { mutationSubscribers.add(listener); return () => mutationSubscribers.delete(listener); },
  };
  globalThis.__PRSTUDIO_PAGE_RUNTIME_V3__ = runtime;

  const deepSelectorExists = (selector) => {
    if (!selector) return true;
    const roots = [document];
    for (let i = 0; i < roots.length && i < 64; i += 1) {
      const root = roots[i];
      try { if (root.querySelector(selector)) return true; } catch { return false; }
      let all = [];
      try { all = [...root.querySelectorAll('*')]; } catch { all = []; }
      for (const element of all) if (element.shadowRoot && !roots.includes(element.shadowRoot)) roots.push(element.shadowRoot);
    }
    return false;
  };

  const eventWait = async (predicate, timeoutMs = 30000, events = []) => {
    const bounded = Math.max(250, Math.min(120000, Number(timeoutMs || 30000)));
    const immediate = await predicate();
    if (immediate) return immediate;
    return new Promise((resolve, reject) => {
      let settled = false;
      const cleanup = () => {
        clearTimeout(timer);
        mutationSubscribers.delete(onChange);
        for (const [target, name, listener] of listeners) try { target.removeEventListener(name, listener, true); } catch {}
      };
      const finish = (value, error = null) => {
        if (settled) return;
        settled = true;
        cleanup();
        if (error) reject(error); else resolve(value);
      };
      const check = async () => {
        try { const value = await predicate(); if (value) finish(value); } catch (error) { finish(null, error); }
      };
      const onChange = () => { queueMicrotask(check); };
      mutationSubscribers.add(onChange);
      const listeners = [];
      for (const [target, name] of events) {
        const listener = () => queueMicrotask(check);
        try { target.addEventListener(name, listener, true); listeners.push([target, name, listener]); } catch {}
      }
      const timer = setTimeout(() => finish(null, new Error('page_runtime_timeout')), bounded);
    });
  };

  const norm = (value) => String(value ?? '').toLowerCase().replace(/\s+/g, ' ').trim();
  const visible = (element) => {
    if (!element?.isConnected) return false;
    const style = getComputedStyle(element);
    if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity || '1') <= 0) return false;
    const rect = element.getBoundingClientRect();
    return rect.width > 0 && rect.height > 0;
  };
  const describe = (element) => {
    if (!element) return null;
    const rect = element.getBoundingClientRect();
    return {
      tag: String(element.tagName || '').toLowerCase(), id: element.id || '', name: element.getAttribute?.('name') || '', type: element.getAttribute?.('type') || '',
      role: element.getAttribute?.('role') || '', text: String(element.innerText || element.textContent || '').trim().slice(0, 1000),
      accessibleName: element.getAttribute?.('aria-label') || element.getAttribute?.('title') || '', valueLength: 'value' in element ? String(element.value ?? '').length : null,
      checked: 'checked' in element ? Boolean(element.checked) : null, disabled: Boolean(element.disabled), visible: visible(element),
      rect: { x: rect.x, y: rect.y, width: rect.width, height: rect.height },
    };
  };
  const selectorFromArgs = (args = {}) => String(args.selector || args.css || '');
  const findElement = (args = {}) => {
    const selector = selectorFromArgs(args);
    if (selector) {
      const roots = [document];
      for (let i = 0; i < roots.length && i < 64; i += 1) {
        const root = roots[i];
        try { const match = root.querySelector(selector); if (match) return { element: match, match: { strategy: 'selector', selector } }; } catch { return { element: null, match: { strategy: 'selector_invalid', selector } }; }
        let all = []; try { all = [...root.querySelectorAll('*')]; } catch { all = []; }
        for (const item of all) if (item.shadowRoot && !roots.includes(item.shadowRoot)) roots.push(item.shadowRoot);
      }
    }
    const role = norm(args.role); const name = norm(args.name || args.text || args.label); const candidates = runtime.interactiveElements();
    let best = null; let bestScore = -1;
    for (const element of candidates) {
      if (!visible(element)) continue;
      const d = describe(element); const hay = norm([d.text, d.accessibleName, d.id, d.name].join(' '));
      let score = 0;
      if (role && norm(d.role || d.tag) === role) score += 4;
      if (name && hay === name) score += 6; else if (name && hay.includes(name)) score += 3;
      if (!role && !name) score = 1;
      if (score > bestScore) { best = element; bestScore = score; }
    }
    return { element: best, match: best ? { strategy: 'semantic', score: bestScore } : null };
  };

  async function domExecutor(action, args = {}) {
    try {
      if (action === 'locate') {
        const found = findElement(args); return { ok: Boolean(found.element), matched: Boolean(found.element), element: describe(found.element), match: found.match, url: location.href };
      }
      const { element, match } = findElement(args);
      if (!element) return { ok: false, matched: false, error: 'target_not_found', url: location.href };
      const before = describe(element);
      if (action === 'click') element.click();
      else if (action === 'fill') { element.focus(); element.value = String(args.value ?? ''); element.dispatchEvent(new Event('input', { bubbles: true })); element.dispatchEvent(new Event('change', { bubbles: true })); }
      else if (action === 'focus') element.focus();
      else if (action === 'blur') element.blur();
      else if (action === 'type') { element.focus(); element.value = String(element.value ?? '') + String(args.text ?? args.value ?? ''); element.dispatchEvent(new Event('input', { bubbles: true })); }
      else if (action === 'press') { const key = String(args.key || ''); const options = { key, bubbles: true }; element.dispatchEvent(new KeyboardEvent('keydown', options)); element.dispatchEvent(new KeyboardEvent('keyup', options)); }
      else if (action === 'select') { const values = Array.isArray(args.value) ? args.value.map(String) : [String(args.value)]; for (const option of element.options || []) option.selected = values.includes(option.value) || values.includes(option.label); element.dispatchEvent(new Event('input', { bubbles: true })); element.dispatchEvent(new Event('change', { bubbles: true })); }
      else if (action === 'check') { if (Boolean(element.checked) !== Boolean(args.value ?? true)) element.click(); }
      else if (action === 'extract_text') return { ok: true, matched: true, action, element: before, match, text: String(element.innerText || element.textContent || '').slice(0, 1000000), url: location.href };
      else if (action === 'computed_styles') { const style = getComputedStyle(element); const properties = Array.isArray(args.properties) && args.properties.length ? args.properties : ['display','visibility','position','color','background-color','font-size','width','height']; return { ok: true, matched: true, element: before, match, styles: Object.fromEntries(properties.map((name) => [name, style.getPropertyValue(name)])) }; }
      else return { ok: false, error: 'contract_dom_action_missing', message: `Azione DOM ${action} non implementata.` };
      return { ok: true, matched: true, action, element: before, after: describe(element), match, url: location.href, title: document.title };
    } catch (error) { return { ok: false, error: String(error?.message || error), message: String(error?.message || error) }; }
  }

  const handle = async (payload = {}) => {
    if (payload.kind === 'ping') return { pong: true, domVersion, indexSize: interactive.size, url: location.href };
    if (payload.kind === 'dom_action') return domExecutor(String(payload.action || ''), payload.args || {});
    if (payload.kind === 'wait_selector') return eventWait(async () => { const result = await domExecutor('locate', payload.args || {}); return result?.ok && result?.matched ? result : null; }, payload.timeoutMs || 30000, [[document, 'readystatechange'], [window, 'pageshow']]);
    if (payload.kind === 'wait_absent') return eventWait(async () => { const result = await domExecutor('locate', payload.args || {}); return !result?.ok || !result?.matched ? { ok: true, absent: true, url: location.href } : null; }, payload.timeoutMs || 30000, [[document, 'readystatechange'], [window, 'pageshow']]);
    if (payload.kind === 'wait_ready') return eventWait(async () => { const ready = document.readyState === 'interactive' || document.readyState === 'complete'; if (!ready || !deepSelectorExists(String(payload.selector || ''))) return null; return { ready: true, selectorReady: true, readyState: document.readyState, bodyLength: String(document.body?.innerText || document.body?.textContent || '').length, url: location.href, domVersion }; }, payload.timeoutMs || 30000, [[document, 'DOMContentLoaded'], [document, 'readystatechange'], [window, 'pageshow']]);
    if (payload.kind === 'read_value') { const found = findElement({ selector: payload.selector, targetRef: payload.targetRef }); const element = found.element; if (!element) return { supported: false, value: null, valueLength: null }; const supported = 'value' in element; const value = supported ? String(element.value ?? '') : String(element.textContent ?? ''); return { supported: true, value, valueLength: value.length, tag: String(element.tagName || '').toLowerCase(), domVersion }; }
    if (payload.kind === 'batch') { const results = []; for (const item of Array.isArray(payload.actions) ? payload.actions : []) { const result = await domExecutor(String(item?.action || ''), item?.args || {}); results.push(result); if (!result?.ok) break; } return { ok: results.every((item) => item?.ok), results, count: results.length, domVersion, url: location.href }; }
    throw new Error(`page_runtime_kind_unknown:${String(payload.kind || '')}`);
  };

  const respondMessage = (message, _sender, sendResponse) => {
    if (message?.channel !== 'prstudio-page-runtime') return undefined;
    handle(message).then((result) => sendResponse({ ok: true, result })).catch((error) => sendResponse({ ok: false, error: String(error?.message || error), message: String(error?.message || error) }));
    return true;
  };
  chrome.runtime.onMessage.addListener(respondMessage);

  const connect = () => {
    try {
      port = chrome.runtime.connect({ name: 'prstudio-page-runtime' });
      reconnectBackoff?.markConnected();
      dirtyNotifier?.reset();
      port.onMessage.addListener((message = {}) => {
        reconnectBackoff?.markActivity();
        if (message?.type !== 'runtime_request' || !message.id) return;
        dirtyNotifier?.synchronize(domVersion, location.href);
        handle(message.payload || {}).then((result) => { try { port.postMessage({ type: 'runtime_response', id: message.id, ok: true, result }); } catch {} }).catch((error) => { try { port.postMessage({ type: 'runtime_response', id: message.id, ok: false, error: String(error?.message || error), message: String(error?.message || error) }); } catch {} });
      });
      port.onDisconnect.addListener(() => { port = null; if (reconnectBackoff) reconnectBackoff.schedule(connect); else setTimeout(connect, 250); });
      port.postMessage({ type: 'runtime_ready', domVersion, url: location.href, frameTop: window === window.top });
    } catch { port = null; if (reconnectBackoff) reconnectBackoff.schedule(connect); else setTimeout(connect, 500); }
  };
  connect();
})();
