# PR STUDIO EXECUTION CONSTITUTION

**Reference date: 17 August 2026.**

LAW 1 — ANTI-CRASH IS THE ONLY MUTATION GUARD.  
LAW 2 — VERIFICATION IS EVIDENCE, NEVER AUTHORIZATION.  
LAW 3 — EXECUTABLE ACTIONS EXECUTE.  
LAW 4 — HUMAN INTERVENTION IS AUTH-CHALLENGE ONLY.  
LAW 5 — TRANSIENT FAILURE RETRIES; IT DOES NOT PARK THE MISSION.  
LAW 6 — OWNERSHIP IS SESSION/LANE SCOPED.  
LAW 7 — NO TRIAL INPUT.  
LAW 8 — NO MODEL ROUND-TRIP WITHOUT NEW JUDGMENT.

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
