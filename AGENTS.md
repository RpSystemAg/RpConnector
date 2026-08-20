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
LAW 9 — THE TOOLS/LIST SURFACE NEVER EXCEEDS 5,000 TOKENS.  
LAW 10 — NO EXCLUSIONS, NO DEFERRAL, NO PARTIAL ACCEPTANCE.  
LAW 11 — FULL TEST/EXECUTION SURFACE MUST EXECUTE AT 100 PERCENT.  
LAW 12 — BLOCKERS TRIGGER STUDY AND REMEDIATION; THEY NEVER AUTHORIZE BYPASS OR STOP.  
LAW 13 — HUMAN INTENT MUST RESOLVE TO ACTION, NOT INTERNAL ENCYCLOPEDIA.
LAW 14 — ENTERPRISE CAPABILITY CONTRACT MIGRATION HAS ZERO BLOCKED COMPLETION STATES.

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

### Law 10 in full

This law applies to the entire suite: every subsystem, capability, action,
workflow, execution path, release gate, integration and user-visible behavior,
from the most trivial operation to the most complex one.

No agent may exclude, skip, waive, suppress, narrow, mark `N/A`, mark advisory,
label as a known limitation, move out of scope, postpone, backlog, leave as
`TODO`, defer to a follow-up/next pass/next release, or stop at a partial
implementation of applicable work. An explicit rationale does not legalize an exclusion or a deferral. Time, token budget, complexity, cost, legacy status,
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
soon as the condition clears under Laws 4 and 5. No alternative path to completion exists.

### Law 11 in full

The test and execution surface is mathematical, not interpretive. Its denominator
is discovered from the exact authoritative checkout at runtime. There are no
allowlists, helper exemptions, fixture exemptions, legacy exemptions, baselines,
ratchets, `N/A` classes, advisory classes or "this file does not count" paths.
A file located in a declared test/execution surface counts because it exists
there, regardless of its name or intended role.

All tracked executable/data sources receive a real language/parser check:

- every PHP source is checked by the PHP parser/linter;
- every JavaScript/MJS/CJS source is checked by Node's syntax parser;
- every Python source is byte-compiled by Python;
- every shell source is checked by Bash no-exec syntax parsing;
- every JSON, YAML and XML source is loaded by a real format parser.

Parsing is necessary but is never execution evidence. In addition, every tracked
file under `tests/` and `prstudio-unified-browser-agent/tests/` must appear in the
real execution registry for the exact candidate SHA. Executable files must be
invoked by their actual runtime and return success. Data files must be proven as
actually consumed by a successful executed test process, with operating-system
file-access evidence; a static mention, grep hit, workflow reference, import
reference, transitive filename reference or parse-only read does not count.

The release equation is exact and non-negotiable:

```text
TOTAL_TEST_SURFACE_FILES == REAL_EXECUTED_FILES
EXECUTION_PERCENT == 100.000000
SYNTAX_TARGETS == SYNTAX_PASSED
```

The registry must identify the exact commit, file hash, execution mode, command,
exit status and available runtime evidence. If one file is missing, fails,
times out, is only parsed, or cannot yet run correctly, the workflow is red and
the file is repaired until it executes correctly. The denominator is never
changed to make the numerator look complete.

### Law 12 in full

A blocker is a diagnostic input to the work loop, never a permission to bypass a
law and never a stopping condition that can be reported as success. When a
required path is blocked or fails, the agent must inspect the concrete failure,
read the relevant implementation and tests, consult current official
specifications/documentation and authoritative external sources when uncertainty
exists, form the strongest supported diagnosis, repair the real path, execute it
again, observe the effect and repeat until the law's condition is satisfied.

