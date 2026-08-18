
# PR STUDIO 1.0.0 — operazioni H24

## Runner affidabile

WP-Cron e Action Scheduler sono fallback opportunistici. La garanzia H24 richiede `runtime/worker.py` richiamato da un cron di sistema, servizio o scheduler esterno con endpoint e credenziali configurati. Il worker deve restare bounded, senza shell arbitraria e con un solo lease vincente.

## Pianificazione giornaliera

La modalità `daily_wall_clock` usa `Europe/Rome`, ora locale esplicita e occurrence key stabile. I test coprono ora inesistente primaverile, ora duplicata autunnale, riavvio, deduplica e salto delle occorrenze perse: nessun backlog storm.

La Scheduled task di ChatGPT/Codex può tornare in questa task ogni giorno alle 03:30, ma per file locali il PC deve essere acceso e l'app in esecuzione. Esegue SYSTEM-BENCH, controlla la readiness di AGENT-BENCH e può concludere `NO CHANGE`. Non può cambiare formula/corpus, eliminare test o applicare mutazioni senza autorizzazione.

## SLO e incidenti

- MCP initialize/tool list: obiettivo p95 <2 s;
- ack Browser task: obiettivo p95 <5 s;
- stati salute: healthy, degraded, blocked, failed_safe, not_configured;
- P0: doppia mutazione, perdita credenziali/stato, falso successo;
- P1: coda bloccata, Browser/GSC stale, runner H24 assente;
- P2: regressione non critica o dashboard/evidenza degradata.

Ogni chiusura richiede correlation ID, causa, test correttivo, owner ed evidence. I log sono bounded e redatti.
