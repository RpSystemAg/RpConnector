/* PR STUDIO 17.0 — MediaStream/WebRTC producer controller.
 * Installation identity is intentionally unchanged: this module is imported by
 * the existing service-worker.js inside the same unpacked
 * prstudio-unified-browser-agent folder.
 */

const LIVE_MENU_ID = 'prstudio-live-webrtc-toggle';
const LIVE_STATE_KEY = 'prstudioLiveWebRtcState';
const LIVE_DIAG_KEY = 'prstudioLiveWebRtcDiagnostic';
const OFFSCREEN_URL = 'offscreen-live.html';
const API_TIMEOUT_MS = 15000;
const CAPTUREABLE = /^https?:/i;
const BLOCKED = /^(chrome|edge|devtools|chrome-extension|about|view-source):/i;

function safeError(error) {
  return {
    code: String(error?.code || 'LIVE_ERROR').slice(0, 96),
    message: String(error?.message || error || 'Errore LIVE sconosciuto').slice(0, 500),
  };
}

async function config() {
  const value = await chrome.storage.local.get('prstudioConfig');
  return value?.prstudioConfig || null;
}

async function liveApi(path, options = {}) {
  const cfg = await config();
  if (!cfg?.apiBase || !cfg?.token || cfg?.authExpired) {
    const error = new Error('Browser Agent non associato o chiave scaduta.');
    error.code = 'LIVE_NOT_PAIRED';
    throw error;
  }
  const apiBase = new URL(String(cfg.apiBase));
  const requestUrl = new URL(`${apiBase.href.replace(/\/$/, '')}${String(path || '')}`);
  if (requestUrl.origin !== apiBase.origin) {
    const error = new Error('Endpoint LIVE fuori dall’origine WordPress associata.');
    error.code = 'LIVE_API_ORIGIN_MISMATCH';
    throw error;
  }
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort('live_api_timeout'), Math.max(1000, Math.min(30000, Number(options.timeoutMs || API_TIMEOUT_MS))));
  try {
    const response = await fetch(requestUrl.href, {
      method: options.method || 'GET',
      headers: {
        Authorization: `Bearer ${cfg.token}`,
        'Content-Type': 'application/json',
      },
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: controller.signal,
      cache: 'no-store',
      credentials: 'omit',
      redirect: 'error',
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const error = new Error(data?.message || data?.error_description || `HTTP ${response.status}`);
      error.code = String(data?.code || data?.error || `HTTP_${response.status}`);
      error.status = response.status;
      throw error;
    }
    return data;
  } finally {
    clearTimeout(timer);
  }
}

async function state() {
  return (await chrome.storage.local.get(LIVE_STATE_KEY))?.[LIVE_STATE_KEY] || { sessions: {} };
}
async function saveState(next) { await chrome.storage.local.set({ [LIVE_STATE_KEY]: next }); }

async function setSession(tabId, patch) {
  const current = await state();
  const sessions = { ...(current.sessions || {}) };
  const key = String(Number(tabId || 0));
  if (patch === null) delete sessions[key];
  else sessions[key] = { ...(sessions[key] || {}), ...patch, tabId: Number(tabId), updatedAt: Date.now() };
  await saveState({ sessions });
  return sessions[key] || null;
}

async function diagnostic(gate, ok, detail = {}) {
  const payload = {
    at: new Date().toISOString(),
    gate: String(gate),
    ok: Boolean(ok),
    detail: detail && typeof detail === 'object' ? detail : { value: String(detail) },
  };
  const saved = (await chrome.storage.local.get(LIVE_DIAG_KEY))?.[LIVE_DIAG_KEY] || { gates: {}, history: [] };
  saved.gates = { ...(saved.gates || {}), [payload.gate]: payload };
  saved.history = [...(Array.isArray(saved.history) ? saved.history : []), payload].slice(-80);
  await chrome.storage.local.set({ [LIVE_DIAG_KEY]: saved });
  const tabId = Number(detail?.tabId || 0);
  const sessionId = String(detail?.sessionId || '');
  if (sessionId) {
    liveApi(`/stream/session/${encodeURIComponent(sessionId)}`, {
      method: 'POST',
      body: { after: 0, events: [{ type: 'diagnostic', payload: { gate: payload.gate, ok: payload.ok, detail: payload.detail } }] },
      timeoutMs: 5000,
    }).catch(() => {});
  }
  if (tabId) notifyPanel({ tabId, status: ok ? gate : 'error', gate: payload.gate, gateOk: payload.ok, message: ok ? '' : String(detail?.message || '') });
  return payload;
}

