
# Live acceptance PR STUDIO 17.0.0

Stato: **PENDING — local candidate only**. `production_proven: false`.

- [ ] upgrade del pacchetto WordPress esatto su staging con rollback provato;
- [ ] refresh **RP Studio Connector** e OAuth 2.1/PKCE reale senza perdita dei grant;
- [ ] pairing/restart del Browser Agent esatto senza re-pair non richiesto;
- [ ] Browser LIVE MediaStream/WebRTC: `tabCapture` → offscreen `getUserMedia` → offer/answer → ICE connected → `<video>` realmente in movimento;
- [ ] `getStats()` LIVE con bitrate > 0, FPS > 0 e risoluzione coerente;
- [ ] navigazione nella stessa tab, stop, chiusura tab, deduplica catture e ICE restart verificati;
- [ ] nessun frame/media persistito in database/filesystem; soltanto signaling privato effimero;
- [ ] solo dopo questi PASS rimuovere il viewer LIVE legacy basato su frame/screenshot;
- [ ] matrice visuale reale prima/dopo: home, shop, prodotto, cart, checkout, account × 360×800, 430×932, 768×1024, 1440×1000, 1920×1080;
- [ ] screenshot component-level per rating/card/header/filtri/drawer/wishlist/newsletter;
- [ ] provider social/Canva configurati realmente;
- [ ] worker esterno e soak H24 di almeno 24 ore con lease/recovery osservati.

Il laboratorio fixture ha 30 baseline, 30 candidate e 90 confronti con 0 failure. Questo dimostra il laboratorio, non il rendering del sito reale.
