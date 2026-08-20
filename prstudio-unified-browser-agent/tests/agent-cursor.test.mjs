/**
 * The agent pointer: visible where the input went, invisible everywhere else.
 *
 * WHY THIS EXISTS
 * ---------------
 * Reported from a real session, watching the extension work: "there is no
 * cursor and it does not seem to record the screen the way you do". That is
 * accurate. The Browser Agent drives pages with CDP Input.dispatchMouseEvent,
 * which is real native input, but Chrome paints no pointer for it. An agent
 * clicking the right element and an agent doing nothing at all look identical.
 *
 * It is not only morale. "It clicked the wrong thing" and "it never clicked"
 * are different defects with different fixes, and nothing on screen separated
 * them.
 *
 * WHAT IS ASSERTED
 * ----------------
 * The properties that make the overlay safe to draw over a live mutation:
 *
 *   - it can never receive the click it is drawing (pointer-events: none)
 *   - it draws only at coordinates that mean something, never at the (0,0) a
 *     default-initialised pointer state produces
 *   - a press pulses and a move does not, so a click is distinguishable from a
 *     hover when watching
 *   - it can be hidden before a capture, so it never becomes a pixel diff in a
 *     visual baseline this suite compares against itself
 *
 * The painter is exercised against a small DOM stub rather than a real page,
 * because what matters here is what it writes, not that Chrome can render it.
 */

import { test } from "node:test";
import assert from "node:assert/strict";

import {
  agentCursorPainter,
  isDrawablePoint,
  cursorModeForEvent,
  CURSOR_ELEMENT_ID,
} from "../lib/agent-cursor.js";

/** Minimal DOM the painter needs: getElementById, createElement, appendChild. */
function domStub() {
  const nodes = new Map();
  const root = { children: [], appendChild(node) { this.children.push(node); nodes.set(node.id, node); } };
  const timers = [];
  const doc = {
    documentElement: root,
    getElementById: (id) => nodes.get(id) || null,
    createElement: () => ({
      id: "",
      style: { cssText: "", opacity: "", transform: "" },
      attributes: {},
      setAttribute(key, value) { this.attributes[key] = value; },
      parentNode: null,
    }),
  };
  return {
    doc,
    root,
    node: () => nodes.get(CURSOR_ELEMENT_ID) || null,
    runTimers: () => { while (timers.length) timers.shift()(); },
    install() {
      globalThis.document = doc;
      globalThis.setTimeout = (fn) => { timers.push(fn); return 0; };
    },
    restore() {
      delete globalThis.document;
    },
  };
}

function withDom(run) {
  const realTimeout = globalThis.setTimeout;
  const stub = domStub();
  stub.install();
  try {
    return run(stub);
  } finally {
    stub.restore();
    globalThis.setTimeout = realTimeout;
  }
}

test("the overlay can never receive the click it is drawing", () => {
  withDom((dom) => {
    agentCursorPainter(120, 240, "click", CURSOR_ELEMENT_ID);
    const node = dom.node();
    assert.ok(node, "the pointer should have been created");
    assert.match(node.style.cssText, /pointer-events:none/);
    // Above every plausible page layer, so it is actually seen.
    assert.match(node.style.cssText, /z-index:2147483647/);
    // Hidden from assistive technology: it is decoration, not content.
    assert.equal(node.attributes["aria-hidden"], "true");
  });
});

test("it draws at the coordinate the input event carried", () => {
  withDom((dom) => {
    agentCursorPainter(413, 87, "move", CURSOR_ELEMENT_ID);
    assert.match(dom.node().style.transform, /translate\(413px,87px\)/);
  });
});

test("a press pulses, a move does not", () => {
  withDom((dom) => {
    agentCursorPainter(50, 60, "click", CURSOR_ELEMENT_ID);
    assert.match(dom.node().style.transform, /scale\(0\.6\)/, "a click should compress the pointer");
    dom.runTimers();
    assert.match(dom.node().style.transform, /scale\(1\)/, "and then return to size");
  });
  withDom((dom) => {
    agentCursorPainter(50, 60, "move", CURSOR_ELEMENT_ID);
    assert.match(dom.node().style.transform, /scale\(1\)/);
    assert.doesNotMatch(dom.node().style.transform, /scale\(0\.6\)/);
  });
});

test("it is reused rather than stacked on every event", () => {
  withDom((dom) => {
    agentCursorPainter(10, 10, "move", CURSOR_ELEMENT_ID);
    agentCursorPainter(20, 20, "move", CURSOR_ELEMENT_ID);
    agentCursorPainter(30, 30, "click", CURSOR_ELEMENT_ID);
    assert.equal(dom.root.children.length, 1, "one pointer, not one per event");
    assert.match(dom.node().style.transform, /translate\(30px,30px\)/);
  });
});

test("hide leaves the node in place but invisible, so a capture is clean", () => {
  withDom((dom) => {
    agentCursorPainter(10, 10, "click", CURSOR_ELEMENT_ID);
    agentCursorPainter(0, 0, "hide", CURSOR_ELEMENT_ID);
    assert.equal(dom.node().style.opacity, "0");
    assert.equal(dom.root.children.length, 1, "hiding must not destroy and rebuild it");
  });
});

test("painting without a document does not throw", () => {
  // A frame that navigated out from under the injection has no
  // documentElement. The overlay is decoration over a real action and must
  // never turn a successful click into a failed step.
  const realDocument = globalThis.document;
  globalThis.document = { documentElement: null, getElementById: () => null };
  try {
    assert.equal(agentCursorPainter(1, 1, "click", CURSOR_ELEMENT_ID), false);
  } finally {
    if (realDocument === undefined) delete globalThis.document;
    else globalThis.document = realDocument;
  }
});

test("coordinates that mean nothing are not drawn", () => {
  // (0,0) is what a default-initialised pointer state holds before a target is
  // resolved. Drawing it would put a pointer in the corner during every action
  // that failed to locate anything, which reads as a real click.
  assert.equal(isDrawablePoint(0, 0), false);
  assert.equal(isDrawablePoint(NaN, 10), false);
  assert.equal(isDrawablePoint(10, NaN), false);
  assert.equal(isDrawablePoint(-1, 10), false);
  assert.equal(isDrawablePoint(undefined, undefined), false);
  assert.equal(isDrawablePoint("", ""), false);

  assert.equal(isDrawablePoint(1, 1), true);
  assert.equal(isDrawablePoint(0, 5), true, "an edge coordinate is still a real coordinate");
  assert.equal(isDrawablePoint("413", "87"), true, "CDP params arrive as numbers but strings must not break it");
});

test("only the events worth drawing produce a mode", () => {
  assert.equal(cursorModeForEvent("mousePressed"), "click");
  assert.equal(cursorModeForEvent("mouseMoved"), "move");
  assert.equal(cursorModeForEvent("mouseWheel"), "move");
  // mouseReleased lands on the same point as the press; drawing it would pulse
  // twice for one click.
  assert.equal(cursorModeForEvent("mouseReleased"), null);
  assert.equal(cursorModeForEvent("keyDown"), null);
  assert.equal(cursorModeForEvent(""), null);
  assert.equal(cursorModeForEvent(undefined), null);
});
