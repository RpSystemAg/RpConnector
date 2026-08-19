#!/usr/bin/env python3
"""The plugin must keep accepting the protocol the shipped extension speaks.

Why this exists
---------------
The two halves of this product are installed separately and updated by hand:
the plugin as a ZIP upload, the extension loaded unpacked. That is deliberate
and is not going to change. It also means the two versions on any given machine
drift apart as a matter of course -- someone updates the plugin on Tuesday and
gets round to the extension on Friday, or never.

The negotiation for this already exists and is sound. lib/executor-meta.js
declares EXECUTOR_PROTOCOL_VERSION, the plugin declares EXECUTOR_PROTOCOL plus
an ACCEPTED_EXECUTOR_PROTOCOLS list, and the service worker raises
`executor_protocol_mismatch` when there is no overlap. Nothing was missing at
runtime.

What was missing is anything that keeps the two declarations honest. They live
in different languages, in different folders, shipped in different artefacts,
and no test compared them. Drop "3.0.0" from the accepted list while bumping
the preferred version and every extension already installed stops pairing --
correctly, with a clear error, which is exactly what makes it hard to notice in
CI. Every test still passes. The break only appears on someone's machine.

What is asserted
----------------
1. The version the extension declares is in the plugin's accepted list.
   Violating this breaks every already-installed extension on upgrade.

2. The plugin's preferred version is itself in its own accepted list.
   A preferred value that is not accepted would reject the current extension.

3. The accepted list never shrinks below what is recorded here. Widening is
   free; narrowing is the thing that strands users, so it has to be a decision
   someone writes down rather than a side effect of an edit.

None of these prevent moving the protocol forward. They require that dropping
support for a version is deliberate.
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
EXT_META = ROOT / "prstudio-unified-browser-agent" / "lib" / "executor-meta.js"
PLUGIN_PROTO = ROOT / "prstudio-unified-control" / "includes" / "class-prstudio-uc-browser-protocol.php"
BASELINE = Path(__file__).resolve().parent / "protocol-compatibility-baseline.json"


def fail(message: str) -> None:
    print(f"FAIL {message}", file=sys.stderr)


def extension_version() -> str | None:
    if not EXT_META.exists():
        return None
    m = re.search(r"EXECUTOR_PROTOCOL_VERSION\s*=\s*[\"']([^\"']+)[\"']", EXT_META.read_text(encoding="utf-8"))
    return m.group(1) if m else None


def plugin_protocols() -> tuple[str | None, list[str]]:
    if not PLUGIN_PROTO.exists():
        return None, []
    text = PLUGIN_PROTO.read_text(encoding="utf-8")
    pref = re.search(r"const\s+EXECUTOR_PROTOCOL\s*=\s*'([^']+)'", text)
    accepted_block = re.search(r"const\s+ACCEPTED_EXECUTOR_PROTOCOLS\s*=\s*array\(([^)]*)\)", text)
    accepted = re.findall(r"'([^']+)'", accepted_block.group(1)) if accepted_block else []
    return (pref.group(1) if pref else None), accepted


def main() -> int:
    problems = 0

    ext = extension_version()
    preferred, accepted = plugin_protocols()

    if ext is None:
        fail(f"cannot read EXECUTOR_PROTOCOL_VERSION from {EXT_META.relative_to(ROOT).as_posix()}")
        return 1
    if preferred is None or not accepted:
        fail(f"cannot read the protocol constants from {PLUGIN_PROTO.relative_to(ROOT).as_posix()}")
        return 1

    print(f"extension speaks: {ext}")
    print(f"plugin prefers:   {preferred}")
    print(f"plugin accepts:   {', '.join(accepted)}")

    if ext not in accepted:
        fail(
            f"the shipped extension speaks {ext}, which the plugin does not accept "
            f"({', '.join(accepted)}).\n"
            "     Every already-installed extension stops pairing the moment this plugin\n"
            "     is uploaded. The two halves are updated separately and by hand, so a\n"
            "     user running the previous extension is the normal case, not the edge one."
        )
        problems += 1

    if preferred not in accepted:
        fail(
            f"the plugin prefers {preferred} but does not list it as accepted "
            f"({', '.join(accepted)}). It would reject its own preferred protocol."
        )
        problems += 1

    if BASELINE.exists():
        previous = json.loads(BASELINE.read_text(encoding="utf-8"))
        dropped = sorted(set(previous.get("accepted", [])) - set(accepted))
        if dropped:
            fail(
                f"support was dropped for {', '.join(dropped)} without recording the decision.\n"
                "     Widening the accepted list needs no ceremony. Narrowing it strands\n"
                "     every user still on that protocol, so it has to be written down:\n"
                "     update tests/protocol-compatibility-baseline.json in the same commit,\n"
                "     with the reason in the message."
            )
            problems += 1
    else:
        BASELINE.write_text(
            json.dumps(
                {
                    "_comment": (
                        "Protocol versions the plugin accepts. Removing an entry strands "
                        "every installed extension speaking it. See "
                        "validate-protocol-compatibility.py."
                    ),
                    "accepted": accepted,
                    "preferred": preferred,
                    "extension": ext,
                },
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
        print(f"baseline recorded: {', '.join(accepted)}")

    if problems:
        return 1

    print("plugin and extension agree on a protocol")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
