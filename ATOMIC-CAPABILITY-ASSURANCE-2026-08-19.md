# RP Connector — Atomic Capability Assurance Protocol

**Normative date:** 2026-08-19  
**Scope:** every MCP tool, WordPress Ability, catalog capability/action, Browser Agent action, internal executable router target and externally-advertised operation.

## 1. Purpose

Inventory is not evidence. A capability is not operational merely because it appears in JSON, a tool list, generated documentation, a PHP array, a registry, a class name or a UI.

Every advertised executable item MUST have a one-to-one evidence record connecting:

`declaration -> concrete implementation -> individual executable case -> independent oracle -> official upstream documentation -> runtime evidence`

No aggregate suite may substitute for an item-level result.

## 2. Atomic evidence grades

| Grade | Meaning | Minimum evidence |
|---|---|---|
| AE0 | Declared | Unique inventory ID exists. |
| AE1 | Implemented | Concrete source symbol/file is reachable from the declaration; implementation is not a stub or unresolved thin adapter. |
| AE2 | Individually testable | Unique test case ID exists for this exact item; fixture, arguments, timeout and expected terminal class are declared. |
| AE3 | Functionally proven | Positive execution succeeds and an independent oracle observes the claimed result. The executor's own `ok:true` is never sufficient evidence. |
| AE4 | Behaviorally hardened | Required negative, permission, retry, idempotency, rollback, concurrency and/or ownership cases pass according to annotations. |
| AE5 | Document-traced | RP behavioral contract and every material upstream platform primitive are tied to official/normative documentation. |
| AE6 | Environment proven | Item passes in the real environment it requires: WordPress/WooCommerce, real SQL, real Chrome, remote HTTPS/OAuth/provider, as applicable. |

`operational_count` MUST count only items that reach the grade required by their environment, never raw inventory size.

## 3. Mandatory per-item test contract

Every executable item receives a stable `atomic_case_id` and MUST declare:

- exact inventory ID and kind;
- implementation file and symbol/handler/delegate chain;
- execution environment (`pure`, `wordpress`, `woocommerce`, `database`, `browser`, `remote_provider`);
- setup fixture;
- exact valid input;
- maximum wall-clock time;
- expected terminal outcome;
- independent observation/oracle;
- required negative cases;
- whether the item mutates state;
- rollback strategy and equality oracle when mutating;
- idempotency expectation;
- permission/authentication expectation;
- concurrency expectation when shared state exists;
- required official documentation references;
- evidence artifact/receipt identity.

A wildcard test name is not item-level coverage. Templates may generate cases, but the generated report MUST contain one independently addressable result for every inventory ID.

## 4. Test classes by behavior

### 4.1 Read-only WordPress/WooCommerce

For each item:
1. build a known fixture;
2. execute through the public runtime path, not only the underlying PHP method;
3. read the same state using an independent WordPress/WooCommerce primitive;
4. compare schema and semantic values;
5. repeat with missing entity, empty result and denied permission.

### 4.2 Mutating WordPress/WooCommerce

For each item:
1. capture an independent baseline;
2. execute one exact mutation;
3. read back independently;
4. verify every claimed changed field;
5. replay the same logical request and check declared idempotency semantics;
6. run at least one invalid payload and prove zero side effects;
7. rollback;
8. independently prove equality with baseline.

A self-reported `verified:true` is not an oracle.

### 4.3 Browser actions

For each action:
1. start a fresh owned Chrome profile and deterministic local fixture page;
2. pair the exact extension commit;
3. execute the exact action;
4. observe through DOM/CDP/screenshot evidence independent of the executor's success flag;
5. test wrong tab, wrong origin/document, stale ownership/session and forbidden raw-CDP paths where relevant;
6. enforce an action deadline and a no-progress deadline;
7. restore or discard the profile.

### 4.4 Database/runtime operations

Use real MariaDB and MySQL. Each atomic case MUST exercise the real SQL path and, where relevant, collision, lease expiry, retry, fencing and crash-window behavior.

### 4.5 Remote providers

Mocked or local contract success is AE3 at most. AE6 requires an exact-commit live receipt from the real remote service or a provider-supported sandbox that exercises the real protocol. Auth challenges may be classified separately but cannot be silently counted as successful execution.

## 5. Stub and virtual implementation detection

File size alone is only a signal. The gate MUST inspect implementation substance.

A file/symbol is release-blocking when any applicable condition is true:

- declaration has no reachable concrete source symbol;
- implementation target exists only in generated inventory/docs/tests;
- function/method body is empty or effectively `return true`, `return []`, `TODO`, `not_implemented`, placeholder success or equivalent;
- a write capability has no reachable mutation/delegation path;
- a read capability has no reachable source/query/delegation path;
- a declared handler/callback/delegate target does not exist;
- executable source uses `example.*`, dummy endpoints or fixture-only identifiers outside test code;
- a thin file (<1 KiB or very low executable token count) has no concrete tested delegate;
- success is returned after swallowed critical exceptions without evidence;
- an action is reachable only from comments/strings, not an executable registration/dispatch path.

Small, valid adapters are permitted only when the ledger records their delegate chain and the terminal delegate has its own evidence.

## 6. Documentation traceability

Every item MUST carry two documentation dimensions when applicable:

1. **RP normative behavior** — what RP Connector promises for this exact operation, including terminal states, side effects, verification and rollback.
2. **Official upstream basis** — normative/official documentation for the platform primitives it relies on.

Approved upstream authority classes include:

- Model Context Protocol official specification;
- WordPress Developer Reference / Common APIs Handbook / Abilities API;
- WooCommerce Developer Documentation;
- Chrome for Developers Extensions reference;
- IETF/RFC Editor OAuth standards and BCPs;
- PHP manual for material runtime behavior;
- OpenAI official platform documentation only for OpenAI-specific integration behavior.

Blog posts, Stack Overflow, vendor summaries and generated AI text cannot satisfy a normative source requirement.

When a custom RP operation has no external specification, that is recorded explicitly. It still requires RP normative documentation plus official references for every material upstream API family used by its implementation.

## 7. Claim accounting

Release metadata MUST expose at least:

- `inventory_count`
- `implemented_count`
- `individually_tested_count`
- `functionally_proven_count`
- `document_traced_count`
- `live_verified_count`
- `operational_count`
- `blocked_count`
- `untested_count`

The product MUST NOT market `inventory_count` as operational capability count.

## 8. CI execution model

Approximately 1,400 items are not represented as 1,400 GitHub Actions jobs. The atomic runner deterministically shards inventory IDs across a bounded worker matrix. Each shard still emits one result object per item and one stable case ID per item.

Failures are item-addressable and reports are mergeable into a complete ledger.

## 9. Release invariant

A release claiming an item as operational MUST satisfy:

`declared && implemented && individual_case && independent_oracle && required_negative_cases && docs_traced && required_environment_evidence`

If any term is false, the item remains inventory, experimental, blocked or partially proven; it is not operational.
