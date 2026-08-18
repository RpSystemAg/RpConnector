# Changelog
## 17.0.0

- Added MCP 2026-07-28 stateless core with 2025 compatibility fallback, per-result server metadata and cache hints.
- Added lazy read-only WordPress Abilities API bridge; mutation surface remains MCP-only.
- Declared WooCommerce HPOS compatibility through FeaturesUtil.
- Primed bulk option reads and split cache-clear providers so page/CDN flushes do not evict object cache.
- Preserved the existing single anti-crash authority and fast execution lanes; no new mutation guards.
- Replaced the Core Web Vitals `Performance.getMetrics` surrogate with PerformanceObserver-based LCP/CLS/INP collection using Google web-vitals 6.x metric semantics; DevTools `Performance.getMetrics` remains only in generic performance audits.
- Reworked scheduled Agency execution to one recurring topology: Action Scheduler is preferred, WP-Cron is fallback-only, duplicate recurring AS chains are reconciled on topology migration, and the disabled legacy Agency no longer schedules its own five-minute cron.
- Split fast Agency continuation from periodic schedule/SERP/procedural maintenance and bounded one scheduler batch to a four-second total execution budget, closing the old ~5 jobs × ~5 seconds PHP-worker hold path.
- Kept plugin/theme/core updates on WordPress native Upgrader classes, inheriting core temporary-backup/restore behavior instead of adding a parallel proprietary updater.
- Added targeted scheduler-topology regression coverage and retained the existing anti-crash authority without new guards or approval gates.

- R4: raw SQL mutation lane now accepts bounded DDL (CREATE/ALTER/DROP/TRUNCATE/RENAME) through the existing database anti-crash write gate; removed the duplicated DML-only connector/backend restriction.

## 16.0.0

### Adaptive 5-second fast-return hotfix (same 16.0.0 build)
- Browser MCP, GSC Browser fallback and orchestrator calls now use a 5-second initial synchronous budget by default; explicit longer waits remain supported for genuinely long work.
- Pending job/browser continuations now long-read for 5 seconds instead of 15 seconds, reducing model-visible stall time without shortening durable deadlines.
- Closed a lost-wakeup race in the Browser long-poll channel by sampling the work-generation token before the initial queue claim. A task enqueued in the old claim/token window could otherwise sit until the full 20-second hold expired.
- Durable job long-reads now poll quickly at first and back off to 400 ms, improving completion pickup without a sustained tight loop.


### Pre-mutation hotfix — single authoritative guard
- Replaced broad `readOnlyHint === false` anti-crash routing with explicit mutation
  scope and one authoritative pre-commit safety helper. Context/lane, Browser,
  Agency, Twin, SERP and other internal-state operations no longer enter the
  WordPress anti-crash path.
- Content transaction preconditions now run before safety gating; `write_token` is
  part of the public transaction schema.
- Browser turn deadlines are scoped to the continuation identity, terminal state is
  re-read before timeout, and successful terminal tasks no longer produce `ok:false`.
- GSC verification now requires operation-specific semantic evidence rather than
  transport completion alone.
- Corrected read-only diagnostic metadata, opportunity domain filtering and
  filesystem single-file search behavior.

Execution release. Nothing was removed: the full 15.0 surface is preserved and
its shape, latency and termination contract changed.

### Context budget — 147.9 KB removed from `tools/list`, measured
- `outputSchema` is no longer advertised. 15.0 emitted an identical 1,163-byte
  copy on each of 111 tools: 126.1 KB restating the same envelope, which a model
  learns from its first response anyway. Re-enable with `PRSTUDIO_UC_MCP_OUTPUT_SCHEMA`.
- `lane_token` and its `anyOf` branch left the published schema of 66 tools
  (19.7 KB). It remains fully accepted at runtime, so 15.x clients are unaffected.
- Listing descriptions are one line; the complete text moved to the new
  `prstudio_tool_manual`, not deleted.
- `tools()` is memoized and indexed by name; `tool_by_name()` no longer rebuilds
  111 schemas on every call.
