import { createRefreshLoop } from './lib/panel-refresh.js';

const $ = (id) => document.getElementById(id);
const send = (type, extra = {}) => chrome.runtime.sendMessage({ type, ...extra });
const sendLive = (type, extra = {}) => chrome.runtime.sendMessage({ target: 'prstudio-live-runtime', type, ...extra });
let localState = null;
let remoteState = null;
let busy = false;
const busyDisabledState = new Map();

// Chrome grants activeTab only to the tab that was active at a recognized
// invocation (action icon click, context menu, command) -- never to whatever
// tab happens to be focused when a side-panel button is pressed later. This
// panel opens via openPanelOnActionClick, so the tab active at panel-load is
// the one genuinely granted; a later tabCapture success (e.g. via the PR
// STUDIO LIVE WebRTC context menu on another tab) re-proves a grant for that
// tab too. Tracking this lets the UI disable "Avvia LIVE" and explain why
// *before* the user hits the Chrome error, instead of only after.
let grantedTabId = 0;
function tabIsGranted(tabId) { return grantedTabId > 0 && Number(tabId || 0) === grantedTabId; }

const COMMANDS = [
  ["audit", "Analizza pagina", () => runHealth()],
  ["salute", "Analizza pagina", () => runHealth()],
  ["registra", "Avvia registrazione", () => startRecorder()],
  ["recorder", "Avvia registrazione", () => startRecorder()],
  ["inspector", "Seleziona elemento", () => startInspector()],
  ["debug", "Debug live", () => runDebug(false)],
  ["debug reload", "Debug + ricarica", () => runDebug(true)],
  ["responsive", "Test responsive", () => runResponsive()],
  ["scan", "Scansiona sito", () => runSiteScan()],
  ["report", "Esporta report diagnostico", () => exportDiagnosticReport()],
  ["workspace", "Salva workspace", () => saveWorkspace()],
  ["baseline", "Salva baseline", () => captureBaseline()],
  ["stop", "STOP locale", () => cancelLocal()],
];

function show(message, error = false) {
  $("message").textContent = message || "";
  $("message").style.color = error ? "#a32020" : "#1f2328";
}
function errText(result) {
  if (!result) return "nessuna risposta";
  if (typeof result.error === "string") return result.error;
  return result.error?.message || result.error?.code || JSON.stringify(result.error || result);
}
function setBusyControls(value) {
  const controls = [...document.querySelectorAll("button"), $("commandPalette")];
  for (const control of controls) {
    if (!control || ["localCancelButton", "cancelButton"].includes(control.id)) continue;
    if (value) {
      if (!busyDisabledState.has(control)) busyDisabledState.set(control, Boolean(control.disabled));
      control.disabled = true;
      continue;
    }
    if (busyDisabledState.has(control)) control.disabled = busyDisabledState.get(control);
    busyDisabledState.delete(control);
  }
}
async function guarded(label, fn) {
  if (busy) return;
  busy = true; setBusyControls(true); show(label);
  try { return await fn(); }
  catch (error) { show(`Errore: ${error?.message || error}`, true); }
  finally { busy = false; setBusyControls(false); }
}

for (const button of document.querySelectorAll(".tabButton")) button.addEventListener("click", () => activateTab(button.dataset.tab));
function activateTab(name) {
  for (const button of document.querySelectorAll(".tabButton")) button.classList.toggle("active", button.dataset.tab === name);
  for (const pane of document.querySelectorAll(".tabPane")) pane.classList.toggle("active", pane.id === `tab-${name}`);
}

