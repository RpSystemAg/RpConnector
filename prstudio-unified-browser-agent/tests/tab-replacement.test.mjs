import assert from "node:assert/strict";
import test from "node:test";
import { migrateTabReplacementRecord } from "../lib/tab-ownership.js";

test("tab replacement migrates canonical ownership to the new tab id", () => {
  const old = {
    tabId: 41,
    windowId: 7,
    taskId: "task-a",
    laneId: "lane-a",
    expectedOrigin: "https://shop.example",
    url: "https://shop.example/checkout",
    title: "Checkout",
    owner: "prstudio-agent",
    ownershipNonce: "nonce-a",
    createdAt: 100,
    updatedAt: 200,
    reservationExpiresAt: 999,
  };
  const next = migrateTabReplacementRecord(old, {
    id: 52,
    windowId: 7,
    url: "https://shop.example/checkout",
    pendingUrl: "",
    title: "Checkout ready",
  }, 41);
  assert.equal(next.tabId, 52);
  assert.equal(next.windowId, 7);
  assert.equal(next.taskId, "task-a");
  assert.equal(next.laneId, "lane-a");
  assert.equal(next.ownershipNonce, "nonce-a");
  assert.equal(next.createdAt, 100);
  assert.equal(next.reservationExpiresAt, 999);
  assert.equal(next.url, "https://shop.example/checkout");
  assert.equal(next.title, "Checkout ready");
  assert.equal(next.provisional, false);
  assert.ok(next.updatedAt >= 200);
});

test("tab replacement keeps pending-url ownership provisional", () => {
  const next = migrateTabReplacementRecord({
    tabId: 41,
    windowId: 7,
    taskId: "task-a",
    expectedOrigin: "https://shop.example",
    url: "https://shop.example/pay",
    provisional: true,
    owner: "prstudio-agent",
    ownershipNonce: "nonce-a",
    createdAt: 100,
  }, {
    id: 52,
    windowId: 7,
    url: "about:blank",
    pendingUrl: "https://shop.example/pay",
    title: "",
  }, 41);
  assert.equal(next.tabId, 52);
  assert.equal(next.url, "https://shop.example/pay");
  assert.equal(next.provisional, true);
});

test("tab replacement rejects stale or malformed inputs", () => {
  assert.equal(migrateTabReplacementRecord(null, { id: 52 }, 41), null);
  assert.equal(migrateTabReplacementRecord({ tabId: 40 }, { id: 52 }, 41), null);
  assert.equal(migrateTabReplacementRecord({ tabId: 41 }, { id: 0 }, 41), null);
});
