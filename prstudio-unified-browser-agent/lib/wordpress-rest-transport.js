import { parseUserUrl } from "./url-input.js";

const PAIR_ROUTE = "/prstudio-unified/v1/pair";
const REST_NAMESPACE = "prstudio-unified/v1";

function transportError(code, message, details = null) {
  const error = new Error(message);
  error.code = code;
  if (details && typeof details === "object") error.details = details;
  return error;
}

function isLoopback(hostname) {
  return ["localhost", "127.0.0.1", "[::1]", "::1"].includes(String(hostname || "").toLowerCase());
}

/**
 * Normalize the user-entered WordPress installation root without discarding a
 * subdirectory. Query strings/fragments are never part of the installation
 * root and are deliberately dropped before REST discovery.
 */
export function normalizeWordPressSiteBase(value) {
  const parsed = parseUserUrl(value);
  if (!parsed?.url) throw transportError("site_url_invalid", "URL WordPress non valido.");
  const url = new URL(parsed.url.href);
  if (url.username || url.password) {
    throw transportError("site_credentials_forbidden", "L'URL WordPress non può contenere credenziali.");
  }
  if (url.protocol !== "https:" && !(url.protocol === "http:" && isLoopback(url.hostname))) {
    throw transportError("pairing_https_required", "Il pairing richiede HTTPS (HTTP è ammesso soltanto su loopback locale).");
  }
  url.search = "";
  url.hash = "";
  url.pathname = `${url.pathname.replace(/\/+$/, "")}/`;
  return url;
}

