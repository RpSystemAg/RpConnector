<?php
if ( ! defined( 'ABSPATH' ) && ! defined( 'PRSTUDIO_UC_TESTING' ) ) { exit; }

/**
 * Politica di retry per fallimenti transitori.
 *
 * Riferimento: "On the Fragility of Self-Improving Agents: Variance, Task
 * Order and a Way Forward" (arXiv, settimana 13-19 agosto 2026) — aspetto
 * gestione dei fallimenti transitori; Law 5: il fallimento transiente fa
 * retry, non parcheggia la missione.
 *
 * Fornisce:
 * - backoff esponenziale con jitter (full/equal/none) e tetto massimo,
 *   deterministico quando viene fornito un seed (testabilità Law 11);
 * - classificazione transitorio/permanente degli errori: transitori fanno
 *   retry, permanenti no (un errore permanente ritentato è spreco e rumore);
 * - limiti espliciti di tentativi: nessun retry infinito.
 *
 * Il modulo è puro: nessuno stato, nessun side-effect, nessuna dipendenza
 * WordPress.
 */
final class PRSTUDIO_UC_Retry_Policy {
    public const VERSION = '1.0.0';

    public const DEFAULT_BASE_MS = 500;
    public const DEFAULT_FACTOR = 2.0;
    public const DEFAULT_CAP_MS = 30000;
    public const DEFAULT_MAX_ATTEMPTS = 5;

    /** Codici di errore considerati transitori (retry sicuro). */
    private const TRANSIENT_CODES = array(
        'timeout', 'request_timeout', 'connection_timeout', 'connect_timeout',
        'connection_refused', 'connection_reset', 'connection_failed',
        'http_502', 'http_503', 'http_504', 'http_429', 'http_500',
        'lock_contention', 'lease_lost', 'queue_busy', 'worker_busy',
        'service_unavailable', 'temporary_unavailable', 'throttled',
        'transient_failure', 'network_error', 'dns_error', 'stale_lease',
        'task_requeued', 'retryable',
    );

    /** Codici di errore sempre permanenti (mai retry). */
    private const PERMANENT_CODES = array(
        'invalid_arguments', 'invalid_parameters', 'validation_error',
        'unauthorized', 'forbidden', 'not_found', 'missing_required',
        'capability_not_found', 'tool_not_found', 'job_not_found',
        'browser_task_not_found', 'permission_denied', 'method_not_allowed',
        'payload_too_large', 'unsupported_protocol', 'invalid_request',
        'action_infeasible', 'context_leak_blocked', 'permanent_failure',
        'http_400', 'http_401', 'http_403', 'http_404', 'http_405', 'http_422',
    );

    /**
     * Ritardo (ms) per il tentativo dato, con backoff esponenziale e jitter.
     *
     * attempt è 1-based (il primo retry dopo il tentativo 1 è attempt=2).
     * - jitter 'full':  random uniforme in [0, cap] (AWS full jitter);
     * - jitter 'equal':  random uniforme in [base, cap] (AWS equal jitter);
     * - jitter 'none':   valore deterministico base * factor^(attempt-1).
     *
     * Con seed fornito la sequenza è riproducibile (test deterministici).
     */
    public static function schedule( int $attempt, array $options = array(), ?int $seed = null ): int {
        $attempt = max( 1, $attempt );
        $base = max( 1, (int) ( $options['base_ms'] ?? self::DEFAULT_BASE_MS ) );
        $factor = max( 1.0, (float) ( $options['factor'] ?? self::DEFAULT_FACTOR ) );
        $cap = max( $base, (int) ( $options['cap_ms'] ?? self::DEFAULT_CAP_MS ) );
        $jitter = (string) ( $options['jitter'] ?? 'full' );
        $exponent = max( 0, $attempt - 1 );
        $scaled = (int) min( $cap, $base * ( $factor ** $exponent ) );
        if ( 'none' === $jitter ) {
            return min( $cap, $scaled );
        }
        if ( 'equal' === $jitter ) {
            $low = min( $scaled, $cap );
            return self::random_int( $low, $cap, $seed, $attempt );
        }
        // full jitter: uniforme in [0, scaled]
        return self::random_int( 0, min( $scaled, $cap ), $seed, $attempt );
    }

