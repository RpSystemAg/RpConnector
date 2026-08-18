import test from 'node:test';
import assert from 'node:assert/strict';
import * as nativeInput from '../lib/native-input.js';

test('numeric normalization is total and modifier inputs do not coerce objects into privileges', () => {
  assert.throws(
    () => nativeInput.pointerSequence([{ type: 'move', x: Symbol('x'), y: 2 }]),
    /pointer_event_invalid:coordinate/
  );
  const delayed = nativeInput.keyboardSequence([{ type: 'text', text: 'x', delayMs: Symbol('delay') }]);
  assert.equal(delayed[0].delayMs, 0);
  assert.equal(nativeInput.modifierMask({ toString: () => 'ctrl' }), 0);
  assert.equal(nativeInput.modifierMask(['CTRL', 'shift', 'unknown', 'ctrl']), 10);
});

test('parseKeyChord canonicalizes space/digits conservatively and rejects non-string chords', () => {
  assert.deepEqual(nativeInput.parseKeyChord(' '), {
    key: ' ', code: 'Space', text: ' ', windowsVirtualKeyCode: 32, nativeVirtualKeyCode: 32, modifiers: 0,
  });
  const digit = nativeInput.parseKeyChord('5');
  assert.equal(digit.key, '5');
  assert.equal(digit.code, 'Digit5');
  assert.equal(digit.text, '5');
  assert.equal(digit.windowsVirtualKeyCode, 53);
  const punctuation = nativeInput.parseKeyChord('?');
  assert.equal(punctuation.key, '?');
  assert.equal(punctuation.code, '');
  assert.equal(punctuation.windowsVirtualKeyCode, 0);
  assert.equal(nativeInput.parseKeyChord(Symbol('bad')).key, '');
  assert.equal(nativeInput.parseKeyChord('Control++A').key, '');
});

test('pointerSequence emits only valid button masks and returns a technical failure on malformed pointer fields', () => {
  const middle = nativeInput.pointerSequence([{ type: 'click', x: 1, y: 2, button: 'middle' }]);
  assert.equal(middle[1].params.button, 'middle');
  assert.equal(middle[1].params.buttons, 4);
  const back = nativeInput.pointerSequence([{ type: 'down', x: 1, y: 2, button: 'back' }]);
  assert.equal(back[0].params.buttons, 8);
  assert.throws(() => nativeInput.pointerSequence([{ type: 'click', x: 1, y: 2, button: 'primary' }]), /pointer_event_invalid:button/);
  assert.throws(() => nativeInput.pointerSequence([{ type: 'click', x: 1, y: 2, clickCount: 1.5 }]), /pointer_event_invalid:click_count/);
  assert.throws(() => nativeInput.pointerSequence([{ type: 'click', x: NaN, y: 2 }]), /pointer_event_invalid:coordinate/);
  assert.throws(() => nativeInput.pointerSequence([{ type: 'mouse---move', x: 1, y: 2 }]), /pointer_event_invalid/);
  const precedence = nativeInput.pointerSequence([{ type: 'wheel', x: 1, y: 2, deltaX: 7, delta_x: Symbol('ignored') }]);
  assert.equal(precedence[0].params.deltaX, 7);
});

test('dragSequence validates endpoints before construction and stays total for malformed step counts', () => {
  assert.throws(() => nativeInput.dragSequence(null, { x: 10, y: 10 }), /pointer_event_invalid:drag_point/);
  const commands = nativeInput.dragSequence({ x: 0, y: 0 }, { x: 12, y: 6 }, { steps: Symbol('bad'), stepDelayMs: 0 });
  assert.equal(commands.length, 15);
  assert.deepEqual(commands[0].params.x, 0);
  assert.deepEqual(commands.at(-1).params.x, 12);
  assert.deepEqual(commands.at(-1).params.y, 6);
});

test('keyboardSequence rejects malformed key/text values before any CDP command can be dispatched', () => {
  assert.throws(() => nativeInput.keyboardSequence([{ type: 'press', key: Symbol('bad') }]), /keyboard_key_required/);
  assert.throws(() => nativeInput.keyboardSequence([{ type: 'text', text: Symbol('bad') }]), /keyboard_event_invalid:text/);
  assert.throws(() => nativeInput.keyboardSequence([{ type: '', key: 'A' }]), /keyboard_event_invalid/);
  assert.equal(nativeInput.keyboardSequence([{ type: 'text', text: '' }])[0].params.text, '');
  assert.doesNotThrow(() => nativeInput.keyboardSequence([{ type: 'text', text: 'x'.repeat(100_000) }]));
  assert.throws(() => nativeInput.keyboardSequence([{ type: 'text', text: 'x'.repeat(100_001) }]), /keyboard_text_too_long/);
});
