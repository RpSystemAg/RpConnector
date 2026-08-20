#!/usr/bin/env python3
"""Milestone-11 typed technical failure static contracts shared by release validators.

This module intentionally checks only properties that static source can prove.  A
missing proof is a failure for public reachability/annotation contracts; runtime,
hosting and production-only concerns remain WARN/NA in the exhaustive checkpoint.
Every text file is decoded explicitly as UTF-8 so Windows never falls back to the
active ANSI code page.
"""
from __future__ import annotations

import json
import re
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parent.parent
MCP = ROOT / "prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php"
LANES = ROOT / "prstudio-unified-control/includes/class-prstudio-uc-execution-lanes.php"
WORKBENCH = ROOT / "prstudio-unified-control/includes/class-prstudio-uc-engineering-workbench.php"
LEGACY_MCP = ROOT / "prstudio-unified-control/includes/class-wpaib-mcp.php"
LEGACY_EXECUTOR = ROOT / "prstudio-unified-control/includes/class-prstudio-uc-legacy-capability-executor.php"
CATALOG = ROOT / "prstudio-unified-control/connector/action-catalog.json"
CAPABILITY_FILES = (
    ROOT / "prstudio-unified-control/capabilities/capability-registry.json",
    ROOT / "prstudio-unified-control/capabilities/agency-capabilities.json",
)
PROTOCOL = ROOT / "prstudio-unified-browser-agent/lib/protocol.js"
SERVICE_WORKER = ROOT / "prstudio-unified-browser-agent/service-worker.js"


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="strict")


def document(path: Path) -> Any:
    return json.loads(text(path))


def brace_body(source: str, start: int) -> str:
    """Return a PHP/JS brace body while ignoring quoted braces."""
    opening = source.find("{", start)
    if opening < 0:
        return ""
    depth = 0
    quote = ""
    escaped = False
    for index in range(opening, len(source)):
        char = source[index]
        if escaped:
            escaped = False
            continue
        if quote:
            if char == "\\":
                escaped = True
            elif char == quote:
                quote = ""
            continue
        if char in {"'", '"', "`"}:
            quote = char
            continue
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return source[opening + 1:index]
    return source[opening + 1:]


def function_body(source: str, name: str) -> str:
    match = re.search(r"\bfunction\s+" + re.escape(name) + r"\s*\(", source)
    return brace_body(source, match.end()) if match else ""


def balanced_calls(source: str, marker: str) -> list[str]:
    """Return complete calls, including multiline/nested arguments."""
    calls: list[str] = []
    cursor = 0
    while True:
        start = source.find(marker, cursor)
        if start < 0:
            break
        opening = source.find("(", start + len(marker))
        if opening < 0:
            break
        depth = 0
        quote = ""
        escaped = False
        end = -1
        for index in range(opening, len(source)):
            char = source[index]
            if escaped:
                escaped = False
                continue
            if quote:
                if char == "\\":
                    escaped = True
                elif char == quote:
                    quote = ""
                continue
            if char in {"'", '"', "`"}:
                quote = char
                continue
            if char == "(":
                depth += 1
            elif char == ")":
                depth -= 1
                if depth == 0:
                    end = index + 1
                    break
        if end < 0:
            break
        calls.append(source[start:end])
        cursor = end
    return calls


def _direct_protocol_bindings(protocol: str) -> dict[str, set[str]]:
    marker = re.search(r"\bconst\s+direct\s*=\s*\{", protocol)
    body = brace_body(protocol, marker.start()) if marker else ""
    bindings: dict[str, set[str]] = {}
    # Slice each top-level entry at the next action key.  Most entries fit on one
    # line, while conditional bindings such as playwright_new_page span several.
    entries = list(re.finditer(r"^\s{4}([A-Za-z0-9_:-]+)\s*:\s*", body, re.M))
    for index, action in enumerate(entries):
        end = entries[index + 1].start() if index + 1 < len(entries) else len(body)
        entry = body[action.end():end]
        bindings[action.group(1)] = set(re.findall(r"\btype\s*:\s*['\"]([^'\"]+)['\"]", entry))
    return bindings


