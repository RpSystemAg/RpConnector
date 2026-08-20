#!/usr/bin/env python3
"""Security-drift audit for self-evolution (arXiv 2026-08-13..19).

Reference: "Auditing Self-Evolution in Financial Agents: Capability Gains,
Security Drift" (week 2026-08-13..19).

The suite evolves quickly. Every capability gain must not silently weaken a
security surface. This gate snapshots the SHA-256 of the security-relevant
surfaces (redaction, allowlists, containment policy, leak gauge, taint config,
security workflows) into quality/security-drift-baseline.json and fails when a
tracked surface drifts from the baseline.

A drift is a diagnostic input (Law 12), never a bypass: an intentional change
must regenerate the baseline in the SAME commit that changes the surface.
Run with --regenerate only after a deliberate, reviewed security change.

Usage:
    python tests/security-drift-audit.py [--regenerate]
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASELINE = ROOT / "quality" / "security-drift-baseline.json"
GATE_VERSION = "1.0.0"

# Stable security surfaces: full-file digests.
TRACKED_FILES = {
    "prstudio-unified-browser-agent/lib/policy.js": "browser action/cdp allowlists and auth-challenge lists",
    "prstudio-unified-browser-agent/lib/observation-security.js": "browser observation redaction",
    "prstudio-unified-browser-agent/lib/trap-page-policy.js": "trap-page containment policy (untrusted page input)",
    "prstudio-unified-control/includes/class-prstudio-uc-memory.php": "MCP response redaction (Memory::redact)",
    "prstudio-unified-control/includes/class-prstudio-uc-context-leak-gauge.php": "context-leakage gauges (blocking invariant)",
    "psalm-taint.xml": "taint-analysis configuration",
    ".github/workflows/oauth-security-invariants.yml": "OAuth security invariants workflow",
    ".github/workflows/dast-security.yml": "authenticated DAST workflow",
    ".github/workflows/network-chaos.yml": "network chaos workflow",
    ".github/workflows/security-drift-audit.yml": "this gate's own workflow",
}

# Evolving files: only the security-relevant marker is tracked, so ordinary
# capability/tool additions do not raise false drift.
TRACKED_MARKERS = {
    "prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php": {
        "context_leak_blocked": "blocking context-leak error must stay wired in the MCP response path",
        "PRSTUDIO_UC_Context_Leak_Gauge::blocking_verdict": "the gauge must be invoked before any response is emitted",
        "known_secrets_for_gauge": "known OAuth secrets must feed the gauge",
    },
    "prstudio-unified-browser-agent/service-worker.js": {
        "trap_page.contained": "trap-page containment must stay wired into the mission loop",
        "horizon.fallback": "horizon single-step fallback must stay wired into the mission loop",
    },
}


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def markers_snapshot() -> dict[str, dict[str, str]]:
    snapshot: dict[str, dict[str, str]] = {}
    for rel, markers in TRACKED_MARKERS.items():
        path = ROOT / rel
        if not path.is_file():
            snapshot[rel] = {"_missing": "true"}
            continue
        source = path.read_text(encoding="utf-8", errors="replace")
        snapshot[rel] = {marker: ("present" if marker in source else "absent") for marker in markers}
    return snapshot


def files_snapshot() -> dict[str, dict[str, str]]:
    snapshot: dict[str, dict[str, str]] = {}
    for rel, role in TRACKED_FILES.items():
        path = ROOT / rel
        if not path.is_file():
            snapshot[rel] = {"role": role, "sha256": "", "present": "false"}
            continue
        snapshot[rel] = {"role": role, "sha256": sha256(path), "present": "true"}
    return snapshot


def current_snapshot() -> dict[str, object]:
    return {
        "gate_version": GATE_VERSION,
        "generated_utc": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "files": files_snapshot(),
        "markers": markers_snapshot(),
    }


def drift_report(current: dict[str, object]) -> list[str]:
    baseline = json.loads(BASELINE.read_text(encoding="utf-8"))
    baseline_files = baseline.get("files", {})
    baseline_markers = baseline.get("markers", {})
    findings: list[str] = []

    for rel, row in current["files"].items():  # type: ignore[union-attr]
        expected = baseline_files.get(rel)
        if not expected:
            findings.append(f"new security surface not in baseline: {rel}")
            continue
        if row["sha256"] != expected.get("sha256"):
            findings.append(
                f"security surface drifted: {rel} (sha256 {expected.get('sha256')} -> {row['sha256']}); "
                "review the change and regenerate the baseline in the same commit"
            )
    for rel, markers in current["markers"].items():  # type: ignore[union-attr]
        expected = baseline_markers.get(rel, {})
        for marker, state in markers.items():
            expected_state = expected.get(marker, "absent")
            if state != expected_state:
                findings.append(f"security marker drifted: {rel} :: {marker} ({expected_state} -> {state})")
    return findings


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--regenerate", action="store_true", help="rewrite the baseline from the current checkout")
    args = parser.parse_args()

    current = current_snapshot()
    if args.regenerate:
        current["generated_utc"] = datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")
        BASELINE.write_text(json.dumps(current, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(f"PASS security-drift baseline regenerated ({len(current['files'])} files, {len(current['markers'])} marker sets)")
        return 0

    if not BASELINE.is_file():
        print("DRIFT security-drift baseline is missing; run --regenerate after a reviewed security pass", file=sys.stderr)
        return 1

    findings = drift_report(current)
    if findings:
        for finding in findings:
            print(f"DRIFT {finding}", file=sys.stderr)
        return 1

    files = current["files"]
    tracked = sum(1 for row in files.values() if row.get("present") == "true")
    markers = sum(len(m) for m in current["markers"].values())
    print(f"PASS security-drift audit: {tracked} surfaces and {markers} markers match the baseline")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
