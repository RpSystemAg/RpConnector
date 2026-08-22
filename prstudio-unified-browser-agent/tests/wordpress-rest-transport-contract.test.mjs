import test from "node:test";
import assert from "node:assert/strict";
import {
  buildApiUrl,
  buildWordPressRestCandidates,
  discoverPairingEndpoint,
  installWordPressRestTransport,
  normalizeWordPressSiteBase,
  repairConcatenatedPlainRestUrl,
  restIndexSupportsPairRoute,
} from "../lib/wordpress-rest-transport.js";

const REST_NS = "prstudio-unified/v1";
const PAIR_ROUTE = "/prstudio-unified/v1/pair";

function fakeHeaders(contentType = "application/json; charset=utf-8") {
  return { get(name) { return String(name).toLowerCase() === "content-type" ? contentType : null; } };
}

function response(url, { status = 200, contentType = "application/json; charset=utf-8", body = {}, redirected = false } = {}) {
  return {
    url,
    status,
    statusText: status === 200 ? "OK" : "Error",
    ok: status >= 200 && status < 300,
    redirected,
    headers: fakeHeaders(contentType),
    async json() {
      if (body instanceof Error) throw body;
      return body;
    },
  };
}

function restIndex(url, mode = "pretty") {
  return response(url, {
    body: {
      name: "WordPress",
      namespaces: ["wp/v2", REST_NS],
      routes: { [PAIR_ROUTE]: { namespace: REST_NS, methods: ["POST"] } },
      _test_mode: mode,
    },
  });
}

function eventApi() {
  const listeners = [];
  return {
    addListener(listener) { listeners.push(listener); },
    emit(message) { for (const listener of listeners) listener(message, {}, () => {}); },
  };
}

for (const [site, pretty, plain] of [
  ["https://example.com", "https://example.com/wp-json/prstudio-unified/v1/pair", "https://example.com/?rest_route=%2Fprstudio-unified%2Fv1%2Fpair"],
  ["https://example.com/", "https://example.com/wp-json/prstudio-unified/v1/pair", "https://example.com/?rest_route=%2Fprstudio-unified%2Fv1%2Fpair"],
  ["https://example.com/wordpress", "https://example.com/wordpress/wp-json/prstudio-unified/v1/pair", "https://example.com/wordpress/?rest_route=%2Fprstudio-unified%2Fv1%2Fpair"],
  ["https://example.com/wordpress/", "https://example.com/wordpress/wp-json/prstudio-unified/v1/pair", "https://example.com/wordpress/?rest_route=%2Fprstudio-unified%2Fv1%2Fpair"],
  ["http://127.0.0.1:8082", "http://127.0.0.1:8082/wp-json/prstudio-unified/v1/pair", "http://127.0.0.1:8082/?rest_route=%2Fprstudio-unified%2Fv1%2Fpair"],
]) {
  test(`REST candidates preserve WordPress installation root: ${site}`, () => {
    const actual = buildWordPressRestCandidates(site);
    assert.equal(actual.pretty.pairUrl, pretty);
    assert.equal(actual.plain.pairUrl, plain);
    assert.equal(new URL(actual.pretty.pairUrl).origin, new URL(site).origin);
    assert.equal(new URL(actual.plain.pairUrl).origin, new URL(site).origin);
  });
}

test("site query and fragment cannot contaminate REST discovery", () => {
  const base = normalizeWordPressSiteBase("https://example.com/wordpress/?preview=1#frag");
  assert.equal(base.href, "https://example.com/wordpress/");
  const candidates = buildWordPressRestCandidates("https://example.com/wordpress/?preview=1#frag");
  assert.equal(candidates.pretty.indexUrl, "https://example.com/wordpress/wp-json/");
  assert.equal(candidates.plain.indexUrl, "https://example.com/wordpress/?rest_route=%2F");
});

test("non-loopback cleartext pairing fails closed", () => {
  assert.throws(() => normalizeWordPressSiteBase("http://example.com"), (error) => error?.code === "pairing_https_required");
});

test("site URL userinfo fails closed", () => {
  assert.throws(() => normalizeWordPressSiteBase("https://user:pass@example.com/wordpress"), (error) => error?.code === "site_credentials_forbidden");
});

test("malformed site URL fails closed", () => {
  assert.throws(() => normalizeWordPressSiteBase("not a site"), (error) => error?.code === "site_url_invalid");
});

test("REST index recognizes namespace and route forms", () => {
  assert.equal(restIndexSupportsPairRoute({ namespaces: [REST_NS] }), true);
  assert.equal(restIndexSupportsPairRoute({ routes: { [PAIR_ROUTE]: {} } }), true);
  assert.equal(restIndexSupportsPairRoute({ namespaces: ["wp/v2"], routes: {} }), false);
  assert.equal(restIndexSupportsPairRoute("html"), false);
});