$("runCommandButton").addEventListener("click", runCommand);
$("commandPalette").addEventListener("keydown", (event) => { if (event.key === "Enter") runCommand(); });
$("commandPalette").addEventListener("input", renderCommandSuggestions);
function renderCommandSuggestions() {
  const q = $("commandPalette").value.trim().toLowerCase();
  const suggestions = COMMANDS.filter(([key, label]) => !q || key.includes(q) || label.toLowerCase().includes(q)).slice(0, 5);
  $("commandSuggestions").replaceChildren(...suggestions.map(([key, label]) => {
    const button = document.createElement("button"); button.className = "suggestion"; button.textContent = label;
    button.addEventListener("click", () => { $("commandPalette").value = key; runCommand(); }); return button;
  }));
}
function runCommand() {
  const q = $("commandPalette").value.trim().toLowerCase();
  if (!q) return show("Scrivi un comando oppure scegline uno dai suggerimenti.", true);
  const command = COMMANDS.find(([key, label]) => key === q || label.toLowerCase() === q) || COMMANDS.find(([key]) => key.includes(q));
  if (!command) return show("Comando non riconosciuto.", true);
  command[2]();
}

$("healthButton").addEventListener("click", runHealth);
$("debugButton").addEventListener("click", () => runDebug(false));
$("debugReloadButton").addEventListener("click", () => runDebug(true));
$("inspectorButton").addEventListener("click", startInspector);
$("responsiveButton").addEventListener("click", runResponsive);
$("siteScanButton").addEventListener("click", runSiteScan);
$("reportButton").addEventListener("click", exportDiagnosticReport);
$("recordStartButton").addEventListener("click", startRecorder);
$("recordStopButton").addEventListener("click", stopRecorder);
$("localCancelButton").addEventListener("click", cancelLocal);
$("originProfile").addEventListener("change", async () => {
  if (!localState?.origin) return;
  const result = await send("local_set_origin_profile", { origin: localState.origin, mode: $("originProfile").value });
  show(result?.ok ? "Profilo origine aggiornato." : `Errore: ${errText(result)}`, !result?.ok); await refreshLocal();
});
$("baselineCaptureButton").addEventListener("click", captureBaseline);
$("baselineCompareButton").addEventListener("click", compareBaseline);

async function runHealth() {
  await guarded("Audit locale in corso…", async () => {
    const result = await send("local_page_health");
    if (!result?.ok) return show(`Errore: ${errText(result)}`, true);
    renderHealth(result.result); show(`Audit completato: ${result.result.score}/100.`); await refreshLocal();
  });
}
function renderHealth(r) {
  if (!r) return;
  const cls = r.score >= 85 ? "scoreGood" : r.score >= 70 ? "scoreWarn" : "scoreBad";
  $("healthResult").className = "resultBox";
  $("healthResult").innerHTML = `<strong class="${cls}">${r.score}/100</strong><div class="healthGrid">
    <div class="metric">H1: ${r.h1Count}</div><div class="metric">Immagini senza ALT: ${r.imagesMissingAlt}</div>
    <div class="metric">Controlli senza nome: ${r.unlabeledControls}</div><div class="metric">ID duplicati: ${r.duplicateIdCount}</div>
    <div class="metric">Link problematici: ${r.badLinkCount}</div><div class="metric">Schema JSON errori: ${r.schemaParseErrors}</div>
    <div class="metric">Risorse: ${r.resourceCount}</div><div class="metric">Mixed content: ${r.mixedContentCount}</div></div>
    <p class="miniMeta">${escapeHtml(r.url)} · load ${r.navigation?.loadMs ?? "—"} ms</p>`;
}
async function runDebug(reload) {
  await guarded(reload ? "Diagnostica con ricarica…" : "Diagnostica live…", async () => {
    const result = await send("local_debug_capture", { reload });
    if (!result?.ok) return show(`Errore: ${errText(result)}`, true);
    $("debugResult").textContent = JSON.stringify(result.result, null, 2);
    if (result.result.page) renderHealth(result.result.page);
    show(`Debug completato: ${result.result.network?.errorResponses?.length || 0} risposte HTTP problematiche.`);
  });
}
async function runResponsive() {
  await guarded("Test responsive locale…", async () => {
    const result = await send("local_responsive_matrix");
    if (!result?.ok) return show(`Errore: ${errText(result)}`, true);
    const rows = result.results || [];
    $("responsiveResult").className = "resultBox";
    $("responsiveResult").innerHTML = rows.map((row) => `<div class="metric"><b>${escapeHtml(row.name)}</b> ${row.requested.width}×${row.requested.height} · overflow: ${row.snapshot?.horizontal ? 'sì' : 'no'}</div>`).join("");
    show(`Responsive completato su ${rows.length} viewport.`);
  });
}

