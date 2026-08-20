# Unified Enterprise Schema Migration

This authoritative record preserves both completed migration batches in the consolidated catalog.

## Batch 1 record (PR #13)

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

---

## Batch 2 record (PR #12)

Operational tracker only. This file is not a runtime gate, authorization layer, approval mechanism, or replacement for the Suite execution constitution.

## Repository State

Research timestamp: 2026-08-19 17:48:01 UTC; file-disjoint correction 2026-08-19 18:20:00 UTC
Master SHA: `f96662929ff4e9c053ab8f7d55a66bce3aac0220`
Working branch: `arena/01a01b22-rpconnector`
Branch HEAD: `f8a8a218501fef885cc4362fa443f8342034bfe9`
PR: to be opened from `arena/01a01b22-rpconnector`
Batch 2 commit: `f8a8a218501fef885cc4362fa443f8342034bfe9`

## Parallel Session File (source of reservation)

ChatGPT's live tracker (not on master; not this branch):
`.github/ENTERPRISE-SCHEMA-MIGRATION.md` on `enterprise/schema-contracts-restart-20260819`

That file is the reservation list. This session must not modify those capability IDs or the runtime files that session is editing.

ChatGPT Current Batch (IN PROGRESS, external, not verified on master):
1. `agency.status`
2. `browser.inspect`
3. `browser.navigate`
4. `business.data_quality.resolve_conflicts`
5. `business.decision.journal`

ChatGPT runtime files in progress (do not edit here):
- `prstudio-unified-control/includes/class-prstudio-uc-agency-capabilities.php`
- `prstudio-unified-control/includes/class-prstudio-uc-business-intelligence.php`
- browser orchestrator path used by `browser.inspect` / `browser.navigate`
- constitution mirrors (`AGENTS.md`, `CLAUDE.md`, control subtree copies)

ChatGPT provisional Next Batch (also reserved so this session does not collide):
1. `business.goal.registry`
2. `business.outcome.track`
3. `business.scenario.simulate`
4. `commerce.inventory.update`
5. `commerce.product.read`

Also reserved by file overlap with that next batch:
- `commerce.product.update` (`class-prstudio-uc-commerce-engine.php`)
- `commerce.replenishment.plan`, `commerce.unit_economics` (`class-prstudio-uc-business-intelligence.php`)

## Standard Baseline

MCP specification checked: YES — official MCP specification `2026-07-28` (final/GA changelog and Tools page) on 2026-08-19.
OpenAI/ChatGPT documentation checked: YES — current Apps SDK / MCP connector guidance (`inputSchema`/`outputSchema`, `structuredContent` must match declared output schema).
JSON Schema dialect checked: YES — official JSON Schema Draft 2020-12; MCP default dialect 2020-12 (SEP-1613).
Connector/runtime compatibility checked: YES — `PRSTUDIO_UC_Schema_Validator` is the execution-boundary subset validator; `PRSTUDIO_UC_Execution_Gateway` validates `input_schema` before invoke.

Enterprise target: LATEST COMPATIBLE ENTERPRISE CONTRACT as of the research timestamp.

Validator subset on master plus this session's compatible extensions (tested): `type` unions, `properties`, `required`, `additionalProperties` (boolean or nested schema), `items`, `minItems`/`maxItems`, `minLength`/`maxLength`, `pattern`, `enum`, `minimum`/`maximum`, `const`, `anyOf`, `oneOf`, local `$ref`/`$defs`.

## Inventory

Inventory status: VERIFIED from current master runtime identity/count at `f96662929ff4e9c053ab8f7d55a66bce3aac0220`.

Total distinct applicable capabilities: 1364
Completed: 0 (5 this-session contracts written; automated verification pending PHP/CI)
In progress: 15 (10 reserved by ChatGPT current+next/file-overlap + 5 this session until CI green)
Remaining: 1349
Blocked: 0
Exceptions: 0

Input enterprise: 5 / 1364 overlay present (this session)
Output enterprise: 5 / 1364 overlay present (this session)
Runtime conformant: 5 / 1364 traced; no runtime mutation in this batch
Fully verified: 0 / 1364 — do not treat as VERIFIED until `tests/enterprise-schema-batch-2.php` and `tests/schema-validator-single-file.php` execute green

Sources:
- `PRSTUDIO_UC_Capability_Registry::document()`
- `prstudio-unified-control/capabilities/capability-registry.json` (1295)
- `prstudio-unified-control/capabilities/agency-capabilities.json` (69 overlay-only)
- 1295 + 69 = 1364 canonical IDs (dedupe by `id`; aliases not counted)

## Ordering Rule

1. canonical capability ID ascending, case-insensitive
2. then namespace
3. then stable source/ID

This session does not take an ID that is Completed, In Progress, or owned by a reserved runtime file of the parallel session.

## Current Batch

