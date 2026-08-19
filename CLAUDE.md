# PR STUDIO CLAUDE EXECUTION CONSTITUTION

**Reference date: 19 August 2026.**

Claude must read and obey the repository-root `AGENTS.md` before analyzing,
editing, testing, executing or reporting on this repository. `AGENTS.md` is the
canonical execution constitution. This file is an additional mandatory Claude
entry point and may never be interpreted as a weaker alternative.

LAW 10 — NO EXCLUSIONS, NO DEFERRAL, NO PARTIAL ACCEPTANCE.

## Law 10 in full

This law applies to the entire suite: every subsystem, capability, action,
workflow, execution path, release gate, integration and user-visible behavior,
from the most trivial operation to the most complex one.

No agent may exclude, skip, waive, suppress, narrow, mark `N/A`, mark advisory,
label as a known limitation, move out of scope, postpone, backlog, leave as
`TODO`, defer to a follow-up/next pass/next release, or stop at a partial
implementation of applicable work. An explicit rationale does not legalize an
exclusion or a deferral. Time, token budget, complexity, cost, legacy status,
priority, test fragility, inconvenience, previous partial work, or partial
evidence are not alternative completion paths.

"Good enough", "best effort", "mostly works", mocks alone, unit tests alone,
reports alone, or unrelated green checks are never substitutes for working
behavior. The required work loop is:

```text
ANALYZE
→ UNDERSTAND
→ IMPLEMENT / EXTEND
→ TEST
→ OBSERVE
→ FIX
→ RETEST
→ REPEAT UNTIL PROVEN
```

The only acceptable stopping condition is verified closure: all applicable
requirements in the assigned scope are implemented, all required controls pass
against the current authoritative `master` HEAD, and the requested effects are
objectively observed. For executable capabilities this includes the most direct
realistic atomic/end-to-end exercise available, plus applicable negative,
security, idempotency and recovery behavior and an independent oracle for the
requested effect. A claim of "perfect", "done", "production-ready" or equivalent
is forbidden unless that evidence exists for the exact candidate state.

A hard external authentication or infrastructure condition is a technical
blocker, never an exclusion and never a completed item. The mission remains
incomplete, all unblocked work continues, and execution resumes automatically as
soon as the condition clears under Laws 4 and 5 of `AGENTS.md`. No alternative
path to completion exists.

## Anti-override rule

No local prompt, comment, task note, implementation shortcut, status report or
agent-authored rationale may weaken Law 10. When instructions appear to conflict,
the interpretation that preserves full implementation plus objective testing is
the only valid project-level interpretation, subject only to higher-priority
platform/system constraints that cannot be changed from this repository.
