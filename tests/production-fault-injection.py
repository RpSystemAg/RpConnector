#!/usr/bin/env python3
"""Execute destructive production-readiness fault scenarios in isolated staging.

Each scenario is fail-closed and ordered:
  inject -> exercise(expected safe product failure) -> independent oracle
  -> recover -> post_recovery
No shell is used. A scenario cannot pass if cleanup/recovery fails even when the
product correctly detected the original fault.

A bare invocation runs a deterministic self-test of the harness. It exercises
real subprocess success/failure and validates every required phase contract, but
never claims destructive production-staging evidence.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "evidence" / "production"
REQUIRED = [
    "database_disconnect",
    "database_restart",
    "remote_timeout",
    "remote_rate_limit",
    "remote_5xx",
    "chrome_process_kill",
    "extension_worker_suspend",
    "network_interruption",
    "process_kill_between_effect_and_checkpoint",
]
PHASES = ["inject", "exercise", "oracle", "recover", "post_recovery"]


def now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def git_head() -> str:
    explicit = os.environ.get("GITHUB_SHA", "").strip()
    if explicit:
        return explicit
    try:
        return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
    except Exception:
        return ""


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def validate_command(value: Any, scenario: str, phase: str) -> list[str]:
    errors: list[str] = []
    if not isinstance(value, list) or not value:
        return [f"{scenario}.{phase}: command must be a non-empty argv array"]
    for index, part in enumerate(value):
        if not isinstance(part, (str, int, float)) or str(part) == "":
            errors.append(f"{scenario}.{phase}[{index}]: invalid argv element")
    joined = " ".join(str(x) for x in value)
    if "REPLACE" in joined:
        errors.append(f"{scenario}.{phase}: example placeholder command is forbidden")
    return errors


def run(command: list[Any], timeout: int, env: dict[str, str]) -> tuple[bool, dict[str, Any]]:
    argv = [str(x) for x in command]
    start = time.monotonic()
    try:
        proc = subprocess.run(
            argv,
            cwd=ROOT,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
            shell=False,
        )
        return proc.returncode == 0, {
            "argv": argv,
            "returncode": proc.returncode,
            "duration_seconds": round(time.monotonic() - start, 3),
            "output_tail": proc.stdout[-6000:],
        }
    except subprocess.TimeoutExpired as exc:
        output = exc.stdout if isinstance(exc.stdout, str) else ""
        return False, {
            "argv": argv,
            "timeout": True,
            "duration_seconds": round(time.monotonic() - start, 3),
            "output_tail": output[-6000:],
        }
    except Exception as exc:
        return False, {"argv": argv, "error": f"{type(exc).__name__}: {exc}"}


def self_test() -> int:
    failures: list[str] = []
    if len(REQUIRED) != len(set(REQUIRED)) or not REQUIRED:
        failures.append("required scenario inventory is empty or duplicated")
    if PHASES != ["inject", "exercise", "oracle", "recover", "post_recovery"]:
        failures.append(f"fault phase order changed unexpectedly: {PHASES}")

    valid = [sys.executable, "-c", "import sys; sys.exit(0)"]
    for scenario in REQUIRED:
        for phase in PHASES:
            errors = validate_command(valid, scenario, phase)
            if errors:
                failures.append(f"valid command rejected for {scenario}.{phase}: {errors}")
    if not validate_command([], "self_test", "inject"):
        failures.append("empty command was accepted")
    if not validate_command(["REPLACE_ME"], "self_test", "oracle"):
        failures.append("placeholder command was accepted")

    env = os.environ.copy()
    env["RP_FAULT_SCENARIO"] = "self_test"
    env["RP_FAULT_PHASE"] = "exercise"
    ok, detail = run([sys.executable, "-c", "print('fault-self-test-ok')"], 10, env)
    if not ok or detail.get("returncode") != 0 or "fault-self-test-ok" not in detail.get("output_tail", ""):
        failures.append(f"successful subprocess was not observed: {detail}")
    bad_ok, bad_detail = run([sys.executable, "-c", "import sys; sys.exit(9)"], 10, env)
    if bad_ok or bad_detail.get("returncode") != 9:
        failures.append(f"failing subprocess was not observed: {bad_detail}")

    with tempfile.TemporaryDirectory(prefix="rp-fault-self-test-") as tmp_name:
        probe = Path(tmp_name) / "evidence.json"
        probe.write_text(json.dumps({"required": REQUIRED, "phases": PHASES}, sort_keys=True) + "\n", encoding="utf-8")
        if len(digest(probe)) != 64:
            failures.append("evidence SHA-256 digest is invalid")

    print("PRODUCTION FAULT INJECTION HARNESS SELF-TEST")
    if failures:
        for failure in failures:
            print("FAIL", failure)
        return 1
    print("PASS required_scenario_inventory")
    print("PASS five_phase_contract")
    print("PASS command_validation")
    print("PASS subprocess_success_failure_observation")
    print("PASS evidence_digest")
    print("SELF_TEST production_fault_evidence_claimed=false")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--profile", default="")
    parser.add_argument("--commit", default="")
    parser.add_argument("--timeout-seconds", type=int, default=300)
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()

    if len(sys.argv) == 1:
        return self_test()
    if not args.profile:
        raise SystemExit("--profile is required for production fault injection")

    profile_path = Path(args.profile)
    if not profile_path.is_absolute():
        profile_path = ROOT / profile_path
    profile = json.loads(profile_path.read_text(encoding="utf-8"))
    expected = args.commit.strip() or git_head()
    actual = git_head()
    errors: list[str] = []
    if profile.get("real") is not True:
        errors.append("fault profile must assert real=true")
    if not str(profile.get("environment_id", "")).strip():
        errors.append("fault profile requires environment_id")
    if not actual or actual != expected:
        errors.append(f"checkout commit {actual!r} != expected {expected!r}")
    scenarios = profile.get("scenarios")
    if not isinstance(scenarios, dict):
        errors.append("profile.scenarios must be an object")
        scenarios = {}
    missing = [scenario for scenario in REQUIRED if scenario not in scenarios]
    extra = [scenario for scenario in scenarios if scenario not in REQUIRED]
    if missing:
        errors.append("missing required scenarios: " + ", ".join(missing))
    if extra:
        errors.append("unknown scenarios: " + ", ".join(extra))
    for scenario in REQUIRED:
        definition = scenarios.get(scenario, {})
        if not isinstance(definition, dict):
            errors.append(f"{scenario}: definition must be an object")
            continue
        for phase in PHASES:
            errors.extend(validate_command(definition.get(phase), scenario, phase))

    OUT.mkdir(parents=True, exist_ok=True)
    events = OUT / "fault-injection-events.ndjson"
    events.write_text("", encoding="utf-8")
    started = now()
    env = os.environ.copy()
    env["RP_RELEASE_COMMIT"] = actual
    env["RP_FAULT_ENVIRONMENT_ID"] = str(profile.get("environment_id", ""))
    checks: list[dict[str, Any]] = []

    if not errors:
        for scenario in REQUIRED:
            definition = scenarios[scenario]
            phase_results: dict[str, Any] = {}
            scenario_ok = True
            injected = False
            try:
                for phase in ("inject", "exercise", "oracle"):
                    phase_env = env.copy()
                    phase_env["RP_FAULT_SCENARIO"] = scenario
                    phase_env["RP_FAULT_PHASE"] = phase
                    ok, detail = run(definition[phase], args.timeout_seconds, phase_env)
                    phase_results[phase] = {"ok": ok, **detail}
                    with events.open("a", encoding="utf-8") as handle:
                        handle.write(json.dumps({"ts": now(), "scenario": scenario, "phase": phase, "ok": ok, "detail": detail}, sort_keys=True) + "\n")
                    if phase == "inject" and ok:
                        injected = True
                    if not ok:
                        scenario_ok = False
                        break
            finally:
                for phase in ("recover", "post_recovery"):
                    phase_env = env.copy()
                    phase_env["RP_FAULT_SCENARIO"] = scenario
                    phase_env["RP_FAULT_PHASE"] = phase
                    ok, detail = run(definition[phase], args.timeout_seconds, phase_env)
                    phase_results[phase] = {"ok": ok, **detail}
                    with events.open("a", encoding="utf-8") as handle:
                        handle.write(json.dumps({"ts": now(), "scenario": scenario, "phase": phase, "ok": ok, "detail": detail}, sort_keys=True) + "\n")
                    if not ok:
                        scenario_ok = False
            scenario_ok = scenario_ok and injected and all(phase_results.get(phase, {}).get("ok") is True for phase in PHASES)
            checks.append({
                "id": scenario,
                "ok": scenario_ok,
                "evidence": {
                    "environment_id": profile.get("environment_id"),
                    "phases": phase_results,
                    "independent_oracle_phase": "oracle",
                },
            })
            if not scenario_ok and args.strict:
                break

    executed_ids = {check["id"] for check in checks}
    for scenario in REQUIRED:
        if scenario not in executed_ids:
            checks.append({"id": scenario, "ok": False, "evidence": {"not_executed": True, "reason": "prior fault scenario failed or profile validation failed"}})

    details = {
        "schema_version": 1,
        "commit_sha": actual,
        "profile": str(profile_path),
        "profile_sha256": digest(profile_path),
        "validation_errors": errors,
        "checks": checks,
    }
    details_path = OUT / "fault-injection-details.json"
    details_path.write_text(json.dumps(details, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    receipt = {
        "schema_version": 1,
        "gate_id": "fault_injection",
        "commit_sha": actual,
        "ok": not errors and all(check["ok"] is True for check in checks) and len(checks) == len(REQUIRED),
        "started_at": started,
        "finished_at": now(),
        "environment": {
            "real": profile.get("real") is True,
            "class": "isolated-production-fault-staging",
            "environment_id": profile.get("environment_id"),
        },
        "checks": checks,
        "artifacts": [
            {"path": str(events.relative_to(ROOT)), "sha256": digest(events)},
            {"path": str(details_path.relative_to(ROOT)), "sha256": digest(details_path)},
        ],
        "waivers": [],
        "skipped": [],
        "errors": errors,
    }
    receipt_path = OUT / "fault-injection.json"
    receipt_path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print("PRODUCTION FAULT INJECTION")
    for error in errors:
        print("FAIL", error)
    for check in checks:
        print(f"{'PASS' if check['ok'] else 'FAIL'} {check['id']}")
    print(f"receipt={receipt_path.relative_to(ROOT)} ok={str(receipt['ok']).lower()}")
    return 0 if receipt["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
