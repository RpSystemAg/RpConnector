#!/usr/bin/env python3
"""Enterprise-level repository audit.

The goal is to catch classes of defects that unit tests do not see: release
placeholders, workflow privilege/supply-chain drift, unbounded loops, malformed
JSON and security checks that were accidentally made informational.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"

errors: list[str] = []
warnings: list[str] = []


def rel(path: Path) -> str:
    return path.relative_to(ROOT).as_posix()


def is_fixture(path: Path) -> bool:
    parts = set(path.relative_to(ROOT).parts)
    return bool(parts & {"tests", "test", "fixtures", "fixture", "bench"})


# 1. Every JSON artifact must parse. Large generated registries are included.
json_count = 0
for path in ROOT.rglob("*.json"):
    if any(part in {".git", "node_modules", "vendor"} for part in path.parts):
        continue
    json_count += 1
    try:
        json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        errors.append(f"JSON_PARSE {rel(path)}: {exc}")

# 2. Executable production runtime must never ship example.* network endpoints.
# HTML form placeholders such as placeholder="https://example.com" are UX hints,
# not network destinations; tests/fixtures are likewise excluded. PHP/JS/JSON
# runtime values remain blocking because they can influence actual execution/CSP.
production_roots = [
    ROOT / "prstudio-unified-control",
    ROOT / "prstudio-unified-browser-agent",
]
placeholder_re = re.compile(r"https?://(?:www\.)?example\.(?:com|org|net)(?:/|['\"\s]|$)", re.I)
for base in production_roots:
    for path in base.rglob("*"):
        if (
            not path.is_file()
            or is_fixture(path)
            or path.suffix.lower() not in {".php", ".js", ".mjs", ".json"}
        ):
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        if placeholder_re.search(text):
            errors.append(f"PRODUCTION_PLACEHOLDER {rel(path)} contains example.* executable runtime URL")

# 3. Release-critical workflows require explicit least-privilege shape.
sha_ref = re.compile(r"^[0-9a-f]{40}$", re.I)
workflow_count = 0
for path in sorted(WORKFLOWS.glob("*.yml")) + sorted(WORKFLOWS.glob("*.yaml")):
    workflow_count += 1
    text = path.read_text(encoding="utf-8")
    lines = text.splitlines()
    if not re.search(r"(?m)^permissions\s*:", text):
        errors.append(f"WORKFLOW_PERMISSIONS {rel(path)} has no explicit top-level permissions")
    jobs_match = re.search(r"(?m)^jobs:\s*$", text)
    if jobs_match:
        jobs_text = text[jobs_match.end():]
        job_names = re.findall(r"(?m)^  ([A-Za-z0-9_.-]+):\s*$", jobs_text)
        for job in job_names:
            block_match = re.search(
                rf"(?ms)^  {re.escape(job)}:\s*\n(.*?)(?=^  [A-Za-z0-9_.-]+:\s*$|\Z)",
                jobs_text,
            )
            block = block_match.group(1) if block_match else ""
            if "runs-on:" in block and "timeout-minutes:" not in block:
                errors.append(f"WORKFLOW_TIMEOUT {rel(path)} job={job} has no timeout-minutes")
    for lineno, line in enumerate(lines, 1):
        stripped = line.strip()
        if stripped.startswith("uses:") or stripped.startswith("- uses:"):
            value = stripped.split("uses:", 1)[1].strip()
            # YAML comments are metadata, not part of the action ref.
            value = re.sub(r"\s+#.*$", "", value).strip().strip("'\"")
            if value.startswith("./"):
                continue
            if "@" not in value:
                errors.append(f"ACTION_UNPINNED {rel(path)}:{lineno} {value} has no ref")
                continue
            action, ref_value = value.rsplit("@", 1)
            if not sha_ref.fullmatch(ref_value):
                errors.append(
                    f"ACTION_MUTABLE_REF {rel(path)}:{lineno} {action}@{ref_value} must pin immutable 40-char SHA"
                )
        if re.search(r"continue-on-error\s*:\s*true", stripped, re.I):
            errors.append(f"FAIL_OPEN_CHECK {rel(path)}:{lineno} uses continue-on-error:true")
        if re.search(r"(?:curl|wget).*(?:\||;)\s*(?:sudo\s+)?(?:sh|bash)\b", stripped):
            errors.append(f"PIPE_TO_SHELL {rel(path)}:{lineno} release workflow downloads directly into a shell")

# 4. Detect truly unbounded literal loops. A literal infinite-loop syntax is not
# itself a defect when its body has an explicit exit plus a bounded resource or
# deadline. We inspect the local body window and block only when neither exists.
loop_patterns = [
    re.compile(r"\bwhile\s*\(\s*true\s*\)", re.I),
    re.compile(r"\bfor\s*\(\s*;\s*;\s*\)"),
]
bound_markers = re.compile(
    r"\b(?:deadline|timeout|timed_out|timed|maxBytes|max_bytes|maxOutput|max_output|"
    r"PROCESS_OUTPUT_LIMIT|MAX_OUTPUT|maxSitemaps|maxUrls|max_urls|limit|done|running)\b",
    re.I,
)
for base in production_roots:
    for path in base.rglob("*"):
        if not path.is_file() or is_fixture(path) or path.suffix.lower() not in {".php", ".js", ".mjs"}:
            continue
        text = path.read_text(encoding="utf-8", errors="replace")
        for pattern in loop_patterns:
            for match in pattern.finditer(text):
                line = text.count("\n", 0, match.start()) + 1
                window = text[match.start(): min(len(text), match.start() + 1800)]
                has_exit = bool(re.search(r"\bbreak\b|\breturn\b|\bthrow\b", window))
                has_bound = bool(bound_markers.search(window))
                if not (has_exit and has_bound):
                    errors.append(f"UNBOUNDED_LOOP {rel(path)}:{line} has no local exit+bound evidence")
                else:
                    warnings.append(f"BOUNDED_LITERAL_LOOP {rel(path)}:{line} reviewed via local exit+bound evidence")

# 5. Detect empty swallowed Throwable/Exception handlers in core runtime.
# Optional subsystems may fail without aborting the main execution, but a silent
# catch still destroys diagnosis/evidence and therefore remains a blocking
# enterprise hardening finding until it records a bounded event/log.
critical = [
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-agency-runtime.php",
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-job-engine.php",
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-store.php",
    ROOT / "prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php",
]
empty_catch = re.compile(r"catch\s*\([^)]*(?:Throwable|Exception)[^)]*\)\s*\{\s*\}", re.S)
for path in critical:
    if not path.exists():
        continue
    text = path.read_text(encoding="utf-8", errors="replace")
    for match in empty_catch.finditer(text):
        line = text.count("\n", 0, match.start()) + 1
        errors.append(f"SILENT_CRITICAL_EXCEPTION {rel(path)}:{line} swallows runtime failure without evidence")

# 6. Basic generated/public surface sanity.
descriptor = ROOT / "RP-STUDIO-CHATGPT-PLUGIN-1.0.0.json"
build_info = ROOT / "prstudio-unified-control/BUILD-INFO.json"
if descriptor.exists() and build_info.exists():
    d = json.loads(descriptor.read_text(encoding="utf-8"))
    b = json.loads(build_info.read_text(encoding="utf-8"))
    expected = d.get("expected_tools")
    build_tools = b.get("mcp_tool_count")
    if isinstance(expected, int) and isinstance(build_tools, int) and expected != build_tools:
        errors.append(f"TOOL_COUNT_DRIFT descriptor expected_tools={expected} BUILD-INFO mcp_tool_count={build_tools}")

print(
    f"ENTERPRISE STATIC AUDIT: json={json_count} workflows={workflow_count} "
    f"errors={len(errors)} warnings={len(warnings)}"
)
for item in errors:
    print(f"ERROR {item}")
for item in warnings:
    print(f"WARN  {item}")

sys.exit(1 if errors else 0)
