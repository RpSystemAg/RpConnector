const MAX_SEQUENCE_EVENTS = 200;
const POINTER_SETTLE_MS = 16;

const MODIFIER_BITS = Object.freeze({
  alt: 1,
  ctrl: 2,
  control: 2,
  meta: 4,
  command: 4,
  cmd: 4,
  shift: 8,
});

const MOUSE_BUTTON_BITS = Object.freeze({
  left: 1,
  right: 2,
  middle: 4,
  back: 8,
  forward: 16,
});

const KEY_CODES = Object.freeze({
  Backspace: { code: "Backspace", windowsVirtualKeyCode: 8 },
  Tab: { code: "Tab", windowsVirtualKeyCode: 9 },
  Enter: { code: "Enter", windowsVirtualKeyCode: 13 },
  Escape: { code: "Escape", windowsVirtualKeyCode: 27 },
  Space: { code: "Space", key: " ", text: " ", windowsVirtualKeyCode: 32 },
  ArrowLeft: { code: "ArrowLeft", windowsVirtualKeyCode: 37 },
  ArrowUp: { code: "ArrowUp", windowsVirtualKeyCode: 38 },
  ArrowRight: { code: "ArrowRight", windowsVirtualKeyCode: 39 },
  ArrowDown: { code: "ArrowDown", windowsVirtualKeyCode: 40 },
  Delete: { code: "Delete", windowsVirtualKeyCode: 46 },
  Home: { code: "Home", windowsVirtualKeyCode: 36 },
  End: { code: "End", windowsVirtualKeyCode: 35 },
  PageUp: { code: "PageUp", windowsVirtualKeyCode: 33 },
  PageDown: { code: "PageDown", windowsVirtualKeyCode: 34 },
});

function finite(value, fallback = 0) {
  let candidate;
  if (typeof value === "number") candidate = value;
  else if (typeof value === "string" && value.trim() !== "") candidate = Number(value);
  else return fallback;
  return Number.isFinite(candidate) ? candidate : fallback;
}

function finiteField(record, key, fallback, errorCode) {
  if (!record || !Object.prototype.hasOwnProperty.call(record, key) || record[key] === undefined) return fallback;
  const candidate = finite(record[key], Number.NaN);
  if (!Number.isFinite(candidate)) throw new Error(errorCode);
  return candidate;
}

function boundedDelay(value) {
  return Math.max(0, Math.min(5000, finite(value, 0)));
}

function normalizedPointerType(value) {
  if (typeof value !== "string") return "";
  const token = value.trim().toLowerCase();
  if (/[-_\s]{2,}/.test(token)) return token;
  const compact = token.replace(/[-_\s]/g, "");
  return ({
    pointermove: "move", mousemove: "move",
    pointerdown: "down", mousedown: "down",
    pointerup: "up", mouseup: "up",
    mousewheel: "wheel", wheelscroll: "wheel",
    tap: "click", pointerclick: "click", mouseclick: "click",
    dblclick: "doubleclick", doubletap: "doubleclick", doubleclick: "doubleclick",
    touchstart: "touchstart", touchmove: "touchmove", touchend: "touchend",
  })[compact] || compact;
}

function normalizedMouseButton(value, fallback = "left") {
  const candidate = value === undefined ? fallback : (typeof value === "string" ? value.trim().toLowerCase() : "");
  if (!Object.prototype.hasOwnProperty.call(MOUSE_BUTTON_BITS, candidate)) throw new Error("pointer_event_invalid:button");
  return candidate;
}

function normalizedClickCount(value, fallback = 1) {
  if (value === undefined) return fallback;
  const candidate = finite(value, Number.NaN);
  if (!Number.isSafeInteger(candidate) || candidate < 1 || candidate > 3) throw new Error("pointer_event_invalid:click_count");
  return candidate;
}

function requiredDragPoint(value) {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw new Error("pointer_event_invalid:drag_point");
  const x = finiteField(value, "x", Number.NaN, "pointer_event_invalid:drag_point");
  const y = finiteField(value, "y", Number.NaN, "pointer_event_invalid:drag_point");
  if (!Number.isFinite(x) || !Number.isFinite(y)) throw new Error("pointer_event_invalid:drag_point");
  return { x, y };
}

function mouseParams(params = {}) {
  return { ...params, pointerType: "mouse" };
}

export function modifierMask(input = []) {
  const values = Array.isArray(input) ? input : (typeof input === "string" ? input.split(/[+\s,]+/) : []);
  return values.reduce((mask, name) => {
    if (typeof name !== "string") return mask;
    return mask | (MODIFIER_BITS[name.trim().toLowerCase()] || 0);
  }, 0);
}

