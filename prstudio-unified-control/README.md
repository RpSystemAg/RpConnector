# PR STUDIO Unified Control 17.0.0

WordPress/WooCommerce control plane and durable source of truth for PR STUDIO Unified Suite 17.0.0.

## Installation contract

- Install or update the ZIP as a normal WordPress plugin. The stable folder and bootstrap remain `prstudio-unified-control/prstudio-unified-control.php`.
- Activation stays lightweight. Schema and data migrations run later with a lock, checkpoints, bounded retries and fail-safe recovery.
- Settings, site identity, valid Browser pairing, jobs, memory, evidence and existing OAuth clients/refresh grants are preserved during a normal update. Revocation remains an explicit owner action.
- Runtime identity is derived from the WordPress site; no production host is embedded in the component.

## ChatGPT Plugin and MCP

ChatGPT connects to the same remote MCP endpoint:

`<site>/wp-json/prstudio-unified/v1/mcp`

The public surface contains 123 bounded tools backed by 1,376 internal capabilities. Frequent agency, Browser, GSC, commerce, twin, social, opportunity and sentinel operations use explicit schemas; search/describe/execute provides governed access to the larger registry.

The server implements the MCP `2026-07-28` stateless core and preserves `2025-06-18` / `2025-03-26` compatibility for older clients. MCP 2026 server discovery and per-result server metadata/cache hints are emitted only for the matching protocol generation. Tasks are an optional client opt-in extension rather than a dependency of the core execution path; long-running PR STUDIO work remains durable through the typed agency and job controls on the same endpoint.

OAuth uses Authorization Code with PKCE, dynamic client registration, explicit scopes and rotating refresh tokens when `offline_access` is requested. The execution connector declares `prstudio.read prstudio.write offline_access`, and the WordPress owner sees and approves that request; scope normalization never silently adds write access. The persistent `prstudio_mcp_v5_*` option names are deliberately unchanged because they are compatibility identities, not product-version labels.

## Execution agency

The SQL-backed Agency Runtime is the single durable execution path. It provides owned jobs, leases, idempotency keys, checkpoints, schedules, dead-letter handling and Browser-task correlation. ChatGPT is the control surface, not the worker that must remain open.

The operational layer includes:

- a site-scoped Operational Twin with source, freshness and confidence;
- provider-neutral Social Intelligence for imported API evidence and owned-browser observations;
- deterministic Opportunity ranking;
- a bounded Site Sentinel with changed-only alerts;
- typed mission submit, inspect, cancel and retry controls.

Provider credentials and API completeness are never fabricated. A social provider remains `not_configured` until its own first-party OAuth, scopes, token lifecycle and live tests exist.

## Browser-first execution

The paired Chrome Agent is the primary executor for visible-page work. It uses only Agent-owned tabs, binds origin and document identity before an action, redacts observations before transmission, and blocks arbitrary JavaScript, cookie export and global permission mutation.

Version 17 keeps native DevTools input, observation bundles, durable recovery and lease isolation. It also replaces the old Core Web Vitals surrogate with PerformanceObserver-based LCP, CLS and INP collection. The existing anti-crash test remains the single blocking pre-mutation guardian; no new approval/checkpoint chain was added.

### Browser LIVE MediaStream/WebRTC

Browser LIVE captures the selected controlled tab with Chrome `tabCapture`, consumes the stream in the MV3 offscreen document and transports video peer-to-peer with WebRTC. WordPress persists only bounded ephemeral signaling metadata (SDP/ICE/state) in private files and never media frames. The Browser Agent installation identity is unchanged: update the same unpacked `prstudio-unified-browser-agent` folder in place and press Reload; `prstudioConfig`, pairing endpoint and wire protocol remain stable.

## H24 operation

Durable state and schedules live in WordPress. Inside WordPress, PR STUDIO 17 maintains one recurring worker topology: Action Scheduler is preferred when available and WP-Cron is fallback-only, never a parallel PR STUDIO worker. Fast job continuations are isolated from periodic schedule/SERP/curation work and each scheduled batch has a bounded total runtime budget. True wall-clock H24 execution still requires a reliable external runner (system cron or equivalent) calling the documented worker entrypoint. Continuous visible-browser work additionally requires Chrome and the paired extension to be running; otherwise that domain reports `degraded` while server-side work continues.

## Native update/recovery path

Plugin, theme and WordPress core updates continue to delegate to WordPress `Plugin_Upgrader`, `Theme_Upgrader` and `Core_Upgrader`. PR STUDIO does not add a second proprietary updater: current WordPress core already provides temporary backup/restore and upgrade recovery behavior, so duplicating it would add failure modes rather than resilience.

See the suite-level architecture, operations, social connector and decision documents for deployment and acceptance requirements. Local validation proves code, contract and package integrity; production status still requires live WordPress, Chrome, Canva, social-provider and ChatGPT acceptance on the exact release artifacts.
