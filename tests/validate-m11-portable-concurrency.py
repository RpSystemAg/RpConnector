#!/usr/bin/env python3
"""Real multi-process concurrency stress test without a pcntl dependency."""
from __future__ import annotations

import argparse
import json
import os
import shutil
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Iterable


ROOT = Path(__file__).resolve().parent.parent
WORKER = ROOT / "tests/m11-portable-concurrency-worker.php"


def run_group(
    php: str,
    store: Path,
    directory: Path,
    specs: Iterable[tuple[str, ...]],
    group: str,
) -> list[dict]:
    barrier = directory / f"barrier-{group}"
    processes: list[subprocess.Popen[str]] = []
    for spec in specs:
        command = [php, str(WORKER), spec[0], str(store), str(barrier), *spec[1:]]
        processes.append(subprocess.Popen(
            command,
            cwd=ROOT,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
            encoding="utf-8",
            errors="strict",
        ))
    # All children start first and block on the same file.  Releasing this barrier
    # creates genuine overlapping OS processes and shared-resource contention.
    time.sleep(0.08)
    barrier.write_text("go\n", encoding="utf-8")
    rows: list[dict] = []
    for process in processes:
        stdout, stderr = process.communicate(timeout=20)
        if process.returncode != 0:
            raise RuntimeError(f"worker exit={process.returncode}: {stderr.strip()}")
        try:
            row = json.loads(stdout)
        except json.JSONDecodeError as error:
            raise RuntimeError(f"worker returned invalid JSON: {stdout!r}; {stderr!r}") from error
        if not isinstance(row, dict):
            raise RuntimeError(f"worker returned non-object JSON: {row!r}")
        rows.append(row)
    return rows


def check(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)
    print(f"PASS {message}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--php", default=os.environ.get("PRSTUDIO_PHP") or shutil.which("php") or "")
    args = parser.parse_args()
    if not args.php:
        print("FAIL PHP executable unavailable; set PRSTUDIO_PHP", flush=True)
        return 2
    php = str(Path(args.php).resolve()) if Path(args.php).exists() else args.php

    with tempfile.TemporaryDirectory(prefix="prstudio-m11-concurrency-") as raw_directory:
        directory = Path(raw_directory)
        store = directory / "options.json"
        store.write_text("{}\n", encoding="utf-8")

        opens = run_group(
            php,
            store,
            directory,
            (("open", f"portable-chat-{index}") for index in range(16)),
            "opens",
        )
        check(all(row.get("ok") for row in opens), "16 separate PHP processes open lanes without errors")
        lane_ids = {str(row.get("lane_id") or "") for row in opens}
        check("" not in lane_ids and len(lane_ids) == 16, "concurrent context opens preserve 16 unique lanes")

        status = run_group(php, store, directory, (("status",),), "status")[0]
        check(status.get("count") == 16, "shared state has no lost lane updates")
        tokens = [str(opens[0].get("lane_handle") or ""), str(opens[1].get("lane_handle") or "")]
        check(all(tokens), "two reusable lane credentials are available for contention")

        race_specs: list[tuple[str, ...]] = []
        for round_index in range(20):
            resource = f"wp:post:portable-race-{round_index}"
            race_specs.extend((("acquire", tokens[0], resource), ("acquire", tokens[1], resource)))
        races = run_group(php, store, directory, race_specs, "leases")
        for round_index in range(20):
            pair = races[round_index * 2:round_index * 2 + 2]
            winners = [row for row in pair if row.get("ok")]
            losers = [row for row in pair if not row.get("ok")]
            check(len(winners) == 1, f"lease race {round_index + 1}/20 grants exactly one owner")
            loser_is_typed = len(losers) == 1 and losers[0].get("error") == "resource_busy_other_context"
            check(
                loser_is_typed,
                f"lease race {round_index + 1}/20 returns typed technical contention for the competing lane"
                + ("" if loser_is_typed else f"; observed={losers}"),
            )

        editorial = run_group(
            php,
            store,
            directory,
            (("editorial_upsert", str(i)) for i in range(24)),
            "editorial",
        )
        check(all(row.get("ok") for row in editorial), "24 concurrent editorial mutations execute without application quota veto")
        editorial_status = run_group(php, store, directory, (("editorial_list",),), "editorial-status")[0]
        ids = {str(row.get("id") or "") for row in (editorial_status.get("campaigns") or [])}
        check(all(f"portable-{i}" in ids for i in range(24)), "technical editorial mutex preserves all 24 concurrent updates")

    print("OK portable multi-process lane and technical editorial concurrency stress")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (AssertionError, RuntimeError, subprocess.TimeoutExpired) as error:
        print(f"FAIL {error}", flush=True)
        raise SystemExit(1)
