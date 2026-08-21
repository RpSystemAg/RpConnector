/**
 * Show where the agent is acting.
 *
 * WHY
 * ---
 * The Browser Agent drives pages with CDP Input.dispatchMouseEvent. That is real
 * native input -- the right primitive, and better than a synthetic DOM .click()
 * -- but Chrome does not paint a pointer for it. Nothing moves on screen. From
 * the operator's chair an agent that is working correctly and an agent that is
 * doing nothing at all look identical, which is the single most demoralising
 * property this suite can have and the one a person notices first.
 *
 * It also costs real diagnostic information. "It clicked the wrong thing" and
 * "it never clicked" are different defects with different fixes, and without a
 * visible pointer neither can be told from the other by watching.
 *
 * WHAT THIS IS
 * ------------
 * A pointer drawn into the page, following the same coordinates the CDP event
 * is dispatched at, so what you see is literally where the input went. It is
 * decoration over the truth, not a reconstruction of it: the position comes
 * from the dispatch itself.
 *
 * CONSTRAINTS THAT SHAPED IT
 * --------------------------
 * It must never change the outcome of an action. `pointer-events: none` means
 * it can never intercept a click, and it is appended to documentElement rather
 * than body so a page that rewrites body keeps it. Under LAW 1 nothing here can
 * stop a mutation; it cannot even observe one.
 *
 * It must be removable before a capture. Perception screenshots and visual
 * baselines compare pixels, and an overlay burned into them would be a
 * self-inflicted diff -- so `hide()` exists and the capture path calls it.
 *
 * It carries no page data anywhere. It only draws.
 */

/** Element id, deliberately unlikely to collide with a real page. */
export const CURSOR_ELEMENT_ID = "prstudio-agent-cursor-9f3a";

/**
 * Paint the agent pointer inside the page.
 *
 * Passed to chrome.scripting.executeScript as `func`, so it is serialised and
 * runs in the page with no access to anything in this module. Everything it
 * needs arrives through arguments; that is why the element id is a parameter
 * rather than the exported constant.
 *
 * @param {number} x Viewport x, the same coordinate the CDP event uses.
 * @param {number} y Viewport y.
 * @param {"move"|"click"|"hide"|"remove"} mode What to draw.
 * @param {string} elementId Overlay element id.
 * @returns {boolean}
 */
export function agentCursorPainter(x, y, mode, elementId) {
  const root = document.documentElement;
  if (!root) return false;
  let node = document.getElementById(elementId);

  if (mode === "hide") {
    if (node) node.style.opacity = "0";
    return true;
  }
  if (mode === "remove") {
    if (node && node.parentNode) node.parentNode.removeChild(node);
    return true;
  }

  if (!node) {
    node = document.createElement("div");
    node.id = elementId;
    node.setAttribute("aria-hidden", "true");
    // pointer-events:none is the load-bearing line: the overlay must never be
    // able to receive the click it is drawing.
    node.style.cssText = [
      "position:fixed",
      "left:0",
      "top:0",
      "width:22px",
      "height:22px",
      "margin:-11px 0 0 -11px",
      "border-radius:50%",
      "pointer-events:none",
      "z-index:2147483647",
      "background:rgba(23,107,50,0.28)",
      "border:2px solid rgba(23,107,50,0.95)",
      "box-shadow:0 0 0 1px rgba(255,255,255,0.85)",
      "transition:transform 90ms linear,opacity 120ms linear",
      "will-change:transform",
    ].join(";");
    root.appendChild(node);
  }

  node.style.opacity = "1";
  const translate = "translate(" + Number(x) + "px," + Number(y) + "px)";
  node.style.transform = translate + (mode === "click" ? " scale(0.6)" : " scale(1)");

  if (mode === "click") {
    // The pulse is what makes a click distinguishable from a hover when
    // watching. A timer rather than a keyframe animation keeps this
    // dependency-free and leaves nothing running afterwards.
    setTimeout(function () {
      const later = document.getElementById(elementId);
      if (later) later.style.transform = translate + " scale(1)";
    }, 140);
  }
  return true;
}

/**
 * Whether a point is worth drawing.
 *
 * Guards the overlay against the coordinates that mean "unknown": NaN from a
 * failed box-model resolve, and the (0,0) that a default-initialised pointer
 * state produces. Drawing those would put a cursor in the top-left corner
 * during every action that did not resolve a target, which is worse than
 * drawing nothing because it looks like a real click.
 *
 * @param {unknown} x Horizontal viewport coordinate.
 * @param {unknown} y Vertical viewport coordinate.
 * @returns {boolean}
 */
export function isDrawablePoint(x, y) {
  const px = Number(x);
  const py = Number(y);
  if (!Number.isFinite(px) || !Number.isFinite(py)) return false;
  if (px < 0 || py < 0) return false;
  if (px === 0 && py === 0) return false;
  return true;
}

/**
 * The overlay mode a CDP input event should produce.
 *
 * mousePressed is the moment worth pulsing: mouseReleased at the same point
 * would pulse twice for one click, and mouseMoved is a track, not an action.
 *
 * @param {string} cdpType CDP Input event type.
 * @returns {"move"|"click"|null} null when the event should not draw at all.
 */
export function cursorModeForEvent(cdpType) {
  switch (String(cdpType || "")) {
    case "mousePressed":
      return "click";
    case "mouseMoved":
    case "mouseWheel":
      return "move";
    default:
      return null;
  }
}
