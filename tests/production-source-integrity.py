#!/usr/bin/env python3
"""Generate the source_integrity production-readiness receipt.

This is deliberately conservative. It checks the exact checkout, generated
contract drift, production placeholders, strong executable stub markers, and
immutable GitHub Action references. Dynamic functionality is certified elsewhere.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "evidence" / "production"
PRODUCTION_ROOTS = [ROOT / "prstudio-unified-control", ROOT / "prstudio-unified-browser-agent"]
EXEC_SUFFIXES = {".php", ".js", ".mjs", ".cjs"}
IGNORE_PARTS = {"tests", "test", "fixtures", "vendor", "node_modules", "docs", "capabilities"}
STRONG_STUB_PATTERNS = [
    re.compile(r"\bcoming[_ -]?soon\b", re.I),
    re.compile(r"\bplaceholder[_ -]?success\b", re.I),
    re.compile(r"\bdummy[_ -]?implementation\b", re.I),
    re.compile(r"throw\s+new\s+[A-Za-z_][A-Za-z0-9_]*(?:NotImplemented|UnsupportedOperation)[A-Za-z0-9_]*\s*\(", re.I),
    re.compile(r"(?:return|resolve|callback)\s+[^;\n]{0,120}\bnot[_ -]?implemented\b", re.I),
]
PLACEHOLDER_RE = re.compile(r"https?://(?:www\.)?(?:example\.com|example\.org|example\.net)(?:[/:'\"\s]|$)", re.I)
USES_RE = re.compile(r"^\s*-?\s*uses:\s*([^\s#]+)", re.M)
FULL_SHA_RE = re.compile(r"^[0-9a-f]{40}$", re.I)


def now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def run(command: list[str]) -> tuple[bool, str]:
    try:
        proc = subprocess.run(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300)
        text = proc.stdout[-12000:]
        return proc.returncode == 0, text
    except Exception as exc:
        return False, f"{type(exc).__name__}: {exc}"


def git_head() -> str:
    ok, output = run(["git", "rev-parse", "HEAD"])
    return output.strip() if ok else ""


def executable_files() -> list[Path]:
    files: list[Path] = []
    for base in PRODUCTION_ROOTS:
        if not base.exists():
            continue
        for path in base.rglob("*"):
            if not path.is_file() or path.suffix.lower() not in EXEC_SUFFIXES:
                continue
            rel = path.relative_to(ROOT)
            if any(part.lower() in IGNORE_PARTS for part in rel.parts):
                continue
            files.append(path)
    return files


def scan_placeholders(files: list[Path]) -> list[dict[str, Any]]:
    findings = []
    for path in files:
        text = path.read_text(encoding="utf-8", errors="replace")
        for match in PLACEHOLDER_RE.finditer(text):
            line = text.count("\n", 0, match.start()) + 1
            findings.append({"file": str(path.relative_to(ROOT)), "line": line, "match": match.group(0)})
    return findings


def scan_stubs(files: list[Path]) -> list[dict[str, Any]]:
    findings = []
    for path in files:
        text = path.read_text(encoding="utf-8", errors="replace")
        for pattern in STRONG_STUB_PATTERNS:
            for match in pattern.finditer(text):
                line = text.count("\n", 0, match.start()) + 1
                findings.append({"file": str(path.relative_to(ROOT)), "line": line, "match": match.group(0)})
    return findings


def check_actions_pinned() -> list[dict[str, str]]:
    findings = []
    for path in sorted((ROOT / ".github" / "workflows").glob("*.y*ml")):
        text = path.read_text(encoding="utf-8", errors="replace")
        for value in USES_RE.findall(text):
            if value.startswith("./"):
                continue
            if value.startswith("docker://"):
                if "@sha256:" not in value:
                    findings.append({"file": str(path.relative_to(ROOT)), "uses": value, "reason": "docker action is not digest pinned"})
                continue
            if "@" not in value:
                findings.append({"file": str(path.relative_to(ROOT)), "uses": value, "reason": "missing ref"})
                continue
            ref = value.rsplit("@", 1)[1]
            if not FULL_SHA_RE.fullmatch(ref):
                findings.append({"file": str(path.relative_to(ROOT)), "uses": value, "reason": "ref is not immutable 40-hex commit"})
    return findings


def artifact_sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--commit", default=os.environ.get("GITHUB_SHA", ""))
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()
    started = now()
    expected = args.commit.strip() or git_head()
    head = git_head()

    files = executable_files()
    placeholders = scan_placeholders(files)
    stubs = scan_stubs(files)
    action_refs = check_actions_pinned()

    regen_ok, regen_output = run([sys.executable, "tests/regenerate-contract-artifacts.py", "--php-binary", "php", "--check"])
    mcp_drift_ok, mcp_drift_output = run(["node", ".github/scripts/check-mcp-tool-drift.mjs"])
    counts_ok, counts_output = run(["node", ".github/scripts/check-build-info-counts.mjs"])

    checks = [
        {
            "id": "exact_source_tree",
            "ok": bool(head and expected and head == expected),
            "evidence": {"head": head, "expected": expected},
        },
        {
            "id": "generated_artifacts_reproducible",
            "ok": regen_ok,
            "evidence": regen_output,
        },
        {
            "id": "release_manifest_consistent",
            "ok": mcp_drift_ok and counts_ok,
            "evidence": {"mcp_tool_drift": mcp_drift_output, "build_info_counts": counts_output},
        },
        {
            "id": "no_release_placeholders",
            "ok": not placeholders,
            "evidence": {"scanned_files": len(files), "findings": placeholders},
        },
        {
            "id": "no_unresolved_stubs",
            "ok": not stubs,
            "evidence": {"strong_executable_stub_findings": stubs},
        },
        {
            "id": "dependency_actions_pinned",
            "ok": not action_refs,
            "evidence": {"unimmutable_action_refs": action_refs},
        },
    ]

    OUT.mkdir(parents=True, exist_ok=True)
    detail = {
        "schema_version": 1,
        "commit_sha": head,
        "scanned_executable_files": len(files),
        "checks": checks,
    }
    detail_path = OUT / "source-integrity-details.json"
    detail_path.write_text(json.dumps(detail, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    receipt = {
        "schema_version": 1,
        "gate_id": "source_integrity",
        "commit_sha": head,
        "ok": all(check["ok"] is True for check in checks),
        "started_at": started,
        "finished_at": now(),
        "environment": {"real": False, "class": "source_checkout", "ci": bool(os.environ.get("CI"))},
        "checks": checks,
        "artifacts": [{"path": str(detail_path.relative_to(ROOT)), "sha256": artifact_sha(detail_path)}],
        "waivers": [],
        "skipped": [],
    }
    receipt_path = OUT / "source-integrity.json"
    receipt_path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print("SOURCE INTEGRITY PRODUCTION GATE")
    for check in checks:
        print(f"{'PASS' if check['ok'] else 'FAIL'} {check['id']}")
    print(f"receipt={receipt_path.relative_to(ROOT)} ok={str(receipt['ok']).lower()}")
    return 1 if args.strict and not receipt["ok"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
