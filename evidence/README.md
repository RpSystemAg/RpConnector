# Live production evidence

This directory is reserved for **real acceptance evidence**, not generated placeholders.

The release gate intentionally accepts no prose-only claim and no synthetic `ok: true` fixture. A production candidate must provide machine-readable receipts for the exact release commit under `evidence/live/`.

Required receipts:

- `wordpress-live.json` — real WordPress/WooCommerce install, upgrade, mutation/read-back acceptance;
- `browser-live.json` — paired real Chrome Browser Agent, restart/reconnect and representative execution/evidence acceptance;
- `remote-mcp-oauth.json` — public HTTPS MCP `2026-07-28` and OAuth/PKCE lifecycle acceptance against the deployed candidate;
- `h24-soak.json` — continuous external H24 soak evidence with bounded recovery and no unexplained stalls.

Each receipt must minimally contain:

```json
{
  "schema": "rpconnector-live-evidence/1",
  "commit_sha": "<40-char release commit>",
  "ok": true,
  "started_at": "<RFC3339>",
  "completed_at": "<RFC3339>",
  "environment": {},
  "checks": [],
  "evidence": {
    "summary": "<what was actually observed>",
    "artifacts": []
  }
}
```

## Evidence rules

1. Never commit credentials, OAuth codes/tokens, Browser pairing secrets, cookies, personal data, raw session material or unredacted screenshots.
2. `commit_sha` must match the exact source commit being certified.
3. `ok=true` is valid only if every mandatory check for that receipt passed. Skipped required checks make the receipt non-passing.
4. Evidence must identify real versions/environment (WordPress, PHP, DB, Chrome, MCP protocol) and timestamps.
5. A Browser/WordPress/MCP response that merely says success is not sufficient; the receipt must record the independent observation used to establish the effect.
6. The 24-hour soak receipt must record duration, workload, progress watchdog statistics, recoveries, dead letters, queue maxima, unexplained stalls and false-success count.
7. `bench/AGENT-BENCH-HISTORY.ndjson` must independently contain measured agent episodes; live receipts do not substitute for the benchmark.
8. Receipts may reference immutable external CI artifacts instead of embedding large logs, provided the reference remains auditable.

`tests/release-evidence-gate.py` enforces that `production_proven=true` can never outrun these receipts and measured AGENT-BENCH evidence.
