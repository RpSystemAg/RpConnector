# PR STUDIO Unified Suite 1.0.0 — Final Five-Pass Build Report

Generated: 2026-08-17T08:00:18Z
Research reference date: 2026-08-17 (Europe/Rome)

## Release position

PR STUDIO Unified Suite 1.0.0 has completed **five full research-driven passes** over the complete shipped suite. The SHA-bound research ledger covers **619/619 non-self-referential shipped files**, with five pass records per file, zero uncovered files and zero hard failures. The rigorous deployable/tool audit covers **206 deployable Control/Browser files**, **117 MCP tools**, **1,376 capabilities** and **1,076 connector actions**, with zero hard failures.

The final strict release validator, including the PHP intelligence smoke, reports **359 passed, 0 warnings, 0 skipped, 0 failed**. The release remains `production_proven=false` because the exact final package still requires deployed staging/live acceptance and because the host-level MariaDB restart root cause cannot be proven from application evidence alone.

The retired 250-checkpoint matrix was **not executed** and no new mutation guard was added.

## Five-pass audit

1. **MCP / OAuth / scheduler + complete inventory.** Compared with MCP 2026-07-28, OAuth metadata/RFC 9207 behavior and Action Scheduler semantics. Added CIMD-compatible client handling with DCR fallback, authorization response `iss`, bounded durable OAuth `last_used` touches, and corrected the duplicated scheduler topology.
2. **WordPress / WooCommerce / database / cache / bulk execution.** Compared with WordPress cache priming, WooCommerce HPOS/CRUD data stores and MariaDB set-based execution. Fixed DB import/restore truncation risk, added bounded multi-row INSERT chunks, primed posts/meta/options in bulk and moved WooCommerce collections onto native collection APIs.
3. **Browser / CDP / Core Web Vitals / Search Console.** Compared with Chrome MV3/runtime/CDP, Google web-vitals 6.x and Search Console API behavior. Corrected GSC dimension/provenance handling, FAQ rich-result recommendations, BFCache/soft-navigation CWV semantics and CDP coverage lifecycle cleanup. Browser test harness inconsistencies were corrected without weakening runtime gates; the complete Browser matrix reached **163/163 PASS**.
4. **Security / updater / HTTP / supply chain / OpenAPI / packaging.** Moved arbitrary external URLs to WordPress safe HTTP primitives, hardened serialized-value search/replace with `allowed_classes=false`, retained native WordPress Upgrader backup/recovery instead of introducing a duplicate updater, updated the PDF MCP pin, and made the unresolved Postgres MCP 0.3.0 security surface fail closed.
5. **Full-suite replay / freshness / contract consistency / closure.** Rebuilt the per-file five-pass ledger, re-audited every published MCP tool/capability/action, corrected the lazy-autoload map for a multi-class anti-crash file, removed remaining validator assumptions that contradicted the intended lazy bootstrap, and closed all release metadata/integrity gates.

The research ledger maps files to **29 current primary/upstream source families**, including MCP, WordPress, WooCommerce, MariaDB, PHP, Chrome/CDP, Google Search Console/web-vitals, OpenAPI/OpenAI Actions and pinned MCP sidecars.

## Important runtime fixes

### Scheduler / blackout amplification

Live 16.x evidence showed two independent recurring Action Scheduler chains for `prstudio_uc_agency_action_scheduler_tick`, a simultaneous WordPress cron worker, and legacy Agency initialization capable of adding further periodic work. The 17.0 runtime now selects **one recurring topology** (Action Scheduler when available, WP-Cron only as fallback), reconciles duplicate recurring chains, disables the legacy recurring Agency worker when the legacy Agency is disabled, separates fast job continuation from periodic maintenance, and applies a **4-second total scheduled batch budget**.

`DISABLE_WP_CRON` is not automatically changed because the application cannot prove that a real system scheduler exists. Disabling visitor-triggered WP-Cron without that evidence could stop legitimate WordPress/WooCommerce scheduled work.

### MariaDB restart finding

During the audit the live MariaDB service reset again; uptime was observed at 95 then 105 seconds. Immediately after restart, the sampled server state showed `Max_used_connections=6`, `Threads_running=1`, no current InnoDB row-lock waits and `Slow_queries=0`. This supports the conclusion that persistent connection/lock saturation was not visible at that sample, but it **does not prove the restart cause**. Kernel/systemd/OOM/MariaDB/PHP-FPM host logs remain required.

The live MariaDB 10.6 line is also past its upstream maintenance lifetime as of July 2026; host migration/update is an infrastructure requirement, not something PR STUDIO can safely patch inside a WordPress plugin.

## MCP / OAuth modernization

- Primary MCP contract: **2026-07-28**, with bounded 2025 compatibility.
- Stateless 2026 discovery/cache metadata behavior retained; mandatory Tasks are not advertised.
- CIMD-compatible client metadata handling is supported while DCR remains a bounded compatibility fallback.
- OAuth authorization metadata advertises the RFC 9207 response issuer parameter and authorize responses include `iss`.
- OAuth registry `last_used` is no longer persisted on every MCP call; durable touches are rate-limited.
- Public concurrency credential is `lane_handle`; secret/internal `lane_token` remains compatibility-only and is not published by tool schemas.
- Catalog runtime: **117 unique MCP tools** with schema and annotations verified.

