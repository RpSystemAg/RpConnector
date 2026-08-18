=== PR STUDIO Unified Control Plane ===
Contributors: prstudio
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 17.0.0
License: GPLv2 or later

PR STUDIO Unified Control 17.0.0 is the WordPress/WooCommerce source of truth and durable execution agency for the unchanged RP Studio Connector and paired Browser Agent.

== Installation ==
1. Install or update the ZIP as a normal WordPress plugin. Keep the `prstudio-unified-control` folder and `prstudio-unified-control.php` bootstrap.
2. Activation is lightweight; migrations are deferred, locked, checkpointed and fail-safe.
3. Replace the files in the same unpacked `prstudio-unified-browser-agent` folder and click Reload. Existing `prstudioConfig` pairing is reused unless it was explicitly revoked.
4. In ChatGPT connect `RP Studio Connector` to `<site>/wp-json/prstudio-unified/v1/mcp` using OAuth.
5. Request write scope and `offline_access` only when needed. Existing registered clients and refresh grants survive a normal update.
6. In ChatGPT open Settings > Plugins > RP Studio Connector > Information > Refresh. That pulls the new tools, descriptions and server instructions from this plugin; nothing has to be pasted anywhere. Then start a new conversation, because an open one is still using the surface it read earlier.
7. Optional: Tools > PR STUDIO > "Esecuzione e manutenzione" sets the autonomy mode, the long-poll channel and database retention. Defaults are safe.

== Execution agency ==
The 17.0 runtime persists owned jobs, leases, schedules, checkpoints, Browser-task correlation and dead letters in SQL. Operational Twin, Social Intelligence, Opportunity Engine and Site Sentinel add evidence-backed continuous analysis without inventing provider access.

== Browser-first execution ==
The Browser Agent uses only owned tabs and native DevTools pointer/keyboard input for visible applications such as Canva. Sensitive observations are redacted before transmission. The existing anti-crash gate is the single blocking pre-mutation guardian; crash-uncertain mutations are never replayed automatically.

== H24 ==
For reliable wall-clock execution configure the documented external runner. PR STUDIO uses one in-WordPress recurring worker: Action Scheduler when available, otherwise WP-Cron fallback; it does not run both recurring lanes in parallel. Visible-browser schedules also require Chrome and the paired extension to remain available.

== Changelog ==

= 17.0.0 =
* Added the single SQL-backed Agency Runtime with owned jobs, leases, idempotency, checkpoints, schedules, dead-letter handling and Browser correlation.
* Added Operational Twin, Social Intelligence, Opportunity Engine and changed-only Site Sentinel services.
* Expanded the bounded MCP surface to 123 typed tools backed by 1,376 capabilities; MCP 2026-07-28 is the primary stateless core with 2025 compatibility, while Tasks remains an optional client opt-in extension rather than a core dependency.
* Removed silent OAuth read-to-write escalation; refresh tokens are issued only when `offline_access` is explicitly requested.
* Hardened Browser ownership, pre-action origin binding, CDP allowlists, redaction and restart recovery without adding a second mutation-approval chain.
* Added native pointer, drag, wheel, touch and keyboard execution for canvas-oriented sites.
* Replaced the Core Web Vitals surrogate with PerformanceObserver-based LCP/CLS/INP collection derived from Google web-vitals 6.x semantics.
* Reworked the Agency scheduler topology: Action Scheduler is primary, WP-Cron is fallback-only, duplicate recurring chains are reconciled, the legacy Agency cron is disabled by default, and fast continuations no longer execute periodic maintenance.
* Bounded one scheduled Agency batch to a 4-second total runtime budget to avoid one cron request monopolizing a PHP worker across multiple jobs.
* Kept plugin/theme/core self-update on WordPress native Upgrader primitives and their temporary backup/restore recovery path instead of adding a proprietary updater.
* Added Browser LIVE MediaStream/WebRTC with private ephemeral SDP/ICE signaling and no persistent media storage; Browser Agent upgrade remains same-folder file replacement + Chrome Reload with existing pairing preserved.
* Preserved the WordPress folder/bootstrap, Browser folder/pairing/storage, MCP URL, OAuth persistence keys and Browser wire protocol `3.0.0`.
