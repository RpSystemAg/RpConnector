import assert from "node:assert/strict";
import test from "node:test";

import {
  BROWSER_CONTROL_KERNEL_VERSION,
  controllerGroupTitle,
  controllerIsolationInvariant,
  kernelCertificationSnapshot,
  normalizeControllerSessionId,
  reconcileControlState,
  sitePermissionDecision,
} from "../lib/browser-control-kernel.js";

function group(id, controller, windowId = 1) {
  return { id, windowId, title: controllerGroupTitle(controller), color: "green", collapsed: false };
}

function tab(id, groupId, url, extra = {}) {
  return {
    id,
    groupId,
    windowId: extra.windowId || 1,
    url,
    pendingUrl: "",
    title: extra.title || `tab-${id}`,
    ...extra,
  };
}

test("Browser Agent 2.0 kernel has an explicit certification version", () => {
  assert.equal(BROWSER_CONTROL_KERNEL_VERSION, "2.0.0");
  assert.equal(normalizeControllerSessionId({ laneId: "legacy-lane" }, {}), "legacy-lane");
  assert.equal(normalizeControllerSessionId({}, { controller_session_id: "controller-new" }), "controller-new");
});

test("1000 open -> cross-origin navigate -> close cycles produce zero ownership failures", () => {
  const controller = "controller-stress-a";
  for (let cycle = 0; cycle < 1000; cycle += 1) {
    const tabId = cycle + 1000;
    const groupId = cycle + 2000;
    let state = reconcileControlState({
      registry: {},
      groups: [group(groupId, controller)],
      tabs: [tab(tabId, groupId, `https://origin-a.example/${cycle}`)],
      debuggerTargets: [{ tabId, attached: true }],
      now: cycle * 3 + 1,
    });
    assert.ok(state.registry[String(tabId)], `cycle ${cycle}: open claim missing`);
    assert.equal(state.registry[String(tabId)].controllerSessionId, controller);
    assert.equal(state.registry[String(tabId)].debuggerAttached, true);

    state = reconcileControlState({
      registry: state.registry,
      groups: [group(groupId, controller)],
      tabs: [tab(tabId, groupId, `https://origin-b.example/${cycle}`)],
      debuggerTargets: [{ tabId, attached: true }],
      now: cycle * 3 + 2,
    });
    assert.ok(state.registry[String(tabId)], `cycle ${cycle}: cross-origin ownership lost`);
    assert.equal(state.registry[String(tabId)].controllerSessionId, controller);
    assert.equal(state.registry[String(tabId)].expectedOrigin, "");
    assert.equal(state.released.length, 0);

    state = reconcileControlState({
      registry: state.registry,
      groups: [],
      tabs: [],
      debuggerTargets: [],
      now: cycle * 3 + 3,
    });
    assert.equal(Object.keys(state.registry).length, 0, `cycle ${cycle}: closed tab leaked`);
  }
});

test("100 cross-origin transitions never change controller ownership", () => {
  const controller = "controller-cross-origin";
  const groupId = 77;
  const tabId = 88;
  const origins = [
    "https://idealmarket1987.com/",
    "https://search.google.com/search-console",
    "https://trends.google.com/trends/",
    "https://answerthepublic.com/",
  ];
  let registry = {};
  for (let i = 0; i < 100; i += 1) {
    const url = origins[i % origins.length];
    const state = reconcileControlState({
      registry,
      groups: [group(groupId, controller)],
      tabs: [tab(tabId, groupId, url)],
      debuggerTargets: [{ tabId, attached: i % 3 !== 0 }],
      now: i + 1,
    });
    registry = state.registry;
    assert.equal(registry[String(tabId)].controllerSessionId, controller);
    assert.equal(registry[String(tabId)].url, url);
    assert.equal(registry[String(tabId)].expectedOrigin, "");
    assert.equal(state.released.length, 0);
  }
});

test("MV3 restart reconstructs ownership from Chrome with an empty registry", () => {
  const controller = "controller-restart";
  const groupId = 901;
  const tabId = 902;
  const recovered = reconcileControlState({
    registry: {},
    groups: [group(groupId, controller)],
    tabs: [tab(tabId, groupId, "https://example.com/dashboard")],
    debuggerTargets: [{ tabId, attached: true }],
    now: 5000,
  });
  assert.equal(recovered.adoptedFromGroups, 1);
  assert.equal(recovered.registry[String(tabId)].controllerSessionId, controller);
  assert.equal(recovered.registry[String(tabId)].controlGroupId, groupId);
  assert.equal(recovered.registry[String(tabId)].debuggerAttached, true);
  assert.match(recovered.registry[String(tabId)].ownershipNonce, /^chrome-group:/);
});

