import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

import { parseUserUrl } from "../lib/url-input.js";
import {
  candidateTabUrl,
  provisionalOwnershipState,
  migrateTabReplacementRecord,
  migrateTabReplacementState,
  tabBindingCompatibility,
} from "../lib/tab-ownership.js";
import {
  normalizeUrlForEvidence,
  assessUserUrlRegex,
  testUserUrlRegex,
  matchLiteralWildcard,
  compareUrlEvidence,
} from "../lib/protocol.js";
import { DEBUGGER_PROTOCOL_CANDIDATES, attachWithProtocolFallback } from "../lib/cdp-protocol.js";

const here = path.dirname(fileURLToPath(import.meta.url));
const extensionRoot = path.join(here, "..");
const manifest = JSON.parse(fs.readFileSync(path.join(extensionRoot, "manifest.json"), "utf8"));

// Each generated subtest exercises a distinct production input/state tuple.
// This matrix complements, but never substitutes for, the real-Chrome remote
// lifecycle gates in CI.

const manifestInvariants = [
  ["MV3", manifest.manifest_version, 3],
  ["semantic version", /^\d+\.\d+\.\d+$/.test(manifest.version), true],
  ["minimum Chrome numeric", /^\d+$/.test(manifest.minimum_chrome_version), true],
  ["minimum Chrome floor", Number(manifest.minimum_chrome_version) >= 120, true],
  ["module background", manifest.background?.type, "module"],
  ["bootstrap background", manifest.background?.service_worker, "service-worker-bootstrap.js"],
  ["action title present", Boolean(manifest.action?.default_title), true],
  ["side panel present", manifest.side_panel?.default_path, "sidepanel.html"],
  ["all urls host permission", manifest.host_permissions?.includes("<all_urls>"), true],
  ["all urls content script", manifest.content_scripts?.[0]?.matches?.includes("<all_urls>"), true],
  ["document_start", manifest.content_scripts?.[0]?.run_at, "document_start"],
  ["all frames", manifest.content_scripts?.[0]?.all_frames, true],
  ["match about blank", manifest.content_scripts?.[0]?.match_about_blank, true],
  ["isolated world", manifest.content_scripts?.[0]?.world, "ISOLATED"],
  ["reconnect first", manifest.content_scripts?.[0]?.js?.[0], "lib/reconnect-backoff.js"],
  ["dirty notifier second", manifest.content_scripts?.[0]?.js?.[1], "lib/runtime-dirty-notifier.js"],
  ["page runtime last", manifest.content_scripts?.[0]?.js?.at(-1), "page-runtime.js"],
  ["no legacy background scripts", Array.isArray(manifest.background?.scripts), false],
  ["no background page", Boolean(manifest.background?.page), false],
  ["description non-empty", Boolean(String(manifest.description || "").trim()), true],
];
for (const [name, actual, expected] of manifestInvariants) {
  test(`manifest invariant: ${name}`, () => assert.deepEqual(actual, expected));
}
const requiredPermissions = ["storage", "alarms", "tabs", "scripting", "debugger", "activeTab", "downloads", "webNavigation", "sidePanel", "tabGroups", "system.display", "notifications"];
for (const permission of requiredPermissions) {
  test(`manifest permission: ${permission}`, () => assert.equal(manifest.permissions.includes(permission), true));
}
const declaredResources = [manifest.background.service_worker, manifest.side_panel.default_path, ...manifest.content_scripts[0].js];
for (const resource of declaredResources) {
  test(`manifest resource exists: ${resource}`, () => assert.equal(fs.existsSync(path.join(extensionRoot, resource)), true));
}