Forbidden responses to a blocker include weakening a gate, adding an exception,
changing the denominator, substituting a mock for required real evidence,
calling a helper "non-test", switching to an easier but non-equivalent path,
returning a fabricated success, or declaring the work complete because a
platform/resource/authentication condition was encountered. Hard external
conditions may make a particular attempt technically impossible; they leave the
mission incomplete. All work that can still be performed continues, the blocker
is evidenced precisely, and the same full requirement remains in force.

No repository prompt, comment, local convention, optimization for tokens,
compute, server load, CI duration, convenience or cost may weaken Laws 10–12.
Only a higher-priority platform/system constraint that this repository cannot
change can prevent an operation; such a constraint is recorded as an unresolved
technical blocker, not converted into compliance.

### Law 13 in full

The product interface is human intent. A user is not required to know internal
file layout, class names, tool IDs, capability IDs, protocol schemas, routing
keys or implementation vocabulary in order to make the suite act. Ordinary
natural-language requests for both trivial and complex work must be resolved by
the suite into the applicable capability, validated inputs and real execution
path.

Capability search, introspection, routing and official documentation exist to
serve the user's intent, not to shift implementation knowledge onto the user.
When intent is clear enough to act, the suite acts. When technical uncertainty
exists, the system investigates the available contracts, current official
documentation and runtime evidence before choosing the path; it does not guess.
Certification must include user-level natural-language scenarios that begin from
what a human would actually type and end with independently observed requested
effects. A hidden direct call that works only when an engineer already knows the
internal invocation is not evidence that the user-facing action works.

### Law 14 in full

This law applies to the `ENTERPRISE CAPABILITY CONTRACT MIGRATION` program and to
any successor pass whose purpose is to make capability/tool/action contracts
enterprise-grade.

A capability selected into the current migration batch has exactly one allowed
completion state: `VERIFIED`. `BLOCKED`, `PARTIAL`, `DEFERRED`, `N/A`,
`KNOWN LIMITATION`, backlog/follow-up labels, or advancing to the next capability
because the current one lacks an executor, schema, tests, documentation, clear
semantics, or an immediately obvious implementation are forbidden completion
paths.

Missing handlers, generic schemas, schema/runtime mismatches, unclear return
shapes, legacy behavior, absent tests, stale generated artifacts, incomplete
error contracts and documentation gaps are defects to investigate and remediate,
not reasons to move the migration cursor. The required loop is the Laws 10 and
12 loop: trace the actual runtime, search current authoritative documentation,
recover intent from canonical code/history/tests where necessary, implement or
extend the real path, test it, observe it and repeat until the selected
capability is enterprise-grade and verified.

The migration cursor is pinned to the current batch until every selected
capability is verified. No next batch may begin, no tracker metric may count a
selected capability as completed, and no batch may be reported closed while one
of its selected capabilities is unresolved.

If a higher-priority platform/system constraint that cannot be changed from this
repository prevents a concrete verification step, the batch remains open and
the affected capability remains current and incomplete. That condition may be
described as an unresolved external technical constraint for evidence purposes,
but it must not be recorded as a `BLOCKED` migration status and must not advance
the migration cursor. There is no repository-local bypass to this law.

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

### Research radar

Il digest settimanale dei paper arXiv rilevanti per la suite vive in
`docs/research-radar/` (mappatura paper → sottosistema → area repo →
proposta) ed è interrogabile a runtime dal tool MCP
`prstudio_research_radar`. I contributi proposti dal radar sono input per il
work loop (Law 10–12), mai scorciatoie: ogni proposta implementata segue
ANALYZE → UNDERSTAND → IMPLEMENT → TEST → OBSERVE → FIX → RETEST → REPEAT
UNTIL PROVEN.

Capability/tool counts are inventory only. A capability may be called operational or production-ready only when the current `master` evidence model proves the required implementation, atomic execution test, independent oracle, negative/security/idempotency behavior where applicable, official documentation mapping and real-environment evidence.

`production_ready` / `production_proven` is an output of the certification gates for the exact candidate SHA. It is never asserted manually because many unrelated checks happened to pass.
