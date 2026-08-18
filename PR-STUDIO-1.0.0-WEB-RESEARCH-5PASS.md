# PR STUDIO 1.0.0 — Five-pass web research ledger

Research date: **2026-08-17** (Europe/Rome)

Files covered (excluding the two self-referential ledger outputs): **628**

Old 250-checkpoint suite executed: **NO**

## Passes

- Pass 1: MCP/OAuth/scheduler + complete inventory — completed
- Pass 2: WordPress/WooCommerce/database/cache/bulk execution — completed
- Pass 3: Browser/CDP/Core Web Vitals/Search Console — completed
- Pass 4: security/updater/HTTP/supply-chain/OpenAPI/packaging — completed
- Pass 5: full-suite replay, 2026 freshness check, contract consistency and release closure — completed

## Source registry

- `MCP_2026_07_28` — Model Context Protocol — https://blog.modelcontextprotocol.io/posts/2026-07-28/ — stateless core, server/discover, header routing, cache hints, extensions, CIMD/DCR deprecation, RFC 9207
- `MCP_TS_SDK_2026` — Model Context Protocol TypeScript SDK — https://ts.sdk.modelcontextprotocol.io/v2/migration/support-2026-07-28 — serverInfo _meta, 2026/2025 compatibility, cacheable responses
- `OPENAI_GPT_ACTIONS` — OpenAI — https://help.openai.com/en/articles/9442513-configuring-actions-in-gpts — current GPT Actions OpenAPI/OAuth requirements
- `OPENAPI_3_1_2` — OpenAPI Initiative — https://spec.openapis.org/oas/v3.1.2.html — 3.1 patch compatibility and normative API description rules
- `WP_SAFE_HTTP` — WordPress Developer Resources — https://developer.wordpress.org/reference/functions/wp_safe_remote_get/ — SSRF-safe HTTP for arbitrary URLs and redirect validation
- `WP_ABILITIES` — WordPress Developer Resources — https://developer.wordpress.org/apis/abilities-api/rest-api-endpoints/ — Abilities API REST exposure is opt-in; show_in_rest false by default
- `WP_UPGRADER` — WordPress Developer Resources / Core — https://developer.wordpress.org/reference/classes/wp_upgrader/ — native upgrader, temporary backup/restore and core update semantics
- `WP_CRON` — WordPress Developer Resources — https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/ — system scheduler + DISABLE_WP_CRON guidance
- `WP_CACHE_PRIMING` — WordPress Developer Resources — https://developer.wordpress.org/reference/functions/_prime_post_caches/ — set-oriented post/meta cache priming
- `WOO_HPOS` — WooCommerce Developer Blog — https://developer.woocommerce.com/docs/features/high-performance-order-storage/ — HPOS compatibility and CRUD data-store access
- `ACTION_SCHEDULER` — Action Scheduler — https://actionscheduler.org/api/ — recurring action API and scheduling semantics
- `ACTION_SCHEDULER_PERF` — Action Scheduler — https://actionscheduler.org/perf/ — batch/concurrency performance and high-throughput guidance
- `MARIADB_10_6_EOL` — MariaDB Foundation — https://mariadb.org/mariadb-server-10-6-reaches-end-of-life-on-july-6th/ — MariaDB 10.6 EOL 2026-07-06 and migration requirement
- `MARIADB_MULTI_INSERT` — MariaDB Documentation — https://mariadb.com/kb/en/insert/ — multi-value INSERT and set-based SQL execution
- `PHP_8_5_9` — PHP — https://www.php.net/releases/8_5_9.php — PHP 8.5.9 security release
- `PHP_UNSERIALIZE` — PHP Manual — https://www.php.net/manual/en/function.unserialize.php — unserialize security and allowed_classes behavior
- `CHROME_MV3_SW` — Chrome for Developers — https://developer.chrome.com/docs/extensions/develop/concepts/service-workers/lifecycle — MV3 extension service worker lifecycle/event-driven execution
- `CHROME_RUNTIME` — Chrome for Developers — https://developer.chrome.com/docs/extensions/reference/api/runtime — runtime.onConnect and extension event contracts
- `CDP_PROTOCOL` — Chrome DevTools Protocol — https://chromedevtools.github.io/devtools-protocol/ — Profiler/CSS/DOM/Network domain lifecycle
- `WEB_VITALS_6` — GoogleChrome/web-vitals — https://github.com/GoogleChrome/web-vitals/blob/main/CHANGELOG.md — v6.0.1, soft navigation/BFCache/INP semantics
- `GSC_SEARCH_ANALYTICS` — Google Search Console API — https://developers.google.com/webmaster-tools/v1/searchanalytics/query — top rows not exhaustive, rowLimit/startRow, hourly_all, FAQ searchAppearance deprecation
- `GSC_URL_INSPECTION` — Google Search Console API — https://developers.google.com/webmaster-tools/v1/urlInspection.index/inspect — URL Inspection reports indexed-version state, not live test
- `GSC_INDEXING_API` — Google Indexing API — https://developers.google.com/search/apis/indexing-api/v3/using-api — restricted supported content and bounded batch semantics
- `MCP_FILESYSTEM_PIN` — modelcontextprotocol/servers — https://github.com/modelcontextprotocol/servers/tree/main/src/filesystem — official filesystem MCP implementation/version provenance
- `MCP_SEQUENTIAL_PIN` — modelcontextprotocol/servers — https://github.com/modelcontextprotocol/servers/tree/main/src/sequentialthinking — official sequential-thinking MCP implementation/version provenance
- `MCP_GIT_PIN` — modelcontextprotocol/servers — https://github.com/modelcontextprotocol/servers/tree/main/src/git — official git MCP implementation/version provenance
- `PDF_READER_PIN` — sylphlab/pdf-reader-mcp — https://github.com/sylphlab/pdf-reader-mcp — PDF reader MCP 4.1.2 surface/provenance
- `POSTGRES_MCP_SECURITY` — crystaldba/postgres-mcp — https://github.com/crystaldba/postgres-mcp/issues/181 — open restricted-mode file-read security issue; typed technical failure policy
- `POSTGRES_MCP_SQLI` — crystaldba/postgres-mcp — https://github.com/crystaldba/postgres-mcp/pull/161 — open ExplainPlanTool SQL injection fix; no patched release verified

## File accountability

The JSON companion contains the SHA-256, role, mapped primary sources and all five pass decisions for every covered file. Binary fixtures/evidence are accounted for through their generating/consuming technical family; they are not falsely described as independently specified web artifacts.

