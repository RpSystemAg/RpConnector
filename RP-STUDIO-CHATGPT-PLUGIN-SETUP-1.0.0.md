
# Setup RP Studio Connector 1.0.0

## Aggiornamento invariato

Carica `prstudio-unified-control-1.0.0.zip` come normale aggiornamento plugin WordPress. Il pacchetto deve aprirsi nella cartella `prstudio-unified-control` con bootstrap `prstudio-unified-control.php`.

Per aggiornare il Browser Agent, **non caricare una nuova estensione e non cambiare cartella**: estrai `prstudio-unified-browser-agent-1.0.0.zip`, sostituisci i file dentro la stessa cartella unpacked `prstudio-unified-browser-agent` già caricata e premi **Ricarica** in `chrome://extensions`. `prstudioConfig` e pairing restano invariati e l'upgrade normale non richiede nuovo pairing. La 1.0.0 mantiene `system.display` e aggiunge `activeTab`, `tabCapture`, `offscreen` e `contextMenus` per Browser LIVE. Il pairing resta `/wp-json/prstudio-unified/v1/pair`; wire protocol 3.0.0, rolling acceptance 4.0.0.

## ChatGPT

In ChatGPT **Developer mode**, crea o aggiorna il connettore chiamato **RP Studio Connector** usando:

`https://example.com/wp-json/prstudio-unified/v1/mcp`

L'autenticazione resta OAuth 2.1 Authorization Code + PKCE S256. Un aggiornamento non deve eliminare `prstudio_mcp_v5_clients`, `prstudio_mcp_v5_tokens` o `prstudio_mcp_v5_generation`.

Prima del refresh in ChatGPT, verifica initialize, tools/list e una lettura con **MCP Inspector**. Poi apri `prstudio_context_open`, conserva il `lane_handle` e prova heartbeat/close; il token segreto deve restare redatto.

## Accettazione

Esegui upgrade su staging con backup, pairing/restart Chrome, OAuth reale, e la matrice visuale home/shop/prodotto/cart/checkout/account a 360×800, 430×932, 768×1024, 1440×1000 e 1920×1080. Non cambiare il tema senza baseline reali prima/dopo.
