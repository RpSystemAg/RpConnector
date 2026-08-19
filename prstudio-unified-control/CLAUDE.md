# PR STUDIO CONTROL — CLAUDE EXECUTION CONSTITUTION

**Reference date: 19 August 2026.**

Claude working in this subtree must read and obey both repository-root
`AGENTS.md` and this subtree's `AGENTS.md`. The repository-root constitution is
canonical; this file cannot weaken it.

LAW 10 — NO EXCLUSIONS, NO DEFERRAL, NO PARTIAL ACCEPTANCE.

No agent may exclude, skip, waive, suppress, narrow, mark `N/A`, mark advisory,
move work out of scope, postpone, backlog, leave `TODO`, defer to a follow-up,
next pass or next release, or stop at partial implementation of applicable work.
An explicit rationale does not legalize an exclusion or a deferral.

The required loop is:

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
requirements are implemented and objectively proven on the authoritative
`master` state. "Good enough", partial evidence, unrelated green checks, mocks
alone or reports alone are not completion. No alternative path to completion
exists.