function captureable(tab) {
  const tabId = Number(tab?.id || 0);
  if (!tabId) return { ok: false, reason: 'tab_missing' };
  const url = String(tab?.url || tab?.pendingUrl || '');
  if (url && BLOCKED.test(url)) return { ok: false, reason: 'restricted_url', url };
  // Missing URL is not treated as a failure: Chrome can hide sensitive Tab
  // properties before activeTab has been granted.
  if (url && !CAPTUREABLE.test(url)) return { ok: false, reason: 'unsupported_url', url };
  return { ok: true, url };
}

async function hasOffscreen() {
  if (chrome.offscreen?.hasDocument) return Boolean(await chrome.offscreen.hasDocument());
  const contexts = await chrome.runtime.getContexts?.({ contextTypes: ['OFFSCREEN_DOCUMENT'], documentUrls: [chrome.runtime.getURL(OFFSCREEN_URL)] }).catch(() => []);
  return Array.isArray(contexts) && contexts.length > 0;
}

async function ensureOffscreen() {
  if (await hasOffscreen()) return true;
  await chrome.offscreen.createDocument({
    url: OFFSCREEN_URL,
    reasons: ['USER_MEDIA', 'WEB_RTC'],
    justification: 'Trasmettere in tempo reale la scheda selezionata con MediaStream e WebRTC senza registrare il video.',
  });
  return true;
}

async function offscreenMessage(message) {
  await ensureOffscreen();
  const result = await chrome.runtime.sendMessage({ target: 'prstudio-live-offscreen', ...message });
  if (!result?.ok) {
    const error = new Error(result?.error?.message || result?.error || 'Documento offscreen LIVE non disponibile.');
    error.code = result?.error?.code || 'LIVE_OFFSCREEN_FAILED';
    throw error;
  }
  return result;
}

async function offscreenStatus() {
  if (!(await hasOffscreen())) return { ok: true, captures: [] };
  return chrome.runtime.sendMessage({ target: 'prstudio-live-offscreen', type: 'status' }).catch(() => ({ ok: true, captures: [] }));
}

async function releaseStaleCapture(tabId) {
  const captures = await chrome.tabCapture.getCapturedTabs().catch(() => []);
  const conflict = captures.find((item) => Number(item?.tabId) === Number(tabId) && !['stopped', 'error'].includes(String(item?.status || '')));
  if (!conflict) return false;
  const offscreen = await offscreenStatus();
  const ours = (offscreen?.captures || []).some((item) => Number(item?.tabId) === Number(tabId));
  if (ours) return false;
  // A capture owned by this extension survived a failed pipeline but no
  // offscreen session owns it. Closing the orphan offscreen context releases
  // MediaStream tracks authoritatively before the next attempt.
  if (await hasOffscreen()) await chrome.offscreen.closeDocument().catch(() => {});
  await ensureOffscreen();
  return true;
}

function notifyPanel(detail) {
  chrome.runtime.sendMessage({ target: 'prstudio-live-panel', type: 'state', detail }).catch(() => {});
}

async function createServerSession(tab, source) {
  const cfg = await config();
  const result = await liveApi('/stream/session', {
    method: 'POST',
    body: {
      tab_id: Number(tab.id),
      source: String(source || 'user'),
      title: String(tab.title || '').slice(0, 300),
      url: String(tab.url || '').slice(0, 2000),
      diagnostic: { gate: 'signaling_session', ok: true },
    },
  });
  if (!result?.session_id) throw Object.assign(new Error('Il server non ha creato la sessione WebRTC.'), { code: 'LIVE_SESSION_CREATE_FAILED' });
  await setSession(tab.id, { sessionId: result.session_id, deviceId: cfg?.deviceId || '', source, phase: 'signaling_session' });
  await diagnostic('08_signaling_session', true, { tabId: tab.id, sessionId: result.session_id, source });
  return result;
}

async function closeServerSession(sessionId, reason = 'stop') {
  if (!sessionId) return;
  await liveApi(`/stream/session/${encodeURIComponent(sessionId)}`, { method: 'DELETE', body: { reason }, timeoutMs: 5000 }).catch(() => {});
}

