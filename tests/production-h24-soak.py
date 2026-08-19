#!/usr/bin/env python3
"""Run a real exact-SHA 24-hour production soak and emit certifiable evidence.

The harness refuses shortened production runs. It executes only bounded argv or
HTTP probes (never shell=True), records every observation, requires a real
operator-supplied restart hook, and requires independent lease/queue/memory
oracles before h24_soak can pass.

A bare invocation runs a deterministic self-test of this executable harness. It
never emits or claims H24 production evidence. Supplying production inputs or
RP_H24_REAL_ENVIRONMENT=1 enters the real H24 path, whose 86,400-second minimum
and real-environment requirements remain unchanged.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import signal
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_PROFILE = ROOT / "quality" / "h24-soak-profile.json"
OUT = ROOT / "evidence" / "production"
REQUIRED_HOOKS = ("restart", "lease_oracle", "queue_oracle", "memory_oracle")


def iso_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def git_head() -> str:
    explicit = os.environ.get("GITHUB_SHA", "").strip()
    if explicit:
        return explicit
    try:
        return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
    except Exception:
        return ""


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def run_argv(command: list[str], timeout: int) -> tuple[bool, dict[str, Any]]:
    started = time.monotonic()
    try:
        proc = subprocess.run(
            command,
            cwd=ROOT,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.STDOUT,
            timeout=timeout,
        )
        return proc.returncode == 0, {
            "command": command,
            "returncode": proc.returncode,
            "duration_seconds": round(time.monotonic() - started, 3),
            "output_tail": proc.stdout[-4000:],
        }
    except subprocess.TimeoutExpired as exc:
        output = exc.stdout if isinstance(exc.stdout, str) else ""
        return False, {
            "command": command,
            "duration_seconds": round(time.monotonic() - started, 3),
            "timeout": True,
            "output_tail": output[-4000:],
        }
    except Exception as exc:
        return False, {"command": command, "error": f"{type(exc).__name__}: {exc}"}


def run_http(probe: dict[str, Any], timeout: int) -> tuple[bool, dict[str, Any]]:
    base = os.environ.get(str(probe.get("url_env", "")), "").strip()
    if not base:
        return False, {"error": f"missing URL environment variable {probe.get('url_env')}"}
    path = str(probe.get("path", ""))
    url = urllib.parse.urljoin(base.rstrip("/") + "/", path.lstrip("/"))
    started = time.monotonic()
    request = urllib.request.Request(url, method="GET", headers={"User-Agent": "RPConnector-Production-H24/1"})
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            status = int(response.status)
            body = response.read(4096)
        allowed = [int(v) for v in probe.get("expected_status", [200])]
        return status in allowed, {
            "url": url,
            "status": status,
            "allowed_status": allowed,
            "duration_seconds": round(time.monotonic() - started, 3),
            "body_bytes_sampled": len(body),
        }
    except urllib.error.HTTPError as exc:
        return False, {"url": url, "status": int(exc.code), "error": str(exc)}
    except Exception as exc:
        return False, {"url": url, "error": f"{type(exc).__name__}: {exc}"}


def execute_probe(probe: dict[str, Any], timeout: int) -> tuple[bool, dict[str, Any]]:
    kind = probe.get("kind")
    if kind == "argv":
        command = probe.get("command")
        if not isinstance(command, list) or not command:
            return False, {"error": "argv probe requires non-empty command array"}
        return run_argv([str(part) for part in command], timeout)
    if kind == "http_get":
        return run_http(probe, timeout)
    return False, {"error": f"unsupported probe kind {kind!r}"}


def load_environment_profile(path_value: str) -> tuple[dict[str, Any], list[str], Path | None]:
    errors: list[str] = []
    if not path_value:
        return {}, ["environment profile is mandatory for production H24"], None
    path = Path(path_value)
    if not path.is_absolute():
        path = ROOT / path
    if not path.is_file():
        return {}, [f"environment profile not found: {path}"], path
    try:
        profile = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        return {}, [f"environment profile is invalid JSON: {exc}"], path
    if profile.get("real") is not True:
        errors.append("environment profile must assert real=true")
    hooks = profile.get("hooks")
    if not isinstance(hooks, dict):
        errors.append("environment profile hooks must be an object")
        hooks = {}
    for hook_id in REQUIRED_HOOKS:
        hook = hooks.get(hook_id)
        if not isinstance(hook, dict):
            errors.append(f"missing required environment hook {hook_id}")
            continue
        if hook.get("kind") != "argv" or not isinstance(hook.get("command"), list) or not hook.get("command"):
            errors.append(f"environment hook {hook_id} must be kind=argv with non-empty command array")
    return profile, errors, path


def append_event(path: Path, event: dict[str, Any]) -> None:
    with path.open("a", encoding="utf-8") as handle:
        handle.write(json.dumps(event, sort_keys=True) + "\n")


def self_test() -> int:
    """Exercise the executable harness without fabricating production evidence."""
    failures: list[str] = []

    try:
        profile = json.loads(DEFAULT_PROFILE.read_text(encoding="utf-8"))
        if int(profile.get("minimum_duration_seconds", 0)) < 86400:
            failures.append("default profile no longer enforces an H24 minimum")
    except Exception as exc:
        failures.append(f"default profile could not be loaded: {type(exc).__name__}: {exc}")

    ok, detail = run_argv([sys.executable, "-c", "print('h24-self-test-ok')"], 10)
    if not ok or detail.get("returncode") != 0 or "h24-self-test-ok" not in detail.get("output_tail", ""):
        failures.append(f"successful argv probe failed: {detail}")

    bad_ok, bad_detail = run_argv([sys.executable, "-c", "import sys; sys.exit(7)"], 10)
    if bad_ok or bad_detail.get("returncode") != 7:
        failures.append(f"failing argv probe was not observed: {bad_detail}")

    missing_http_ok, missing_http = run_http({"url_env": "RP_H24_SELF_TEST_URL_MUST_NOT_EXIST", "path": "/"}, 1)
    if missing_http_ok or "missing URL environment variable" not in missing_http.get("error", ""):
        failures.append(f"missing HTTP environment was not rejected: {missing_http}")

    unsupported_ok, unsupported = execute_probe({"kind": "unsupported"}, 1)
    if unsupported_ok or "unsupported probe kind" not in unsupported.get("error", ""):
        failures.append(f"unsupported probe kind was not rejected: {unsupported}")

    with tempfile.TemporaryDirectory(prefix="rp-h24-self-test-") as tmp_name:
        temp = Path(tmp_name)
        profile_path = temp / "environment.json"
        command = [sys.executable, "-c", "import sys; sys.exit(0)"]
        profile_path.write_text(
            json.dumps(
                {
                    "real": True,
                    "environment_id": "h24-harness-self-test",
                    "hooks": {hook_id: {"kind": "argv", "command": command} for hook_id in REQUIRED_HOOKS},
                },
                sort_keys=True,
            )
            + "\n",
            encoding="utf-8",
        )
        loaded, errors, resolved = load_environment_profile(str(profile_path))
        if errors or resolved != profile_path or loaded.get("real") is not True:
            failures.append(f"valid environment profile was rejected: errors={errors} resolved={resolved}")

        for hook_id in REQUIRED_HOOKS:
            hook_ok, hook_detail = execute_probe({"id": hook_id, **loaded["hooks"][hook_id]}, 10)
            if not hook_ok:
                failures.append(f"hook {hook_id} did not execute: {hook_detail}")

        events = temp / "events.ndjson"
        append_event(events, {"event": "self_test", "ok": True})
        try:
            event = json.loads(events.read_text(encoding="utf-8").strip())
            if event != {"event": "self_test", "ok": True}:
                failures.append(f"event persistence mismatch: {event}")
            if len(sha256(events)) != 64:
                failures.append("event SHA-256 digest length is invalid")
        except Exception as exc:
            failures.append(f"event evidence could not be read: {type(exc).__name__}: {exc}")

    print("PRODUCTION H24 HARNESS SELF-TEST")
    if failures:
        for item in failures:
            print(f"FAIL {item}")
        return 1
    print("PASS default_profile_minimum_24h")
    print("PASS argv_success_and_failure_observed")
    print("PASS http_missing_environment_rejected")
    print("PASS environment_hooks_execute")
    print("PASS event_evidence_and_hash")
    print("SELF_TEST production_evidence_claimed=false")
    return 0


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--profile", default=str(DEFAULT_PROFILE))
    parser.add_argument("--environment-profile", default=os.environ.get("RP_H24_ENVIRONMENT_PROFILE", ""))
    parser.add_argument("--duration-seconds", type=int, default=0)
    parser.add_argument("--interval-seconds", type=int, default=0)
    parser.add_argument("--commit", default="")
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()

    production_requested = bool(
        args.environment_profile
        or args.duration_seconds
        or args.interval_seconds
        or args.commit
        or args.strict
        or os.environ.get("RP_H24_REAL_ENVIRONMENT", "").strip() == "1"
    )
    if not production_requested:
        return self_test()

    profile_path = Path(args.profile)
    if not profile_path.is_absolute():
        profile_path = ROOT / profile_path
    profile = json.loads(profile_path.read_text(encoding="utf-8"))
    environment_profile, environment_profile_errors, environment_profile_path = load_environment_profile(args.environment_profile)

    minimum_duration = int(profile.get("minimum_duration_seconds", 86400))
    requested_duration = int(args.duration_seconds or minimum_duration)
    interval = int(args.interval_seconds or profile.get("probe_interval_seconds", 300))
    timeout = int(profile.get("max_probe_runtime_seconds", 120))
    max_consecutive_failures = int(profile.get("max_consecutive_failures", 2))
    expected_commit = args.commit.strip() or git_head()
    actual_commit = git_head()

    env_errors = list(environment_profile_errors)
    for name in profile.get("required_environment_variables", []):
        if not os.environ.get(str(name), "").strip():
            env_errors.append(f"missing required environment variable {name}")
    if os.environ.get("RP_H24_REAL_ENVIRONMENT", "").strip() != "1":
        env_errors.append("RP_H24_REAL_ENVIRONMENT must equal 1")
    if requested_duration < minimum_duration:
        env_errors.append(f"requested duration {requested_duration}s is below production minimum {minimum_duration}s")
    if not actual_commit or actual_commit != expected_commit:
        env_errors.append(f"checkout commit {actual_commit!r} does not match expected commit {expected_commit!r}")

    hooks = environment_profile.get("hooks", {}) if isinstance(environment_profile.get("hooks"), dict) else {}
    regular_probes = list(profile.get("probes", []))
    oracle_probes = []
    for hook_id in ("lease_oracle", "queue_oracle", "memory_oracle"):
        hook = hooks.get(hook_id)
        if isinstance(hook, dict):
            oracle_probes.append({"id": hook_id, **hook})
    probes = regular_probes + oracle_probes

    OUT.mkdir(parents=True, exist_ok=True)
    events_path = OUT / "h24-soak-events.ndjson"
    receipt_path = OUT / "h24-soak.json"
    events_path.write_text("", encoding="utf-8")
    started_at = iso_now()
    start_monotonic = time.monotonic()
    stop_requested = False

    def request_stop(signum, _frame):
        nonlocal stop_requested
        stop_requested = True
        append_event(events_path, {"ts": iso_now(), "event": "signal", "signal": int(signum)})

    signal.signal(signal.SIGINT, request_stop)
    signal.signal(signal.SIGTERM, request_stop)

    probe_results: dict[str, dict[str, int]] = {
        str(probe.get("id")): {
            "runs": 0,
            "passes": 0,
            "failures": 0,
            "max_consecutive_failures": 0,
            "current_consecutive_failures": 0,
        }
        for probe in probes
    }
    global_failures = 0
    restart_attempted = False
    restart_recovered = False
    restart_evidence: dict[str, Any] = {}
    cycle = 0

    while not env_errors and not stop_requested and time.monotonic() - start_monotonic < requested_duration:
        cycle += 1
        cycle_started = time.monotonic()
        for probe in probes:
            probe_id = str(probe.get("id", "unnamed"))
            ok, detail = execute_probe(probe, timeout)
            stats = probe_results[probe_id]
            stats["runs"] += 1
            if ok:
                stats["passes"] += 1
                stats["current_consecutive_failures"] = 0
            else:
                stats["failures"] += 1
                global_failures += 1
                stats["current_consecutive_failures"] += 1
                stats["max_consecutive_failures"] = max(stats["max_consecutive_failures"], stats["current_consecutive_failures"])
            append_event(events_path, {
                "ts": iso_now(),
                "event": "probe",
                "cycle": cycle,
                "probe_id": probe_id,
                "ok": ok,
                "detail": detail,
                "elapsed_seconds": round(time.monotonic() - start_monotonic, 3),
            })
            if stats["current_consecutive_failures"] > max_consecutive_failures:
                stop_requested = True
                break

        elapsed = time.monotonic() - start_monotonic
        if not stop_requested and not restart_attempted and elapsed >= requested_duration / 2:
            restart_attempted = True
            restart_hook = hooks.get("restart", {})
            restart_ok, restart_detail = execute_probe({"id": "restart", **restart_hook}, timeout)
            post_restart = []
            post_restart_ok = restart_ok
            if restart_ok:
                for probe in regular_probes:
                    probe_ok, detail = execute_probe(probe, timeout)
                    post_restart.append({"probe_id": probe.get("id"), "ok": probe_ok, "detail": detail})
                    post_restart_ok = post_restart_ok and probe_ok
            restart_recovered = post_restart_ok
            restart_evidence = {
                "restart_hook_ok": restart_ok,
                "restart_detail": restart_detail,
                "post_restart_probes": post_restart,
                "recovered": restart_recovered,
            }
            append_event(events_path, {
                "ts": iso_now(),
                "event": "forced_environment_restart",
                "cycle": cycle,
                "elapsed_seconds": round(elapsed, 3),
                **restart_evidence,
            })
            if not restart_recovered:
                stop_requested = True

        remaining = requested_duration - (time.monotonic() - start_monotonic)
        if remaining <= 0 or stop_requested:
            break
        sleep_for = max(0.0, min(float(interval), remaining) - (time.monotonic() - cycle_started))
        if sleep_for:
            time.sleep(sleep_for)

    finished_at = iso_now()
    duration = time.monotonic() - start_monotonic
    every_probe_ran = bool(probe_results) and all(stats["runs"] > 0 for stats in probe_results.values())
    no_excessive_failures = all(stats["max_consecutive_failures"] <= max_consecutive_failures for stats in probe_results.values())
    continuous_24h = duration >= minimum_duration and not stop_requested
    useful_work = every_probe_ran and sum(stats["runs"] for stats in probe_results.values()) >= max(3, int(minimum_duration / max(interval, 1)))

    lease_ok = bool(probe_results.get("lease_oracle", {}).get("runs")) and probe_results["lease_oracle"]["failures"] == 0
    queue_ok = bool(probe_results.get("queue_oracle", {}).get("runs")) and probe_results["queue_oracle"]["failures"] == 0
    memory_ok = bool(probe_results.get("memory_oracle", {}).get("runs")) and probe_results["memory_oracle"]["failures"] == 0

    checks = [
        {"id": "continuous_24h", "ok": continuous_24h, "evidence": {"duration_seconds": round(duration, 3), "minimum_duration_seconds": minimum_duration}},
        {"id": "periodic_jobs_progress", "ok": useful_work, "evidence": {"cycles": cycle, "probe_results": probe_results}},
        {"id": "forced_restart_recovery", "ok": restart_attempted and restart_recovered, "evidence": restart_evidence},
        {"id": "zero_orphan_leases", "ok": lease_ok, "evidence": probe_results.get("lease_oracle", {})},
        {"id": "zero_unbounded_queue_growth", "ok": queue_ok, "evidence": probe_results.get("queue_oracle", {})},
        {"id": "zero_fatal_errors", "ok": global_failures == 0 and no_excessive_failures, "evidence": {"probe_failures": global_failures, "probe_results": probe_results}},
        {"id": "memory_growth_bounded", "ok": memory_ok, "evidence": probe_results.get("memory_oracle", {})},
        {"id": "evidence_chain_complete", "ok": events_path.is_file() and events_path.stat().st_size > 0, "evidence": str(events_path.relative_to(ROOT))},
    ]

    receipt = {
        "schema_version": 1,
        "gate_id": "h24_soak",
        "commit_sha": actual_commit,
        "ok": not env_errors and all(check["ok"] is True for check in checks),
        "started_at": started_at,
        "finished_at": finished_at,
        "environment": {
            "real": os.environ.get("RP_H24_REAL_ENVIRONMENT", "") == "1" and environment_profile.get("real") is True,
            "class": "production_soak",
            "environment_id": environment_profile.get("environment_id"),
            "wordpress_url": os.environ.get("RP_H24_WORDPRESS_URL", ""),
            "profile": str(profile_path.relative_to(ROOT)) if profile_path.is_relative_to(ROOT) else str(profile_path),
            "environment_profile": (
                str(environment_profile_path.relative_to(ROOT))
                if environment_profile_path and environment_profile_path.is_relative_to(ROOT)
                else str(environment_profile_path or "")
            ),
        },
        "checks": checks,
        "artifacts": [{"path": str(events_path.relative_to(ROOT)), "sha256": sha256(events_path)}],
        "waivers": [],
        "skipped": [],
        "errors": env_errors,
    }
    receipt_path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print("PRODUCTION H24 SOAK")
    print(f"commit={actual_commit or 'UNKNOWN'} duration={duration:.1f}s cycles={cycle}")
    for error in env_errors:
        print(f"FAIL {error}")
    for check in checks:
        print(f"{'PASS' if check['ok'] else 'FAIL'} {check['id']}")
    print(f"receipt={receipt_path.relative_to(ROOT)} ok={str(receipt['ok']).lower()}")
    return 0 if receipt["ok"] else 1


if __name__ == "__main__":
    raise SystemExit(main())
