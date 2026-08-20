<?php
/**
 * Evidence gate smoke — "Mixture-of-Expert Blocks Contain Strong
 * Hallucination Detection Signals" (arXiv 2026-08-13..19); Law 2.
 *
 * Azioni senza evidenza coerente -> unverified; evidenza contraddittoria ->
 * conflicting; l'esecuzione resta executed=true (la verifica è evidenza, mai
 * autorizzazione).
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-evidence-gate.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

// 1) Evidenza coerente: URL corrispondente + testo atteso presente.
$verified = PRSTUDIO_UC_Evidence_Gate::evaluate(
    array( 'action' => 'browser_navigate', 'url' => 'https://example.com/prodotto', 'expect_text' => 'Aggiungi al carrello' ),
    array( 'url' => 'https://example.com/prodotto', 'text' => 'Il prodotto è disponibile. Aggiungi al carrello per continuare.', 'ok' => true )
);
ok( 'verified' === $verified['verdict'], 'coherent url + expected text verify' );

// 2) Nessuna evidenza di testo: unverified, mai bloccante.
$unverified = PRSTUDIO_UC_Evidence_Gate::evaluate(
    array( 'action' => 'wordpress_content_transaction', 'expect_text' => 'Nuovo paragrafo' ),
    array( 'url' => '', 'text' => '', 'ok' => true )
);
ok( 'unverified' === $unverified['verdict'], 'missing text evidence yields unverified' );

// 3) URL contraddittorio: conflicting.
$conflicting = PRSTUDIO_UC_Evidence_Gate::evaluate(
    array( 'action' => 'browser_navigate', 'url' => 'https://example.com/atteso' ),
    array( 'url' => 'https://evil.example.com/altro', 'ok' => true )
);
ok( 'conflicting' === $conflicting['verdict'], 'conflicting url yields conflicting verdict' );

// 4) Evidenza tecnica assente (ok=false): unverified.
$no_ok = PRSTUDIO_UC_Evidence_Gate::evaluate(
    array( 'action' => 'browser_click', 'expect_selector' => '#submit' ),
    array( 'ok' => false, 'error' => 'timeout' )
);
ok( 'unverified' === $no_ok['verdict'], 'failed evidence yields unverified' );

// 5) Selettore atteso assente dal DOM: unverified.
$no_sel = PRSTUDIO_UC_Evidence_Gate::evaluate(
    array( 'action' => 'browser_click', 'expect_selector' => '#submit' ),
    array( 'selectors' => array( '#cancel' ), 'ok' => true )
);
ok( 'unverified' === $no_sel['verdict'], 'missing expected selector yields unverified' );

// 6) Law 2: tag su un risultato eseguito — executed resta true, verified=false,
//    degraded=true. Nessun veto, nessun rollback.
$result = array( 'executed' => true, 'status' => 'completed' );
$tagged = PRSTUDIO_UC_Evidence_Gate::tag( $result, 'unverified' );
ok( true === $tagged['executed'], 'unverified tag preserves executed=true (Law 2)' );
ok( false === $tagged['verified'], 'unverified tag sets verified=false' );
ok( true === $tagged['degraded'], 'unverified tag sets degraded=true' );
ok( 'unverified' === $tagged['evidence_gate'], 'evidence_gate carries the verdict' );

$tagged_verified = PRSTUDIO_UC_Evidence_Gate::tag( $result, 'verified' );
ok( true === $tagged_verified['verified'] && empty( $tagged_verified['degraded'] ), 'verified tag clears degraded' );

// 7) gate() combina valutazione + tag in un report completo.
$report = PRSTUDIO_UC_Evidence_Gate::gate(
    array( 'action' => 'browser_navigate', 'url' => 'https://example.com/x' ),
    array( 'url' => 'https://example.com/x', 'ok' => true ),
    array( 'executed' => true, 'status' => 'completed' )
);
ok( true === $report['verified'], 'gate() report marks verified when coherent' );
ok( isset( $report['evidence_gate_report']['checks']['url_coherent'] ), 'gate() report embeds the checks' );

// 8) Normalizzazione URL: schema e slash finali non contano.
$normalized = PRSTUDIO_UC_Evidence_Gate::evaluate(
    array( 'url' => 'https://Example.com/prodotto/' ),
    array( 'url' => 'example.com/prodotto', 'ok' => true )
);
ok( true === $normalized['checks']['url_coherent'], 'url normalization tolerates scheme and trailing slash' );

fwrite( STDOUT, "PASS evidence gate smoke complete\n" );
exit( 0 );
