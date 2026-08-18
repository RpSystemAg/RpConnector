# SEO Autopilot campaign tracking — design proposal (v1, pre-implementation)

Status: draft, pending confirmation against a full read of `PRSTUDIO_UC_Memory`, `PRSTUDIO_UC_Idempotency`, `PRSTUDIO_UC_Execution_Lanes`, `PRSTUDIO_UC_Job_Engine`, `PRSTUDIO_UC_Mission_Engine`, `PRSTUDIO_UC_Playbook_Engine` and the capability/autoload wiring (in progress). Items marked **[confirmed]** were read directly tonight; items marked **[proposed]** are the design choice pending that confirmation, not yet implemented.

## A. Files/classes involved

New:
- `includes/class-prstudio-uc-seo-autopilot.php` — `PRSTUDIO_UC_SEO_Autopilot`, the orchestrator. Naming matches the existing `PRSTUDIO_UC_<Name>` convention **[confirmed pattern, seen throughout includes/]**.
- One new table for entity rows (see C).

Touched, not replaced:
- `includes/class-prstudio-uc-store.php` — template for the new table's migration/claim pattern **[confirmed: has `*_table()` helpers, `$wpdb->prepare()`-based claim logic with lease_token/lease_expires_gmt]**.
- `includes/class-prstudio-uc-interventions.php` — read-before-propose, write-after-apply; campaign state and intervention ledger stay two separate, cooperating systems as specified.
- `includes/class-prstudio-uc-mcp-v5.php` — register 3 new tools (`seo.autopilot.status/next/control`), same `self::tool(...)` pattern used by the existing 123 **[confirmed: 123/123 verified matching earlier tonight]**.
- `capabilities/capability-registry.json` (or whatever process generates it — pending agent confirmation of hand-edited vs. generated).
- `RP-STUDIO-CHATGPT-PLUGIN-1.0.0.json` — `tool_names` + `expected_tools`, same drift-check already added to CI.
- SEO skill/instructions doc — one short protocol rule, no state, no IDs (as specified).

## B. Storage to reuse

Two different shapes, two different existing patterns:
- **Campaign record** (1 row per campaign, small, needs a stable named alias like `ACTIVE_SEO_MISSION` → `SEO-MASTER-IDEALMARKET-2026-V1`): **[proposed]** reuse `PRSTUDIO_UC_Memory`'s document/alias mechanism if it supports named documents — this is exactly what an "alias resolving to a mission id" sounds like it's for. Not yet confirmed the API shape supports this; if it doesn't, a single-row table alongside the entities table is the fallback, not a new bespoke memory system.
- **Entity records** (~800-1000 rows, need indexed status lookups and atomic per-row claims): a JSON blob does not give real per-row locking at this cardinality. **[proposed]** new table, see C.

## C. Does this need a new table — yes, proposed schema

`wp_prstudio_uc_seo_campaign_entities`, built with the same dbDelta/schema-version pattern already used by `PRSTUDIO_UC_Store` **[confirmed pattern]**:

- `id` bigint PK, `campaign_id`, `entity_type`, `entity_id`, `entity_key` (generated `entity_type:entity_id`), `canonical_url`, `status`, `priority`, `source_fingerprint`, `issue_revision`, `resolved_issues` (json), `remaining_issues` (json), `claimed_by`, `claim_expires_at`, `operation_id`, `first_seen_gmt`, `last_observed_gmt`, `completed_gmt`, `notes` (json).
- `UNIQUE KEY (campaign_id, entity_type, entity_id)`, `KEY (campaign_id, status, priority)` for the claim-next query.
- Claim uses the same conditional-UPDATE lease pattern as `PRSTUDIO_UC_Store::mark_running()` — no new locking primitive.
- Campaign row (counters, `inventory_hash`, `active` flag) either in `PRSTUDIO_UC_Memory` (if B holds) or a second small table.

## D. Flow

```
"next" / "continua"
  → seo.autopilot.next (MCP, authenticated, same layer as the 123 existing tools)
  → resolve_active_seo_mission()      [Memory alias lookup]
  → reconcile check (cheap: new products since last reconcile? — full reconcile is a separate, explicit operation, not run on every "next")
  → claim_next(): SELECT next PENDING by (priority DESC, entity_id ASC) WHERE status NOT IN (COMPLETED, BLOCKED, REVIEW_REQUIRED) AND (claim_expires_at IS NULL OR claim_expires_at < NOW())
  → atomic UPDATE …WHERE id = ? AND status = ? (CAS, same shape as existing lease claims) → CLAIMED
  → cross-check PRSTUDIO_UC_Interventions::state_of(entity_key) so already-APPLIED issues aren't re-proposed
  → return entity descriptor to caller (NOT the audit itself — research/optimize happens on the ChatGPT/Browser-Agent side, which already has GSC/live-site access this environment does not)
  → caller does the work, then calls a complete/commit capability
  → complete_entity(): verify → update resolved/remaining issues → record intervention → release claim → update campaign counters → COMPLETED or back to PENDING with new issue_revision
```

## Explicit scope line for this pass

This design covers the **persistent tracking/queue system only** (part 1 of the request): campaign state, atomic claim, resume-without-context, ledger integration, reconcile. It defines the capability surface and entity schema that the "agency quality" research/optimization behavior (GSC, Trends, Rank Math live observation, content rewriting) will plug into, but that behavior itself runs live-connected (ChatGPT + Browser Agent against the real site) and isn't something this repo's test suite can execute or verify — it's tested here only as far as the state machine and interfaces are concerned.