async function runSiteScan() {
  await guarded("Scansione del sito…", async () => {
    const result = await send("local_site_scan", { limit: 8 });
    if (!result?.ok) return show(`Errore: ${errText(result)}`, true);
    const rows = result.results || [];
    $("siteScanResult").className = "resultBox";
    $("siteScanResult").innerHTML = rows.map((row) => `<div class="metric"><b>${row.error ? "ERRORE" : `${row.score}/100`}</b> ${escapeHtml(row.url)}${row.error ? ` · ${escapeHtml(row.error.message || "")}` : ""}</div>`).join("");
    show(`Scansione completata: ${rows.length} pagine dello stesso sito.`);
  });
}

async function exportDiagnosticReport() {
  await guarded("Creazione report diagnostico locale…", async () => {
    const result = await send("local_bug_report", { includeScreenshot: $("reportScreenshot").checked });
    if (!result?.ok) return show(`Errore: ${errText(result)}`, true);
    downloadJson(`PR-STUDIO-DIAGNOSTIC-${new Date().toISOString().replace(/[:.]/g, "-")}.json`, result.report);
    show("Report diagnostico creato localmente. Nessun upload esterno eseguito.");
  });
}

function downloadJson(filename, value) {
  const blob = new Blob([JSON.stringify(value, null, 2)], { type: "application/json" });
  const url = URL.createObjectURL(blob); const a = document.createElement("a"); a.href = url; a.download = filename; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1500);
}

async function startInspector() {
  await guarded("Selezione elemento…", async () => {
    const result = await send("local_inspector_start");
    show(result?.ok ? "Selezione attiva: clicca l'elemento nella pagina (Esc per annullare)." : `Errore: ${errText(result)}`, !result?.ok);
    if (result?.ok) setTimeout(refreshLocal, 400);
  });
}
async function startRecorder() {
  await guarded("Avvio registrazione…", async () => {
    const result = await send("local_recorder_start", { name: $("workflowName").value.trim() });
    show(result?.ok ? "Registrazione avviata. Usa normalmente la pagina." : `Errore: ${errText(result)}`, !result?.ok); await refreshLocal();
  });
}
async function stopRecorder() {
  await guarded("Salvataggio sequenza…", async () => {
    const result = await send("local_recorder_stop", { name: $("workflowName").value.trim() });
    show(result?.ok ? `Sequenza "${result.workflow.name}" salvata (${result.workflow.steps.length} passaggi).` : `Errore: ${errText(result)}`, !result?.ok); await refreshLocal();
  });
}
async function cancelLocal() {
  const result = await send("local_cancel"); show(result?.ok ? "Esecuzione locale arrestata." : `Errore: ${errText(result)}`, !result?.ok); await refreshLocal();
}

