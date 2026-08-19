#!/usr/bin/env python3
"""Build the same commit twice from independent clean source copies.

The release builder already normalizes ZIP order/timestamps. This test adds a
second trust boundary: two fresh directories with no shared generated state must
produce byte-identical component ZIPs, integrity documents and CycloneDX SBOM.
"""
from __future__ import annotations

import hashlib
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ARTIFACTS = [
    "prstudio-unified-control-1.0.0.zip",
    "prstudio-unified-browser-agent-1.0.0.zip",
    "prstudio-unified-control/MANIFEST.json",
    "prstudio-unified-control/FILE-INTEGRITY.json",
    "prstudio-unified-browser-agent/COMPONENT-MANIFEST.json",
    "prstudio-unified-browser-agent/FILE-INTEGRITY.json",
    "dist/sbom.cdx.json",
]
# Keep .git in each independent copy because the SBOM deliberately binds itself
# to the exact source commit. Generated/dependency state is excluded.
EXCLUDE = {"vendor", "node_modules", "dist", "megalinter-reports", "__pycache__", ".hypothesis"}


def copy_source(dst: Path) -> None:
    def ignore(_dir: str, names: list[str]) -> set[str]:
        return {name for name in names if name in EXCLUDE or name.endswith(".pyc")}
    shutil.copytree(ROOT, dst, ignore=ignore, symlinks=False)


def sha(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def build(path: Path, epoch: str) -> dict[str, str]:
    env = os.environ.copy()
    env["SOURCE_DATE_EPOCH"] = epoch
    subprocess.run(
        [sys.executable, "tests/build-release.py", "--build"],
        cwd=path,
        env=env,
        text=True,
        timeout=180,
        check=True,
    )
    subprocess.run(
        [sys.executable, "tests/build-release.py", "--check"],
        cwd=path,
        env=env,
        text=True,
        timeout=180,
        check=True,
    )
    subprocess.run(
        [sys.executable, "tests/generate-production-sbom.py", "--output", "dist/sbom.cdx.json"],
        cwd=path,
        env=env,
        text=True,
        timeout=180,
        check=True,
    )
    result: dict[str, str] = {}
    for rel in ARTIFACTS:
        target = path / rel
        if not target.is_file():
            raise RuntimeError(f"clean build omitted required artifact {rel}")
        result[rel] = sha(target)
    return result


epoch = os.environ.get("SOURCE_DATE_EPOCH", "1786438800")
with tempfile.TemporaryDirectory(prefix="rp-repro-a-") as a, tempfile.TemporaryDirectory(prefix="rp-repro-b-") as b:
    pa, pb = Path(a) / "repo", Path(b) / "repo"
    copy_source(pa)
    copy_source(pb)
    first = build(pa, epoch)
    second = build(pb, epoch)

mismatches = {key: (first[key], second[key]) for key in ARTIFACTS if first[key] != second[key]}
print(f"REPRODUCIBLE CLEAN BUILD: artifacts={len(ARTIFACTS)} mismatches={len(mismatches)}")
for key in ARTIFACTS:
    print(f"{key}\t{first[key]}\t{second[key]}")
if mismatches:
    for key, values in mismatches.items():
        print(f"ERROR NONDETERMINISTIC {key}: {values[0]} != {values[1]}")
    raise SystemExit(1)
print("PASS two independent clean builds and SBOM are byte-identical")
