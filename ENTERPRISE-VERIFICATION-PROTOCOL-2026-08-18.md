# RP Connector Enterprise Verification Protocol — 2026-08-18

Status: **normative for release hardening**

This document defines the evidence required before RP Connector may be described as production-proven. It is intentionally stricter than feature-count validation. Tool/capability count is inventory; it is not reliability evidence.

## 1. Non-negotiable release rule

A release MUST NOT be called production-proven because it exposes a large tool surface, passes syntax checks, or succeeds in a local harness.

`production_proven=true` is allowed only when all mandatory evidence tiers below are green for the exact release commit and the evidence is reproducible.

A failed, skipped, flaky, timed-out, cancelled, informational-only, or `continue-on-error` mandatory check is **not green**.

No result may be promoted in strength. In particular:

- unobserved effect != verified effect;
- child verification `ok=false` cannot become parent `ok=true`;
- queued work != executed work;
- claimed work != completed work;
- completed HTTP request != verified business effect;
- capability advertised != capability exercised;
- benchmark calibration != measured benchmark;
- local simulation != live acceptance.

## 2. Evidence tiers

| Tier | Name | Required evidence |
|---|---|---|
| E0 | Structural | parse/lint, generated-artifact drift, schema integrity, manifest integrity, duplicate detection |
| E1 | Unit/contract | deterministic unit tests for executors, verifiers, state transitions and serialization |
| E2 | Formal finite model | exhaustive finite-state checks for state machine, retry budget, idempotency identity and verification lattice |
| E3 | Real database | actual MySQL/MariaDB executes schema, claims, leases, recovery, retries, dead-letter and concurrency |
| E4 | Real WordPress | plugin installs/upgrades/activates on supported WordPress/PHP combinations and executes representative mutations with read-back |
| E5 | Real Chrome | unpacked extension loads in real Chrome; pairing, owned-tab execution, restart/reconnect, auth-challenge and evidence flows are exercised |
| E6 | Real remote MCP/OAuth | remote HTTPS endpoint exercised against MCP 2026-07-28 behavior and OAuth authorization-code + PKCE lifecycle |
| E7 | Soak/chaos | long-running workload with failure injection, progress watchdogs, recovery bounds and zero silent stalls |
| E8 | Agent benchmark | frozen same-model task corpus with measured success/evidence/efficiency; no synthetic calibration substituted for runs |

A product can be a release candidate with E0–E5. It cannot be production-proven without E6–E8.

## 3. MCP 2026-07-28 conformance baseline

The modern endpoint targets MCP `2026-07-28`. Mandatory modern-wire checks:

1. Modern requests are stateless at protocol level. No modern request may require `initialize`, `initialized`, `Mcp-Session-Id`, sticky sessions or hidden transport state.
2. Every modern request validates `MCP-Protocol-Version` and the self-describing request metadata it consumes.
3. Streamable HTTP routing validates `Mcp-Method`; named operations validate `Mcp-Name` where required.
4. `server/discover`, where exposed, must accurately describe implemented server capabilities and extensions.
5. `tools/list`, `prompts/list`, `resources/list`, and `resources/read` responses that are cacheable must provide deterministic ordering and correct cache hints (`ttlMs`, `cacheScope`).
6. Tool `inputSchema`/`outputSchema` behavior must be compatible with JSON Schema 2020-12. External `$ref` dereferencing must never occur implicitly. Validation depth/time must be bounded.
7. Missing/invalid resources use standard JSON-RPC invalid-params semantics required by the modern revision rather than stale custom codes.
8. Multi-round-trip input requirements must be represented in-band. Modern requests must not depend on an unsolicited server→client request channel.
9. Deprecated Roots/Sampling/Logging must not be newly required by the modern runtime.
10. Tasks, if advertised, are treated as an extension and must follow the extension lifecycle rather than the obsolete experimental core lifecycle.
11. OAuth authorization responses must implement the issuer hardening required by the current revision; credentials are issuer/resource bound.
12. Dynamic Client Registration, if retained for compatibility, is treated as deprecated compatibility. New architecture must be compatible with Client ID Metadata Documents / current MCP authorization direction.
13. Authorization-code flow requires PKCE S256, exact redirect validation, resource binding, scope enforcement, code single-use, access-token expiry, refresh-token rotation and replay rejection.
14. Legacy MCP revisions may remain supported, but their session/handshake behavior must not contaminate the modern path.
15. Tool list size is a hard host-compatibility invariant. Withheld tools remain discoverable/executable through explicit routers and must not silently disappear.