async function captureBaseline() {
  await guarded("Acquisizione baseline…", async () => {
    const result = await send("local_baseline_capture", { name: localState?.tab?.title || "Baseline" });
    show(result?.ok ? "Baseline visiva salvata localmente." : `Errore: ${errText(result)}`, !result?.ok); await refreshLocal();
  });
}
async function compareBaseline() {
  const id = $("baselineSelect").value; if (!id) return show("Salva prima una baseline.", true);
  await guarded("Confronto pixel…", async () => {
    const result = await send("local_baseline_compare", { id });
    if (!result?.ok) return show(`Errore: ${errText(result)}`, true);
    const diff = await pixelDiff(result.baseline.dataUrl, result.current.dataUrl);
    const semantic = result.semanticDiff || {};
    $("baselineResult").textContent = `Differenza visiva: ${diff.percent.toFixed(2)}% · testo: ${semantic.textChanged ? "cambiato" : "uguale"} · struttura: ${semantic.structureChanged ? "cambiata" : "uguale"}`;
    show("Confronto baseline completato.");
  });
}
function imageFromUrl(url) { return new Promise((resolve, reject) => { const img = new Image(); img.onload = () => resolve(img); img.onerror = reject; img.src = url; }); }
async function pixelDiff(a, b) {
  const [ia, ib] = await Promise.all([imageFromUrl(a), imageFromUrl(b)]);
  const w = Math.min(ia.width, ib.width, 1200), h = Math.min(ia.height, ib.height, 1200);
  const canvas = document.createElement("canvas"); canvas.width = w; canvas.height = h; const ctx = canvas.getContext("2d", { willReadFrequently: true });
  ctx.drawImage(ia, 0, 0, w, h); const da = ctx.getImageData(0, 0, w, h).data; ctx.clearRect(0, 0, w, h); ctx.drawImage(ib, 0, 0, w, h); const db = ctx.getImageData(0, 0, w, h).data;
  let changed = 0; for (let i = 0; i < da.length; i += 4) if (Math.abs(da[i]-db[i])+Math.abs(da[i+1]-db[i+1])+Math.abs(da[i+2]-db[i+2]) > 45) changed += 1;
  return { changed, percent: (changed / (w * h || 1)) * 100 };
}

$("workspaceSaveButton").addEventListener("click", saveWorkspace);
async function saveWorkspace() { await guarded("Salvataggio gruppo di schede…", async () => { const result = await send("local_workspace_save", { name: $("workspaceName").value.trim() }); show(result?.ok ? "Gruppo salvato." : `Errore: ${errText(result)}`, !result?.ok); await refreshLocal(); }); }
$("workflowList").addEventListener("click", handleCardAction); $("workspaceList").addEventListener("click", handleCardAction); $("scheduleList").addEventListener("click", handleCardAction);
async function handleCardAction(event) {
  const button = event.target.closest("button[data-action]"); if (!button) return;
  const { action, id } = button.dataset;
  let result;
  if (action === "run-workflow") result = await guarded("Workflow in esecuzione…", () => send("local_workflow_run", { id }));
  if (action === "delete-workflow") result = await send("local_workflow_delete", { id });
  if (action === "restore-workspace") result = await send("local_workspace_restore", { id });
  if (action === "delete-workspace") result = await send("local_workspace_delete", { id });
  if (action === "delete-schedule") result = await send("local_schedule_delete", { id });
  if (result) show(result?.ok ? "Operazione completata." : `Errore: ${errText(result)}`, !result?.ok);
  await refreshLocal();
}

$("exportButton").addEventListener("click", async () => {
  const result = await send("local_export_state"); if (!result?.ok) return show(`Errore: ${errText(result)}`, true);
  downloadJson(`PR-STUDIO-Local-Studio-1.0.0-${Date.now()}.json`, result.export); show("Configurazione locale esportata.");
});
$("importButton").addEventListener("click", () => $("importFile").click());
$("importFile").addEventListener("change", async () => {
  const file = $("importFile").files?.[0]; if (!file) return;
  try {
    const data = JSON.parse(await file.text()); const workflows = Array.isArray(data.workflows) ? data.workflows : (data.steps ? [data] : []);
    let imported = 0; for (const workflow of workflows.slice(0, 100)) { const result = await send("local_workflow_import", { workflow }); if (result?.ok) imported += 1; }
    show(`Importati ${imported} workflow.`); await refreshLocal();
  } catch (error) { show(`Import non valido: ${error?.message || error}`, true); }
  $("importFile").value = "";
});

$("scheduleSaveButton").addEventListener("click", async () => {
  await guarded("Salvataggio controllo programmato…", async () => {
    const result = await send("local_schedule_upsert", { schedule: { name: $("scheduleName").value.trim(), url: $("scheduleUrl").value.trim(), minutes: Number($("scheduleMinutes").value) } });
    show(result?.ok ? "Controllo locale programmato." : `Errore: ${errText(result)}`, !result?.ok); await refreshLocal();
  });
});

