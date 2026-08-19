# PR STUDIO EXECUTION CONSTITUTION

**Reference date: 19 August 2026.**

Agents working in this subtree must read and obey repository-root `AGENTS.md`
before analysis, editing, testing, execution or reporting. The root constitution
is canonical; this file is an additional mandatory entry point and cannot weaken
it.

LAW 1 — ANTI-CRASH IS THE ONLY MUTATION GUARD.  
LAW 2 — VERIFICATION IS EVIDENCE, NEVER AUTHORIZATION.  
LAW 3 — EXECUTABLE ACTIONS EXECUTE.  
LAW 4 — HUMAN INTERVENTION IS AUTH-CHALLENGE ONLY.  
LAW 5 — TRANSIENT FAILURE RETRIES; IT DOES NOT PARK THE MISSION.  
LAW 6 — OWNERSHIP IS SESSION/LANE SCOPED.  
LAW 7 — NO TRIAL INPUT.  
LAW 8 — NO MODEL ROUND-TRIP WITHOUT NEW JUDGMENT.  
LAW 10 — NO EXCLUSIONS, NO DEFERRAL, NO PARTIAL ACCEPTANCE.  
LAW 11 — FULL TEST/EXECUTION SURFACE MUST EXECUTE AT 100 PERCENT.  
LAW 12 — BLOCKERS TRIGGER STUDY AND REMEDIATION; THEY NEVER AUTHORIZE BYPASS OR STOP.  
LAW 13 — HUMAN INTENT MUST RESOLVE TO ACTION, NOT INTERNAL ENCYCLOPEDIA.

## Laws 10–13 are non-bypassable project invariants

No agent may exclude, skip, waive, suppress, narrow, mark `N/A`, mark advisory,
move work out of scope, postpone, backlog, leave `TODO`, defer to a later pass or
stop at partial implementation of applicable work. An explicit rationale does not legalize an exclusion or a deferral. The required loop is:

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

The only acceptable stopping condition is verified closure. No alternative path to completion exists.

The test/execution denominator is discovered from the exact authoritative
checkout at runtime. There are no allowlists, helper exemptions, fixture
exemptions, legacy exemptions, baselines, ratchets or "does not count" classes.
Every PHP, JS/MJS/CJS, Python and shell source receives its real language
parser/compiler check; every JSON/YAML/XML source receives its real format
parser check. Parsing alone is never execution evidence.

Every tracked file under `tests/` and `prstudio-unified-browser-agent/tests/`
must additionally appear in the exact-SHA real execution registry. Executable
files must actually run successfully under their runtime. Data files must be
shown by operating-system evidence to have been consumed by a successful
executed test process. Static references, filename mentions, imports, workflow
mentions and parse-only reads do not substitute for runtime execution.

```text
TOTAL_TEST_SURFACE_FILES == REAL_EXECUTED_FILES
EXECUTION_PERCENT == 100.000000
SYNTAX_TARGETS == SYNTAX_PASSED
```

If one file cannot run, it is repaired until it runs; the denominator or gate is
never weakened. A blocker triggers diagnosis, study of the concrete code and
current official specification/documentation when uncertainty exists, repair,
execution, observation and repetition. A blocker never authorizes an exception,
a weaker path or a success report. Hard external constraints leave the mission
incomplete while all other possible work continues.

The user-facing API is natural-language human intent. Users are not required to
know file layout, class names, tool IDs, capability IDs, schemas or routing keys.
The suite must resolve ordinary user language into the correct capability and
real execution path. When technically uncertain, inspect contracts, current
official documentation and runtime evidence rather than guessing. Certification
must start from realistic user language and end with independently observed
requested effects; engineer-only direct calls do not certify the human-facing
path.

No optimization for token budget, compute, server load, CI duration,
convenience or cost may weaken these laws. Higher-priority platform/system
constraints that cannot be changed from the repository remain unresolved
technical blockers, never compliance.

## Runtime invariant

For any technically executable mutation:

```text
CAN_EXECUTE
→ ANTI_CRASH
→ EXECUTE
→ OBSERVE
→ CONTINUE / REPORT
```

Authentication, protocol authorization, schema/input validation, sanitization,
escaping, injection protection, filesystem/path/archive containment, technical
capability checks, timeouts, network/HTTP/filesystem/persistence failures and
actual process failures are technical correctness. They return a technical
error; they are not application approval/review states.

Post-execution uncertainty is represented as `executed=true`, `verified=false`,
`degraded=true`, `blocking=false`. Evidence uncertainty must not roll back a
successful mutation, fail the mission, request review, transfer ownership or
require manual resume.

External CAPTCHA/MFA/interactive-login challenges are the only
human-interaction exception. They remain inline within the controlled session;
the runtime detects challenge disappearance and continues automatically without
a Resume control.

A future feature that can stop a technically valid action is forbidden unless it
is the Anti-Crash mutation guard.
