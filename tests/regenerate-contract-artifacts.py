#!/usr/bin/env python3
"""Deterministically align generated contract metadata with runtime sources.

The historical package shipped no generator for these artifacts.  This script
keeps the large JSON/PHP indexes reproducible and prevents runtime normalization
from concealing stale authorization metadata on disk.
"""

from __future__ import annotations

import argparse
import copy
import hashlib
import json
import subprocess
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parent.parent
CONTROL = ROOT / "prstudio-unified-control"
CATALOG = CONTROL / "connector" / "action-catalog.json"
HOT_JSON = CONTROL / "contract" / "action-hot-index.json"
HOT_PHP = CONTROL / "contract" / "action-hot-index.php"
CONTRACT = CONTROL / "contract" / "capability-contract.json"
REGISTRY = CONTROL / "capabilities" / "capability-registry.json"
SEARCH = CONTROL / "capabilities" / "capability-search-index.json"

READ_ACTIONS = {
    ("/system-manage", "get_runtime_config"),
    ("/global-search", "preview_replace"),
    ("/global-search", "verify_replace"),
    ("/global-search", "preview_regex_replace"),
    ("/global-search", "preview_url_replace"),
    ("/cache-manage", "get_rewrite_rules"),
    ("/content-manage", "list_post_types"),
    ("/content-manage", "get_post_type"),
    ("/content-manage", "validate_blocks"),
    ("/widgets-manage", "list_widget_types"),
    ("/templates-manage", "list_block_templates"),
    ("/templates-manage", "get_block_template"),
    ("/plugins-manage", "inspect_plugin_settings"),
    ("/plugins-manage", "inspect_plugin_rest_routes"),
    ("/plugins-manage", "inspect_plugin_blocks"),
    ("/plugins-manage", "inspect_plugin_assets"),
    ("/themes-manage", "inspect_theme_assets"),
    ("/themes-manage", "inspect_theme_rest_routes"),
    ("/themes-manage", "inspect_theme_blocks"),
    ("/seo-manage", "audit_news_seo"),
    ("/files-manage", "audit_run_batch"),
    ("/maintenance-manage", "list_updates"),
    ("/maintenance-manage", "run_integrity_check"),
    ("/maintenance-manage", "run_preflight_checks"),
    ("/maintenance-manage", "run_postflight_checks"),
    ("/maintenance-manage", "run_smoke_tests"),
    ("/frontend-manage", "query_selector"),
}


class GenerationError(RuntimeError):
    pass


def compact(value: Any) -> bytes:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":")).encode("utf-8")


def sha(value: Any) -> str:
    return hashlib.sha256(compact(value)).hexdigest()


def load(path: Path) -> Any:
    return json.loads(path.read_text(encoding="utf-8"))


def dump_pretty(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, indent=2) + "\n").encode("utf-8")


def dump_compact(value: Any) -> bytes:
    return (json.dumps(value, ensure_ascii=False, separators=(",", ":")) + "\n").encode("utf-8")


def php_document(value: Any) -> bytes:
    source = json.dumps(value, ensure_ascii=False, indent=2)
    escaped = source.replace("\\", "\\\\").replace("'", "\\'")
    return (
        "<?php\n"
        "// Generated action hot index. Do not edit manually.\n"
        "if ( ! defined( 'ABSPATH' ) ) { exit; }\n"
        "return json_decode( '" + escaped + "', true, 512, JSON_THROW_ON_ERROR );\n"
    ).encode("utf-8")


def canonical_tools_text(raw: str) -> dict[str, dict[str, Any]]:
    marker = "---JSON---"
    if marker not in raw:
        raise GenerationError("canonical tool dump lacks ---JSON--- delimiter")
    tools = json.loads(raw.split(marker, 1)[1].strip())
    if not isinstance(tools, list):
        raise GenerationError("canonical tool dump is not a list")
    result = {}
    for tool in tools:
        if not isinstance(tool, dict) or not tool.get("name"):
            raise GenerationError("invalid canonical tool definition")
        name = str(tool["name"])
        if name in result:
            raise GenerationError(f"duplicate canonical tool: {name}")
        result[name] = tool
    return result


def canonical_tools(path: Path) -> dict[str, dict[str, Any]]:
    return canonical_tools_text(path.read_text(encoding="utf-8"))


