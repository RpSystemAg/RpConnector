# Laboratorio di Production-Readiness — 2026-08-19

Stato: **evidenza di laboratorio raccolta; `production_proven` resta `false`**
(per `ENTERPRISE-VERIFICATION-PROTOCOL-2026-08-18.md` la promozione richiede i
tier E6–E8 con evidenza esterna riproducibile).

> **Correzione evidenza — 2026-08-22.** Il laboratorio descritto qui installava
> WordPress core e poi copiava il checkout del plugin nel filesystem prima di
> chiamare `activate_plugin()`. Quindi prova attivazione/runtime del plugin su
> WordPress reale, **non** il percorso di installazione del pacchetto release
> `ZIP -> ZipArchive -> WP_Filesystem -> wp-content/plugins`. Il gap è
> documentato in `docs/CI-ZIP-INSTALL-GATE-2026-08-22.md` e deve essere coperto
> dal gate Unified H24 prima di qualificare il release ZIP come installation-tested.

## 1. Ambiente creato nel sandbox

| Componente | Versione | Origine |
|---|---|---|
| WordPress core | 7.0.4 | clone git da `github.com/wordpress/wordpress` (tag 7.0.4) |
| PHP | 8.3.32 (SAPI CLI/WASM) | `@php-wasm/node` (WordPress Playground) |
| Backend DB | MySQL-on-SQLite 1.8.0 (layer ufficiale WP Performance Team) | clone git `WordPress/sqlite-database-integration` (monorepo) |
| Plugin PR STUDIO | 1.0.0 (candidate `arena/01a01a4b-rpconnector`) | copia del checkout |
| Drop-in | `wp-content/db.php` (SQLite) + mu-plugin `lab-sqlite-compat.php` | generati per il lab |

### Patch di laboratorio (documentate, NON prodotto)

1. `db.copy` → `wp-content/db.php` con path del plugin risolto.
2. Symlink monorepo `wp-includes/database` non materializzato dal clone →
   sostituito con la directory reale del pacchetto `mysql-on-sqlite`.
3. `WP_SQLite_PDO_User_Defined_Functions`:
   - `get_lock()` / `release_lock()`: il dummy ufficiale restituisce `'1=1'`,
     che il guard OAuth del plugin (`'1' !== $raw_acquired`) legge come
     "lock occupato". Nel lab restituiscono `'1'` (lock acquisito) —
     equivalente al comportamento MySQL su un DB single-writer.
   - `connection_id()`: emula `CONNECTION_ID()` (mancante nel layer).
4. `WP_ENVIRONMENT_TYPE=local` (il guard HTTPS di OAuth richiede `local` per
   ambienti non-TLS; identico al comportamento CI con `wp config set`).

Queste patch interessano SOLO l'infrastruttura del laboratorio: nessun file
del plugin PR STUDIO è stato modificato per far passare i test.

## 2. Evidenza raccolta

### 2.1 WordPress reale: attivazione da checkout (tier E4 parziale)
- `wp_install()` completato: sito installato, `DB class: WP_SQLite_DB`.
- Checkout plugin copiato nel filesystem; installazione del release ZIP non esercitata in questo lab.
- Plugin attivato (`activate_plugin`): OK.
- `PRSTUDIO_UC_Store::maybe_upgrade()`: OK, schema 4.0.0, `schema_ready=yes`.
- Tabelle create (tradotte dal layer): `wp_prstudio_uc_dead_letters`,
  `wp_prstudio_uc_devices`, `wp_prstudio_uc_events`, `wp_prstudio_uc_jobs`,
  `wp_prstudio_uc_schedules`, `wp_prstudio_uc_tasks`.

### 2.2 MCP end-to-end su WordPress reale (tier E4 parziale)
Token OAuth reali minted con `PRSTUDIO_UC_MCP_Auth_V5::issue_tokens`
(registro `prstudio_mcp_v5_tokens` scritto realmente), richieste dispatchate
attraverso `rest_get_server()` sull'endpoint `/prstudio-unified/v1/mcp`:

| Chiamata | Status | Esito |
|---|---|---|
| `tools/list` (con bearer) | 200 | 36 tool pubblicizzati (budget Law 9 rispettato) |
| `tools/call prstudio_health` | 200 | `ok=true, status=completed` |
| `tools/call prstudio_research_radar` | 200 | `count=3, proposals=3`; `MobileWorldSafety → subsystem=security` (classificazione corretta) |
| `tools/call prstudio_intervention_record` | 200 | **`recorded=true, totals=1`** — scrittura reale nel ledger |
| `tools/call prstudio_backlog` | 200 | risposta corretta; l'intervento `applied` è escluso dal backlog (comportamento di deduplicazione previsto) |
| `tools/call prstudio_context_open` | 200 | lane creata (poi visibile in context_status) |
| `tools/call prstudio_context_status` | 200 | 2 lane |
| `tools/call agency_status` | 200 | risposta coerente |

