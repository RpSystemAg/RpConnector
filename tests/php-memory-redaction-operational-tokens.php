<?php
/** Regression coverage for MCP response redaction of operational opaque handles. */
declare( strict_types = 1 );
define( 'PRSTUDIO_UC_TESTING', true );

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-memory.php';

function ok_memory_redaction( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

$payload = array(
    'write_token' => 'wt1.observable-signed-precondition.signature',
    'lane_token' => 'internal-lane-secret',
    'access_token' => 'oauth-secret',
    'pairing_token' => 'pairing-secret',
    'nested' => array( 'write_token' => 'wt1.second.signature' ),
);
$redacted = PRSTUDIO_UC_Memory::redact( $payload );

ok_memory_redaction( 'wt1.observable-signed-precondition.signature' === ( $redacted['write_token'] ?? '' ), 'write_token survives response redaction' );
ok_memory_redaction( 'wt1.second.signature' === ( $redacted['nested']['write_token'] ?? '' ), 'nested write_token survives response redaction' );
ok_memory_redaction( '[REDACTED]' === ( $redacted['lane_token'] ?? '' ), 'lane_token remains redacted' );
ok_memory_redaction( '[REDACTED]' === ( $redacted['access_token'] ?? '' ), 'OAuth access_token remains redacted' );
ok_memory_redaction( '[REDACTED]' === ( $redacted['pairing_token'] ?? '' ), 'pairing_token remains redacted' );

fwrite( STDOUT, "PASS operational token redaction regression complete\n" );
exit( 0 );
