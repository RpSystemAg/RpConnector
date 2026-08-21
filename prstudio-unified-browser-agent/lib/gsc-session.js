/** Pure Search Console session/dimension helpers. No Chrome dependency. */
const SUPPORTED = Object.freeze(["query", "page", "country", "device", "searchAppearance", "date"]);
const ALIASES = Object.freeze({
  query: ["query", "queries", "search query", "search queries", "query di ricerca", "query di ricerca principali"],
  page: ["page", "pages", "pagina", "pagine"],
  country: ["country", "countries", "paese", "paesi"],
  device: ["device", "devices", "dispositivo", "dispositivi"],
  searchAppearance: ["search appearance", "search appearances", "aspetto nella ricerca", "aspetti nella ricerca"],
  date: ["date", "dates", "data", "date del report", "data del report"],
});

export function normalizeGscText(value) {
  return String(value || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, " ").trim();
}

export function unsupportedGscDimensions(input = []) {
  const source = Array.isArray(input) ? input : [];
  const unsupported = [];
  for (const value of source) {
    const normalized = normalizeGscText(value);
    const raw = normalized.replace(/\s/g, "");
    let canonical = raw === "queries" ? "query" : raw === "pages" ? "page" : raw === "countries" ? "country" : raw === "devices" ? "device" : raw === "searchappearance" ? "searchAppearance" : raw === "dates" ? "date" : raw;
    if (!SUPPORTED.includes(canonical)) {
      canonical = SUPPORTED.find((dim) => (ALIASES[dim] || []).some((alias) => normalizeGscText(alias) === normalized)) || canonical;
    }
    if (!SUPPORTED.includes(canonical) && !unsupported.includes(String(value))) unsupported.push(String(value));
  }
  return unsupported;
}

export function normalizeGscDimensions(input = []) {
  const source = Array.isArray(input) && input.length ? input : ["query", "page"];
  const out = [];
  for (const value of source) {
    const normalized = normalizeGscText(value);
    const raw = normalized.replace(/\s/g, "");
    let canonical = raw === "queries" ? "query" : raw === "pages" ? "page" : raw === "countries" ? "country" : raw === "devices" ? "device" : raw === "searchappearance" ? "searchAppearance" : raw === "dates" ? "date" : raw;
    if (!SUPPORTED.includes(canonical)) {
      canonical = SUPPORTED.find((dim) => (ALIASES[dim] || []).some((alias) => normalizeGscText(alias) === normalized)) || canonical;
    }
    if (SUPPORTED.includes(canonical) && !out.includes(canonical)) out.push(canonical);
  }
  return out.length ? out : ["query"];
}

export function gscDimensionAliases(dimension) {
  return [...(ALIASES[normalizeGscDimensions([dimension])[0]] || [])];
}

export function headerMatchesGscDimension(header, dimension) {
  const h = normalizeGscText(header);
  return gscDimensionAliases(dimension).some((alias) => h === normalizeGscText(alias) || h.startsWith(`${normalizeGscText(alias)} `));
}

export function labelMatchesGscDimension(label, dimension) {
  const h = normalizeGscText(label);
  return gscDimensionAliases(dimension).some((alias) => h === normalizeGscText(alias));
}

export function inferGscDimensionFromHeaders(headers = []) {
  const first = String(Array.isArray(headers) ? headers[0] || "" : "");
  return SUPPORTED.find((dim) => headerMatchesGscDimension(first, dim)) || "";
}

export function validateGscDimensionRows(rows = [], dimension) {
  const dim = normalizeGscDimensions([dimension])[0];
  const accepted = [];
  let rejected = 0;
  for (const row of Array.isArray(rows) ? rows : []) {
    if (!row || typeof row !== "object" || Array.isArray(row)) { rejected += 1; continue; }
    const hasDimension = typeof row[dim] === "string" && row[dim].trim() !== "";
    const metricCount = ["clicks", "impressions", "ctr", "position"].filter((key) => typeof row[key] === "number" && Number.isFinite(row[key])).length;
    if (hasDimension && metricCount >= 1) accepted.push({ ...row, _verified_dimension: dim });
    else rejected += 1;
  }
  return { dimension: dim, rows: accepted, accepted: accepted.length, rejected, verified: rejected === 0 && accepted.length > 0 };
}

