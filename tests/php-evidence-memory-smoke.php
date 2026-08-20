<?php
/**
 * Evidence memory smoke — "D²ACCI: A Dual-Loop Diagnostic Protocol for
 * Evidence-Preserving Agent Memory" (arXiv 2026-08-13..19).
 *
 * Ogni decisione è conservata con la sua evidenza; il diagnostico a doppio
 * loop (analisi/osservazione) ripercorre la catena e segnala i gap
 * (esecuzione senza evidenza, analisi senza esito).
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

function sanitize_key( $v ): string { return trim( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ), '-_' ); }
function wp_json_encode( $v, $flags = 0 ): string { return (string) json_encode( $v, $flags ); }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-evidence-memory.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

$dir = sys_get_temp_dir() . '/prstudio-evidence-memory-' . bin2hex( random_bytes( 4 ) );
PRSTUDIO_UC_Evidence_Memory::set_dir_for_test( $dir );

// 1) Decisione completa: analisi, osservazione, esecuzione con evidenza, verifica.
$id = 'content_optimization_42';
PRSTUDIO_UC_Evidence_Memory::remember( $id, array(
    'loop' => 'analyze',
    'decision' => 'Ottimizzare la meta description della pagina 42',
    'outcome' => 'meta_description troppo corta',
) );
PRSTUDIO_UC_Evidence_Memory::remember( $id, array(
    'loop' => 'observe',
    'decision' => 'Leggere la meta description attuale',
    'evidence' => array( 'post_id' => 42, 'meta_description' => 'Vendita online di scarpe' ),
    'outcome' => 'meta_description osservata: 28 caratteri',
) );
PRSTUDIO_UC_Evidence_Memory::remember( $id, array(
    'loop' => 'execute',
    'decision' => 'Sostituire la meta description',
    'evidence' => array( 'intervention_key' => 'meta_description', 'state' => 'applied' ),
    'outcome' => 'transazione applicata',
) );
PRSTUDIO_UC_Evidence_Memory::remember( $id, array(
    'loop' => 'verify',
    'decision' => 'Verificare la persistenza',
    'evidence' => array( 'readback' => 'Vendita online di scarpe comode e resistenti', 'persisted' => true ),
    'outcome' => 'verifica completata',
) );

$diagnosis = PRSTUDIO_UC_Evidence_Memory::diagnose( $id );
ok( 4 === count( $diagnosis['records'] ), 'diagnose traces all 4 records' );
ok( 4 === array_sum( $diagnosis['loops'] ), 'all four loops are represented' );
ok( array() === $diagnosis['gaps'], 'no gaps in a fully evidenced decision' );

// 2) Decisione con gap: esecuzione senza evidenza.
$id2 = 'risky_decision';
PRSTUDIO_UC_Evidence_Memory::remember( $id2, array(
    'loop' => 'execute',
    'decision' => 'Pubblicare senza evidenza',
    'evidence' => array(),
    'outcome' => 'eseguito',
) );
$diagnosis2 = PRSTUDIO_UC_Evidence_Memory::diagnose( $id2 );
ok( 1 === count( $diagnosis2['gaps'] ), 'execute without evidence is reported as a gap' );
ok( str_starts_with( $diagnosis2['gaps'][0], 'no_evidence_on_execute' ), 'gap names the loop and the record' );

// 3) Analisi senza esito.
$id3 = 'analysis_without_outcome';
PRSTUDIO_UC_Evidence_Memory::remember( $id3, array(
    'loop' => 'analyze',
    'decision' => 'Valutare il refactoring',
    'outcome' => '',
) );
$diagnosis3 = PRSTUDIO_UC_Evidence_Memory::diagnose( $id3 );
ok( 1 === count( $diagnosis3['gaps'] ), 'analysis without outcome is reported as a gap' );

// 4) Recall per parola chiave.
$recall = PRSTUDIO_UC_Evidence_Memory::recall( 'meta_description' );
ok( $recall['count'] >= 3, 'recall finds evidence by keyword (got ' . $recall['count'] . ')' );
$recall_none = PRSTUDIO_UC_Evidence_Memory::recall( 'parola_che_non_esiste' );
ok( 0 === $recall_none['count'], 'recall returns zero for unknown keywords' );

// 5) Persistenza: il file esiste e contiene le righe.
$path = $dir . '/evidence.ndjson';
ok( is_file( $path ), 'evidence file is persisted on disk' );
$lines = array_filter( explode( "\n", (string) file_get_contents( $path ) ) );
ok( count( $lines ) >= 6, 'evidence file contains the records (got ' . count( $lines ) . ')' );

// 6) Redazione: le chiavi sensibili non vengono mai persistite.
PRSTUDIO_UC_Evidence_Memory::remember( 'secret_decision', array(
    'loop' => 'observe',
    'decision' => 'Leggere credenziali',
    'evidence' => array( 'access_token' => 'super-secret-value', 'safe' => 'ok' ),
    'outcome' => 'redatto',
) );
$raw = (string) file_get_contents( $path );
ok( false === strpos( $raw, 'super-secret-value' ), 'sensitive evidence values are redacted before persistence' );

// 7) Export limitato.
$export = PRSTUDIO_UC_Evidence_Memory::export( 3 );
ok( 3 === count( $export['records'] ), 'export honors the limit' );

fwrite( STDOUT, "PASS evidence memory smoke complete\n" );
exit( 0 );
