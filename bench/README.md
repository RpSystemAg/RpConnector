# PRSTUDIO daily benchmarks

Two scores are deliberately separate.

- `PRSTUDIO-SYSTEM-BENCH` measures infrastructure health: the 250-question checkpoint, strict release validation, normalized checkpoint efficiency, capability-search latency, and a bounded 500-entity Operational Twin sync. It is bounded to 0–100 and writes `SYSTEM-BENCH-HISTORY.ndjson`.
- `PRSTUDIO-AGENT-BENCH` measures end-to-end task equivalence with the model held constant. Tool/capability counts earn no points. Its absolute index uses a frozen frontier reference at 100 and is intentionally unbounded, so PR Studio can score above 100. A second frontier-relative index rescales the current measured leader to 100.

The product owner's Day Zero calibration is recorded as 2.00/100 in `AGENT-BENCH-DAY-ZERO.json`, explicitly marked unmeasured. `AGENT-BENCH-HISTORY.ndjson` remains empty until a real 500-task corpus and same-model reference episodes exist. The status command fails closed instead of turning the calibration into fake evidence:

```powershell
python .\bench\agent-bench-status.py
```

The required corpus split is 200 public/core, 150 private holdout, 100 procedurally generated, and 50 rotating adversarial tasks across reason, code, act, computer, orchestrate, recover, learn, and operate. A successful task is the hard multiplier for every quality/efficiency component.

All formulas are versioned. A formula change requires a new version and an entry in `FORMULA-CHANGELOG.md`; it starts a non-comparable segment. Historical records are never recalculated.

## Commands

Preview without changing history:

```powershell
$env:PRSTUDIO_PHP = 'C:\path\to\php.exe'
$env:PRSTUDIO_PYTHON = 'C:\path\to\python.exe'
& $env:PRSTUDIO_PYTHON .\bench\prstudio-bench.py --mode preview --runs 5 --php $env:PRSTUDIO_PHP
```

Canonical daily occurrence:

```powershell
& $env:PRSTUDIO_PYTHON .\bench\prstudio-bench.py --mode daily --runs 5 --php $env:PRSTUDIO_PHP
```

Daily mode permits one record per Europe/Rome calendar day. Concurrent or catch-up duplicates are rejected under an OS file lock. Every command has fresh evidence under `bench/runs/<run-id>/`; the generated proposal identifies the weakest questions but is deliberately non-mutating.

## Night automation boundary

The reliable executor remains the external H24 worker/system scheduler. A ChatGPT scheduled action can wake the task, run SYSTEM-BENCH, check AGENT-BENCH readiness, compare fresh records, and try to falsify the hypothesis that the current candidate is better. It may validly conclude `NO CHANGE`. It must not edit a formula/reference, exclude tasks, suppress failed checks, or apply a code/site mutation without an explicit write scope and execution lane.

This local benchmark does not prove a live WordPress upgrade, Browser pairing/restart, OAuth against ChatGPT, provider integrations, or a 24-hour soak. `production_proven` therefore remains `false`.

## Tool-surface harness (2026-08-19)

`bench/tool-surface-harness.py` loads the REAL runtime tool catalog
(`PRSTUDIO_UC_MCP_V5::tools()` via PHP) and reports per tool: surface bytes,
approx tokens, schema conformity (valid samples accepted 100%, invalid
rejected 100% on closed schemas), direct dispatch coverage. It runs the
order-variance protocol (same workload, shuffled orderings → identical Law 9
advertised surface, variance must be 0) — Fragility of Self-Improving Agents
and Task-Aware Harness Provisioning (arXiv week 2026-08-13..19). It is a
release-equation column in `full-surface-execution.yml` and
`production-certification.yml`; the report lands in
`quality/tool-surface-report.json`.
