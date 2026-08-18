# PRSTUDIO benchmark formula changelog

## SYSTEM-BENCH 1.1.0 — 2026-08-11

- Supersedes the pre-release 1.0.0 draft before the first canonical history record.
- Keeps infrastructure quality dominant (65 points), strict release validation at 20, normalized checkpoint efficiency at 8, and adds 7 independently measured points for cold/warm capability search and a 500-entity Operational Twin sync.
- Fixes the critical-runtime ceilings before the 15.0 Day One measurement: 250 ms cold capability search, 5 ms warm p95, and 1,500 ms for the bounded 500-entity twin sync. Each metric uses five fresh PHP processes and can only lose, never exceed, its declared weight.
- A future change still requires another version and starts a non-comparable history segment.

## SYSTEM-BENCH 1.0.0 — 2026-08-11

- Introduces the fixed 0–100 formula: 70 points for the 250-question checkpoint, 20 for the strict release validator, and 10 for normalized checkpoint efficiency.
- `PASS` is worth 1, `WARN` 0.5, `FAIL` 0, and `NA` is excluded from the denominator.
- Freezes the 10.0.0 checkpoint reference at 5,202.565967 ms per million item/question evaluations.
- Adds score ceilings for hard failures and release-validator failures, one canonical record per Europe/Rome day, inventory-drop review, and a SHA-256 history chain.

The 1.0.0 formula produced no canonical history record. Any future formula change must add a dated entry here, use a new formula version, and start a non-comparable history segment. Historical records are never recalculated.

## AGENT-BENCH 1.0.0 — 2026-08-11

- Separates agentic task completion from infrastructure health. Capability, tool, class, and checkpoint counts contribute zero direct agent points.
- Anchors the immutable absolute index to a frozen, same-model frontier reference on the same task corpus; the index is intentionally unbounded and may exceed 100.
- Adds a separately dated frontier-relative index whose current leader is 100.
- Requires a 500-task corpus split 40% public/core, 30% private holdout, 20% procedurally generated, and 10% rotating adversarial.
- Makes task success a hard multiplier: an uncompleted task earns no partial points for owning tools or producing plans.

## SYSTEM-BENCH 1.2.0 — 2026-08-11
- Reclassifies `items × questions` as **matrix cells**, not independent test executions.
- Uses strict release validation and counts WARN in validator quality at half weight; SKIP remains explicitly reported and unscored.
- Replaces pseudo-throughput based on all matrix cells with an explicit evidence-coverage component (`applicable_rule_cells / matrix_cells`).
- Emits an explicit infrastructure/contract scope and a separate unmeasured AGENT benchmark marker.
- Historical 1.1.0 records remain immutable and non-comparable to 1.2.0.