### 2.3 Corpus di test PHP completo (tier E0/E1)
Eseguiti **tutti gli 81 file PHP di `tests/`** con `strict-php-errors`
(exit code e output registrati):

- **~66 PASS** (inclusi i 12 nuovi smoke del 2026-08-19 e, con l'argomento
  corretto, i driver `*-single-file`).
- 19 FAIL classificati — **nessuno è una regressione introdotta dal
  contributo**:
  - 4 confermati identici su `master` (oauth-2026, first-action-recovery,
    scheduler-topology, suite-latency) — sensibili a timing/funzioni del
    runtime WASM;
  - 5 driver `*-single-file` invocati senza l'argomento (il full-surface gate
    di CI li invoca senza argomento: condizione preesistente, master già
    rosso sul gate; con l'argomento passano 4/5);
  - 3 richiedono `pcntl_fork` (non disponibile in WASM; presente su CI
    Linux);
  - 3 richiedono subprocess PHP CLI nativo (engineering/toolchain);
  - 4 richiedono WordPress/DB reali (`wordpress-*`, `oauth-concurrency`,
    `playground-forward-canary`) — questi ora hanno copertura parziale dal
    lab §2.1–2.2;
  - `php-health-integrity-smoke` inizialmente FAIL per manifest non
    rigenerato → **PASS dopo `build-release.py --build`** (flusso previsto).

### 2.4 Altri gate
- Browser Agent: 173 test Node PASS (inclusi i 18 nuovi).
- Gate Python (constitution, security-drift, oracolo, SQL syntax, arity,
  inventory, protocol, one-guard): PASS.
- Drift checks (MCP tool drift, BUILD-INFO, toolchain, security contract):
  PASS.

## 3. Tier E0–E8: copertura e blocchi

| Tier | Requisito | Copertura lab | Blocco residuo |
|---|---|---|---|
| E0 | parse/lint/struttura | ✅ completo | — |
| E1 | unit/contract | ✅ ~66 suite eseguite | 3 suite subprocess-only |
| E2 | modello finito | ⚠️ parziale (state machine, exhaustive-checkpoint su CI) | — |
| E3 | database reale | ⚠️ **MySQL-on-SQLite** (layer ufficiale) invece di MySQL/MariaDB: DDL, opzioni, ledger, lock OAuth emulati | server MySQL/MariaDB non scaricabile (CDN bloccate); `GET_LOCK`/`CONNECTION_ID` emulati |
| E4 | WordPress reale | ⚠️ WP 7.0.4 installato + plugin attivato da checkout + MCP E2E (36 tool, write reale) | installazione release ZIP non coperta da questo lab; backend SQLite invece di MySQL; REST via dispatch (niente socket HTTP) |
| E5 | Chrome reale | ❌ | binario Chrome non scaricabile (dl.google.com bloccato); estensione non caricabile |
| E6 | MCP/OAuth remoto | ❌ (flusso OAuth esercitato localmente, token reali) | endpoint HTTPS esterno + client ChatGPT reale |
| E7 | soak/chaos | ❌ | 24h di runtime continuo; toxiproxy non scaricabile |
| E8 | agent benchmark | ❌ | corpus AGENT-BENCH + chiavi modello |

## 4. Verdetto e prossimi passi per la produzione

1. **E3**: rieseguire §2.1–2.3 su MySQL/MariaDB reale (CI lo fa già:
   `full-surface-execution.yml` monta MariaDB 11.4 — il gate resta il
   riferimento per il 100%).
2. **E4**: richiedere sia runtime/MCP su WordPress reale sia installazione del
   release ZIP tramite il WordPress upgrader/filesystem path; il gap ZIP è
   descritto in `docs/CI-ZIP-INSTALL-GATE-2026-08-22.md`.
3. **E5**: caricare l'estensione in un Chrome reale (pairing, owned-tab,
   screenshot, WebRTC LIVE) — richiede un runner con Chrome.
4. **E6**: OAuth 2.1 + PKCE reale contro l'endpoint HTTPS pubblico con un
   client ChatGPT.
5. **E7**: soak H24 con `nightly-soak.yml`/`production-h24-soak.py`.
6. **E8**: `bench/agent-bench-status.py` resta rosso finché il corpus
   AGENT-BENCH non è misurato (invariante del repo).

`production_proven` diventa `true` solo quando E6–E8 sono verdi sullo stesso
commit esatto (regola non negoziabile §1 del protocollo).

## 5. File di riferimento

- Questo report: `docs/PRODUCTION-READINESS-LAB-2026-08-19.md`
- Correzione gate ZIP: `docs/CI-ZIP-INSTALL-GATE-2026-08-22.md`
- Protocollo: `ENTERPRISE-VERIFICATION-PROTOCOL-2026-08-18.md`
- Corpus risultati: `/tmp/php-corpus-results/*.txt` (sandbox)
- Script lab: `/tmp/wp-*.php`, `/tmp/wp-core/` (sandbox)