def audit_browser_reachability() -> tuple[list[str], dict[str, Any]]:
    protocol = text(PROTOCOL)
    worker = text(SERVICE_WORKER)
    bindings = _direct_protocol_bindings(protocol)
    step_body = function_body(worker, "executeStep")
    step_types = set(re.findall(r"\bcase\s+['\"]([^'\"]+)['\"]\s*:", step_body))
    failures: list[str] = []
    missing: dict[str, list[str]] = {}
    for action, emitted_types in sorted(bindings.items()):
        absent = sorted(emitted_types - step_types)
        if not emitted_types:
            failures.append(f"browser_direct_without_step:{action}")
        elif absent:
            missing[action] = absent
            failures.append(f"browser_direct_missing_executor:{action}:{','.join(absent)}")

    # This exact regression used to pass because protocol.js named verify_url even
    # though executeStep had no executor.  Both halves are mandatory.
    if bindings.get("verify_url") != {"verify_url"}:
        failures.append("browser_verify_url_protocol_binding_missing")
    if "verify_url" not in step_types:
        failures.append("browser_verify_url_service_worker_executor_missing")
    verify_match = re.search(r"\bcase\s+['\"]verify_url['\"]\s*:", step_body)
    verify_case = ""
    if verify_match:
        next_case = re.search(r"\bcase\s+['\"]", step_body[verify_match.end():])
        verify_end = verify_match.end() + next_case.start() if next_case else len(step_body)
        verify_case = step_body[verify_match.end():verify_end]
    if not all(marker in verify_case for marker in ("resolveTabId", "chrome.tabs.get", "step.url", "matched")):
        failures.append("browser_verify_url_executor_not_observational")
    return failures, {
        "direct_actions": len(bindings),
        "service_worker_step_types": len(step_types),
        "missing_step_bindings": missing,
        "verify_url_reachable": bindings.get("verify_url") == {"verify_url"} and "verify_url" in step_types,
        "verify_url_observational": all(marker in verify_case for marker in ("resolveTabId", "chrome.tabs.get", "step.url", "matched")),
    }


def audit_legacy_direct() -> tuple[list[str], dict[str, Any]]:
    capabilities: list[dict[str, Any]] = []
    for path in CAPABILITY_FILES:
        capabilities.extend(document(path).get("capabilities", []))
    direct = [cap for cap in capabilities if (cap.get("source") or {}).get("kind") == "legacy_direct_tool"]
    executor = text(LEGACY_EXECUTOR)
    legacy_mcp = text(LEGACY_MCP)
    compat = function_body(legacy_mcp, "call_tool_compat")
    failures: list[str] = []

    ids = [str(cap.get("id") or "") for cap in direct]
    tools = [str((cap.get("source") or {}).get("tool_name") or "") for cap in direct]
    if any(not value for value in ids):
        failures.append("legacy_direct_missing_capability_id")
    if any(not value for value in tools):
        failures.append("legacy_direct_missing_tool_name")
    if len(ids) != len(set(ids)):
        failures.append("legacy_direct_duplicate_capability_id")
    wrong_bindings = [
        str(cap.get("id") or "") for cap in direct
        if str(cap.get("executor") or "") != "PRSTUDIO_UC_Legacy_Capability_Executor::execute"
    ]
    failures.extend(f"legacy_direct_executor_binding_missing:{cap_id}" for cap_id in wrong_bindings)

    # A method_exists guard around a method that is not actually declared is not
    # an executor.  The compatibility adapter must expose one concrete target and
    # that target must fail with WP_Error rather than returning null for unknowns.
    if not compat:
        failures.append("legacy_direct_underlying_call_tool_compat_missing")
    else:
        compact = re.sub(r"\s+", "", compat)
        if re.search(r"\breturn\s+null\s*;", compat):
            failures.append("legacy_direct_call_tool_compat_null_result")
        if "WP_Error" not in compat or not any(
            marker in compat
            for marker in ("dispatch_enterprise_tool", "execute_backend_plan", "call_tool_result", "call_tool_compat_result", "self::call_tool")
        ):
            failures.append("legacy_direct_call_tool_compat_not_fail_closed")
        if compact in {"", "returnnull;"}:
            failures.append("legacy_direct_call_tool_compat_empty")

    legacy_body = function_body(executor, "execute")
    if "legacy_direct_tool" not in legacy_body:
        failures.append("legacy_direct_adapter_branch_missing")
    nullable_plan = re.search(
        r"return\s+PRSTUDIO_Agency::execute_backend_plan\s*\(", legacy_body
    )
    if nullable_plan:
        failures.append("legacy_direct_nullable_agency_fallback")
    if "prstudio_legacy_direct_executor_unavailable" not in legacy_body:
        failures.append("legacy_direct_typed_unavailable_error_missing")

    return failures, {
        "capabilities": len(direct),
        "unique_tool_names": len(set(tools)),
        "wrong_executor_bindings": len(wrong_bindings),
        "compat_method_present": bool(compat),
        "nullable_agency_fallback": bool(nullable_plan),
    }