function renderLocal(state) {
  localState = state;
  $("localVersion").textContent = state?.version ? `Local Studio ${state.version}` : "";
  const tab = state?.tab; $("currentPage").textContent = tab?.url ? `Pagina corrente: ${tab.title || ""} — ${tab.url}` : `Pagina corrente non disponibile`;
  $("originProfile").value = state?.originProfile || "automation";
  if (!$("scheduleUrl").value && tab?.url) $("scheduleUrl").value = tab.url;
  const rec = state?.recorder || { active: false }; $("recorderState").textContent = rec.active ? "REC" : "fermo"; $("recorderState").className = rec.active ? "pill" : "pill neutral";
  $("recordStartButton").hidden = rec.active; $("recordStopButton").hidden = !rec.active; $("recorderInfo").textContent = rec.active ? `${rec.stepCount} step registrati · tab ${rec.tabId}` : "Fermo";
  const inspection = state?.inspector?.result; if (inspection) $("inspectionResult").innerHTML = `<b>${escapeHtml(inspection.tag || "elemento")}</b> · ${escapeHtml(inspection.locator?.name || "innominato")}`;
  renderWorkflowList(state?.workflows || []); renderWorkspaceList(state?.workspaces || []); renderSchedules(state?.schedules || [], state?.scheduledResults || []); renderBaselines(state?.baselines || []);
  $("localLogs").textContent = JSON.stringify(state?.flight || [], null, 2);
}
function renderWorkflowList(items) {
  $("workflowList").innerHTML = items.length ? items.map((w) => `<div class="miniCard"><h3>${escapeHtml(w.name)}</h3><div class="miniMeta">${w.steps?.length || 0} step · ${new Date(w.updatedAt || 0).toLocaleDateString()}</div></div>`).join("") : "";
}
function renderWorkspaceList(items) {
  $("workspaceList").innerHTML = items.length ? items.map((w) => `<div class="miniCard"><h3>${escapeHtml(w.name)}</h3><div class="miniMeta">${w.tabs?.length || 0} tab</div></div>`).join("") : "";
}
function renderSchedules(items, results) {
  $("scheduleList").innerHTML = items.length ? items.map((s) => `<div class="miniCard"><h3>${escapeHtml(s.name)}</h3><div class="miniMeta">${escapeHtml(s.url)} · ogni ${s.minutes} min</div></div>`).join("") : "";
  $("scheduleResults").innerHTML = results.length ? [...results].reverse().map((r) => `<div class="miniCard"><h3>${escapeHtml(r.status)} ${r.score != null ? `· ${r.score}/100` : ""}</h3></div>`).join("") : "";
}
function renderBaselines(items) { $("baselineSelect").innerHTML = items.length ? items.map((b) => `<option value="${escapeAttr(b.id)}">${escapeHtml(b.name)}</option>`).join("") : '<option value="">Nessuna baseline</option>'; }
async function refreshLocal() { try { const state = await send("local_status"); if (state?.ok) renderLocal(state); } catch { /* service worker may be restarting */ } }


async function activeLiveTab() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  return tab || null;
}

function renderLiveStatus(status, tab = null) {
  const active = Boolean(status?.active);
  $("liveBadge").textContent = active ? "LIVE" : "OFF";
  $("liveBadge").className = active ? "pill" : "pill neutral";
  const currentTabId = Number(tab?.id || 0);
  const authorized = active || tabIsGranted(currentTabId);
  $("liveStartButton").disabled = active || !authorized;
  $("liveStopButton").disabled = !active;
  const shownTab = tab || status?.captures?.find((x) => Number(x.tabId) === Number(status?.tabId));
  $("liveTarget").textContent = shownTab?.title ? `Scheda LIVE: ${shownTab.title}` : (status?.tabId ? `Scheda LIVE: ${status.tabId}` : "Scheda LIVE: —");
  const phase = status?.phase || status?.captures?.[0]?.connectionState || "pronto";
  if (active) {
    $("liveMessage").textContent = `MediaStream/WebRTC attivo · ${phase}. Il video non viene registrato nel database.`;
  } else if (!authorized && currentTabId) {
    $("liveMessage").textContent = "🔒 Scheda non autorizzata da Chrome. Clicca l'icona PR STUDIO su questa scheda prima di Avvia LIVE.";
  }
  const gates = status?.diagnostic?.gates || {};
  const order = Object.keys(gates).sort();
  $("liveDiagnostic").textContent = order.length ? order.map((key) => {
    const row = gates[key];
    const detail = row?.detail && Object.keys(row.detail).length ? ` · ${JSON.stringify(row.detail)}` : "";
    return `${row?.ok ? "PASS" : "FAIL"} ${key}${detail}`;
  }).join("\n") : "Self-test non ancora eseguito.";
}

