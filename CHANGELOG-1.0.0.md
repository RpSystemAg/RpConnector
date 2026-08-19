
# PR STUDIO Unified Suite 1.0.0

## 2026-08-19 — Migliorie research-driven (settimana arXiv 13–19 agosto 2026)

**Area 1 — Sicurezza (Priorità 1)**
- Trap-page policy nel Browser Agent (MobileWorldSafety): il contenuto di
  pagina è input non fidato; le azioni derivate dal testo della pagina senza
  challenge di autorizzazione restano in sandbox e vengono sostituite da
  osservazione read-only (`lib/trap-page-policy.js`, Law 4). Test in
  `trap-page-policy.test.mjs`; wiring nel service worker; casi aggiunti a
  `dast-security.yml` e `network-chaos.yml`.
- Gauge di context-leakage nel flusso OAuth/MCP (The Model's Tell):
  invariante bloccante `context_leak_blocked` prima dell'emissione di ogni
  risposta (`class-prstudio-uc-context-leak-gauge.php` + integrazione in
  `PRSTUDIO_UC_MCP_V5::clean_result`); le opzioni `prstudio_mcp_v5_tokens`
  restano redatte e alimentano il gauge come segreti noti. Nuovo step in
  `oauth-security-invariants.yml`.
- Audit di sicurezza per self-evolution (Auditing Self-Evolution): nuovo
  workflow `security-drift-audit.yml` + `tests/security-drift-audit.py` con
  baseline SHA-256 delle superfici di sicurezza in
  `quality/security-drift-baseline.json`.

**Area 2 — Affidabilità LLM (Priorità 2)**
- Gate anti-allucinazione sul flusso di evidenza (Mixture-of-Expert Blocks):
  `class-prstudio-uc-evidence-gate.php` — verdetto verified/unverified/
  conflicting; le azioni senza evidenza coerente restano fuori
  dall'accettazione live ma l'esecuzione resta `executed=true` (Law 2).
  Aggiornato `LIVE-ACCEPTANCE-1.0.0.md`.
- Oracolo indipendente per accettazione live con rubriche esplicite (Grading
  Needs a Rubric): `quality/live-acceptance-oracle-rubric.json` +
  `tests/live-acceptance-oracle.py`; colonna della release equation Law 11
  in `enterprise-verification.yml`; aggiornato
  `ENTERPRISE-VERIFICATION-PROTOCOL-2026-08-18.md`.
- Calibrazione confidenza (Too Sure to Be Safe):
  `class-prstudio-uc-confidence-calibration.php` — binning con ECE,
  ricalibrazione e verdetto di overconfidence.
- Detection drift stile scrittura (When Writing Style Drifts):
  `class-prstudio-uc-style-drift-monitor.php` — features stilistiche con
  baseline media/varianza incrementale (Welford).
- Verifica fattibilità azioni prima dell'esecuzione (Fragility of
  Self-Improving Agents): `class-prstudio-uc-action-feasibility.php` —
  pre-check tecnico (target, args, lane, budget, conflitti); mai un gate
  autorizzativo.

**Area 3 — Robustezza runtime (Priorità 3)**
- Workspace versionato per retry (StagedWorkspace):
  `class-prstudio-uc-workspace-snapshots.php` — snapshot di sessione per
  correlation ID con digest verificato, ripristino idempotente e fail-closed;
  DDL SQL canonico per il deployment. Aggiornato `ARCHITECTURE-1.0.0.md`.
- Retry con backoff esponenziale jittered e classificazione
  transitorio/permanente (`class-prstudio-uc-retry-policy.php`, Law 5).
- Memory bank con evidenza preservata (D²ACCI):
  `class-prstudio-uc-evidence-memory.php` — diagnostica a doppio loop con gap
  analysis.
- Audit trail completo tamper-evident (D²ACCI):
  `class-prstudio-uc-audit-trail.php` — catena SHA-256 con verifica di
  integrità.

**Area 4 — Browser Agent (Priorità 3)**
- Hardening long-horizon (Wuying-Browser-Agent): `lib/horizon-stability.js`
  — stati di evidenza DOM densi (digest), ricaduta a scatto singolo su
  mutazione pagina, replay deterministico; wiring nel service worker; test in
  `horizon-stability.test.mjs` (coperti dal glob `tests/*.test.mjs` di
  `browser-agent-tests.yml`).

**Area 5 — Ricerca & nuovi tool (Priorità 4)**
- Nuovo tool MCP tipizzato `prstudio_research_radar` (~40 token, ammesso
  dopo i router essenziali in `tools_within_budget()`): classifica i paper
  arXiv sui 6 sottosistemi e propone i 5 contributi migliori
  (`class-prstudio-uc-research-radar.php`). Aggiornati
  `RP-STUDIO-CHATGPT-PLUGIN-1.0.0.json` (118 tool) e BUILD-INFO.
- Radar settimanale in `docs/research-radar/2026-08-19.md`; rimando in
  `AGENTS.md`.
- Provisioning dinamico della superficie tool per task (Task-Aware Harness
  Provisioning): `PRSTUDIO_UC_MCP_V5::tools_for_intent()` con hard-cap Law 9
  invariato; nuovi casi in `tests/php-tools-list-budget.php` e step in
  `mcp-official-conformance.yml`.
- Benchmark harness per la superficie tool: `bench/tool-surface-harness.py`
  — conformità schema 100%, copertura dispatch 100%, protocollo di
  varianza/ordine (varianza = 0); colonna aggiuntiva della release equation
  in `full-surface-execution.yml` e `production-certification.yml`; report in
  `quality/tool-surface-report.json`.

**Area 6 — Monitoring & metriche (Priorità 4)**
- Metriche di varianza esecuzione nel tool-surface harness (ordine task,
  Law 11).
- Audit trail completo (vedi Area 3).

Nota: `production_proven` resta `false`; il repository master ha già check
rossi preesistenti (full-surface execution, taint, mutation) non introdotti
da questa passata.

- Convergenza diretta alla sola release installabile 1.0.0; milestone 11–14 conservate come gate interni.
- Corretto il contratto `lane_handle`, gli executor legacy, `browser_verify_url`, metadata read/write, PHP lint e path errors.
- Semplificati service worker, ownership lane/session, screenshot perception-first, GSC, esecuzione locale/remota e cleanup tecnico Suite 17; eliminati takeover, approval/review e gate di verification.
- Aggiunti scheduling DST-safe, correlation ID end-to-end, identità build e dashboard WordPress in linguaggio semplice.
- Integrate cinque passate complete di ricerca web primaria con ledger file-per-file, audit rigoroso e SYSTEM-BENCH 1.2 evidence-aware; lo storico 1.1 resta immutabile e l’AGENT-BENCH resta non misurato finché il corpus non è pronto.
- Installazione, pairing, configurazione, MCP e OAuth persistente restano invariati. Production proof resta pending.
- Browser LIVE candidato al cutover MediaStream/WebRTC: `tabCapture` + offscreen `getUserMedia` + `RTCPeerConnection`, signaling privato effimero, nessuna persistenza media; invariati cartella Browser Agent, `prstudioConfig`, pairing e procedura same-folder + Ricarica.
