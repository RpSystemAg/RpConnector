#!/usr/bin/env python3
"""Deterministic black-box fuzzing for the real MCP HTTP endpoint.

Requires RP_MCP_URL and RP_MCP_TOKEN. Secrets are never printed. The invariant
is fail-closed: malformed/bounded attacker-controlled input may be rejected but
must never yield 5xx, hang beyond the request deadline, or bypass the modern
protocol envelope.
"""
from __future__ import annotations

import json
import os
import random
import sys
import time
import urllib.error
import urllib.request

URL = os.environ.get('RP_MCP_URL', '').strip()
TOKEN = os.environ.get('RP_MCP_TOKEN', '').strip()
if not URL or not TOKEN:
    print('FAIL RP_MCP_URL and RP_MCP_TOKEN are required', file=sys.stderr)
    sys.exit(2)

ALLOWED = {200, 204, 400, 401, 403, 404, 405, 409, 413, 429}
MAX_SECONDS = 5.0


def request(raw: bytes, method_header: str = 'ping', name_header: str = '') -> tuple[int, bytes, float]:
    headers = {
        'Authorization': f'Bearer {TOKEN}',
        'Content-Type': 'application/json',
        'MCP-Protocol-Version': '2026-07-28',
        'Mcp-Method': method_header,
    }
    if name_header:
        headers['Mcp-Name'] = name_header
    req = urllib.request.Request(URL, data=raw, headers=headers, method='POST')
    started = time.monotonic()
    try:
        with urllib.request.urlopen(req, timeout=MAX_SECONDS) as response:
            return response.status, response.read(2_000_000), time.monotonic() - started
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read(2_000_000), time.monotonic() - started


def meta() -> dict:
    return {
        'io.modelcontextprotocol/protocolVersion': '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities': {},
        'io.modelcontextprotocol/clientInfo': {'name': 'rpconnector-fuzz', 'version': '1.0'},
    }

cases: list[tuple[str, bytes, str, str]] = [
    ('malformed-json', b'{', 'ping', ''),
    ('empty-object', b'{}', 'ping', ''),
    ('missing-meta', json.dumps({'jsonrpc': '2.0', 'id': 1, 'method': 'ping', 'params': {}}).encode(), 'ping', ''),
    ('method-header-mismatch', json.dumps({'jsonrpc': '2.0', 'id': 2, 'method': 'ping', 'params': {'_meta': meta()}}).encode(), 'tools/list', ''),
    ('batch-forbidden', json.dumps([{'jsonrpc': '2.0', 'id': 3, 'method': 'ping', 'params': {'_meta': meta()}}]).encode(), 'ping', ''),
    ('unknown-method', json.dumps({'jsonrpc': '2.0', 'id': 4, 'method': 'no/such/method', 'params': {'_meta': meta()}}).encode(), 'no/such/method', ''),
    ('tools-call-missing-name-header', json.dumps({'jsonrpc': '2.0', 'id': 5, 'method': 'tools/call', 'params': {'_meta': meta(), 'name': 'prstudio_health', 'arguments': {}}}).encode(), 'tools/call', ''),
    ('oversized-body', b'{' + b'"x":"' + (b'a' * 1_048_700) + b'"}', 'ping', ''),
]

rng = random.Random(20260818)
scalar_pool = [None, True, False, 0, -1, 1.5, '', 'x', 'A' * 1024]
for i in range(120):
    shape = rng.randrange(6)
    if shape == 0:
        payload = rng.choice(scalar_pool)
    elif shape == 1:
        payload = {'jsonrpc': rng.choice(['2.0', '1.0', '', None]), 'id': i, 'method': rng.choice(['ping', '', None, 'tools/list']), 'params': rng.choice([{}, [], None, {'_meta': meta()}])}
    elif shape == 2:
        payload = {'jsonrpc': '2.0', 'id': i, 'method': 'ping', 'params': {'_meta': {'io.modelcontextprotocol/protocolVersion': rng.choice(['2026-07-28', 'bad', '']), 'io.modelcontextprotocol/clientCapabilities': rng.choice([{}, [], None])}}}
    elif shape == 3:
        payload = {'jsonrpc': '2.0', 'id': i, 'method': 'tools/list', 'params': {'_meta': meta(), 'junk': {str(n): rng.choice(scalar_pool) for n in range(20)}}}
    elif shape == 4:
        payload = [{'jsonrpc': '2.0', 'id': i, 'method': 'ping', 'params': {'_meta': meta()}} for _ in range(rng.randint(1, 30))]
    else:
        payload = {'deep': {'a': {'b': {'c': {'d': {'e': rng.choice(scalar_pool)}}}}}}
    raw = json.dumps(payload).encode('utf-8')
    expected_method = payload.get('method') if isinstance(payload, dict) and isinstance(payload.get('method'), str) and payload.get('method') else 'ping'
    cases.append((f'random-{i:03d}', raw, expected_method, ''))

failures: list[str] = []
max_latency = 0.0
for name, raw, method_header, name_header in cases:
    try:
        status, body, elapsed = request(raw, method_header, name_header)
    except Exception as exc:
        failures.append(f'{name}: transport exception {type(exc).__name__}: {exc}')
        continue
    max_latency = max(max_latency, elapsed)
    if elapsed > MAX_SECONDS:
        failures.append(f'{name}: elapsed={elapsed:.3f}s exceeds {MAX_SECONDS}s')
    if status >= 500 or status not in ALLOWED:
        failures.append(f'{name}: unexpected HTTP {status}, body_prefix={body[:160]!r}')
    if len(body) >= 2_000_000:
        failures.append(f'{name}: response exceeded 2MB fuzz safety bound')

print(f'MCP HTTP FUZZ: cases={len(cases)} failures={len(failures)} max_latency={max_latency:.3f}s')
for failure in failures:
    print('ERROR', failure)
sys.exit(1 if failures else 0)