## 4. Durable runtime invariants

These invariants are release blocking.

### 4.1 Liveness

- A healthy claim/resume/yield/browser wait MUST NOT consume a failure retry budget.
- Retry counters count failures/recovery attempts, not worker scheduling events.
- A non-terminal job must always be either claimable now, claimable after a bounded `available_gmt`, waiting on a live child with a deadline, or explicitly blocked by a named external condition.
- A `READY` job that can never be claimed is a P0 liveness defect.
- Every wait state has a timeout/recovery path.
- A no-progress watchdog must detect repeated cycles with no monotonic checkpoint/evidence change.

### 4.2 State machine

- Job and browser-task states each have an explicit `from -> to` relation.
- Terminal states cannot transition.
- `COMPLETED` is reachable only from an execution state after durable result persistence.
- `WAITING_FOR_BROWSER` is reachable only with a durable child task identity and deadline.
- Every transition is compare-and-swap/fenced against stale owners.
- Transition tests exhaust every state pair.

### 4.3 Leases and fencing

- Lease acquisition is atomic.
- A stale worker cannot checkpoint, complete, fail, cancel or release work after losing the lease.
- Heartbeat extension fails once lease expiry has passed.
- Recovery of expired leases is executable against real databases.
- Reclamation never deletes or overwrites a newer owner’s lock/lease.

### 4.4 Idempotency

Every side-effecting logical step has a deterministic durable identity derived from stable logical inputs, such as:

`site/owner + mission + plan_hash + step_id + logical_attempt`

The following crash window must be safe:

1. parent decides to dispatch child;
2. child row is persisted;
3. process crashes before parent checkpoint;
4. parent lease expires;
5. parent resumes.

The resume MUST recover/reuse the same logical child rather than create an uncorrelated duplicate.

Replay tests must cover completed, failed, cancelled, expired, in-flight and stale-callback children.

### 4.5 Verification monotonicity

Verification is a lattice. Parent evidence strength may equal or weaken child evidence; it may never strengthen it without new independent evidence.

Minimum final receipt:

- executed;
- mutated;
- verification state (`verified`, `degraded`, `unverified`);
- blocking/nonblocking;
- evidence hashes/references;
- unverified step IDs;
- degraded reasons;
- timestamps;
- correlation/mission/job IDs.

If any required effect is unverified, top-level `verification.ok` cannot be true.

### 4.6 Deadlines and cancellation

- Every declared `timeout_seconds` is enforced, not decorative metadata.
- Worker budget and step deadline are independent.
- Browser child deadline, parent lease deadline and mission expiry are explicit.
- Cancellation is propagated to children where safe and parent reconciliation is deterministic.
- Timeouts produce named retry/terminal semantics, never silent continuation.

### 4.7 Recovery and compensation

- Retryable errors retry with bounded exponential backoff + jitter.
- Retry exhaustion produces dead-letter evidence.
- Non-retryable technical failures terminate immediately.
- Partial mutations either compensate/rollback or report exact partial state.
- Recovery never reports success merely because a recovery command ran.

## 5. Required runtime matrices

### PHP

Minimum supported PHP must be exercised directly. CI should cover at least:

- 8.0
- 8.1
- 8.2
- 8.3
- 8.4
- 8.5 where runner/tooling support is stable

All plugin PHP files must lint on the minimum runtime, and runtime smokes must execute on both minimum and current PHP.

### JavaScript / Node

Browser-Agent deterministic tests should cover maintained Node lines used by CI tooling, currently at least Node 22 and 24. Browser behavior itself must be tested in real Chrome, not inferred from Node tests.

### Python

Audit/benchmark scripts should execute on maintained interpreter lines used operationally, at least 3.11–3.14 when supported by dependencies.

### Databases

Real SQL execution must cover supported MySQL-compatible engines, at minimum:

- MariaDB 10.11 LTS-class line;
- MariaDB 11.x;
- MySQL 8.0;
- MySQL 8.4 LTS.

Schema install, upgrades, queue claims, lease recovery, idempotency collisions, dead-letter paths, scheduler compare-and-swap and concurrent workers are mandatory.

