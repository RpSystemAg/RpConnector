# Enterprise Schema Migration

Operational tracker only. This file is not a runtime gate, authorization layer, approval mechanism, or replacement for the Suite execution constitution.

## Repository State

Research timestamp: 2026-08-19 19:25:28 +02:00 (Europe/Rome)
Master SHA: `f96662929ff4e9c053ab8f7d55a66bce3aac0220`
Working branch: `enterprise/schema-contracts-restart-20260819`
Branch HEAD before tracker checkpoint: `c0bb95aaec2dfd86395f57594b82b5ba4ede855d`
PR: none for the restart branch. Superseded PR #11 was closed without merge and is not authoritative.

## Standard Baseline

MCP specification checked: YES — official Model Context Protocol 2026-07-28 final specification/release; Tools contract and compatibility/lifecycle guidance checked.
OpenAI/ChatGPT documentation checked: YES — official current MCP/connectors/tool guidance and structured-output/tool-description guidance checked.
JSON Schema dialect checked: YES — official JSON Schema Draft 2020-12 core/validation and release notes checked.
Connector/runtime compatibility checked: YES — RpConnector is a custom PHP/WordPress MCP implementation; internal execution-boundary validator currently implements a deliberate subset of Draft 2020-12 keywords. Enterprise schemas must use supported semantics or extend/test that validator before relying on additional keywords.

Enterprise target:
LATEST COMPATIBLE ENTERPRISE CONTRACT
as of the research timestamp.

Current baseline notes:
- MCP 2026-07-28 is final/GA as of this session and supports full JSON Schema Draft 2020-12 for tool input/output contracts.
- Tool `inputSchema` remains object-rooted; `outputSchema` may describe structured tool output. When an output schema is declared, the server result/structured content must conform.
- Modern keywords are used only when semantically useful and supported by RpConnector's real validation/transport chain; unsupported keywords are not decorative metadata.
- OpenAI guidance favors precise tool descriptions, explicit return fields/types and machine-readable error behavior.

## Inventory

Inventory status: VERIFIED from current master runtime identity/count and generation sources.

Total distinct applicable capabilities: 1364
Completed: 0
In progress: 5
Remaining: 1359
Blocked: 0 — migration completion state forbidden by LAW 14; unresolved selected work remains Current Batch and incomplete.
Exceptions: 0

Input enterprise: 0 / 1364 migration-program verified
Output enterprise: 0 / 1364 migration-program verified
Runtime conformant: 0 / 1364 migration-program verified
Fully verified: 0 / 1364

Inventory source-of-truth and deduplication:
- Runtime authority: `PRSTUDIO_UC_Capability_Registry::document()` in `prstudio-unified-control/includes/class-prstudio-uc-capability-registry.php`.
- Base registry: `prstudio-unified-control/capabilities/capability-registry.json`.
- Native agency/business overlay: `prstudio-unified-control/capabilities/agency-capabilities.json`.
- Runtime deduplicates by canonical capability `id`; `source.tool_name` is an alias lookup and is not counted separately.
- 1295 base capabilities + 69 native overlay capabilities = 1364 canonical capability IDs.
- Base cross-check: 20 base native + 199 legacy direct tools + 1076 legacy actions = 1295.
- Total native after overlay: 89. Total legacy-mapped: 1275.
- Public MCP direct surface: 117 tools. It is a bounded exposure/router surface, not 117 additional semantic capabilities on top of the registry.
- Browser action catalog: 130 actions (119 executable, 11 security-guarded); action declarations/mappings are not double-counted where they resolve to an existing canonical contract.
- Local Studio/toolchain, WordPress, WooCommerce/commerce, OAuth/connectors, social, jobs/runtime, memory/search, Browser, legacy direct tools and legacy actions are in scope when represented by a distinct callable canonical capability contract.
- Internal helpers/adapters without an independently callable consumer-visible semantic contract are implementation paths, not additional capabilities.
- `tests/regenerate-contract-artifacts.py` is used to distinguish canonical runtime definitions from generated registry/search/hot-index artifacts; generated artifacts are regenerated from source and are not treated as independent semantic authorities.

## Ordering Rule

Canonical ordering is frozen for this migration pass:
1. canonical capability ID/name ascending, case-insensitive;
2. if equal, namespace ascending;
3. if still equal, stable source/ID ascending.

Aliases do not receive a second position.

LAW 14 cursor rule: a selected capability cannot be skipped, marked blocked/partial/deferred, or moved to follow-up to advance ordering. The Current Batch remains pinned until all five selected capabilities are VERIFIED.

## Current Batch

1. `agency.status`
2. `browser.inspect`
3. `browser.navigate`
4. `business.data_quality.resolve_conflicts`
5. `business.decision.journal`

Checkpoint guarantee: these exact five were persisted before any capability contract/runtime modification in the restarted migration.

## Completed Capabilities

None yet.

## Blocked / Needs Follow-up

`BLOCKED` is not a permitted migration completion status under LAW 14. Any unresolved defect in a Current Batch capability remains current, incomplete work and must be studied/remediated before the batch cursor can advance.

Current unresolved defects discovered during pre-contract trace:
- `business.data_quality.resolve_conflicts`: registry-declared executor must be traced and, if absent at the authoritative runtime, implemented and verified in this batch.
- `business.decision.journal`: registry-declared executor must be traced and, if absent at the authoritative runtime, implemented and verified in this batch.

These are work items, not blocked statuses.

## Live Retest Queue

None yet. Automated verification and any required live/browser evidence will be distinguished explicitly at batch close.

## Next Batch

Not active and must not begin until all five Current Batch capabilities are VERIFIED.

Provisional canonical successors for drift checking only:
1. `business.goal.registry`
2. `business.outcome.track`
3. `business.scenario.simulate`
4. `commerce.inventory.update`
5. `commerce.product.read`

The final Next Batch list will be fresh-read from the canonical ordering at batch close.

## Session Log

### 2026-08-19 — Restarted Batch 1

- Restarted cleanly from master `f96662929ff4e9c053ab8f7d55a66bce3aac0220` after superseding/closing the earlier unmerged PR #11.
- Added repository LAW 14: Enterprise Capability Contract Migration has zero blocked completion states; selected capabilities remain current until VERIFIED.
- Mirrored LAW 14 in root/local Claude and control-subtree entry points so entering the plugin subtree cannot weaken it.
- Rebuilt inventory from repository sources; did not use previous chat state as authority.
- Re-ran current official MCP/OpenAI/JSON Schema research for this session.
- Current Batch frozen before capability modifications.
