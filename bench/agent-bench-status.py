#!/usr/bin/env python3
"""Report readiness without inventing an Agent Bench score."""

from __future__ import annotations

import hashlib
import json
from pathlib import Path


ROOT = Path(__file__).resolve().parent


def digest(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    spec_path = ROOT / "PRSTUDIO-AGENT-BENCH-SPEC-1.0.0.json"
    corpus_path = ROOT / "AGENT-CORPUS-MANIFEST.json"
    baseline_path = ROOT / "AGENT-BENCH-DAY-ZERO.json"
    spec = json.loads(spec_path.read_text(encoding="utf-8"))
    corpus = json.loads(corpus_path.read_text(encoding="utf-8"))
    baseline = json.loads(baseline_path.read_text(encoding="utf-8"))
    required = int(spec["corpus"]["required_tasks"])
    registered = int(corpus.get("registered_tasks", 0))
    split_ready = all(
        int(value.get("registered", 0)) == int(value.get("required", -1))
        for value in corpus.get("splits", {}).values()
    )
    ready = bool(
        corpus.get("ready")
        and registered == required
        and split_ready
        and corpus.get("frozen_reference", {}).get("ready")
        and corpus.get("frozen_reference", {}).get("episode_bundle_hash")
    )
    result = {
        "ok": ready,
        "benchmark": "PRSTUDIO-AGENT-BENCH",
        "measured_score_available": ready,
        "day_zero_calibration": baseline["score"],
        "day_zero_calibration_measured": baseline["measured"],
        "registered_tasks": registered,
        "required_tasks": required,
        "corpus_ready": bool(corpus.get("ready")),
        "reference_ready": bool(corpus.get("frozen_reference", {}).get("ready")),
        "spec_sha256": digest(spec_path),
        "corpus_manifest_sha256": digest(corpus_path),
        "blocking_reason": None if ready else corpus.get("blocking_reason"),
        "production_proven": False
    }
    print(json.dumps(result, ensure_ascii=False, sort_keys=True))
    return 0 if ready else 3


if __name__ == "__main__":
    raise SystemExit(main())