export function parseKeyChord(chord = "") {
  if (typeof chord !== "string") {
    return { key: "", code: "", text: "", windowsVirtualKeyCode: 0, nativeVirtualKeyCode: 0, modifiers: 0 };
  }
  const literalSpace = chord === " ";
  const rawParts = literalSpace ? ["Space"] : chord.split("+");
  if (!literalSpace && rawParts.some((value) => value.trim() === "")) {
    return { key: "", code: "", text: "", windowsVirtualKeyCode: 0, nativeVirtualKeyCode: 0, modifiers: 0 };
  }
  const parts = rawParts.map((value) => value.trim());
  const key = parts.pop() || "";
  const modifiers = modifierMask(parts);
  const alias = ({ esc: "Escape", return: "Enter", space: "Space" })[key.toLowerCase()];
  const canonicalKey = alias || key;
  const known = KEY_CODES[canonicalKey] || {};
  const codePoints = [...canonicalKey];
  const printable = codePoints.length === 1;
  const asciiLetter = /^[A-Za-z]$/.test(canonicalKey);
  const asciiDigit = /^[0-9]$/.test(canonicalKey);
  const upper = asciiLetter ? canonicalKey.toUpperCase() : canonicalKey;
  const virtualKeyCode = known.windowsVirtualKeyCode ?? ((asciiLetter || asciiDigit) ? upper.charCodeAt(0) : 0);
  const code = known.code || (asciiLetter ? `Key${upper}` : asciiDigit ? `Digit${canonicalKey}` : "");
  return {
    key: known.key ?? canonicalKey,
    code,
    text: modifiers ? "" : (known.text ?? (printable ? canonicalKey : "")),
    windowsVirtualKeyCode: virtualKeyCode,
    nativeVirtualKeyCode: virtualKeyCode,
    modifiers,
  };
}

export function pointerSequence(events = []) {
  if (!Array.isArray(events) || !events.length) throw new Error("pointer_sequence_required");
  if (events.length > MAX_SEQUENCE_EVENTS) throw new Error("pointer_sequence_too_long");
  const commands = [];
  let current = { x: 0, y: 0, button: "none", buttons: 0 };
  for (const raw of events) {
    const event = raw && typeof raw === "object" && !Array.isArray(raw) ? raw : {};
    const type = normalizedPointerType(event.type);
    const x = finiteField(event, "x", current.x, "pointer_event_invalid:coordinate");
    const y = finiteField(event, "y", current.y, "pointer_event_invalid:coordinate");
    const modifiers = modifierMask(event.modifiers ?? []);
    const delayMs = boundedDelay(event.delayMs ?? event.delay_ms);
    if (type === "move" || type === "hover") {
      current = { ...current, x, y };
      commands.push({ method: "Input.dispatchMouseEvent", params: mouseParams({ type: "mouseMoved", x, y, button: "none", buttons: current.buttons, modifiers }), delayMs });
    } else if (type === "down") {
      if (current.buttons) throw new Error("pointer_event_invalid:button_state");
      const button = normalizedMouseButton(event.button);
      const buttons = MOUSE_BUTTON_BITS[button];
      const clickCount = normalizedClickCount(event.clickCount);
      current = { x, y, button, buttons };
      commands.push({ method: "Input.dispatchMouseEvent", params: mouseParams({ type: "mousePressed", x, y, button, buttons, clickCount, modifiers }), delayMs });
    } else if (type === "up") {
      const button = normalizedMouseButton(event.button, current.button !== "none" ? current.button : "left");
      if (current.buttons && button !== current.button) throw new Error("pointer_event_invalid:button_state");
      const clickCount = normalizedClickCount(event.clickCount);
      commands.push({ method: "Input.dispatchMouseEvent", params: mouseParams({ type: "mouseReleased", x, y, button, buttons: 0, clickCount, modifiers }), delayMs });
      current = { x, y, button: "none", buttons: 0 };
    } else if (type === "click" || type === "doubleclick") {
      if (current.buttons) throw new Error("pointer_event_invalid:button_state");
      const button = normalizedMouseButton(event.button);
      const clickCount = type === "click" ? normalizedClickCount(event.clickCount) : 2;
      const buttons = MOUSE_BUTTON_BITS[button];
      // Chrome's Input domain is asynchronous with compositor hit-testing. A
      // zero-gap move/press/release sequence can be acknowledged by CDP before
      // the pointer has been committed to the target surface, especially on a
      // newly created/background tab. Yield one frame between move and press,
      // and between press and release. This preserves one physical click while
      // making the browser engine, not a DOM fallback, authoritative.
      commands.push({ method: "Input.dispatchMouseEvent", params: mouseParams({ type: "mouseMoved", x, y, button: "none", buttons: 0, modifiers }), delayMs: POINTER_SETTLE_MS });
      commands.push({ method: "Input.dispatchMouseEvent", params: mouseParams({ type: "mousePressed", x, y, button, buttons, clickCount, modifiers }), delayMs: POINTER_SETTLE_MS });
      commands.push({ method: "Input.dispatchMouseEvent", params: mouseParams({ type: "mouseReleased", x, y, button, buttons: 0, clickCount, modifiers }), delayMs });
    } else if (type === "wheel" || type === "scroll") {
      const deltaXSource = event.deltaX ?? event.delta_x;
      const deltaYSource = event.deltaY ?? event.delta_y;
      const deltaX = deltaXSource === undefined ? 0 : finite(deltaXSource, Number.NaN);
      const deltaY = deltaYSource === undefined ? 0 : finite(deltaYSource, Number.NaN);
      if (!Number.isFinite(deltaX) || !Number.isFinite(deltaY)) throw new Error("pointer_event_invalid:wheel_delta");
      commands.push({ method: "Input.dispatchMouseEvent", params: mouseParams({ type: "mouseWheel", x, y, deltaX, deltaY, modifiers }), delayMs });
    } else if (["touchstart", "touchmove", "touchend"].includes(type)) {
      const cdpType = type === "touchstart" ? "touchStart" : type === "touchmove" ? "touchMove" : "touchEnd";
      const radiusX = finiteField(event, "radiusX", 1, "pointer_event_invalid:touch_radius");
      const radiusY = finiteField(event, "radiusY", 1, "pointer_event_invalid:touch_radius");
      const force = finiteField(event, "force", 1, "pointer_event_invalid:touch_force");
      const id = finiteField(event, "id", 0, "pointer_event_invalid:touch_id");
      if (radiusX < 0 || radiusY < 0) throw new Error("pointer_event_invalid:touch_radius");
      if (force < 0 || force > 1) throw new Error("pointer_event_invalid:touch_force");
      const touchPoints = cdpType === "touchEnd" ? [] : [{ x, y, radiusX, radiusY, force, id }];
      commands.push({ method: "Input.dispatchTouchEvent", params: { type: cdpType, touchPoints, modifiers }, delayMs });
    } else {
      throw new Error(`pointer_event_invalid:${type || "missing"}`);
    }
  }
  return commands;
}

