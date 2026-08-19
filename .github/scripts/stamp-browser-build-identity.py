#!/usr/bin/env python3
"""Stamp the Browser Agent with the exact Git source identity for a build.

The repository intentionally stores an UNSTAMPED/unbound identity because a
commit cannot contain its own Git SHA. Official candidate builds must run this
script before regenerating component integrity documents, testing and packaging.
The script fails closed if the requested SHA is not the checkout HEAD or if it
finds metadata already stamped for a different commit.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BROWSER = ROOT / "prstudio-unified-browser-agent"
BUILD_INFO = BROWSER / "BUILD-INFO.json"
EXECUTOR_META = BROWSER / "lib" / "executor-meta.js"
SERVICE_WORKER = BROWSER / "service-worker.js"
SHA_RE = re.compile(r"^[0-9a-f]{40}$")


class BuildIdentityError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise BuildIdentityError(message)


def git(*args: str) -> str:
    try:
        result = subprocess.run(
            ["git", *args],
            cwd=ROOT,
            check=True,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=30,
        )
    except (OSError, subprocess.SubprocessError) as exc:
        raise BuildIdentityError(f"git {' '.join(args)} failed: {exc}") from exc
    return result.stdout.strip()


def normalize_source_sha(raw: str) -> str:
    sha = raw.strip().lower()
    if not SHA_RE.fullmatch(sha):
        fail("source SHA must be exactly 40 lowercase hexadecimal characters")
    head = git("rev-parse", "HEAD").lower()
    if head != sha:
        fail(f"requested source SHA {sha} does not match checkout HEAD {head}")
    return sha


def source_epoch(sha: str) -> int:
    raw = os.environ.get("SOURCE_DATE_EPOCH", "").strip()
    if raw:
        try:
            value = int(raw)
        except ValueError as exc:
            raise BuildIdentityError("SOURCE_DATE_EPOCH must be an integer") from exc
    else:
        raw = git("show", "-s", "--format=%ct", sha)
        try:
            value = int(raw)
        except ValueError as exc:
            raise BuildIdentityError(f"git returned invalid commit epoch {raw!r}") from exc
    stamp = datetime.fromtimestamp(value, tz=timezone.utc)
    if stamp.year < 1980 or stamp.year > 2107:
        fail("build identity epoch must fit ZIP timestamp range 1980..2107")
    return value


def iso_timestamp(epoch: int) -> str:
    return datetime.fromtimestamp(epoch, tz=timezone.utc).isoformat().replace("+00:00", "Z")


def atomic_text_write(path: Path, text: str) -> None:
    descriptor, temporary_name = tempfile.mkstemp(prefix=f".{path.name}.", suffix=".tmp", dir=path.parent)
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as handle:
            handle.write(text)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def guarded_existing(value: str, allowed: set[str], label: str) -> None:
    if value not in allowed:
        fail(f"{label} is already bound to unexpected value {value!r}")


def stamp_build_info(sha: str, timestamp: str, build_id: str) -> None:
    try:
        info = json.loads(BUILD_INFO.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise BuildIdentityError(f"cannot read BUILD-INFO.json: {exc}") from exc
    if not isinstance(info, dict):
        fail("BUILD-INFO.json root must be an object")
    guarded_existing(str(info.get("source_commit", "")), {"UNSTAMPED", sha}, "BUILD-INFO source_commit")
    guarded_existing(str(info.get("build_id", "")), {"prstudio-browser-1.0.0+unbound", build_id}, "BUILD-INFO build_id")
    guarded_existing(str(info.get("built_at_utc", "")), {"UNSTAMPED", timestamp}, "BUILD-INFO built_at_utc")
    info["source_commit"] = sha
    info["build_id"] = build_id
    info["built_at_utc"] = timestamp
    atomic_text_write(BUILD_INFO, json.dumps(info, ensure_ascii=False, indent=2) + "\n")


def replace_export(text: str, name: str, value: str, allowed: set[str]) -> str:
    pattern = re.compile(rf'^export const {re.escape(name)} = "([^"]*)";$', re.MULTILINE)
    match = pattern.search(text)
    if not match:
        fail(f"executor-meta.js missing {name} export")
    guarded_existing(match.group(1), allowed, f"executor-meta {name}")
    return text[: match.start()] + f'export const {name} = "{value}";' + text[match.end() :]


def stamp_executor_meta(sha: str, timestamp: str, build_id: str) -> None:
    text = EXECUTOR_META.read_text(encoding="utf-8")
    text = replace_export(text, "EXECUTOR_SOURCE_SHA", sha, {"UNSTAMPED", sha})
    text = replace_export(text, "EXECUTOR_BUILD_TIMESTAMP", timestamp, {"UNSTAMPED", timestamp})
    build_pattern = re.compile(r"^export const EXECUTOR_BUILD_ID = (.+);$", re.MULTILINE)
    match = build_pattern.search(text)
    if not match:
        fail("executor-meta.js missing EXECUTOR_BUILD_ID export")
    existing = match.group(1).strip()
    allowed = {"`prstudio-browser-${SUITE_VERSION}+unbound`", json.dumps(build_id)}
    if existing not in allowed:
        fail(f"executor-meta EXECUTOR_BUILD_ID is already bound unexpectedly: {existing}")
    text = text[: match.start()] + f"export const EXECUTOR_BUILD_ID = {json.dumps(build_id)};" + text[match.end() :]
    atomic_text_write(EXECUTOR_META, text)


def stamp_service_worker(sha: str) -> None:
    text = SERVICE_WORKER.read_text(encoding="utf-8")
    marker = "    agentBuild: EXECUTOR_BUILD_ID,\n    buildTimestamp: EXECUTOR_BUILD_TIMESTAMP,\n"
    if marker not in text:
        fail("service-worker status identity marker is missing")
    source_pattern = re.compile(r'^    sourceSha: "([0-9a-f]{40})",$', re.MULTILINE)
    match = source_pattern.search(text)
    if match:
        guarded_existing(match.group(1), {sha}, "service-worker sourceSha")
    else:
        text = text.replace(marker, marker + f'    sourceSha: "{sha}",\n', 1)
    atomic_text_write(SERVICE_WORKER, text)


def verify(sha: str, timestamp: str, build_id: str) -> None:
    info = json.loads(BUILD_INFO.read_text(encoding="utf-8"))
    checks = {
        "BUILD-INFO source_commit": info.get("source_commit") == sha,
        "BUILD-INFO build_id": info.get("build_id") == build_id,
        "BUILD-INFO built_at_utc": info.get("built_at_utc") == timestamp,
    }
    meta = EXECUTOR_META.read_text(encoding="utf-8")
    checks.update(
        {
            "executor source SHA": f'export const EXECUTOR_SOURCE_SHA = "{sha}";' in meta,
            "executor build timestamp": f'export const EXECUTOR_BUILD_TIMESTAMP = "{timestamp}";' in meta,
            "executor build id": f"export const EXECUTOR_BUILD_ID = {json.dumps(build_id)};" in meta,
        }
    )
    worker = SERVICE_WORKER.read_text(encoding="utf-8")
    checks["runtime source SHA"] = worker.count(f'    sourceSha: "{sha}",') == 1
    failed = [label for label, ok in checks.items() if not ok]
    if failed:
        fail("build identity verification failed: " + ", ".join(failed))


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Stamp/verify exact Browser Agent source identity")
    parser.add_argument("--source-sha", required=True, help="exact 40-character Git commit SHA")
    parser.add_argument("--verify", action="store_true", help="verify only; do not mutate files")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    sha = normalize_source_sha(args.source_sha)
    epoch = source_epoch(sha)
    timestamp = iso_timestamp(epoch)
    build_id = f"prstudio-browser-1.0.0+git.{sha[:12]}"
    if not args.verify:
        stamp_build_info(sha, timestamp, build_id)
        stamp_executor_meta(sha, timestamp, build_id)
        stamp_service_worker(sha)
    verify(sha, timestamp, build_id)
    mode = "verify" if args.verify else "stamp"
    print(f"PASS browser build identity {mode}: source_sha={sha} build_id={build_id} built_at={timestamp}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except BuildIdentityError as exc:
        print(f"FAIL browser build identity: {exc}")
        raise SystemExit(1)
