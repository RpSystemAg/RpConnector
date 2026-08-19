#!/usr/bin/env python3
"""Concurrency invariants for OAuth state.

OAuth tokens and client registrations are shared mutable security state. A
whole-option read/modify/write loses updates under concurrent PHP requests and
is therefore forbidden unless guarded by an explicit CAS/transaction primitive.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / 'prstudio-unified-control/includes/class-prstudio-uc-mcp-auth-v5.php'
SRC = PATH.read_text(encoding='utf-8', errors='replace')


def body(name: str) -> str:
    m = re.search(rf'function\s+{re.escape(name)}\s*\(', SRC)
    if not m:
        raise AssertionError(f'missing function {name}')
    start = SRC.find('{', m.end())
    depth = 0
    quote = None
    escaped = False
    for i in range(start, len(SRC)):
        ch = SRC[i]
        if quote:
            if escaped:
                escaped = False
            elif ch == '\\':
                escaped = True
            elif ch == quote:
                quote = None
            continue
        if ch in "'\"":
            quote = ch
        elif ch == '{':
            depth += 1
        elif ch == '}':
            depth -= 1
            if depth == 0:
                return SRC[start + 1:i]
    raise AssertionError(f'unbalanced function {name}')

violations: list[str] = []

# Acceptable implementation evidence: dedicated SQL-backed state, an explicit
# compare-and-swap primitive, or a named atomic registry helper used by callers.
GLOBAL_ATOMIC_MARKERS = (
    'oauth_tokens_table',
    'oauth_clients_table',
    'compare_and_swap',
    'cas_option',
    'atomic_token_registry',
    'atomic_client_registry',
    'START TRANSACTION',
)

for fn, option, code in (
    ('register_client', 'CLIENTS_OPTION', 'OAUTH-C1'),
    ('issue_tokens', 'TOKENS_OPTION', 'OAUTH-C2'),
    ('delete_token_record', 'TOKENS_OPTION', 'OAUTH-C3'),
    ('verify_access_token', 'TOKENS_OPTION', 'OAUTH-C4'),
):
    b = body(fn)
    whole_read = re.search(rf'get_option\s*\(\s*self::{option}', b)
    whole_write = re.search(rf'update_option\s*\(\s*self::{option}', b)
    atomic = any(marker in b or marker in SRC for marker in GLOBAL_ATOMIC_MARKERS)
    if whole_read and whole_write and not atomic:
        violations.append(f'{code} {fn}: whole-option read/modify/write is not concurrency-safe')

refresh = body('exchange_refresh')
if 'delete_token_record' in refresh and 'issue_tokens' in refresh:
    if not any(marker in refresh or marker in SRC for marker in ('rotate_refresh_token_atomic', 'START TRANSACTION', 'compare_and_swap', 'cas_option')):
        violations.append('OAUTH-C5 exchange_refresh: delete-old + issue-new rotation is not one atomic operation')

rate = body('rate_limit')
if re.search(r'get_transient\s*\(', rate) and re.search(r'set_transient\s*\(', rate):
    if not any(marker in rate for marker in ('wp_cache_incr', 'atomic', 'INCR', 'INSERT', 'UPDATE')):
        violations.append('OAUTH-C6 rate_limit: get_transient/set_transient counter admits concurrent overrun')

# DCR client cap must be atomic as well: count + insert on a shared registry is
# otherwise a race even when the limit is mostly a resource bound.
register = body('register_client')
if 'MAX_CLIENTS' in register and 'count(' in register and 'update_option' in register:
    if not any(marker in register or marker in SRC for marker in ('atomic_client_registry', 'compare_and_swap', 'cas_option', 'START TRANSACTION')):
        violations.append('OAUTH-C7 register_client: MAX_CLIENTS check and insert are not atomic')

print(f'OAUTH CONCURRENCY INVARIANTS: violations={len(violations)}')
for violation in violations:
    print('ERROR', violation)
sys.exit(1 if violations else 0)
