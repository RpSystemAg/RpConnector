#!/usr/bin/env python3
"""Execute one real-environment production gate from an argv-only scenario profile.

Every policy check is executed as setup -> exercise -> independent oracle -> cleanup.
Cleanup always runs after setup is attempted. The exercise and oracle commands must
be distinct. The harness never invokes a shell and never accepts missing/skipped
checks, placeholder commands or cross-commit execution.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
POLICY = ROOT / "quality" / "production-readiness-policy.json"
OUT = ROOT / "evidence" / "production"
ALLOWED_GATES = {
    "auth_security",
    "browser_isolation",
    "transaction_integrity",
    "concurrency_recovery",
    "load_backpressure",
    "remote_live_integration",
    "observability_operability",
    "compatibility_degradation",
}
PHASES = ("setup", "exercise", "oracle", "cleanup")


def now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def git_head() -> str:
    explicit = os.environ.get("RP_RELEASE_COMMIT", "").strip()
    if explicit:
        # RP_RELEASE_COMMIT is a claim from the caller, not source truth; actual
        # checkout is still independently resolved below by git.
        pass
    try:
        return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True, stderr=subprocess.DEVNULL).strip()
    except Exception:
        return ""


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def validate_argv(value: Any, check_id: str, phase: str, required: bool) -> list[str]:
    if value in (None, []) and not required:
        return []
    if not isinstance(value, list) or not value:
        return [f"{check_id}.{phase}: non-empty argv array required"]
    errors: list[str] = []
    joined = " ".join(str(part) for part in value)
    if "REPLACE" in joined or "TODO" in joined or "PLACEHOLDER" in joined.upper():
        errors.append(f"{check_id}.{phase}: placeholder argv is forbidden")
    for index, part in enumerate(value):
        if not isinstance(part, (str, int, float)) or str(part) == "":
            errors.append(f"{check_id}.{phase}[{index}]: invalid argv element")
    return errors


def run(argv: list[Any], timeout: int, env: dict[str, str]) -> tuple[bool, dict[str, Any]]:
    command = [str(part) for part in argv]
    started = time.monotonic()
    try:
        proc = subprocess.run(
            command,
            cwd=ROOT,
            env=env,
            shell=False,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
        )
        return proc.returncode == 0, {
            "argv": command,
            "returncode": proc.returncode,
            "duration_seconds": round(time.monotonic() - started, 3),
            "output_tail": proc.stdout[-8000:],
        }
    except subprocess.TimeoutExpired as exc:
        output = exc.stdout if isinstance(exc.stdout, str) else ""
        return False, {
            "argv": command,
            "timeout": True,
            "duration_seconds": round(time.monotonic() - started, 3),
            "output_tail": output[-8000:],
        }
    except Exception as exc:
        return False, {"argv": command, "error": f"{type(exc).__name__}: {exc}"}


def append_event(path: Path, event: dict[str, Any]) -> None:
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(event, sort_keys=True) + "\n")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--profile", required=True)
    parser.add_argument("--gate", required=True, choices=sorted(ALLOWED_GATES))
    parser.add_argument("--commit", default=os.environ.get("GITHUB_SHA", ""))
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()

    profile_path = Path(args.profile)
    if not profile_path.is_absolute():
        profile_path = ROOT / profile_path
    profile = json.loads(profile_path.read_text(encoding="utf-8"))
    policy = json.loads(POLICY.read_text(encoding="utf-8"))
    gate_policy = policy.get("gates", {}).get(args.gate)
    if not isinstance(gate_policy, dict):
        raise SystemExit(f"policy gate {args.gate} missing")
    required_checks = list(gate_policy.get("required_checks", []))
    expected_commit = args.commit.strip()
    actual_commit = git_head()
    gate_profile = profile.get("gates", {}).get(args.gate) if isinstance(profile.get("gates"), dict) else None

    errors: list[str] = []
    if profile.get("real") is not True:
        errors.append("profile must assert real=true")
    if not str(profile.get("environment_id", "")).strip():
        errors.append("profile.environment_id is required")
    if not expected_commit or len(expected_commit) != 40:
        errors.append("exact 40-hex --commit is required")
    if actual_commit != expected_commit:
        errors.append(f"checkout commit {actual_commit!r} != expected release commit {expected_commit!r}")
    if not isinstance(gate_profile, dict):
        errors.append(f"profile.gates.{args.gate} is missing")
        gate_profile = {}
    configured_checks = gate_profile.get("checks") if isinstance(gate_profile.get("checks"), dict) else {}
    missing_checks = sorted(set(required_checks) - set(configured_checks))
    extra_checks = sorted(set(configured_checks) - set(required_checks))
    if missing_checks:
        errors.append("missing required checks: " + ", ".join(missing_checks))
    if extra_checks:
        errors.append("unknown checks: " + ", ".join(extra_checks))

    for check_id in required_checks:
        definition = configured_checks.get(check_id)
        if not isinstance(definition, dict):
            continue
        errors.extend(validate_argv(definition.get("setup"), check_id, "setup", False))
        errors.extend(validate_argv(definition.get("exercise"), check_id, "exercise", True))
        errors.extend(validate_argv(definition.get("oracle"), check_id, "oracle", True))
        errors.extend(validate_argv(definition.get("cleanup"), check_id, "cleanup", True))
        if isinstance(definition.get("exercise"), list) and definition.get("exercise") == definition.get("oracle"):
            errors.append(f"{check_id}: exercise and independent oracle commands must differ")
        timeout = definition.get("timeout_seconds", gate_profile.get("timeout_seconds", 300))
        if not isinstance(timeout, int) or timeout < 1 or timeout > 3600:
            errors.append(f"{check_id}: timeout_seconds must be integer 1..3600")

    OUT.mkdir(parents=True, exist_ok=True)
    events_path = OUT / f"{args.gate.replace('_', '-')}-scenario-events.ndjson"
    details_path = OUT / f"{args.gate.replace('_', '-')}-scenario-details.json"
    events_path.write_text("", encoding="utf-8")
    started_at = now()
    checks: list[dict[str, Any]] = []

    if not errors:
        for check_id in required_checks:
            definition = configured_checks[check_id]
            timeout = int(definition.get("timeout_seconds", gate_profile.get("timeout_seconds", 300)))
            env = os.environ.copy()
            env["RP_RELEASE_COMMIT"] = actual_commit
            env["RP_PRODUCTION_GATE"] = args.gate
            env["RP_PRODUCTION_CHECK"] = check_id
            env["RP_PRODUCTION_ENVIRONMENT_ID"] = str(profile.get("environment_id", ""))
            phases: dict[str, Any] = {}
            check_ok = True
            setup_attempted = False
            try:
                setup = definition.get("setup")
                if setup:
                    setup_attempted = True
                    ok, detail = run(setup, timeout, {**env, "RP_PRODUCTION_PHASE": "setup"})
                    phases["setup"] = {"ok": ok, **detail}
                    append_event(events_path, {"ts": now(), "gate": args.gate, "check": check_id, "phase": "setup", "ok": ok, "detail": detail})
                    if not ok:
                        check_ok = False
                if check_ok:
                    for phase in ("exercise", "oracle"):
                        ok, detail = run(definition[phase], timeout, {**env, "RP_PRODUCTION_PHASE": phase})
                        phases[phase] = {"ok": ok, **detail}
                        append_event(events_path, {"ts": now(), "gate": args.gate, "check": check_id, "phase": phase, "ok": ok, "detail": detail})
                        if not ok:
                            check_ok = False
                            break
            finally:
                # Cleanup is mandatory even after partial setup/exercise failure.
                cleanup_ok, cleanup_detail = run(definition["cleanup"], timeout, {**env, "RP_PRODUCTION_PHASE": "cleanup"})
                phases["cleanup"] = {"ok": cleanup_ok, **cleanup_detail}
                append_event(events_path, {"ts": now(), "gate": args.gate, "check": check_id, "phase": "cleanup", "ok": cleanup_ok, "detail": cleanup_detail})
                check_ok = check_ok and cleanup_ok
            check_ok = check_ok and phases.get("exercise", {}).get("ok") is True and phases.get("oracle", {}).get("ok") is True
            checks.append({
                "id": check_id,
                "ok": check_ok,
                "evidence": {
                    "environment_id": profile.get("environment_id"),
                    "oracle_is_separate_process": True,
                    "setup_attempted": setup_attempted,
                    "phases": phases,
                },
            })
            if args.strict and not check_ok:
                # Do not continue mutating a real environment after a check whose
                # cleanup/oracle failed. Remaining mandatory checks become failures.
                break

    executed = {check["id"] for check in checks}
    for check_id in required_checks:
        if check_id not in executed:
            checks.append({"id": check_id, "ok": False, "evidence": {"not_executed": True, "reason": "profile validation or prior real-environment check failed"}})

    details = {
        "schema_version": 1,
        "gate_id": args.gate,
        "commit_sha": actual_commit,
        "profile_path": str(profile_path),
        "profile_sha256": sha256(profile_path),
        "environment_id": profile.get("environment_id"),
        "errors": errors,
        "checks": checks,
    }
    details_path.write_text(json.dumps(details, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    gate_ok = not errors and len(checks) == len(required_checks) and all(check.get("ok") is True for check in checks)
    receipt_path = OUT / str(gate_policy.get("receipt", f"{args.gate.replace('_', '-')}.json"))
    receipt = {
        "schema_version": 1,
        "gate_id": args.gate,
        "commit_sha": actual_commit,
        "ok": gate_ok,
        "started_at": started_at,
        "finished_at": now(),
        "environment": {
            "real": profile.get("real") is True,
            "class": str(gate_profile.get("environment_class", "isolated-production-staging")),
            "environment_id": profile.get("environment_id"),
        },
        "checks": checks,
        "artifacts": [
            {"path": str(events_path.relative_to(ROOT)), "sha256": sha256(events_path)},
            {"path": str(details_path.relative_to(ROOT)), "sha256": sha256(details_path)},
        ],
        "waivers": [],
        "skipped": [],
        "errors": errors,
    }
    receipt_path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print(f"PRODUCTION SCENARIO GATE {args.gate}")
    for error in errors:
        print("FAIL", error)
    for check in checks:
        print(f"{'PASS' if check['ok'] else 'FAIL'} {check['id']}")
    print(f"receipt={receipt_path.relative_to(ROOT)} ok={str(gate_ok).lower()}")
    return 1 if args.strict and not gate_ok else 0


if __name__ == "__main__":
    raise SystemExit(main())
