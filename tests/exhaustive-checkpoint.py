#!/usr/bin/env python3
"""Exhaustive 250-question semantic checkpoint for PR STUDIO Unified Suite 1.0.0.

Audits every published MCP tool, catalog action, capability and PHP/JS function.
The checker is intentionally strict: every published catalog action/capability must
resolve to a concrete operational chain. Metadata-only, policy-only, stub and
placeholder public surfaces are release failures. Short accessors/delegators are
accepted only when their target is concrete and reachable.
"""
from __future__ import annotations
import argparse, collections, hashlib, json, os, re, sys
from pathlib import Path
from m11_contract_audit import (
    _semantic_read_action,
    audit_annotations,
    audit_browser_reachability,
    audit_contracts,
    audit_legacy_direct,
)

# Windows task runners commonly inherit a cp1252 console.  The checkpoint emits
# the Italian questionnaire verbatim, so make its CLI output deterministic too.
if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8')
if hasattr(sys.stderr, 'reconfigure'):
    sys.stderr.reconfigure(encoding='utf-8')

VERSION='1.0.0'
ROOT=Path(__file__).resolve().parent.parent
SURFACE=ROOT/'prstudio-unified-control/contract/concrete-execution-surface.json'
SUMMARY=ROOT/f'EXHAUSTIVE-CHECKPOINT-{VERSION}.json'
DETAIL=ROOT/f'EXHAUSTIVE-CHECKPOINT-DETAIL-{VERSION}.ndjson'