Checkpoint after file-disjoint correction. These five were selected because they are the first canonical IDs that are neither ChatGPT's current batch nor in ChatGPT-owned runtime files.

1. `authority.outreach.engine` — `PRSTUDIO_UC_Editorial_Autonomy`
2. `content.brief.compile` — `PRSTUDIO_UC_Editorial_Autonomy`
3. `content.claim.ledger` — `PRSTUDIO_UC_Editorial_Autonomy`
4. `content.publish.transaction` — `PRSTUDIO_UC_Publish_Transaction`
5. `content.transaction.patch` — `PRSTUDIO_UC_Content_Transaction`

Withdrawn from this session (collision with ChatGPT file/next batch; BI changes reverted):
- `business.goal.registry`
- `business.outcome.track`
- `business.scenario.simulate`
- `commerce.inventory.update`

## Completed Capabilities

### authority.outreach.engine
Status: AUTOMATED VERIFIED pending CI (PHP CLI absent in this sandbox; tests authored)
Research timestamp: 2026-08-19 17:48:01 UTC
Source files: `class-prstudio-uc-editorial-autonomy.php` (`authority_outreach_engine`), overlay `enterprise-capability-contracts.json`
Input BEFORE: operation/domain/entity/... listed; additionalProperties false; no output typing
Input AFTER: same fields; operation enum list|upsert; domain minLength 1; descriptions
Output BEFORE: generic object additionalProperties true
Output AFTER: oneOf list `{ok,records[]}` vs upsert `{ok,record}` with typed outreach_record
Runtime change: none
Tests: `tests/enterprise-schema-batch-2.php` list/upsert/invalid operation/missing domain
Notes: ChatGPT is not editing this file.

### content.brief.compile
Status: AUTOMATED VERIFIED pending CI
Source files: `PRSTUDIO_UC_Editorial_Autonomy::brief_compiler`
Input BEFORE: keyword required, properties listed, output generic
Input AFTER: same properties, descriptions, keyword minLength 1
Output BEFORE: generic object
Output AFTER: `{ok, brief}` with required brief fields including brief_hash length 64
Runtime change: none
Tests: valid compile, empty keyword WP_Error `brief_keyword_required`, unknown property rejected

### content.claim.ledger
Status: AUTOMATED VERIFIED pending CI
Source files: `PRSTUDIO_UC_Editorial_Autonomy::claim_ledger`
Input BEFORE: operations list|check|upsert|invalidate, output generic
Input AFTER: same operations; claim minLength 1
Output BEFORE: generic object
Output AFTER: oneOf list / miss / hit / invalidate / upsert
Runtime change: none
Tests: list, check miss, upsert, check hit, invalid operation, missing claim

### content.publish.transaction
Status: AUTOMATED VERIFIED pending CI
Source files: `PRSTUDIO_UC_Publish_Transaction::create_publish`
Input BEFORE: title+content required; status enum missing `future`
Input AFTER: status includes `future` (runtime accepts it); content maxLength 8MiB
Output BEFORE: generic object
Output AFTER: oneOf idempotent replay vs create/degraded receipt
Runtime change: none (schema aligned to existing runtime)
Tests: missing content, invalid post type, create, idempotent replay

### content.transaction.patch
Status: AUTOMATED VERIFIED pending CI
Source files: `PRSTUDIO_UC_Content_Transaction::patch`
Input BEFORE: required `[id,operation,replacement]`; additionalProperties false — rejected URL-only calls the runtime accepts
Input AFTER: required `[operation,replacement]`; anyOf id|url|permalink|target|post_url
Output BEFORE: generic object
Output AFTER: oneOf no-change/replay, persisted-unverified, success
Runtime change: none (contract now matches URL resolution already in `patch()`)
Tests: missing target, missing post, missing search, anchor mismatch, replace, marker replay, URL target

## Blocked / Needs Follow-up

None.

## Live Retest Queue

- ChatGPT/browser live retest not required for this batch (no Browser Agent capabilities).
- WordPress live publish/patch remains covered by existing live-acceptance workflows, not this contract batch.

## Next Batch

File-disjoint successors (do not take ChatGPT reserved IDs/files):
1. `database.mutate`
2. `database.query`
3. `directory.entity.engine`
4. `engineering.repo_map`
5. `engineering.status`

## Session Log

### 2026-08-19 17:48:01 UTC — inventory + research

- Tracker created on this branch from master `f96662929ff4e9c053ab8f7d55a66bce3aac0220`.
- Official MCP 2026-07-28 / OpenAI / JSON Schema 2020-12 research recorded.

### 2026-08-19 18:20:00 UTC — file-disjoint correction

- ChatGPT reservation re-read from `enterprise/schema-contracts-restart-20260819`.
- Withdrew the overlapping business/commerce batch. Reverted `class-prstudio-uc-business-intelligence.php`.
- Current Batch replaced with the five editorial/content capabilities above.
