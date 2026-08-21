/**
 * PR STUDIO 2.0.0 runtime resilience primitives.
 * Pure functions only: testable without Chrome and reusable by the service worker.
 */

function safeUrl(value) {
  try { return new URL(String(value || "")); } catch { return null; }
}

export function urlAffinityScore(tab = {}, options = {}) {
  const tabUrl = safeUrl(tab.url);
  const expectedOrigin = safeUrl(options.expectedOrigin || options.expected_origin);
  const expectedUrl = String(options.expectedUrl || options.expected_url || "").trim();
  const taskId = String(options.taskId || "");
  const laneId = String(options.laneId || options.lane_id || "");
  const lastTabId = Number(options.lastTabId || 0);
  let score = 0;

  if (laneId) {
    if (tab.laneId && String(tab.laneId) !== laneId) return Number.NEGATIVE_INFINITY;
    if (String(tab.laneId || "") === laneId) score += 240;
  }
  if (lastTabId && Number(tab.tabId) === lastTabId) score += 120;
  if (taskId && tab.taskId && String(tab.taskId) === taskId) score += 90;
  if (expectedOrigin) {
    if (!tabUrl || tabUrl.origin !== expectedOrigin.origin) return Number.NEGATIVE_INFINITY;
    score += 70;
  }
  if (expectedUrl) {
    const actual = String(tab.url || "");
    if (actual === expectedUrl) score += 90;
    else if (actual.startsWith(expectedUrl) || expectedUrl.startsWith(actual)) score += 55;
    else if (tabUrl && safeUrl(expectedUrl)?.origin === tabUrl.origin) score += 25;
    else return Number.NEGATIVE_INFINITY;
  }
  score += Math.min(20, Math.max(0, Math.floor(Number(tab.updatedAt || 0) / 1000) % 20));
  return score;
}

export function selectOwnedTabCandidate(tabs = [], options = {}) {
  const rows = Array.isArray(tabs) ? tabs.filter((tab) => Number(tab?.tabId || 0) > 0) : [];
  if (!rows.length) return { tabId: null, reason: "none", ambiguous: false };

  const expectedOrigin = String(options.expectedOrigin || options.expected_origin || "").trim();
  const expectedUrl = String(options.expectedUrl || options.expected_url || "").trim();
  const laneId = String(options.laneId || options.lane_id || "").trim();
  const lastTabId = Number(options.lastTabId || 0);
  const hasHint = Boolean(expectedOrigin || expectedUrl || lastTabId || options.taskId || laneId);

  if (!hasHint && rows.length === 1) {
    return { tabId: Number(rows[0].tabId), reason: "sole_owned_tab", ambiguous: false };
  }

  const ranked = rows
    .map((tab) => ({ tab, score: urlAffinityScore(tab, options) }))
    .filter((item) => Number.isFinite(item.score))
    .sort((a, b) => b.score - a.score || Number(b.tab.updatedAt || 0) - Number(a.tab.updatedAt || 0));
  if (!ranked.length) return { tabId: null, reason: "no_match", ambiguous: false };

  if (ranked.length > 1 && ranked[0].score === ranked[1].score && ranked[0].score < 100) {
    return { tabId: null, reason: "ambiguous", ambiguous: true, tabIds: ranked.map((item) => Number(item.tab.tabId)) };
  }
  if (!hasHint && ranked.length > 1) {
    return { tabId: null, reason: "ambiguous", ambiguous: true, tabIds: ranked.map((item) => Number(item.tab.tabId)) };
  }
  return { tabId: Number(ranked[0].tab.tabId), reason: "affinity", ambiguous: false, score: ranked[0].score };
}

function normalizedHeader(value) {
  return String(value || "")
    .normalize("NFD").replace(/[\u0300-\u036f]/g, "")
    .toLowerCase().replace(/[^a-z0-9%]+/g, " ").trim();
}

function metricKey(label) {
  const h = normalizedHeader(label);
  if (/^(click|clicks|clic|clics|clic totali|total clicks)$/.test(h) || h.includes("click")) return "clicks";
  if (h.includes("impression")) return "impressions";
  if (h === "ctr" || h.includes("ctr medio") || h.includes("average ctr")) return "ctr";
  if (h.includes("position") || h.includes("posizione")) return "position";
  if (h === "query" || h.includes("query di ricerca") || h.includes("search query") || h.includes("queries")) return "query";
  if (h === "page" || h === "pagina" || h.includes("pages") || h.includes("pagine")) return "page";
  if (h.includes("country") || h.includes("paese")) return "country";
  if (h.includes("device") || h.includes("dispositivo")) return "device";
  if (h === "date" || h.includes("data")) return "date";
  return "";
}