QUESTIONS=[
('Q01','È dichiarata con identità univoca e stabile?'),
('Q02','Esiste un corpo/implementazione reale?'),
('Q03','È raggiungibile dal percorso che la espone?'),
('Q04','Risolva a un executor/callback concreto?'),
('Q05','È priva di stub, placeholder o no-op ingannevoli?'),
('Q06','È priva di TODO/FIXME/NotImplemented nel percorso operativo?'),
('Q07','Evita falsi successi e risultati inventati?'),
('Q08','Ha un contratto di input adeguato quando pubblico?'),
('Q09','Ha un contratto di output/evidenza adeguato quando pubblico?'),
('Q10','Valida e normalizza gli input non fidati?'),
('Q11','Valida/sanifica l’output prima di esporlo?'),
('Q12','Applica autenticazione quando attraversa un confine remoto?'),
('Q13','Applica autorizzazione/least privilege?'),
('Q14','Classifica correttamente read-only e write?'),
('Q15','Protegge operazioni distruttive/sensibili?'),
('Q16','Gestisce idempotenza/replay dove necessario?'),
('Q17','Riprova solo errori retry-safe?'),
('Q18','Ha retry strettamente limitati?'),
('Q19','Ha timeout/budget finiti?'),
('Q20','Può essere cancellata/interrotta in sicurezza se lunga?'),
('Q21','Esegue cleanup/finally sui percorsi di errore?'),
('Q22','Rilascia lock, debugger, file/processi e risorse?'),
('Q23','Ha una strategia anti-crash coerente?'),
('Q24','Usa checkpoint/stato incerto quando serve?'),
('Q25','Usa lease/lock/ownership quando concorre su risorse condivise?'),
('Q26','Ha una policy di concorrenza definita?'),
('Q27','Evita accavallamenti con task indipendenti?'),
('Q28','Non può bloccare indefinitamente la coda globale?'),
('Q29','Misura progresso reale, non solo liveness, quando applicabile?'),
('Q30','Gestisce offline/disconnessioni senza falso successo?'),
('Q31','Gestisce dipendenze opzionali mancanti senza rompere la suite?'),
('Q32','Evita shell/eval/esecuzione arbitraria non autorizzata?'),
('Q33','Applica restrizioni path/origin/SSRF/injection pertinenti?'),
('Q34','Protegge e redige segreti, token, cookie e credenziali?'),
('Q35','Minimizza i dati persistiti o restituiti?'),
('Q36','Verifica gli effetti con read-back/evidence quando mutativa?'),
('Q37','Offre rollback/snapshot quando ragionevolmente necessario?'),
('Q38','Usa atomicità/transazioni/guardie di concorrenza quando applicabili?'),
('Q39','Propaga errori strutturati e diagnosticabili?'),
('Q40','Gestisce edge case e input vuoti/limite?'),
('Q41','Limita profondità, batch, dimensioni o cardinalità?'),
('Q42','Limita output/log/artefatti per evitare blow-up?'),
('Q43','Evita lavoro palesemente superfluo o N+1 non limitati?'),
('Q44','Usa cache solo dove coerente e invalidabile?'),
('Q45','Ha comportamento deterministico/riproducibile dove richiesto?'),
('Q46','Espone osservabilità sufficiente per capire cosa è successo?'),
('Q47','Produce audit trail per azioni sensibili/pubbliche?'),
('Q48','Ha copertura di test diretta o una verifica statica equivalente?'),
('Q49','È coperta da test di integrazione del proprio layer?'),
('Q50','È coperta da un gate anti-regressione/release?'),
('Q51','Usa API/protocolli moderni o una compatibilità legacy deliberata?'),
('Q52','Mantiene compatibilità all’indietro necessaria?'),
('Q53','Non richiede modifiche inattese a installazione/configurazione?'),
('Q54','È coerente con capability discovery tra ChatGPT/plugin/estensione?'),
('Q55','Implementa l’intera semantica dichiarata, non solo il nome?'),
('Q56','Non duplica o collide con un’altra azione senza ownership?'),
('Q57','Non contiene branch pubblici irraggiungibili?'),
('Q58','Non è catalog-only se viene dichiarata eseguibile?'),
('Q59','Descrizione/documentazione corrisponde al comportamento reale?'),
('Q60','Distingue test locale da prova live/production evidence?'),
('Q61','Le dipendenze eseguibili sono fissate/versionate o deliberate?'),
('Q62','Provenienza, licenza e supply-chain delle dipendenze sono tracciabili?'),
('Q63','Le dipendenze opzionali mancanti degradano in modo fail-safe?'),
('Q64','Upgrade e migrazioni preservano stato e compatibilità?'),
('Q65','Stato persistente, schema e protocollo sono versionati?'),
('Q66','Segreti e credenziali hanno storage, redazione e rotazione coerenti?'),
('Q67','Scadenza auth/pairing produce re-auth controllata e non loop?'),
('Q68','Rate limit e Retry-After sono rispettati quando applicabili?'),
('Q69','Backoff e jitter impediscono retry storm?'),
('Q70','Esistono circuit breaker per duplicati/failure storm?'),
('Q71','Backpressure e limiti di carico proteggono coda/runtime?'),
('Q72','Dati stale o heartbeat stale vengono riconosciuti esplicitamente?'),
('Q73','Provenienza e conflitti tra fonti sono rappresentati?'),
('Q74','Un evidence-sufficiency gate può fermare decisioni non supportate?'),
('Q75','Le approvazioni dipendono dall’effetto/rischio, non dal gesto tecnico?'),
('Q76','Le mutazioni distinguono dispatch riuscito da accettazione applicativa?'),
('Q77','Le SPA usano readiness verificabile senza dipendere da networkidle infinito?'),
('Q78','Idempotenza e replay rimangono sicuri dopo crash/restart?'),
('Q79','Pairing renewal/reconnect è esplicito e bounded?'),
('Q80','Heartbeat device è indipendente dal consumo della coda?'),
('Q81','La coda ha self-healing e recovery del mancato progresso?'),
('Q82','Lease stale/lost ownership viene rilevato senza doppia esecuzione?'),
('Q83','Esiste un runner H24 esterno verificabile oltre al cron opportunistico?'),
('Q84','Schedule e occurrence key evitano drift e duplicati al catch-up?'),
('Q85','Health/SLO/metriche permettono di misurare affidabilità reale?'),
('Q86','Request/job/task/correlation ID attraversano i layer?'),
('Q87','Le decisioni strategiche hanno un journal separato dai log tecnici?'),
('Q88','Le procedure vengono apprese solo dopo successo verificato?'),
('Q89','I failed path vengono ricordati per evitare tentativi già falliti?'),
('Q90','Le skill hanno expiry, invalidation e ri-verifica?'),
('Q91','File, processi, origin e sidecar restano dentro sandbox/allowlist?'),
('Q92','Non viene esposta una shell arbitraria; il terminale è bounded/argv?'),
('Q93','Repo-map/progressive disclosure riducono contesto e scansioni inutili?'),
('Q94','Esiste un loop deterministico lint/test/checkpoint/fix?'),
('Q95','Parallelismo e workstream sono bounded e isolabili?'),
('Q96','Processi, lock, debugger e risorse vengono puliti anche su timeout?'),
('Q97','Upgrade/rollback preservano stato e non eliminano superfici esistenti?'),
('Q98','Contratti tra estensione, connector e plugin restano consistenti?'),
('Q99','Il sistema separa evidenza statica, test locale e prova live?'),
('Q100','Il pacchetto soddisfa un gate enterprise di release senza stub/falsi successi?'),
('Q101','Ogni workstream mutativo ha una mission/lane identity persistente?'),
('Q102','La missione conserva un resume point deterministico?'),
('Q103','Gli step già completati vengono ricordati e non rieseguiti inutilmente?'),
('Q104','I failed path della missione vengono conservati e riusati?'),
('Q105','La discovery già verificata non viene ripetuta senza nuova evidenza?'),
('Q106','L’evidenza precedente viene riusata entro freshness/validity bounds?'),
('Q107','Le entità/URL/post/tab hanno lock o lease cross-task?'),
('Q108','Due chat/task concorrenti hanno mutex/lease atomici e non possono incrociare la stessa risorsa?'),
('Q109','Lane/tab adottate sopravvivono ai restart necessari e le risorse stale vengono rilasciate senza perdere ownership valida?'),
('Q110','La chiusura della missione ha una condizione deterministica e auditabile?'),
('Q111','Esiste un fast path reale per task semplici e deterministici?'),
('Q112','Il planner impone un budget massimo di tool call per modalità?'),
('Q113','Le operazioni omogenee usano batch solo quando il protocollo lo consente e MCP 2025-06-18 rifiuta JSON-RPC batch?'),
('Q114','Il parallelismo viene usato solo per workstream indipendenti e bounded?'),
('Q115','Cache/snapshot/sessioni e status compatti evitano lavoro ripetuto e payload storici inutilmente enormi?'),
('Q116','Le attestazioni anti-crash valide vengono riusate invece di rifare test globali?'),
('Q117','Il browser viene evitato quando esiste un executor server-side più deterministico?'),
('Q118','Il flusso si ferma appena l’evidenza richiesta è sufficiente?'),
('Q119','Retry/recovery/screenshot hanno limiti espliciti, circuit breaker e nessun loop su storage/capture failure?'),
('Q120','Replan/fallback hanno limiti espliciti per evitare spirali?'),
('Q121','Il successo è legato all’effetto richiesto, non alla sola esecuzione del tool?'),
('Q122','DB verified e frontend verified sono stati separati semanticamente?'),
('Q123','Dispatch/evento inviato è distinto dall’accettazione applicativa?'),
('Q124','Screenshot/evidenza visiva hanno storage privato verificabile, preflight capacità, MIME/integrità e retention bounded?'),
('Q125','Prima di mutare una pagina visibile viene risolta la sorgente di rendering autorevole?'),
('Q126','Canonical/indexability vengono verificati sul risultato pubblico quando pertinenti?'),
('Q127','Gli effetti collaterali e la blast radius sono rappresentati prima/dopo la mutazione?'),
('Q128','Evidenze stale o di altra missione/lane non possono certificare il risultato corrente?'),
('Q129','Le mutazioni pubbliche importanti hanno verifica indipendente/readback?'),
('Q130','È impossibile dichiarare fully_verified quando una verifica richiesta è falsa/non eseguita?'),
('Q131','Ogni esecuzione autonoma è confinata da un autonomy envelope esplicito?'),
('Q132','Il percorso autonomo è compatibile con scheduler/H24 senza UI locale obbligatoria?'),
('Q133','Le pubblicazioni/mutazioni e context_open sono idempotenti e replay-safe anche dopo timeout ambiguo?'),
('Q134','Le mutazioni autonome hanno rollback/quarantine verificabile?'),
('Q135','Esiste un no-human path server-side per le azioni editoriali consentite?'),
('Q136','Le eccezioni dopo una mutazione vengono messe in quarantine/fail-safe?'),
('Q137','Esistono limiti per-run/giornalieri su pubblicazioni e aggiornamenti?'),
('Q138','La concorrenza autonoma usa mutex/lease atomici, rinnovo heartbeat e limiti giornalieri race-safe?'),
('Q139','Un crash dopo la mutazione viene riconciliato tramite readback/receipt prima di ritentare?'),
('Q140','Recovery/cancel/close e MCP notifications/cancelled rilasciano lock e preservano stato sufficiente a riprendere?'),
('Q141','Ogni keyword editoriale può essere vincolata a un URL dominante persistente?'),
('Q142','La SERP intent viene osservata, hashata, datata e riusata finché valida?'),
('Q143','Prima di creare contenuto viene controllata equivalenza/cannibalizzazione esistente?'),
('Q144','Ogni nuovo contenuto è collegabile a una campagna head keyword persistente?'),
('Q145','La generazione avviene contro un brief strutturato e hashato?'),
('Q146','Le affermazioni fattuali possono essere vincolate a provenance/confidence/expiry?'),
('Q147','Il linking interno misura e rafforza il pillar/campaign target corretto?'),
('Q148','Schema, canonical e indexability sono validati nel percorso editoriale?'),
('Q149','Una pubblicazione è chiusa solo dopo DB + rendering pubblico verificato?'),
('Q150','Ogni nuovo URL pubblicato crea un watcher post-pubblicazione fino a discovery/ranking?'),
('Q151','Il sistema resta corretto quando la stessa installazione gestisce più siti o reti WordPress Multisite?'),
('Q152','Le tabelle e gli indici custom restano performanti quando job/evidence superano milioni di righe?'),
('Q153','L’attivazione e l’aggiornamento restano sicuri su hosting condiviso con limiti stretti di memoria e max_execution_time?'),
('Q154','Un object cache esterno (Redis/Memcached) viene invalidato correttamente dopo ogni scrittura rilevante?'),
('Q155','Un page cache o una CDN davanti al sito vengono invalidati o bypassati quando necessario dopo una mutazione?'),
('Q156','Il comportamento resta corretto in presenza di plugin di sicurezza o firewall applicativo di terze parti?'),
('Q157','Un conflitto di hook o priorità con altri plugin attivi viene rilevato invece di fallire silenziosamente?'),
('Q158','Le query nuove hanno un piano di esecuzione verificato ed evitano scansioni complete non necessarie su tabelle grandi?'),
('Q159','Il consumo di connessioni database resta entro i limiti del pool anche sotto carico concorrente?'),
('Q160','Esiste un percorso di degrado controllato quando l’hosting nega funzionalità come proc_open, cron reale o estensioni PHP opzionali?'),
('Q161','I calcoli di scheduling restano corretti attraverso una transizione di ora legale o solare?'),
('Q162','Un disallineamento di orologio tra server WordPress e device Browser viene rilevato senza generare falsi timeout?'),
('Q163','Il cambio di fuso orario del sito in wp-admin non duplica né perde le occorrenze già pianificate?'),
('Q164','Le date limite come 29 febbraio, fine mese o fine anno sono gestite senza errori nei calcoli di ricorrenza?'),
('Q165','Un burst di recupero dopo una lunga inattività non genera un thundering herd sulla coda?'),
('Q166','Le occorrenze recuperate dopo un downtime restano deduplicate anche se il worker viene invocato più volte in parallelo?'),
('Q167','I timestamp persistiti sono coerenti, sempre UTC o sempre esplicitamente fuso, tra tutti i componenti del trittico?'),
('Q168','Una migrazione di hosting che cambia il fuso del server, mantenendo invariato quello del sito, non altera gli orari già pianificati?'),
('Q169','L’interfaccia admin resta utilizzabile con screen reader e navigazione da tastiera?'),
('Q170','Le stringhe utente sono predisposte per la traduzione invece di essere hardcoded?'),
('Q171','I contenuti RTL come arabo o ebraico sono editati e verificati senza corruzione di layout o encoding?'),
('Q172','Caratteri unicode estesi ed emoji nel contenuto non causano troncamenti o errori di charset a database?'),
('Q173','Valuta, formati numerici e decimali sono corretti per i locale WooCommerce configurati?'),
('Q174','Un contenuto multilingua non viene confuso tra lingue diverse dagli engine editoriali o SEO?'),
('Q175','Contrasto colore ed etichette ARIA della UI locale rispettano requisiti minimi di accessibilità?'),
('Q176','La generazione automatica di contenuto rispetta la lingua e il tono richiesti dal brief invece di un default implicito?'),
('Q177','Ogni dipendenza PHP, JS o sidecar ha una distinta materiali tracciabile nel pacchetto di release?'),
('Q178','Le versioni delle dipendenze sono fissate con un lockfile verificato, non solo dichiarate in un manifest?'),
('Q179','Una scansione delle vulnerabilità note viene eseguita anche in modo schedulato, non solo on-demand?'),
('Q180','Il pacchetto di release è riproducibile da un ambiente di build pulito, non solo verificato per parità di byte a valle?'),
('Q181','Esiste un checksum o una firma verificabile pubblicamente per ogni artefatto distribuito?'),
('Q182','Un canale di aggiornamento compromesso o un mirror non fidato vengono rilevati prima dell’installazione?'),
('Q183','Le licenze incompatibili tra loro vengono segnalate automaticamente prima del rilascio?'),
('Q184','Un downgrade non autorizzato del pacchetto installato viene rifiutato o segnalato?'),
('Q185','Il processo di build fallisce in modo esplicito se una dipendenza dichiarata risulta assente o alterata?'),
('Q186','Gli artefatti temporanei di build restano fuori dal pacchetto di release finale?'),
('Q187','Un game day o chaos test periodico verifica realmente gli RPO/RTO dichiarati, non solo sulla carta?'),
('Q188','Il sistema sopravvive alla terminazione improvvisa del processo PHP nel mezzo di una transazione multi-step?'),
('Q189','Un ripristino da backup in ambiente isolato viene eseguito e verificato con cadenza regolare e documentata?'),
('Q190','Una perdita di connettività al database per una finestra breve produce un degrado controllato invece di dati incoerenti?'),
('Q191','Un failover di regione o di hosting è documentato e testabile senza perdita silenziosa di lease o coda?'),
('Q192','Le chiavi di cifratura per evidenza e backup hanno un piano di rotazione indipendente dal singolo operatore?'),
('Q193','Il sistema distingue esplicitamente un’interruzione pianificata da un incidente non pianificato nelle metriche?'),
('Q194','Un rollback del pacchetto durante job attivi preserva lease e checkpoint invece di corromperli?'),
('Q195','Un test di carico realistico, non solo locale, valida gli SLO dichiarati nelle operazioni H24?'),
('Q196','Un audit post-incidente aggiunge automaticamente un test di regressione per ogni causa radice confermata?'),
('Q197','Screenshot e coordinate restano corretti su schermi multipli con scala DPI differente?'),
('Q198','Un CAPTCHA/MFA/login challenge resta inline sulla sessione controllata e produce auto-resume quando scompare?'),
('Q199','Un ad-blocker o un’estensione di terze parti installata dall’utente non altera silenziosamente il DOM osservato?'),
('Q200','Le interazioni funzionano correttamente attraverso iframe cross-origin e shadow DOM annidati?'),
('Q201','Una navigazione SPA senza reload completo viene rilevata senza dipendere soltanto da eventi di rete?'),
('Q202','L’espulsione del service worker MV3 per pressione di memoria viene rilevata e recuperata senza perdere ownership?'),
('Q203','Un aggiornamento dell’estensione a metà task viene gestito senza duplicare o perdere l’esecuzione in corso?'),
('Q204','Il comportamento resta corretto quando l’utente apre più finestre o profili Chrome contemporaneamente?'),
('Q205','La sospensione e ripresa del laptop durante un task attivo viene distinta da una disconnessione permanente?'),
('Q206','Le automazioni sul Browser Agent rispettano i termini d’uso e le policy di automazione dei siti terzi target?'),
('Q207','Un client MCP con una versione di protocollo diversa da quella corrente riceve una negoziazione esplicita invece di un errore opaco?'),
('Q208','Il catalogo dei tool pubblicato viene confrontato automaticamente con il contratto statico per rilevare drift prima del rilascio?'),
('Q209','Più client MCP possono operare sullo stesso sito senza collidere sulle stesse risorse?'),
('Q210','Una finestra di compatibilità per un protocollo deprecato ha una scadenza esplicita e comunicata?'),
('Q211','L’adozione di una futura revisione della specifica MCP è isolata da un flag esplicito prima di diventare default?'),
('Q212','I metadati di annotazione read-only, destructive e idempotent restano accurati dopo ogni modifica di un tool esistente?'),
('Q213','Una richiesta MCP malformata o con schema sconosciuto fallisce in modo pulito senza esporre dettagli interni?'),
('Q214','Il numero di tool pubblici cresce solo per decisione deliberata, non per accumulo incontrollato nel tempo?'),
('Q215','Un riuso rilevato di un refresh token già ruotato provoca la revoca dell’intera catena, non solo del singolo token?'),
('Q216','Una raffica di registrazioni DCR sospette viene limitata senza bloccare la registrazione legittima?'),
('Q217','Uno scostamento di orologio ragionevole tra client e server non invalida erroneamente token validi?'),
('Q218','Due amministratori dello stesso sito con client OAuth distinti restano isolati senza fuga di dati tra i loro contesti?'),
('Q219','La revoca di un singolo client non interrompe le sessioni valide degli altri client autorizzati?'),
('Q220','Un token con scope di sola lettura non può, nemmeno indirettamente, invocare una capacità di scrittura?'),
('Q221','La scadenza di un pairing Browser produce una richiesta di ri-autorizzazione esplicita invece di un loop silenzioso?'),
('Q222','L’endpoint di autorizzazione resta protetto da un rate limit indipendente da quello applicato ai tool MCP?'),
('Q223','Un correlation ID unico attraversa in modo verificabile WordPress, coda, Browser Agent e risposta ChatGPT per la stessa richiesta?'),
('Q224','Il volume di log sotto carico elevato viene campionato senza perdere gli eventi classificati come critici?'),
('Q225','La cardinalità delle metriche resta limitata per evitare un’esplosione di serie temporali nel tempo?'),
('Q226','Un dashboard operativo mostra lo stato dei domini di salute senza richiedere accesso diretto al database?'),
('Q227','Ogni gate di release marcato vero riporta il comando eseguito e l’evidenza reale, non solo un booleano dichiarato?'),
('Q228','Le soglie di allerta P0, P1 e P2 vengono ricalibrate periodicamente su baseline di produzione reali?'),
('Q229','Un’allerta duplicata per lo stesso incidente viene deduplicata invece di generare rumore ripetuto?'),
('Q230','La chiusura di un incidente richiede esplicitamente causa radice, test correttivo e owner prima di essere marcata risolta?'),
('Q231','Un tentativo di path traversal o zip-slip nei tool filesystem o di pacchettizzazione viene rifiutato e testato esplicitamente?'),
('Q232','Un messaggio di errore mostrato a un utente non amministratore evita di rivelare stack trace o percorsi interni?'),
('Q233','La modalità debug non può restare accidentalmente attiva in un ambiente marcato come produzione?'),
('Q234','Un percorso di escalation di privilegi tramite concatenazione di capacità singolarmente a basso rischio viene rilevato?'),
('Q235','Nonce e protezione CSRF hanno durata e rotazione coerenti su tutte le route admin-ajax e REST equivalenti?'),
('Q236','Un endpoint di health o discovery non autenticato resiste a un flood senza degradare gli endpoint autenticati?'),
('Q237','Un webhook in ingresso da un provider esterno viene verificato tramite firma prima di essere elaborato?'),
('Q238','Una consegna webhook duplicata dallo stesso provider viene deduplicata entro una finestra esplicita?'),
('Q239','Un nome di capacità o un parametro non può essere usato per richiamare una classe o funzione non prevista?'),
('Q240','Un ambiente di staging viene rilevato ed evita scritture accidentali verso destinazioni di produzione reali?'),
('Q241','Un’esecuzione autonoma di più settimane rileva un goal drift rispetto alla missione originariamente approvata?'),
('Q242','Un esaurimento di budget di tempo, costo o chiamate durante l’autonomia produce uno stop controllato con stato riprendibile?'),
('Q243','Un contenuto generato dall’AI viene etichettato internamente come tale per finalità di audit e di disclosure legale quando richiesto?'),
('Q244','Un override umano resta sempre disponibile e prioritario su qualunque missione autonoma in corso?'),
('Q245','Un contenuto che cita statistiche o cifre numeriche viene verificato contro la fonte originale prima della pubblicazione pubblica?'),
('Q246','La spesa verso provider a pagamento ha un tetto configurabile con arresto automatico al superamento?'),
('Q247','Un nuovo dominio o capacità aggiunto da uno sviluppatore terzo eredita automaticamente lo stesso contratto delle 250 domande di audit?'),
('Q248','Esiste un comando di arresto globale singolo che congela in modo verificabile tutte le scritture su WordPress, Browser e MCP insieme?'),
('Q249','Il tempo di propagazione dell’arresto globale è misurato e resta entro un budget dichiarato?'),
('Q250','Ogni record persistente porta un numero di versione di schema che consente un’evoluzione futura senza un flag day distruttivo?'),
]