const hosts = [
  "example.com", "www.example.com", "api.example.com", "sub.domain.example", "localhost",
  "127.0.0.1", "search.google.com", "trends.google.com", "idealmarket1987.com", "xn--bcher-kva.example",
];
const suffixes = ["", "/", "/alpha", "/a/b", "/?q=one", "/path?x=1&y=2#frag"];
for (const host of hosts) {
  for (const suffix of suffixes) {
    for (const explicit of [false, true]) {
      const raw = `${explicit ? "https://" : ""}${host}${suffix}`;
      test(`URL input ${explicit ? "explicit" : "bare"}: ${host}${suffix || "<root>"}`, () => {
        const parsed = parseUserUrl(raw);
        assert.ok(parsed?.url instanceof URL);
        assert.equal(parsed.url.protocol, "https:");
        assert.equal(parsed.url.hostname, new URL(`https://${host}`).hostname);
        assert.equal(parsed.coerced, !explicit);
      });
    }
  }
}
for (const port of [80, 81, 443, 444, 3000, 38889, 8080, 8082, 9000, 65535]) {
  test(`URL input localhost port ${port}`, () => {
    const parsed = parseUserUrl(`localhost:${port}/health`);
    assert.ok(parsed?.url instanceof URL);
    assert.equal(parsed.url.protocol, "https:");
    assert.equal(parsed.url.hostname, "localhost");
    assert.equal(parsed.coerced, true);
  });
}
const invalidInputs = [
  "", "   ", "not a host", "two words.example", ".", "..", "...", "http://", "https://", "://bad",
  "exa mple.com", "example .com", "[", "]", "{}", "?query-only", "#fragment-only", "/relative-only",
  "\\windows\\path", "http://[::1", "https://?x", "https://#x", "localhost port", "\u0000", "\n", "\t",
];
for (const [i, raw] of invalidInputs.entries()) {
  test(`URL input rejects malformed variant ${i + 1}`, () => assert.equal(parseUserUrl(raw), null));
}
const schemeInputs = ["javascript:alert(1)", "data:text/plain,hello", "about:blank", "file:///tmp/a", "chrome://settings/"];
for (const raw of schemeInputs) {
  test(`URL parser preserves explicit scheme for downstream policy: ${raw.split(":")[0]}`, () => {
    const parsed = parseUserUrl(raw);
    assert.ok(parsed?.url instanceof URL);
    assert.equal(parsed.coerced, false);
    assert.equal(parsed.url.protocol, `${raw.split(":")[0]}:`);
  });
}

const ownerLanes = ["", "lane-a", "lane-b"];
const requestedLanes = ["", "lane-a", "lane-b", "lane-c"];
const ownerTasks = ["", "task-a", "task-b"];
const requestedTasks = ["", "task-a", "task-c"];
for (const ownerLane of ownerLanes) {
  for (const requestedLane of requestedLanes) {
    for (const ownerTask of ownerTasks) {
      for (const requestedTask of requestedTasks) {
        test(`ownership ownerLane=${ownerLane || "none"} requestedLane=${requestedLane || "none"} ownerTask=${ownerTask || "none"} requestedTask=${requestedTask || "none"}`, () => {
          const result = tabBindingCompatibility({ laneId: ownerLane, taskId: ownerTask }, { laneId: requestedLane, taskId: requestedTask });
          const laneConflict = Boolean(ownerLane && requestedLane && ownerLane !== requestedLane);
          assert.equal(result.ok, !laneConflict);
          if (laneConflict) {
            assert.equal(result.code, "tab_lane_conflict");
          } else {
            const changedTask = Boolean(ownerTask && requestedTask && ownerTask !== requestedTask);
            const effectiveLane = ownerLane || requestedLane;
            const expectedMode = changedTask ? (effectiveLane ? "lane_task_rebind" : "session_task_rebind") : (effectiveLane ? "lane_owned" : "session_owned");
            assert.equal(result.mode, expectedMode);
          }
        });
      }
    }
  }
}
const alternateLaneContexts = [
  [{ laneId: "lane-a", taskId: "old" }, { _prstudio_lane_id: "lane-a", taskId: "new" }, true, "lane_task_rebind"],
  [{ laneId: "lane-a", taskId: "old" }, { _prstudio_lane_id: "lane-b", taskId: "new" }, false, "tab_lane_conflict"],
  [{ laneId: "", taskId: "old" }, { _prstudio_lane_id: "lane-a", taskId: "new" }, true, "lane_task_rebind"],
  [{ laneId: "lane-a", taskId: "" }, { _prstudio_lane_id: "lane-a", taskId: "new" }, true, "lane_owned"],
];
for (const [i, [record, context, ok, modeOrCode]] of alternateLaneContexts.entries()) {
  test(`ownership alternate lane context ${i + 1}`, () => {
    const result = tabBindingCompatibility(record, context);
    assert.equal(result.ok, ok);
    assert.equal(ok ? result.mode : result.code, modeOrCode);
  });
}

