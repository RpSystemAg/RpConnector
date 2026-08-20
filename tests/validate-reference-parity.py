#!/usr/bin/env python3
"""The Browser Agent must be able to do everything the reference tooling can.

WHY THIS EXISTS
---------------
"Make it work like the reference" is not a checkable statement, and a claim of
parity written in a commit message decays the moment either side moves. This
turns it into a property that is measured on every run: each capability of the
reference browser surface is declared here, mapped to the tool that provides it
in this suite, and an unmapped capability fails the build.

The reference surface is Claude in Chrome, the tooling this agent drives a real
browser with. Its shape matters as much as its contents: it exposes one pointer
tool carrying a mode -- thirteen gestures behind a single entry -- rather than a
tool per gesture. That is why several rows below map to the same tool with
different parameters, and why adding browser_right_click would have been the
wrong answer to a missing right click.

HOW TO CHANGE IT
----------------
Adding a row is how a newly-noticed reference capability enters the backlog: it
fails until something provides it. Removing a row is a claim that the reference
no longer has that capability, which is rarely true and should be argued in the
commit message rather than done quietly.

`provided_by` names a tool that must exist in the MCP surface. `via` records the
parameter that selects the behaviour, so a reader can see that a right click is
browser_click with button=right rather than assuming a tool is missing.

Exit code 1 when any reference capability has no provider, or when a declared
provider does not exist.
"""
from __future__ import annotations

import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Every capability of the reference browser surface, grouped as it is grouped
# there. `provided_by` is the tool in this suite; `via` is the parameter that
# selects it, when one tool serves several capabilities.
REFERENCE_SURFACE: list[dict] = [
    # -- Tabs -------------------------------------------------------------
    {"group": "tabs", "reference": "tabs_context", "provided_by": "browser_tabs", "via": ""},
    {"group": "tabs", "reference": "tabs_create", "provided_by": "browser_open", "via": "url"},
    {"group": "tabs", "reference": "tabs_close", "provided_by": "browser_close", "via": ""},
    {"group": "tabs", "reference": "tabs_select", "provided_by": "browser_tabs", "via": "activate"},

    # -- Navigation -------------------------------------------------------
    {"group": "navigation", "reference": "navigate(url)", "provided_by": "browser_navigate", "via": "url"},
    {"group": "navigation", "reference": "navigate(back)", "provided_by": "browser_back", "via": ""},
    {"group": "navigation", "reference": "navigate(forward)", "provided_by": "browser_forward", "via": ""},
    {"group": "navigation", "reference": "reload", "provided_by": "browser_reload", "via": ""},

    # -- Pointer and keyboard ---------------------------------------------
    # The reference exposes these as modes of one action, not as separate tools.
    {"group": "input", "reference": "left_click", "provided_by": "browser_click", "via": ""},
    {"group": "input", "reference": "right_click", "provided_by": "browser_click", "via": "button"},
    {"group": "input", "reference": "double_click", "provided_by": "browser_click", "via": "click_count"},
    {"group": "input", "reference": "triple_click", "provided_by": "browser_click", "via": "click_count"},
    {"group": "input", "reference": "hover", "provided_by": "browser_hover", "via": ""},
    {"group": "input", "reference": "left_click_drag", "provided_by": "browser_drag", "via": ""},
    {"group": "input", "reference": "type", "provided_by": "browser_type", "via": ""},
    {"group": "input", "reference": "key", "provided_by": "browser_press", "via": ""},
    {"group": "input", "reference": "scroll", "provided_by": "browser_scroll", "via": ""},
    {"group": "input", "reference": "scroll_to(ref)", "provided_by": "browser_scroll", "via": "target_ref"},
    {"group": "input", "reference": "form_input", "provided_by": "browser_fill", "via": ""},
    {"group": "input", "reference": "wait", "provided_by": "browser_wait", "via": ""},

    # -- Observation ------------------------------------------------------
    {"group": "read", "reference": "screenshot", "provided_by": "browser_screenshot", "via": ""},
    {"group": "read", "reference": "zoom(region)", "provided_by": "browser_screenshot", "via": "region"},
    {"group": "read", "reference": "read_page", "provided_by": "browser_snapshot", "via": ""},
    {"group": "read", "reference": "find", "provided_by": "browser_find", "via": "query"},
    {"group": "read", "reference": "get_page_text", "provided_by": "browser_extract", "via": ""},
    {"group": "read", "reference": "read_console_messages", "provided_by": "browser_console", "via": ""},
    {"group": "read", "reference": "read_network_requests", "provided_by": "browser_network", "via": ""},

    # -- Page control -----------------------------------------------------
    {"group": "control", "reference": "javascript_exec", "provided_by": "browser_evaluate", "via": ""},
    {"group": "control", "reference": "resize_window", "provided_by": "browser_viewport", "via": ""},
    {"group": "control", "reference": "browser_batch", "provided_by": "browser_batch", "via": ""},
    {"group": "control", "reference": "file_upload", "provided_by": "browser_upload_file", "via": ""},
]

