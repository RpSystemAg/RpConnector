#!/usr/bin/env python3
"""Compare an original mission journal and an offline replay journal.

Usage: deterministic-replay-oracle.py original.ndjson replay.ndjson
The oracle intentionally ignores wall-clock timestamps and physical IDs that
must differ between executions; it compares causal sequence, state transitions,
recorded observations, logical effects and final verification verdict.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

if len(sys.argv) != 3:
    raise SystemExit("usage: deterministic-replay-oracle.py ORIGINAL REPLAY")


def load(path: str) -> list[dict]:
    rows: list[dict] = []
    for lineno, line in enumerate(Path(path).read_text(encoding="utf-8").splitlines(), 1):
        if not line.strip():
            continue
        try:
            row = json.loads(line)
        except Exception as exc:
            raise SystemExit(f"invalid NDJSON {path}:{lineno}: {exc}")
        if not isinstance(row, dict):
            raise SystemExit(f"non-object event {path}:{lineno}")
        rows.append(row)
    if not rows:
        raise SystemExit(f"empty journal: {path}")
    return rows


def normalize(event: dict) -> dict:
    keep = [
        "sequence", "event_type", "job_uuid", "step_id", "logical_attempt",
        "state_from", "state_to", "input_hash", "output_hash", "effect_id",
        "idempotency_key", "executed", "evidence_id", "verified", "strength",
        "source", "observation_hash", "recorded_payload_or_artifact_hash",
        "final_verdict",
    ]
    return {key: event.get(key) for key in keep if key in event}

original = load(sys.argv[1])
replay = load(sys.argv[2])
if len(original) != len(replay):
    raise SystemExit(f"REPLAY-DIVERGENCE event_count original={len(original)} replay={len(replay)}")

for index, (left, right) in enumerate(zip(original, replay)):
    nl, nr = normalize(left), normalize(right)
    if nl != nr:
        print("REPLAY-DIVERGENCE", json.dumps({
            "first_event_index": index,
            "original": nl,
            "replay": nr,
        }, indent=2, sort_keys=True))
        raise SystemExit(1)

# A replay that emitted an actual mutation marker is unsafe even if the logical
# transcript happens to match.
for event in replay:
    if event.get("replay_real_mutation") is True:
        raise SystemExit("REPLAY-SAFETY replay executed a real mutation")

print(f"PASS deterministic replay: events={len(original)} causal transcript identical")
