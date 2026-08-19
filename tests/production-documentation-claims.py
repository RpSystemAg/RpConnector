#!/usr/bin/env python3
"""Certify documentation/claim consistency against executable source and evidence.

Documentation does not count as proof by itself. Official source-map validity,
atomic item traceability, generated counts, versions/endpoints and any positive
production claim are checked against source or exact-SHA evidence.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
from pathlib import Path
from urllib.parse import urlparse
from datetime import datetime, timezone
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / "evidence" / "production"
SOURCE_MAP = ROOT / "quality" / "official-source-map.json"
ATOMIC = OUT / "atomic-capability-coverage.json"
BUILD = ROOT / "prstudio-unified-control" / "BUILD-INFO.json"
DESCRIPTOR = ROOT / "RP-STUDIO-CHATGPT-PLUGIN-1.0.0.json"
PLUGIN_BOOTSTRAP = ROOT / "prstudio-unified-control" / "prstudio-unified-control.php"
BROWSER_MANIFEST = ROOT / "prstudio-unified-browser-agent" / "manifest.json"
MCP_SOURCE = ROOT / "prstudio-unified-control" / "includes" / "class-prstudio-uc-mcp-v5.php"


def now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def git_head() -> str:
    value = os.environ.get("GITHUB_SHA", "").strip()
    if value:
        return value
    try:
        return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True, stderr=subprocess.DEVNULL).strip()
    except Exception:
        return ""


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def command(command: list[str]) -> tuple[bool, str]:
    proc = subprocess.run(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300)
    return proc.returncode == 0, proc.stdout[-12000:]


def official_source_map_check() -> tuple[bool, dict[str, Any]]:
    value = json.loads(SOURCE_MAP.read_text(encoding="utf-8"))
    allowed = set(value.get("policy", {}).get("official_domains", []))
    sources = value.get("sources") if isinstance(value.get("sources"), dict) else {}
    errors: list[str] = []
    for source_id, source in sources.items():
        if not isinstance(source, dict):
            errors.append(f"{source_id}: source is not object")
            continue
        url = str(source.get("url", ""))
        parsed = urlparse(url)
        if parsed.scheme != "https" or parsed.hostname not in allowed:
            errors.append(f"{source_id}: URL not on allowed official HTTPS domain: {url}")
        if not source.get("authority") or not source.get("type") or not source.get("covers"):
            errors.append(f"{source_id}: authority/type/covers incomplete")
    rules = value.get("classification_rules")
    if not isinstance(rules, list) or not rules:
        errors.append("classification_rules missing")
    else:
        for rule in rules:
            for source_id in rule.get("source_ids", []):
                if source_id not in sources:
                    errors.append(f"classification {rule.get('id')} references unknown source {source_id}")
    return bool(sources) and not errors, {"source_count": len(sources), "allowed_domains": sorted(allowed), "errors": errors}


def atomic_trace_check(commit: str) -> tuple[bool, dict[str, Any]]:
    if not ATOMIC.is_file():
        return False, {"error": "atomic capability production receipt missing"}
    try:
        receipt = json.loads(ATOMIC.read_text(encoding="utf-8"))
    except Exception as exc:
        return False, {"error": f"atomic receipt invalid JSON: {exc}"}
    checks = {str(c.get("id")): c for c in receipt.get("checks", []) if isinstance(c, dict)}
    required = ("one_exact_case_per_operational_item", "official_docs_traced")
    ok = receipt.get("commit_sha") == commit and all(checks.get(name, {}).get("ok") is True for name in required)
    return ok, {
        "receipt": str(ATOMIC.relative_to(ROOT)),
        "receipt_commit": receipt.get("commit_sha"),
        "required_checks": {name: checks.get(name) for name in required},
    }


def counts_check() -> tuple[bool, dict[str, Any]]:
    drift_ok, drift_output = command(["node", ".github/scripts/check-mcp-tool-drift.mjs"])
    count_ok, count_output = command(["node", ".github/scripts/check-build-info-counts.mjs"])
    return drift_ok and count_ok, {"mcp_tool_drift": drift_output, "build_info_counts": count_output}


def versions_check() -> tuple[bool, dict[str, Any]]:
    build = json.loads(BUILD.read_text(encoding="utf-8"))
    descriptor = json.loads(DESCRIPTOR.read_text(encoding="utf-8"))
    browser = json.loads(BROWSER_MANIFEST.read_text(encoding="utf-8"))
    bootstrap = PLUGIN_BOOTSTRAP.read_text(encoding="utf-8", errors="replace")
    match = re.search(r"^\s*\*\s*Version:\s*([^\s]+)", bootstrap, re.M)
    plugin_version = match.group(1) if match else ""
    expected_control = str(build.get("version", ""))
    expected_browser = str(build.get("browser_live_webrtc", {}).get("version", ""))
    values = {
        "plugin_header": plugin_version,
        "build_control": expected_control,
        "descriptor": str(descriptor.get("version", "")),
        "browser_manifest": str(browser.get("version", "")),
        "build_browser": expected_browser,
    }
    ok = bool(expected_control and expected_browser) and plugin_version == expected_control == values["descriptor"] and values["browser_manifest"] == expected_browser
    return ok, values


def endpoints_check() -> tuple[bool, dict[str, Any]]:
    build = json.loads(BUILD.read_text(encoding="utf-8"))
    descriptor = json.loads(DESCRIPTOR.read_text(encoding="utf-8"))
    source = MCP_SOURCE.read_text(encoding="utf-8", errors="replace")
    expected_mcp = "/wp-json/prstudio-unified/v1/mcp"
    expected_pair = "/wp-json/prstudio-unified/v1/pair"
    descriptor_mcp = str(descriptor.get("server_url_template", ""))
    install = descriptor.get("installation_contract", {}) if isinstance(descriptor.get("installation_contract"), dict) else {}
    browser_install = install.get("browser_agent", {}) if isinstance(install.get("browser_agent"), dict) else {}
    descriptor_pair = str(browser_install.get("pair_endpoint", ""))
    build_mcp = str(build.get("mcp_endpoint", ""))
    mcp_source_ok = "prstudio-unified/v1" in source and "register_rest_route" in source and "mcp" in source
    checks = {
        "expected_mcp": expected_mcp,
        "descriptor_mcp": descriptor_mcp,
        "build_mcp": build_mcp,
        "expected_pair": expected_pair,
        "descriptor_pair": descriptor_pair,
        "mcp_source_route_present": mcp_source_ok,
    }
    ok = expected_mcp in descriptor_mcp and expected_mcp in build_mcp and descriptor_pair == expected_pair and mcp_source_ok
    return ok, checks


def production_claim_check(commit: str) -> tuple[bool, dict[str, Any]]:
    descriptor = json.loads(DESCRIPTOR.read_text(encoding="utf-8"))
    claimed = descriptor.get("production_proven") is True
    verdict_path = OUT / "production-readiness-verdict.json"
    verdict = None
    if verdict_path.is_file():
        try:
            verdict = json.loads(verdict_path.read_text(encoding="utf-8"))
        except Exception:
            verdict = None
    # Conservative false claims never exceed evidence. Positive source claims do.
    if not claimed:
        return True, {"descriptor_production_proven": False, "rule": "conservative false claim does not exceed evidence"}
    supported = bool(verdict and verdict.get("commit_sha") == commit and verdict.get("production_ready") is True)
    return supported, {
        "descriptor_production_proven": True,
        "verdict_present": verdict is not None,
        "verdict_commit": verdict.get("commit_sha") if verdict else None,
        "verdict_production_ready": verdict.get("production_ready") if verdict else None,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--commit", default="")
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()
    commit = args.commit.strip() or git_head()
    started = now()

    source_ok, source_ev = official_source_map_check()
    trace_ok, trace_ev = atomic_trace_check(commit)
    counts_ok, counts_ev = counts_check()
    versions_ok, versions_ev = versions_check()
    endpoints_ok, endpoints_ev = endpoints_check()
    claim_ok, claim_ev = production_claim_check(commit)
    checks = [
        {"id": "official_source_map_valid", "ok": source_ok, "evidence": source_ev},
        {"id": "all_operational_items_document_traced", "ok": trace_ok, "evidence": trace_ev},
        {"id": "counts_match_generated_manifest", "ok": counts_ok, "evidence": counts_ev},
        {"id": "versions_match_source", "ok": versions_ok, "evidence": versions_ev},
        {"id": "endpoints_match_source", "ok": endpoints_ok, "evidence": endpoints_ev},
        {"id": "production_claim_matches_verdict", "ok": claim_ok, "evidence": claim_ev},
    ]

    OUT.mkdir(parents=True, exist_ok=True)
    detail = {"schema_version": 1, "commit_sha": commit, "checks": checks}
    detail_path = OUT / "documentation-claims-details.json"
    detail_path.write_text(json.dumps(detail, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    result = {
        "schema_version": 1,
        "gate_id": "documentation_claims",
        "commit_sha": commit,
        "ok": all(check["ok"] is True for check in checks),
        "started_at": started,
        "finished_at": now(),
        "environment": {"real": False, "class": "source-and-exact-sha-evidence"},
        "checks": checks,
        "artifacts": [{"path": str(detail_path.relative_to(ROOT)), "sha256": sha256(detail_path)}],
        "waivers": [],
        "skipped": [],
    }
    receipt_path = OUT / "documentation-claims.json"
    receipt_path.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print("PRODUCTION DOCUMENTATION CLAIMS")
    for check in checks:
        print(f"{'PASS' if check['ok'] else 'FAIL'} {check['id']}")
    print(f"receipt={receipt_path.relative_to(ROOT)} ok={str(result['ok']).lower()}")
    return 1 if args.strict and not result["ok"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
