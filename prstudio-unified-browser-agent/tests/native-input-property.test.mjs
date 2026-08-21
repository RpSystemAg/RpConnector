import test from 'node:test';
import assert from 'node:assert/strict';
import * as nativeInput from '../lib/native-input.js';
import * as remote from '../lib/remote-recovery.js';

const SEED = 1376263;
const CASES = 80;
const mouseTypes = new Set(['mousePressed', 'mouseReleased', 'mouseMoved', 'mouseWheel']);
const mouseButtons = new Set(['none', 'left', 'middle', 'right', 'back', 'forward']);
const keyTypes = new Set(['keyDown', 'keyUp', 'rawKeyDown', 'char']);
const touchTypes = new Set(['touchStart', 'touchEnd', 'touchMove', 'touchCancel']);
const methods = new Set(['Input.dispatchMouseEvent', 'Input.dispatchTouchEvent', 'Input.dispatchKeyEvent', 'Input.insertText']);

function rng(seed) {
  let state = seed >>> 0;
  return () => {
    state += 0x6D2B79F5;
    let t = state;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const random = rng(SEED);
const pick = (rows) => rows[Math.floor(random() * rows.length)];

function deepFreeze(value, seen = new Set()) {
  if (!value || (typeof value !== 'object' && typeof value !== 'function') || seen.has(value)) return value;
  seen.add(value);
  for (const key of Reflect.ownKeys(value)) deepFreeze(value[key], seen);
  return Object.freeze(value);
}

function outcome(fn) {
  try { return { ok: true, value: fn() }; }
  catch (error) { return { ok: false, error: String(error?.message || error) }; }
}

function assertFiniteNumbers(value) {
  if (typeof value === 'number') assert.equal(Number.isFinite(value), true, `non-finite number ${value}`);
  if (!value || typeof value !== 'object') return;
  for (const child of Object.values(value)) assertFiniteNumbers(child);
}

function validateCommands(commands) {
  assert.ok(Array.isArray(commands));
  for (const command of commands) {
    assert.ok(methods.has(command.method), command.method);
    assert.ok(Number.isFinite(command.delayMs));
    assert.ok(command.delayMs >= 0 && command.delayMs <= 5000);
    assertFiniteNumbers(command.params);
    if (command.method === 'Input.dispatchMouseEvent') {
      assert.ok(mouseTypes.has(command.params.type));
      if ('button' in command.params) assert.ok(mouseButtons.has(command.params.button));
      if ('buttons' in command.params) assert.ok(Number.isInteger(command.params.buttons) && [0,1,2,4,8,16].includes(command.params.buttons));
      if ('clickCount' in command.params) assert.ok(Number.isSafeInteger(command.params.clickCount) && command.params.clickCount >= 1 && command.params.clickCount <= 3);
    } else if (command.method === 'Input.dispatchTouchEvent') {
      assert.ok(touchTypes.has(command.params.type));
      assert.ok(Array.isArray(command.params.touchPoints));
      assert.equal(command.params.type === 'touchEnd' ? command.params.touchPoints.length === 0 : command.params.touchPoints.length >= 1, true);
      for (const point of command.params.touchPoints) {
        assert.ok(point.radiusX >= 0 && point.radiusY >= 0);
        assert.ok(point.force >= 0 && point.force <= 1);
      }
    } else if (command.method === 'Input.dispatchKeyEvent') {
      assert.ok(keyTypes.has(command.params.type));
      assert.ok(Number.isInteger(command.params.modifiers) && command.params.modifiers >= 0 && command.params.modifiers <= 15);
      assert.equal(typeof command.params.key, 'string');
      assert.equal(typeof command.params.code, 'string');
      assert.equal(typeof command.params.text, 'string');
      assert.ok(Number.isSafeInteger(command.params.windowsVirtualKeyCode));
      assert.ok(Number.isSafeInteger(command.params.nativeVirtualKeyCode));
    } else {
      assert.equal(typeof command.params.text, 'string');
      assert.ok(command.params.text.length <= 100_000);
    }
  }
}

const weirdNumbers = [undefined, null, 0, -1, 0.5, 1, 2, 60, 61, 5000, 5001, '0', ' 5 ', '', ' ', NaN, Infinity, -Infinity, Number.MAX_SAFE_INTEGER, Number.MAX_SAFE_INTEGER + 1, 1e300, Symbol('n'), 1n, true, false, [], [5], {}, { valueOf: () => 5 }];
const pointerTypes = ['move', 'pointerMove', 'mouse_move', 'mouse---move', 'down', 'up', 'click', 'double_click', 'wheel', 'scroll', 'touch_start', 'touchmove', 'touchend', 'teleport', '', Symbol('type'), 1];
const buttons = [undefined, 'left', 'RIGHT', 'middle', 'back', 'forward', 'primary', '', null, Symbol('button'), 1];
const modifiers = [[], ['ctrl'], ['CTRL', 'shift'], ['meta', 'alt'], ['unknown'], 'ctrl+shift', 'cmd,alt', {}, Symbol('mods')];
const keyboardTypes = [undefined, 'press', 'PRESS', 'down', 'keydown', 'rawkeydown', 'up', 'keyup', 'text', 'insert_text', '', 'unknown', Symbol('type'), 1];
const keys = ['a', 'A', '5', '?', ' ', 'Esc', 'Return', 'Space', 'Control+A', 'Meta+A', 'Control++A', '😊', 'e\u0301', '', Symbol('key'), 1, 1n, null];
const texts = ['', 'hello', '😊', 'e\u0301', '\n', '\t', Symbol('text'), 1, 1n, null, { toString: () => 'ctrl' }];

function pointerCase() {
  const event = deepFreeze({
    type: pick(pointerTypes), x: pick(weirdNumbers), y: pick(weirdNumbers), button: pick(buttons),
    clickCount: pick(weirdNumbers), deltaX: pick(weirdNumbers), delta_x: pick(weirdNumbers), deltaY: pick(weirdNumbers),
    radiusX: pick(weirdNumbers), radiusY: pick(weirdNumbers), force: pick(weirdNumbers), id: pick(weirdNumbers),
    modifiers: pick(modifiers), delayMs: pick(weirdNumbers), delay_ms: pick(weirdNumbers),
  });
  const first = outcome(() => nativeInput.pointerSequence([event]));
  const second = outcome(() => nativeInput.pointerSequence([event]));
  assert.deepEqual(first, second);
  if (first.ok) validateCommands(first.value);
  else assert.match(first.error, /^(pointer_event_invalid:|pointer_sequence_)/);
}

function keyboardCase() {
  const type = pick(keyboardTypes);
  const event = deepFreeze({ type, key: pick(keys), chord: pick(keys), text: pick(texts), value: pick(texts), modifiers: pick(modifiers), delayMs: pick(weirdNumbers), delay_ms: pick(weirdNumbers) });
  const first = outcome(() => nativeInput.keyboardSequence([event]));
  const second = outcome(() => nativeInput.keyboardSequence([event]));
  assert.deepEqual(first, second);
  if (first.ok) validateCommands(first.value);
  else assert.match(first.error, /^(keyboard_event_invalid:|keyboard_key_required$|keyboard_text_too_long$|keyboard_sequence_)/);
}

function chordCase() {
  const chord = pick(keys);
  const first = outcome(() => nativeInput.parseKeyChord(chord));
  const second = outcome(() => nativeInput.parseKeyChord(chord));
  assert.deepEqual(first, second);
  assert.equal(first.ok, true);
  assert.equal(typeof first.value.key, 'string');
  assert.equal(typeof first.value.code, 'string');
  assert.equal(typeof first.value.text, 'string');
  assert.ok(Number.isSafeInteger(first.value.modifiers));
  const input = pick(modifiers);
  const mask = nativeInput.modifierMask(input);
  assert.ok(Number.isInteger(mask) && mask >= 0 && mask <= 15);
}

function dragCase() {
  const from = deepFreeze({ x: pick(weirdNumbers), y: pick(weirdNumbers) });
  const to = deepFreeze({ x: pick(weirdNumbers), y: pick(weirdNumbers) });
  const options = deepFreeze({ steps: pick(weirdNumbers), stepDelayMs: pick(weirdNumbers), button: pick(buttons) });
  const first = outcome(() => nativeInput.dragSequence(from, to, options));
  const second = outcome(() => nativeInput.dragSequence(from, to, options));
  assert.deepEqual(first, second);
  if (first.ok) {
    validateCommands(first.value);
    assert.ok(first.value.length <= 63);
  } else assert.match(first.error, /^pointer_event_invalid:/);
}

test('native-input deterministic bounded property/fuzz', () => {
  for (let index = 0; index < CASES; index += 1) {
    [pointerCase, keyboardCase, chordCase, dragCase][index % 4]();
  }

  assert.equal(nativeInput.NATIVE_INPUT_LIMITS.maxSequenceEvents, 200);
  assert.throws(() => nativeInput.pointerSequence([]), /pointer_sequence_required/);
  assert.equal(nativeInput.pointerSequence(Array.from({ length: 200 }, () => ({ type: 'move', x: 1, y: 2 }))).length, 200);
  assert.throws(() => nativeInput.pointerSequence(Array.from({ length: 201 }, () => ({ type: 'move' }))), /pointer_sequence_too_long/);
  assert.throws(() => nativeInput.keyboardSequence([]), /keyboard_sequence_required/);
  assert.equal(nativeInput.keyboardSequence(Array.from({ length: 200 }, () => ({ type: 'text', text: '' }))).length, 200);
  assert.throws(() => nativeInput.keyboardSequence(Array.from({ length: 201 }, () => ({ type: 'text', text: '' }))), /keyboard_sequence_too_long/);

  const delayPrecedence = nativeInput.keyboardSequence([{ type: 'text', text: 'x', delayMs: 0, delay_ms: 5000 }]);
  assert.equal(delayPrecedence[0].delayMs, 0);
  const snakeDelay = nativeInput.keyboardSequence([{ type: 'text', text: 'x', delay_ms: 999999 }]);
  assert.equal(snakeDelay[0].delayMs, 5000);

  assert.doesNotThrow(() => nativeInput.keyboardSequence([{ type: 'text', text: 'x'.repeat(100_000) }]));
  let tooLong;
  try { nativeInput.keyboardSequence([{ type: 'text', text: 'x'.repeat(100_001) }]); } catch (error) { tooLong = error; }
  assert.match(String(tooLong?.message || ''), /keyboard_text_too_long/);
  assert.equal(remote.isPreDispatchInputError(tooLong), false);

  let pointerError;
  let keyboardError;
  try { nativeInput.pointerSequence([{ type: 'click', x: NaN, y: 0 }]); } catch (error) { pointerError = error; }
  try { nativeInput.keyboardSequence([{ type: '', key: 'A' }]); } catch (error) { keyboardError = error; }
  assert.match(String(pointerError?.message || ''), /pointer_event_invalid/);
  assert.match(String(keyboardError?.message || ''), /keyboard_event_invalid/);
  assert.equal(remote.isPreDispatchInputError(pointerError), true);
  assert.equal(remote.isPreDispatchInputError(keyboardError), true);

  const drag = nativeInput.dragSequence({ x: -10.5, y: 2.25 }, { x: 50.5, y: -4.75 }, { steps: 60, stepDelayMs: 5001 });
  assert.equal(drag.length, 63);
  assert.equal(drag[0].params.x, -10.5);
  assert.equal(drag.at(-1).params.x, 50.5);
  assert.equal(drag.at(-1).params.y, -4.75);
  assert.ok(drag.every((command) => command.delayMs >= 0 && command.delayMs <= 5000));
});
