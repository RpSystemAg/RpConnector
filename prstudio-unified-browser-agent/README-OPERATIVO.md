# PR STUDIO Browser Agent 16.0 — Operativo

L'estensione espone due sole aree: **Automazione** e **Log**.

## Automazione
Raccoglie tutte le funzioni che in precedenza erano separate tra Locale, Workflow e Monitor: audit pagina, debug, inspector, responsive, site scan, registratore, automazioni salvate, workspace, baseline, controlli programmati, pairing e task remoti. Non esistono gate di approvazione operatore o profili di velocità responsabile.

Le modifiche server-side restano subordinate all'unico guardiano pre-mutazione della suite: l'anti-crash. CAPTCHA, MFA, passkey e login sono condizioni esterne e possono richiedere intervento umano, ma non sono approvazioni PR STUDIO.

### Recovery manuale
Il pulsante **Ripulisci azioni, log, loop** azzera task/transienti runtime, log, sessioni e telemetria runtime senza cancellare pairing, automazioni salvate, schedule, workspace, baseline o profili origine.

## Log
Mostra log locali e remoti.