- 111 tools before, 116 after.

### Turn contract — the job that never closed
- Every call resolves to `completed`, `failed`, `blocked`, `needs_human`, or to
  `pending` carrying `next_action`, `poll_after_ms` and `deadline_gmt`. A bare
  job/task id is never returned.
- Past its deadline a continuation becomes a defined failure with the last
  evidence attached.
- `prstudio_job_get` accepts `wait_seconds` and waits server-side; `browser_status`
  accepts `wait_seconds` with `task_id`.
- A loop guard distinguishes an identical repeated call (a stall) from
  continuation depth (normal polling). The third identical call returns the
  evidence already gathered plus what to do instead.

### Write path — the fix for "it only ever audits"
- New `prstudio_observe`: reads post/page/product/url/option/term/site and
  returns content plus a signed `write_token` carrying sha256, modified time and
  exact anchor counts. The optimistic lock and read-back verification are
  unchanged; only the producer of those facts changed.
- `wordpress_content_transaction` accepts `write_token`. Explicit preconditions
  still win, so existing callers are untouched.
- Precondition and anchor-count errors now state the remedy and include the
  correct values, so a single retry succeeds.

### Interventions ledger — memory of what was done
- New table `prstudio_uc_interventions`: entity × intervention × state × when,
  with no TTL. `rejected` and `reverted` are first-class, so declined work does
  not return next week.
- New tools `prstudio_backlog` and `prstudio_intervention_record`.
- A verified content transaction records itself automatically.
- An audit can no longer demote settled work back to "to do".

### Autonomy — one gate instead of four
- `dry_run`, `execution_mode` and the single `anti_crash` pre-mutation gate now
  sit behind one operator-chosen mode: `guarded`, `standard` (default),
  `autonomous`. Irreversible operations require a human in every mode.
- Configured in the WordPress admin, never by a tool argument.

### Dispatch — long-poll
- `/tasks/next` accepts `wait`. While idle the wait channel consults a
  cache-resident generation counter and touches no table; producers signal on
  enqueue. Bounded hold, early exit on client disconnect, concurrency cap, and a
  clean fallback to the previous cadence when any limit is hit.
- Device presence is written at most once per minute instead of once per poll.

### Loading
- 113 file-scope `require_once` replaced by a class-map autoloader over 124
  classes, after verifying no file under `includes/` has load-time side effects.
  Front-end requests no longer parse ~2 MB of control-plane PHP.

### Database hygiene
- New hourly `prstudio_uc_gc` with real batched deletes, a time budget and
  operator-configurable retention. The tables declared TTLs that were honoured on
  read and never applied on delete.
- One WordPress revision per work session instead of one per call.
- The global `wp_cache_flush()` in public verification is replaced by targeted
  post invalidation plus page-cache purge hooks.

### Model-facing contract
- Server `instructions` rewritten as an operating procedure rather than a list of
  prohibitions, with the leading 492 characters self-contained for clients that
  surface only the beginning. Every safety property remains enforced in code.

## 15.0.0

### Suite 15 convergence
- Replaced the redacted lane-token dead end with a reusable opaque `lane_handle`, resolved only inside WordPress and bound to the authenticated OAuth client; the legacy token remains accepted internally and redacted externally.
- Restored concrete execution for all 202 `legacy_direct_tool` capabilities through the canonical `WPAIB_MCP::call_tool_compat` path and aligned generated read/write/destructive metadata with the executable definitions.
- Corrected 23 inspection/preview/list actions that were incorrectly marked as writes, including theme asset inspection, without weakening genuinely mutating actions.
- Added deterministic contract-artifact regeneration, typed invalid-path failures, reliable Windows/PHP process exit handling, `browser_verify_url`, and opaque end-to-end correlation IDs.
- Added DST-safe `Europe/Rome` daily wall-clock scheduling with occurrence deduplication and missed-run skipping, avoiding catch-up storms after outages.
- Distinguished online, offline, stale and revoked devices; transient absence no longer destroys pairing. Explicit routing now prefers a typed tool, then Local Studio, Browser contract, generic Browser and finally legacy compatibility.
- Reworked the WordPress dashboard into four plain-language status cards, guided ChatGPT/Chrome setup and simplified Browser/activity tables; technical JSON remains available only in collapsed diagnostics.
- Added PRSTUDIO-BENCH formula 1.1, a fixed daily score/history contract, critical-path latency gates, 250 checkpoints and reproducible visual-lab evidence. Live production proof remains intentionally separate.

