# Enterprise Schema Migration

Operational tracker only. This file is not a runtime gate, authorization layer, approval mechanism, or replacement for the Suite's existing execution/verification laws.

## Repository State

Research timestamp: pending current-session standards research (checkpoint intentionally written first)
Master SHA: f96662929ff4e9c053ab8f7d55a66bce3aac0220
Working branch: `enterprise/schema-contracts-batch-1-20260819`
Branch HEAD at checkpoint base: f96662929ff4e9c053ab8f7d55a66bce3aac0220
PR: none yet; unrelated existing PR #9 is not this migration branch

## Standard Baseline

MCP specification checked: pending current-session official research
OpenAI/ChatGPT documentation checked: pending current-session official research
JSON Schema dialect checked: pending current-session official research
Connector/runtime compatibility checked: repository runtime inspected; standards compatibility research pending

Enterprise target:
LATEST COMPATIBLE ENTERPRISE CONTRACT
as of the research timestamp.

## Inventory

Inventory status: VERIFIED for canonical runtime identity/count and source decomposition at master `f96662929ff4e9c053ab8f7d55a66bce3aac0220`.

Total distinct applicable capabilities: 1364
Completed: 0
In progress: 5
Remaining: 1359
Blocked: 0
Exceptions: 0

Input enterprise: 0 / 1364 migration-program verified
Output enterprise: 0 / 1364 migration-program verified
Runtime conformant: 0 / 1364 migration-program verified
Fully verified: 0 / 1364

Inventory source-of-truth and deduplication:
- Runtime authority: `PRSTUDIO_UC_Capability_Registry::document()` in `prstudio-unified-control/includes/class-prstudio-uc-capability-registry.php`.
- Base generated registry: `prstudio-unified-control/capabilities/capability-registry.json` (blob `797038ea1dc8b76bb9ee9bf5522fb62985c9cdf3`).
- Native overlay: `prstudio-unified-control/capabilities/agency-capabilities.json` (blob `cc42d08f7239b985ecd28fce21364db2ece05737`).
- Runtime deduplicates by canonical `id`; `source.tool_name` is an alias lookup and is not counted as a second capability.
- 1295 base capabilities + 69 native overlay capabilities = 1364 canonical IDs.
- Base decomposition cross-check: 20 base native + 199 legacy direct tools + 1076 legacy actions = 1295.
- Total native after overlay: 89. Total legacy-mapped: 1275.
- Public MCP direct surface: 117 tools. This is a bounded surfaced subset/router layer, not an additional 117 semantic capabilities on top of the registry.
- Browser action catalog: 130 actions (119 executable, 11 security-guarded). These are tracked as Browser/action execution declarations and mappings; they are not double-counted where they resolve to an existing canonical capability contract.
- Local Studio/toolchain, WordPress, WooCommerce/commerce, OAuth/connectors, social, jobs/runtime, memory/search, Browser, legacy direct tools and legacy actions are all in scope when represented by a canonical callable capability ID.
- Internal helpers/adapters that are not independently callable and have no distinct consumer-visible semantic contract are inventoried as implementation paths, not additional capabilities.

Generated-artifact rule:
- `tests/regenerate-contract-artifacts.py` proves that direct-tool contracts come from canonical PHP tool definitions and legacy-action metadata comes from the action catalog/runtime authority; generated registry/search/hot-index files must be regenerated, not hand-edited as semantic sources.

## Ordering Rule

Canonical ordering is frozen for this migration pass:
1. lowercase canonical capability `id`, ascending bytewise/case-insensitive lexical order;
2. if a canonical name ever ties, namespace ascending;
3. if still tied, stable source identity/ID ascending.

Aliases never receive a second position. A blocked item keeps its status but the next batch proceeds to the next canonical ID. The ordered inventory is deterministically reconstructed from the 1364 canonical runtime IDs at the pinned source state; batch checkpoints below persist the progression.

## Current Batch

1. `agency.status`
2. `browser.inspect`
3. `browser.navigate`
4. `business.data_quality.resolve_conflicts`
5. `business.decision.journal`

Checkpoint rule: these five were selected and persisted before any capability contract modification in this program.

## Completed Capabilities

None yet.

## Blocked / Needs Follow-up

None yet.

## Live Retest Queue

None yet.

## Next Batch

To be recalculated from the canonical ordering after this batch is fully tested and the tracker is fresh-read. Provisional ordering immediately after the Current Batch begins with `business.goal.registry`, `business.outcome.track`, `business.scenario.simulate`, followed by `commerce.*`; final five will be written only at batch close after drift verification.

## Session Log

### 2026-08-19 — Batch 1 checkpoint

- Master fresh-read: `f96662929ff4e9c053ab8f7d55a66bce3aac0220`.
- Dedicated branch created from exactly that SHA.
- Existing PR #9 is unrelated and was not reused.
- Tracker did not previously exist.
- Canonical inventory identity/count verified before selecting the batch.
- Standards research and all five capability traces/tests remain to be completed in this same session.