def canonical_tools_from_php(php_binary: str) -> dict[str, dict[str, Any]]:
    dump_script = ROOT / "tests" / "dump-wpaib-tools.php"
    completed = subprocess.run(
        [php_binary, str(dump_script)],
        cwd=ROOT,
        check=False,
        capture_output=True,
        text=True,
        encoding="utf-8",
    )
    if completed.returncode != 0:
        detail = (completed.stderr or completed.stdout).strip()
        raise GenerationError(f"canonical PHP tool dump failed ({completed.returncode}): {detail}")
    return canonical_tools_text(completed.stdout)


def action_projection(actions: list[dict[str, Any]]) -> list[dict[str, Any]]:
    fields = (
        "tool_name", "action", "route", "read_only", "destructive",
        "idempotent", "input_schema", "output_schema", "executor", "strategy",
    )
    return [{field: row.get(field) for field in fields} for row in actions]


def generate(tool_dump: Path | None = None, php_binary: str = "") -> dict[Path, bytes]:
    definitions = canonical_tools_from_php(php_binary) if php_binary else canonical_tools(tool_dump)
    catalog = copy.deepcopy(load(CATALOG))
    hot = copy.deepcopy(load(HOT_JSON))
    contract = copy.deepcopy(load(CONTRACT))
    registry = copy.deepcopy(load(REGISTRY))
    search = copy.deepcopy(load(SEARCH))

    catalog_by_key = {}
    for row in catalog.get("actions", []):
        key = (str(row.get("route") or ""), str(row.get("action") or ""))
        if key in catalog_by_key:
            raise GenerationError(f"duplicate catalog route/action: {key}")
        catalog_by_key[key] = row
        if key in READ_ACTIONS:
            row["read_only"] = True
            row["destructive"] = False
            row["idempotent"] = True
    missing_actions = sorted(READ_ACTIONS - set(catalog_by_key))
    if missing_actions:
        raise GenerationError(f"read actions missing from catalog: {missing_actions}")

    catalog["registry_hash"] = sha(catalog["actions"])
    contract_hash = sha(action_projection(catalog["actions"]))
    catalog["contract_hash"] = contract_hash
    unsigned_catalog = {key: value for key, value in catalog.items() if key != "document_hash"}
    catalog["document_hash"] = sha(unsigned_catalog)

    contract_actions = {
        (str(row.get("route") or ""), str(row.get("action") or "")): row
        for row in contract.get("actions", [])
    }
    for key, source in catalog_by_key.items():
        target = contract_actions.get(key)
        if target is None:
            raise GenerationError(f"contract action missing: {key}")
        for field in ("read_only", "destructive", "idempotent"):
            target[field] = bool(source.get(field))
    contract["contract_hash"] = contract_hash
    contract["document_hash"] = catalog["document_hash"]

    registry_by_id = {str(cap.get("id")): cap for cap in registry.get("capabilities", [])}
    direct_count = 0
    direct_names: set[str] = set()
    for capability in registry.get("capabilities", []):
        source = capability.get("source") if isinstance(capability.get("source"), dict) else {}
        kind = str(source.get("kind") or "")
        if kind == "legacy_action":
            key = (str(source.get("route") or ""), str(source.get("action") or ""))
            row = catalog_by_key.get(key)
            if row is None:
                raise GenerationError(f"legacy action capability has no catalog row: {capability.get('id')}")
            read_only = bool(row.get("read_only"))
            destructive = bool(row.get("destructive"))
            capability["read_only"] = read_only
            capability["write"] = not read_only
            capability["destructive"] = destructive
            capability["idempotent"] = bool(row.get("idempotent"))
            capability["risk_level"] = "low" if read_only else ("critical" if destructive else "medium")
            capability["concurrency_policy"] = "parallel_read" if read_only else "exclusive_resource"
        elif kind == "legacy_direct_tool":
            direct_count += 1
            name = str(source.get("tool_name") or "")
            direct_names.add(name)
            tool = definitions.get(name)
            if tool is None:
                raise GenerationError(f"legacy direct capability has no canonical tool: {name}")
            annotations = tool.get("annotations") if isinstance(tool.get("annotations"), dict) else {}
            read_only = bool(annotations.get("readOnlyHint"))
            destructive = bool(annotations.get("destructiveHint"))
            capability["read_only"] = read_only
            capability["write"] = not read_only
            capability["destructive"] = destructive
            capability["idempotent"] = bool(annotations.get("idempotentHint"))
            capability["risk_level"] = "low" if read_only else ("critical" if destructive else "medium")
            capability["concurrency_policy"] = "parallel_read" if read_only else "exclusive_resource"
            capability["input_schema"] = tool.get("inputSchema", {"type": "object", "additionalProperties": True})
            capability["output_schema"] = tool.get("outputSchema", {"type": "object", "additionalProperties": True})
    expected_direct = len(contract.get("direct_tools", []))
    if direct_count != expected_direct:
        raise GenerationError(f"legacy direct capability count {direct_count} does not match contract direct_tools {expected_direct}")
    registry["registry_hash"] = sha(registry["capabilities"])

    compact_fields = (
        "id", "version", "domain", "description", "read_only", "risk_level",
        "browser_required", "gsc_required", "estimated_cost", "source", "executor",
    )
    search_items = []
    for old in search.get("items", []):
        capability = registry_by_id.get(str(old.get("id") or ""))
        if capability is None:
            raise GenerationError(f"search capability missing from full registry: {old.get('id')}")
        search_items.append({field: capability.get(field) for field in compact_fields})
    search["items"] = search_items
    search["count"] = len(search_items)
    search["registry_hash"] = sha(search_items)

    hot_tools = hot.get("tools", {})
    if not isinstance(hot_tools, dict):
        raise GenerationError("hot index tools is not an object")
    for key, row in catalog_by_key.items():
        name = str(row.get("tool_name") or "")
        target = hot_tools.get(name)
        if not isinstance(target, dict):
            raise GenerationError(f"catalog tool missing from hot index: {name}")
        read_only = bool(row.get("read_only"))
        destructive = bool(row.get("destructive"))
        target["read_only"] = read_only
        target["destructive"] = destructive
        target["idempotent"] = bool(row.get("idempotent"))
        target["risk"] = "read" if read_only else ("destructive" if destructive else "write")
    for name in sorted(direct_names):
        tool = definitions[name]
        target = hot_tools.get(name)
        if not isinstance(target, dict):
            raise GenerationError(f"direct tool missing from hot index: {name}")
        annotations = tool.get("annotations") if isinstance(tool.get("annotations"), dict) else {}
        schema = tool.get("inputSchema") if isinstance(tool.get("inputSchema"), dict) else {"type": "object", "additionalProperties": True}
        read_only = bool(annotations.get("readOnlyHint"))
        destructive = bool(annotations.get("destructiveHint"))
        target["title"] = str(tool.get("title") or "")
        target["description"] = str(tool.get("description") or "")
        target["parameters"] = sorted((schema.get("properties") or {}).keys())
        target["required"] = list(schema.get("required") or [])
        target["schema_hash"] = sha(schema)
        target["read_only"] = read_only
        target["destructive"] = destructive
        target["idempotent"] = bool(annotations.get("idempotentHint"))
        target["risk"] = "read" if read_only else ("destructive" if destructive else "write")
    hot["contract_hash"] = contract_hash
    hot["source_document_hash"] = catalog["document_hash"]
    unsigned_hot = {key: value for key, value in hot.items() if key != "hot_index_hash"}
    hot["hot_index_hash"] = sha(unsigned_hot)

    return {
        CATALOG: dump_pretty(catalog),
        CONTRACT: dump_pretty(contract),
        REGISTRY: dump_pretty(registry),
        SEARCH: dump_compact(search),
        HOT_JSON: dump_pretty(hot),
        HOT_PHP: php_document(hot),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    source = parser.add_mutually_exclusive_group(required=True)
    source.add_argument("--tools-dump", type=Path)
    source.add_argument("--php-binary", help="Run tests/dump-wpaib-tools.php with this PHP binary.")
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--write", action="store_true")
    mode.add_argument("--check", action="store_true")
    args = parser.parse_args()
    outputs = generate(args.tools_dump.resolve() if args.tools_dump else None, args.php_binary or "")
    changed = [path for path, payload in outputs.items() if not path.is_file() or path.read_bytes() != payload]
    if args.check:
        if changed:
            print("STALE generated contract artifacts: " + ", ".join(path.relative_to(ROOT).as_posix() for path in changed))
            return 1
        print(f"PASS generated contract artifacts: {len(outputs)} files aligned")
        return 0
    for path, payload in outputs.items():
        path.write_bytes(payload)
    print(f"WROTE generated contract artifacts: {len(outputs)} files")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