def _semantic_read_action(action: str) -> bool:
    """High-confidence read classifier; ambiguous verbs intentionally opt out."""
    if "search_replace" in action or action.startswith(("get_or_create", "verify_and_")):
        return False
    return action.startswith((
        "status", "get_", "list_", "inspect_", "audit_", "search_", "verify_",
        "validate_", "preview_", "describe_", "count_", "read_", "query_", "health",
    ))


def audit_annotations() -> tuple[list[str], dict[str, Any]]:
    catalog = document(CATALOG).get("actions", [])
    by_action = {(str(row.get("route") or ""), str(row.get("action") or "")): row for row in catalog}
    capabilities: list[dict[str, Any]] = []
    for path in CAPABILITY_FILES:
        capabilities.extend(document(path).get("capabilities", []))
    failures: list[str] = []
    semantic_mislabels: list[str] = []
    parity_checked = 0

    mcp_source = text(MCP)
    mcp_tools_body = function_body(mcp_source, "build_tools") or function_body(mcp_source, "tools")
    tool_calls = balanced_calls(mcp_tools_body, "self::tool")
    declared_mcp = set(re.findall(r"self::tool\(\s*['\"]([A-Za-z0-9_:-]+)['\"]", mcp_tools_body))
    mcp_annotations: dict[str, tuple[bool, bool, bool, bool]] = {}
    for call in tool_calls:
        match = re.search(
            r"self::tool\(\s*['\"]([A-Za-z0-9_:-]+)['\"].*self::annotations\(([^)]*)\)",
            call,
            re.S,
        )
        if not match:
            continue
        raw = [part.strip().lower() for part in match.group(2).split(",") if part.strip()]
        defaults = [True, False, True, False]
        values = defaults[:]
        for index, value in enumerate(raw[:4]):
            if value not in {"true", "false"}:
                failures.append(f"annotation_mcp_nonliteral:{match.group(1)}:{index}")
                continue
            values[index] = value == "true"
        mcp_annotations[match.group(1)] = tuple(values)  # type: ignore[assignment]
    for missing in sorted(declared_mcp - set(mcp_annotations)):
        failures.append(f"annotation_mcp_missing:{missing}")
    for name, (read_only, destructive, _idempotent, _open_world) in sorted(mcp_annotations.items()):
        if read_only and destructive:
            failures.append(f"annotation_mcp_read_destructive:{name}")
    expected_mcp_read = {
        "prstudio_health": True,
        "prstudio_context_open": False,
        "engineering_repo_map": True,
        "engineering_validate": True,
        "browser_verify_url": True,
        "browser_snapshot": True,
        "browser_screenshot": True,
        "browser_open": False,
        "browser_click": False,
        "wordpress_content_transaction": False,
        "gsc_request_indexing": False,
    }
    for name, expected_read in expected_mcp_read.items():
        actual = mcp_annotations.get(name)
        if actual is None or actual[0] != expected_read:
            failures.append(f"annotation_mcp_semantic_drift:{name}")

    for row in catalog:
        action = str(row.get("action") or "")
        route = str(row.get("route") or "")
        for field in ("read_only", "destructive", "idempotent"):
            if not isinstance(row.get(field), bool):
                failures.append(f"annotation_catalog_missing_boolean:{route}::{action}:{field}")
        if _semantic_read_action(action) and not bool(row.get("read_only")):
            key = f"{route}::{action}"
            semantic_mislabels.append(key)
            failures.append(f"annotation_semantic_read_marked_write:{key}")
        if bool(row.get("read_only")) and bool(row.get("destructive")):
            failures.append(f"annotation_read_and_destructive:{route}::{action}")

    for cap in capabilities:
        cap_id = str(cap.get("id") or "")
        for field in ("read_only", "write", "destructive", "idempotent"):
            if not isinstance(cap.get(field), bool):
                failures.append(f"annotation_capability_missing_boolean:{cap_id}:{field}")
        read_only = bool(cap.get("read_only"))
        write = bool(cap.get("write"))
        destructive = bool(cap.get("destructive"))
        if read_only == write:
            failures.append(f"annotation_capability_read_write_drift:{cap_id}")
        if read_only and destructive:
            failures.append(f"annotation_capability_read_destructive:{cap_id}")
        source = cap.get("source") or {}
        if source.get("kind") != "legacy_action":
            continue
        key = (str(source.get("route") or ""), str(source.get("action") or ""))
        row = by_action.get(key)
        if row is None:
            failures.append(f"annotation_capability_catalog_target_missing:{cap_id}")
            continue
        parity_checked += 1
        for field in ("read_only", "destructive", "idempotent"):
            if bool(cap.get(field)) != bool(row.get(field)):
                failures.append(f"annotation_catalog_capability_drift:{cap_id}:{field}")

    inspect_key = ("/themes-manage", "inspect_theme_assets")
    inspect_action = by_action.get(inspect_key)
    inspect_caps = [
        cap for cap in capabilities
        if (cap.get("source") or {}).get("route") == inspect_key[0]
        and (cap.get("source") or {}).get("action") == inspect_key[1]
    ]
    if not inspect_action or not bool(inspect_action.get("read_only")) or bool(inspect_action.get("destructive")):
        failures.append("inspect_theme_assets_catalog_not_read_only")
    for cap in inspect_caps:
        if (
            not bool(cap.get("read_only"))
            or bool(cap.get("write"))
            or bool(cap.get("destructive"))
            or str(cap.get("risk_level") or "").lower() not in {"low", "read"}
        ):
            failures.append("inspect_theme_assets_capability_not_read_only_low_risk")
    if len(inspect_caps) != 1:
        failures.append(f"inspect_theme_assets_capability_count:{len(inspect_caps)}")

    return failures, {
        "catalog_actions": len(catalog),
        "capabilities": len(capabilities),
        "catalog_capability_parity_checked": parity_checked,
        "mcp_tools_declared": len(declared_mcp),
        "mcp_annotations_parsed": len(mcp_annotations),
        "semantic_read_mislabels": semantic_mislabels,
        "inspect_theme_assets_contract_ok": not any(item.startswith("inspect_theme_assets_") for item in failures),
    }


