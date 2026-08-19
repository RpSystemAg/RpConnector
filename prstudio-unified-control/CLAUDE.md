# PR STUDIO CONTROL — CLAUDE EXECUTION CONSTITUTION

**Reference date: 19 August 2026.**

Claude working in this subtree must read and obey repository-root `AGENTS.md`
and this subtree's `AGENTS.md` before analysis, editing, testing, execution or
reporting. The root constitution is canonical; this file cannot weaken it.

LAW 10 — NO EXCLUSIONS, NO DEFERRAL, NO PARTIAL ACCEPTANCE.  
LAW 11 — FULL TEST/EXECUTION SURFACE MUST EXECUTE AT 100 PERCENT.  
LAW 12 — BLOCKERS TRIGGER STUDY AND REMEDIATION; THEY NEVER AUTHORIZE BYPASS OR STOP.  
LAW 13 — HUMAN INTENT MUST RESOLVE TO ACTION, NOT INTERNAL ENCYCLOPEDIA.  
LAW 14 — ENTERPRISE CAPABILITY CONTRACT MIGRATION HAS ZERO BLOCKED COMPLETION STATES.

An explicit rationale does not legalize an exclusion or a deferral. The only
acceptable stopping condition is verified closure. No alternative path to
completion exists.

For Enterprise Capability Contract Migration work, every selected capability
remains in the current batch until it is `VERIFIED`. `BLOCKED`, `PARTIAL`,
`DEFERRED`, `N/A`, known-limitation and follow-up/backlog statuses never advance
the cursor. Missing executors, generic contracts, unclear semantics, absent tests
and schema/runtime mismatches are defects to study, implement, execute and
retest in the current batch until proven. A higher-priority external constraint
may leave the batch open and incomplete; it never creates a completed blocked
state or permission to start the next batch.

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

There are no allowlists, helper/fixture/legacy exemptions, baselines, ratchets or
"does not count" classes for the test/execution surface. Every applicable source
must pass its real parser/compiler, and every tracked file under `tests/` and
`prstudio-unified-browser-agent/tests/` must also be present in the exact-SHA
real execution registry. Parsing, imports, workflow mentions and filename
references do not count as execution.

If a required path fails or is blocked, diagnose it, inspect the implementation
and tests, consult current official documentation and authoritative sources when
uncertain, repair the real path and execute again. Never weaken the requirement,
change the denominator, substitute a non-equivalent path or report success from
a blocker.

A human supplies normal language, not internal invocation knowledge. The suite
must resolve that intent into the real capability and execution path and prove
the requested effect end to end. Engineer-only direct calls do not certify the
human-facing route.

No token, compute, server-load, CI-duration, convenience or cost optimization may
weaken these laws. A higher-priority platform/system constraint remains an
unresolved technical blocker, never compliance.