export async function livePrepare() {
  const permissionCheck = await chrome.permissions.contains({ permissions: ['activeTab', 'tabCapture', 'offscreen', 'contextMenus'] }).catch(() => false);
  await diagnostic('01_manifest_permissions', permissionCheck, { permissions: ['activeTab', 'tabCapture', 'offscreen', 'contextMenus'] });
  if (!permissionCheck) return { ok: false, error: { code: 'LIVE_PERMISSIONS_MISSING', message: 'Permessi LIVE non caricati: ricarica l’estensione aggiornata.' } };
  await ensureOffscreen();
  await diagnostic('05_offscreen', true, {});
  await liveSetupMenus();
  return { ok: true };
}

export async function liveSetupMenus() {
  if (!chrome.contextMenus) return;
  await chrome.contextMenus.remove(LIVE_MENU_ID).catch(() => {});
  chrome.contextMenus.create({
    id: LIVE_MENU_ID,
    title: 'PR STUDIO LIVE WebRTC — Avvia/Ferma questa scheda',
    contexts: ['page'],
    documentUrlPatterns: ['http://*/*', 'https://*/*'],
  }, () => void chrome.runtime.lastError);
}

export async function liveHandleContextMenu(info, tab) {
  if (info?.menuItemId !== LIVE_MENU_ID) return false;
  await liveToggle(tab, 'context_menu');
  return true;
}

export async function liveStart(tabOrId, source = 'panel') {
  let tab = typeof tabOrId === 'object' ? tabOrId : null;
  if (!tab) tab = await chrome.tabs.get(Number(tabOrId)).catch(() => null);
  const valid = captureable(tab);
  if (!valid.ok) {
    const message = valid.reason === 'restricted_url'
      ? 'Questa pagina interna di Chrome non può essere trasmessa. Apri una normale pagina HTTP(S).'
      : 'Nessuna normale scheda HTTP(S) selezionata.';
    await diagnostic('02_invocation_target', false, { tabId: Number(tab?.id || 0), message, reason: valid.reason });
    return { ok: false, error: { code: 'LIVE_TAB_NOT_CAPTUREABLE', message } };
  }

  const tabId = Number(tab.id);
  const current = await state();
  const existing = current.sessions?.[String(tabId)];
  if (existing?.sessionId) {
    const offscreen = await offscreenStatus();
    if ((offscreen?.captures || []).some((item) => Number(item?.tabId) === tabId)) {
      return { ok: true, alreadyActive: true, sessionId: existing.sessionId, tabId };
    }
  }

  let sessionId = '';
  try {
    await diagnostic('02_invocation', true, { tabId, source });
    await releaseStaleCapture(tabId);
    await ensureOffscreen();
    await diagnostic('05_offscreen', true, { tabId });

    const session = await createServerSession(tab, source);
    sessionId = String(session.session_id);

    // The only reliable proof of activeTab for tabCapture is that Chrome grants
    // getMediaStreamId() for this exact target tab. No URL visibility heuristic
    // is allowed to substitute for this API result.
    let streamId;
    try {
      streamId = await chrome.tabCapture.getMediaStreamId({ targetTabId: tabId });
    } catch (error) {
      await diagnostic('03_active_tab_grant', false, { tabId, sessionId, message: error?.message || String(error) });
      const e = new Error(/invoked|activeTab/i.test(String(error?.message || ''))
        ? 'Chrome non ha concesso activeTab a questa scheda. Clicca l’icona PR STUDIO sulla scheda e poi “Avvia LIVE”, oppure usa direttamente il menu contestuale PR STUDIO LIVE WebRTC.'
        : String(error?.message || 'Impossibile ottenere il MediaStream ID.'));
      e.code = 'LIVE_STREAM_ID_FAILED';
      throw e;
    }
    await diagnostic('03_active_tab_grant', true, { tabId, sessionId });
    await diagnostic('04_stream_id', true, { tabId, sessionId });

    const started = await offscreenMessage({ type: 'start', tabId, sessionId, streamId });
    await setSession(tabId, { sessionId, phase: 'media_starting', startedAt: Date.now() });
    notifyPanel({ tabId, sessionId, status: 'media_starting' });
    return { ok: true, tabId, sessionId, phase: started.phase || 'media_starting' };
  } catch (error) {
    if (sessionId) await closeServerSession(sessionId, 'start_failed');
    await setSession(tabId, null);
    const safe = safeError(error);
    notifyPanel({ tabId, status: 'error', message: safe.message });
    return { ok: false, error: safe };
  }
}

