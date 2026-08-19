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
LAW 9 — THE TOOLS/LIST SURFACE NEVER EXCEEDS 5,000 TOKENS.

### Law 9 in full

Every name, description and input schema that `tools/list` emits counts against
one ceiling of approximately 5,000 tokens. This is not a style preference. A
host that enforces it does not reject the server: the tools stay visible in the
prompt and silently stop being callable, which presents as a permissions or
protocol fault and sends the investigation somewhere else entirely. The suite
shipped at roughly 22,000 tokens — four and a half times over — and that is the
most likely cause of the "tool not exposed" reports.

The ceiling is enforced in code, not by convention:
`PRSTUDIO_UC_MCP_V5::tools_within_budget()` assembles the surface until the
budget is reached and then stops, so adding a tool can never push the server
past the limit again. Essential routers and the discovery surface are admitted
first and are never trimmed; everything below the line stays fully reachable
through `prstudio_capability_search` plus `prstudio_execute`, so a withheld tool
costs a lookup, never a capability.

`tests/php-tools-list-budget.php` fails the build if the emitted surface exceeds
the budget or if any essential tool was trimmed. It has the same standing as the
one-guard constitution check: do not raise the constant to make it pass.

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

## Single-repository AI coordination protocol

**`master` is the only authoritative repository state.** All agents, human contributors, CI workflows, release gates and remediation work must resolve their decisions against the current `master` HEAD before reading, testing, editing or reporting project status.

The former `hardening/runtime-invariants-2026-08-18` branch is historical only. It must not be treated as a second source of truth, used for new hardening work, or cited as the current product state.

### Division of work

- **Verification/control agents** add or strengthen tests, invariants, benchmarks, evidence collectors, security/supply-chain gates and production-readiness checks against current `master`.
- **Remediation agents** read those exact failures from current `master`, fix the product or the test harness when the harness is objectively wrong, and keep the invariant equal or stronger.
- No agent weakens, skips, marks advisory, raises thresholds or suppresses a failing control merely to obtain a green build.
- A failing control that is a test defect is fixed as a test defect; a failing control that reproduces a product defect remains release-blocking until the product is fixed.

### Branch discipline

Long-lived parallel truth branches are forbidden. If a temporary branch is necessary, it must:

1. start from the current `master` HEAD;
2. contain one bounded change set;
3. re-read/rebase from `master` before final write if `master` moved;
4. merge back promptly;
5. be considered obsolete immediately after its content reaches `master`.

Before every write to `master`, fetch the current `master` HEAD and the current blob SHA of every file being modified. If `master` moved during the operation, stop the write, re-read the new state and integrate rather than force-pushing or overwriting another agent's work.

### Control-plane ownership

The canonical control plane lives in `.github/workflows/`, `quality/`, `tests/`, `evidence/`, `ENTERPRISE-VERIFICATION-PROTOCOL-2026-08-18.md`, `ATOMIC-CAPABILITY-ASSURANCE-2026-08-19.md` and `PRODUCTION-READINESS-CERTIFICATION-2026-08-19.md` on `master`.

Capability/tool counts are inventory only. A capability may be called operational or production-ready only when the current `master` evidence model proves the required implementation, atomic execution test, independent oracle, negative/security/idempotency behavior where applicable, official documentation mapping and real-environment evidence.

`production_ready` / `production_proven` is an output of the certification gates for the exact candidate SHA. It is never asserted manually because many unrelated checks happened to pass.
