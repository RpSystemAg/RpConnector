#!/usr/bin/env python3
"""Fail-closed meta-certifier for the exact release commit.

A receipt is evidence input, not truth. This program independently validates the
shape, commit binding, freshness, duration, environment class, mandatory checks,
waiver/skip/degraded state and artifact digests before computing production_ready.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import sys
from datetime import datetime, timezone, timedelta
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_POLICY = ROOT / "quality" / "production-readiness-policy.json"


def utc(value: str) -> datetime:
    value = value.strip()
    if value.endswith("Z"):
        value = value[:-1] + "+00:00"
    dt = datetime.fromisoformat(value)
    if dt.tzinfo is None:
        raise ValueError("timestamp has no timezone")
    return dt.astimezone(timezone.utc)


def current_commit(explicit: str) -> str:
    if explicit:
        return explicit.strip()
    env = os.environ.get("GITHUB_SHA", "").strip()
    if env:
        return env
    try:
        return subprocess.check_output(
            ["git", "rev-parse", "HEAD"], cwd=ROOT, text=True, stderr=subprocess.DEVNULL
        ).strip()
    except Exception:
        return ""


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def nonempty_evidence(check: dict[str, Any]) -> bool:
    evidence = check.get("evidence")
    artifacts = check.get("artifacts")
    metrics = check.get("metrics")
    return bool(evidence) or bool(artifacts) or bool(metrics)


def validate_artifacts(receipt: dict[str, Any], gate_id: str, require_sha: bool) -> list[str]:
    errors: list[str] = []
    artifacts = receipt.get("artifacts")
    if not isinstance(artifacts, list) or not artifacts:
        return [f"{gate_id}: artifacts must be a non-empty list"]
    remote_prefixes = ("http://", "https://", "s3://", "gs://", "artifact://")
    for index, artifact in enumerate(artifacts):
        if not isinstance(artifact, dict):
            errors.append(f"{gate_id}: artifact[{index}] is not an object")
            continue
        location = str(artifact.get("path") or artifact.get("uri") or "").strip()
        digest = str(artifact.get("sha256") or "").lower().strip()
        if not location:
            errors.append(f"{gate_id}: artifact[{index}] has no path/uri")
            continue
        if require_sha and (len(digest) != 64 or any(c not in "0123456789abcdef" for c in digest)):
            errors.append(f"{gate_id}: artifact[{index}] has no valid sha256")
            continue
        if location.startswith(remote_prefixes):
            # The meta-certifier has no authority to fetch arbitrary evidence URIs.
            # Remote references therefore require a prior collector/verifier to assert
            # that it fetched the object and checked this digest for the exact release.
            if artifact.get("verified_external") is not True:
                errors.append(f"{gate_id}: artifact[{index}] remote URI is not independently verified")
            continue
        path = Path(location)
        if not path.is_absolute():
            path = ROOT / path
        if not path.exists() or not path.is_file():
            errors.append(f"{gate_id}: artifact[{index}] local evidence is missing: {location}")
            continue
        if require_sha:
            actual = sha256_file(path)
            if actual != digest:
                errors.append(f"{gate_id}: artifact[{index}] digest mismatch for {location}")
    return errors


def validate_receipt(
    gate_id: str,
    gate: dict[str, Any],
    receipt_path: Path,
    policy: dict[str, Any],
    commit: str,
    now: datetime,
) -> tuple[bool, list[str], dict[str, Any] | None]:
    errors: list[str] = []
    if not receipt_path.exists():
        return False, [f"{gate_id}: missing receipt {receipt_path.relative_to(ROOT) if receipt_path.is_relative_to(ROOT) else receipt_path}"], None
    try:
        receipt = json.loads(receipt_path.read_text(encoding="utf-8"))
    except Exception as exc:
        return False, [f"{gate_id}: invalid JSON: {exc}"], None
    if not isinstance(receipt, dict):
        return False, [f"{gate_id}: receipt root must be an object"], None

    schema = policy.get("receipt_schema", {})
    for key in schema.get("required", []):
        if key not in receipt:
            errors.append(f"{gate_id}: missing field {key}")

    if receipt.get("gate_id") != gate_id:
        errors.append(f"{gate_id}: receipt gate_id={receipt.get('gate_id')!r}")
    if receipt.get("ok") is not True:
        errors.append(f"{gate_id}: receipt does not assert ok=true")

    receipt_commit = str(receipt.get("commit_sha", "")).strip()
    if policy.get("require_exact_commit", True):
        if not commit:
            errors.append(f"{gate_id}: release commit cannot be determined")
        elif receipt_commit != commit:
            errors.append(f"{gate_id}: commit_sha {receipt_commit!r} != {commit!r}")

    started = finished = None
    try:
        started = utc(str(receipt.get("started_at", "")))
        finished = utc(str(receipt.get("finished_at", "")))
        if finished < started:
            errors.append(f"{gate_id}: finished_at precedes started_at")
        if finished > now + timedelta(minutes=5):
            errors.append(f"{gate_id}: finished_at is implausibly in the future")
        max_age = gate.get("max_age_hours")
        if max_age is not None and now - finished > timedelta(hours=float(max_age)):
            errors.append(f"{gate_id}: evidence is older than {max_age}h")
        min_duration = gate.get("min_duration_seconds")
        if min_duration is not None and (finished - started).total_seconds() < float(min_duration):
            errors.append(f"{gate_id}: duration is below required {min_duration}s")
    except Exception as exc:
        errors.append(f"{gate_id}: invalid timestamps: {exc}")

    environment = receipt.get("environment")
    if not isinstance(environment, dict):
        errors.append(f"{gate_id}: environment must be an object")
    elif gate.get("real_environment") and environment.get("real") is not True:
        errors.append(f"{gate_id}: requires environment.real=true")

    if schema.get("forbid_waivers", True) or not policy.get("allow_waivers", False):
        if receipt.get("waivers"):
            errors.append(f"{gate_id}: waivers are forbidden for production certification")
    if schema.get("forbid_skipped", True) and receipt.get("skipped"):
        errors.append(f"{gate_id}: skipped checks are forbidden")
    if schema.get("forbid_degraded", True) and receipt.get("degraded"):
        errors.append(f"{gate_id}: degraded evidence cannot certify production")

    checks = receipt.get("checks")
    if not isinstance(checks, list):
        checks = []
        errors.append(f"{gate_id}: checks must be a list")
    by_id: dict[str, dict[str, Any]] = {}
    duplicates: set[str] = set()
    for check in checks:
        if not isinstance(check, dict):
            errors.append(f"{gate_id}: non-object check entry")
            continue
        check_id = str(check.get("id", "")).strip()
        if not check_id:
            errors.append(f"{gate_id}: check without id")
            continue
        if check_id in by_id:
            duplicates.add(check_id)
        by_id[check_id] = check
    for check_id in sorted(duplicates):
        errors.append(f"{gate_id}: duplicate check id {check_id}")

    for check_id in gate.get("required_checks", []):
        check = by_id.get(check_id)
        if check is None:
            errors.append(f"{gate_id}: missing required check {check_id}")
            continue
        if check.get("ok") is not True:
            errors.append(f"{gate_id}: required check {check_id} is not ok=true")
        if check.get("skipped") or check.get("waived") or check.get("degraded"):
            errors.append(f"{gate_id}: required check {check_id} is skipped/waived/degraded")
        if not nonempty_evidence(check):
            errors.append(f"{gate_id}: required check {check_id} has no independent evidence/artifact/metric")

    errors.extend(validate_artifacts(receipt, gate_id, bool(schema.get("artifact_sha256_required", True))))
    return not errors, errors, receipt


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--policy", default=str(DEFAULT_POLICY))
    parser.add_argument("--evidence-dir", default="")
    parser.add_argument("--commit", default="")
    parser.add_argument("--strict", action="store_true", help="exit non-zero unless production_ready=true")
    parser.add_argument("--output", default="")
    args = parser.parse_args()

    policy_path = Path(args.policy)
    if not policy_path.is_absolute():
        policy_path = ROOT / policy_path
    policy = json.loads(policy_path.read_text(encoding="utf-8"))
    commit = current_commit(args.commit)
    evidence_dir = Path(args.evidence_dir or policy.get("receipt_directory", "evidence/production"))
    if not evidence_dir.is_absolute():
        evidence_dir = ROOT / evidence_dir
    now = datetime.now(timezone.utc)

    gate_results: dict[str, Any] = {}
    failures: list[str] = []
    mandatory_total = mandatory_passed = 0
    for gate_id, gate in policy.get("gates", {}).items():
        if not gate.get("mandatory", True):
            continue
        mandatory_total += 1
        receipt_path = evidence_dir / str(gate.get("receipt", f"{gate_id}.json"))
        ok, errors, receipt = validate_receipt(gate_id, gate, receipt_path, policy, commit, now)
        if ok:
            mandatory_passed += 1
        else:
            failures.extend(errors)
        gate_results[gate_id] = {
            "ok": ok,
            "receipt": str(receipt_path.relative_to(ROOT)) if receipt_path.is_relative_to(ROOT) else str(receipt_path),
            "errors": errors,
            "receipt_commit": receipt.get("commit_sha") if receipt else None,
        }

    production_ready = bool(commit) and mandatory_total > 0 and mandatory_passed == mandatory_total and not failures
    verdict = {
        "schema_version": 1,
        "claim": policy.get("claim", "production_ready"),
        "commit_sha": commit,
        "evaluated_at": now.isoformat().replace("+00:00", "Z"),
        "production_ready": production_ready,
        "mandatory_gates_passed": mandatory_passed,
        "mandatory_gates_total": mandatory_total,
        "failures": failures,
        "gates": gate_results,
    }

    output = args.output or policy.get("verdict_file", "evidence/production/production-readiness-verdict.json")
    output_path = Path(output)
    if not output_path.is_absolute():
        output_path = ROOT / output_path
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(json.dumps(verdict, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print("PRODUCTION READINESS CERTIFICATION")
    print(f" commit={commit or 'UNKNOWN'}")
    print(f" gates={mandatory_passed}/{mandatory_total}")
    print(f" production_ready={str(production_ready).lower()}")
    for failure in failures:
        print(f"FAIL {failure}")

    github_output = os.environ.get("GITHUB_OUTPUT")
    if github_output:
        with open(github_output, "a", encoding="utf-8") as fh:
            fh.write(f"production_ready={'true' if production_ready else 'false'}\n")
            fh.write(f"verdict={output_path}\n")

    return 1 if args.strict and not production_ready else 0


if __name__ == "__main__":
    raise SystemExit(main())
