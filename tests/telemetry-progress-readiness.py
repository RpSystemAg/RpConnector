#!/usr/bin/env python3
"""Check that the runtime exposes enough correlated telemetry to prove liveness.

This is intentionally stronger than checking for a logger class. A production
runtime must make one mission causally reconstructable and must expose a durable
progress signature/deadline so a 30-minute no-op loop is machine-detectable.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = json.loads((ROOT / "quality/telemetry-progress-contract.json").read_text(encoding="utf-8"))
OBS = (ROOT / "prstudio-unified-control/includes/class-prstudio-uc-observability.php").read_text(encoding="utf-8", errors="replace")
RUNTIME_FILES = [
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-agency-runtime.php",
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-store.php",
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-job-engine.php",
]
RUNTIME = "\n".join(p.read_text(encoding="utf-8", errors="replace") for p in RUNTIME_FILES if p.is_file())
errors: list[str] = []

for field in CONTRACT["mandatory_span_fields"]:
    if not re.search(rf"['\"]{re.escape(field)}['\"]", OBS):
        errors.append(f"OTEL-01 observability record lacks mandatory span field {field}")

for field in CONTRACT["mandatory_runtime_attributes"]:
    if not re.search(rf"['\"]{re.escape(field)}['\"]", OBS + RUNTIME):
        errors.append(f"OTEL-02 runtime exposes no correlated field {field}")

# A trace ID must be propagated, not regenerated independently by each start().
if "trace_id" in OBS and not re.search(r"trace_id.{0,500}(?:parent|attributes|context|propagat)", OBS, re.I | re.S):
    errors.append("OTEL-03 trace_id exists but no propagation/context behavior is evident")

# A local append that explicitly falls back to unlocked best-effort output is
# unacceptable for state transitions if it is the only telemetry sink.
if CONTRACT.get("forbid_best_effort_loss_for_state_transition_events"):
    if "best-effort" in OBS.lower() and not re.search(r"durable|database|store.*event|journal", RUNTIME, re.I | re.S):
        errors.append("OTEL-04 state/progress evidence still depends on best-effort file telemetry with no durable journal")

# Progress needs a persisted signature, finite watchdog and a deadline visible
# from the parent state machine. Merely having per-process timeouts does not pass.
for pattern, label in [
    (r"progress_signature", "persisted progress signature"),
    (r"no[_ -]?progress|progress[_ -]?watchdog", "no-progress watchdog"),
    (r"wait(?:ing)?[_ -]?(?:deadline|expires)|browser[_ -]?deadline", "WAITING_FOR_BROWSER deadline"),
]:
    if not re.search(pattern, RUNTIME, re.I):
        errors.append(f"OTEL-05 missing {label}")

# No-progress threshold itself must remain finite and at or below policy maximum.
max_s = int(CONTRACT["max_no_progress_seconds"])
if max_s <= 0 or max_s > 300:
    errors.append(f"OTEL-06 invalid no-progress policy threshold {max_s}s")

print(f"TRACE/PROGRESS READINESS: errors={len(errors)}")
for error in errors:
    print("ERROR", error)
sys.exit(1 if errors else 0)
