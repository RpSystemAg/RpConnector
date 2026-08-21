# PR STUDIO Unified Browser Agent 1.1.0

L'estensione conserva il collegamento PR STUDIO esistente e rimuove il motore browser custom.

## Invarianti mantenute

- stessa cartella unpacked `prstudio-unified-browser-agent`;
- stesso endpoint WordPress `/wp-json/prstudio-unified/v1/pair`;
- stesso storage Chrome `prstudioConfig`;
- stesso executor wire protocol `3.0.0`;
- stessa coda task/lease/heartbeat del plugin.

## Motore browser

Il service worker non usa più debugger CDP custom, page runtime, selector engine, native input, recovery engine o Local Studio. Inoltra le operazioni a `http://127.0.0.1:8765`, dove gira `prstudio-browser-mcp-bridge`.

Provider esposti:

1. **Chrome DevTools MCP** — server ufficiale `chrome-devtools-mcp`.
2. **Chrome WebMCP** — strumenti WebMCP forniti dallo stesso server ufficiale.
3. **Puppeteer MCP** — alias verso `chrome-devtools-mcp`, che il progetto Puppeteer indica come server MCP Puppeteer-based.
4. **Selenium** — adapter minimo sulla libreria ufficiale `selenium-webdriver`; SeleniumHQ non pubblica un server MCP ufficiale al 21/08/2026.

## Browser personale

Per `--autoConnect` servono Chrome 144+ e Remote Debugging abilitato in `chrome://inspect/#remote-debugging`. Chrome chiede esplicitamente il consenso alla connessione.

## Aggiornamento

Sostituire i file nella stessa cartella unpacked e premere **Ricarica** in `chrome://extensions`. Il pairing non va rifatto.
