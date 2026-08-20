#!/usr/bin/env python3
"""Fail when WordPress Plugin Check ERROR counts regress beyond the committed baseline."""
from __future__ import annotations

import argparse
import json
import re
import sys
from collections import Counter
from pathlib import Path

FILE_HEADER = re.compile(r"(?m)^FILE: (.+)\n")


def parse_results(path: Path) -> Counter[tuple[str, str]]:
    if not path.is_file():
        raise ValueError(f"results file does not exist: {path}")
    text = path.read_text(encoding="utf-8")
    if not text.strip():
        raise ValueError(f"results file is empty: {path}")

    parts = FILE_HEADER.split(text)
    if len(parts) < 3:
        raise ValueError("results file has no Plugin Check FILE sections")

    counts: Counter[tuple[str, str]] = Counter()
    for index in range(1, len(parts), 2):
        file_name = parts[index].strip()
        payload = parts[index + 1].strip()
        try:
            findings = json.loads(payload)
        except json.JSONDecodeError as exc:
            raise ValueError(f"invalid JSON for {file_name}: {exc}") from exc
        if not isinstance(findings, list):
            raise ValueError(f"expected a JSON list for {file_name}")
        for finding in findings:
            if not isinstance(finding, dict):
                raise ValueError(f"invalid finding for {file_name}: expected object")
            if finding.get("type") != "ERROR":
                continue
            code = finding.get("code")
            if not isinstance(code, str) or not code:
                raise ValueError(f"ERROR finding without a code in {file_name}")
            counts[(file_name, code)] += 1
    return counts


def load_baseline(path: Path) -> tuple[int, Counter[tuple[str, str]]]:
    raw = json.loads(path.read_text(encoding="utf-8"))
    if raw.get("version") != 1:
        raise ValueError("unsupported Plugin Check baseline version")
    expected_total = raw.get("total_errors")
    entries = raw.get("errors")
    if not isinstance(expected_total, int) or expected_total < 0 or not isinstance(entries, dict):
        raise ValueError("malformed Plugin Check baseline")

    counts: Counter[tuple[str, str]] = Counter()
    for file_name, codes in entries.items():
        if not isinstance(file_name, str) or not isinstance(codes, dict):
            raise ValueError("malformed Plugin Check baseline entry")
        for code, count in codes.items():
            if not isinstance(code, str) or not isinstance(count, int) or count < 1:
                raise ValueError(f"malformed baseline count for {file_name}: {code}")
            counts[(file_name, code)] = count

    actual_total = sum(counts.values())
    if actual_total != expected_total:
        raise ValueError(
            f"baseline total mismatch: declared {expected_total}, entries sum to {actual_total}"
        )
    return expected_total, counts


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("results", type=Path)
    parser.add_argument(
        "--baseline",
        type=Path,
        default=Path("tests/plugin-check-error-baseline.json"),
    )
    args = parser.parse_args()

    try:
        baseline_total, baseline = load_baseline(args.baseline)
        current = parse_results(args.results)
    except (OSError, ValueError, json.JSONDecodeError) as exc:
        print(f"Plugin Check ratchet could not evaluate results: {exc}", file=sys.stderr)
        return 2

    regressions = []
    for key, current_count in sorted(current.items()):
        baseline_count = baseline.get(key, 0)
        if current_count > baseline_count:
            regressions.append((key[0], key[1], baseline_count, current_count))

    current_total = sum(current.values())
    reductions = sum(
        max(0, baseline_count - current.get(key, 0))
        for key, baseline_count in baseline.items()
    )

    print(
        "Plugin Check ERROR ratchet: "
        f"baseline={baseline_total}, current={current_total}, reduced={reductions}, "
        f"regressions={len(regressions)}"
    )

    if regressions:
        for file_name, code, old, new in regressions:
            print(
                f"REGRESSION {file_name}: {code}: baseline={old}, current={new}",
                file=sys.stderr,
            )
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
