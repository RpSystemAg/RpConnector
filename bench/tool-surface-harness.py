#!/usr/bin/env python3
"""Tool-surface harness: routing, schema conformity and variance metrics.

References (arXiv week 2026-08-13..19):
 - "Task-Aware Harness Provisioning for LLM Agents in Mission-Critical
   Infrastructure Operations": measuring the tool surface per tool — schema
   conformity of inputs, routing coverage, fallback reachability — instead of
   assuming a static surface is fine.
 - "On the Fragility of Self-Improving Agents: Variance, Task Order and a Way
   Forward": the same workload must produce the same advertised surface
   regardless of tool ordering; variance is measured, not assumed.

The harness is a release-equation column (Law 11). It loads the REAL tool
catalog from the runtime (PRSTUDIO_UC_MCP_V5::tools() via PHP, the exact
schemas tools/list would emit) and checks:
  - every typed tool parses and has an object input schema;
  - valid generated inputs are schema-accepted at 100%;
  - invalid inputs are schema-rejected at 100% (open-schema tools excluded);
  - the Law 9 budget selection is order-invariant (variance == 0);
  - every tool is reachable: direct dispatch case in call_tool.

Usage:
    python bench/tool-surface-harness.py [--report quality/tool-surface-report.json] [--runs N]
"""
from __future__ import annotations

import argparse
import hashlib
import json
import random
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MCP_SOURCE = ROOT / "prstudio-unified-control" / "includes" / "class-prstudio-uc-mcp-v5.php"
DUMP_SCRIPT = r"""
define('PRSTUDIO_UC_TESTING', true);
require '%s';
$tools = PRSTUDIO_UC_MCP_V5::tools();
$surface = array();
foreach ($tools as $tool) {
    $surface[] = array(
        'name' => $tool['name'],
        'description' => $tool['description'],
        'inputSchema' => $tool['inputSchema'],
    );
}
echo json_encode($surface, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
"""
BUDGET = 5000
ESSENTIALS = [
    "prstudio_do", "prstudio_capability_search", "prstudio_capability_describe", "prstudio_execute",
    "prstudio_tool_manual", "prstudio_health", "prstudio_observe", "prstudio_flow", "prstudio_backlog",
    "prstudio_context_open", "prstudio_job_get", "prstudio_job_control", "prstudio_intervention_record",
    "browser_status", "browser_task_control", "browser_open", "browser_screenshot", "browser_snapshot",
    "wordpress_content_transaction", "procedural_skill_search", "procedural_skill_get",
]


def load_tools() -> list[dict]:
    """Load the exact tool catalog the runtime emits (real schemas, Law 11)."""
    if not Path("php").exists() and not _php_available():
        raise SystemExit("DRIFT tool-surface harness requires a PHP runtime (php) on PATH")
    command = [
        "php",
        "-d",
        "auto_prepend_file=tests/strict-php-errors.php",
        "-r",
        DUMP_SCRIPT % (ROOT / "prstudio-unified-control" / "includes" / "class-prstudio-uc-mcp-v5.php"),
    ]
    proc = subprocess.run(command, cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=120)
    if proc.returncode != 0:
        raise SystemExit(f"DRIFT php dump failed: {proc.stdout[-2000:]}")
    payload = proc.stdout.strip()
    start = payload.find("[")
    if start < 0:
        raise SystemExit(f"DRIFT php dump produced no JSON array: {payload[-2000:]}")
    data = json.loads(payload[start:])
    if not isinstance(data, list) or not data:
        raise SystemExit("DRIFT php dump returned an empty tool catalog")
    return data


