import assert from "node:assert/strict";
import test from "node:test";

import { selectOwnedTabCandidate, urlAffinityScore } from "../lib/resilience.js";

test("same controller remains selectable after a cross-origin navigation", () => {
  const tabs = [
    {
      tabId: 101,
      controllerSessionId: "chat-a",
      laneId: "chat-a",
      taskId: "task-open",
      url: "https://trends.google.com/trends/",
      updatedAt: 10_000,
    },
  ];
  const chosen = selectOwnedTabCandidate(tabs, {
    controllerSessionId: "chat-a",
    taskId: "task-next",
    expectedOrigin: "https://search.google.com",
    expectedUrl: "https://search.google.com/search-console",
  });
  assert.equal(chosen.tabId, 101);
  assert.equal(chosen.reason, "controller_affinity");
  assert.ok(Number.isFinite(chosen.score));
});

test("another controller is excluded even when its URL is an exact match", () => {
  const tabs = [
    {
      tabId: 201,
      controllerSessionId: "chat-b",
      laneId: "chat-b",
      url: "https://search.google.com/search-console",
      updatedAt: 20_000,
    },
  ];
  assert.equal(urlAffinityScore(tabs[0], {
    controllerSessionId: "chat-a",
    expectedUrl: "https://search.google.com/search-console",
  }), Number.NEGATIVE_INFINITY);
  const chosen = selectOwnedTabCandidate(tabs, {
    controllerSessionId: "chat-a",
    expectedUrl: "https://search.google.com/search-console",
  });
  assert.equal(chosen.tabId, null);
  assert.equal(chosen.reason, "no_match");
});

test("controller identity outranks a foreign exact URL", () => {
  const tabs = [
    {
      tabId: 301,
      controllerSessionId: "chat-a",
      laneId: "chat-a",
      url: "https://trends.google.com/trends/",
      updatedAt: 30_000,
    },
    {
      tabId: 302,
      controllerSessionId: "chat-b",
      laneId: "chat-b",
      url: "https://search.google.com/search-console",
      updatedAt: 40_000,
    },
  ];
  const chosen = selectOwnedTabCandidate(tabs, {
    controllerSessionId: "chat-a",
    expectedOrigin: "https://search.google.com",
    expectedUrl: "https://search.google.com/search-console",
  });
  assert.equal(chosen.tabId, 301);
});

test("legacy unscoped selection stays conservative on origin mismatch", () => {
  const tabs = [
    { tabId: 401, url: "https://trends.google.com/trends/", updatedAt: 50_000 },
  ];
  const chosen = selectOwnedTabCandidate(tabs, {
    expectedOrigin: "https://search.google.com",
  });
  assert.equal(chosen.tabId, null);
  assert.equal(chosen.reason, "no_match");
});