for (const [apiBase, relative, expected] of [
  ["https://example.com/wp-json/prstudio-unified/v1", "/device/heartbeat", "https://example.com/wp-json/prstudio-unified/v1/device/heartbeat"],
  ["https://example.com/wp-json/prstudio-unified/v1/", "tasks/next", "https://example.com/wp-json/prstudio-unified/v1/tasks/next"],
  ["https://example.com/wp-json/prstudio-unified/v1", "/tasks/task-1/running", "https://example.com/wp-json/prstudio-unified/v1/tasks/task-1/running"],
  ["https://example.com/?rest_route=/prstudio-unified/v1", "/device/heartbeat", "https://example.com/?rest_route=%2Fprstudio-unified%2Fv1%2Fdevice%2Fheartbeat"],
  ["https://example.com/?rest_route=/prstudio-unified/v1", "/tasks/next", "https://example.com/?rest_route=%2Fprstudio-unified%2Fv1%2Ftasks%2Fnext"],
  ["https://example.com/wordpress/?rest_route=/prstudio-unified/v1", "/tasks/task-1/checkpoint", "https://example.com/wordpress/?rest_route=%2Fprstudio-unified%2Fv1%2Ftasks%2Ftask-1%2Fcheckpoint"],
  ["https://example.com/wordpress/?rest_route=/prstudio-unified/v1", "/tasks/task-1/complete", "https://example.com/wordpress/?rest_route=%2Fprstudio-unified%2Fv1%2Ftasks%2Ftask-1%2Fcomplete"],
  ["https://example.com/wordpress/?rest_route=/prstudio-unified/v1", "/logs", "https://example.com/wordpress/?rest_route=%2Fprstudio-unified%2Fv1%2Flogs"],
]) {
  test(`server-authoritative api_base composes ${relative}`, () => {
    const actual = buildApiUrl(apiBase, relative);
    assert.equal(actual.href, expected);
    assert.equal(actual.origin, new URL(apiBase).origin);
  });
}

test("api_base query transport preserves real query parameters", () => {
  const actual = buildApiUrl("https://example.com/?rest_route=/prstudio-unified/v1", "/tasks/next?wait=20&device=abc");
  assert.equal(actual.searchParams.get("rest_route"), "/prstudio-unified/v1/tasks/next");
  assert.equal(actual.searchParams.get("wait"), "20");
  assert.equal(actual.searchParams.get("device"), "abc");
});

test("api_base with embedded credentials fails closed", () => {
  assert.throws(() => buildApiUrl("https://user:pass@example.com/?rest_route=/prstudio-unified/v1", "/status"), (error) => error?.code === "api_credentials_forbidden");
});

test("absolute relative route is rejected rather than changing origin", () => {
  assert.throws(() => buildApiUrl("https://example.com/?rest_route=/prstudio-unified/v1", "https://evil.example/tasks/next"), (error) => error?.code === "api_relative_route_invalid");
});

test("legacy concatenation repair separates wait query from rest_route", () => {
  const actual = repairConcatenatedPlainRestUrl("http://127.0.0.1:8082/?rest_route=/prstudio-unified/v1/tasks/next?wait=20&lease=abc");
  assert.equal(actual.searchParams.get("rest_route"), "/prstudio-unified/v1/tasks/next");
  assert.equal(actual.searchParams.get("wait"), "20");
  assert.equal(actual.searchParams.get("lease"), "abc");
});

test("pretty REST discovery is non-mutating and authoritative", async () => {
  const calls = [];
  const fetchImpl = async (url, init = {}) => {
    calls.push({ url: String(url), init });
    return restIndex(String(url), "pretty");
  };
  const found = await discoverPairingEndpoint(fetchImpl, "https://example.com/wordpress");
  assert.equal(found.mode, "pretty");
  assert.equal(found.pairUrl, "https://example.com/wordpress/wp-json/prstudio-unified/v1/pair");
  assert.equal(calls.length, 1);
  assert.equal(calls[0].init.method, "GET");
  assert.equal(calls[0].init.body, undefined);
});

test("plain permalink discovery follows HTML pretty index without sending pairing secret", async () => {
  const calls = [];
  const fetchImpl = async (url, init = {}) => {
    calls.push({ url: String(url), init });
    if (String(url).includes("/wp-json/")) return response(String(url), { contentType: "text/html", body: new Error("not json") });
    return restIndex(String(url), "plain");
  };
  const found = await discoverPairingEndpoint(fetchImpl, "http://127.0.0.1:8082");
  assert.equal(found.mode, "plain");
  assert.equal(new URL(found.pairUrl).searchParams.get("rest_route"), PAIR_ROUTE);
  assert.equal(calls.length, 2);
  assert.ok(calls.every((call) => call.init.method === "GET"));
  assert.ok(calls.every((call) => call.init.body === undefined));
});

test("both invalid REST indexes fail closed", async () => {
  const fetchImpl = async (url) => response(String(url), { status: 404, body: { code: "rest_no_route" } });
  await assert.rejects(() => discoverPairingEndpoint(fetchImpl, "https://example.com"), (error) => error?.code === "pairing_rest_route_unavailable");
});

test("cross-origin REST discovery redirect fails closed", async () => {
  const fetchImpl = async () => response("https://evil.example/wp-json/", { redirected: true, body: { namespaces: [REST_NS] } });
  await assert.rejects(() => discoverPairingEndpoint(fetchImpl, "https://example.com"), (error) => error?.code === "pairing_redirect_forbidden");
});

