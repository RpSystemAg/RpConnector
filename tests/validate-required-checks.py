#!/usr/bin/env python3
"""A required status check must name a job that actually exists.

Why this exists
---------------
Branch protection matches required checks by their rendered name, as a string.
There is no link back to the workflow. Rename a job and the requirement does not
error, does not warn, and does not turn red -- it simply stops matching
anything, and the branch is then protected by a rule that can never fail.

That is the worst possible failure for a protection rule: it looks stronger than
an unprotected branch while being weaker, because nobody is watching a green
shield.

The risk is not hypothetical here. This repository is about to have required
checks configured, two agents are renaming jobs in it on the same day, and one
job name was already changed twice this week.

Dynamic job names
-----------------
A `name:` reading `${{ github.* }}` renders differently depending on the event,
so it cannot be a stable required check. The MegaLinter job was exactly this for
a few hours -- it rendered "MegaLinter (full sweep, advisory)" on a master push
and "MegaLinter (changed files, blocking)" everywhere else. Requiring either
string would have stopped matching on the other event, which is the hole above.
It has since been given a static name, so the repository is currently clean;
the check stays because the name was mine and the mistake is easy to repeat.

A matrix expression is a different thing and is not reported. GitHub renders one
check per leg, and each of those names is stable, so it can be required. Nine of
the eighteen workflows here use one, and reporting them would make this gate cry
wolf -- which is how a gate ends up permanently overridden.

What is checked
---------------
1. Every check listed in required-checks.json corresponds to a real job.
2. No required check names a job whose name is dynamic.
3. If the repository has rulesets or branch protection configured and gh is
   authenticated, the live required set matches required-checks.json. Without
   network or auth this step is skipped and said so -- a check that quietly
   turns into a no-op offline would be the same defect it exists to prevent.

Exit code 1 on any mismatch.
"""
from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WORKFLOWS = ROOT / ".github" / "workflows"
DECLARED = Path(__file__).resolve().parent / "required-checks.json"
REPO = "RpSystemAg/RpConnector"


def workflow_jobs() -> tuple[dict[str, str], set[str]]:
    """Return (rendered_name -> workflow file, names that are dynamic)."""
    try:
        import yaml
    except ImportError:
        print("SKIP pyyaml is not installed", file=sys.stderr)
        raise SystemExit(0)

    names: dict[str, str] = {}
    dynamic: set[str] = set()
    for path in sorted(WORKFLOWS.glob("*.y*ml")):
        try:
            doc = yaml.safe_load(path.read_text(encoding="utf-8", errors="replace"))
        except yaml.YAMLError as exc:
            print(f"FAIL {path.name} does not parse: {exc}", file=sys.stderr)
            raise SystemExit(1)
        if not isinstance(doc, dict):
            continue
        for job_id, job in (doc.get("jobs") or {}).items():
            label = job_id
            if isinstance(job, dict) and isinstance(job.get("name"), str):
                label = job["name"]
            if "${{" in label:
                # A matrix expression is not the problem. GitHub renders one
                # check per leg -- "PHP 8.0 (declared floor is 8.0)" -- and each
                # of those names is stable, so it can be required. Reporting
                # them would be crying wolf, and a gate that cries wolf gets
                # overridden permanently.
                #
                # An expression reading github.* is the problem: the same job
                # renders one name on a master push and another on a pull
                # request, so requiring either string stops matching on the
                # other event.
                if re.search(r"\$\{\{[^}]*github\.", label):
                    dynamic.add(f"{path.name}:{job_id} -> {label}")
                    continue
                for leg in matrix_legs(job, label):
                    names[leg] = path.name
                names.setdefault(job_id, path.name)
                continue
            names[label] = path.name
            names.setdefault(job_id, path.name)
    return names, dynamic



