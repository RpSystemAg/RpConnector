#!/usr/bin/env python3
"""Require a causal, offline-safe event journal before deterministic replay can pass."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONTRACT = json.loads((ROOT / "quality/deterministic-replay-contract.json").read_text(encoding="utf-8"))
FILES = [
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-observability.php",
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-store.php",
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-agency-runtime.php",
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-job-engine.php",
]
SOURCE = "\n".join(p.read_text(encoding="utf-8", errors="replace") for p in FILES if p.is_file())
errors: list[str] = []

# A request/response snapshot named replay is not an event journal. Require an
# append-only ordered event concept and the fields needed to reconstruct causal
# transitions and logical effects.
if not re.search(r"event[_ -]?journal|append[_ -]?event|journal[_ -]?event", SOURCE, re.I):
    errors.append("REPLAY-01 no durable append-only event journal implementation is evident")

for field in CONTRACT["mandatory_event_fields"]:
    if not re.search(rf"['\"]{re.escape(field)}['\"]", SOURCE):
        errors.append(f"REPLAY-02 journal/replay source lacks mandatory event field {field}")

for field in CONTRACT["effect_event_fields"]:
    if not re.search(rf"['\"]{re.escape(field)}['\"]", SOURCE):
        errors.append(f"REPLAY-03 effect replay lacks field {field}")

for field in CONTRACT["external_observation_fields"]:
    if not re.search(rf"['\"]{re.escape(field)}['\"]", SOURCE):
        errors.append(f"REPLAY-04 external-observation replay lacks field {field}")

# Offline replay must have an explicit execution barrier. A replay function that
# merely accepts request/response payloads and can share production executors is
# not sufficient.
if not re.search(r"offline[_ -]?replay|replay[_ -]?mode|forbid.*mutation|mutation.*forbid", SOURCE, re.I):
    errors.append("REPLAY-05 no explicit offline replay mode/mutation barrier")

if not re.search(r"first[_ -]?diverg|diverg.*event|replay.*diverg", SOURCE, re.I):
    errors.append("REPLAY-06 replay cannot identify the first causal divergence")

print(f"DETERMINISTIC REPLAY READINESS: errors={len(errors)}")
for error in errors:
    print("ERROR", error)
sys.exit(1 if errors else 0)
