#!/usr/bin/env python3
"""Stamp and verify Browser Agent + Control identity for one exact Git checkout.

A commit cannot contain its own SHA, so source BUILD-INFO files intentionally carry
UNSTAMPED/+unbound placeholders. Candidate CI calls this before packaging. Both
components are stamped in one transaction so the Browser Agent and Control expose
the same source SHA and the same hashes of the server contract/protocol sources.
"""
from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import subprocess
import tempfile
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
BROWSER = ROOT / "prstudio-unified-browser-agent"
CONTROL = ROOT / "prstudio-unified-control"
BROWSER_BUILD_INFO = BROWSER / "BUILD-INFO.json"
CONTROL_BUILD_INFO = CONTROL / "BUILD-INFO.json"
EXECUTOR_META = BROWSER / "lib" / "executor-meta.js"
SERVICE_WORKER = BROWSER / "service-worker.js"
CONTRACT_FILE = CONTROL / "includes" / "class-prstudio-uc-contract.php"
PROTOCOL_FILE = CONTROL / "includes" / "class-prstudio-uc-browser-protocol.php"
IDENTITY_PATHS = {
    "prstudio-unified-browser-agent/BUILD-INFO.json",
    "prstudio-unified-browser-agent/lib/executor-meta.js",
    "prstudio-unified-browser-agent/service-worker.js",
    "prstudio-unified-control/BUILD-INFO.json",
}
SHA_RE = re.compile(r"^[0-9a-f]{40}$")


class BuildIdentityError(RuntimeError):
    pass


def fail(message: str) -> None:
    raise BuildIdentityError(message)


def git(*args: str) -> str:
    try:
        result = subprocess.run(
            ["git", *args], cwd=ROOT, check=True, text=True,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=30,
        )
    except (OSError, subprocess.SubprocessError) as exc:
        raise BuildIdentityError(f"git {' '.join(args)} failed: {exc}") from exc
    return result.stdout.strip()


def git_paths(*args: str) -> set[str]:
    return {line.strip() for line in git(*args).splitlines() if line.strip()}


def assert_workspace_identity_boundary(*, verify_only: bool) -> None:
    tracked = git_paths("diff", "HEAD", "--name-only", "--")
    untracked = git_paths("ls-files", "--others", "--exclude-standard")
    if untracked:
        fail("checkout contains untracked source bytes: " + ", ".join(sorted(untracked)))
    expected = IDENTITY_PATHS if verify_only else set()
    if tracked != expected:
        fail("checkout tracked-diff boundary mismatch: expected " + repr(sorted(expected)) + " got " + repr(sorted(tracked)))


def normalize_source_sha(raw: str) -> str:
    sha = raw.strip().lower()
    if not SHA_RE.fullmatch(sha):
        fail("source SHA must be exactly 40 lowercase hexadecimal characters")
    head = git("rev-parse", "HEAD").lower()
    if head != sha:
        fail(f"requested source SHA {sha} does not match checkout HEAD {head}")
    return sha


def source_epoch(sha: str) -> int:
    raw = os.environ.get("SOURCE_DATE_EPOCH", "").strip() or git("show", "-s", "--format=%ct", sha)
    try:
        value = int(raw)
    except ValueError as exc:
        raise BuildIdentityError(f"invalid build identity epoch {raw!r}") from exc
    stamp = datetime.fromtimestamp(value, tz=timezone.utc)
    if stamp.year < 1980 or stamp.year > 2107:
        fail("build identity epoch must fit ZIP timestamp range 1980..2107")
    return value


def iso_timestamp(epoch: int) -> str:
    return datetime.fromtimestamp(epoch, tz=timezone.utc).isoformat().replace("+00:00", "Z")


