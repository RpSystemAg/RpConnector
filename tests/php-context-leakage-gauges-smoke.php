<?php
/**
 * Context-leakage gauges smoke — "The Model's Tell: Measuring Context-Leakage
 * Attack Signals with Behavior Gauges" (arXiv 2026-08-13..19).
 *
 * I gauge comportamentali misurano segnali di fuga di contesto (token,
 * lane_token, client_secret) nelle risposte MCP. Il gauge è un invariante
 * BLOCCANTE: il flusso MCP sostituisce la risposta con un errore tecnico
 * `context_leak_blocked` (verificato anche sul sorgente di MCP V5).
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

function wp_json_encode( $v, $flags = 0 ): string { return (string) json_encode( $v, $flags ); }

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-context-leak-gauge.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

// 1) Payload pulito: nessun leak.
$clean = PRSTUDIO_UC_Context_Leak_Gauge::scan( array(
    'ok' => true,
    'result' => array( 'title' => 'Pagina prodotto', 'url' => 'https://example.com/prodotto' ),
    'correlation_id' => 'corr_abcd',
) );
ok( false === $clean['leak'] && array() === $clean['findings'], 'clean payload has no leakage findings' );

// 2) Fuga di un segreto noto (valore esatto di un'opzione OAuth).
$secret = 'oauth-access-token-0123456789abcdef';
$leak_known = PRSTUDIO_UC_Context_Leak_Gauge::scan(
    array( 'ok' => true, 'result' => array( 'token_echo' => $secret ) ),
    array( 'known_secrets' => array( $secret ) )
);
ok( true === $leak_known['leak'], 'known secret exact match is detected' );
ok( true === $leak_known['gauges']['gauge_known_secret'], 'known-secret gauge fires' );

// 3) Fuga per chiave vietata (lane_token interno).
$leak_key = PRSTUDIO_UC_Context_Leak_Gauge::scan( array(
    'ok' => true,
    'result' => array( 'lane' => array( 'lane_token' => 'internal-lane-value' ) ),
) );
ok( true === $leak_key['leak'], 'forbidden internal key is detected' );
ok( true === $leak_key['gauges']['gauge_forbidden_key'], 'forbidden-key gauge fires' );

// 4) Fuga per pattern (Bearer token inline nel testo).
$leak_pattern = PRSTUDIO_UC_Context_Leak_Gauge::scan( array(
    'ok' => true,
    'result' => array( 'message' => 'Autenticato con Bearer abcdefghijklmnopqrstuvwxyz0123456789' ),
) );
ok( true === $leak_pattern['leak'], 'bearer token pattern is detected' );
ok( true === $leak_pattern['gauges']['gauge_secret_pattern'], 'pattern gauge fires' );

// 5) Verdetto bloccante.
$verdict = PRSTUDIO_UC_Context_Leak_Gauge::blocking_verdict(
    array( 'ok' => true, 'result' => array( 'access_token' => 'xyz' ) ),
    array()
);
ok( true === $verdict['blocked'] && 'context_leak_blocked' === $verdict['code'], 'blocking verdict names context_leak_blocked' );

$verdict_clean = PRSTUDIO_UC_Context_Leak_Gauge::blocking_verdict( $clean );
ok( false === $verdict_clean['blocked'] && '' === $verdict_clean['code'], 'clean payload is not blocked' );

// 6) Redazione difensiva: i segreti vengono sostituiti.
$redacted = PRSTUDIO_UC_Context_Leak_Gauge::redact(
    array( 'ok' => true, 'result' => array( 'lane_token' => 't-1', 'text' => 'Bearer abcdefghijklmnopqrstuvwxyz0123456789' ) ),
    array()
);
ok( '[REDACTED]' === ( $redacted['redacted']['result']['lane_token'] ?? '' ), 'redact replaces forbidden keys' );
ok( false === strpos( (string) wp_json_encode( $redacted['redacted'] ), 'Bearer abcdefghijklmnopqrstuvwxyz' ), 'redact removes bearer tokens' );
ok( 2 === count( $redacted['findings'] ), 'redact reports every finding' );

// 7) Status dei gauge: tutti bloccanti.
$status = PRSTUDIO_UC_Context_Leak_Gauge::status();
ok( 'blocking' === ( $status['gauges']['gauge_known_secret'] ?? '' ), 'known-secret gauge is blocking' );
ok( 'blocking' === ( $status['gauges']['gauge_forbidden_key'] ?? '' ), 'forbidden-key gauge is blocking' );

// 8) Invariante nel flusso MCP: il sorgente del server deve applicare il
//    verdetto bloccante in clean_result.
$mcp_source = (string) file_get_contents( dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-mcp-v5.php' );
ok( false !== strpos( $mcp_source, 'context_leak_blocked' ), 'MCP V5 embeds the blocking context_leak_blocked error' );
ok( false !== strpos( $mcp_source, 'PRSTUDIO_UC_Context_Leak_Gauge::blocking_verdict' ), 'MCP V5 calls the gauge before emitting a response' );
ok( false !== strpos( $mcp_source, 'known_secrets_for_gauge' ), 'MCP V5 feeds known OAuth secrets to the gauge' );

fwrite( STDOUT, "PASS context leakage gauges smoke complete\n" );
exit( 0 );