### Stabilization hardening (same 16.0.0 build)
- Fixed legacy gateway source TypeError, WooCommerce setting single-item writes/readback, native AdTribes feed scheduling, Browser Agent takeover queue parking, and mandatory anti-regression gates.

### Browser task self-healing (same 16.0.0 build)
- Existing task-cancel route now accepts an authenticated `restart_fresh` mode for the owning Browser device; no new route, pairing step, MCP tool or protocol version is introduced.
- Server resets only tasks with no accepted completed checkpoint and permits at most one automatic fresh restart, while preserving audit attempt/recovery counters.
- Browser status/heartbeat capability metadata exposes queue self-healing, watchdog and internal-scroll support to WordPress and ChatGPT discovery surfaces.

### Extension capability awareness (same 16.0.0 build)
- Pairing/heartbeat now exchange additive integration-capability metadata without changing credentials, endpoints or executor protocol.
- Device capability snapshots expose the extension's Local Studio feature list to WordPress and the existing `browser_status` MCP response. Local Studio remains local-only and creates no new remotely invokable MCP tool.

- Consolidated execution into one SQL-backed Agency Runtime with client ownership, leases, idempotency, schedules, checkpoints, Browser-task correlation and dead-letter recovery.
- Added Operational Twin, provider-neutral Social Intelligence, deterministic Opportunity Engine, playbooks and a bounded changed-only Site Sentinel.
- Expanded the public MCP surface additively from the original 81-tool baseline to 111 typed tools while keeping 1,378 runtime capabilities internal and governed; no baseline MCP tool was removed.
- Kept the active MCP transport on the fully implemented `2025-06-18` and `2025-03-26` dates; draft 2026 discovery/Tasks are not advertised, while client-owned agency/job tools provide durable long-running control.
- Removed implicit OAuth write/offline escalation; `prstudio.write` and `offline_access` must be explicitly requested and approved.
- Hardened Browser execution with exact CDP policy, owned-tab/origin prebinding, centralized observation redaction, native pointer/keyboard input, durable recovery, emergency stop and local approval gates.
- Preserved all installation identities: WordPress and Browser folders, bootstrap, pairing route/storage, MCP route, OAuth persistence keys and Browser wire protocol `3.0.0`.
- Added deterministic packaging, exact ZIP parity checks and release evidence that remains `production_proven: false` until live acceptance succeeds on the exact artifacts.

## 5.0.0
- Replaced the primary Custom GPT/GPT Actions integration with a custom ChatGPT Plugin/App backed by a typed MCP 5.0 server hosted directly inside Unified Control.
- Added OAuth Authorization Code + PKCE, dynamic client registration, `offline_access`, rotating refresh tokens, protected-resource metadata and bounded token/session storage.
- Kept the WordPress installation folder/bootstrap/update contract unchanged and retained deferred fail-safe migration so upgrade activation does not execute heavy schema work synchronously.
- Promoted Browser Agent to first-class/primary executor for live UI and human-visible work instead of treating Browser as a fallback.
- Added typed MCP Browser tools for navigation, tabs, DOM/accessibility, click/fill/type/press/select/check/tap/drag/scroll, screenshots/PDF, network/console/errors, emulation, responsive diagnostics, traces/HAR and live audits.
- Mapped the advanced Browser catalog to real Agent executors: 119 executable Browser actions; 11 secret/arbitrary-code/global-permission operations remain intentionally guarded.
- Fixed legacy capability executor bootstrap so published legacy mappings cannot reference an unloaded executor class; registry consistency verifies callable executors.
- Replaced the 4.x opaque binder failure mode with recursive typed MCP validation for nested objects, arrays, enums, bounds and required parameters.
- Made GSC Browser-first by default with typed site/date/dimension inputs, persistent owned Search Analytics session, verified dimension/header binding and no synthetic cross-dimension joins.
- Made screenshot evidence immutable during retention by assigning every capture a unique cryptographic artifact ID plus SHA-256 and bounded per-device retention.
- Decoupled Browser product version from the stable executor wire protocol; CDP attach negotiates 1.3 first with controlled legacy fallback.
- Kept the 1,298 WordPress capabilities internal to the Capability Registry; ChatGPT receives a bounded typed MCP tool surface rather than a 1,278/1,298-tool static catalog.

