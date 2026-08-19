#!/usr/bin/env python3
"""Normalize exact-SHA GitHub Actions workflow/job results into production receipts.

A workflow conclusion alone is not sufficient. Every configured selector must
match the expected minimum number of jobs and every matched job must conclude
successfully. Receipt timestamps are inherited from the source runs so stale
workflow evidence cannot be made fresh merely by regenerating the receipt.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_CONFIG = ROOT / "quality" / "production-workflow-gates.json"
OUT = ROOT / "evidence" / "production"
API = os.environ.get("GITHUB_API_URL", "https://api.github.com").rstrip("/")


def request_json(url: str, token: str) -> Any:
    request = urllib.request.Request(
        url,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {token}",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "RPConnector-Workflow-Evidence/1",
        },
    )
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.loads(response.read().decode("utf-8"))


def successful_run(repository: str, workflow: str, commit: str, token: str) -> dict[str, Any] | None:
    encoded = urllib.parse.quote(workflow, safe="")
    query = urllib.parse.urlencode({"head_sha": commit, "status": "completed", "per_page": 100})
    payload = request_json(f"{API}/repos/{repository}/actions/workflows/{encoded}/runs?{query}", token)
    runs = payload.get("workflow_runs", []) if isinstance(payload, dict) else []
    matches = [
        run for run in runs
        if run.get("head_sha") == commit
        and run.get("status") == "completed"
        and run.get("conclusion") == "success"
    ]
    matches.sort(key=lambda run: (str(run.get("updated_at", "")), int(run.get("id", 0))), reverse=True)
    return matches[0] if matches else None


def run_jobs(repository: str, run_id: int, token: str) -> list[dict[str, Any]]:
    jobs: list[dict[str, Any]] = []
    page = 1
    while True:
        payload = request_json(f"{API}/repos/{repository}/actions/runs/{run_id}/jobs?per_page=100&page={page}", token)
        batch = payload.get("jobs", []) if isinstance(payload, dict) else []
        jobs.extend(batch)
        if len(batch) < 100:
            break
        page += 1
        if page > 20:
            raise RuntimeError(f"run {run_id}: excessive job pagination")
    return jobs


def parse_time(value: str) -> datetime:
    value = value.strip()
    if value.endswith("Z"):
        value = value[:-1] + "+00:00"
    dt = datetime.fromisoformat(value)
    if dt.tzinfo is None:
        raise ValueError("timezone missing")
    return dt.astimezone(timezone.utc)


def z(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", default=os.environ.get("GITHUB_REPOSITORY", ""))
    parser.add_argument("--commit", default=os.environ.get("GITHUB_SHA", ""))
    parser.add_argument("--token", default=os.environ.get("GITHUB_TOKEN", ""))
    parser.add_argument("--config", default=str(DEFAULT_CONFIG))
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()

    repository = args.repository.strip()
    commit = args.commit.strip()
    token = args.token.strip()
    if "/" not in repository:
        raise SystemExit("repository owner/name is required")
    if len(commit) != 40 or any(ch not in "0123456789abcdefABCDEF" for ch in commit):
        raise SystemExit("exact 40-hex commit is required")
    if not token:
        raise SystemExit("GITHUB_TOKEN/actions:read is required")

    config_path = Path(args.config)
    if not config_path.is_absolute():
        config_path = ROOT / config_path
    config = json.loads(config_path.read_text(encoding="utf-8"))
    OUT.mkdir(parents=True, exist_ok=True)

    run_cache: dict[str, tuple[dict[str, Any] | None, list[dict[str, Any]]]] = {}
    global_failures: list[str] = []

    def load_workflow(workflow: str) -> tuple[dict[str, Any] | None, list[dict[str, Any]]]:
        if workflow in run_cache:
            return run_cache[workflow]
        run = successful_run(repository, workflow, commit, token)
        jobs = run_jobs(repository, int(run["id"]), token) if run else []
        run_cache[workflow] = (run, jobs)
        return run, jobs

    for gate_id, gate in config.get("gates", {}).items():
        receipt_name = str(gate.get("receipt", f"{gate_id.replace('_', '-')}.json"))
        checks: list[dict[str, Any]] = []
        gate_runs: dict[int, dict[str, Any]] = {}
        gate_errors: list[str] = []

        for check_id, check_definition in gate.get("checks", {}).items():
            requirements = check_definition.get("requirements", [])
            requirement_evidence: list[dict[str, Any]] = []
            check_ok = bool(requirements)
            if not requirements:
                check_ok = False
                gate_errors.append(f"{gate_id}.{check_id}: no requirements configured")

            for requirement in requirements:
                workflow = str(requirement.get("workflow", ""))
                pattern_text = str(requirement.get("job_regex", ""))
                min_count = int(requirement.get("min_count", 1))
                try:
                    pattern = re.compile(pattern_text)
                except re.error as exc:
                    check_ok = False
                    gate_errors.append(f"{gate_id}.{check_id}: invalid regex {pattern_text!r}: {exc}")
                    continue

                run, jobs = load_workflow(workflow)
                if not run:
                    check_ok = False
                    requirement_evidence.append({
                        "workflow": workflow,
                        "job_regex": pattern_text,
                        "min_count": min_count,
                        "run": None,
                        "matched_jobs": [],
                        "error": f"no successful completed run for exact SHA {commit}",
                    })
                    continue

                run_id = int(run["id"])
                gate_runs[run_id] = run
                matched = [job for job in jobs if pattern.search(str(job.get("name", "")))]
                successful = [job for job in matched if job.get("status") == "completed" and job.get("conclusion") == "success"]
                cardinality_ok = len(matched) >= min_count
                all_matches_success = bool(matched) and len(successful) == len(matched)
                requirement_ok = cardinality_ok and all_matches_success
                check_ok = check_ok and requirement_ok
                if not requirement_ok:
                    gate_errors.append(
                        f"{gate_id}.{check_id}: {workflow} / {pattern_text!r} matched={len(matched)} "
                        f"success={len(successful)} required>={min_count}"
                    )
                requirement_evidence.append({
                    "workflow": workflow,
                    "run_id": run_id,
                    "run_url": run.get("html_url"),
                    "run_created_at": run.get("created_at"),
                    "run_updated_at": run.get("updated_at"),
                    "head_sha": run.get("head_sha"),
                    "job_regex": pattern_text,
                    "min_count": min_count,
                    "matched_count": len(matched),
                    "successful_count": len(successful),
                    "matched_jobs": [
                        {
                            "id": job.get("id"),
                            "name": job.get("name"),
                            "status": job.get("status"),
                            "conclusion": job.get("conclusion"),
                            "started_at": job.get("started_at"),
                            "completed_at": job.get("completed_at"),
                            "url": job.get("html_url"),
                        }
                        for job in matched
                    ],
                })

            checks.append({"id": check_id, "ok": check_ok, "evidence": requirement_evidence})

        run_values = list(gate_runs.values())
        time_errors: list[str] = []
        starts: list[datetime] = []
        finishes: list[datetime] = []
        for run in run_values:
            try:
                starts.append(parse_time(str(run.get("created_at", ""))))
                finishes.append(parse_time(str(run.get("updated_at", ""))))
            except Exception as exc:
                time_errors.append(f"run {run.get('id')}: invalid timestamps: {exc}")
        gate_errors.extend(time_errors)

        # Evidence time is inherited from the actual workflows, not the receipt-generation time.
        started_at = z(min(starts)) if starts else "1970-01-01T00:00:00Z"
        finished_at = z(max(finishes)) if finishes else "1970-01-01T00:00:00Z"
        gate_ok = bool(checks) and not gate_errors and all(check.get("ok") is True for check in checks)

        detail = {
            "schema_version": 1,
            "gate_id": gate_id,
            "repository": repository,
            "commit_sha": commit,
            "workflow_runs": [
                {
                    "id": run.get("id"),
                    "name": run.get("name"),
                    "workflow_id": run.get("workflow_id"),
                    "event": run.get("event"),
                    "head_sha": run.get("head_sha"),
                    "status": run.get("status"),
                    "conclusion": run.get("conclusion"),
                    "created_at": run.get("created_at"),
                    "updated_at": run.get("updated_at"),
                    "url": run.get("html_url"),
                }
                for run in run_values
            ],
            "checks": checks,
            "errors": gate_errors,
        }
        detail_path = OUT / f"{gate_id.replace('_', '-')}-workflow-details.json"
        detail_path.write_text(json.dumps(detail, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        receipt = {
            "schema_version": 1,
            "gate_id": gate_id,
            "commit_sha": commit,
            "ok": gate_ok,
            "started_at": started_at,
            "finished_at": finished_at,
            "environment": gate.get("environment", {"real": False, "class": "github-actions"}),
            "checks": checks,
            "artifacts": [{"path": str(detail_path.relative_to(ROOT)), "sha256": sha256(detail_path)}],
            "waivers": [],
            "skipped": [],
            "errors": gate_errors,
        }
        receipt_path = OUT / receipt_name
        receipt_path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")

        print(f"{gate_id}: ok={str(gate_ok).lower()} receipt={receipt_path.relative_to(ROOT)}")
        for check in checks:
            print(f"  {'PASS' if check['ok'] else 'FAIL'} {check['id']}")
        for error in gate_errors:
            print(f"  FAIL {error}")
        if not gate_ok:
            global_failures.append(gate_id)

    return 1 if args.strict and global_failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