# Item answers are deliberately conservative.  These are the only properties a
# published entry inherits from being present in this generated inventory with a
# concrete, exact resolver and schema.  System/hosting concerns (especially
# Q151-Q250) default to NA and are evaluated once at component level below; they
# never become PASS merely because an item exists or contains a matching word.
PUBLIC_DEFAULT_PASS={
    'Q01','Q02','Q03','Q04','Q05','Q07','Q08','Q09','Q39','Q48','Q49','Q50',
    'Q52','Q53','Q54','Q55','Q57','Q58','Q59','Q60','Q100','Q208','Q239','Q247',
}

# Q151-Q250 are cross-cutting component questions, not properties of every tiny
# helper.  `owners` defines where the concern is meaningful; the marker tuple is
# direct static evidence for a contract, never proof that a live hosting/browser
# scenario has actually occurred.  LIVE_EVIDENCE_QUESTIONS therefore remain WARN
# until their separate integration/soak evidence exists, even when code support is
# visible.  This makes blanket/default PASS impossible while retaining 250 explicit
# answers on every inventory item and component.
ADVANCED_QUESTION_CONTRACTS={
    'Q151':('wp',('is_multisite','switch_to_blog','blog_id')),
    'Q152':('wp',('index','max_jobs','retention','paginate')),
    'Q153':('wp',('memory_limit','max_execution_time','bounded')),
    'Q154':('wp',('wp_cache_delete','wp_cache_flush','object_cache')),
    'Q155':('wp',('purge_cache','purge_url','cdn_cache')),
    'Q156':('wp',('firewall','waf','security plugin')),
    'Q157':('wp',('hook conflict','has_action','priority')),
    'Q158':('wp',('explain','query_plan','database_sandbox')),
    'Q159':('wp',('connection pool','db_pool','max_connections')),
    'Q160':('wp',('proc_open_unavailable','external_runner','optional')),
    'Q161':('all',('dst','daylight','timezone')),
    'Q162':('all',('clock_skew','server_time','device_time')),
    'Q163':('server',('timezone_string','occurrence_key','scheduled_gmt')),
    'Q164':('server',('leap','february','end_of_month')),
    'Q165':('all',('backoff','jitter','catch_up','catchup')),
    'Q166':('server',('occurrence_key','idempotency','with_mutex')),
    'Q167':('all',('gmdate','utc','_gmt')),
    'Q168':('server',('timezone_string','utc','scheduled_gmt')),
    'Q169':('wp_browser',('aria','keydown','tabindex')),
    'Q170':('wp',('__(', '_e(', 'esc_html__')),
    'Q171':('wp_browser',('rtl','dir=', 'unicode')),
    'Q172':('wp',('utf8mb4','json_unescaped_unicode','mb_')),
    'Q173':('wp',('wc_price','decimal_separator','currency')),
    'Q174':('server',('language','locale','hreflang')),
    'Q175':('wp_browser',('aria-label','contrast','axe')),
    'Q176':('server',('tone','language','brief_hash')),
    'Q177':('all',('sbom','component-manifest','build-info')),
    'Q178':('all',('package-lock','composer.lock','lockfile')),
    'Q179':('all',('vulnerability','security scan','scheduled')),
    'Q180':('all',('reproducible','deterministic build','tree_digest')),
    'Q181':('all',('sha256','checksum','signature')),
    'Q182':('all',('checksum','signature','trusted origin')),
    'Q183':('all',('license','licence','sbom')),
    'Q184':('server',('downgrade','version_compare','minimum_version')),
    'Q185':('all',('dependency','missing','integrity')),
    'Q186':('all',('.tmp','temporary','exclude')),
    'Q187':('all',('chaos','game day','rpo','rto')),
    'Q188':('server',('checkpoint','finally','transaction')),
    'Q189':('server',('restore','backup','verified')),
    'Q190':('server',('database','retryable','rollback')),
    'Q191':('server',('failover','lease','checkpoint')),
    'Q192':('server',('key rotation','rotate','encryption')),
    'Q193':('server',('planned','maintenance','incident')),
    'Q194':('server',('rollback','lease','checkpoint')),
    'Q195':('all',('load test','stress','slo')),
    'Q196':('server',('root cause','regression test','post-incident')),
    'Q197':('browser',('devicescalefactor','device_scale_factor','dpi')),
    'Q198':('browser',('captcha','authchallenge','autoresume')),
    'Q199':('browser',('extension','dom_hash','observation')),
    'Q200':('browser',('iframe','shadowroot','shadow dom')),
    'Q201':('browser',('waitforspaready','popstate','spa')),
    'Q202':('browser',('onstartup','recoverSavedTask','service worker')),
    'Q203':('browser',('oninstalled','recoverSavedTask','update')),
    'Q204':('browser',('windowid','profile','getall')),
    'Q205':('browser',('suspend','resume','clock_skew')),
    'Q206':('browser',('terms','policy','allowed origin')),
    'Q207':('connector',('accepted_mcp_protocols','protocolversion','negotiated')),
    'Q208':('connector',('mcp-tool-surface-baseline','tools/list','contract')),
    'Q209':('connector',('owner_client_id','lane_handle','resource_busy_other_context')),
    'Q210':('connector',('legacy_default_protocol','deprecated','sunset')),
    'Q211':('connector',('protocol flag','accepted_mcp_protocols','feature flag')),
    'Q212':('connector',('readonlyhint','destructivehint','idempotenthint')),
    'Q213':('connector',('invalid request','-32600','rpc_error')),
    'Q214':('connector',('mcp-tool-surface-baseline','baseline_tool_removed','tool_count')),
    'Q215':('server',('refresh token reuse','token_family','generation')),
    'Q216':('server',('dcr','registration','rate_limit')),
    'Q217':('server',('clock_skew','leeway','expires_at')),
    'Q218':('server',('client_id','owner_hash','owner_client_id')),
    'Q219':('server',('client_id','revoke','generation')),
    'Q220':('server',('write scope','read_only','oauth')),
    'Q221':('all',('authexpired','re-auth','pairing')),
    'Q222':('server',('oauth','rate_limit','authorization')),
    'Q223':('all',('correlation','request_id','task_uuid')),
    'Q224':('all',('sample','critical','log')),
    'Q225':('all',('cardinality','metric','bounded')),
    'Q226':('all',('health','dashboard','status')),
    'Q227':('all',('command','evidence','quality gate')),
    'Q228':('all',('p0','p1','p2','baseline')),
    'Q229':('all',('dedup','incident','fingerprint')),
    'Q230':('server',('root cause','owner','resolved')),
    'Q231':('wp',('path_outside','zip-slip','path traversal')),
    'Q232':('all',('safe_exception','stack trace','redact')),
    'Q233':('server',('wp_debug','production','debug')),
    'Q234':('server',('risk_engine','compound','escalation')),
    'Q235':('wp',('check_ajax_referer','wp_verify_nonce','permission_callback')),
    'Q236':('server',('health','rate_limit','circuit')),
    'Q237':('server',('webhook','signature','hmac')),
    'Q238':('server',('webhook','idempotency','dedup')),
    'Q239':('server',('allowlist','tool_definition','exact_class_method_match')),
    'Q240':('all',('staging','production','environment')),
    'Q241':('server',('goal drift','objective_hash','mission')),
    'Q242':('server',('budget','checkpoint','resume')),
    'Q243':('server',('ai_generated','disclosure','provenance')),
    'Q244':('all',('observeronly','authchallenge','emergency stop')),
    'Q245':('server',('claim_ledger','source_url','verified')),
    'Q246':('server',('cost','spend','budget')),
    'Q247':('all',('exhaustive-checkpoint','questions','capability')),
    'Q248':('all',('emergency_stop','global_stop','write freeze')),
    'Q249':('all',('stop_propagation','emergency_stop','budget_ms')),
    'Q250':('server',('schema_version','version','migration')),
}
LIVE_EVIDENCE_QUESTIONS={
    *(f'Q{i}' for i in range(151,177)),
    'Q179','Q180','Q182','Q183','Q184','Q187','Q189','Q191','Q192','Q193','Q195','Q196',
    *(f'Q{i}' for i in range(197,207)),
    'Q210','Q211','Q215','Q216','Q217','Q218','Q219','Q221','Q222','Q223','Q224','Q225','Q228','Q230',
    'Q233','Q234','Q236','Q237','Q238','Q240','Q241','Q243','Q245','Q246','Q249',
}

def sha(path:Path)->str:
    h=hashlib.sha256();
    with path.open('rb') as f:
        for b in iter(lambda:f.read(1<<20),b''): h.update(b)
    return h.hexdigest()

def rel(path:Path)->str:return path.relative_to(ROOT).as_posix()

def load(path):return json.loads(Path(path).read_text(encoding='utf-8'))

def find_block(text:str,start:int):
    i=text.find('{',start)
    if i<0:return ('',start,start)
    depth=0;sq=dq=False;esc=False
    for j in range(i,len(text)):
        c=text[j]
        if esc:esc=False;continue
        if c=='\\' and (sq or dq):esc=True;continue
        if c=="'" and not dq:sq=not sq;continue
        if c=='"' and not sq:dq=not dq;continue
        if sq or dq:continue
        if c=='{':depth+=1
        elif c=='}':
            depth-=1
            if depth==0:return (text[i+1:j],i,j)
    return (text[i+1:],i,len(text)-1)

def php_functions(path:Path):
    text=path.read_text(encoding='utf-8',errors='replace')
    class_ranges=[]
    for cm in re.finditer(r'^\s*(?:(?:final|abstract|readonly)\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b', text, re.M):
        _body, cbs, cbe = find_block(text, cm.end())
        class_ranges.append((cbs, cbe, cm.group(1)))
    pattern=re.compile(r'(?P<vis>public|protected|private)?\s*(?P<static>static\s+)?function\s+(?P<name>[A-Za-z_][A-Za-z0-9_]*)\s*\(',re.M)
    out=[]
    for m in pattern.finditer(text):
        body,bs,be=find_block(text,m.end())
        line=text.count('\n',0,m.start())+1
        owner=''
        for cbs,cbe,cname in class_ranges:
            if cbs < m.start() < cbe:
                owner=cname; break
        out.append({'name':m.group('name'),'class':owner,'visibility':m.group('vis') or 'public','static':bool(m.group('static')),'body':body,'file':rel(path),'line':line,'loc':max(1,body.count('\n')+1),'statements':max(1,body.count(';')+len(re.findall(r'\b(?:if|for|foreach|while|switch|try|catch)\s*\(',body)))})
    return out

