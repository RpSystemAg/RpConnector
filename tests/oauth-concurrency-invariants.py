#!/usr/bin/env python3
"""Fail-closed structural concurrency invariants for OAuth shared state.

This file is a regression guard, not runtime evidence. The WordPress/MariaDB
acceptance surface must still execute concurrent token/client/code operations.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PATH = ROOT / "prstudio-unified-control/includes/class-prstudio-uc-mcp-auth-v5.php"
SRC = PATH.read_text(encoding="utf-8", errors="strict")


def body(name: str) -> str:
    match = re.search(rf"function\s+{re.escape(name)}\s*\(", SRC)
    if not match:
        raise AssertionError(f"missing function {name}")
    start = SRC.find("{", match.end())
    if start < 0:
        raise AssertionError(f"missing body for {name}")
    depth = 0
    quote = None
    escaped = False
    for index in range(start, len(SRC)):
        char = SRC[index]
        if quote:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == quote:
                quote = None
            continue
        if char in "'\"":
            quote = char
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return SRC[start + 1 : index]
    raise AssertionError(f"unbalanced function {name}")


violations: list[str] = []


def require(haystack: str, needle: str, code: str, message: str) -> None:
    if needle not in haystack:
        violations.append(f"{code} {message}")


def forbid(haystack: str, needle: str, code: str, message: str) -> None:
    if needle in haystack:
        violations.append(f"{code} {message}")


lock = body("with_db_lock")
for needle, code, message in (
    ("DB_NAME", "OAUTH-C1", "lock name is not database scoped"),
    ("$wpdb->prefix", "OAUTH-C2", "lock name is not WordPress-site scoped"),
    ("get_current_blog_id", "OAUTH-C3", "multisite lock namespace is missing"),
    ("GET_LOCK", "OAUTH-C4", "advisory lock acquisition is missing"),
    ("RELEASE_LOCK", "OAUTH-C5", "advisory lock release is missing"),
    ("CONNECTION_ID()", "OAUTH-C6", "connection-loss fencing is missing"),
    ("oauth_state_lock_lost", "OAUTH-C7", "lost-lock path is not fail-closed"),
    ("oauth_state_lock_order", "OAUTH-C8", "nested cross-scope lock order is not rejected"),
):
    require(lock, needle, code, message)
if lock.count("CONNECTION_ID()") < 2:
    violations.append("OAUTH-C9 lock owner connection is not checked before and after mutation")
if not re.search(r"null\s*===\s*\$raw_acquired", lock):
    violations.append("OAUTH-C10 GET_LOCK infrastructure failure is not distinguished from contention")
if not re.search(r"'1'\s*!==\s*\(string\)\s*\$released", lock):
    violations.append("OAUTH-C11 RELEASE_LOCK result is not verified")

cas = body("atomic_option_registry")
for needle, code, message in (
    ("BINARY option_value = BINARY %s", "OAUTH-C12", "option registry lacks byte-exact CAS fencing"),
    ("INSERT IGNORE", "OAUTH-C13", "empty registry initialization is not race-safe"),
    ("oauth_state_conflict", "OAUTH-C14", "CAS exhaustion is not fail-closed"),
    ("invalidate_option_cache", "OAUTH-C15", "successful direct SQL mutation does not invalidate WordPress option cache"),
):
    require(cas, needle, code, message)
if not re.search(r"for\s*\(\s*\$attempt\s*=\s*0;\s*\$attempt\s*<\s*8", cas):
    violations.append("OAUTH-C16 CAS conflict retry is missing or unbounded")

clients = body("atomic_client_registry")
tokens = body("atomic_token_registry")
require(clients, "atomic_option_registry", "OAUTH-C17", "client registry bypasses CAS helper")
require(tokens, "atomic_option_registry", "OAUTH-C18", "token registry bypasses CAS helper")

register = body("register_client")
require(register, "atomic_client_registry", "OAUTH-C19", "DCR capacity + insert are not one atomic registry mutation")
forbid(register, "update_option( self::CLIENTS_OPTION", "OAUTH-C20", "DCR writes client registry outside atomic helper")
require(register, "client_registry_full", "OAUTH-C38", "DCR capacity does not explicitly reject excess clients")
forbid(register, "array_slice( $clients", "OAUTH-C39", "DCR capacity silently evicts already accepted clients")

issue = body("issue_tokens")
require(issue, "atomic_token_registry", "OAUTH-C21", "token issuance is not one atomic registry mutation")

refresh = body("rotate_refresh_token_atomic")
require(refresh, "atomic_token_registry", "OAUTH-C22", "refresh rotation is not one atomic registry mutation")
if "unset( $tokens[ $parsed['id'] ] )" not in refresh or "$tokens[ $material['id'] ]" not in refresh:
    violations.append("OAUTH-C23 refresh rotation does not consume-old + publish-new inside the same mutation")

consume = body("consume_authorization_code")
require(consume, "with_db_lock", "OAUTH-C24", "authorization code consumption is not serialized")
require(consume, "delete_transient", "OAUTH-C25", "authorization code is not consumed")
exchange_code = body("exchange_code")
require(exchange_code, "consume_authorization_code", "OAUTH-C26", "authorization-code exchange bypasses atomic single-use consumer")
forbid(exchange_code, "get_transient", "OAUTH-C27", "authorization-code exchange performs an unlocked transient read")

atomic_rate = body("atomic_rate_limit")
require(atomic_rate, "with_db_lock", "OAUTH-C28", "rate-limit increment is not serialized")
require(atomic_rate, "oauth_rate_store_failed", "OAUTH-C29", "rate-limit persistence failure can masquerade as success")
verify_access = body("verify_access_token")
require(verify_access, "is_wp_error( $rate )", "OAUTH-C30", "rate-limit infrastructure error is collapsed into HTTP 429")
rate = body("rate_limit")
if "return self::atomic_rate_limit" not in rate:
    violations.append("OAUTH-C31 rate_limit does not propagate atomic counter errors")

revoke = body("revoke_all")
require(revoke, "with_db_lock", "OAUTH-C32", "global revocation is not serialized")
require(revoke, "atomic_token_registry", "OAUTH-C33", "global revocation does not clear tokens through atomic registry path")
require(revoke, "is_wp_error( $result )", "OAUTH-C34", "global revocation ignores lock/mutation failure")
audit_pos = revoke.find("self::audit( 'oauth.revoke_all'")
error_pos = revoke.find("is_wp_error( $result )")
if audit_pos < 0 or error_pos < 0 or audit_pos < error_pos:
    violations.append("OAUTH-C35 revoke_all can emit success audit before checking mutation result")

# A direct whole-option write to shared OAuth registries reintroduces the lost-
# update class even if another atomic helper exists elsewhere in the file.
for option, code in (("CLIENTS_OPTION", "OAUTH-C36"), ("TOKENS_OPTION", "OAUTH-C37")):
    if re.search(rf"update_option\s*\(\s*self::{option}", SRC):
        violations.append(f"{code} direct update_option on shared registry is forbidden")

print(f"OAUTH CONCURRENCY INVARIANTS: violations={len(violations)}")
for violation in violations:
    print("ERROR", violation)
sys.exit(1 if violations else 0)
