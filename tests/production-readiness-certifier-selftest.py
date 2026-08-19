#!/usr/bin/env python3
"""Adversarial tests for production-readiness-certifier.py.

The certifier is security/release-critical code. This suite proves that common
ways of manufacturing a false green verdict remain fail-closed.
"""
from __future__ import annotations

import copy
import hashlib
import json
import subprocess
import sys
import tempfile
from datetime import datetime, timedelta, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CERTIFIER = ROOT / "tests" / "production-readiness-certifier.py"
COMMIT = "a" * 40


def z(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def write(path: Path, value) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def invoke(policy: Path, evidence: Path, output: Path) -> int:
    proc = subprocess.run(
        [
            sys.executable,
            str(CERTIFIER),
            "--strict",
            "--policy",
            str(policy),
            "--evidence-dir",
            str(evidence),
            "--commit",
            COMMIT,
            "--output",
            str(output),
        ],
        cwd=ROOT,
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
    )
    return proc.returncode


def main() -> int:
    now = datetime.now(timezone.utc)
    with tempfile.TemporaryDirectory(prefix="rp-production-certifier-") as tmp:
        base = Path(tmp)
        evidence = base / "evidence"
        evidence.mkdir()
        artifact = base / "proof.txt"
        artifact.write_text("independent observed proof\n", encoding="utf-8")
        artifact_sha = hashlib.sha256(artifact.read_bytes()).hexdigest()
        policy_path = base / "policy.json"
        output = base / "verdict.json"

        policy = {
            "schema_version": 1,
            "claim": "production_ready",
            "fail_closed": True,
            "require_exact_commit": True,
            "allow_waivers": False,
            "receipt_schema": {
                "required": [
                    "schema_version", "gate_id", "commit_sha", "ok", "started_at",
                    "finished_at", "environment", "checks", "artifacts", "waivers", "skipped"
                ],
                "artifact_sha256_required": True,
                "forbid_skipped": True,
                "forbid_waivers": True,
                "forbid_degraded": True
            },
            "gates": {
                "gate": {
                    "mandatory": True,
                    "receipt": "gate.json",
                    "real_environment": True,
                    "max_age_hours": 24,
                    "min_duration_seconds": 60,
                    "required_checks": ["proof"]
                }
            }
        }
        write(policy_path, policy)

        valid = {
            "schema_version": 1,
            "gate_id": "gate",
            "commit_sha": COMMIT,
            "ok": True,
            "started_at": z(now - timedelta(minutes=5)),
            "finished_at": z(now - timedelta(minutes=1)),
            "environment": {"real": True, "class": "selftest-real"},
            "checks": [{"id": "proof", "ok": True, "evidence": "independent observation"}],
            "artifacts": [{"path": str(artifact), "sha256": artifact_sha}],
            "waivers": [],
            "skipped": []
        }

        cases = []

        def expect(name: str, mutator, expected_rc: int) -> None:
            receipt = copy.deepcopy(valid)
            mutator(receipt)
            write(evidence / "gate.json", receipt)
            rc = invoke(policy_path, evidence, output)
            cases.append((name, rc == expected_rc, rc, expected_rc))

        expect("valid exact evidence passes", lambda r: None, 0)
        expect("wrong commit fails", lambda r: r.__setitem__("commit_sha", "b" * 40), 1)
        expect("receipt ok=false fails", lambda r: r.__setitem__("ok", False), 1)
        expect("real environment false fails", lambda r: r["environment"].__setitem__("real", False), 1)
        expect("waiver fails", lambda r: r.__setitem__("waivers", ["temporary exception"]), 1)
        expect("skip fails", lambda r: r.__setitem__("skipped", ["proof"]), 1)
        expect("degraded fails", lambda r: r.__setitem__("degraded", True), 1)
        expect("missing check fails", lambda r: r.__setitem__("checks", []), 1)
        expect("check without evidence fails", lambda r: r.__setitem__("checks", [{"id": "proof", "ok": True}]), 1)
        expect("failed required check fails", lambda r: r["checks"][0].__setitem__("ok", False), 1)
        expect("bad artifact digest fails", lambda r: r["artifacts"][0].__setitem__("sha256", "0" * 64), 1)
        expect("short duration fails", lambda r: r.__setitem__("started_at", z(now - timedelta(seconds=30))), 1)
        expect("stale receipt fails", lambda r: (r.__setitem__("started_at", z(now - timedelta(hours=30))), r.__setitem__("finished_at", z(now - timedelta(hours=29)))), 1)
        expect("duplicate check id fails", lambda r: r.__setitem__("checks", r["checks"] + [copy.deepcopy(r["checks"][0])]), 1)
        expect("future evidence fails", lambda r: r.__setitem__("finished_at", z(now + timedelta(minutes=10))), 1)

        missing_path = evidence / "gate.json"
        if missing_path.exists():
            missing_path.unlink()
        rc = invoke(policy_path, evidence, output)
        cases.append(("missing receipt fails", rc == 1, rc, 1))

        failures = [case for case in cases if not case[1]]
        print("PRODUCTION CERTIFIER SELFTEST")
        for name, ok, rc, expected in cases:
            print(f"{'PASS' if ok else 'FAIL'} {name}: rc={rc} expected={expected}")
        print(f"cases={len(cases)} failures={len(failures)}")
        return 1 if failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