function gscResource(url) {
  try { return new URL(url).searchParams.get("resource_id") || ""; } catch { return ""; }
}

export function shouldNavigateSearchConsole(currentUrl, desiredUrl, mode = "search_analytics") {
  let current; let desired;
  try { current = new URL(String(currentUrl || "")); desired = new URL(String(desiredUrl || "")); } catch { return true; }
  if (current.origin !== "https://search.google.com" || desired.origin !== "https://search.google.com") return true;
  if (mode === "search_analytics") {
    const currentAnalytics = current.pathname.includes("/search-console/performance/search-analytics");
    const desiredAnalytics = desired.pathname.includes("/search-console/performance/search-analytics");
    if (!currentAnalytics || !desiredAnalytics) return true;
    const wanted = gscResource(desired.href); const active = gscResource(current.href);
    return Boolean(wanted && active && wanted !== active) || Boolean(wanted && !active);
  }
  return current.pathname !== desired.pathname || (gscResource(desired.href) && gscResource(current.href) !== gscResource(desired.href));
}

export function mergeGscDimensionCollections(collections = [], requested = []) {
  const dims = normalizeGscDimensions(requested);
  const results = {};
  const missing = [];
  for (const dim of dims) {
    const found = (Array.isArray(collections) ? collections : []).find((item) => item?.dimension === dim && item?.dimension_integrity?.status === "verified");
    if (found) results[dim] = found; else missing.push(dim);
  }
  const single = dims.length === 1 && results[dims[0]] ? results[dims[0]].rows || [] : [];
  return {
    requested_dimensions: dims,
    dimensions_collected: Object.keys(results),
    dimension_results: results,
    rows: single,
    row_count: single.length,
    cross_dimension_join: false,
    completeness: missing.length === 0 ? (dims.length === 1 ? true : "verified_dimension_sets_no_cross_join") : false,
    collection_completeness: missing.length === 0 ? "bounded_by_search_console_and_observed_ui" : "partial_dimension_collection",
    row_exhaustiveness: "not_guaranteed",
    totals_scope: "active_search_console_report_and_dimension",
    missing_dimensions: missing,
    dimension_integrity: {
      status: missing.length ? "partial" : "verified",
      mode: dims.length === 1 ? "active_tab_header_bound" : "separate_active_tab_header_bound_sets",
      cross_dimension_join: false,
      requested: dims,
      collected: Object.keys(results),
    },
    verified: missing.length === 0,
  };
}

export function normalizeGscProperty(value = "") {
  const raw = String(value || "").trim();
  if (!raw) return "";
  if (/^sc-domain:/i.test(raw)) return `sc-domain:${raw.replace(/^sc-domain:/i, "").trim().toLowerCase()}`;
  try {
    const url = new URL(raw);
    const path = url.pathname === "/" ? "/" : url.pathname.replace(/\/+$/, "") + "/";
    return `${url.protocol}//${url.host.toLowerCase()}${path}`;
  } catch {
    return raw.replace(/\/+$/, "").toLowerCase();
  }
}

export function gscPropertyMatches(actual = "", requested = "") {
  const wanted = normalizeGscProperty(requested);
  if (!wanted) return true;
  return normalizeGscProperty(actual) === wanted;
}

export function gscPropertyLabels(site = "") {
  const normalized = normalizeGscProperty(site);
  const values = new Set([String(site || "").trim(), normalized]);
  if (/^sc-domain:/i.test(normalized)) values.add(normalized.replace(/^sc-domain:/i, ""));
  else {
    try {
      const url = new URL(normalized);
      values.add(url.hostname);
      values.add(`${url.protocol}//${url.host}/`);
    } catch { /* not an URL-prefix property */ }
  }
  return [...values].filter(Boolean);
}