export function buildWordPressRestCandidates(siteUrl, route = PAIR_ROUTE) {
  const base = normalizeWordPressSiteBase(siteUrl);
  const normalizedRoute = `/${String(route || "").replace(/^\/+/, "")}`;
  const relativeRoute = normalizedRoute.replace(/^\//, "");

  const prettyIndex = new URL("wp-json/", base);
  const prettyPair = new URL(`wp-json/${relativeRoute}`, base);
  const plainIndex = new URL(base.href);
  plainIndex.searchParams.set("rest_route", "/");
  const plainPair = new URL(base.href);
  plainPair.searchParams.set("rest_route", normalizedRoute);

  for (const candidate of [prettyIndex, prettyPair, plainIndex, plainPair]) {
    if (candidate.origin !== base.origin || candidate.username || candidate.password) {
      throw transportError("pairing_api_origin_mismatch", "Il REST WordPress deve restare sulla stessa origine del sito.");
    }
  }

  return {
    base,
    pretty: { mode: "pretty", indexUrl: prettyIndex.href, pairUrl: prettyPair.href },
    plain: { mode: "plain", indexUrl: plainIndex.href, pairUrl: plainPair.href },
  };
}

function relativeRouteParts(relativeRoute) {
  const raw = String(relativeRoute == null ? "" : relativeRoute).trim();
  if (!raw || raw.startsWith("//") || /^[a-z][a-z0-9+.-]*:/i.test(raw)) {
    throw transportError("api_relative_route_invalid", "La rotta API relativa non è valida.");
  }
  const absolute = new URL(raw.startsWith("/") ? `https://route.invalid${raw}` : `https://route.invalid/${raw}`);
  return { pathname: absolute.pathname, searchParams: absolute.searchParams };
}

/**
 * Compose an endpoint from the server-authoritative api_base. Supports both
 * pretty REST bases and WordPress plain-permalink bases using ?rest_route=.
 */
export function buildApiUrl(apiBase, relativeRoute) {
  const base = new URL(String(apiBase || ""));
  if (base.username || base.password) throw transportError("api_credentials_forbidden", "api_base non può contenere credenziali.");
  const { pathname, searchParams } = relativeRouteParts(relativeRoute);
  const out = new URL(base.href);
  const restRoute = out.searchParams.get("rest_route");

  if (restRoute != null) {
    const joined = `${String(restRoute).replace(/\/+$/, "")}/${pathname.replace(/^\/+/, "")}`.replace(/\/{2,}/g, "/");
    out.searchParams.set("rest_route", joined.startsWith("/") ? joined : `/${joined}`);
  } else {
    out.pathname = `${out.pathname.replace(/\/+$/, "")}/${pathname.replace(/^\/+/, "")}`.replace(/\/{2,}/g, "/");
  }
  for (const [key, value] of searchParams) out.searchParams.set(key, value);
  out.hash = "";
  return out;
}

export function restIndexSupportsPairRoute(payload) {
  if (!payload || typeof payload !== "object" || Array.isArray(payload)) return false;
  const namespaces = Array.isArray(payload.namespaces) ? payload.namespaces.map(String) : [];
  if (namespaces.includes(REST_NAMESPACE)) return true;
  const routes = payload.routes && typeof payload.routes === "object" ? payload.routes : {};
  return Object.prototype.hasOwnProperty.call(routes, PAIR_ROUTE)
    || Object.keys(routes).some((route) => String(route).replace(/\/$/, "") === PAIR_ROUTE);
}

function responseContentType(response) {
  return String(response?.headers?.get?.("content-type") || "").toLowerCase();
}

function isJsonContentType(contentType) {
  return /(?:^|[;\s])application\/(?:[a-z0-9.+-]*\+)?json(?:[;\s]|$)/i.test(String(contentType || ""));
}

function safeResponseUrl(response, fallback) {
  try { return new URL(String(response?.url || fallback)); } catch { return null; }
}

async function inspectRestIndex(fetchImpl, candidate, baseOrigin, signal) {
  let response;
  try {
    response = await fetchImpl(candidate.indexUrl, {
      method: "GET",
      headers: { Accept: "application/json" },
      cache: "no-store",
      credentials: "omit",
      redirect: "error",
      signal,
    });
  } catch (error) {
    return { ok: false, mode: candidate.mode, reason: "fetch_failed", message: String(error?.message || error || "") };
  }

  const finalUrl = safeResponseUrl(response, candidate.indexUrl);
  if (response?.redirected || !finalUrl || finalUrl.origin !== baseOrigin) {
    throw transportError("pairing_redirect_forbidden", "La discovery REST non consente redirect o cambi di origine.", { mode: candidate.mode });
  }
  const contentType = responseContentType(response);
  if (!response?.ok || !isJsonContentType(contentType)) {
    return { ok: false, mode: candidate.mode, reason: !response?.ok ? `http_${Number(response?.status || 0)}` : "not_json", status: Number(response?.status || 0), contentType };
  }
  let payload;
  try {
    payload = await response.json();
  } catch {
    return { ok: false, mode: candidate.mode, reason: "malformed_json", status: Number(response?.status || 0), contentType };
  }
  if (!restIndexSupportsPairRoute(payload)) {
    return { ok: false, mode: candidate.mode, reason: "pair_route_missing", status: Number(response?.status || 0), contentType };
  }
  return { ok: true, mode: candidate.mode, status: Number(response?.status || 0), contentType };
}

export async function discoverPairingEndpoint(fetchImpl, siteUrl, { signal } = {}) {
  if (typeof fetchImpl !== "function") throw transportError("pairing_rest_discovery_failed", "Fetch REST non disponibile.");
  const candidates = buildWordPressRestCandidates(siteUrl);
  const diagnostics = [];
  for (const candidate of [candidates.pretty, candidates.plain]) {
    const result = await inspectRestIndex(fetchImpl, candidate, candidates.base.origin, signal);
    diagnostics.push(result);
    if (result.ok) return { ...candidate, base: candidates.base, diagnostics };
  }
  throw transportError("pairing_rest_route_unavailable", "Nessuna rotta REST WordPress compatibile espone il pairing.", { diagnostics });
}

/**
 * service-worker.js historically concatenates relative query strings onto a
 * query-based api_base. WordPress then receives e.g.
 *   ?rest_route=/.../tasks/next?wait=20
 * where wait accidentally becomes part of rest_route. Repair that shape at the
 * transport boundary without changing origin, credentials or request body.
 */
export function repairConcatenatedPlainRestUrl(value) {
  const url = new URL(String(value));
  const route = url.searchParams.get("rest_route");
  if (route == null) return url;
  const marker = route.indexOf("?");
  if (marker < 0) return url;
  const routeOnly = route.slice(0, marker);
  const embedded = new URLSearchParams(route.slice(marker + 1));
  url.searchParams.set("rest_route", routeOnly);
  for (const [key, item] of embedded) url.searchParams.set(key, item);
  return url;
}

function requestMethod(input, init) {
  return String(init?.method || (typeof Request !== "undefined" && input instanceof Request ? input.method : "GET") || "GET").toUpperCase();
}

function requestHref(input) {
  if (typeof input === "string") return input;
  if (input instanceof URL) return input.href;
  if (typeof Request !== "undefined" && input instanceof Request) return input.url;
  return String(input || "");
}

function isPrettyPairRequest(url, method) {
  if (method !== "POST") return false;
  return url.pathname.endsWith(`/wp-json${PAIR_ROUTE}`);
}

function deriveSiteBaseFromPrettyPair(url) {
  const suffix = `/wp-json${PAIR_ROUTE}`;
  const path = url.pathname.slice(0, -suffix.length) || "/";
  const base = new URL(url.href);
  base.pathname = `${path.replace(/\/+$/, "")}/`;
  base.search = "";
  base.hash = "";
  return base.href;
}

function assertAuthoritativePairResponse(response, siteOrigin, fallbackUrl) {
  const finalUrl = safeResponseUrl(response, fallbackUrl);
  if (response?.redirected || !finalUrl || finalUrl.origin !== siteOrigin) {
    throw transportError("pairing_redirect_forbidden", "Il pairing non consente redirect o cambi di origine.");
  }
  if (response?.ok && !isJsonContentType(responseContentType(response))) {
    throw transportError("pairing_response_not_json", "La risposta di pairing non è JSON REST valido.", {
      status: Number(response?.status || 0),
      contentType: responseContentType(response),
    });
  }
}

/**
 * Install the compatibility transport used by the real MV3 bootstrap.
 * Discovery GETs never carry the pairing body; the original one-time POST is
 * issued exactly once after a REST route has been proven. The transport stays
 * below runtime messaging: it does not register a competing onMessage listener.
 */
export function installWordPressRestTransport(scope = globalThis) {
  if (!scope || typeof scope.fetch !== "function") return { installed: false };
  if (scope.__PRSTUDIO_WORDPRESS_REST_TRANSPORT__?.installed) return scope.__PRSTUDIO_WORDPRESS_REST_TRANSPORT__;

  const nativeFetch = scope.fetch.bind(scope);
  const wrappedFetch = async (input, init = undefined) => {
    const href = requestHref(input);
    let parsed;
    try { parsed = new URL(href); } catch { return nativeFetch(input, init); }
    const method = requestMethod(input, init);

    if (isPrettyPairRequest(parsed, method)) {
      const siteUrl = deriveSiteBaseFromPrettyPair(parsed);
      const discovered = await discoverPairingEndpoint(nativeFetch, siteUrl, { signal: init?.signal });
      const response = await nativeFetch(discovered.pairUrl, init);
      assertAuthoritativePairResponse(response, discovered.base.origin, discovered.pairUrl);
      return response;
    }

    if (parsed.searchParams.has("rest_route") && String(parsed.searchParams.get("rest_route") || "").includes("?")) {
      const repaired = repairConcatenatedPlainRestUrl(parsed.href);
      if (repaired.origin !== parsed.origin) throw transportError("api_request_origin_mismatch", "La rotta REST riparata ha cambiato origine.");
      return nativeFetch(repaired.href, init);
    }
    return nativeFetch(input, init);
  };

  scope.fetch = wrappedFetch;
  const state = { installed: true, nativeFetch, wrappedFetch };
  scope.__PRSTUDIO_WORDPRESS_REST_TRANSPORT__ = state;
  return state;
}

export const WORDPRESS_REST_TEST_CONSTANTS = Object.freeze({ PAIR_ROUTE, REST_NAMESPACE });
