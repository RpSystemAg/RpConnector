#!/usr/bin/env python3
"""Self-test the non-network security boundary of collect-production-evidence.py."""
from __future__ import annotations

import importlib.util
import io
import tempfile
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
COLLECTOR = ROOT / "tests" / "collect-production-evidence.py"


def load_collector():
    spec = importlib.util.spec_from_file_location("rp_production_collector", COLLECTOR)
    if spec is None or spec.loader is None:
        raise RuntimeError("cannot load collector")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def archive(entries: dict[str, bytes]) -> bytes:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for name, data in entries.items():
            zf.writestr(name, data)
    return buf.getvalue()


def expect_error(label: str, callback) -> None:
    try:
        callback()
    except Exception:
        print("PASS reject", label)
        return
    raise AssertionError(f"collector accepted {label}")


def main() -> int:
    collector = load_collector()

    with tempfile.TemporaryDirectory(prefix="rp-collector-selftest-") as raw:
        root = Path(raw)
        extracted = collector.safe_extract(
            archive(
                {
                    "nested/prstudio-unified-control-deadbee.zip": b"plugin",
                    "nested/prstudio-unified-browser-agent-deadbee.zip": b"browser",
                    "nested/supply-chain-release.json": b"{}",
                }
            ),
            root,
        )
        by_name = {path.name: path for path in extracted}
        selected = collector.validate_copy_globs(
            [{"pattern": "prstudio-unified-*.zip", "min_count": 2, "max_count": 2}],
            by_name,
        )
        expected = [
            "prstudio-unified-browser-agent-deadbee.zip",
            "prstudio-unified-control-deadbee.zip",
        ]
        if selected != expected:
            raise AssertionError(f"unexpected dynamic selection: {selected}")
        print("PASS exact dynamic cardinality")

        expect_error(
            "too few dynamic release ZIPs",
            lambda: collector.validate_copy_globs(
                [{"pattern": "prstudio-unified-control-*.zip", "min_count": 2, "max_count": 2}],
                by_name,
            ),
        )
        expect_error(
            "unsafe glob path",
            lambda: collector.validate_copy_globs(
                [{"pattern": "../*.zip", "min_count": 1, "max_count": 1}],
                by_name,
            ),
        )
        expect_error(
            "overlapping dynamic selectors",
            lambda: collector.validate_copy_globs(
                [
                    {"pattern": "prstudio-unified-*.zip", "min_count": 2, "max_count": 2},
                    {"pattern": "*-control-*.zip", "min_count": 1, "max_count": 1},
                ],
                by_name,
            ),
        )

    with tempfile.TemporaryDirectory(prefix="rp-collector-selftest-") as raw:
        root = Path(raw)
        expect_error(
            "ZIP path traversal",
            lambda: collector.safe_extract(archive({"../escape.json": b"bad"}), root),
        )
        expect_error(
            "absolute ZIP path",
            lambda: collector.safe_extract(archive({"/escape.json": b"bad"}), root),
        )
        if (root.parent / "escape.json").exists():
            raise AssertionError("ZIP traversal created an external file")

    print("PRODUCTION EVIDENCE COLLECTOR SELFTEST PASS cases=7")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
