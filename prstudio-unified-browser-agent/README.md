# PR STUDIO Unified Browser Agent 1.0.0

Browser executor + Local Studio per PR STUDIO Unified Control. **Versione, installazione, pairing e wire protocol restano invariati**: stessa cartella unpacked `prstudio-unified-browser-agent`, stesso endpoint `/wp-json/prstudio-unified/v1/pair`, stesso storage `prstudioConfig`, stesso executor protocol `3.0.0`.

## Local Studio — nessun account/API esterna

La stessa estensione ora è utile anche senza WordPress o ChatGPT attivi. Dal Side Panel può lavorare sulla scheda utente corrente con una lane separata dalle tab possedute dall'Agent remoto:

- Standalone Mode e profili origine `readonly / automation / debug`;
- Visual Recorder semantico, Workflow Library, replay bounded e import/export JSON;
- Smart Element Inspector con CSS/XPath/ruolo/nome accessibile e selector fallback;
- Flight Recorder locale e Recovery Console fail-closed;
- Page Health, Debug Center (console/network/performance), responsive matrix e bounded same-origin site scan;
- baseline visiva + hash di testo/struttura per visual/semantic diff;
- diagnostic report builder locale con screenshot opzionale;
- workspace di tab, command palette e controlli periodici via `chrome.alarms`;
- STOP locale integrato nello STOP globale.

Non sono richieste API key, account cloud o backend aggiuntivi. Browser LIVE aggiunge esclusivamente i permessi Chrome `activeTab`, `tabCapture`, `offscreen` e `contextMenus`; il metodo di aggiornamento non cambia: si sostituiscono i file **nella stessa cartella unpacked `prstudio-unified-browser-agent` già caricata** e si preme **Ricarica** in `chrome://extensions`. Non si carica una nuova cartella e non si rifà il pairing. Password, OTP e campi con semantica token/secret non vengono persistiti nei workflow; report/log passano dalla redazione centrale.

## Browser LIVE — MediaStream + WebRTC

Il viewer LIVE usa `chrome.tabCapture` soltanto dopo un gesto Chrome valido, consuma il MediaStream nel documento offscreen e invia il video con `RTCPeerConnection`. WordPress trasporta esclusivamente signaling effimero SDP/ICE/stato; non registra frame, video o audio. L’action esistente continua ad aprire il Side Panel; il menu contestuale `PR STUDIO LIVE WebRTC — Avvia/Ferma questa scheda` è un ingresso deterministico aggiuntivo.

L’upgrade resta quello storico della suite: **stessa cartella unpacked**, sostituzione dei file, **Ricarica**. `prstudioConfig`, endpoint di pairing e wire protocol restano invariati. La rimozione del viewer legacy avverrà solo dopo accettazione E2E con video realmente in movimento e statistiche WebRTC valide.

## Simbiosi con WordPress + ChatGPT

Il pairing e l'heartbeat esistenti pubblicano l'elenco delle feature locali al plugin. Il plugin conserva queste capability per dispositivo e le rende visibili anche nel già esistente `browser_status` MCP. In senso opposto, pairing/heartbeat restituiscono all'estensione le capability d'integrazione del plugin. Local Studio resta utilizzabile in modo autonomo e zero-account dal Side Panel. In aggiunta, il connettore già esistente può invocare **solo** le operazioni Local Studio esplicitamente allowlisted tramite la normale lane OAuth/Browser Agent; le operazioni page-bound richiedono una tab posseduta/adottata e non aprono un canale di installazione o pairing alternativo.

La lane locale usa normali tab utente; la lane remota usa tab/finestra Agent-owned. Le lane mantengono sessioni e ownership separati senza trasformare l'attività dell'utente in takeover e senza parcheggiare task remoti per sola attività locale.

## Sicurezza e recovery

Raw CDP rimane su allowlist esatta read-only; i comandi interni sono bounded. Cookie/session export, JavaScript arbitrario, bypass CAPTCHA e mutation globale delle permission restano bloccati. Se Chrome/STOP interrompe uno step locale mutativo, lo stato viene marcato `failed_nonreplayable` e lo step non viene mai ripetuto automaticamente.
