#!/usr/bin/env python3
"""Adversarial self-test for production-supply-chain-release.py.

Cryptographic verification itself is performed by `gh attestation verify` in the
build workflow. This suite attacks the local verifier/receipt layer and proves it
rejects stale, incomplete or internally inconsistent evidence.
"""
from __future__ import annotations

import hashlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
import zipfile
from pathlib import Path
from typing import Callable

ROOT = Path(__file__).resolve().parents[1]
VERIFIER = ROOT / "tests" / "production-supply-chain-release.py"
COMMIT = "a" * 40
REPOSITORY = "RpSystemAg/RpConnector"


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def write_json(path: Path, value: object) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def make_zip(path: Path, member: str) -> None:
    with zipfile.ZipFile(path, "w", compression=zipfile.ZIP_DEFLATED) as archive:
        archive.writestr(member, "synthetic release payload\n")


def make_fixture(root: Path) -> Path:
    dist = root / "dist"
    dist.mkdir(parents=True)
    make_zip(dist / "prstudio-unified-browser-agent-deadbee.zip", "browser/manifest.json")
    make_zip(dist / "prstudio-unified-control-deadbee.zip", "plugin/prstudio-unified-control.php")

    write_json(
        dist / "supply-chain-source.json",
        {
            "schema_version": 1,
            "checks": {
                "github_actions_commit_pinned": {"ok": True, "findings": []},
                "dependencies_locked": {"ok": True, "findings": []},
            },
        },
    )
    write_json(
        dist / "sbom.cdx.json",
        {
            "bomFormat": "CycloneDX",
            "specVersion": "1.6",
            "version": 1,
            "metadata": {
                "properties": [
                    {"name": "rpstudio:source_commit", "value": COMMIT},
                ]
            },
            "components": [
                {"type": "application", "bom-ref": "a", "name": "a"},
                {"type": "application", "bom-ref": "b", "name": "b"},
                {"type": "file", "bom-ref": "file:x", "name": "x"},
            ],
        },
    )
    write_json(dist / "trivy-vulnerabilities.json", {"SchemaVersion": 2, "Results": []})

    checksummed = [
        *sorted(dist.glob("prstudio-unified-*.zip")),
        dist / "sbom.cdx.json",
        dist / "supply-chain-source.json",
        dist / "trivy-vulnerabilities.json",
    ]
    (dist / "SHA256SUMS").write_text(
        "".join(f"{sha256(path)}  {path}\n" for path in checksummed),
        encoding="utf-8",
    )
    (dist / "provenance.bundle.jsonl").write_text('{"synthetic":"nonempty"}\n', encoding="utf-8")

    subjects = []
    for path in [*checksummed, dist / "SHA256SUMS"]:
        digest = sha256(path)
        subjects.append(
            {
                "name": path.name,
                "sha256": digest,
                "verification": [
                    {
                        "verificationResult": {
                            "statement": {
                                "subject": [{"name": path.name, "digest": {"sha256": digest}}]
                            }
                        }
                    }
                ],
            }
        )
    write_json(
        dist / "attestation-verification.json",
        {
            "schema_version": 1,
            "commit_sha": COMMIT,
            "policy": {
                "repository": REPOSITORY,
                "signer_workflow": REPOSITORY + "/.github/workflows/build-attest.yml",
                "source_digest": COMMIT,
                "predicate_type": "https://slsa.dev/provenance/v1",
            },
            "subjects": subjects,
        },
    )
    return dist


def run(dist: Path) -> subprocess.CompletedProcess[str]:
    env = dict(os.environ)
    env["GITHUB_REPOSITORY"] = REPOSITORY
    return subprocess.run(
        [
            sys.executable,
            str(VERIFIER),
            "--dist",
            str(dist),
            "--commit",
            COMMIT,
            "--started-at",
            "2026-08-19T06:00:00Z",
            "--output",
            str(dist / "supply-chain-release.json"),
        ],
        cwd=ROOT,
        env=env,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        check=False,
    )


def mutate_bad_checksum(dist: Path) -> None:
    path = dist / "sbom.cdx.json"
    value = json.loads(path.read_text(encoding="utf-8"))
    value["version"] = 2
    write_json(path, value)


def mutate_cross_sha_sbom(dist: Path) -> None:
    value = json.loads((dist / "sbom.cdx.json").read_text(encoding="utf-8"))
    value["metadata"]["properties"][0]["value"] = "b" * 40
    write_json(dist / "sbom.cdx.json", value)
    refresh_checksum_and_verification(dist, "sbom.cdx.json")


