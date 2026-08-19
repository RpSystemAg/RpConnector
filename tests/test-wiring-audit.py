#!/usr/bin/env python3
"""Find test-like files that are not reachable from any GitHub Actions workflow.

A test file in the repository is not evidence unless CI can reach it. The audit
builds a static reachability graph: workflows are roots; scripts they name are
reachable; reachable scripts may in turn invoke other test-like scripts.
"""
from __future__ import annotations

import fnmatch
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW_DIR = ROOT / ".github/workflows"
TOKENS = (
    "test", "smoke", "integration", "audit", "selftest", "conformance", "fuzz",
    "chaos", "invariant", "model", "benchmark", "bench", "gate", "readiness",
    "slo", "verification", "assurance", "replay", "liveness", "security",
)
EXTS = {".py", ".php", ".mjs", ".js", ".sh"}

candidates: set[Path] = set()
for base in (ROOT / "tests", ROOT / "prstudio-unified-browser-agent/tests"):
    if not base.is_dir():
        continue
    for path in base.rglob("*"):
        if not path.is_file() or path.suffix.lower() not in EXTS:
            continue
        low = path.name.lower()
        if any(token in low for token in TOKENS) or low.endswith(".test.mjs"):
            candidates.add(path.resolve())

workflow_files = sorted(WORKFLOW_DIR.glob("*.yml")) + sorted(WORKFLOW_DIR.glob("*.yaml"))
workflow_text = "\n".join(path.read_text(encoding="utf-8", errors="replace") for path in workflow_files)

# Extract glob-like path tokens from workflows. This covers commands such as
# `node --test tests/*.test.mjs` where no individual filename is written.
glob_tokens = set(re.findall(r"[A-Za-z0-9_./-]*tests/[A-Za-z0-9_.*?\[\]-]+", workflow_text))


def named_in(text: str, candidate: Path) -> bool:
    rel = candidate.relative_to(ROOT).as_posix()
    basename = candidate.name
    if rel in text or basename in text:
        return True
    # A workflow working-directory can make `tests/*.test.mjs` refer to the
    # Browser Agent tests folder, so match both full path and suffix path.
    suffixes = [rel]
    if "/tests/" in rel:
        suffixes.append("tests/" + rel.split("/tests/", 1)[1])
    for pattern in glob_tokens:
        for suffix in suffixes:
            if fnmatch.fnmatch(suffix, pattern):
                return True
    return False

reachable: set[Path] = {path for path in candidates if named_in(workflow_text, path)}

# Fixed-point expansion: a reached runner may invoke another test script.
changed = True
while changed:
    changed = False
    corpus_parts = []
    for path in reachable:
        try:
            corpus_parts.append(path.read_text(encoding="utf-8", errors="replace"))
        except Exception:
            pass
    corpus = "\n".join(corpus_parts)
    for path in candidates - reachable:
        if named_in(corpus, path):
            reachable.add(path)
            changed = True

unwired = sorted(candidates - reachable, key=lambda p: p.relative_to(ROOT).as_posix())
print(f"TEST WIRING: candidates={len(candidates)} reachable={len(reachable)} unwired={len(unwired)} workflows={len(workflow_files)}")
for path in sorted(reachable, key=lambda p: p.relative_to(ROOT).as_posix()):
    print("WIRED", path.relative_to(ROOT).as_posix())
for path in unwired:
    print("ERROR UNWIRED", path.relative_to(ROOT).as_posix())

if unwired:
    print("Every test-like file must be executed by CI or explicitly reclassified/renamed as a helper; silent test inventory is forbidden.")
sys.exit(1 if unwired else 0)