### Operating systems / architectures

Pure runtime/tooling tests should use free public-repository standard runners to broaden coverage:

- Ubuntu x64;
- Windows x64;
- macOS arm64/current;
- Ubuntu arm64 for architecture-sensitive tooling where practical.

Platform-specific failures must not be hidden by a single-OS canonical build.

## 6. Real WordPress acceptance

At minimum:

1. Fresh install on minimum supported WordPress and latest stable WordPress.
2. Plugin activation with PHP minimum and current.
3. Upgrade from previous schema/release fixture.
4. Deactivate/reactivate without losing OAuth grants, pairing, durable jobs or required state.
5. Representative read-only operations.
6. Representative WordPress mutations with DB read-back and public front-end read-back.
7. WooCommerce flows when WooCommerce is present: product update, rollback, inventory, visibility/featured/virtual/downloadable fields, partial-failure handling.
8. Cron/Action Scheduler topology and repair.
9. Object-cache present and absent.
10. Multisite only if claimed supported; otherwise explicit unsupported evidence.

## 7. Real Browser Agent acceptance

Mandatory scenarios:

- extension installs/loads unpacked in real stable Chrome;
- service worker starts and can restart after suspension;
- pair → heartbeat → claim → running → complete;
- Chrome restart and extension restart preserve/recover durable server state;
- owned-tab identity/origin/document binding cannot be crossed;
- tab close reconciles parent immediately;
- stale completion from old lease is rejected;
- raw CDP blocked-method tests remain negative;
- arbitrary JavaScript/cookie export/global-permission escape remains impossible;
- navigation/document replacement invalidates stale element grounding;
- CAPTCHA/MFA/auth challenge becomes explicit human-input-required state, not fake failure/success;
- screenshots/DOM/CDP evidence have correlation IDs and bounded/redacted persistence;
- live streaming signaling stores no media payload server-side;
- offline browser produces degraded/blocked semantics appropriate to the requested action.

## 8. OAuth/security acceptance

Mandatory concurrency and negative tests:

- two concurrent client registrations;
- concurrent token issuance does not lose records;
- concurrent refresh rotation cannot resurrect old refresh tokens;
- reused authorization code rejected;
- wrong verifier rejected;
- wrong redirect rejected;
- wrong resource/audience rejected;
- missing write scope rejected for mutation;
- expired/revoked token rejected;
- issuer mismatch rejected/never minted ambiguously;
- rate limits are atomic enough for the deployment architecture;
- secrets never appear in logs, events, benchmark artifacts or MCP responses.

## 9. Security and supply-chain gates

Mandatory before production proof:

- CodeQL for every supported CodeQL language present in the repository: JavaScript/TypeScript, Python and GitHub Actions workflows;
- PHP security scanning through WordPress Plugin Check plus PHP-specific static analysis/rules; real security findings cannot be globally `continue-on-error`;
- secret scanning / high-entropy credential checks;
- dependency review on pull requests where manifests exist;
- third-party GitHub Actions pinned to immutable commit SHAs for release-critical workflows;
- workflow `permissions` explicitly least-privilege;
- every job has `timeout-minutes`;
- no `curl | sh` / equivalent unauthenticated bootstrap in release paths;
- dependency/tool versions pinned or recorded;
- SBOM/provenance/integrity generated for release artifacts;
- build artifacts regenerated from source and diff-clean before release.

Exceptions require an explicit ledger entry containing finding/rule, exact scope, justification, owner, date, expiry/review date and compensating control. Blanket suppressions are forbidden.

## 10. Failure-injection catalogue

The nightly/soak program should inject, where technically possible:

- worker killed after child persistence but before parent checkpoint;
- worker killed after mutation but before verification persistence;
- browser killed during RUNNING;
- browser returns duplicate completion;
- stale lease callback after recovery;
- DB disconnect during transaction;
- deadlock/lock timeout;
- transient 429/500/502/503/504;
- DNS/network timeout;
- provider malformed JSON;
- disk write failure for file-backed stores;
- unavailable persistent object cache;
- clock skew around expiry/schedule boundaries;
- duplicate scheduler runners;
- concurrent OAuth writes;
- concurrent content transaction writers;
- stale destination during migration;
- partial rollback failure;
- verification source unavailable after successful mutation.

