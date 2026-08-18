import test from 'node:test';
import assert from 'node:assert/strict';
import { DEBUGGER_PROTOCOL_CANDIDATES, attachWithProtocolFallback } from '../lib/cdp-protocol.js';

test('candidate order is stable and first-choice success preserves the happy path', async () => {
  assert.deepEqual([...DEBUGGER_PROTOCOL_CANDIDATES], ['1.3', '0.1']);
  assert.equal(Object.isFrozen(DEBUGGER_PROTOCOL_CANDIDATES), true);
  const calls = [];
  const target = Object.freeze({ tabId: 42 });
  const result = await attachWithProtocolFallback({ attach: async (actualTarget, version) => {
    calls.push(version); assert.equal(actualTarget, target);
  } }, target, async () => { throw new Error('state probe should not run on success'); });
  assert.deepEqual(calls, ['1.3']);
  assert.deepEqual(result, { ok: true, protocolVersion: '1.3', fallbackUsed: false, errors: [] });
});

test('verified pre-side-effect failure still takes the bounded protocol fallback', async () => {
  const calls = [];
  let attached = false;
  const result = await attachWithProtocolFallback({ attach: async (_target, version) => {
    calls.push(version);
    if (version === '1.3') throw new Error('requested version rejected');
    attached = true;
  } }, { tabId: 7 }, async () => attached);
  assert.deepEqual(calls, ['1.3', '0.1']);
  assert.equal(result.ok, true);
  assert.equal(result.protocolVersion, '0.1');
  assert.equal(result.fallbackUsed, true);
  assert.deepEqual(result.errors, [{ protocolVersion: '1.3', message: 'requested version rejected' }]);
});

test('ambiguous post-attach state is verified before replay and does not attempt a second attach', async () => {
  const calls = [];
  let attached = false;
  await assert.rejects(
    () => attachWithProtocolFallback({ attach: async (_target, version) => {
      calls.push(version); attached = true; throw new Error('injected ambiguous attach failure');
    } }, { tabId: 9 }, async () => attached),
    (error) => error?.code === 'cdp_attach_state_ambiguous' && error?.details?.protocolVersion === '1.3',
  );
  assert.deepEqual(calls, ['1.3']);
});

test('known already-attached race remains a positive recovered success without replay', async () => {
  const calls = [];
  const result = await attachWithProtocolFallback({ attach: async (_target, version) => {
    calls.push(version); throw new Error('Another debugger is already attached');
  } }, { tabId: 11 }, async () => true);
  assert.deepEqual(calls, ['1.3']);
  assert.equal(result.ok, true);
  assert.equal(result.alreadyAttached, true);
  assert.equal(result.protocolVersion, '1.3');
});

test('failed state verification prevents blind replay', async () => {
  const calls = [];
  await assert.rejects(
    () => attachWithProtocolFallback({ attach: async (_target, version) => {
      calls.push(version); throw new Error('attach failed');
    } }, { tabId: 12 }, async () => { throw new Error('getTargets unavailable'); }),
    (error) => error?.code === 'cdp_attach_state_unverified' && error?.details?.protocolVersion === '1.3',
  );
  assert.deepEqual(calls, ['1.3']);
});

test('two verified unattached failures retain bounded incompatible taxonomy and error truncation', async () => {
  const calls = [];
  await assert.rejects(
    () => attachWithProtocolFallback({ attach: async (_target, version) => {
      calls.push(version); throw new Error(version === '1.3' ? 'x'.repeat(400) : 'fallback rejected');
    } }, { tabId: 13 }, async () => false),
    (error) => {
      assert.equal(error?.code, 'cdp_protocol_incompatible');
      assert.deepEqual(error?.details?.candidates, ['1.3', '0.1']);
      assert.equal(error?.details?.errors?.length, 2);
      assert.equal(error?.details?.errors?.[0]?.message?.length, 240);
      return true;
    },
  );
  assert.deepEqual(calls, ['1.3', '0.1']);
});

function rng(seed) {
  let x = seed >>> 0;
  return () => { x ^= x << 13; x ^= x >>> 17; x ^= x << 5; return (x >>> 0) / 0x100000000; };
}

test('64 deterministic attach-state cases preserve bounded replay and autonomy invariants', async () => {
  const random = rng(1376263);
  for (let i = 0; i < 64; i++) {
    const firstKind = Math.floor(random() * 4);
    const secondSuccess = random() < 0.5;
    const probeThrows = random() < 0.2;
    const target = Object.freeze({ tabId: i + 1, marker: `case-${i}` });
    const before = JSON.stringify(target);
    const calls = [];
    let attached = false;
    let probes = 0;
    const api = { attach: async (actualTarget, version) => {
      assert.equal(actualTarget, target); calls.push(version);
      if (calls.length === 1) {
        if (firstKind === 0) { attached = true; return; }
        if (firstKind === 1) { attached = false; throw new Error('version rejected'); }
        attached = true;
        if (firstKind === 3) throw new Error('Another debugger is already attached');
        throw new Error('ambiguous attach failure');
      }
      if (secondSuccess) { attached = true; return; }
      attached = false; throw new Error('fallback rejected');
    } };
    const probe = async () => { probes++; if (probeThrows) throw new Error('probe unavailable'); return attached; };
    let result = null, caught = null;
    try { result = await attachWithProtocolFallback(api, target, probe); } catch (error) { caught = error; }
    assert.equal(JSON.stringify(target), before);
    assert.ok(calls.length >= 1 && calls.length <= 2);
    assert.equal(calls[0], '1.3'); if (calls.length === 2) assert.equal(calls[1], '0.1');
    if (firstKind === 0) { assert.deepEqual(calls, ['1.3']); assert.equal(probes, 0); assert.equal(result?.ok, true); continue; }
    if (probeThrows) { assert.deepEqual(calls, ['1.3']); assert.equal(caught?.code, 'cdp_attach_state_unverified'); continue; }
    if (firstKind === 2) { assert.deepEqual(calls, ['1.3']); assert.equal(caught?.code, 'cdp_attach_state_ambiguous'); continue; }
    if (firstKind === 3) { assert.deepEqual(calls, ['1.3']); assert.equal(result?.alreadyAttached, true); continue; }
    assert.equal(calls.length, 2);
    if (secondSuccess) assert.equal(result?.protocolVersion, '0.1'); else assert.equal(caught?.code, 'cdp_protocol_incompatible');
  }
});
