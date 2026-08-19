#!/usr/bin/env python3
"""Fail-closed source audit for release/build dependencies.

Checks package-manager lock coverage, immutable GitHub Actions, immutable Docker
image references and obviously floating tool/dependency specifications. This is
not a vulnerability scanner; it proves that the inputs intended to be scanned
and built are reproducibly selected.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
WORKFLOWS = ROOT / ".github" / "workflows"
FULL_SHA = re.compile(r"^[0-9a-f]{40}$", re.I)
DOCKER_IMAGE = re.compile(r"(?<![A-Za-z0-9_.-])((?:mariadb|mysql|postgres|redis|node|python|php|wordpress|ubuntu|alpine):[^\s'\"}\],]+)", re.I)
USES = re.compile(r"^\s*-?\s*uses:\s*([^\s#]+)", re.M)
FLOATING = re.compile(r"(?:@latest\b|:\s*latest\b|\b(?:pip|pip3)\s+install\s+[^\n]*\b(?:--pre\b|-[Uu]\b)|\bnpm\s+(?:install|i)\s+[^\n]*@latest\b)", re.I)
COMPOSER_REQUIRE = re.compile(r"composer\s+(?:global\s+)?require[^\n\\]*(.*)", re.I)

MANIFEST_LOCKS = {
    "composer.json": ["composer.lock"],
    "package.json": ["package-lock.json", "npm-shrinkwrap.json", "pnpm-lock.yaml", "yarn.lock"],
    "pyproject.toml": ["uv.lock", "poetry.lock", "pdm.lock"],
    "Pipfile": ["Pipfile.lock"],
}


def rel(path: Path) -> str:
    return str(path.relative_to(ROOT)).replace("\\", "/")


def package_lock_findings() -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    manifests: list[dict[str, Any]] = []
    findings: list[dict[str, Any]] = []
    ignored = {"vendor", "node_modules", ".git", "dist", "evidence"}
    for path in ROOT.rglob("*"):
        if not path.is_file() or any(part in ignored for part in path.parts):
            continue
        name = path.name
        if name in MANIFEST_LOCKS:
            locks = [path.parent / candidate for candidate in MANIFEST_LOCKS[name]]
            present = [candidate for candidate in locks if candidate.is_file()]
            manifests.append({"manifest": rel(path), "locks": [rel(p) for p in present]})
            if not present:
                findings.append({"file": rel(path), "reason": "package-manager manifest has no recognized lock file"})
        elif name.startswith("requirements") and name.endswith(".txt"):
            unpinned = []
            for line_no, line in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
                value = line.split("#", 1)[0].strip()
                if not value or value.startswith(("-r", "--requirement", "--index-url", "--extra-index-url")):
                    continue
                if "==" not in value or any(op in value for op in (">=", "<=", "~=", "!=", "<", ">")):
                    unpinned.append({"line": line_no, "value": value})
            manifests.append({"manifest": rel(path), "requirements": True})
            if unpinned:
                findings.append({"file": rel(path), "reason": "requirements contain non-exact versions", "entries": unpinned})
    return manifests, findings


def workflow_findings() -> tuple[list[dict[str, Any]], list[dict[str, Any]], list[dict[str, Any]]]:
    action_findings: list[dict[str, Any]] = []
    dependency_findings: list[dict[str, Any]] = []
    observations: list[dict[str, Any]] = []
    for path in sorted(WORKFLOWS.glob("*.y*ml")):
        text = path.read_text(encoding="utf-8", errors="replace")
        for value in USES.findall(text):
            if value.startswith("./"):
                continue
            if value.startswith("docker://"):
                if "@sha256:" not in value:
                    action_findings.append({"file": rel(path), "value": value, "reason": "docker action lacks digest"})
                continue
            if "@" not in value:
                action_findings.append({"file": rel(path), "value": value, "reason": "GitHub Action has no ref"})
                continue
            ref_value = value.rsplit("@", 1)[1]
            if not FULL_SHA.fullmatch(ref_value):
                action_findings.append({"file": rel(path), "value": value, "reason": "GitHub Action ref is not immutable 40-hex SHA"})

        for match in DOCKER_IMAGE.finditer(text):
            value = match.group(1)
            # Digest-pinned references do not match this tag-only expression in normal YAML.
            line_no = text.count("\n", 0, match.start()) + 1
            line = text.splitlines()[line_no - 1] if line_no <= len(text.splitlines()) else ""
            if "@sha256:" not in line:
                dependency_findings.append({"file": rel(path), "line": line_no, "value": value, "reason": "container image is tag-only; production dependency is mutable"})

        for match in FLOATING.finditer(text):
            line_no = text.count("\n", 0, match.start()) + 1
            dependency_findings.append({"file": rel(path), "line": line_no, "value": match.group(0), "reason": "floating dependency/tool selector"})

        # Exact composer CLI constraints use package:version. Reject common open ranges.
        for line_no, line in enumerate(text.splitlines(), 1):
            if "composer global require" not in line and "composer require" not in line:
                continue
            if any(token in line for token in ("^", "~", "*", ">", "<", " dev-", "@dev")):
                dependency_findings.append({"file": rel(path), "line": line_no, "value": line.strip(), "reason": "Composer build tool uses an open version range"})
            observations.append({"file": rel(path), "line": line_no, "composer_requirement": line.strip()})
    return action_findings, dependency_findings, observations


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--json-output", default="")
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()

    manifests, package_findings = package_lock_findings()
    action_findings, workflow_dependency_findings, observations = workflow_findings()
    dependency_findings = package_findings + workflow_dependency_findings

    result = {
        "schema_version": 1,
        "checks": {
            "github_actions_commit_pinned": {"ok": not action_findings, "findings": action_findings},
            "dependencies_locked": {"ok": not dependency_findings, "manifests": manifests, "findings": dependency_findings, "observations": observations},
        },
    }
    output = json.dumps(result, indent=2, sort_keys=True) + "\n"
    if args.json_output:
        path = Path(args.json_output)
        if not path.is_absolute():
            path = ROOT / path
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(output, encoding="utf-8")
    print("PRODUCTION SUPPLY-CHAIN SOURCE AUDIT")
    for check_id, check in result["checks"].items():
        print(f"{'PASS' if check['ok'] else 'FAIL'} {check_id}")
        for finding in check.get("findings", []):
            print("  ", json.dumps(finding, sort_keys=True))
    failed = [check_id for check_id, check in result["checks"].items() if not check["ok"]]
    return 1 if args.strict and failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
