<?php
/**
 * Style drift monitor smoke — "When Writing Style Drifts" (arXiv 2026-08-13..19).
 *
 * Baseline su campioni di stile omogeneo; un campione anomalo (telegrafico,
 * denso di punteggiatura e cifre) deve essere marcato drifted, un campione
 * normale no.
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

function sanitize_key( $v ): string { return trim( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ), '-_' ); }
function wp_json_encode( $v, $flags = 0 ): string { return (string) json_encode( $v, $flags ); }
function get_option( $key, $default = false ) { return $default; }
function update_option( $key, $value, $autoload = null ): bool { return true; }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-style-drift-monitor.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

PRSTUDIO_UC_Style_Drift_Monitor::set_store_for_test( array() );
$key = 'editorial_output';

$normal_samples = array(
    'Il negozio offre una selezione curata di prodotti artigianali, con spedizione rapida in tutta Italia e resi facili. Il supporto clienti risponde entro poche ore durante la settimana.',
    'La nuova collezione arriva in autunno e comprende capi realizzati con materiali sostenibili. Ogni capo viene controllato prima della spedizione per garantire la massima qualità.',
    'Abbiamo aggiornato la pagina dei pagamenti con opzioni più sicure e veloci. I clienti possono scegliere tra carta, PayPal e bonifico, con ricevuta immediata per ogni transazione.',
    'Il servizio di assistenza è disponibile dal lunedì al venerdì, dalle nove alle diciotto. Per domande urgenti consigliamo di utilizzare il modulo di contatto dedicato.',
    'La guida illustra i passaggi per configurare il nuovo modulo di spedizione. Seguendo le istruzioni si completa la configurazione in meno di dieci minuti.',
    'Ogni mese pubblichiamo un riepilogo delle novità e dei miglioramenti del negozio. Iscriviti alla newsletter per non perdere gli aggiornamenti più importanti.',
    'Il team ha lavorato a lungo sulla nuova interfaccia per renderla più chiara. I test con gli utenti mostrano un miglioramento significativo nella navigazione.',
    'La politica di reso copre trenta giorni dalla data di consegna. Il rimborso viene elaborato entro cinque giorni lavorativi dalla ricezione del prodotto.',
    'Per le aziende offriamo condizioni dedicate e un account manager di riferimento. Contattaci per ricevere un preventivo personalizzato in base alle esigenze.',
    'Le recensioni dei clienti aiutano a migliorare continuamente il servizio. Invitiamo chi ha acquistato a condividere la propria esperienza con una valutazione.',
);

foreach ( $normal_samples as $sample ) {
    PRSTUDIO_UC_Style_Drift_Monitor::record( $key, PRSTUDIO_UC_Style_Drift_Monitor::features( $sample ) );
}

$drift_normal = PRSTUDIO_UC_Style_Drift_Monitor::drift( $key, PRSTUDIO_UC_Style_Drift_Monitor::features( $normal_samples[0] ) );
ok( false === $drift_normal['drifted'], 'homogeneous style sample is not drifted' );

$anomalous = 'VENDITA OGGI!! -50% su tutto! CODICE: SALDI50. Acquista ORA! www.example.com/saldi. OFFERTA LIMITATA! 24 ORE! CHIAMA SUBITO: 0123-456789!!';
$drift_anomalous = PRSTUDIO_UC_Style_Drift_Monitor::drift( $key, PRSTUDIO_UC_Style_Drift_Monitor::features( $anomalous ) );
ok( true === $drift_anomalous['drifted'], 'telegraphic all-caps sales text is flagged as drifted' );
ok( count( $drift_anomalous['changed_features'] ) >= 2, 'drift flags at least two changed style features (got ' . count( $drift_anomalous['changed_features'] ) . ')' );

// Le features sono deterministiche: stesso input, stesso output.
$a = PRSTUDIO_UC_Style_Drift_Monitor::features( $normal_samples[0] );
$b = PRSTUDIO_UC_Style_Drift_Monitor::features( $normal_samples[0] );
ok( $a === $b, 'features() is deterministic' );

// Un testo di un'altra lingua ma con profilo stilistico simile non drift.
$other_normal = 'The store offers a curated selection of products with fast shipping. Customer support replies within a few hours on weekdays.';
$drift_other = PRSTUDIO_UC_Style_Drift_Monitor::drift( $key, PRSTUDIO_UC_Style_Drift_Monitor::features( $other_normal ) );
ok( false === $drift_other['drifted'], 'similar-style text in another language is not drifted' );

ok( true === PRSTUDIO_UC_Style_Drift_Monitor::reset( $key ), 'reset removes the baseline' );
ok( false === PRSTUDIO_UC_Style_Drift_Monitor::reset( 'never_existed' ), 'reset on missing key reports false' );

// Dopo il reset la baseline è fredda: nessun drift segnalabile.
$drift_cold = PRSTUDIO_UC_Style_Drift_Monitor::drift( $key, PRSTUDIO_UC_Style_Drift_Monitor::features( $anomalous ) );
ok( false === $drift_cold['drifted'], 'cold baseline cannot declare drift (insufficient samples)' );

fwrite( STDOUT, "PASS style drift monitor smoke complete\n" );
exit( 0 );
