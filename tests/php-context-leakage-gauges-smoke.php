<?php
/**
 * Context-leakage gauges smoke.
 *
 * I gauge rilevano segreti reali. Un payload gia' redatto resta emettibile:
 * questo e' essenziale per context_open, che espone lane_handle ma non il
 * lane_token interno.
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

function wp_json_encode( $v, $flags = 0 ): string { return (string) json_encode( $v, $flags ); }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-context-leak-gauge.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

$clean = PRSTUDIO_UC_Context_Leak_Gauge::scan( array(
    'ok' => true,
    'result' => array( 'title' => 'Pagina prodotto', 'url' => 'https://example.com/prodotto' ),
    'correlation_id' => 'corr_abcd',
) );
ok( false === $clean['leak'] && array() === $clean['findings'], 'clean payload has no leakage findings' );

$secret = 'oauth-access-token-0123456789abcdef';
$leak_known = PRSTUDIO_UC_Context_Leak_Gauge::scan(
    array( 'ok' => true, 'result' => array( 'token_echo' => $secret ) ),
    array( 'known_secrets' => array( $secret ) )
);
ok( true === $leak_known['leak'], 'known secret exact match is detected' );
ok( true === $leak_known['gauges']['gauge_known_secret'], 'known-secret gauge fires' );

$leak_key = PRSTUDIO_UC_Context_Leak_Gauge::scan( array(
    'ok' => true,
    'result' => array( 'lane' => array( 'lane_token' => 'internal-lane-value' ) ),
) );
ok( true === $leak_key['leak'], 'forbidden internal key with a real value is detected' );
ok( true === $leak_key['gauges']['gauge_forbidden_key'], 'forbidden-key gauge fires' );

$leak_pattern = PRSTUDIO_UC_Context_Leak_Gauge::scan( array(
    'ok' => true,
    'result' => array( 'message' => 'Autenticato con Bearer abcdefghijklmnopqrstuvwxyz0123456789' ),
) );
ok( true === $leak_pattern['leak'], 'bearer token pattern is detected' );
ok( true === $leak_pattern['gauges']['gauge_secret_pattern'], 'pattern gauge fires' );

$verdict = PRSTUDIO_UC_Context_Leak_Gauge::blocking_verdict(
    array( 'ok' => true, 'result' => array( 'access_token' => 'xyz' ) ),
    array()
);
ok( true === $verdict['blocked'] && 'context_leak_blocked' === $verdict['code'], 'raw forbidden credential remains blocking' );

$verdict_clean = PRSTUDIO_UC_Context_Leak_Gauge::blocking_verdict( $clean );
ok( false === $verdict_clean['blocked'] && '' === $verdict_clean['code'], 'clean payload is not blocked' );

$redacted = PRSTUDIO_UC_Context_Leak_Gauge::redact(
    array( 'ok' => true, 'result' => array( 'lane_token' => 't-1', 'text' => 'Bearer abcdefghijklmnopqrstuvwxyz0123456789' ) ),
    array()
);
ok( '[REDACTED]' === ( $redacted['redacted']['result']['lane_token'] ?? '' ), 'redact replaces forbidden keys' );
ok( false === strpos( (string) wp_json_encode( $redacted['redacted'] ), 'Bearer abcdefghijklmnopqrstuvwxyz' ), 'redact removes bearer tokens' );
ok( 2 === count( $redacted['findings'] ), 'redact reports every finding' );

// Regression P0: MCP calls PRSTUDIO_UC_Memory::redact before the blocking gauge.
// The internal lane_token is therefore already [REDACTED]. That sentinel must
// not make context_open fail; the public lane_handle must survive unchanged.
$context_open_after_memory_redaction = array(
    'ok' => true,
    'result' => array(
        'lane_id' => 'lane_0123456789abcdef0123456789abcdef',
        'lane_handle' => 'lane_0123456789abcdef0123456789abcdef',
        'lane_token' => '[REDACTED]',
        'reused' => false,
    ),
);
$context_verdict = PRSTUDIO_UC_Context_Leak_Gauge::blocking_verdict( $context_open_after_memory_redaction );
ok( false === $context_verdict['blocked'], 'redacted lane_token does not block context_open' );
ok( 'lane_0123456789abcdef0123456789abcdef' === $context_open_after_memory_redaction['result']['lane_handle'], 'public lane_handle survives redaction' );

$status = PRSTUDIO_UC_Context_Leak_Gauge::status();
ok( 'blocking' === ( $status['gauges']['gauge_known_secret'] ?? '' ), 'known-secret gauge is blocking' );
ok( 'blocking' === ( $status['gauges']['gauge_forbidden_key'] ?? '' ), 'forbidden-key gauge is blocking' );

$mcp_source = (string) file_get_contents( dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php' );
ok( false !== strpos( $mcp_source, 'context_leak_blocked' ), 'MCP V5 still embeds fail-closed context leak handling' );
ok( false !== strpos( $mcp_source, 'PRSTUDIO_UC_Memory::redact' ), 'MCP V5 redacts before the blocking gauge' );
ok( false !== strpos( $mcp_source, 'PRSTUDIO_UC_Context_Leak_Gauge::blocking_verdict' ), 'MCP V5 applies the gauge to the redacted output' );
ok( false !== strpos( $mcp_source, 'known_secrets_for_gauge' ), 'MCP V5 feeds known OAuth secrets to the gauge' );

fwrite( STDOUT, "PASS context leakage gauges smoke complete\n" );
exit( 0 );
