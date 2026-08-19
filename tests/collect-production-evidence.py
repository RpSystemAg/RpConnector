#!/usr/bin/env python3
"""Collect external production evidence from GitHub Actions for one exact SHA.

The collector rejects cross-SHA evidence, unsuccessful/in-progress runs, duplicate
receipt filenames, unsafe ZIP paths, artifact name mismatches, dynamic artifact
cardinality mismatches and output collisions. It is intended to run immediately
before production-readiness-certifier.py in release CI.
"""
from __future__ import annotations

import argparse
import fnmatch
import io
import json
import os
import shutil
import urllib.parse
import urllib.request
import zipfile
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
DEFAULT_SOURCES = ROOT / "quality" / "production-evidence-sources.json"
DEFAULT_OUT = ROOT / "evidence" / "production"
API = os.environ.get("GITHUB_API_URL", "https://api.github.com").rstrip("/")


def request_json(url: str, token: str) -> Any:
    request = urllib.request.Request(
        url,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {token}",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "RPConnector-Production-Evidence/1",
        },
    )
    with urllib.request.urlopen(request, timeout=60) as response:
        return json.loads(response.read().decode("utf-8"))


def request_bytes(url: str, token: str) -> bytes:
    request = urllib.request.Request(
        url,
        headers={
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {token}",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "RPConnector-Production-Evidence/1",
        },
    )
    with urllib.request.urlopen(request, timeout=120) as response:
        return response.read()


def safe_extract(data: bytes, destination: Path) -> list[Path]:
    extracted: list[Path] = []
    root = destination.resolve()
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        for info in archive.infolist():
            name = info.filename.replace("\\", "/")
            if name.endswith("/"):
                continue
            if name.startswith("/") or ".." in Path(name).parts:
                raise RuntimeError(f"unsafe artifact path: {name}")
            target = (destination / name).resolve()
            if root != target and root not in target.parents:
                raise RuntimeError(f"artifact escapes destination: {name}")
            target.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(info, "r") as source, target.open("wb") as sink:
                shutil.copyfileobj(source, sink)
            extracted.append(target)
    return extracted


def find_successful_run(repository: str, workflow: str, commit: str, token: str) -> dict[str, Any] | None:
    encoded = urllib.parse.quote(workflow, safe="")
    query = urllib.parse.urlencode({"head_sha": commit, "status": "completed", "per_page": 100})
    data = request_json(f"{API}/repos/{repository}/actions/workflows/{encoded}/runs?{query}", token)
    runs = data.get("workflow_runs", []) if isinstance(data, dict) else []
    candidates = [
        run for run in runs
        if run.get("head_sha") == commit
        and run.get("status") == "completed"
        and run.get("conclusion") == "success"
    ]
    candidates.sort(key=lambda run: (str(run.get("updated_at", "")), int(run.get("id", 0))), reverse=True)
    return candidates[0] if candidates else None


def find_artifact(repository: str, run_id: int, prefix: str, commit: str, token: str) -> dict[str, Any] | None:
    data = request_json(f"{API}/repos/{repository}/actions/runs/{run_id}/artifacts?per_page=100", token)
    artifacts = data.get("artifacts", []) if isinstance(data, dict) else []
    expected = prefix + commit
    matches = [
        artifact for artifact in artifacts
        if artifact.get("name") == expected and artifact.get("expired") is not True
    ]
    matches.sort(key=lambda artifact: int(artifact.get("id", 0)), reverse=True)
    return matches[0] if matches else None


def receipt_commit(path: Path) -> str:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        raise RuntimeError(f"invalid JSON receipt {path.name}: {exc}") from exc
    return str(value.get("commit_sha", "")) if isinstance(value, dict) else ""


