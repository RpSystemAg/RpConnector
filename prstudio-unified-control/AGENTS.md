# PR STUDIO EXECUTION CONSTITUTION

**Reference date: 19 August 2026.**

LAW 1 — ANTI-CRASH IS THE ONLY MUTATION GUARD.  
LAW 2 — VERIFICATION IS EVIDENCE, NEVER AUTHORIZATION.  
LAW 3 — EXECUTABLE ACTIONS EXECUTE.  
LAW 4 — HUMAN INTERVENTION IS AUTH-CHALLENGE ONLY.  
LAW 5 — TRANSIENT FAILURE RETRIES; IT DOES NOT PARK THE MISSION.  
LAW 6 — OWNERSHIP IS SESSION/LANE SCOPED.  
LAW 7 — NO TRIAL INPUT.  
LAW 8 — NO MODEL ROUND-TRIP WITHOUT NEW JUDGMENT.  
LAW 10 — NO EXCLUSIONS, NO DEFERRAL, NO PARTIAL ACCEPTANCE.

### Law 10 in full

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
soon as the condition clears under Laws 4 and 5. No alternative path to
completion exists.

## Runtime invariant

For any technically executable mutation:

```text
CAN_EXECUTE
→ ANTI_CRASH
→ EXECUTE
→ OBSERVE
→ CONTINUE / REPORT
```

Authentication, protocol authorization, schema/input validation, sanitization, escaping, injection protection, filesystem/path/archive containment, technical capability checks, timeouts, network/HTTP/filesystem/persistence failures, and actual process failures are technical correctness. They return a technical error; they are not application approval/review states.

Post-execution uncertainty is represented as `executed=true`, `verified=false`, `degraded=true`, `blocking=false`. Evidence uncertainty must not roll back a successful mutation, fail the mission, request review, transfer ownership, or require manual resume.

External CAPTCHA/MFA/interactive-login challenges are the only human-interaction exception. They remain inline within the controlled session; the runtime detects challenge disappearance and continues automatically without a Resume control.

Browser ownership belongs to lane/session, never task ID. Agent-created tabs are claimed on `about:blank`, bound to lane/session, attached to CDP, then navigated. Human pointer/keyboard/focus activity is observer telemetry only.

Deterministic browser input is grounded before dispatch. No trial clicks and no blind fallback chains. Screenshot perception and DOM/AX/geometry/coordinate transforms are execution inputs, not optional diagnostics.

A future feature that can stop a technically valid action is forbidden unless it is the Anti-Crash mutation guard.
