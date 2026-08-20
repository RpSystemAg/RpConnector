/**
 * The agent works in the same Chrome as the operator, in its own group.
 *
 * WHY THIS EXISTS
 * ---------------
 * Reported while watching the extension work, and it describes the intended
 * model exactly: the reference tooling attaches to the same Chrome, but keeps
 * its tabs in a dedicated group and works in the background. The operator works
 * in their tabs, the agent works in its own, one browser, nobody blocked.
 *
 * This extension attached to the same Chrome -- correct, and the whole point of
 * driving a real logged-in browser -- and then opened its tabs loose in the
 * operator's window, interleaved with theirs. It had neither the tabGroups
 * permission nor any grouping code, so there was no logic to repair; the
 * capability was absent.
 *
 * WHAT IS ASSERTED
 * ----------------
 * The two decisions that have to be right, because getting either wrong is
 * worse than not grouping at all:
 *
 *   Reuse. A stored group id outlives a service-worker restart but not the
 *   operator closing its last tab, and it can never be used from another
 *   window. Both send us back to creating one, and neither is an error.
 *
 *   Scope. Only tabs the agent owns are ever filed. Moving an operator's tab
 *   into the agent's group is precisely the interference this prevents.
 */

import { test } from "node:test";
import assert from "node:assert/strict";

import {
  AGENT_GROUP_TITLE,
  AGENT_GROUP_COLOR,
  isReusableGroup,
  groupableTabIds,
} from "../lib/agent-tab-group.js";

test("a live group in the same window is reused", () => {
  assert.equal(isReusableGroup(7, { id: 7, windowId: 3 }, 3), true);
});

test("a group the operator closed is not reused", () => {
  // chrome.tabGroups.get rejects for a group whose last tab is gone; the caller
  // turns that into null. Creating a fresh group is the correct response, not
  // an error to report.
  assert.equal(isReusableGroup(7, null, 3), false);
  assert.equal(isReusableGroup(7, undefined, 3), false);
});

test("a group in another window is not reused", () => {
  // A tab can only join a group in its own window. Trying anyway would throw on
  // every single tab creation in a second window.
  assert.equal(isReusableGroup(7, { id: 7, windowId: 99 }, 3), false);
});

test("a mismatched or absent id is not reused", () => {
  assert.equal(isReusableGroup(7, { id: 8, windowId: 3 }, 3), false);
  assert.equal(isReusableGroup(0, { id: 0, windowId: 3 }, 3), false);
  assert.equal(isReusableGroup(null, { id: 7, windowId: 3 }, 3), false);
  // chrome.tabGroups.TAB_GROUP_ID_NONE is -1 and means "not in a group".
  assert.equal(isReusableGroup(-1, { id: -1, windowId: 3 }, 3), false);
});

test("only owned tabs in the target window are filed", () => {
  const owned = [
    { tabId: 11, windowId: 3 },
    { tabId: 12, windowId: 3 },
    { tabId: 13, windowId: 4 },
  ];
  assert.deepEqual(groupableTabIds(owned, 3), [11, 12]);
  assert.deepEqual(groupableTabIds(owned, 4), [13]);
});

test("duplicates and malformed records cannot produce a bad group call", () => {
  const owned = [
    { tabId: 11, windowId: 3 },
    { tabId: 11, windowId: 3 },
    { tabId: 0, windowId: 3 },
    { windowId: 3 },
    null,
    "not a record",
    { tabId: "12", windowId: 3 },
  ];
  assert.deepEqual(groupableTabIds(owned, 3), [11, 12]);
});

test("nothing to file is an empty list, not a throw", () => {
  assert.deepEqual(groupableTabIds([], 3), []);
  assert.deepEqual(groupableTabIds(null, 3), []);
  assert.deepEqual(groupableTabIds(undefined, undefined), []);
});

test("the group is identifiable to the person sharing the browser", () => {
  // The operator has to be able to tell at a glance whose tabs these are, or
  // the group is just a container.
  assert.match(AGENT_GROUP_TITLE, /PR STUDIO/);
  // Chrome only accepts a fixed set of colour names; an invalid one makes
  // tabGroups.update reject and the group stays unlabelled.
  assert.ok(
    ["grey", "blue", "red", "yellow", "green", "pink", "purple", "cyan", "orange"].includes(AGENT_GROUP_COLOR),
    `${AGENT_GROUP_COLOR} is not a colour Chrome accepts`,
  );
});
