#!/usr/bin/env python3
"""Static conformance gates for the MCP 2026-07-28 implementation.

This is deliberately strict and complements, rather than replaces, live wire
acceptance. It catches regressions where legacy/session semantics leak into the
modern path or required modern metadata disappears from source.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MCP_PATH = ROOT / "prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php"
AUTH_PATH = ROOT / "prstudio-unified-control/includes/class-prstudio-uc-mcp-auth-v5.php"
MCP = MCP_PATH.read_text(encoding="utf-8", errors="replace")
AUTH = AUTH_PATH.read_text(encoding="utf-8", errors="replace")

errors: list[str] = []


def require(source: str, pattern: str, label: str, flags: int = 0) -> None:
    if not re.search(pattern, source, flags):
        errors.append(label)


def forbid(source: str, pattern: str, label: str, flags: int = 0) -> None:
    if re.search(pattern, source, flags):
        errors.append(label)

# Protocol identity and modern transport metadata.
require(MCP, r"2026-07-28", "MCP-01 server does not declare protocol 2026-07-28")
require(MCP, r"MCP-Protocol-Version|Mcp-Protocol-Version", "MCP-02 protocol-version HTTP header is not handled", re.I)
require(MCP, r"Mcp-Method", "MCP-03 Mcp-Method routing header is not handled", re.I)
require(MCP, r"Mcp-Name", "MCP-04 Mcp-Name routing header is not handled", re.I)
require(MCP, r"server/discover", "MCP-05 modern server/discover method is absent")

# Modern list/resource caching semantics.
require(MCP, r"ttlMs", "MCP-06 modern list/resource responses expose no ttlMs cache hint")
require(MCP, r"cacheScope", "MCP-07 modern list/resource responses expose no cacheScope hint")

# Self-describing request metadata. Accept either canonical URI-key spelling or
# an implementation helper that explicitly validates modern _meta.
if not (
    re.search(r"io\.modelcontextprotocol/clientInfo", MCP)
    or re.search(r"clientInfo", MCP)
):
    errors.append("MCP-08 modern clientInfo request metadata is not handled")
if "_meta" not in MCP:
    errors.append("MCP-09 modern request _meta envelope is not handled")

# New resource-not-found behavior must not regress to the stale custom code.
forbid(MCP, r"-32002", "MCP-10 stale resource error code -32002 remains in MCP server")

# JSON Schema 2020-12: root input schemas remain object-shaped, but the runtime
# must not intentionally downgrade schemas to an older draft.
forbid(MCP, r"draft-0[467]", "MCP-11 MCP code references an obsolete JSON Schema draft", re.I)

# Modern 2026-07-28 must not require protocol sessions. Legacy support may keep
# session identifiers in compatibility branches, so require an explicit modern
# branch/era marker and reject unconditional session requirements.
require(MCP, r"modern|2026-07-28", "MCP-12 no explicit modern protocol branch is identifiable", re.I)
if re.search(r"Mcp-Session-Id.{0,240}(?:required|missing|must)", MCP, re.I | re.S):
    errors.append("MCP-13 modern path appears to require Mcp-Session-Id")

# Deprecated core features should not be newly required by the modern server.
for deprecated in ("roots/list", "sampling/createMessage", "logging/setLevel"):
    if deprecated in MCP and re.search(rf"2026-07-28.{{0,600}}{re.escape(deprecated)}", MCP, re.I | re.S):
        errors.append(f"MCP-14 deprecated core feature appears coupled to modern path: {deprecated}")

# Authorization hardening.
require(AUTH, r"code_challenge_method", "AUTH-01 PKCE challenge method validation absent")
require(AUTH, r"S256", "AUTH-02 PKCE S256 enforcement absent")
require(AUTH, r"redirect_uri", "AUTH-03 redirect URI binding absent")
require(AUTH, r"resource", "AUTH-04 OAuth resource binding absent")
require(AUTH, r"offline_access", "AUTH-05 offline_access/refresh scope handling absent")
require(AUTH, r"refresh", "AUTH-06 refresh-token lifecycle absent", re.I)
require(AUTH, r"iss", "AUTH-07 RFC 9207 issuer parameter/hardening marker absent", re.I)

# DCR may exist only for compatibility. Current protocol direction requires it
# to be distinguishable from the preferred metadata-based client identity path.
if re.search(r"oauth/register|dynamic client registration|DCR", MCP + AUTH, re.I):
    if not re.search(r"deprecated|compatib|client metadata|CIMD|application_type", MCP + AUTH, re.I):
        errors.append("AUTH-08 DCR exists without explicit modern compatibility/deprecation handling")

# Security bounds expected for an internet-facing server.
require(MCP, r"1048576|1\s*\*\s*1024\s*\*\s*1024", "SEC-01 bounded request-body size is not evident")
require(MCP, r"180000|180_000", "SEC-02 bounded MCP result size is not evident")

print(f"MCP 2026-07-28 CONFORMANCE AUDIT: errors={len(errors)}")
for error in errors:
    print(f"ERROR {error}")
sys.exit(1 if errors else 0)
