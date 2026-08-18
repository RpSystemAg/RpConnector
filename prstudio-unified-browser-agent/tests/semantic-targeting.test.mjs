import test from "node:test";
import assert from "node:assert/strict";
import { bestSemanticTarget, rankSemanticTargets } from "../lib/semantic-ranking.js";
import { actionToSteps } from "../lib/protocol.js";

const targets = [
  { targetRef: "bg", tag: "button", role: "button", accessibleName: "Save", text: "Save", clickable: true, focusable: true, inViewport: true, occluded: true, inDialog: false, boundingBox: { width: 100, height: 32 } },
  { targetRef: "modal", tag: "button", role: "button", accessibleName: "Save", text: "Save", clickable: true, focusable: true, inViewport: true, occluded: false, inDialog: true, boundingBox: { width: 100, height: 32 } },
  { targetRef: "cancel", tag: "button", role: "button", accessibleName: "Cancel", text: "Cancel", clickable: true, focusable: true, inViewport: true, occluded: false, inDialog: true, boundingBox: { width: 100, height: 32 } },
];

test("semantic ranking prefers the actionable visible target inside the active dialog", () => {
  const best = bestSemanticTarget(targets, { role: "button", name: "Save", intendedAction: "click" });
  assert.equal(best?.target?.targetRef, "modal");
  assert.ok(best.score > 400);
});

test("semantic ranking uses action compatibility to choose an editable control", () => {
  const rows = rankSemanticTargets([
    { targetRef: "button", tag: "button", role: "button", accessibleName: "Email", text: "Email", clickable: true, focusable: true, inViewport: true, occluded: false, boundingBox: { width: 100, height: 30 } },
    { targetRef: "input", tag: "input", inputType: "email", role: "textbox", accessibleName: "Email", label: "Email", text: "", focusable: true, inViewport: true, occluded: false, boundingBox: { width: 220, height: 30 } },
  ], { name: "Email", intendedAction: "fill" });
  assert.equal(rows[0]?.target?.targetRef, "input");
});

test("default navigation waits only for interactive DOM", () => {
  const steps = actionToSteps("playwright_goto", { tab_id: 17, url: "https://example.test/" });
  assert.equal(steps[0].type, "navigate");
  assert.equal(steps[0].waitUntil, "interactive");
});
