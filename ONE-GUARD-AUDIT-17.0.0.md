# PR STUDIO 17.0.0 — ONE-GUARD AUDIT

Reference date: **17 August 2026**

## ONE_GUARD_STATUS

```text
ANTI_CRASH: PRESENT
OTHER_MUTATION_GUARDS: ZERO
```

## Runtime purge result

- Forbidden runtime occurrences in original uploaded ZIP: **150** across **33** deployable files.
- Forbidden runtime occurrences after purge: **0** across **0** deployable files.
- Runtime red-team lines classified: **2401**.
- `UNWANTED CONSERVATISM`: **0**.
- `ANTI-CRASH`: **58** classified lines.
- `TECHNICAL CORRECTNESS`: **1699** classified lines.
- `TELEMETRY`: **644** classified lines.

## Physically removed files

- `prstudio-unified-control/includes/class-prstudio-uc-autonomy.php`
- `prstudio-unified-control/includes/class-prstudio-uc-canary-engine.php`
- `prstudio-unified-control/includes/class-prstudio-uc-interaction-policy.php`
- `prstudio-unified-control/includes/class-prstudio-uc-loop-guard.php`
- `prstudio-unified-control/includes/class-prstudio-uc-policy-engine.php`
- `prstudio-unified-browser-agent/lib/interaction-pacer.js`

## Removed mechanisms

- Human takeover queue/storage/TTL/endpoints/UI and manual resume/acknowledgement.
- `HUMAN_TAKEOVER`, `RESUMING`, `WAITING_FOR_APPROVAL`, `FAILED_SAFE` and equivalent workflow parking states.
- Local approvals / critical-action approvals / approval queue and cross-department approval chain.
- Interaction Policy, Policy Engine, Loop Guard, Canary Engine and Interaction Pacer.
- Autonomy modes/envelope/quota and risk-as-permission behavior.
- Verifier-as-completion-gate and evidence-driven rollback/fail-job behavior.
- WordPress/WooCommerce readback rollback after a persisted mutation.
- Same-lane task binding veto, agent-tab-required loop and re-adoption dependency.
- Persistent Emergency Stop / manual re-enable path and recovery acknowledgement.
- Local-vs-remote application lane veto, duplicate-action circuit and step/time budget veto.
- Screenshot persistence preflight/circuit before perception capture.
- Planner `policy_risk`, `canary_gate`, pre-action snapshot, evidence stop and risk-based deep/standard modes.
- Generic `browser_action` typed-precedence/security-routing round-trip; generic routing now resolves/dispatches locally.

## Retained technical correctness

Authentication/OAuth, authorization required by the remote protocol, schema/input validation, sanitization/escaping, SQL-injection controls, path/archive traversal controls, filesystem sandboxing, bounded technical timeouts, real HTTP/network/filesystem/database errors, idempotency/concurrency locking and transaction rollback on a real atomicity failure remain technical correctness mechanisms. They are not converted into review/approval/human states.

## Verification behavior

Post-execution verification is evidence only. An executed mutation may return `verified=false`, `degraded=true`, `blocking=false`; it remains executed/persisted. Failure to observe public/readback evidence alone does not roll back or fail the workflow.

## Browser execution behavior

Controlled ownership is lane/session scoped. Agent-created tabs follow `about:blank → ownership registration → lane/session binding → CDP attach → navigate`. User focus/click/mouse/key activity is observer telemetry. CAPTCHA/MFA/interactive-login is the sole human challenge path and auto-resumes when the challenge disappears.

## Evidence files

- `ONE-GUARD-STATUS-17.0.0.json` — machine-readable invariant status.
- `ONE-GUARD-BENCHMARK-17.0.0.json` — measured/structural before-after evidence.
- `ONE-GUARD-RUNTIME-CLASSIFICATION-17.0.0.ndjson` — repository-wide runtime red-team classification for the requested search vocabulary.
- `RIGOROUS-AUDIT-17.0.0.json` — file/tool/capability/action audit.
- `tests/one_guard_constitution.py` — repository-wide build failure test for reintroduced mutation guards.