export function parseMetricNumber(value, kind = "") {
  if (typeof value === "number" && Number.isFinite(value)) return value;
  const raw = String(value ?? "").trim();
  if (!raw) return null;
  const percent = raw.includes("%");
  let clean = raw.replace(/\s/g, "").replace(/%/g, "");
  if (["clicks", "impressions"].includes(kind) && /^[-+]?\d{1,3}([.,]\d{3})+$/.test(clean)) {
    const integer = Number(clean.replace(/[.,]/g, ""));
    return Number.isFinite(integer) ? integer : null;
  }
  const comma = clean.lastIndexOf(",");
  const dot = clean.lastIndexOf(".");
  if (comma > dot) clean = clean.replace(/\./g, "").replace(",", ".");
  else if (dot > comma && comma >= 0) clean = clean.replace(/,/g, "");
  else if (comma >= 0) clean = clean.replace(",", ".");
  clean = clean.replace(/[^0-9+\-.eE]/g, "");
  const n = Number(clean);
  if (!Number.isFinite(n)) return null;
  if (kind === "ctr" && percent) return n / 100;
  return n;
}

export function normalizeMetricGrid(headers = [], rows = []) {
  const keys = headers.map(metricKey);
  const normalized = [];
  for (const row of rows || []) {
    const cells = Array.isArray(row) ? row : [];
    const item = {};
    cells.forEach((value, index) => {
      const key = keys[index];
      if (!key) return;
      if (["clicks", "impressions", "ctr", "position"].includes(key)) item[key] = parseMetricNumber(value, key);
      else item[key] = String(value ?? "").trim();
    });
    const metricCount = ["clicks", "impressions", "ctr", "position"].filter((key) => item[key] !== undefined && item[key] !== null).length;
    if (metricCount >= 2 || (metricCount >= 1 && (item.query || item.page))) normalized.push(item);
  }
  return normalized;
}

function normalizeApiRow(row, dimensions = []) {
  if (!row || typeof row !== "object" || Array.isArray(row)) return null;
  const out = {};
  const directMap = {
    clicks: "clicks", click: "clicks", impressions: "impressions", impression: "impressions",
    ctr: "ctr", position: "position", averagePosition: "position", average_position: "position",
    query: "query", page: "page", country: "country", device: "device", date: "date",
  };
  for (const [key, value] of Object.entries(row)) {
    const mapped = directMap[key] || metricKey(key);
    if (!mapped) continue;
    if (["clicks", "impressions", "ctr", "position"].includes(mapped)) out[mapped] = parseMetricNumber(value, mapped);
    else if (value !== null && value !== undefined) out[mapped] = String(value);
  }
  const keys = Array.isArray(row.keys) ? row.keys : Array.isArray(row.dimensions) ? row.dimensions : null;
  if (keys) {
    const dims = Array.isArray(dimensions) && dimensions.length ? dimensions : keys.length === 2 ? ["query", "page"] : keys.length === 1 ? ["query"] : [];
    keys.forEach((value, index) => {
      const key = metricKey(dims[index] || "") || String(dims[index] || "").trim();
      if (key) out[key] = String(value ?? "");
    });
  }
  const metricCount = ["clicks", "impressions", "ctr", "position"].filter((key) => out[key] !== undefined && out[key] !== null).length;
  return metricCount >= 2 || (metricCount >= 1 && (out.query || out.page)) ? out : null;
}

export function extractMetricRowsFromPayload(payload, dimensions = [], options = {}) {
  const maxNodes = Math.max(100, Math.min(50000, Number(options.maxNodes || 12000)));
  const rows = [];
  const queue = [payload];
  const seen = new Set();
  let nodes = 0;
  while (queue.length && nodes < maxNodes) {
    const value = queue.shift();
    nodes += 1;
    if (!value || typeof value !== "object") continue;
    if (seen.has(value)) continue;
    seen.add(value);
    if (Array.isArray(value)) {
      for (const item of value.slice(0, 5000)) queue.push(item);
      continue;
    }
    const row = normalizeApiRow(value, dimensions);
    if (row) rows.push(row);
    for (const child of Object.values(value)) {
      if (child && typeof child === "object") queue.push(child);
    }
  }
  return dedupeMetricRows(rows);
}

export function dedupeMetricRows(rows = []) {
  const out = [];
  const seen = new Set();
  for (const row of rows || []) {
    if (!row || typeof row !== "object") continue;
    const key = JSON.stringify([
      row.query || "", row.page || "", row.country || "", row.device || "", row.date || "",
      row.clicks ?? null, row.impressions ?? null, row.ctr ?? null, row.position ?? null,
    ]);
    if (seen.has(key)) continue;
    seen.add(key);
    const item = { ...row };
    if (typeof item.ctr === "number") item.ctr_percent = item.ctr * 100;
    out.push(item);
  }
  return out;
}

export function boundedCrawlerOptions(args = {}) {
  const nested = args?.browser && typeof args.browser === 'object' && !Array.isArray(args.browser) ? args.browser : {};
  const input = { ...nested, ...args };
  return {
    maxPages: Math.max(1, Math.min(1500, Number(input.max_pages || input.maxPages || input.limit || 25))),
    maxDepth: Math.max(0, Math.min(5, Number(input.max_depth ?? input.maxDepth ?? 2))),
    concurrency: Math.max(1, Math.min(4, Number(input.concurrency || 1))),
    delayMs: Math.max(100, Math.min(10000, Number(input.delay_ms ?? input.delayMs ?? 750))),
    allowCrossOrigin: Boolean(input.allow_cross_origin || input.allowCrossOrigin || false),
  };
}