def audit_lane_handle() -> tuple[list[str], dict[str, Any]]:
    mcp = text(MCP)
    lanes = text(LANES)
    failures: list[str] = []
    open_body = function_body(lanes, "open")
    call_body = function_body(mcp, "call_tool")
    tool_body = function_body(mcp, "tool")
    if "lane_handle" not in open_body:
        failures.append("lane_handle_not_returned_by_context_open")
    if "lane_handle" not in tool_body and "lane_handle" not in function_body(mcp, "tools"):
        failures.append("lane_handle_missing_from_mcp_tool_schemas")
    if "lane_handle" not in call_body:
        failures.append("lane_handle_not_resolved_by_call_tool")
    if "lane_token" not in call_body:
        failures.append("lane_token_additive_compatibility_removed")
    # Additive compatibility may be represented with JSON Schema anyOf or by a
    # helper that injects equivalent one-of-two credential requirements.
    has_additive_schema = (
        "anyOf" in tool_body
        or "anyOf" in function_body(mcp, "tools")
        or any(marker in mcp for marker in ("lane_credential_schema", "lane_credentials", "require_lane_credential"))
    )
    if not has_additive_schema:
        failures.append("lane_handle_or_legacy_token_schema_missing")
    if not any(marker in lanes for marker in ("resolve_handle", "handle_hash", "lane_handle")):
        failures.append("lane_handle_internal_mapping_missing")
    return failures, {
        "context_open_returns_handle": "lane_handle" in open_body,
        "call_tool_resolves_handle": "lane_handle" in call_body,
        "legacy_token_preserved": "lane_token" in call_body,
        "additive_schema": has_additive_schema,
    }


