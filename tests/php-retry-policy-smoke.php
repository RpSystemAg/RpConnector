<?php
/**
 * Retry policy smoke — "On the Fragility of Self-Improving Agents" (arXiv
 * 2026-08-13..19), gestione fallimenti transitori; Law 5.
 *
 * Backoff esponenziale con jitter deterministico, classificazione
 * transitorio/permanente, limite massimo di tentativi.
 */

declare( strict_types = 1 );

define( 'PRSTUDIO_UC_TESTING', true );

require dirname( __DIR__ ) . '/prstudio-unified-control/includes/class-prstudio-uc-retry-policy.php';

function ok( bool $condition, string $message ): void {
    if ( ! $condition ) { fwrite( STDERR, "BAD: {$message}\n" ); exit( 1 ); }
    fwrite( STDOUT, "PASS {$message}\n" );
}

// 1) Backoff senza jitter: valori deterministici crescenti, mai oltre il cap.
$delays = array();
for ( $attempt = 1; $attempt <= 6; $attempt++ ) {
    $delays[] = PRSTUDIO_UC_Retry_Policy::schedule( $attempt, array( 'base_ms' => 500, 'factor' => 2.0, 'cap_ms' => 8000, 'jitter' => 'none' ) );
}
ok( $delays === array( 500, 1000, 2000, 4000, 8000, 8000 ), 'no-jitter backoff is deterministic and capped (got ' . implode( ',', $delays ) . ')' );

// 2) Full jitter con seed: sequenza riproducibile e dentro [0, scaled].
$seed = 42;
$s1 = array();
$s2 = array();
for ( $attempt = 1; $attempt <= 4; $attempt++ ) {
    $s1[] = PRSTUDIO_UC_Retry_Policy::schedule( $attempt, array( 'base_ms' => 500, 'factor' => 2.0, 'cap_ms' => 8000, 'jitter' => 'full' ), $seed );
    $s2[] = PRSTUDIO_UC_Retry_Policy::schedule( $attempt, array( 'base_ms' => 500, 'factor' => 2.0, 'cap_ms' => 8000, 'jitter' => 'full' ), $seed );
}
ok( $s1 === $s2, 'seeded full jitter is reproducible' );
foreach ( $s1 as $i => $delay ) {
    $scaled = min( 8000, 500 * ( 2 ** $i ) );
    ok( $delay >= 0 && $delay <= $scaled, 'full jitter delay ' . $delay . ' within [0,' . $scaled . ']' );
}

// 3) Equal jitter con seed: dentro [scaled, cap].
$e = PRSTUDIO_UC_Retry_Policy::schedule( 3, array( 'base_ms' => 500, 'factor' => 2.0, 'cap_ms' => 8000, 'jitter' => 'equal' ), $seed );
ok( $e >= 2000 && $e <= 8000, 'equal jitter delay ' . $e . ' within [2000,8000]' );

// 4) Classificazione transitori/permanenti.
$t1 = PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'http_503' ) );
ok( true === $t1['transient'], 'http_503 is transient' );
$t2 = PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'timeout' ) );
ok( true === $t2['transient'], 'timeout is transient' );
$t3 = PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'connection_refused' ) );
ok( true === $t3['transient'], 'connection_refused is transient' );
$t4 = PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'lock_contention' ) );
ok( true === $t4['transient'], 'lock_contention is transient' );
$p1 = PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'invalid_arguments' ) );
ok( false === $p1['transient'], 'invalid_arguments is permanent' );
$p2 = PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'http_404' ) );
ok( false === $p2['transient'], 'http_404 is permanent' );
$p3 = PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'browser_task_not_found' ) );
ok( false === $p3['transient'], 'browser_task_not_found is permanent' );

// 5) Flag espliciti retryable/non-retryable prevalgono.
ok( true === PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'weird', 'retryable' => true ) )['transient'], 'explicit retryable flag wins' );
ok( false === PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'http_503', 'retryable' => false ) )['transient'], 'explicit non-retryable flag wins' );

// 6) Codice sconosciuto: conservativo (niente retry alla cieca).
ok( false === PRSTUDIO_UC_Retry_Policy::classify( array( 'code' => 'mystery_code' ) )['transient'], 'unknown code is conservative (no blind retry)' );

// 7) next_attempt: transitorio entro i limiti -> retry con delay; oltre -> stop.
$n1 = PRSTUDIO_UC_Retry_Policy::next_attempt( 1, array( 'code' => 'http_503' ), array( 'max_attempts' => 5 ), $seed );
ok( true === $n1['retry'] && $n1['attempt'] === 2, 'transient failure schedules retry attempt 2' );
ok( $n1['delay_ms'] > 0, 'retry carries a positive delay' );
$n2 = PRSTUDIO_UC_Retry_Policy::next_attempt( 5, array( 'code' => 'http_503' ), array( 'max_attempts' => 5 ), $seed );
ok( false === $n2['retry'] && 'max_attempts_reached' === $n2['reason'], 'max attempts stops the retry loop' );
$n3 = PRSTUDIO_UC_Retry_Policy::next_attempt( 2, array( 'code' => 'invalid_arguments' ), array( 'max_attempts' => 5 ), $seed );
ok( false === $n3['retry'] && str_starts_with( $n3['reason'], 'not_transient' ), 'permanent failure never retries' );

fwrite( STDOUT, "PASS retry policy smoke complete\n" );
exit( 0 );