function inspectionField(label) {
  const h = normalizedHeader(label);
  if (!h) return "";
  if ((h.includes("google") && h.includes("canonical")) || (h.includes("google") && h.includes("canonic"))) return "google_selected_canonical";
  if ((h.includes("user") || h.includes("utente")) && (h.includes("canonical") || h.includes("canonic"))) return "user_declared_canonical";
  if (h.includes("last crawl") || h.includes("ultima scansione")) return "last_crawl";
  if (h.includes("crawled as") || h.includes("scansione eseguita come")) return "crawled_as";
  if (h.includes("crawl allowed") || h.includes("scansione consentita") || h.includes("robots txt")) return "crawl_allowed";
  if (h.includes("page fetch") || h.includes("recupero pagina") || h.includes("fetch pagina")) return "page_fetch";
  if (h.includes("indexing allowed") || h.includes("indicizzazione consentita")) return "indexing_allowed";
  if (h.includes("coverage") || h.includes("copertura")) return "coverage";
  if (h.includes("page indexing") || h.includes("indicizzazione della pagina") || h.includes("indicizzazione pagina")) return "index_status";
  return "";
}

function cleanInspectionValue(value) {
  return String(value ?? "").replace(/\s+/g, " ").trim().slice(0, 1000);
}

function assignInspectionField(out, sources, field, value, source) {
  const clean = cleanInspectionValue(value);
  if (!field || !clean || clean.length > 1000) return;
  if (out[field] === undefined || out[field] === "") {
    out[field] = clean;
    sources[field] = source;
  }
}

function scanInspectionPayload(payload, out, sources, maxNodes = 12000) {
  const queue = [payload];
  const seen = new Set();
  let nodes = 0;
  while (queue.length && nodes < maxNodes) {
    const value = queue.shift();
    nodes += 1;
    if (!value || typeof value !== "object" || seen.has(value)) continue;
    seen.add(value);
    if (Array.isArray(value)) {
      for (const child of value.slice(0, 3000)) queue.push(child);
      continue;
    }
    for (const [key, child] of Object.entries(value)) {
      const k = normalizedHeader(key);
      let field = inspectionField(key);
      if (!field && k.includes("canonical")) {
        if (k.includes("google")) field = "google_selected_canonical";
        else if (k.includes("user") || k.includes("declared")) field = "user_declared_canonical";
      }
      if (!field && (k === "verdict" || k.includes("index status") || k.includes("indexing state"))) field = "index_status";
      if (!field && k.includes("coverage state")) field = "coverage";
      if (field && ["string", "number", "boolean"].includes(typeof child)) assignInspectionField(out, sources, field, child, `network:${key}`);
      if (child && typeof child === "object") queue.push(child);
    }
  }
}

/**
 * Normalize Search Console URL Inspection evidence from live DOM/network data.
 * No field is synthesized: data_verified is true only when recognized evidence exists.
 */
export function extractSearchConsoleInspection(input = {}) {
  const out = {};
  const sources = {};
  const pairs = Array.isArray(input.pairs) ? input.pairs : [];
  for (const pair of pairs) {
    if (!pair || typeof pair !== "object") continue;
    assignInspectionField(out, sources, inspectionField(pair.label), pair.value, "dom_pair");
  }

  const text = String(input.text || "");
  const lines = text.split(/\r?\n/).map((line) => line.replace(/\s+/g, " ").trim()).filter(Boolean).slice(0, 8000);
  for (let i = 0; i < lines.length; i += 1) {
    const line = lines[i];
    const direct = line.match(/^(.{2,90}?)(?:\s*[:–—]\s+)(.{1,1000})$/);
    if (direct) assignInspectionField(out, sources, inspectionField(direct[1]), direct[2], "dom_text");
    const field = inspectionField(line);
    if (field && i + 1 < lines.length && !inspectionField(lines[i + 1])) assignInspectionField(out, sources, field, lines[i + 1], "dom_text_next_line");
  }
  const statusSignals = [
    [/\bURL is on Google\b/i, "URL is on Google"],
    [/\bURL is not on Google\b/i, "URL is not on Google"],
    [/\bL['’]URL è su Google\b/i, "L’URL è su Google"],
    [/\bL['’]URL non è su Google\b/i, "L’URL non è su Google"],
  ];
  for (const [pattern, value] of statusSignals) if (pattern.test(text)) assignInspectionField(out, sources, "index_status", value, "dom_status_banner");

  for (const payload of Array.isArray(input.networkPayloads) ? input.networkPayloads : []) scanInspectionPayload(payload, out, sources);

  const verifiedFields = Object.keys(out);
  return {
    ...out,
    verified_fields: verifiedFields,
    field_sources: sources,
    extraction_status: verifiedFields.length ? "structured" : "no_verified_inspection_fields",
    data_verified: verifiedFields.length > 0,
    verified: verifiedFields.length > 0,
    no_data_reason: verifiedFields.length ? "" : "Search Console non ha esposto campi di Ispezione URL verificabili nella pagina o nei payload live.",
  };
}