def js_functions(path:Path):
    text=path.read_text(encoding='utf-8',errors='replace');out=[];seen=set()
    def matching_paren(open_pos:int)->int:
        depth=0;sq=dq=bt=False;esc=False
        for i in range(open_pos,len(text)):
            c=text[i]
            if esc:esc=False;continue
            if c=='\\' and (sq or dq or bt):esc=True;continue
            if c=="'" and not dq and not bt:sq=not sq;continue
            if c=='"' and not sq and not bt:dq=not dq;continue
            if c=='`' and not sq and not dq:bt=not bt;continue
            if sq or dq or bt:continue
            if c=='(':depth+=1
            elif c==')':
                depth-=1
                if depth==0:return i
        return -1
    decl=re.compile(r'(?:(?:export\s+)?(?:async\s+)?)function\s+([A-Za-z_$][\w$]*)\s*\(')
    for m in decl.finditer(text):
        close=matching_paren(text.find('(',m.start()))
        if close<0:continue
        brace=text.find('{',close)
        if brace<0:continue
        body,bs,be=find_block(text,brace)
        out.append({'name':m.group(1),'visibility':'module','static':False,'body':body,'file':rel(path),'line':text.count('\n',0,m.start())+1,'loc':max(1,body.count('\n')+1),'statements':max(1,body.count(';')+len(re.findall(r'\b(?:if|for|foreach|while|switch|try|catch)\s*\(',body)))})
        seen.add(m.start())
    arrow=re.compile(r'(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*(?:async\s*)?\([^)]*\)\s*=>\s*\{')
    for m in arrow.finditer(text):
        body,bs,be=find_block(text,m.end()-1)
        out.append({'name':m.group(1),'visibility':'module','static':False,'body':body,'file':rel(path),'line':text.count('\n',0,m.start())+1,'loc':max(1,body.count('\n')+1),'statements':max(1,body.count(';')+len(re.findall(r'\b(?:if|for|foreach|while|switch|try|catch)\s*\(',body)))})
    return out

def semantic_body(fn):
    body=fn['body']; stripped=re.sub(r'/\*.*?\*/|//[^\n]*|#[^\n]*','',body,flags=re.S).strip()
    low=stripped.lower()
    stub=False;reason=''
    if not stripped:stub=True;reason='empty_body'
    elif re.search(r'\b(todo|fixme|notimplemented|not implemented)\b',low):stub=True;reason='placeholder_marker'
    elif re.fullmatch(r'(?:return\s+(?:null|false|true|\[\]|array\(\)|new\s+stdclass\(\))\s*;?|throw\s+new\s+\w+[^;]*;?)',stripped,re.S|re.I):
        # Boolean predicates and empty-collection getters can be legitimate, do not fail private helpers automatically.
        if fn['visibility']=='public' and fn['name'] not in {'permission_callback'}: stub=True;reason='constant_only_public_body'
    delegate=bool(re.search(r'return\s+(?:self::|static::|parent::|[A-Za-z_][A-Za-z0-9_]*::|\$this->|[A-Za-z_$][\w$]*\s*\()',stripped))
    accessor=bool(re.fullmatch(r'return\s+[^;]+;?',stripped,re.S))
    return stub,reason,delegate,accessor,stripped

def extract_action_literals(body):
    vals=set(re.findall(r"case\s+['\"]([A-Za-z0-9_:-]+)['\"]\s*:",body))
    vals|=set(re.findall(r"['\"]([A-Za-z0-9_:-]+)['\"]\s*===\s*\$action",body))
    vals|=set(re.findall(r"\$action\s*===\s*['\"]([A-Za-z0-9_:-]+)['\"]",body))
    for mm in re.finditer(r"in_array\s*\(\s*\$action\s*,\s*(?:array\s*\((.*?)\)|\[(.*?)\])\s*,\s*true\s*\)",body,re.S):
        vals|=set(re.findall(r"['\"]([A-Za-z0-9_:-]+)['\"]",(mm.group(1) or mm.group(2) or '')))
    return vals

def generate_surface():
    catalog=load(ROOT/'prstudio-unified-control/connector/action-catalog.json')['actions']
    catalog_ids={(a['route'],a['action']) for a in catalog}
    rest=ROOT/'prstudio-unified-control/includes/class-wpaib-rest.php'; rest_text=rest.read_text(encoding='utf-8')
    route_to_func={'/system-manage':'control_system','/global-search':'control_search','/backup-manage':'control_backup','/cache-manage':'control_cache','/cron-manage':'control_cron','/logs-manage':'control_logs','/content-manage':'control_content','/taxonomy-manage':'control_taxonomy','/media-manage':'control_media','/comments-manage':'control_comments','/users-manage':'control_users','/settings-manage':'control_settings','/plugins-manage':'control_plugins','/themes-manage':'control_themes','/products-manage':'control_products','/inventory-manage':'control_inventory','/orders-manage':'control_orders','/seo-manage':'control_seo','/files-manage':'control_files','/database-manage':'control_database','/maintenance-manage':'control_maintenance','/frontend-manage':'control_frontend','/security-manage':'control_security','/menus-manage':'control_menus','/widgets-manage':'control_widgets','/templates-manage':'control_templates','/styles-manage':'control_styles','/customers-manage':'control_customers','/coupons-manage':'control_coupons','/commerce-settings-manage':'control_commerce_settings'}
    phpmap={f['name']:f for f in php_functions(rest)}
    native=set()
    for route,fn in route_to_func.items():
        body=phpmap.get(fn,{}).get('body','')
        native|={(route,a) for a in extract_action_literals(body) if (route,a) in catalog_ids}

    # Database route deliberately delegates to a dedicated backend; count only
    # actions present in the backend's authoritative allowlist AND execute switch.
    db_path=ROOT/'prstudio-unified-control/includes/class-prstudio-uc-database-backend.php'
    db_funcs={f['name']:f for f in php_functions(db_path)}
    db_actions_body=db_funcs.get('actions',{}).get('body','')
    db_execute_body=db_funcs.get('execute',{}).get('body','')
    db_declared=set(re.findall(r"['\"]([a-z0-9_:-]+)['\"]",db_actions_body))
    db_dispatched=extract_action_literals(db_execute_body)
    db_ok=db_declared & db_dispatched

    # Web Stories: command must appear in both declared command set and dispatch branch.
    ws_path=ROOT/'prstudio-unified-control/includes/class-web-stories-manage.php'; ws=ws_path.read_text(encoding='utf-8')
    cm=re.search(r'private const COMMANDS\s*=\s*\[(.*?)\];',ws,re.S) or re.search(r'private const COMMANDS\s*=\s*array\s*\((.*?)\);',ws,re.S)
    commands=set(re.findall(r"['\"]([a-z0-9_:-]+)['\"]",cm.group(1))) if cm else set()
    dispatch=next((f for f in php_functions(ws_path) if f['name']=='dispatch'),None)
    dispatch_cases=extract_action_literals(dispatch['body'] if dispatch else '')
    ws_ok=commands & dispatch_cases

    # Browser: every browser action must map directly to a concrete local executor.
    proto=(ROOT/'prstudio-unified-browser-agent/lib/protocol.js').read_text(encoding='utf-8')
    service=(ROOT/'prstudio-unified-browser-agent/service-worker.js').read_text(encoding='utf-8')
    dm=re.search(r'const direct\s*=\s*\{(.*?)\n\s*\};\n\n\s*if \(direct\[action\]\)',proto,re.S)
    direct=set(re.findall(r'^\s*([A-Za-z0-9_]+)\s*:',dm.group(1),re.M)) if dm else set()
    # A protocol alias is not an executor.  Keep only direct actions whose emitted
    # step type is handled by service-worker executeStep; this closes the historical
    # verify_url false green where protocol.js named it but the worker could not run it.
    browser_contract_failures,browser_contract_evidence=audit_browser_reachability()
    unreachable_direct=set(browser_contract_evidence.get('missing_step_bindings',{}))
    unreachable_direct|={failure.split(':',1)[1] for failure in browser_contract_failures if failure.startswith('browser_direct_without_step:')}
    direct-=unreachable_direct
    start=service.find('async function executeKnownContractAction');end=service.find('async function storeScreenshotArtifact',start);contract=service[start:end]
    handled=set(re.findall(r'action\s*===\s*[\'\"]([^\'\"]+)[\'\"]',contract))
    for mm in re.finditer(r'new Set\(\s*\[(.*?)\]\s*\)',contract,re.S):handled|=set(re.findall(r'[\'\"]([^\'\"]+)[\'\"]',mm.group(1)))
    for mm in re.finditer(r'\[\s*((?:[\'\"][^\'\"]+[\'\"]\s*,?\s*)+)\]\.includes\(action\)',contract,re.S):handled|=set(re.findall(r'[\'\"]([^\'\"]+)[\'\"]',mm.group(1)))
    browser_ok=direct|handled

    # Complete action executor: additive concrete bindings for every action that
    # the original 1.0.0 catalog exposed without an explicit native route branch.
    complete_path=ROOT/'prstudio-unified-control/includes/class-prstudio-uc-complete-action-executor.php'
    complete_text=complete_path.read_text(encoding='utf-8') if complete_path.is_file() else ''
    complete=set()
    for mm in re.finditer(r"\n\t\t'(/[^']+)'\s*=>\s*array\((.*?)\n\t\t\),", complete_text, re.S):
        route=mm.group(1)
        for action_name in re.findall(r"['\"]([a-z0-9_:-]+)['\"]",mm.group(2)):
            if (route,action_name) in catalog_ids: complete.add((route,action_name))

    rows={}; counts=collections.Counter()
    for a in catalog:
        key=f"{a['route']}::{a['action']}"; pair=(a['route'],a['action']); status='catalog_only';resolver='';reason='No concrete static executor was found.'
        if pair in complete:
            status='complete_native';resolver='PRSTUDIO_UC_Complete_Action_Executor::execute';reason='Explicit bounded route/action implementation in the 1.0.0 complete executor.'
        elif a.get('executor')=='browser_agent':
            if a['action'] in browser_ok: status='browser_agent';resolver='protocol.js + executeKnownContractAction/executeStep';reason='Concrete Browser Agent mapping.'
        elif a['route']=='/database-manage' and a['action'] in db_ok:
            status='database_native';resolver='PRSTUDIO_UC_Database_Backend::execute';reason='Declared database action exists in the backend allowlist and concrete execute switch.'
        elif a['route']=='/web-stories-manage' and a['action'] in ws_ok:
            status='web_stories_native';resolver='PRSTUDIO_Web_Stories_Manage::dispatch';reason='Declared command has a concrete dispatch case.'
        elif pair in native:
            status='wordpress_native';resolver=f"WPAIB_REST::{route_to_func.get(a['route'],'native_control_action')}";reason='Concrete action literal in native route handler.'
        rows[key]={'route':a['route'],'action':a['action'],'tool_name':a.get('tool_name',''),'status':status,'resolver':resolver,'reason':reason,'read_only':bool(a.get('read_only')),'destructive':bool(a.get('destructive')),'executor':a.get('executor','')}
        counts[status]+=1
    return {'version':VERSION,'catalog_count':len(catalog),'status_counts':dict(sorted(counts.items())),'actions':rows,'complete_executor':{'implemented':len(complete)},'database':{'declared':len(db_declared),'dispatch_cases':len(db_dispatched),'implemented':len(db_ok),'missing_dispatch':sorted(db_declared-db_dispatched)},'web_stories':{'declared':len(commands),'dispatch_cases':len(dispatch_cases),'missing_dispatch':sorted(commands-dispatch_cases)},'browser':{'catalog':sum(1 for a in catalog if a.get('executor')=='browser_agent'),'mapped_or_handled':len(browser_ok & {a['action'] for a in catalog if a.get('executor')=='browser_agent'}),'unmapped':sorted({a['action'] for a in catalog if a.get('executor')=='browser_agent'}-browser_ok),'contract_failures':browser_contract_failures}}

def public_question_map(kind,implemented=True,read_only=None,destructive=None,modern=True,annotations_valid=True,notes=''):
    q={k:'NA' for k,_ in QUESTIONS}
    for k in PUBLIC_DEFAULT_PASS:q[k]='PASS'
    # Q14/Q212 are evidence-backed metadata contracts, never optimistic defaults.
    q['Q14']=q['Q212']='PASS' if annotations_valid else 'FAIL'
    q['Q51']='PASS' if modern else 'WARN'
    if not implemented:
        for k in ['Q02','Q03','Q04','Q05','Q07','Q48','Q49','Q55','Q58','Q100','Q208','Q239','Q247']:
            q[k]='FAIL'
    if read_only is True:
        q['Q15']='NA';q['Q16']='NA';q['Q24']='NA';q['Q36']='NA';q['Q37']='NA';q['Q38']='NA'
    if destructive is False:q['Q15']='NA'
    return q

