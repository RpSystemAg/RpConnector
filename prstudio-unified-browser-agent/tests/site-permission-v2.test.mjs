import assert from 'node:assert/strict';
import test from 'node:test';
import { controllerGroupTitle, reconcileControlState, sitePermissionDecision } from '../lib/browser-control-kernel.js';

const request = 'https://new-origin.example/path';
const allowedOrigins = ['https://known.example'];

test('unknown origin policy supports allow ask and deny explicitly', () => {
  const allow = sitePermissionDecision({ requestedUrl: request, allowedOrigins, unknownPolicy: 'allow' });
  const ask = sitePermissionDecision({ requestedUrl: request, allowedOrigins, unknownPolicy: 'ask' });
  const deny = sitePermissionDecision({ requestedUrl: request, allowedOrigins, unknownPolicy: 'deny' });
  assert.equal(allow.decision, 'allow');
  assert.equal(allow.reason, 'unknown_origin_allowed_by_policy');
  assert.equal(ask.decision, 'ask');
  assert.equal(ask.reason, 'unknown_origin_requires_handoff');
  assert.equal(deny.decision, 'deny');
  assert.equal(deny.reason, 'not_in_allowlist');
});

test('origin permission decision never changes controller ownership', () => {
  const group = { id: 41, windowId: 1, title: controllerGroupTitle('chat-permission'), color: 'green' };
  const tab = { id: 42, windowId: 1, groupId: 41, url: request, title: 'new origin' };
  const baseline = reconcileControlState({ registry: {}, groups: [group], tabs: [tab], now: 1 });
  const record = baseline.registry['42'];
  assert.equal(record.controllerSessionId, 'chat-permission');
  for (const unknownPolicy of ['allow', 'ask', 'deny']) {
    const decision = sitePermissionDecision({ requestedUrl: request, allowedOrigins, unknownPolicy });
    assert.ok(['allow', 'ask', 'deny'].includes(decision.decision));
    const after = reconcileControlState({ registry: baseline.registry, groups: [group], tabs: [tab], now: 2 });
    assert.equal(after.registry['42'].controllerSessionId, 'chat-permission');
    assert.equal(after.registry['42'].controlGroupId, 41);
    assert.equal(after.released.length, 0);
  }
});