async function refreshLive() {
  try {
    const tab = await activeLiveTab();
    const tabId = Number(tab?.id || 0);
    const status = await sendLive('live_status', { tabId });
    if (status?.ok) renderLiveStatus(status, tab);
  } catch { /* worker restart */ }
}

$("liveStartButton").addEventListener("click", async () => {
  const tab = await activeLiveTab();
  const tabId = Number(tab?.id || 0);
  if (!tabId) return show("Nessuna scheda selezionata.", true);
  if (!tabIsGranted(tabId)) {
    $("liveMessage").textContent = "🔒 Scheda non autorizzata da Chrome.";
    return show("Chrome non ha ancora concesso l'accesso a questa scheda.", true);
  }
  $("liveStartButton").disabled = true;
  $("liveMessage").textContent = "Avvio MediaStream/WebRTC…";
  const result = await sendLive('live_start', { tabId, source: 'side_panel_after_action' }).catch((error) => ({ ok: false, error: { message: error?.message || String(error) } }));
  if (!result?.ok) {
    $("liveMessage").textContent = result?.error?.message || "Avvio LIVE fallito.";
    show(`LIVE: ${result?.error?.message || errText(result)}`, true);
  } else {
    grantedTabId = tabId;
    $("liveMessage").textContent = `Sessione WebRTC ${String(result.sessionId || '').slice(0, 8)}… in negoziazione.`;
  }
  await refreshLive();
});

$("liveStopButton").addEventListener("click", async () => {
  const tab = await activeLiveTab();
  const status = await sendLive('live_status', { tabId: Number(tab?.id || 0) });
  const tabId = Number(status?.tabId || tab?.id || 0);
  if (tabId) await sendLive('live_stop', { tabId, reason: 'side_panel_stop' }).catch(() => {});
  await refreshLive();
});

chrome.runtime?.onMessage?.addListener?.((message) => {
  if (message?.target !== 'prstudio-live-panel') return;
  if (message?.type === 'active_tab_granted') {
    const tabId = Number(message?.detail?.tabId || 0);
    if (tabId) grantedTabId = tabId;
    refreshLive().catch(() => {});
    return;
  }
  if (message?.type !== 'state') return;
  const detail = message.detail || {};
  const text = detail.message || (detail.status ? `WebRTC · ${detail.status}` : '');
  if (text) $("liveMessage").textContent = text;
  const grantedFromStatus = String(detail.status || '');
  if (detail.tabId && grantedFromStatus && !['error', 'stopped'].includes(grantedFromStatus)) grantedTabId = Number(detail.tabId);
  refreshLive().catch(() => {});
});

if (chrome.tabs?.onActivated) chrome.tabs.onActivated.addListener(() => { refreshLive().catch(() => {}); });
if (chrome.tabs?.onUpdated) chrome.tabs.onUpdated.addListener((_tabId, info) => { if (info.status === 'loading') refreshLive().catch(() => {}); });