export async function liveStop(tabId, reason = 'user_stop') {
  tabId = Number(tabId || 0);
  const current = await state();
  const record = current.sessions?.[String(tabId)] || null;
  await chrome.runtime.sendMessage({ target: 'prstudio-live-offscreen', type: 'stop', tabId, reason }).catch(() => {});
  if (record?.sessionId) await closeServerSession(record.sessionId, reason);
  await setSession(tabId, null);
  notifyPanel({ tabId, status: 'stopped', reason });
  return { ok: true, tabId, stopped: true };
}

export async function liveToggle(tab, source = 'context_menu') {
  const tabId = Number(tab?.id || 0);
  if (!tabId) return { ok: false, error: { code: 'LIVE_TAB_MISSING', message: 'Scheda non disponibile.' } };
  const current = await state();
  const record = current.sessions?.[String(tabId)];
  const offscreen = await offscreenStatus();
  const active = (offscreen?.captures || []).some((item) => Number(item?.tabId) === tabId);
  if (record?.sessionId && active) return liveStop(tabId, 'toggle_stop');
  return liveStart(tab, source);
}

export async function liveStatus(tabId = 0) {
  const [current, diag, offscreen, captured] = await Promise.all([
    state(),
    chrome.storage.local.get(LIVE_DIAG_KEY).then((x) => x?.[LIVE_DIAG_KEY] || { gates: {}, history: [] }),
    offscreenStatus(),
    chrome.tabCapture.getCapturedTabs().catch(() => []),
  ]);
  const id = Number(tabId || 0);
  const record = id ? current.sessions?.[String(id)] || null : null;
  return {
    ok: true,
    active: Boolean(record && (offscreen?.captures || []).some((item) => Number(item?.tabId) === id)),
    tabId: id || null,
    sessionId: record?.sessionId || null,
    phase: record?.phase || null,
    captures: offscreen?.captures || [],
    chromeCapturedTabs: captured,
    diagnostic: diag,
  };
}

export async function liveHandleRuntimeMessage(message) {
  switch (String(message?.type || '')) {
    case 'live_prepare': return livePrepare();
    case 'live_start': return liveStart(Number(message.tabId || 0), String(message.source || 'panel'));
    case 'live_stop': return liveStop(Number(message.tabId || 0), String(message.reason || 'user_stop'));
    case 'live_status': return liveStatus(Number(message.tabId || 0));
    default: return { ok: false, error: { code: 'LIVE_MESSAGE_UNKNOWN', message: 'Messaggio LIVE sconosciuto.' } };
  }
}

export async function liveHandleInternalMessage(message) {
  const sessionId = String(message?.sessionId || '');
  if (!sessionId) return { ok: false, error: { code: 'LIVE_SESSION_MISSING', message: 'Sessione LIVE mancante.' } };
  switch (String(message?.type || '')) {
    case 'agent_exchange':
      return liveApi(`/stream/session/${encodeURIComponent(sessionId)}`, {
        method: 'POST',
        body: { after: Math.max(0, Number(message.after || 0)), events: Array.isArray(message.events) ? message.events.slice(0, 32) : [] },
        timeoutMs: 10000,
      });
    case 'agent_diagnostic': {
      const detail = { ...(message.detail || {}), sessionId, tabId: Number(message.tabId || 0) };
      await diagnostic(String(message.gate || 'unknown'), Boolean(message.ok), detail);
      if (message.phase && message.tabId) await setSession(Number(message.tabId), { phase: String(message.phase) });
      return { ok: true };
    }
    case 'agent_state':
      if (message.tabId) await setSession(Number(message.tabId), { phase: String(message.status || '') });
      notifyPanel({ tabId: Number(message.tabId || 0), sessionId, status: String(message.status || ''), ...(message.detail || {}) });
      return { ok: true };
    case 'agent_close':
      await closeServerSession(sessionId, String(message.reason || 'agent_close'));
      if (message.tabId) await setSession(Number(message.tabId), null);
      return { ok: true };
    default:
      return { ok: false, error: { code: 'LIVE_INTERNAL_UNKNOWN', message: 'Messaggio interno LIVE sconosciuto.' } };
  }
}

export async function liveOnCaptureStatusChanged(info) {
  const tabId = Number(info?.tabId || 0);
  if (!tabId) return;
  const status = String(info?.status || '');
  notifyPanel({ tabId, status: `capture_${status}` });
  if (['stopped', 'error'].includes(status)) {
    const current = await state();
    const record = current.sessions?.[String(tabId)];
    if (record?.sessionId) await closeServerSession(record.sessionId, `capture_${status}`);
    await setSession(tabId, null);
  }
}

export const LIVE_CONTEXT_MENU_ID = LIVE_MENU_ID;
