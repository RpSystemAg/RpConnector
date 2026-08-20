<?php
/**
 * Audit trail smoke — "D²ACCI" (arXiv 2026-08-13..19), aspetto audit/compliance.
 *
 * Registrazione append-only con catena di hash tamper-evident: la
 * manomissione di un record deve rompere la verifica della catena.
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

function sanitize_key( $v ): string { return trim( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ), '-_' ); }
function sanitize_text_field( $v ): string { return trim( strip_tags( (string) $v ) ); }
function wp_json_encode( $v, $flags = 0 ): string { return (string) json_encode( $v, $flags ); }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-audit-trail.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

$dir = sys_get_temp_dir() . '/prstudio-audit-trail-' . bin2hex( random_bytes( 4 ) );
PRSTUDIO_UC_Audit_Trail::set_dir_for_test( $dir );

// 1) Registrazione di eventi con catena.
$e1 = PRSTUDIO_UC_Audit_Trail::record( array(
    'actor' => 'mcp_client_a', 'action' => 'browser_navigate', 'correlation_id' => 'corr_aaa',
    'lane_handle' => 'lane_1', 'intent' => 'aprire la pagina prodotto', 'result_state' => 'completed', 'evidence_ref' => 'art_1',
) );
ok( true === $e1['ok'] && '' !== $e1['sha256'], 'first audit record carries a chain hash' );
$e2 = PRSTUDIO_UC_Audit_Trail::record( array(
    'actor' => 'mcp_client_a', 'action' => 'browser_click', 'correlation_id' => 'corr_aaa',
    'lane_handle' => 'lane_1', 'intent' => 'clic su aggiungi al carrello', 'result_state' => 'completed', 'evidence_ref' => 'art_2',
) );
$e3 = PRSTUDIO_UC_Audit_Trail::record( array(
    'actor' => 'mcp_client_b', 'action' => 'wordpress_content_transaction', 'correlation_id' => 'corr_bbb',
    'intent' => 'aggiornare la home', 'result_state' => 'completed', 'evidence_ref' => 'art_3',
) );
ok( $e3['seq'] === 3 && $e2['seq'] === 2 && $e1['seq'] === 1, 'records are numbered sequentially' );

// 2) Catena integra.
$verify = PRSTUDIO_UC_Audit_Trail::verify();
ok( true === $verify['ok'] && 3 === $verify['records'], 'untampered chain verifies' );

// 3) Query per filtro.
$q = PRSTUDIO_UC_Audit_Trail::query( array( 'actor' => 'mcp_client_a' ) );
ok( 2 === $q['count'], 'query filters by actor' );
$q2 = PRSTUDIO_UC_Audit_Trail::query( array( 'correlation_id' => 'corr_bbb' ) );
ok( 1 === $q2['count'] && 'mcp_client_b' === ( $q2['records'][0]['actor'] ?? '' ), 'query filters by correlation id' );

// 4) Tamper: modifica del primo record -> catena rotta.
$path = $dir . '/audit.ndjson';
$lines = file( $path, FILE_IGNORE_NEW_LINES );
$first = json_decode( (string) $lines[0], true );
$first['intent'] = 'intent manomesso';
$lines[0] = (string) json_encode( $first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
file_put_contents( $path, implode( "\n", $lines ) . "\n" );
$verify_broken = PRSTUDIO_UC_Audit_Trail::verify();
ok( false === $verify_broken['ok'] && 0 === $verify_broken['broken_at'], 'tampered first record breaks the chain at index 0' );

// 5) Tamper su un record intermedio.
file_put_contents( $path, '' );
PRSTUDIO_UC_Audit_Trail::record( array( 'actor' => 'a', 'action' => 'x', 'correlation_id' => 'c1', 'intent' => 'i1', 'result_state' => 'completed' ) );
PRSTUDIO_UC_Audit_Trail::record( array( 'actor' => 'a', 'action' => 'y', 'correlation_id' => 'c2', 'intent' => 'i2', 'result_state' => 'completed' ) );
PRSTUDIO_UC_Audit_Trail::record( array( 'actor' => 'b', 'action' => 'z', 'correlation_id' => 'c3', 'intent' => 'i3', 'result_state' => 'completed' ) );
$lines = file( $path, FILE_IGNORE_NEW_LINES );
$second = json_decode( (string) $lines[1], true );
$second['result_state'] = 'anti_crash';
$lines[1] = (string) json_encode( $second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
file_put_contents( $path, implode( "\n", $lines ) . "\n" );
$verify_mid = PRSTUDIO_UC_Audit_Trail::verify();
ok( false === $verify_mid['ok'] && 1 === $verify_mid['broken_at'], 'tampered middle record breaks the chain at index 1' );

// 6) Stats.
$stats = PRSTUDIO_UC_Audit_Trail::stats();
ok( 3 === $stats['count'] && '' !== $stats['last_gmt'], 'stats report count and timestamps' );

fwrite( STDOUT, "PASS audit trail smoke complete\n" );
exit( 0 );
