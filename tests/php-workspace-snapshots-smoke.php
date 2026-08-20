<?php
/**
 * Workspace snapshots smoke — "StagedWorkspace: A Versioned Workspace for
 * Knowledge-Work Agents" (arXiv 2026-08-13..19); Law 5 retry da stato
 * verificato.
 *
 * Snapshot di sessione versionati per correlation ID, ripristino idempotente
 * e fail-closed su digest mancante. Backend file nei test; schema SQL
 * verificato per il deployment.
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

function sanitize_key( $v ): string { return trim( (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $v ) ), '-_' ); }
function sanitize_text_field( $v ): string { return trim( strip_tags( (string) $v ) ); }
function wp_json_encode( $v, $flags = 0 ): string { return (string) json_encode( $v, $flags ); }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-workspace-snapshots.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

$dir = sys_get_temp_dir() . '/prstudio-snapshots-' . bin2hex( random_bytes( 4 ) );
PRSTUDIO_UC_Workspace_Snapshots::set_dir_for_test( $dir );

// 1) Schema SQL canonico per il deployment (parsato da validate-sql-syntax).
$ddl = PRSTUDIO_UC_Workspace_Snapshots::schema_sql();
ok( false !== strpos( $ddl, 'CREATE TABLE IF NOT EXISTS' ), 'schema_sql exposes canonical DDL' );
ok( false !== strpos( $ddl, 'prstudio_uc_workspace_snapshots' ), 'schema_sql names the snapshot table' );
ok( false !== strpos( $ddl, 'UNIQUE KEY uq_corr_version' ), 'schema_sql enforces one row per correlation/version' );

// 2) Salvataggio versionato per correlation ID server-derived.
$corr = 'corr_' . str_repeat( 'a', 32 );
$state_v1 = array( 'lane' => 'lane_1', 'timestep' => 10, 'wp' => array( 'post_count' => 3 ), 'steps' => array( 's1', 's2' ) );
$s1 = PRSTUDIO_UC_Workspace_Snapshots::save( $corr, $state_v1, array( 'lane_handle' => 'lane_1', 'timestep_gmt' => '2026-08-19T10:00:00Z' ) );
ok( true === $s1['ok'] && 1 === $s1['version'] && 'file' === $s1['backend'], 'first snapshot saved as version 1 (file backend in tests)' );

$state_v2 = array( 'lane' => 'lane_1', 'timestep' => 11, 'wp' => array( 'post_count' => 4 ), 'steps' => array( 's1', 's2', 's3' ) );
$s2 = PRSTUDIO_UC_Workspace_Snapshots::save( $corr, $state_v2, array( 'lane_handle' => 'lane_1', 'timestep_gmt' => '2026-08-19T10:05:00Z' ) );
ok( 2 === $s2['version'], 'second snapshot is version 2' );

// 3) Lista versioni in ordine.
$list = PRSTUDIO_UC_Workspace_Snapshots::list( $corr );
ok( 2 === count( $list ) && 1 === (int) $list[0]['version'] && 2 === (int) $list[1]['version'], 'list returns ordered versions' );

// 4) Ripristino dell'ultima versione e di una versione specifica.
$restore = PRSTUDIO_UC_Workspace_Snapshots::restore( $corr );
ok( true === $restore['ok'] && 2 === $restore['version'] && true === $restore['digest_verified'], 'restore returns the latest verified state' );
ok( $restore['state']['steps'] === array( 's1', 's2', 's3' ), 'restored state matches the snapshot payload' );

$restore_v1 = PRSTUDIO_UC_Workspace_Snapshots::restore( $corr, 1 );
ok( 1 === $restore_v1['version'] && $restore_v1['state']['timestep'] === 10, 'restore of a specific version works' );

// 5) Idempotenza del ripristino: due restore identici.
$restore_again = PRSTUDIO_UC_Workspace_Snapshots::restore( $corr );
ok( $restore_again['state'] === $restore['state'], 'restore is idempotent' );

// 6) Fail-closed: payload manomesso -> digest mismatch, nessuno stato.
$path = $dir . '/workspace-snapshots.ndjson';
$lines = file( $path, FILE_IGNORE_NEW_LINES );
$tampered = false;
foreach ( $lines as $i => $line ) {
    $row = json_decode( (string) $line, true );
    if ( is_array( $row ) && (int) ( $row['version'] ?? 0 ) === 2 ) {
        $row['payload'] = '{"lane":"lane_1","timestep":99,"wp":[],"steps":[]}';
        $lines[ $i ] = (string) json_encode( $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
        $tampered = true;
        break;
    }
}
ok( true === $tampered, 'test tampered the version-2 payload' );
file_put_contents( $path, implode( "\n", $lines ) . "\n" );
$restore_tampered = PRSTUDIO_UC_Workspace_Snapshots::restore( $corr, 2 );
ok( false === $restore_tampered['ok'] && 'digest_mismatch_fail_closed' === $restore_tampered['reason'], 'tampered snapshot fails closed' );

// 7) Prune: conserva solo gli ultimi N.
PRSTUDIO_UC_Workspace_Snapshots::save( $corr, array( 'lane' => 'lane_1', 'timestep' => 12, 'wp' => array(), 'steps' => array() ) );
$pruned = PRSTUDIO_UC_Workspace_Snapshots::prune( $corr, 2 );
ok( $pruned >= 1, 'prune removes old versions' );
ok( 2 === count( PRSTUDIO_UC_Workspace_Snapshots::list( $corr ) ), 'prune keeps only the latest N versions' );

// 8) Correlation vuota: rifiuto tecnico.
$empty = PRSTUDIO_UC_Workspace_Snapshots::save( '', array( 'x' => 1 ) );
ok( false === $empty['ok'], 'empty correlation id is rejected' );

fwrite( STDOUT, "PASS workspace snapshots smoke complete\n" );
exit( 0 );