def audit_engineering_contracts() -> tuple[list[str], dict[str, Any]]:
    source = text(WORKBENCH)
    failures: list[str] = []
    repo_decl = re.search(r"public\s+static\s+function\s+repo_map\s*\([^)]*\)\s*([^\{]*)\{", source)
    repo_tail = (repo_decl.group(1) if repo_decl else "").replace(" ", "")
    repo_body = function_body(source, "repo_map")
    if not repo_decl:
        failures.append("engineering_repo_map_missing")
    elif ":array" in repo_tail and "WP_Error" not in repo_tail:
        # Returning WP_Error from a PHP `: array` function raises TypeError before
        # the caller receives the promised typed error.
        failures.append("engineering_repo_map_wp_error_violates_return_type")
    if "engineering_path_invalid" not in repo_body or "WP_Error" not in repo_body:
        failures.append("engineering_repo_map_typed_path_error_missing")

    process_body = function_body(source, "run_process")
    # proc_get_status may reap a process on Windows, leaving proc_close() == -1.
    # The wrapper must preserve the last non-negative status exit code and use it
    # when proc_close cannot provide one.
    captures_status_exit = bool(re.search(r"\[['\"]exitcode['\"]\]", process_body))
    has_close_fallback = (
        "proc_close" in process_body
        and captures_status_exit
        and bool(re.search(r"(?:-1|<\s*0|>=\s*0).*exit", process_body, re.S))
    )
    if not has_close_fallback:
        failures.append("engineering_php_lint_windows_exit_code_fallback_missing")
    return failures, {
        "repo_map_typed_error": "engineering_repo_map_wp_error_violates_return_type" not in failures,
        "php_lint_windows_exit_fallback": has_close_fallback,
    }


def audit_contracts() -> tuple[list[str], dict[str, Any]]:
    failures: list[str] = []
    evidence: dict[str, Any] = {}
    for name, audit in (
        ("browser_reachability", audit_browser_reachability),
        ("legacy_direct", audit_legacy_direct),
        ("annotations", audit_annotations),
        ("lane_handle", audit_lane_handle),
        ("engineering", audit_engineering_contracts),
    ):
        current, detail = audit()
        failures.extend(current)
        evidence[name] = detail
    return sorted(set(failures)), evidence


def main() -> int:
    failures, evidence = audit_contracts()
    print(json.dumps({
        "ok": not failures,
        "failure_count": len(failures),
        "failures": failures,
        "evidence": evidence,
        "policy": "Public wrappers, underlying executors, lane credentials and annotations are typed technical failure.",
    }, ensure_ascii=False, indent=2, sort_keys=True))
    return 0 if not failures else 1


if __name__ == "__main__":
    raise SystemExit(main())