def sha256_file(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


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


def load_info(path: Path) -> dict:
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise BuildIdentityError(f"cannot read {path.relative_to(ROOT)}: {exc}") from exc
    if not isinstance(value, dict):
        fail(f"{path.relative_to(ROOT)} root must be an object")
    return value


def stamp_browser_build_info(sha: str, timestamp: str, build_id: str, contract_hash: str, protocol_hash: str) -> None:
    info = load_info(BROWSER_BUILD_INFO)
    guarded_existing(str(info.get("source_commit", "")), {"UNSTAMPED", sha}, "Browser BUILD-INFO source_commit")
    guarded_existing(str(info.get("build_id", "")), {"prstudio-browser-1.0.0+unbound", build_id}, "Browser BUILD-INFO build_id")
    guarded_existing(str(info.get("built_at_utc", "")), {"UNSTAMPED", timestamp}, "Browser BUILD-INFO built_at_utc")
    for key, value in (("control_contract_sha256", contract_hash), ("control_protocol_sha256", protocol_hash)):
        guarded_existing(str(info.get(key, "UNSTAMPED")), {"UNSTAMPED", value}, f"Browser BUILD-INFO {key}")
        info[key] = value
    info["source_commit"] = sha
    info["build_id"] = build_id
    info["built_at_utc"] = timestamp
    atomic_text_write(BROWSER_BUILD_INFO, json.dumps(info, ensure_ascii=False, indent=2) + "\n")


def stamp_control_build_info(sha: str, timestamp: str, build_id: str, contract_hash: str, protocol_hash: str) -> None:
    info = load_info(CONTROL_BUILD_INFO)
    guarded_existing(str(info.get("source_commit", "")), {"UNSTAMPED", sha}, "Control BUILD-INFO source_commit")
    guarded_existing(str(info.get("build_id", "")), {"prstudio-control-1.0.0+unbound", build_id}, "Control BUILD-INFO build_id")
    guarded_existing(str(info.get("built_at_utc", "")), {"UNSTAMPED", timestamp}, "Control BUILD-INFO built_at_utc")
    guarded_existing(str(info.get("contract_file_sha256", "")), {"UNSTAMPED", contract_hash}, "Control contract hash")
    guarded_existing(str(info.get("protocol_file_sha256", "")), {"UNSTAMPED", protocol_hash}, "Control protocol hash")
    info["source_commit"] = sha
    info["build_id"] = build_id
    info["built_at_utc"] = timestamp
    info["contract_file_sha256"] = contract_hash
    info["protocol_file_sha256"] = protocol_hash
    atomic_text_write(CONTROL_BUILD_INFO, json.dumps(info, ensure_ascii=False, indent=2) + "\n")


def replace_export(text: str, name: str, value: str, allowed: set[str]) -> str:
    pattern = re.compile(rf'^export const {re.escape(name)} = "([^"]*)";$', re.MULTILINE)
    match = pattern.search(text)
    if not match:
        fail(f"executor-meta.js missing {name} export")
    guarded_existing(match.group(1), allowed, f"executor-meta {name}")
    return text[:match.start()] + f'export const {name} = "{value}";' + text[match.end():]


def stamp_executor_meta(sha: str, timestamp: str, build_id: str) -> None:
    text = EXECUTOR_META.read_text(encoding="utf-8")
    text = replace_export(text, "EXECUTOR_SOURCE_SHA", sha, {"UNSTAMPED", sha})
    text = replace_export(text, "EXECUTOR_BUILD_TIMESTAMP", timestamp, {"UNSTAMPED", timestamp})
    pattern = re.compile(r"^export const EXECUTOR_BUILD_ID = (.+);$", re.MULTILINE)
    match = pattern.search(text)
    if not match:
        fail("executor-meta.js missing EXECUTOR_BUILD_ID export")
    existing = match.group(1).strip()
    allowed = {"`prstudio-browser-${SUITE_VERSION}+unbound`", json.dumps(build_id)}
    if existing not in allowed:
        fail(f"executor-meta EXECUTOR_BUILD_ID is already bound unexpectedly: {existing}")
    text = text[:match.start()] + f"export const EXECUTOR_BUILD_ID = {json.dumps(build_id)};" + text[match.end():]
    atomic_text_write(EXECUTOR_META, text)


def stamp_service_worker(sha: str) -> None:
    text = SERVICE_WORKER.read_text(encoding="utf-8")
    marker = "    agentBuild: EXECUTOR_BUILD_ID,\n    buildTimestamp: EXECUTOR_BUILD_TIMESTAMP,\n"
    if marker not in text:
        fail("service-worker status identity marker is missing")
    pattern = re.compile(r'^    sourceSha: "([0-9a-f]{40})",$', re.MULTILINE)
    match = pattern.search(text)
    if match:
        guarded_existing(match.group(1), {sha}, "service-worker sourceSha")
    else:
        text = text.replace(marker, marker + f'    sourceSha: "{sha}",\n', 1)
    atomic_text_write(SERVICE_WORKER, text)


def verify(sha: str, timestamp: str, browser_id: str, control_id: str, contract_hash: str, protocol_hash: str) -> None:
    browser = load_info(BROWSER_BUILD_INFO)
    control = load_info(CONTROL_BUILD_INFO)
    checks = {
        "Browser source_commit": browser.get("source_commit") == sha,
        "Browser build_id": browser.get("build_id") == browser_id,
        "Browser built_at": browser.get("built_at_utc") == timestamp,
        "Browser control contract hash": browser.get("control_contract_sha256") == contract_hash,
        "Browser control protocol hash": browser.get("control_protocol_sha256") == protocol_hash,
        "Control source_commit": control.get("source_commit") == sha,
        "Control build_id": control.get("build_id") == control_id,
        "Control built_at": control.get("built_at_utc") == timestamp,
        "Control contract hash": control.get("contract_file_sha256") == contract_hash,
        "Control protocol hash": control.get("protocol_file_sha256") == protocol_hash,
        "Control required capability hash": control.get("required_browser_capability_contract_sha256") == browser.get("capability_contract_sha256"),
        "Control required GSC session": control.get("required_gsc_dimension_session_version") == "4.0.0",
    }
    meta = EXECUTOR_META.read_text(encoding="utf-8")
    checks.update({
        "executor source SHA": f'export const EXECUTOR_SOURCE_SHA = "{sha}";' in meta,
        "executor build timestamp": f'export const EXECUTOR_BUILD_TIMESTAMP = "{timestamp}";' in meta,
        "executor build id": f"export const EXECUTOR_BUILD_ID = {json.dumps(browser_id)};" in meta,
        "executor GSC session v4": 'export const GSC_DIMENSION_SESSION_VERSION = "4.0.0";' in meta,
    })
    worker = SERVICE_WORKER.read_text(encoding="utf-8")
    checks["runtime source SHA"] = worker.count(f'    sourceSha: "{sha}",') == 1
    failed = [label for label, ok in checks.items() if not ok]
    if failed:
        fail("build identity verification failed: " + ", ".join(failed))


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Stamp/verify exact Browser Agent + Control source identity")
    parser.add_argument("--source-sha", required=True, help="exact 40-character Git commit SHA")
    parser.add_argument("--verify", action="store_true", help="verify only; do not mutate files")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    sha = normalize_source_sha(args.source_sha)
    assert_workspace_identity_boundary(verify_only=args.verify)
    timestamp = iso_timestamp(source_epoch(sha))
    browser_id = f"prstudio-browser-1.0.0+git.{sha[:12]}"
    control_id = f"prstudio-control-1.0.0+git.{sha[:12]}"
    contract_hash = sha256_file(CONTRACT_FILE)
    protocol_hash = sha256_file(PROTOCOL_FILE)
    if not args.verify:
        stamp_browser_build_info(sha, timestamp, browser_id, contract_hash, protocol_hash)
        stamp_control_build_info(sha, timestamp, control_id, contract_hash, protocol_hash)
        stamp_executor_meta(sha, timestamp, browser_id)
        stamp_service_worker(sha)
        assert_workspace_identity_boundary(verify_only=True)
    verify(sha, timestamp, browser_id, control_id, contract_hash, protocol_hash)
    mode = "verify" if args.verify else "stamp"
    print(f"PASS release build identity {mode}: source_sha={sha} browser={browser_id} control={control_id} built_at={timestamp} contract_sha256={contract_hash} protocol_sha256={protocol_hash}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except BuildIdentityError as exc:
        print(f"FAIL release build identity: {exc}")
        raise SystemExit(1)