Every injected failure must have an expected terminal/recovery state and maximum recovery time.

## 11. No-progress / infinite-loop policy

A run that spends 30 minutes without useful work is a release-blocking defect, not an acceptable timeout.

Every long-running mission records a monotonic progress signature based on at least:

`state + step_index + checkpoint_hash + child_task + evidence_hash + failure_count`

Rules:

- repeated identical signature beyond a bounded threshold => `STUCK` / technical error or deterministic recovery;
- no durable progress for a configured wall-clock bound => watchdog event;
- watchdog event names the blocking resource and last successful checkpoint;
- recovery may retry only when it changes a causal condition or consumes an explicit retry budget;
- identical retry loops are bounded;
- CI tests include workloads with more browser steps than the default retry budget to prove healthy resume cannot self-exhaust.

## 12. Benchmarks

### SYSTEM-BENCH

PR validation should run preview benchmarks without mutating canonical history. Nightly should run more repetitions and preserve machine-readable artifacts.

Track at least:

- strict release-validation pass rate;
- capability/tool discovery latency p50/p95/p99;
- tool-list payload/token budget;
- job claim/checkpoint/release throughput;
- stale-lease recovery latency;
- browser dispatch latency;
- verification latency;
- queue depth and oldest undispatched age;
- Operational Twin bounded-sync throughput;
- memory/runtime ceilings;
- failure recovery success rate.

A release must have an explicit regression budget. A statistically meaningful regression outside budget blocks release even if functional tests pass.

### AGENT-BENCH

The existing 500-task target is treated as a production-proof gate, not marketing calibration:

- 200 public/core;
- 150 private holdout;
- 100 procedurally generated;
- 50 rotating adversarial.

Dimensions include reason, code, act, computer, orchestrate, recover, learn and operate.

A task receives success credit only when required effects are actually verified. Tool/capability count earns no score.

No `production_proven=true` while the measured AGENT-BENCH corpus/history is absent.

## 13. Soak requirements

Before production proof:

- repeated synthetic/runtime soak in CI with progress assertions;
- real WordPress + DB soak;
- real Browser Agent soak;
- remote MCP/OAuth soak;
- at least one 24-hour external H24 acceptance run for the release candidate;
- zero unexplained stalls;
- zero silent false-success receipts;
- zero unreconciled terminal browser children;
- zero permanently unclaimable non-terminal jobs;
- bounded queue growth;
- all injected recoverable faults recovered inside documented SLO.

## 14. Release evidence manifest

Each candidate should produce a machine-readable release-evidence document containing:

- commit SHA;
- component versions;
- generated registry hashes/counts;
- workflow run IDs;
- matrix results;
- benchmark record IDs;
- real WordPress versions tested;
- DB engines tested;
- Chrome version tested;
- MCP protocol revision;
- OAuth scenarios;
- soak duration;
- failure-injection results;
- known exceptions;
- `production_proven` boolean.

The boolean is derived from evidence, never manually asserted against failing/missing mandatory fields.

## 15. CI cadence

### Every pull request

- E0/E1 structural/unit gates;
- finite-state model;
- runtime invariant audit;
- minimum/current PHP;
- Node Browser-Agent tests;
- Python audits;
- real DB queue/runtime integration;
- MCP conformance audit;
- generated-surface drift;
- security scans;
- short SYSTEM-BENCH preview;
- regression comparison.

### Nightly

- expanded PHP/Node/Python/DB matrices;
- repeated Browser-Agent tests;
- real Chrome load/evidence smoke;
- longer benchmarks;
- deterministic stress/soak;
- concurrency/failure-injection scenarios.

### Release candidate / tag

- all above;
- clean artifact rebuild;
- SBOM/provenance/attestation;
- real WordPress install/upgrade acceptance;
- live Chrome pairing/restart acceptance;
- remote MCP 2026-07-28 + OAuth acceptance;
- AGENT-BENCH measured corpus;
- H24 soak evidence;
- release evidence manifest.

## 16. Current policy for RP Connector

Until the mandatory runtime defects and missing live evidence are closed, the correct declaration remains a release candidate / hardening build, not production-proven.

The hardening program should reduce capability growth priority to near zero until the durable execution kernel can prove: **execute, observe, recover, progress, verify, terminate** under real failure conditions.