def validate_copy_globs(specs: Any, by_name: dict[str, Path]) -> list[str]:
    """Resolve basename-only dynamic files with explicit cardinality bounds."""
    if specs in (None, []):
        return []
    if not isinstance(specs, list):
        raise RuntimeError("copy_globs must be a list")
    selected: list[str] = []
    seen: set[str] = set()
    for index, spec in enumerate(specs):
        if not isinstance(spec, dict):
            raise RuntimeError(f"copy_globs[{index}] must be an object")
        pattern = str(spec.get("pattern", "")).strip()
        if not pattern or Path(pattern).name != pattern or "/" in pattern or "\\" in pattern or ".." in pattern:
            raise RuntimeError(f"copy_globs[{index}] pattern must be a safe basename glob")
        try:
            minimum = int(spec.get("min_count", 1))
            maximum = int(spec.get("max_count", minimum))
        except Exception as exc:
            raise RuntimeError(f"copy_globs[{index}] cardinality is not integer") from exc
        if minimum < 0 or maximum < minimum:
            raise RuntimeError(f"copy_globs[{index}] has invalid cardinality {minimum}..{maximum}")
        matches = sorted(name for name in by_name if fnmatch.fnmatchcase(name, pattern))
        if len(matches) < minimum or len(matches) > maximum:
            raise RuntimeError(
                f"copy_globs[{index}] pattern={pattern!r} expected {minimum}..{maximum} files, found {len(matches)}: {matches}"
            )
        duplicate = seen.intersection(matches)
        if duplicate:
            raise RuntimeError(f"copy_globs select the same file more than once: {sorted(duplicate)}")
        seen.update(matches)
        selected.extend(matches)
    return selected


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--repository", default=os.environ.get("GITHUB_REPOSITORY", ""))
    parser.add_argument("--commit", default=os.environ.get("GITHUB_SHA", ""))
    parser.add_argument("--token", default=os.environ.get("GITHUB_TOKEN", ""))
    parser.add_argument("--sources", default=str(DEFAULT_SOURCES))
    parser.add_argument("--output", default=str(DEFAULT_OUT))
    parser.add_argument("--strict", action="store_true")
    args = parser.parse_args()

    repository = args.repository.strip()
    commit = args.commit.strip()
    token = args.token.strip()
    if "/" not in repository:
        raise SystemExit("repository owner/name is required")
    if len(commit) != 40 or any(ch not in "0123456789abcdefABCDEF" for ch in commit):
        raise SystemExit("exact 40-hex commit is required")
    if not token:
        raise SystemExit("GITHUB_TOKEN/actions:read is required")

    sources_path = Path(args.sources)
    if not sources_path.is_absolute():
        sources_path = ROOT / sources_path
    output = Path(args.output)
    if not output.is_absolute():
        output = ROOT / output
    output.mkdir(parents=True, exist_ok=True)
    config = json.loads(sources_path.read_text(encoding="utf-8"))

    failures: list[str] = []
    collected: list[dict[str, Any]] = []
    claimed_names: set[str] = set()

    for source in config.get("sources", []):
        source_id = str(source.get("id", ""))
        workflow = str(source.get("workflow", ""))
        prefix = str(source.get("artifact_name_prefix", ""))
        required_files = [str(name) for name in source.get("required_files", [])]
        copy_globs = source.get("copy_globs", [])
        if not source_id or not workflow or not prefix or not required_files:
            failures.append(f"invalid evidence source definition: {source!r}")
            continue
        duplicate = claimed_names.intersection(required_files)
        if duplicate:
            failures.append(f"{source_id}: duplicate required filenames across sources: {sorted(duplicate)}")
            continue
        claimed_names.update(required_files)

        temp: Path | None = None
        try:
            run = find_successful_run(repository, workflow, commit, token)
            if not run:
                failures.append(f"{source_id}: no successful completed {workflow} run for exact SHA {commit}")
                continue
            run_id = int(run["id"])
            artifact = find_artifact(repository, run_id, prefix, commit, token)
            if not artifact:
                failures.append(f"{source_id}: expected artifact {prefix}{commit!s} not found on run {run_id}")
                continue
            artifact_id = int(artifact["id"])
            archive = request_bytes(f"{API}/repos/{repository}/actions/artifacts/{artifact_id}/zip", token)
            temp = output / f".incoming-{source_id}-{artifact_id}"
            if temp.exists():
                shutil.rmtree(temp)
            temp.mkdir(parents=True)
            extracted = safe_extract(archive, temp)
            by_name: dict[str, Path] = {}
            for path in extracted:
                name = path.name
                if name in by_name:
                    raise RuntimeError(f"artifact contains duplicate basename {name}")
                by_name[name] = path
            missing = [name for name in required_files if name not in by_name]
            if missing:
                raise RuntimeError(f"artifact missing required files: {missing}")

            dynamic_files = validate_copy_globs(copy_globs, by_name)
            files_to_copy = required_files + dynamic_files
            if len(files_to_copy) != len(set(files_to_copy)):
                raise RuntimeError("required_files and copy_globs overlap")
            cross_source = claimed_names.intersection(dynamic_files)
            if cross_source:
                raise RuntimeError(f"dynamic filenames collide with another evidence source: {sorted(cross_source)}")
            claimed_names.update(dynamic_files)

            targets = [output / name for name in files_to_copy]
            collisions = [str(path) for path in targets if path.exists()]
            if collisions:
                raise RuntimeError(f"refusing to overwrite existing evidence files: {collisions}")

            for name in files_to_copy:
                source_path = by_name[name]
                if name.endswith(".json") and name != "wordpress-lifecycle-details.json":
                    bound = receipt_commit(source_path)
                    if bound and bound != commit:
                        raise RuntimeError(f"{name} commit_sha={bound!r} != exact release SHA {commit!r}")
                shutil.copy2(source_path, output / name)

            collected.append({
                "source": source_id,
                "workflow": workflow,
                "run_id": run_id,
                "run_url": run.get("html_url"),
                "artifact_id": artifact_id,
                "artifact_name": artifact.get("name"),
                "files": files_to_copy,
                "required_files": required_files,
                "dynamic_files": dynamic_files,
            })
        except Exception as exc:
            failures.append(f"{source_id}: {type(exc).__name__}: {exc}")
        finally:
            if temp is not None and temp.exists():
                shutil.rmtree(temp)

    manifest = {
        "schema_version": 1,
        "repository": repository,
        "commit_sha": commit,
        "sources_file": str(sources_path),
        "collected": collected,
        "failures": failures,
    }
    manifest_path = output / "collected-evidence-manifest.json"
    manifest_path.write_text(json.dumps(manifest, indent=2, sort_keys=True) + "\n", encoding="utf-8")

    print("PRODUCTION EVIDENCE COLLECTION")
    print(f"repository={repository} commit={commit}")
    for item in collected:
        print(f"PASS {item['source']} run={item['run_id']} artifact={item['artifact_name']} files={len(item['files'])}")
    for failure in failures:
        print(f"FAIL {failure}")
    print(f"manifest={manifest_path}")
    return 1 if args.strict and failures else 0


if __name__ == "__main__":
    raise SystemExit(main())