## 4.0.2
- GPT Actions OpenAPI hotfix: OpenAPI 3.1.0, explicit 200 response properties for all seven actions, and correct 202 success schema for asynchronous execute.

## 4.0.1
- Recovery hardening: existing 2.0/3.x schemas are left untouched during plugin activation; schema/data migration runs only in the deferred recovery job.
- GPT Actions are Actions-Key-only and no longer fall back to bridge permission/OAuth sessions, including Health and Job reads.
- Activation no longer initializes legacy MCP or Browser Runtime; both remain disabled by default and outside the normal 4.0.1 lifecycle.
- Preserved WordPress plugin folder/bootstrap/update mode and Browser Agent folder/pairing/storage contracts; Control accepts Agent 3.0 during rolling upgrade without re-pairing.
- Kept the ChatGPT surface at seven stable GPT Actions with OpenAPI 3.0.3, deterministic operationIds, dedicated scoped Actions key, and uniform JSON errors for authentication/rate/payload failures.
- Removed MCP as a bootstrap/health dependency; legacy MCP remains disabled-by-default compatibility code only.
- Rebuilt GSC fallback around a persistent owned Search Analytics tab, in-page dimension switching, active-tab/header verification, structured-network buffer isolation, and no inferred cross-dimension joins.
- Official GSC API remains the only provider allowed to return exact multi-dimension query/page relationships; stale cache is never labeled live.
- Added compact Capability Registry search index, stronger secret redaction including api_key/apikey fields, loopback status-0 fail-safe semantics, and expanded anti-crash/recovery tests.

## 1.0.0
- Durable workflow jobs, explicit idempotency, verification receipts, fail-safe recovery, Health/Jobs endpoints and automatic schema upgrade.
- Installation, pairing, OAuth, wire protocol and public tool identity preserved.

## 0.3.11
- KPI link runtime completo: grafo source→target dal DOM post-hydration nel Browser Agent, con inventario sitemap same-origin bounded e fallback HTTP pubblico server-side.
- Nuovo log orchestrator bounded/redatto con stream aggregato, per componente e per file sorgente; ingest autenticato dei log estensione e mirror audit connettore.
- Integrità distribuzione verificabile per file tramite byte count, line count e SHA-256, senza duplicare contenuti sensibili nei log.
- Retention fail-closed: dopo il nuovo backup vengono eliminati vecchi backup, cache e generazioni log; il lavoro non viene chiuso se la pulizia richiesta fallisce.
- Refresh connettore retrocompatibile: wire protocol, endpoint, OAuth/PKCE, pairing, tool identity e contract hash restano invariati.
- Build/test robusti su layout sorgente versionato e pacchetti installabili; corretto il path bug del test di parità 0.3.10.
- Hardening filesystem/log: deny Apache/IIS, redazione token anche in URL, limiti di payload/stream e scansioni statiche anti-shell/eval/segreti.

## 0.3.10
- Native-first database/SEO routing; no adapter handoff for authoritative executors.
- Server-side bounded sitemap crawler and WordPress orphan/coverage audits.
- Catalog bulk metadata writes verified field-by-field with transactional rollback verification.
- Browser Agent ownership rehydration after MV3 worker restart and structured Search Console URL Inspection.
- OAuth/pairing/diagnostics hardening and regression/security gates.