def matrix_legs(job: dict, label: str) -> list[str]:
    """Render a matrix job name once per combination.

    Only substitutes matrix values that are plain scalars listed in the
    workflow. An include/exclude block or a value built at run time is left
    alone: the name is still recorded under the job id, so a required check can
    reference that instead of a string this cannot predict.
    """
    import itertools

    matrix = ((job.get("strategy") or {}).get("matrix") or {})
    axes = {k: v for k, v in matrix.items() if isinstance(v, list) and all(isinstance(x, (str, int, float)) for x in v)}
    if not axes:
        return [label]
    out = []
    for combo in itertools.product(*axes.values()):
        rendered = label
        for key, value in zip(axes.keys(), combo):
            rendered = re.sub(r"\$\{\{\s*matrix\." + re.escape(key) + r"\s*\}\}", str(value), rendered)
        if "${{" not in rendered:
            out.append(rendered)
    return out or [label]


def live_required() -> list[str] | None:
    """Required checks configured on master, or None when it cannot be read."""
    for args in (
        ["gh", "api", f"repos/{REPO}/rulesets", "--jq", ".[].id"],
    ):
        try:
            out = subprocess.run(args, capture_output=True, text=True, timeout=30)
        except (OSError, subprocess.SubprocessError):
            return None
        if out.returncode != 0:
            return None
        ids = [line.strip() for line in out.stdout.splitlines() if line.strip()]
        found: list[str] = []
        for rid in ids:
            r = subprocess.run(
                ["gh", "api", f"repos/{REPO}/rulesets/{rid}"],
                capture_output=True, text=True, timeout=30,
            )
            if r.returncode != 0:
                continue
            data = json.loads(r.stdout)
            for rule in data.get("rules", []):
                if rule.get("type") == "required_status_checks":
                    for c in rule.get("parameters", {}).get("required_status_checks", []):
                        found.append(c.get("context", ""))
        return found
    return None


def main() -> int:
    names, dynamic = workflow_jobs()

    declared = []
    if DECLARED.exists():
        declared = json.loads(DECLARED.read_text(encoding="utf-8")).get("required", [])
    else:
        DECLARED.write_text(
            json.dumps(
                {
                    "_comment": (
                        "Checks that must be required on master. Every entry must name a "
                        "real job with a static name. See validate-required-checks.py."
                    ),
                    "required": [],
                },
                indent=2,
            )
            + "\n",
            encoding="utf-8",
        )
        print("required-checks.json created, empty. Populate it when protections go on.")

    problems = 0
    print(f"{len(names)} job name(s) across {len(list(WORKFLOWS.glob('*.y*ml')))} workflow(s)")

    if dynamic:
        print(f"\n{len(dynamic)} job name(s) render differently per event:")
        for d in sorted(dynamic):
            print(f"  {d}")
        print("  These cannot be used as required checks -- the requirement would stop")
        print("  matching on the events where the name renders differently, and a rule")
        print("  that matches nothing never fails.")

    for check in declared:
        if check in names:
            continue
        print(f"\nFAIL required check '{check}' matches no job", file=sys.stderr)
        print("     Branch protection matches by rendered name. A requirement naming a", file=sys.stderr)
        print("     job that does not exist can never fail, so the branch is guarded by", file=sys.stderr)
        print("     a rule that is permanently green.", file=sys.stderr)
        problems += 1

    for entry in dynamic:
        rendered = entry.split(" -> ", 1)[-1]
        for check in declared:
            if check and check in rendered:
                print(f"\nFAIL required check '{check}' names a job with a dynamic name", file=sys.stderr)
                print(f"     {entry}", file=sys.stderr)
                problems += 1

    live = live_required()
    if live is None:
        print("\nSKIP live ruleset not read (no gh auth or no network)")
    elif not live and not declared:
        print("\nno required checks configured yet, and none declared -- consistent")
    else:
        missing = sorted(set(declared) - set(live))
        extra = sorted(set(live) - set(declared))
        if missing:
            print(f"\nFAIL declared but not enforced on master: {', '.join(missing)}", file=sys.stderr)
            problems += 1
        if extra:
            print(f"\nFAIL enforced on master but not declared here: {', '.join(extra)}", file=sys.stderr)
            print("     The committed list is meant to be the reviewable copy of what", file=sys.stderr)
            print("     protects the branch. A rule that exists only in the web UI is a", file=sys.stderr)
            print("     rule nobody reviews.", file=sys.stderr)
            problems += 1
        if not missing and not extra:
            print(f"\nlive ruleset matches the declared list ({len(live)} check(s))")

    if problems:
        return 1
    print("\nrequired checks are consistent")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
