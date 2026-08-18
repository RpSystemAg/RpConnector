#!/usr/bin/env python3
"""Build and verify the deterministic PR STUDIO 17.0.0 suite archive."""

from __future__ import annotations

import argparse
import hashlib
import os
import stat
import tempfile
import zipfile
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath


VERSION = "17.0.0"
FOLDER = f"PR-STUDIO-Unified-Suite-{VERSION}"
SCRIPT = Path(__file__).resolve()
ROOT = SCRIPT.parent.parent.resolve()
DEFAULT_OUTPUT = ROOT.parent / f"{FOLDER}.zip"
RELEASE_EPOCH = int(datetime(2026, 8, 11, 9, 0, tzinfo=timezone.utc).timestamp())
TRANSIENT_NAMES = {"__pycache__", ".pytest_cache", ".mypy_cache"}
TRANSIENT_SUFFIXES = {".pyc", ".pyo", ".lock", ".tmp"}


class ArchiveError(RuntimeError):
    pass


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def zip_timestamp() -> tuple[int, int, int, int, int, int]:
    stamp = datetime.fromtimestamp(RELEASE_EPOCH, tz=timezone.utc)
    return (stamp.year, stamp.month, stamp.day, stamp.hour, stamp.minute, stamp.second)


def validate_layout(output: Path) -> None:
    if ROOT.name != FOLDER or SCRIPT != ROOT / "tests" / "build-suite-archive.py":
        raise ArchiveError(f"Builder must remain in {FOLDER}/tests")
    resolved = output.resolve(strict=False)
    try:
        resolved.relative_to(ROOT)
    except ValueError:
        return
    raise ArchiveError("The suite archive must be outside the suite directory")


def collect_files() -> list[tuple[str, Path]]:
    records: list[tuple[str, Path]] = []
    casefolded: dict[str, str] = {}
    for current, directories, filenames in os.walk(ROOT, topdown=True, followlinks=False):
        current_path = Path(current)
        for directory in directories:
            candidate = current_path / directory
            relative = candidate.relative_to(ROOT)
            if candidate.is_symlink():
                raise ArchiveError(f"Symlink directory is forbidden: {relative.as_posix()}")
            if directory in TRANSIENT_NAMES:
                raise ArchiveError(f"Transient directory is forbidden: {relative.as_posix()}")
        for filename in filenames:
            source = current_path / filename
            relative_path = source.relative_to(ROOT)
            relative = relative_path.as_posix()
            pure = PurePosixPath(relative)
            if source.is_symlink() or not stat.S_ISREG(source.stat(follow_symlinks=False).st_mode):
                raise ArchiveError(f"Only regular files are allowed: {relative}")
            if any(part in TRANSIENT_NAMES for part in pure.parts) or source.suffix.lower() in TRANSIENT_SUFFIXES:
                raise ArchiveError(f"Transient file is forbidden: {relative}")
            archive_name = f"{FOLDER}/{relative}"
            folded = archive_name.casefold()
            if folded in casefolded:
                raise ArchiveError(f"Case-insensitive archive collision: {casefolded[folded]} <> {archive_name}")
            casefolded[folded] = archive_name
            records.append((archive_name, source))
    records.sort(key=lambda item: (item[0].casefold(), item[0]))
    if not records:
        raise ArchiveError("Suite has no files")
    return records


def verify_archive(output: Path, records: list[tuple[str, Path]]) -> None:
    expected_names = [name for name, _source in records]
    expected_timestamp = zip_timestamp()
    with zipfile.ZipFile(output, "r") as archive:
        infos = archive.infolist()
        names = [info.filename for info in infos]
        if names != expected_names:
            raise ArchiveError("Archive file set or deterministic order differs from the suite tree")
        if len({name.casefold() for name in names}) != len(names):
            raise ArchiveError("Archive contains duplicate or case-colliding paths")
        for info, (expected_name, source) in zip(infos, records, strict=True):
            if info.filename != expected_name or info.is_dir():
                raise ArchiveError(f"Unexpected archive entry: {info.filename}")
            if info.date_time != expected_timestamp:
                raise ArchiveError(f"Non-deterministic timestamp: {info.filename}")
            if (info.external_attr >> 16) & 0o777 != 0o644:
                raise ArchiveError(f"Non-deterministic permissions: {info.filename}")
            payload = archive.read(info)
            if len(payload) != source.stat().st_size or sha256_bytes(payload) != sha256_file(source):
                raise ArchiveError(f"Archive payload mismatch: {info.filename}")


def build_archive(output: Path, records: list[tuple[str, Path]]) -> None:
    output.parent.mkdir(parents=True, exist_ok=True)
    descriptor, temporary_name = tempfile.mkstemp(prefix=f".{output.name}.", suffix=".tmp", dir=output.parent)
    os.close(descriptor)
    temporary = Path(temporary_name)
    try:
        with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for archive_name, source in records:
                info = zipfile.ZipInfo(archive_name, date_time=zip_timestamp())
                info.compress_type = zipfile.ZIP_DEFLATED
                info.create_system = 3
                info.external_attr = (stat.S_IFREG | 0o644) << 16
                with source.open("rb") as handle:
                    archive.writestr(info, handle.read(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        verify_archive(temporary, records)
        os.replace(temporary, output)
    finally:
        temporary.unlink(missing_ok=True)


def main() -> int:
    parser = argparse.ArgumentParser()
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--build", action="store_true")
    mode.add_argument("--check", action="store_true")
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    args = parser.parse_args()
    output = args.output.expanduser().resolve(strict=False)
    validate_layout(output)
    records = collect_files()
    if args.build:
        build_archive(output, records)
    elif not output.is_file():
        raise ArchiveError(f"Suite archive does not exist: {output}")
    verify_archive(output, records)
    print(
        f"PASS: deterministic suite archive verified; files={len(records)}; "
        f"bytes={output.stat().st_size}; sha256={sha256_file(output)}"
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ArchiveError, OSError, zipfile.BadZipFile) as exc:
        print(f"FAIL: {exc}")
        raise SystemExit(1)