## 0.3.9
- Eliminato `client_action_required`/continuation ChatGPT come percorso di esecuzione: ogni azione pubblicata è backend-owned o fallisce chiusa con una precondizione precisa.
- Aggiunto `PRSTUDIO_UC_Backend_Executability` con audit dell'intero catalogo e blocco release se esistono azioni catalog-only.
- Implementato backend nativo completo per tutte le 29 azioni `/database-manage`, incluse `delete`, `transaction`, batch, DDL, snapshot, import/export e migration.
- `transaction` usa START TRANSACTION/SAVEPOINT/COMMIT/ROLLBACK, blocca DDL impliciti, verifica engine transazionali e fingerprint del rollback.
- Le 71 azioni enterprise non native usano `wordpress_backend_plan`; i job legacy pending vengono migrati a `blocked`, non a continuation.
- Browser Agent con registro runtime esatto delle 50 azioni avanzate `contract_action`; mismatch catalogo/executor fa fallire i test.
- Nuovi test regressivi di backend executability e database transaction/delete; nomi/schemi tool e 17 endpoint restano invariati.

## 0.3.7
- Persistenza dell’affinità della scheda agente tra task indipendenti, con preparazione/navigazione automatica e senza fallback sulla scheda attiva dell’utente.
- Screenshot CDP aggiornato al protocollo debugger 1.3 con downgrade progressivo dei parametri di cattura.
- Crawler link e sitemap resi autonomi, bounded, governati e con worker tab dedicati.
- Search Console: estrazione strutturata query/page/click/impression/CTR/position da rete e griglie DOM, senza inventare righe mancanti.
- Catalogo MCP reso deterministico anche quando il client perde Mcp-Session-Id durante Aggiorna azioni; capability custom isolate nel namespace experimental.
- Orchestratore Browser con dipendenza di navigazione automatica e fallback diretto dei cinque tool Search Console esistenti.
- Nuovi test regressione per tab handoff, protocollo screenshot, crawler, metriche e refresh connettore.

## 0.3.6

- Indice istantaneo condiviso per 1.278 tool.
- MCP 2026 discovery e cataloghi cacheabili.
- Pacing responsabile, budget per origine e circuit breaker.
- Audit enterprise completo e revisione in due passaggi.
- Manuali utente, architettura e manutenzione.


## 0.3.4

- Aggiornamento strettamente retrocompatibile con la 0.3.3: invariati nomi, schemi, parametri, endpoint, route, executor e hash del contratto operativo per 1.278 tool.
- Aggiunti due profili interni selezionati automaticamente tramite capability e version negotiation: `legacy_full_catalog` e `compact_dynamic_catalog`.
- Il profilo legacy conserva la superficie attiva 0.3.3; il profilo compatto carica al massimo 48 schemi canonici, mantenendo direttamente invocabili tutti i 1.278 tool tramite discovery e dispatcher dinamici.
- Nessuna nuova configurazione, procedura di installazione, associazione, chiave, pairing o OAuth. Client 0.3.3 e Browser Agent già associati continuano a funzionare durante l'aggiornamento.
- Separati hash di compatibilità operativo e hash del documento 0.3.4, così i metadati del catalogo possono evolvere senza invalidare il pairing esistente.
- Aggiunta toolchain locale e open source per build, test, accessibilità, sicurezza e SBOM; nessuna dipendenza di sviluppo entra negli ZIP di produzione.
- Aggiunti snapshot degli schemi 0.3.3 e test regressivi per 1.278 definizioni MCP e 17 endpoint registrati.
- Vietate e rilevate automaticamente integrazioni SaaS o a consumo, inclusi AgentQL, Browserbase, Stagehand, Semgrep Platform e CodeQL.

## 0.3.3

