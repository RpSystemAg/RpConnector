#!/usr/bin/env python3
"""Every test in tests/ must be executed by CI, or be declared as something else.

Why this exists
---------------
A test file that no workflow runs is not a test. It is a file that looks like
one. It passes review, it counts in "we have 103 suites", it goes stale as the
code moves under it, and the day someone finally runs it the failures are
archaeology rather than signal.

When this gate was written, tests/ held 103 PHP and Python suites and the
workflows referenced 39 of them. Sixty-four were never executed by anything --
including suites covering concurrency, execution lanes and the browser
orchestrator, which is precisely where the defects of 2026-08-18 and -19 were
found by hand.

How it behaves
--------------
A ratchet, for the same reason the MegaLinter posture is one: blocking on a
backlog nobody in the current change created just teaches people to bypass the
job. So:

  * A file that is referenced by a workflow is executed. Fine.
  * A file listed in HELPERS is not a test -- a fixture, a generator, a
    reporting script. Each entry carries the reason. Fine.
  * Anything else is unexecuted, and is compared against the committed baseline
    in test-inventory-baseline.json.

Adding a new unexecuted test fails the gate immediately. Wiring one up shrinks
the baseline. The baseline can only go down, and `--update-baseline` refuses to
grow it, so the ratchet cannot be quietly released by rerunning the tool.

Exit code 1 when the set of unexecuted suites gains a member.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
TESTS = ROOT / "tests"
WORKFLOWS = ROOT / ".github" / "workflows"
BASELINE = TESTS / "test-inventory-baseline.json"

SUITE_SUFFIXES = (".php", ".py", ".mjs")

# Files under tests/ that are deliberately not suites. Each needs a reason: the
# point of the list is that a reader can tell "this is a fixture" from "someone
# wanted the gate to be quiet".
HELPERS: dict[str, str] = {
    "build-release.py": "release builder, invoked by the build workflow as a tool",
    "build-suite-archive.py": "packaging tool, not an assertion",
    "dump-sql-strings.php": "extracts SQL literals for validate-sql-syntax.py",
    "dump-wpaib-tools.php": "dumps the tool surface for other checks to read",
    "phpstan-bootstrap.php": "constant declarations for static analysis, never executed",
    "regenerate-contract-artifacts.py": "generator; the drift check is what asserts",
    "assemble-release-evidence.py": "collects evidence produced by other jobs",
    "generate-one-guard-audit.py": "renders a report from one_guard_constitution.py",
    "generate-web-research-ledger.py": "renders a document, asserts nothing",
    "m11-portable-concurrency-worker.php": "child process spawned by its parent suite",
    "validate-test-inventory.py": "this gate",
}


def suites() -> list[str]:
    out = []
    for path in sorted(TESTS.rglob("*")):
        if not path.is_file() or path.suffix not in SUITE_SUFFIXES:
            continue
        if any(part in {"fixtures", "__pycache__", "node_modules"} for part in path.parts):
            continue
        out.append(path.relative_to(TESTS).as_posix())
    return out


def referenced() -> set[str]:
    """Names mentioned anywhere in the workflow files.

    Deliberately generous: a glob like `tests/*.test.mjs` counts, and so does a
    bare basename inside a shell loop. The question this gate asks is "does
    anything run this", not "is it run in the tidiest way".
    """
    seen: set[str] = set()
    globs: list[re.Pattern[str]] = []
    for wf in WORKFLOWS.glob("*.y*ml"):
        text = wf.read_text(encoding="utf-8", errors="replace")
        for m in re.finditer(r"[\w./-]*tests/([\w./-]*)", text):
            frag = m.group(1)
            if "*" in frag:
                globs.append(re.compile("^" + re.escape(frag).replace(r"\*", "[^/]*") + "$"))
            elif frag:
                seen.add(frag)
        for m in re.finditer(r"\b([\w-]+\.(?:php|py|mjs))\b", text):
            seen.add(m.group(1))

    resolved = set()
    for name in suites():
        base = name.rsplit("/", 1)[-1]
        if name in seen or base in seen or any(g.match(name) or g.match(base) for g in globs):
            resolved.add(name)
    return resolved


def unexecuted() -> list[str]:
    ran = referenced()
    return sorted(
        name
        for name in suites()
        if name not in ran and name.rsplit("/", 1)[-1] not in HELPERS
    )


def load_baseline() -> list[str]:
    if not BASELINE.exists():
        return []
    return sorted(json.loads(BASELINE.read_text(encoding="utf-8"))["unexecuted"])


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "--update-baseline",
        action="store_true",
        help="rewrite the baseline. Refuses to add entries; use it after wiring suites up.",
    )
    args = ap.parse_args()

    current = unexecuted()
    baseline = load_baseline()

    if args.update_baseline:
        added = sorted(set(current) - set(baseline))
        if added and BASELINE.exists():
            print("refusing to grow the baseline. Wire these up instead:", file=sys.stderr)
            for name in added:
                print(f"  {name}", file=sys.stderr)
            return 1
        BASELINE.write_text(
            json.dumps(
                {
                    "_comment": (
                        "Suites in tests/ that no workflow executes. This list may only "
                        "shrink. See validate-test-inventory.py for why it exists."
                    ),
                    "unexecuted": current,
                },
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
        print(f"baseline written: {len(current)} unexecuted suite(s)")
        return 0

    total = len(suites())
    print(f"test inventory: {total} suite(s), {total - len(current)} executed by CI, {len(current)} not")

    added = sorted(set(current) - set(baseline))
    removed = sorted(set(baseline) - set(current))

    for name in removed:
        print(f"  now executed: {name}")

    if added:
        print(f"\n{len(added)} suite(s) added that nothing runs:", file=sys.stderr)
        for name in added:
            print(f"  {name}", file=sys.stderr)
        print(
            "\nA test no workflow executes is not a test. Add it to a workflow, or\n"
            "declare it in HELPERS with the reason it is not a suite.",
            file=sys.stderr,
        )
        return 1

    if removed:
        print(f"\n{len(removed)} suite(s) newly wired up. Run with --update-baseline to record that.")

    print("no unexecuted suite was added")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