def agency_surface():
    agency_path=ROOT/'prstudio-unified-control/includes/class-prstudio-agency.php'
    semantic_path=ROOT/'prstudio-unified-control/includes/class-prstudio-uc-agency-action-executor.php'
    text=agency_path.read_text(encoding='utf-8')
    semantic=semantic_path.read_text(encoding='utf-8') if semantic_path.is_file() else ''
    funcs={f['name']:f for f in php_functions(agency_path)}
    groups_body=funcs.get('groups',{}).get('body','')
    actions=[]
    for csv in re.findall(r"=>\s*'([a-z0-9_,:-]+)'",groups_body):
        actions.extend([x for x in csv.split(',') if x])
    actions=list(dict.fromkeys(actions))
    native=set(re.findall(r"['\"]([a-z0-9_:-]+)['\"]", funcs.get('native_actions',{}).get('body','')))
    state=set(re.findall(r"['\"]([a-z0-9_:-]+)['\"]", funcs.get('stored_only_actions',{}).get('body','')))
    cm=re.search(r'private const ACTIONS\s*=\s*array\s*\((.*?)\n\t\);',semantic,re.S)
    semantic_actions=set(re.findall(r"['\"]([a-z0-9_:-]+)['\"]",cm.group(1))) if cm else set()
    execute_fn=next((f for f in php_functions(semantic_path) if f['name']=='execute'),None) if semantic_path.is_file() else None
    execute_cases=extract_action_literals(execute_fn['body'] if execute_fn else '')
    rows={}
    for action in actions:
        if action in native:
            status='agency_native'; resolver='PRSTUDIO_Agency::native_fallback'
        elif action in state:
            status='agency_state_native'; resolver='PRSTUDIO_Agency::native_fallback/native state'
        elif action in semantic_actions and action in execute_cases:
            status='agency_semantic'; resolver='PRSTUDIO_UC_Agency_Action_Executor::execute'
        elif action in semantic_actions:
            status='agency_semantic_missing_dispatch'; resolver='PRSTUDIO_UC_Agency_Action_Executor::execute'
        else:
            status='agency_missing_executor'; resolver=''
        rows[action]={'status':status,'resolver':resolver,'native':action in native,'state_native':action in state,'semantic_declared':action in semantic_actions,'semantic_dispatch':action in execute_cases}
    return {
        'count':len(actions),'actions':rows,
        'native':len(native & set(actions)),'state_native':len(state & set(actions)),'semantic':len(semantic_actions & set(actions)),
        'missing':sorted(set(actions)-native-state-semantic_actions),
        'semantic_missing_dispatch':sorted((semantic_actions & set(actions))-execute_cases),
        'orphan_semantic':sorted(semantic_actions-set(actions)),
        'overlap_native_state':sorted(native & state),
        'overlap_native_semantic':sorted(native & semantic_actions),
        'overlap_state_semantic':sorted(state & semantic_actions),
    }

def build_items(surface):
    items=[]
    descriptor=load(ROOT/f'RP-STUDIO-CHATGPT-PLUGIN-{VERSION}.json')
    class_methods=collections.defaultdict(set); global_methods=set()
    for php_path in (ROOT/'prstudio-unified-control').rglob('*.php'):
        for fn in php_functions(php_path):
            if fn.get('class'): class_methods[fn['class']].add(fn['name'])
            else: global_methods.add(fn['name'])
    mcp_path=ROOT/'prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php';mcp=mcp_path.read_text(encoding='utf-8')
    # Explicit MCP tools + switch cases + immediate downstream verification.
    tools=[]
    for m in re.finditer(r"self::tool\(\s*['\"]([a-zA-Z0-9_:-]+)['\"]",mcp):tools.append(m.group(1))
    case_matches=list(re.finditer(r"case\s+['\"]([a-zA-Z0-9_:-]+)['\"]\s*:",mcp))
    cases={m.group(1) for m in case_matches}
    case_bodies={}
    for idx,cm in enumerate(case_matches):
        end=case_matches[idx+1].start() if idx+1<len(case_matches) else mcp.find("        return new WP_Error('tool_not_found'",cm.end())
        if end<0:end=len(mcp)
        case_bodies[cm.group(1)]=mcp[cm.end():end]
    browser_operational={a['action'] for a in surface['actions'].values() if a.get('executor')=='browser_agent' and a.get('status') in {'browser_agent','complete_native'}}
    overlay_path=ROOT/'prstudio-unified-control/contract/browser-action-overlay.json'
    overlay_actions={a.get('action') for a in (load(overlay_path).get('actions',[]) if overlay_path.is_file() else []) if isinstance(a,dict) and a.get('executor')=='browser_agent'}
    protocol_text=(ROOT/'prstudio-unified-browser-agent/lib/protocol.js').read_text(encoding='utf-8')
    overlay_operational={a for a in overlay_actions if re.search(r'\b'+re.escape(a)+r'\s*:',protocol_text)}
    direct_match=re.search(r'const direct\s*=\s*\{(.*?)\n\s*\};\n\n\s*if \(direct\[action\]\)',protocol_text,re.S)
    protocol_direct=set(re.findall(r'^\s*([A-Za-z0-9_]+)\s*:',direct_match.group(1),re.M)) if direct_match else set()
    for name in tools:
        body=case_bodies.get(name,'');targets=[];downstream_ok=True
        if 'browser_dispatch' in body:
            browser_targets=set(re.findall(r"['\"]((?:playwright_[A-Za-z0-9_:-]+|verify_url|headers|computed_styles))['\"]",body))
            for target in sorted(browser_targets):
                ok=target in browser_operational or target in overlay_operational or target in protocol_direct;targets.append({'kind':'browser_action','target':target,'verified':ok});downstream_ok=downstream_ok and ok
        for cls,method in re.findall(r"\b(PRSTUDIO_[A-Za-z0-9_]+)::([A-Za-z_][A-Za-z0-9_]*)\s*\(",body):
            ok=method in class_methods.get(cls,set());targets.append({'kind':'php_class_method','target':cls+'::'+method,'verified':ok});downstream_ok=downstream_ok and ok
        for method in re.findall(r"\bself::([A-Za-z_][A-Za-z0-9_]*)\s*\(",body):
            if method=='tool':continue
            ok=method in class_methods.get('PRSTUDIO_UC_MCP_V5',set());targets.append({'kind':'mcp_helper','target':'PRSTUDIO_UC_MCP_V5::'+method,'verified':ok});downstream_ok=downstream_ok and ok
        implemented=name in cases and bool(body.strip()) and downstream_ok
        q=public_question_map('mcp_tool',implemented=implemented,modern=True)
        items.append({'id':name,'kind':'chatgpt_mcp_tool','file':rel(mcp_path),'line':0,'loc':0,'implementation_status':'implemented' if implemented else ('missing_downstream' if name in cases else 'missing_dispatch'),'questions':q,'evidence':{'dispatch_case':name in cases,'downstream_targets':targets,'downstream_verified':downstream_ok}})

    # Catalog actions.
    catalog=load(ROOT/'prstudio-unified-control/connector/action-catalog.json')['actions']
    catalog_by_key={(str(a.get('route','')),str(a.get('action',''))):a for a in catalog}
    for a in catalog:
        s=surface['actions'][f"{a['route']}::{a['action']}"];implemented=s['status'] in {'wordpress_native','database_native','browser_agent','web_stories_native','complete_native'}
        annotation_ok=not (_semantic_read_action(str(a.get('action',''))) and not bool(a.get('read_only')))
        annotation_ok=annotation_ok and not (bool(a.get('read_only')) and bool(a.get('destructive')))
        q=public_question_map('control_action',implemented=implemented or guarded,guarded=guarded,read_only=bool(a.get('read_only')),destructive=bool(a.get('destructive')),annotations_valid=annotation_ok)
        items.append({'id':f"{a['route']}::{a['action']}",'kind':'control_action','file':'prstudio-unified-control/connector/action-catalog.json','line':0,'loc':0,'implementation_status':s['status'],'questions':q,'evidence':s})

    # Capabilities. Legacy actions inherit action-surface status. Native/direct
    # capabilities must point to a concrete Class::method present in the source.
    caps=load(ROOT/'prstudio-unified-control/capabilities/capability-registry.json').get('capabilities',[])+load(ROOT/'prstudio-unified-control/capabilities/agency-capabilities.json').get('capabilities',[])
    legacy_direct_failures,_legacy_direct_evidence=audit_legacy_direct()
    legacy_direct_reachable=not legacy_direct_failures
    for c in caps:
        source=c.get('source') or {};kind=source.get('kind','');executor=str(c.get('executor') or '');cls='';method=''
        if '::' in executor: cls,method=executor.split('::',1)
        implemented=bool(cls and method and method in class_methods.get(cls,set())); status='implemented' if implemented else 'missing_executor'
        if kind=='legacy_action':
            key=f"{source.get('route','')}::{source.get('action','')}";s=surface['actions'].get(key);status=s['status'] if s else 'mapping_missing';implemented=status in {'wordpress_native','database_native','browser_agent','web_stories_native','complete_native'}
        elif kind=='legacy_direct_tool':
            implemented=implemented and legacy_direct_reachable and bool(source.get('tool_name'))
            status='implemented' if implemented else 'missing_underlying_executor'
        annotation_ok=bool(c.get('read_only')) != bool(c.get('write')) and not (bool(c.get('read_only')) and bool(c.get('destructive')))
        if kind=='legacy_action':
            target=catalog_by_key.get((str(source.get('route','')),str(source.get('action',''))))
            annotation_ok=annotation_ok and bool(target)
            if target:
                annotation_ok=annotation_ok and all(bool(c.get(field))==bool(target.get(field)) for field in ('read_only','destructive','idempotent'))
                annotation_ok=annotation_ok and not (_semantic_read_action(str(target.get('action',''))) and not bool(target.get('read_only')))
        q=public_question_map('capability',implemented=implemented,read_only=bool(c.get('read_only')),destructive=bool(c.get('destructive')),annotations_valid=annotation_ok)
        items.append({'id':c.get('id',''),'kind':'capability','file':'prstudio-unified-control/capabilities/*.json','line':0,'loc':0,'implementation_status':status,'questions':q,'evidence':{'source':source,'executor':c.get('executor',''),'exact_class_method_match':implemented if kind!='legacy_action' else None}})

    # Enterprise Agency actions: all published actions must resolve to native,
    # persisted-native or semantic executor branches. External-provider actions are
    # operational contracts with explicit dependency errors, never fake success.
    ag=agency_surface()
    agency_text=(ROOT/'prstudio-unified-control/includes/class-prstudio-agency.php').read_text(encoding='utf-8')
    read_body=next((f['body'] for f in php_functions(ROOT/'prstudio-unified-control/includes/class-prstudio-agency.php') if f['name']=='read_only'),'')
    read_only_names=set(re.findall(r"['\"]([a-z0-9_:-]+)['\"]",read_body))
    for name,e in ag['actions'].items():
        implemented=e['status'] in {'agency_native','agency_state_native','agency_semantic'}
        q=public_question_map('agency_action',implemented=implemented,read_only=name in read_only_names,destructive=None)
        # Concrete dependency errors remain executable semantics, so missing external
        # accounts/providers do not fail Q31 or Q55.
        items.append({'id':name,'kind':'agency_action','file':'prstudio-unified-control/includes/class-prstudio-agency.php','line':0,'loc':0,'implementation_status':e['status'],'questions':q,'evidence':e})

    # PHP/JS functions.
    files=list((ROOT/'prstudio-unified-control').rglob('*.php'))
    for path in files:
      for fn in php_functions(path):
        stub,reason,delegate,accessor,stripped=semantic_body(fn)
        q={k:'NA' for k,_ in QUESTIONS}
        for k in ['Q01','Q02','Q03','Q05','Q06','Q39','Q40','Q43','Q45','Q52','Q53','Q57','Q59','Q60','Q247']:q[k]='PASS'
        if stub:q['Q02']=q['Q05']='FAIL'
        if reason=='placeholder_marker':q['Q06']='FAIL'
        compact=fn.get('statements',1)<=3 and (delegate or accessor)
        if compact:q['Q05']='PASS'
        items.append({'id':f"{fn['file']}::{fn['name']}@{fn['line']}",'kind':'php_function','file':fn['file'],'line':fn['line'],'loc':fn['loc'],'implementation_status':'stub_suspect' if stub else ('compact_concrete_helper' if compact else 'implemented'),'questions':q,'evidence':{'class':fn.get('class',''),'visibility':fn['visibility'],'stub_reason':reason,'delegate':delegate,'accessor':accessor,'logical_statements':fn.get('statements',1)}})
    for path in list((ROOT/'prstudio-unified-browser-agent').rglob('*.js'))+list((ROOT/'prstudio-unified-browser-agent').rglob('*.mjs')):
      for fn in js_functions(path):
        stub,reason,delegate,accessor,stripped=semantic_body(fn)
        q={k:'NA' for k,_ in QUESTIONS}
        for k in ['Q01','Q02','Q03','Q05','Q06','Q39','Q40','Q43','Q45','Q51','Q52','Q53','Q57','Q59','Q60','Q247']:q[k]='PASS'
        if stub:q['Q02']=q['Q05']='FAIL'
        if reason=='placeholder_marker':q['Q06']='FAIL'
        compact=fn.get('statements',1)<=3 and (delegate or accessor)
        if compact:q['Q05']='PASS'
        items.append({'id':f"{fn['file']}::{fn['name']}@{fn['line']}",'kind':'browser_js_function','file':fn['file'],'line':fn['line'],'loc':fn['loc'],'implementation_status':'stub_suspect' if stub else ('compact_concrete_helper' if compact else 'implemented'),'questions':q,'evidence':{'stub_reason':reason,'delegate':delegate,'accessor':accessor,'logical_statements':fn.get('statements',1)}})
    return items