test("transport performs one pairing POST only after plain REST discovery", async () => {
  const calls = [];
  const secret = "PAIR-CODE-SHOULD-NOT-LEAK";
  const onMessage = eventApi();
  const scope = {
    chrome: { runtime: { onMessage } },
    fetch: async (url, init = {}) => {
      calls.push({ url: String(url), init: { ...init } });
      const href = String(url);
      if (init.method === "GET" && href.includes("/wp-json/")) return response(href, { contentType: "text/html", body: new Error("html") });
      if (init.method === "GET") return restIndex(href, "plain");
      if (init.method === "POST") return response(href, { body: { device_id: "device-1", token: "token-1", api_base: "http://127.0.0.1:8082/?rest_route=/prstudio-unified/v1" } });
      throw new Error(`unexpected method ${init.method}`);
    },
  };
  installWordPressRestTransport(scope);
  onMessage.emit({ type: "pair", siteUrl: "http://127.0.0.1:8082", code: secret });
  const result = await scope.fetch("http://127.0.0.1:8082/wp-json/prstudio-unified/v1/pair", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ code: secret, name: "Agent" }),
  });
  assert.equal(result.ok, true);
  const gets = calls.filter((call) => call.init.method === "GET");
  const posts = calls.filter((call) => call.init.method === "POST");
  assert.equal(gets.length, 2);
  assert.equal(posts.length, 1);
  assert.equal(new URL(posts[0].url).searchParams.get("rest_route"), PAIR_ROUTE);
  assert.ok(gets.every((call) => call.init.body === undefined));
  assert.ok(gets.every((call) => !call.url.includes(secret) && !JSON.stringify(call.init).includes(secret)));
});

test("authoritative JSON 403 pairing response never triggers a second POST", async () => {
  const calls = [];
  const scope = {
    chrome: { runtime: { onMessage: eventApi() } },
    fetch: async (url, init = {}) => {
      calls.push({ url: String(url), init });
      if (init.method === "GET") return restIndex(String(url));
      return response(String(url), { status: 403, body: { code: "pairing_code_invalid", message: "invalid" } });
    },
  };
  installWordPressRestTransport(scope);
  const result = await scope.fetch("https://example.com/wp-json/prstudio-unified/v1/pair", { method: "POST", body: "{}" });
  assert.equal(result.status, 403);
  assert.equal(calls.filter((call) => call.init.method === "POST").length, 1);
});

test("authoritative JSON 409 pairing response never triggers a second POST", async () => {
  const calls = [];
  const scope = {
    chrome: { runtime: { onMessage: eventApi() } },
    fetch: async (url, init = {}) => {
      calls.push({ url: String(url), init });
      if (init.method === "GET") return restIndex(String(url));
      return response(String(url), { status: 409, body: { code: "pairing_contract_mismatch" } });
    },
  };
  installWordPressRestTransport(scope);
  const result = await scope.fetch("https://example.com/wp-json/prstudio-unified/v1/pair", { method: "POST", body: "{}" });
  assert.equal(result.status, 409);
  assert.equal(calls.filter((call) => call.init.method === "POST").length, 1);
});

test("HTML 200 pairing response is rejected as non-JSON", async () => {
  const scope = {
    chrome: { runtime: { onMessage: eventApi() } },
    fetch: async (url, init = {}) => init.method === "GET"
      ? restIndex(String(url))
      : response(String(url), { contentType: "text/html", body: new Error("html") }),
  };
  installWordPressRestTransport(scope);
  await assert.rejects(
    () => scope.fetch("https://example.com/wp-json/prstudio-unified/v1/pair", { method: "POST", body: "{}" }),
    (error) => error?.code === "pairing_response_not_json",
  );
});

test("cross-origin pairing final URL is rejected", async () => {
  const scope = {
    chrome: { runtime: { onMessage: eventApi() } },
    fetch: async (url, init = {}) => init.method === "GET"
      ? restIndex(String(url))
      : response("https://evil.example/pair", { redirected: true, body: {} }),
  };
  installWordPressRestTransport(scope);
  await assert.rejects(
    () => scope.fetch("https://example.com/wp-json/prstudio-unified/v1/pair", { method: "POST", body: "{}" }),
    (error) => error?.code === "pairing_redirect_forbidden",
  );
});

test("plain api_base long-poll query is repaired at production transport boundary", async () => {
  const calls = [];
  const scope = {
    chrome: { runtime: { onMessage: eventApi() } },
    fetch: async (url, init = {}) => { calls.push({ url: String(url), init }); return response(String(url), { body: {} }); },
  };
  installWordPressRestTransport(scope);
  await scope.fetch("https://example.com/?rest_route=/prstudio-unified/v1/tasks/next?wait=20&device=device-1", { method: "GET" });
  assert.equal(calls.length, 1);
  const actual = new URL(calls[0].url);
  assert.equal(actual.searchParams.get("rest_route"), "/prstudio-unified/v1/tasks/next");
  assert.equal(actual.searchParams.get("wait"), "20");
  assert.equal(actual.searchParams.get("device"), "device-1");
});
