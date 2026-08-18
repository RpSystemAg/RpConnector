# SEO Autopilot campaign tracking — design (v2, confirmed against code)

Status: confirmed via direct code read (Explore agent, 52 file reads). Ready for implementation pending sign-off on the two deviations flagged below.

## A. Files/classes involved

New:
- `includes/class-prstudio-uc-seo-autopilot.php` — `PRSTUDIO_UC_SEO_Autopilot`.
- `wp_prstudio_uc_seo_campaign_entities` table + install migration.
- `tests/php-seo-autopilot-*-smoke.php` (standalone CLI smoke tests, matching the existing pattern — no PHPUnit/wp-env in this repo).

Touched:
- `includes/class-prstudio-uc-autoload.php` — one line in the hand-maintained class map (confirmed: no directory-scan autoloader; a class is invisible until added here).
- `prstudio-unified-control.php` `activate()` — add `PRSTUDIO_UC_SEO_Autopilot::install()` alongside the existing `PRSTUDIO_UC_Interventions::install()` call.
- `capabilities/agency-capabilities.json` — 3 new entries (hand-edited overlay, not the generated `capability-registry.json`).
- `includes/class-prstudio-uc-mcp-v5.php` — 3 new `self::tool(...)` declarations + 3 `case` branches in `call_tool()`.
- `RP-STUDIO-CHATGPT-PLUGIN-1.0.0.json` + the drift-check script (already in CI).
- `tests/validate-release.mjs` — register the new smoke test.
- SEO skill/instructions doc — one protocol line, no state.

## B. Storage

Confirmed by reading `PRSTUDIO_UC_Memory`, `PRSTUDIO_UC_Agency_State`/Operational Twin, `PRSTUDIO_UC_Execution_Lanes`, `PRSTUDIO_UC_Store`, and the Job/Mission engine in full:

- **Entity rows (~800-1000, need atomic claim + indexed status query): new table**, not Memory/Agency_State. Both of those are one JSON blob per site/state, rewritten whole on every mutation, behind one `flock()` shared with unrelated traffic (Memory's lock is sitewide — shared with logging from every other subsystem) — no indexed "next PENDING" query is possible without an O(n) in-PHP scan. Execution_Lanes is explicitly capped at 128 short-TTL lock entries in one `wp_options` row — a mutex layer, not a system of record. `PRSTUDIO_UC_Store` already has the exact right pattern: transactional `SELECT ... FOR UPDATE` → `UPDATE` claim (`claim_next()`/`claim_job_internal()`), lease/heartbeat, stale-claim recovery sweep. New table copies this pattern directly.
- **`ACTIVE_SEO_MISSION` alias**: `PRSTUDIO_UC_Memory::mission(string $id, ?array $state=null)` is built for exactly this — an arbitrary-id alias/state store, low write frequency. Campaign counters stay in the new table (updated atomically alongside claims), not in Memory, so counter updates don't contend with the sitewide lock.
- **`source_fingerprint`**: reuse `PRSTUDIO_UC_Product_Audit::fingerprint()` verbatim as the template (id, modified, name, slug, status, stock, stock_status, image_id + 5 RankMath fields → `PRSTUDIO_UC_Memory::fingerprint()` for canonicalization). Not a new hashing scheme.
- **Initial inventory seeding**: `wc_get_products()` (HPOS-safe), matching `PRSTUDIO_UC_Operational_Twin::commerce_entities()` — not raw `get_posts()`. The bootstrap file already mandates the WC CRUD layer over raw post-table assumptions.

## C. New table — yes, confirmed necessary. Schema unchanged from v1 draft.

## D. Flow — unchanged from v1, one addition

`claim_next()` additionally calls `PRSTUDIO_UC_Interventions::filter_new()` (existing bulk-check method, not a new one) to exclude issues already settled, and can layer `PRSTUDIO_UC_Execution_Lanes::acquire('product:<id>', ...)` as a secondary guard against a concurrent manual/browser edit on the same product — not the primary claim mechanism, which is the table's own row lock.

## Two deviations from the original spec — flagging before writing code, not deciding unilaterally

1. **Tool naming.** The spec proposed `seo.autopilot.status/next/control` (dot notation). All 123 existing MCP tools use `snake_case`, `prstudio_`-prefixed (`prstudio_health`, `commerce_product_audit`, `gsc_url_inspection`) — none use dots. Proposing `prstudio_seo_autopilot_status` / `_next` / `_control` instead, to match the one actual convention in this codebase rather than introduce a second one.
2. **`REVIEW_REQUIRED`/`BLOCKED` semantics.** `AGENTS.md` (the binding constitution) is explicit: "A future feature that can stop a technically valid action is forbidden unless it is the Anti-Crash mutation guard," and verification uncertainty must render as `verified=false, degraded=true` on a completed action, never a blocking/parked state. So `REVIEW_REQUIRED`/`BLOCKED` can only fire on a genuine technical/business inability (e.g. Rank Math ≥90 not reachable without a semantically incorrect change — the spec's own stated exception), never on "the model wasn't confident." Implementing it any looser would violate the constitution this whole plugin already runs under.

## Not yet built, correctly out of scope for this pass

The "Agency Quality" research/optimization behavior (GSC, Trends, Rank Math live observation, content rewriting) — this defines the state machine and capability surface it plugs into, not that behavior itself, which runs live-connected and isn't testable in this environment.