DUMP_PHP = r"""<?php
declare(strict_types=1);
define('PRSTUDIO_UC_TESTING', true);
require getenv('RP_ROOT') . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';
$out = array();
foreach (PRSTUDIO_UC_MCP_V5::tools() as $tool) {
    $props = $tool['inputSchema']['properties'] ?? array();
    $out[$tool['name'] ?? ''] = array_values(array_keys((array) $props));
}
file_put_contents(getenv('RP_OUT'), json_encode($out, JSON_UNESCAPED_SLASHES));
"""


def suite_tools() -> dict[str, list[str]]:
    """Every tool this suite defines, with its declared parameters."""
    php = os.environ.get("RP_PHP_BIN", "").strip() or "php"
    with tempfile.TemporaryDirectory() as tmp:
        script = Path(tmp) / "dump.php"
        script.write_text(DUMP_PHP, encoding="utf-8")
        out = Path(tmp) / "tools.json"
        env = {**os.environ, "RP_ROOT": ROOT.as_posix(), "RP_OUT": str(out)}
        run = subprocess.run([php, "-f", str(script)], capture_output=True, text=True, timeout=120, env=env)
        if run.returncode != 0 or not out.exists():
            print("could not read the tool surface; PHP is required to certify parity", file=sys.stderr)
            print(run.stderr[-1500:], file=sys.stderr)
            raise SystemExit(1)
        return json.loads(out.read_text(encoding="utf-8"))


def main() -> int:
    tools = suite_tools()
    missing: list[dict] = []
    partial: list[dict] = []

    group = ""
    for row in REFERENCE_SURFACE:
        if row["group"] != group:
            group = row["group"]
            print(f"\n{group}")
        provider = row["provided_by"]
        via = row["via"]
        if provider not in tools:
            print(f"  {row['reference']:24} -> {provider} (absent)")
            missing.append(row)
            continue
        if via and via not in tools[provider]:
            print(f"  {row['reference']:24} -> {provider} lacks '{via}'")
            partial.append(row)
            continue
        detail = f"{provider}" + (f" [{via}]" if via else "")
        print(f"  {row['reference']:24} -> {detail}")

    covered = len(REFERENCE_SURFACE) - len(missing) - len(partial)
    print(f"\nreference capabilities: {len(REFERENCE_SURFACE)}  provided: {covered}  absent: {len(missing)}  incomplete: {len(partial)}")

    if not missing and not partial:
        print("parity holds: every reference capability has a provider")
        return 0

    print("", file=sys.stderr)
    for row in missing:
        print(f"  no tool provides {row['reference']} (expected {row['provided_by']})", file=sys.stderr)
    for row in partial:
        print(f"  {row['provided_by']} exists but cannot express {row['reference']}: no '{row['via']}' parameter", file=sys.stderr)
    print("\n  Parity is the goal, so an unprovided capability is a defect, not a backlog entry.", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