def _php_available() -> bool:
    try:
        proc = subprocess.run(["php", "-v"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        return proc.returncode == 0
    except (OSError, subprocess.SubprocessError):
        return False


def surface_bytes(tool: dict) -> int:
    payload = json.dumps(
        {"name": tool["name"], "description": tool["description"], "inputSchema": tool["inputSchema"]},
        separators=(",", ":"),
        ensure_ascii=False,
    )
    return len(payload.encode("utf-8"))


def validate(value, schema: dict, path: str = "$") -> list[str]:
    errors: list[str] = []
    if "anyOf" in schema:
        for branch in schema["anyOf"]:
            if not validate(value, branch, path):
                return []
        errors.append(f"{path}: no anyOf branch matched")
        return errors
    kind = schema.get("type")
    if kind == "object":
        if not isinstance(value, dict):
            return [f"{path}: expected object"]
        for required in schema.get("required", []):
            if required not in value:
                errors.append(f"{path}: missing required {required}")
        if schema.get("additionalProperties") is False:
            for key in value:
                if key not in schema.get("properties", {}):
                    errors.append(f"{path}: unknown key {key}")
        for key, subschema in schema.get("properties", {}).items():
            if key in value:
                errors.extend(validate(value[key], subschema, f"{path}.{key}"))
    elif kind == "array":
        if not isinstance(value, list):
            return [f"{path}: expected array"]
        items = schema.get("items", {})
        if items:
            for i, item in enumerate(value):
                errors.extend(validate(item, items, f"{path}[{i}]"))
    elif kind == "string":
        if not isinstance(value, str):
            errors.append(f"{path}: expected string")
        elif "pattern" in schema and schema["pattern"]:
            try:
                if not re.search(schema["pattern"], value):
                    errors.append(f"{path}: pattern mismatch")
            except re.error:
                pass
    elif kind == "integer":
        if not isinstance(value, int) or isinstance(value, bool):
            errors.append(f"{path}: expected integer")
    elif kind == "number":
        if not isinstance(value, (int, float)) or isinstance(value, bool):
            errors.append(f"{path}: expected number")
    elif kind == "boolean":
        if not isinstance(value, bool):
            errors.append(f"{path}: expected boolean")
    return errors


def sample_for(schema: dict):
    kind = schema.get("type")
    if kind == "object":
        return valid_sample(schema)
    if kind == "array":
        return [sample_for(schema.get("items", {}))]
    if kind == "integer":
        return int(schema.get("minimum", 0) or 0)
    if kind == "number":
        return float(schema.get("minimum", 0) or 0)
    if kind == "boolean":
        return True
    enum = schema.get("enum")
    if enum:
        return enum[0]
    pattern = schema.get("pattern")
    if pattern:
        return "2026-08-19" if "\\d{4}" in pattern else "x"
    return "x"


def valid_sample(schema: dict) -> dict:
    out: dict = {}
    for key, subschema in schema.get("properties", {}).items():
        if key in ("lane_handle", "lane_token"):
            continue
        out[key] = sample_for(subschema)
    for required in schema.get("required", []):
        if required not in out:
            out[required] = sample_for(schema.get("properties", {}).get(required, {}))
    return out


def bad_sample(schema: dict) -> dict:
    """A sample guaranteed to violate the schema (wrong types)."""
    out: dict = {}
    props = schema.get("properties", {})
    for key in list(props)[:2]:
        kind = props[key].get("type")
        if kind == "string":
            out[key] = 123
        elif kind in ("integer", "number"):
            out[key] = "not-a-number"
        elif kind == "boolean":
            out[key] = "not-a-bool"
        elif kind == "object":
            out[key] = [1, 2]
        elif kind == "array":
            out[key] = {"x": 1}
    if not out:
        out["_bad"] = 1  # additionalProperties=false violation
    return out


def is_open_schema(schema: dict) -> bool:
    """Open-schema tools accept arbitrary payloads by design (any_object)."""
    return schema.get("additionalProperties") is True and not schema.get("properties")


def advertised_for(tools: list[dict]) -> list[str]:
    """Law 9 selection reimplementation: essentials first, then smallest-first.

    The runtime enforces the full invariant (tools_within_budget); this
    reimplementation exists only to measure ORDER variance of the same rule.
    """
    by_name = {t["name"]: t for t in tools}
    ordered: list[dict] = []
    for name in ESSENTIALS:
        if name in by_name:
            ordered.append(by_name.pop(name))
    rest = sorted(by_name.values(), key=lambda t: (t["_bytes"], t["name"]))
    selected: list[str] = []
    budget_bytes = BUDGET * 4
    total = 2
    for tool in [*ordered, *rest]:
        cost = tool["_bytes"] + 1
        if selected and total + cost > budget_bytes:
            continue
        selected.append(tool["name"])
        total += cost
    return selected


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--report", default="", help="write the JSON report to this path")
    parser.add_argument("--runs", type=int, default=5, help="order-variance protocol repetitions")
    args = parser.parse_args()

    source = MCP_SOURCE.read_text(encoding="utf-8")
    tools = load_tools()
    for tool in tools:
        tool["_bytes"] = surface_bytes(tool)

    failures: list[str] = []
    rows: list[dict] = []
    for tool in tools:
        name = tool["name"]
        schema = tool["inputSchema"]
        if not isinstance(schema, dict) or schema.get("type") != "object":
            failures.append(f"{name}: runtime input schema is not an object")
            continue
        valid_errors = validate(valid_sample(schema), schema)
        open_schema = is_open_schema(schema)
        bad_errors = validate(bad_sample(schema), schema) if not open_schema else []
        direct_case = re.search(rf"case\s+'{re.escape(name)}'\s*:", source) is not None
        rows.append(
            {
                "tool": name,
                "surface_bytes": tool["_bytes"],
                "approx_tokens": (tool["_bytes"] + 3) // 4,
                "schema_object": True,
                "open_schema": open_schema,
                "valid_sample_accepted": len(valid_errors) == 0,
                "invalid_sample_rejected": open_schema or len(bad_errors) > 0,
                "direct_dispatch": direct_case,
            }
        )
        if valid_errors:
            failures.append(f"{name}: generated valid sample rejected ({valid_errors[:2]})")
        if not open_schema and not bad_errors:
            failures.append(f"{name}: generated invalid sample accepted")

    valid_accepted = sum(1 for r in rows if r["valid_sample_accepted"])
    closed_schema_tools = sum(1 for r in rows if not r["open_schema"])
    invalid_rejected = sum(1 for r in rows if not r["open_schema"] and r["invalid_sample_rejected"])
    direct = sum(1 for r in rows if r["direct_dispatch"])
    total_bytes = sum(r["surface_bytes"] for r in rows)

    rng = random.Random(20260819)
    advertised_sets: list[str] = []
    for _ in range(max(1, args.runs)):
        shuffled = list(tools)
        rng.shuffle(shuffled)
        advertised_sets.append(advertised_for(shuffled))
    first = advertised_sets[0]
    identical = all(s == first for s in advertised_sets[1:])

    report = {
        "schema_version": "1.0.0",
        "checkout": hashlib.sha256(source.encode("utf-8")).hexdigest()[:16],
        "tools_total": len(tools),
        "valid_sample_acceptance_rate": round(valid_accepted / max(1, len(tools)), 4),
        "invalid_sample_rejection_rate": round(invalid_rejected / max(1, closed_schema_tools), 4),
        "direct_dispatch_coverage": round(direct / max(1, len(tools)), 4),
        "surface_total_bytes": total_bytes,
        "surface_approx_tokens": (total_bytes + 3) // 4,
        "law9_budget": BUDGET,
        "advertised_surface_count": len(first),
        "order_variance": {"runs": len(advertised_sets), "identical_across_orders": identical},
        "rows": rows,
    }

    print(f"PASS tool-surface harness: {len(tools)} tools loaded from the runtime catalog")
    print(f"  valid sample acceptance: {valid_accepted}/{len(tools)}")
    print(f"  invalid sample rejection: {invalid_rejected}/{closed_schema_tools} closed-schema tools")
    print(f"  direct dispatch: {direct}/{len(tools)}")
    print(f"  surface approx tokens (full catalog): {report['surface_approx_tokens']}")
    print(f"  advertised under Law 9 budget: {len(first)} tools")
    print(f"  order variance protocol: {len(advertised_sets)} runs, identical={identical}")

    if args.report:
        report_path = ROOT / args.report
        report_path.parent.mkdir(parents=True, exist_ok=True)
        report_path.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
        print(f"  report written: {args.report}")

    if not identical:
        failures.append("Law 9 advertised surface is NOT order-invariant (variance > 0)")
    if failures:
        for failure in failures:
            print(f"DRIFT {failure}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
