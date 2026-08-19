#!/usr/bin/env python3
"""Prove that test inventory is fail-closed and runtime-derived.

This test intentionally contains no exception table and no count ratchet. It
asserts that the repository-wide execution gate discovers the exact tracked
surface, executes it, emits syscall-backed evidence, and requires 100 percent.
"""
from __future__ import annotations

import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GATE = ROOT / ".github" / "scripts" / "full-surface-execution.py"
WORKFLOW = ROOT / ".github" / "workflows" / "full-surface-execution.yml"
LEGACY_BASELINE = ROOT / "tests" / "test-inventory-baseline.json"
TEST_ROOTS = ("tests/", "prstudio-unified-browser-agent/tests/")


def tracked_surface() -> list[str]:
    raw = subprocess.check_output(["git", "ls-files", "-z"], cwd=ROOT)
    names = [item.decode("utf-8") for item in raw.split(b"\0") if item]
    return sorted(name for name in names if any(name.startswith(prefix) for prefix in TEST_ROOTS))


def fail(message: str) -> None:
    print(f"FAIL {message}", file=sys.stderr)
    raise SystemExit(1)


def main() -> int:
    if LEGACY_BASELINE.exists():
        fail("legacy unexecuted-test baseline exists")
    if not GATE.is_file():
        fail("full-surface execution gate is missing")
    if not WORKFLOW.is_file():
        fail("full-surface execution workflow is missing")

    gate = GATE.read_text(encoding="utf-8")
    workflow = WORKFLOW.read_text(encoding="utf-8")
    surface = tracked_surface()
    if not surface:
        fail("tracked test surface is empty")

    required_gate_fragments = (
        'git", "ls-files", "-z"',
        'TEST_ROOTS = (Path("tests"), Path("prstudio-unified-browser-agent/tests"))',
        '"strace"',
        '"php", "-d", "auto_prepend_file=tests/strict-php-errors.php"',
        'sys.executable, rel.as_posix()',
        '"node", "--test"',
        '"bash", rel.as_posix()',
        '"runtime-consumed-data"',
        '"parse_does_not_count_as_execution": True',
        '"required_execution_percent": 100.0',
        'exact_100 = executed_ok == total_surface',
    )
    for fragment in required_gate_fragments:
        if fragment not in gate:
            fail(f"execution gate missing invariant: {fragment}")

    forbidden_gate_fragments = (
        "HELPERS =",
        "HELPERS:",
        "ALLOWLIST",
        "allow_list",
        "--update-baseline",
        "test-inventory-baseline.json",
    )
    for fragment in forbidden_gate_fragments:
        if fragment in gate:
            fail(f"execution gate contains forbidden exception mechanism: {fragment}")

    required_workflow_fragments = (
        "python .github/scripts/full-surface-execution.py",
        ".counts.total_test_surface_files == .counts.real_executed_files",
        ".counts.execution_percent == 100",
        ".ok == true",
    )
    for fragment in required_workflow_fragments:
        if fragment not in workflow:
            fail(f"workflow missing fail-closed assertion: {fragment}")

    print(f"TEST_INVENTORY_CONTRACT: PASS tracked_surface={len(surface)} required_execution=100%")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
