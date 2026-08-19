#!/usr/bin/env python3
"""Generate a deterministic CycloneDX 1.6 SBOM for the two shipped components.

The repository intentionally has no runtime Composer/npm dependency graph today;
therefore the SBOM enumerates the shipped WordPress plugin and Chrome extension
plus every shipped file with SHA-256. If package manifests are introduced, the
supply-chain lock audit blocks release until a lock exists and this generator can
be extended to model those packages explicitly.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
import subprocess
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / "prstudio-unified-control"
BROWSER = ROOT / "prstudio-unified-browser-agent"


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def source_commit() -> str:
    value = subprocess.check_output(
        ["git", "rev-parse", "HEAD"], cwd=ROOT, text=True, stderr=subprocess.DEVNULL
    ).strip()
    if len(value) != 40 or any(ch not in "0123456789abcdefABCDEF" for ch in value):
        raise RuntimeError("cannot resolve exact 40-hex source commit")
    return value.lower()


def plugin_version() -> str:
    source = (PLUGIN / "prstudio-unified-control.php").read_text(encoding="utf-8", errors="replace")
    match = re.search(r"^\s*\*\s*Version:\s*([^\s]+)", source, re.M)
    if not match:
        raise RuntimeError("plugin Version header not found")
    return match.group(1)


def browser_version() -> str:
    manifest = json.loads((BROWSER / "manifest.json").read_text(encoding="utf-8"))
    value = str(manifest.get("version", "")).strip()
    if not value:
        raise RuntimeError("browser manifest version missing")
    return value


def shipped_files(base: Path, component_ref: str) -> list[dict[str, Any]]:
    ignored = {"node_modules", "vendor", ".git", ".DS_Store"}
    rows: list[dict[str, Any]] = []
    for path in sorted(base.rglob("*")):
        if not path.is_file() or any(part in ignored for part in path.parts):
            continue
        rel = path.relative_to(ROOT).as_posix()
        rows.append({
            "type": "file",
            "bom-ref": f"file:{rel}",
            "name": rel,
            "hashes": [{"alg": "SHA-256", "content": sha256(path)}],
            "properties": [
                {"name": "rpstudio:component", "value": component_ref},
                {"name": "rpstudio:bytes", "value": str(path.stat().st_size)},
            ],
        })
    return rows


def build() -> dict[str, Any]:
    commit = source_commit()
    pv = plugin_version()
    bv = browser_version()
    plugin_ref = f"pkg:wordpress/prstudio-unified-control@{pv}"
    browser_ref = f"pkg:generic/prstudio-unified-browser-agent@{bv}"
    files = shipped_files(PLUGIN, plugin_ref) + shipped_files(BROWSER, browser_ref)
    components = [
        {
            "type": "application",
            "bom-ref": plugin_ref,
            "name": "prstudio-unified-control",
            "version": pv,
            "purl": plugin_ref,
            "properties": [{"name": "rpstudio:platform", "value": "WordPress"}],
        },
        {
            "type": "application",
            "bom-ref": browser_ref,
            "name": "prstudio-unified-browser-agent",
            "version": bv,
            "purl": browser_ref,
            "properties": [{"name": "rpstudio:platform", "value": "Chrome Manifest V3"}],
        },
        *files,
    ]
    return {
        "bomFormat": "CycloneDX",
        "specVersion": "1.6",
        "version": 1,
        "metadata": {
            "component": {
                "type": "application",
                "bom-ref": "pkg:generic/rpconnector-suite@1.0.0",
                "name": "RP Connector Suite",
                "version": "1.0.0",
                "components": [
                    {"type": "application", "bom-ref": plugin_ref, "name": "prstudio-unified-control", "version": pv},
                    {"type": "application", "bom-ref": browser_ref, "name": "prstudio-unified-browser-agent", "version": bv},
                ],
            },
            "properties": [
                {"name": "rpstudio:deterministic", "value": "true"},
                {"name": "rpstudio:file_hash_algorithm", "value": "SHA-256"},
                {"name": "rpstudio:source_commit", "value": commit},
            ],
        },
        "components": components,
        "dependencies": [
            {"ref": "pkg:generic/rpconnector-suite@1.0.0", "dependsOn": [plugin_ref, browser_ref]},
            {"ref": plugin_ref, "dependsOn": []},
            {"ref": browser_ref, "dependsOn": []},
        ],
    }


def validate(value: dict[str, Any]) -> list[str]:
    errors: list[str] = []
    if value.get("bomFormat") != "CycloneDX" or value.get("specVersion") != "1.6":
        errors.append("unexpected CycloneDX identity")
    properties = value.get("metadata", {}).get("properties", []) if isinstance(value.get("metadata"), dict) else []
    source_values = [p.get("value") for p in properties if isinstance(p, dict) and p.get("name") == "rpstudio:source_commit"]
    if source_values != [source_commit()]:
        errors.append("SBOM source commit is absent, duplicated or stale")
    components = value.get("components")
    if not isinstance(components, list) or len(components) < 3:
        errors.append("components missing")
        return errors
    refs: set[str] = set()
    for component in components:
        if not isinstance(component, dict):
            errors.append("non-object component")
            continue
        ref = str(component.get("bom-ref", ""))
        if not ref:
            errors.append("component without bom-ref")
        elif ref in refs:
            errors.append(f"duplicate bom-ref {ref}")
        refs.add(ref)
        if component.get("type") == "file":
            hashes = component.get("hashes")
            if not isinstance(hashes, list) or len(hashes) != 1:
                errors.append(f"file {ref} has invalid hash set")
            else:
                digest = str(hashes[0].get("content", ""))
                if hashes[0].get("alg") != "SHA-256" or len(digest) != 64 or any(c not in "0123456789abcdef" for c in digest.lower()):
                    errors.append(f"file {ref} has invalid SHA-256")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", default="dist/sbom.cdx.json")
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    value = build()
    errors = validate(value)
    output = Path(args.output)
    if not output.is_absolute():
        output = ROOT / output
    output.parent.mkdir(parents=True, exist_ok=True)
    serialized = json.dumps(value, indent=2, sort_keys=True, ensure_ascii=False) + "\n"
    if args.check and output.exists():
        current = output.read_text(encoding="utf-8")
        if current != serialized:
            errors.append(f"{output.relative_to(ROOT)} is not reproducible from current source")
    else:
        output.write_text(serialized, encoding="utf-8")
    print("PRODUCTION SBOM")
    print(f"commit={source_commit()} components={len(value['components'])} output={output.relative_to(ROOT)}")
    for error in errors:
        print("FAIL", error)
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
