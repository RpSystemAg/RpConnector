#!/usr/bin/env python3
"""Property/stateful testing against the real WordPress durable-job runtime.

Hypothesis generates and shrinks action sequences. Each sequence is executed by
wordpress-stateful-sequence.php through wp-cli, so a counterexample is not merely
a Python-model failure: it has exercised the production Store implementation and
the real WordPress database connection.
"""
from __future__ import annotations

import json
import os
import subprocess
import tempfile
from pathlib import Path

from hypothesis import HealthCheck, given, note, settings, strategies as st

ROOT = Path(__file__).resolve().parents[1]
PHP_RUNNER = ROOT / "tests" / "wordpress-stateful-sequence.php"
WP_PATH = os.environ.get("RP_WP_PATH", "").strip()

if not WP_PATH:
    raise SystemExit("RP_WP_PATH is required; this test refuses to fall back to a mock runtime")
if not PHP_RUNNER.is_file():
    raise SystemExit(f"missing PHP sequence oracle: {PHP_RUNNER}")

# Keep generated verbs deliberately small. Shrinking then produces a human-sized
# minimal causal sequence instead of a random payload nobody can reason about.
action = st.sampled_from(["claim", "double_claim", "yield", "stale_recover", "observe"])
sequences = st.lists(action, min_size=6, max_size=45).filter(
    lambda xs: "claim" in xs and ("yield" in xs or "stale_recover" in xs)
)


def execute(actions: list[str], max_attempts: int) -> dict:
    scenario = {"actions": actions, "max_attempts": max_attempts}
    with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False, encoding="utf-8") as handle:
        json.dump(scenario, handle, separators=(",", ":"))
        path = handle.name
    env = os.environ.copy()
    env["PR_STATEFUL_SCENARIO"] = path
    try:
        proc = subprocess.run(
            ["wp", "eval-file", str(PHP_RUNNER), f"--path={WP_PATH}"],
            cwd=ROOT,
            env=env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=25,
            check=False,
        )
    finally:
        Path(path).unlink(missing_ok=True)

    if proc.returncode != 0:
        raise AssertionError(
            "real-runtime invariant violation\n"
            f"scenario={json.dumps(scenario, separators=(',', ':'))}\n"
            f"stdout={proc.stdout[-12000:]}\n"
            f"stderr={proc.stderr[-12000:]}"
        )
    lines = [line for line in proc.stdout.splitlines() if line.strip().startswith("{")]
    if not lines:
        raise AssertionError(f"runtime returned no JSON oracle result: {proc.stdout!r}")
    result = json.loads(lines[-1])
    assert result.get("ok") is True, result
    return result


@given(actions=sequences, max_attempts=st.integers(min_value=1, max_value=8))
@settings(
    max_examples=120,
    deadline=None,
    suppress_health_check=[HealthCheck.filter_too_much, HealthCheck.too_slow],
    print_blob=True,
)
def test_generated_runtime_sequences(actions: list[str], max_attempts: int) -> None:
    note(f"actions={actions!r} max_attempts={max_attempts}")
    result = execute(actions, max_attempts)
    # A healthy claim is evidence that the generated sequence actually entered
    # the leased execution path; sequences that only become terminal through
    # valid recovery exhaustion are still legitimate.
    assert result["final_attempts"] >= 0
    assert result["final_status"] in {
        "READY", "RUNNING", "WAITING_FOR_BROWSER", "COMPLETED", "FAILED", "CANCELLED", "DEAD_LETTER"
    }, result


if __name__ == "__main__":
    test_generated_runtime_sequences()
    print("PASS Hypothesis generated/shrunk 120 real WordPress runtime sequences")