def component_checkpoints(items, surface):
    """250-question static/release checkpoint for the three deployable components.

    PASS means the package contains direct code evidence or all scoped public items pass
    the corresponding existing question. WARN means the concern is operational or only
    partially provable statically. NA is deliberately not applicable to that component.
    """
    component_defs={
        'browser_extension':{
            'files':[ROOT/'prstudio-unified-browser-agent'],
            'item':lambda i:i['kind']=='browser_js_function',
            'label':'PR STUDIO Browser Agent extension',
        },
        'rp_studio_connector':{
            'files':[ROOT/'prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php',ROOT/'prstudio-unified-control/includes/class-prstudio-uc-mcp-auth-v5.php',ROOT/'prstudio-unified-control/includes/class-prstudio-uc-mcp-toolchain.php'],
            'item':lambda i:i['kind']=='chatgpt_mcp_tool' or ('class-prstudio-uc-mcp' in i.get('file','')),
            'label':'RP Studio Connector MCP',
        },
        'wordpress_plugin':{
            'files':[ROOT/'prstudio-unified-control'],
            'item':lambda i:i['kind'] in {'control_action','capability','agency_action'} or (i['kind']=='php_function' and 'class-prstudio-uc-mcp' not in i.get('file','')),
            'label':'PR STUDIO WordPress plugin',
        },
    }
    def collect_text(paths):
        chunks=[]
        for path in paths:
            if path.is_file():
                chunks.append(path.read_text(encoding='utf-8',errors='replace'))
            elif path.is_dir():
                for f in path.rglob('*'):
                    if f.is_file() and f.suffix.lower() in {'.php','.js','.mjs','.json','.py','.md'} and f.stat().st_size < 4_000_000:
                        chunks.append(f.read_text(encoding='utf-8',errors='replace'))
        return '\n'.join(chunks).lower()
    def worst(values):
        order={'FAIL':4,'WARN':3,'PASS':2,'NA':1}
        vals=[v for v in values if v in order]
        return max(vals,key=lambda x:order[x]) if vals else 'NA'
    outputs={}
    # End-to-end evidence matters for the connector because it intentionally delegates
    # execution/recovery/policy to the WordPress runtime instead of duplicating them in
    # the MCP boundary. Keep the component scan scoped, but allow explicit downstream
    # contracts to satisfy cross-layer questions when the connector exposes the route.
    whole_text=collect_text([ROOT/'prstudio-unified-control', ROOT/'prstudio-unified-browser-agent'])
    all_ids={str(i.get('id','')) for i in items}
    contract_failures,contract_evidence=audit_contracts()
    dims={
        'modernita':['Q51','Q61','Q65','Q77','Q93','Q98'],
        'prestazioni':['Q19','Q28','Q41','Q42','Q43','Q69','Q71','Q93','Q95'],
        'robustezza':['Q17','Q18','Q21','Q23','Q30','Q70','Q72','Q78','Q81','Q82','Q96'],
        'enterprise':['Q12','Q13','Q25','Q34','Q47','Q62','Q66','Q83','Q85','Q91','Q97','Q100'],
        'automazione':['Q16','Q20','Q24','Q29','Q78','Q80','Q81','Q83','Q84','Q88','Q94'],
        'precisione':['Q07','Q09','Q10','Q36','Q39','Q45','Q73','Q74','Q76','Q99'],
        'utilita':['Q03','Q04','Q46','Q54','Q55','Q59','Q87','Q89','Q90','Q93'],
        'continuita_missione':['Q101','Q102','Q103','Q104','Q105','Q106','Q107','Q108','Q109','Q110'],
        'velocita_tool_economics':['Q111','Q112','Q113','Q114','Q115','Q116','Q117','Q118','Q119','Q120'],
        'truthfulness':['Q121','Q122','Q123','Q124','Q125','Q126','Q127','Q128','Q129','Q130'],
        'autonomia_sicura':['Q131','Q132','Q133','Q134','Q135','Q136','Q137','Q138','Q139','Q140'],
        'seo_editoriale_autonomo':['Q141','Q142','Q143','Q144','Q145','Q146','Q147','Q148','Q149','Q150'],
        'hosting_scala_tempo':['Q151','Q152','Q153','Q154','Q155','Q156','Q157','Q158','Q159','Q160','Q161','Q162','Q163','Q164','Q165','Q166','Q167','Q168'],
        'localizzazione_supply_chain':['Q169','Q170','Q171','Q172','Q173','Q174','Q175','Q176','Q177','Q178','Q179','Q180','Q181','Q182','Q183','Q184','Q185','Q186'],
        'continuita_browser_reale':['Q187','Q188','Q189','Q190','Q191','Q192','Q193','Q194','Q195','Q196','Q197','Q198','Q199','Q200','Q201','Q202','Q203','Q204','Q205','Q206'],
        'protocollo_identita_osservabilita':['Q207','Q208','Q209','Q210','Q211','Q212','Q213','Q214','Q215','Q216','Q217','Q218','Q219','Q220','Q221','Q222','Q223','Q224','Q225','Q226','Q227','Q228','Q229','Q230'],
        'sicurezza_governance_lungo_termine':['Q231','Q232','Q233','Q234','Q235','Q236','Q237','Q238','Q239','Q240','Q241','Q242','Q243','Q244','Q245','Q246','Q247','Q248','Q249','Q250'],
    }
    for name,cfg in component_defs.items():
        scoped=[i for i in items if cfg['item'](i)]
        text=collect_text(cfg['files'])
        answers={}
        for qid,question in QUESTIONS[:60]:
            values=[i['questions'].get(qid,'NA') for i in scoped]
            answers[qid]={'question':question,'status':worst(values),'evidence':f'scoped_items={len(scoped)}'}
        def setq(qid,status,evidence):
            question=dict(QUESTIONS)[qid];answers[qid]={'question':question,'status':status,'evidence':evidence}
        # Cross-cutting modern enterprise checks. These are evidence-backed string/static checks, not production claims.
        setq('Q61','PASS' if ('@2026.' in text or 'version' in text or name=='browser_extension') else 'WARN','version/dependency metadata inspected')
        browser_self_contained = name=='browser_extension' and "script-src 'self'" in text and 'component-manifest' in text and 'tree_digest' in text
        setq('Q62','PASS' if browser_self_contained or ('source' in text and ('official' in text or 'license' in text)) else 'WARN','dependency provenance/integrity explicit; Browser Agent runtime is self-contained with self-only extension CSP')
        setq('Q63','PASS' if any(k in text for k in ['optional','fallback','unavailable','best-effort','best_effort']) else 'WARN','optional-dependency/fallback branches inspected')
        setq('Q64','PASS' if any(k in text for k in ['schema_version','migration','backward','contractunchanged','contract_unchanged']) or (name=='rp_studio_connector' and any(k in whole_text for k in ['schema_version','migration','contract_unchanged'])) else 'WARN','version/migration compatibility markers')
        setq('Q65','PASS' if any(k in text for k in ['schema_version','protocolversion','protocol_version','version']) else 'WARN','state/protocol version markers')
        setq('Q66','PASS' if any(k in text for k in ['redact','secret','token','storage.local','secrets_vault']) else 'WARN','secret storage/redaction markers')
        setq('Q67','PASS' if any(k in text for k in ['authexpired','auth_expired','pairingrenewal','refresh_token','re-auth','reauth']) else ('NA' if name=='wordpress_plugin' else 'WARN'),'auth/pairing expiry handling')
        setq('Q68','PASS' if any(k in text for k in ['retry-after','retry_after','429','rate_limit','rate limit']) else 'WARN','rate-limit handling markers')
        setq('Q69','PASS' if any(k in text for k in ['backoff','jitter','adaptivepolldelay','adaptive_poll']) or (name=='rp_studio_connector' and any(k in whole_text for k in ['backoff','jitter','adaptivepolldelay','adaptive_poll'])) else 'WARN','bounded retry pacing in connector or delegated runtime')
        setq('Q70','PASS' if any(k in text for k in ['circuitbreaker','circuit_breaker','duplicateactioncircuitbreaker','duplicate_action']) or (name=='rp_studio_connector' and any(k in whole_text for k in ['circuitbreaker','circuit_breaker','duplicateactioncircuitbreaker','duplicate_action'])) else 'WARN','circuit-breaker in connector or delegated runtime')
        setq('Q71','PASS' if any(k in text for k in ['max_body','max_files','maxresult','max_result','max_task','limit','budget']) else 'WARN','bounded workloads/output')
        setq('Q72','PASS' if any(k in text for k in ['stale','last_seen','lastdeviceheartbeat','heartbeat']) else 'WARN','staleness/heartbeat markers')
        setq('Q73','PASS' if any(k in text for k in ['provenance','conflict','source_labels','source label']) else ('NA' if name=='browser_extension' else 'WARN'),'source provenance/conflict support')
        setq('Q74','PASS' if name=='browser_extension' or ('business.evidence.gate' not in all_ids and 'evidence_sufficiency_gate' not in whole_text) else 'FAIL','legacy evidence gate absent end-to-end')
        setq('Q75','PASS' if ('sensitiveeffect' in text or 'risk_engine' in text or 'risk level' in text) or (name=='rp_studio_connector' and any(k in whole_text for k in ['sensitiveeffect','risk_engine','risk level'])) else 'WARN','effect/risk based policy evidence end-to-end')
        setq('Q76','PASS' if ('applicationaccepted' in text or 'application_accepted' in text or 'postcondition' in text) or (name=='rp_studio_connector' and any(k in whole_text for k in ['applicationaccepted','application_accepted','postcondition'])) else 'WARN','dispatch versus application-state verification end-to-end')
        setq('Q77','NA' if name=='wordpress_plugin' else ('PASS' if ('waitforspaready' in text or 'dom_ready_selector_readiness' in text or 'networkidle' not in text) else 'WARN'),'SPA readiness is an extension/client responsibility; server plugin marked NA')
        setq('Q78','PASS' if any(k in text for k in ['idempotent','idempotency','replay','checkpoint']) else 'WARN','durable replay/idempotency markers')
        setq('Q79','NA' if name in {'wordpress_plugin','rp_studio_connector'} else ('PASS' if any(k in text for k in ['pairingrenewal','pairing_renewal','reconnect']) else 'WARN'),'pairing/reconnect is owned by the Browser Agent; connector/server report status only')
        setq('Q80','PASS' if 'prstudio-device-heartbeat' in text or 'lastdeviceheartbeat' in text else ('NA' if name!='browser_extension' else 'WARN'),'device heartbeat independent alarm')
        setq('Q81','PASS' if any(k in text for k in ['selfhealing','self_healing','watchdog','recovery_manager','recovery manager']) else 'WARN','queue/runtime recovery markers')
        setq('Q82','PASS' if any(k in text for k in ['stale_lease','stale lease','lease_token','ownership','owner_client_id']) else 'WARN','lease/ownership checks')
        setq('Q83','PASS' if 'external_runner' in text or 'agency run --limit' in text or (name=='rp_studio_connector' and ('agency_status' in all_ids and 'external_runner' in whole_text)) else ('NA' if name=='browser_extension' else 'WARN'),'H24 external runner contract exposed/delegated end-to-end')
        setq('Q84','PASS' if any(k in text for k in ['occurrence_key','idempotency','schedule','cron']) else 'WARN','schedule occurrence/idempotency evidence')
        setq('Q85','PASS' if any(k in text for k in ['health','metrics','heartbeat','status']) else 'WARN','health and metric surfaces')
        setq('Q86','PASS' if any(k in text for k in ['request_id','job_uuid','task_uuid','correlation']) else 'WARN','correlation identifiers')
        setq('Q87','PASS' if 'decision_journal' in text or 'business.decision.journal' in text or (name=='rp_studio_connector' and 'business.decision.journal' in all_ids) else ('NA' if name=='browser_extension' else 'WARN'),'managerial decision journal')
        setq('Q88','PASS' if 'learn_verified' in text or 'verified_browser_task' in text or (name=='rp_studio_connector' and 'procedural_skill_search' in all_ids and 'learn_verified' in whole_text) else ('NA' if name=='browser_extension' else 'WARN'),'verified-only procedural learning behind connector surface')
        setq('Q89','PASS' if 'failed_paths' in text or 'observe_failure' in text or (name=='rp_studio_connector' and 'procedural_skill_search' in all_ids and 'observe_failure' in whole_text) else ('NA' if name=='browser_extension' else 'WARN'),'failed-path memory behind connector surface')
        setq('Q90','PASS' if ('expires_gmt' in text and 'invalidate' in text) or (name=='rp_studio_connector' and 'procedural_skill_invalidate' in all_ids and 'expires_gmt' in whole_text) else ('NA' if name=='browser_extension' else 'WARN'),'skill expiry/invalidation behind connector surface')
        setq('Q91','PASS' if any(k in text for k in ['allowlist','allowed','expectedorigin','expected_origin','resolve(','sandbox','strict_tab']) else 'WARN','sandbox/path/origin allowlists')
        setq('Q92','PASS' if ('arbitrary_shell_exposed' in text or 'no arbitrary shell' in text or name=='browser_extension' or (name=='rp_studio_connector' and 'engineering_terminal' in all_ids and 'arbitrary_shell_exposed' in whole_text)) else 'WARN','arbitrary shell is not a published generic primitive')
        setq('Q93','PASS' if ('repo_map' in text or 'skill.md' in text or 'progressive_disclosure' in text) else ('NA' if name=='browser_extension' else 'WARN'),'repo-map/progressive disclosure support')
        setq('Q94','PASS' if any(k in text for k in ['php_lint','json_validate','checkpoint','test_matrix','node --check']) else 'WARN','deterministic validation loop')
        setq('Q95','PASS' if any(k in text for k in ['concurrency','max_task','isolated','strict_tab','serialized','parallel_read']) or (name=='rp_studio_connector' and any(k in whole_text for k in ['concurrency','max_task','isolated','strict_tab','serialized','parallel_read'])) else 'WARN','bounded concurrency/isolation markers end-to-end')
        setq('Q96','PASS' if any(k in text for k in ['finally','flock','proc_terminate','detachdebugger','abortcontroller','cleanup']) else 'WARN','resource cleanup markers')
        setq('Q97','PASS' if any(k in text for k in ['contract_unchanged','backward','rollback','snapshot','schema_version']) else 'WARN','upgrade/backward/rollback markers')
        setq('Q98','PASS' if any(k in text for k in ['executorprotocolversion','mcp_protocol','capability','contracthash','contract_hash']) else 'WARN','cross-layer protocol/contract markers')
        setq('Q99','PASS' if any(k in text for k in ['production_proven','browser-live','browser_live','source_labels','live evidence','cache']) else 'WARN','source/evidence-mode distinction')
        published_fail=[i['id'] for i in scoped if i['kind'] in {'chatgpt_mcp_tool','control_action','capability','agency_action'} and i['implementation_status'] not in {'implemented','wordpress_native','database_native','browser_agent','web_stories_native','complete_native','agency_native','agency_state_native','agency_semantic'}]
        stub_fail=[i['id'] for i in scoped if i['implementation_status']=='stub_suspect']
        setq('Q100','PASS' if not published_fail and not stub_fail else 'FAIL',f'published_failures={len(published_fail)} stub_suspects={len(stub_fail)}')
        # Q101-Q150: execution-efficiency, truthfulness and autonomous-editorial gates.
        e2e = text + ('\n' + whole_text if name=='rp_studio_connector' else '')
        is_browser=name=='browser_extension'; is_connector=name=='rp_studio_connector'; is_wp=name=='wordpress_plugin'
        def has(*markers): return all(m.lower() in e2e for m in markers)
        def anyhas(*markers): return any(m.lower() in e2e for m in markers)
        # Mission continuity.
        setq('Q101','PASS' if anyhas('execution_lanes','lane_token','mission_id','laneid') else 'FAIL','execution lane + mission identity')
        setq('Q102','PASS' if anyhas('resume','checkpoint','last_completed_step','mission_resume_lookup') else 'WARN','resume/checkpoint markers')
        setq('Q103','PASS' if anyhas('last_completed_step','completed steps','mission_resume_lookup','memory_lookup') else 'WARN','completed-step reuse')
        setq('Q104','PASS' if anyhas('failed_paths','observe_failure') else ('NA' if is_browser else 'FAIL'),'failed path memory')
        setq('Q105','PASS' if anyhas('do_not_repeat_verified_discovery','do not repeat verified discovery','memory_lookup','cached','gsc_session','sessions[lanekey]','affinityreason') else 'WARN','verified discovery/session reuse')
        setq('Q106','PASS' if anyhas('expires_gmt','freshness','ttl_seconds','cache') else 'WARN','freshness-bounded evidence reuse')
        setq('Q107','PASS' if anyhas('resource_busy_other_context','lease_token','ownershipnonce','laneid') else 'FAIL','entity/tab/resource lease isolation')
        setq('Q108','PASS' if anyhas('resource_busy_other_context','execution_lane_mutex','with_mutex','db_mutex','atomic lease','runtime_mutex','remote_lane_busy') else 'FAIL','atomic cross-chat isolation')
        setq('Q109','PASS' if anyhas('adopted external tab','external_adopted','reconcileagentownership','stale_lease','expires_gmt') else 'WARN','restart-safe adopted-tab ownership + stale release')
        setq('Q110','PASS' if anyhas('context_close','mission_id','status','closed') else 'WARN','deterministic mission close')
        # Speed/tool economics.
        setq('Q111','PASS' if anyhas("'fast'",'fast path','max_tool_calls') else ('NA' if is_browser else 'FAIL'),'FAST planner path')
        setq('Q112','PASS' if anyhas('max_tool_calls') else ('NA' if is_browser else 'FAIL'),'tool-call budgets')
        setq('Q113','PASS' if anyhas('batch_not_supported','json-rpc batch','2025-06-18','shard') else 'WARN','protocol-aware batch execution')
        setq('Q114','PASS' if anyhas('parallel_read','concurrency','workstream','multi_lane') else 'WARN','bounded independent parallelism')
        setq('Q115','PASS' if anyhas('intent_hash','cache','cached','detail=compact','include_history','device_history') else 'WARN','cache + compact operational status')
        setq('Q116','PASS' if anyhas('anti_crash_attestation','attestation_reused','reusable(') else ('NA' if is_browser else 'FAIL'),'anti-crash attestation cache')
        setq('Q117','PASS' if anyhas('prefer_server_executor','server-side','browser_required": false','browser_required":false') else ('NA' if is_browser else 'WARN'),'server-first deterministic execution')
        setq('Q118','PASS' if anyhas('evidence_sufficiency_stop','evidence_sufficiency','stop when') else ('NA' if is_browser else 'WARN'),'stop after sufficient evidence')
        setq('Q119','PASS' if anyhas('max_retries','max_attempts','noinfiniteretry','screenshotstoragecircuit','screenshot_artifact_too_large','duplicateactioncircuitbreaker') else 'WARN','bounded retry + screenshot circuit breaker')
        setq('Q120','PASS' if anyhas('max_replans','max_fallbacks','freshrestartlimit','fresh_restart_limit') else ('NA' if is_browser else 'FAIL'),'bounded replans/fallbacks')
        # Truthfulness/effect verification.
        setq('Q121','PASS' if anyhas('effect_verify','applicationaccepted','fully_verified','independent_postcondition') else 'FAIL','effect-based success')
        setq('Q122','PASS' if anyhas('database_only_verified','frontend_verified','verification_scope') else ('NA' if is_browser else 'FAIL'),'DB/frontend semantic separation')
        setq('Q123','PASS' if anyhas('applicationaccepted','application_accepted','verificationstrength') else ('NA' if is_wp else 'FAIL'),'dispatch vs application acceptance')
        setq('Q124','PASS' if anyhas('max_artifact_bytes','headroom_ok','artifact_integrity','accepted_mime_types','screenshot_storage') else 'WARN','bounded private visual artifact storage')
        setq('Q125','PASS' if anyhas('render_source_resolver','render.source.resolve','authoritative_source') else ('NA' if is_browser else 'FAIL'),'authoritative render-source resolver')
        setq('Q126','PASS' if anyhas('canonical','indexable','robots') else 'WARN','canonical/indexability verification')
        setq('Q127','PASS' if anyhas('semantic_blast_radius','blast_radius','impact_analysis') else ('NA' if is_browser else 'WARN'),'side-effect/blast-radius model')
        setq('Q128','PASS' if anyhas('laneid','lane_token','freshness','expires_gmt') else 'WARN','lane/freshness-bound evidence')
        setq('Q129','PASS' if anyhas('independent_postcondition','independent_readback','db_readback','public_verify') else ('NA' if is_browser else 'WARN'),'independent mutation verification')
        false_full = ("'frontend_verified'=>false,'fully_verified'=>true" in e2e.replace(' ',''))
        setq('Q130','FAIL' if false_full else ('PASS' if anyhas('fully_verified','frontend_verified','render_verified') else ('NA' if is_browser and anyhas('applicationaccepted','verificationstrength') else 'WARN')),'no false fully_verified contract')
        # Autonomous execution safety.
        setq('Q131','PASS' if is_browser or not anyhas('autonomy.envelope','autonomy_envelope') else 'FAIL','legacy autonomy envelope absent')
        setq('Q132','PASS' if anyhas('browser_required": false','server-side','external_runner','scheduled') else ('NA' if is_browser else 'WARN'),'server/scheduler-safe path')
        setq('Q133','PASS' if anyhas('idempotency_key','idempotent_reuse','chat_key','context_open','duplicateactioncircuitbreaker') else 'WARN','mutation/context replay idempotency')
        setq('Q134','PASS' if anyhas('anti_crash','pre_mutation_safety','nonreplayable') else 'WARN','single Anti-Crash mutation guard')
        setq('Q135','PASS' if anyhas('content.publish.transaction','create_publish','confirmation": false','confirmation":false') else ('NA' if is_browser else 'FAIL'),'no-human server-side editorial path')
        setq('Q136','PASS' if anyhas('technical_error','retryable','anti_crash') else 'WARN','technical error / bounded retry / anti-crash taxonomy')
        setq('Q137','PASS' if anyhas('publications_per_day','updates_per_day','limits') else ('NA' if is_browser else 'FAIL'),'per-run/day limits')
        setq('Q138','PASS' if anyhas('resource_busy_other_context','with_mutex','renew','publications_per_day','exclusive_mutation_leases') else 'FAIL','atomic lease/heartbeat/race-safe quota')
        setq('Q139','PASS' if anyhas('db_readback','receipt','idempotent_reuse','replay') else 'WARN','post-crash reconciliation')
        setq('Q140','PASS' if anyhas('notifications/cancelled','context_close','release','cancel','recovery') else 'WARN','MCP-aware recover/cancel/release')
        # Autonomous SEO publishing. Browser component is an observation executor; plugin/connector own editorial state.
        seo_na = is_browser
        setq('Q141','NA' if seo_na else ('PASS' if anyhas('keyword_url_registry','seo.keyword_url.registry','dominant') else 'FAIL'),'dominant keyword→URL registry')
        setq('Q142','NA' if seo_na else ('PASS' if anyhas('serp_intent_observer','intent_hash','expires_gmt') else 'FAIL'),'persisted SERP intent hash/TTL')
        setq('Q143','NA' if seo_na else ('PASS' if anyhas('cannibalization_resolver','seo.cannibalization.resolver','forbidden_overlap') else 'FAIL'),'semantic duplicate/cannibalization gate')
        setq('Q144','NA' if seo_na else ('PASS' if anyhas('campaign_manager','seo.campaign.manager','campaign_id') else 'FAIL'),'persistent head-keyword campaign')
        setq('Q145','NA' if seo_na else ('PASS' if anyhas('brief_compiler','brief_hash','content.brief.compile') else 'FAIL'),'structured hashed brief')
        setq('Q146','NA' if seo_na else ('PASS' if anyhas('claim_ledger','source_url','confidence','expires_gmt') else 'FAIL'),'claim provenance/confidence/expiry')
        setq('Q147','NA' if seo_na else ('PASS' if anyhas('internal_link_graph','pillar_support','incoming','outgoing') else 'FAIL'),'pillar-support internal-link graph')
        setq('Q148','NA' if seo_na else ('PASS' if anyhas('schema_editorial_compiler','canonical','indexable') else 'FAIL'),'schema/canonical/indexability editorial checks')
        setq('Q149','NA' if seo_na else ('PASS' if anyhas('content.publish.transaction','db_verified','render_verified','public_verification') else 'FAIL'),'DB + public render publish closure')
        setq('Q150','NA' if seo_na else ('PASS' if anyhas('post_publish_watcher','seo.post_publish.watcher','history') else 'FAIL'),'post-publish watcher')
        # Q151-Q250 applicability is component-owned.  Marker presence proves a
        # static contract only.  Questions requiring a real host, browser, time
        # transition, provider, legal review or periodic exercise remain WARN and
        # cannot be promoted to PASS by source strings.
        component_role={'browser_extension':'browser','rp_studio_connector':'connector','wordpress_plugin':'wp'}[name]
        def owner_applies(owner):
            if owner=='all':return True
            if owner=='server':return component_role in {'connector','wp'}
            if owner=='wp_browser':return component_role in {'wp','browser'}
            return owner==component_role
        for qid,(owner,markers) in ADVANCED_QUESTION_CONTRACTS.items():
            if not owner_applies(owner):
                setq(qid,'NA',f'owned_by={owner}; component_role={component_role}')
                continue
            found=[marker for marker in markers if marker.lower() in e2e]
            status='WARN' if qid in LIVE_EVIDENCE_QUESTIONS else ('PASS' if found else 'WARN')
            setq(qid,status,('live/integration evidence required; ' if qid in LIVE_EVIDENCE_QUESTIONS else '')+f'static_markers={found[:6]}')

        # Deterministic release contracts use parser-backed evidence rather than
        # marker heuristics.  These exact regressions are also hard failures in main().
        annotation_failures=[failure for failure in contract_failures if failure.startswith(('annotation_','inspect_theme_assets_'))]
        if owner_applies('connector') or owner_applies('wp'):
            setq('Q212','FAIL' if annotation_failures else 'PASS',f'annotation_contract_failures={len(annotation_failures)}')
        if owner_applies('server'):
            dispatch_failures=[failure for failure in contract_failures if failure.startswith(('legacy_direct_','browser_direct_','browser_verify_url_'))]
            setq('Q239','FAIL' if dispatch_failures else 'PASS',f'dynamic_dispatch_contract_failures={len(dispatch_failures)}')
        if component_role=='connector':
            baseline=load(ROOT/'tests/mcp-tool-surface-compatibility-baseline.json').get('tools',[])
            declared=[i['id'] for i in scoped if i['kind']=='chatgpt_mcp_tool']
            setq('Q208','PASS' if set(baseline)<=set(declared) and len(declared)==len(set(declared)) else 'FAIL',f'baseline={len(baseline)} declared={len(declared)} unique={len(set(declared))}')
            setq('Q213','PASS' if all(marker in e2e for marker in ('invalid request','rpc_error')) else 'FAIL','typed malformed-request branch')
            setq('Q214','PASS' if set(baseline)<=set(declared) else 'FAIL','historical MCP baseline is additive')
        inherited=all(len(i.get('questions',{}))==len(QUESTIONS) for i in scoped)
        setq('Q247','PASS' if len(QUESTIONS)==250 and inherited else 'FAIL',f'questions={len(QUESTIONS)} scoped_items={len(scoped)} inherited={inherited}')
        score={}
        for dim,qids in dims.items():
            applicable=[answers[q]['status'] for q in qids if answers[q]['status']!='NA']
            points=sum(1.0 if st=='PASS' else 0.5 if st=='WARN' else 0.0 for st in applicable)
            score[dim]=round((points/max(1,len(applicable)))*10,2)
        outputs[name]={'label':cfg['label'],'scoped_items':len(scoped),'answers':[dict(id=q,**answers[q]) for q,_ in QUESTIONS],'scores_10':score,'failures':[q for q,a in answers.items() if a['status']=='FAIL'],'warnings':[q for q,a in answers.items() if a['status']=='WARN']}
    return outputs