const committedCandidates = ["about:blank", "chrome://newtab/", "", "https://example.com/a", "http://localhost:8080/a"];
const pendingCandidates = ["", "https://pending.example/a", "http://127.0.0.1:8080/p", "chrome://settings/", "data:text/plain,x"];
for (const committed of committedCandidates) {
  for (const pending of pendingCandidates) {
    test(`provisional ownership committed=${committed || "empty"} pending=${pending || "empty"}`, () => {
      const fallback = "https://fallback.example/path";
      const expected = /^https?:/i.test(committed) ? committed : (/^https?:/i.test(pending) ? pending : fallback);
      assert.equal(candidateTabUrl({ url: committed, pendingUrl: pending }, fallback), expected);
      const state = provisionalOwnershipState({ url: committed, pendingUrl: pending }, { url: fallback, provisional: true });
      assert.equal(state.candidateUrl, expected);
      assert.equal(state.committedHttp, /^https?:/i.test(committed));
      assert.equal(state.candidateHttp, /^https?:/i.test(expected));
      assert.equal(state.provisional, !/^https?:/i.test(committed) && /^https?:/i.test(expected));
    });
  }
}

const replacementUrls = ["https://example.com/one", "https://search.google.com/two", "http://localhost:8080/three", "about:blank"];
for (const removedId of [7, 11, 23, 41]) {
  for (const addedId of [101, 202, 303]) {
    for (const [variant, url] of replacementUrls.entries()) {
      test(`tab replacement ${removedId}->${addedId} urlVariant=${variant}`, () => {
        const now = 1700000000000 + removedId * 100 + addedId + variant;
        const record = { tabId: removedId, windowId: 2, url: `https://fallback.example/${removedId}/${addedId}`, title: "before", laneId: "lane-a", taskId: "task-old", provisional: false };
        const addedTab = { id: addedId, windowId: 9, url, pendingUrl: url === "about:blank" ? `https://pending.example/${addedId}` : "", title: `after-${variant}` };
        const migrated = migrateTabReplacementRecord(record, addedTab, removedId, now);
        assert.equal(migrated.tabId, addedId);
        assert.equal(migrated.replacedFromTabId, removedId);
        assert.equal(migrated.laneId, "lane-a");
        assert.equal(migrated.taskId, "task-old");
        assert.equal(migrated.updatedAt, now);
        const state = migrateTabReplacementState({
          registry: { [removedId]: record, 999: { tabId: 999, laneId: "lane-z" } },
          affinityTasks: { a: { tabId: removedId }, b: { tabId: 999 } },
          lastTabId: removedId,
          activeTask: { taskId: "task-old", tabId: removedId },
          runtimeSessions: { s1: { tabId: removedId }, s2: { tabId: 999 } },
        }, addedTab, removedId, now);
        assert.equal(state.registry[String(removedId)], undefined);
        assert.equal(state.registry[String(addedId)].tabId, addedId);
        assert.equal(state.affinityTasks.a.tabId, addedId);
        assert.equal(state.affinityTasks.a.reason, "tab_replaced");
        assert.equal(state.affinityTasks.b.tabId, 999);
        assert.equal(state.lastTabId, addedId);
        assert.equal(state.activeTask.tabId, addedId);
        assert.equal(state.runtimeSessions.s1.interruptedByTabReplacement, true);
        assert.equal(state.runtimeSessions.s2.tabId, 999);
      });
    }
  }
}

