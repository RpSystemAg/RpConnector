#!/usr/bin/env python3
"""Verify a built release directory and emit the supply_chain_release receipt.

This verifier consumes only concrete build outputs. It does not infer safety from a
workflow conclusion. The signed provenance is cryptographically checked by `gh
attestation verify` before this script runs; this script additionally requires the
machine-readable verification report to bind every attested subject to its actual
SHA-256 and to the exact source commit policy used by that command.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import zipfile
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]


def now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def check_zip(path: Path) -> tuple[bool, dict[str, Any]]:
    try:
        with zipfile.ZipFile(path) as zf:
            bad = zf.testzip()
            names = [info.filename.replace("\\", "/") for info in zf.infolist() if not info.is_dir()]
            unsafe = [name for name in names if name.startswith("/") or ".." in Path(name).parts]
            return bad is None and not unsafe and bool(names), {
                "file": path.name,
                "sha256": sha256(path),
                "entries": len(names),
                "first_bad_crc": bad,
                "unsafe_entries": unsafe[:20],
            }
    except Exception as exc:
        return False, {"file": path.name, "error": f"{type(exc).__name__}: {exc}"}


def sbom_commit(value: dict[str, Any]) -> str:
    metadata = value.get("metadata")
    props = metadata.get("properties", []) if isinstance(metadata, dict) else []
    values = [
        str(prop.get("value", ""))
        for prop in props
        if isinstance(prop, dict) and prop.get("name") == "rpstudio:source_commit"
    ]
    return values[0] if len(values) == 1 else ""


def parse_sums(path: Path, dist: Path) -> tuple[bool, dict[str, Any], dict[str, str]]:
    declared: dict[str, str] = {}
    errors: list[str] = []
    for line_no, line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = line.strip()
        if not line:
            continue
        parts = line.split(None, 1)
        if len(parts) != 2:
            errors.append(f"line {line_no}: invalid SHA256SUMS record")
            continue
        digest, name = parts
        name = name.lstrip("* ").strip()
        basename = Path(name).name
        if len(digest) != 64 or any(ch not in "0123456789abcdefABCDEF" for ch in digest):
            errors.append(f"line {line_no}: invalid digest")
            continue
        if basename in declared:
            errors.append(f"duplicate checksum basename {basename}")
            continue
        candidate = dist / basename
        if not candidate.is_file():
            errors.append(f"checksummed file missing: {basename}")
            continue
        actual = sha256(candidate)
        if actual != digest.lower():
            errors.append(f"checksum mismatch: {basename}")
        declared[basename] = digest.lower()
    zips = sorted(path.name for path in dist.glob("prstudio-unified-*.zip"))
    expected = set(zips) | {"sbom.cdx.json", "supply-chain-source.json", "trivy-vulnerabilities.json"}
    if set(declared) != expected:
        errors.append(f"checksum coverage mismatch expected={sorted(expected)} actual={sorted(declared)}")
    return not errors and len(zips) == 2, {"declared": declared, "errors": errors, "zip_count": len(zips)}, declared


def trivy_findings(value: Any) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    critical: list[dict[str, Any]] = []
    high: list[dict[str, Any]] = []
    if not isinstance(value, dict):
        return [{"error": "Trivy root is not an object"}], []
    for result in value.get("Results") or []:
        if not isinstance(result, dict):
            continue
        target = result.get("Target")
        for vuln in result.get("Vulnerabilities") or []:
            if not isinstance(vuln, dict):
                continue
            row = {
                "target": target,
                "id": vuln.get("VulnerabilityID"),
                "package": vuln.get("PkgName"),
                "installed": vuln.get("InstalledVersion"),
                "fixed": vuln.get("FixedVersion"),
            }
            severity = str(vuln.get("Severity", "")).upper()
            if severity == "CRITICAL":
                critical.append(row)
            elif severity == "HIGH":
                high.append(row)
    return critical, high


def statement_has_digest(verification: Any, expected: str) -> bool:
    if not isinstance(verification, list) or not verification:
        return False
    for item in verification:
        if not isinstance(item, dict):
            continue
        result = item.get("verificationResult")
        statement = result.get("statement") if isinstance(result, dict) else None
        subjects = statement.get("subject", []) if isinstance(statement, dict) else []
        for subject in subjects:
            digest = subject.get("digest") if isinstance(subject, dict) else None
            if isinstance(digest, dict) and str(digest.get("sha256", "")).lower() == expected:
                return True
    return False


def artifact_entry(dist_path: Path, evidence_name: str | None = None) -> dict[str, Any]:
    return {
        "path": "evidence/production/" + (evidence_name or dist_path.name),
        "sha256": sha256(dist_path),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dist", default="dist")
    parser.add_argument("--commit", default=os.environ.get("GITHUB_SHA", ""))
    parser.add_argument("--started-at", default="")
    parser.add_argument("--output", default="dist/supply-chain-release.json")
    args = parser.parse_args()

    commit = args.commit.strip().lower()
    if len(commit) != 40 or any(ch not in "0123456789abcdef" for ch in commit):
        raise SystemExit("exact 40-hex --commit is required")
    dist = Path(args.dist)
    if not dist.is_absolute():
        dist = ROOT / dist
    required = {
        "source": dist / "supply-chain-source.json",
        "sbom": dist / "sbom.cdx.json",
        "sums": dist / "SHA256SUMS",
        "trivy": dist / "trivy-vulnerabilities.json",
        "bundle": dist / "provenance.bundle.jsonl",
        "verification": dist / "attestation-verification.json",
    }
    missing = [name for name, path in required.items() if not path.is_file() or path.stat().st_size == 0]
    if missing:
        raise SystemExit(f"missing production build evidence: {missing}")

    source = load(required["source"])
    source_checks = source.get("checks", {}) if isinstance(source, dict) else {}
    action_ok = source_checks.get("github_actions_commit_pinned", {}).get("ok") is True
    deps_ok = source_checks.get("dependencies_locked", {}).get("ok") is True

    sbom = load(required["sbom"])
    components = sbom.get("components", []) if isinstance(sbom, dict) else []
    sbom_ok = (
        isinstance(sbom, dict)
        and sbom.get("bomFormat") == "CycloneDX"
        and sbom.get("specVersion") == "1.6"
        and isinstance(components, list)
        and len(components) > 2
        and sbom_commit(sbom) == commit
    )

    sums_ok, sums_evidence, declared = parse_sums(required["sums"], dist)
    zip_evidence = []
    zip_ok = True
    for path in sorted(dist.glob("prstudio-unified-*.zip")):
        ok, evidence = check_zip(path)
        zip_ok = zip_ok and ok
        zip_evidence.append(evidence)
    sums_ok = sums_ok and zip_ok and len(zip_evidence) == 2

    trivy = load(required["trivy"])
    critical, high = trivy_findings(trivy)
    critical_ok = not critical

    verification = load(required["verification"])
    subjects = verification.get("subjects", []) if isinstance(verification, dict) else []
    policy = verification.get("policy", {}) if isinstance(verification, dict) else {}
    attested_expected = set(declared) | {"SHA256SUMS"}
    attested_actual: set[str] = set()
    attestation_errors: list[str] = []
    for subject in subjects if isinstance(subjects, list) else []:
        if not isinstance(subject, dict):
            attestation_errors.append("non-object verification subject")
            continue
        name = str(subject.get("name", ""))
        path = dist / name
        if not path.is_file():
            attestation_errors.append(f"verified subject missing locally: {name}")
            continue
        actual = sha256(path)
        if str(subject.get("sha256", "")).lower() != actual:
            attestation_errors.append(f"verification report digest mismatch: {name}")
            continue
        if not statement_has_digest(subject.get("verification"), actual):
            attestation_errors.append(f"cryptographic verification result lacks subject digest: {name}")
            continue
        attested_actual.add(name)
    if attested_actual != attested_expected:
        attestation_errors.append(
            f"attested subject coverage mismatch expected={sorted(attested_expected)} actual={sorted(attested_actual)}"
        )
    if policy.get("repository") != os.environ.get("GITHUB_REPOSITORY", policy.get("repository")):
        attestation_errors.append("attestation policy repository mismatch")
    if str(policy.get("source_digest", "")).lower() != commit:
        attestation_errors.append("attestation policy source digest mismatch")
    if not str(policy.get("signer_workflow", "")).endswith("/.github/workflows/build-attest.yml"):
        attestation_errors.append("attestation signer workflow policy missing")
    attestation_ok = not attestation_errors and required["bundle"].stat().st_size > 0

    checks = [
        {
            "id": "github_actions_commit_pinned",
            "ok": action_ok,
            "evidence": source_checks.get("github_actions_commit_pinned", {}),
        },
        {
            "id": "dependencies_locked",
            "ok": deps_ok,
            "evidence": source_checks.get("dependencies_locked", {}),
        },
        {
            "id": "sbom_generated",
            "ok": sbom_ok,
            "evidence": {"format": sbom.get("bomFormat") if isinstance(sbom, dict) else None, "spec": sbom.get("specVersion") if isinstance(sbom, dict) else None, "components": len(components) if isinstance(components, list) else 0, "source_commit": sbom_commit(sbom) if isinstance(sbom, dict) else ""},
        },
        {
            "id": "artifact_checksums",
            "ok": sums_ok,
            "evidence": {**sums_evidence, "zip_integrity": zip_evidence},
        },
        {
            "id": "build_attestation",
            "ok": attestation_ok,
            "evidence": {"subjects": sorted(attested_actual), "policy": policy, "errors": attestation_errors, "bundle_sha256": sha256(required["bundle"])},
        },
        {
            "id": "known_critical_vulnerabilities_zero",
            "ok": critical_ok,
            "evidence": {"critical_count": len(critical), "high_count": len(high), "critical": critical[:50]},
        },
    ]

    artifact_paths = [
        required["source"], required["sbom"], required["sums"], required["trivy"],
        required["bundle"], required["verification"], *sorted(dist.glob("prstudio-unified-*.zip")),
    ]
    receipt = {
        "schema_version": 1,
        "gate_id": "supply_chain_release",
        "commit_sha": commit,
        "ok": all(check["ok"] is True for check in checks),
        "started_at": args.started_at or now(),
        "finished_at": now(),
        "environment": {
            "real": False,
            "class": "github-actions-attested-build",
            "repository": os.environ.get("GITHUB_REPOSITORY", ""),
            "run_id": os.environ.get("GITHUB_RUN_ID", ""),
        },
        "checks": checks,
        "artifacts": [artifact_entry(path) for path in artifact_paths],
        "waivers": [],
        "skipped": [],
    }
    output = Path(args.output)
    if not output.is_absolute():
        output = ROOT / output
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print("PRODUCTION SUPPLY-CHAIN RELEASE RECEIPT")
    for check in checks:
        print(f"{'PASS' if check['ok'] else 'FAIL'} {check['id']}")
    print(f"receipt={output} ok={str(receipt['ok']).lower()}")
    return 0 if receipt["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
