#!/usr/bin/env python3
"""Reproducible PR STUDIO 1.0.0 component builder and finalizer.

This script never creates test, quality, performance, security, preflight or
live-acceptance reports. Those files must be produced by their real test flows.

Modes (exactly one is required):

  python tests/build-release.py --check
      Non-mutating validation. Recomputes component file records and tree
      digests, checks existing component manifests and deterministic ZIP parity,
      and verifies final release metadata when it is already present.

  python tests/build-release.py --build
      Regenerates only per-component integrity documents and deterministic ZIPs.
      It does not create root reports, the root release manifest or checksums.

  python tests/build-release.py --finalize
      Requires the exact component manifests/ZIPs and every final report/document
      to exist and validate. It then writes RELEASE-MANIFEST-1.0.0.json and,
      last, COMPONENT-SHA256SUMS-1.0.0.txt. Neither file includes itself.

Reproducibility:

  Entry order, permissions, timestamps and JSON serialization are normalized.
  SOURCE_DATE_EPOCH can override the fixed release epoch; use the same value for
  --build, --check and --finalize. ZIPs contain exactly one top-level component
  folder. Symlinks, traversal, backslash paths and case-insensitive collisions
  are release errors.

The script uses only the Python standard library and requires Python 3.10+.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import sys
import tempfile
import zipfile
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath
from typing import Any, Iterable, Sequence


VERSION = "1.0.0"
SUITE_FOLDER = f"PR-STUDIO-Unified-Suite-{VERSION}"
CONTROL_FOLDER = "prstudio-unified-control"
BROWSER_FOLDER = "prstudio-unified-browser-agent"
CONTROL_ZIP = f"{CONTROL_FOLDER}-{VERSION}.zip"
BROWSER_ZIP = f"{BROWSER_FOLDER}-{VERSION}.zip"
RELEASE_MANIFEST = f"RELEASE-MANIFEST-{VERSION}.json"
CHECKSUM_FILE = f"COMPONENT-SHA256SUMS-{VERSION}.txt"

SCRIPT_PATH = Path(__file__).resolve()
ROOT = SCRIPT_PATH.parent.parent.resolve()

DEFAULT_RELEASE_EPOCH = int(datetime(2026, 8, 11, 9, 0, tzinfo=timezone.utc).timestamp())
TREE_DIGEST_DESCRIPTION = (
    "SHA-256 of sorted UTF-8 records: path NUL byte-count NUL file-sha256 LF"
)

CONTROL_EXCLUSIONS = ("FILE-INTEGRITY.json", "MANIFEST.json")
BROWSER_EXCLUSIONS = ("COMPONENT-MANIFEST.json", "FILE-INTEGRITY.json")

FINAL_REPORTS = (
    f"INSTALL-CONNECTION-COMPATIBILITY-{VERSION}.json",
    f"MCP-PLUGIN-PREFLIGHT-{VERSION}.json",
    f"PERFORMANCE-BENCHMARK-{VERSION}.json",
    f"QUALITY-GATE-{VERSION}.json",
    f"SECURITY-HARDENING-{VERSION}.json",
    f"TEST-REPORT-{VERSION}.json",
)

# LIVE-ACCEPTANCE is a maintained status document (an honest, human-updated
# PENDING/PASS checklist), not a generated ceremony report -- it lives here,
# not in FINAL_REPORTS, so its presence never forces check()/finalize() to
# require the auto-generated JSON report battery to exist alongside it.
FINAL_DOCUMENTS = (
    f"ARCHITECTURE-{VERSION}.md",
    f"H24-OPERATIONS-{VERSION}.md",
    f"SOCIAL-CONNECTORS-{VERSION}.md",
    f"VISIONE-E-DECISIONI-{VERSION}.md",
    f"LIVE-ACCEPTANCE-{VERSION}.md",
    f"RP-STUDIO-CHATGPT-PLUGIN-{VERSION}.json",
    f"RP-STUDIO-CHATGPT-PLUGIN-INSTRUCTIONS-{VERSION}.txt",
    f"RP-STUDIO-CHATGPT-PLUGIN-SETUP-{VERSION}.md",
    f"PR-STUDIO-{VERSION}-FINAL-REPORT.md",
)

FINAL_ROOT_ARTIFACTS = tuple(
    sorted((*FINAL_REPORTS, *FINAL_DOCUMENTS, CONTROL_ZIP, BROWSER_ZIP))
)

JSON_REPORTS_REQUIRING_GENERATION_TIME = (
    f"INSTALL-CONNECTION-COMPATIBILITY-{VERSION}.json",
    f"MCP-PLUGIN-PREFLIGHT-{VERSION}.json",
    f"PERFORMANCE-BENCHMARK-{VERSION}.json",
    f"QUALITY-GATE-{VERSION}.json",
    f"SECURITY-HARDENING-{VERSION}.json",
    f"TEST-REPORT-{VERSION}.json",
)


class ReleaseError(RuntimeError):
    """A deterministic, user-actionable release validation failure."""


def fail(message: str) -> None:
    raise ReleaseError(message)


def ensure_inside_root(path: Path) -> Path:
    resolved = path.resolve(strict=False)
    try:
        resolved.relative_to(ROOT)
    except ValueError as exc:
        raise ReleaseError(f"Path escapes suite root: {path}") from exc
    return resolved


def source_date_epoch() -> int:
    raw = os.environ.get("SOURCE_DATE_EPOCH", str(DEFAULT_RELEASE_EPOCH))
    try:
        value = int(raw)
    except ValueError as exc:
        raise ReleaseError("SOURCE_DATE_EPOCH must be an integer Unix timestamp") from exc
    stamp = datetime.fromtimestamp(value, tz=timezone.utc)
    if stamp.year < 1980 or stamp.year > 2107:
        fail("SOURCE_DATE_EPOCH must fit the ZIP timestamp range 1980..2107")
    return value


def epoch_iso(epoch: int) -> str:
    return datetime.fromtimestamp(epoch, tz=timezone.utc).isoformat().replace("+00:00", "Z")


def zip_datetime(epoch: int) -> tuple[int, int, int, int, int, int]:
    stamp = datetime.fromtimestamp(epoch, tz=timezone.utc)
    # DOS ZIP timestamps have two-second resolution.
    return (stamp.year, stamp.month, stamp.day, stamp.hour, stamp.minute, stamp.second - stamp.second % 2)


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def reject_duplicate_pairs(pairs: list[tuple[str, Any]]) -> dict[str, Any]:
    output: dict[str, Any] = {}
    for key, value in pairs:
        if key in output:
            fail(f"Duplicate JSON key: {key!r}")
        output[key] = value
    return output


def load_json(path: Path) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"), object_pairs_hook=reject_duplicate_pairs)
    except (UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise ReleaseError(f"Invalid JSON {path.relative_to(ROOT).as_posix()}: {exc}") from exc


def canonical_json_bytes(value: Any) -> bytes:
    return (
        json.dumps(value, ensure_ascii=False, indent=2, separators=(",", ": ")) + "\n"
    ).encode("utf-8")


def atomic_write(path: Path, payload: bytes) -> None:
    ensure_inside_root(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{path.name}.", suffix=".tmp", dir=str(path.parent)
    )
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
    finally:
        temporary.unlink(missing_ok=True)


def validate_suite_layout() -> None:
    if ROOT.name != SUITE_FOLDER:
        fail(f"Suite folder must be exactly {SUITE_FOLDER!r}; found {ROOT.name!r}")
    if SCRIPT_PATH != ROOT / "tests" / "build-release.py":
        fail("build-release.py must remain at tests/build-release.py")
    for folder in (CONTROL_FOLDER, BROWSER_FOLDER):
        target = ROOT / folder
        if not target.is_dir() or target.is_symlink():
            fail(f"Required real component directory is missing: {folder}")
        ensure_inside_root(target)


def validate_version_anchors() -> None:
    control = ROOT / CONTROL_FOLDER
    browser = ROOT / BROWSER_FOLDER

    bootstrap_path = control / "prstudio-unified-control.php"
    if not bootstrap_path.is_file():
        fail(f"Missing WordPress bootstrap: {bootstrap_path.relative_to(ROOT)}")
    bootstrap = bootstrap_path.read_text(encoding="utf-8")
    if not re.search(r"^\s*\*\s*Version:\s+1\.0\.0\s*$", bootstrap, re.MULTILINE):
        fail("WordPress plugin header is not 1.0.0")
    if not re.search(
        r"define\(\s*'PRSTUDIO_UC_VERSION'\s*,\s*'1\.0\.0'\s*\)", bootstrap
    ):
        fail("PRSTUDIO_UC_VERSION is not 1.0.0")

    control_build = load_json(control / "BUILD-INFO.json")
    if control_build.get("version") != VERSION:
        fail("Control BUILD-INFO.json version is not 1.0.0")

    browser_entries = {entry.name: entry for entry in browser.iterdir()}
    chrome_manifest_path = browser_entries.get("manifest.json")
    if chrome_manifest_path is None or not chrome_manifest_path.is_file():
        fail("Browser runtime manifest must exist with exact lowercase name manifest.json")
    if "MANIFEST.json" in browser_entries:
        fail("Browser MANIFEST.json collides with Chrome manifest.json; use COMPONENT-MANIFEST.json")
    chrome_manifest = load_json(chrome_manifest_path)
    if chrome_manifest.get("manifest_version") != 3:
        fail("Browser manifest.json must remain Chrome Manifest V3")
    if chrome_manifest.get("version") != VERSION:
        fail("Browser manifest.json product version is not 1.0.0")

    browser_build = load_json(browser / "BUILD-INFO.json")
    if browser_build.get("version") != VERSION or browser_build.get("product_version") != VERSION:
        fail("Browser BUILD-INFO.json version/product_version is not 1.0.0")

    executor_meta_path = browser / "lib" / "executor-meta.js"
    executor_meta = executor_meta_path.read_text(encoding="utf-8")
    if not re.search(r'EXECUTOR_PRODUCT_VERSION\s*=\s*"1\.0\.0"', executor_meta):
        fail("Browser executor product version is not 1.0.0")
    if not re.search(r'EXECUTOR_PROTOCOL_VERSION\s*=\s*"3\.0\.0"', executor_meta):
        fail("Browser wire protocol must remain 3.0.0")


def safe_relative(relative: str) -> str:
    if "\\" in relative:
        fail(f"Backslash path is forbidden in release metadata: {relative}")
    pure = PurePosixPath(relative)
    if pure.is_absolute() or not pure.parts or any(part in ("", ".", "..") for part in pure.parts):
        fail(f"Unsafe release path: {relative}")
    if re.match(r"^[A-Za-z]:", relative):
        fail(f"Drive-qualified release path: {relative}")
    return pure.as_posix()


def validate_casefolded_paths(paths: Iterable[str], context: str) -> None:
    seen: dict[str, str] = {}
    for raw in paths:
        current = safe_relative(raw)
        folded = current.casefold()
        previous = seen.get(folded)
        if previous is not None:
            if previous == current:
                fail(f"Duplicate path in {context}: {current}")
            fail(f"Case-insensitive collision in {context}: {previous} <> {current}")
        seen[folded] = current


def component_files(component: Path, exclusions: Sequence[str]) -> list[tuple[str, Path]]:
    ensure_inside_root(component)
    excluded = {safe_relative(item) for item in exclusions}
    files: list[tuple[str, Path]] = []
    all_paths: list[str] = []

    for current, directories, filenames in os.walk(component, topdown=True, followlinks=False):
        current_path = Path(current)
        for directory in list(directories):
            candidate = current_path / directory
            relative = candidate.relative_to(component).as_posix()
            safe_relative(relative)
            if candidate.is_symlink():
                fail(f"Symlink directory is forbidden: {component.name}/{relative}")
            all_paths.append(relative + "/")
        for filename in filenames:
            candidate = current_path / filename
            relative = candidate.relative_to(component).as_posix()
            safe_relative(relative)
            if candidate.is_symlink():
                fail(f"Symlink file is forbidden: {component.name}/{relative}")
            mode = candidate.stat(follow_symlinks=False).st_mode
            if not stat.S_ISREG(mode):
                fail(f"Non-regular file is forbidden: {component.name}/{relative}")
            all_paths.append(relative)
            if relative not in excluded:
                files.append((relative, candidate))

    validate_casefolded_paths((path.rstrip("/") for path in all_paths), component.name)
    files.sort(key=lambda item: (item[0].casefold(), item[0]))
    return files


@dataclass(frozen=True)
class FileRecord:
    path: str
    bytes: int
    sha256: str

    def as_json(self) -> dict[str, Any]:
        return {"path": self.path, "bytes": self.bytes, "sha256": self.sha256}


def records_for(component: Path, exclusions: Sequence[str]) -> list[FileRecord]:
    records: list[FileRecord] = []
    for relative, source in component_files(component, exclusions):
        records.append(FileRecord(relative, source.stat().st_size, sha256_file(source)))
    return records


def tree_digest(records: Sequence[FileRecord]) -> str:
    digest = hashlib.sha256()
    for record in records:
        digest.update(record.path.encode("utf-8"))
        digest.update(b"\0")
        digest.update(str(record.bytes).encode("ascii"))
        digest.update(b"\0")
        digest.update(record.sha256.encode("ascii"))
        digest.update(b"\n")
    return digest.hexdigest()


@dataclass(frozen=True)
class ComponentSpec:
    component: str
    name: str
    folder: str
    zip_name: str
    integrity_name: str
    manifest_name: str
    exclusions: tuple[str, ...]


COMPONENTS = (
    ComponentSpec(
        component="control",
        name="PR STUDIO Unified Control Plane",
        folder=CONTROL_FOLDER,
        zip_name=CONTROL_ZIP,
        integrity_name="FILE-INTEGRITY.json",
        manifest_name="MANIFEST.json",
        exclusions=CONTROL_EXCLUSIONS,
    ),
    ComponentSpec(
        component="browser_agent",
        name="PR STUDIO Unified Browser Agent",
        folder=BROWSER_FOLDER,
        zip_name=BROWSER_ZIP,
        integrity_name="FILE-INTEGRITY.json",
        manifest_name="COMPONENT-MANIFEST.json",
        exclusions=BROWSER_EXCLUSIONS,
    ),
)


def expected_component_documents(spec: ComponentSpec, epoch: int) -> tuple[bytes, bytes, str]:
    component = ROOT / spec.folder
    records = records_for(component, spec.exclusions)
    digest = tree_digest(records)
    record_json = [record.as_json() for record in records]
    generated = epoch_iso(epoch)

    integrity = {
        "schema_version": "1.0.0",
        "algorithm": "SHA-256",
        "component": spec.component,
        "version": VERSION,
        "generated_at_utc": generated,
        "source_date_epoch": epoch,
        "exclusions": list(spec.exclusions),
        "file_count": len(records),
        "tree_digest": digest,
        "tree_digest_algorithm": TREE_DIGEST_DESCRIPTION,
        "files": record_json,
    }
    manifest = {
        "schema_version": "1.0.0",
        "component": spec.component,
        "name": spec.name,
        "version": VERSION,
        "generated_at_utc": generated,
        "source_date_epoch": epoch,
        "top_level_folder": spec.folder,
        "file_count": len(records),
        "integrity_algorithm": "SHA-256",
        "integrity_exclusions": list(spec.exclusions),
        "tree_digest": digest,
        "tree_digest_algorithm": TREE_DIGEST_DESCRIPTION,
        "files": record_json,
    }
    return canonical_json_bytes(integrity), canonical_json_bytes(manifest), digest


def write_component_documents(spec: ComponentSpec, epoch: int) -> str:
    integrity, manifest, digest = expected_component_documents(spec, epoch)
    component = ROOT / spec.folder
    atomic_write(component / spec.integrity_name, integrity)
    atomic_write(component / spec.manifest_name, manifest)
    return digest


def verify_component_documents(spec: ComponentSpec, epoch: int) -> str:
    expected_integrity, expected_manifest, digest = expected_component_documents(spec, epoch)
    component = ROOT / spec.folder
    expected = (
        (component / spec.integrity_name, expected_integrity),
        (component / spec.manifest_name, expected_manifest),
    )
    for path, payload in expected:
        if not path.is_file():
            fail(f"Missing generated component document: {path.relative_to(ROOT).as_posix()}")
        if path.read_bytes() != payload:
            fail(f"Stale component document: {path.relative_to(ROOT).as_posix()}")
    return digest


def archive_source_files(component: Path) -> list[tuple[str, Path]]:
    # Generated integrity documents are part of the installable ZIP and parity check.
    return component_files(component, ())


def build_zip(spec: ComponentSpec, epoch: int) -> None:
    component = ROOT / spec.folder
    destination = ensure_inside_root(ROOT / spec.zip_name)
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{destination.name}.", suffix=".tmp", dir=str(ROOT)
    )
    os.close(descriptor)
    temporary = Path(temporary_name)
    try:
        with zipfile.ZipFile(
            temporary,
            mode="w",
            compression=zipfile.ZIP_DEFLATED,
            compresslevel=9,
            allowZip64=False,
            strict_timestamps=True,
        ) as archive:
            archive.comment = f"PR STUDIO {VERSION} deterministic {spec.component}".encode("ascii")
            for relative, source in archive_source_files(component):
                archive_name = safe_relative(f"{spec.folder}/{relative}")
                info = zipfile.ZipInfo(archive_name, date_time=zip_datetime(epoch))
                info.compress_type = zipfile.ZIP_DEFLATED
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                info.flag_bits |= 0x800
                info.extra = b""
                info.comment = b""
                archive.writestr(
                    info,
                    source.read_bytes(),
                    compress_type=zipfile.ZIP_DEFLATED,
                    compresslevel=9,
                )
        verify_zip(spec, epoch, temporary)
        os.replace(temporary, destination)
    finally:
        temporary.unlink(missing_ok=True)


def zip_entry_is_symlink(info: zipfile.ZipInfo) -> bool:
    if info.create_system != 3:
        return False
    return stat.S_IFMT(info.external_attr >> 16) == stat.S_IFLNK


def verify_zip(spec: ComponentSpec, epoch: int, archive_path: Path | None = None) -> None:
    path = archive_path or (ROOT / spec.zip_name)
    if not path.is_file() or path.is_symlink():
        fail(f"Missing real component ZIP: {path.relative_to(ROOT).as_posix()}")

    component = ROOT / spec.folder
    ordered_source = archive_source_files(component)
    source = {relative: file_path for relative, file_path in ordered_source}
    expected_order = [f"{spec.folder}/{relative}" for relative, _ in ordered_source]
    expected_names = set(expected_order)
    expected_comment = f"PR STUDIO {VERSION} deterministic {spec.component}".encode("ascii")
    expected_attributes = (stat.S_IFREG | 0o644) << 16

    with zipfile.ZipFile(path, "r") as archive:
        infos = archive.infolist()
        if archive.comment != expected_comment:
            fail(f"Non-deterministic ZIP comment in {path.name}")
        if any(info.is_dir() for info in infos):
            fail(f"Unexpected directory entry in deterministic ZIP: {path.name}")
        file_infos = [info for info in infos if not info.is_dir()]
        names = [info.filename for info in file_infos]
        if names != expected_order:
            fail(f"Non-deterministic entry order in {path.name}")
        validate_casefolded_paths(names, path.name)
        for info in infos:
            safe_relative(info.filename.rstrip("/"))
            if "\\" in info.filename:
                fail(f"Backslash path in {path.name}: {info.filename}")
            if zip_entry_is_symlink(info):
                fail(f"Symlink entry in {path.name}: {info.filename}")
            if info.flag_bits & 0x1:
                fail(f"Encrypted entry in {path.name}: {info.filename}")
            if info.date_time != zip_datetime(epoch):
                fail(f"Non-deterministic timestamp in {path.name}: {info.filename}")
            if info.compress_type != zipfile.ZIP_DEFLATED:
                fail(f"Unexpected compression method in {path.name}: {info.filename}")
            if info.create_system != 3 or info.external_attr != expected_attributes:
                fail(f"Non-deterministic permissions in {path.name}: {info.filename}")
            if info.extra or info.comment:
                fail(f"Unexpected per-entry metadata in {path.name}: {info.filename}")

        actual_names = set(names)
        if actual_names != expected_names:
            missing = sorted(expected_names - actual_names)
            extra = sorted(actual_names - expected_names)
            fail(f"ZIP/source file-set mismatch for {path.name}; missing={missing}; extra={extra}")

        roots = {PurePosixPath(name).parts[0] for name in names}
        if roots != {spec.folder}:
            fail(f"ZIP must have one top-level folder {spec.folder!r}; found {sorted(roots)}")

        if spec.folder == BROWSER_FOLDER:
            chrome_name = f"{BROWSER_FOLDER}/manifest.json"
            colliding_name = f"{BROWSER_FOLDER}/MANIFEST.json"
            if chrome_name not in actual_names or colliding_name in actual_names:
                fail("Browser ZIP must contain manifest.json and must not contain MANIFEST.json")

        for archive_name in sorted(actual_names, key=lambda item: (item.casefold(), item)):
            relative = archive_name[len(spec.folder) + 1 :]
            try:
                payload = archive.read(archive_name)  # reading verifies CRC
            except (KeyError, RuntimeError, zipfile.BadZipFile) as exc:
                raise ReleaseError(f"Cannot read/verify {archive_name} in {path.name}: {exc}") from exc
            source_payload = source[relative].read_bytes()
            if payload != source_payload:
                fail(f"ZIP byte parity failed: {path.name}:{archive_name}")

    # Re-open to force central-directory validation independently from the build handle.
    try:
        with zipfile.ZipFile(path, "r") as archive:
            bad = archive.testzip()
    except zipfile.BadZipFile as exc:
        raise ReleaseError(f"Invalid ZIP {path.name}: {exc}") from exc
    if bad is not None:
        fail(f"CRC failure in {path.name}: {bad}")


def validate_final_reports() -> None:
    missing = [name for name in (*FINAL_REPORTS, *FINAL_DOCUMENTS) if not (ROOT / name).is_file()]
    if missing:
        fail(f"Finalization requires every real report/document; missing: {missing}")

    for name in FINAL_REPORTS:
        current = ROOT / name
        if current.is_symlink() or not current.is_file():
            fail(f"Final report must be a regular file: {name}")
        legacy_name = name.replace(f"-{VERSION}", "-5.0.0")
        legacy = ROOT / legacy_name
        if legacy.is_file() and sha256_file(current) == sha256_file(legacy):
            fail(f"Final report is a byte-for-byte renamed legacy report: {name}")
        if f"{VERSION}" not in current.read_text(encoding="utf-8"):
            fail(f"Final report lacks the active 1.0.0 anchor: {name}")

    for name in JSON_REPORTS_REQUIRING_GENERATION_TIME:
        report = load_json(ROOT / name)
        if report.get("version") != VERSION:
            fail(f"Final JSON report version is not 1.0.0: {name}")
        generated = report.get("generated_at_utc")
        if not isinstance(generated, str) or not generated.strip():
            fail(f"Final JSON report lacks generated_at_utc: {name}")

    descriptor = load_json(ROOT / f"RP-STUDIO-CHATGPT-PLUGIN-{VERSION}.json")
    if descriptor.get("version") != VERSION:
        fail("ChatGPT deployment descriptor version is not 1.0.0")

    quality = load_json(ROOT / f"QUALITY-GATE-{VERSION}.json")
    if not isinstance(quality.get("status"), str) or not quality["status"].strip():
        fail("QUALITY-GATE must carry its real status")
    if not isinstance(quality.get("production_proven"), bool):
        fail("QUALITY-GATE production_proven must be a real boolean")

    test_report = load_json(ROOT / f"TEST-REPORT-{VERSION}.json")
    if not isinstance(test_report.get("status"), str) or not test_report["status"].strip():
        fail("TEST-REPORT must carry its real status")


def root_artifact_records(names: Sequence[str]) -> list[dict[str, Any]]:
    records: list[dict[str, Any]] = []
    validate_casefolded_paths(names, "root release artifacts")
    for name in names:
        safe_relative(name)
        path = ROOT / name
        ensure_inside_root(path)
        if not path.is_file() or path.is_symlink():
            fail(f"Missing regular root artifact: {name}")
        records.append({"name": name, "bytes": path.stat().st_size, "sha256": sha256_file(path)})
    return records


def read_component_manifest(spec: ComponentSpec) -> dict[str, Any]:
    manifest = load_json(ROOT / spec.folder / spec.manifest_name)
    if manifest.get("version") != VERSION:
        fail(f"{spec.manifest_name} version is not 1.0.0 for {spec.folder}")
    if manifest.get("top_level_folder") != spec.folder:
        fail(f"{spec.manifest_name} top-level folder mismatch for {spec.folder}")
    if manifest.get("integrity_exclusions") != list(spec.exclusions):
        fail(f"{spec.manifest_name} self-exclusions are incorrect for {spec.folder}")
    digest = manifest.get("tree_digest")
    if not isinstance(digest, str) or not re.fullmatch(r"[0-9a-f]{64}", digest):
        fail(f"{spec.manifest_name} lacks a valid tree digest")
    return manifest


def release_manifest_document(epoch: int) -> dict[str, Any]:
    validate_final_reports()
    artifacts = root_artifact_records(FINAL_ROOT_ARTIFACTS)
    quality = load_json(ROOT / f"QUALITY-GATE-{VERSION}.json")
    components = []
    for spec in COMPONENTS:
        component_manifest = read_component_manifest(spec)
        zip_path = ROOT / spec.zip_name
        components.append(
            {
                "component": spec.component,
                "source_directory": spec.folder,
                "component_manifest": f"{spec.folder}/{spec.manifest_name}",
                "source_tree_digest": component_manifest["tree_digest"],
                "zip": spec.zip_name,
                "zip_bytes": zip_path.stat().st_size,
                "zip_sha256": sha256_file(zip_path),
                "source_zip_parity": True,
            }
        )

    return {
        "schema_version": "1.0.0",
        "version": VERSION,
        "generated_at_utc": epoch_iso(epoch),
        "source_date_epoch": epoch,
        "status": quality["status"],
        "production_proven": quality["production_proven"],
        "top_level_folder": SUITE_FOLDER,
        "self_exclusions": [RELEASE_MANIFEST, CHECKSUM_FILE],
        "component_zips": [CONTROL_ZIP, BROWSER_ZIP],
        "components": components,
        "artifacts": artifacts,
        "notes": [
            "This manifest contains only exact 1.0.0 artifacts and does not hash itself.",
            "Component tree digests exclude their own integrity/manifest documents as declared.",
            "COMPONENT-SHA256SUMS-1.0.0.txt is generated after this manifest and excludes itself.",
        ],
    }


def checksum_bytes(names: Sequence[str]) -> bytes:
    validate_casefolded_paths(names, CHECKSUM_FILE)
    lines = []
    for name in sorted(names, key=lambda item: (item.casefold(), item)):
        path = ROOT / name
        if not path.is_file() or path.is_symlink():
            fail(f"Cannot checksum missing/non-regular artifact: {name}")
        lines.append(f"{sha256_file(path)}  {name}")
    return ("\n".join(lines) + "\n").encode("utf-8")


def finalize(epoch: int) -> None:
    for spec in COMPONENTS:
        verify_component_documents(spec, epoch)
        verify_zip(spec, epoch)

    manifest = release_manifest_document(epoch)
    if any(item["name"] in (RELEASE_MANIFEST, CHECKSUM_FILE) for item in manifest["artifacts"]):
        fail("Release manifest attempted to include itself or the checksum file")
    atomic_write(ROOT / RELEASE_MANIFEST, canonical_json_bytes(manifest))

    checksum_names = (*FINAL_ROOT_ARTIFACTS, RELEASE_MANIFEST)
    if CHECKSUM_FILE in checksum_names:
        fail("Checksum file attempted to include itself")
    atomic_write(ROOT / CHECKSUM_FILE, checksum_bytes(checksum_names))
    verify_final_metadata(epoch)


def verify_final_metadata(epoch: int) -> None:
    manifest_path = ROOT / RELEASE_MANIFEST
    sums_path = ROOT / CHECKSUM_FILE
    if not manifest_path.is_file() or not sums_path.is_file():
        fail("Final release manifest/checksum pair is incomplete")

    manifest = load_json(manifest_path)
    if manifest.get("version") != VERSION or manifest.get("top_level_folder") != SUITE_FOLDER:
        fail("Release manifest version/top-level folder mismatch")
    if manifest.get("source_date_epoch") != epoch:
        fail("Release manifest SOURCE_DATE_EPOCH mismatch")
    if manifest.get("self_exclusions") != [RELEASE_MANIFEST, CHECKSUM_FILE]:
        fail("Release manifest self-exclusions are incorrect")
    if manifest.get("component_zips") != [CONTROL_ZIP, BROWSER_ZIP]:
        fail("Release manifest component ZIP list is incorrect")

    expected_records = root_artifact_records(FINAL_ROOT_ARTIFACTS)
    if manifest.get("artifacts") != expected_records:
        fail("Release manifest artifact bytes/hashes are stale")
    if any(item.get("name") in (RELEASE_MANIFEST, CHECKSUM_FILE) for item in manifest["artifacts"]):
        fail("Release manifest includes a self-referential artifact")

    raw_lines = sums_path.read_text(encoding="utf-8").splitlines()
    expected_names = set((*FINAL_ROOT_ARTIFACTS, RELEASE_MANIFEST))
    found: dict[str, str] = {}
    for line_number, line in enumerate(raw_lines, start=1):
        match = re.fullmatch(r"([0-9a-f]{64})  (.+)", line)
        if not match:
            fail(f"Malformed checksum line {line_number}")
        digest, name = match.groups()
        safe_relative(name)
        if name == CHECKSUM_FILE:
            fail("Checksum file includes itself")
        if name in found:
            fail(f"Duplicate checksum entry: {name}")
        found[name] = digest
    if set(found) != expected_names:
        fail(
            f"Checksum artifact set mismatch; missing={sorted(expected_names - set(found))}; "
            f"extra={sorted(set(found) - expected_names)}"
        )
    for name, expected in found.items():
        if sha256_file(ROOT / name) != expected:
            fail(f"Checksum mismatch: {name}")


def check(epoch: int) -> None:
    for spec in COMPONENTS:
        verify_component_documents(spec, epoch)
        verify_zip(spec, epoch)

    final_markers = [ROOT / name for name in (*FINAL_REPORTS, RELEASE_MANIFEST, CHECKSUM_FILE)]
    if not any(path.exists() for path in final_markers):
        print("Component check complete; no 1.0.0 final reports/manifest detected.")
        return

    missing_reports = [name for name in FINAL_REPORTS if not (ROOT / name).is_file()]
    if missing_reports:
        fail(f"Partial final report set detected; missing: {missing_reports}")
    validate_final_reports()
    verify_final_metadata(epoch)


def build(epoch: int) -> None:
    for spec in COMPONENTS:
        write_component_documents(spec, epoch)
        # Recompute after writes to prove the self-exclusions are non-recursive.
        verify_component_documents(spec, epoch)
        build_zip(spec, epoch)
        verify_zip(spec, epoch)


def parse_args(argv: Sequence[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Build/check deterministic PR STUDIO 1.0.0 component packages or finalize root metadata.",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog=(
            "Examples:\n"
            "  python tests/build-release.py --check\n"
            "  python tests/build-release.py --build\n"
            "  python tests/build-release.py --finalize\n\n"
            "Set SOURCE_DATE_EPOCH to the same integer for every phase when overriding the fixed release epoch.\n"
            "No mode generates or modifies test/quality/security/performance/live-acceptance reports."
        ),
    )
    modes = parser.add_mutually_exclusive_group(required=True)
    modes.add_argument("--check", action="store_true", help="non-mutating component/final metadata verification")
    modes.add_argument("--build", action="store_true", help="write component integrity documents and deterministic ZIPs")
    modes.add_argument("--finalize", action="store_true", help="write root release manifest and checksums after all reports exist")
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = parse_args(sys.argv[1:] if argv is None else argv)
    try:
        validate_suite_layout()
        validate_version_anchors()
        epoch = source_date_epoch()
        if args.check:
            check(epoch)
            print("PASS: non-mutating release check completed")
        elif args.build:
            build(epoch)
            print("PASS: component manifests and deterministic ZIPs built and verified")
        else:
            finalize(epoch)
            print("PASS: root release manifest and checksum file finalized and verified")
        return 0
    except ReleaseError as exc:
        print(f"FAIL: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
