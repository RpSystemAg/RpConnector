#!/usr/bin/env python3
"""Fail closed when production_proven is stronger than available evidence.

Live evidence is deliberately not fabricated by CI. External/live acceptance
runs must deposit signed/reviewed machine-readable receipts under evidence/live
for the exact release commit. This gate prevents the descriptor from claiming
more than those receipts establish.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DESCRIPTOR = ROOT / "RP-STUDIO-CHATGPT-PLUGIN-1.0.0.json"
AGENT_HISTORY = ROOT / "bench/AGENT-BENCH-HISTORY.ndjson"
LIVE = ROOT / "evidence/live"

REQUIRED_LIVE = {
    "wordpress-live.json": "real WordPress install/upgrade acceptance",
    "browser-live.json": "real Browser Agent pairing/restart/action acceptance",
    "remote-mcp-oauth.json": "remote HTTPS MCP 2026-07-28 + OAuth acceptance",
    "h24-soak.json": "24-hour H24 soak acceptance",
}

parser = argparse.ArgumentParser()
parser.add_argument("--require-production-proof", action="store_true")
parser.add_argument("--commit", default=os.environ.get("GITHUB_SHA", "").strip())
args = parser.parse_args()

errors: list[str] = []
missing: list[str] = []
receipts: dict[str, dict] = {}

descriptor = json.loads(DESCRIPTOR.read_text(encoding="utf-8"))
claimed = bool(descriptor.get("production_proven", False))

agent_records = []
if AGENT_HISTORY.exists():
    for line_no, line in enumerate(AGENT_HISTORY.read_text(encoding="utf-8").splitlines(), 1):
        if not line.strip():
            continue
        try:
            agent_records.append(json.loads(line))
        except Exception as exc:
            errors.append(f"AGENT-BENCH-HISTORY line {line_no} is invalid JSON: {exc}")
if not agent_records:
    missing.append("measured AGENT-BENCH history")

for filename, label in REQUIRED_LIVE.items():
    path = LIVE / filename
    if not path.exists():
        missing.append(label)
        continue
    try:
        receipt = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        errors.append(f"{filename} invalid JSON: {exc}")
        continue
    receipts[filename] = receipt
    if receipt.get("ok") is not True:
        errors.append(f"{filename} does not assert ok=true")
    if not receipt.get("evidence"):
        errors.append(f"{filename} contains no evidence payload/reference")
    if args.commit:
        commit = str(receipt.get("commit_sha", ""))
        if commit != args.commit:
            errors.append(f"{filename} commit_sha={commit!r} does not match release commit {args.commit!r}")

proof_available = not missing and not errors
if claimed and not proof_available:
    errors.append("descriptor production_proven=true is unsupported by mandatory E6-E8 evidence")
if args.require_production_proof and not proof_available:
    errors.append("production proof explicitly required but mandatory live/benchmark evidence is incomplete")

print("RELEASE EVIDENCE GATE")
print(f" descriptor.production_proven={str(claimed).lower()}")
print(f" agent_bench_records={len(agent_records)}")
print(f" live_receipts={len(receipts)}/{len(REQUIRED_LIVE)}")
print(f" proof_available={str(proof_available).lower()}")
for item in missing:
    print(f"MISSING {item}")
for item in errors:
    print(f"ERROR   {item}")

# In normal PR CI, missing proof is permitted only while the product does NOT
# claim production-proven. Release certification invokes --require-production-proof.
if errors:
    sys.exit(1)
if claimed != proof_available and claimed:
    sys.exit(1)
if args.require_production_proof and not proof_available:
    sys.exit(1)
print("PASS release claim does not exceed available evidence")
