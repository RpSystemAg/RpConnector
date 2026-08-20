#!/usr/bin/env python3
"""A blocking gate must know which WordPress it failed against.

WHY THIS EXISTS
---------------
Every blocking workflow provisioned WordPress with a bare `wp core download`,
which fetches whatever is current. WordPress 7.1 shipped on 19 August 2026, so
from that morning the gates changed what they were testing without a commit,
a review, or a line in any log.

That is not primarily a compatibility risk -- it is a diagnosis risk. When the
suite is red you have two candidate causes, "we changed the code" and
"WordPress changed underneath us", and an unpinned download removes the only
evidence that could separate them. On 20 August 2026 the full-surface gate ran
100 times and passed zero, one day after a major WordPress release, and nothing
in the run output could say whether those facts were related.

The repository already had the right shape for this and only used half of it:
`wordpress-forward-canary.yml` deliberately tracks `latest`, `beta` and
`nightly` on a schedule, which is exactly where chasing new WordPress belongs.
A canary is only useful next to something that does not move. Pinning the
blocking gates completes that pattern, and it turns "did 7.1 break us?" into a
one-line experiment: change the pin, read the result.

The pin is `${RP_WP_VERSION:-7.1}`, so CI can override it for a bisect without
editing ten files, and 7.1 -- the release people are actually running -- is what
you get when nobody overrides anything.

WHAT IS DELIBERATELY EXEMPT
---------------------------
Two workflows are supposed to move, and both are named here rather than matched
by a pattern, because an exemption that is inferred is an exemption that grows:

  wordpress-forward-canary.yml   -- its whole job is latest/beta/nightly
  wordpress-live-acceptance.yml  -- runs a WordPress version matrix in which
                                    "latest" is one deliberate case

Anything else that downloads WordPress must say which one.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WORKFLOWS = ROOT / ".github" / "workflows"
PLUGIN = ROOT / "prstudio-unified-control" / "prstudio-unified-control.php"

# Named, not pattern-matched. See the note above.
MOVING_ON_PURPOSE = {
    "wordpress-forward-canary.yml",
    "wordpress-live-acceptance.yml",
}

DOWNLOAD = re.compile(r"wp\s+core\s+download\b[^\n]*")
PINNED = re.compile(r"--version=")
DEFAULT_PIN = re.compile(r"RP_WP_VERSION:-([0-9]+\.[0-9]+(?:\.[0-9]+)?)")


def main() -> int:
    problems: list[str] = []
    checked = 0
    pins: set[str] = set()

    for workflow in sorted(WORKFLOWS.glob("*.yml")):
        text = workflow.read_text(encoding="utf-8")
        for line_number, line in enumerate(text.splitlines(), start=1):
            match = DOWNLOAD.search(line)
            if not match:
                continue
            checked += 1
            if workflow.name in MOVING_ON_PURPOSE:
                continue
            command = match.group(0)
            if not PINNED.search(command):
                problems.append(
                    f"{workflow.name}:{line_number} downloads WordPress without --version. "
                    f"A gate that fails here cannot say whether the code or WordPress moved. "
                    f"Use --version=\"${{RP_WP_VERSION:-<version>}}\", or add this workflow to "
                    f"MOVING_ON_PURPOSE if it is meant to track releases."
                )
                continue
            found = DEFAULT_PIN.search(command)
            if found:
                pins.add(found.group(1))

    if checked == 0:
        problems.append(
            "no `wp core download` found in any workflow. Either the provisioning moved and this "
            "check is now watching nothing, or the real-WordPress gates are gone. Both need a look."
        )

    if len(pins) > 1:
        problems.append(
            f"the blocking gates pin different WordPress versions ({', '.join(sorted(pins))}). "
            f"They are supposed to answer the same question about the same WordPress; pick one."
        )

    # The plugin tells users which WordPress it was tested against. That claim is
    # only worth something if it names the version CI actually runs -- otherwise
    # it is a number someone typed once.
    header = PLUGIN.read_text(encoding="utf-8")
    tested = re.search(r"Tested up to:\s*([0-9]+\.[0-9]+(?:\.[0-9]+)?)", header)
    if not tested:
        problems.append(
            f"{PLUGIN.name} has no `Tested up to:` header. Users read it to decide whether to "
            f"install; WordPress reads it to warn them."
        )
    elif pins and tested.group(1) not in pins:
        problems.append(
            f"{PLUGIN.name} claims `Tested up to: {tested.group(1)}` while CI provisions "
            f"{', '.join(sorted(pins))}. One of the two is wrong, and the plugin header is the "
            f"one users see."
        )

    if problems:
        print("WordPress version pinning: not satisfied\n", file=sys.stderr)
        for item in problems:
            print(f"  - {item}", file=sys.stderr)
        return 1

    print(
        f"WordPress version pinning: {checked} provisioning call(s) checked, "
        f"pinned to {', '.join(sorted(pins)) or '(matrix only)'}, "
        f"and the plugin header agrees"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
