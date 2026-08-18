#!/usr/bin/env python3
"""Run a fresh, reproducible PRSTUDIO-BENCH measurement.

The daily mode appends exactly one hash-chained record per Europe/Rome day.
The preview mode executes the same workload but never changes the history.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import shutil
import statistics
import subprocess
import sys
import tempfile
import time
import uuid
from contextlib import contextmanager
from datetime import datetime
from pathlib import Path
from typing import Any, Iterator
from zoneinfo import ZoneInfo


ROOT = Path(__file__).resolve().parent.parent
BENCH_DIR = Path(__file__).resolve().parent
DEFAULT_FORMULA = BENCH_DIR / "PRSTUDIO-BENCH-FORMULA-1.2.0.json"
DEFAULT_HISTORY = BENCH_DIR / "SYSTEM-BENCH-HISTORY.ndjson"
ROME = ZoneInfo("Europe/Rome")
SECRET_PATTERN = re.compile(
    r"(?i)(authorization|access[_-]?token|refresh[_-]?token|lane[_-]?token|"
    r"pairing[_-]?(?:key|token)|client[_-]?secret|password|api[_-]?key)"
    r"(\s*[:=]\s*)([^\s,;\]}]+)"
)


class BenchError(RuntimeError):
    """A deterministic benchmark or integrity failure."""


def canonical_bytes(value: Any) -> bytes:
    return json.dumps(
        value, ensure_ascii=False, sort_keys=True, separators=(",", ":")
    ).encode("utf-8")


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def redact(text: str) -> str:
    return SECRET_PATTERN.sub(lambda match: match.group(1) + match.group(2) + "[REDACTED]", text)


def load_json(path: Path) -> dict[str, Any]:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise BenchError(f"invalid JSON {path}: {exc}") from exc
    if not isinstance(value, dict):
        raise BenchError(f"expected a JSON object: {path}")
    return value


def suite_version() -> str:
    bootstrap = ROOT / "prstudio-unified-control" / "prstudio-unified-control.php"
    source = bootstrap.read_text(encoding="utf-8")
    match = re.search(r"define\(\s*'PRSTUDIO_UC_VERSION'\s*,\s*'([^']+)'", source)
    if not match:
        raise BenchError("PRSTUDIO_UC_VERSION not found in the stable plugin bootstrap")
    return match.group(1)


def ensure_executable(value: str, label: str) -> str:
    candidate = Path(value)
    if candidate.is_file():
        return str(candidate.resolve())
    resolved = shutil.which(value)
    if resolved:
        return resolved
    raise BenchError(f"{label} executable not found: {value}")


def run_command(
    argv: list[str],
    *,
    env: dict[str, str],
    timeout_seconds: int,
) -> dict[str, Any]:
    started = time.perf_counter_ns()
    try:
        process = subprocess.run(
            argv,
            cwd=ROOT,
            env=env,
            text=True,
            encoding="utf-8",
            errors="replace",
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=timeout_seconds,
            check=False,
        )
        timed_out = False
        exit_code = process.returncode
        stdout = redact(process.stdout)
        stderr = redact(process.stderr)
    except subprocess.TimeoutExpired as exc:
        timed_out = True
        exit_code = 124
        stdout = redact((exc.stdout or "") if isinstance(exc.stdout, str) else "")
        stderr = redact((exc.stderr or "") if isinstance(exc.stderr, str) else "")
    elapsed_ms = round((time.perf_counter_ns() - started) / 1_000_000, 6)
    return {
        "argv": [str(part) for part in argv],
        "exit_code": exit_code,
        "timed_out": timed_out,
        "elapsed_ms": elapsed_ms,
        "stdout": stdout,
        "stderr": stderr,
    }


def write_log(path: Path, result: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "argv": result["argv"],
        "exit_code": result["exit_code"],
        "timed_out": result["timed_out"],
        "elapsed_ms": result["elapsed_ms"],
        "stdout": result["stdout"],
        "stderr": result["stderr"],
    }
    path.write_bytes(canonical_bytes(payload) + b"\n")


def parse_validator_counts(output: str, exit_code: int) -> dict[str, int]:
    counts = {"passed": 0, "failed": 0, "skipped": 0, "warnings": 0}
    patterns = {
        "passed": r"(?im)^\s*(?:\[?PASS\]?|PASS:)\b",
        "failed": r"(?im)^\s*(?:\[?FAIL\]?|FAIL:)\b",
        "skipped": r"(?im)^\s*(?:\[?SKIP\]?|SKIP:)\b",
        "warnings": r"(?im)^\s*(?:\[?WARN(?:ING)?\]?|WARN(?:ING)?:)\b",
    }
    for name, pattern in patterns.items():
        counts[name] = len(re.findall(pattern, output))
    if counts["passed"] + counts["failed"] == 0:
        counts["passed" if exit_code == 0 else "failed"] = 1
    if exit_code != 0 and counts["failed"] == 0:
        counts["failed"] = 1
    return counts


def aggregate_checkpoint(summary: dict[str, Any]) -> dict[str, int]:
    totals = {"PASS": 0, "WARN": 0, "FAIL": 0, "NA": 0}
    question_counts = summary.get("question_status_counts")
    if not isinstance(question_counts, dict):
        raise BenchError("checkpoint summary lacks question_status_counts")
    for statuses in question_counts.values():
        if not isinstance(statuses, dict):
            raise BenchError("invalid checkpoint question status record")
        for status, count in statuses.items():
            if status not in totals:
                raise BenchError(f"unknown checkpoint status: {status}")
            totals[status] += int(count)
    return totals


def inventory_total(summary: dict[str, Any]) -> int:
    counts = summary.get("inventory_counts")
    if not isinstance(counts, dict):
        raise BenchError("checkpoint summary lacks inventory_counts")
    return sum(int(value) for value in counts.values())


def weakest_questions(summary: dict[str, Any], limit: int = 10) -> list[dict[str, Any]]:
    candidates: list[dict[str, Any]] = []
    for question_id, statuses in summary.get("question_status_counts", {}).items():
        applicable = sum(int(statuses.get(key, 0)) for key in ("PASS", "WARN", "FAIL"))
        if applicable <= 0:
            continue
        lost = int(statuses.get("FAIL", 0)) + 0.5 * int(statuses.get("WARN", 0))
        candidates.append(
            {
                "question_id": question_id,
                "applicable": applicable,
                "pass": int(statuses.get("PASS", 0)),
                "warn": int(statuses.get("WARN", 0)),
                "fail": int(statuses.get("FAIL", 0)),
                "lost_fraction": round(lost / applicable, 8),
            }
        )
    candidates.sort(key=lambda item: (-item["lost_fraction"], item["question_id"]))
    return candidates[:limit]


def history_records(path: Path) -> list[dict[str, Any]]:
    if not path.exists():
        return []
    records: list[dict[str, Any]] = []
    previous_hash: str | None = None
    for line_number, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        if not raw.strip():
            continue
        try:
            record = json.loads(raw)
        except json.JSONDecodeError as exc:
            raise BenchError(f"corrupt history line {line_number}: {exc}") from exc
        if not isinstance(record, dict):
            raise BenchError(f"history line {line_number} is not an object")
        stored_hash = record.get("record_sha256")
        unsigned = dict(record)
        unsigned.pop("record_sha256", None)
        actual_hash = sha256_bytes(canonical_bytes(unsigned))
        if stored_hash != actual_hash:
            raise BenchError(f"history hash mismatch at line {line_number}")
        if record.get("previous_record_sha256") != previous_hash:
            raise BenchError(f"history chain mismatch at line {line_number}")
        previous_hash = stored_hash
        records.append(record)
    return records


@contextmanager
def exclusive_lock(path: Path) -> Iterator[None]:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor = os.open(path, os.O_RDWR | os.O_CREAT, 0o600)
    try:
        if os.name == "nt":
            import msvcrt

            os.lseek(descriptor, 0, os.SEEK_SET)
            if os.fstat(descriptor).st_size == 0:
                os.write(descriptor, b"0")
                os.fsync(descriptor)
            os.lseek(descriptor, 0, os.SEEK_SET)
            msvcrt.locking(descriptor, msvcrt.LK_LOCK, 1)
        else:
            import fcntl

            fcntl.flock(descriptor, fcntl.LOCK_EX)
        yield
    finally:
        if os.name == "nt":
            import msvcrt

            os.lseek(descriptor, 0, os.SEEK_SET)
            msvcrt.locking(descriptor, msvcrt.LK_UNLCK, 1)
        else:
            import fcntl

            fcntl.flock(descriptor, fcntl.LOCK_UN)
        os.close(descriptor)


def append_history(path: Path, record: dict[str, Any]) -> dict[str, Any]:
    lock_path = path.with_suffix(path.suffix + ".lock")
    with exclusive_lock(lock_path):
        records = history_records(path)
        if any(item.get("local_date") == record["local_date"] for item in records):
            raise BenchError(
                f"canonical record already exists for {record['local_date']}; "
                "use preview mode for additional same-day measurements"
            )
        record["previous_record_sha256"] = records[-1]["record_sha256"] if records else None
        record["record_sha256"] = sha256_bytes(canonical_bytes(record))
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("ab") as handle:
            handle.write(canonical_bytes(record) + b"\n")
            handle.flush()
            os.fsync(handle.fileno())
    return record


def git_commit() -> str | None:
    try:
        result = subprocess.run(
            ["git", "rev-parse", "HEAD"],
            cwd=ROOT,
            text=True,
            encoding="utf-8",
            stdout=subprocess.PIPE,
            stderr=subprocess.DEVNULL,
            timeout=10,
            check=False,
        )
    except (OSError, subprocess.SubprocessError):
        return None
    value = result.stdout.strip()
    return value if result.returncode == 0 and re.fullmatch(r"[0-9a-fA-F]{40,64}", value) else None


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the daily PRSTUDIO-BENCH")
    parser.add_argument("--mode", choices=("daily", "preview"), default="preview")
    parser.add_argument("--runs", type=int, default=5)
    parser.add_argument("--formula", type=Path, default=DEFAULT_FORMULA)
    parser.add_argument("--history", type=Path, default=DEFAULT_HISTORY)
    parser.add_argument("--python", default=sys.executable)
    parser.add_argument("--node", default=os.environ.get("PRSTUDIO_NODE", "node"))
    parser.add_argument("--php", default=os.environ.get("PRSTUDIO_PHP", "php"))
    parser.add_argument("--timeout-seconds", type=int, default=1200)
    args = parser.parse_args()

    formula_path = args.formula.resolve()
    history_path = args.history.resolve()
    formula = load_json(formula_path)
    rules = formula.get("integrity_rules", {})
    required_questions = int(rules.get("required_questions", 250))
    minimum_samples = int(rules.get("minimum_timed_samples", 5))
    if args.runs < minimum_samples:
        raise BenchError(f"at least {minimum_samples} timed samples are required")

    python_bin = ensure_executable(args.python, "Python")
    node_bin = ensure_executable(args.node, "Node.js")
    php_bin = ensure_executable(args.php, "PHP")
    version = suite_version()
    audit_script = ROOT / "tests" / "exhaustive-checkpoint.py"
    validator_script = ROOT / "tests" / "validate-release.mjs"
    critical_script = ROOT / "tests" / "php-critical-performance.php"
    summary_path = ROOT / f"EXHAUSTIVE-CHECKPOINT-{version}.json"
    if not audit_script.is_file() or not validator_script.is_file() or not critical_script.is_file():
        raise BenchError("benchmark executors are missing")

    started_at = datetime.now(tz=ROME)
    started_mtime_ns = time.time_ns()
    local_date = started_at.date().isoformat()
    run_id = f"{local_date}-{uuid.uuid4().hex[:12]}"
    run_dir = BENCH_DIR / "runs" / run_id
    run_dir.mkdir(parents=True, exist_ok=False)

    environment = os.environ.copy()
    environment["PYTHONIOENCODING"] = "utf-8"
    environment["PYTHONUTF8"] = "1"
    environment["PRSTUDIO_PYTHON"] = python_bin
    environment["PRSTUDIO_PHP"] = php_bin

    fresh_audit = run_command(
        [python_bin, str(audit_script), "--write"],
        env=environment,
        timeout_seconds=args.timeout_seconds,
    )
    write_log(run_dir / "audit-write.json", fresh_audit)
    if fresh_audit["exit_code"] != 0:
        raise BenchError(f"fresh checkpoint failed; evidence: {run_dir / 'audit-write.json'}")
    if not summary_path.is_file() or summary_path.stat().st_mtime_ns < started_mtime_ns:
        raise BenchError("fresh checkpoint did not generate a current summary")

    timed_results: list[dict[str, Any]] = []
    for index in range(args.runs):
        result = run_command(
            [python_bin, str(audit_script), "--check"],
            env=environment,
            timeout_seconds=args.timeout_seconds,
        )
        write_log(run_dir / f"audit-check-{index + 1}.json", result)
        timed_results.append(result)
        if result["exit_code"] != 0:
            raise BenchError(f"timed checkpoint sample {index + 1} failed")

    critical_runs: list[dict[str, Any]] = []
    for index in range(args.runs):
        result = run_command(
            [php_bin, str(critical_script)],
            env=environment,
            timeout_seconds=args.timeout_seconds,
        )
        write_log(run_dir / f"critical-performance-{index + 1}.json", result)
        if result["exit_code"] != 0:
            raise BenchError(f"critical performance sample {index + 1} failed")
        try:
            payload = json.loads(result["stdout"].strip())
        except json.JSONDecodeError as exc:
            raise BenchError(f"critical performance sample {index + 1} emitted invalid JSON") from exc
        if not isinstance(payload, dict):
            raise BenchError(f"critical performance sample {index + 1} is not an object")
        critical_runs.append(payload)

    validator = run_command(
        [node_bin, str(validator_script), "--strict", "--php-smoke"],
        env=environment,
        timeout_seconds=args.timeout_seconds,
    )
    write_log(run_dir / "release-validator.json", validator)

    summary = load_json(summary_path)
    question_count = len(summary.get("checklist_questions", []))
    if question_count != required_questions:
        raise BenchError(
            f"benchmark requires exactly {required_questions} questions; found {question_count}"
        )
    item_count = inventory_total(summary)
    if item_count <= 0:
        raise BenchError("empty checkpoint inventory")
    matrix_cells = item_count * question_count
    statuses = aggregate_checkpoint(summary)
    applicable = statuses["PASS"] + statuses["WARN"] + statuses["FAIL"]
    if applicable <= 0:
        raise BenchError("checkpoint has no applicable rule cells")
    if sum(statuses.values()) != matrix_cells:
        raise BenchError("checkpoint status totals do not match matrix dimensions")
    coverage_ratio = applicable / matrix_cells

    weights = formula["weights"]
    status_points = formula["checkpoint_status_points"]
    checkpoint_ratio = (
        statuses["PASS"] * float(status_points["PASS"])
        + statuses["WARN"] * float(status_points["WARN"])
        + statuses["FAIL"] * float(status_points["FAIL"])
    ) / applicable
    checkpoint_points = checkpoint_ratio * float(weights["checkpoint_quality"])

    validator_counts = parse_validator_counts(
        validator["stdout"] + "\n" + validator["stderr"], validator["exit_code"]
    )
    validator_applicable = validator_counts["passed"] + validator_counts["warnings"] + validator_counts["failed"]
    validator_ratio = (validator_counts["passed"] + 0.5 * validator_counts["warnings"]) / max(1, validator_applicable)
    validator_points = validator_ratio * float(weights["release_validation"])

    samples_ms = [float(result["elapsed_ms"]) for result in timed_results]
    median_ms = float(statistics.median(samples_ms))
    applicable_rate = median_ms / applicable * 1_000_000
    coverage_points = coverage_ratio * float(weights["evidence_coverage"])

    critical_reference = formula.get("critical_runtime_reference", {})
    critical_weight = float(weights.get("critical_runtime_efficiency", 0.0))
    critical_specs = {
        "capability_search_cold": (
            [float(row["capability_search"]["cold_ms"]) for row in critical_runs],
            critical_reference.get("capability_search_cold", {}),
        ),
        "capability_search_warm_p95": (
            [float(row["capability_search"]["warm_p95_ms"]) for row in critical_runs],
            critical_reference.get("capability_search_warm_p95", {}),
        ),
        "operational_twin_sync_500": (
            [float(row["operational_twin"]["sync_ms"]) for row in critical_runs],
            critical_reference.get("operational_twin_sync_500", {}),
        ),
    }
    critical_metrics: dict[str, Any] = {}
    critical_points = 0.0
    declared_critical_weight = 0.0
    for metric_name, (metric_samples, metric_reference) in critical_specs.items():
        metric_weight = float(metric_reference.get("weight", 0.0))
        maximum_ms = float(metric_reference.get("maximum_median_ms", 0.0))
        if metric_weight <= 0 or maximum_ms <= 0 or len(metric_samples) < int(rules.get("required_critical_samples", 5)):
            raise BenchError(f"invalid or incomplete critical runtime reference: {metric_name}")
        metric_median = float(statistics.median(metric_samples))
        metric_points = metric_weight * min(1.0, maximum_ms / max(metric_median, 0.000001))
        declared_critical_weight += metric_weight
        critical_points += metric_points
        critical_metrics[metric_name] = {
            "samples_ms": metric_samples,
            "median_ms": round(metric_median, 6),
            "maximum_reference_ms": maximum_ms,
            "points": round(metric_points, 6),
            "weight": metric_weight,
        }
    if abs(declared_critical_weight - critical_weight) > 0.000001:
        raise BenchError("critical runtime subweights do not match the formula weight")

    total_formula_weight = sum(float(value) for value in weights.values())
    if abs(total_formula_weight - 100.0) > 0.000001:
        raise BenchError("system benchmark weights must total exactly 100")

    raw_score = checkpoint_points + validator_points + coverage_points + critical_points
    hard_failures = int(summary.get("hard_failure_count", 0))
    score = raw_score
    status = "valid"
    if hard_failures:
        score = min(score, float(rules["hard_failure_score_ceiling"]))
        status = "hard_failure"
    if validator["exit_code"] != 0:
        score = min(score, float(rules["release_validator_failure_score_ceiling"]))
        status = "release_validator_failure" if status == "valid" else status

    formula_sha256 = sha256_file(formula_path)
    previous_records = history_records(history_path)
    previous = previous_records[-1] if previous_records else None
    comparable = bool(previous is None or previous.get("formula_sha256") == formula_sha256)
    inventory_drop = bool(previous and item_count < int(previous.get("inventory", {}).get("items", 0)))
    delta = None
    positive_result = False
    if previous and comparable and not inventory_drop:
        delta = round(score - float(previous["score"]["total"]), 6)
        positive_result = delta > 0
    if inventory_drop:
        status = "inventory_drop_review_required"
    if previous and not comparable:
        status = "formula_changed_non_comparable"

    completed_at = datetime.now(tz=ROME)
    record: dict[str, Any] = {
        "schema_version": "1.0.0",
        "benchmark": "PRSTUDIO-SYSTEM-BENCH",
        "run_id": run_id,
        "occurrence_key": f"prstudio-system-bench|{local_date}|daily",
        "local_date": local_date,
        "timezone": "Europe/Rome",
        "started_at": started_at.isoformat(),
        "completed_at": completed_at.isoformat(),
        "suite_version": version,
        "git_commit": git_commit(),
        "source_digest": summary.get("source_digest"),
        "formula_id": formula.get("formula_id"),
        "formula_version": formula.get("formula_version"),
        "formula_sha256": formula_sha256,
        "fresh_run": True,
        "status": status,
        "comparable_to_previous": comparable and not inventory_drop,
        "inventory": {
            "items": item_count,
            "by_kind": summary.get("inventory_counts", {}),
            "drop_from_previous": inventory_drop,
        },
        "checkpoint": {
            "questions_per_item": question_count,
            "matrix_cells": matrix_cells,
            "evaluations": matrix_cells,
            "evaluations_deprecated_label": "matrix_cells_not_independent_executions",
            "applicable_rule_cells": applicable,
            "not_applicable_rule_cells": statuses["NA"],
            "evidence_coverage_ratio": round(coverage_ratio, 9),
            "statuses": statuses,
            "applicable": applicable,
            "hard_failure_count": hard_failures,
            "summary_sha256": sha256_file(summary_path),
            "timed_samples_ms": samples_ms,
            "median_ms": round(median_ms, 6),
            "milliseconds_per_million_applicable_rule_cells": round(applicable_rate, 6),
            "measurement_semantics": "rule-matrix timing; matrix cells are not independent behavior executions",
        },
        "release_validator": {
            "exit_code": validator["exit_code"],
            "counts": validator_counts,
            "evidence_sha256": sha256_file(run_dir / "release-validator.json"),
        },
        "critical_runtime": {
            "samples": len(critical_runs),
            "metrics": critical_metrics,
            "fixture": "500 bounded WordPress/WooCommerce entities; public capability search path",
            "fixture_script_sha256": sha256_file(critical_script),
            "peak_memory_bytes": [int(row.get("peak_memory_bytes", 0)) for row in critical_runs],
            "production_proven": False,
        },
        "score": {
            "checkpoint_quality": round(checkpoint_points, 6),
            "release_validation": round(validator_points, 6),
            "evidence_coverage": round(coverage_points, 6),
            "critical_runtime_efficiency": round(critical_points, 6),
            "raw": round(raw_score, 6),
            "total": round(max(0.0, min(100.0, score)), 6),
            "delta_from_previous": delta,
            "positive_result": positive_result,
        },
        "weakest_questions": weakest_questions(summary),
        "evidence_directory": run_dir.relative_to(ROOT).as_posix(),
        "benchmark_scope": "infrastructure_and_contract_quality",
        "agent_benchmark": {"measured": False, "score": None, "manifest": "bench/AGENT-CORPUS-MANIFEST.json"},
        "matrix_cells_are_independent_executions": False,
        "production_proven": False,
        "previous_record_sha256": None,
    }

    weakest = record["weakest_questions"]
    evidenced_gap = bool(
        hard_failures
        or validator["exit_code"] != 0
        or any(float(item.get("lost_fraction", 0.0)) > 0.0 for item in weakest)
    )
    proposal = {
        "schema_version": "1.0.0",
        "run_id": run_id,
        "score": record["score"],
        "status": record["status"],
        "weakest_questions": weakest,
        "decision": "REVIEW_CANDIDATE" if evidenced_gap else "NO_CHANGE",
        "reason": (
            "At least one fresh checkpoint or validator gap is evidenced."
            if evidenced_gap
            else "No checkpoint loss or validator failure was measured; Day One has no comparable prior record."
        ),
        "instruction": (
            "Propose one bounded, test-first intervention against the weakest evidenced area. "
            "Do not change the scoring formula, remove inventory, suppress a gate, mutate a live site, "
            "or apply source changes without an explicitly authorized execution lane."
            if evidenced_gap
            else "NO CHANGE. Preserve the candidate and wait for new evidence; do not manufacture a modification."
        ),
    }
    proposal_dir = BENCH_DIR / "proposals"
    proposal_dir.mkdir(parents=True, exist_ok=True)
    proposal_path = proposal_dir / f"{run_id}.json"
    proposal_path.write_bytes(canonical_bytes(proposal) + b"\n")

    if args.mode == "daily":
        record = append_history(history_path, record)
    else:
        unsigned = dict(record)
        unsigned.pop("record_sha256", None)
        record["record_sha256"] = sha256_bytes(canonical_bytes(unsigned))

    output = {
        "ok": hard_failures == 0 and validator["exit_code"] == 0,
        "mode": args.mode,
        "run_id": run_id,
        "status": record["status"],
        "score": record["score"],
        "inventory": record["inventory"],
        "checkpoint": {
            "questions_per_item": question_count,
            "hard_failure_count": hard_failures,
            "median_ms": round(median_ms, 6),
        },
        "evidence_directory": record["evidence_directory"],
        "proposal": proposal_path.relative_to(ROOT).as_posix(),
        "history_appended": args.mode == "daily",
        "production_proven": False,
    }
    print(json.dumps(output, ensure_ascii=False, sort_keys=True))
    return 0 if output["ok"] else 1


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except BenchError as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False), file=sys.stderr)
        raise SystemExit(2)