## WordPress / WooCommerce / database execution

- WordPress options/post/meta/media bulk reads use native cache-priming/set-oriented paths where available.
- WooCommerce collections use CRUD/data-store APIs and retain HPOS compatibility rather than direct order-table assumptions.
- SQL import/restore no longer emits one INSERT per row and no longer silently risks exceeding the internal statement cap. Regular rows are grouped into bounded multi-value INSERTs with byte-aware chunking.
- SQL export/import restore preserves foreign-key-check state and creates missing tables only where the import format requires it.
- Database maintenance remains set-based: the 128-table optimize fixture remains **2 SQL statements**, not one preflight/query per table.

## Browser / Google correctness

- LCP/CLS/INP are collected through PerformanceObserver semantics compatible with Google web-vitals 6.x, including BFCache and soft-navigation handling where supported.
- INP keeps page-lifetime interaction-count semantics and the 8 ms fallback used for soft-navigation/BFCache cases with interactions below the event-duration observation threshold.
- CDP JS/CSS coverage now cleans up Profiler/CSS/DOM domains after successful collection while preserving recovery evidence when stop fails. Network is deliberately not disabled because doing so would invalidate later network/HAR evidence.
- Search Console rows now carry explicit non-exhaustive provenance; collection completeness and total scope are not conflated with “all rows”.
- Browser-side dimension handling no longer silently degrades an unsupported dimension to `query`.
- Automated SEO recommendations no longer suggest FAQ rich results as a Google feature after their 2026 retirement; manual schema interoperability remains possible.
- Complete Browser test matrix: **163/163 PASS**.

## Security / supply chain

- Arbitrary/configurable external URLs use WordPress `wp_safe_remote_*` validation, including redirect safety; trusted same-site WordPress paths do not pay this validation unnecessarily.
- Generic serialized search/replace uses `unserialize(..., ['allowed_classes' => false, ...])`; serialized objects are kept byte-identical rather than instantiating arbitrary plugin classes or triggering `__wakeup`/`__unserialize`.
- Official Filesystem, Git and Sequential Thinking MCP sidecars remain pinned/current according to the recorded source registry.
- PDF Reader MCP pin is updated to the verified 4.1.2 surface.
- `crystaldba/postgres-mcp` 0.3.0 remains discoverable but is **fail closed** because the audit could not verify a patched release for open restricted-mode/file-read and ExplainPlan SQL-injection findings.
- OpenAPI output remains 3.1.0 intentionally for compatibility; OpenAPI 3.1.2 was reviewed as a no-change patch-level reference rather than forcing a cosmetic version bump.

## Performance and tool-call economics

Local fixture measurements are evidence of orchestration overhead only; they are not deployed network claims. Current release evidence keeps:

- simple DB DML/update/delete fixtures at **1 query**;
- 128-table maintenance at **2 queries**;
- deterministic 2-step local flow: one MCP call and one model round-trip avoided;
- deterministic 3-step mixed flow: one MCP call, three internal operations and two model round-trips avoided;
- minimal bootstrap/lazy loading preserved instead of eagerly requiring the full MCP/Agency class graph.

The strict validator also runs a critical runtime fixture over the capability registry/twin path and records bounded memory; production latency/SLO proof remains a live acceptance item.

## Final validation evidence

- Five-pass research ledger: **5/5 passes, 619 files, 0 uncovered, 0 hard failures**.
- Rigorous deployable/tool audit: **206 files, 117 MCP tools, 1,376 capabilities, 1,076 connector actions, 0 hard failures**.
- Strict validator + PHP intelligence: **359 passed, 0 warnings, 0 skipped, 0 failed**.
- Browser full matrix: **163/163 PASS**.
- OAuth 2026 smoke: **8/8 PASS**.
- Scheduler topology smoke: **6/6 PASS**.
- DB bulk v17 smoke: **8/8 PASS**.
- WordPress/WooCommerce bulk v17 smoke: **8/8 PASS**.
- Security primitives v17 smoke: PASS, including zero `__wakeup` invocation in the hostile serialized-object probe.
- Supply-chain v17 smoke: PASS with Postgres sidecar fail-closed policy.
- Full PHP lint, JSON validation, generated-contract parity, deterministic component ZIP parity, anti-crash work binding, execution-lane concurrency, editorial concurrency, correlation chain, build identity and Browser enterprise hardening are all mandatory green gates in the final validator.

## Remaining live acceptance

The local release decision is **ready for live acceptance**, not “production proven”. Remaining evidence must come from the exact final artifact after deployment:

1. staging WordPress plugin update + rollback;
2. ChatGPT/RP Studio Connector OAuth 2.1/PKCE refresh against the deployed endpoint;
3. Browser Agent reload/pair/restart without credential loss;
4. real visual matrix across home/shop/product/cart/checkout/account;
5. external H24 runner confirmation and at least 24-hour soak;
6. host investigation of MariaDB restarts using systemd/kernel/OOM/MariaDB/PHP-FPM logs;
7. provider/social integrations only where real credentials/accounts are configured.

No superiority claim is made for work that has not been measured, and no host-level restart is marked fixed without host evidence.
