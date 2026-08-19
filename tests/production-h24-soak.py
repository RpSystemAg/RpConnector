#!/usr/bin/env python3
"""Run a real exact-SHA 24-hour production soak and emit certifiable evidence.

This harness refuses to certify shortened runs. It executes bounded argv and
HTTP probes repeatedly, records every observation, injects a controlled process
restart checkpoint, and emits h24-soak.json only from observed results.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import signal
import subprocess
import sys
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
        return False, {
            "command": command,
            "duration_seconds": round(time.monotonic() - started, 3),
            "timeout": True,
            "output_tail": ((exc.stdout or "") if isinstance(exc.stdout, str) else "")[-4000:],
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


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--profile", default=str(DEFAULT_PROFILE))
    parser.add_argument("--duration-seconds", type=int, default=0)
    parser.add_argument("--interval-seconds", type=int, default=0)
    parser.add_argument("--commit", default="")
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()

    profile_path = Path(args.profile)
    if not profile_path.is_absolute():
        profile_path = ROOT / profile_path
    profile = json.loads(profile_path.read_text(encoding="utf-8"))
    minimum_duration = int(profile.get("minimum_duration_seconds", 86400))
    requested_duration = int(args.duration_seconds or minimum_duration)
    interval = int(args.interval_seconds or profile.get("probe_interval_seconds", 300))
    timeout = int(profile.get("max_probe_runtime_seconds", 120))
    max_consecutive_failures = int(profile.get("max_consecutive_failures", 2))
    expected_commit = args.commit.strip() or git_head()
    actual_commit = git_head()

    env_errors: list[str] = []
    for name in profile.get("required_environment_variables", []):
        if not os.environ.get(str(name), "").strip():
            env_errors.append(f"missing required environment variable {name}")
    if os.environ.get("RP_H24_REAL_ENVIRONMENT", "").strip() != "1":
        env_errors.append("RP_H24_REAL_ENVIRONMENT must equal 1")
    if requested_duration < minimum_duration:
        env_errors.append(f"requested duration {requested_duration}s is below production minimum {minimum_duration}s")
    if not actual_commit or actual_commit != expected_commit:
        env_errors.append(f"checkout commit {actual_commit!r} does not match expected commit {expected_commit!r}")

    OUT.mkdir(parents=True, exist_ok=True)
    events_path = OUT / "h24-soak-events.ndjson"
    receipt_path = OUT / "h24-soak.json"
    started_at = iso_now()
    start_monotonic = time.monotonic()
    stop_requested = False

    def request_stop(signum, _frame):
        nonlocal stop_requested
        stop_requested = True
        with events_path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps({"ts": iso_now(), "event": "signal", "signal": int(signum)}) + "\n")

    signal.signal(signal.SIGINT, request_stop)
    signal.signal(signal.SIGTERM, request_stop)

    events_path.write_text("", encoding="utf-8")
    probe_results: dict[str, dict[str, int]] = {
        str(probe.get("id")): {"runs": 0, "passes": 0, "failures": 0, "max_consecutive_failures": 0, "current_consecutive_failures": 0}
        for probe in profile.get("probes", [])
    }
    global_failures = 0
    forced_restart_checkpoint_observed = False
    cycle = 0

    while not env_errors and not stop_requested and time.monotonic() - start_monotonic < requested_duration:
        cycle += 1
        cycle_started = time.monotonic()
        for probe in profile.get("probes", []):
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
            event = {
                "ts": iso_now(),
                "event": "probe",
                "cycle": cycle,
                "probe_id": probe_id,
                "ok": ok,
                "detail": detail,
                "elapsed_seconds": round(time.monotonic() - start_monotonic, 3),
            }
            with events_path.open("a", encoding="utf-8") as handle:
                handle.write(json.dumps(event, sort_keys=True) + "\n")
            if stats["current_consecutive_failures"] > max_consecutive_failures:
                stop_requested = True
                break

        elapsed = time.monotonic() - start_monotonic
        # Mid-soak checkpoint proves the harness survived and resumed its own useful-work loop.
        # External orchestration should additionally restart WordPress/DB/Chrome and record that
        # in environment-specific receipts; this checkpoint must not be confused with those tests.
        if not forced_restart_checkpoint_observed and elapsed >= requested_duration / 2:
            forced_restart_checkpoint_observed = True
            with events_path.open("a", encoding="utf-8") as handle:
                handle.write(json.dumps({
                    "ts": iso_now(),
                    "event": "mid_soak_checkpoint",
                    "cycle": cycle,
                    "elapsed_seconds": round(elapsed, 3),
                }) + "\n")

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

    checks = [
        {"id": "continuous_24h", "ok": continuous_24h, "evidence": {"duration_seconds": round(duration, 3), "minimum_duration_seconds": minimum_duration}},
        {"id": "periodic_jobs_progress", "ok": useful_work, "evidence": {"cycles": cycle, "probe_results": probe_results}},
        {"id": "forced_restart_recovery", "ok": forced_restart_checkpoint_observed, "evidence": "mid-soak harness checkpoint observed; environment restart evidence must also be supplied by the live environment runner"},
        {"id": "zero_orphan_leases", "ok": False, "evidence": "requires environment-specific lease oracle; intentionally not self-certified by generic soak harness"},
        {"id": "zero_unbounded_queue_growth", "ok": False, "evidence": "requires environment-specific queue-depth time series; intentionally not self-certified by generic soak harness"},
        {"id": "zero_fatal_errors", "ok": global_failures == 0 and no_excessive_failures, "evidence": {"probe_failures": global_failures, "probe_results": probe_results}},
        {"id": "memory_growth_bounded", "ok": False, "evidence": "requires environment-specific memory time series; intentionally not self-certified by generic soak harness"},
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
            "real": os.environ.get("RP_H24_REAL_ENVIRONMENT", "") == "1",
            "class": "production_soak",
            "wordpress_url": os.environ.get("RP_H24_WORDPRESS_URL", ""),
            "profile": str(profile_path.relative_to(ROOT)) if profile_path.is_relative_to(ROOT) else str(profile_path),
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
    return 1 if args.strict and not receipt["ok"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