test("three controllers and ten simultaneous tabs remain completely isolated", () => {
  const controllers = ["chat-a", "chat-b", "chat-c"];
  const groups = controllers.map((controller, index) => group(300 + index, controller));
  const tabs = [];
  for (let i = 0; i < 10; i += 1) {
    const controllerIndex = i % controllers.length;
    tabs.push(tab(400 + i, groups[controllerIndex].id, `https://example${i}.com/`));
  }
  const state = reconcileControlState({ registry: {}, groups, tabs, now: 6000 });
  assert.equal(Object.keys(state.registry).length, 10);
  for (let i = 0; i < 10; i += 1) {
    assert.equal(state.registry[String(400 + i)].controllerSessionId, controllers[i % controllers.length]);
  }
  const isolation = controllerIsolationInvariant(state.registry);
  assert.equal(isolation.ok, true);
  assert.equal(isolation.controlledTabs, 10);
});

test("popup inherits opener controller without adopting unrelated user tabs", () => {
  const controller = "controller-popup";
  const groupId = 710;
  const openerId = 711;
  const popupId = 712;
  const userId = 713;
  const state = reconcileControlState({
    registry: {},
    groups: [group(groupId, controller)],
    tabs: [
      tab(openerId, groupId, "https://shop.example/product"),
      tab(popupId, -1, "https://pay.example/checkout", { openerTabId: openerId }),
      tab(userId, -1, "https://private.example/mail"),
    ],
    now: 7000,
  });
  assert.equal(state.registry[String(openerId)].controllerSessionId, controller);
  assert.equal(state.registry[String(popupId)].controllerSessionId, controller);
  assert.equal(state.registry[String(popupId)].popupInherited, true);
  assert.equal(state.registry[String(userId)], undefined);
  assert.equal(state.inheritedPopups, 1);
});

test("dragging a kernel-managed tab out releases it; dragging a tab into the group adopts it", () => {
  const controller = "controller-drag";
  const groupId = 801;
  const tabId = 802;
  let state = reconcileControlState({
    registry: {},
    groups: [group(groupId, controller)],
    tabs: [tab(tabId, groupId, "https://example.com/")],
    now: 8000,
  });
  assert.ok(state.registry[String(tabId)]);

  state = reconcileControlState({
    registry: state.registry,
    groups: [group(groupId, controller)],
    tabs: [tab(tabId, -1, "https://example.com/")],
    now: 8001,
  });
  assert.equal(state.registry[String(tabId)], undefined);
  assert.equal(state.released[0].reason, "dragged_out_of_control_group");

  state = reconcileControlState({
    registry: {},
    groups: [group(groupId, controller)],
    tabs: [tab(tabId, groupId, "https://example.com/")],
    now: 8002,
  });
  assert.equal(state.registry[String(tabId)].controllerSessionId, controller);
  assert.equal(state.adoptedFromGroups, 1);
});

test("origin policy is separate from ownership", () => {
  const allow = sitePermissionDecision({
    requestedUrl: "https://trends.google.com/trends/",
    allowedOrigins: ["https://trends.google.com"],
  });
  assert.equal(allow.decision, "allow");
  const deny = sitePermissionDecision({
    requestedUrl: "https://evil.example/",
    allowedOrigins: ["https://trends.google.com"],
  });
  assert.equal(deny.decision, "deny");

  const state = reconcileControlState({
    registry: {},
    groups: [group(900, "controller-policy")],
    tabs: [tab(901, 900, "https://evil.example/")],
    now: 9000,
  });
  assert.equal(state.registry["901"].controllerSessionId, "controller-policy");
  assert.equal(state.registry["901"].expectedOrigin, "");
});

test("certification snapshot reports Chrome-derived control evidence", () => {
  const snapshot = kernelCertificationSnapshot({
    registry: {},
    groups: [group(1001, "controller-cert")],
    tabs: [tab(1002, 1001, "https://example.com/")],
    debuggerTargets: [{ tabId: 1002, attached: true }],
  });
  assert.deepEqual(snapshot, {
    kernelVersion: "2.0.0",
    controlledTabs: 1,
    controlledGroups: 1,
    debuggerAttachedTabs: 1,
    isolationOk: true,
    released: 0,
    adoptedFromGroups: 1,
    inheritedPopups: 0,
  });
});