def mutate_critical_vulnerability(dist: Path) -> None:
    write_json(
        dist / "trivy-vulnerabilities.json",
        {
            "SchemaVersion": 2,
            "Results": [
                {
                    "Target": "synthetic",
                    "Vulnerabilities": [
                        {
                            "VulnerabilityID": "CVE-SYNTHETIC-0001",
                            "PkgName": "synthetic",
                            "InstalledVersion": "1",
                            "FixedVersion": "2",
                            "Severity": "CRITICAL",
                        }
                    ],
                }
            ],
        },
    )
    refresh_checksum_and_verification(dist, "trivy-vulnerabilities.json")


def mutate_missing_attested_subject(dist: Path) -> None:
    path = dist / "attestation-verification.json"
    value = json.loads(path.read_text(encoding="utf-8"))
    value["subjects"] = value["subjects"][:-1]
    write_json(path, value)


def mutate_wrong_attested_digest(dist: Path) -> None:
    path = dist / "attestation-verification.json"
    value = json.loads(path.read_text(encoding="utf-8"))
    value["subjects"][0]["verification"][0]["verificationResult"]["statement"]["subject"][0]["digest"]["sha256"] = "0" * 64
    write_json(path, value)


def mutate_empty_bundle(dist: Path) -> None:
    (dist / "provenance.bundle.jsonl").write_text("", encoding="utf-8")


def mutate_unlocked_dependencies(dist: Path) -> None:
    path = dist / "supply-chain-source.json"
    value = json.loads(path.read_text(encoding="utf-8"))
    value["checks"]["dependencies_locked"] = {
        "ok": False,
        "findings": [{"reason": "synthetic floating dependency"}],
    }
    write_json(path, value)
    refresh_checksum_and_verification(dist, "supply-chain-source.json")


def refresh_checksum_and_verification(dist: Path, name: str) -> None:
    """Keep all other evidence coherent so each negative isolates one invariant."""
    target = dist / name
    lines = []
    for line in (dist / "SHA256SUMS").read_text(encoding="utf-8").splitlines():
        digest, filename = line.split(None, 1)
        if Path(filename.strip()).name == name:
            digest = sha256(target)
        lines.append(f"{digest}  {filename.strip()}")
    (dist / "SHA256SUMS").write_text("\n".join(lines) + "\n", encoding="utf-8")

    verification_path = dist / "attestation-verification.json"
    value = json.loads(verification_path.read_text(encoding="utf-8"))
    for subject in value["subjects"]:
        subject_name = subject["name"]
        subject_path = dist / subject_name
        if subject_name == name or subject_name == "SHA256SUMS":
            digest = sha256(subject_path)
            subject["sha256"] = digest
            subject["verification"] = [
                {
                    "verificationResult": {
                        "statement": {
                            "subject": [{"name": subject_name, "digest": {"sha256": digest}}]
                        }
                    }
                }
            ]
    write_json(verification_path, value)


def expect_failure(label: str, mutator: Callable[[Path], None]) -> None:
    with tempfile.TemporaryDirectory(prefix="rp-supply-selftest-") as raw:
        dist = make_fixture(Path(raw))
        mutator(dist)
        result = run(dist)
        if result.returncode == 0:
            raise AssertionError(f"{label}: verifier accepted manufactured evidence\n{result.stdout}")
        print("PASS reject", label)


def main() -> int:
    with tempfile.TemporaryDirectory(prefix="rp-supply-selftest-") as raw:
        dist = make_fixture(Path(raw))
        result = run(dist)
        if result.returncode != 0:
            print(result.stdout)
            raise AssertionError("valid synthetic structural fixture did not pass")
        receipt = json.loads((dist / "supply-chain-release.json").read_text(encoding="utf-8"))
        if receipt.get("ok") is not True or receipt.get("commit_sha") != COMMIT:
            raise AssertionError("positive receipt is not exact-SHA green")
        print("PASS positive structural fixture")

    cases: list[tuple[str, Callable[[Path], None]]] = [
        ("bad checksum", mutate_bad_checksum),
        ("cross-SHA SBOM", mutate_cross_sha_sbom),
        ("critical vulnerability", mutate_critical_vulnerability),
        ("missing attested subject", mutate_missing_attested_subject),
        ("wrong attested digest", mutate_wrong_attested_digest),
        ("empty provenance bundle", mutate_empty_bundle),
        ("unlocked dependency audit", mutate_unlocked_dependencies),
    ]
    for label, mutator in cases:
        expect_failure(label, mutator)
    print(f"SUPPLY-CHAIN SELFTEST PASS cases={1 + len(cases)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
