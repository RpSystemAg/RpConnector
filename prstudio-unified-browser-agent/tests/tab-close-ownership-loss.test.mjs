import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
const worker = await readFile(new URL("../service-worker.js", import.meta.url), "utf8");
test("active controlled-tab close terminalizes as technical error", () => {
  const block=worker.slice(worker.indexOf("chrome.tabs.onRemoved"), worker.indexOf("chrome.windows.onRemoved"));
  assert.match(block, /CONTROLLED_TAB_CLOSED/);
  assert.match(block, /\/fail/);
  assert.doesNotMatch(block, /enterHumanTakeover|resumeActive|approval_required|needs_review/);
});
test("tab close never creates a parked cleanup queue", () => {
  assert.doesNotMatch(worker, /PendingTakeovers|TakeoverCleanup|enqueueTakeoverCleanup|reconcileParked/);
});
