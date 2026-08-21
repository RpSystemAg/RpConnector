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

test("semantic ranking grounds Italian intent on an English control", () => {
  const rows = rankSemanticTargets([
    { targetRef: "wishlist", tag: "button", role: "button", accessibleName: "Add to wishlist", text: "Add to wishlist", clickable: true, focusable: true, inViewport: true, occluded: false, boundingBox: { width: 160, height: 36 } },
    { targetRef: "cart", tag: "button", role: "button", accessibleName: "Add to cart", text: "Add to cart", clickable: true, focusable: true, inViewport: true, occluded: false, boundingBox: { width: 160, height: 36 } },
  ], { name: "Aggiungi al carrello", intendedAction: "clicca" });
  assert.equal(rows[0]?.target?.targetRef, "cart");
  assert.ok(rows[0]?.semanticStrength >= 0.9);
});

test("semantic ranking grounds English intent on an Italian control", () => {
  const best = bestSemanticTarget([
    { targetRef: "cancel", tag: "button", role: "button", accessibleName: "Annulla", text: "Annulla", clickable: true, focusable: true, inViewport: true, occluded: false, boundingBox: { width: 100, height: 32 } },
    { targetRef: "save", tag: "button", role: "button", accessibleName: "Salva", text: "Salva", clickable: true, focusable: true, inViewport: true, occluded: false, boundingBox: { width: 100, height: 32 } },
  ], { role: "button", name: "Save", intendedAction: "click" });
  assert.equal(best?.target?.targetRef, "save");
});

test("Italian fill intent keeps editable controls above buttons", () => {
  const rows = rankSemanticTargets([
    { targetRef: "button", tag: "button", role: "button", accessibleName: "Nome", text: "Nome", clickable: true, focusable: true, inViewport: true, occluded: false, boundingBox: { width: 100, height: 30 } },
    { targetRef: "input", tag: "input", inputType: "text", role: "textbox", accessibleName: "Name", label: "Name", text: "", focusable: true, inViewport: true, occluded: false, boundingBox: { width: 220, height: 30 } },
  ], { name: "Nome", intendedAction: "compila" });
  assert.equal(rows[0]?.target?.targetRef, "input");
});

test("default navigation waits only for interactive DOM", () => {
  const steps = actionToSteps("playwright_goto", { tab_id: 17, url: "https://example.test/" });
  assert.equal(steps[0].type, "navigate");
  assert.equal(steps[0].waitUntil, "interactive");
});