// Remote/pairing contract: intentionally unchanged.
$("pairButton").addEventListener("click", async () => { const code = $("pairCode").value.trim(); if (!code) return show("Inserisci il nuovo codice pairing.", true); show("Associazione in corso…", false); const result = await send("pair", { siteUrl: $("siteUrl").value.trim(), code, name: $("deviceName").value.trim() }); if (!result?.ok) return show(`Errore: ${errText(result)}`, true); $("pairCode").value = ""; show(result.renewed ? "Chiave rinnovata e vecchio dispositivo revocato." : "Associazione completata."); await refreshRemote(); });
$("forgetButton").addEventListener("click", async () => { const result = await send("unpair"); show(result?.ok ? "Associazione locale rimossa." : `Errore: ${errText(result)}`, !result?.ok); await refreshRemote(); });
$("refreshButton").addEventListener("click", refreshRemote);
$("manualCleanupButton").addEventListener("click", async () => { const result = await guarded("Pulizia runtime…", () => send("manual_cleanup_runtime")); show(result?.ok ? "Azioni transitorie e risorse rilasciate." : `Errore: ${errText(result)}`, !result?.ok); });
$("cancelButton").addEventListener("click", async () => { const result = await send("cancel"); show(result?.ok ? "Task annullato anche sul server." : `Errore: ${errText(result)}`, !result?.ok); await refreshRemote(); });


async function refreshRemote() {
  try {
    const status = await send("status"); remoteState = status; const paired = Boolean(status?.paired); $("connected").hidden = !paired; $("forgetButton").hidden = !paired; $("pairHeading").textContent = paired ? "Associato" : "Non associato";
    if (paired) {
      $("deviceId").textContent = status.deviceId || ""; $("site").textContent = status.siteUrl || ""; $("suiteVersion").textContent = status.suiteVersion || ""; $("protocol").textContent = status.protocol || "";
      const chain = status.serverCapabilities;
      const toolchainProfiles = chain?.mcp_toolchain_profiles ? Object.keys(chain.mcp_toolchain_profiles).length : 0;
      $("integrationChain").textContent = chain ? `WordPress collegato · strumenti ${chain.mcp_available ? "disponibili" : "in verifica"} · ${toolchainProfiles || 0} gruppi attivi` : "Collegamento non verificato";
      const connected = status.pollLoopRunning && !status.authExpired; $("connectionText").textContent = status.authExpired ? "Chiave scaduta o revocata" : (connected ? "Connessa" : "In riconnessione");
      const active = status.active; $("taskCard").hidden = !active; if (active) {
        const authChallenge = active.authChallenge?.active ? active.authChallenge : null;
        $("taskStatus").textContent = authChallenge ? "Autenticazione esterna richiesta · auto-ripresa attiva" : (active.phase || "In esecuzione");
        $("taskReason").textContent = authChallenge ? `${active.action} — completa CAPTCHA/MFA/login nella pagina; PR STUDIO continua automaticamente` : `${active.action} — passaggio ${active.stepIndex || 0}`;
      }
    }
    $("logs").textContent = JSON.stringify(status?.logs || [], null, 2);
  } catch { /* service worker restart */ }
}

function escapeHtml(value) { return String(value ?? "").replace(/[&<>"]/g, (c) => ({ "&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;" }[c])); }
function escapeAttr(value) { return escapeHtml(value).replace(/'/g, "&#39;"); }
renderCommandSuggestions();
activeLiveTab().then((tab) => { if (tab?.id) grantedTabId = Number(tab.id); }).catch(() => {});
const panelRefreshLoops = [
  createRefreshLoop(refreshLocal, { intervalMs: 5_000 }),
  createRefreshLoop(refreshRemote, { intervalMs: 10_000 }),
  createRefreshLoop(refreshLive, { intervalMs: 4_000 }),
];
for (const loop of panelRefreshLoops) void loop.start({ immediate: false });
function updatePanelRefreshVisibility() {
  if (document.hidden) {
    for (const loop of panelRefreshLoops) loop.pause();
    return;
  }
  for (const loop of panelRefreshLoops) void loop.resume({ immediate: true });
}
document.addEventListener?.('visibilitychange', updatePanelRefreshVisibility);
globalThis.addEventListener?.('pagehide', () => { for (const loop of panelRefreshLoops) loop.stop(); }, { once: true });
updatePanelRefreshVisibility();