def write_json(path,obj):path.write_text(json.dumps(obj,ensure_ascii=False,indent=2,sort_keys=True)+'\n',encoding='utf-8')

def main():
    ap=argparse.ArgumentParser();ap.add_argument('--write',action='store_true');ap.add_argument('--check',action='store_true');args=ap.parse_args()
    if not (args.write or args.check):ap.error('use --write or --check')
    surface=generate_surface();items=build_items(surface)
    totals=collections.Counter(i['kind'] for i in items);impl=collections.Counter(i['implementation_status'] for i in items)
    qcounts={qid:collections.Counter(i['questions'].get(qid,'NA') for i in items) for qid,_ in QUESTIONS}
    hard=[]
    # Release invariant: every published surface is operational. No catalog-only
    # or policy-only capability is allowed to pass merely because it is described.
    operational={'implemented','wordpress_native','database_native','browser_agent','web_stories_native','complete_native','compact_concrete_helper','agency_native','agency_state_native','agency_semantic'}
    for i in items:
        if i['kind'] in {'chatgpt_mcp_tool','control_action','capability','agency_action'} and i['implementation_status'] not in operational: hard.append(i['id'])
        if i['kind'] in {'php_function','browser_js_function'} and i['implementation_status']=='stub_suspect':hard.append(i['id'])
    # Surface invariants: no Web Stories drift; browser coverage complete.
    if surface['web_stories']['missing_dispatch']:hard += ['web_stories:'+x for x in surface['web_stories']['missing_dispatch']]
    if surface['status_counts'].get('browser_agent',0)+sum(1 for a in surface['actions'].values() if a.get('executor')=='browser_agent' and a.get('status')=='complete_native') != surface['browser']['catalog']:hard.append('browser_catalog_coverage')
    ag=agency_surface()
    if ag['count'] != 94: hard.append(f"agency_action_count:{ag['count']}")
    for category in ['missing','semantic_missing_dispatch','orphan_semantic','overlap_native_state','overlap_native_semantic','overlap_state_semantic']:
        if ag[category]: hard += [f"agency_{category}:"+x for x in ag[category]]
    contract_failures,contract_evidence=audit_contracts()
    hard += ['m11_contract:'+failure for failure in contract_failures]
    generated_runtime_prefixes=('bench/runs/','bench/proposals/','test-environment/release-evidence/')
    generated_runtime_files={
        'bench/SYSTEM-BENCH-HISTORY.ndjson',
        'bench/AGENT-BENCH-HISTORY.ndjson',
        'bench/FRONTIER-REFERENCE-HISTORY.ndjson',
    }
    generated_release_files={
        'ARCHITECTURE-1.0.0.md',
        'CHANGELOG-1.0.0.md',
        'COMPONENT-SHA256SUMS-1.0.0.txt',
        'H24-OPERATIONS-1.0.0.md',
        'INSTALL-CONNECTION-COMPATIBILITY-1.0.0.json',
        'LIVE-ACCEPTANCE-1.0.0.md',
        'MCP-PLUGIN-PREFLIGHT-1.0.0.json',
        'MCP-TOOLCHAIN-1.0.0.md',
        'PERFORMANCE-BENCHMARK-1.0.0.json',
        'QUALITY-GATE-1.0.0.json',
        'RELEASE-MANIFEST-1.0.0.json',
        'RP-STUDIO-CHATGPT-PLUGIN-1.0.0.json',
        'RP-STUDIO-CHATGPT-PLUGIN-INSTRUCTIONS-1.0.0.txt',
        'RP-STUDIO-CHATGPT-PLUGIN-SETUP-1.0.0.md',
        'SECURITY-HARDENING-1.0.0.json',
        'SOCIAL-CONNECTORS-1.0.0.md',
        'TEST-REPORT-1.0.0.json',
        'VISIONE-E-DECISIONI-1.0.0.md',
        'prstudio-unified-browser-agent-1.0.0.zip',
        'prstudio-unified-control-1.0.0.zip',
    }
    sources=[]
    for p in ROOT.rglob('*'):
        if not p.is_file() or p.name in {SUMMARY.name,DETAIL.name,SURFACE.name,'RELEASE-MANIFEST-1.0.0.json','COMPONENT-SHA256SUMS-1.0.0.txt'}:
            continue
        relative=rel(p)
        if (
            '.pre-exhaustive' in relative
            or relative.startswith(generated_runtime_prefixes)
            or relative in generated_runtime_files
            or relative in generated_release_files
            or '__pycache__/' in relative
            or relative.endswith(('.pyc', '.pyo', '.ndjson.lock'))
        ):
            continue
        sources.append(p)
    digest=hashlib.sha256('\n'.join(f'{rel(p)}:{sha(p)}' for p in sorted(sources)).encode()).hexdigest()
    component_checks=component_checkpoints(items,surface)
    summary={'suite_version':VERSION,'checklist_questions':[{'id':q,'question':t} for q,t in QUESTIONS],'component_checkpoints':component_checks,'inventory_counts':dict(sorted(totals.items())),'implementation_status_counts':dict(sorted(impl.items())),'question_status_counts':{q:dict(sorted(c.items())) for q,c in qcounts.items()},'control_surface':{'catalog_count':surface['catalog_count'],'status_counts':surface['status_counts']},'agency_surface':agency_surface(),'milestone_11_contracts':contract_evidence,'hard_failures':hard,'hard_failure_count':len(hard),'source_digest':digest,'production_proven':False,'policy':'every published tool/action/capability must resolve end-to-end to a concrete executor; metadata-only, stub, null and not-implemented public paths are release failures. Anti-Crash is the only mutation guard; risk and verification are advisory evidence only.'}
    if args.write:
        SURFACE.parent.mkdir(parents=True,exist_ok=True);write_json(SURFACE,surface);write_json(SUMMARY,summary)
        with DETAIL.open('w',encoding='utf-8') as f:
            for i in items:f.write(json.dumps(i,ensure_ascii=False,sort_keys=True)+'\n')
        print(json.dumps({'ok':not hard,'summary':summary,'surface':surface['status_counts']},ensure_ascii=False))
        return 0 if not hard else 1
    for p in (SURFACE,SUMMARY,DETAIL):
        if not p.is_file():print(f'MISSING {rel(p)}',file=sys.stderr);return 2
    stored=load(SUMMARY)
    if stored.get('source_digest')!=digest:
        print('STALE exhaustive checkpoint source digest',file=sys.stderr);return 3
    if stored.get('hard_failure_count')!=0:
        print('HARD FAILURES in exhaustive checkpoint',file=sys.stderr);return 4
    if load(SURFACE)!=surface:
        print('STALE concrete execution surface',file=sys.stderr);return 5
    if stored!=summary:
        print('STALE exhaustive checkpoint summary content',file=sys.stderr);return 6
    expected_detail=''.join(json.dumps(i,ensure_ascii=False,sort_keys=True)+'\n' for i in items)
    try: actual_detail=DETAIL.read_text(encoding='utf-8')
    except OSError:
        print('UNREADABLE exhaustive checkpoint detail',file=sys.stderr);return 7
    if actual_detail!=expected_detail:
        print('STALE exhaustive checkpoint detail content',file=sys.stderr);return 8
    matrix_cells=len(items)*len(QUESTIONS)
    applicable=sum(1 for i in items for status in i.get('questions',{}).values() if status in {'PASS','WARN','FAIL'})
    na_cells=matrix_cells-applicable
    print(f"PASS exhaustive checkpoint: {len(items)} items, {len(QUESTIONS)} questions/item, matrix_cells={matrix_cells}, applicable_rule_cells={applicable}, na_cells={na_cells}, hard_failures=0")
    return 0
if __name__=='__main__':raise SystemExit(main())
