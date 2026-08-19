#!/usr/bin/env python3
"""Run the canonical production certifier with the modern-assurance overlay.

The base policy remains the general production contract. The modern registry is
an additive fail-closed overlay: every domain with mandatory_for_production=true
becomes a normal receipt gate using the same exact-SHA/freshness/artifact rules.
"""
from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / "quality/production-readiness-policy.json"
MODERN = ROOT / "quality/modern-specialized-assurance.json"
CERTIFIER = ROOT / "tests/production-readiness-certifier.py"

if "--policy" in sys.argv[1:]:
    raise SystemExit("MODERN-CERT-00 --policy override is forbidden; the canonical base+modern overlay is mandatory")

base = json.loads(BASE.read_text(encoding="utf-8"))
modern = json.loads(MODERN.read_text(encoding="utf-8"))
if modern.get("fail_closed") is not True:
    raise SystemExit("MODERN-CERT-01 modern registry must be fail_closed=true")
if modern.get("allow_expected_failures") is not False:
    raise SystemExit("MODERN-CERT-02 expected failures are forbidden")
if modern.get("allow_advisory_substitution") is not False:
    raise SystemExit("MODERN-CERT-03 advisory substitution is forbidden")
if modern.get("source_of_truth") != "master":
    raise SystemExit("MODERN-CERT-04 source_of_truth must be master")

gates = base.setdefault("gates", {})
for domain_id, domain in modern.get("domains", {}).items():
    if domain.get("mandatory_for_production") is not True:
        continue
    if domain_id in gates:
        raise SystemExit(f"MODERN-CERT-05 duplicate gate id in base and modern policies: {domain_id}")
    checks = domain.get("checks")
    if not isinstance(checks, list) or not checks or len(set(map(str, checks))) != len(checks):
        raise SystemExit(f"MODERN-CERT-06 invalid/duplicate checks for {domain_id}")
    receipt = str(domain.get("receipt", "")).strip()
    if not receipt.endswith(".json") or "/" in receipt or "\\" in receipt:
        raise SystemExit(f"MODERN-CERT-07 invalid receipt name for {domain_id}: {receipt!r}")
    gates[domain_id] = {
        "mandatory": True,
        "receipt": receipt,
        "real_environment": bool(domain.get("real_environment", False)),
        "max_age_hours": domain.get("max_age_hours", 24),
        "required_checks": list(map(str, checks)),
    }

with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8") as handle:
    json.dump(base, handle, indent=2, sort_keys=True)
    handle.write("\n")
    merged_path = Path(handle.name)

try:
    command = [sys.executable, str(CERTIFIER), "--policy", str(merged_path), *sys.argv[1:]]
    print(f"MODERN PRODUCTION OVERLAY: added={sum(1 for d in modern['domains'].values() if d.get('mandatory_for_production') is True)} total_gates={len(gates)}")
    completed = subprocess.run(command, cwd=ROOT, check=False)
    raise SystemExit(completed.returncode)
finally:
    merged_path.unlink(missing_ok=True)
