#!/usr/bin/env python3
"""Fail closed on runtime patterns that can break durable execution semantics.

These checks intentionally target architectural invariants rather than individual
bug strings. They are cheap enough to run on every push and PR.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
STORE = (ROOT / "prstudio-unified-control/includes/class-prstudio-uc-store.php").read_text(encoding="utf-8")
RUNTIME = (ROOT / "prstudio-unified-control/includes/class-prstudio-uc-agency-runtime.php").read_text(encoding="utf-8")
JOB_ENGINE = (ROOT / "prstudio-unified-control/includes/class-prstudio-uc-job-engine.php").read_text(encoding="utf-8")


def function_body(source: str, name: str) -> str:
    match = re.search(rf"function\s+{re.escape(name)}\s*\(", source)
    if not match:
        raise AssertionError(f"function {name} not found")
    brace = source.find("{", match.end())
    if brace < 0:
        raise AssertionError(f"function {name} has no body")
    depth = 0
    quote = None
    escaped = False
    for i in range(brace, len(source)):
        ch = source[i]
        if quote:
            if escaped:
                escaped = False
            elif ch == "\\":
                escaped = True
            elif ch == quote:
                quote = None
            continue
        if ch in ("'", '"'):
            quote = ch
            continue
        if ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                return source[brace + 1 : i]
    raise AssertionError(f"function {name} body is unbalanced")


violations: list[str] = []

# INV-1: acquiring/resuming healthy work must never consume the failure retry budget.
claim = function_body(STORE, "claim_job_internal")
if re.search(r"['\"]attempts['\"]\s*=>[^\n;]*\+\s*1", claim):
    violations.append("INV-1 liveness: claim_job_internal increments attempts; claim/resume is not a failure")

# INV-2: every browser child dispatched by a durable parent must have a stable step key.
execute_step = function_body(RUNTIME, "execute_step")
browser_marker = execute_step.find("case 'browser.action'")
if browser_marker < 0:
    violations.append("INV-2 idempotency: browser.action handler missing")
else:
    browser_block = execute_step[browser_marker:]
    if not any(key in browser_block for key in ("_idempotency_key", "idempotency_key", "client_request_id")):
        violations.append("INV-2 idempotency: durable browser dispatch has no explicit stable step idempotency key")

# INV-3: declared per-step timeout_seconds must be consumed by the executor, not documentation only.
run_one = function_body(RUNTIME, "run_one")
if "timeout_seconds" not in run_one:
    violations.append("INV-3 deadlines: run_one never consumes step timeout_seconds")

# INV-4: verification strength is monotonic. A weak child cannot become a strong parent.
if "agency_playbook_v10" in run_one and re.search(r"['\"]ok['\"]\s*=>\s*true", run_one):
    if "unverified_steps" not in run_one and "degraded" not in run_one:
        violations.append("INV-4 verification: Agency completion can hard-code ok=true without aggregating child evidence")

complete_browser = function_body(JOB_ENGINE, "complete_browser_task")
if re.search(r"reconcile_browser_parent\s*\(\s*\$completed\s*,\s*true", complete_browser):
    if not re.search(r"reconcile_browser_parent\s*\([^;]*(?:verification\[['\"]ok['\"]\]|\$verification\s*\[\s*['\"]ok)", complete_browser):
        violations.append("INV-4b verification: completed browser child is reconciled as successful independently of verifier.ok")

# INV-5: job state writes require an explicit transition relation, like browser tasks already do.
set_job_state = function_body(STORE, "set_job_state")
transition_evidence = (
    "can_job_transition",
    "assert_job_transition",
    "JOB_TRANSITIONS",
    "job_transition_allowed",
)
if not any(marker in set_job_state or marker in STORE for marker in transition_evidence):
    violations.append("INV-5 state machine: jobs have allowed target states but no explicit from->to transition invariant")

# INV-6: stale recovery must never silently disappear from coverage.
recovery = function_body(STORE, "recover_stale_tasks")
if "lease_expires_gmt" not in recovery or "recovery_count" not in recovery:
    violations.append("INV-6 recovery: stale browser lease recovery path is missing required fencing/accounting")

if violations:
    print("RUNTIME INVARIANT AUDIT: FAIL")
    for item in violations:
        print(f" - {item}")
    sys.exit(1)

print("RUNTIME INVARIANT AUDIT: PASS")
