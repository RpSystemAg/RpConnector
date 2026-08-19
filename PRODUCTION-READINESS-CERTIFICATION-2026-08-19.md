# RP Connector — Production Readiness Certification

**Normative date:** 2026-08-19  
**Claim:** `production_ready`  
**Policy:** `quality/production-readiness-policy.json`

## 1. Production-ready is a computed claim

`production_ready=true` is never authored manually and is never inferred from test count, coverage percentage, a successful build, registry size, a green mock suite, or a single GitHub Actions workflow.

The only valid claim is the output of `tests/production-readiness-certifier.py --strict` for the exact release commit.

The certification is fail-closed:

- every mandatory gate must pass;
- every receipt must name the exact release SHA;
- every required check must have its own evidence, artifact or metric;
- skipped, waived or degraded mandatory checks are failures;
- stale receipts are failures;
- real-environment gates require `environment.real=true`;
- H24 evidence must span at least 86,400 seconds;
- evidence artifacts require SHA-256 digests;
- a receipt's own `ok:true` is insufficient unless all of the above are independently satisfied by the certifier.

This is a certification of the tested release envelope, not a proof that software can contain no defect.

## 2. Mandatory production gates

| Gate | What must be demonstrated |
|---|---|
| Source integrity | Exact clean source, reproducible generated contracts, no release placeholders/stubs, immutable CI dependencies. |
| Atomic capability coverage | Every operational item has concrete implementation, exact executable case, independent oracle, official docs and environment proof. |
| Static security | CodeQL/static analysis, Plugin Check, dependencies and secrets have no blocking findings. |
| Auth security | PKCE, exact redirect/resource binding, scopes, refresh rotation, replay/expiry and permission-denial behavior. |
| Browser isolation | Tab/origin/document/session ownership, RAW-CDP restrictions, no arbitrary JS/cookie export, MV3 restart behavior. |
| Real environment matrix | Supported WordPress/WooCommerce/PHP/Chrome plus real MariaDB and MySQL paths. |
| Upgrade/migration | Fresh install, previous-release upgrade, interrupted migration recovery, idempotency and preservation of persistent state. |
| Transaction integrity | Independent readback, zero side effects for invalid input, rollback equality, replay semantics and no false success after partial failure. |
| Concurrency/recovery | Multi-worker exclusion, leases, fencing, CAS, advisory locks, crash windows, retry/dead-letter behavior. |
| Fault injection | DB/network/provider/Chrome/service-worker/process failures are injected and recovery is observed. |
| Load/backpressure | Concurrent MCP load, bounded queues/results/memory, explicit backpressure and declared latency SLO. |
| H24 soak | 24 continuous hours on exact SHA with progress, restart recovery, bounded resources and complete evidence chain. |
| Remote live integration | Real HTTPS MCP, OAuth client, Chrome pairing, real read plus reversible write. |
| Observability/operability | Health, correlation IDs, audit evidence, redaction, classification, alerts and exercised runbook. |
| Backup/restore | Backup, clean restore, state integrity and recorded/reproduced RPO/RTO. |
| Supply chain/release | Immutable actions, locked dependencies, SBOM, checksums, attestation and zero known critical vulnerabilities. |
| Compatibility/degradation | Unsupported/runtime-unavailable cases fail explicitly and never synthesize success. |
| Documentation/claims | Official source map, item traceability, generated counts/versions/endpoints and production claim all agree with source/evidence. |

## 3. Evidence receipt contract

Each gate writes `evidence/production/<receipt>.json`:

```json
{
  "schema_version": 1,
  "gate_id": "transaction_integrity",
  "commit_sha": "<40-hex exact release SHA>",
  "ok": true,
  "started_at": "2026-08-19T10:00:00Z",
  "finished_at": "2026-08-19T10:10:00Z",
  "environment": {
    "real": true,
    "class": "wordpress-woocommerce-mysql",
    "versions": {}
  },
  "checks": [
    {
      "id": "rollback_restores_baseline",
      "ok": true,
      "evidence": "independent baseline/readback equality",
      "metrics": {}
    }
  ],
  "artifacts": [
    {
      "path": "evidence/production/artifacts/transaction-integrity.ndjson",
      "sha256": "<64-hex>"
    }
  ],
  "waivers": [],
  "skipped": []
}
```

Receipts are normalized evidence, not permission to self-certify. Test harnesses must generate them from observed results. Do not commit fabricated passing receipts to make a release gate green.

## 4. Atomic execution is a prerequisite, not a substitute

`tests/atomic-capability-assurance.py --execute` owns item-level proof. For every exact inventory ID it requires:

`inventory -> implementation -> runner -> independent oracle -> negative cases -> documentation -> environment evidence`

The production meta-gate then requires the complete atomic receipt in addition to system-level tests. This prevents both failure modes:

1. a system soak is green while individual capabilities are nominal/stubbed;
2. every capability works alone while the complete system fails under upgrade, load, concurrency or recovery.

## 5. Failure injection requirements

Production certification must test failure windows, not only happy paths. At minimum inject:

- database connection loss and restart;
- process death before/after a side effect and before checkpoint persistence;
- Chrome process death and extension service-worker suspension;
- network interruption;
- remote timeouts, 429 and 5xx;
- expired/stale ownership and leases;
- simultaneous workers contending for the same logical work;
- invalid input immediately before a mutation boundary.

The oracle is the independently observed final state. A retry loop or `ok:true` response is not recovery evidence.

## 6. Upgrade and rollback requirements

A production candidate must be tested as both a fresh install and an upgrade from the supported previous release. The test must prove preservation of settings, OAuth/pairing state, jobs, memory and evidence where the product contract promises persistence.

Interrupted migrations must resume safely, and a failed migration must never be marked complete. Mutating functional tests must restore the baseline or explicitly destroy an isolated test environment after independently verifying final state.

## 7. Load and endurance requirements

Performance claims must be tied to declared workload and SLO. Tests record concurrency, request mix, dataset size, p50/p95/p99, error rate, queue depth, memory and CPU where available.

H24 is separate from a short load test. The H24 receipt must prove at least 86,400 seconds between `started_at` and `finished_at` and include periodic progress plus forced-restart recovery. A CI job that merely sleeps does not count: useful work and invariants must be observed during the interval.

## 8. Release decision

The release decision is binary:

```text
ALL mandatory receipts present
AND exact SHA matches everywhere
AND every required check passes
AND no skip/waiver/degraded mandatory evidence
AND freshness/duration/environment requirements pass
AND atomic operational surface is fully proven
= production_ready=true
```

Anything else is `production_ready=false` with an itemized failure list in `production-readiness-verdict.json`.
