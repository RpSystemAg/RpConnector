#!/usr/bin/env python3
"""Fail closed until the critical PHP kernel is genuinely mutation-testable."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
POLICY = json.loads((ROOT / "quality/mutation-kernel.json").read_text(encoding="utf-8"))
errors: list[str] = []

phpunit_configs = [ROOT / "phpunit.xml", ROOT / "phpunit.xml.dist"]
if not any(path.is_file() for path in phpunit_configs):
    errors.append("MUT-01 missing phpunit.xml/phpunit.xml.dist for critical-kernel mutation tests")

candidate_dirs = [ROOT / "tests/phpunit-kernel", ROOT / "tests/Unit", ROOT / "tests/unit"]
test_files = [p for d in candidate_dirs if d.is_dir() for p in d.rglob("*.php")]
if not test_files:
    errors.append("MUT-02 no dedicated PHPUnit critical-kernel test files found")

corpus = "\n".join(p.read_text(encoding="utf-8", errors="replace") for p in test_files)
for target in POLICY["targets"]:
    path = ROOT / target["path"]
    if not path.is_file():
        errors.append(f"MUT-03 target missing: {target['id']} -> {target['path']}")
        continue
    marker = str(target["required_test_marker"])
    if corpus and not re.search(re.escape(marker), corpus, re.I):
        errors.append(f"MUT-04 no PHPUnit kernel test references {target['id']} marker {marker!r}")

if not (0 <= int(POLICY.get("minimum_msi_percent", -1)) <= 100):
    errors.append("MUT-05 invalid MSI threshold")
if int(POLICY.get("minimum_msi_percent", 0)) < 90:
    errors.append("MUT-06 critical-kernel MSI floor may not be lower than 90")
if int(POLICY.get("minimum_covered_msi_percent", 0)) < 95:
    errors.append("MUT-07 covered-code MSI floor may not be lower than 95")
if POLICY.get("allow_ignored_critical_mutants") is not False:
    errors.append("MUT-08 critical mutants may not be silently ignored")

print(f"MUTATION KERNEL READINESS: targets={len(POLICY['targets'])} phpunit_files={len(test_files)} errors={len(errors)}")
for error in errors:
    print("ERROR", error)
sys.exit(1 if errors else 0)