export function dragSequence(from = {}, to = {}, options = {}) {
  const start = requiredDragPoint(from);
  const end = requiredDragPoint(to);
  const config = options && typeof options === "object" && !Array.isArray(options) ? options : {};
  const steps = Math.max(2, Math.min(60, Math.floor(finite(config.steps, 12))));
  const events = [{ type: "move", ...start }, { type: "down", ...start, button: config.button ?? "left" }];
  for (let index = 1; index <= steps; index += 1) {
    const ratio = index / steps;
    events.push({
      type: "move",
      x: start.x + (end.x - start.x) * ratio,
      y: start.y + (end.y - start.y) * ratio,
      delayMs: boundedDelay(config.stepDelayMs ?? 16),
    });
  }
  events.push({ type: "up", ...end, button: config.button ?? "left" });
  return pointerSequence(events);
}

export function keyboardSequence(events = []) {
  const rows = Array.isArray(events) ? events : [events];
  if (!rows.length) throw new Error("keyboard_sequence_required");
  if (rows.length > MAX_SEQUENCE_EVENTS) throw new Error("keyboard_sequence_too_long");
  const commands = [];
  for (const raw of rows) {
    const event = typeof raw === "string" ? { type: "press", key: raw }
      : (raw && typeof raw === "object" && !Array.isArray(raw) ? raw : {});
    if (event.type !== undefined && typeof event.type !== "string") throw new Error("keyboard_event_invalid:type");
    const type = event.type === undefined ? "press" : event.type.toLowerCase();
    const delayMs = boundedDelay(event.delayMs ?? event.delay_ms);
    if (type === "text" || type === "insert_text") {
      const textValue = event.text ?? event.value ?? "";
      if (typeof textValue !== "string") throw new Error("keyboard_event_invalid:text");
      if (textValue.length > 100_000) throw new Error("keyboard_text_too_long");
      commands.push({ method: "Input.insertText", params: { text: textValue }, delayMs });
      continue;
    }
    const key = parseKeyChord(event.key ?? event.chord ?? "");
    if (!key.key) throw new Error("keyboard_key_required");
    if (["down", "keydown", "rawkeydown"].includes(type)) {
      commands.push({ method: "Input.dispatchKeyEvent", params: { type: type === "rawkeydown" ? "rawKeyDown" : "keyDown", ...key }, delayMs });
    } else if (["up", "keyup"].includes(type)) {
      commands.push({ method: "Input.dispatchKeyEvent", params: { type: "keyUp", ...key, text: "" }, delayMs });
    } else if (type === "press") {
      commands.push({ method: "Input.dispatchKeyEvent", params: { type: "keyDown", ...key }, delayMs: 0 });
      commands.push({ method: "Input.dispatchKeyEvent", params: { type: "keyUp", ...key, text: "" }, delayMs });
    } else {
      throw new Error(`keyboard_event_invalid:${type || "missing"}`);
    }
  }
  return commands;
}

export const NATIVE_INPUT_LIMITS = Object.freeze({ maxSequenceEvents: MAX_SEQUENCE_EVENTS, pointerSettleMs: POINTER_SETTLE_MS });
