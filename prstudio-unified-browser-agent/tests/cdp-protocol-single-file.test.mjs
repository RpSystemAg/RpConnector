import test from 'node:test';
import assert from 'node:assert/strict';
import { DEBUGGER_PROTOCOL_CANDIDATES, attachWithProtocolFallback } from '../lib/cdp-protocol.js';

test('chrome.debugger uses the documented extension protocol 0.1 only', async () => {
  assert.deepEqual([...DEBUGGER_PROTOCOL_CANDIDATES], ['0.1']);
  assert.equal(Object.isFrozen(DEBUGGER_PROTOCOL_CANDIDATES), true);
  const calls = [];
  const target = Object.freeze({ tabId: 42 });
  const result = await attachWithProtocolFallback({ attach: async (actualTarget, version) => {
    calls.push(version);
    assert.equal(actualTarget, target);
  } }, target, async () => { throw new Error('state probe should not run on success'); });
  assert.deepEqual(calls, ['0.1']);
  assert.deepEqual(result, { ok: true, protocolVersion: '0.1', fallbackUsed: false, errors: [] });
});

test('known already-attached race remains a recovered success without replay', async () => {
  const calls = [];
  const result = await attachWithProtocolFallback({ attach: async (_target, version) => {
    calls.push(version);
    throw new Error('Another debugger is already attached');
  } }, { tabId: 11 }, async () => true);
  assert.deepEqual(calls, ['0.1']);
  assert.equal(result.ok, true);
  assert.equal(result.alreadyAttached, true);
  assert.equal(result.protocolVersion, '0.1');
});

test('ambiguous post-attach state does not replay attach', async () => {
  const calls = [];
  let attached = false;
  await assert.rejects(
    () => attachWithProtocolFallback({ attach: async (_target, version) => {
      calls.push(version);
      attached = true;
      throw new Error('injected ambiguous attach failure');
    } }, { tabId: 9 }, async () => attached),
    (error) => error?.code === 'cdp_attach_state_ambiguous' && error?.details?.protocolVersion === '0.1',
  );
  assert.deepEqual(calls, ['0.1']);
});

test('failed state verification remains fail closed', async () => {
  const calls = [];
  await assert.rejects(
    () => attachWithProtocolFallback({ attach: async (_target, version) => {
      calls.push(version);
      throw new Error('attach failed');
    } }, { tabId: 12 }, async () => { throw new Error('getTargets unavailable'); }),
    (error) => error?.code === 'cdp_attach_state_unverified' && error?.details?.protocolVersion === '0.1',
  );
  assert.deepEqual(calls, ['0.1']);
});

test('verified unattached failure reports the single supported candidate', async () => {
  const calls = [];
  await assert.rejects(
    () => attachWithProtocolFallback({ attach: async (_target, version) => {
      calls.push(version);
      throw new Error('x'.repeat(400));
    } }, { tabId: 13 }, async () => false),
    (error) => {
      assert.equal(error?.code, 'cdp_protocol_incompatible');
      assert.deepEqual(error?.details?.candidates, ['0.1']);
      assert.equal(error?.details?.errors?.length, 1);
      assert.equal(error?.details?.errors?.[0]?.message?.length, 240);
      return true;
    },
  );
  assert.deepEqual(calls, ['0.1']);
});

function rng(seed) {
  let x = seed >>> 0;
  return () => { x ^= x << 13; x ^= x >>> 17; x ^= x << 5; return (x >>> 0) / 0x100000000; };
}

test('64 deterministic attach-state cases never negotiate an invalid protocol', async () => {
  const random = rng(1376263);
  for (let i = 0; i < 64; i++) {
    const kind = Math.floor(random() * 4);
    const probeThrows = random() < 0.2;
    const target = Object.freeze({ tabId: i + 1, marker: `case-${i}` });
    const before = JSON.stringify(target);
    const calls = [];
    let attached = false;
    let probes = 0;
    const api = { attach: async (actualTarget, version) => {
      assert.equal(actualTarget, target);
      calls.push(version);
      assert.equal(version, '0.1');
      if (kind === 0) { attached = true; return; }
      if (kind === 1) { attached = false; throw new Error('version rejected'); }
      attached = true;
      if (kind === 3) throw new Error('Another debugger is already attached');
      throw new Error('ambiguous attach failure');
    } };
    const probe = async () => { probes++; if (probeThrows) throw new Error('probe unavailable'); return attached; };
    let result = null, caught = null;
    try { result = await attachWithProtocolFallback(api, target, probe); } catch (error) { caught = error; }
    assert.equal(JSON.stringify(target), before);
    assert.deepEqual(calls, ['0.1']);
    if (kind === 0) { assert.equal(probes, 0); assert.equal(result?.ok, true); continue; }
    if (probeThrows) { assert.equal(caught?.code, 'cdp_attach_state_unverified'); continue; }
    if (kind === 2) { assert.equal(caught?.code, 'cdp_attach_state_ambiguous'); continue; }
    if (kind === 3) { assert.equal(result?.alreadyAttached, true); continue; }
    assert.equal(caught?.code, 'cdp_protocol_incompatible');
  }
});
