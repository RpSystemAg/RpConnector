#!/usr/bin/env python3
"""Atomic assurance: one inventory item -> one implementation -> one executable test -> one oracle.

The runner never treats registry presence as implementation evidence and never
uses a suite-level green result as coverage for an individual item.
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
CAP_DIR = ROOT / "prstudio-unified-control" / "capabilities"
CASE_FILE = ROOT / "quality" / "atomic-cases.json"
SOURCE_MAP_FILE = ROOT / "quality" / "official-source-map.json"
BUILD_INFO = ROOT / "prstudio-unified-control" / "BUILD-INFO.json"
OUT = ROOT / "evidence" / "production"

STRONG_ID_KEYS = ("capability_id", "action_id", "tool_name", "ability_id", "operation_id")
WEAK_ID_KEYS = ("id", "name", "slug")
EXEC_MARKERS = {
    "executor", "handler", "callback", "implementation", "input_schema", "output_schema",
    "inputSchema", "outputSchema", "permission_callback", "risk", "side_effects", "verification",
    "arguments", "parameters", "readonly", "destructive", "idempotent", "domain", "kind",
}
GENERIC = {"string", "integer", "number", "boolean", "array", "object", "properties", "required", "items", "type", "schema"}
CODE_SUFFIXES = {".php", ".js", ".mjs", ".cjs", ".py"}
STRONG_STUB_RE = re.compile(r"\b(?:not[_ -]?implemented|coming[_ -]?soon|placeholder[_ -]?success|dummy[_ -]?implementation)\b", re.I)
CALL_RE = re.compile(r"(?:->|::|\b)[A-Za-z_][A-Za-z0-9_]*\s*\(")


def iso_now() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def stable_case_id(item_id: str) -> str:
    digest = hashlib.sha256(item_id.encode()).hexdigest()[:12]
    safe = re.sub(r"[^A-Za-z0-9_.-]+", "_", item_id)[:96]
    return f"atomic::{safe}::{digest}"


def plausible(value: Any) -> bool:
    if not isinstance(value, str):
        return False
    value = value.strip()
    return 2 <= len(value) <= 220 and value.lower() not in GENERIC and bool(re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9_./:@-]*", value))


def kind(path: Path, node: dict[str, Any]) -> str:
    text = (path.name + " " + str(node.get("kind", "")) + " " + str(node.get("domain", ""))).lower()
    if "browser" in text:
        return "browser_action"
    if "ability" in text:
        return "wordpress_ability"
    if "action" in text:
        return "catalog_action"
    if "tool" in text:
        return "tool"
    return "capability"


def extract(path: Path, data: Any) -> list[dict[str, Any]]:
    found: list[dict[str, Any]] = []

    def add(item_id: str, node: dict[str, Any], pointer: str) -> None:
        found.append({"id": item_id, "kind": kind(path, node), "file": str(path.relative_to(ROOT)), "pointer": pointer})

    def walk(node: Any, pointer: str = "$") -> None:
        if isinstance(node, dict):
            for key in STRONG_ID_KEYS:
                if plausible(node.get(key)):
                    add(str(node[key]), node, pointer)
                    break
            else:
                marker_count = len(set(node) & EXEC_MARKERS)
                if marker_count >= 2:
                    for key in WEAK_ID_KEYS:
                        if plausible(node.get(key)):
                            value = str(node[key])
                            if any(ch in value for ch in ("_", "-", ".", "/", ":")):
                                add(value, node, pointer)
                                break
            for key, child in node.items():
                if plausible(key) and isinstance(child, dict) and len(set(child) & EXEC_MARKERS) >= 2:
                    if any(ch in key for ch in ("_", "-", ".", "/", ":")):
                        add(key, child, f"{pointer}.{key}")
                walk(child, f"{pointer}.{key}")
        elif isinstance(node, list):
            for index, child in enumerate(node):
                walk(child, f"{pointer}[{index}]")

    walk(data)
    return found


def load_inventory() -> tuple[dict[str, dict[str, Any]], list[str], dict[str, int]]:
    errors: list[str] = []
    all_items: dict[str, dict[str, Any]] = {}
    per_file: dict[str, set[str]] = {}
    candidates = sorted(CAP_DIR.rglob("*.json")) if CAP_DIR.exists() else []
    candidates = [p for p in candidates if any(token in p.name.lower() for token in ("registry", "catalog", "contract", "surface", "index"))]
    if not candidates:
        errors.append("no capability inventory JSON files found")

    for path in candidates:
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
        except Exception as exc:
            errors.append(f"cannot parse {path.relative_to(ROOT)}: {exc}")
            continue
        rel = str(path.relative_to(ROOT))
        per_file.setdefault(rel, set())
        for item in extract(path, data):
            item_id = item["id"]
            per_file[rel].add(item_id)
            existing = all_items.get(item_id)
            if existing is None:
                all_items[item_id] = item | {"declarations": [item["file"]]}
            elif item["file"] not in existing["declarations"]:
                existing["declarations"].append(item["file"])

    # Public MCP tools are executable inventory even if they are not duplicated in generated JSON.
    mcp = ROOT / "prstudio-unified-control" / "includes" / "class-prstudio-uc-mcp-v5.php"
    if mcp.exists():
        text = mcp.read_text(encoding="utf-8", errors="replace")
        for match in re.finditer(r"['\"]name['\"]\s*=>\s*['\"]([A-Za-z][A-Za-z0-9_.-]{2,100})['\"]", text):
            item_id = match.group(1)
            if item_id.startswith(("prstudio_", "browser_")):
                all_items.setdefault(item_id, {
                    "id": item_id,
                    "kind": "mcp_tool",
                    "file": str(mcp.relative_to(ROOT)),
                    "pointer": f"source@{match.start()}",
                    "declarations": [str(mcp.relative_to(ROOT))],
                })

    return all_items, errors, {name: len(ids) for name, ids in per_file.items()}


def find_number(node: Any, wanted: str) -> int | None:
    if isinstance(node, dict):
        if wanted in node and isinstance(node[wanted], int):
            return node[wanted]
        for value in node.values():
            result = find_number(value, wanted)
            if result is not None:
                return result
    elif isinstance(node, list):
        for value in node:
            result = find_number(value, wanted)
            if result is not None:
                return result
    return None


def expected_capabilities() -> int | None:
    if not BUILD_INFO.exists():
        return None
    try:
        return find_number(json.loads(BUILD_INFO.read_text(encoding="utf-8")), "capability_count")
    except Exception:
        return None


def normalize_implementations(value: Any) -> list[dict[str, str]]:
    if isinstance(value, str):
        return [{"file": value}]
    if isinstance(value, dict):
        return [{str(k): str(v) for k, v in value.items() if v is not None}]
    if isinstance(value, list):
        out = []
        for entry in value:
            if isinstance(entry, str):
                out.append({"file": entry})
            elif isinstance(entry, dict):
                out.append({str(k): str(v) for k, v in entry.items() if v is not None})
        return out
    return []


def inspect_implementation(case: dict[str, Any]) -> tuple[bool, list[dict[str, Any]]]:
    details = []
    ok = True
    for entry in normalize_implementations(case.get("implementation")):
        rel = entry.get("file", "").lstrip("/")
        path = ROOT / rel
        detail: dict[str, Any] = {"file": rel, "exists": path.is_file()}
        if not path.is_file() or path.suffix.lower() not in CODE_SUFFIXES:
            detail["error"] = "missing or non-code implementation file"
            ok = False
            details.append(detail)
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        symbol = entry.get("symbol", "")
        detail.update({
            "bytes": path.stat().st_size,
            "symbol": symbol,
            "symbol_found": not symbol or symbol in text,
            "strong_stub_marker": bool(STRONG_STUB_RE.search(text)),
            "call_sites": len(CALL_RE.findall(text)),
        })
        small = path.stat().st_size < 1024
        delegate = str(entry.get("delegate") or case.get("delegate") or "").strip()
        detail["small_under_1k"] = small
        detail["delegate_declared"] = bool(delegate)
        detail["substance_ok"] = bool(detail["symbol_found"] and not detail["strong_stub_marker"] and (not small or detail["call_sites"] >= 1 or delegate))
        if not detail["substance_ok"]:
            ok = False
        details.append(detail)
    if not details:
        ok = False
        details.append({"error": "no concrete implementation mapping"})
    return ok, details


def docs_ok(case: dict[str, Any], sources: dict[str, Any]) -> tuple[bool, dict[str, Any]]:
    contract = str(case.get("contract", "")).strip()
    contract_path = contract.split("#", 1)[0] if contract else ""
    contract_exists = bool(contract_path and (ROOT / contract_path).is_file())
    refs = case.get("official_refs") if isinstance(case.get("official_refs"), list) else []
    missing = [ref for ref in refs if ref not in sources]
    return bool(contract_exists and refs and not missing), {
        "contract": contract,
        "contract_exists": contract_exists,
        "official_refs": refs,
        "unknown_official_refs": missing,
    }


def commit_sha() -> str:
    value = os.environ.get("GITHUB_SHA", "").strip()
    if value:
        return value
    try:
        return subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip()
    except Exception:
        return ""


def validate_environment_evidence(case: dict[str, Any], commit: str) -> tuple[bool, dict[str, Any]]:
    environment = str(case.get("environment", "")).lower()
    value = case.get("environment_evidence")
    if environment in {"pure", "local", "source"} and value in {"runner", "atomic_runner"}:
        return True, {"class": environment, "source": value}
    if not isinstance(value, str) or not value:
        return False, {"class": environment, "error": "missing environment evidence receipt"}
    path = ROOT / value
    if not path.is_file():
        return False, {"class": environment, "receipt": value, "error": "receipt missing"}
    try:
        receipt = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        return False, {"class": environment, "receipt": value, "error": str(exc)}
    valid = receipt.get("ok") is True and (not commit or receipt.get("commit_sha") == commit)
    return valid, {"class": environment, "receipt": value, "receipt_ok": receipt.get("ok"), "receipt_commit": receipt.get("commit_sha")}


def execute_case(item_id: str, case: dict[str, Any], commit: str) -> tuple[bool, dict[str, Any]]:
    runner = case.get("runner")
    if not isinstance(runner, dict) or not isinstance(runner.get("command"), list) or not runner["command"]:
        return False, {"error": "runner.command must be a non-empty argv array"}
    command = [str(part) for part in runner["command"]]
    timeout = int(case.get("timeout_seconds", 60))
    env = os.environ.copy()
    env["RP_ATOMIC_ITEM_ID"] = item_id
    env["RP_ATOMIC_CASE_ID"] = stable_case_id(item_id)
    env["RP_RELEASE_COMMIT"] = commit
    try:
        proc = subprocess.run(command, cwd=ROOT, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=timeout)
    except subprocess.TimeoutExpired:
        return False, {"command": command, "timeout": timeout, "error": "timeout"}
    except Exception as exc:
        return False, {"command": command, "error": f"{type(exc).__name__}: {exc}"}

    evidence_rel = str(case.get("evidence_file", "")).strip()
    evidence_path = ROOT / evidence_rel if evidence_rel else None
    evidence: dict[str, Any] = {}
    evidence_error = ""
    if not evidence_path or not evidence_path.is_file():
        evidence_error = "runner evidence_file missing"
    else:
        try:
            evidence = json.loads(evidence_path.read_text(encoding="utf-8"))
        except Exception as exc:
            evidence_error = f"invalid runner evidence JSON: {exc}"
    evidence_ok = bool(
        not evidence_error
        and evidence.get("item_id") == item_id
        and evidence.get("ok") is True
        and evidence.get("oracle_observed") is True
        and (not commit or evidence.get("commit_sha") == commit)
        and evidence.get("negative_cases_passed") is True
    )
    if any(key in case for key in ("rollback", "idempotency")):
        evidence_ok = evidence_ok and evidence.get("rollback_verified") is True and evidence.get("idempotency_verified") is True
    return proc.returncode == 0 and evidence_ok, {
        "command": command,
        "returncode": proc.returncode,
        "output_tail": proc.stdout[-4000:],
        "evidence_file": evidence_rel,
        "evidence_error": evidence_error,
        "evidence_ok": evidence_ok,
    }


def artifact_sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--execute", action="store_true", help="execute every exact case selected for this shard")
    parser.add_argument("--strict", action="store_true")
    parser.add_argument("--shard-index", type=int, default=0)
    parser.add_argument("--shard-count", type=int, default=1)
    args = parser.parse_args()
    started = iso_now()
    if args.shard_count < 1 or not 0 <= args.shard_index < args.shard_count:
        print("invalid shard parameters", file=sys.stderr)
        return 2

    case_config = json.loads(CASE_FILE.read_text(encoding="utf-8"))
    cases = case_config.get("cases", {}) if isinstance(case_config.get("cases"), dict) else {}
    source_map = json.loads(SOURCE_MAP_FILE.read_text(encoding="utf-8"))
    sources = source_map.get("sources", {})
    items, inventory_errors, per_file = load_inventory()
    expected = expected_capabilities()
    commit = commit_sha()

    selected_ids = [item_id for index, item_id in enumerate(sorted(items)) if index % args.shard_count == args.shard_index]
    rows: list[dict[str, Any]] = []
    required_fields = case_config.get("required_fields", [])

    for item_id in selected_ids:
        case = cases.get(item_id)
        row: dict[str, Any] = {"item_id": item_id, "case_id": stable_case_id(item_id), "inventory": items[item_id], "case_present": isinstance(case, dict)}
        if not isinstance(case, dict):
            row.update({"implemented": False, "test_defined": False, "oracle_defined": False, "docs_traced": False, "environment_proven": False, "executed": False, "passed": False, "errors": ["missing exact atomic case"]})
            rows.append(row)
            continue

        missing_fields = [field for field in required_fields if field not in case or case.get(field) in (None, "", [], {})]
        impl_ok, impl_detail = inspect_implementation(case)
        doc_ok, doc_detail = docs_ok(case, sources)
        env_ok, env_detail = validate_environment_evidence(case, commit)
        oracle = case.get("oracle")
        oracle_ok = bool(oracle and str(oracle).lower() not in {"ok", "success", "executor_success", "self_report"})
        runner_defined = isinstance(case.get("runner"), dict) and isinstance(case["runner"].get("command"), list) and bool(case["runner"]["command"])
        execution_ok = False
        execution_detail: dict[str, Any] = {"not_run": True}
        if args.execute and not missing_fields and impl_ok and doc_ok and oracle_ok and runner_defined:
            execution_ok, execution_detail = execute_case(item_id, case, commit)
        row_errors = []
        if missing_fields:
            row_errors.append("missing fields: " + ", ".join(missing_fields))
        if not impl_ok:
            row_errors.append("implementation not concretely resolved/substantive")
        if not oracle_ok:
            row_errors.append("independent oracle is missing or self-referential")
        if not doc_ok:
            row_errors.append("RP contract or official documentation trace is incomplete")
        if not env_ok:
            row_errors.append("required environment evidence is missing/invalid")
        if args.execute and not execution_ok:
            row_errors.append("individual execution/oracle did not pass")
        row.update({
            "implemented": impl_ok,
            "implementation": impl_detail,
            "test_defined": runner_defined,
            "oracle_defined": oracle_ok,
            "docs_traced": doc_ok,
            "docs": doc_detail,
            "environment_proven": env_ok,
            "environment_evidence": env_detail,
            "executed": args.execute and not execution_detail.get("not_run", False),
            "execution": execution_detail,
            "passed": bool(not missing_fields and impl_ok and oracle_ok and doc_ok and env_ok and runner_defined and (execution_ok if args.execute else True)),
            "errors": row_errors,
        })
        rows.append(row)

    all_inventory_ids = set(items)
    orphan_cases = sorted(set(cases) - all_inventory_ids)
    missing_cases = sorted(all_inventory_ids - set(cases))
    case_rows = [row for row in rows if row.get("case_present")]

    registry_count = 0
    for filename, count in per_file.items():
        if filename.endswith("capability-registry.json"):
            registry_count = count
            break
    inventory_complete = not inventory_errors and bool(items) and (expected is None or registry_count == expected)
    implementation_resolved = bool(rows) and all(row.get("implemented") for row in rows)
    one_case = not missing_cases and not orphan_cases and len(cases) == len(all_inventory_ids)
    oracle_all = bool(case_rows) and all(row.get("oracle_defined") for row in case_rows) and one_case
    docs_all = bool(case_rows) and all(row.get("docs_traced") for row in case_rows) and one_case
    no_stub = bool(case_rows) and all(row.get("implemented") for row in case_rows) and one_case
    env_all = bool(case_rows) and all(row.get("environment_proven") for row in case_rows) and one_case
    if args.execute:
        env_all = env_all and all(row.get("passed") for row in rows)

    checks = [
        {"id": "inventory_complete", "ok": inventory_complete, "evidence": {"unique_items": len(items), "registry_items": registry_count, "build_expected_capabilities": expected, "per_inventory_file": per_file, "errors": inventory_errors}},
        {"id": "implementation_resolved", "ok": implementation_resolved, "evidence": {"resolved": sum(bool(r.get('implemented')) for r in rows), "selected": len(rows)}},
        {"id": "one_exact_case_per_operational_item", "ok": one_case, "evidence": {"inventory": len(all_inventory_ids), "cases": len(cases), "missing_count": len(missing_cases), "orphan_count": len(orphan_cases), "missing_sample": missing_cases[:100], "orphan_sample": orphan_cases[:100]}},
        {"id": "independent_oracle_per_item", "ok": oracle_all, "evidence": {"covered": sum(bool(r.get('oracle_defined')) for r in case_rows), "cases": len(case_rows)}},
        {"id": "official_docs_traced", "ok": docs_all, "evidence": {"covered": sum(bool(r.get('docs_traced')) for r in case_rows), "cases": len(case_rows)}},
        {"id": "zero_stub_operational_items", "ok": no_stub, "evidence": {"substantive": sum(bool(r.get('implemented')) for r in case_rows), "cases": len(case_rows)}},
        {"id": "required_environment_evidence_present", "ok": env_all, "evidence": {"environment_proven": sum(bool(r.get('environment_proven')) for r in case_rows), "cases": len(case_rows), "execution_required": args.execute}},
    ]

    OUT.mkdir(parents=True, exist_ok=True)
    suffix = "" if args.shard_count == 1 else f"-shard-{args.shard_index:03d}-of-{args.shard_count:03d}"
    ledger_path = OUT / f"atomic-capability-ledger{suffix}.json"
    ledger = {
        "schema_version": 1,
        "commit_sha": commit,
        "shard_index": args.shard_index,
        "shard_count": args.shard_count,
        "execute": args.execute,
        "inventory_total": len(items),
        "selected_total": len(rows),
        "rows": rows,
    }
    ledger_path.write_text(json.dumps(ledger, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    receipt = {
        "schema_version": 1,
        "gate_id": "atomic_capability_coverage",
        "commit_sha": commit,
        "ok": all(check["ok"] is True for check in checks),
        "started_at": started,
        "finished_at": iso_now(),
        "environment": {"real": False, "class": "atomic_test_orchestrator", "execution_enabled": args.execute},
        "checks": checks,
        "artifacts": [{"path": str(ledger_path.relative_to(ROOT)), "sha256": artifact_sha(ledger_path)}],
        "waivers": [],
        "skipped": [],
    }
    receipt_path = OUT / f"atomic-capability-coverage{suffix}.json"
    receipt_path.write_text(json.dumps(receipt, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print("ATOMIC CAPABILITY ASSURANCE")
    print(f"inventory={len(items)} cases={len(cases)} selected={len(rows)} execute={args.execute}")
    for check in checks:
        print(f"{'PASS' if check['ok'] else 'FAIL'} {check['id']}")
    print(f"receipt={receipt_path.relative_to(ROOT)} ok={str(receipt['ok']).lower()}")
    return 1 if args.strict and not receipt["ok"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