    /**
     * Intero pseudo-casuale riproducibile quando serve un seed.
     *
     * LCG 32-bit overflow-safe: max(state*1103515245) ~= 2.37e18 < PHP_INT_MAX,
     * quindi nessuna conversione a float su PHP 8. Con seed fornito la
     * sequenza è deterministicamente riproducibile (Law 11: test eseguibili).
     */
    private static function random_int( int $min, int $max, ?int $seed, int $salt ): int {
        if ( $max <= $min ) { return $min; }
        $state = null !== $seed ? ( $seed & 0x7fffffff ) : random_int( 0, 2147483647 );
        $state = ( $state * 1103515245 + 12345 + $salt * 2654435761 ) % 2147483648;
        return $min + ( $state % ( $max - $min + 1 ) );
    }

    /**
     * Classifica un errore come transitorio (retry sicuro) o permanente.
     *
     * @param array{code?:string,retryable?:bool,status?:int,data?:array<string,mixed>} $error
     * @return array{transient:bool,code:string,reason:string}
     */
    public static function classify( array $error ): array {
        $code = strtolower( (string) ( $error['code'] ?? '' ) );
        $data = is_array( $error['data'] ?? null ) ? $error['data'] : ( is_array( $error['details'] ?? null ) ? $error['details'] : array() );
        $status = (int) ( $error['status'] ?? $data['status'] ?? 0 );

        if ( true === ( $error['retryable'] ?? $data['retryable'] ?? null ) ) {
            return array( 'transient' => true, 'code' => $code, 'reason' => 'explicit_retryable_flag' );
        }
        if ( false === ( $error['retryable'] ?? $data['retryable'] ?? null ) ) {
            return array( 'transient' => false, 'code' => $code, 'reason' => 'explicit_non_retryable_flag' );
        }
        if ( in_array( $code, self::TRANSIENT_CODES, true ) ) {
            return array( 'transient' => true, 'code' => $code, 'reason' => 'known_transient_code' );
        }
        if ( in_array( $code, self::PERMANENT_CODES, true ) ) {
            return array( 'transient' => false, 'code' => $code, 'reason' => 'known_permanent_code' );
        }
        if ( $status >= 500 ) {
            return array( 'transient' => true, 'code' => $code, 'reason' => 'http_5xx' );
        }
        if ( $status >= 400 && $status < 500 ) {
            return array( 'transient' => false, 'code' => $code, 'reason' => 'http_4xx' );
        }
        if ( '' !== $code ) {
            // Codice sconosciuto ma esplicito: prudenza conservativa, il retry
            // è sicuro solo quando l'azione è idempotente (Law 5 richiede
            // retry, ma mai retry distruttivo).
            return array( 'transient' => false, 'code' => $code, 'reason' => 'unknown_code_conservative' );
        }
        return array( 'transient' => false, 'code' => '', 'reason' => 'no_code_conservative' );
    }

    /** Limite tentativi: nessun retry oltre max_attempts. */
    public static function should_retry( int $attempt, int $max_attempts = self::DEFAULT_MAX_ATTEMPTS ): bool {
        return $attempt < max( 1, $max_attempts );
    }

    /**
     * Decisione completa per il tentativo successivo.
     *
     * @return array{retry:bool,attempt:int,delay_ms:int,reason:string}
     */
    public static function next_attempt( int $attempt, array $error, array $options = array(), ?int $seed = null ): array {
        $classification = self::classify( $error );
        $max_attempts = max( 1, (int) ( $options['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS ) );
        if ( ! $classification['transient'] ) {
            return array(
                'retry' => false,
                'attempt' => $attempt,
                'delay_ms' => 0,
                'reason' => 'not_transient:' . $classification['reason'],
            );
        }
        if ( ! self::should_retry( $attempt, $max_attempts ) ) {
            return array(
                'retry' => false,
                'attempt' => $attempt,
                'delay_ms' => 0,
                'reason' => 'max_attempts_reached',
            );
        }
        $next = $attempt + 1;
        return array(
            'retry' => true,
            'attempt' => $next,
            'delay_ms' => self::schedule( $next, $options, $seed ),
            'reason' => 'transient:' . $classification['reason'],
        );
    }
}