- Introdotto un Capability Contract unico e versionato, identico in connettore, plugin ed estensione: 1.278 tool richiamabili, 1.076 azioni dinamiche, 202 tool diretti, 31 route e 10 domini.
- Tutti i tool restano disponibili con nome e schema originari; ogni chiamata diretta viene governata trasparentemente dall’orchestratore e da una delle 10 sottoclassi.
- Aggiunta negoziazione di protocollo e contract hash durante pairing, heartbeat e assegnazione task; i componenti disallineati vengono bloccati con diagnosi precisa.
- Browser Agent isolato nella finestra `PR STUDIO AGENT`, con registro di proprietà dei tab e divieto di usare scheda attiva, ultima scheda o tab dell’utente come fallback.
- Nuove schede create in background con URL obbligatorio; `about:blank` è vietato e non viene mai usato nei retry.
- Screenshot viewport, elemento e full-page acquisiti via Chrome DevTools Protocol senza cambiare focus; lazy loading con scroll progressivo e verifica artefatto.
- Motore di individuazione elementi allineato su CSS standard, accessibilità, testo, XPath e coordinate; verifica post-azione e massimo due tentativi deterministici sullo stesso tab.
- Human takeover limitato a gate realmente visibili, login/MFA, azioni critiche o conflitto con l’utente nella finestra agente.
- I cinque tool Search Console sono preservati e usano la sessione Google del Chrome personale; rimossa la dipendenza attiva da GSC Wizard e il relativo OAuth remoto.
- Aggiunti test di parità contratto, isolamento browser, runtime Chrome simulato, assenza fallback `about:blank`, navigazione admin e provider Search Console.

## 0.3.2

- Rimossa completamente la gestione dei plugin legacy dal ciclo di vita della suite.
- L’attivazione non cerca, non disattiva e non elimina pacchetti precedenti.
- Rimossi classe di cleanup, endpoint `admin-post`, pulsante amministrativo, notice e opzioni correlate.
- Aggiunti test negativi `legacy-independence-smoke.php` e navigazione admin per impedire regressioni.
- Allineate le versioni del plugin e del Browser Agent a 0.3.2.
## 0.3.1

- Corretto fatal error in amministrazione: la rimozione dei plugin legacy non viene più eseguita durante `plugins_loaded`.
- La pulizia legacy è ora un'azione amministrativa esplicita e protetta da nonce, eseguita con API filesystem caricate.
- Aggiunto test runtime `admin-navigation-smoke.php` che simula un amministratore con plugin legacy presente e API filesystem non inizializzate.
- Aggiornati test e contratto suite per impedire regressioni di bootstrap amministrativo.

## 0.3.0

- Aggiunto orchestratore deterministico con 10 classi operative e copertura delle 31 route / 1.076 azioni.
- Aggiunti workflow composti con propagazione automatica di `tabId`; supporto multi-scheda per Trends, Search Console e Merchant Center.
- Il Browser Agent live diventa il target predefinito quando un dispositivo è online.
- Corretto il falso positivo CAPTCHA causato da iframe reCAPTCHA invisibili; i gate vengono valutati soltanto su elementi realmente visibili e nei passaggi pertinenti.
- Aggiunta sostituzione della chiave pairing senza reinstallazione dell’estensione e revoca automatica del vecchio dispositivo.
- Screenshot spostati su filesystem privato con ritenzione singola per dispositivo; eliminati i Data URL dai checkpoint e aggiunta sanificazione difensiva lato database.
- OCR riscritto con Tesseract CLI sicuro, timeout e diagnostica; fallback Chrome TextDetector e DOM/accessibilità per pagine web.
- Aggiunto gate anti-crash con evidenze fresche, hash del target e blocco reale delle mutazioni.
- Sostituiti i backup per passaggio con journal transazionale e un solo backup ZIP finale; fallback WordPress PclZip.
- Eliminazione fisica dei due plugin legacy, preservando OAuth, opzioni e tabelle.
- Scrittura esplicitamente vietata in `wp-content/mu-plugins/`.
- Aggiunti smoke test per orchestratore, multi-tab, OCR reale, retention screenshot, backup unico, gate anti-crash e rimozione legacy.

## 0.2.0

- Unione fisica del bridge e del browser runtime.
- Pairing dell’estensione Chrome, lease task, checkpoint e human takeover.
