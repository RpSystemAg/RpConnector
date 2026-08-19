#!/usr/bin/env python3
"""Turn Psalm's JSON output into a security-only fail-closed verdict.

Psalm 6 emits regular type findings together with taint findings when
--taint-analysis is enabled. PHPStan owns the type gate; this parser owns only
Psalm's security dataflow signal. Engine/config crashes always fail.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

out = Path("/tmp/psalm-taint.json")
err = Path("/tmp/psalm-taint.stderr")
code_file = Path("/tmp/psalm-taint.exit")

if not out.is_file() or not code_file.is_file():
    raise SystemExit("PSALM-TAINT-01 missing Psalm result/exit artifacts")

stderr = err.read_text(encoding="utf-8", errors="replace") if err.is_file() else ""
if re.search(r"(?:Fatal error|Uncaught|ConfigException|InvalidArgumentException|cannot load|could not load)", stderr, re.I):
    print(stderr[-12000:], file=sys.stderr)
    raise SystemExit("PSALM-TAINT-02 Psalm engine/configuration failed")

try:
    payload = json.loads(out.read_text(encoding="utf-8", errors="strict") or "[]")
except Exception as exc:
    print(stderr[-12000:], file=sys.stderr)
    raise SystemExit(f"PSALM-TAINT-03 invalid JSON result: {exc}")
if not isinstance(payload, list):
    raise SystemExit("PSALM-TAINT-04 Psalm JSON result is not an issue list")

security = []
for issue in payload:
    if not isinstance(issue, dict):
        continue
    issue_type = str(issue.get("type", ""))
    if issue_type.startswith("Tainted") or "Tainted" in issue_type:
        security.append(issue)

print(f"PSALM TAINT VERDICT: all_findings={len(payload)} taint_findings={len(security)} exit={code_file.read_text().strip()}")
for issue in security:
    print(json.dumps({
        "type": issue.get("type"),
        "message": issue.get("message"),
        "file": issue.get("file_name"),
        "line": issue.get("line_from"),
    }, ensure_ascii=False))

if security:
    raise SystemExit("PSALM-TAINT-05 unreviewed taint path(s) reached a security sink")
print("PASS Psalm found no source-to-security-sink path under the configured WordPress/MCP taint model")
