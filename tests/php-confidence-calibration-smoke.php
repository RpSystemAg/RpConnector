<?php
/**
 * Confidence calibration smoke — "Too Sure to Be Safe" (arXiv 2026-08-13..19).
 *
 * Un modello che dichiara 0.95 ma azzecca il 60% deve essere ricalibrato a
 * ~0.6 e marcato overconfident; un modello calibrato deve restare calibrato.
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

function sanitize_key( $v ): string { return trim( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ), '-_' ); }
function wp_json_encode( $v, $flags = 0 ): string { return (string) json_encode( $v, $flags ); }
function get_option( $key, $default = false ) { return $default; }
function update_option( $key, $value, $autoload = null ): bool { return true; }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-confidence-calibration.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

PRSTUDIO_UC_Confidence_Calibration::set_store_for_test( array() );
$key = 'overconfident_stream';

// 1) Stream overconfidente: dichiara 0.95, corretto nel 60% dei casi.
for ( $i = 0; $i < 200; $i++ ) {
    $correct = ( $i % 10 ) < 6;
    PRSTUDIO_UC_Confidence_Calibration::record( $key, 0.95, $correct );
}
$ece = PRSTUDIO_UC_Confidence_Calibration::expected_calibration_error( $key );
ok( $ece['samples'] === 200, 'overconfident stream recorded 200 samples' );
ok( $ece['ece'] > 0.2, 'overconfident stream has ECE > 0.2 (got ' . $ece['ece'] . ')' );

$recal = PRSTUDIO_UC_Confidence_Calibration::recalibrated( $key, 0.95 );
ok( $recal >= 0.45 && $recal <= 0.75, 'recalibrated 0.95 lands near observed accuracy 0.6 (got ' . $recal . ')' );

$verdict = PRSTUDIO_UC_Confidence_Calibration::verdict( $key, 0.95 );
ok( true === $verdict['overconfident'], '0.95 declared on a 0.6 stream is overconfident' );
ok( abs( $verdict['gap'] - ( 0.95 - $recal ) ) < 0.001, 'verdict gap is declared minus recalibrated' );

// 2) Stream calibrato: dichiara 0.8, corretto nell'80% dei casi.
$key2 = 'calibrated_stream';
for ( $i = 0; $i < 200; $i++ ) {
    $correct = ( $i % 10 ) < 8;
    PRSTUDIO_UC_Confidence_Calibration::record( $key2, 0.8, $correct );
}
$ece2 = PRSTUDIO_UC_Confidence_Calibration::expected_calibration_error( $key2 );
ok( $ece2['ece'] < 0.1, 'calibrated stream has ECE < 0.1 (got ' . $ece2['ece'] . ')' );
$verdict2 = PRSTUDIO_UC_Confidence_Calibration::verdict( $key2, 0.8 );
ok( false === $verdict2['overconfident'], 'calibrated stream is not overconfident' );

// 3) Pochi campioni: nessuna ricalibrazione inventata (fallback al dichiarato).
$key3 = 'cold_stream';
ok( 0.9 === PRSTUDIO_UC_Confidence_Calibration::recalibrated( $key3, 0.9 ), 'cold stream falls back to declared confidence' );

// 4) reset.
ok( PRSTUDIO_UC_Confidence_Calibration::reset( $key ), 'reset removes the overconfident key' );
ok( PRSTUDIO_UC_Confidence_Calibration::expected_calibration_error( $key )['samples'] === 0, 'reset clears counters' );
ok( false === PRSTUDIO_UC_Confidence_Calibration::reset( 'never_existed' ), 'reset on missing key reports false' );

// 5) snapshot.
$snapshot = PRSTUDIO_UC_Confidence_Calibration::snapshot();
ok( isset( $snapshot[ $key2 ] ) && $snapshot[ $key2 ]['samples'] === 200, 'snapshot lists the calibrated key with sample count' );

// 6) Verdetto con tolleranza personalizzata.
$verdict3 = PRSTUDIO_UC_Confidence_Calibration::verdict( $key2, 0.95, 0.3 );
ok( false === $verdict3['overconfident'], 'wide tolerance absorbs a small gap' );

fwrite( STDOUT, "PASS confidence calibration smoke complete\n" );
exit( 0 );
