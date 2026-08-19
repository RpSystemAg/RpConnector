#!/usr/bin/env python3
"""Fail if Laws 11-13 or their mechanical enforcement are weakened."""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTROL = ROOT / "prstudio-unified-control"
BROWSER = ROOT / "prstudio-unified-browser-agent"

CONSTITUTIONS = (
    ROOT / "AGENTS.md",
    CONTROL / "AGENTS.md",
    BROWSER / "AGENTS.md",
    ROOT / "CLAUDE.md",
    CONTROL / "CLAUDE.md",
    BROWSER / "CLAUDE.md",
)
GATE = ROOT / ".github" / "scripts" / "full-surface-execution.py"
WORKFLOW = ROOT / ".github" / "workflows" / "full-surface-execution.yml"
ENTERPRISE_WORKFLOW = ROOT / ".github" / "workflows" / "enterprise-verification.yml"
INVENTORY_TEST = ROOT / "tests" / "validate-test-inventory.py"
LEGACY_BASELINE = ROOT / "tests" / "test-inventory-baseline.json"

REQUIRED_LAWS = (
    "NO EXCLUSIONS, NO DEFERRAL, NO PARTIAL ACCEPTANCE",
    "FULL TEST/EXECUTION SURFACE MUST EXECUTE AT 100 PERCENT",
    "BLOCKERS TRIGGER STUDY AND REMEDIATION; THEY NEVER AUTHORIZE BYPASS OR STOP",
    "HUMAN INTENT MUST RESOLVE TO ACTION, NOT INTERNAL ENCYCLOPEDIA",
)
REQUIRED_CLAUSES = (
    "An explicit rationale does not legalize an exclusion or a deferral.",
    "REPEAT UNTIL PROVEN",
    "The only acceptable stopping condition is verified closure",
    "No alternative path to completion exists.",
)


def fail(message: str) -> None:
    print(f"FAIL {message}", file=sys.stderr)
    raise SystemExit(1)


def assert_failure_output_contract(gate: str) -> None:
    match = re.search(r'FAILURE_OUTPUT_RE\s*=\s*re\.compile\(r"([^"]+)"\)', gate)
    if match is None:
        fail("execution gate missing explicit failure-output matcher")
    pattern = re.compile(match.group(1))
    for sample in (
        "FAIL invariant broken",
        "worker: FAILED after recovery",
        "receipt FAILURE detected",
        "prefix FAIL token with exit zero",
    ):
        if pattern.search(sample) is None:
            fail(f"failure-output matcher accepts explicit failure marker: {sample!r}")
    for sample in (
        "PASS invariant",
        "fail-closed policy text",
        "failure handling is enabled",
    ):
        if pattern.search(sample) is not None:
            fail(f"failure-output matcher rejects non-failure text: {sample!r}")


def main() -> int:
    for path in CONSTITUTIONS:
        if not path.is_file():
            fail(f"missing constitution {path.relative_to(ROOT)}")
        text = path.read_text(encoding="utf-8", errors="replace")
        for law in REQUIRED_LAWS:
            if law not in text:
                fail(f"{path.relative_to(ROOT)} missing law: {law}")
        for clause in REQUIRED_CLAUSES:
            if clause not in text:
                fail(f"{path.relative_to(ROOT)} missing non-bypass clause: {clause}")

    if LEGACY_BASELINE.exists():
        fail("legacy test-inventory baseline reintroduced")
    for path in (GATE, WORKFLOW, ENTERPRISE_WORKFLOW, INVENTORY_TEST):
        if not path.is_file():
            fail(f"missing enforcement file {path.relative_to(ROOT)}")

    gate = GATE.read_text(encoding="utf-8", errors="replace")
    workflow = WORKFLOW.read_text(encoding="utf-8", errors="replace")
    enterprise = ENTERPRISE_WORKFLOW.read_text(encoding="utf-8", errors="replace")
    inventory = INVENTORY_TEST.read_text(encoding="utf-8", errors="replace")

    for forbidden in (
        "HELPERS =",
        "HELPERS:",
        "ALLOWLIST",
        "allow_list",
        "--update-baseline",
        "test-inventory-baseline.json",
    ):
        if forbidden in gate:
            fail(f"execution gate contains exception mechanism {forbidden!r}")
        if forbidden in inventory and forbidden != "test-inventory-baseline.json":
            fail(f"inventory contract contains exception mechanism {forbidden!r}")

    gate_requirements = (
        '["git", "ls-files", "-z"]',
        'TEST_ROOTS = (Path("tests"), Path("prstudio-unified-browser-agent/tests"))',
        "def source_kind",
        'first.startswith("#!")',
        '"php", "-l"',
        '"node", "--check"',
        "py_compile.compile",
        '"bash", "-n"',
        "json.load",
        "yaml.safe_load_all",
        "ET.parse",
        '"strace"',
        "def successful_runtime_evidence",
        "def output_has_failure_marker",
        'result["trace_evidence"] = evidence',
        'successful_trace_texts: list[str] = []',
        'successful_trace_texts.append(trace_text)',
        'combined_trace = "\\n".join(successful_trace_texts)',
        '"parse_does_not_count_as_execution": True',
        '"direct_execution_requires_syscall_evidence": True',
        '"failed_process_trace_cannot_count_data_execution": True',
        '"failure_output_with_zero_exit_is_failure": True',
        '"required_execution_percent": 100.0',
        "exact_100 = executed_ok == total_surface",
    )
    for fragment in gate_requirements:
        if fragment not in gate:
            fail(f"execution gate missing mechanical invariant {fragment!r}")

    if "trace_texts.append(trace_text)" in gate:
        fail("failed-process trace aggregation was reintroduced")
    assert_failure_output_contract(gate)

    workflow_requirements = (
        "push:",
        "pull_request:",
        "python .github/scripts/full-surface-execution.py",
        ".requirements.direct_execution_requires_syscall_evidence == true",
        ".counts.syntax_targets == .counts.syntax_passed",
        ".counts.total_test_surface_files == .counts.real_executed_files",
        ".counts.execution_percent == 100",
        ".ok == true",
    )
    for fragment in workflow_requirements:
        if fragment not in workflow:
            fail(f"full-surface workflow missing blocking assertion {fragment!r}")

    enterprise_requirements = (
        "push:",
        "pull_request:",
        "python3 tests/full_surface_execution_constitution.py",
    )
    for fragment in enterprise_requirements:
        if fragment not in enterprise:
            fail(f"enterprise verification missing independent constitution check {fragment!r}")

    print("FULL_SURFACE_EXECUTION_CONSTITUTION: PASS")
    print("laws_11_12_13_present_in_all_entry_points=true")
    print("allowlist=false")
    print("helper_exemption=false")
    print("baseline=false")
    print("parse_only_execution=false")
    print("shebang_scripts_included=true")
    print("direct_runtime_requires_syscall_evidence=true")
    print("failed_process_trace_counts=false")
    print("zero_exit_failure_output_counts=false")
    print("independent_enterprise_crosscheck=true")
    print("required_execution_percent=100")
    print("blocker_bypass=false")
    print("human_intent_is_entrypoint=true")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
