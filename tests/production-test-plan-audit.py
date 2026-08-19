#!/usr/bin/env python3
"""Audit the production test plan itself.

A production gate is not covered merely because a similarly named test exists.
Every policy check must be mapped to an executable provider, and real-environment
gates cannot be represented only by model/mock/static providers.
"""
from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
POLICY = ROOT / "quality" / "production-readiness-policy.json"
PLAN = ROOT / "quality" / "production-test-plan.json"

REAL_OK_STATUSES = {
    "implemented_real_harness",
    "partial_real_harness",
    "harness_added_not_yet_executed",
    "legacy_receipts_partial",
}
PROVIDER_KEYS = {"receipt_generator", "tests", "workflows", "legacy_receipts"}


def exists_provider(value: Any) -> tuple[bool, list[str]]:
    missing: list[str] = []
    paths: list[str] = []
    if isinstance(value, str):
        paths = [value]
    elif isinstance(value, list):
        paths = [str(v) for v in value]
    for raw in paths:
        # Directories are acceptable test providers only when they physically exist.
        path = ROOT / raw
        if not path.exists():
            missing.append(raw)
    return not missing, missing


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--require-real-harness", action="store_true")
    args = parser.parse_args()

    policy = json.loads(POLICY.read_text(encoding="utf-8"))
    plan = json.loads(PLAN.read_text(encoding="utf-8"))
    gates = policy.get("gates", {})
    providers = plan.get("providers", {})
    errors: list[str] = []
    warnings: list[str] = []

    for gate_id, gate in gates.items():
        if not gate.get("mandatory", True):
            continue
        provider = providers.get(gate_id)
        if not isinstance(provider, dict):
            errors.append(f"{gate_id}: no production-test-plan provider")
            continue
        status = str(provider.get("status", "")).strip()
        if not status:
            errors.append(f"{gate_id}: missing status")
        concrete = False
        for key in PROVIDER_KEYS:
            if key not in provider:
                continue
            ok, missing = exists_provider(provider[key])
            if ok:
                concrete = True
            else:
                errors.extend(f"{gate_id}: mapped provider missing: {path}" for path in missing)
        if not concrete:
            warnings.append(f"{gate_id}: no executable provider currently present; gap is explicit")

        gap = str(provider.get("blocking_gap", "")).strip()
        if status not in {"implemented_ci", "implemented_real_harness"} and not gap:
            errors.append(f"{gate_id}: incomplete status without blocking_gap")

        if gate.get("real_environment"):
            if provider.get("environment_class") in {None, "", "source", "model", "mock"}:
                errors.append(f"{gate_id}: real gate has invalid environment_class={provider.get('environment_class')!r}")
            if args.require_real_harness and status not in REAL_OK_STATUSES | {"implemented_real_harness"}:
                errors.append(f"{gate_id}: no real-environment harness status ({status})")

        # Required-check trace must become explicit before production certification.
        mapped_checks = provider.get("mapped_checks")
        required_checks = list(gate.get("required_checks", []))
        if mapped_checks is None:
            warnings.append(f"{gate_id}: {len(required_checks)} required checks still need provider-level 1:1 trace")
        elif not isinstance(mapped_checks, dict):
            errors.append(f"{gate_id}: mapped_checks must be an object")
        else:
            missing_checks = sorted(set(required_checks) - set(mapped_checks))
            extra_checks = sorted(set(mapped_checks) - set(required_checks))
            if missing_checks:
                errors.append(f"{gate_id}: unmapped required checks: {', '.join(missing_checks)}")
            if extra_checks:
                errors.append(f"{gate_id}: mapped unknown checks: {', '.join(extra_checks)}")
            for check_id, mapped in mapped_checks.items():
                if not mapped:
                    errors.append(f"{gate_id}.{check_id}: empty provider mapping")

    extra_gates = sorted(set(providers) - set(gates))
    if extra_gates:
        errors.append("test plan contains unknown gates: " + ", ".join(extra_gates))

    print("PRODUCTION TEST PLAN AUDIT")
    print(f"policy_gates={len(gates)} plan_gates={len(providers)}")
    print(f"errors={len(errors)} warnings={len(warnings)}")
    for warning in warnings:
        print(f"WARN {warning}")
    for error in errors:
        print(f"FAIL {error}")

    # Default mode enforces structural completeness and honest provider existence.
    # --require-real-harness additionally blocks all model/mock/partial source-only gaps.
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