const evidenceHosts = ["example.com", "search.google.com", "trends.google.com", "idealmarket1987.com", "localhost:8080"];
const evidencePaths = ["/", "/alpha", "/a/b", "/wp-admin/", "/search?q=term"];
for (const host of evidenceHosts) {
  for (const p of evidencePaths) {
    const actual = `https://${host}${p}`;
    test(`URL evidence exact ${host}${p}`, () => {
      const result = compareUrlEvidence(actual, actual);
      assert.equal(result.matched, true);
      assert.equal(result.matchStrategy, "normalized_exact");
    });
    test(`URL evidence wildcard ${host}${p}`, () => {
      const result = compareUrlEvidence(actual, `https://${host}*`);
      assert.equal(result.matched, true);
      assert.equal(result.matchStrategy, "anchored_wildcard");
    });
    test(`URL evidence literal ${host}${p}`, () => {
      const expected = p === "/" ? host : p.split("?")[0];
      const result = compareUrlEvidence(actual, expected);
      assert.equal(result.matched, true);
      assert.equal(result.matchStrategy, "literal_substring");
    });
  }
}
const normalizationCases = [
  ["HTTPS://EXAMPLE.COM:443/a", "https://example.com/a"],
  ["http://EXAMPLE.COM:80/a", "http://example.com/a"],
  ["https://user:pass@example.com/a", "https://example.com/a"],
  ["https://Example.Com/a?x=1#z", "https://example.com/a?x=1#z"],
  ["not-a-url", "not-a-url"],
];
for (const [raw, expected] of normalizationCases) {
  test(`URL evidence normalization: ${raw}`, () => assert.equal(normalizeUrlForEvidence(raw), expected));
}
const safeRegexes = ["example", "^https://example\\.com", "path[0-9]", "a?b", "a{1}", "a{1,3}", "^[a-z]{1,8}$", "https://[^/]+/a"];
for (const pattern of safeRegexes) test(`safe URL regex: ${pattern}`, () => assert.equal(assessUserUrlRegex(pattern).safe, true));
const unsafeRegexes = ["", "(a)", "a|b", "(a|b)", "a+b+", "a*b*", "(.*)", "a{1,999}", "a{3,1}", "a{1,}", "a\\1", "[abc", "abc\\", "a{", "a{foo}"];
for (const pattern of unsafeRegexes) test(`unsafe URL regex: ${JSON.stringify(pattern)}`, () => assert.equal(assessUserUrlRegex(pattern).safe, false));
for (const [actual, pattern, expected] of [
  ["https://example.com/a", "https://*.com/*", true], ["https://example.com/a", "http://*.com/*", false],
  ["abcXYZdef", "abc*def", true], ["abcXYZ", "abc*def", false], ["prefix-middle-suffix", "prefix*middle*suffix", true],
  ["prefix-X-suffix", "prefix*middle*suffix", false], ["same", "same", true], ["same", "different", false],
]) test(`literal wildcard ${actual} vs ${pattern}`, () => assert.equal(matchLiteralWildcard(actual, pattern), expected));
for (const [actual, pattern, expected] of [
  ["https://example.com/a", "^https://example\\.com", true], ["https://example.com/a", "^http://example\\.com", false],
  ["abc123", "^[a-z]{1,8}[0-9]{1,3}$", true], ["123abc", "^[a-z]{1,8}[0-9]{1,3}$", false],
]) test(`URL regex execution ${actual} vs ${pattern}`, () => assert.equal(testUserUrlRegex(actual, pattern).matched, expected));

// CDP orchestration is verified here at the protocol boundary. A separate CI
// job loads the unpacked extension in Google Chrome and exercises debugger APIs.
test("CDP candidate list is production protocol 1.3 only", () => assert.deepEqual([...DEBUGGER_PROTOCOL_CANDIDATES], ["1.3"]));
for (let i = 1; i <= 20; i += 1) {
  test(`CDP incompatible attach error variant ${i}`, async () => {
    const message = `Protocol error variant ${i}`;
    await assert.rejects(
      () => attachWithProtocolFallback({ attach: async () => { throw new Error(message); } }, { tabId: 1000 + i }, async () => false),
      (error) => error?.code === "cdp_protocol_incompatible" && error?.details?.errors?.[0]?.message === message,
    );
  });
}
for (const [i, message] of [
  "Already attached", "already attached to this target", "Another debugger is already attached", "ANOTHER DEBUGGER IS ALREADY ATTACHED",
  "already ATTACHED", "Another debugger is already attached to the tab", "already attached: target", "Another debugger is already attached.",
].entries()) {
  test(`CDP existing attachment recovery ${i + 1}`, async () => {
    const result = await attachWithProtocolFallback({ attach: async () => { throw new Error(message); } }, { tabId: 2000 + i }, async () => true);
    assert.equal(result.ok, true);
    assert.equal(result.alreadyAttached, true);
    assert.equal(result.protocolVersion, "1.3");
  });
}
for (let i = 1; i <= 8; i += 1) {
  test(`CDP ambiguous state variant ${i}`, async () => {
    await assert.rejects(
      () => attachWithProtocolFallback({ attach: async () => { throw new Error(`permission-like-${i}`); } }, { tabId: 3000 + i }, async () => true),
      (error) => error?.code === "cdp_attach_state_ambiguous",
    );
  });
}
for (let i = 1; i <= 8; i += 1) {
  test(`CDP unverifiable state variant ${i}`, async () => {
    await assert.rejects(
      () => attachWithProtocolFallback({ attach: async () => { throw new Error(`attach-${i}`); } }, { tabId: 4000 + i }, async () => { throw new Error(`state-${i}`); }),
      (error) => error?.code === "cdp_attach_state_unverified" && error?.details?.stateError === `state-${i}`,
    );
  });
}
